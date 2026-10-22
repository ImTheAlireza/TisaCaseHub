#!/usr/bin/env node
/**
 * گارد «دقت فهرست بلااستفاده — بررسی در خودِ اسکن» (Unused Accuracy Contract · v2).
 *
 * تاریخچه:
 *  - کاربر اول گفت: «۱۳ مورد استفاده‌شده در لیست بلااستفاده بود».
 *  - بعد گفت: «دوست ندارم هنگام باز کردن فهرست دوباره چک شود؛ همان اسکن مجدد
 *    که می‌زنم باید خودش بفهمد واقعاً بلااستفاده است یا نه.»
 *
 * پس قرارداد این است:
 *   ۱) اسکن و بازرسی زندهٔ حذف از «یک فهرست کانونی» منابع استفاده بخوانند.
 *   ۲) خودِ اسکن، پیش از نهایی‌شدن کاتالوگ، یک گام «بازبینی نهایی» روی
 *      تصاویر بدون مرجع اجرا کند (bounded، بخشی از پیشرفت اسکن).
 *   ۳) فهرست‌ها هیچ بازرسی اضافه‌ای هنگام نمایش اجرا نکنند.
 *   ۴) بازرسی ایمن پیش از حذف سر جایش بماند (هیچ عکسی بی‌بررسی حذف نمی‌شود).
 *   ۵) نتیجهٔ حذف به کاربر با دلیل دقیق و قابل‌فهم گزارش شود.
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

/** بدنهٔ یک تابع PHP با شمارش آکولاد. */
const phpBody = (src, name) => {
	const start = src.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const i = src.indexOf('{', start);
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

const jsBody = (name) => {
	const start = js.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const rest = js.slice(start + 10);
	const next = rest.search(/\n\t(?:async )?function /);
	return next === -1 ? rest : rest.slice(0, next);
};

console.log('── ۱) یک فهرست کانونی برای منابع استفاده ──');
const fastRefs = phpBody(scanner, 'run_stage_fast_refs');
ok('اسکنر کلیدهای متای تصویری را از موتور استفاده می‌گیرد', /Usage::gallery_meta_keys\(\)/.test(fastRefs));
ok('اسکنر فهرست محلی کوتاه‌تر ندارد', !/\$media_meta_keys\s*=\s*array\(/.test(fastRefs));
ok('گزینه‌های مستقیم از فهرست کانونی می‌آیند', /Usage::direct_option_labels\(\)/.test(fastRefs));
ok('کلیدهای کانونی شامل توییتر/سئو و المنتور است', /_yoast_wpseo_twitter-image-id/.test(usage) && /_elementor_page_image/.test(usage));
ok('بازرسی زندهٔ حذف هم از همان فهرست می‌خواند', /gallery_meta_keys\(\)/.test(phpBody(usage, 'live_refs_chunk')));

console.log('── ۲) الگوهای شناسه‌ای مشترک ──');
const extract = phpBody(scanner, 'extract_ids_from_text_or_data');
ok('الگوی data-id / data-attachment-id شناسایی می‌شود', /data-attachment-id/.test(extract) && /data-id/.test(extract));
ok('الگوی attachment_id / image_id شناسایی می‌شود', /attachment_id\|image_id/.test(extract));
ok('آرایهٔ سریالایز PHP شناسایی می‌شود', /i:\(\[0-9\]/.test(extract));
ok('متای تصویری سریالایز/JSON هم خوانده می‌شود', /extract_ids_from_text_or_data\( \$raw_meta/.test(fastRefs));

console.log('── ۳) «واقعاً بلااستفاده؟» داخل خودِ اسکن ──');
ok('گام live_reconcile در تابع اسکن وجود دارد', /function run_stage_live_reconcile\(/.test(scanner));
ok('گام در ماشین وضعیت اسکن فراخوانی می‌شود', /'live_reconcile' === \$stage[\s\S]{0,400}run_stage_live_reconcile/.test(scanner));
ok('پس از تشخیص تکراری‌ها به بازبینی می‌رود', /'hash_dups' : 'live_reconcile'/.test(scanner) && /cursor_hash_idx'\] >= \$total_h[\s\S]{0,200}'live_reconcile'/.test(scanner));
ok(
	'فقط تصاویر بدون هیچ مرجع بازبینی می‌شوند',
	/\$has\s*=\s*! empty\( \$refs\['active'\] \)[\s\S]{0,700}\$no_refs\[\] = \$aid/.test(
		phpBody(scanner, 'run_stage_live_reconcile')
	)
);
const reconcile = phpBody(scanner, 'run_stage_live_reconcile');
ok('بازبینی از همان موتور بازرسی زندهٔ حذف استفاده می‌کند', /Usage::live_verified_refs\(/.test(reconcile));
ok('بسته‌های کوچک (۲۵تایی) اجرا می‌شود', /array_slice\( \$queue, \$offset, 25 \)/.test(reconcile));
ok('سقف زمانی تیک اسکن رعایت می‌شود', /microtime\( true \) - \$started_tick \) < \$budget/.test(reconcile));
ok('مرجع پیداشده در کاتالوگ ثبت می‌شود', /record_ref\(/.test(reconcile) && /بازبینی نهایی اسکن/.test(reconcile));
ok('پیشرفت اسکن برای این گام نمایش داده می‌شود', /بازبینی نهایی «واقعاً بلااستفاده است\؟»/.test(scanner) && /reconcile_index/.test(scanner));
ok('«نمایش فوری» این گام را رد می‌کند (اجازهٔ حذف هم ندارد)', /fast_finish[\s\S]{0,220}\$state\['stage'\] = 'finalize'/.test(scanner.split("'live_reconcile' === $stage")[1] || ''));
ok(
	'وضعیت اسکن قدیمی سازگار می‌شود',
	/reconcile_queue'\][\s\S]{0,60}= array\(\)/.test(phpBody(scanner, 'normalize_state'))
);

console.log('── ۴) فهرست‌ها هنگام نمایش بازرسی اضافه نمی‌کنند ──');
const query = phpBody(inspector, 'query_items');
ok('query_items بازرسی زنده ندارد', !/probe_live_usage|live_verified_refs/.test(query));
ok('پاسخ فهرست کلید verify ندارد', !/'verify'\s*=>/.test(query));
ok('کد مردهٔ اصلاح کاتالوگ پاک شد', !/function apply_usage_corrections|function probe_live_usage/.test(inspector));
ok('رابط کاربری حلقهٔ بازرسی ندارد', !/continueUnusedVerify|state\.unusedVerify/.test(js));
ok('پیام «بازرسی زنده ادامه دارد» حذف شد', !/بازرسی زنده ادامه دارد/.test(js));
ok('پیام «از این فهرست خارج شد» حذف شد', !/از این فهرست خارج شد/.test(js));

console.log('── ۵) یادآوری اسکن کهنه و بازرسی ایمن پیش از حذف ──');
ok('سن کاتالوگ در خلاصهٔ داشبورد هست', /'scan_age_hours'\s*=>/.test(inspector));
ok('یادآوری «اسکن مجدد» برای کاتالوگ کهنه وجود دارد', /scan_age_hours/.test(js) && /tco-rescan-unused/.test(js));
ok('دکمهٔ یادآوری به اسکن تازه وصل است', /#tco-rescan-unused[\s\S]{0,120}ensureDataForCurrentView\(true\)/.test(js));
ok('بازرسی ایمن پیش از حذف حذف نشده', /self::live_refs\( \$fresh_ids \)/.test(phpBody(usage, 'verify_batch')));
ok('گام بازرسی پیش از حذف در موتور کار هست', /verify_step/.test(job) && /verify_batch\(/.test(job));

console.log('── ۶) شفافیت نتیجهٔ حذف ──');
ok('کارت «نتیجهٔ آخرین عملیات» وجود دارد', /function renderLastRunCard/.test(js));
ok('دلیل و نمونهٔ موارد حذف‌نشده نمایش داده می‌شود', /چرا این موارد حذف نشدند/.test(js) && /blockedItems/.test(js));
ok('پیام پایان کار از دلیل واقعی استفاده می‌کند', /const reasons = \(state\.batch\.blockReasons/.test(js));
ok('جملهٔ مبهم «یا اسکن کامل نیست» حذف شد', !/یا اسکن کامل نیست\) حذف نشد/.test(js));
ok('نمونهٔ موارد مسدود در خلاصهٔ کار هست', /'blocked'\s*=>\s*\$blocked_sample/.test(job));
ok('دلایل تجمیع‌شده در خلاصهٔ کار هست', /'block_reasons'\s*=>\s*\$block_reasons/.test(job));
ok('کلاس‌های CSS کارت تعریف شده‌اند', ['.tco-result-card', '.tco-reason-list', '.tco-details'].every((c) => css.includes(c)));

console.log(
	`\n${fails.length === 0 ? '✓' : '✗'} هر ${pass} سنجهٔ «دقت فهرست بلااستفاده» ${
		fails.length === 0 ? 'سالم است.' : 'نقض شد:'
	}`
);
if (fails.length) {
	fails.forEach((f) => console.log(`   - ${f}`));
	process.exit(1);
}
