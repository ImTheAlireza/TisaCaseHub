#!/usr/bin/env node
/**
 * تست سرتاسری تعاملی (End-to-End JSDOM Integration Test) برای TisaCase Optimizer.
 * فایل واقعی `plugins/src/tisacase-optimizer/assets/admin.js` را روی DOM واقعی اجرا کرده و
 * تمام ۵ ماژول، هر ۸ زیرتب بخش تصاویر، مرتب‌سازی حجمی، قرنطینه و بازگردانی تک‌کلیکی،
 * ادغام هوشمند تکراری‌های SHA-1، پاک‌سازی فایل خام `-scaled`، اسلایدر قبل/بعد و سئوی خودکار را می‌سنجد.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const html = fs.readFileSync(path.join(ROOT, 'design/preview/optimizer-live.html'), 'utf8');
const jqSrc = fs.readFileSync(path.join(ROOT, 'node_modules/jquery/dist/jquery.min.js'), 'utf8');
const mockSrc = fs.readFileSync(path.join(ROOT, 'design/preview/optimizer-mock-api.js'), 'utf8');
const appSrc = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-optimizer/assets/admin.js'), 'utf8');

const cleanHtml = html.replace(/<script[\s\S]*?<\/script>/g, '');
const dom = new JSDOM(cleanHtml, {
	url: 'http://localhost/design/preview/optimizer-live.html',
	runScripts: 'outside-only',
	pretendToBeVisual: true,
});

const { window } = dom;
window.eval(jqSrc);
window.eval(mockSrc);
window.eval(appSrc);

const flush = () => new Promise((r) => setTimeout(r, 35));

await flush();

// ۱. بررسی بارگذاری هر ۵ ماژول و نمای کلی دیسک
const navBtns = window.document.querySelectorAll('#tco-module-nav .tco-nav__btn');
assert.equal(navBtns.length, 5, 'باید دقیقاً ۵ ماژول در ناوبری بالا رندر شود');
assert.match(window.document.getElementById('tco-main').textContent, /کالبدشکافی مصرف فضای دیسک/);

// ۲. تست آزادسازی تک‌کلیکی فایل‌های خام پشت `-scaled` در نمای کلی
window.document.getElementById('tco-quick-clean-unscaled').click();
await flush();
assert.match(window.document.getElementById('tco-toasts').textContent, /-scaled پاک شد/);

// ۳. رفتن به «سنگین‌ترین تصاویر» (Explorer) و تست مرتب‌سازی نزولی بر اساس حجم کل دیسک + بازرسی اسلایدر قبل/بعد
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const expRows = [...window.document.querySelectorAll('#tco-main tbody tr')];
assert.equal(expRows.length, 7, 'همهٔ ۷ تصویر اولیه باید در کاوشگر لیست شوند');
assert.equal(expRows[0].getAttribute('data-row-id'), '5011', 'سنگین‌ترین تصویر روی دیسک باید در ردیف اول باشد');

// باز کردن مودال مقایسهٔ زندهٔ قبل و بعد + تغییر اسلایدر
window.document.querySelector('[data-inspect="5012"]').click();
await flush();
assert.equal(window.document.getElementById('tco-modal').hidden, false, 'مودال مقایسه قبل/بعد باید باز شود');
const slider = window.document.getElementById('tco-cmp-slider');
slider.value = '28';
slider.dispatchEvent(new window.Event('input', { bubbles: true }));
assert.equal(window.document.getElementById('tco-cmp-after').style.clipPath, 'inset(0 0 0 28%)');
window.document.querySelector('[data-modal-close="1"]').click();

// ۴. رفتن به «تصاویر بدون استفاده»، قرنطینهٔ تصویر بی‌استفاده #5011، سپس رفتن به «قرنطینه و تاریخچه» و بازگردانی آن با همان ID!
window.document.querySelector('[data-go-subtab="unused"]').click();
await flush();
assert.equal(window.document.querySelectorAll('#tco-main tbody tr').length, 2, 'دقیقاً ۲ تصویر مطلقاً بدون استفاده باید لیست شود (پیش‌نویس تلگرام محافظت شده است)');

window.document.querySelector('[data-remove-one="5011"]').click();
await flush();
assert.equal(window.document.querySelectorAll('#tco-main tbody tr').length, 1, 'تصویر #5011 باید به قرنطینه منتقل شده باشد');

window.document.querySelector('[data-go-module="vault"]').click();
await flush();
const restoreBtn = window.document.querySelector('[data-restore-q]');
assert.ok(restoreBtn, 'بستهٔ قرنطینهٔ #5011 باید در خزانه دیده شود');
restoreBtn.click();
await flush();

window.document.querySelector('[data-go-module="images"]').click();
await flush();
assert.equal(window.document.querySelectorAll('#tco-main tbody tr').length, 2, 'تصویر #5011 پس از بازگردانی از قرنطینه دوباره سر جایش برگشت!');

// ۵. تست تأیید دوضربه‌ای در حالت «حذف قطعی فوری»
window.document.querySelector('[data-set-delmode="permanent"]').click();
await flush();
window.document.querySelector('[data-remove-one="5008"]').click(); // کلیک اول: فقط مسلح می‌کند
await flush();
assert.equal(window.document.querySelectorAll('#tco-main tbody tr').length, 2, 'کلیک اول در حذف قطعی نباید بدون تأیید حذف کند');
window.document.querySelector('[data-remove-one="5008"]').click(); // کلیک دوم: حذف قطعی واقعی
await flush();
assert.equal(window.document.querySelectorAll('#tco-main tbody tr').length, 1, 'کلیک دوم تصویر بی‌استفاده را به‌طور قطعی حذف کرد');

// ۶. تست «تصاویر تکراری» (SHA-1) و ادغام هوشمند گروه تکراری S24 Ultra بدون شکستن گالری محصول
window.document.querySelector('[data-go-subtab="duplicates"]').click();
await flush();
assert.match(window.document.getElementById('tco-main').textContent, /SHA1: dup998877665/);
window.document.getElementById('tco-merge-all-dups').click();
await flush();
assert.match(window.document.getElementById('tco-main').textContent, /هیچ تصویر تکراری بایت‌به‌بایتی در کتابخانهٔ رسانه وجود ندارد/);

// ۷. تست «زیرسایزها» و آزادسازی فضای سایزهای غیرفعال روی دیسک
window.document.querySelector('[data-go-subtab="subsizes"]').click();
await flush();
window.document.getElementById('tco-clean-disabled-sizes').click();
await flush();
assert.match(window.document.getElementById('tco-toasts').textContent, /فایل زیرسایز اضافی حذف شد/);

// ۸. تست «سئو و Alt خودکار» و تکمیل دسته‌جمعی Altها
window.document.querySelector('[data-go-subtab="seo"]').click();
await flush();
window.document.getElementById('tco-bulk-seo-btn').click();
await flush();
assert.match(window.document.getElementById('tco-main').textContent, /همهٔ تصاویر دارای متن جایگزین معتبر هستند/);

// ۹. تست ماژول‌های «سلامت سرور»، «دیتابیس و ووکامرس» و «شتاب‌دهندهٔ فرانت‌اند»
for (const modId of ['system', 'database', 'frontend']) {
	window.document.querySelector(`[data-go-module="${modId}"]`).click();
	await flush();
	assert.ok(window.document.getElementById('tco-main').textContent.length > 60);
}

// ۱۰. تست اجرای اسکن مرحله‌ای `scan_step` و محافظت در برابر خطای HTML غیر-JSON (مثل 504 کلودفلر) بدون گیرکردن اسپینر!
window.document.querySelector('[data-go-module="images"]').click();
window.document.querySelector('[data-go-subtab="overview"]').click();
await flush();

const baseFetch = window.fetch;
let stepCalls = 0;
window.fetch = async function (url, opts) {
	const body = String((opts && opts.body) || '');
	if (body.includes('op=scan_step')) {
		stepCalls += 1;
		if (stepCalls === 1) {
			const p1 = JSON.stringify({
				success: true,
				data: {
					done: false,
					stage: 'files_inspect',
					stage_num: 4,
					stage_label: 'گام ۴ از ۵: محاسبهٔ حجم واقعی دیسک…',
					detail_label: '۴۰۰ از ۱۲۰۰ تصویر بررسی شد',
					percent: 72,
					elapsed_sec: 2,
				},
			});
			return { ok: true, status: 200, text: async () => p1, json: async () => JSON.parse(p1) };
		}
	}
	return baseFetch(url, opts);
};

window.document.getElementById('tco-rescan-btn').click();
await flush();
assert.ok(stepCalls >= 2, 'ماشین اسکن مرحله‌ای باید چند تیک پیاپی scan_step اجرا کند و کامل شود');
assert.match(window.document.getElementById('tco-main').textContent, /کالبدشکافی مصرف فضای دیسک/);

// تست پاسخ HTML 504 غیر-JSON سرور (نباید هرگز روی اسپینر گیر کند و باید کارت ادامه را نمایش دهد)
window.fetch = async function () {
	return {
		ok: false,
		status: 504,
		text: async () => '<html><body>504 Gateway Time-out</body></html>',
	};
};
window.document.getElementById('tco-rescan-btn').click();
await new Promise((r) => setTimeout(r, 1850));
assert.match(window.document.getElementById('tco-main').textContent, /وقفه در ارتباط با سرور/);
assert.ok(window.document.getElementById('tco-resume-scan-btn'), 'دکمهٔ ادامه از آخرین نقطهٔ ذخیره‌شده باید نمایش داده شود');

// بازگردانی شبکه و کلیک روی «ادامه از آخرین نقطهٔ ذخیره‌شده»
window.fetch = baseFetch;
window.document.getElementById('tco-resume-scan-btn').click();
await flush();
assert.match(window.document.getElementById('tco-main').textContent, /کالبدشکافی مصرف فضای دیسک/);

console.log('✓ تمام سناریوهای تعاملی E2E مرکز بهینه‌سازی تیساکیس (۱۰ بخش شامل اسکن مرحله‌ای scan_step و ریکاوری خطای ۵۰۴) با موفقیت اجرا شدند!');
