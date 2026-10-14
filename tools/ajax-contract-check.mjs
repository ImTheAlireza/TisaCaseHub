#!/usr/bin/env node
/**
 * قرارداد AJAX بین PHP و جاوااسکریپت را ایستا بررسی می‌کند.
 *
 * چرا؟ دو باگ واقعی که به سایت کاربر رسید از همین دسته بودند و هیچ تستی
 * نمی‌گرفتشان، چون سمت PHP در سندباکس هرگز اجرا نمی‌شود و تست jsdom فقط
 * ماکِ جاوااسکریپت را می‌بیند:
 *   ۱٫ `list_scope_attributes()` آرایهٔ کلیددار برمی‌گرداند ولی JS آن را
 *      آرایهٔ تخت می‌خواند (و متغیر تعریف‌نشده هم داشت → خطای کشنده).
 *   ۲٫ `list_scope_values()` آرایهٔ تخت برمی‌گرداند ولی لایهٔ AJAX
 *      `$pack['values']` می‌خواند → مقدارها همیشه خالی به مرورگر می‌رسید.
 *
 * بررسی‌ها:
 *   A) هر اندپوینتی که JS صدا می‌زند در `endpoints()` ثبت شده باشد و برعکس.
 *   B) هر کلیدی که JS از پاسخ می‌خواند در payload همان اندپوینت باشد.
 *   C) هر کلیدی که PHP از نتیجهٔ یک متد می‌خواند (`$pack['values']`) در
 *      `return`های آن متد موجود باشد.
 *   D) هر کلیدی که JS مثل آرایه مصرفش می‌کند (length/index/Array.isArray)
 *      نباید از PHP آرایهٔ کلیددار (JSON object) بگیرد.
 *
 * اجرا: node tools/ajax-contract-check.mjs [مسیر افزونه]
 * خروج ۱ = نقض قرارداد.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(ROOT, 'package.json'));
const P = require('php-parser');

const pluginDir = process.argv[2] || 'plugins/src/tisacase-bulk-stock-manager';
const base = path.isAbsolute(pluginDir) ? pluginDir : path.join(ROOT, pluginDir);
const AJAX_FILE = path.join(base, 'includes/class-tbsm-ajax.php');
const STOCK_FILE = path.join(base, 'includes/class-tbsm-stock.php');
const JS_FILE = path.join(base, 'assets/admin.js');

for (const f of [AJAX_FILE, STOCK_FILE, JS_FILE]) {
	if (!fs.existsSync(f)) {
		console.error('فایل نیست: ' + f);
		process.exit(2);
	}
}

const parser = new P({ parser: { php7: true, suppressErrors: true }, ast: {} });

function walk(node, visit) {
	if (!node || typeof node !== 'object') return;
	if (Array.isArray(node)) {
		for (const child of node) walk(child, visit);
		return;
	}
	if (typeof node.kind === 'string') visit(node);
	for (const key of Object.keys(node)) {
		if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue;
		const v = node[key];
		if (v && typeof v === 'object') walk(v, visit);
	}
}

const nameOf = (node) => (node && node.name ? (typeof node.name === 'string' ? node.name : node.name.name) : '');

/** پیمایش بدنهٔ یک متد بدون وارد شدن به توابع/کلوزرهای تودرتو (scope جدا). */
function walkShallow(node, visit) {
	if (!node || typeof node !== 'object') return;
	if (Array.isArray(node)) {
		for (const child of node) walkShallow(child, visit);
		return;
	}
	if (typeof node.kind === 'string') {
		const nested = node.kind === 'closure' || node.kind === 'function' || node.kind === 'arrowfunc';
		visit(node, nested);
		if (nested) return;
	}
	for (const key of Object.keys(node)) {
		if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue;
		const v = node[key];
		if (v && typeof v === 'object') walkShallow(v, visit);
	}
}

/** `self::foo(...)` / `TBSM_Stock::foo(...)` → نام متد، وگرنه ''. */
function staticCallName(node) {
	if (!node || node.kind !== 'call' || !node.what || node.what.kind !== 'staticlookup' || !node.what.offset) return '';
	return nameOf(node.what.offset);
}

/** شکل یک آرایهٔ نوشتاری: `list` (فهرست) یا `keyed` (آبجکت JSON) یا null. */
function arrayLiteralShape(node) {
	if (!node || node.kind !== 'array') return null;
	const keys = [];
	let list = true;
	for (const item of node.items || []) {
		if (!item || item.kind !== 'entry') {
			list = false;
			continue;
		}
		if (!item.key) continue;
		list = false;
		keys.push(item.key.kind === 'string' ? String(item.key.value) : '<' + item.key.kind + '>');
	}
	return list ? { shape: 'list', keys: [] } : { shape: 'keyed', keys };
}

/**
 * شکل بازگشتی متدهای یک فایل: نام → { shapes: [...], opaque: bool }
 * با حل انتساب‌های خطی داخل هر متد (var → آرایه / array_values / متد دیگر).
 */
function functionShapes(file) {
	const ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
	const out = new Map();
	const bodies = [];

	walk(ast, (node) => {
		if ((node.kind === 'method' || node.kind === 'function') && node.body) bodies.push({ name: nameOf(node), body: node.body });
	});

	for (const { name, body } of bodies) {
		const env = new Map();
		const returns = [];
		const assigns = [];

		walkShallow(body, (node) => {
			if (node.kind === 'assign' && node.left && node.left.kind === 'variable' && node.left.name) {
				assigns.push({ var: String(node.left.name), expr: node.right });
			}
		});

		const resolveVar = (expr, depth = 0) => {
			if (!expr || depth > 6) return null;
			const lit = arrayLiteralShape(expr);
			if (lit) return lit;
			if (expr.kind === 'call' && nameOf(expr.what) === 'array_values') return { shape: 'list', keys: [] };
			if (expr.kind === 'variable') return env.get(String(expr.name)) || null;
			const inner = staticCallName(expr);
			if (inner && out.has(inner)) {
				const t = out.get(inner);
				if (t.opaque) return null;
				return t.shapes[0] || null;
			}
			return null;
		};

		for (let pass = 0; pass < 3; pass++) {
			for (const a of assigns) env.set(a.var, resolveVar(a.expr));
		}

		walkShallow(body, (node) => {
			if (node.kind !== 'return') return;
			const lit = arrayLiteralShape(node.expr);
			if (lit) {
				returns.push(lit);
				return;
			}
			if (node.expr && node.expr.kind === 'variable' && env.get(String(node.expr.name))) {
				returns.push(env.get(String(node.expr.name)));
				return;
			}
			returns.push(null);
		});

		out.set(name, { shapes: returns.filter(Boolean), opaque: returns.some((r) => !r) || returns.length === 0 });
	}

	return out;
}

/** خواندن کلیدها از متغیرها در یک فایل: `$pack['values']` → var → Set(keys). */
function keyReads(file) {
	const ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
	const reads = new Map();

	walk(ast, (node) => {
		if (node.kind !== 'offsetlookup' || !node.what || node.what.kind !== 'variable') return;
		if (!node.offset || node.offset.kind !== 'string') return;
		const v = String(node.what.name);
		if (!reads.has(v)) reads.set(v, new Set());
		reads.get(v).add(String(node.offset.value));
	});

	return reads;
}

/** `$pack = TBSM_Stock::fn(...)` → var → نام متد. */
function callAssignments(file) {
	const ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
	const map = new Map();

	walk(ast, (node) => {
		if (node.kind !== 'assign' || !node.left || node.left.kind !== 'variable') return;
		const name = staticCallName(node.right);
		if (name) map.set(String(node.left.name), name);
	});

	return map;
}

/**
 * payload هر اندپوینت از `endpoints()` (تنها جای ثبت) و `send_success()` همان
 * متد — با حل انتساب‌های خطی مثل `$result = array(...); send_success($result);`.
 */
function endpointPayloads(file) {
	const ast = parser.parseCode(fs.readFileSync(file, 'utf8'), file);
	const endpoints = new Map();
	const methods = new Map();

	walk(ast, (node) => {
		if (node.kind === 'method' && node.body) methods.set(nameOf(node), node.body);
	});

	// سبک تازه: نگاشت اکشن → متد داخل endpoints()
	const registry = methods.get('endpoints');
	if (registry) {
		walk(registry, (node) => {
			if (node.kind !== 'entry' || !node.key || node.key.kind !== 'string') return;
			if (!node.value || node.value.kind !== 'string') return;
			endpoints.set(String(node.key.value), { method: String(node.value.value), keys: new Set(), exprs: new Map(), vars: new Map() });
		});
	}

	// سبک قدیمی‌تر (نسخه‌های ۱٫۳٫۱ تا ۱٫۳٫۵): add_action( 'wp_ajax_x', array( __CLASS__, 'ajax_y' ) )
	if (!registry) {
		walk(ast, (node) => {
			if (node.kind !== 'call' || nameOf(node.what) !== 'add_action') return;
			const args = node.arguments || [];
			const actionArg = args[0];
			if (!actionArg || actionArg.kind !== 'string' || !String(actionArg.value).startsWith('wp_ajax_')) return;
			const action = String(actionArg.value).replace(/^wp_ajax_/, '');
			let method = '';
			for (const a of args) {
				walk(a, (inner) => {
					if (inner.kind === 'string' && /^ajax_/.test(String(inner.value))) method = String(inner.value);
				});
			}
			if (method) endpoints.set(action, { method, keys: new Set(), exprs: new Map(), vars: new Map() });
		});
	}

	for (const [, ep] of endpoints) {
		const body = methods.get(ep.method);
		if (!body) continue;

		walk(body, (n) => {
			if (n.kind === 'assign' && n.left && n.left.kind === 'variable') ep.vars.set(String(n.left.name), n.right);
		});

		walk(body, (n) => {
			if (n.kind !== 'call' || n.what.kind !== 'staticlookup' || nameOf(n.what.offset) !== 'send_success') return;
			let arg = (n.arguments || [])[0];
			if (arg && arg.kind === 'variable' && ep.vars.has(String(arg.name))) arg = ep.vars.get(String(arg.name));
			if (!arg || arg.kind !== 'array') return;
			for (const item of arg.items || []) {
				if (!item || !item.key || item.key.kind !== 'string') continue;
				const key = String(item.key.value);
				ep.keys.add(key);
				ep.exprs.set(key, item.value);
			}
		});
	}

	return endpoints;
}

/** شکل مقدار یک عبارت داخل payload (با حل متغیرهای محلی و متدهای PHP). */
function resolveShape(expr, env, fnShapes, depth = 0) {
	if (!expr || depth > 5) return null;
	const lit = arrayLiteralShape(expr);
	if (lit) return lit;
	if (expr.kind === 'variable') {
		const name = String(expr.name);
		if (!env.has(name)) return null;
		return resolveShape(env.get(name), env, fnShapes, depth + 1);
	}
	if (expr.kind === 'ternary') {
		return resolveShape(expr.trueExpr, env, fnShapes, depth + 1) || resolveShape(expr.falseExpr, env, fnShapes, depth + 1);
	}
	if (expr.kind === 'call' && nameOf(expr.what) === 'array_values') return { shape: 'list', keys: [] };
	const call = staticCallName(expr);
	if (call && fnShapes.has(call)) {
		const t = fnShapes.get(call);
		if (t.opaque) return { shape: 'unknown', keys: [] };
		return t.shapes[0] || { shape: 'unknown', keys: [] };
	}
	return null;
}

/* ---------------------------------------------------- سمت جاوااسکریپت */

/** اکشن‌هایی که JS صدا می‌زند + کلیدهایی که از پاسخ می‌خواند + انتظار آرایه. */
function jsContract(file) {
	const src = fs.readFileSync(file, 'utf8');
	const actions = new Set();
	for (const m of src.matchAll(/action\s*=\s*'(tbsm_[a-z_]+)'/g)) actions.add(m[1]);
	for (const m of src.matchAll(/action:\s*'(tbsm_[a-z_]+)'/g)) actions.add(m[1]);

	const expectations = new Map(); // action → Map(key → 'array'|'any')
	const ensure = (action) => {
		if (!expectations.has(action)) expectations.set(action, new Map());
		return expectations.get(action);
	};

	const re = /action\s*=\s*'(tbsm_[a-z_]+)'/g;
	let m;
	while ((m = re.exec(src))) {
		const action = m[1];
		const doneIdx = src.indexOf('.done(function', m.index);
		if (doneIdx === -1) continue;
		const open = src.indexOf('{', doneIdx);
		let depth = 0;
		let end = src.length - 1;
		for (let i = open; i < src.length; i++) {
			if (src[i] === '{') depth++;
			else if (src[i] === '}') {
				depth--;
				if (depth === 0) {
					end = i;
					break;
				}
			}
		}
		const body = src.slice(open + 1, end);
		const keys = ensure(action);
		const aliases = new Map(); // نام متغیر محلی → کلید پاسخ

		// نام‌های دیگری که به همان کلید اشاره می‌کنند (data.x / res.data.x)
		for (const am of body.matchAll(/(?:var|let|const)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:res\.)?data\.([A-Za-z_]\w*)/g)) aliases.set(am[1], am[2]);
		for (const am of body.matchAll(/\b(?:self|this)\.([A-Za-z_$][\w$]*)\s*=\s*(?:res\.)?data\.([A-Za-z_]\w*)/g)) aliases.set(am[1], am[2]);

		for (const km of body.matchAll(/\b(?:data|res\.data)\.([A-Za-z_]\w*)\b/g)) {
			const key = km[1];
			if (!keys.has(key)) keys.set(key, 'any');
			const before = body.slice(Math.max(0, km.index - 26), km.index);
			if (/\.length\s*$/.test(before) || /\[\s*$/.test(before)) keys.set(key, 'array');
		}
		for (const am of body.matchAll(/Array\.isArray\(\s*(?:data|res\.data)\.([A-Za-z_]\w*)\s*\)/g)) keys.set(am[1], 'array');
		for (const am of body.matchAll(/\b(?:data|res\.data)\.([A-Za-z_]\w*)\.length/g)) keys.set(am[1], 'array');
		for (const am of body.matchAll(/\b(?:data|res\.data)\.([A-Za-z_]\w*)\s*\[/g)) keys.set(am[1], 'array');

		// متغیرهای محلی که مثل آرایه مصرف می‌شوند
		for (const [alias, key] of aliases) {
			const esc = alias.replace(/[$]/g, '\\$');
			const listish = new RegExp(
				'(Array\\.isArray\\(\\s*' + esc + '\\s*\\)|\\.' + esc + '\\s*\\.length|\\b' + esc + '\\s*\\.length|\\b' + esc + '\\s*\\[|\\b' + esc + '\\s*\\.(map|filter|forEach|slice|indexOf)\\()'
			);
			if (listish.test(src)) keys.set(key, 'array');
		}
	}

	// رندرکننده‌ها: انتظارشان روی پارامتر (یک سطح indirection)
	const rendererReads = new Map();
	for (const rm of src.matchAll(/([A-Za-z_$][\w$]*)\(\s*(?:res\.data|data)\s*\|\|\s*\{\}\s*\)/g)) {
		const fn = rm[1];
		const hit = new RegExp('(?:^|\\n)\\t\\t' + fn + ':\\s*function\\s*\\(\\s*([A-Za-z_$][\\w$]*)\\s*\\)\\s*\\{').exec(src);
		if (!hit) continue;
		const open = src.indexOf('{', hit.index);
		let depth = 0;
		let end = src.length - 1;
		for (let i = open; i < src.length; i++) {
			if (src[i] === '{') depth++;
			else if (src[i] === '}') {
				depth--;
				if (depth === 0) {
					end = i;
					break;
				}
			}
		}
		const body = src.slice(open + 1, end);
		const param = hit[1];
		const keys = new Set();
		for (const km of body.matchAll(new RegExp('\\b' + param + '\\.([A-Za-z_]\\w*)', 'g'))) keys.add(km[1]);
		rendererReads.set(fn, { keys, tolerant: new RegExp('Array\\.isArray\\(\\s*' + param + '\\s*\\)').test(body) });
	}

	return { actions, expectations, rendererReads, src };
}

/* ---------------------------------------------------------------- اجرا */

const fnShapes = functionShapes(STOCK_FILE);
const reads = keyReads(AJAX_FILE);
const callAssign = callAssignments(AJAX_FILE);
const endpoints = endpointPayloads(AJAX_FILE);
const js = jsContract(JS_FILE);

const problems = [];
const notes = [];

// A) اندپوینت‌ها دو طرفه هم‌خوان باشند
for (const action of js.actions) {
	if (!endpoints.has(action)) problems.push(`اندپوینت «${action}» در JS صدا زده می‌شود ولی در PHP ثبت نشده است.`);
}
for (const action of endpoints.keys()) {
	if (!js.actions.has(action)) notes.push(`اندپوینت «${action}» در PHP ثبت است ولی در admin.js صدا زده نمی‌شود.`);
}

// C) کلیدی که PHP از نتیجهٔ یک متد می‌خواند باید در returnهای آن متد باشد
for (const [varName, methodName] of callAssign) {
	const wanted = reads.get(varName);
	if (!wanted || !wanted.size) continue;
	const t = fnShapes.get(methodName);
	if (!t || t.opaque) {
		notes.push(`شکل بازگشتی ${methodName}() قابل تحلیل ایستا نبود (return متغیر ناشناخته) — دستی بررسی شود.`);
		continue;
	}
	const keyed = t.shapes.filter((sh) => sh.shape === 'keyed');
	const lists = t.shapes.filter((sh) => sh.shape === 'list');

	for (const key of wanted) {
		if (!keyed.length) {
			problems.push(`کلید «${key}» از $${varName} = ${methodName}() خوانده می‌شود ولی هیچ returnی از آن متد آرایهٔ کلیددار نیست.`);
			continue;
		}
		if (lists.length) {
			problems.push(
				`کلید «${key}» از $${varName} = ${methodName}() خوانده می‌شود ولی بعضی returnهای آن متد آرایهٔ **تخت** هستند (شکل بازگشتی ناهمگون) — مقدار در زمان اجرا خالی/نادرست می‌شود.`
			);
			continue;
		}
		if (!keyed.some((sh) => sh.keys.includes(key))) {
			notes.push(`کلید «${key}» فقط در بعضی returnهای ${methodName}() هست (مسیر خطا/گارد) — بررسی شود.`);
		}
	}
}

// B) کلیدهای خوانده‌شده توسط JS باید در payload باشند
for (const [action, keys] of js.expectations) {
	const ep = endpoints.get(action);
	if (!ep) continue;
	for (const key of keys.keys()) {
		if (!ep.keys.has(key)) problems.push(`JS در پاسخ «${action}» کلید «${key}» را می‌خواند ولی PHP آن را نمی‌فرستد (كلیدهای PHP: ${[...ep.keys].join(', ')}).`);
	}
}

// D) انتظار آرایه در JS ↔ شکل PHP
for (const [action, keys] of js.expectations) {
	const ep = endpoints.get(action);
	if (!ep) continue;
	for (const [key, want] of keys) {
		if (want !== 'array' || !ep.exprs.has(key)) continue;
		const shape = resolveShape(ep.exprs.get(key), ep.vars, fnShapes);
		if (shape && shape.shape === 'keyed') {
			problems.push(`JS در «${action}» کلید «${key}» را آرایه می‌خواهد ولی PHP یک آرایهٔ کلیددار (${shape.keys.join(', ')}) می‌فرستد.`);
		}
	}
	for (const rm of js.src.matchAll(/([A-Za-z_$][\w$]*)\(\s*(?:res\.data|data)\s*\|\|\s*\{\}\s*\)/g)) {
		const info = js.rendererReads.get(rm[1]);
		if (!info || info.tolerant) continue;
		void info;
	}
}

/* ---------------------------------------------------------------- خروجی */

console.log('── بررسی قرارداد AJAX (PHP ↔ JS) ──');
console.log(`${endpoints.size} اندپوینت PHP · ${js.actions.size} اکشن در JS`);
for (const n of notes) console.log('• ' + n);
for (const p of problems) console.log('✗ ' + p);
console.log(problems.length ? `\n✗ ${problems.length} نقض قرارداد` : '\n✓ قرارداد PHP ↔ JS سالم است');
process.exit(problems.length ? 1 : 0);
