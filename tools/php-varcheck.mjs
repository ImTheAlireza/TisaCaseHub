#!/usr/bin/env node
/**
 * جست‌وجوی **متغیرهای تعریف‌نشده** در فایل‌های PHP افزونه‌ها با پارسر php-parser.
 *
 * چرا لازم است: `tools/php-check.py` توازن آکولادها را می‌بیند و `tools/php-parse.mjs`
 * سینتکس را؛ اما هیچ‌کدام «خواندن متغیری که هرگز تعریف نشده» را نمی‌گیرند — همان
 * باگی که در ۱.۴.۰ موتور SQL انبار انبوه (`$clean` به‌جای `$items` در
 * apply_scope_stock) روی سرور کاربر TypeError ساخت و هر دستهٔ اعمال را شکست داد.
 *
 * روش: برای هر متد/تابع/کلوزر، مجموعهٔ متغیرهای «تعریف‌شده» را از پارامترها،
 * assignها، foreach، global، static، catch، آرگومان ارجاعی توابع خروجی‌ساز
 * (preg_match و…) و use کلوزرها جمع می‌کند و هر خواندن `$var` بیرون از آن
 * مجموعه را خطا می‌گیرد. هیوریستیک و جریان‌ناآگاه است: متغیری که فقط در یک
 * شاخه تعریف می‌شود ولی در شاخهٔ دیگر هم خوانده می‌شود خطا گرفته نمی‌شود
 * (تعریف در هر نقطه از بدنه کافی است) — هدف گرفتن تایپوهای «نام کاملاً
 * اشتباه» است، نه تحلیل جریان داده.
 *
 * نصب پیش‌نیاز:  npm install php-parser
 *
 * استفاده:
 *     node tools/php-varcheck.mjs [مسیر ...]   # پیش‌فرض: plugins/
 *
 * کد خروجی: ۰ = سالم · ۱ = متغیر تعریف‌نشده یا خطای پارس · ۲ = پارسر نصب نیست
 */

import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const targets = process.argv.slice(2).filter((a) => !a.startsWith('--'));

const SKIP_DIRS = new Set(['node_modules', '.git', 'dist', 'build', 'vendor', '.cache', 'coverage']);

function walkFiles(dir, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name.startsWith('.')) continue;
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (SKIP_DIRS.has(entry.name)) continue;
			walkFiles(full, out);
		} else if (entry.name.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

let PhpParser;
try {
	const mod = await import('php-parser');
	PhpParser = mod.default || mod;
} catch (e) {
	console.error('✗ پارسر PHP نصب نیست. یک‌بار اجرا کنید:  npm install php-parser');
	process.exit(2);
}

const parser = new PhpParser({
	parser: { extractDoc: false, suppressErrors: false, php7: true },
	ast: { withPositions: true },
});

// ابرمتغیرها و سراسری‌هایی که WP در دسترس همه‌جا می‌گذارد.
const SUPER = new Set(['this', '_GET', '_POST', '_REQUEST', '_SERVER', '_FILES', '_COOKIE', 'GLOBALS', 'wpdb', 'wp_query', 'shortcode_tags']);
// جاهایی که «متغیر» نشانهٔ خواندنِ مقدار واقعی نیست (isset/empty یا نام property).
const SKIP_PARENT = new Set(['isset', 'empty', 'staticlookup', 'propertystaticlookup', 'propertylookup']);
// توابعی که آرگومان ارجاعی‌شان متغیر را «تعریف» می‌کند (خروجی می‌سازند).
const BYREF_CREATORS = new Set(['preg_match', 'preg_match_all', 'parse_str', 'wp_parse_str', 'sscanf', 'mb_ereg', 'mb_eregi', 'mb_ereg_match', 'socket_getopt']);

const nameOf = (n) => (typeof n === 'string' ? n : (n && typeof n.name === 'string' ? n.name : (n && n.name && n.name.name) || null));

// راه‌پیمایی با پدرِ هر گره.
function* walk(node, parent) {
	if (!node || typeof node !== 'object') return;
	if (Array.isArray(node)) {
		for (const n of node) yield* walk(n, parent);
		return;
	}
	if (node.kind) yield [node, parent];
	for (const k of Object.keys(node)) {
		if (k === 'loc' || k === 'leadingComments' || k === 'trailingComments') continue;
		const v = node[k];
		if (v && typeof v === 'object') yield* walk(v, node.kind ? node : parent);
	}
}

// یافته‌های شناخته‌شدهٔ قدیمی در افزونه‌های دیگر (بی‌اثر در اجرا، خارج از محدودهٔ
// این دروازه): `rel:خط:$var` — به‌جای خطا، هشدار می‌گیرند تا رفعشان فراموش نشود.
const KNOWN = new Set(['plugins/src/wc-telegram-orders/wc-telegram-orders.php:3004:$s']);

const roots = targets.length ? targets.map((t) => path.resolve(ROOT, t)) : [path.join(ROOT, 'plugins')];
const files = [];
for (const root of roots) {
	if (!fs.existsSync(root)) continue;
	if (fs.statSync(root).isDirectory()) walkFiles(root, files);
	else if (root.endsWith('.php')) files.push(root);
}

let bad = 0;
let parsed = 0;
for (const file of files) {
	const rel = path.relative(ROOT, file);
	let ast;
	try {
		ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
		parsed += 1;
	} catch (e) {
		bad += 1;
		console.log(`✗ خطای پارس در ${rel}: ${e.message}`);
		continue;
	}
	for (const [m] of walk(ast, null)) {
		if (m.kind !== 'method' && m.kind !== 'function' && m.kind !== 'closure') continue;
		const defined = new Set(SUPER);
		const addName = (n) => {
			const nm = nameOf(n);
			if (nm) defined.add(nm);
		};
		for (const p of m.arguments || []) addName(p);
		for (const u of m.uses || []) addName(u);
		for (const [n] of walk(m.body, null)) {
			if (n.kind === 'assign' || n.kind === 'assignref') {
				for (const [l] of walk(n.left, null)) if (l.kind === 'variable') defined.add(l.name);
			}
			if (n.kind === 'foreach') {
				if (n.value) for (const [v] of walk(n.value, null)) if (v.kind === 'variable') defined.add(v.name);
				if (n.key) for (const [v] of walk(n.key, null)) if (v.kind === 'variable') defined.add(v.name);
			}
			if (n.kind === 'global') for (const g of n.items || []) addName(g);
			if (n.kind === 'closure' || n.kind === 'function') {
				for (const p of n.arguments || []) addName(p);
				for (const u of n.uses || []) addName(u);
			}
			if (n.kind === 'catch') {
				addName(n.variable);
				for (const w of n.what || []) addName(w);
			}
			if (n.kind === 'static') {
				// static $x = ... داخل تابع
				for (const [v] of walk(n, null)) if (v.kind === 'variable') defined.add(v.name);
			}
			if (n.kind === 'call' && n.what) {
				// فراخوانی توابع خروجی‌ساز: آرگومان‌های ارجاعی تعریف می‌شوند.
				const fn = typeof n.what === 'string' ? n.what : nameOf(n.what);
				if (fn && BYREF_CREATORS.has(fn)) {
					for (const a of n.arguments || []) {
						const expr = a && a.value !== undefined ? a.value : a;
						for (const [v] of walk(expr, null)) if (v.kind === 'variable') defined.add(v.name);
					}
				}
			}
		}
		for (const [n, par] of walk(m.body, null)) {
			if (n.kind !== 'variable') continue;
			if (par && SKIP_PARENT.has(par.kind)) continue;
			if (!defined.has(n.name)) {
				const line = n.loc ? n.loc.start.line : 0;
				if (KNOWN.has(`${rel}:${line}:$${n.name}`)) {
					console.log(`⚠ شناخته‌شده ${rel}:${line} — ${nameOf(m) || '?'}() متغیر تعریف‌نشده: $${n.name}`);
					continue;
				}
				bad += 1;
				console.log(`✗ ${rel}:${line || '?'} — ${nameOf(m) || '?'}() متغیر تعریف‌نشده: $${n.name}`);
			}
		}
	}
}

if (bad) {
	console.log(`✗ ${bad} متغیر تعریف‌نشده در ${parsed} فایل PHP`);
	process.exit(1);
}
console.log(`✓ بدون متغیر تعریف‌نشده (${parsed} فایل PHP)`);
