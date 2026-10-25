<?php
/** قرارداد Jobهای AJAX و تازه‌سازی داده پیش از اتصال جفتی. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error { private $message; public function __construct( $code = '', $message = '' ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
class TCAS_JSON_Response extends Exception { public $success; public $data; public $status; public function __construct( $success, $data, $status ) { $this->success = $success; $this->data = $data; $this->status = $status; parent::__construct( 'json' ); } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); } function wp_unslash( $value ) { return $value; } function absint( $value ) { return abs( (int) $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'tcas_admin_nonce' === $action; }
function wp_send_json_success( $data ) { throw new TCAS_JSON_Response( true, $data, 200 ); } function wp_send_json_error( $data, $status = 400 ) { throw new TCAS_JSON_Response( false, $data, $status ); }
function get_transient( $key ) { return isset( $GLOBALS['transients'][ $key ] ) ? $GLOBALS['transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_current_user_id() { return 7; } function admin_url( $path = '' ) { return 'https://tisa.test/wp-admin/' . $path; }
class TCAS_Core { const NONCE = 'tcas_admin_nonce'; public static function can() { return true; } public static function is_configured() { return true; } }
class TCAS_Logger { public static function add( ...$args ) {} }
class TCAS_Pairing {
	public static $preview_calls = 0; public static $received_preview = null;
	public static function preview( $pair ) { self::$preview_calls++; return array( 'row' => array( 'selectable' => true, 'status' => 'connect', 'local_code' => $pair['local_code'], 'remote_code' => 'RESOLVED-22' ), 'fresh' => 'fresh-' . self::$preview_calls ); }
	public static function connect( $preview ) { self::$received_preview = $preview; return array( 'message' => 'done', 'product_id' => 91 ); }
}
class TCAS_Link_Resolver {
	public static $product = null; public static $last_url = '';
	public static function resolve( $url ) { self::$last_url = $url; return self::$product ? self::$product : array( 'shop' => 'tisacase', 'shop_label' => 'TisaCase', 'sku' => 'TS0100', 'name' => 'Local case', 'product_id' => 41, 'type' => 'simple', 'reference' => 'TS0100' ); }
	public static function resolve_local_reference( $reference ) { return self::resolve( $reference ); }
	public static function resolve_remote_reference( $reference ) { return TCAS_Api::fetch_reference( $reference ); }
}
class TCAS_Api {
	public static $product = null; public static $reference = null;
	public static function fetch_reference( $reference ) { self::$reference = $reference; return self::$product; }
}
class TCAS_Mapping { public static function is_enabled( $id ) { return true; } public static function remote_code( $id ) { return 'AR22'; } }
class TCAS_Live { public static $state = null; public static function refresh( $id ) { return self::$state; } }
require_once dirname( __DIR__ ) . '/includes/class-tcas-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-ajax.php';
$pass = 0; $fail = 0;
function ajax_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function invoke_ajax( $method ) { try { TCAS_Ajax::$method(); return null; } catch ( TCAS_JSON_Response $response ) { return $response; } }
$token = str_repeat( 'a', 32 ); $_REQUEST['nonce'] = 'valid'; $_POST['token'] = $token;
$pair = array( 'local_code' => 'AR120', 'remote_code' => 'https://ariocase.com/product/case-slug/', 'error' => '' );
$GLOBALS['transients'][ 'tcas_job_' . $token ] = array(
	'user_id' => 7, 'mode' => 'connect', 'pairs' => array( $pair ), 'connect_indexes' => array( 0 ), 'offset' => 0,
	'results' => array( array( 'fresh' => 'old-preview' ) ),
);
$response = invoke_ajax( 'connect_step' );
ajax_test( 'اتصال نهایی دوباره Preview تازه می‌گیرد', 1 === TCAS_Pairing::$preview_calls );
ajax_test( 'Snapshot کهنه به Connect داده نمی‌شود', is_array( TCAS_Pairing::$received_preview ) && 'fresh-1' === TCAS_Pairing::$received_preview['fresh'] );
ajax_test( 'پاسخ و Offset عملیات کامل می‌شوند', $response && $response->success && $response->data['done'] && 1 === $GLOBALS['transients'][ 'tcas_job_' . $token ]['offset'] );
ajax_test( 'نتیجهٔ اتصال SKU کشفشده را جای لینک نشان می‌دهد', $response && 'RESOLVED-22' === $response->data['row']['remote_code'] );
TCAS_Pairing::$preview_calls = 0; $GLOBALS['transients'][ 'tcas_job_' . $token ]['user_id'] = 8;
$response = invoke_ajax( 'connect_step' );
ajax_test( 'Job کاربر دیگر با 409 رد می‌شود', $response && ! $response->success && 409 === $response->status );
ajax_test( 'Job نامعتبر هیچ اتصال یا APIای اجرا نمی‌کند', 0 === TCAS_Pairing::$preview_calls );
$_POST['url'] = 'https://tisacase.com/product/local-case/';
$response = invoke_ajax( 'resolve_link' );
ajax_test( 'AJAX تبدیل لینک SKU و فروشگاه را برمی‌گرداند', $response && $response->success && 'TS0100' === $response->data['sku'] && 'tisacase' === $response->data['shop'] );
$_POST['url'] = 'https://tisacase.com/product/%d9%82%d8%a7%d8%a8-%d9%82%d9%84%d8%a8-%d9%86%da%86%d8%b1%d8%a7%d9%84/';
$response = invoke_ajax( 'resolve_link' );
ajax_test( 'AJAX حروف %XX نامک فارسی را بدون sanitize_text_field تا Resolver حفظ می‌کند', $response && $response->success && TCAS_Link_Resolver::$last_url === $_POST['url'] );
unset( $_POST['url'] );
$_POST['local_code'] = 'ID:50'; $_POST['remote_code'] = 'ID:70';
TCAS_Link_Resolver::$product = array(
	'shop' => 'tisacase', 'name' => 'Local variable', 'type' => 'variable', 'reference' => 'ID:50',
	'variations' => array(
		array( 'reference' => 'ID:51', 'label' => 'Samsung Galaxy S25 Ultra', 'sku' => 'SHARED' ),
		array( 'reference' => 'ID:52', 'label' => 'S24', 'sku' => 'SHARED' ),
	),
);
TCAS_Api::$product = array(
	'shop' => 'ariocase', 'name' => 'Remote variable', 'type' => 'variable', 'reference' => 'ID:70',
	'variations' => array(
		array( 'reference' => 'REMOTE-SKU', 'product_id' => 71, 'parent_id' => 70, 'label' => 's25ultra', 'sku' => 'SHARED' ),
		array( 'reference' => 'ID:70/72', 'label' => 'S23', 'sku' => 'SHARED' ),
	),
);
$response = invoke_ajax( 'match_variations' );
ajax_test( 'AJAX تطبیق، مرجع دقیق Variationهای معادل را برمی‌گرداند', $response && $response->success && 1 === count( $response->data['matches'] ) && 'ID:51' === $response->data['matches'][0]['local_code'] && 'ID:70/71' === $response->data['matches'][0]['remote_code'] && 'ID:70' === TCAS_Api::$reference );
ajax_test( 'تطبیق AJAX مدل نامرتبط را وارد جفت‌ها نمی‌کند', $response && 2 === count( $response->data['ambiguous'] ) + count( $response->data['unmatched'] ) );
TCAS_Link_Resolver::$product = null; TCAS_Api::$product = null; TCAS_Api::$reference = null;
$response = invoke_ajax( 'match_variations' );
ajax_test( 'تطبیق فقط والد متغیر می‌پذیرد و برای Simple ریموت را نمی‌خواند', $response && ! $response->success && 422 === $response->status && null === TCAS_Api::$reference );
unset( $_POST['local_code'], $_POST['remote_code'] );
$_POST['code'] = 'ID:658';
TCAS_Api::$product = array( 'code' => '', 'remote_id' => 658, 'name' => 'ID only', 'type' => 'simple', 'stock' => array( 'quantity' => 5.0, 'status' => 'instock' ), 'variations' => array() );
$response = invoke_ajax( 'test_connection' );
ajax_test( 'تست اتصال برای محصول بدون SKU، مرجع و Product ID را برمی‌گرداند', $response && $response->success && 'ID:658' === TCAS_Api::$reference && 'ID:658' === $response->data['product']['reference'] && 658 === $response->data['product']['product_id'] );
unset( $_POST['code'] );
$_POST['product_id'] = 91; TCAS_Live::$state = null;
$response = invoke_ajax( 'sync_mapping' );
ajax_test( 'State ناموجود در Sync دستی موفقیت کاذب ندارد', $response && ! $response->success && 409 === $response->status );
echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
