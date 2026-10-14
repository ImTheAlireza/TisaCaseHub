#!/usr/bin/env node
/**
 * هم‌خوانی مارک‌آپ بین `render_page()` و پیش‌نمایش —
 *
 * چرا؟ یک `</div>` اضافه در `render_page()` (نسخهٔ ۱٫۳٫۱۲) باعث شد گام ۲ حالت
 * گروهی بیرون از پنل خودش و گام ۳ بیرون از `.tbsm-wrap` رندر شود؛ پیش‌نمایش و
 * تست jsdom سالم می‌ماندند چون پیش‌نمایش مارک‌آپ درستِ خودش را داشت. این ابزار
 * همان شکاف را می‌گیرد: ساختار درختی (تگ + شناسه + کلاس‌ها) خروجی واقعی PHP را
 * با زیردرخت `.wrap.tbsm-wrap` در `design/preview/bulk-stock-manager.html` مقایسه
 * می‌کند؛ هر جابه‌جایی/کم‌شدن/اضافه‌شدن گره = خروج ۱.
 *
 * نکته: آیکون‌های SVG در PHP با `echo self::icon(...)` چاپ می‌شوند؛ اینجا جای هر
 * echo یک `<svg>` خالی گذاشته می‌شود تا با زیردرخت svg پیش‌نمایش هم‌ارز شود
 * (جزئیات داخل svg مقایسه نمی‌شود).
 *
 * اجرا: node tools/markup-parity.mjs        خروج ۱ = ناهم‌خوانی مارک‌آپ.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { JSDOM } = require('jsdom');

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const PHP_FILE = path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/includes/class-tbsm-admin.php');
const PREVIEW = path.join(ROOT, 'design/preview/bulk-stock-manager.html');

/** HTML داخل render_page() — اکوها با معادل ساختاری جایگزین می‌شوند. */
function renderPageHtml() {
	const src = fs.readFileSync(PHP_FILE, 'utf8');
	const at = src.indexOf('public static function render_page');
	if (at === -1) throw new Error('render_page() پیدا نشد');
	const seg = src.slice(at);
	const body = seg.slice(seg.indexOf('?>') + 2, seg.lastIndexOf('<?php'));
	return body
		.replace(/<\?php echo self::icon\([^)]*\);[^?]*\?>/g, '<svg></svg>')
		.replace(/<\?php[\s\S]*?\?>/g, '');
}

/** امضای ساختاری: ترتیب پیش‌روندی گره‌ها (تگ#شناسه.کلاس‌ها)؛ svg فقط یک برگ. */
function signature(root) {
	const out = [];
	const walk = (el) => {
		const tag = el.tagName.toLowerCase();
		if (tag === 'svg') {
			out.push('svg');
			return;
		}
		const cls = Array.from(el.classList || []).join('.');
		out.push(tag + (el.id ? '#' + el.id : '') + (cls ? '.' + cls : ''));
		for (const child of el.children) walk(child);
	};
	walk(root);
	return out;
}

const phpDom = new JSDOM('<!doctype html><html><body>' + renderPageHtml() + '</body></html>');
const phpWrap = phpDom.window.document.querySelector('.tbsm-wrap');
const prevDom = new JSDOM(fs.readFileSync(PREVIEW, 'utf8'));
const prevWrap = prevDom.window.document.querySelector('.wrap.tbsm-wrap');

if (!phpWrap || !prevWrap) {
	console.log('✗ ریشهٔ .tbsm-wrap در یکی از دو منبع پیدا نشد');
	process.exit(1);
}

const a = signature(phpWrap);
const b = signature(prevWrap);

let bad = 0;
if (a.length !== b.length) {
	bad++;
	console.log(`✗ شمار گره‌ها: render_page ${a.length} · پیش‌نمایش ${b.length}`);
}
const n = Math.max(a.length, b.length);
let shown = 0;
for (let i = 0; i < n && shown < 10; i++) {
	if (a[i] !== b[i]) {
		bad++;
		shown++;
		console.log(`✗ گره ${i}: render_page «${a[i] || '—'}» · پیش‌نمایش «${b[i] || '—'}»`);
	}
}

// شناسه‌ها (قرارداد JS) باید دقیقاً همان‌ها باشند — جدا هم چک می‌شود تا پیام روشن بماند.
const ids = (sig) => sig.filter((s) => s.includes('#')).map((s) => s.slice(s.indexOf('#')));
const ia = ids(a);
const ib = ids(b);
if (ia.join(',') !== ib.join(',')) {
	bad++;
	const missing = ia.filter((x) => !ib.includes(x));
	const extra = ib.filter((x) => !ia.includes(x));
	console.log('✗ شناسه‌ها هم‌خوان نیستند' + (missing.length ? ` · فقط در PHP: ${missing.join(', ')}` : '') + (extra.length ? ` · فقط در پیش‌نمایش: ${extra.join(', ')}` : ''));
}

console.log(bad
	? `✗ مارک‌آپ render_page و پیش‌نمایش یکی نیستند (${bad} اختلاف) — پیش‌نمایش را از PHP بازسازی کنید.`
	: `✓ مارک‌آپ render_page و پیش‌نمایش یکی است (${a.length} گره · ${ia.length} شناسه)`);
process.exit(bad ? 1 : 0);
