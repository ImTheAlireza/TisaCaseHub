<?php
/** مسیر REST پشتیبان: فهرست actionهای مجاز و ردکردن action ناشناس. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = array() ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) ); }
function add_action( $hook, $callback ) { $GLOBALS['tcas_rest_hooks'][ $hook ][] = $callback; return true; }
class TCAS_Core { const NONCE = 'tcas_admin_nonce'; public static function can() { return true; } }
class TCAS_Ajax {
	public static $called = '';
	public static function preview_step() { self::$called = 'preview_step:' . ( isset( $_POST['from'] ) ? $_POST['from'] : '' ); }
	public static function resolve_link() { self::$called = 'resolve_link:' . ( isset( $_REQUEST['nonce'] ) ? $_REQUEST['nonce'] : '' ); }
}
class TCAS_Rest_Request {
	private $params; private $headers;
	public function __construct( $params, $headers = array() ) { $this->params = $params; $this->headers = $headers; }
	public function get_params() { return $this->params; }
	public function get_header( $name ) { return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : ''; }
	public function offsetGet( $key ) { return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null; }
}
class TCAS_Rest_Request_ArrayAccess extends TCAS_Rest_Request implements ArrayAccess {
	public function offsetExists( $o ): bool { return isset( $this->get_params()[ $o ] ); }
	public function offsetGet( $o ): mixed { return parent::offsetGet( $o ); }
	public function offsetSet( $o, $v ): void {}
	public function offsetUnset( $o ): void {}
}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['tcas_rest_route'] = array( $ns, $route, $args ); return true; }
require_once dirname( __DIR__ ) . '/includes/class-tcas-rest.php';
$pass = 0; $fail = 0;
function rest_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }

TCAS_Rest::init();
rest_test( 'مسیر REST روی rest_api_init ثبت می‌شود', ! empty( $GLOBALS['tcas_rest_hooks']['rest_api_init'] ) );
TCAS_Rest::routes();
$route = $GLOBALS['tcas_rest_route'];
rest_test( 'فضای نام و الگوی مسیر امن است', 'tcas/v1' === $route[0] && '/do/(?P<action>[a-z_]+)' === $route[1] );
rest_test( 'فقط POST و با بررسی دسترسی ثبت می‌شود', 'POST' === $route[2]['methods'] && is_callable( $route[2]['permission_callback'] ) );
rest_test( 'دسترسی، همان کنترل صفحهٔ افزونه است', true === call_user_func( $route[2]['permission_callback'] ) );

$allowed = TCAS_Rest::allowed();
rest_test( 'همهٔ actionهای لازم از مسیر REST در دسترس‌اند', ! array_diff( array( 'preview_start', 'preview_step', 'connect_start', 'connect_step', 'resolve_link', 'search_products', 'match_variations', 'preview_pair', 'test_connection' ), $allowed ) );
rest_test( 'action غیرمجاز وارد فهرست نمی‌شود', ! in_array( 'delete_everything', $allowed, true ) );

$response = TCAS_Rest::dispatch( new TCAS_Rest_Request_ArrayAccess( array( 'action' => 'delete_everything' ) ) );
rest_test( 'action ناشناس با خطای ۴۰۴ رد می‌شود', $response instanceof WP_Error && 404 === $response->get_error_data()['status'] );

$_POST = array(); $_REQUEST = array();
$response = TCAS_Rest::dispatch( new TCAS_Rest_Request_ArrayAccess( array( 'action' => 'preview_step', 'from' => '6', 'size' => '3', 'nonce' => 'abc' ) ) );
rest_test( 'پارامترهای REST در $_POST قرار می‌گیرند و handler اجرا می‌شود', null === $response && 'preview_step:6' === TCAS_Ajax::$called );
rest_test( 'nonce هم برای همان بررسی امنیتی AJAX منتقل می‌شود', 'abc' === $_REQUEST['nonce'] );

$_POST = array(); $_REQUEST = array(); TCAS_Ajax::$called = '';
$response = TCAS_Rest::dispatch( new TCAS_Rest_Request_ArrayAccess( array( 'action' => 'resolve_link' ), array( 'x_tcas_nonce' => 'from-header' ) ) );
rest_test( 'nonce از هدر X-TCAS-Nonce هم پذیرفته می‌شود', null === $response && 'resolve_link:from-header' === TCAS_Ajax::$called );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
