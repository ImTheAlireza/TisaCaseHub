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
	private $sku; private $name; private $type; private $children; private $attributes;
	public function __construct( $sku, $name = 'Product', $type = 'simple', $children = array(), $attributes = array() ) { $this->sku = $sku; $this->name = $name; $this->type = $type; $this->children = $children; $this->attributes = $attributes; }
	public function get_sku() { return $this->sku; }
	public function get_name() { return $this->name; }
	public function get_type() { return $this->type; }
	public function get_children() { return $this->children; }
	public function get_attributes() { return $this->attributes; }
}
class TCAS_Core { public static function settings() { return array( 'base_url' => 'https://ariocase.com/wp-json/wc/v3' ); } }
class TCAS_Api {
	public static $responses = array(); public static $calls = array();
	public static function fetch_reference( $url ) { self::$calls[] = $url; return isset( self::$responses[ $url ] ) ? self::$responses[ $url ] : new WP_Error( 'not_found', 'Not found' ); }
}
class TCAS_Mapping {
	public static function local_by_sku( $sku ) {
		$ids = isset( $GLOBALS['sku_ids'][ $sku ] ) ? $GLOBALS['sku_ids'][ $sku ] : array();
		if ( count( $ids ) > 1 ) return new WP_Error( 'tcas_duplicate_sku', 'Duplicate SKU' );
		return $ids ? (int) $ids[0] : 0;
	}
}
require_once dirname( __DIR__ ) . '/includes/class-tcas-link-resolver.php';
$pass = 0; $fail = 0;
function resolver_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function reset_resolver_fixtures() {
	$GLOBALS['products'] = array( 41 => new TCAS_Test_Product( 'TS0100', 'Local case' ), 42 => new TCAS_Test_Product( '', 'No SKU' ) );
	$GLOBALS['slugs'] = array( 'local-case' => 41 );
	$GLOBALS['pretty'] = array( 'https://tisacase.com/product/local-case/' => 41 );
	$GLOBALS['url_calls'] = array(); $GLOBALS['slug_calls'] = array(); $GLOBALS['sku_ids'] = array( 'TS0100' => array( 41 ) ); TCAS_Api::$calls = array(); TCAS_Api::$responses = array();
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
$local_variable_url = 'https://tisacase.com/product/local-variable/';
$GLOBALS['products'][43] = new TCAS_Test_Product( 'TS-PARENT', 'Local variable', 'variable', array( 44, 45 ) );
$GLOBALS['products'][44] = new TCAS_Test_Product( 'TS-RED', 'Local variable - Red', 'variation', array(), array( 'attribute_color' => 'قرمز' ) );
$GLOBALS['products'][45] = new TCAS_Test_Product( '', 'Local variable - Blue', 'variation', array(), array( 'attribute_color' => 'آبی' ) );
$GLOBALS['pretty'][ $local_variable_url ] = 43;
$local_variable = TCAS_Link_Resolver::resolve( $local_variable_url );
resolver_test( 'لینک والد تیساکیس، Variationها را با ID یکتا می‌آورد', is_array( $local_variable ) && 'variable' === $local_variable['type'] && 2 === count( $local_variable['variations'] ) && 'ID:44' === $local_variable['variations'][0]['reference'] && 'قرمز' === $local_variable['variations'][0]['label'] && 'ID:45' === $local_variable['variations'][1]['reference'] );
$local_by_id = TCAS_Link_Resolver::resolve_local_reference( 'ID:43' );
resolver_test( 'ID والد Local برای تطبیق هوشمند Resolve می‌شود', is_array( $local_by_id ) && 'variable' === $local_by_id['type'] && 2 === count( $local_by_id['variations'] ) );
$GLOBALS['sku_ids']['TS-PARENT'] = array( 43 );
$local_by_sku = TCAS_Link_Resolver::resolve_local_reference( 'TS-PARENT' );
resolver_test( 'SKU والد Local برای تطبیق هوشمند Resolve می‌شود', is_array( $local_by_sku ) && 'variable' === $local_by_sku['type'] && 43 === $local_by_sku['product_id'] );
$GLOBALS['sku_ids']['DUP-PARENT'] = array( 43, 43 );
$duplicate_parent = TCAS_Link_Resolver::resolve_local_reference( 'DUP-PARENT' );
resolver_test( 'SKU مشترک والد برای تطبیق خودکار رد می‌شود', is_wp_error( $duplicate_parent ) && 'tcas_duplicate_sku' === $duplicate_parent->get_error_code() );

reset_resolver_fixtures();
$local_duplicate_url = 'https://tisacase.com/product/local-duplicate-skus/';
$GLOBALS['products'][46] = new TCAS_Test_Product( 'SB193', 'Shared SKU parent', 'variable', array( 47, 48 ) );
$GLOBALS['products'][47] = new TCAS_Test_Product( 'SB193', 'Shared SKU - iPhone 13', 'variation', array(), array( 'attribute_model' => 'iPhone 13' ) );
$GLOBALS['products'][48] = new TCAS_Test_Product( 'SB193', 'Shared SKU - iPhone 13 Pro', 'variation', array(), array( 'attribute_model' => 'iPhone 13 Pro' ) );
$GLOBALS['pretty'][ $local_duplicate_url ] = 46;
$local_duplicates = TCAS_Link_Resolver::resolve( $local_duplicate_url );
resolver_test( 'SKU مشترک والد و Variationها به مرجع ID متفاوت تبدیل می‌شود', is_array( $local_duplicates ) && 'ID:47' === $local_duplicates['variations'][0]['reference'] && 'ID:48' === $local_duplicates['variations'][1]['reference'] && 'SB193' === $local_duplicates['variations'][0]['sku'] && 'SB193' === $local_duplicates['variations'][1]['sku'] );

reset_resolver_fixtures();
$no_sku = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=42' );
resolver_test( 'محصول تیساکیس بدون SKU برای کپی رد می‌شود', is_wp_error( $no_sku ) && 'tcas_local_sku_missing' === $no_sku->get_error_code() );
$missing = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=999' );
resolver_test( 'لینک غیرمحصول تیساکیس شناسهٔ جعلی نمی‌سازد', is_wp_error( $missing ) && 'tcas_local_product_missing' === $missing->get_error_code() );

reset_resolver_fixtures();
$remote_url = 'https://ariocase.com/product/remote-case/';
TCAS_Api::$responses[ $remote_url ] = remote_result();
$remote = TCAS_Link_Resolver::resolve( $remote_url );
resolver_test( 'لینک ArioCase از همان API قبلی به SKU تبدیل می‌شود', is_array( $remote ) && 'ariocase' === $remote['shop'] && 'AR22' === $remote['sku'] && 'AR22' === $remote['reference'] && 222 === $remote['product_id'] && array( $remote_url ) === TCAS_Api::$calls );

reset_resolver_fixtures();
$remote_variable_url = 'https://ariocase.com/product/remote-variable/';
$remote_variable_response = remote_result( '' );
$remote_variable_response['type'] = 'variable';
$remote_variable_response['variations'] = array(
	array( 'remote_id' => 223, 'parent_remote_id' => 222, 'code' => '', 'name' => '', 'variation_attributes' => array( 'Color' => 'مشکی' ) ),
	array( 'remote_id' => 224, 'parent_remote_id' => 222, 'code' => 'AR-BLUE', 'name' => 'Blue', 'variation_attributes' => array( 'Color' => 'آبی' ) ),
);
TCAS_Api::$responses[ $remote_variable_url ] = $remote_variable_response;
$remote_variable = TCAS_Link_Resolver::resolve( $remote_variable_url );
resolver_test( 'Variation آریوکیس بدون SKU مرجع والد/تنوع می‌گیرد', is_array( $remote_variable ) && 'ID:222/223' === $remote_variable['variations'][0]['reference'] && 223 === $remote_variable['variations'][0]['product_id'] && 222 === $remote_variable['variations'][0]['parent_id'] && 'مشکی' === $remote_variable['variations'][0]['label'] );
resolver_test( 'Variation آریوکیس دارای SKU با همان SKU و ID نمایش داده می‌شود', 'AR-BLUE' === $remote_variable['variations'][1]['reference'] && 224 === $remote_variable['variations'][1]['product_id'] );

reset_resolver_fixtures();
$id_only_url = 'https://ariocase.com/product/id-only-case/';
TCAS_Api::$responses[ $id_only_url ] = remote_result( '' );
$id_only = TCAS_Link_Resolver::resolve( $id_only_url );
resolver_test( 'محصول ArioCase بدون SKU مرجع ID قابل‌کپی می‌گیرد', is_array( $id_only ) && '' === $id_only['sku'] && 'ID:222' === $id_only['reference'] && 222 === $id_only['product_id'] );
reset_resolver_fixtures();
$identityless_url = 'https://ariocase.com/product/identityless-case/';
$identityless_response = remote_result( '' ); $identityless_response['remote_id'] = 0;
TCAS_Api::$responses[ $identityless_url ] = $identityless_response;
$identityless = TCAS_Link_Resolver::resolve( $identityless_url );
resolver_test( 'بدون SKU و ID خروجی کپی ساخته نمی‌شود', is_wp_error( $identityless ) && 'tcas_remote_id_missing' === $identityless->get_error_code() );

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
