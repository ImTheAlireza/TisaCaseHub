#!/usr/bin/env node
/**
 * گارد چیدمان صفحه‌های TisaCase.
 *
 * چرا؟ باگ واقعی «صفحه تمام‌عرض شد / نوار خاکستری پهن در یک طرف» از یک قاعدهٔ
 * چیدمان در لایهٔ مشترک می‌آمد:
 *   ۱) `body.tisa-scope #wpcontent { padding-inline-start: 0 }` — پدینگ هستهٔ
 *      وردپرس (فاصلهٔ منوی کنار) را صفر می‌کرد.
 *   ۲) `.tisa-scope .wrap { margin-inline-start: 24px; margin-inline-end: 24px }`
 *      — به‌جای `auto`، پس کارت وسط نمی‌نشست و فضای خالی در یک طرف جمع می‌شد.
 * بدتر: اختصاصی‌بودن `.tisa-scope .wrap` از `.tbsm-wrap` بیشتر است، پس چیدمان
 * خودِ افزونهٔ ما هم بی‌صدا بازنویسی می‌شد. این ابزار همان قرارداد را می‌سنجد:
 *
 *   A) هیچ فایلی پدینگ/مارجین کنار `#wpcontent` را صفر نکند.
 *   B) هر قاعدهٔ `… .wrap` در لایهٔ مشترک، مارجین کنارش `auto` باشد.
 *   C) صفحهٔ افزونهٔ ما قاعدهٔ وسط‌چینی با اختصاصی‌بودن کافی داشته باشد
 *      (شناسه در سلکتور) تا قاعدهٔ بیرونی نتواند از مرکزش بیرون بیندازد.
 *   D) دو نسخهٔ tisacase-ui.css (مرجع طراحی و کپی هاب) دقیقاً یکی باشند.
 *
 * اجرا: node tools/layout-guard.mjs        خروج ۱ = نقض قرارداد چیدمان.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const SHARED = [
	'design/tisacase-ui.css',
	'plugins/tisacase-hub/assets/tisacase-ui.css',
];
const PAGES_CSS = [
	'plugins/src/tisacase-bulk-stock-manager/assets/admin.css',
	'plugins/tisacase-hub/assets/hub.css',
];
const ALL = [...SHARED, ...PAGES_CSS];

/** قاعده‌های یک فایل CSS به‌صورت {selector, body, line}. (بدون @media تو در تو) */
function rules(file) {
	const raw = fs.readFileSync(path.join(ROOT, file), 'utf8');
	// کامنت‌ها حذف می‌شوند تا سلکتور/پیام‌ها تمیز بمانند (منطق قاعده‌ها تغییر نمی‌کند).
	const src = raw
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.split('\n')
		.map((line, i) => (line.trim().startsWith('*') || line.trim().startsWith('//') ? '' : line))
		.join('\n');
	const out = [];
	const re = /([^{}]+)\{([^{}]*)\}/g;
	let m;
	while ((m = re.exec(src))) {
		const selector = m[1].trim().replace(/\s+/g, ' ');
		if (!selector || selector.startsWith('@')) continue;
		out.push({ selector, body: m[2], line: src.slice(0, m.index).split('\n').length });
	}
	return out;
}

const problems = [];
const notes = [];

/* A) کنار #wpcontent دست نزن */
for (const file of ALL) {
	for (const r of rules(file)) {
		if (!/#wpcontent/.test(r.selector)) continue;
		const zeroInline = /(padding-inline-start|padding-inline-end|padding-left|padding-right)\s*:\s*0/.test(r.body);
		if (zeroInline) {
			problems.push(`${file}:${r.line} — «${r.selector}» پدینگ کنارِ #wpcontent را صفر می‌کند؛ فاصلهٔ منو و مرکزچینی به‌هم می‌ریزد.`);
		}
	}
}

/* B) قاعده‌های .wrap در لایهٔ مشترک باید auto باشند */
for (const file of SHARED) {
	for (const r of rules(file)) {
		if (!/\.wrap\b/.test(r.selector)) continue;
		const hasInline = /margin-inline(-start|-end)?\s*:/.test(r.body);
		if (!hasInline) continue;
		const auto =
			/margin-inline\s*:\s*auto/.test(r.body) ||
			(/margin-inline-start\s*:\s*auto/.test(r.body) && /margin-inline-end\s*:\s*auto/.test(r.body));
		if (!auto) {
			const fixed = /margin-inline(-start|-end)?\s*:\s*[^;]*\d/.test(r.body);
			if (fixed) {
				problems.push(`${file}:${r.line} — «${r.selector}» مارجین ثابت دارد؛ باید auto باشد وگرنه کارت وسط نمی‌نشیند.`);
			} else {
				notes.push(`${file}:${r.line} — «${r.selector}» مارجین کنارش auto نیست؛ اگر عمدی است نادیده بگیرید.`);
			}
		}
	}
}

/* C) صفحهٔ خودمان: وسط‌چینی با اختصاصی‌بودن کافی */
{
	const file = 'plugins/src/tisacase-bulk-stock-manager/assets/admin.css';
	const rs = rules(file);
	const centered = rs.filter((r) => /#|\.\w+\.[\w-]+/.test(r.selector) && /margin-inline\s*:\s*auto/.test(r.body));
	if (!centered.length) {
		problems.push(`${file} — قاعدهٔ وسط‌چینی با اختصاصی‌بودن کافی (شناسه یا دو کلاس) پیدا نشد؛ ممکن است لایهٔ مشترک آن را بازنویسی کند.`);
	} else {
		notes.push(`${file}:${centered[0].line} — «${centered[0].selector}» وسط‌چین است ✓`);
	}
}

/* C۲) صفحهٔ ما باید فوتر پیشخوان را پنهان کند و عرض کارت‌ها را قفل کند */
{
	const file = 'plugins/src/tisacase-bulk-stock-manager/assets/admin.css';
	const css = fs.readFileSync(path.join(ROOT, file), 'utf8');
	if (!/body\.tbsm-page\s+#wpfooter\s*\{[^}]*display\s*:\s*none/.test(css)) {
		problems.push(`${file} — قاعدهٔ پنهان‌کردن فوتر پیشخوان (body.tbsm-page #wpfooter) نیست؛ «نگارش وردپرس» وسط صفحه می‌افتد.`);
	}
	if (!/\.tbsm-wrap\s+\.tbsm-card\s*\{[^}]*max-width\s*:\s*100%/.test(css)) {
		problems.push(`${file} — قفل عرض کارت‌های گام (.tbsm-wrap .tbsm-card) نیست؛ دو کارت می‌توانند ناهم‌عرض شوند.`);
	}
	const php = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/includes/class-tbsm-admin.php'), 'utf8');
	if (!/add_filter\(\s*'admin_body_class'/.test(php) || !/' tbsm-page'/.test(php)) {
		problems.push('class-tbsm-admin.php — کلاس بدنهٔ tbsm-page روی صفحهٔ ما اضافه نمی‌شود.');
	} else {
		notes.push('کلاس بدنهٔ tbsm-page فقط روی صفحهٔ ما اضافه می‌شود ✓');
	}
}

/* D) دو نسخهٔ لایهٔ مشترک باید یکی باشند */
{
	const a = fs.readFileSync(path.join(ROOT, SHARED[0]), 'utf8');
	const b = fs.readFileSync(path.join(ROOT, SHARED[1]), 'utf8');
	if (a !== b) {
		problems.push(`${SHARED[0]} با ${SHARED[1]} یکی نیست — با tools/build-hub-zip.sh هم‌گامش کنید.`);
	} else {
		notes.push('دو نسخهٔ tisacase-ui.css یکی هستند ✓');
	}
}

console.log('── گارد چیدمان صفحه ──');
for (const n of notes) console.log('• ' + n);
for (const p of problems) console.log('✗ ' + p);
console.log(problems.length ? `\n✗ ${problems.length} نقض چیدمان` : '\n✓ قرارداد چیدمان سالم است');
process.exit(problems.length ? 1 : 0);
