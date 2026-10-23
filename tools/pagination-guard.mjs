#!/usr/bin/env node
/**
 * گارد «هیچ فهرستی بی‌صدا بریده نمی‌شود» (Pagination Contract).
 *
 * چرا؟ کاربر گزارش داد: «توی بخش تصاویر تکراری، تو هر صفحه فقط ۲۵ مورد نشون
 * می‌ده، نه گزینهٔ لود/انتخاب موارد بیشتر، نه صفحهٔ بعدی.»
 *
 * ریشهٔ مشکل سه چیز بود: سقفِ سخت‌کدشدهٔ ۲۵ در بک‌اند، نبودِ نوار صفحه‌بندی در
 * چند فهرست، و نبودِ انتخاب «تعداد در هر صفحه». این گارد آن سه را قفل می‌کند:
 *
 *   ۱) تنظیمات باید کلید `per_page` با پیش‌فرض و `clamp_per_page()` داشته باشد
 *      (۰ = همهٔ موارد).
 *   ۲) هیچ کوئری‌ای نباید «۲۵» را سخت‌کد کند؛ باید از آرگومان یا تنظیمات بخواند
 *      و مقدار مؤثر `per_page` را برگرداند.
 *   ۳) هر فهرست ردیف‌دار در admin.js باید `renderPager(...)` رندر کند.
 *   ۴) نوار صفحه‌بندی باید اول/قبلی/بعدی/آخر + پرش به شمارهٔ صفحه + انتخاب
 *      «تعداد در هر صفحه» داشته باشد و انتخاب کاربر ذخیره شود.
 *   ۵) کلاس‌های CSS این کنترل‌ها باید تعریف شده باشند.
 *
 * اجرا: node tools/pagination-guard.mjs
 * خروج ۱ = نقض قرارداد.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const SRC = path.join(ROOT, 'plugins/src/tisacase-optimizer');
const js = fs.readFileSync(path.join(SRC, 'assets/admin.js'), 'utf8');
const css = fs.readFileSync(path.join(SRC, 'assets/admin.css'), 'utf8');
const settings = fs.readFileSync(path.join(SRC, 'includes/class-tco-settings.php'), 'utf8');
const inspector = fs.readFileSync(path.join(SRC, 'includes/class-tco-media-inspector.php'), 'utf8');
const seo = fs.readFileSync(path.join(SRC, 'includes/class-tco-image-seo.php'), 'utf8');
const ajax = fs.readFileSync(path.join(SRC, 'includes/class-tco-ajax.php'), 'utf8');

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

/** بدنهٔ یک تابع جاوااسکریپت سطح ماژول را برمی‌گرداند. */
const bodyOf = (name) => {
	const start = js.indexOf(`function ${name}(`);
	if (start === -1) return '';
	const rest = js.slice(start + 10);
	const next = rest.search(/\n\t(?:async )?function /);
	return next === -1 ? rest : rest.slice(0, next);
};

console.log('── ۱) تنظیم «تعداد در هر صفحه» در بک‌اند ──');
ok('کلید per_page در پیش‌فرض‌های تنظیمات هست', /'per_page'\s*=>\s*25/.test(settings));
ok('تابع clamp_per_page وجود دارد', /function\s+clamp_per_page\s*\(/.test(settings));
ok('مقدار ۰ به معنای «همه» پذیرفته می‌شود', /0 === \$value[\s\S]{0,40}return 0;/.test(settings));
ok('سقف ۵۰۰ برای محافظت از حافظه اعمال می‌شود', /max\(\s*10,\s*min\(\s*500/.test(settings));
ok('update() مقدار per_page را پالایش می‌کند', /clamp_per_page\(\s*\$raw\['per_page'\]\s*\)/.test(settings));

console.log('── ۲) هیچ سقف سخت‌کدشده‌ای در کوئری‌ها نماند ──');
ok('query_items از آرگومان per_page می‌خواند', /args\['per_page'\]/.test(inspector));
ok('query_items به تنظیمات برمی‌گردد', /Settings::get\(\s*'per_page'/.test(inspector));
ok('query_items مقدار مؤثر per_page را برمی‌گرداند', (inspector.match(/'per_page'\s*=>/g) || []).length >= 2);
ok('هر دو شاخهٔ query_items «همه» را پشتیبانی می‌کنند', (inspector.match(/\$per_page\s*=\s*\$per_page > 0 \? \$per_page : max\( 1, \$total \)/g) || []).length >= 2);
ok('Image_SEO::audit پارامتر per_page دارد', /function audit\( \$filter = 'missing_alt', \$page = 1, \$per_page = 0 \)/.test(seo));
ok('audit هم از تنظیمات می‌خواند', /Settings::get\(\s*'per_page'/.test(seo));
ok('audit per_page مؤثر را برمی‌گرداند', /'per_page'\s*=>\s*\(int\) \$per_page/.test(seo));
ok('get_seo مقدار per_page را از درخواست می‌فرستد', /audit\([\s\S]{0,120}per_page/.test(ajax));
ok(
	'هیچ کوئری‌ای ۲۵ را سخت‌کد نمی‌کند',
	!/max\(\s*10,\s*min\(\s*100,\s*[^)]*25\s*\)/.test(inspector) && !/per_page\s*=\s*25\s*;/.test(inspector)
);

console.log('── ۳) هر فهرست ردیف‌دار صفحه‌بندی دارد ──');
const listRenderers = ['renderExplorerTab', 'renderUnusedTab', 'renderDuplicatesTab', 'renderSeoTab'];
for (const fn of listRenderers) {
	const body = bodyOf(fn);
	ok(`${fn} نوار صفحه‌بندی رندر می‌کند`, /renderPager\(\{/.test(body));
}
ok(
	'تعداد نوارهای صفحه‌بندی با تعداد فهرست‌ها می‌خواند',
	(js.match(/renderPager\(\{/g) || []).length >= 4,
	`تعداد: ${(js.match(/renderPager\(\{/g) || []).length}`
);

console.log('── ۴) کنترل‌های صفحه‌بندی کامل و کارا ──');
const pager = bodyOf('renderPager');
ok('نوار صفحه‌بندی شمارهٔ صفحه و تعداد کل را نشان می‌دهد', /صفحهٔ/.test(pager) && /مجموع/.test(pager));
ok('دکمهٔ «اول» و «آخر» وجود دارد', /data-page-edge="first"/.test(pager) && /data-page-edge="last"/.test(pager));
ok('دکمهٔ قبلی/بعدی وجود دارد', /data-page-delta="-1"/.test(pager) && /data-page-delta="1"/.test(pager));
ok('پرش به شمارهٔ صفحه وجود دارد', /tco-page-jump/.test(pager));
ok('انتخاب «تعداد در هر صفحه» در نوار هست', /renderPerPageSelect\(\)/.test(pager));
const per = bodyOf('renderPerPageSelect');
ok('گزینه‌های ۲۵ تا ۵۰۰ ارائه می‌شوند', [25, 50, 100, 200, 500].every((n) => per.includes(`[${n},`)));
ok('گزینهٔ «همهٔ موارد» (۰) وجود دارد', /\[\s*0,\s*'همهٔ موارد'/.test(per));
ok('انتخاب کاربر در state نگه داشته می‌شود', /state\.perPage/.test(per));
ok('تغییر تعداد در هر صفحه ذخیره می‌شود', /function applyPerPage/.test(js) && /save_settings/.test(bodyOf('applyPerPage')));
ok(
	'پس از تغییر تعداد، صفحه به ۱ برمی‌گردد',
	/\.page = 1;/.test(bodyOf('applyPerPage')) &&
		/state\.dupPage = 1;/.test(bodyOf('applyPerPage')) &&
		/state\.seoPage = 1;/.test(bodyOf('applyPerPage'))
);
ok('نمودار تکراری‌ها روی گروه‌ها برش می‌خورد', /visibleGroups/.test(js) && /slice\(startIdx/.test(js));
ok('رویداد تغییر تعداد در هر صفحه وصل است', /tco-per-page/.test(js) && /applyPerPage\(ev\.target\.value\)/.test(js));
ok('دکمه‌های صفحه در ACTION_SELECTOR ثبت شده‌اند', /'\[data-page-edge\]'/.test(js) && /'\[data-page-go\]'/.test(js));
ok('per_page همراه query_media فرستاده می‌شود', /query_media', Object\.assign\(\{\}, state\.(explorerFilter|unusedFilter), \{ per_page: state\.perPage \}\)/.test(js));
ok('per_page همراه get_seo فرستاده می‌شود', /get_seo', \{ seo_filter: state\.seoFilter, page: state\.seoPage, per_page: state\.perPage \}/.test(js));

console.log('── ۵) استایل کنترل‌های صفحه‌بندی ──');
for (const cls of ['.tco-pager', '.tco-pager__info', '.tco-pager__actions', '.tco-pager__jump', '.tco-perpage', '.tco-input--sm']) {
	ok(`کلاس ${cls} در CSS تعریف شده`, css.includes(cls));
}

console.log(`\n${fails.length === 0 ? '✓' : '✗'} هر ${pass} سنجهٔ «صفحه‌بندی و تعداد در هر صفحه» ${fails.length === 0 ? 'سالم است.' : 'نقض شد:'}`);
if (fails.length) {
	fails.forEach((f) => console.log(`   - ${f}`));
	process.exit(1);
}
