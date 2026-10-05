/**
 * اسموکتست قالب PDF افزونهٔ tisacase-exporter — بدون نیاز به وردپرس.
 *
 * موتور PHP را در WASM اجرا می‌کند (php-wasm)، کلاس‌های افزونه را لود می‌کند،
 * با یک جدول مشتریان فارسی و یک لیست شماره، PDF واقعی می‌سازد و چندین چک انجام می‌دهد:
 * سرآیند/پایان/xref، خواندن قلم جاسازی‌شده، شکل‌دهی (init/medi/fina و لام-الف)،
 * شکستن خط، دو‌جهته‌بودن ایمیل، و دست‌نخورده‌ماندن خروجی TXT نسخهٔ ۱.x.
 *
 * نیازمندی‌ها (فقط برای تست):
 *   npm i php-wasm        # در /tmp یا هر پوشه‌ای که node پیدا کند
 *   python3 -m pip install --break-system-packages pypdf pypdfium2 pillow   # بازبینی چشمی
 *
 * اجرا:
 *   node tools/pdf-smoke-test.mjs
 * خروجی PDFها در /tmp/pdf-out/ نوشته می‌شود (و مسیرشان چاپ می‌شود).
 */
import fs from 'node:fs';

/*
 * php-wasm این‌جا نصب نیست (بستهٔ تست، نه وابستگی افزونه). اول از محل نصب معمولِ تست
 * (/tmp/node_modules) لود می‌شود و اگر نبود، از import معمول استفاده می‌شود.
 */
let PhpNode = null;
for (const spec of [
  process.env.PHP_WASM || '',
  '/tmp/node_modules/php-wasm/PhpNode.js',
  'php-wasm/PhpNode',
].filter(Boolean)) {
  try {
    ({ PhpNode } = await import(spec));
    break;
  } catch (e) { /* بعدی */ }
}

if (!PhpNode) {
  console.error('php-wasm پیدا نشد. یک‌بار اجرا کنید:  cd /tmp && npm i php-wasm');
  process.exit(2);
}

const PLUGIN = '/home/user/TisaCaseHub/plugins/src/tisacase-exporter';
const OUT = '/tmp/pdf-out';

const php = new PhpNode();
for (const dir of ['/plugin', '/plugin/includes', '/plugin/assets', '/plugin/assets/fonts', '/out']) {
  try { php.mkdir(dir); } catch (e) { /* قبلاً ساخته شده */ }
}

const includes = [
  'class-tce-format.php',
  'class-tce-phone.php',
  'class-tce-pdf-font-data.php',
  'class-tce-pdf-text.php',
  'class-tce-pdf-writer.php',
  'class-tce-pdf.php',
];
for (const name of includes) {
  php.writeFile('/plugin/includes/' + name, fs.readFileSync(`${PLUGIN}/includes/${name}`, 'utf8'));
}

const b64 = (p) => fs.readFileSync(p).toString('base64');

const script = `<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('ABSPATH', '/wp/');
define('TISA_EXPORTER_DIR', '/plugin/');

function __($t, $d = '') { return $t; }
function esc_html__($t, $d = '') { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t); }
function esc_attr($t) { return htmlspecialchars((string) $t); }
function wp_strip_all_tags($t, $rb = false) { return trim(strip_tags((string) $t)); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function apply_filters($tag, $value) { return $value; }
function get_date_from_gmt($d, $f = 'Y-m-d H:i') { return gmdate($f, strtotime((string) $d)); }
function wc_get_price_decimals() { return 0; }
function wc_get_order_status_name($s) { return (string) $s; }
function wc_get_coupon_types() { return array('percent' => 'درصدی', 'fixed_cart' => 'مبلغ ثابت'); }
function get_option($k, $d = false) { return $d; }

class TisaCase_Exporter { const TEXT_DOMAIN = 'tisacase-exporter'; }

file_put_contents('/plugin/assets/fonts/vazirmatn-pdf.ttf', base64_decode('${b64(`${PLUGIN}/assets/fonts/vazirmatn-pdf.ttf`)}'));
file_put_contents('/plugin/assets/fonts/vazirmatn-pdf-bold.ttf', base64_decode('${b64(`${PLUGIN}/assets/fonts/vazirmatn-pdf-bold.ttf`)}'));

foreach (array('class-tce-format.php','class-tce-phone.php','class-tce-pdf-font-data.php','class-tce-pdf-text.php','class-tce-pdf-writer.php','class-tce-pdf.php') as $f) {
	require '/plugin/includes/' . $f;
}

$fails = array();
function check($cond, $label) { global $fails; echo ($cond ? "  ok   " : "  FAIL ") . $label . "\\n"; if (!$cond) { $fails[] = $label; } }

/* ---------- ۱) قالب و نرمال‌سازی موبایل ---------- */
$phone_cases = array(
	'09121234567'            => '989121234567',
	'9121234567'             => '989121234567',
	'989121234567'           => '989121234567',
	'+98 912 123 4567'       => '989121234567',
	'0098 912-123-4567'      => '989121234567',
	'+98 98 912 123 4567'    => '989121234567',
	'+98 98 98 912 123 4567' => '989121234567',
	'۰۹۱۲۱۲۳۴۵۶۷'            => '989121234567',
	'٠٩١٢١٢٣٤٥٦٧'            => '989121234567',
);
foreach ($phone_cases as $raw => $expected) {
	check($expected === TisaCase_Exporter_Phone::normalize_phone($raw), 'شمارهٔ موبایل به قالب canonical می‌رسد: ' . $raw);
}
check('' === TisaCase_Exporter_Format::value('02112345678', 'phone'), 'شمارهٔ نامعتبر به‌صورت خام وارد خروجی نمی‌شود');
$broken_code = TisaCase_Exporter_Format::value("ABC\tDEF\nGHI", 'code');
check(false === strpos($broken_code, "\t") && false === strpos($broken_code, "\n"), 'فیلد code نمی‌تواند مرز ردیف TSV را بشکند');
$layout_font = TisaCase_Exporter_Pdf_Font_Data::FONTS['regular'];
check('سلام دنیا' === TisaCase_Exporter_Pdf_Text::layout('سلام دنیا', $layout_font, true)['actual_text'], 'نسخهٔ منطقی متن فارسی برای ActualText حفظ می‌شود');

/* ---------- قالب PDF ثبت شده است ---------- */
$formats = TisaCase_Exporter_Format::formats();
check(isset($formats['pdf']), 'قالب pdf در formats() هست');
check('pdf' === TisaCase_Exporter_Format::ext('pdf'), 'پسوند pdf');
check('application/pdf' === TisaCase_Exporter_Format::mime('pdf'), 'mime نوع pdf');
check(TisaCase_Exporter_Pdf::supported(), 'فایل‌های قلم خوانده می‌شوند');

/* ---------- ۲) جدول مشتریان با متن فارسی ---------- */
$cols = array(
	array('key' => 'name',   'label' => 'نام مشتری',            'type' => 'text'),
	array('key' => 'phone',  'label' => 'موبایل (989xxxxxxx)',  'type' => 'phone'),
	array('key' => 'email',  'label' => 'ایمیل',                'type' => 'code'),
	array('key' => 'city',   'label' => 'شهر و نشانی',          'type' => 'text'),
	array('key' => 'orders', 'label' => 'تعداد سفارش',          'type' => 'num'),
	array('key' => 'spent',  'label' => 'مجموع خرید (تومان)',    'type' => 'money'),
	array('key' => 'date',   'label' => 'آخرین خرید',           'type' => 'date'),
);
$labels = array(); $keys = array();
foreach ($cols as $c) { $labels[] = $c['label']; $keys[] = $c['key']; }
$filter_summary = 'وضعیت سفارش: در حال انجام، تکمیل‌شده، لغوشده، در انتظار پرداخت، در حال بررسی، ناموفق و بازپرداخت‌شده · تاریخ ثبت: از ۱۴۰۵/۰۱/۰۱ تا ۱۴۰۵/۰۷/۱۴ · نوع مشتری: مهمان و عضو · حداقل مبلغ سفارش: ۱۲۵٬۰۰۰ تومان · فقط سفارش‌های دارای شمارهٔ موبایل · بازهٔ بلند برای آزمایش شکستن متن در چند سطر';
$meta = array('title' => 'مشتریان', 'site' => 'فروشگاه تیساکیس', 'date' => '۱۴ مهر ۱۴۰۵', 'filters' => $filter_summary, 'columns' => $cols);

$people = array(
	array('علی رضایی', '09121234567', 'ali@example.com', 'تهران، خیابان ولیعصر، کوچهٔ بهار، پلاک ۱۲', 7, '1,250,000', '1403-05-12'),
	array('زهرا محمدی', '0098 935 123 4567', 'zahra@shop.ir', 'اصفهان، خیابان چهارباغ بالا، مجتمع نور', 3, '480,000', '1402-11-03'),
	array('محمدرضا شعبانی‌زاده', '989121234567', 'm.reza@tisa-case.ir', 'شیراز، بلوار زند، ساختمان پزشکان', 21, '12,900,000', '1399-01-20'),
	array('عبدالحسین نوری', '+98 912 000 1122', '', 'مشهد، احمدآباد، خیابان دانشگاه ۵', 0, '0', '1404-01-01'),
	array('فاطمه‌السادات حسینی', '۰۹۱۲۳۴۵۶۷۸۹', 'fatemeh.s@subdomain.example.com', 'تبریز، خیابان امام، نزدیک میدان ساعت، طبقهٔ ۳', 12, '3,150,000', '1403-12-29'),
	array('امیر کاظمی', '0915-222-3344', 'safari@x.co', 'کرج، عظیمیه، بلوار کاج — واحد ۷', 5, '990,500', '1404-02-14'),
);
$long = 'این یک یادداشت بلند فارسی است تا شکستن خط و شکل‌دهی حروف در چند سطر آزمایش شود؛ شامل نیم‌فاصله، پرانتز (آزمایشی) و اعداد ۱۲۳ می‌باشد.';

$h = fopen('/out/customers.pdf', 'wb');
TisaCase_Exporter_Format::stream_open('pdf', $labels, $keys, $h, $meta);
for ($i = 0; $i < 60; $i++) {
	$p = $people[$i % count($people)];
	$note = (0 === $i % 7) ? $long : $p[3];
	TisaCase_Exporter_Format::stream_row(array($p[0] . ' (' . ($i + 1) . ')', $p[1], $p[2], $note, $p[4], $p[5], $p[6]), $keys);
}
TisaCase_Exporter_Format::stream_close();
fclose($h);

$pdf = file_get_contents('/out/customers.pdf');
check(0 === strpos($pdf, '%PDF-1.4'), 'سرآیند PDF درست است');
check(false !== strpos($pdf, '%%EOF'), 'پایان فایل PDF درست است');
check(strlen($pdf) > 20000, 'حجم PDF معقول است (' . strlen($pdf) . ' بایت)');
check(strlen($pdf) < 400000, 'حجم بدون zlib هم قابل قبول است');
check(preg_match('/\\/FontDescriptor/', $pdf) ? true : false, 'FontDescriptor نوشته شده');
check(false !== strpos($pdf, 'xref'), 'جدول xref هست');

/* ---------- ۳) بخش شماره‌ها: تک‌ستونی ---------- */
$pcols = array(array('key' => 'phone', 'label' => 'موبایل (989xxxxxxx)', 'type' => 'phone'));
$pmeta = array('title' => 'شماره تماس‌ها', 'site' => 'فروشگاه تیساکیس', 'date' => '۱۴ مهر ۱۴۰۵', 'columns' => $pcols);
$h2 = fopen('/out/phones.pdf', 'wb');
TisaCase_Exporter_Format::stream_open('pdf', array('موبایل (989xxxxxxx)'), array('phone'), $h2, $pmeta);
for ($i = 0; $i < 130; $i++) {
	TisaCase_Exporter_Format::stream_row(array('0912' . str_pad((string) (1000000 + $i * 7), 7, '0', STR_PAD_LEFT)), array('phone'));
}
TisaCase_Exporter_Format::stream_close();
fclose($h2);
$ppdf = file_get_contents('/out/phones.pdf');
check(0 === strpos($ppdf, '%PDF-1.4'), 'PDF شماره‌ها ساخته شد (' . strlen($ppdf) . ' بایت)');

/* ---------- ۴) حتی جدول‌های تمام‌عددی هم آرایش و تراز RTL دارند ---------- */
$num_cols = array(
	array('key' => 'id', 'label' => 'شناسه', 'type' => 'num'),
	array('key' => 'total', 'label' => 'مبلغ کل', 'type' => 'money'),
	array('key' => 'date', 'label' => 'تاریخ', 'type' => 'date'),
);
$num_labels = array_map(function ($col) { return $col['label']; }, $num_cols);
$num_keys = array_map(function ($col) { return $col['key']; }, $num_cols);
$num_meta = array('title' => 'سفارش‌ها', 'site' => 'فروشگاه تیساکیس', 'date' => '۱۴ مهر ۱۴۰۵', 'columns' => $num_cols);
$num_handle = fopen('/out/numeric.pdf', 'wb');
TisaCase_Exporter_Pdf::open($num_handle, $num_labels, $num_keys, $num_meta);
for ($i = 0; $i < 40; $i++) {
	TisaCase_Exporter_Pdf::row(array($i + 1, 125000 + ($i * 1000), '1405-07-14'));
}
$reflection = new ReflectionClass('TisaCase_Exporter_Pdf');
$columns_property = $reflection->getProperty('cols');
$columns_property->setAccessible(true);
$positioned = $columns_property->getValue();
$slots = array_map(function ($column) { return $column['slot']; }, $positioned);
$right_aligned = count($positioned) === 3;
foreach ($positioned as $column) {
	$right_aligned = $right_aligned && 'right' === $column['align'];
}
check(array(2, 1, 0) === $slots, 'ترتیب ستون‌های PDF تمام‌عددی از راست به چپ است: ' . json_encode($slots));
check($right_aligned, 'همهٔ سلول‌ها و سرستون‌های PDF راست‌چین هستند');
TisaCase_Exporter_Pdf::close();
fclose($num_handle);

/* ---------- ۵) رگرسیون: TXT باید دست‌نخورده بماند ---------- */
$h3 = fopen('/out/phones.txt', 'wb');
TisaCase_Exporter_Format::stream_open('txt', array('موبایل (989xxxxxxx)'), array('phone'), $h3, $pmeta);
for ($i = 0; $i < 3; $i++) {
	$tsv = TisaCase_Exporter_Format::row_to_tsv(array('phone' => '0912123456' . $i), $pcols);
	TisaCase_Exporter_Format::stream_row(TisaCase_Exporter_Format::split_tsv($tsv), array('phone'));
}
TisaCase_Exporter_Format::stream_close();
fclose($h3);
$txt = file_get_contents('/out/phones.txt');
check("989121234560\\n989121234561\\n989121234562\\n" === $txt, 'خروجی TXT دقیقاً مثل قبل است: ' . json_encode($txt));

/* ---------- ۵) شکل‌دهی و عرض ---------- */
$font = TisaCase_Exporter_Pdf_Font_Data::FONTS['regular'];
$lay = TisaCase_Exporter_Pdf_Text::layout('سلام دنیا', $font, true);
check($lay['rtl'] === true, 'جهت متن فارسی راست‌به‌چپ تشخیص داده شد');
check(count($lay['glyphs']) === 8, 'تعداد گلیف‌ها با لیگاتور لام-الف (' . count($lay['glyphs']) . ')');
check($lay['width'] > 0, 'عرض متن مثبت است (' . $lay['width'] . ')');
$lat = TisaCase_Exporter_Pdf_Text::layout('ali@example.com', $font, true);
check($lat['rtl'] === false, 'ایمیل لاتین تشخیص داده شد');
$form_salam = $font['forms'][0x0633];
$first20 = array_slice($lay['glyphs'], 0, 20);
check(in_array($form_salam[2], array_map(function ($g) { return $g['g']; }, $first20), true), 'فرم init برای حرف «س» استفاده شده');
$lam = TisaCase_Exporter_Pdf_Text::layout('الله', $font, true);
check(count($lam['glyphs']) === 4, '«الله» درست گلیف شد (' . count($lam['glyphs']) . ')');
$lig = TisaCase_Exporter_Pdf_Text::layout('بالاتر', $font, true);
check(count($lig['glyphs']) === 5, 'لیگاتور لام-الف در «بالاتر» (' . count($lig['glyphs']) . ')');
$wrap = TisaCase_Exporter_Pdf_Text::wrap($long, 20, $font, true);
check(count($wrap) > 1 && count($wrap) < 12, 'شکستن خط واژه‌ای کار می‌کند (' . count($wrap) . ' خط)');
check(TisaCase_Exporter_Pdf_Text::measure($long, $font, true) > 20 && TisaCase_Exporter_Pdf_Text::measure($long, $font, true) < 60, 'عرض متن به em است (' . round(TisaCase_Exporter_Pdf_Text::measure($long, $font, true), 1) . ')');

echo "\\nPDF_B64_CUSTOMERS:" . base64_encode($pdf) . ":END\\n";
echo "PDF_B64_PHONES:" . base64_encode($ppdf) . ":END\\n";
echo 'RESULT: ' . (empty($fails) ? 'ALL PASS' : count($fails) . ' FAIL') . "\\n";
echo "DONE\\n";
`;

let out = '';
php.onoutput = (ev) => { out += ev.detail; };

fs.mkdirSync(OUT, { recursive: true });
php.writeFile('/run.php', script);
try {
  await php.run('<?php require "/run.php";');
} catch (err) {
  console.log('PHP FATAL:', err && err.message ? err.message : String(err));
}

const text = out;
console.log(text.replace(/PDF_B64_[A-Z]+:[^]*?:END\n/g, (m) => m.slice(0, 24) + '…:' + (m.length / 1024).toFixed(0) + 'KB:END\n'));
const grab = (label, file) => {
  const m = text.match(new RegExp(label + ':([A-Za-z0-9+/=]+):END'));
  if (!m) { console.log('!! خروجی PDF پیدا نشد: ' + label); return; }
  fs.writeFileSync(file, Buffer.from(m[1], 'base64'));
  console.log('نوشته شد: ' + file + ' (' + fs.statSync(file).size + ' بایت)');
};
grab('PDF_B64_CUSTOMERS', `${OUT}/customers.pdf`);
grab('PDF_B64_PHONES', `${OUT}/phones.pdf`);
