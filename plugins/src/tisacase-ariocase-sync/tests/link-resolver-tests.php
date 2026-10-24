<?php
/** تشخیص لینک محصول هر دو فروشگاه و تبدیل آن به SKU واقعی. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://tisacase.com' . $path; }
function site_url( $path = '' ) { return 'https://tisacase.com' . $path; }
function get_page_by_path( $slug, $output, $post_type ) {
	$GLOBALS['slug_calls'][] = array( $slug, $post_type );
	if ( 'product' !== $post_type || empty( $GLOBALS['slugs'][ $slug ] ) ) return null;
	return (object) array( 'ID' => $GLOBALS['slugs'][ $slug ] );
}
function url_to_postid( $url ) { $GLOBALS['url_calls'][] = $url; return isset( $GLOBALS['pretty'][ $url ] ) ? $GLOBALS['pretty'][ $url ] : 0; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : false; }
class TCAS_Test_Product {
	private $sku; private $name; private $type;
	public function __construct( $sku, $name = 'Product', $type = 'simple' ) { $this->sku = $sku; $this->name = $name; $this->type = $type; }
	public function get_sku() { return $this->sku; }
	public function get_name() { return $this->name; }
	public function get_type() { return $this->type; }
}
class TCAS_Core { public static function settings() { return array( 'base_url' => 'https://ariocase.com/wp-json/wc/v3' ); } }
class TCAS_Api {
	public static $responses = array(); public static $calls = array();
	public static function fetch_reference( $url ) { self::$calls[] = $url; return isset( self::$responses[ $url ] ) ? self::$responses[ $url ] : new WP_Error( 'not_found', 'Not found' ); }
}
require_once dirname( __DIR__ ) . '/includes/class-tcas-link-resolver.php';
$pass = 0; $fail = 0;
function resolver_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function reset_resolver_fixtures() {
	$GLOBALS['products'] = array( 41 => new TCAS_Test_Product( 'TS0100', 'Local case' ), 42 => new TCAS_Test_Product( '', 'No SKU' ) );
	$GLOBALS['slugs'] = array( 'local-case' => 41 );
	$GLOBALS['pretty'] = array( 'https://tisacase.com/product/local-case/' => 41 );
	$GLOBALS['url_calls'] = array(); $GLOBALS['slug_calls'] = array(); TCAS_Api::$calls = array(); TCAS_Api::$responses = array();
}
function remote_result( $code = 'AR22' ) { return array( 'code' => $code, 'name' => 'Remote case', 'remote_id' => 222, 'type' => 'simple' ); }

reset_resolver_fixtures();
$local = TCAS_Link_Resolver::resolve( 'https://www.tisacase.com/product/local-case/' );
resolver_test( 'لینک Pretty تیساکیس با www به SKU محلی تبدیل می‌شود', is_array( $local ) && 'tisacase' === $local['shop'] && 'TS0100' === $local['sku'] && 41 === $local['product_id'] );
resolver_test( 'Rewrite محلی با Host canonical و بدون Fetch خارجی اجرا می‌شود', array( 'https://tisacase.com/product/local-case/' ) === $GLOBALS['url_calls'] && 0 === count( TCAS_Api::$calls ) );

reset_resolver_fixtures();
$short = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=41' );
resolver_test( 'لینک کوتاه تیساکیس مستقیم با Product ID خوانده می‌شود', is_array( $short ) && 'TS0100' === $short['sku'] && 0 === count( $GLOBALS['url_calls'] ) );
$plain = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?product=local-case' );
resolver_test( 'Plain Permalink تیساکیس با نامک محصول Resolve می‌شود', is_array( $plain ) && 'TS0100' === $plain['sku'] && array( array( 'local-case', 'product' ) ) === $GLOBALS['slug_calls'] );

reset_resolver_fixtures();
$no_sku = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=42' );
resolver_test( 'محصول تیساکیس بدون SKU برای کپی رد می‌شود', is_wp_error( $no_sku ) && 'tcas_local_sku_missing' === $no_sku->get_error_code() );
$missing = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=999' );
resolver_test( 'لینک غیرمحصول تیساکیس شناسهٔ جعلی نمی‌سازد', is_wp_error( $missing ) && 'tcas_local_product_missing' === $missing->get_error_code() );

reset_resolver_fixtures();
$remote_url = 'https://ariocase.com/product/remote-case/';
TCAS_Api::$responses[ $remote_url ] = remote_result();
$remote = TCAS_Link_Resolver::resolve( $remote_url );
resolver_test( 'لینک ArioCase از همان API قبلی به SKU تبدیل می‌شود', is_array( $remote ) && 'ariocase' === $remote['shop'] && 'AR22' === $remote['sku'] && 222 === $remote['product_id'] && array( $remote_url ) === TCAS_Api::$calls );

reset_resolver_fixtures();
$before = count( TCAS_Api::$calls );
$unknown = TCAS_Link_Resolver::resolve( 'https://example.com/product/case/' );
resolver_test( 'دامنهٔ ناشناس بدون API یا Rewrite رد می‌شود', is_wp_error( $unknown ) && 'tcas_unknown_shop' === $unknown->get_error_code() && $before === count( TCAS_Api::$calls ) && 0 === count( $GLOBALS['url_calls'] ) );
$insecure = TCAS_Link_Resolver::resolve( 'http://tisacase.com/product/local-case/' );
resolver_test( 'HTTP در حالت عادی برای هر دو فروشگاه رد می‌شود', is_wp_error( $insecure ) && 'tcas_insecure_link' === $insecure->get_error_code() && 0 === count( $GLOBALS['url_calls'] ) );
$with_password = TCAS_Link_Resolver::resolve( 'https://user:secret@tisacase.com/product/local-case/' );
resolver_test( 'User-info در لینک پذیرفته نمی‌شود', is_wp_error( $with_password ) && 'tcas_insecure_link' === $with_password->get_error_code() );

reset_resolver_fixtures();
$api_error_url = 'https://ariocase.com/product/missing/';
TCAS_Api::$responses[ $api_error_url ] = new WP_Error( 'api_failed', 'API failed' );
$api_error = TCAS_Link_Resolver::resolve( $api_error_url );
resolver_test( 'خطای API در تبدیل‌کننده به SKU خالی تبدیل نمی‌شود', is_wp_error( $api_error ) && 'api_failed' === $api_error->get_error_code() );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
