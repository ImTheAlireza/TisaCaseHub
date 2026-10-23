#!/usr/bin/env node
/**
 * بررسی ایستای «فراخوانی متد وجود دارد؟» برای افزونه‌های تیساکیس.
 *
 * چرا؟ خطای واقعی روی سایت کاربر:
 *   `Call to undefined method TisaCase_Optimizer_Media_Inspector::detect_opaque_png`
 * رخ داد چون فراخوانی به کلاس اشتباه اشاره می‌کرد (متد در
 * `TisaCase_Optimizer_Reference_Scanner::fast_detect_opaque_png` بود).
 * چون هیچ PHP واقعی در سندباکس اجرا نمی‌شود، این خطا فقط روی سرور کاربر
 * ظاهر می‌شد. این ابزار همان کلاس خطا را ایستا می‌گیرد:
 *   ۱. هر `ClassName::method(` که کلاسش را خودمان تعریف کرده‌ایم باید متد
 *      اعلام‌شده داشته باشد.
 *   ۲. متد `static` صدا زده شود اگر `::` است و `$this` نباشد (متد غیرstatic
 *      با `::` در PHP 8 خطای کشنده می‌دهد).
 *   ۳. متدهای `private` از بیرون کلاس صدا زده نشوند.
 *   ۴. نام کلاس‌هایی که ارجاع داده می‌شوند اما تعریف نشده‌اند (تایپو در نام)
 *      گزارش شوند.
 *
 * اجرا: node tools/php-call-check.mjs [دایرکتوری...]
 * خروج ۱ = فراخوانی نامعتبر (فروپاشی احتمالی روی سایت).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(ROOT, 'package.json'));
const P = require('php-parser');

const engine = new P({ parser: { extractDoc: false }, ast: { withPositions: true } });

const targets = process.argv.slice(2);
const dirs = (targets.length ? targets : ['plugins']).map((d) => (path.isAbsolute(d) ? d : path.join(ROOT, d)));

function walk(dir, out = []) {
	if (!fs.existsSync(dir)) return out;
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (['node_modules', 'dist', '.git'].includes(entry.name)) continue;
			walk(full, out);
		} else if (entry.name.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

const files = [...new Set(dirs.flatMap((d) => (fs.statSync(d).isDirectory() ? walk(d) : [d])))];
if (!files.length) {
	console.error('فایل PHP پیدا نشد.');
	process.exit(1);
}

/** @type {Map<string, {file:string, methods:Map<string,{visibility:string,isStatic:boolean}>}>} */
const classes = new Map();
/** @type {Array<{file:string, line:number, className:string, method:string, scope:string}>} */
const calls = [];

function lineOf(node) {
	return node.loc ? node.loc.start.line : 0;
}

function currentScopeName(stack) {
	// نام کلاس دربرگیرندهٔ گره (برای تشخیص دسترسی private از داخل همان کلاس).
	for (let i = stack.length - 1; i >= 0; i -= 1) {
		const n = stack[i];
		if (n && n.kind === 'class') return n.name ? n.name.name || String(n.name) : '';
	}
	return '';
}

function collectClasses(ast, file) {
	const stack = [];
	const visit = (node) => {
		if (!node || typeof node !== 'object') return;
		if (Array.isArray(node)) {
			node.forEach(visit);
			return;
		}
		if (typeof node.kind !== 'string') return;

		if (node.kind === 'class') {
			const name = node.name ? String(node.name.name || node.name) : '';
			if (name) {
				const methods = new Map();
				for (const m of node.body || []) {
					if (m.kind !== 'method') continue;
					const mname = m.name && typeof m.name === 'object' ? String(m.name.name || '') : String(m.name || '');
					if (!mname) continue;
					methods.set(mname, {
						visibility: (m.visibility || 'public').toLowerCase(),
						isStatic: Boolean(m.isStatic),
					});
				}
				if (!classes.has(name)) {
					classes.set(name, { file, methods });
				} else {
					// ادغام امن در صورت چند تعریف (نباید رخ دهد).
					const prev = classes.get(name);
					methods.forEach((v, k) => prev.methods.set(k, v));
				}
			}
		}

		stack.push(node);
		for (const key of Object.keys(node)) {
			if (key === 'loc') continue;
			const val = node[key];
			if (val && typeof val === 'object') visit(val);
		}
		stack.pop();
	};
	visit(ast);
}

function collectCalls(ast, file) {
	const stack = [];
	const visit = (node) => {
		if (!node || typeof node !== 'object') return;
		if (Array.isArray(node)) {
			node.forEach(visit);
			return;
		}
		if (typeof node.kind !== 'string') return;

		if (node.kind === 'call' && node.what && node.what.kind === 'staticlookup') {
			const look = node.what;
			// php-parser نسخهٔ ۳ بخش کلاس را در `what` می‌گذارد (نه `class`).
			const clsNode = look.class || look.what;
			const cname = clsNode && clsNode.kind === 'name' ? String(clsNode.name || '') : '';
			const mname = look.offset && look.offset.kind === 'identifier' ? String(look.offset.name) : '';
			if (cname && mname && /^[A-Za-z_][A-Za-z0-9_]*$/.test(cname)) {
				calls.push({ file, line: lineOf(node), className: cname, method: mname, scope: currentScopeName(stack) });
			}
		}

		stack.push(node);
		for (const key of Object.keys(node)) {
			if (key === 'loc') continue;
			const val = node[key];
			if (val && typeof val === 'object') visit(val);
		}
		stack.pop();
	};
	visit(ast);
}

let parseErrors = 0;
for (const file of files) {
	const src = fs.readFileSync(file, 'utf8');
	try {
		const ast = engine.parseCode(src, file);
		collectClasses(ast, file);
		collectCalls(ast, file);
	} catch (e) {
		parseErrors += 1;
		console.error(`✗ خطای تجزیه: ${path.relative(ROOT, file)} — ${e.message}`);
	}
}
if (parseErrors) process.exit(1);

const known = [...classes.keys()];
const isOurClass = (name) => classes.has(name);

const problems = [];
let checked = 0;

for (const call of calls) {
	if (!isOurClass(call.className)) continue;
	// متدهای جادویی/دینامیک را رد کن.
	if (call.method.startsWith('__')) continue;
	checked += 1;

	const cls = classes.get(call.className);
	const meta = cls.methods.get(call.method);
	const rel = path.relative(ROOT, call.file);

	if (!meta) {
		problems.push(`${rel}:${call.line} — متد «${call.method}» در کلاس ${call.className} وجود ندارد (خطای کشنده در زمان اجرا)`);
		continue;
	}

	if (!meta.isStatic) {
		problems.push(`${rel}:${call.line} — «${call.className}::${call.method}» متد غیرstatic است ولی با «::» صدا زده شده (PHP 8: خطای کشنده)`);
		continue;
	}

	if (meta.visibility === 'private' && call.scope !== call.className) {
		problems.push(`${rel}:${call.line} — «${call.className}::${call.method}» private است و از بیرون صدا زده شده`);
	}
}

// نام کلاس‌های ناشناخته با پیشوند پروژه (تایپوی احتمالی).
const unknownClasses = new Set();
for (const call of calls) {
	const n = call.className;
	if (isOurClass(n)) continue;
	if (!/^TisaCase_|^TCO_/.test(n)) continue;
	unknownClasses.add(`${path.relative(ROOT, call.file)}:${call.line} — کلاس «${n}» تعریف نشده است`);
}

const all = [...problems, ...unknownClasses];

console.log(`بررسی شد: ${files.length} فایل PHP · ${classes.size} کلاس · ${checked} فراخوانی استاتیک از کلاس‌های خودمان`);

if (all.length) {
	console.error(`\n✗ ${all.length} فراخوانی نامعتبر (روی سایت به خطای کشنده تبدیل می‌شود):`);
	all.forEach((p) => console.error(`   • ${p}`));
	process.exit(1);
}

console.log('✓ هیچ فراخوانی متد ناموجود/غیرمجاز یافت نشد.');
