<?php
/** قرارداد Jobهای AJAX و تازه‌سازی داده پیش از Import. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error { private $message; public function __construct( $code = '', $message = '' ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
class TCAS_JSON_Response extends Exception { public $success; public $data; public $status; public function __construct( $success, $data, $status ) { $this->success = $success; $this->data = $data; $this->status = $status; parent::__construct( 'json' ); } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'tcas_admin_nonce' === $action; }
function wp_send_json_success( $data ) { throw new TCAS_JSON_Response( true, $data, 200 ); }
function wp_send_json_error( $data, $status = 400 ) { throw new TCAS_JSON_Response( false, $data, $status ); }
function get_transient( $key ) { return isset( $GLOBALS['transients'][ $key ] ) ? $GLOBALS['transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][ $key ] = $value; return true; }
function get_current_user_id() { return 7; }
function admin_url( $path = '' ) { return 'https://tisa.test/wp-admin/' . $path; }
class TCAS_Core { const NONCE = 'tcas_admin_nonce'; public static function can() { return true; } }
class TCAS_Logger { public static function add( ...$args ) {} }
class TCAS_Importer {
	public static $preview_calls = 0;
	public static $received_preview = null;
	public static function preview( $code ) { self::$preview_calls++; return array( 'row' => array( 'selectable' => true ), 'fresh' => 'fresh-' . self::$preview_calls ); }
	public static function import( $preview, $options ) { self::$received_preview = $preview; return array( 'message' => 'done', 'product_id' => 91, 'warnings' => array() ); }
}
class TCAS_Mapping { public static function is_enabled( $id ) { return true; } public static function remote_code( $id ) { return 'FRESH'; } }
class TCAS_Live { public static $state = null; public static function refresh( $id ) { return self::$state; } }

require_once dirname( __DIR__ ) . '/includes/class-tcas-ajax.php';
$pass = 0; $fail = 0;
function ajax_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function invoke_ajax( $method ) { try { TCAS_Ajax::$method(); return null; } catch ( TCAS_JSON_Response $response ) { return $response; } }
$token = str_repeat( 'a', 32 );
$_REQUEST['nonce'] = 'valid';
$_POST['token'] = $token;
$GLOBALS['transients'][ 'tcas_job_' . $token ] = array(
	'user_id' => 7, 'mode' => 'import', 'import_codes' => array( 'FRESH' ), 'offset' => 0,
	'options' => array( 'prices' => true, 'images' => false ),
	'results' => array( 'FRESH' => array( 'fresh' => 'old-preview' ) ),
);
$response = invoke_ajax( 'import_step' );
ajax_test( 'Import مرحلهٔ نهایی دوباره Preview تازه می‌گیرد', 1 === TCAS_Importer::$preview_calls );
ajax_test( 'Snapshot کهنه به Import داده نمی‌شود', is_array( TCAS_Importer::$received_preview ) && 'fresh-1' === TCAS_Importer::$received_preview['fresh'] );
ajax_test( 'پاسخ و Offset عملیات کامل می‌شوند', $response && $response->success && $response->data['done'] && 1 === $GLOBALS['transients'][ 'tcas_job_' . $token ]['offset'] );

TCAS_Importer::$preview_calls = 0;
$GLOBALS['transients'][ 'tcas_job_' . $token ]['user_id'] = 8;
$response = invoke_ajax( 'import_step' );
ajax_test( 'Job کاربر دیگر با 409 رد می‌شود', $response && ! $response->success && 409 === $response->status );
ajax_test( 'Job نامعتبر هیچ Import یا APIای اجرا نمی‌کند', 0 === TCAS_Importer::$preview_calls );

$_POST['product_id'] = 91;
TCAS_Live::$state = null;
$response = invoke_ajax( 'sync_mapping' );
ajax_test( 'State ناموجود در Sync دستی موفقیت کاذب ندارد', $response && ! $response->success && 409 === $response->status );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
