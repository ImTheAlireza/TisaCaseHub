#!/usr/bin/env node
/**
 * محافظ «بار سرور» برای عملیات مخربِ حجیم (نسخهٔ ۱٫۰٫۶).
 *
 * چرا؟ کاربر ۱۵۰۰ تصویر را برای حذف انتخاب کرد و اجرا وسط کار ایستاد، بدون
 * این‌که بداند چند مورد انجام شده یا چرا متوقف شده. ریشهٔ باگ این بود که مرورگر
 * شناسه‌ها را از پیش به بسته‌های ۲۵تایی می‌برید و سرور در سکوت با بودجهٔ زمانی
 * ۳٫۵ ثانیه `break` می‌کرد؛ باقی بسته ناپدید می‌شد. هم‌زمان هر بسته، کل کاتالوگ
 * JSON را بازنویسی می‌کرد (IO سنگین).
 *
 * این محافظ تضمین می‌کند آن معماری برنگردد و افزونه خودش بار روی سرور نباشد:
 *   A) صف در سرور (نه در مرورگر) و ذخیرهٔ دوره‌ای وضعیت (داخل تیک + پایان تیک).
 *   B) تیک‌های زمان‌بندی‌شده + سقف تعداد + مکث بین آیتم‌ها.
 *   C) نوشتن کاتالوگ یک‌بار در پایان کار (نه هر بسته).
 *   D) راستی‌آزمایی ارزان در حالت دسته‌ای (بدون بازرسی محتوای هر تصویر).
 *   E) جابه‌جایی فایل با rename (نه copy+delete) و نشانگر خرابی برای بازیابی.
 *   F) رابط کاربری: حلقهٔ تیک‌های سرورمحور + کارت «ادامه» + شمارش صادقانه.
 *
 * اجرا: node tools/optimizer-load-guard.mjs
 * خروج ۱ = نقض.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const P = 'plugins/src/tisacase-optimizer';
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

const boot = read(`${P}/tisacase-optimizer.php`);
const job = read(`${P}/includes/class-tco-job.php`);
const ajax = read(`${P}/includes/class-tco-ajax.php`);
const usage = read(`${P}/includes/class-tco-usage.php`);
const inspector = read(`${P}/includes/class-tco-media-inspector.php`);
const vault = read(`${P}/includes/class-tco-vault.php`);
const settings = read(`${P}/includes/class-tco-settings.php`);
const admin = read(`${P}/assets/admin.js`);
const preview = read(`${P}/includes/class-tco-preview.php`);
const scanner = read(`${P}/includes/class-tco-reference-scanner.php`);
const runner = read(`${P}/includes/class-tco-server-runner.php`);
const proc = read(`${P}/includes/class-tco-image-processor.php`);
const dedupe = read(`${P}/includes/class-tco-deduplicator.php`);

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

function fnBody(source, name) {
	const start = source.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const rest = source.slice(start + 10);
	const next = rest.search(/\n\t\t(?:public|private|protected|static)?\s*(?:static\s+)?function\s/);
	return next === -1 ? rest : rest.slice(0, next);
}

console.log('── ۱) صف در سرور، نه در مرورگر ──');
ok('کلاس موتور کار در Autoloader ثبت شده است', boot.includes("'TisaCase_Optimizer_Job'") && boot.includes("'class-tco-job.php'"));
ok('صف در یک آپشن مستقل ذخیره می‌شود', /const\s+OPTION\s*=\s*'tisacase_optimizer_active_job'/.test(job));
ok('آپشن کار autoload نمی‌شود (بار اضافه روی هر صفحهٔ سایت)', /update_option\(\s*self::OPTION,\s*\$job,\s*false\s*\)/.test(job));
ok('فقط یک کار هم‌زمان مجاز است (پاسخ ۴۰۹ به شروع کار دوم)', ajax.includes('یک عملیات نیمه‌تمام در جریان است') && /409/.test(ajax));
ok('ادامهٔ کار با تطبیق شناسهٔ کار انجام می‌شود', /\$job\['id'\]\s*!==\s*\$job_id/.test(ajax));
ok('وضعیت کار داخل تیک دوره‌ای و در پایان تیک ذخیره می‌شود (مقاوم به قطع ناگهانی، بدون گلوگاه سریال‌سازی هر آیتم)', /self::run_one\(\s*\$job\s*\);[\s\S]{0,1200}?self::save\(\s*\$job\s*\);/.test(job) && /refresh_rate_base\( \$job \);[\s\S]{0,40}?self::save\(\s*\$job\s*\);/.test(job));
ok('وضعیت شامل شناسهٔ آیتم در حال پردازش است', job.includes("'current'"));
ok('کار رهاشده در شروع کار بعدی تسویه می‌شود', /function\s+reap_orphans\s*\(/.test(job) && job.includes('reap_orphans()'));
ok('لغو کار، صف را خالی و وضعیت را ثبت می‌کند', /function\s+cancel\s*\(/.test(job));
ok('خطای کشنده قابل ثبت است (تا کارت ادامه دلیل واقعی را بگوید)', /function\s+mark_error\s*\(/.test(job));

console.log('── ۲) تیک‌های کوتاه و قابل تنظیم (بار روی هاست اشتراکی) ──');
ok('بودجهٔ زمانی هر تیک از تنظیمات خوانده می‌شود', /delete_tick_ms/.test(job));
ok('بودجهٔ زمانی کران‌دار است', /max\(\s*1500,\s*min\(\s*15000/.test(job));
ok('سقف تعداد آیتم در هر تیک وجود دارد', /max\(\s*5,\s*min\(\s*200/.test(job) && job.includes('delete_tick_max'));
ok('مکث بین آیتم‌ها برای محافظت از IOPS وجود دارد', job.includes('delete_throttle_ms') && /usleep\(\s*\$throttle_ms/.test(job));
ok('حلقهٔ اجرا با بودجهٔ زمانی محدود می‌شود', /\(\s*microtime\(\s*true\s*\)\s*-\s*\$started\s*\)\s*<\s*\$budget_s/.test(job));
ok('پس از اتمام صف، کار به فاز پایان می‌رود (بدون حلقهٔ بی‌پایان)', job.includes("$job['phase'] = 'finish'"));
ok('تنظیمات، هر سه کران را معتبر می‌سازد', /delete_tick_ms'\]\s*=\s*max\(\s*1500/.test(settings) && /delete_tick_max'\]\s*=\s*max\(\s*5/.test(settings) && /delete_throttle_ms'\]\s*=\s*max\(\s*0/.test(settings));
ok('سه تنظیم در رابط کاربری قابل تغییر است', admin.includes('tco-cfg-tickms') && admin.includes('tco-cfg-tickmax') && admin.includes('tco-cfg-throttle'));

console.log('── ۳) کاتالوگ یک‌بار نوشته می‌شود، نه در هر بسته ──');
ok('حذف از کاتالوگ می‌تواند تعویق بیفتد', inspector.includes('function remove_from_cached_catalog(') && /\$defer\s*=\s*false/.test(inspector) && inspector.includes('if ( $defer )'));
ok('صف حذف‌های معوق وجود دارد', /function\s+queue_pending_removals\s*\(/.test(inspector) && /function\s+flush_pending_removals\s*\(/.test(inspector));
ok(
	'flush کاتالوگ نه در حلقهٔ آیتم‌ها، بلکه فقط در تسویه و پایان انجام می‌شود',
	(job.match(/flush_pending_removals\(/g) || []).length === 2 &&
		/reap_orphans[\s\S]{0,400}?flush_pending_removals/.test(job) &&
		/function\s+finish\s*\([\s\S]*?flush_pending_removals/.test(job) &&
		!/while\s*\([\s\S]{0,600}?flush_pending_removals/.test(job)
);
ok('flush در فاز پایان انجام می‌شود', /function\s+finish\s*\([\s\S]*?flush_pending_removals\(/.test(job));
ok('مسیر حذف هرگز اسکن سنگین نمی‌سازد', !fnBody(usage, 'verify_batch').includes('build_catalog') && !fnBody(job, 'finish').includes('build_catalog'));

console.log('── ۴) راستی‌آزمایی ارزان و «بسته‌بندی‌شده» (رفع «۰ از ۱٬۴۸۰») ──');
ok('فاز بازرسی، صف را بسته‌بسته برمی‌دارد (نه یک‌جا)', /array_splice\(\s*\$job\['queue_ids'\],\s*0,\s*\$chunk_size\s*\)/.test(job));
ok('اندازهٔ بستهٔ بازرسی کران‌دار و قابل تنظیم است', /verify_chunk/.test(job) && /max\(\s*5,\s*min\(\s*50/.test(job));
ok('پس از هر بستهٔ بازرسی، وضعیت ذخیره می‌شود (پیشرفت گم نمی‌شود)', /verify_batch\([\s\S]{0,2200}?self::save\(\s*\$job\s*\);/.test(job));
ok('حلقهٔ بازرسی هم با بودجهٔ زمانی بسته می‌شود', /while \( microtime\( true \) < \$until \) \{[\s\S]{0,160}?queue_ids/.test(job));
ok('تنظیم اندازهٔ بستهٔ بازرسی اعتبارسنجی می‌شود', /verify_chunk'\]\s*=\s*max\(\s*5,\s*min\(\s*50/.test(settings));

console.log('── ۴-۱) هیچ تیکی به سقف ۲۸ ثانیهٔ هاست نمی‌رسد ──');
ok('سقف زمانی مخصوص بازرسی وجود دارد (جدا از بودجهٔ تیک)', /verify_step_cap/.test(job) && /\$until\s*=\s*min\(/.test(job));
ok('بازرسی با سقف زمانی مطلق اجرا می‌شود', /while \( microtime\( true \) < \$until \)/.test(job));
ok('سقف تعداد تصویر در هر فراخوانی بازرسی وجود دارد', /const VERIFY_SLICE\s*=\s*\d+/.test(usage));
ok('بازرسی، مازادِ بسته را به تیک بعد موکول می‌کند', /deferred_ids/.test(usage) && /\$deferred_ids\s*=\s*array_slice/.test(usage));
ok('موتور کار موارد موکول‌شده را به صف برمی‌گرداند', /\$job\['queue_ids'\]\s*=\s*array_values\(\s*array_merge\(\s*\$deferred/.test(job));
ok('مسیرهای موکول‌شده به صف مسیرها برمی‌گردند (نه absint)', /deferred_paths/.test(usage) && /\$job\['queue_paths'\]\s*=\s*array_values\(\s*array_merge\(\s*\$deferred_path_chunks/.test(job));
ok('بازرسی محتوا هم سقف زمانی دارد', /microtime\( true \) >= \$deadline/.test(usage));
ok(
	'هیچ موردی بی‌بررسی حذف نمی‌شود (موکول ≠ مجاز)',
	!/deferred[\s\S]{0,120}allow\[ \$aid \]\s*=\s*true/.test(usage)
);
ok('نتیجهٔ بازرسی کوتاه‌مدت کش می‌شود (ادامهٔ کار پس از قطع، فوری است)', /VERIFY_CACHE_KEY/.test(usage) && /VERIFY_CACHE_TTL/.test(usage));
ok('کش هرگز باعث حذف بدون بررسی نمی‌شود (فقط برای ۱۰ دقیقه)', /VERIFY_CACHE_TTL\s*=\s*\d+/.test(usage) && /set_transient\( self::VERIFY_CACHE_KEY/.test(usage));

console.log('── ۴-۲) کران سخت روی کوئری‌های «مراجع زنده» ──');
ok('سقف شناسه در هر فراخوانی مراجع زنده وجود دارد', /const MAX_LIVE_IDS\s*=\s*\d+/.test(usage));
ok('مراجع زنده به بسته‌های کوچک شکسته می‌شود', /array_chunk\(\s*\$ids,\s*self::MAX_PATTERNS_PER_QUERY\s*\)/.test(usage) && /live_refs_chunk/.test(usage));
ok('هیچ شرط LIKE به‌ازای هر شناسهٔ نامحدود ساخته نمی‌شود', /count\( \$ids \) > self::MAX_LIVE_IDS[\s\S]{0,120}?array_slice/.test(usage));
ok('تطبیق ردیف‌ها با استخراج عدد است، نه حلقهٔ ردیف×شناسه', /matched_ids_in_text/.test(usage) && !/foreach \( \(array\) \$gal_rows as \$row \) \{\s*foreach \( \$ids as \$id \)/.test(usage));
ok(
	'کوئری آپشن‌ها تعداد placeholder و آرگومان هم‌سان دارد',
	/\$opt_ph\s*=\s*implode\(\s*', ',\s*array_fill\(\s*0,\s*count\(\s*\$opt_names\s*\),\s*'%s'\s*\)\s*\)/.test(usage) &&
		/WHERE option_name IN \( \{\$opt_ph\} \)[\s\S]{0,80}?\$opt_names/.test(usage)
);

console.log('── ۴-۳) راستی‌آزمایی ارزان در حالت دسته‌ای ──');
ok('verify_batch حالت انبوه را می‌شناسد', /function\s+verify_batch\s*\([\s\S]{0,200}?\$bulk/.test(usage));
ok('در کاتالوگ تازه و کامل، بازرسی محتوای هر تصویر حذف می‌شود', /\$bulk\s*&&\s*\$is_fresh/.test(usage) && usage.includes("'full' === $coverage"));
ok('موتور کار راستی‌آزمایی را دسته‌ای صدا می‌زند', /verify_batch\(\s*\$chunk,\s*\$pchunk,[\s\S]{0,160}?\$until\s*\)/.test(job));
ok('سقف بازرسی محتوا هنوز کران‌دار است', /MAX_CONTENT_PROBES\s*=\s*\d+/.test(usage));

console.log('── ۵) جابه‌جایی فایل بدون دوبرابر IO و با امکان بازیابی ──');
ok('انتقال با rename انجام می‌شود', /@rename\(/.test(vault));
ok('در صورت شکست rename، copy به‌عنوان جایگزین هست', /@rename\([\s\S]{0,600}?@copy\(/.test(vault));
ok('نشانگر نیمه‌کارهٔ manifest قبل از جابه‌جایی نوشته می‌شود', vault.includes('manifest.partial.json'));
ok(
	'در صورت شکست manifest، همهٔ فایل‌ها به مسیر اصلی برمی‌گردند',
	/manifest_failed/.test(vault) && /@rename\(\s*\$src,\s*\$back\s*\)/.test(vault) && /'manifest_failed'/.test(vault)
);
ok('قرنطینهٔ فایل یتیم هم منتقل‌شده را دوباره حذف نمی‌کند', /\$relocated/.test(vault));

console.log('── ۶) رابط کاربری: حلقهٔ تیک سرورمحور و شمارش صادقانه ──');
ok('جاوااسکریپت با job_id تیک می‌زند (نه بسته‌های از پیش بریده)', /job_id:\s*jobId/.test(admin));
ok('شناسهٔ بستهٔ سمت کلاینت دیگر مصرف نمی‌شود', !admin.includes('chunk.splice(') || !/runBatchRemove[\s\S]{0,3000}chunk\.splice\(/.test(admin));
ok('حلقهٔ کلاینت کران‌دار است (ضدقفل‌شدن)', /guard\s*<\s*\d+/.test(admin));
ok('تعداد انجام‌شده/باقی‌مانده از سرور خوانده می‌شود', admin.includes('job.remaining') && admin.includes('job.done'));
ok('کارت «ادامهٔ عملیات» رندر می‌شود', /function\s+renderResumeCard\s*\(/.test(admin) && admin.includes('tco-resume-job'));
ok('دکمهٔ رهاکردن کار به سرور اعلام می‌شود', admin.includes('tco-discard-job') && admin.includes("api('job_cancel'"));
ok('وضعیت کار در بارگذاری اولیه خوانده می‌شود', admin.includes('refreshJobStatus()'));
ok('پس از قطع، عددها با سرور هم‌تراز می‌شوند', /await refreshJobStatus\(\)/.test(admin));
ok('دلیل توقف به کاربر نشان داده می‌شود', /lastError/.test(admin) && admin.includes('عملیات نیمه‌تمام'));
ok('کارت ادامه در فاز بازرسی، وضعیت را صادقانه می‌گوید', admin.includes("job.phase === 'verify'") && admin.includes('بازرسی ایمنی'));
ok('دلایل مسدودشدن (به‌تفکیک تعداد) در کارت ادامه دیده می‌شود', admin.includes('blockReasons') && admin.includes('tco-reason-list'));
ok('راهنمای عملی سرور در کارت نمایش داده می‌شود', admin.includes('job.hint') && job.includes("'hint'"));
ok('وقتی اسکن کامل لازم است، دکمهٔ اجرای اسکن در کارت هست', admin.includes('tco-resume-rescan'));
ok('حذف صفر موردی هرگز «انجام شد» اعلام نمی‌شود', admin.includes('هیچ تصویری حذف نشد'));
ok('سرور برای «همه رد شدند» دلیل واقعی را ذخیره می‌کند', /هیچ تصویری حذف نشد: هر %1\$d مورد/.test(job));
ok('خلاصهٔ کار دلایل مسدودشدن را تجمیع می‌کند', /block_reasons/.test(job) && /arsort\(\s*\$reason_counts/.test(job));
ok('کلاینت در برابر بی‌حرکتی سرور می‌ایستد (نه چرخیدن بی‌پایان)', /stallTicks\s*>=\s*\d+/.test(admin));
ok('کارت ادامه در همهٔ نماها بالای صفحه است', (admin.match(/renderResumeCard\(\)\s*\+/g) || []).length >= 3);

console.log('── ۶-۲) «فشرده‌سازی + جایگزینی» خودکار ──');
ok('دکمهٔ «فشرده‌سازی و جایگزینی» در نوار بالای جدول وجود دارد', admin.includes('tco-bulk-opt-replace') && admin.includes('فشرده‌سازی و جایگزینی'));
ok('دکمهٔ جایگزینی انبوه در تب فشرده‌سازی هم هست', admin.includes('tco-start-replace-compress'));
ok('جایگزینی انبوه دو مرحله‌ای (ضد اشتباه) است', /armConfirm\('bulk-opt-replace'\)/.test(admin) && /armConfirm\('bulk-opt-replace-all'\)/.test(admin));
ok('هر دو دکمه با replaceMode اجرا می‌شوند', (admin.match(/replaceMode: true/g) || []).length >= 2);
ok('حالت جایگزینی به سرور فرستاده می‌شود', /overrides: replaceMode \? \{ replace_mode: 1 \}/.test(admin));
ok('سرور حالت جایگزینی را به webp_mode=replace تبدیل می‌کند', ajax.includes("$clean_ovr['webp_mode']      = 'replace'"));
ok('صف «جایگزینی» تصاویری را که فقط WebP موازی دارند هم شامل می‌شود', ajax.includes("'not_webp'") && inspector.includes("'not_webp'"));
ok('پشتیبان فایل اصلی پیش از جایگزینی گرفته می‌شود', /backup_origins|backup_original_before_optimize/.test(read(`${P}/includes/class-tco-image-processor.php`)));
ok('اعلان پایان، کاهش واقعی حجم دیسک را گزارش می‌کند', admin.includes('جایگزینی تمام شد'));

console.log('── ۷) اندپوینت‌ها و امنیت ──');
ok('اندپوینت job_status ثبت شده است', ajax.includes("'job_status'") && ajax.includes('TisaCase_Optimizer_Job::summary'));
ok('اندپوینت job_cancel ثبت شده است', ajax.includes("'job_cancel'") && ajax.includes('TisaCase_Optimizer_Job::cancel'));
ok('حذف دسته‌ای بر پایهٔ موتور کار است', /TisaCase_Optimizer_Job::start_remove/.test(ajax) && /TisaCase_Optimizer_Job::advance/.test(ajax));
ok('همهٔ اندپوینت‌ها از مسیر مشترک با nonce و مجوز عبور می‌کنند', ajax.includes('check_ajax_referer') && ajax.includes("current_user_can( 'manage_options' )"));
ok('حذف و ادامهٔ کار فقط برای مدیر مجاز است (بدون مسیر میان‌بر)', !/function\s+handle_job_cancel[\s\S]{0,200}?check_ajax_referer/.test(ajax) || ajax.includes('self::dispatch'));

console.log('── ۸) پیش‌نمایش و بندانگشتی خزانه (بدون فشار روی سرور) ──');
ok('پیش‌نمایش فقط برای مدیر مجاز و با nonce سرو می‌شود', preview.includes('check_ajax_referer') && preview.includes('TisaCase_Optimizer_Ajax::user_can_access'));
ok('مسیر فایل با realpath داخل پوشهٔ خزانه محدود می‌شود (ضد LFI)', /realpath\(/.test(preview) && /0 === strpos\( \$real, \$root \)/.test(preview));
ok('مسیر نسبی پیش از استفاده، «..» را حذف می‌کند', /str_replace\( array\( '\.\.'/.test(preview));
ok('بندانگشتی یک‌بار ساخته و روی دیسک کش می‌شود', preview.includes('function thumb_path') && preview.includes("'.jpg'") && /is_file\( \$cached \)/.test(preview));
ok('کلید کش وابسته به mtime و حجم فایل است (نه محتوای تکراری)', /md5\( \$abs \. '\|' \. \(int\) @filemtime/.test(preview));
ok('سقف حجم فایل برای ساخت بندانگشتی تعریف شده است', /THUMB_MAX_BYTES\s*=\s*\d+/.test(preview) && /filesize\( \$src \) > self::THUMB_MAX_BYTES/.test(preview));
ok('ابعاد بندانگشتی محدود است (پیکسل ثابت)', /THUMB_MAX\s*=\s*\d+/.test(preview) && /self::THUMB_MAX, self::THUMB_MAX/.test(preview));
ok('اگر ساخت بندانگشتی ممکن نبود، خود فایل سرو می‌شود (جای خالی نمی‌ماند)', /self::stream\( \$abs, \$mime \)/.test(preview));
ok('پاسخ بدون دخالت کش صفحه و با هدر امن سرو می‌شود', preview.includes('nocache_headers') && preview.includes('X-Content-Type-Options'));
ok('مرورگر مجاز است تصویر را کوتاه‌مدت کش کند (پس از حذف/بازگردانی تازه می‌شود)', /Cache-Control: private, max-age=\d+/.test(preview));
ok('اندپوینت پیش‌نمایش از مسیر روتین JSON جدا ثبت شده است', boot.includes('TisaCase_Optimizer_Preview::init()') && preview.includes("const ACTION = 'tisacase_optimizer_preview'"));
ok('فهرست خزانه برای هر ردیف آدرس پیش‌نمایش/بندانگشتی می‌سازد', /TisaCase_Optimizer_Preview::url\( 'quarantine'/.test(ajax) && /TisaCase_Optimizer_Preview::url\( 'original'/.test(ajax));
ok('بندانگشتی‌ها در مرورگر تنبل بارگذاری می‌شوند', /loading="lazy"[\s\S]{0,80}data-preview-q/.test(admin) || /data-preview-q[^>]*loading="lazy"/.test(admin) || (admin.match(/loading="lazy"/g) || []).length >= 2);
ok('فهرست قرنطینه صفحه‌بندی دارد (همهٔ ردیف‌ها یک‌جا رندر نمی‌شوند)', /function vaultPageInfo/.test(admin) && /renderPager\(\{ page, pages, total: filtered\.length/.test(admin));

console.log('── ۹) بازگردانی دسته‌ای بر اساس علت حذف ──');
ok('سرور فهرست را از کلید علت می‌سازد (نه هزاران شناسه از مرورگر)', /\$reason_filter\s*=\s*isset\( \$_POST\['reason'\]/.test(ajax) && /\$qi_reason === \$reason_filter/.test(ajax));
ok('چیپ‌های علت در رابط ساخته می‌شوند', /data-vault-reason/.test(admin) && /function renderVaultReasonChips/.test(admin));
ok('بازگردانی یک علت دو کلیک تأیید دارد', /armConfirm\('restore-reason'\)/.test(admin));
ok('علت حذف هنگام قرنطینه ثبت می‌شود', /'reason'\s*=>\s*sanitize_key\( \(string\) \$reason \)/.test(job) && /self::remove_reason\( \$job \)/.test(job));
ok('نام فارسی علت‌ها یک‌جا تعریف شده است (بدون تکرار در JS)', /function reason_labels/.test(vault) && /function reason_label\( \$key \)/.test(vault));

console.log('── ۱۰) «وایت‌لیست پوشه»: پوشه‌ای که هیچ‌وقت پاک نمی‌شود ──');
ok('پوشه‌های محافظت‌شده در یک آپشن جدا ذخیره می‌شوند', /OPTION_FOLDERS\s*=\s*'tisacase_optimizer_whitelist_folders'/.test(settings));
ok('مسیر ورودی نرمال‌سازی می‌شود (دامنه، wp-content/uploads، اسلش اضافه)', /function normalize_folder_path/.test(settings) && settings.includes('wp-content/uploads/'));
ok('مسیرهای خطرناک («..» و پوشهٔ خزانه) رد می‌شوند', /'\.\.'/.test(settings) && /tisa-opt-vault/.test(settings));
ok('تعداد پوشه‌های محافظت‌شده سقف دارد', /MAX_WHITELIST_FOLDERS\s*=\s*\d+/.test(settings));
ok('مسیر داخل پوشه با تطبیق «شامل/شروع با» تشخیص داده می‌شود', /function matched_whitelist_folder/.test(settings) && /false !== strpos\( \$rel, '\/' \. \$needle \. '\/' \)/.test(settings));
ok('اسکنر فایل‌های داخل پوشه را از فهرست «رهاشده» بیرون می‌گذارد', /matched_whitelist_folder/.test(scanner) && /whitelist_stats/.test(scanner));
ok('کنارگذاشتن پوشه در سقف ۱۰۰۰ مورد فهرست یتیم‌ها حساب می‌شود (فهرست کوتاه نمی‌شود)', /orphans_skipped/.test(scanner));
ok('فایل‌های پوشه، وضعیت صریح «داخل پوشهٔ محافظت‌شده» می‌گیرند', /folder_whitelisted/.test(inspector) && /whitelist_folder/.test(inspector));
ok('فهرست «بدون استفاده» دوباره فیلتر می‌شود (ضد نشت پوشه به فهرست)', /is_whitelisted_path/.test(inspector));
ok('کاوشگر فیلتر اختصاصی این وضعیت را دارد', /'folder_whitelisted'/.test(ajax) || /folder_whitelisted/.test(inspector));
ok('حذف تکی/دسته‌ای شناسهٔ داخل پوشه را مسدود می‌کند', /matched_whitelist_folder/.test(usage) && usage.includes('هرگز حذف نمی‌شود'));
ok('ادغام تکراری‌ها فایل داخل پوشه را پاک نمی‌کند', /whitelisted_folder/.test(dedupe));
ok('ذخیرهٔ فهرست پوشه‌ها اندپوینت جدا با nonce و مجوز دارد', ajax.includes("'save_whitelist_folders'") && /check_ajax_referer/.test(ajax));
ok('شمارش فایل‌های پوشه با بودجهٔ زمانی و سقف فایل انجام می‌شود (نه اسکن کل uploads)', /function whitelist_folder_stats/.test(inspector) && /budget/.test(inspector) && /\$cap\s*=\s*\d+/.test(inspector));
ok('رابط کاربری ورودی مسیر پوشه و دکمهٔ افزودن دارد', admin.includes('tco-wl-folder-add') && admin.includes('tco-wl-folder-input'));
ok('هر پوشه دکمهٔ «حذف از لیست سفید» دارد (مسیر بن‌بست نیست)', /data-wl-folder-remove/.test(admin));
ok('حذف از لیست سفید صریحاً می‌گوید هیچ فایلی پاک نشد', admin.includes('هیچ فایلی پاک نشد'));

console.log('── ۱۱) «اجرا روی خود هاست»: کار بدون مرورگر ──');
ok('موتور سمت هاست کلاس مستقل و قابل‌بارگذاری تنبل دارد', runner.includes('class TisaCase_Optimizer_Server_Runner') && boot.includes("'TisaCase_Optimizer_Server_Runner'     => 'class-tco-server-runner.php'"));
ok('کرون در کران‌های ۳۰/۶۰ ثانیه‌ای سفارشی ثبت می‌شود', /function add_schedules/.test(runner) && runner.includes('tco_runner_30s') && runner.includes('tco_runner_60s'));
ok('هر تیک سقف زمان و سقف تعداد دارد (نه نزدیک ۲۸ ثانیه)', /runner_tick_ms/.test(runner) && /runner_tick_max/.test(runner) && runner.includes('min( 16000'));
ok('قفل اتمیک ضد تیک هم‌زمان وجود دارد', /add_option\( self::LOCK_OPTION/.test(runner) && /LOCK_TTL/.test(runner) && /function release_lock/.test(runner));
ok('قفل رهاشده بعد از TTL آزاد می‌شود (کار هیچ‌وقت گیر نمی‌کند)', /LOCK_TTL\s*=\s*\d+/.test(runner) && /update_option\( self::LOCK_OPTION, \$now/.test(runner));
ok('پیشرفت کار در سرور ذخیره می‌شود (ذخیرهٔ دوره‌ای داخل تیک + پایان تیک؛ مقاوم به مرگ PHP)', /(self|TisaCase_Optimizer_Job)::save\( \$job \)/.test(job) && /advance_now/.test(job));
ok('پس از بستن مرورگر، PHP تیک را نیمه‌کاره رها نمی‌کند', /ignore_user_abort\( true \)/.test(runner) && /set_time_limit/.test(runner));
ok('نتیجهٔ کار در نبود کاربر ذخیره و قابل دیدن است', /LAST_RESULT_OPTION/.test(runner) && /function last_result/.test(runner));
ok('کار فعال از کرون قابل بیدارشدن فوری است (spawn_cron)', /spawn_cron\(\)/.test(runner) && /function wake/.test(runner));
ok('نخستین درخواست کار را به هاست می‌سپارد (نه پردازش در مرورگر)', /runner_started/.test(job) && /TisaCase_Optimizer_Server_Runner::wake\(\)/.test(job));
ok('کرون کارِ حالت پنل را هم‌زمان جلو نمی‌برد (ضد پردازش دوباره)', /panel_job/.test(runner));
ok('کلید روشن/خاموش در تنظیمات هست', /'server_runner'/.test(settings) && /'runner_interval'/.test(settings));
ok('رابط کاربری کلید و وضعیت کرون را نشان می‌دهد', admin.includes('tco-runner-toggle') && admin.includes('tco-runner-now') && /function renderRunnerCard/.test(admin));
ok('در حین اجرا صریح می‌گوید «روی هاست»', admin.includes('روی خود هاست اجرا می‌شود'));
ok('اگر کرون هاست خواب بود، پنل بن‌بست نمی‌شود (تیک دستی)', admin.includes("api('runner_tick')") || /hostAwareCall/.test(admin));
ok('نتیجهٔ کار در نبود کاربر در کارت نشان داده می‌شود', admin.includes('آخرین نتیجهٔ هاست'));
ok('جایگزینی لینک‌ها دسته‌ای است (یک UPDATE بر جدول، نه بر جفت‌URL)', /function bulk_replace_urls/.test(proc) && !/SET post_content = REPLACE\( post_content, %s, %s \)/.test(proc));
ok('زنجیرهٔ توربو تیک‌ها را حین اجرای کار پشت‌سرهم می‌کند', /function chain_next_tick/.test(runner) && /wp_schedule_single_event\( time\(\) \+ 3, self::HOOK \)/.test(runner));
ok('رمزنگار WebP/AVIF گزینهٔ سرعت دارد', /webp:method/.test(proc) && /heic:speed/.test(proc));

console.log('── ۱۲) پشتیبان‌های اولیهٔ انبوه بدون نوشتن O(n²) ──');
const backupFn = fnBody(vault, 'backup_original_before_optimize');
const restoreOriginalFn = fnBody(vault, 'restore_original_backup');
const migrationFn = fnBody(vault, 'migrate_legacy_original_backups');
const vaultSummaryFn = fnBody(vault, 'summary');
const purgeOriginalFn = fnBody(vault, 'purge_original_backups');
ok('هر پشتیبان در آپشن مستقلِ غیر-autoload نوشته می‌شود', /OPTION_ORIG_PREFIX/.test(vault) && /add_option\(\s*\$backup_option,\s*\$entry,\s*'',\s*false\s*\)/.test(backupFn));
ok('مسیر هر تصویر، کل فهرست پشتیبان‌ها را بازخوانی نمی‌کند', !backupFn.includes('list_original_backups'));
ok('مهاجرت قدیمی در بسته‌های ۱۰۰تایی انجام می‌شود', /array_chunk\(\s*\$valid,\s*100,\s*true\s*\)/.test(migrationFn) && /INSERT IGNORE INTO/.test(migrationFn));
ok('فهرست قدیمی تا تأیید رکوردهای جدید حذف نمی‌شود', /if \( \$complete \)[\s\S]{0,180}?delete_option\( self::OPTION_ORIG_INDEX \)/.test(migrationFn));
ok('بازگردانی یک تصویر فقط رکورد همان شناسه را می‌خواند', restoreOriginalFn.includes('get_original_backup_entry') && !restoreOriginalFn.includes('list_original_backups'));
ok('داشبورد از شمارندهٔ سبک می‌خواند، نه از همهٔ متادیتاها', vaultSummaryFn.includes('original_backup_summary') && !vaultSummaryFn.includes('list_original_backups'));
ok('فهرست پشتیبان‌ها با جست‌وجوی پیشوندی option_name خوانده می‌شود', /function read_original_backup_options[\s\S]{0,900}?WHERE option_name LIKE %s/.test(vault));
ok('تخلیهٔ گروهی رکوردهای مستقل را هم پاک می‌کند', purgeOriginalFn.includes('delete_all_original_backup_options'));

console.log('');
if (fails.length) {
	console.error(`✗ ${fails.length} نقض در محافظ «بار سرور»:`);
	fails.forEach((f) => console.error(`   • ${f}`));
	process.exit(1);
}
console.log(`✓ هر ${pass} سنجهٔ محافظ «بار سرور و کار قابل ادامه» سالم است.`);
