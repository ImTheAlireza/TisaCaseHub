<?php
/** آزمون موتور Stock/Live با دوبل‌های ووکامرس. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $default; }
function add_option( ...$args ) { $GLOBALS['options'][ $args[0] ] = $args[1]; return true; }
function update_option( ...$args ) { $GLOBALS['options'][ $args[0] ] = $args[1]; return true; }
function add_action( ...$args ) { $GLOBALS['actions'][] = $args; }
function add_filter( ...$args ) {}
function is_admin() { return ! empty( $GLOBALS['is_admin'] ); }
function wp_doing_ajax() { return ! empty( $GLOBALS['doing_ajax'] ); }
function is_product() { return ! empty( $GLOBALS['context']['product'] ); }
function is_cart() { return ! empty( $GLOBALS['context']['cart'] ); }
function is_checkout() { return ! empty( $GLOBALS['context']['checkout'] ); }
function get_transient( $key ) {
	if ( ! isset( $GLOBALS['transients'][ $key ] ) ) { return false; }
	$entry = $GLOBALS['transients'][ $key ];
	if ( ! empty( $entry['expires'] ) && time() >= $entry['expires'] ) { unset( $GLOBALS['transients'][ $key ] ); return false; }
	return $entry['value'];
}
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['transients'][ $key ] = array( 'value' => $value, 'expires' => $expiration ? time() + $expiration : 0 ); return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); return true; }
function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( (array) $GLOBALS['scheduled'] as $event ) { if ( $event['hook'] === $hook && $event['args'] === $args ) { return $event['timestamp']; } }
	return false;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	if ( wp_next_scheduled( $hook, $args ) ) { return false; }
	$GLOBALS['scheduled'][] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args );
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$before = count( $GLOBALS['scheduled'] );
	$GLOBALS['scheduled'] = array_values( array_filter( $GLOBALS['scheduled'], function( $event ) use ( $hook, $args ) { return $event['hook'] !== $hook || $event['args'] !== $args; } ) );
	return $before - count( $GLOBALS['scheduled'] );
}
function get_post_meta( $id, $key, $single = true ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : null; }
function wc_delete_product_transients( $id ) {}
function wc_add_notice( $message, $type ) { $GLOBALS['notices'][] = array( $message, $type ); }
function WC() { return $GLOBALS['wc']; }
class TCAS_Test_Cart {
	public $items = array();
	public function get_cart() { return $this->items; }
}
class TCAS_Test_Product {
	private $id; private $type; private $parent; private $manage = false; private $qty = null; private $status = 'instock';
	public $saved = 0; private $sku; private $name;
	public function __construct( $id, $type = 'simple', $qty = 0, $status = 'instock', $parent = 0 ) { $this->id = $id; $this->type = $type; $this->qty = $qty; $this->status = $status; $this->parent = $parent; $this->sku = 'SKU-' . $id; $this->name = 'محصول ' . $id; }
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_type() { return $this->type; }
	public function is_type( $type ) { return $this->type === $type; }
	public function get_manage_stock( $context = 'view' ) { return $this->manage; }
	public function get_stock_quantity( $context = 'view' ) { return $this->qty; }
	public function get_stock_status( $context = 'view' ) { return $this->status; }
	public function set_manage_stock( $value ) { $this->manage = (bool) $value; }
	public function set_stock_quantity( $value ) { $this->qty = (float) $value; }
	public function set_stock_status( $value ) { $this->status = $value; }
	public function save() { $this->saved++; return $this->id; }
}
class WC_Product_Variable { public static function sync_stock_status( $id, $save = true ) {} }
class TCAS_Api {
	public static $responses = array(); public static $calls = array(); public static $identifiers = array();
	public static function fetch_stock( $code, $identifiers = array() ) { self::$calls[] = $code; self::$identifiers[ $code ] = $identifiers; return isset( self::$responses[ $code ] ) ? self::$responses[ $code ] : new WP_Error( 'missing', 'missing' ); }
}
class TCAS_Test_Errors { public $errors = array(); public function add( $code, $message ) { $this->errors[ $code ] = $message; } }

/** دوبل Backoff جارو (کلاس واقعی در این مجموعه بارگذاری نمی‌شود). */
class TCAS_Sweeper {
	public static $backoff = array();
	public static function in_backoff() { return ! empty( self::$backoff ); }
}
require_once dirname( __DIR__ ) . '/includes/class-tcas-core.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-mapping.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-stock.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-live.php';

$pass = 0; $fail = 0;
$GLOBALS['actions'] = array(); $GLOBALS['scheduled'] = array(); $GLOBALS['transients'] = array(); $GLOBALS['context'] = array();
$GLOBALS['is_admin'] = false; $GLOBALS['doing_ajax'] = false;
function live_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function map_product( $id, $code, $product = null ) {
	$GLOBALS['products'][ $id ] = $product ?: new TCAS_Test_Product( $id );
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_CODE ] = $code;
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_ENABLED ] = 'yes';
}
$GLOBALS['wc'] = (object) array( 'cart' => new TCAS_Test_Cart() );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ] = array_merge( TCAS_Core::defaults(), array( 'live_scope' => 'all', 'base_url' => 'https://ariocase.test/api', 'api_key' => 'secret', 'stock_mode' => 'quantity', 'fail_mode' => 'closed' ) );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS_VERSION ] = 0;
TCAS_Core::maybe_upgrade_settings();
live_test( 'تنظیمات نسخهٔ قدیمی از خواندن هم‌زمان به حالت خرید مهاجرت می‌کند', 'purchase' === get_option( TCAS_Core::OPTION_SETTINGS )['live_scope'] && 'secret' === get_option( TCAS_Core::OPTION_SETTINGS )['api_key'] );
live_test( 'پیش‌فرض نصب جدید هم مسیر امن خرید است', 'purchase' === TCAS_Core::defaults()['live_scope'] );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ] = array_merge( TCAS_Core::settings(), array( 'live_scope' => 'all', 'stock_mode' => 'quantity', 'fail_mode' => 'closed' ) );
TCAS_Core::maybe_upgrade_settings();
live_test( 'حالت هم‌زمان پس از مهاجرت فقط با انتخاب صریح فعال می‌ماند', 'all' === TCAS_Core::settings()['live_scope'] );

map_product( 1, 'REMOTE-1', new TCAS_Test_Product( 1, 'simple', 9, 'outofstock' ) );
TCAS_Api::$responses['REMOTE-1'] = array( 'code' => 'REMOTE-1', 'quantity' => 5.0, 'status' => 'instock', 'product' => array( 'remote_id' => 1001 ) );
$qty = TCAS_Live::filter_quantity( 9, $GLOBALS['products'][1] );
$status = TCAS_Live::filter_status( 'outofstock', $GLOBALS['products'][1] );
live_test( 'فیلترهای زنده مقدار ArioCase را برمی‌گردانند', 5.0 === $qty && 'instock' === $status );
live_test( 'چند Getter در یک Request فقط یک API Call دارند', array( 'REMOTE-1' ) === TCAS_Api::$calls );
live_test( 'تغییر معتبر با CRUD ذخیره می‌شود', 1 === $GLOBALS['products'][1]->saved && true === $GLOBALS['products'][1]->get_manage_stock() && 5.0 === $GLOBALS['products'][1]->get_stock_quantity() );
$invalid = TCAS_Stock::apply( $GLOBALS['products'][1], array( 'quantity' => -2, 'status' => 'outofstock' ) );
live_test( 'موجودی منفی حتی با Stub معیوب ذخیره نمی‌شود', is_wp_error( $invalid ) && 1 === $GLOBALS['products'][1]->saved );

$GLOBALS['products'][2] = new TCAS_Test_Product( 2, 'simple', 8, 'instock' );
live_test( 'محصول نگاشت‌نشده دست‌نخورده و بدون API است', 8 === TCAS_Live::filter_quantity( 8, $GLOBALS['products'][2] ) && 1 === count( TCAS_Api::$calls ) );
live_test( 'Live شناسهٔ محصول دریافت‌شده را برای Request بعدی ذخیره می‌کند', 1001 === (int) get_post_meta( 1, TCAS_Mapping::META_REMOTE_ID, true ) );

map_product( 3, 'REMOTE-3', new TCAS_Test_Product( 3, 'simple', 7, 'instock' ) );
TCAS_Api::$responses['REMOTE-3'] = new WP_Error( 'timeout', 'timeout' );
live_test( 'خطای API مقدار قبلی را صفر نمی‌کند', 7 === TCAS_Live::filter_quantity( 7, $GLOBALS['products'][3] ) && 0 === $GLOBALS['products'][3]->saved );
$passed = TCAS_Live::validate_add_to_cart( true, 3, 1 );
live_test( 'خطای API هنگام خرید Fail-closed است', false === $passed && ! empty( $GLOBALS['notices'] ) );

map_product( 4, 'REMOTE-4', new TCAS_Test_Product( 4, 'variation', 2, 'instock', 40 ) );
TCAS_Api::$responses['REMOTE-4'] = array( 'code' => 'REMOTE-4', 'quantity' => 2.0, 'status' => 'instock' );
$passed = TCAS_Live::validate_add_to_cart( true, 4, 3, 4 );
live_test( 'موجودی کمتر از تعداد درخواستی رد می‌شود', false === $passed );

map_product( 5, 'REMOTE-5', new TCAS_Test_Product( 5, 'simple', 5, 'instock' ) );
TCAS_Api::$responses['REMOTE-5'] = array( 'code' => 'REMOTE-5', 'quantity' => 5.0, 'status' => 'instock' );
$passed = TCAS_Live::validate_add_to_cart( true, 5, 2 );
live_test( 'تعداد کافی در افزودن به سبد پذیرفته می‌شود', true === $passed );

map_product( 6, 'REMOTE-6', new TCAS_Test_Product( 6, 'simple', 3, 'instock' ) );
TCAS_Api::$responses['REMOTE-6'] = array( 'code' => 'REMOTE-6', 'quantity' => 3.0, 'status' => 'instock' );
$GLOBALS['wc']->cart->items = array(
	array( 'product_id' => 6, 'variation_id' => 0, 'quantity' => 2 ),
	array( 'product_id' => 6, 'variation_id' => 0, 'quantity' => 2 ),
);
$errors = new TCAS_Test_Errors();
TCAS_Live::validate_checkout( array(), $errors );
live_test( 'تعداد تکراری یک قلم در سبد تجمیع و کنترل می‌شود', isset( $errors->errors['tcas_live_quantity'] ) );

map_product( 7, 'REMOTE-7', new TCAS_Test_Product( 7, 'simple', 4, 'outofstock' ) );
TCAS_Api::$responses['REMOTE-7'] = array( 'code' => 'REMOTE-7', 'quantity' => null, 'status' => 'instock' );
$manage = TCAS_Live::filter_manage_stock( true, $GLOBALS['products'][7] );
live_test( 'Status-only انبار عددی محلی را خنثی می‌کند', false === $manage && false === $GLOBALS['products'][7]->get_manage_stock() && 'instock' === $GLOBALS['products'][7]->get_stock_status() );

map_product( 8, 'REMOTE-8', new TCAS_Test_Product( 8, 'simple', 0, 'outofstock' ) );
TCAS_Api::$responses['REMOTE-8'] = array( 'code' => 'REMOTE-8', 'quantity' => 0.0, 'status' => 'outofstock' );
$passed = TCAS_Live::validate_add_to_cart( true, 8, 1 );
live_test( 'صفر واقعی مانع خرید می‌شود', false === $passed );

map_product( 9, 'REMOTE-9', new TCAS_Test_Product( 9, 'variable', 0, 'instock' ) );
TCAS_Api::$responses['REMOTE-9'] = array( 'code' => 'REMOTE-9', 'quantity' => 99.0, 'status' => 'instock' );
$calls_before = count( TCAS_Api::$calls );
live_test( 'والد متغیر به‌جای تنوع‌ها سینک نمی‌شود', null === TCAS_Live::state( 9 ) && $calls_before === count( TCAS_Api::$calls ) );

$product1_saved = $GLOBALS['products'][1]->saved;
TCAS_Live::refresh( 1 );
live_test( 'Refresh دوباره API می‌خواند ولی مقدار تکراری را بازنویسی نمی‌کند', 2 === array_count_values( TCAS_Api::$calls )['REMOTE-1'] && $product1_saved === $GLOBALS['products'][1]->saved );
live_test( 'درخواست بعدی شناسهٔ عددی ذخیره‌شده را به API می‌دهد', 1001 === TCAS_Api::$identifiers['REMOTE-1']['remote_id'] );

$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['live_scope'] = 'purchase';
map_product( 14, 'REMOTE-14', new TCAS_Test_Product( 14, 'simple', 0, 'outofstock' ) );
$GLOBALS['meta'][14][ TCAS_Mapping::META_QTY ] = '0';
$GLOBALS['meta'][14][ TCAS_Mapping::META_STATUS ] = 'outofstock';
$GLOBALS['meta'][14][ TCAS_Mapping::META_FETCHED_AT ] = time();
TCAS_Api::$responses['REMOTE-14'] = array( 'code' => 'REMOTE-14', 'quantity' => 3.0, 'status' => 'instock' );
$api_before_purchase = count( TCAS_Api::$calls );
$purchase_passed = TCAS_Live::validate_add_to_cart( true, 14, 1 );
live_test( 'افزودن به سبد در حالت کش، Snapshot کهنه را نادیده می‌گیرد و API زنده را کنترل می‌کند', true === $purchase_passed && count( TCAS_Api::$calls ) === $api_before_purchase + 1 && '3' === (string) $GLOBALS['meta'][14][ TCAS_Mapping::META_QTY ] );

map_product( 15, 'REMOTE-15', new TCAS_Test_Product( 15, 'simple', 0, 'outofstock' ) );
$GLOBALS['meta'][15][ TCAS_Mapping::META_QTY ] = '0';
$GLOBALS['meta'][15][ TCAS_Mapping::META_STATUS ] = 'outofstock';
$GLOBALS['meta'][15][ TCAS_Mapping::META_FETCHED_AT ] = time();
TCAS_Api::$responses['REMOTE-15'] = array( 'code' => 'REMOTE-15', 'quantity' => 2.0, 'status' => 'instock' );
$GLOBALS['wc']->cart->items = array( array( 'product_id' => 15, 'variation_id' => 0, 'quantity' => 1 ) );
$checkout_errors = new TCAS_Test_Errors();
$api_before_checkout = count( TCAS_Api::$calls );
TCAS_Live::validate_checkout( array(), $checkout_errors );
live_test( 'Checkout در حالت کش موجودی تازهٔ API را بررسی می‌کند', empty( $checkout_errors->errors ) && count( TCAS_Api::$calls ) === $api_before_checkout + 1 );

map_product( 11, 'REMOTE-11', new TCAS_Test_Product( 11, 'simple', 1, 'outofstock' ) );
$GLOBALS['meta'][11][ TCAS_Mapping::META_QTY ] = '4';
$GLOBALS['meta'][11][ TCAS_Mapping::META_STATUS ] = 'instock';
$GLOBALS['meta'][11][ TCAS_Mapping::META_FETCHED_AT ] = time();
$GLOBALS['context'] = array( 'product' => true );
$api_before = count( TCAS_Api::$calls );
$cached_quantity = TCAS_Live::filter_quantity( 1, $GLOBALS['products'][11] );
$cached_status = TCAS_Live::filter_status( 'outofstock', $GLOBALS['products'][11] );
live_test( 'حالت خرید مقدار Snapshot تازه را بدون API در صفحهٔ محصول می‌خواند', 4.0 === $cached_quantity && 'instock' === $cached_status && $api_before === count( TCAS_Api::$calls ) );

$GLOBALS['is_admin'] = true; $GLOBALS['doing_ajax'] = true; $GLOBALS['context'] = array();
TCAS_Live::filter_status( 'outofstock', $GLOBALS['products'][11] );
$GLOBALS['is_admin'] = false; $GLOBALS['doing_ajax'] = false;
live_test( 'درخواست AJAX عمومی در حالت پیشنهادی API هم‌زمان صدا نمی‌زند', $api_before === count( TCAS_Api::$calls ) );

map_product( 13, 'REMOTE-13', new TCAS_Test_Product( 13, 'simple', 1, 'outofstock' ) );
$GLOBALS['meta'][13][ TCAS_Mapping::META_QTY ] = '4';
$GLOBALS['meta'][13][ TCAS_Mapping::META_STATUS ] = 'instock';
$GLOBALS['meta'][13][ TCAS_Mapping::META_FETCHED_AT ] = time() - TCAS_Live::CACHE_TTL - 30;
$scheduled_before = count( $GLOBALS['scheduled'] );
$old_quantity = TCAS_Live::filter_quantity( 1, $GLOBALS['products'][13] );
TCAS_Live::filter_status( 'outofstock', $GLOBALS['products'][13] );
live_test( 'Snapshot کهنه همان لحظه نمایش داده می‌شود و فقط یک Cron صف می‌گیرد', 4.0 === $old_quantity && $scheduled_before + 1 === count( $GLOBALS['scheduled'] ) && $api_before === count( TCAS_Api::$calls ) );

TCAS_Api::$responses['REMOTE-13'] = array( 'code' => 'REMOTE-13', 'quantity' => 4.0, 'status' => 'instock' );
$stale_time = (int) $GLOBALS['meta'][13][ TCAS_Mapping::META_FETCHED_AT ];
TCAS_Live::process_scheduled_refresh( 13 );
live_test( 'Cron پس‌زمینه موجودی را تازه می‌کند و قفل صف را آزاد می‌کند', count( TCAS_Api::$calls ) === $api_before + 1 && (int) $GLOBALS['meta'][13][ TCAS_Mapping::META_FETCHED_AT ] > $stale_time && false === get_transient( 'tcas_stock_refresh_13' ) );
live_test( 'پاسخ هم‌مقدار هم زمان Snapshot را تازه می‌کند', (int) $GLOBALS['meta'][13][ TCAS_Mapping::META_FETCHED_AT ] > $stale_time );

map_product( 12, 'REMOTE-12', new TCAS_Test_Product( 12, 'simple', 2, 'instock' ) );
$GLOBALS['meta'][12][ TCAS_Mapping::META_QTY ] = '2';
$GLOBALS['meta'][12][ TCAS_Mapping::META_STATUS ] = 'instock';
$GLOBALS['meta'][12][ TCAS_Mapping::META_FETCHED_AT ] = time() - TCAS_Live::CACHE_TTL - 30;
TCAS_Api::$responses['REMOTE-12'] = new WP_Error( 'timeout', 'remote timeout' );
TCAS_Live::filter_quantity( 2, $GLOBALS['products'][12] );
$scheduled_after_failure_queue = count( $GLOBALS['scheduled'] );
TCAS_Live::process_scheduled_refresh( 12 );
TCAS_Live::filter_quantity( 2, $GLOBALS['products'][12] );
live_test( 'خطای Cron کش قبلی را حفظ و تلاش مجدد را موقتاً محدود می‌کند', 'failed' === get_transient( 'tcas_stock_refresh_12' ) && $scheduled_after_failure_queue === count( $GLOBALS['scheduled'] ) && '2' === $GLOBALS['meta'][12][ TCAS_Mapping::META_QTY ] );

$GLOBALS['products'][40] = new TCAS_Test_Product( 40, 'variable', 0, 'instock' );
$groups = TCAS_Mapping::group_rows( array(
	array( 'id' => 41, 'parent_id' => 40, 'type' => 'variation', 'name' => 'مدل A', 'remote_code' => 'ID:900/901', 'remote_parent_id' => 900, 'menu_order' => 2 ),
	array( 'id' => 42, 'parent_id' => 40, 'type' => 'variation', 'name' => 'مدل B', 'remote_code' => 'ID:900/902', 'remote_parent_id' => 900, 'menu_order' => 1 ),
	array( 'id' => 43, 'parent_id' => 40, 'type' => 'variation', 'name' => 'مدل C', 'remote_code' => 'ID:901/903', 'remote_parent_id' => 901 ),
) );
live_test( 'اتصال‌های Variation بر اساس جفت والد محلی/ریموت در یک گروه قرار می‌گیرند', 2 === count( $groups ) && 2 === $groups[0]['count'] && 'محصول 40' === $groups[0]['local_parent_name'] && 900 === $groups[0]['remote_parent_id'] && array( 42, 41 ) === array_column( $groups[0]['rows'], 'id' ) );

$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['enabled'] = 'no';
live_test( 'خاموش‌کردن سینک State جدید نمی‌سازد', null === TCAS_Live::state( 10 ) );

// --- حالت flag: فقط موجود/ناموجود ---
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['enabled']     = 'yes';
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['stock_mode'] = 'flag';
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['fail_mode']  = 'open';
live_test( 'حالت پیش‌فرض نوشتن، فقط وضعیت است', 'flag' === TCAS_Stock::mode() );

$GLOBALS['products'][60] = new TCAS_Test_Product( 60, 'variation', 40, 'instock', 59 );
$GLOBALS['meta'][60][ TCAS_Mapping::META_QTY ] = '40';
$GLOBALS['meta'][60][ TCAS_Mapping::META_STATUS ] = 'instock';
$flag_applied = TCAS_Stock::apply( $GLOBALS['products'][60], array( 'quantity' => 0.0, 'status' => 'instock' ) );
live_test( 'حالت flag با موجودی صفر فقط Variation را ناموجود می‌کند', true === $flag_applied && 'outofstock' === $GLOBALS['products'][60]->get_stock_status() );
live_test( 'حالت flag انبار عددی و مدیریت انبار محلی را دست نمی‌زند', 40.0 === (float) $GLOBALS['products'][60]->get_stock_quantity() && false === $GLOBALS['products'][60]->get_manage_stock() && 1 === $GLOBALS['products'][60]->saved );
$flag_repeat = TCAS_Stock::apply( $GLOBALS['products'][60], array( 'quantity' => 0.0, 'status' => 'outofstock' ) );
live_test( 'تکرار همان وضعیت نوشتن تازه نمی‌سازد', false === $flag_repeat && 1 === $GLOBALS['products'][60]->saved );
$GLOBALS['products'][60]->set_stock_status( 'outofstock' );
TCAS_Stock::apply( $GLOBALS['products'][60], array( 'quantity' => 12.0, 'status' => 'instock' ) );
live_test( 'بازگشت موجودی همکار وضعیت را دوباره موجود می‌کند', 'instock' === $GLOBALS['products'][60]->get_stock_status() && 40.0 === (float) $GLOBALS['products'][60]->get_stock_quantity() );

$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['stock_mode'] = 'quantity';
$GLOBALS['products'][61] = new TCAS_Test_Product( 61, 'simple', 40, 'instock' );
TCAS_Stock::apply( $GLOBALS['products'][61], array( 'quantity' => 3.0, 'status' => 'instock' ) );
live_test( 'حالت quantity عدد دقیق همکار را می‌نویسد', 3.0 === (float) $GLOBALS['products'][61]->get_stock_quantity() && true === $GLOBALS['products'][61]->get_manage_stock() );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['stock_mode'] = 'flag';

// --- سیاست «همکار در دسترس نیست» ---
map_product( 60, 'REMOTE-60', $GLOBALS['products'][60] );
TCAS_Api::$responses['REMOTE-60'] = array( 'code' => 'REMOTE-60', 'quantity' => 5.0, 'status' => 'instock' );
TCAS_Live::state( 60, true );
TCAS_Api::$responses['REMOTE-60'] = new WP_Error( 'timeout', 'timeout' );
TCAS_Live::state( 60, true );
$GLOBALS['notices'] = array();
$open_add = TCAS_Live::validate_add_to_cart( true, 60, 1 );
live_test( 'در fail-open با Snapshot موجود فروش ادامه می‌یابد و هیچ پیامی به مشتری نشان داده نمی‌شود', true === $open_add && empty( $GLOBALS['notices'] ) );

map_product( 63, 'REMOTE-63', new TCAS_Test_Product( 63, 'simple', 3, 'instock' ) );
TCAS_Api::$responses['REMOTE-63'] = new WP_Error( 'timeout', 'timeout' );
TCAS_Live::state( 63, true );
live_test( 'بدون هیچ Snapshot، قطعی همکار فروش را نمی‌بندد (fail-open)', true === TCAS_Live::validate_add_to_cart( true, 63, 1 ) );

$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['fail_mode'] = 'closed';
$GLOBALS['notices'] = array();
$closed_add = TCAS_Live::validate_add_to_cart( true, 60, 1 );
live_test( 'در حالت بسته، قطعی همکار افزودن به سبد را متوقف می‌کند', false === $closed_add && 'error' === $GLOBALS['notices'][0][1] );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['fail_mode'] = 'open';

// --- متن‌های مشتری: هیچ اشاره‌ای به نام همکار ---
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['fail_mode'] = 'closed';
map_product( 64, 'REMOTE-64', new TCAS_Test_Product( 64, 'simple', 0, 'outofstock' ) );
TCAS_Api::$responses['REMOTE-64'] = array( 'code' => 'REMOTE-64', 'quantity' => 0.0, 'status' => 'outofstock' );
TCAS_Live::state( 64, true );
$GLOBALS['notices'] = array();
$oos = TCAS_Live::validate_add_to_cart( true, 64, 1 );
live_test( 'پیام ناموجودی مشتری نام فروشگاه تأمین‌کننده را نمی‌برد', false === $oos && false !== strpos( $GLOBALS['notices'][0][0], 'ناموجود' ) && false === stripos( $GLOBALS['notices'][0][0], 'ariocase' ) );

map_product( 65, 'REMOTE-65', new TCAS_Test_Product( 65, 'simple', 3, 'instock' ) );
TCAS_Api::$responses['REMOTE-65'] = new WP_Error( 'timeout', 'timeout' );
TCAS_Live::state( 65, true );
$GLOBALS['notices'] = array();
$closed_error = TCAS_Live::validate_add_to_cart( true, 65, 1 );
live_test( 'پیام خطای بررسی موجودی هم نام همکار را لو نمی‌دهد', false === $closed_error && false === stripos( $GLOBALS['notices'][0][0], 'ariocase' ) && false !== strpos( $GLOBALS['notices'][0][0], 'قابل بررسی نیست' ) );
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['fail_mode'] = 'open';

map_product( 62, 'REMOTE-62', new TCAS_Test_Product( 62, 'simple', 3, 'instock' ) );
$GLOBALS['meta'][62][ TCAS_Mapping::META_STATUS ] = 'outofstock';
$GLOBALS['meta'][62][ TCAS_Mapping::META_QTY ] = '0';
$GLOBALS['meta'][62][ TCAS_Mapping::META_FETCHED_AT ] = time();
TCAS_Api::$responses['REMOTE-62'] = array( 'code' => 'REMOTE-62', 'quantity' => 9.0, 'status' => 'instock' );
TCAS_Sweeper::$backoff = array( 'until' => time() + 600 );
$calls_before_backoff = count( TCAS_Api::$calls );
$backoff_result = TCAS_Live::validate_add_to_cart( true, 62, 1 );
live_test( 'در بازهٔ Backoff کنترل خرید از Snapshot می‌خواند و API را صدا نمی‌زند', false === $backoff_result && $calls_before_backoff === count( TCAS_Api::$calls ) );
TCAS_Sweeper::$backoff = array();

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
