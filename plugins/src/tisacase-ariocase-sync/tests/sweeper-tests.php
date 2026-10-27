<?php
/** آزمون جاروی پس‌زمینهٔ موجودی (TCAS_Sweeper) با دوبل وردپرس و ووکامرس. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['options'][ $key ] = $value; return true; }
function add_option( $key, $value ) { $GLOBALS['options'][ $key ] = $value; return true; }
function get_transient( $key ) { return isset( $GLOBALS['transients'][ $key ] ) ? $GLOBALS['transients'][ $key ] : false; }
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
function get_post_meta( $id, $key, $single = true ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['actions'][ $hook ][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $callback; }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['cron'][ $hook ] ) ? $GLOBALS['cron'][ $hook ] : false; }
function wp_schedule_event( $timestamp, $recurrence, $hook ) { $GLOBALS['cron'][ $hook ] = $timestamp; $GLOBALS['cron_args'][ $hook ] = array( $timestamp, $recurrence ); return true; }
function wp_schedule_single_event( $timestamp, $hook ) { if ( isset( $GLOBALS['cron'][ $hook ] ) ) { return false; } $GLOBALS['cron'][ $hook ] = $timestamp; return true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['cron'][ $hook ] ); return 1; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : null; }

class TCAS_Test_Product {
	private $id; private $type; private $name;
	public function __construct( $id, $type = 'simple' ) { $this->id = $id; $this->type = $type; $this->name = 'محصول ' . $id; }
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function is_type( $type ) { return $this->type === $type; }
}

/** دوبل TCAS_Core: فقط تنظیمات و defaults لازم است. */
class TCAS_Core {
	const OPTION_SETTINGS = 'tcas_ariocase_settings';
	public static $settings = array();
	public static function defaults() {
		return array( 'enabled' => 'yes', 'live_scope' => 'purchase', 'persist_stock' => 'yes', 'checkout_guard' => 'yes', 'stock_mode' => 'flag', 'fail_mode' => 'open', 'sweep_interval' => 30, 'sweep_batch' => 20 );
	}
	public static function settings() { return array_merge( self::defaults(), self::$settings ); }
}

/** دوبل نگاشت: فقط کلیدهای متا. */
class TCAS_Mapping {
	const META_CODE = '_tcas_remote_code';
	const META_ENABLED = '_tcas_sync_enabled';
	const META_QTY = '_tcas_remote_stock';
	const META_STATUS = '_tcas_remote_stock_status';
	const META_FETCHED_AT = '_tcas_remote_fetched_at';
}

class TCAS_Partners {
	public static $configured = true;
	public static function active_id() { return 'ariocase'; }
	public static function is_configured( $id = 'ariocase' ) { return self::$configured; }
	public static function label( $id = 'ariocase' ) { return 'ArioCase'; }
}

/** دوبل Live: پاسخ هر کد از فهرست آماده خوانده می‌شود. */
class TCAS_Live {
	public static $responses = array();
	public static $calls = array();
	public static function refresh( $product_id ) {
		self::$calls[] = $product_id;
		$key = 'P' . $product_id;
		$value = isset( self::$responses[ $key ] ) ? self::$responses[ $key ] : new WP_Error( 'tcas_http_status', 'پاسخ ناموفق (HTTP 500)' );
		if ( $value instanceof WP_Error ) {
			return $value;
		}
		update_post_meta( $product_id, TCAS_Mapping::META_QTY, (string) $value['quantity'] );
		update_post_meta( $product_id, TCAS_Mapping::META_STATUS, (string) $value['status'] );
		update_post_meta( $product_id, TCAS_Mapping::META_FETCHED_AT, time() );
		return $value;
	}
}

class TCAS_Logger {
	public static $entries = array();
	public static function add( $event, $code, $message ) { self::$entries[] = array( $event, $code, $message ); }
}

class TCAS_Test_Wpdb {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public $prepared = array();
	public $ids = array();
	public $count = 0;
	public function prepare( $query, ...$args ) {
		$this->prepared[] = array( $query, $args );
		return 'PREPARED:' . implode( '|', array_map( 'strval', $args ) );
	}
	public function get_col( $query ) { $GLOBALS['last_query'] = $query; return $this->ids; }
	public function get_var( $query ) { $GLOBALS['last_query'] = $query; return $this->count; }
}

require_once dirname( __DIR__ ) . '/includes/class-tcas-sweeper.php';

$pass = 0; $fail = 0;
function sweep_test( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "OK   {$label}\n"; }
	else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; }
}

$GLOBALS['options'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['meta'] = array();
$GLOBALS['actions'] = array(); $GLOBALS['filters'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['cron_args'] = array();
$GLOBALS['products'] = array(); $GLOBALS['slept'] = 0;
$GLOBALS['wpdb'] = new TCAS_Test_Wpdb();

TCAS_Core::$settings = array();
sweep_test( 'چرخهٔ پیش‌فرض ۳۰ دقیقه است', 30 === TCAS_Sweeper::interval() );
TCAS_Core::$settings = array( 'sweep_interval' => 7 );
sweep_test( 'مقدار غیرمجاز چرخه به ۳۰ دقیقه برمی‌گردد', 30 === TCAS_Sweeper::interval() );
TCAS_Core::$settings = array( 'sweep_interval' => 0 );
sweep_test( 'چرخهٔ خاموش مقدار صفر است و سن مجاز از یک دقیقه کمتر نمی‌شود', 0 === TCAS_Sweeper::interval() && MINUTE_IN_SECONDS === TCAS_Sweeper::max_age() );
TCAS_Core::$settings = array( 'sweep_interval' => 15 );
sweep_test( 'سن مجاز Snapshot برابر چرخهٔ انتخاب‌شده است', 15 * MINUTE_IN_SECONDS === TCAS_Sweeper::max_age() );
TCAS_Core::$settings = array( 'sweep_batch' => 999 );
sweep_test( 'اندازهٔ دسته به سقف ایمنی محدود می‌شود', TCAS_Sweeper::MAX_BATCH === TCAS_Sweeper::batch() );

$schedules = TCAS_Sweeper::schedules( array( 'hourly' => array( 'interval' => 3600, 'display' => 'hourly' ) ) );
sweep_test( 'زمان‌بندی پایهٔ پنج‌دقیقه‌ای در cron_schedules ثبت می‌شود', isset( $schedules['tcas_sweep_5'] ) && 300 === $schedules['tcas_sweep_5']['interval'] && isset( $schedules['hourly'] ) );

TCAS_Core::$settings = array( 'enabled' => 'yes', 'sweep_interval' => 30 );
$GLOBALS['cron'] = array();
TCAS_Sweeper::ensure_schedule();
sweep_test( 'با چرخهٔ فعال، رخداد Cron زمان‌بندی می‌شود', isset( $GLOBALS['cron'][ TCAS_Sweeper::HOOK ] ) && 'tcas_sweep_5' === $GLOBALS['cron_args'][ TCAS_Sweeper::HOOK ][1] );
TCAS_Sweeper::ensure_schedule();
sweep_test( 'زمان‌بندی تکراری ساخته نمی‌شود', 1 === count( $GLOBALS['cron'] ) );
TCAS_Core::$settings = array( 'enabled' => 'yes', 'sweep_interval' => 0 );
TCAS_Sweeper::ensure_schedule();
sweep_test( 'چرخهٔ خاموش رخداد Cron را حذف می‌کند', ! isset( $GLOBALS['cron'][ TCAS_Sweeper::HOOK ] ) );

// --- صف کهنه ---
TCAS_Core::$settings = array( 'enabled' => 'yes', 'sweep_interval' => 30 );
$GLOBALS['wpdb']->ids = array( '11', 12, 12, '13' );
$ids = TCAS_Sweeper::stale_ids( 20 );
list( $sql, $args ) = end( $GLOBALS['wpdb']->prepared );
sweep_test( 'صف فقط شناسه‌های یکتا و عددی برمی‌گرداند', array( 11, 12, 13 ) === $ids );
sweep_test( 'پرس‌وجوی صف فقط کدهای فعال با Snapshot کهنه را می‌آورد', false !== strpos( $sql, "pm.meta_value <> ''" ) && false !== strpos( $sql, "en.meta_value <> 'no'" ) && false !== strpos( $sql, 'CAST(age.meta_value AS UNSIGNED) <= %d' ) && false === strpos( $sql, 'LIMIT 500' ) );
sweep_test( 'مرز کهنگی و اندازهٔ دسته به پرس‌وجو داده می‌شود', ( time() - 1800 ) === (int) $args[5] && 20 === (int) $args[6] );
sweep_test( 'کدهای غیرقابل‌خواندن هم تا پایان مهلت از صف کنار می‌روند', false !== strpos( $sql, 'sk.meta_value' ) && ( time() - TCAS_Sweeper::SKIP_TTL ) === (int) $args[4] );

// --- اجرای موفق ---
$GLOBALS['wpdb']->ids = array( 21, 22 );
$GLOBALS['wpdb']->count = 0;
foreach ( array( 21, 22 ) as $id ) {
	$GLOBALS['products'][ $id ] = new TCAS_Test_Product( $id );
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_STATUS ] = 'instock';
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_QTY ] = '5';
}
TCAS_Live::$responses['P21'] = array( 'quantity' => 0, 'status' => 'outofstock' );
TCAS_Live::$responses['P22'] = array( 'quantity' => 5, 'status' => 'instock' );
TCAS_Core::$settings = array( 'enabled' => 'yes', 'sweep_interval' => 30 );
$GLOBALS['cron'] = array();
TCAS_Live::$calls = array();
$result = TCAS_Sweeper::run( 'cron' );
sweep_test( 'جارو اجرا شد و هر دو کد خوانده شدند', true === $result['ran'] && 2 === $result['processed'] && array( 21, 22 ) === TCAS_Live::$calls );
sweep_test( 'فقط تغییر واقعی موجودی شمرده می‌شود', 1 === $result['changed'] && 'outofstock' === $GLOBALS['meta'][21][ TCAS_Mapping::META_STATUS ] );
sweep_test( 'قفل اجرا پس از پایان آزاد می‌شود', false === get_transient( TCAS_Sweeper::LOCK_KEY ) );
sweep_test( 'خلاصهٔ اجرا برای رابط ذخیره می‌شود', 2 === (int) TCAS_Sweeper::state()['processed'] && false !== strpos( TCAS_Sweeper::summary_text(), 'کد بررسی شد' ) );
sweep_test( 'با خالی‌بودن صف، اجرای پیوسته زمان‌بندی نمی‌شود', ! isset( $GLOBALS['cron'][ TCAS_Sweeper::CONTINUE_HOOK ] ) );
sweep_test( 'گزارش عملیات برای تغییر موجودی ثبت می‌شود', ! empty( TCAS_Logger::$entries ) );

// --- صف باقی‌مانده ---
$GLOBALS['wpdb']->count = 40;
$GLOBALS['cron'] = array();
TCAS_Sweeper::run( 'continue' );
sweep_test( 'اگر صف باقی بماند، اجرای پیوستهٔ بعدی زمان‌بندی می‌شود', isset( $GLOBALS['cron'][ TCAS_Sweeper::CONTINUE_HOOK ] ) );
sweep_test( 'شمارندهٔ زنجیره در خلاصه ثبت می‌شود', 1 === (int) TCAS_Sweeper::state()['chain'] );
sweep_test( 'متن خلاصه صف باقی‌مانده را گزارش می‌کند', false !== strpos( TCAS_Sweeper::state()['message'], 'در صف' ) );

// --- قفل هم‌زمانی ---
set_transient( TCAS_Sweeper::LOCK_KEY, time(), 600 );
TCAS_Live::$calls = array();
$blocked = TCAS_Sweeper::run( 'manual' );
sweep_test( 'اجرای هم‌زمان با قفل رد می‌شود و هیچ API صدا نمی‌خورد', false === $blocked['ran'] && empty( TCAS_Live::$calls ) );
delete_transient( TCAS_Sweeper::LOCK_KEY );

// --- خطای محدودیت ---
$GLOBALS['cron'] = array();
$GLOBALS['wpdb']->ids = array( 31, 32 );
$GLOBALS['products'][31] = new TCAS_Test_Product( 31 );
$GLOBALS['products'][32] = new TCAS_Test_Product( 32 );
TCAS_Live::$responses['P31'] = new WP_Error( 'tcas_rate_limited', 'محدودیت تعداد درخواست (HTTP 429)' );
TCAS_Live::$responses['P32'] = array( 'quantity' => 1, 'status' => 'instock' );
TCAS_Live::$calls = array();
$limited = TCAS_Sweeper::run( 'cron' );
sweep_test( 'روی ۴۲۹ جارو فوراً متوقف می‌شود', 1 === count( TCAS_Live::$calls ) && array( 31 ) === TCAS_Live::$calls );
sweep_test( 'Backoff با زمان پایان ذخیره می‌شود', TCAS_Sweeper::in_backoff() && TCAS_Sweeper::backoff()['until'] > time() );
sweep_test( 'پیام فارسی محدودیت همکار نمایش داده می‌شود', false !== strpos( $limited['message'], 'محدود' ) && false === $limited['ok'] );
$GLOBALS['wpdb']->ids = array( 33 );
$GLOBALS['products'][33] = new TCAS_Test_Product( 33 );
TCAS_Live::$calls = array();
$skipped_run = TCAS_Sweeper::run( 'cron' );
sweep_test( 'در بازهٔ Backoff هیچ درخواستی ارسال نمی‌شود', false === $skipped_run['ran'] && empty( TCAS_Live::$calls ) );
sweep_test( 'متن Backoff ساعت پایان را نشان می‌دهد', false !== strpos( $skipped_run['message'], 'متوقف' ) );
TCAS_Sweeper::clear_backoff();
sweep_test( 'پاک‌کردن Backoff دوباره اجازهٔ اجرا می‌دهد', false === TCAS_Sweeper::in_backoff() );

// --- کدهای غیرقابل‌خواندن و خطاهای موقت ---
$GLOBALS['wpdb']->ids = array( 41, 42, 43 );
$GLOBALS['products'][41] = new TCAS_Test_Product( 41, 'variable' );
$GLOBALS['products'][42] = new TCAS_Test_Product( 42 );
$GLOBALS['products'][43] = new TCAS_Test_Product( 43 );
TCAS_Live::$responses['P42'] = new WP_Error( 'tcas_remote_not_found', 'محصول در همکار پیدا نشد (HTTP 404)' );
TCAS_Live::$responses['P43'] = array( 'quantity' => 2, 'status' => 'instock' );
TCAS_Live::$calls = array();
$mixed = TCAS_Sweeper::run( 'cron' );
sweep_test( 'والد متغیر بدون API رد و موقتاً از صف کنار گذاشته می‌شود', ! in_array( 41, TCAS_Live::$calls, true ) && ! empty( $GLOBALS['meta'][41][ TCAS_Sweeper::SKIP_META ] ) );
$retry_at = (int) $GLOBALS['meta'][42][ TCAS_Sweeper::SKIP_META ] + TCAS_Sweeper::SKIP_TTL;
sweep_test( 'کد خطادار فقط یک‌ربع بعد برای تلاش دوباره آزاد می‌شود', ! empty( $GLOBALS['meta'][42][ TCAS_Sweeper::SKIP_META ] ) && abs( $retry_at - time() - TCAS_Sweeper::RETRY_TTL ) <= 5 );
sweep_test( 'خطای ۴۰۴ کل صف را متوقف نمی‌کند', in_array( 43, TCAS_Live::$calls, true ) && 1 === (int) $mixed['errors'] && 1 === (int) $mixed['skipped'] );
sweep_test( 'خطای غیرمحدودیت‌ساز Backoff نمی‌سازد', false === TCAS_Sweeper::in_backoff() );

// --- خاموش و تنظیم‌نشده ---
TCAS_Core::$settings = array( 'enabled' => 'no' );
$off = TCAS_Sweeper::run( 'cron', 0, true );
sweep_test( 'همگام‌سازی خاموش هیچ درخواستی نمی‌فرستد', false === $off['ran'] && false === $off['ok'] && false !== strpos( $off['message'], 'خاموش' ) );
TCAS_Core::$settings = array( 'enabled' => 'yes' );
TCAS_Partners::$configured = false;
$unconfigured = TCAS_Sweeper::run( 'cron', 0, true );
sweep_test( 'همکار تنظیم‌نشده جارو را متوقف می‌کند', false === $unconfigured['ran'] && false !== strpos( $unconfigured['message'], 'تنظیم نشده' ) );
TCAS_Partners::$configured = true;
TCAS_Core::$settings = array( 'enabled' => 'yes', 'sweep_interval' => 0 );
$GLOBALS['wpdb']->count = 0;
$manual = TCAS_Sweeper::run( 'manual', 0, true );
sweep_test( 'با چرخهٔ خاموش، اجرای دستی هنوز کار می‌کند', true === $manual['ran'] );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
