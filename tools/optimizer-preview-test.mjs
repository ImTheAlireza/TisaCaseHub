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
// فهرست فایل‌های واقعی روی دیسک (شامل نسخهٔ موازی webp) باید خوانده و نمایش داده شود.
await new Promise((r) => setTimeout(r, 400));
const subBox = window.document.querySelector('.tco-subsize-box');
assert.ok(subBox, 'مودال باید بخش «فایل‌های واقعی روی دیسک» را داشته باشد');
const subRows = window.document.querySelectorAll('#tco-subsize-detail tbody tr');
assert.ok(subRows.length >= 2, 'فهرست فایل‌ها باید شامل اصل و زیرسایز باشد');
const paths = [...window.document.querySelectorAll('#tco-subsize-detail .tco-path-cell')].map((td) => td.textContent);
assert.ok(paths.some((p) => /\.webp$/.test(p.trim())), 'مسیر فایل WebP باید با پسوند .webp نشان داده شود');
assert.ok(paths.some((p) => /\.png$/.test(p.trim())), 'مسیر فایل اصلی باید نمایش داده شود');
assert.match(
	window.document.getElementById('tco-disk-note').textContent,
	/نسخهٔ موازی|جایگزینی مستقیم/,
	'باید صریح بگوید حالت فعلی WebP موازی است یا جایگزینی'
);
assert.match(window.document.querySelector('.tco-subsize-box').textContent, /uploads\//, 'مسیر داخل uploads باید ذکر شود');

// دکمهٔ «فشرده‌سازی و جایگزینی» بالای جدول: دو کلیک، سپس جایگزینی خودکار فایل‌ها.
const pageChk = window.document.getElementById('tco-check-page');
pageChk.checked = true;
pageChk.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
const replaceAllBtn = window.document.getElementById('tco-bulk-opt-replace');
assert.ok(replaceAllBtn, 'دکمهٔ «فشرده‌سازی و جایگزینی» باید در نوار بالای جدول باشد');
assert.match(replaceAllBtn.textContent, /فشرده‌سازی و جایگزینی/, 'برچسب دکمه باید «فشرده‌سازی و جایگزینی» باشد');
replaceAllBtn.click();
await flush();
assert.match(
	window.document.getElementById('tco-bulk-opt-replace').textContent,
	/کلیک مجدد|تأیید/,
	'کلیک اول باید دکمه را مسلح کند (ضد جایگزینی اشتباهی)'
);
const beforeReplace = window.__tcoMockItem(5011);
const beforeTotal = beforeReplace.main_bytes + beforeReplace.subsizes_bytes + (beforeReplace.unscaled_bytes || 0);
window.document.getElementById('tco-bulk-opt-replace').click();
await new Promise((r) => setTimeout(r, 1200));
const afterReplace = window.__tcoMockItem(5011);
assert.ok(afterReplace, 'تصویر باید پس از جایگزینی هنوز وجود داشته باشد');
assert.equal(afterReplace.format, 'WEBP', 'پس از «فشرده‌سازی و جایگزینی» فرمت فایل باید WEBP شود');
const afterTotal = afterReplace.main_bytes + afterReplace.subsizes_bytes + (afterReplace.unscaled_bytes || 0);
assert.ok(afterTotal < beforeTotal, 'حجم کل دیسک باید پس از جایگزینی کمتر شود');
assert.match(window.document.getElementById('tco-toasts').textContent, /جایگزینی تمام شد/, 'اعلان پایان جایگزینی باید نمایش داده شود');

// دکمهٔ جایگزینی بعد از بستن مودال آزمون می‌شود (پایین).
assert.equal(window.document.getElementById('tco-modal').hidden, false, 'مودال مقایسه قبل/بعد باید باز شود');
const slider = window.document.getElementById('tco-cmp-slider');
slider.value = '28';
slider.dispatchEvent(new window.Event('input', { bubbles: true }));
assert.equal(window.document.getElementById('tco-cmp-after').style.clipPath, 'inset(0 0 0 28%)');
window.document.querySelector('[data-modal-close="1"]').click();
// دکمهٔ «جایگزینی مستقیم با WebP» باید برای تصویر غیر WebP وجود داشته باشد و کار کند.
const replaceBtn = window.document.getElementById('tco-replace-one');
assert.ok(replaceBtn, 'برای تصویر PNG باید دکمهٔ جایگزینی مستقیم با WebP باشد');
replaceBtn.click();
await new Promise((r) => setTimeout(r, 900));
const replacedItem = window.__tcoMockItem ? window.__tcoMockItem(5012) : null;
assert.ok(replacedItem && replacedItem.format === 'WEBP', 'پس از جایگزینی، فرمت تصویر باید WEBP شود');
assert.equal(window.document.getElementById('tco-modal').hidden, true, 'مودال پس از جایگزینی باید بسته شود');
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

// ۱۲. تست بازخورد جهانی «در حال انجام» — هیچ کلیکی بی‌پاسخ نمی‌ماند
// (کاربر گزارش داده بود دکمه می‌زند و تا پایان کار هیچ نشانه‌ای نمی‌بیند.)
{
	const doc = window.document;

	// در حالت بی‌کاری، نشانگرها نباید روشن یا چسبیده باشند.
	const barBefore = doc.querySelector('.tco-busybar');
	if (barBefore) {
		assert.equal(barBefore.classList.contains('is-on'), false, 'نشانگر در حالت بی‌کاری نباید روشن باشد');
		assert.equal(barBefore.getAttribute('aria-hidden'), 'true');
	}
	assert.equal(doc.querySelector('.tco-is-busy'), null, 'هیچ دکمه‌ای در حالت بی‌کاری نباید قفل بماند');

	// تأخیر مصنوعی روی شبکه تا پنجرهٔ نمایش نشانگر حتماً باز شود.
	const slowFetch = async function (url, opts) {
		await new Promise((r) => setTimeout(r, 700));
		return baseFetch(url, opts);
	};

	// ── الف) عملیات درصدی: اسکن کامل باید نوار «تعیین‌شده» با درصد نشان دهد.
	window.fetch = slowFetch;
	doc.querySelector('[data-go-subtab="explorer"]').click();
	await flush();
	doc.querySelector('[data-go-subtab="overview"]').click();
	await flush();

	doc.getElementById('tco-rescan-btn').click();
	await new Promise((r) => setTimeout(r, 300));

	const bar = doc.querySelector('.tco-busybar');
	const chip = doc.querySelector('.tco-busy-chip');
	assert.ok(bar && chip, 'نوار و چیپ نشانگر باید ساخته شوند');
	assert.ok(bar.classList.contains('is-on'), 'نوار باید در حین کار روشن باشد');
	assert.equal(bar.getAttribute('aria-hidden'), 'false', 'نوار باید برای صفحه‌خوان فعال اعلام شود');
	assert.match(chip.textContent, /اسکن|گام|در حال/, 'چیپ باید برچسب فارسی عملیات را نشان دهد');
	assert.ok(bar.classList.contains('is-determinate'), 'اسکن باید نوار درصدی (determinate) نشان دهد');
	const valuenow = Number(bar.getAttribute('aria-valuenow'));
	assert.ok(valuenow >= 0 && valuenow <= 100, 'درصد نوار باید عددی بین ۰ و ۱۰۰ باشد');
	assert.match(chip.textContent, /٪/, 'چیپ باید درصد را نمایش دهد');

	// پایان اسکن: نشانگر باید کامل خاموش و پاک شود.
	for (let i = 0; i < 40 && bar.classList.contains('is-on'); i += 1) {
		await new Promise((r) => setTimeout(r, 120));
	}
	assert.equal(bar.classList.contains('is-on'), false, 'پس از پایان اسکن نوار باید خاموش شود');
	assert.equal(bar.getAttribute('aria-hidden'), 'true');

	// ── ب) عملیات تکی: دکمه باید قفل و اسپینر بگیرد و کلیک دوم نادیده بماند.
	doc.querySelector('[data-go-subtab="explorer"]').click();
	await flush();
	const single = doc.querySelector('[data-opt-one]');
	assert.ok(single, 'دکمهٔ بهینه‌سازی تک‌تصویر باید موجود باشد');

	let calls = 0;
	window.fetch = async function (url, opts) {
		calls += 1;
		await new Promise((r) => setTimeout(r, 300));
		return baseFetch(url, opts);
	};

	const callsBefore = calls;
	single.click();
	await new Promise((r) => setTimeout(r, 300));
	assert.ok(single.classList.contains('tco-is-busy'), 'دکمهٔ کلیک‌شده باید حالت «در حال انجام» بگیرد');
	assert.equal(single.getAttribute('aria-busy'), 'true');
	assert.ok(doc.querySelector('.tco-busy-chip').classList.contains('is-on'), 'چیپ باید در حین درخواست روشن باشد');

	const callsDuring = calls;
	single.click(); // کلیک دوم روی دکمهٔ قفل‌شده باید بی‌اثر باشد
	await new Promise((r) => setTimeout(r, 120));
	assert.equal(calls, callsDuring, 'کلیک دوم روی دکمهٔ در حال اجرا نباید درخواست تکراری بسازد');
	assert.ok(calls > callsBefore, 'درخواست واقعی باید ارسال شود');

	// پایان: همه‌چیز آزاد شود (هم قفل دکمه، هم نوار — چون پس از عملیات،
	// بازخوانی آمار هم یک درخواست دیگر است).
	for (let i = 0; i < 60; i += 1) {
		const stillBusy = doc.querySelector('.tco-is-busy') || doc.querySelector('.tco-busybar').classList.contains('is-on');
		if (!stillBusy) break;
		await new Promise((r) => setTimeout(r, 150));
	}
	assert.equal(doc.querySelector('.tco-is-busy'), null, 'پس از پایان، هیچ دکمه‌ای نباید در حالت قفل بماند');
	assert.equal(doc.querySelector('.tco-busybar').classList.contains('is-on'), false);
	assert.equal(doc.querySelector('.tco-busy-chip').classList.contains('is-on'), false);

	// ── ج) خطای شبکه باید اعلان فارسی بدهد، نه سکوت.
	window.fetch = async function () {
		throw new Error('شبیه‌سازی قطع شبکه');
	};
	doc.querySelector('[data-go-subtab="overview"]').click();
	await flush();
	const cleanBtn = doc.getElementById('tco-quick-clean-unscaled');
	if (cleanBtn && !cleanBtn.disabled) {
		cleanBtn.click();
		await new Promise((r) => setTimeout(r, 500));
		assert.match(doc.getElementById('tco-toasts').textContent, /خطا|قط|شبکه|سرور/, 'خطای شبکه باید به‌صورت اعلان دیده شود');
	}
	assert.equal(doc.querySelector('.tco-is-busy'), null, 'پس از خطا هم دکمه نباید در حالت قفل بماند');

	window.fetch = baseFetch;
	await flush();
}

// ۱۱. تست مرکز کامل «پشتیبان و بازگردانی» (زیرتب مستقیم در بخش تصاویر + بازگردانی یک‌کلیکی کل نوبت اجرا!)
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const qModeBtn6 = window.document.querySelector('[data-set-delmode="quarantine"]');
if (qModeBtn6) qModeBtn6.click();
await flush();
const anyRemoveBtn = window.document.querySelector('[data-remove-one]');
assert.ok(anyRemoveBtn, 'دکمهٔ انتقال به قرنطینه در کاوشگر تصاویر باید فعال باشد');
anyRemoveBtn.click();
await flush();
window.document.querySelector('[data-go-subtab="restore"]').click();
await flush();
assert.match(window.document.getElementById('tco-main').textContent, /پشتیبان و بازگردانی تصاویر قرنطینه‌شده/);
window.document.querySelector('[data-vault-tab="history"]').click();
await flush();
const undoRunBtn = window.document.querySelector('[data-restore-batch]');
assert.ok(undoRunBtn, 'دکمهٔ بازگردانی کامل نوبت اجرا باید در گزارش نوبت‌ها فعال باشد');
undoRunBtn.click();
await flush();

// ۱۲. موتور «کار قابل ادامه»: قطع ناگهانی وسط حذف انبوه، سپس ادامه از همان نقطه بدون گم‌شدن هیچ شناسه‌ای.
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();

const qBtn = window.document.querySelector('[data-set-delmode="quarantine"]');
if (qBtn) qBtn.click();
await flush();

const totalRows = window.document.querySelectorAll('#tco-main tbody tr').length;
assert.ok(totalRows >= 2, 'برای آزمون حذف انبوه باید حداقل ۲ تصویر در کاوشگر موجود باشد');

// انتخاب همهٔ ردیف‌های صفحه
const pageCheck = window.document.getElementById('tco-check-page');
pageCheck.checked = true;
pageCheck.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
assert.ok(window.document.getElementById('tco-bulk-remove'), 'دکمهٔ حذف/قرنطینهٔ دسته‌ای باید با انتخاب موارد فعال شود');

// تیک کوچک (۱ مورد) + قطع اجباری در تیک دوم => کار نیمه‌تمام در سرور می‌ماند.
window.__tcoMockVerifyMax = 25;
window.__tcoMockTickMax = 1;
window.__tcoMockFailAfter = 3;
window.document.getElementById('tco-bulk-remove').click();
await new Promise((r) => setTimeout(r, 2500));

const resumeBtn = window.document.getElementById('tco-resume-job');
assert.ok(resumeBtn, 'پس از قطع، کارت «عملیات نیمه‌تمام» با دکمهٔ ادامه باید نمایش داده شود');
assert.match(window.document.querySelector('.tco-resume').textContent, /انجام شده/, 'کارت باید تعداد انجام‌شده از کل را نشان دهد');

const jobSnapshot = window.__tcoMockJob;
assert.equal(jobSnapshot.status, 'running', 'کار باید در سرور «در جریان» باقی بماند و گم نشود');
assert.ok(jobSnapshot.done > 0, 'قبل از قطع باید چند مورد واقعاً انجام شده باشد');
assert.ok(
	jobSnapshot.queue_ids.length + jobSnapshot.queue_paths.length + jobSnapshot.safe_ids.length > 0,
	'باید موارد باقی‌مانده در صف سرور (تأییدشده یا تأییدنشده) وجود داشته باشد'
);
const doneBeforeResume = jobSnapshot.done;
const totalJob = jobSnapshot.total;

// ادامه: تیک بزرگ‌تر تا پایان کار.
window.__tcoMockVerifyMax = 25;
window.__tcoMockTickMax = 25;
resumeBtn.click();
await new Promise((r) => setTimeout(r, 3200));

const jobAfter = window.__tcoMockJob;
assert.equal(jobAfter.status, 'done', 'پس از ادامه، کار باید کامل تمام شود');
assert.equal(
	jobAfter.done + Object.keys(jobAfter.blocked).length,
	totalJob,
	'حساب‌ها باید ببندد: انجام‌شده + محافظت‌شده = کل (هیچ شناسه‌ای گم نمی‌شود)'
);
assert.ok(jobAfter.done >= doneBeforeResume, 'شمارش انجام‌شده هرگز نباید عقب برود');
assert.equal(window.document.getElementById('tco-resume-job'), null, 'کارت ادامه پس از پایان کار باید ناپدید شود');

// آزمون «رها کردن»: یک کار تازه، قطع، سپس بستن آن از کارت.
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pageCheck2 = window.document.getElementById('tco-check-page');
if (pageCheck2) {
	pageCheck2.checked = true;
	pageCheck2.dispatchEvent(new window.Event('change', { bubbles: true }));
	await flush();
	const bulk2 = window.document.getElementById('tco-bulk-remove');
	if (bulk2 && !bulk2.disabled) {
		window.__tcoMockVerifyMax = 25;
		window.__tcoMockTickMax = 1;
		window.__tcoMockFailAfter = 2;
		bulk2.click();
		await new Promise((r) => setTimeout(r, 1500));
		const discardBtn = window.document.getElementById('tco-discard-job');
		assert.ok(discardBtn, 'کارت ادامه باید دکمهٔ «رها کردن» داشته باشد');
		discardBtn.click();
		await new Promise((r) => setTimeout(r, 1200));
		assert.equal(window.document.getElementById('tco-resume-job'), null, 'پس از «رها کردن»، کارت ادامه نباید بماند');
		assert.ok(
			!window.__tcoMockJob || window.__tcoMockJob.status !== 'running',
			'کار سرور باید بسته و از حافظه پاک شود (تا عملیات بعدی قفل نشود)'
		);
	}
}

window.__tcoMockTickMax = 6;
window.__tcoMockVerifyMax = 0;
window.__tcoMockFailAfter = 0;

// ۱۳. وقتی هیچ تصویری حذف نمی‌شود: نه سکوت، نه «انجام شد» دروغین؛ دلیل واقعی.
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pageCheck3 = window.document.getElementById('tco-check-page');
let blockedToastChecked = false;
if (pageCheck3) {
	pageCheck3.checked = true;
	pageCheck3.dispatchEvent(new window.Event('change', { bubbles: true }));
	await flush();
	const bulk3 = window.document.getElementById('tco-bulk-remove');
	if (bulk3 && !bulk3.disabled) {
		// سرور همهٔ موارد را در بازرسی ایمنی رد می‌کند.
		window.__tcoMockBlockedIds = [...window.document.querySelectorAll('#tco-main tbody tr')].map((tr) => Number(tr.getAttribute('data-row-id')));
		window.__tcoMockVerifyMax = 50;
		bulk3.click();
		await new Promise((r) => setTimeout(r, 2500));
		const toasts = window.document.getElementById('tco-toasts').textContent;
		assert.match(toasts, /هیچ تصویری حذف نشد/, 'وقتی صفر مورد حذف می‌شود باید صریحاً و با دلیل اعلام شود');
		assert.match(toasts, /رد شد|استفاده می‌شود/, 'دلیل رد شدن باید در پیام باشد');
		blockedToastChecked = true;
	}
}
assert.ok(blockedToastChecked, 'سناریوی «همه رد شدند» باید اجرا شود');
window.__tcoMockBlockedIds = [];

// ۱۴. کارت «ادامه» باید دلیل‌ها و دکمهٔ اسکن کامل را نشان دهد.
window.__tcoMockNeedsRescan = true;
window.__tcoMockVerifyMax = 1;
window.__tcoMockTickMax = 1;
window.__tcoMockFailAfter = 0;
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pageCheck4 = window.document.getElementById('tco-check-page');
if (pageCheck4) {
	pageCheck4.checked = true;
	pageCheck4.dispatchEvent(new window.Event('change', { bubbles: true }));
	await flush();
	const bulk4 = window.document.getElementById('tco-bulk-remove');
	if (bulk4 && !bulk4.disabled) {
		window.__tcoMockFailAfter = 1;
		bulk4.click();
		await new Promise((r) => setTimeout(r, 2000));
		const card = window.document.querySelector('.tco-resume');
		assert.ok(card, 'پس از قطع در فاز بازرسی، کارت ادامه باید ظاهر شود');
		assert.match(card.textContent, /بازرسی ایمنی|انجام شده/, 'کارت ادامه باید وضعیت بازرسی را نشان دهد');
		assert.ok(
			card.querySelector('#tco-resume-rescan'),
			'وقتی اسکن کامل لازم است، دکمهٔ «اجرای اسکن کامل و تلاش مجدد» باید وجود داشته باشد'
		);
	}
}
window.__tcoMockNeedsRescan = false;

window.__tcoMockTickMax = 6;
window.__tcoMockVerifyMax = 0;
window.__tcoMockFailAfter = 0;

// ۱۵. موتور کار برای «فشرده‌سازی» و «بازگردانی»: توقف وسط راه ⇒ کارت «ادامه» با نام درست
// عملیات ⇒ ادامه از همان نقطه بدون تکرار یا گم‌شدن هیچ موردی.

// پاک‌سازی: کار نیمه‌تمامِ باقی‌مانده از سناریوی قبلی رها می‌شود؛ وگرنه — درست —
// قاعدهٔ «فقط یک کار هم‌زمان» جلوی سناریوی تازه را می‌گیرد.
const leftoverDiscard = window.document.getElementById('tco-discard-job');
if (leftoverDiscard) {
	leftoverDiscard.click();
	await new Promise((r) => setTimeout(r, 700));
}
window.__tcoMockJob = null;
window.__tcoMockJobs = {};

// مجموعهٔ آزمون: همهٔ قرنطینه‌شده‌ها را برمی‌گردانیم تا صف فشرده‌سازی چند موردی باشد.
for (let i = 0; i < 30; i += 1) {
	const rr = await window.fetch('http://localhost/wp-admin/admin-ajax.php', {
		method: 'POST',
		body: new URLSearchParams({ action: 'tisacase', op: 'restore_quarantine', restore_all: '1' }),
	});
	const rj = await rr.json();
	if (!rj.data || !rj.data.job || rj.data.job.finished) break;
}
window.__tcoMockLatency = 0;

window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pageCheck5 = window.document.getElementById('tco-check-page');
assert.ok(pageCheck5, 'چک‌باکس انتخاب همهٔ صفحه باید باشد');
pageCheck5.checked = true;
pageCheck5.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
const bulkOpt = window.document.getElementById('tco-bulk-opt');
assert.ok(bulkOpt && !bulkOpt.disabled, 'دکمهٔ «فشرده‌سازی و WebP» باید با انتخاب موارد فعال شود');

// صف فشرده‌سازی فقط تصاویر بهینه‌نشده را برمی‌دارد؛ برای آزمون، چند مورد را
// «بهینه‌نشده» می‌کنیم تا صف واقعاً کار داشته باشد.
[...window.document.querySelectorAll('#tco-main tbody tr')].forEach((tr) => {
	const it = window.__tcoMockItem(tr.getAttribute('data-row-id'));
	if (it) it.is_optimized = false;
});

window.__tcoMockTickMax = 1;
window.__tcoMockLatency = 160;
bulkOpt.click();
await new Promise((r) => setTimeout(r, 400));
const stopBtn = window.document.getElementById('tco-stop-batch');
assert.ok(stopBtn, 'در حین فشرده‌سازی باید دکمهٔ توقف با نوار پیشرفت دیده شود');
stopBtn.click();
await new Promise((r) => setTimeout(r, 2200));

window.__tcoMockLatency = 0;
const optJobId = Object.keys(window.__tcoMockJobs || {})
	.filter((k) => window.__tcoMockJobs[k].op === 'optimize')
	.pop();
assert.ok(optJobId, 'فشرده‌سازی باید به‌صورت «کار» در سرور ذخیره شود');
const optJob = window.__tcoMockJobs[optJobId];
assert.equal(optJob.status, 'running', 'کار فشرده‌سازی نیمه‌تمام باید در سرور بماند');
assert.ok(optJob.done > 0, 'قبل از توقف باید چند تصویر واقعاً فشرده شده باشد');

const optCard = window.document.querySelector('.tco-resume');
assert.ok(optCard, 'پس از توقف فشرده‌سازی، کارت «ادامهٔ عملیات» باید ظاهر شود');
assert.match(optCard.textContent, /فشرده‌سازی/, 'کارت ادامه باید نام عملیات (فشرده‌سازی) را بگوید');
assert.ok(
	optCard.querySelector('#tco-resume-job'),
	'کارت ادامهٔ کار فشرده‌سازی هم باید دکمهٔ «ادامهٔ عملیات» داشته باشد'
);

const doneBefore = optJob.done;
const totalOpt = optJob.total;
window.__tcoMockTickMax = 50;
optCard.querySelector('#tco-resume-job').click();
await new Promise((r) => setTimeout(r, 2200));
assert.equal(optJob.status, 'done', 'پس از «ادامهٔ عملیات» کار فشرده‌سازی باید تمام شود');
assert.equal(optJob.done, totalOpt, 'همهٔ موارد صف باید فشرده شوند (هیچ شناسه‌ای گم نمی‌شود)');
assert.ok(optJob.done > doneBefore, 'شمارش انجام‌شده باید جلو برود');
assert.equal(window.document.getElementById('tco-resume-job'), null, 'کارت ادامه باید پس از پایان ناپدید شود');
assert.match(window.document.getElementById('tco-toasts').textContent, /فشرده‌سازی تمام شد/, 'اعلان پایان فشرده‌سازی باید نمایش داده شود');

// بازگردانی هم باید کار باشد: توقف وسط راه ⇒ ادامه از همان نقطه.
window.document.querySelector('[data-go-subtab="restore"]').click();
await flush();
window.document.querySelector('[data-vault-tab="history"]').click();
await flush();
const restoreAllBtn = window.document.getElementById('tco-restore-all-quarantine');
if (restoreAllBtn) {
	const beforeQ = (window.document.querySelectorAll('[data-purge-q]') || []).length;
	window.__tcoMockTickMax = 1;
	window.__tcoMockLatency = 160;
	restoreAllBtn.click();
	await new Promise((r) => setTimeout(r, 420));
	window.__tcoMockLatency = 0;
	const stop2 = window.document.getElementById('tco-stop-batch');
	if (stop2) {
		stop2.click();
		await new Promise((r) => setTimeout(r, 2000));
		const rCard = window.document.querySelector('.tco-resume');
		if (rCard) {
			assert.match(rCard.textContent, /بازگردانی/, 'کارت ادامهٔ بازگردانی باید نام عملیات را بگوید');
			window.__tcoMockTickMax = 50;
			rCard.querySelector('#tco-resume-job').click();
			await new Promise((r) => setTimeout(r, 2000));
		}
	}
	window.__tcoMockTickMax = 6;
	await flush();
	assert.ok(beforeQ >= 0, 'سناریوی بازگردانی انبوه باید بی‌خطا اجرا شود');
}

window.__tcoMockTickMax = 6;
window.__tcoMockVerifyMax = 0;
window.__tcoMockFailAfter = 0;

// ۱۶. سناریوی واقعی گزارش‌شده: «هنگام بازگردانی موارد از قرنطینه، عملیات شروع نشد چون
// یک کار نیمه‌تمام باز بود». باید: (۱) هیچ پیام موفقیت دروغینی ندهد، (۲) کارت «ادامه یا
// رهاکردن» را با نام و عدد همان کار نشان دهد، (۳) با یک کلیک رها و همان بازگردانی شروع شود.
window.__tcoMockTickMax = 6;
window.__tcoMockVerifyMax = 0;
window.__tcoMockFailAfter = 0;

// داده‌های آزمون: چند تصویر تازه (برای صف فشرده‌سازی) و چند مورد قرنطینه (برای دکمهٔ بازگردانی).
window.__tcoMockSeedItems(6);
window.__tcoMockSeedQuarantine(4);

window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
window.document.querySelector('[data-go-subtab="unused"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();

// حالا یک کار فشرده‌سازی شروع و عمداً نیمه‌تمام رها می‌کنیم (کار زنده، نه کهنه).
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
[...window.document.querySelectorAll('#tco-main tbody tr')].forEach((tr) => {
	const it = window.__tcoMockItem(tr.getAttribute('data-row-id'));
	if (it) it.is_optimized = false;
});
const pc7 = window.document.getElementById('tco-check-page');
pc7.checked = true;
pc7.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
window.__tcoMockTickMax = 1;
window.__tcoMockLatency = 150;
window.document.getElementById('tco-bulk-opt').click();
await new Promise((r) => setTimeout(r, 380));
window.__tcoMockLatency = 0;
const stop6 = window.document.getElementById('tco-stop-batch');
if (!stop6) {
}
assert.ok(stop6, 'کار فشرده‌سازی باید شروع شده باشد');
stop6.click();
await new Promise((r) => setTimeout(r, 1800));

const liveOptJob = Object.values(window.__tcoMockJobs || {}).find((j) => j.op === 'optimize' && j.status === 'running');
assert.ok(liveOptJob, 'کار فشرده‌سازی نیمه‌تمام باید در «سرور» باز بماند');

// اکنون بازگردانی از قرنطینه را می‌زنیم: باید با تعارض صریح روبه‌رو شود، نه پیام موفقیت.
window.document.querySelector('[data-go-subtab="restore"]').click();
await new Promise((r) => setTimeout(r, 300));
const qTab6 = window.document.querySelector('[data-vault-tab="quarantine"]');
if (qTab6) qTab6.click();
await flush();
const restoreAll6 = window.document.getElementById('tco-restore-all-quarantine');
assert.ok(restoreAll6, 'دکمهٔ «بازگردانی همه» باید در دسترس باشد');
const toastsBefore = window.document.getElementById('tco-toasts').textContent;
restoreAll6.click();
await new Promise((r) => setTimeout(r, 900));

const toastAfter = window.document.getElementById('tco-toasts').textContent;
assert.ok(
	toastAfter.length > toastsBefore.length && /شروع نشد|هنوز باز است/.test(toastAfter),
	'باید صریحاً گفته شود که این عملیات شروع نشد و دلیلش کار قبلی است'
);
assert.ok(
	!/تصویر با همان ID و تمام زیرسایزها به جای اول بازگردانده شد/.test(toastAfter),
	'هرگز نباید پیام موفقیت بازگردانی داده شود وقتی کاری انجام نشده است'
);
assert.match(toastAfter, /فشرده‌سازی/, 'پیام باید نام عملیات مسدودکننده را بگوید');

const conflictCard = window.document.querySelector('.tco-resume');
assert.ok(conflictCard, 'کارت «ادامه یا رهاکردن» باید ظاهر شود');
assert.match(conflictCard.textContent, /فشرده‌سازی/, 'کارت باید نوع کار بازمانده را نشان دهد');
const discardAndRun = window.document.getElementById('tco-discard-and-run');
assert.ok(discardAndRun, 'دکمهٔ «رها کردن و شروع» باید در دسترس باشد');
assert.match(discardAndRun.textContent, /رها کردن و شروع/, 'برچسب دکمه باید صریح باشد');

// یک کلیک: کار قبلی رها و همان بازگردانی شروع می‌شود.
window.__tcoMockTickMax = 50;
discardAndRun.click();
await new Promise((r) => setTimeout(r, 2500));
const afterRun = window.document.getElementById('tco-toasts').textContent;
assert.ok(/کار قبلی رها شد/.test(afterRun), 'رهاکردن کار قبلی باید گزارش شود');
assert.ok(
	/بازگردانده شد|بازگردانی تمام شد|هیچ موردی بازگردانده نشد/.test(afterRun),
	'پس از رهاکردن، بازگردانی باید واقعاً اجرا و گزارش شود'
);
window.__tcoMockTickMax = 6;

// ۱۷. کار «رهاشده» (بی‌خبر برای مدتی طولانی) نباید برای همیشه همه‌چیز را قفل کند؛
// باید خودکار رها شود و همان عملیات تازه اجرا شود، با گزارش صریح به کاربر.
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pc8 = window.document.getElementById('tco-check-page');
pc8.checked = true;
pc8.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
window.__tcoMockTickMax = 1;
window.__tcoMockLatency = 150;
window.document.getElementById('tco-bulk-remove').click();
await new Promise((r) => setTimeout(r, 380));
window.__tcoMockLatency = 0;
const stop7 = window.document.getElementById('tco-stop-batch');
if (stop7) stop7.click();
await new Promise((r) => setTimeout(r, 1500));

const staleJob = window.__tcoMockJob;
assert.ok(staleJob && staleJob.status === 'running', 'یک کار نیمه‌تمام باید باز مانده باشد');
// شبیه‌سازی «هیچ پیشرفتی برای ۱۰ دقیقه» (مرورگر بسته شده بود).
staleJob.updated = Math.floor(Date.now() / 1000) - 600;

window.__tcoMockTickMax = 50;
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
const pc9 = window.document.getElementById('tco-check-page');
pc9.checked = true;
pc9.dispatchEvent(new window.Event('change', { bubbles: true }));
await flush();
window.document.getElementById('tco-bulk-remove').click();
await new Promise((r) => setTimeout(r, 2200));

const staleToast = window.document.getElementById('tco-toasts').textContent;
assert.match(staleToast, /رها شد/, 'کار کهنه باید خودکار رها و به کاربر گزارش شود');
assert.ok(
	!/یک عملیات نیمه‌تمام در جریان است/.test(staleToast),
	'کار کهنه نباید خطای «در جریان است» بدهد و مسیر را قفل کند'
);
window.__tcoMockTickMax = 6;
window.__tcoMockVerifyMax = 0;
window.__tcoMockFailAfter = 0;
window.__tcoMockJob = null;
window.__tcoMockJobs = {};

// ۱۸. «قدم بعدی» (B1)، «توصیهٔ هوشمند زیرسایز» (B2) و «چکاپ سرور» (B7).
window.document.querySelector('[data-go-module="images"]').click();
await flush();
const overviewNav = window.document.querySelector('[data-go-subtab="overview"]');
if (overviewNav) overviewNav.click();
await flush();
const nextCard = window.document.querySelector('.tco-next');
assert.ok(nextCard, 'کارت «قدم بعدی پیشنهادی» باید در نمای کلی باشد');
const nextBtns = nextCard.querySelectorAll('[data-next-action]');
assert.ok(nextBtns.length >= 1, 'کارت «قدم بعدی» باید حداقل یک دکمهٔ اقدام داشته باشد');
assert.match(nextCard.textContent, /قدم بعدی|مورد/, 'کارت باید تعداد موارد و توضیح داشته باشد');

window.document.querySelector('[data-go-subtab="subsizes"]').click();
await flush();
const adviceCells = [...window.document.querySelectorAll('.tco-advice')];
assert.ok(adviceCells.length >= 1, 'هر زیرسایز باید ستون «توصیهٔ هوشمند» داشته باشد');
assert.ok(
	adviceCells.some((c) => c.textContent.trim().length > 5),
	'توصیهٔ زیرسایز باید متن فارسی توضیح‌دهنده داشته باشد'
);

window.document.querySelector('[data-go-module="system"]').click();
await new Promise((r) => setTimeout(r, 400));
const preflightBtn = window.document.getElementById('tco-preflight-btn');
assert.ok(preflightBtn, 'دکمهٔ «اجرای چکاپ» باید در ماژول سیستم باشد');
assert.match(window.document.getElementById('tco-main').textContent, /چکاپ پیش از عملیات/, 'کارت چکاپ باید نمایش داده شود');
preflightBtn.click();
await new Promise((r) => setTimeout(r, 500));
assert.match(window.document.getElementById('tco-main').textContent, /امتیاز آمادگی/, 'پس از چکاپ، امتیاز آمادگی باید نمایش داده شود');
assert.ok(window.document.querySelectorAll('#tco-main .tco-table tbody tr').length >= 3, 'چکاپ باید چند مورد بررسی‌شده نشان دهد');

// ۱۹. هیچ فهرستی نباید بی‌صدا بریده شود: کنترل صفحه‌بندی کامل + انتخاب
// «تعداد در هر صفحه» (شامل «همهٔ موارد») در کاوشگر، تکراری‌ها، بلااستفاده و سئو.
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();

window.__tcoMockSeedItems(30);
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();

const expPager = window.document.querySelector('#tco-main .tco-pager');
assert.ok(expPager, 'کاوشگر باید نوار صفحه‌بندی داشته باشد');
assert.ok(expPager.querySelector('[data-page-edge="first"]'), 'نوار صفحه‌بندی باید دکمهٔ «اول» داشته باشد');
assert.ok(expPager.querySelector('[data-page-edge="last"]'), 'نوار صفحه‌بندی باید دکمهٔ «آخر» داشته باشد');
assert.ok(expPager.querySelector('#tco-page-jump'), 'نوار صفحه‌بندی باید امکان پرش به شمارهٔ صفحه بدهد');
const perSel = expPager.querySelector('#tco-per-page');
assert.ok(perSel, 'هر فهرست باید امکان انتخاب «تعداد در هر صفحه» را بدهد');
assert.equal(perSel.querySelectorAll('option').length, 6, 'گزینه‌های «تعداد در هر صفحه» باید شامل ۲۵ تا ۵۰۰ و «همه» باشند');
assert.ok(
	[...perSel.options].some((o) => o.value === '0' && /همه/.test(o.textContent)),
	'گزینهٔ «همهٔ موارد» باید وجود داشته باشد'
);

const expPagerInfo = () => window.document.querySelector('#tco-main .tco-pager__info').textContent;
assert.match(expPagerInfo(), /صفحهٔ/, 'شمارهٔ صفحه باید دیده شود');
assert.ok(/۲/.test(expPagerInfo()), 'با ۳۷ تصویر و ۲۵ ردیف در صفحه باید حداقل ۲ صفحه باشد');

const expBefore = window.document.querySelector('#tco-main tbody tr').getAttribute('data-row-id');
window.document.querySelector('#tco-main [data-page-edge="last"]').click();
await flush();
assert.match(expPagerInfo(), /صفحهٔ ۲/, 'دکمهٔ «آخر» باید به صفحهٔ آخر ببرد');
assert.notEqual(
	window.document.querySelector('#tco-main tbody tr').getAttribute('data-row-id'),
	expBefore,
	'صفحهٔ دوم باید ردیف‌های دیگری نشان دهد'
);
window.document.querySelector('#tco-main [data-page-edge="first"]').click();
await flush();
assert.match(expPagerInfo(), /صفحهٔ ۱/, 'دکمهٔ «اول» باید به صفحهٔ اول برگرداند');

// تغییر «تعداد در هر صفحه» به ۵۰: بلافاصله اعمال و در صفحهٔ اول بازنشانی می‌شود.
const perSel2 = window.document.getElementById('tco-per-page');
perSel2.value = '50';
perSel2.dispatchEvent(new window.Event('change', { bubbles: true }));
await new Promise((r) => setTimeout(r, 120));
assert.equal(window.document.getElementById('tco-per-page').value, '50', 'انتخاب کاربر باید حفظ شود');
assert.match(expPagerInfo(), /صفحهٔ ۱ از ۱/, 'با ۵۰ مورد در صفحه، ۳۷ ردیف در یک صفحه جا می‌شود');

// بازگرداندن «تعداد در هر صفحه» به ۲۵ (انتخاب کاربر باید ذخیره و دوباره خوانده شود).
const perSel3 = window.document.getElementById('tco-per-page');
perSel3.value = '25';
perSel3.dispatchEvent(new window.Event('change', { bubbles: true }));
await new Promise((r) => setTimeout(r, 120));
assert.equal(window.document.getElementById('tco-per-page').value, '25', 'تغییر دوم «تعداد در هر صفحه» هم باید حفظ شود');

// فهرست تکراری‌ها: قبلاً هیچ صفحه‌بندی و هیچ راهی برای دیدن بیشتر از ۲۵ گروه نداشت.
window.__tcoMockSeedDuplicates(30);
window.document.querySelector('[data-go-subtab="duplicates"]').click();
await flush();
await new Promise((r) => setTimeout(r, 120));
const dupGroups = () => window.document.querySelectorAll('#tco-main .tco-dup-group').length;
const dupPager = () => window.document.querySelector('#tco-main .tco-pager');
assert.ok(dupPager(), 'فهرست تصاویر تکراری باید نوار صفحه‌بندی داشته باشد');
assert.equal(dupGroups(), 25, 'صفحهٔ اول تکراری‌ها باید ۲۵ گروه نشان دهد');
assert.match(window.document.querySelector('#tco-main .tco-pager__info').textContent, /۳۰ مورد در مجموع/, 'تعداد کل گروه‌ها باید صریح گفته شود');
window.document.querySelector('#tco-main [data-page-delta="1"]').click();
await flush();
assert.equal(dupGroups(), 5, 'صفحهٔ دوم تکراری‌ها باید ۵ گروه باقی‌مانده را نشان دهد');

// «همهٔ موارد»: هر ۳۰ گروه یک‌جا.
const dupPer = window.document.getElementById('tco-per-page');
dupPer.value = '0';
dupPer.dispatchEvent(new window.Event('change', { bubbles: true }));
await new Promise((r) => setTimeout(r, 150));
assert.equal(dupGroups(), 30, 'انتخاب «همهٔ موارد» باید کل ۳۰ گروه را نشان دهد');
assert.match(window.document.querySelector('#tco-main .tco-pager__info').textContent, /صفحهٔ ۱ از ۱/, 'در حالت «همه» تنها یک صفحه وجود دارد');

// بخش سئو هم باید نوار صفحه‌بندی داشته باشد (قبلاً هیچ کنترلی نداشت).
window.document.querySelector('[data-go-subtab="seo"]').click();
await flush();
await new Promise((r) => setTimeout(r, 150));
assert.ok(window.document.querySelector('#tco-main .tco-pager'), 'فهرست سئو باید نوار صفحه‌بندی داشته باشد');
assert.ok(window.document.getElementById('tco-per-page'), 'فهرست سئو باید انتخاب «تعداد در هر صفحه» داشته باشد');

// بخش «بلااستفاده» هم باید نوار صفحه‌بندی داشته باشد.
window.document.querySelector('[data-go-subtab="unused"]').click();
await flush();
await new Promise((r) => setTimeout(r, 150));
assert.ok(window.document.querySelector('#tco-main .tco-pager'), 'فهرست «مطلقاً بلااستفاده» باید نوار صفحه‌بندی داشته باشد');

// ۲۰. بررسی «واقعاً بلااستفاده» فقط داخل خودِ اسکن انجام می‌شود و فهرست،
// بعد از نمایش دوباره چک نمی‌شود (درخواست کاربر). کارت «نتیجهٔ آخرین عملیات»
// هم باید دلیل دقیق حذف‌نشدن را نشان دهد.
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.__tcoMockSeedItems(6, 'strict_unused');
window.document.querySelector('[data-go-subtab="unused"]').click();
await flush();
await new Promise((r) => setTimeout(r, 250));

const unusedRowIds = () =>
	[...window.document.querySelectorAll('#tco-main tbody tr[data-row-id]')].map((tr) =>
		Number(tr.getAttribute('data-row-id'))
	);
const beforeIds = unusedRowIds();
assert.ok(beforeIds.length >= 3, 'فهرست بلااستفاده باید چند ردیف داشته باشد');

const unusedText = window.document.getElementById('tco-main').textContent;
assert.ok(
	!/بازرسی زنده/.test(unusedText),
	'فهرست نباید بعد از باز شدن دوباره بازرسی زنده راه بیندازد (درخواست کاربر)'
);
assert.ok(
	!/از این فهرست خارج شد/.test(unusedText),
	'پیام «از این فهرست خارج شد» نباید در فهرست نمایش داده شود'
);

// کارت «نتیجهٔ آخرین عملیات»: بعد از یک اجرای نیمه‌موفق باید دقیقاً بگوید
// کدام مورد و چرا حذف نشد (نه جملهٔ مبهم «چون هنوز استفاده می‌شود»).
const twoIds = beforeIds.slice(0, 2);
window.__tcoMockBlockedIds = [twoIds[0]];
window.__tcoMockTickMax = 50;
window.__tcoMockVerifyMax = 50;
twoIds.forEach((id) => {
	const cb = window.document.querySelector(`#tco-main .tco-row-check[value="${id}"]`);
	if (cb) {
		cb.checked = true;
		cb.dispatchEvent(new window.Event('change', { bubbles: true }));
	}
});
await flush();
const bulkBtn = window.document.getElementById('tco-bulk-remove');
assert.ok(bulkBtn, 'با انتخاب دو ردیف باید دکمهٔ پردازش انتخاب‌شده‌ها فعال شود');
bulkBtn.click();
await new Promise((r) => setTimeout(r, 2600));
const afterRunText = window.document.getElementById('tco-main').textContent;
assert.match(afterRunText, /نتیجهٔ آخرین عملیات حذف/, 'بعد از اجرا کارت «نتیجهٔ آخرین عملیات» باید نمایش داده شود');
assert.match(afterRunText, /چرا این موارد حذف نشدند/, 'کارت باید دلیل حذف‌نشدن را توضیح دهد');
assert.match(afterRunText, /تصویر شاخص محصول/, 'دلیل دقیق (نه جملهٔ کلی) باید نمایش داده شود');
window.__tcoMockBlockedIds = [];
window.__tcoMockVerifyMax = 0;
window.__tcoMockTickMax = 6;

// ۲۱. دستهٔ حذف باید «کوتاه و پیوسته» باشد: نوار پیشرفت در فاز بازرسی
// حرکت می‌کند (نه ۰٪ گیرکرده) و پیام قطع نیمه‌کاره دقیقاً می‌گوید هنوز
// چیزی حذف نشده و چند مورد بررسی و ذخیره شده است.
window.document.querySelector('[data-go-module="images"]').click();
await flush();
window.document.querySelector('[data-go-subtab="explorer"]').click();
await flush();
window.__tcoMockTickMax = 1;
window.__tcoMockVerifyMax = 1;
window.__tcoMockLatency = 120;
const pc10 = window.document.getElementById('tco-check-page');
if (pc10) {
	pc10.checked = true;
	pc10.dispatchEvent(new window.Event('change', { bubbles: true }));
}
await flush();
const bulk10 = window.document.getElementById('tco-bulk-remove');
if (bulk10) bulk10.click();
await new Promise((r) => setTimeout(r, 420));

const progressBox = window.document.querySelector('.tco-progress-box');
assert.ok(progressBox, 'در حین حذف، کادر پیشرفت باید دیده شود');
assert.match(
	progressBox.textContent,
	/بازرسی ایمنی/,
	'در فاز بازرسی، متن پیشرفت باید صریحاً «بازرسی ایمنی» بگوید'
);
const stopBtn10 = window.document.getElementById('tco-stop-batch');
if (stopBtn10) stopBtn10.click();
await new Promise((r) => setTimeout(r, 900));
window.__tcoMockLatency = 0;
window.__tcoMockVerifyMax = 0;
window.__tcoMockTickMax = 6;
window.__tcoMockJob = null;
window.__tcoMockJobs = {};

console.log('✓ تمام سناریوهای تعاملی E2E مرکز بهینه‌سازی تیساکیس (۲۱ بخش شامل موتور اسکن نسل ۳، بازگردانی کامل نوبت‌ها، بازخورد جهانی «در حال انجام»، ادامهٔ کار حذف انبوه پس از قطع ناگهانی، اعلام صریح دلیل وقتی هیچ تصویری حذف نمی‌شود، موتور کار یکسان برای فشرده‌سازی/بازگردانی، و کارت‌های «قدم بعدی» و «چکاپ سرور»، سناریوی «تعارض کار»، «رهاکردن خودکار کار کهنه»، «صفحه‌بندی و انتخاب تعداد در هر صفحه» «بررسی بلااستفاده در خودِ اسکن و شفافیت نتیجهٔ حذف» و «کوتاه و شفاف بودن تیک‌های حذف») با موفقیت اجرا شدند!');
