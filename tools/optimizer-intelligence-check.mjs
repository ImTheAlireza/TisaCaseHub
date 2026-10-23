#!/usr/bin/env node
/**
 * گارد «هوشمندی و بهینه‌بودن» (نسخه ۱.۲).
 *
 * قفل می‌کند دستاوردهای بازنویسی معماری از بین نروند:
 *   A2) خلاصهٔ کش‌شدهٔ کاتالوگ: نمای کلی و ساخت صف نباید کاتالوگ را دوباره رمزگشایی کنند.
 *   A3) فهرست نسخه‌های موازی: تحویل `<picture>` نباید برای هر تصویر `is_file` بزند.
 *   A5) ریزبهینه‌سازی‌ها: کش `get_all()`/`pinned_ids()`/`summary()` و استفادهٔ مجدد از فهرست فایل‌ها.
 *   B1) موتور «قدم بعدی» با دکمهٔ اقدام در رابط.
 *   B2) توصیهٔ هوشمند برای هر زیرسایز.
 *   B6) پاک‌سازی کش/CDN پس از تغییر واقعی فایل‌ها.
 *   B7) چکاپ پیش از عملیات با امتیاز آمادگی.
 *
 * اجرا: node tools/optimizer-intelligence-check.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const P = (rel) => path.join(ROOT, 'plugins/src/tisacase-optimizer', rel);
const read = (rel) => fs.readFileSync(P(rel), 'utf8');

const inspector = read('includes/class-tco-media-inspector.php');
const scanner = read('includes/class-tco-reference-scanner.php');
const delivery = read('includes/class-tco-webp-delivery.php');
const settings = read('includes/class-tco-settings.php');
const usage = read('includes/class-tco-usage.php');
const vault = read('includes/class-tco-vault.php');
const ajax = read('includes/class-tco-ajax.php');
const job = read('includes/class-tco-job.php');
const subsizes = read('includes/class-tco-subsizes.php');
const plugin = read('tisacase-optimizer.php');
const js = read('assets/admin.js');
const css = read('assets/admin.css');
const summary = read('includes/class-tco-catalog-summary.php');
const sidecar = read('includes/class-tco-sidecar-index.php');
const purger = read('includes/class-tco-cache-purger.php');
const preflight = read('includes/class-tco-preflight.php');
const mock = fs.readFileSync(path.join(ROOT, 'design/preview/optimizer-mock-api.js'), 'utf8');

let pass = 0;
const fails = [];
const ok = (name, cond) => {
	if (cond) {
		pass += 1;
		console.log(`  ✓ ${name}`);
	} else {
		fails.push(name);
		console.log(`  ✗ ${name}`);
	}
};

console.log('── A2) خلاصهٔ کاتالوگ ──');
ok('کلاس خلاصهٔ کاتالوگ وجود دارد و ثبت شده', /class TisaCase_Optimizer_Catalog_Summary/.test(summary) && /Catalog_Summary'\s+=> 'class-tco-catalog-summary\.php'/.test(plugin));
ok('نمای کلی ابتدا خلاصه را می‌خواند', /function overview_stats[\s\S]{0,600}Catalog_Summary::overview\(\)/.test(inspector));
ok('نمای کلی خلاصه را پس از محاسبه می‌نویسد', /Catalog_Summary::write\( \$overview, \$items \)/.test(inspector));
ok('خلاصه با تغییر کاتالوگ باطل می‌شود', /Catalog_Summary::invalidate\(\)/.test(scanner));
ok('خلاصه فقط با مهر زمانی معتبر همان کاتالوگ پذیرفته می‌شود', /catalog_mtime/.test(summary) && /function valid/.test(summary));
ok('اعداد زندهٔ خزانه و آمار عمر روی خلاصه سوار می‌شوند', /cached_ov\['vault'\]/.test(inspector) && /lifetime_stats/.test(inspector));
ok('ساخت صف فشرده‌سازی از فهرست سبک استفاده می‌کند', /Catalog_Summary::rows\(\)/.test(ajax));
ok('اگر فهرست سبک نبود، مسیر قبلی باقی می‌ماند', /else \{[\s\S]{0,400}query_items/.test(ajax));

console.log('── A3) فهرست نسخه‌های موازی ──');
ok('کلاس فهرست نسخه‌های موازی وجود دارد و ثبت شده', /class TisaCase_Optimizer_Sidecar_Index/.test(sidecar) && /Sidecar_Index'\s+=> 'class-tco-sidecar-index\.php'/.test(plugin));
ok('تحویل <picture> از فهرست استفاده می‌کند (نه is_file برای هر تصویر)', /Sidecar_Index::has\( \$rel, 'webp' \)/.test(delivery) && !/is_file\( \$abs \. '\.webp' \)/.test(delivery));
ok('در نبود فهرست، همان is_file قبلی با حافظهٔ موقت اجرا می‌شود', /probe\[/.test(sidecar));
ok('فهرست پس از اسکن کامل بازسازی می‌شود', /Sidecar_Index::rebuild\(\)/.test(scanner));
ok('فهرست پس از هر عملیات دسته‌ای برای همان تصاویر اصلاح می‌شود', /Sidecar_Index::refresh_for_ids/.test(job));
ok('فایل فهرست با پاک‌سازی کش حذف می‌شود', /Sidecar_Index::FILE/.test(scanner));

console.log('── A5) ریزبهینه‌سازی نقاط داغ ──');
ok('تنظیمات در هر درخواست یک‌بار خوانده می‌شود', /cache_settings/.test(settings) && /defaults\(\)/.test(settings));
ok('کش تنظیمات با هر ذخیره باطل می‌شود', /cache_settings = null/.test(settings) && /function flush_cache/.test(settings));
ok('فهرست پین‌ها در هر درخواست یک‌بار خوانده می‌شود', /cache_pinned/.test(settings));
ok('پین‌ها به نقشه تبدیل می‌شوند (بدون in_array در حلقه)', /array_fill_keys\( TisaCase_Optimizer_Settings::pinned_ids\(\), true \)/.test(usage));
ok('خلاصهٔ خزانه کش می‌شود و با هر تغییر باطل می‌گردد', /function summary[\s\S]{0,400}\$cache_rev/.test(vault) && /invalidate_summary\(\)/.test(vault));
ok('فهرست فایل‌های اصلی دوباره پیمایش نمی‌شود', /known_files/.test(read('includes/class-tco-image-processor.php')));

console.log('── B1) موتور «قدم بعدی» ──');
ok('سرور فهرست اقدام‌ها را می‌سازد', /function build_next_actions/.test(inspector));
ok('نمای کلی همیشه next_actions دارد (چه کش چه تازه)', (inspector.match(/\['next_actions'\] = self::build_next_actions/g) || []).length >= 2);
ok('رابط کارت «قدم بعدی» را نشان می‌دهد', /renderNextActions/.test(js) && /data-next-action/.test(js));
ok('دکمه‌های کارت به اقدام واقعی وصل‌اند', /data-next-action\]/.test(js) && /cta === 'optimize_all'/.test(js));
ok('دکمهٔ اقدام در فهرست کنترل‌های بازخورد ثبت شده', /'\[data-next-action\]'/.test(js));
ok('CSS کارت «قدم بعدی» وجود دارد', /\.tco-next/.test(css));
ok('پیش‌نمایش هم همان فهرست را می‌سازد', /buildNextActions/.test(mock));

console.log('── B2) توصیهٔ هوشمند زیرسایز ──');
ok('سرور برای هر سایز توصیه می‌سازد', /advice_text/.test(subsizes) && /reclaimable/.test(subsizes));
ok('توصیه بر «ضروری/به‌جامانده/سنگین» استوار است', /essential/.test(subsizes) && /is_orphan/.test(subsizes) && /avg > 200 \* 1024/.test(subsizes));
ok('رابط ستون توصیه را نشان می‌دهد', /tco-advice/.test(js) && /advice_text/.test(js));
ok('CSS ستون توصیه وجود دارد', /\.tco-advice/.test(css));

console.log('── B6) پاک‌سازی کش/CDN ──');
ok('کلاس پاک‌سازی کش وجود دارد و ثبت شده', /class TisaCase_Optimizer_Cache_Purger/.test(purger) && /Cache_Purger'\s+=>/.test(plugin));
ok('پاک‌سازی فقط پس از تغییر واقعی فایل‌ها اجرا می‌شود', /'remove' !== \$op && \(int\) \$job\['done'\] > 0/.test(job));
ok('سرویس‌های رایج پوشش داده شده‌اند', /rocket_clean_files/.test(purger) && /litespeed_purge_all/.test(purger) && /w3tc_flush_all/.test(purger));
ok('قلاب عمومی برای هماهنگی افزونه‌های دیگر دارد', /tisacase_optimizer_after_images_changed/.test(purger));

console.log('── B7) چکاپ پیش از عملیات ──');
ok('کلاس چکاپ وجود دارد و ثبت شده', /class TisaCase_Optimizer_Preflight/.test(preflight) && /Preflight'\s+=>/.test(plugin));
ok('عملیات preflight در ایجکس ثبت شده', /case 'preflight':/.test(ajax));
ok('چکاپ امتیاز و سطح می‌دهد', /'score'/.test(preflight) && /'level'/.test(preflight));
ok('رابط کارت چکاپ و دکمهٔ اجرا دارد', /renderPreflightCard/.test(js) && /tco-preflight-btn/.test(js));
ok('دکمهٔ چکاپ در فهرست کنترل‌ها ثبت شده', /'#tco-preflight-btn'/.test(js));
ok('برچسب فارسی عملیات چکاپ وجود دارد', /preflight: '/.test(js));
ok('پیش‌نمایش پاسخ چکاپ را شبیه‌سازی می‌کند', /op === 'preflight'/.test(mock));

console.log('');
if (fails.length) {
	console.error(`✗ ${fails.length} نقض در گارد هوشمندی:`);
	for (const f of fails) console.error('   • ' + f);
	process.exit(1);
}
console.log(`✓ هر ${pass} سنجهٔ «هوشمندی و بهینه‌بودن» سالم است.`);
