#!/usr/bin/env node
/**
 * گارد «هیچ تصویرِ در حال استفاده‌ای در فهرست بلااستفاده نمی‌آید» (Unused Accuracy Contract).
 *
 * چرا؟ کاربر گزارش داد: «۱۲ تصویر به قرنطینه رفت و ۱۳ مورد چون هنوز در سایت
 * استفاده می‌شود حذف نشد — چرا این ۱۳ مورد در لیست بلااستفاده بودند؟»
 *
 * ریشه: فهرست از کاتالوگ اسکن ساخته می‌شد، ولی پیش از حذف یک بازرسی زندهٔ
 * دیتابیس اجرا می‌شد که چند منبع را بیشتر می‌دید (۱۱ کلید متای تصویری در برابر
 * ۷ کلید در اسکنر، الگوهای محتوایی مثل `data-id` و متاهای سریالایز). نتیجه:
 * تصویر در فهرست «بلااستفاده» دیده می‌شد و بعد «هنوز استفاده می‌شود» رد می‌شد.
 *
 * این گارد قفل می‌کند:
 *   ۱) اسکنر و بازرسی زنده از «یک فهرست کانونی» بخوانند (بدون فهرست محلی کوتاه‌تر).
 *   ۲) فهرست strict_unused پیش از نمایش، با دیتابیس زنده مقایسه شود (زمان‌بندی‌شده و کش‌شده).
 *   ۳) نتیجهٔ این مقایسه در کاتالوگ ثبت شود تا شمارنده‌ها و «قدم بعدی» هم درست شوند.
 *   ۴) رابط کاربری، تعداد و دلیل خارج‌شدن موارد را صریح نشان دهد و کارت «نتیجهٔ آخرین عملیات» داشته باشد.
 *
 * اجرا: node tools/unused-accuracy-guard.mjs
 * خروج ۱ = نقض قرارداد.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'plugins/src/tisacase-optimizer');
const read = (p) => fs.readFileSync(path.join(SRC, p), 'utf8');

const usage = read('includes/class-tco-usage.php');
const scanner = read('includes/class-tco-reference-scanner.php');
const inspector = read('includes/class-tco-media-inspector.php');
const job = read('includes/class-tco-job.php');
const js = read('assets/admin.js');
const css = read('assets/admin.css');

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

/** بدنهٔ یک تابع PHP را با شمارش آکولاد برمی‌گرداند. */
const phpBody = (src, name) => {
	const start = src.indexOf(`function ${name}(`);
	if (start === -1) return '';
	let i = src.indexOf('{', start);
	if (i === -1) return '';
	let depth = 0;
	for (let j = i; j < src.length; j += 1) {
		if (src[j] === '{') depth += 1;
		else if (src[j] === '}') {
			depth -= 1;
			if (depth === 0) return src.slice(i, j + 1);
		}
	}
	return '';
};

/** بدنهٔ یک تابع جاوااسکریپت سطح ماژول. */
const jsBody = (name) => {
	const start = js.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const rest = js.slice(start + 10);
	const next = rest.search(/\n\t(?:async )?function /);
	return next === -1 ? rest : rest.slice(0, next);
};

console.log('── ۱) یک فهرست کانونی برای «منابع استفاده» ──');
const fastRefs = phpBody(scanner, 'run_stage_fast_refs');
ok('اسکنر کلیدهای متای تصویری را از موتور استفاده می‌گیرد', /Usage::gallery_meta_keys\(\)/.test(fastRefs));
ok(
	'اسکنر فهرست محلی و کوتاه‌تر ندارد',
	!/\$media_meta_keys\s*=\s*array\(/.test(fastRefs),
	'فهرست محلی دوباره اضافه شده است'
);
ok('گزینه‌های مستقیم از فهرست کانونی می‌آیند', /Usage::direct_option_labels\(\)/.test(fastRefs));
ok('کلیدهای کانونی شامل تصویر توییتر/سئو و المنتور است', /_yoast_wpseo_twitter-image-id/.test(usage) && /_elementor_page_image/.test(usage));
ok('همان فهرست در بازرسی زندهٔ حذف هم استفاده می‌شود', /gallery_meta_keys\(\)/.test(phpBody(usage, 'live_refs_chunk')));

console.log('── ۲) الگوهای شناسه‌ای که فقط بازرسی زنده می‌دید ──');
const extract = phpBody(scanner, 'extract_ids_from_text_or_data');
ok('الگوی data-id / data-attachment-id اضافه شد', /data-attachment-id/.test(extract) || /data-id/.test(extract));
ok('الگوی attachment_id / image_id اضافه شد', /attachment_id\|image_id/.test(extract));
ok('آرایهٔ سریالایز PHP پشتیبانی می‌شود', /i:\(\[0-9\]/.test(extract));
ok('متای تصویری با مقدار JSON/سریالایز هم خوانده می‌شود', /extract_ids_from_text_or_data\( \$raw_meta/.test(fastRefs));

console.log('── ۳) بازرسی زنده پیش از نمایش فهرست ──');
ok('تابع عمومی بازرسی زنده وجود دارد', /public static function live_verified_refs\(/.test(usage));
const query = phpBody(inspector, 'query_items');
ok('فهرست strict_unused بازرسی زنده می‌شود', /'strict_unused' === \$usage[\s\S]{0,120}probe_live_usage/.test(query));
ok('نتیجهٔ بازرسی به رابط کاربری برگردانده می‌شود', /'verify'\s*=>\s*\$live_verify/.test(query));
ok('ردیف‌های استفاده‌شده از فهرست حذف می‌شوند', /array_filter\([\s\S]{0,200}\$rec/.test(query));

const probe = phpBody(inspector, 'probe_live_usage');
ok('بازرسی در چانک‌های کوچک اجرا می‌شود', /array_chunk\( \$pending, 25 \)/.test(probe));
ok('سقف تعداد در هر درخواست دارد', /\$limit\s*=\s*max\( 10, min\( 500/.test(probe));
ok('سقف زمانی دارد (بار سرور کنترل‌شده)', /live_probe_budget/.test(probe) && /microtime\( true \)/.test(probe));
ok('نتیجه را کش می‌کند تا هر صفحه دوباره کوئری نزند', /LIVE_PROBE_KEY/.test(probe) && /set_transient/.test(probe));
ok('کش بی‌رویه بزرگ نمی‌شود', /count\( \$cache \) > 5000/.test(probe));
ok('دلیل فارسی هر مورد ذخیره می‌شود', /reclassified\[ \$id \]/.test(probe));

console.log('── ۴) اصلاح کاتالوگ (هم‌خوانی شمارنده‌ها با حقیقت) ──');
const applyFn = phpBody(inspector, 'apply_usage_corrections');
ok('وضعیت کاتالوگ به «استفاده‌شده» تغییر می‌کند', /'usage_status'\]\s*=\s*isset\( \$pinned/.test(applyFn) && /'used'/.test(applyFn));
ok('دلیل استفاده در کاتالوگ ثبت می‌شود', /'label'\s*=>\s*\$reason/.test(applyFn));
ok('کاتالوگ نوشته و کش‌ها باطل می‌شوند', /write_persisted_catalog/.test(applyFn) && /flush_runtime_cache/.test(applyFn));
ok('زمان بازرسی روی آیتم ثبت می‌شود', /live_probed_at/.test(applyFn));
ok('موارد پین‌شده همچنان محافظت می‌شوند', /'pinned'/.test(applyFn));

console.log('── ۵) شفافیت در رابط کاربری ──');
ok('نوار «از فهرست خارج شد» وجود دارد', /از این فهرست خارج شد/.test(js));
ok('دلیل استفاده‌شدن در همان نوار دیده می‌شود', /v\.samples/.test(js) || /samples/.test(js));
ok('بازرسی ناتمام خودکار ادامه می‌یابد', /function continueUnusedVerify/.test(js) && /state\.unusedVerify\.complete/.test(js));
ok('پیشرفت بازرسی به کاربر نشان داده می‌شود', /بازرسی زندهٔ استفاده:/.test(js));
ok('کارت «نتیجهٔ آخرین عملیات» وجود دارد', /function renderLastRunCard/.test(js));
ok('دلیل و نمونهٔ موارد حذف‌نشده در کارت هست', /چرا این موارد حذف نشدند/.test(js) && /blockedItems/.test(js));
ok('کارت پس از اجرا ذخیره می‌شود', /state\.lastRun = \{/.test(js));
ok('پیام پایان کار از دلیل واقعی استفاده می‌کند', /const reasons = \(state\.batch\.blockReasons/.test(js));
ok('جملهٔ مبهم «یا اسکن کامل نیست» از پیام حذف شد', !/یا اسکن کامل نیست\) حذف نشد/.test(js));
ok('کارت قابل بستن است', /#tco-dismiss-run/.test(js) && /'#tco-dismiss-run',/.test(js));
ok('کلاس‌های CSS کارت تعریف شده‌اند', ['.tco-result-card', '.tco-reason-list', '.tco-details'].every((c) => css.includes(c)));

console.log('── ۶) گزارش کار سرور ──');
ok('نمونهٔ موارد مسدود در خلاصهٔ کار هست', /'blocked'\s*=>\s*\$blocked_sample/.test(job));
ok('دلایل تجمیع‌شده برای نمایش هست', /'block_reasons'\s*=>\s*\$block_reasons/.test(job));

console.log(
	`\n${fails.length === 0 ? '✓' : '✗'} هر ${pass} سنجهٔ «دقت فهرست بلااستفاده» ${
		fails.length === 0 ? 'سالم است.' : 'نقض شد:'
	}`
);
if (fails.length) {
	fails.forEach((f) => console.log(`   - ${f}`));
	process.exit(1);
}
