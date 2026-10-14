/**
 * تست jsdom پیش‌نمایش «مدیریت انبوه موجودی» —
 * تک‌محصول (رگرسیون) + گروهی «محدودهٔ محصولات ← ویژگی/مقدار مدل».
 *
 * اجرا (از ریشهٔ مخزن):
 *     npm install jsdom jquery@3.7.1        # پیش‌نیاز
 *     node tools/bulk-stock-manager-preview-test.mjs
 *
 * چرا این تست در مخزن است: هر تغییری در `assets/admin.js` باید با پیش‌نمایشِ
 * `design/preview/bulk-stock-manager.html` (که فیکسچرها و ماکِ AJAX را دارد)
 * سنجیده شود؛ اگر در پوشهٔ موقت بماند، در سشن بعدی از دست می‌رود و همان
 * خطاهای رگرسیون برمی‌گردد.
 */
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(ROOT, 'package.json'));
const { JSDOM } = require('jsdom');

const PREVIEW = path.join(ROOT, 'design/preview/bulk-stock-manager.html');
const ADMIN_JS = path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/assets/admin.js');

const html = fs.readFileSync(PREVIEW, 'utf8');
const mockJs = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1])[0];
const htmlNoScripts = html.replace(/<script[\s\S]*?<\/script>/g, '<!-- script -->');

const dom = new JSDOM(htmlNoScripts, { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://preview.local/' });
const { window } = dom;
const $ = require('jquery')(window);
window.jQuery = window.$ = $;
window.Element.prototype.scrollIntoView = function () {};

const errors = [];
window.addEventListener('error', (e) => errors.push(String(e.message || e.error)));

window.eval(mockJs);
window.eval(fs.readFileSync(ADMIN_JS, 'utf8'));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const doc = window.document;

let pass = 0, fail = 0;
const results = [];
function ok(name, cond, extra) {
	results.push({ name, cond: !!cond, extra });
	if (cond) pass++; else fail++;
}
async function waitFor(label, fn, timeout = 2500) {
	const start = Date.now();
	while (Date.now() - start < timeout) {
		try { if (fn()) return true; } catch (e) { /* ignore */ }
		await sleep(25);
	}
	ok('timeout: ' + label, false);
	return false;
}
const q = (sel) => doc.querySelector(sel);
const qa = (sel) => [...doc.querySelectorAll(sel)];
const text = (sel) => (q(sel) ? q(sel).textContent.trim() : '—MISSING—');
const visible = (sel) => !!q(sel) && !q(sel).hasAttribute('hidden');
// پیام جاری فقط وقتی خوانده می‌شود که واقعاً دیده می‌شود؛ notice('') متن قبلی را
// پاک نمی‌کند (فقط مخفی می‌کند)، پس خواندن متنِ پنهان، خطای آزمایشی می‌سازد.
const noticeText = () => {
	const el = q('#tbsm-notice');
	if (!el || el.hasAttribute('hidden')) return '';
	return el.textContent.trim();
};
const attrOptions = () => qa('#tbsm-group-attr option').map((o) => o.textContent.trim());
const termOptions = () => qa('#tbsm-group-term option').map((o) => o.textContent.trim());
async function click(sel) {
	const el = typeof sel === 'string' ? q(sel) : sel;
	el.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));
	await sleep(20);
}
async function change(sel, value) {
	const el = typeof sel === 'string' ? q(sel) : sel;
	el.value = value;
	el.dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(20);
}
async function type(el, value) {
	el.value = value;
	el.dispatchEvent(new window.Event('input', { bubbles: true }));
	await sleep(20);
}

await sleep(120); // document ready

/* ------------------------------------------------ ساختار و تب‌ها */
ok('دو تب وجود دارد', qa('.tbsm-tab').length === 2);
ok('تب پیش‌فرض تک‌محصول فعال است', q('#tbsm-tab-single').classList.contains('is-active'));
ok('پنل تک‌محصول آشکار است', visible('#tbsm-panel-single'));
ok('پنل گروهی پنهان است', !visible('#tbsm-panel-group'));
ok('گام کارت‌ها قفل است', q('#tbsm-step-cards').classList.contains('tbsm-step-locked'));

/* ------------------------------------------------ تک‌محصول (رگرسیون) */
await type(doc.getElementById('tbsm-search'), 'اسپیس');
await waitFor('نتایج جست‌وجو', () => qa('#tbsm-results .tbsm-result').length === 1);
ok('جست‌وجو نتیجه داد', qa('#tbsm-results .tbsm-result').length === 1);
await click('#tbsm-results .tbsm-result');
await waitFor('کارت‌های تک‌محصول', () => qa('#tbsm-grid .tbsm-var').length === 33);
ok('۳۳ کارت متغیر رندر شد', qa('#tbsm-grid .tbsm-var').length === 33);
ok('سلکت دسته پر شد', qa('#tbsm-cat-select optgroup').length === 2);
ok('گام کارت‌ها باز شد', !q('#tbsm-step-cards').classList.contains('tbsm-step-locked'));
await change('#tbsm-cat-select', 'pa_model:iphone-15-pro-max');
ok('فیلتر دسته اعمال شد', qa('#tbsm-grid .tbsm-var:not(.is-hidden)').length === 3);

/* ------------------------------------------------ سوئیچ به حالت گروهی */
await click('#tbsm-tab-group');
ok('تب گروهی فعال شد', q('#tbsm-tab-group').classList.contains('is-active'));
ok('پنل گروهی آشکار شد', visible('#tbsm-panel-group'));
ok('پنل تک‌محصول پنهان شد', !visible('#tbsm-panel-single'));
ok('با سوئیچ، کارت‌ها پاک شدند', qa('#tbsm-grid .tbsm-var').length === 0);
ok('پنل «چند محصول» پیش‌فرض آشکار است', visible('#tbsm-scope-pane-products'));
ok('پنل دسته پنهان است', !visible('#tbsm-scope-pane-category'));
ok('پنل شناسه پنهان است', !visible('#tbsm-scope-pane-sku'));
ok('پنل «همهٔ محصولات» پنهان است', !visible('#tbsm-scope-pane-all'));
ok('گزینهٔ «همهٔ محصولات» در دکمه‌های محدوده هست', qa('.tbsm-seg-btn').map((b) => b.getAttribute('data-scope')).join(',') === 'products,category,sku,all');
ok('دکمهٔ نمایش ویژگی‌ها بدون محصول غیرفعال است', q('#tbsm-scope-apply').disabled === true);
ok('گام ۲ (ویژگی‌ها) قفل است', q('#tbsm-step-model').classList.contains('tbsm-step-locked'));

/* ------------------------------------------------ گام ۱: محدودهٔ چند محصول */
await type(doc.getElementById('tbsm-scope-search'), 'آیفون');
await waitFor('نتایج محدوده', () => qa('#tbsm-scope-results .tbsm-scope-row').length === 3);
ok('۳ محصول آیفون در فهرست محدوده آمد', qa('#tbsm-scope-results .tbsm-scope-row').length === 3);
ok('دکمهٔ «افزودن همهٔ نتایج» هست', q('#tbsm-scope-addall') !== null);

await click('#tbsm-scope-results .tbsm-scope-row');
ok('ردیف انتخاب‌شده تیک خورد', qa('#tbsm-scope-results .tbsm-scope-row.is-picked').length === 1);
ok('چیپ محصول انتخاب‌شده ساخته شد', qa('#tbsm-scope-picked .tbsm-pick-chip').length === 1);
ok('متن تعداد انتخاب درست است', /۱ محصول/.test(text('#tbsm-scope-picked')), text('#tbsm-scope-picked'));
ok('دکمهٔ نمایش ویژگی‌ها فعال شد', q('#tbsm-scope-apply').disabled === false);

await click('#tbsm-scope-addall');
ok('همهٔ نتایج اضافه شدند', qa('#tbsm-scope-picked .tbsm-pick-chip').length === 3);
ok('همهٔ ردیف‌ها تیک‌خورده‌اند', qa('#tbsm-scope-results .tbsm-scope-row.is-picked').length === 3);

await click('#tbsm-scope-picked .tbsm-pick-chip');
ok('کلیک روی چیپ محصول را حذف کرد', qa('#tbsm-scope-picked .tbsm-pick-chip').length === 2);
await click('#tbsm-scope-addall');
ok('دوباره سه محصول انتخاب‌شده', qa('#tbsm-scope-picked .tbsm-pick-chip').length === 3);

/* ------------------------------------------------ گام ۲: ویژگی‌های همان محدوده */
await click('#tbsm-scope-apply');
await waitFor('فهرست ویژگی‌های محدوده', () => qa('#tbsm-group-attr option').length > 1);
ok('گام ۲ باز شد', !q('#tbsm-step-model').classList.contains('tbsm-step-locked'));
ok('سلکت ویژگی فعال شد', q('#tbsm-group-attr').disabled === false);

const labels = attrOptions();
ok('ویژگی‌های هم‌نام ادغام شدند (۴ ردیف نه ۷)', qa('#tbsm-group-attr option').length === 5, labels.join(' | '));
ok('برچسب «مدل» خوانا شد', labels.some((t) => /^مدل/.test(t)), labels.join(' | '));
ok('برچسب «رنگ» خوانا شد', labels.some((t) => /^رنگ/.test(t)), labels.join(' | '));
ok('هیچ رشتهٔ کدشده‌ای در سلکت نمانده', !labels.some((t) => /%[0-9a-fA-F]{2}/.test(t)), labels.join(' | '));
ok('تعداد محصول کنار هر ویژگی آمده', labels.slice(1).every((t) => /\(?[۰-۹]+ محصول\)?/.test(t) || /\s[۰-۹]+ محصول/.test(t)), labels.join(' | '));
ok('شمار کلیدهای ادغام‌شده نشان داده می‌شود', labels.some((t) => /۳ کلید ادغام‌شده/.test(t)), labels.join(' | '));
ok('«رنگ» هم ۲ کلید ادغام‌شده دارد', labels.some((t) => /^رنگ.*۲ کلید ادغام‌شده/.test(t)), labels.join(' | '));
ok('شمارش محصولِ ادغام‌شده دقیق است (۳ محصول)', /۳ محصول/.test(labels.filter((t) => /^مدل/.test(t))[0]), labels.filter((t) => /^مدل/.test(t))[0]);
ok('شمارش متغیرهای ادغام‌شده دقیق است (۴۱ = ۳۳+۶+۲)', /۴۱ متغیر/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));
ok('کلیدهای خام در data-keys می‌مانند (برای تطبیق سرور)', (q('#tbsm-group-attr option[data-keys]').getAttribute('data-keys') || '').split('|').length >= 2, q('#tbsm-group-attr option[data-keys]').getAttribute('data-keys'));

const summary = text('#tbsm-scope-summary');
ok('خط خلاصهٔ محدوده نمایش گرفت', visible('#tbsm-scope-summary'), summary);
ok('خلاصه «۳ محصول انتخابی» را نشان می‌دهد', /۳ محصول انتخابی/.test(summary), summary);

// مقدارهای ویژگی «مدل» (تازه از سرور)
const modelAttr = qa('#tbsm-group-attr option').filter((o) => /^مدل/.test(o.textContent.trim()))[0];
await change('#tbsm-group-attr', modelAttr.value);
await waitFor('مقدارهای مدل', () => qa('#tbsm-group-term option').length > 1);
const terms = termOptions();
ok('۱۱ مدل در سلکت مقدار است', qa('#tbsm-group-term option').length === 12, terms.join(' | '));
ok('مقدارها هم خوانا شده‌اند (بدون درصد)', !terms.some((t) => /%[0-9a-fA-F]{2}/.test(t)), terms.join(' | '));
const groupOf = (i) => [...qa('#tbsm-group-term optgroup')[i].querySelectorAll('option')].map((o) => o.textContent.trim());
ok('ترتیب طبیعی داخل گروه سامسونگ (A06 < A11 < A21s < A55)', /^A06/.test(groupOf(1)[0]) && /^A11/.test(groupOf(1)[1]) && /^A21s/.test(groupOf(1)[2]) && /^A55/.test(groupOf(1)[3]), groupOf(1).join(' | '));
ok('ترتیب طبیعی داخل گروه شیائومی (Poco قبل از Redmi)', /^Poco/.test(groupOf(2)[0]) && /^Redmi/.test(groupOf(2)[1]), groupOf(2).join(' | '));
ok('تعداد محصول کنار مقدار آمده', /[۰-۹]+ محصول/.test(terms[1]), terms[1]);
ok('نوشتار خام مقدار در data-values می‌ماند', (qa('#tbsm-group-term option[data-values]').filter((o) => /^iPhone 15 —/.test(o.textContent.trim()))[0].getAttribute('data-values') || '').indexOf('iphone-15') !== -1, 'data-values');

// گروه‌بندی برند (مثل صفحهٔ محصول): سه گروه + ترتیب داخل گروه
const groupLabels = qa('#tbsm-group-term optgroup').map((g) => g.getAttribute('label'));
ok('مقدارها بر اساس برند گروه شدند (۳ گروه)', groupLabels.length === 3, groupLabels.join(' | '));
ok('گروه‌ها: آیفون (۵)، سامسونگ (۴)، شیائومی (۲)', /^آیفون \(۵\)$/.test(groupLabels[0]) && /^سامسونگ \(۴\)$/.test(groupLabels[1]) && /^شیائومی \(۲\)$/.test(groupLabels[2]), groupLabels.join(' | '));
ok('داخل گروه آیفون ترتیب طبیعی است (۱۳ < ۱۴ < ۱۵ < ۱۵ Pro Max < ۱۶ Pro Max)', ['iPhone 13', 'iPhone 14', 'iPhone 15', 'iPhone 15 Pro Max', 'iPhone 16 Pro Max'].every((name, i) => groupOf(0)[i].indexOf(name + ' —') === 0), groupOf(0).join(' | '));
ok('هیچ مدلی بیرون از گروه‌ها نمانده', qa('#tbsm-group-term optgroup option').length === 11, String(qa('#tbsm-group-term optgroup option').length));

// جست‌وجوی مقدار: «a55» باید به یک نتیجه برسد
await type(doc.getElementById('tbsm-group-term-search'), 'a55');
await waitFor('فیلتر مقدار', () => qa('#tbsm-group-term option').length === 2);
ok('جست‌وجوی «a55» یک مقدار آورد', qa('#tbsm-group-term option').length === 2, termOptions().join(' | '));
ok('همان «A55» است', /^A55/.test(termOptions()[1]), termOptions()[1]);
ok('در حالت فیلتر گروه‌بندی برداشته می‌شود', qa('#tbsm-group-term optgroup').length === 0);
ok('شمار نتیجه‌ها نشان داده می‌شود', /نمایش ۱ از ۱۱ مقدار/.test(text('#tbsm-group-term-hint')), text('#tbsm-group-term-hint'));

// جست‌وجو با ارقام فارسی/عربی و با فاصله هم کار می‌کند
await type(doc.getElementById('tbsm-group-term-search'), '۱۴');
await waitFor('فیلتر ارقام فارسی', () => qa('#tbsm-group-term option').length === 2);
ok('«۱۴» (ارقام فارسی) همان iPhone 14 را می‌آورد', /^iPhone 14/.test(termOptions()[1]), termOptions().join(' | '));

await type(doc.getElementById('tbsm-group-term-search'), 'iphone 15 pro');
await waitFor('فیلتر چندکلمه‌ای', () => qa('#tbsm-group-term option').length === 2);
ok('جست‌وجوی «iphone 15 pro» فقط Pro Max را می‌آورد', /^iPhone 15 Pro Max/.test(termOptions()[1]), termOptions()[1]);

await type(doc.getElementById('tbsm-group-term-search'), '');
await waitFor('پاک‌شدن فیلتر', () => qa('#tbsm-group-term option').length === 12);
ok('با خالی‌شدن جست‌وجو همهٔ ۱۱ مقدار برمی‌گردند', qa('#tbsm-group-term option').length === 12);
ok('گروه‌بندی برمی‌گردد', qa('#tbsm-group-term optgroup').length === 3);
ok('راهنمای شمارش پاک می‌شود', text('#tbsm-group-term-hint').length === 0 || q('#tbsm-group-term-hint').hasAttribute('hidden'), text('#tbsm-group-term-hint'));

// «رنگ»: مقدار کدشدهٔ «مشکی» باید با «مشکی» سالم در یک گزینه ادغام شود
const colorAttr = qa('#tbsm-group-attr option').filter((o) => /^رنگ/.test(o.textContent.trim()))[0];
await change('#tbsm-group-attr', colorAttr.value);
await waitFor('مقدارهای رنگ', () => qa('#tbsm-group-term option').length > 1);
const colorTerms = qa('#tbsm-group-term option').slice(1).map((o) => o.textContent.trim());
ok('«مشکی» کدشده با «مشکی» سالم یکی شد (۳ محصول)', colorTerms.some((t) => /^مشکی.*۳ محصول/.test(t)), colorTerms.join(' | '));
ok('«سبز» از دو کلید یک گزینه شد (۳ محصول)', colorTerms.some((t) => /^سبز.*۳ محصول/.test(t)), colorTerms.join(' | '));
ok('«نقره‌ای» فقط دو محصول دارد', colorTerms.some((t) => /^نقره.ای.*۲ محصول/.test(t)), colorTerms.join(' | '));
ok('«رنگ» (۳ مقدار) گروه‌بندی برند نمی‌گیرد', qa('#tbsm-group-term optgroup').length === 0);
const blackOpt = qa('#tbsm-group-term option').filter((o) => /^مشکی/.test(o.textContent.trim()))[0];
ok('هر دو نوشتار خام «مشکی» در یک گزینه‌اند', (blackOpt.getAttribute('data-values') || '').split('|').length === 2, blackOpt.getAttribute('data-values'));
ok('نوشتار کدشدهٔ «مشکی» هم داخل گزینه است', /d9%85%d8%b4%da%a9%db%8c/.test(blackOpt.getAttribute('data-values') || ''), blackOpt.getAttribute('data-values'));

/* ------------------------------------------------ گام ۳: کارت‌های همان مدل */
// برگشت به ویژگی «مدل» و انتخاب مقدار پرتکرار آن
await change('#tbsm-group-attr', modelAttr.value);
await waitFor('مقدارهای مدل (بار دوم)', () => qa('#tbsm-group-term option').length > 1);
const proMax = qa('#tbsm-group-term option').filter((o) => /iPhone 15 Pro Max/.test(o.textContent.trim()))[0];
await change('#tbsm-group-term', proMax.value);
await waitFor('کارت‌های مدل', () => qa('#tbsm-grid .tbsm-var').length > 0);
ok('۶ متغیر در ۲ محصول فهرست شد', qa('#tbsm-grid .tbsm-var').length === 6);
ok('نام کارت = نام محصول والد', text('#tbsm-grid .tbsm-var .tbsm-var-name') === 'قاب اسپیس آیفون (سری کامل)', text('#tbsm-grid .tbsm-var .tbsm-var-name'));
ok('خط دوم = برچسب متغیر + شناسه', /iPhone 15 Pro Max/.test(text('#tbsm-grid .tbsm-var .tbsm-var-sku')), text('#tbsm-grid .tbsm-var .tbsm-var-sku'));
ok('همهٔ کارت‌ها پیش‌فرض تیک‌خورده‌اند', qa('#tbsm-grid .tbsm-var.is-selected').length === 6);
ok('گام کارت‌ها باز شد', !q('#tbsm-step-cards').classList.contains('tbsm-step-locked'));

const note = text('#tbsm-group-note');
ok('خط اطلاعات گروهی نمایش گرفت', visible('#tbsm-group-note'), note);
ok('خط اطلاعات محدوده را دارد', /۳ محصول انتخابی/.test(note), note);
ok('خط اطلاعات مدل را دارد', /iPhone 15 Pro Max/.test(note), note);
ok('شمارش «۶ متغیر در ۲ محصول» درست است', /۶ متغیر در ۲ محصول/.test(note), note);
ok('شمارنده‌ها به‌روز شد', /۶ از ۶/.test(text('#tbsm-counts')), text('#tbsm-counts'));
ok('دکمهٔ اعمال فعال شد', q('#tbsm-apply').disabled === false);

/* ------------------------------------------------ موجودی همگانی (کلی) */
await type(doc.getElementById('tbsm-quickset'), '7');
await click('#tbsm-quickset-btn');
ok('مقدار همگانی روی هر ۶ کارت درج شد', qa('#tbsm-grid .tbsm-var-stock').every((i) => i.value === '7'));
await click('#tbsm-unified');
ok('دکمهٔ اعمال همگانی به حالت تأیید رفت', q('#tbsm-unified').classList.contains('tisa-btn--confirm'));
await click('#tbsm-unified');
await waitFor('گزارش اعمال همگانی', () => qa('#tbsm-report .tbsm-report-row').length > 0);
ok('گزارش ۶ ردیف دارد', qa('#tbsm-report .tbsm-report-row').length === 6);
const reportText = text('#tbsm-report');
ok('گزارش نام محصول والد را دارد', /قاب اسپیس آیفون/.test(reportText), reportText.slice(0, 160));
ok('گزارش «قبل ← بعد» دارد', reportText.includes('←'));
ok('همگام‌سازی «۲ محصول والد» گزارش شد', /۲ محصول والد/.test(reportText), reportText.slice(0, 260));
ok('هر دو محصول در گزارش تفکیک شده‌اند', /قاب شفاف آیفون/.test(reportText) && /قاب اسپیس آیفون/.test(reportText));

/* ------------------------------------------------ ویرایش تکی (یک کارت) */
const boxes = qa('#tbsm-grid .tbsm-var .tbsm-var-check');
for (const i of [0, 1, 3, 4, 5]) { await click(boxes[i]); }
ok('فقط یک کارت تیک‌خورده ماند', qa('#tbsm-grid .tbsm-var.is-selected').length === 1);
await type(qa('#tbsm-grid .tbsm-var:nth-child(3) .tbsm-var-stock')[0], '55');
await click('#tbsm-apply');
ok('دکمهٔ اعمال به حالت تأیید رفت', q('#tbsm-apply').classList.contains('tisa-btn--confirm'));
ok('متن تأیید مخصوص حالت گروهی است', /گروهی/.test(text('#tbsm-apply')), text('#tbsm-apply'));
await click('#tbsm-apply');
await waitFor('گزارش اعمال تکی', () => /۵۵/.test(text('#tbsm-report')));
ok('گزارش اعمال تکی یک ردیف دارد', qa('#tbsm-report .tbsm-report-row').length === 1, text('#tbsm-report').slice(0, 120));

/* ------------------------------------------------ فهرست بلند: صفحه‌بندی سرور (۱٫۴٫۰) */
window.tbsmMockPageSize = 4;
await change('#tbsm-group-term', proMax.value);
await waitFor('صفحهٔ نخست فهرست صفحه‌بندی‌شده', () => qa('#tbsm-grid .tbsm-var').length === 4);
ok('با سقف ۴، فقط صفحهٔ نخست (۴ کارت) می‌آید', qa('#tbsm-grid .tbsm-var').length === 4);
ok('دکمهٔ «نمایش موارد بعدی» آمد', !!q('#tbsm-more'), text('#tbsm-more'));
ok('برچسب دکمه سقف صفحه را دارد', /۴/.test(text('#tbsm-more')), text('#tbsm-more'));
ok('خط اطلاعات دربارهٔ صفحه‌بندی هشدار می‌دهد', /نمایش موارد بعدی/.test(text('#tbsm-group-note')), text('#tbsm-group-note'));
ok('شمارش متغیر در خط اطلاعات تجمعی است', /۴ متغیر در/.test(text('#tbsm-group-note')), text('#tbsm-group-note'));
ok('کارت‌های صفحهٔ نخست هم پیش‌فرض تیک‌خورده‌اند', qa('#tbsm-grid .tbsm-var.is-selected').length === 4);
ok('شمارنده‌ها فقط موارد بارگیری‌شده را می‌شمارند', /۴ از ۴/.test(text('#tbsm-counts')), text('#tbsm-counts'));
await click('#tbsm-more');
await waitFor('الحاق صفحهٔ دوم', () => qa('#tbsm-grid .tbsm-var').length === 6);
ok('صفحهٔ دوم الحاق شد (۶ کارت، بدون بازسازی فهرست)', qa('#tbsm-grid .tbsm-var').length === 6);
ok('پس از بارگیری کامل، دکمهٔ ادامه رفت', !q('#tbsm-more'));
ok('هشدار صفحه‌بندی از خط اطلاعات رفت', !/نمایش موارد بعدی/.test(text('#tbsm-group-note')), text('#tbsm-group-note'));
ok('شمارش تجمعی کامل شد (۶ متغیر در ۲ محصول)', /۶ متغیر در ۲ محصول/.test(text('#tbsm-group-note')), text('#tbsm-group-note'));
ok('شمارنده‌ها کل موارد بارگیری‌شده را نشان می‌دهند', /۶ از ۶/.test(text('#tbsm-counts')), text('#tbsm-counts'));
delete window.tbsmMockPageSize;

/* ------------------------------------------------ اعمال دسته‌ای: دسته‌های کوچک، گزارش تجمعی (۱٫۴٫۰) */
window.tbsmMockApplyCalls = 0;
window.TBSM.applyChunk = 2;
await type(doc.getElementById('tbsm-quickset'), '9');
await click('#tbsm-quickset-btn');
await click('#tbsm-apply'); // حالت تأیید
await click('#tbsm-apply'); // اجرای دسته‌ای
await waitFor('گزارش اعمال دسته‌ای', () => qa('#tbsm-report .tbsm-report-row').length === 6);
ok('اعمال ۶ مورد در ۳ دستهٔ ۲تایی فرستاده شد', window.tbsmMockApplyCalls === 3, String(window.tbsmMockApplyCalls));
ok('گزارش تجمعی هر ۶ ردیف را دارد', qa('#tbsm-report .tbsm-report-row').length === 6);
ok('همگام‌سازی والد بدون شمارش مضاعف (۲ محصول، نه ۶)', /۲ محصول والد/.test(text('#tbsm-report')), text('#tbsm-report').slice(0, 200));
ok('پیشرفت دسته‌ای پس از پایان پاک شده', q('#tbsm-notice').hasAttribute('hidden') || !/اعمال دسته‌ای/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('کارت‌های اعمال‌شده نشان «اعمال‌شده» گرفتند', qa('#tbsm-grid .tbsm-var[data-applied]').length === 6);
await click('#tbsm-apply');
ok('اعمال دوباره بدون باقی‌مانده → پیام «همه اعمال شده‌اند»', /اعمال شده/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('برای اعمال دوبارهٔ بی‌مورد، درخواستی نرفت', window.tbsmMockApplyCalls === 3, String(window.tbsmMockApplyCalls));

// جداسازی مورد خراب: دو کارت وسطی خطای سرور می‌گیرند
const badIds = qa('#tbsm-grid .tbsm-var').slice(2, 4).map((c) => c.getAttribute('data-id'));
qa('#tbsm-grid .tbsm-var').forEach((c) => c.removeAttribute('data-applied'));
window.tbsmMockApplyFail = badIds;
window.tbsmMockApplyCalls = 0;
await type(doc.getElementById('tbsm-quickset'), '8');
await click('#tbsm-quickset-btn');
await click('#tbsm-apply'); // تأیید
await click('#tbsm-apply'); // اجرا
await waitFor('اعمال با جداسازی مورد خراب', () => qa('#tbsm-report .tbsm-report-row').length === 4);
ok('۴ سالم اعمال شد، ۲ خراب جدا افتاد', qa('#tbsm-report .tbsm-report-row').length === 4);
ok('تلاش‌ها: دسته‌ها + نصف‌شدن + تلاش دوبارهٔ تک‌مورد = ۷ درخواست', window.tbsmMockApplyCalls === 7, String(window.tbsmMockApplyCalls));
ok('پیام خطای جزئی «۲ مورد» آمد', /۲ مورد/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('کارت‌های خراب نشان «اعمال‌شده» نگرفتند', qa('#tbsm-grid .tbsm-var[data-applied]').length === 4);

// ادامه از نقطهٔ توقف: فقط ۲ باقی‌مانده فرستاده می‌شود
window.tbsmMockApplyFail = null;
await click('#tbsm-apply'); // تأیید
await click('#tbsm-apply'); // اجرای باقی‌مانده
await waitFor('ادامهٔ اعمال از نقطهٔ توقف', () => qa('#tbsm-report .tbsm-report-row').length === 2);
ok('ادامه، فقط ۲ مورد باقی‌مانده را فرستاد', qa('#tbsm-report .tbsm-report-row').length === 2);
ok('پس از ادامه، هر ۶ کارت نشان دارند', qa('#tbsm-grid .tbsm-var[data-applied]').length === 6);
window.TBSM.applyChunk = 25;
delete window.tbsmMockApplyCalls;
delete window.tbsmMockApplyFail;

/* ------------------------------------------------ محدوده با شناسه (SKU) */
await click('.tbsm-seg-btn[data-scope="sku"]');
ok('پنل شناسه آشکار شد', visible('#tbsm-scope-pane-sku'));
ok('پنل محصولات پنهان شد', !visible('#tbsm-scope-pane-products'));
await type(doc.getElementById('tbsm-scope-sku'), 'CH2');
await click('#tbsm-scope-apply');
await waitFor('خلاصهٔ محدودهٔ CH2', () => /CH2/.test(text('#tbsm-scope-summary')));
ok('«شروع می‌شود با CH2» فقط قاب گلکسی را آورد', /۱ محصول/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));
ok('ویژگی‌های محدودهٔ جدید فهرست شد (۲ ردیف)', qa('#tbsm-group-attr option').length === 3, attrOptions().join(' | '));

await change('#tbsm-scope-sku-mode', 'contains');
await type(doc.getElementById('tbsm-scope-sku'), 'CH5');
await click('#tbsm-scope-apply');
await waitFor('محدودهٔ CH5', () => /CH5/.test(text('#tbsm-scope-summary')));
ok('حالت «شامل» با CH5 درست کار کرد', /۱ محصول/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));

/* ------------------------------------------------ محدودهٔ دارای ویژگی کدشده */
await change('#tbsm-scope-sku-mode', 'prefix');
await type(doc.getElementById('tbsm-scope-sku'), 'CH5');
await click('#tbsm-scope-apply');
await waitFor('ویژگی‌های محصول کدشده', () => qa('#tbsm-group-attr option').length > 1);
const encLabels = attrOptions();
ok('ویژگی‌های کدشده به فارسی خوانا شدند', encLabels.slice(1).every((t) => !/%[0-9a-fA-F]{2}/.test(t)), encLabels.join(' | '));
ok('دو «مدل» کدشده در یک ردیف ادغام شدند (۴ ویژگی)', qa('#tbsm-group-attr option').length === 5, encLabels.join(' | '));
await change('#tbsm-group-attr', qa('#tbsm-group-attr option').filter((o) => /^مدل/.test(o.textContent.trim()))[0].value);
await waitFor('مقدارهای ویژگی کدشده', () => qa('#tbsm-group-term option').length > 1);
ok('مقدار مدل این ویژگی فهرست شد', qa('#tbsm-group-term option').length === 2, termOptions().join(' | '));
await change('#tbsm-group-term', qa('#tbsm-group-term option')[1].value);
await waitFor('کارت‌های محصول کدشده', () => qa('#tbsm-grid .tbsm-var').length === 2);
ok('محصول با ویژگی کدشده فهرست شد', qa('#tbsm-grid .tbsm-var').length === 2);
ok('نام کارت همان محصول کدشده است', text('#tbsm-grid .tbsm-var .tbsm-var-name') === 'قاب مگ‌سیف آیفون (ویژگی کدشده)', text('#tbsm-grid .tbsm-var .tbsm-var-name'));
ok('برچسب متغیر هم فارسی شد', !/%/.test(text('#tbsm-grid .tbsm-var .tbsm-var-sku')), text('#tbsm-grid .tbsm-var .tbsm-var-sku'));

/* ------------------------------------------------ محدودهٔ بدون ویژگی (محصول ساده) */
await type(doc.getElementById('tbsm-scope-sku'), 'SB');
await click('#tbsm-scope-apply');
await waitFor('پیام نبود ویژگی', () => /ویژگی متغیری/.test(text('#tbsm-notice')));
ok('پیام «ویژگی متغیری پیدا نشد» آمد', /ویژگی متغیری/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('گام ۲ قفل ماند', q('#tbsm-step-model').classList.contains('tbsm-step-locked'));
ok('کارت‌ها خالی ماندند', qa('#tbsm-grid .tbsm-var').length === 0);

/* ------------------------------------------------ محدودهٔ دسته‌بندی */
await click('.tbsm-seg-btn[data-scope="category"]');
ok('پنل دسته آشکار شد', visible('#tbsm-scope-pane-category'));
ok('سلکت دسته پر شد (۴ دسته)', qa('#tbsm-scope-category option').length === 5, qa('#tbsm-scope-category option').map((o) => o.textContent.trim()).join(' | '));
ok('زیردسته با تورفتگی آمده', /^\u00A0\u00A0قاب آیفون/.test(qa('#tbsm-scope-category option')[2].textContent), JSON.stringify(qa('#tbsm-scope-category option')[2].textContent));

// جست‌وجوی دسته: «آیفون» → فقط «قاب آیفون» (و والدش برای زمینه)
await type(doc.getElementById('tbsm-scope-category-search'), 'آیفون');
await waitFor('فیلتر دسته‌ها', () => qa('#tbsm-scope-category option').length === 3);
ok('جست‌وجوی «آیفون» دو گزینه می‌گذارد (والد + قاب آیفون)', qa('#tbsm-scope-category option').length === 3, qa('#tbsm-scope-category option').map((o) => o.textContent.trim()).join(' | '));
ok('والد «قاب موبایل» برای زمینه مانده', /قاب موبایل/.test(qa('#tbsm-scope-category option')[1].textContent), qa('#tbsm-scope-category option')[1].textContent);
ok('شمار نتیجه‌ها نشان داده می‌شود', /نمایش ۱ دسته از ۴/.test(text('#tbsm-scope-category-hint')), text('#tbsm-scope-category-hint'));

await type(doc.getElementById('tbsm-scope-category-search'), 'چیز ناموجود');
await waitFor('فیلتر بی‌نتیجه', () => /پیدا نشد/.test(text('#tbsm-scope-category-hint')));
ok('پیام «دسته‌ای پیدا نشد» می‌آید', /دسته‌ای با این نام پیدا نشد/.test(text('#tbsm-scope-category-hint')), text('#tbsm-scope-category-hint'));

await type(doc.getElementById('tbsm-scope-category-search'), '');
await waitFor('پاک‌شدن فیلتر دسته', () => qa('#tbsm-scope-category option').length === 5);
ok('با پاک‌شدن جست‌وجو همهٔ ۴ دسته برمی‌گردند', qa('#tbsm-scope-category option').length === 5);
ok('راهنمای دسته پاک می‌شود', q('#tbsm-scope-category-hint').hasAttribute('hidden'), text('#tbsm-scope-category-hint'));

await change('#tbsm-scope-category', '31');
await click('#tbsm-scope-apply');
await waitFor('محدودهٔ دسته', () => /دستهٔ/.test(text('#tbsm-scope-summary')));
ok('خلاصه نام دسته را دارد', /دستهٔ «قاب موبایل»/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));
ok('محصولات دسته و زیردسته‌ها آمدند (۴ محصول)', /۴ محصول/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));
ok('ویژگی‌های محدودهٔ دسته (۴ ردیف ادغام‌شده)', qa('#tbsm-group-attr option').length === 5, attrOptions().join(' | '));

await change('#tbsm-scope-category', '34');
await click('#tbsm-scope-apply');
await waitFor('محدودهٔ لوازم جانبی', () => /لوازم جانبی/.test(text('#tbsm-scope-summary')));
ok('دستهٔ بدون ویژگی: پیام آمد', /ویژگی متغیری/.test(text('#tbsm-notice')), text('#tbsm-notice'));

/* ------------------------------------------------ بازگشت به تک‌محصول */
await click('#tbsm-tab-single');
ok('تب تک‌محصول فعال شد', q('#tbsm-tab-single').classList.contains('is-active'));
ok('پنل تک‌محصول آشکار شد', visible('#tbsm-panel-single'));
ok('کارت‌های گروهی پاک شدند', qa('#tbsm-grid .tbsm-var').length === 0);
ok('خط اطلاعات گروهی پنهان شد', !visible('#tbsm-group-note'));
await type(doc.getElementById('tbsm-search'), 'گلکسی');
await waitFor('جست‌وجوی دوم', () => qa('#tbsm-results .tbsm-result').length === 1);
await click('#tbsm-results .tbsm-result');
await waitFor('کارت‌های محصول دوم', () => qa('#tbsm-grid .tbsm-var').length === 6);
ok('۶ کارت محصول گلکسی رندر شد', qa('#tbsm-grid .tbsm-var').length === 6);

/* ------------------------------------------------ پاسخ دیررس نادیده گرفته می‌شود */
await click('#tbsm-tab-group');
await type(doc.getElementById('tbsm-scope-search'), 'آیفون');
await waitFor('نتایج محدوده دوم', () => qa('#tbsm-scope-results .tbsm-scope-row').length === 3);
await click('#tbsm-scope-results .tbsm-scope-row');
await click('#tbsm-scope-apply');
await click('#tbsm-tab-single');
await sleep(600);
ok('پاسخ دیررس کارت گروهی نساخت', qa('#tbsm-grid .tbsm-var').length === 0);
ok('خط اطلاعات گروهی پنهان ماند', !visible('#tbsm-group-note'));

/* ------------------------------------------------ واحد: هشدار بریدگی فهرست مقدارها */
const TBSM = window.TBSM;
ok('موتور TBSM روی window است', !!TBSM && typeof TBSM.renderGroupValues === 'function');
if (TBSM) {
	const many = Array.from({ length: 305 }, (_, i) => ({ label: 'مدل ' + (i + 1), values: ['v' + i], products: 1, variations: 1 }));
	TBSM.renderGroupValues({ values: many, groups: [], truncated: true });
	ok('۳۰۵ مقدار بدون خطا رندر شد', qa('#tbsm-group-term option').length === 306, String(qa('#tbsm-group-term option').length));
	ok('هشدار «فهرست بریده شد» نمایش داده می‌شود', /بریده شد/.test(text('#tbsm-group-term-hint')), text('#tbsm-group-term-hint'));
	ok('ترتیب Server دست‌نخورده رندر می‌شود (JS خودش مرتب نمی‌کند)', /^مدل 1 —/.test(termOptions()[1]) && /^مدل 305 —/.test(termOptions()[305]), termOptions().slice(1, 4).join(' | '));
	TBSM.renderGroupValues({ values: [], groups: [], truncated: false });
	ok('فهرست خالی: سلکت غیرفعال و راهنما پاک', q('#tbsm-group-term').disabled === true && q('#tbsm-group-term-hint').hasAttribute('hidden'));
}

/* ------------------------------------------------ خطاها: علت واقعی، نه پیام مبهم */
// همان «بررسی محدوده ناموفق بود» مبهم را هدف می‌گیرد؛ حالت را کامل تازه می‌کنیم.
const waitIdle = () => waitFor('آماده‌شدن دوباره', () => window.TBSM.busy === false, 3000);
const freshAttrs = () => { window.TBSM.scopeAttrs = []; window.TBSM.renderGroupAttrs(); };

await click('#tbsm-tab-group');
await waitIdle();
await click('.tbsm-seg-btn[data-scope="products"]');
if (q('#tbsm-scope-clear')) { await click('#tbsm-scope-clear'); }
await type(doc.getElementById('tbsm-scope-search'), 'آیفون');
await waitFor('نتایج محدوده سوم', () => qa('#tbsm-scope-results .tbsm-scope-row').length === 3);
await click('#tbsm-scope-results .tbsm-scope-row');
ok('(پیش‌نیاز) یک محصول انتخاب شد', qa('#tbsm-scope-picked .tbsm-pick-chip').length === 1, String(qa('#tbsm-scope-picked .tbsm-pick-chip').length));

window.TBSM.notice('');
await click('#tbsm-scope-apply');
await waitIdle();
ok('(پیش‌نیاز) مسیر سالم ویژگی‌ها را می‌آورد', qa('#tbsm-group-attr option').length > 1, String(qa('#tbsm-group-attr option').length));

// (۱) پاسخ «0» — اندپوینت ثبت نشده یا JS/سرور ناهم‌خوان (هارنس قدیمی)
freshAttrs();
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => (data.action === 'tbsm_scope_attributes' ? { resolve: 0 } : null);
await click('#tbsm-scope-apply');
await waitFor('پیام پاسخ نامعتبر', () => /پاسخ نامعتبر/.test(text('#tbsm-notice')));
ok('پاسخ «0» به پیام «پاسخ نامعتبر» تبدیل شد', /پاسخ نامعتبر از سرور/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('پیام مبهم «بررسی محدوده ناموفق بود» نمایش داده نشد', !/بررسی محدوده ناموفق بود/.test(text('#tbsm-notice')), text('#tbsm-notice'));
await waitIdle();
ok('دکمهٔ نمایش ویژگی‌ها دوباره فعال شد', q('#tbsm-scope-apply').disabled === false);

// (۲) خطای HTTP با متن خطای PHP در بدنه — همان متن باید نشان داده شود
freshAttrs();
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => (data.action === 'tbsm_scope_attributes'
	? { reject: { status: 500, responseText: '<b>Fatal error</b>: Allowed memory size exhausted in class-tbsm-stock.php on line 812' } }
	: null);
await click('#tbsm-scope-apply');
await waitFor('پیام خطای سرور', () => /memory size exhausted/.test(text('#tbsm-notice')));
ok('متن خطای واقعی سرور به کاربر نشان داده شد', /memory size exhausted/.test(text('#tbsm-notice')), text('#tbsm-notice'));
ok('تگ‌های HTML از پیام پاک شدند', !/<b>/.test(text('#tbsm-notice')), text('#tbsm-notice'));
await waitIdle();

// (۳) timeout
freshAttrs();
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => (data.action === 'tbsm_scope_attributes' ? { reject: { status: 504, responseText: '' } } : null);
await click('#tbsm-scope-apply');
await waitFor('پیام تایم‌اوت', () => /محدودهٔ کوچک‌تری/.test(text('#tbsm-notice')));
ok('timeout پیام مخصوص خودش را دارد', /محدودهٔ کوچک‌تری/.test(text('#tbsm-notice')), text('#tbsm-notice'));
await waitIdle();

// (۴) توکن منقضی: باید خودکار تازه شود و همان درخواست یک بار تکرار شود
freshAttrs();
window.TBSM.notice('');
const nonceBefore = window.tbsmData.nonce;
let nonceAsks = 0;
let attrAttempts = 0;
window.TBSM_PREVIEW_FAULT = (data) => {
	if (data.action === 'tbsm_nonce') { nonceAsks++; return null; }
	if (data.action === 'tbsm_scope_attributes') {
		attrAttempts++;
		// فقط تلاش اول با توکن منقضی رد می‌شود؛ تلاش بعدی (با توکن تازه) باید موفق شود.
		if (attrAttempts === 1) {
			return { resolve: { success: false, data: { code: 'bad_nonce', message: 'توکن امنیتی صفحه منقضی شده بود.', new_nonce: 'server-fresh-nonce' } } };
		}
	}
	return null;
};
await click('#tbsm-scope-apply');
await waitFor('تکرار خودکار با توکن تازه', () => qa('#tbsm-group-attr option').length > 1);
ok('توکن منقضی خودکار تازه شد', window.tbsmData.nonce !== nonceBefore, window.tbsmData.nonce);
ok('از سرور توکن تازه گرفته شد', nonceAsks >= 1, String(nonceAsks));
ok('همان درخواست خودکار تکرار شد', attrAttempts >= 2, String(attrAttempts));
ok('تلاش دوم با توکن تازه موفق شد و ویژگی‌ها آمدند', qa('#tbsm-group-attr option').length > 1, String(qa('#tbsm-group-attr option').length));
ok('پیام «بررسی محدوده ناموفق بود» نیامد', !/بررسی محدوده ناموفق بود/.test(text('#tbsm-notice')), text('#tbsm-notice'));
window.TBSM_PREVIEW_FAULT = null;
await waitIdle();

// (۵) ناهم‌خوانی نسخه (کش/فایل قدیمی): باید صریح گفته شود
freshAttrs();
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => (data.action === 'tbsm_scope_attributes'
	? { resolve: { success: false, data: { message: 'خطای ناشناخته', version: '1.3.1' } } }
	: null);
await click('#tbsm-scope-apply');
await waitFor('پیام ناهم‌خوانی نسخه', () => /نسخهٔ رابط/.test(text('#tbsm-notice')));
ok('ناهم‌خوانی نسخه صریح گزارش شد', /نسخهٔ رابط/.test(text('#tbsm-notice')) && /1\.3\.1/.test(text('#tbsm-notice')), text('#tbsm-notice'));
window.TBSM_PREVIEW_FAULT = null;
window.TBSM.notice('');
await waitIdle();

/* ------------------------------------------------ نوع موجودی همگانی (۱٫۳٫۷) */
// سه حالت: تعداد / موجود بدون تعداد / ناموجود — روی همهٔ متغیرهای انتخابی.
ok('بخش «تشخیص مشکل» برداشته شده', q('#tbsm-scope-diag') === null && q('#tbsm-diag-box') === null);

// آماده‌سازی: آزمون‌های خطای قبلی عمداً محدوده را شکسته‌اند؛ دوباره یک
// محدودهٔ سالم می‌سازیم تا کارت‌ها و مقدار مدل حاضر باشند.
window.TBSM_PREVIEW_FAULT = null;
await click('#tbsm-tab-group');
await waitIdle();
await click('.tbsm-seg-btn[data-scope="products"]');
if (q('#tbsm-scope-clear')) { await click('#tbsm-scope-clear'); }
await type(doc.getElementById('tbsm-scope-search'), 'آیفون');
await waitFor('(پیش‌نیاز) نتایج محدوده', () => qa('#tbsm-scope-results .tbsm-scope-row').length === 3);
await click('#tbsm-scope-addall');
window.TBSM.notice('');
await click('#tbsm-scope-apply');
await waitIdle();
ok('(پیش‌نیاز) فهرست ویژگی‌ها آمد', qa('#tbsm-group-attr option').length > 1, String(qa('#tbsm-group-attr option').length));

const attrPick = qa('#tbsm-group-attr option').map((o) => o.value).filter((v) => v !== '')[0];
await change('#tbsm-group-attr', attrPick);
await waitFor('(پیش‌نیاز) مقدارهای مدل', () => qa('#tbsm-group-term option').length > 1);
const termPick = qa('#tbsm-group-term option').map((o) => o.value).filter((v) => v !== '')[0];
await change('#tbsm-group-term', termPick);
await waitFor('(پیش‌نیاز) کارت‌های متغیر', () => qa('#tbsm-grid .tbsm-var').length > 0, 4000);
ok('(پیش‌نیاز) کارت‌های متغیر ساخته شدند', qa('#tbsm-grid .tbsm-var').length > 0, String(qa('#tbsm-grid .tbsm-var').length));
ok('سلکت نوع موجودی همگانی هست', q('#tbsm-bulk-mode') !== null);
ok('سه حالت دارد', qa('#tbsm-bulk-mode option').map((o) => o.value).join(',') === 'qty,in_stock,out_of_stock', qa('#tbsm-bulk-mode option').map((o) => o.value).join(','));
ok('گزینهٔ «موجود بدون تعداد» هست', /موجود بدون تعداد/.test(text('#tbsm-bulk-mode')), text('#tbsm-bulk-mode'));
ok('پیش‌فرض «تعداد» است و ورودی عدد آشکار', q('#tbsm-bulk-mode').value === 'qty' && visible('#tbsm-quickset-wrap') && !q('#tbsm-quickset').disabled);

await change('#tbsm-bulk-mode', 'in_stock');
ok('در حالت وضعیتی، ورودی عدد پنهان می‌شود', !visible('#tbsm-quickset-wrap'));
ok('در حالت وضعیتی، ورودی عدد غیرفعال می‌شود', q('#tbsm-quickset').disabled === true);
await change('#tbsm-bulk-mode', 'out_of_stock');
ok('حالت «ناموجود» هم ورودی عدد را پنهان می‌کند', !visible('#tbsm-quickset-wrap'));
await change('#tbsm-bulk-mode', 'qty');
ok('بازگشت به «تعداد» ورودی را برمی‌گرداند', visible('#tbsm-quickset-wrap') && !q('#tbsm-quickset').disabled);

// همهٔ کارت‌ها انتخاب‌شده باشند تا اعمال همگانی معنا داشته باشد
qa('#tbsm-grid .tbsm-var').forEach((card) => card.classList.add('is-selected'));
await sleep(30);
window.TBSM.refreshCounts();

// «درج» در حالت موجود بدون تعداد: حالت کارت‌ها را عوض می‌کند، نه عدد را
await change('#tbsm-bulk-mode', 'in_stock');
window.TBSM.notice('');
await click('#tbsm-quickset-btn');
await sleep(40);
ok('«درج» در حالت موجود بدون تعداد، کارت‌ها را به همان حالت می‌برد',
	qa('#tbsm-grid .tbsm-var[data-mode="in_stock"]').length === qa('#tbsm-grid .tbsm-var').length,
	String(qa('#tbsm-grid .tbsm-var[data-mode="in_stock"]').length) + '/' + String(qa('#tbsm-grid .tbsm-var').length));
ok('سلکت هر کارت هم هم‌زمان به‌روز شد', qa('#tbsm-grid .tbsm-var .tbsm-var-mode').every((el) => el.value === 'in_stock'));
ok('پیام «حالت … روی n کارت تنظیم شد» آمد', /تنظیم شد/.test(noticeText()), noticeText());

// «درج» در حالت ناموجود
await change('#tbsm-bulk-mode', 'out_of_stock');
await click('#tbsm-quickset-btn');
await sleep(40);
ok('«درج» در حالت ناموجود هم کارت‌ها را ناموجود می‌کند',
	qa('#tbsm-grid .tbsm-var[data-mode="out_of_stock"]').length === qa('#tbsm-grid .tbsm-var').length);

// اعمال همگانی «موجود بدون تعداد»: بدون عدد، با تأیید مخصوص حالت
await change('#tbsm-bulk-mode', 'in_stock');
window.TBSM.notice('');
await click('#tbsm-unified');
ok('دکمهٔ اعمال (کلیک اول) حالت را در متن تأیید می‌آورد', /موجود بدون تعداد/.test(text('#tbsm-unified')), text('#tbsm-unified'));
await click('#tbsm-unified'); // کلیک دوم = اعمال واقعی

// محتوای درخواست با قلاب پیش‌نمایش گرفته می‌شود تا مطمئن شویم mode درست می‌رود
const grabSpec = () => {
	window.TBSM_PREVIEW_FAULT = (data) => {
		if (data.action === 'tbsm_apply_scope_stock') {
			const keys = Object.keys(data.items || {});
			window.__sentSpec = keys.length ? data.items[keys[0]] : null;
		}
		return null;
	};
};
window.__sentSpec = null;
grabSpec();
await waitFor('اعمال همگانی وضعیتی', () => window.__sentSpec !== null, 3000);
window.TBSM_PREVIEW_FAULT = null;
ok('درخواست اعمال، mode=in_stock و بدون qty می‌فرستد', !!window.__sentSpec && window.__sentSpec.mode === 'in_stock' && window.__sentSpec.qty === undefined, JSON.stringify(window.__sentSpec));
await waitFor('گزارش اعمال', () => /←/.test(text('#tbsm-report')), 3000);
ok('گزارش اعمال، تفاوت وضعیتی نشان می‌دهد', /←/.test(text('#tbsm-report')) && /موجود/.test(text('#tbsm-report')), text('#tbsm-report').slice(0, 90));

// اعمال همگانی «ناموجود»
await change('#tbsm-bulk-mode', 'out_of_stock');
window.__sentSpec = null;
grabSpec();
await click('#tbsm-unified');
await click('#tbsm-unified');
await waitFor('اعمال ناموجود', () => window.__sentSpec !== null, 3000);
window.TBSM_PREVIEW_FAULT = null;
ok('درخواست اعمال، mode=out_of_stock می‌فرستد', !!window.__sentSpec && window.__sentSpec.mode === 'out_of_stock', JSON.stringify(window.__sentSpec));
await waitFor('کارت‌های ناموجود', () => qa('#tbsm-grid .tbsm-var[data-mode="out_of_stock"]').length > 0, 3000);
ok('کارت‌ها بعد از اعمال، ناموجود شدند', qa('#tbsm-grid .tbsm-var[data-mode="out_of_stock"]').length > 0);

// مسیر عددی باید بعد از دو حالت وضعیتی سالم مانده باشد
await change('#tbsm-bulk-mode', 'qty');
await sleep(20);
ok('مسیر عددی بعد از دو حالت وضعیتی سالم است', visible('#tbsm-quickset-wrap') && !q('#tbsm-quickset').disabled);

/* ------------------------------------------------ تحمل شکل پاسخ سرور (۱٫۳٫۶) */
// ریشهٔ باگ واقعی: سرور برای ویژگی‌ها آرایهٔ تخت می‌فرستد؛ اگر نسخه/کشی شکل
// `{values: [...]}` را برگرداند، گام ۲ نباید خالی بماند.
freshAttrs();
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => {
	if (data.action === 'tbsm_scope_attributes') {
		return { resolve: { success: true, data: { scope: { type: 'sku', label: 'همهٔ محصولات', sku: '', sku_mode: 'prefix', product_ids: [], category_id: 0, products: 3, variations: 90 }, attributes: { values: [{ label: 'مدل', keys: ['model'], products: 3, variations: 41 }], groups: [], truncated: false } } } };
	}
	return null;
};
await click('#tbsm-scope-apply');
await waitFor('تحمل شکل کلیددار ویژگی‌ها', () => qa('#tbsm-group-attr option').length > 1);
ok('شکل {values:[…]} هم در گام ۲ خوانده می‌شود', qa('#tbsm-group-attr option').length > 1, String(qa('#tbsm-group-attr option').length));
ok('انتخاب ویژگی بعد از شکل کلیددار کار می‌کند', qa('#tbsm-group-attr option').some((o) => o.value === '0'));

/* ------------------------------------------------ استخراج پیام از پاسخ خطای PHP */
// همان چیزی که روی سایت کاربر دیده شد: صفحهٔ HTML خطای وردپرس + JSON خود افزونه.
window.TBSM.notice('');
window.TBSM_PREVIEW_FAULT = (data) => {
	if (data.action === 'tbsm_scope_attributes') {
		return {
			reject: {
				status: 500,
				responseText: '<html><body>There has been a critical error on this website.</body></html>\n{"success":false,"data":{"message":"خطای اجرای افزونه: TypeError — Argument 2 passed to TBSM_Stock::brand_groups() must be of the type array, null given (class-tbsm-stock.php خط ۶۱۶)","code":"php_exception"}}'
			}
		};
	}
	return null;
};
await click('#tbsm-scope-apply');
await waitFor('پیام استخراج‌شده از JSON خطا', () => /TypeError/.test(text('#tbsm-notice') || ''));
ok('پیام از JSON داخل پاسخ بیرون کشیده می‌شود (نه HTML)', /خطای اجرای افزونه/.test(text('#tbsm-notice') || ''), String(text('#tbsm-notice') || '').slice(0, 80));
ok('متن HTML خطای وردپرس در پیام نمی‌آید', !/critical error/.test(text('#tbsm-notice') || ''));
ok('کد خطای PHP هم در پیام هست', /php_exception|TypeError/.test(text('#tbsm-notice') || ''));
window.TBSM_PREVIEW_FAULT = null;


/* ------------------------------------------------ محدودهٔ «همهٔ محصولات» (۱٫۳٫۱۲) */
// کاربر: «ی اپشن هم بزار: همه محصولات — همرو انتخاب کنه ی جا و ویژگی‌هارو لیست کنه»
{
	// از حالت هرچه هست به تب گروهی برگرد و گزینهٔ «همهٔ محصولات» را بزن.
	await click('#tbsm-tab-group');
	await click('.tbsm-seg-btn[data-scope="all"]');

	ok('پنل «همهٔ محصولات» آشکار شد', visible('#tbsm-scope-pane-all'));
	ok('پنل چند-محصول پنهان شد', !visible('#tbsm-scope-pane-products'));
	ok('پنل دسته پنهان شد', !visible('#tbsm-scope-pane-category'));
	ok('پنل شناسه پنهان شد', !visible('#tbsm-scope-pane-sku'));
	ok('دکمهٔ «نمایش ویژگی‌ها» با همهٔ محصولات فعال است', q('#tbsm-scope-apply').disabled === false);
	ok('دکمهٔ انتخاب‌شده حالت فعال دارد', q('.tbsm-seg-btn[data-scope="all"]').classList.contains('is-active'));

	// آمار (auto-fetch در اولین انتخاب گزینه)
	await waitFor('آمار همهٔ محصولات', () => /—/.test(text('#tbsm-scope-all-stat')));
	const stat = text('#tbsm-scope-all-stat');
	ok('خط آمار پر شد (برچسب + شمار محصول و متغیر)', /^همهٔ محصولات — [۰-۹]+ محصول · [۰-۹]+ متغیر$/.test(stat), stat);
	ok('شمار متغیرها هم آمده (نه صفر)', /· [۰-۹]+ متغیر/.test(stat) && !/· ۰ متغیر/.test(stat), stat);

	// «همه‌رو ی جا» — با یک کلیک، ویژگی‌های کل فروشگاه فهرست می‌شود.
	await click('#tbsm-scope-apply');
	await waitFor('ویژگی‌های همهٔ محصولات', () => !q('#tbsm-step-model').classList.contains('tbsm-step-locked'));
	ok('گام ۲ با محدودهٔ «همهٔ محصولات» باز شد', !q('#tbsm-step-model').classList.contains('tbsm-step-locked'));
	const allAttrs = attrOptions();
	ok('ویژگی‌های مدل فهرست شدند', allAttrs.length > 1, allAttrs.join(' | '));
	ok('برچسب‌ها خوانا مانده‌اند (بدون درصد کدشده)', !allAttrs.some((t) => /%[0-9a-fA-F]{2}/.test(t)), allAttrs.join(' | '));
	ok('خلاصهٔ محدوده «همهٔ محصولات» را نام می‌برد', /همهٔ محصولات/.test(text('#tbsm-scope-summary')), text('#tbsm-scope-summary'));

	// اثبات «کل فروشگاه»: ویژگی‌هایی که در محدودهٔ چند-محصول (فقط آیفون) هرگز نمی‌آمدند
	// حالا هستند — «زمینه» و «نوع بندی» از محصولات بیرون آن محدوده‌اند.
	ok('ویژگی محصولات بیرون محدودهٔ قبلی هم آمد (زمینه/نوع بندی)', allAttrs.some((t) => /^زمینه/.test(t)) && allAttrs.some((t) => /^نوع بندی/.test(t)), allAttrs.join(' | '));
	const allModel = allAttrs.filter((t) => /^مدل/.test(t))[0] || '';
	const allModelCount = (allModel.match(/([۰-۹]+) محصول/) || [])[1];
	ok('شمار محصولِ ویژگی «مدل» بزرگ‌تر از محدودهٔ چند-محصول (۳) است', allModelCount && ['۴', '۵', '۶', '۷', '۸', '۹'].includes(allModelCount[0]), allModel);

	// انتخاب مدل و ساخت کارت‌ها
	const anyModel = qa('#tbsm-group-attr option').filter((o) => /^مدل/.test(o.textContent.trim()))[0];
	await change('#tbsm-group-attr', anyModel.value);
	await waitFor('مقدارهای مدل — همه', () => qa('#tbsm-group-term option').length > 1);
	ok('مقدارهای مدل برای کل فروشگاه آمدند', qa('#tbsm-group-term option').length > 1, termOptions().join(' | '));

	const firstTerm = qa('#tbsm-group-term option').filter((o) => o.value)[0];
	await change('#tbsm-group-term', firstTerm.value);
	await waitFor('کارت‌های همهٔ محصولات', () => qa('#tbsm-grid .tbsm-var').length > 0);
	const allCards = qa('#tbsm-grid .tbsm-var');
	ok('کارت متغیرها ساخته شد', allCards.length > 0, String(allCards.length));
	const parents = new Set(allCards.map((c) => (c.querySelector('.tbsm-var-name') || {}).textContent || ''));
	ok('هر کارت نام محصول والدش را دارد', [...parents].every((t) => t.trim().length > 0), [...parents].join(' | '));
	ok('گام ۳ باز شد', !q('#tbsm-step-cards').classList.contains('tbsm-step-locked'));
	ok('شمارندهٔ کارت‌ها به‌روز است', /[۰-۹]+ از [۰-۹]+/.test(text('#tbsm-counts')), text('#tbsm-counts'));

	// اعمال تکی روی یک کارت از نتیجهٔ «همهٔ محصولات» — کافی است جریان سالم بماند
	const firstSub = qa('#tbsm-grid .tbsm-var .tbsm-var-stock')[0];
	await type(firstSub, '9');
	await click('#tbsm-apply');
	await click('#tbsm-apply');
	await waitFor('گزارش اعمال همه', () => qa('#tbsm-report .tbsm-report-row').length > 0);
	ok('گزارش اعمال برای محدودهٔ کل فروشگاه آمد', qa('#tbsm-report .tbsm-report-row').length > 0, String(qa('#tbsm-report .tbsm-report-row').length));
	ok('گزارش «قبل ← بعد» دارد', text('#tbsm-report').includes('←'), text('#tbsm-report').slice(0, 90));
}

/* ------------------------------------------------ چیدمان صفحه (۱٫۳٫۸) */
// ریشهٔ باگ واقعی «صفحه تمام‌عرض شد / نوار خاکستری پهن در یک طرف»:
//  ۱) لایهٔ مشترک پدینگ #wpcontent را صفر می‌کرد (فاصلهٔ منوی کنار می‌رفت)،
//  ۲) مارجین .wrap ثابت بود (نه auto) → کارت وسط نمی‌نشست.
// این سنجه‌ها همان قرارداد را قفل می‌کنند (به‌ازای فایل، نه فقط پیش‌نمایش).
{
	const stripComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');
	const shared = stripComments(fs.readFileSync(path.join(ROOT, 'design/tisacase-ui.css'), 'utf8'));
	const hubCopy = stripComments(fs.readFileSync(path.join(ROOT, 'plugins/tisacase-hub/assets/tisacase-ui.css'), 'utf8'));
	const hubOwn = stripComments(fs.readFileSync(path.join(ROOT, 'plugins/tisacase-hub/assets/hub.css'), 'utf8'));
	const admin = stripComments(fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/assets/admin.css'), 'utf8'));

	ok('لایهٔ مشترک پدینگ کنارِ #wpcontent را صفر نمی‌کند', !/#wpcontent\s*\{[^}]*padding-(inline-start|inline-end|left|right)\s*:\s*0/.test(shared));
	ok('پوستهٔ هاب هم پدینگ #wpcontent را صفر نمی‌کند', !/#wpcontent\s*\{[^}]*padding-(inline-start|inline-end|left|right)\s*:\s*0/.test(hubOwn));
	ok('قاعدهٔ .wrap لایهٔ مشترک، مارجین auto دارد (وسط‌چین)', /\.tisa-scope\s+\.wrap\s*\{[^}]*margin-inline\s*:\s*auto/.test(shared), (shared.match(/\.tisa-scope\s+\.wrap\s*\{[^}]*\}/) || ['—'])[0].slice(0, 80));
	// پوستهٔ صفحه: اولین قاعدهٔ CSS که سلکتورش .tbsm-wrap دارد (لیست سلکتور مجاز است).
	{
		const shell = (admin.match(/\.tbsm-wrap[^{]*\{([^}]*)\}/) || [null, ''])[1];
		ok('پوستهٔ صفحه وسط‌چین است و سقف عرض دارد (قاعدهٔ عمومی — پیش‌نمایش)', /max-width\s*:\s*var\(--tisa-max-w/.test(shell) && /margin-inline\s*:\s*auto/.test(shell), shell.trim().split('\n')[0]);
		ok('قاعدهٔ شناسه‌دارِ مقاوم هم هست (#wpbody-content .tbsm-wrap / .tbsm-wrap.wrap) تا بازنویسی بیرونی اثر نکند',
			/#wpbody-content\s+\.tbsm-wrap\s*,/.test(admin) && /#wpbody-content\s+\.tbsm-wrap\.wrap\s*\{/.test(admin));
	}
	ok('دو نسخهٔ tisacase-ui.css (مرجع و کپی هاب) یکی هستند', shared.replace(/\s+/g, ' ').trim() === hubCopy.replace(/\s+/g, ' ').trim());

	// فوتر پیشخوان («نگارش ۷٫۱٫۳») روی صفحهٔ ما پنهان می‌شود — با کلاس بدنهٔ مخصوص همین صفحه.
	const php = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/includes/class-tbsm-admin.php'), 'utf8');
	ok('قاعدهٔ پنهان‌کردن فوتر پیشخوان هست', /body\.tbsm-page\s+#wpfooter\s*\{[^}]*display\s*:\s*none/.test(admin));
	ok('کلاس بدنهٔ tbsm-page فقط روی صفحهٔ ما اضافه می‌شود', /add_filter\(\s*'admin_body_class',\s*array\(\s*__CLASS__,\s*'body_class'\s*\)\s*\)/.test(php) && /if \( self::is_our_screen\(\) \)/.test(php));
	ok('اندازهٔ کارت‌ها در سطح خودِ کارت قفل شده (سقف عرض + وسط‌چینی)', /\.tbsm-wrap\s+\.tbsm-card[^{]*\{[^}]*max-width\s*:\s*min\(\s*100%\s*,\s*var\(--tisa-max-w/.test(admin) && /\.tbsm-wrap\s+\.tbsm-card[^{]*\{[^}]*margin-inline\s*:\s*auto/.test(admin));
	// باگ «فلش‌های کاشی‌شده»: shorthand زمینهٔ disabled زیرخصیصه‌های پیکان سلکت را
	// بازنشانی می‌کرد (repeat پیش‌فرض می‌ماند و تصویر از لایهٔ دیگر می‌آمد).
	ok('پیکان سلکت یکپارچه و بالاتر از لایهٔ هاب است (repeat: no-repeat)', /\.tbsm-wrap\s+\.tbsm-input\.tbsm-select\s*\{[^}]*background-repeat:\s*no-repeat/.test(admin));
	ok('حالت disabled با shorthand زمینهٔ پیکان را بازنشانی نمی‌کند', !/\.tbsm-input:disabled\s*\{[^}]*\bbackground:\s/.test(admin));
	// ارتفاع صفحه: منوی پیشخوانِ بلند نباید صفحه را به بلندی خودش بکشد (۱٫۳٫۱۱)
	const jsSrc = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/assets/admin.js'), 'utf8');
	ok('منو روی صفحهٔ ما خکوب می‌شود (fixed + اسکرول داخلی، از ۷۸۳px)', /@media screen and \(min-width: 783px\)[\s\S]{0,400}body\.tbsm-page #adminmenuwrap\s*\{[\s\S]{0,300}position:\s*fixed[\s\S]{0,300}overflow-y:\s*auto/.test(admin));
	ok('زیرمنوهای کشویی از قید overflow بیرون می‌آیند', /function menuFlyoutEscape/.test(jsSrc) && /\$\(menuFlyoutEscape\)/.test(jsSrc));
	ok('رفعِ زیرمنو فقط وقتی منو خکوب است اجرا می‌شود (صفحه‌های دیگر دست‌نخورده)', /'fixed' !== window\.getComputedStyle\(wrap\)\.position/.test(jsSrc) && /li\.classList\.contains\('wp-has-current-submenu'\)/.test(jsSrc));
	ok('کارت‌های داخل پنل هم سقف عرض دارند (شناسه‌دار)', /#wpbody-content \.tbsm-wrap \.tbsm-panel > \.tbsm-card/.test(admin));
}


/* ------------------------------------------------ هم‌خوانی نسخه در همهٔ فایل‌ها */
// جلوی یک اشتباه واقعی را می‌گیرد: بامپ نسخه در فایل اصلی PHP و جا ماندن
// پیش‌نمایش/readme یا برعکس — همان چیزی که «ناهم‌خوانی نسخه» را می‌سازد.
{
	const phpMain = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/tisacase-bulk-stock-manager.php'), 'utf8');
	const readme = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/readme.txt'), 'utf8');
	const readmeMd = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/README.md'), 'utf8');
	const pot = fs.readFileSync(path.join(ROOT, 'plugins/src/tisacase-bulk-stock-manager/languages/tisacase-bsm.pot'), 'utf8');
	const phpVer = (phpMain.match(/\* Version:\s+([\d.]+)/) || [])[1];
	const constVer = (phpMain.match(/TBSM_VERSION',\s*'([\d.]+)'/) || [])[1];
	const faVer = phpVer ? phpVer.replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[+d]).replace(/\./g, '٫') : '';
	ok('نسخهٔ پیش‌نمایش با نسخهٔ افزونه یکی است', window.tbsmData.version === phpVer, window.tbsmData.version + ' ≠ ' + phpVer);
	ok('نسخه در TBSM_VERSION هم همان است', constVer === phpVer, String(constVer));
	ok('هدر HTML پیش‌نمایش هم همان نسخه است', html.includes('v' + phpVer));
	ok('readme.txt (Stable tag) هم همان نسخه است', readme.includes('Stable tag: ' + phpVer));
	ok('README.md هم همان نسخه است', readmeMd.includes(faVer));
	ok('فایل ترجمه (.pot) هم همان نسخه است', pot.includes('TisaCase Bulk Stock Manager ' + phpVer));
	ok('بلوک چنج‌لاگ همان نسخه در readme هست', readme.includes('= ' + phpVer + ' ='));
}


ok('هیچ خطای صفحه‌ای ثبت نشد', errors.length === 0, errors.slice(0, 3).join(' | '));

console.log('\n' + '─'.repeat(72));
for (const r of results) {
	if (!r.cond) console.log('✗ ' + r.name + (r.extra ? '  «' + r.extra + '»' : ''));
}
console.log('\nنتیجه: ' + pass + ' سبز · ' + fail + ' قرمز · مجموع ' + (pass + fail) + ' سنجه');
process.exit(fail ? 1 : 0);
