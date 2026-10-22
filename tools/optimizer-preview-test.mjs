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
// تفکیک زیرسایزها باید خوانده و فهرست شود («زیرسایز چیست؟»)
await new Promise((r) => setTimeout(r, 350));
const subBox = window.document.querySelector('.tco-subsize-box');
assert.ok(subBox, 'مودال باید بخش «زیرسایز چیست» را داشته باشد');
assert.match(subBox.textContent, /زیرسایز/, 'توضیح فارسی زیرسایز باید نمایش داده شود');
const subRows = window.document.querySelectorAll('#tco-subsize-detail tbody tr');
assert.ok(subRows.length >= 1, 'فهرست تفکیک زیرسایزها باید پر شود');
assert.match(subRows[0].textContent, /B|KB|MB|بایت/, 'حجم هر زیرسایز باید نمایش داده شود');
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
const qModeBtn = window.document.querySelector('[data-set-delmode="quarantine"]');
if (qModeBtn) qModeBtn.click();
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
		assert.notEqual(window.__tcoMockJob.status, 'running', 'کار سرور باید بسته شود');
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

console.log('✓ تمام سناریوهای تعاملی E2E مرکز بهینه‌سازی تیساکیس (۱۵ بخش شامل موتور اسکن نسل ۳، بازگردانی کامل نوبت‌ها، بازخورد جهانی «در حال انجام»، ادامهٔ کار حذف انبوه پس از قطع ناگهانی، و اعلام صریح دلیل وقتی هیچ تصویری حذف نمی‌شود) با موفقیت اجرا شدند!');
