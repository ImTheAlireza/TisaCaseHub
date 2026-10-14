#!/usr/bin/env node
/**
 * هندسهٔ واقعی صفحه — اندازه‌گیری با موتور مرورگر (Chromium).
 *
 * چرا؟ چون «چرا کارت پایین با کارت بالا هم‌اندازه نیست / چرا صفحه وسط نیست»
 * با حدس‌زدن حل نمی‌شود. این اسکریپت یک پیشخوان وردپرسِ RTL کوچک می‌سازد
 * (منوی ۱۶۰px سمت راست + `#wpcontent` با قواعد واقعی هستهٔ ۷٫۱٫۳)، مارک‌آپ
 * واقعی افزونه را داخلش می‌گذارد و **عرض/جای** کارت‌ها را در دو حالت می‌سنجد:
 *
 *   before — قواعد معیوبِ نسخه‌های قدیمی لایهٔ مشترک (padding-inline-start:0 و
 *            مارجین ثابت `.tisa-scope .wrap`) دوباره اعمال می‌شوند.
 *   after  — فقط CSS فعلی مخزن.
 *
 * معیار قبولی: (۱) کارت‌های گام ۲ و گام ۳ عرض یکسان، (۲) فاصلهٔ راست/چپِ کارت
 * تا لبه‌های ناحیهٔ محتوا متقارن (اختلاف ≤ ۱px)، (۳) نسخهٔ وردپرس در `#wpfooter`
 * روی صفحهٔ ما نمایش داده نشود.
 *
 * اجرا:  node tools/layout-geometry.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import puppeteer from 'puppeteer-core';
import chromium from '@sparticuz/chromium';

/*
 * بستهٔ chromium@sparticuz فقط وقتی خودش را روی Amazon Linux 2023 ببیند
 * کتابخانه‌های مشترک (libnss3/libnspr4/…) را باز می‌کند. اینجا آن‌ها را
 * دستی از همان بستهٔ همراه باز می‌کنیم تا مرورگر در این محیط هم بالا بیاید.
 * (کتابخانه‌ها در /tmp باز می‌شوند؛ persistence لازم نیست، هر اجرا باز می‌شوند.)
 */
function ensureChromiumLibs() {
	const base = path.join(require('node:os').tmpdir(), 'al2023', 'lib');
	const br = path.join(ROOT, 'node_modules/@sparticuz/chromium/bin/al2023.tar.br');
	if (fs.existsSync(path.join(base, 'libnss3.so'))) return base;
	fs.mkdirSync(path.dirname(base), { recursive: true });
	const tarPath = path.join(require('node:os').tmpdir(), 'al2023.tar');
	if (!fs.existsSync(tarPath)) {
		fs.writeFileSync(tarPath, zlib.brotliDecompressSync(fs.readFileSync(br)));
	}
	require('node:child_process').execFileSync('tar', ['-xf', tarPath, '-C', path.dirname(base)]);
	return base;
}

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

/* ---------- ۱) مارک‌آپ واقعی افزونه (از فایل پیش‌نمایش، همان مارک‌آپ render_page) ---------- */
const preview = read('design/preview/bulk-stock-manager.html');
const dom = new JSDOM(preview);
const wrapNode = dom.window.document.querySelector('.wrap.tbsm-wrap');
if (!wrapNode) throw new Error('مارک‌آپ .wrap.tbsm-wrap در فایل پیش‌نمایش پیدا نشد');
// فقط بخش چندمحصولی/گروهی را می‌خواهیم؛ یادداشت پیش‌نمایش بیرون است.
const wrapHtml = wrapNode.outerHTML;

/* ---------- ۲) پیشخوان کوچک RTL با قواعد واقعی هستهٔ ۷٫۱٫۳ ---------- */
const CORE = `
	html { box-sizing: border-box; }
	body { margin: 0; background: #F0F0F1; color: #3C434A; font-family: sans-serif; }
	a { text-decoration: none; }
	#adminmenumain { position: relative; }
	#adminmenu { float: right; width: 160px; margin: 0; padding: 0; background: #1D2327; color: #C3C4C7; list-style: none; min-height: 100vh; }
	#adminmenu li { padding: 8px 12px; font-size: 13px; }
	#wpcontent { height: 100%; padding-right: 20px; }
	#wpcontent, #wpfooter { margin-right: 160px; }
	#wpbody { position: relative; }
	#wpbody-content { padding-bottom: 65px; float: right; width: 100%; overflow: visible; }
	#wpfooter { position: absolute; bottom: 0; right: 0; left: 0; padding: 10px 0 0; color: #50575E; }
	#wpfooter p { font-size: 13px; margin: 0; line-height: 1.55; }
	.alignleft { float: right; }   /* در RTL */
	.alignright { float: left; }   /* در RTL */
	.clear { clear: both; }
	#wpfooter .alignright { direction: ltr; }
`;

const BROKEN_HUB = `
	/* قواعد معیوب نسخه‌های قدیمی لایهٔ مشترک — فقط برای شبیه‌سازی «قبل» */
	body.tisa-scope #wpcontent { padding-inline-start: 0; }
	.tisa-scope .wrap {
		margin-inline-start: var(--tisa-sp-5);
		margin-inline-end: var(--tisa-sp-5);
	}
`;

// نسخهٔ «قبل» افزونه: همان فایل، ولی بدون قاعدهٔ مقاومِ شناسه‌دار.
const adminCss = read('plugins/src/tisacase-bulk-stock-manager/assets/admin.css');
const adminCssOld = adminCss.replace(
	'.tbsm-wrap,\n#wpbody-content .tbsm-wrap {',
	'.tbsm-wrap {'
);
if (adminCssOld === adminCss) throw new Error('قاعدهٔ #wpbody-content .tbsm-wrap در CSS پیدا نشد');

function shell({ hub, broken }) {
	return `<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<style>${CORE}</style>
<style>${hub}</style>
${broken ? `<style>${BROKEN_HUB}</style>` : ''}
<style>${broken ? adminCssOld : adminCss}</style>
</head>
<body class="wp-admin woocommerce_page_tisacase-bulk-stock-manager tisa-scope tbsm-page">
	<div id="adminmenumain">
		<ul id="adminmenu"><li>پیشخوان</li><li>نوشته‌ها</li><li>ووکامرس</li><li>محصولات</li></ul>
	</div>
	<div id="wpcontent">
		<div id="wpbody">
			<div id="wpbody-content">
				${wrapHtml}
				<div class="clear"></div>
			</div>
			<div id="wpfooter" role="contentinfo">
				<p class="alignleft">از وردپرس سپاسگزاریم که با آن می‌سازید.</p>
				<p class="alignright">نگارش ۷.۱.۳</p>
			</div>
		</div>
	</div>
</body>
</html>`;
}

/* ---------- ۳) سنجش ---------- */
const hubCss = read('plugins/tisacase-hub/assets/tisacase-ui.css') + read('plugins/tisacase-hub/assets/hub.css');

async function measure(page, broken) {
	await page.setContent(shell({ hub: hubCss, broken }), { waitUntil: 'load' });
	// گام ۲ و ۳ را قابل‌دیدن کن (کاربر در همان حالتی است که کارت‌ها را ساخته).
	await page.evaluate(() => {
		document.querySelectorAll('[hidden]').forEach((el) => el.removeAttribute('hidden'));
		document.querySelectorAll('.tbsm-step-locked').forEach((el) => el.classList.remove('tbsm-step-locked'));
		document.querySelectorAll('#tbsm-panel-single').forEach((el) => el.setAttribute('hidden', ''));
	});
	return page.evaluate(() => {
		const r = (sel) => {
			const el = document.querySelector(sel);
			if (!el) return null;
			const b = el.getBoundingClientRect();
			return { w: Math.round(b.width), left: Math.round(b.left), right: Math.round(b.right) };
		};
		const content = document.querySelector('#wpbody-content').getBoundingClientRect();
		const footer = document.querySelector('#wpfooter');
		const ver = document.querySelector('#wpfooter .alignright');
		return {
			content: { w: Math.round(content.width), left: Math.round(content.left), right: Math.round(content.right) },
			wrap: r('.tbsm-wrap'),
			step2: r('#tbsm-step-model'),
			step3: r('#tbsm-step-cards'),
			gutterStart: (() => {
				const b = document.querySelector('.tbsm-wrap').getBoundingClientRect();
				return Math.round(content.right - b.right); // فاصلهٔ کارت تا لبهٔ راست (سمت منو)
			})(),
			gutterEnd: (() => {
				const b = document.querySelector('.tbsm-wrap').getBoundingClientRect();
				return Math.round(b.left - content.left); // فاصلهٔ کارت تا لبهٔ چپ
			})(),
			footerVersionVisible: !!ver && ver.getBoundingClientRect().width > 0 &&
				getComputedStyle(ver).display !== 'none' &&
				getComputedStyle(footer).display !== 'none',
			footerVersionPos: ver ? { x: Math.round(ver.getBoundingClientRect().left) } : null,
		};
	});
}

const libDir = ensureChromiumLibs();
process.env.LD_LIBRARY_PATH = [libDir, process.env.LD_LIBRARY_PATH || ''].filter(Boolean).join(':');

const out = {};
const browser = await puppeteer.launch({
	args: [...chromium.args, '--font-render-hinting=none'],
	executablePath: await chromium.executablePath(),
	headless: true,
});
try {
	const page = await browser.newPage();
	for (const w of [1440, 1920]) {
		for (const [label, broken] of [['before', true], ['after', false]]) {
			await page.setViewport({ width: w, height: 900, deviceScaleFactor: 1 });
			out[label + '@' + w] = await measure(page, broken);
		}
	}
} finally {
	await browser.close();
}

const show = (label, m) => {
	const pad = '  ';
	console.log(`${label}`);
	console.log(`${pad}ناحیهٔ محتوا (#wpbody-content): ${m.content.w}px  [راست ${m.content.right} · چپ ${m.content.left}]`);
	console.log(`${pad}کارت گام ۲ (#tbsm-step-model):  ${m.step2.w}px  [راست ${m.step2.right} · چپ ${m.step2.left}]`);
	console.log(`${pad}کارت گام ۳ (#tbsm-step-cards):  ${m.step3.w}px  [راست ${m.step3.right} · چپ ${m.step3.left}]`);
	console.log(`${pad}فاصلهٔ کنارِ کارت: راست(منو) ${m.gutterStart}px · چپ ${m.gutterEnd}px  → اختلاف ${Math.abs(m.gutterStart - m.gutterEnd)}px`);
	console.log(`${pad}نگارش وردپرس در فوتر: ${m.footerVersionVisible ? `دیده می‌شود (x=${m.footerVersionPos.x})` : 'پنهان ✓'}`);
	console.log('');
};

console.log('\n════════ هندسهٔ واقعی صفحه (Chromium · RTL) ════════\n');

const eq = (a, b) => Math.abs(a - b) <= 1;
const problems = [];
for (const w of [1440, 1920]) {
	const before = out['before@' + w];
	const after = out['after@' + w];
	show(`── قبل (لایهٔ مشترک قدیمی) · پنجرهٔ ${w}px`, before);
	show(`── بعد (CSS فعلی مخزن) · پنجرهٔ ${w}px`, after);
	if (!eq(after.step2.w, after.step3.w)) {
		problems.push(`[${w}px] کارت گام ۲ (${after.step2.w}px) و گام ۳ (${after.step3.w}px) هم‌عرض نیستند.`);
	}
	if (Math.abs(after.gutterStart - after.gutterEnd) > 2) {
		problems.push(`[${w}px] فاصلهٔ کنارِ کارت متقارن نیست: راست ${after.gutterStart}px · چپ ${after.gutterEnd}px.`);
	}
	if (after.footerVersionVisible) {
		problems.push(`[${w}px] نسخهٔ وردپرس در فوتر صفحه دیده می‌شود.`);
	}
	if (Math.abs(before.gutterStart - before.gutterEnd) <= 2) {
		problems.push(`[${w}px] کنترل مثبت: شبیه‌سازی «قبل» نامتقارن نشد — سنجه معتبر نیست.`);
	}
}
if (!eq(out['before@1920'].gutterStart, out['before@1440'].gutterStart)) {
	console.log(`• نکته: در حالت قدیمی، انحراف با پهن‌ترشدن پنجره بیشتر می‌شود (${out['before@1440'].gutterEnd - out['before@1440'].gutterStart}px → ${out['before@1920'].gutterEnd - out['before@1920'].gutterStart}px).`);
}
console.log(problems.length ? '✗ ' + problems.join('\n✗ ') : '✓ کارت‌ها هم‌عرض، چیدمان متقارن، نگارش وردپرس پنهان');
process.exit(problems.length ? 1 : 0);
