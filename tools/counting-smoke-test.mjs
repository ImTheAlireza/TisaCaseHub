/**
 * اسموکتست «شمارش و عیب‌یابی» افزونهٔ tisacase-exporter — بدون نیاز به وردپرس.
 *
 * موتور PHP را در WASM اجرا می‌کند (php-wasm)، کلاس‌های بخش‌ها و کلاس عیب‌یابی را
 * لود می‌کند و با یک `$wpdb` جعلی (که SQL و پارامترها را ثبت می‌کند) این‌ها را می‌سنجد:
 *   ۱) `effective_statuses()` — از جمله حالت «هیچ‌کدام» که قبلاً اشتباهاً همه را می‌گرفت.
 *   ۲) سناریوی واقعی کاربر: ۳۰۰ سفارش «در حال انجام» ولی خروجی ۲۷ ردیف — و این‌که
 *      کارت عیب‌یابی درست همان فیلتر مسئول را نشان می‌دهد.
 *   ۳) هم‌خوانی تعداد «؟‌های» SQL با پارامترها در کوئری کوپن‌ها (باگ واقعی که رفع شد).
 *   ۴) درست‌بودن رشتهٔ GMT تاریخ‌ها (باگ تبدیل دوبارهٔ منطقهٔ زمانی).
 *
 * نیازمندی (فقط برای تست):  npm i php-wasm        # در /tmp یا هر پوشه‌ای که node پیدا کند
 * اجرا:                       node tools/counting-smoke-test.mjs
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

const php = new PhpNode();
for (const dir of ['/plugin', '/plugin/includes']) {
  try { php.mkdir(dir); } catch (e) { /* قبلاً ساخته شده */ }
}

const includes = [
  'class-tce-phone.php',
  'class-tce-format.php',
  'class-tce-module.php',
  'class-tce-module-phones.php',
  'class-tce-module-orders.php',
  'class-tce-module-customers.php',
  'class-tce-module-products.php',
  'class-tce-module-coupons.php',
  'class-tce-modules.php',
  'class-tce-diagnostics.php',
];

for (const name of includes) {
  php.writeFile('/plugin/includes/' + name, fs.readFileSync(`${PLUGIN}/includes/${name}`, 'utf8'));
}

const script = `<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('ABSPATH', '/wp/');
define('TISA_EXPORTER_DIR', '/plugin/');
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

function __($t, $d = '') { return $t; }
function esc_html__($t, $d = '') { return $t; }
function esc_html($t) { return htmlspecialchars((string) $t); }
function esc_attr($t) { return htmlspecialchars((string) $t); }
function esc_sql($t) { return addslashes((string) $t); }
function apply_filters($tag, $value) { return $value; }
function absint($v) { return abs((int) $v); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\\-]/', '', strtolower((string) $k)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function wp_strip_all_tags($t, $rb = false) { return trim(strip_tags((string) $t)); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d, '.', ','); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function get_gmt_from_date($local, $fmt = 'Y-m-d H:i:s') {
	// سایت آزمایشی روی +۳:۳۰ است ⇒ GMT = محلی - ۳:۳۰
	return gmdate($fmt, strtotime((string) $local) - 12600);
}
function get_date_from_gmt($gmt, $fmt = 'Y-m-d H:i') {
	return gmdate($fmt, strtotime((string) $gmt) + 12600);
}
function wc_get_price_decimals() { return 0; }
function wc_get_order_status_name($s) { return (string) $s; }
function wc_get_coupon_types() { return array('percent' => 'درصدی', 'fixed_cart' => 'مبلغ ثابت'); }
function wc_get_order_statuses() {
	return array(
		'wc-pending'    => 'در انتظار پرداخت',
		'wc-processing' => 'در حال انجام',
		'wc-on-hold'    => 'در انتظار بررسی',
		'wc-completed'  => 'تکمیل‌شده',
		'wc-cancelled'  => 'لغو‌شده',
		'wc-refunded'   => 'مسترد‌شده',
		'wc-failed'     => 'ناموفق',
	);
}
function get_option($k, $d = false) { return $d; }

class TisaCase_Exporter { const TEXT_DOMAIN = 'tisacase-exporter'; }

/* سوئیچ HPOS/Legacy (همان چیزی که ووکامرس فراهم می‌کند). */
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled() { return ! empty($GLOBALS["HPOS_MODE"]); } }');

/* ---------- $wpdb جعلی: SQL و پارامترها را ثبت می‌کند ---------- */
$GLOBALS['SQL_LOG']      = array();
$GLOBALS['LEGACY_TOTAL'] = 150;    // کل سفارش‌های جدول قدیمی (برای تست مقایسهٔ منابع)
$GLOBALS['HPOS_MODE']    = true;   // منبع فعال: HPOS

function fake_placeholder_count_old($sql) {
	return (int) preg_match_all('/%[sdf]/', $sql);
}

function fake_placeholder_count($sql) {
	return (int) preg_match_all('/%[sdf]/', (string) $sql);
}

/** پاسخ شمارش‌ها: با فیلتر تاریخ ۲۷ ردیف، بدون آن ۳۰۰ ردیف. */
function fake_count_answer($sql) {
	$has_date = (false !== strpos($sql, 'date_created_gmt >=') || false !== strpos($sql, 'date_created_gmt <=')
		|| false !== strpos($sql, 'post_date_gmt >=') || false !== strpos($sql, 'post_date_gmt <='));

	return $has_date ? 27 : 300;
}

/** SQL بدون نشانهٔ پارامترها. */
function fake_raw_sql($sql) {
	$sql = (string) $sql;
	$pos = strpos($sql, ' /*PARAMS:');

	return (false === $pos) ? $sql : substr($sql, 0, $pos);
}

/** پارامترهای آماده‌شده در نشانهٔ انتهای SQL. */
function fake_params($sql) {
	$sql = (string) $sql;
	$pos = strpos($sql, ' /*PARAMS:');

	if (false === $pos) {
		return array();
	}

	$json    = trim(substr($sql, $pos + 10));
	$json    = rtrim($json, '*/');
	$decoded = json_decode(trim($json), true);

	return is_array($decoded) ? $decoded : array();
}

class FakeWpdb {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $options = 'wp_options';
	public $usermeta = 'wp_usermeta';

	public function prepare($sql, ...$args) {
		$params = (1 === count($args) && is_array($args[0])) ? $args[0] : $args;
		$GLOBALS['SQL_LOG'][] = array('sql' => $sql, 'params' => $params, 'placeholders' => fake_placeholder_count($sql));
		return $sql . ' /*PARAMS:' . wp_json_encode($params) . '*/';
	}

	public function get_var($sql) {
		$raw    = fake_raw_sql($sql);
		$params = fake_params($sql);

		if (false !== strpos($raw, 'SHOW TABLES')) {
			return 'wp_wc_orders';
		}

		if (false !== strpos($raw, "FROM wp_wc_orders WHERE type = 'shop_order'")) {
			return 300; // کل سفارش‌های منبع فعال
		}

		if (false !== strpos($raw, "FROM wp_posts WHERE post_type = 'shop_order'")) {
			return $GLOBALS['LEGACY_TOTAL']; // کل سفارش‌های جدول قدیمی
		}

		if (in_array('__tisacase_none__', $params, true)) {
			return 0;
		}

		return fake_count_answer($raw);
	}

	public function get_row($sql, $mode = null) {
		if (false !== strpos(fake_raw_sql($sql), 'with_phone')) {
			return array('total' => 300, 'with_phone' => 27, 'uniq' => 25);
		}

		return null;
	}

	public function get_results($sql, $mode = null) {
		$raw = fake_raw_sql($sql);

		if (false !== strpos($raw, 'GROUP BY status')) {
			return array(
				array('k' => 'wc-processing', 'c' => 300),
				array('k' => 'wc-completed', 'c' => 120),
				array('k' => 'wc-cancelled', 'c' => 40),
			);
		}

		if (false !== strpos($raw, 'GROUP BY post_status')) {
			return array(
				array('k' => 'wc-processing', 'c' => 300),
				array('k' => 'wc-completed', 'c' => 120),
			);
		}

		return array();
	}

	public function query($sql) { return 1; }
	public function esc_like($s) { return $s; }
}

$wpdb = new FakeWpdb();

foreach (array(${includes.map((n) => `'${n}'`).join(', ')}) as $f) {
	require '/plugin/includes/' . $f;
}

$fails = array();
function check($cond, $label) {
	global $fails;
	echo ($cond ? "  ok   " : "  FAIL ") . $label . "\\n";
	if (!$cond) { $fails[] = $label; }
}

function last_sql() {
	$log = $GLOBALS['SQL_LOG'];
	return $log[count($log) - 1];
}

function sql_text_of($needle) {
	foreach ($GLOBALS['SQL_LOG'] as $item) {
		if (false !== strpos($item['sql'], $needle)) {
			return $item;
		}
	}
	return null;
}

/* ---------- ۱) وضعیت‌های مؤثر و باگ «هیچ‌کدام» ---------- */
$mDef = new ReflectionMethod('TisaCase_Exporter_Module_Orders', 'default_statuses');
$mDef->setAccessible(true);
$defaults = $mDef->invoke(null);
check(count($defaults) === 7, 'پیش‌فرض وضعیت‌ها = ۷ وضعیت هسته');
check(TisaCase_Exporter_Module_Orders::effective_statuses(array('statuses' => array('wc-processing'))) === array('wc-processing'), 'وضعیت انتخابی همان‌طور باقی می‌ماند');
check(TisaCase_Exporter_Module_Orders::effective_statuses(array()) === $defaults, 'نبودِ فیلد وضعیت = پیش‌فرض همه');
$none = TisaCase_Exporter_Module_Orders::effective_statuses(array('statuses' => array()));
check($none === array('__tisacase_none__'), '«هیچ‌کدام» دیگر به «همه» تبدیل نمی‌شود');
check(0 !== count(array_diff($none, $defaults)), 'مقدار «هیچ‌کدام» با هیچ وضعیت واقعی مطابقت ندارد');

/* ---------- ۲) شمارش سفارش‌ها بدون فیلتر تاریخ = ۳۰۰ ---------- */
$GLOBALS['SQL_LOG'] = array();
$all = array('date_mode' => 'all', 'date_from' => '', 'date_to' => '', 'statuses' => array('wc-processing'));
check(300 === TisaCase_Exporter_Module_Orders::count($all), 'بدون محدودیت تاریخ، همهٔ ۳۰۰ سفارش «در حال انجام» شمرده می‌شوند');
$count_sql = last_sql();
check(false === strpos($count_sql['sql'], 'date_created_gmt'), 'در حالت «بدون محدودیت تاریخ» هیچ شرط تاریخی در SQL نیست');
check(false !== strpos($count_sql['sql'], "status IN"), 'شرط وضعیت در SQL هست');
check($count_sql['placeholders'] === count($count_sql['params']), 'تعداد جای‌نگهدارهای SQL با پارامترها برابر است');

/* ---------- ۳) سناریوی کاربر: بازهٔ تاریخ خروجی را به ۲۷ می‌رساند ---------- */
$GLOBALS['SQL_LOG'] = array();
$ranged = $all;
$ranged['date_mode'] = 'range';
$ranged['date_from'] = '2026-01-01';
$ranged['date_to']   = '2026-01-31';
check(27 === TisaCase_Exporter_Module_Orders::count($ranged), 'با بازهٔ تاریخ فقط ۲۷ سفارش می‌ماند');

$report = TisaCase_Exporter_Diagnostics::report('orders', $ranged);
check(!empty($report['ok']), 'گزارش عیب‌یابی ساخته می‌شود');
check(27 === (int) $report['total'], 'عدد نهایی گزارش = همان عدد خروجی (۲۷)');
check(300 === (int) $report['baseline'], 'پلهٔ «بدون هیچ فیلتری» = ۳۰۰');
check(count($report['steps']) >= 3, 'نردبان فیلترها حداقل ۳ پله دارد');

$found_date_step = false;
foreach ($report['steps'] as $step) {
	if ('date_from' === $step['field'] && -273 === (int) $step['delta']) {
		$found_date_step = true;
	}
}
check($found_date_step, 'پلهٔ «از تاریخ» با کاهش ۲۷۳ ردیف پیدا شد');

$worst_text = '';
foreach ($report['warnings'] as $w) {
	if (false !== strpos($w['text'], 'بیشترین کاهش')) {
		$worst_text = $w['text'];
	}
}
check(false !== strpos($worst_text, 'از تاریخ'), 'هشدار «بیشترین کاهش» فیلتر تاریخ را معرفی می‌کند');

$proc = null;
foreach ($report['statuses'] as $s) {
	if ('wc-processing' === $s['key']) {
		$proc = $s;
	}
}
check(null !== $proc && 300 === (int) $proc['count'] && true === $proc['selected'], 'شمارش «در حال انجام» = ۳۰۰ و انتخاب‌شده');

check(27 === (int) $report['phones']['with_phone'] && 273 === (int) $report['phones']['missing'], 'آمار موبایل: ۲۷ دارای شماره، ۲۷۳ بدون شماره');
check(isset($report['reconcile']['hpos'], $report['reconcile']['legacy']), 'مقایسهٔ منابع داده (HPOS/قدیمی) برگردانده می‌شود');
check(300 === (int) $report['reconcile']['hpos'] && 150 === (int) $report['reconcile']['legacy'], 'عدد هر دو منبع درست خوانده می‌شود');

$ok_note = false;
foreach ($report['warnings'] as $w) {
	if ('ok' === $w['level'] && false !== strpos($w['text'], 'هم‌خوان')) {
		$ok_note = true;
	}
}
check($ok_note, 'وقتی منبع دیگر کمتر است، پیام «هم‌خوان» می‌دهد');

$GLOBALS['LEGACY_TOTAL'] = 800;
$report2 = TisaCase_Exporter_Diagnostics::report('orders', $ranged);
$warn_note = false;
foreach ($report2['warnings'] as $w) {
	if ('warn' === $w['level'] && false !== strpos($w['text'], 'منتقل نشده')) {
		$warn_note = true;
	}
}
check($warn_note, 'وقتی منبع غیرفعال بیشتر است، هشدار «منتقل نشده» می‌دهد');
$GLOBALS['LEGACY_TOTAL'] = 150;

/* ---------- ۴) عیب‌یابی روی «هیچ‌کدام» عدد صفر می‌دهد ---------- */
$empty = TisaCase_Exporter_Diagnostics::report('orders', array('statuses' => array()));
check(0 === (int) $empty['total'], 'انتخاب خالی وضعیت‌ها = عدد صفر');

/* ---------- ۵) کوپن‌ها: جای‌نگهدارها = پارامترها (باگ رفع‌شده) ---------- */
$GLOBALS['SQL_LOG'] = array();
TisaCase_Exporter_Module_Coupons::count(array('ctype' => 'percent', 'cstatus' => 'active'));
$c1 = last_sql();
check($c1['placeholders'] === count($c1['params']), 'کوپن‌ها (count با فیلتر): تعداد جای‌نگهدار = پارامتر (' . $c1['placeholders'] . ')');
check(false !== strpos($c1['sql'], 'GROUP BY p.ID'), 'کوئری کوپن‌ها گروه‌بندی بر اساس ID دارد');

$GLOBALS['SQL_LOG'] = array();
$page = TisaCase_Exporter_Module_Coupons::fetch(array('ctype' => 'all', 'cstatus' => 'all'), 0, 50);
$c2 = last_sql();
check($c2['placeholders'] === count($c2['params']), 'کوپن‌ها (fetch بدون فیلتر): تعداد جای‌نگهدار = پارامتر (' . $c2['placeholders'] . ')');
check(true === $page['done'] && 0 === $page['cursor'], 'fetch کوپن با نتیجهٔ خالی درست برمی‌گردد');

$row = array('code' => 'OFF10', 'discount_type' => 'percent', 'amount' => '10', 'usage_count' => '3', 'usage_limit' => '',
	'usage_limit_per_user' => '', 'minimum_amount' => '', 'maximum_amount' => '500000', 'expires' => '1767225600',
	'date_created' => '2025-12-01 08:00:00', 'free_shipping' => 'yes');
$m = new ReflectionMethod('TisaCase_Exporter_Module_Coupons', 'coupon_row');
$m->setAccessible(true);
$out = $m->invoke(null, $row);
check('2026-01-01 00:00:00' === $out['date_expires'], 'تاریخ انقضای کوپن به رشتهٔ GMT تبدیل می‌شود');
check('' === $out['minimum_amount'], 'حداقل سبد خالی، خالی می‌ماند');
check('' === $out['usage_limit'], 'سقف استفادهٔ خالی، خالی می‌ماند');
check(true === $out['free_shipping'], 'پرچم ارسال رایگان درست خوانده می‌شود');

/* ---------- ۶) مقدار پولی خالی و تاریخ ---------- */
check('' === TisaCase_Exporter_Format::value('', 'money'), 'مقدار پولی خالی «0» چاپ نمی‌شود');
check('2026-01-01 03:30' === TisaCase_Exporter_Format::value('2026-01-01 00:00:00', 'date'), 'تاریخ GMT به وقت محلی سایت (+۳:۳۰) تبدیل می‌شود');

/* ---------- ۷) رشتهٔ GMT مستقل از منطقهٔ زمانی ---------- */
$ts = 1767225600;
$gmt = new ReflectionMethod('TisaCase_Exporter_Module', 'gmt_string');
$gmt->setAccessible(true);
$fake = new class($ts) {
	private $t;
	public function __construct($t) { $this->t = $t; }
	public function getTimestamp() { return $this->t; }
	public function date($f) { return gmdate($f, $this->t + 12600); } // مثل WC_DateTime: وقت محلی
};
check(gmdate('Y-m-d H:i:s', $ts) === $gmt->invoke(null, $fake), 'gmt_string فقط به getTimestamp تکیه می‌کند (باگ تبدیل دوباره)');

/* ---------- ۸) بخش‌ها سالم ثبت شده‌اند ---------- */
$ids = array_keys(TisaCase_Exporter_Modules::all());
check($ids === array('phones', 'orders', 'customers', 'products', 'coupons'), 'پنج بخش در رجیستری هستند');
check(method_exists('TisaCase_Exporter_Module_Orders', 'order_status_counts'), 'شمارش وضعیت‌ها روی بخش سفارش‌ها موجود است');
check(method_exists('TisaCase_Exporter_Module_Orders', 'phone_stats'), 'آمار موبایل روی بخش سفارش‌ها موجود است');

/* ---------- ۹) وضعیت‌های یتیم و توضیح یکتاسازی ---------- */
$keys = array();
foreach ( (array) $report['statuses'] as $row ) {
	$keys[] = isset($row['key']) ? (string) $row['key'] : '';
}
check(! in_array('__sources__', $keys, true), 'نگاشت «منابع داده» به‌عنوان وضعیت یتیم نشان داده نمی‌شود');

$report_phones = TisaCase_Exporter_Diagnostics::report('phones', $ranged);
$dedup_note   = '';
foreach ( (array) $report_phones['warnings'] as $w ) {
	if (false !== strpos((string) $w['text'], 'یکتاسازی')) {
		$dedup_note = (string) $w['text'];
	}
}
check('' !== $dedup_note, 'هشدار «یکتاسازی» کاهش خروجی شماره‌ها را توضیح می‌دهد');
check('phone' === (string) $report_phones['dedup']['default'], 'کلید یکتاسازی پیش‌فرض بخش شماره‌ها = شماره موبایل');
check(empty($report['dedup']), 'بخش سفارش‌ها یکتاسازی پیش‌فرض ندارد (عدد خروجی = عدد کل)');

/* ---------- ۱۱) شرط «دارای شماره» در شمارش و خروجی یکسان است ---------- */
$GLOBALS['SQL_LOG'] = array();
$hp = $ranged;
$hp['has_phone'] = 1;
$hp_count = TisaCase_Exporter_Module_Orders::count($hp);
$hp_sql   = $GLOBALS['SQL_LOG'][ count($GLOBALS['SQL_LOG']) - 1 ]['sql'];

check(false === strpos($hp_sql, 'INNER JOIN'), 'شمارش «دارای شماره» از INNER JOIN تک‌منبعی استفاده نمی‌کند');
check(false !== strpos($hp_sql, 'wc_orders_meta'), 'شمارش «دارای شماره» متای سفارش را هم می‌بیند');
check(false !== strpos($hp_sql, 'postmeta'), 'شمارش «دارای شماره» متای قدیمی (wp_postmeta) را هم می‌بیند');

$GLOBALS['SQL_LOG'] = array();
TisaCase_Exporter_Module_Orders::fetch($hp, 0, 100, array('order_id', 'phone'));
$fetch_sql = $GLOBALS['SQL_LOG'][ count($GLOBALS['SQL_LOG']) - 1 ]['sql'];
$needle = 'wc_order_addresses a ON a.order_id';

check(false !== strpos($fetch_sql, $needle), 'خروجی سفارش‌ها شماره را از جدول آدرس‌ها می‌خواند');
check(substr_count($fetch_sql, 'postmeta') >= 1, 'خروجی سفارش‌ها پشتیبان متای قدیمی را دارد');

/* ---------- ۱۲) کوئری مشتری‌ها در حالت سخت‌گیر MySQL هم اجرا می‌شود ---------- */
$GLOBALS['SQL_LOG'] = array();
TisaCase_Exporter_Module_Customers::fetch($ranged, 0, 100, array('phone', 'orders_count'));
$cust_sql = $GLOBALS['SQL_LOG'][ count($GLOBALS['SQL_LOG']) - 1 ]['sql'];

check(false !== strpos($cust_sql, 'ORDER BY phone ASC'), 'ترتیب مشتری‌ها روی همان عبارت گروه‌بندی است (سازگار با ONLY_FULL_GROUP_BY)');
check(false === strpos($cust_sql, 'ORDER BY a.phone'), 'ترتیب مشتری‌ها دیگر روی ستون تک‌منبعی نیست');
check(false !== strpos($cust_sql, 'postmeta'), 'شمارهٔ مشتری پشتیبان متای قدیمی را دارد');

echo "\\n";
if (empty($fails)) {
	echo "ALL PASS\\n";
} else {
	echo "FAILED: " . count($fails) . "\\n";
	foreach ($fails as $f) { echo "  - $f\\n"; }
	exit(1);
}
`;

let output = '';
php.onoutput = (ev) => {
  const detail = ev && ev.detail !== undefined ? ev.detail : ev;
  output += Array.isArray(detail) ? detail.join('') : String(detail);
};

php.writeFile('/run.php', script);

try {
  await php.run('<?php require "/run.php";');
} catch (err) {
  console.log('PHP FATAL:', err && err.message ? err.message : String(err));
}

console.log(output.trim());

/* ---------- ۱۰) غیرفعال‌سازی نباید فایل‌ها را پاک کند (نگهبان منبع) ---------- */
let sourceFails = 0;
const lifecycle = fs.readFileSync(`${PLUGIN}/includes/class-tce-lifecycle.php`, 'utf8');
const deactivate = lifecycle.slice(lifecycle.indexOf('function deactivate'), lifecycle.indexOf('function uninstall'));

if (/sweep_old_exports\s*\(\s*true\s*\)/.test(deactivate)) {
  console.log('  FAIL deactivate() هنوز پاک‌سازی اجباری فایل‌ها را صدا می‌زند');
  sourceFails += 1;
} else {
  console.log('  ok   deactivate() هیچ فایلی را پاک نمی‌کند (تاریخچه حفظ می‌شود)');
}

process.exit(output.includes('ALL PASS') && 0 === sourceFails ? 0 : 1);
