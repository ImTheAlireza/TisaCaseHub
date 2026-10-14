#!/usr/bin/env node
/**
 * هم‌خوانی کلیدهای ترجمه (i18n) بین سه جا:
 *   ۱) آرایهٔ `i18n` در `class-tbsm-admin.php`  (سرور)
 *   ۲) `window.tbsmData.i18n` در پیش‌نمایش      (همان قرارداد برای تست)
 *   ۳) `i18n('…')` در `assets/admin.js`          (مصرف‌کننده)
 *
 * چرا؟ قرارداد ما این است که پیش‌نمایش و تست، «همان چیزی» را ببینند که سایت
 * می‌بیند. اگر کلیدی در PHP اضافه/حذف شود و پیش‌نمایش عقب بماند، تست‌ها سبز
 * می‌مانند ولی روی سایت متن خام (`scopeAllLoading`) دیده می‌شود. این ابزار
 * همین شکاف را می‌گیرد.
 *
 * PHP با پارسر واقعی خوانده می‌شود (نه regex)؛ پیش‌نمایش با jsdom اجرا می‌شود.
 * خروج ۱ = ناهم‌خوانی.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(ROOT, 'package.json'));
const P = require('php-parser');
const { JSDOM } = require('jsdom');

const BASE = path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager');
const PHP_FILE = path.join(BASE, 'includes/class-tbsm-admin.php');
const JS_FILE = path.join(BASE, 'assets/admin.js');
const PREVIEW = path.join(ROOT, 'design/preview/bulk-stock-manager.html');

/* ---------- ۱) کلیدهای PHP (با پارسر) ---------- */
const parser = new P({ parser: { php7: true, suppressErrors: true }, ast: {} });
const ast = parser.parseCode(fs.readFileSync(PHP_FILE, 'utf8'), 'admin.php');

function walk(node, visit) {
	if (!node || typeof node !== 'object') return;
	if (Array.isArray(node)) { node.forEach((c) => walk(c, visit)); return; }
	if (typeof node.kind === 'string') visit(node);
	for (const key of Object.keys(node)) {
		if (key === 'loc' || key.startsWith('leading') || key.startsWith('trailing')) continue;
		const v = node[key];
		if (v && typeof v === 'object') walk(v, visit);
	}
}

const STRING_KINDS = new Set(['string', 'encapsed']);
function literal(node) {
	if (!node) return null;
	if (node.kind === 'string') return String(node.value);
	if (node.kind === 'encapsed') {
		return (node.value || []).map((p) => (typeof p === 'string' ? p : p && (p.value || ''))).join('');
	}
	return null;
}

let phpKeys = null;
walk(ast, (node) => {
	if (phpKeys || node.kind !== 'array') return;
	const items = node.items || [];
	if (!items.length) return;
	const pairs = items.map((it) => (it && it.key ? literal(it.key) : null));
	// آرایهٔ i18n: همهٔ کلیدها رشته‌اند و مقادیرشان رشته
	if (pairs.every((k) => k !== null) && pairs.includes('scopeApply') && items.every((it) => literal(it.value) !== null)) {
		phpKeys = pairs;
	}
});
if (!phpKeys) {
	console.error('✗ آرایهٔ i18n در PHP پیدا نشد.');
	process.exit(2);
}

/* ---------- ۲) کلیدهای پیش‌نمایش (اجرای واقعی) ---------- */
const html = fs.readFileSync(PREVIEW, 'utf8');
const dom = new JSDOM(html.replace(/<script[\s\S]*?<\/script>/g, ''), { runScripts: 'dangerously' });
const $ = require('jquery')(dom.window);
dom.window.jQuery = dom.window.$ = $;
dom.window.eval([...html.matchAll(/<script>([\s\S]*?)<\/script>/g)][0][1]);
const previewKeys = Object.keys((dom.window.tbsmData && dom.window.tbsmData.i18n) || {});
if (!previewKeys.length) {
	console.error('✗ window.tbsmData.i18n در پیش‌نمایش خالی است.');
	process.exit(2);
}

/* ---------- ۳) کلیدهای مصرف‌شده در JS ---------- */
const js = fs.readFileSync(JS_FILE, 'utf8');
const used = [...new Set([...js.matchAll(/i18n\(\s*'([A-Za-z][A-Za-z0-9]*)'/g)].map((m) => m[1]))];

/* ---------- گزارش ---------- */
const setPhp = new Set(phpKeys);
const setPv = new Set(previewKeys);
const onlyPhp = phpKeys.filter((k) => !setPv.has(k));
const onlyPv = previewKeys.filter((k) => !setPhp.has(k));
const usedMissing = used.filter((k) => !setPhp.has(k));

console.log('── هم‌خوانی کلیدهای ترجمه ──');
console.log(`• PHP: ${phpKeys.length} کلید · پیش‌نمایش: ${previewKeys.length} کلید · JS: ${used.length} کلید مصرف می‌کند`);

const problems = [];
if (onlyPhp.length) problems.push('فقط در PHP (پیش‌نمایش عقب است): ' + onlyPhp.join(', '));
if (onlyPv.length) problems.push('فقط در پیش‌نمایش (PHP عقب است): ' + onlyPv.join(', '));
if (usedMissing.length) problems.push('JS کلیدِ ناموجود در PHP صدا می‌زند: ' + usedMissing.join(', '));
for (const p of problems) console.log('✗ ' + p);

console.log(problems.length ? `\n✗ ${problems.length} ناهم‌خوانی` : '\n✓ کلیدها سه‌طرفه هم‌خوان‌اند');
process.exit(problems.length ? 1 : 0);
