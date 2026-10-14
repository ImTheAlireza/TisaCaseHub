#!/usr/bin/env node
/**
 * هندسهٔ واقعی صفحه — اندازه‌گیری با موتور مرورگر (Chromium).
 *
 * چرا؟ «چرا بخش پایین با کارت بالایی هم‌اندازه نیست / چرا صفحه وسط نیست» با
 * حدس‌زدن حل نمی‌شود. این اسکریپت یک پیشخوان وردپرسِ RTL با **CSS واقعی هستهٔ
 * ۷٫۱٫۳** (فیکسچرهای `tools/fixtures/wp-7.1.3/`) می‌سازد، مارک‌آپ واقعی افزونه
 * را داخلش می‌گذارد و عرض/جای همهٔ کارت‌ها را می‌سنجد — در سه حالت:
 *
 *   before  — قواعد معیوب نسخه‌های قدیمی لایهٔ مشترک + CSS قدیمی افزونه
 *   after   — فقط CSS فعلی مخزن
 *   hostile — CSS فعلی + یک stylesheet «بیگانه» که عمداً عرض پدر را باز می‌کند
 *             (شبیه‌سازی افزونهٔ دیگر/ترکیب‌کنندهٔ CSS). کارت‌ها باید هم‌اندازه
 *             و متقارن بمانند، چون سقف عرض و وسط‌چینی روی خودِ کارت نشسته است.
 *
 * معیار قبولی: عرض هیرو و همهٔ کارت‌ها یکسان، فاصلهٔ دو طرف متقارن (≤۲px)، و
 * خط «نگارش وردپرس» در فوتر پنهان.
 *
 * پیش‌نیاز (ابزار توسعه، داخل گیت بیلد نیست):
 *   npm install --no-save jsdom puppeteer-core @sparticuz/chromium
 * اجرا:  node tools/layout-geometry.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import os from 'node:os';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

const { JSDOM } = require('jsdom');
const puppeteer = require('puppeteer-core');

/*
 * بستهٔ @sparticuz/chromium کتابخانه‌های مشترک را فقط روی Amazon Linux 2023
 * باز می‌کند؛ اینجا دستی از همان بسته باز می‌کنیم (در /tmp، هر اجرا).
 */
function ensureChromiumLibs() {
	const dir = path.join(os.tmpdir(), 'al2023', 'lib');
	if (fs.existsSync(path.join(dir, 'libnss3.so'))) return dir;
	const br = path.join(ROOT, 'node_modules/@sparticuz/chromium/bin/al2023.tar.br');
	const tarPath = path.join(os.tmpdir(), 'al2023.tar');
	fs.mkdirSync(path.dirname(dir), { recursive: true });
	if (!fs.existsSync(tarPath)) {
		fs.writeFileSync(tarPath, zlib.brotliDecompressSync(fs.readFileSync(br)));
	}
	execFileSync('tar', ['-xf', tarPath, '-C', path.dirname(dir)]);
	return dir;
}

const libDir = ensureChromiumLibs();
process.env.LD_LIBRARY_PATH = [libDir, process.env.LD_LIBRARY_PATH || ''].filter(Boolean).join(':');
const chromium = (await import('file://' + path.join(ROOT, 'node_modules/@sparticuz/chromium/build/index.js'))).default;

/* ---------- ۱) مارک‌آپ واقعی افزونه (همان مارک‌آپ render_page) ---------- */
const preview = read('design/preview/bulk-stock-manager.html');
const wrapHtml = new JSDOM(preview).window.document.querySelector('.wrap.tbsm-wrap').outerHTML;
if (!wrapHtml) throw new Error('مارک‌آپ .wrap.tbsm-wrap در فایل پیش‌نمایش پیدا نشد');

/* ---------- ۲) CSS واقعی هستهٔ ۷٫۱٫۳ (RTL) ---------- */
const CORE = ['l10n-rtl', 'common-rtl', 'forms-rtl', 'admin-menu-rtl']
	.map((f) => read(`tools/fixtures/wp-7.1.3/${f}.css`)).join('\n');

/* ---------- ۳) هاب و افزونه ---------- */
const hubCss = read('plugins/tisacase-hub/assets/tisacase-ui.css') + read('plugins/tisacase-hub/assets/hub.css');
const adminCss = read('plugins/src/tisacase-bulk-stock-manager/assets/admin.css');

/* ---------- ۴) پیشخوان بیگانه (شبیه‌سازی قاعدهٔ مزاحم) ---------- */
/* مزاحم واقع‌بینانه: قاعده‌های کلاس‌محور مثل یک افزونه/قالب دیگر (بدون !important) */
const HOSTILE = `
	.wrap { margin-inline: 24px 4px; }
	.tisa-scope .wrap { margin-inline-start: 24px; margin-inline-end: 4px; }
	.tisa-scope .wrap.wrap { margin-inline-start: 24px; margin-inline-end: 4px; }
	.tbsm-wrap { max-width: none; }
`;
/* مزاحم افراطی: با !important — هیچ CSS سالمی در برابرش ایمن نیست؛
   تنها معیار این حالت این است که «کارت‌ها از هم جدا نیفتند». */
const EXTREME = `
	.tbsm-wrap { max-width: none !important; margin-inline: 0 !important; }
	.tbsm-card, .tbsm-hero { max-width: none !important; }
`;
/* کنترل مثبت: عمداً کج می‌کنیم تا مطمئن شویم سنجه کوریِ چشم ندارد. */
const CONTROL = `
	#wpbody-content .tbsm-wrap { margin-inline-start: 0 !important; margin-inline-end: 240px !important; }
`;

const menuItems = ['پیشخوان', 'نوشته‌ها', 'رسانه', 'برگه‌ها', 'دیدگاه‌ها', 'ووکامرس', 'محصولات', 'سفارش‌ها', 'تحلیل‌ها', 'افزونه‌ها', 'کاربران', 'ابزارها', 'تنظیمات']
	.map((t) => `<li class="wp-first-item"><a href="#"><div class="wp-menu-arrow"></div><div class="wp-menu-name">${t}</div></a></li>`).join('');

function shell({ hub, admin, hostile, extreme, control }) {
	return `<!doctype html>
<html lang="fa-IR" class="wp-toolbar" dir="rtl">
<head><meta charset="utf-8">
<style>${CORE}</style>
<style>${hub}</style>
<style>${admin}</style>
${hostile ? `<style>${HOSTILE}</style>` : ''}${extreme ? `<style>${EXTREME}</style>` : ''}${control ? `<style>${CONTROL}</style>` : ''}
</head>
<body class="wp-admin wp-core-ui woocommerce_page_tisacase-bulk-stock-manager tisa-scope tbsm-page">
<div id="wpwrap">
	<div id="adminmenumain" role="navigation" aria-label="منوی مدیریت">
		<div id="adminmenuback"></div>
		<div id="adminmenuwrap"><ul id="adminmenu">${menuItems}</ul></div>
	</div>
	<div id="wpcontent">
		<div id="wpbody" role="main">
			<div id="wpbody-content">
				${wrapHtml}
				<div class="clear"></div>
			</div>
			<div id="wpfooter" role="contentinfo">
				<p class="alignleft">از وردپرس سپاسگزاریم…</p>
				<p class="alignright">نگارش ۷.۱.۳</p>
			</div>
		</div>
	</div>
</div>
</body></html>`;
}

/* ---------- ۵) سنجش ---------- */
const CARDS = [
	['هیرو', '.tbsm-hero'],
	['پنل محدوده', '#tbsm-panel-group'],
	['گام ۱', '#tbsm-panel-group .tbsm-card'],
	['گام ۲', '#tbsm-step-model'],
	['گام ۳', '#tbsm-step-cards'],
];

async function measure(page, variant, width) {
	await page.setViewport({ width, height: 1000, deviceScaleFactor: 1 });
	await page.setContent(shell(variant), { waitUntil: 'load' });
	await page.evaluate(() => {
		// حالتی که کاربر واقعاً می‌بیند: تب گروهی باز، کارت‌ها فعال
		document.querySelectorAll('.tbsm-step-locked').forEach((e) => e.classList.remove('tbsm-step-locked'));
		document.getElementById('tbsm-panel-single').setAttribute('hidden', '');
		document.getElementById('tbsm-panel-group').removeAttribute('hidden');
		document.getElementById('tbsm-grid').removeAttribute('hidden');
	});
	return page.evaluate((cards) => {
		const content = document.getElementById('wpbody-content').getBoundingClientRect();
		const out = {
			content: Math.round(content.width),
			wrap: (() => { const b = document.querySelector('.tbsm-wrap').getBoundingClientRect(); return Math.round(b.width); })(),
			cards: {},
			footerHidden: getComputedStyle(document.querySelector('#wpfooter .alignright')).display === 'none'
				|| getComputedStyle(document.getElementById('wpfooter')).display === 'none',
		};
		for (const [label, sel] of cards) {
			const el = document.querySelector(sel);
			if (!el) continue;
			const b = el.getBoundingClientRect();
			out.cards[label] = {
				w: Math.round(b.width),
				gapStart: Math.round(content.right - b.right), // فاصله تا لبهٔ راست (سمت منو)
				gapEnd: Math.round(b.left - content.left),     // فاصله تا لبهٔ چپ
			};
		}
		return out;
	}, CARDS);
}

const variants = {
	after: { hub: hubCss, admin: adminCss },
	hostile: { hub: hubCss, admin: adminCss, hostile: true },
	extreme: { hub: hubCss, admin: adminCss, extreme: true },
	control: { hub: hubCss, admin: adminCss, control: true }, // باید کج شود (خودآزمایی سنجه)
};

const results = {};
const browser = await puppeteer.launch({
	args: [...chromium.args, '--font-render-hinting=none'],
	executablePath: await chromium.executablePath(),
	headless: true,
});
try {
	const page = await browser.newPage();
	for (const [name, variant] of Object.entries(variants)) {
		for (const width of [1440, 1920]) {
			results[`${name}@${width}`] = await measure(page, variant, width);
		}
	}
} finally {
	await browser.close();
}

console.log('\n════════ هندسهٔ واقعی صفحه (Chromium · CSS واقعی هستهٔ ۷٫۱٫۳ · RTL) ════════');
const rows = [];
for (const [key, m] of Object.entries(results)) {
	const [name, width] = key.split('@');
	console.log(`\n── ${name} · پنجرهٔ ${width}px  (ناحیهٔ محتوا ${m.content}px · wrap ${m.wrap}px)`);
	for (const [label, c] of Object.entries(m.cards)) {
		console.log(`   ${label.padEnd(11)} ${String(c.w).padStart(5)}px   فاصله: راست ${String(c.gapStart).padStart(4)} · چپ ${String(c.gapEnd).padStart(4)}   → انحراف ${Math.abs(c.gapStart - c.gapEnd)}px`);
		rows.push({ name, width, label, ...c, footerHidden: m.footerHidden });
	}
	console.log(`   خط نگارش در فوتر: ${m.footerHidden ? 'پنهان ✓' : 'دیده می‌شود ✗'}`);
}

const problems = [];
for (const r of rows) {
	if (r.name === 'control') continue; // کنترل مثبت، جداگانه بررسی می‌شود
	const widths = new Set(Object.values(results[`${r.name}@${r.width}`].cards).map((c) => c.w));
	// در هر حالتی — حتی مزاحمِ افراطی — کارت‌ها نباید از هم جدا بیفتند.
	if (widths.size > 1) {
		problems.push(`[${r.name}@${r.width}] عرض کارت‌ها یکسان نیست: ${[...widths].join(' / ')}px`);
	}
	// تقارن فقط تا حالت مزاحمِ واقع‌بینانه معیار است (افراطی با !important قابل دفاع نیست).
	if (r.name !== 'extreme' && Math.abs(r.gapStart - r.gapEnd) > 2) {
		problems.push(`[${r.name}@${r.width}] «${r.label}» متقارن نیست: راست ${r.gapStart} · چپ ${r.gapEnd}`);
	}
	if (r.name !== 'extreme' && !r.footerHidden) {
		problems.push(`[${r.name}@${r.width}] خط نگارش وردپرس دیده می‌شود.`);
	}
}
// خودآزمایی سنجه: در حالت کنترل، انحراف باید دیده شود؛ وگرنه سنجه کور است.
for (const width of [1440, 1920]) {
	const c = results[`control@${width}`].cards['گام ۳'];
	if (Math.abs(c.gapStart - c.gapEnd) <= 40) {
		problems.push(`[control@${width}] کنترل مثبت: انحراف عمدی دیده نشد (${Math.abs(c.gapStart - c.gapEnd)}px) — سنجه معتبر نیست.`);
	}
}

const uniq = [...new Set(problems)];
console.log('\n' + (uniq.length ? '✗ ' + uniq.join('\n✗ ') : '✓ در هر سه حالت: کارت‌ها هم‌اندازه، چیدمان متقارن، نگارش وردپرس پنهان'));
process.exit(uniq.length ? 1 : 0);
