#!/usr/bin/env node
/**
 * گارد «بازخورد عملیات» (Operation Feedback Contract).
 *
 * چرا؟ کاربر گزارش داد: «دکمه را می‌زنم و هیچ اتفاقی نمی‌بینم در حالی که کار در جریان است.»
 * این تست قفل می‌کند که هیچ عملیاتی بی‌بازخورد نماند:
 *
 *   ۱) هر `api('op')` در جاوااسکریپت باید برچسب فارسی در OP_LABELS داشته باشد
 *      (وگرنه چیپ «در حال انجام» برچسب عمومی نشان می‌دهد).
 *   ۲) هر فراخوانی شبکه باید از مسیر busyStart/busyEnd عبور کند.
 *   ۳) هر عملیات طولانی (دسته‌ای/اسکن) باید توکن نگه‌دارنده داشته باشد تا نوار
 *      بین درخواست‌ها خاموش‌روشن (flicker) نشود.
 *   ۴) هر دکمهٔ کلیک‌پذیر در DOM باید در ACTION_SELECTOR ثبت شده باشد
 *      تا با کلیک، قفل + اسپینر بگیرد.
 *   ۵) هر دکمهٔ `disabled` باید `title` توضیح‌دهنده داشته باشد (کلیک بی‌واکنش ممنوع).
 *   ۶) پوشش CSS: کلاس‌های نشانگر باید در stylesheet تعریف شده باشند.
 *
 * اجرا: node tools/optimizer-feedback-check.mjs
 * خروج ۱ = نقض قرارداد.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const JS = path.join(ROOT, 'plugins/src/tisacase-optimizer/assets/admin.js');
const CSS = path.join(ROOT, 'plugins/src/tisacase-optimizer/assets/admin.css');

const js = fs.readFileSync(JS, 'utf8');
const css = fs.readFileSync(CSS, 'utf8');

let pass = 0;
const fails = [];
const ok = (name, cond, hint = '') => {
	if (cond) {
		pass += 1;
		console.log(`  ✓ ${name}`);
	} else {
		fails.push(`${name}${hint ? ' — ' + hint : ''}`);
		console.log(`  ✗ ${name}${hint ? ' — ' + hint : ''}`);
	}
};

const bodyOf = (name) => {
	const start = js.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const rest = js.slice(start + 10);
	const next = rest.search(/\n\t(?:async )?function /);
	return next === -1 ? rest : rest.slice(0, next);
};

console.log('── ۱) برچسب فارسی برای هر عملیات ──');
const labelBlock = js.slice(js.indexOf('const OP_LABELS = {'), js.indexOf('const busy = {'));
const labeled = new Set([...labelBlock.matchAll(/^\s*([a-z_]+):/gm)].map((m) => m[1]));
const apiOps = new Set([...js.matchAll(/api\(\s*'([a-z_]+)'/g)].map((m) => m[1]));

ok('جدول برچسب عملیات وجود دارد', labeled.size >= 15, `تعداد: ${labeled.size}`);
const unlabeled = [...apiOps].filter((op) => !labeled.has(op)).sort();
ok(
	`همهٔ ${apiOps.size} عملیات فراخوانی‌شده برچسب فارسی دارند`,
	unlabeled.length === 0,
	unlabeled.length ? 'بدون برچسب: ' + unlabeled.join(', ') : ''
);

console.log('── ۲) مسیر سراسری busy برای درخواست‌ها ──');
const apiBody = bodyOf('api');
ok('api() در ابتدا توکن busy می‌سازد', /busyStart\(/.test(apiBody));
ok('api() در خطای شبکه توکن را می‌بندد', /catch \(netErr\)[\s\S]{0,200}busyEnd\(token\)/.test(apiBody));
ok('api() پس از دریافت پاسخ توکن را در finally می‌بندد', /finally \{\s*busyEnd\(token\);\s*\}/.test(apiBody));
ok('api() در هر مسیر بازگشت، توکن آزاد می‌شود', (apiBody.match(/busyEnd\(token\)/g) || []).length >= 2);

console.log('── ۳) توکن نگه‌دارنده برای عملیات چندمرحله‌ای ──');
for (const [fn, token] of [
	['runIncrementalScan', 'scanToken'],
	['runBatchRemove', 'runToken'],
	['runBatchRestoreQuarantine', 'restoreToken'],
	['runBatchOptimize', 'optToken'],
]) {
	const body = bodyOf(fn);
	ok(`${fn}: توکن اختصاصی ${token} ساخته می‌شود`, body.includes(`busyStart(`) && body.includes(token));
	ok(`${fn}: توکن در finally آزاد می‌شود`, new RegExp(`finally\\s*\\{[\\s\\S]{0,120}busyEnd\\(${token}\\)`).test(body));
}
ok('درصد پیشرفت در اسکن به‌روزرسانی می‌شود', /busyProgress\(\s*\n?\s*Number\(step\.percent/.test(js));
ok('درصد پیشرفت دسته‌ها از batchPct به‌روزرسانی می‌شود', /function batchPct\(/.test(js) && /busyProgress\(pct,/.test(js));

console.log('── ۴) ثبت همهٔ کنترل‌ها در ACTION_SELECTOR ──');
const selectorBlock = js.slice(js.indexOf('const ACTION_SELECTOR = ['), js.indexOf('].join(\',\');', js.indexOf('const ACTION_SELECTOR = [')));
const registered = new Set([...selectorBlock.matchAll(/'([^']+)'/g)].map((m) => m[1]));

// همهٔ شناسه‌هایی که در HTML تولیدی، هندلر کلیک دارند.
const handledIds = new Set();
for (const m of js.matchAll(/ev\.target\.closest\('(#[a-z0-9-]+)'\)/g)) handledIds.add(m[1]);
for (const m of js.matchAll(/ev\.target\.closest\('\[data-([a-z-]+)\]'\)/g)) handledIds.add(`[data-${m[1]}]`);
for (const m of js.matchAll(/ev\.target\.getElementById|document\.getElementById\('([a-z0-9-]+)'\)\.click/g)) handledIds.add('#' + m[1]);

const missing = [...handledIds].filter((sel) => !registered.has(sel)).sort();
ok(
	`همهٔ ${handledIds.size} کنترل دارای هندلر در ACTION_SELECTOR ثبت شده‌اند`,
	missing.length === 0,
	missing.length ? 'ثبت‌نشده: ' + missing.join(', ') : ''
);
ok('کلیک‌های غیرعملیاتی (مثل اسلایدر) عمداً بیرون مانده‌اند', !registered.has('#tco-cmp-slider'));
ok('دکمهٔ توقف دسته از قفل‌شدن مستثنی است', /el\.id === 'tco-stop-batch'/.test(js));

console.log('── ۵) هیچ دکمهٔ disabled بی‌توضیح نیست ──');
// فقط دکمه‌ها؛ چک‌باکس‌های «سایز ضروری» عمداً قفل‌اند و برچسب متنی کنارشان توضیح می‌دهد.
const disabledMarks = [...js.matchAll(/\$\{[^}]*?\?\s*'disabled([^']*)'/g)]
	.map((m) => m[0])
	.filter((m) => !/essential/.test(m));
const withoutTitle = disabledMarks.filter((m) => !m.includes('title='));
ok(`همهٔ ${disabledMarks.length} حالت غیرفعال توضیح دارند`, withoutTitle.length === 0, withoutTitle.length ? withoutTitle.slice(0, 3).join(' | ') : '');
ok('CSS برای دکمهٔ غیرفعال، مکان‌نما را تغییر می‌دهد', /\[disabled\][\s\S]{0,160}not-allowed|disabled[\s\S]{0,80}not-allowed/.test(css) || /\.tisa-btn\[disabled\]/.test(css));

console.log('── ۶) پوشش CSS نشانگرها ──');
for (const cls of ['.tco-busybar', '.tco-busy-chip', '.tco-is-busy', '.tco-spinner--sm']) {
	ok(`کلاس ${cls} در stylesheet تعریف شده`, css.includes(cls));
}
ok('نوار انیمیشن نامعین دارد', /@keyframes tco-busy-slide/.test(css));
ok('حالت درصدی (determinate) پشتیبانی می‌شود', /\.tco-busybar\.is-determinate/.test(css));
ok('احترام به prefers-reduced-motion', /prefers-reduced-motion/.test(css));
ok('نوار در حالت ادمین‌بار جابه‌جا می‌شود (روی ابزار وردپرس نیفتد)', /\.admin-bar \.tco-busybar/.test(css));

console.log('── ۷) دسترس‌پذیری ──');
ok('نوار role=progressbar دارد', /bar\.setAttribute\('role', 'progressbar'\)/.test(js));
ok('چیپ aria-live دارد', /aria-live/.test(js));
ok('درصد در aria-valuenow منتشر می‌شود', /aria-valuenow/.test(js));
ok('دکمهٔ در حال اجرا aria-busy می‌گیرد', /setAttribute\('aria-busy', 'true'\)/.test(js));
ok('پس از پایان، aria-busy برداشته می‌شود', /removeAttribute\('aria-busy'\)/.test(js));

console.log('── ۸) ضدپرش و ضدقفل‌ماندن ──');
ok('نمایش نشانگر با تأخیر کوتاه (ضدپرش) انجام می‌شود', /setTimeout\([\s\S]{0,120}\}, 220\)/.test(js));
ok('شمارندهٔ عملیات هم‌زمان مدیریت می‌شود', /busy\.count \+= 1/.test(js) && /busy\.count = Math\.max\(0, busy\.count - 1\)/.test(js));
ok('خطای هندلر به اعلان فارسی تبدیل می‌شود (نه سکوت)', /catch \(err\) \{\s*\n\s*toast\(\(err && err\.message\)/.test(js));

console.log('');
if (fails.length) {
	console.error(`✗ ${fails.length} نقض در قرارداد بازخورد عملیات:`);
	for (const f of fails) console.error('   • ' + f);
	process.exit(1);
}
console.log(`✓ هر ${pass} سنجهٔ «بازخورد عملیات» سالم است.`);
