#!/usr/bin/env node
/**
 * ارتفاع صفحه — چرا زیر کارت‌ها این‌قدر فضای خالی می‌ماند؟
 *
 * ریشهٔ فنی (از خود هستهٔ وردپرس):
 *   • `#adminmenuwrap { position: relative; float: right }` — منوی پیشخوان یک
 *     **فلاوت** است، پس ارتفاعش به ارتفاع سند اضافه می‌شود.
 *   • هسته فقط وقتی منو را «می‌خ‌کوب» می‌کند که جا شود:
 *       `setPinMenu()`: اگر `menu + adminbar > window` → `pinMenu()`، و در
 *       `pinMenu()`: اگر `menu + adminbar + 20 > wpwrap` → `unpinMenu()`.
 *     یعنی روی سایتی که منو (با این همه افزونه) از پنجره بلندتر است، منو
 *     فلاوت می‌ماند و صفحه دقیقاً به بلندی منو می‌شود؛ هرچه بالای صفحه محتوا
 *     کوتاه‌تر باشد، فضای خالی زیرش بیشتر.
 *
 * این ابزار همان حالت را با CSS واقعی هستهٔ ۷٫۱٫۳ بازتولید می‌کند، ارتفاع‌ها را
 * می‌سنجد و اثر «خکوب‌کردن منو فقط روی صفحهٔ ما» را نشان می‌دهد.
 *
 * پیش‌نیاز (ابزار توسعه): npm install --no-save jsdom puppeteer-core @sparticuz/chromium
 * اجرا: node tools/page-height.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import zlib from 'node:zlib';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import puppeteer from 'puppeteer-core';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (p) => fs.readFileSync(path.join(ROOT, p), 'utf8');

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
process.env.LD_LIBRARY_PATH = [ensureChromiumLibs(), process.env.LD_LIBRARY_PATH || ''].filter(Boolean).join(':');
const chromium = (await import('@sparticuz/chromium')).default;

const CORE = ['l10n-rtl', 'common-rtl', 'forms-rtl', 'admin-menu-rtl']
	.map((f) => read(`tools/fixtures/wp-7.1.3/${f}.css`)).join('\n');
const hubCss = read('plugins/tisacase-hub/assets/tisacase-ui.css') + read('plugins/tisacase-hub/assets/hub.css');
const adminCss = read('plugins/src/tisacase-bulk-stock-manager/assets/admin.css');
/**
 * نسخهٔ «قبل»: همان CSS بدون بلوکِ خکوب‌کردن منو.
 * دنبال هر `@media` که `body.tbsm-page #adminmenuwrap` دارد می‌گردیم و با
 * شمردن آکولادها تا انتهای همان بلاک می‌رویم (بدون regex شکننده).
 */
function stripPinBlock(css) {
	const marker = 'body.tbsm-page #adminmenuwrap';
	let from = 0;
	for (;;) {
		const at = css.indexOf(marker, from);
		if (at === -1) {
			throw new Error('بلوک خکوب‌کردن منو در CSS پیدا نشد');
		}
		const atStart = css.lastIndexOf('@media', at);
		if (atStart === -1) {
			from = at + marker.length;
			continue;
		}
		// انتهای بلاک @media با شمردن آکولادها
		let depth = 0;
		let end = -1;
		for (let i = css.indexOf('{', atStart); i < css.length; i++) {
			if (css[i] === '{') depth++;
			else if (css[i] === '}' && --depth === 0) { end = i + 1; break; }
		}
		if (end === -1) throw new Error('بلاک @media بسته نشده است');
		return css.slice(0, atStart) + css.slice(end);
	}
}
const adminCssOld = stripPinBlock(adminCss);
const wrapHtml = new JSDOM(read('design/preview/bulk-stock-manager.html')).window.document.querySelector('.wrap.tbsm-wrap').outerHTML;

/* منوی واقعی این سایت: افزونه‌های زیاد → ~۵۵ آیتم */
const ITEMS = 55;
const plainItem = (i) => `<li class="menu-top"><a href="#" class="menu-top"><div class="wp-menu-name">آیتم منوی شمارهٔ ${i + 1}</div></a></li>`;
// آخرین آیتم زیرمنوی واقعی دارد تا رفتار «کشویی» (flyout) سنجیده شود.
const submenuItem = `<li class="menu-top wp-has-submenu wp-not-current-submenu menu-icon-woocommerce" id="toplevel_page_woocommerce">
	<a href="#" class="menu-top"><div class="wp-menu-image dashicons-before"></div><div class="wp-menu-name">ووکامرس</div></a>
	<ul class="wp-submenu wp-submenu-wrap">
		<li><a href="#">سفارش‌ها</a></li><li><a href="#">محصولات</a></li><li><a href="#">گزارش‌ها</a></li>
	</ul>
</li>`;
const menuItems = Array.from({ length: ITEMS - 1 }, (_, i) => plainItem(i)).join('') + submenuItem;

function shell(extra, css) {
	return `<!doctype html>
<html lang="fa-IR" class="wp-toolbar js" dir="rtl" style="--wp-admin--admin-bar--height:32px">
<head><meta charset="utf-8">
<style>${CORE}</style><style>${hubCss}</style><style>${css}</style>
${extra ? `<style>${extra}</style>` : ''}
</head>
<body class="wp-admin wp-core-ui woocommerce_page_tisacase-bulk-stock-manager tisa-scope tbsm-page">
<div id="wpadminbar" style="height:32px;background:#1d2327"></div>
<div id="wpwrap">
	<div id="adminmenumain"><div id="adminmenuback"></div>
		<div id="adminmenuwrap"><ul id="adminmenu">${menuItems}</ul></div>
	</div>
	<div id="wpcontent"><div id="wpbody" role="main">
		<div id="wpbody-content">${wrapHtml}<div class="clear"></div></div>
		<div id="wpfooter" role="contentinfo"><p class="alignright">نگارش ۷.۱.۳</p></div>
	</div></div>
</div>
</body></html>`;
}

const browser = await puppeteer.launch({
	args: [...chromium.args, '--font-render-hinting=none'],
	executablePath: await chromium.executablePath(),
	headless: true,
});
const page = await browser.newPage();

async function measure(extra, height, css) {
	await page.setViewport({ width: 1568, height: height || 1000, deviceScaleFactor: 1 });
	await page.setContent(shell(extra, css || adminCss), { waitUntil: 'load' });
	await page.evaluate(() => {
		document.querySelectorAll('.tbsm-step-locked').forEach((e) => e.classList.remove('tbsm-step-locked'));
		document.getElementById('tbsm-panel-single').setAttribute('hidden', '');
		document.getElementById('tbsm-panel-group').removeAttribute('hidden');
		document.getElementById('tbsm-grid').removeAttribute('hidden');
	});
	return page.evaluate(() => {
		const h = (s) => { const e = document.querySelector(s); return e ? Math.round(e.getBoundingClientRect().height) : null; };
		const cs = getComputedStyle(document.getElementById('adminmenuwrap'));
		return {
			page: document.documentElement.scrollHeight,
			menu: h('#adminmenu'),
			content: h('#wpbody-content'),
			contentBottom: Math.round(document.querySelector('.tbsm-wrap').getBoundingClientRect().bottom + window.scrollY),
			menuPosition: cs.position,
			menuScrollable: document.getElementById('adminmenuwrap').scrollHeight > document.getElementById('adminmenuwrap').clientHeight + 4,
		};
	});
}

/* سنجش زیرمنوی کشویی: آیا زیر overflow بریده می‌شود؟ */
async function flyout(withJs) {
	await page.setViewport({ width: 1568, height: 1000, deviceScaleFactor: 1 });
	await page.setContent(shell('', adminCss), { waitUntil: 'load' });
	if (withJs) {
		await page.addScriptTag({ path: path.join(ROOT, 'node_modules/jquery/dist/jquery.min.js') });
		await page.addScriptTag({ path: path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/assets/admin.js') });
	}
	// برو سراغ آخرین آیتم (پایین منو) و بازش کن
	await page.evaluate(() => {
		const wrap = document.getElementById('adminmenuwrap');
		wrap.scrollTop = wrap.scrollHeight;
		const li = document.getElementById('toplevel_page_woocommerce');
		li.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
		li.classList.add('opensub'); // همان کاری که hoverIntent هسته می‌کند
	});
	await new Promise((r) => setTimeout(r, 60));
	return page.evaluate(() => {
		const li = document.getElementById('toplevel_page_woocommerce');
		const sub = li.querySelector('.wp-submenu');
		const b = sub.getBoundingClientRect();
		const hit = document.elementFromPoint(Math.round(b.left + b.width / 2), Math.round(b.top + 12));
		return {
			position: getComputedStyle(sub).position,
			rect: { left: Math.round(b.left), right: Math.round(b.right), top: Math.round(b.top), h: Math.round(b.height) },
			inViewport: b.left >= -1 && b.right <= window.innerWidth + 1 && b.top >= -1 && b.bottom <= window.innerHeight + 1,
			clickable: !!(hit && sub.contains(hit)),
			hitTag: hit ? (hit.tagName + '.' + (hit.className || '').toString().split(' ')[0]) : '—',
		};
	});
}

const out = {};
try {
	out.before = await measure('', 1000, adminCssOld); // رفتار خالص هسته (قبل از رفع)
	out.after = await measure('', 1000);                 // CSS فعلی مخزن
	out.flyout = await flyout(true);                     // زیرمنوی کشویی با رفعِ JS
	out.flyoutNoJs = await flyout(false);                // کنترل: بدون رفعِ JS باید بریده شود
} finally {
	await browser.close();
}

const show = (label, r, note) => {
	console.log(`\n── ${label}`);
	console.log(`   ارتفاع کل صفحه: ${r.page}px   ·   منوی پیشخوان: ${r.menu}px (position:${r.menuPosition}${r.menuScrollable ? ' · اسکرول داخلی' : ''})`);
	console.log(`   کارت‌ها تمام می‌شوند در: ${r.contentBottom}px   (محتوا ${r.content}px)`);
	const gap = r.page - r.contentBottom;
	console.log(`   ⇐ ${gap > 80 ? `${gap}px فضای خالی زیر کارت‌ها (منو صفحه را بلند می‌کند)` : 'صفحه به‌اندازهٔ محتوا ✓'}${note ? '   ' + note : ''}`);
	return gap;
};

console.log(`\n════════ ارتفاع صفحه (Chromium · CSS واقعی هستهٔ ۷٫۱٫۳ · منو با ${ITEMS} آیتم) ════════`);
show('قبل — رفتار خالص هسته (پنجرهٔ ۱۰۰۰px)', out.before);
show('پس از خکوب‌کردن منو روی صفحهٔ ما', out.after);

const f = out.flyout;
console.log('\n── زیرمنوی کشوییِ آخرین آیتم (پایین منو)');
console.log(`   position زیرمنو: ${f.position}   ·   کادر: چپ ${f.rect.left} · راست ${f.rect.right} · بالا ${f.rect.top} · ارتفاع ${f.rect.h}`);
console.log(`   کامل در دید: ${f.inViewport ? 'بله ✓' : 'نه ✗'}   ·   روی نقطهٔ وسط کلیک‌پذیر: ${f.clickable ? 'بله ✓' : 'نه ✗'} (${f.hitTag})`);

console.log(`   کنترل (بدون رفعِ JS): position ${out.flyoutNoJs.position} · کامل در دید: ${out.flyoutNoJs.inViewport ? 'بله' : 'نه'} · کلیک‌پذیر: ${out.flyoutNoJs.clickable ? 'بله' : 'نه'}`);

const problems = [];
const CORE_PADDING = 65; // padding-bottom هستهٔ #wpbody-content
if (!(out.before.page - out.before.contentBottom > 200)) {
	problems.push('شبیه‌سازی معتبر نیست: در حالت قبل، فضای خالی بزرگ ساخته نشد (منتظر >۲۰۰px بودیم).');
}
if (out.after.page - out.after.contentBottom > CORE_PADDING + 40) {
	problems.push(`هنوز ${out.after.page - out.after.contentBottom}px فضای خالی زیر کارت‌ها هست؛ خکوب‌کردن اثر نکرد.`);
}
if (!out.after.menuScrollable) {
	problems.push('پس از خکوب‌کردن، منو اسکرول داخلی ندارد؛ آیتم‌های پایینی در دسترس نیستند.');
}
if (!f.inViewport || !f.clickable) {
	problems.push('زیرمنوی کشویی زیر overflow بریده شده — منو کاربردپذیر نیست.');
}
if (out.flyoutNoJs.clickable && out.flyoutNoJs.inViewport) {
	problems.push('کنترل: بدون رفعِ JS هم زیرمنو سالم بود — یعنی overflow بریده نمی‌کند و رفعِ JS لازم نیست (سنجه بی‌اثر است).');
}
console.log('\n' + (problems.length ? '✗ ' + problems.join('\n✗ ') : '✓ ریشه تأیید شد و رفع اثر کرد (صفحه = محتوا، منو اسکرول‌دار)'));
process.exit(problems.length ? 1 : 0);
