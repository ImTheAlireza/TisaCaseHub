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
	'plugins/src/tisacase-optimizer/assets/admin.css',
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

/* C, C۲, C۳) صفحه‌های افزونه‌های اختصاصی: وسط‌چینی، قفل عرض کارت‌ها، مخفی‌سازی فوتر و منوی خکوب */
const PLUGIN_TARGETS = [
	{
		name: 'tisacase-bulk-stock-manager',
		css: 'plugins/src/tisacase-bulk-stock-manager/assets/admin.css',
		js: 'plugins/src/tisacase-bulk-stock-manager/assets/admin.js',
		php: 'plugins/src/tisacase-bulk-stock-manager/includes/class-tbsm-admin.php',
		bodyClass: 'tbsm-page',
		wrapClass: 'tbsm-wrap',
		cardClass: 'tbsm-card',
	},
	{
		name: 'tisacase-optimizer',
		css: 'plugins/src/tisacase-optimizer/assets/admin.css',
		js: 'plugins/src/tisacase-optimizer/assets/admin.js',
		php: 'plugins/src/tisacase-optimizer/includes/class-tco-admin-page.php',
		bodyClass: 'tco-page',
		wrapClass: 'tco-wrap',
		cardClass: 'tco-card',
	},
];

for (const t of PLUGIN_TARGETS) {
	const rs = rules(t.css);
	const centered = rs.filter((r) => /#|\.\w+\.[\w-]+/.test(r.selector) && /margin-inline\s*:\s*auto/.test(r.body));
	if (!centered.length) {
		problems.push(`${t.css} — قاعدهٔ وسط‌چینی با اختصاصی‌بودن کافی (شناسه یا دو کلاس) پیدا نشد.`);
	} else {
		notes.push(`${t.css}:${centered[0].line} — «${centered[0].selector}» وسط‌چین است ✓`);
	}

	const css = fs.readFileSync(path.join(ROOT, t.css), 'utf8');
	const footerRe = new RegExp(`body\\.${t.bodyClass}\\s+#wpfooter\\s*\\{[^}]*display\\s*:\\s*none`);
	const cardMaxRe = new RegExp(`\\.${t.wrapClass}\\s+\\.${t.cardClass}[^{]*\\{[^}]*max-width\\s*:\\s*min\\(\\s*100%\\s*,\\s*var\\(--tisa-max-w`);
	const cardMarginRe = new RegExp(`\\.${t.wrapClass}\\s+\\.${t.cardClass}[^{]*\\{[^}]*margin-inline\\s*:\\s*auto`);

	if (!footerRe.test(css)) {
		problems.push(`${t.css} — قاعدهٔ پنهان‌کردن فوتر پیشخوان (body.${t.bodyClass} #wpfooter) نیست.`);
	}
	if (!cardMaxRe.test(css) || !cardMarginRe.test(css)) {
		problems.push(`${t.css} — قفل اندازهٔ کارت‌ها در سطح خودِ کارت نیست (max-width: min(100%, var(--tisa-max-w)) + margin-inline: auto).`);
	}

	const php = fs.readFileSync(path.join(ROOT, t.php), 'utf8');
	if (!/add_filter\(\s*'admin_body_class'/.test(php) || !php.includes(`' ${t.bodyClass}'`)) {
		problems.push(`${t.php} — کلاس بدنهٔ ${t.bodyClass} روی صفحهٔ افزونه اضافه نمی‌شود.`);
	} else {
		notes.push(`${t.name}: کلاس بدنهٔ ${t.bodyClass} فقط روی صفحهٔ خودش اضافه می‌شود ✓`);
	}

	const pinRe = new RegExp(`@media screen and \\(min-width: 783px\\)[\\s\\S]{0,400}body\\.${t.bodyClass} #adminmenuwrap\\s*\\{[\\s\\S]{0,300}position:\\s*fixed`);
	const ovfRe = new RegExp(`body\\.${t.bodyClass} #adminmenuwrap\\s*\\{[\\s\\S]{0,300}overflow-y:\\s*auto`);
	if (!pinRe.test(css) || !ovfRe.test(css)) {
		problems.push(`${t.css} — بلوک خکوب‌کردن منو (body.${t.bodyClass} #adminmenuwrap: fixed + overflow-y:auto از ۷۸۳px) نیست.`);
	} else {
		notes.push(`${t.name}: منوی پیشخوان خکوب است ✓`);
	}

	const js = fs.readFileSync(path.join(ROOT, t.js), 'utf8');
	if (!/function menuFlyoutEscape/.test(js) || !/\$\(menuFlyoutEscape\)/.test(js)) {
		problems.push(`${t.js} — تابع menuFlyoutEscape نیست یا صدا زده نمی‌شود.`);
	} else if (!/'fixed' !== window\.getComputedStyle\(wrap\)\.position/.test(js)) {
		problems.push(`${t.js} — menuFlyoutEscape بدون بررسی «منو خکوب است» اجرا می‌شود.`);
	} else {
		notes.push(`${t.name}: زیرمنوهای کشویی از قید overflow بیرون می‌آیند ✓`);
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
