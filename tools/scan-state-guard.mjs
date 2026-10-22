#!/usr/bin/env node
/**
 * گارد «وضعیت اسکن بخش‌بندی‌شده» (A1).
 *
 * چرا؟ در فروشگاه‌های بزرگ، کل وضعیت اسکن (ده‌ها مگابایت) در پایان هر تیک از نو
 * نوشته و در تیک بعد از نو خوانده می‌شد؛ حتی وقتی فقط یک شمارنده عوض شده بود.
 * اکنون وضعیت به یک «هستهٔ کوچک» + پنج بخش بزرگ تقسیم شده و هر بخش فقط وقتی
 * تغییر کند (و فشرده) نوشته می‌شود.
 *
 * این گارد قفل می‌کند که:
 *   ۱) هیچ آرایهٔ بزرگی از فهرست بخش‌ها جا نیفتاده باشد.
 *   ۲) خواندن و نوشتن دقیقاً متقارن باشند (کلیدها گم یا دوباره نشوند).
 *   ۳) هر مرحلهٔ اسکن بخشی که تغییر می‌دهد را علامت بزند.
 *   ۴) شبکهٔ ایمنی وجود داشته باشد: اگر علامتی نبود، همه بنویس (هیچ‌گاه پیشرفت گم نشود).
 *   ۵) فایل‌های بخش‌ها هنگام اسکن تازه، پایان اسکن و پاک‌سازی کش حذف شوند.
 *
 * اجرا: node tools/scan-state-guard.mjs
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FILE = path.join(ROOT, 'plugins/src/tisacase-optimizer/includes/class-tco-reference-scanner.php');
const src = fs.readFileSync(FILE, 'utf8');

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

const blockOf = (startMarker, endMarker) => {
	const a = src.indexOf(startMarker);
	if (a === -1) return '';
	const b = src.indexOf(endMarker, a + startMarker.length);
	return b === -1 ? src.slice(a) : src.slice(a, b);
};

console.log('── ۱) تقسیم وضعیت به بخش‌ها ──');
const blobsBlock = blockOf('const STATE_BLOBS = array(', ');');
const blobKeys = [...blobsBlock.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
ok('پنج بخش وضعیت تعریف شده', (blobsBlock.match(/=>\s*array\(/g) || []).length === 5, blobsBlock.slice(0, 90));
for (const key of ['raw_atts', 'atts_by_dir', 'stem_map', 'refs', 'ref_url_hash', 'items', 'subsize_stats', 'hash_groups', 'dir_queue', 'orphans', 'disk_bytes']) {
	ok(`کلید سنگین «${key}» در بخش‌ها ذخیره می‌شود`, blobKeys.includes(key));
}

const initial = blockOf('private static function create_initial_state()', 'private static function run_stage_att_load');
const initialKeys = [...initial.matchAll(/'([a-z_]+)'\s*=>/g)].map((m) => m[1]);
ok('وضعیت اولیه حداقل ۳۰ کلید دارد', initialKeys.length >= 30, `تعداد: ${initialKeys.length}`);

console.log('── ۲) تقارن خواندن و نوشتن ──');
const saveBody = blockOf('private static function save_state(', 'private static function delete_state_files');
const loadBody = blockOf('private static function load_state(', 'private static function save_state(');
ok('در نوشتن، کلیدهای بخش‌ها از هسته جدا می‌شوند', /unset\(\s*\$core\[\s*\$key\s*\]\s*\)/.test(saveBody));
ok('هسته با علامت _split ذخیره می‌شود', /_split'\]\s*=\s*true/.test(saveBody));
ok('در خواندن، بخش‌ها روی هسته سوار می‌شوند', /foreach\s*\(\s*self::STATE_BLOBS as \$blob => \$keys\s*\)/.test(loadBody));
ok('وضعیت نسخهٔ قدیمی (بدون _split) پشتیبانی می‌شود', /_split/.test(loadBody));
ok('فایل‌های فشرده (gzip) خوانده می‌شوند', /gzdecode/.test(src) && /maybe_gunzip\(\s*\$raw\s*\)|maybe_gunzip\(\s*\$braw\s*\)/.test(loadBody));
ok('فایل‌ها فشرده نوشته می‌شوند', /gzencode/.test(src) && /maybe_gzip/.test(saveBody));

console.log('── ۳) شبکهٔ ایمنی (هیچ پیشرفتی گم نشود) ──');
ok(
	'اگر هیچ بخشی علامت نخورده باشد، همه نوشته می‌شوند',
	/if \( empty\( \$dirty \) \) \{\s*\$dirty = array_keys\( self::STATE_BLOBS \);/.test(saveBody)
);
ok('نوشتن هر بخش مستقل و مقاوم است (خطای یک بخش بقیه را نمی‌خواباند)', /continue;/.test(saveBody));

console.log('── ۴) علامت‌گذاری مراحل ──');
const stages = [...src.matchAll(/private static function (run_stage_[a-z_]+|prepare_dir_sweep_queue)\(/g)].map((m) => m[1]);
ok(`همهٔ مراحل پیدا شدند (${stages.length})`, stages.length >= 8);
for (const fn of stages) {
	const body = blockOf(`private static function ${fn}(`, '\n\t\tprivate static function ');
	ok(`مرحلهٔ ${fn} بخش‌هایش را علامت می‌زند`, /self::mark_dirty\(/.test(body));
}

console.log('── ۵) چرخهٔ حیات فایل‌ها ──');
ok('فایل‌های بخش‌ها هنگام اسکن تازه پاک می‌شوند', /delete_state_files\(\)/.test(blockOf('public static function step_scan(', 'private static function normalize_state')));
ok('پس از پایان اسکن (finalize) وضعیت حذف می‌شود', /self::write_persisted_catalog\( \$catalog \);[\s\S]{0,220}self::delete_state_files\(\);/.test(src));
ok('پاک‌سازی کش، بخش‌ها را هم حذف می‌کند', /invalidate_cache[\s\S]{0,900}self::delete_state_files\(\)/.test(src));
ok('نوشتن وضعیت فقط از مسیر save_state انجام می‌شود', (src.match(/file_put_contents\(\s*\$state_file/g) || []).length === 0);

console.log('');
if (fails.length) {
	console.error(`✗ ${fails.length} نقض در وضعیت بخش‌بندی‌شدهٔ اسکن:`);
	for (const f of fails) console.error('   • ' + f);
	process.exit(1);
}
console.log(`✓ هر ${pass} سنجهٔ «وضعیت اسکن بخش‌بندی‌شده» سالم است.`);
