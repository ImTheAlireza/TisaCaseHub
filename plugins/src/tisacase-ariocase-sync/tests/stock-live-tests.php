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
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $default; }
function add_option( ...$args ) { return true; }
function update_option( ...$args ) { return true; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
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

require_once dirname( __DIR__ ) . '/includes/class-tcas-core.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-mapping.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-stock.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-live.php';

$pass = 0; $fail = 0;
function live_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function map_product( $id, $code, $product = null ) {
	$GLOBALS['products'][ $id ] = $product ?: new TCAS_Test_Product( $id );
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_CODE ] = $code;
	$GLOBALS['meta'][ $id ][ TCAS_Mapping::META_ENABLED ] = 'yes';
}
$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ] = array_merge( TCAS_Core::defaults(), array( 'base_url' => 'https://ariocase.test/api', 'api_key' => 'secret' ) );
$GLOBALS['wc'] = (object) array( 'cart' => new TCAS_Test_Cart() );

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

$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ]['enabled'] = 'no';
live_test( 'خاموش‌کردن سینک State جدید نمی‌سازد', null === TCAS_Live::state( 10 ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
