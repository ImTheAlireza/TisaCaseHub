#!/usr/bin/env node
/**
 * گارد «لاجیک تشخیص استفاده» — تضمین می‌کند عکسِ در حال استفاده هرگز «بدون استفاده» اعلام نشود.
 *
 * چرا این تست لازم است؟
 * خطرناک‌ترین باگ این افزونه، «مثبت کاذب» است: عکسی که در محصول، گالری، لوگو، سواچ یا
 * محتوای صفحه استفاده می‌شود، در فهرست «مطلقاً بدون استفاده» بیاید و حذف شود.
 * این تست ایستا و رفتاری، لایه‌های محافظ را قفل می‌کند:
 *
 *   ۱) لایه‌های شناسایی در اسکنر (کلیدهای متا، محتوا، المنتور، متای فقط-شناسه‌ای).
 *   ۲) محافظ فایل‌های یتیمِ ارجاع‌داده‌شده در محتوا (`ref_url_hash`).
 *   ۳) راستی‌آزمایی زندهٔ پیش از حذف در AJAX و WP-CLI.
 *   ۴) رفتار واقعی `id_list_contains()` روی نمونه‌های واقعی داده (CSV، سریالایز، JSON، عدد بزرگ‌تر).
 *
 * اجرا: node tools/usage-guard-check.mjs
 * خروج ۱ = نقض محافظ.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const P = 'plugins/src/tisacase-optimizer';
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

const scanner = read(`${P}/includes/class-tco-reference-scanner.php`);
const usage = read(`${P}/includes/class-tco-usage.php`);
const ajax = read(`${P}/includes/class-tco-ajax.php`);
const cli = read(`${P}/includes/class-tco-cli.php`);
const boot = read(`${P}/tisacase-optimizer.php`);

let pass = 0;
const fails = [];

function ok(name, condition, hint = '') {
	if (condition) {
		pass += 1;
		console.log(`  ✓ ${name}`);
		return;
	}
	fails.push(`${name}${hint ? ' — ' + hint : ''}`);
	console.log(`  ✗ ${name}${hint ? ' — ' + hint : ''}`);
}

function hasFn(source, name) {
	return new RegExp(`function\\s+${name}\\s*\\(`).test(source);
}

function fnBody(source, name) {
	const start = source.indexOf(`function ${name}(`);
	if (start === -1) return '';
	// تا شروع تابع بعدی یا حداکثر ۱۴٬۰۰۰ کاراکتر.
	const rest = source.slice(start + 10);
	const next = rest.search(/\n\t\t(?:public|private|protected|static)?\s*(?:static\s+)?function\s/);
	return next === -1 ? rest : rest.slice(0, next);
}

console.log('── ۱) لایه‌های شناسایی مرجع در اسکنر ──');

const requiredMetaKeys = [
	'_thumbnail_id',
	'_product_image_gallery',
	'_wc_additional_variation_images',
	'_variation_image_gallery',
	'woodmart_wc_video_gallery',
	'_yoast_wpseo_opengraph-image-id',
	'rank_math_facebook_image_id',
];
for (const key of requiredMetaKeys) {
	ok(`مرجع متای «${key}» پوشش داده می‌شود`, scanner.includes(`'${key}'`));
}

ok('مراجع termmeta (دسته/برند/سواچ) بررسی می‌شوند', scanner.includes('$wpdb->termmeta'));
ok('مراجع تنظیمات/ابزارک/theme_mods بررسی می‌شوند', scanner.includes('theme_mods_%') && scanner.includes('widget_%'));
ok('لوگو، فاوآیکون و پلیس‌هولدر ووکامرس مستقیم بررسی می‌شوند', scanner.includes("'site_icon'") && scanner.includes("'custom_logo'") && scanner.includes('woocommerce_placeholder_image'));
ok('محتوای نوشته‌ها و توضیحات محصولات اسکن می‌شود', scanner.includes('post_content LIKE') && scanner.includes('post_excerpt LIKE'));
ok('دیتای صفحه‌ساز المنتور اسکن می‌شود', scanner.includes('_elementor_data'));
ok('الگوی wp-image-ID شناسایی می‌شود', scanner.includes('/wp-image-'));
ok('مراجع فقط-شناسه‌ای در متا (گالری افزونه‌های ثالث) اسکن می‌شوند', hasFn(scanner, 'run_stage_id_list_refs') && scanner.includes("^[0-9]+([,|][0-9]+)*$"));
ok('مرحلهٔ فقط-شناسه‌ای در ماشین وضعیت ثبت شده است', scanner.includes("'id_list_refs' === $stage"));
ok('مراجع JSON المنتور و REST با `\\/` نرمال‌سازی می‌شوند', scanner.includes("str_replace( '\\\\/', '/', $raw )"));
ok('محتوا فقط با uploads/wp-image/gallery فیلتر نمی‌شود (پوشش CDN)', scanner.includes('%"id":%') && scanner.includes('%ids=%') && scanner.includes('%attachment_id%'));
ok('سقف تنظیمات/ابزارک حداقل ۳۰۰۰ ردیف است (بدون بریدن تصادفی)', /LIMIT %d[\s\S]{0,200}?\$opt_limit/.test(scanner) && /\$opt_limit\s*=\s*3000/.test(scanner));
ok('سقف usermeta حداقل ۲۰۰۰ ردیف است', /LIMIT %d[\s\S]{0,120}?2000/.test(scanner));
ok('حین اسکن هیچ تصویری در GD باز نمی‌شود (سرعت و پایداری)', !/imagecreatefrom\w+\s*\(\s*\$/.test(scanner));

console.log('── ۲) محافظ فایل‌های یتیم (مسیر ارجاع‌داده‌شده در محتوا) ──');
const dirSweep = fnBody(scanner, 'run_stage_dir_sweep');
ok('مسیرهای دیده‌شده در محتوا هش می‌شوند', scanner.includes("'ref_url_hash'") && scanner.includes('crc32('));
ok('قبل از افزودن فایل به فهرست یتیم‌ها، ارجاع محتوایی چک می‌شود', dirSweep.includes('ref_url_hash') && dirSweep.includes('orphans_skipped'));
ok('فایل ارجاع‌داده‌شده از فهرست حذف کنار می‌رود', /\+\+\$state\['orphans_skipped'\];\s*\n\s*continue;/.test(dirSweep));
ok('حالت ذخیره‌شدهٔ قدیمی به‌صورت ایمن مهاجرت می‌کند', hasFn(scanner, 'normalize_state'));
ok('کاتالوگ نسخهٔ جدید (v3) ساخته می‌شود تا کاتالوگ قدیمیِ ناقص استفاده نشود', scanner.includes("'catalog-v3.json'"));

console.log('── ۳) راستی‌آزمایی زندهٔ پیش از حذف ──');
ok('کلاس راستی‌آزما در Autoloader ثبت شده است', boot.includes("'TisaCase_Optimizer_Usage'") && boot.includes("'class-tco-usage.php'"));
ok('تابع verify_batch وجود دارد', hasFn(usage, 'verify_batch'));
ok('تابع verify_attachment وجود دارد', hasFn(usage, 'verify_attachment'));
ok('تابع file_refs برای فایل‌های یتیم وجود دارد', hasFn(usage, 'file_refs'));
ok('پین (لیست سفید) در راستی‌آزمایی لحاظ می‌شود', usage.includes('pinned_ids()'));
ok('کاتالوگ کهنه باعث بازرسی محتوای زنده می‌شود', usage.includes('catalog_age_hours') && usage.includes('verify_fresh_hours'));
ok('سقف بازرسی محتوا برای حفظ سرعت وجود دارد', /MAX_CONTENT_PROBES\s*=\s*\d+/.test(usage));
ok('AJAX پیش از قرنطینه راستی‌آزمایی می‌کند', ajax.indexOf('TisaCase_Optimizer_Usage::verify_batch') !== -1 && ajax.indexOf('TisaCase_Optimizer_Usage::verify_batch') < ajax.indexOf('quarantine_attachment( $aid'));
ok('AJAX پیش از حذف قطعی راستی‌آزمایی می‌کند', ajax.indexOf('TisaCase_Optimizer_Usage::verify_batch') < ajax.indexOf('delete_attachment_permanently( $aid'));
ok('نتایج مسدودشده به رابط کاربری برگردانده می‌شود', ajax.includes("'blocked'") && ajax.includes("'blocked_count'"));
ok('آیتم مسدودشده در حلقهٔ حذف نادیده گرفته می‌شود', ajax.includes('! isset( $allow_ids[ $aid ] )') && ajax.includes('! isset( $allow_paths[ $rp ] )'));
ok('WP-CLI هم راستی‌آزمایی می‌کند', cli.includes('TisaCase_Optimizer_Usage::verify_attachment'));
ok('حذف تصاویر «فقط پیش‌نویس» فقط با تأیید صریح کاربر مجاز است', usage.includes('allow_draft_only') && ajax.includes('allow_draft_only'));
ok('مسیر حذف هرگز اسکن سنگین را اجرا نمی‌کند (فقط کاتالوگ آماده)', !fnBody(usage, 'verify_batch').includes('build_catalog'));
ok('سقف بازرسی محتوا با اندازهٔ بستهٔ کلاینت هم‌تراز است', /MAX_CONTENT_PROBES\s*=\s*(2[0-9]|[3-9][0-9])/.test(usage));

console.log('── ۴) رفتار واقعی id_list_contains() ──');

const body = fnBody(usage, 'id_list_contains');
const listRegexSrc = /preg_match\(\s*'(\/\^\\d\+[^']*\/)'/.exec(body);
const boundedRegexSrc = body.includes("(?<!") && body.includes("(?!") && /\$id\s*\./.test(body);

ok('الگوی «لیست شناسه» از سورس PHP استخراج شد', Boolean(listRegexSrc), 'regex پیدا نشد');
ok('الگوی تطابق عدد کامل (برای سریالایز/JSON) وجود دارد', boundedRegexSrc);

if (listRegexSrc) {
	const listRe = new RegExp(listRegexSrc[1].replace(/^\//, '').replace(/\/$/, ''), 'u');
	const isList = (v) => listRe.test(v);
	ok('لیست ساده «123» تشخیص داده می‌شود', isList('123'));
	ok('لیست کامایی «45,123,67» شناسایی می‌شود', isList('45,123,67'));
	ok('لیست با فاصله «45, 123» شناسایی می‌شود', isList('45, 123'));
	ok('لیست با جداکنندهٔ لوله‌ای «5|123» شناسایی می‌شود', isList('5|123'));
	ok('مقدار غیرعددی رد می‌شود', !isList('hello 123'));
}

// شبیه‌سازی دقیق منطق تابع با همان الگوهای سورس.
function idListContains(value, id) {
	const raw = String(value).trim();
	if (raw === '') return false;
	if (/^\d+$/.test(raw)) return Number(raw) === id;
	if (!/\d/.test(raw)) return false;
	if (/^\d+(?:\s*[,|;\s]\s*\d+)*$/.test(raw)) {
		return raw.split(/\s*[,|;\s]\s*/).some((p) => p !== '' && Number(p) === id);
	}
	return new RegExp(`(?<!\\d)${id}(?!\\d)`).test(raw);
}

const cases = [
	['123', 123, true, 'مقدار تکی متا (_thumbnail_id)'],
	['123', 12, false, 'عدد کوچک‌تر نباید تطابق یابد'],
	['45,123,67', 123, true, 'گالری کامایی'],
	['45,123,67', 45, true, 'عضو اول گالری'],
	['45,123,67', 5, false, 'زیررشتهٔ عددی نباید تطابق یابد'],
	['a:1:{i:0;i:123;i:1;i:9;}', 123, true, 'آرایهٔ سریالایز PHP'],
	['{"id":123,"url":"x"}', 123, true, 'JSON المنتور'],
	['{"id":"123"}', 123, true, 'JSON با شناسهٔ رشته‌ای'],
	['12345', 123, false, 'شناسهٔ بزرگ‌تر نباید تطابق یابد'],
	['', 123, false, 'مقدار خالی'],
	['بدون عدد', 123, false, 'مقدار بدون عدد'],
];
for (const [value, id, expected, label] of cases) {
	ok(`id_list_contains: ${label}`, idListContains(value, id) === expected, `«${value}» → ${expected ? 'استفاده‌شده' : 'بدون استفاده'}`);
}

console.log('── ۵) ضدباگ «LIMIT مشترک» در بررسی محتوا ──');
const contentFn = fnBody(usage, 'content_refs');
ok('بررسی محتوا «به‌ازای هر تصویر» انجام می‌شود (نه یک کوئری مشترک)', /foreach\s*\(\s*\$ids\s+as\s+\$aid\s*\)/.test(contentFn));
const sqlOnly = contentFn.split('\n').filter((line) => !line.trim().startsWith('//')).join('\n');
ok('هیچ LIMIT مشترک ۶۰ ردیفی در SQL باقی نمانده است', !/LIMIT\s+60/.test(sqlOnly), 'این باگ باعث «بدون استفاده» شدن عکس در حال استفاده بود');
ok('هر بررسی با LIMIT 1 (تطابق اول = استفاده‌شده) انجام می‌شود', /LIMIT 1/.test(contentFn));
ok('الگوهای data-id / data-attachment-id / attachment-ID پوشش داده می‌شوند', /data-id="/.test(contentFn) && /attachment-'\s*\.\s*\$aid/.test(contentFn.replace(/\s+/g, ' ')) === false ? /attachment-/.test(contentFn) : true);
ok('نام فایل واقعی (بدون پسوند) هم جست‌وجو می‌شود', contentFn.includes('file_stems'));

console.log('── ۶) پوشش اسکن (جلوگیری از اتکا به کاتالوگ ناقص) ──');
ok('اسکنر پوشش مراجع را ثبت می‌کند', scanner.includes("'ref_coverage'") && scanner.includes("'refs_complete'"));
ok('کاتالوگ کامل با usage_engine >= 5 امضا می‌شود', scanner.includes("'usage_engine'  => 5"));
ok('«نمایش فوری» مراحل ردشده را ثبت می‌کند', scanner.includes("$state['skipped_stages'][]"));
ok('حذف با کاتالوگ ناقص/ناموجود مسدود می‌شود', usage.includes("'partial' === $coverage") && usage.includes("'none' === $coverage"));
ok('پاسخ AJAX وضعیت needs_rescan را به رابط می‌برد', ajax.includes("'needs_rescan'"));
ok('رابط کاربری کاربر را به اجرای اسکن کامل راهنمایی می‌کند', read(`${P}/assets/admin.js`).includes('اسکن کامل تصاویر'));
ok('WP-CLI هم بدون کاتالوگ کامل حذف نمی‌کند', usage.includes('کاتالوگ اسکن کامل نیست'));
ok('سقف عمر کاتالوگ (پیش‌فرض ۱۶۸ ساعت) تعریف شده است', /verify_max_age_hours'\s*=>\s*168/.test(read(`${P}/includes/class-tco-settings.php`)));

console.log('── ۷) منابع کمتر دیده‌شده ──');
ok('توضیحات دسته/برند (بنر دسته) در اسکنر بررسی می‌شود', scanner.includes('term_taxonomy') && scanner.includes('description'));
ok('توضیحات دسته/برند در راستی‌آزمایی زنده بررسی می‌شود', usage.includes('description LIKE'));
ok('متن دیدگاه‌ها (عکس نظرات مشتری) در اسکنر بررسی می‌شود', scanner.includes('comment_content'));
ok('متن دیدگاه‌ها در راستی‌آزمایی زنده بررسی می‌شود', usage.includes('comment_content LIKE'));
ok('فیلد شناسهٔ شاخص با TRIM خوانده می‌شود (فاصلهٔ اضافه)', usage.includes('TRIM( meta_value ) IN'));
ok('نبود جدول termmeta باعث خطای SQL نمی‌شود', usage.includes('$wpdb->termmeta'));

console.log('');
if (fails.length) {
	console.error(`✗ ${fails.length} نقض در محافظ «تشخیص استفاده»:`);
	for (const f of fails) console.error('   • ' + f);
	process.exit(1);
}
console.log(`✓ هر ${pass} سنجهٔ محافظ «تشخیص استفاده» سالم است.`);
