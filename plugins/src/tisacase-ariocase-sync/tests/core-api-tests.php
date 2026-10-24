<?php
/** آزمون تنظیمات، URL و HTTP Client با پاسخ‌های Mock. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'TCAS_VERSION', '1.0.0-test' );
class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_query_arg( $key, $value, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
function get_option( $key, $default = false ) { return isset( $GLOBALS['options'][ $key ] ) ? $GLOBALS['options'][ $key ] : $default; }
function add_option( ...$args ) { return true; }
function update_option( ...$args ) { return true; }
function wp_remote_get( $url, $args ) {
	$GLOBALS['requests'][] = array( $url, $args );
	return array_shift( $GLOBALS['responses'] );
}
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) && isset( $response['code'] ) ? $response['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : ''; }
function add_action( ...$args ) {}

require_once dirname( __DIR__ ) . '/includes/class-tcas-core.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-api.php';

$pass = 0; $fail = 0;
function api_test( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "OK   {$label}\n"; }
	else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; }
}
function response( $code, $body ) { return array( 'code' => $code, 'body' => $body ); }
function configure( $over = array() ) {
	$GLOBALS['options'][ TCAS_Core::OPTION_SETTINGS ] = array_merge( TCAS_Core::defaults(), array(
		'base_url' => 'https://ariocase.example/api', 'product_endpoint' => 'products/{code}', 'api_key' => 'secret A/1', 'auth_type' => 'bearer',
	), $over );
	$GLOBALS['requests'] = array(); $GLOBALS['responses'] = array();
}
function call_build_url( $code, $kind, $settings ) {
	$method = new ReflectionMethod( 'TCAS_Api', 'build_url' );
	$method->setAccessible( true );
	return $method->invoke( null, $code, $kind, $settings );
}

$codes = TCAS_Core::parse_codes( "CH1،CH2\nCH1; CH3, CH4" );
api_test( 'ورودی کدها یکتا و مرتب می‌شود', array( 'CH1', 'CH2', 'CH3', 'CH4' ) === $codes, json_encode( $codes ) );
api_test( 'سقف ۵۰ کد اعمال می‌شود', 50 === count( TCAS_Core::parse_codes( range( 1, 80 ) ) ) );
$pairs = TCAS_Core::parse_pairs( "AR120 - AR22\nLOCAL-A - REMOTE-B\nAR120 - AR22" );
api_test( 'جفت دو کد با فاصله و خط جدید خوانده و تکراری حذف می‌شود', 2 === count( $pairs ) && 'AR120' === $pairs[0]['local_code'] && 'AR22' === $pairs[0]['remote_code'] && 'LOCAL-A' === $pairs[1]['local_code'] );
$malformed = TCAS_Core::parse_pairs( 'AR120 - AR22 - EXTRA' );
api_test( 'خط با بیش از یک جداکننده خطای مشخص دارد', 1 === count( $malformed ) && '' !== $malformed[0]['error'] );
$local_conflict = TCAS_Core::parse_pairs( "AR120 - AR22\nAR120 - OTHER" );
api_test( 'یک SKU با دو کد ArioCase در یک دسته رد می‌شود', '' !== $local_conflict[1]['error'] );
$remote_conflict = TCAS_Core::parse_pairs( "AR120 - AR22\nOTHER - AR22" );
api_test( 'یک کد ArioCase با دو SKU در یک دسته رد می‌شود', '' !== $remote_conflict[1]['error'] );
$current = array_merge( TCAS_Core::defaults(), array( 'api_key' => 'old-secret' ) );
$clean = TCAS_Core::sanitize_settings( array( 'enabled' => '1', 'api_key' => '', 'timeout' => 99, 'auth_type' => 'invalid' ), $current );
api_test( 'کلید با فیلد خالی حفظ و Timeout محدود می‌شود', 'old-secret' === $clean['api_key'] && 20 === $clean['timeout'] );
api_test( 'چک‌باکس‌های غایب خاموش و Auth نامعتبر امن می‌شود', 'no' === $clean['persist_stock'] && 'bearer' === $clean['auth_type'] );
$clean = TCAS_Core::sanitize_settings( array( 'clear_api_key' => '1' ), $current );
api_test( 'پاک‌کردن کلید صریح است', '' === $clean['api_key'] );

$settings = array_merge( TCAS_Core::defaults(), array( 'base_url' => 'https://ariocase.example/api', 'product_endpoint' => 'products/{code}' ) );
$url = call_build_url( 'A B/1', 'product', $settings );
api_test( 'Placeholder فقط یک بار URL-encode می‌شود', 'https://ariocase.example/api/products/A%20B%2F1' === $url, $url );
$settings['product_endpoint'] = 'products';
$url = call_build_url( 'A B/1', 'product', $settings );
api_test( 'کد در Query دوبار Encode نمی‌شود', 'https://ariocase.example/api/products?sku=A%20B%2F1' === $url, $url );
$settings['base_url'] = 'http://ariocase.example/api';
api_test( 'HTTP در حالت عادی رد می‌شود', is_wp_error( call_build_url( 'A', 'product', $settings ) ) );
$settings['base_url'] = 'https://ariocase.example/api';
$settings['product_endpoint'] = 'https://evil.example/{code}';
api_test( 'Endpoint مطلق برای جلوگیری از نشت کلید رد می‌شود', is_wp_error( call_build_url( 'A', 'product', $settings ) ) );
$settings['product_endpoint'] = '../secret/{code}';
api_test( 'Path Traversal در Endpoint رد می‌شود', is_wp_error( call_build_url( 'A', 'product', $settings ) ) );
$redacted = TCAS_Api::redact( 'https://x.test/?api_key=secret%20A%2F1&raw=secret A/1', array_merge( TCAS_Core::defaults(), array( 'api_key' => 'secret A/1' ) ) );
api_test( 'کلید خام و Encode‌شده از لاگ حذف می‌شود', false === strpos( $redacted, 'secret' ) && false !== strpos( $redacted, '[API-KEY]' ), $redacted );

configure();
$GLOBALS['responses'][] = response( 200, '{"sku":"CH1","stock_quantity":3}' );
$stock = TCAS_Api::fetch_stock( 'CH1' );
api_test( 'پاسخ Stock معتبر به قرارداد داخلی می‌رسد', is_array( $stock ) && 3.0 === $stock['quantity'] && 'instock' === $stock['status'] );
$request = $GLOBALS['requests'][0];
api_test( 'Bearer و گارد Redirect/SSL درخواست واقعی', 'Bearer secret A/1' === $request[1]['headers']['Authorization'] && 0 === $request[1]['redirection'] && true === $request[1]['sslverify'] );

configure();
$GLOBALS['responses'][] = response( 200, '{"sku":"CH1","stock_quantity":7}' );
TCAS_Api::fetch_stock( 'CH1' );
api_test( 'Endpoint Placeholder در Request واقعی', 'https://ariocase.example/api/products/CH1' === $GLOBALS['requests'][0][0], $GLOBALS['requests'][0][0] );

configure( array( 'auth_type' => 'query', 'product_endpoint' => 'products' ) );
$GLOBALS['responses'][] = response( 200, '{"sku":"CH1","stock":1}' );
TCAS_Api::fetch_stock( 'CH1' );
api_test( 'Query Auth و Code هر دو درست ساخته می‌شوند', false !== strpos( $GLOBALS['requests'][0][0], 'sku=CH1' ) && false !== strpos( $GLOBALS['requests'][0][0], 'api_key=secret%20A%2F1' ), $GLOBALS['requests'][0][0] );

foreach ( array( 404 => 'tcas_remote_not_found', 401 => 'tcas_auth_error', 429 => 'tcas_rate_limited', 500 => 'tcas_http_status' ) as $http => $expected ) {
	configure();
	$GLOBALS['responses'][] = response( $http, '{}' );
	$error = TCAS_Api::fetch_stock( 'CH1' );
	api_test( 'HTTP ' . $http . ' خطای کنترل‌شده است', is_wp_error( $error ) && $expected === $error->get_error_code(), is_wp_error( $error ) ? $error->get_error_code() : 'no error' );
}
configure();
$GLOBALS['responses'][] = response( 200, '<html>not json</html>' );
$error = TCAS_Api::fetch_stock( 'CH1' );
api_test( 'JSON خراب به موجودی صفر تبدیل نمی‌شود', is_wp_error( $error ) && 'tcas_invalid_json' === $error->get_error_code() );
configure();
$GLOBALS['responses'][] = new WP_Error( 'http_request_failed', 'timeout secret A/1' );
$error = TCAS_Api::fetch_stock( 'CH1' );
api_test( 'Timeout خطا است و کلید پیام پاک می‌شود', is_wp_error( $error ) && 'tcas_http_error' === $error->get_error_code() && false === strpos( $error->get_error_message(), 'secret A/1' ) );
configure();
$GLOBALS['responses'][] = response( 200, '{"sku":"OTHER","stock":2}' );
$error = TCAS_Api::fetch_product( 'CH1' );
api_test( 'پاسخ محصول با کد متفاوت پذیرفته نمی‌شود', is_wp_error( $error ) && 'tcas_code_mismatch' === $error->get_error_code() );
configure();
$GLOBALS['responses'][] = response( 200, '{"sku":"OTHER","stock":2}' );
$error = TCAS_Api::fetch_stock( 'CH1' );
api_test( 'موجودی محصول با کد متفاوت اعمال نمی‌شود', is_wp_error( $error ) && 'tcas_code_mismatch' === $error->get_error_code() );
configure();
$GLOBALS['responses'][] = response( 200, '{"sku":"CH1","name":"No stock"}' );
$error = TCAS_Api::fetch_stock( 'CH1' );
api_test( 'نبود فیلد موجودی خطای مشخص است', is_wp_error( $error ) && 'tcas_stock_missing' === $error->get_error_code() );

// قرارداد ارائه‌شدهٔ ArioCase: WooCommerce REST v3 + JWT Bearer.
$actual = array(
	'base_url' => 'https://ariocase.com/wp-json/wc/v3',
	'product_endpoint' => 'products?status=publish&per_page=100&sku={code}',
);
$defaults = TCAS_Core::defaults();
api_test( 'پیش‌فرض‌ها به API واقعی ArioCase و جست‌وجوی SKU اشاره دارند', $actual['base_url'] === $defaults['base_url'] && $actual['product_endpoint'] === $defaults['product_endpoint'] && 'bearer' === $defaults['auth_type'] );
api_test( 'Endpoint لینک، جست‌وجوی دقیق Slug ووکامرس است', 'products?status=publish&per_page=100&slug={slug}' === $defaults['slug_endpoint'] );
$url_pair = TCAS_Core::parse_pairs( 'LOCAL - https://ariocase.com/product/galaxy-case/?utm_source=email' );
api_test( 'لینک محصول در قالب جفت بدون تغییر پذیرفته می‌شود', 1 === count( $url_pair ) && '' === $url_pair[0]['error'] && 'https://ariocase.com/product/galaxy-case/?utm_source=email' === $url_pair[0]['remote_code'] );
$url = call_build_url( 'CH 100', 'product', array_merge( $defaults, $actual ) );
api_test( 'SKU در Query قرارداد واقعی درست Encode می‌شود', 'https://ariocase.com/wp-json/wc/v3/products?status=publish&per_page=100&sku=CH%20100' === $url, $url );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 123, 'sku' => 'CH100', 'name' => 'Case', 'type' => 'simple', 'stock_quantity' => 7, 'stock_status' => 'instock' ) ) ) );
$product = TCAS_Api::fetch_product( 'CH100' );
api_test( 'پاسخ List ووکامرس با SKU و ID نرمال می‌شود', is_array( $product ) && 123 === $product['remote_id'] && 7.0 === $product['stock']['quantity'] );
api_test( 'جست‌وجوی اولیه فقط همان SKU را درخواست می‌کند', 'https://ariocase.com/wp-json/wc/v3/products?status=publish&per_page=100&sku=CH100' === $GLOBALS['requests'][0][0], $GLOBALS['requests'][0][0] );
configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 501, 'parent_id' => 500, 'sku' => 'VAR-1-B', 'type' => 'variation', 'stock_quantity' => 2, 'stock_status' => 'instock' ) ) ) );
$variation_product = TCAS_Api::fetch_product( 'VAR-1-B' );
api_test( 'جست‌وجوی SKU تنوع، Parent ID را هم برای جفت حفظ می‌کند', is_array( $variation_product ) && 'variation' === $variation_product['type'] && 501 === $variation_product['remote_id'] && 500 === $variation_product['parent_remote_id'] );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array(
	array( 'id' => 999, 'slug' => 'other-case', 'sku' => 'OTHER', 'stock_quantity' => 99 ),
	array( 'id' => 321, 'slug' => 'galaxy-case', 'sku' => 'LINK-100', 'name' => 'Linked case', 'type' => 'simple', 'stock_quantity' => 6, 'stock_status' => 'instock' ),
) ) );
$linked = TCAS_Api::fetch_reference( 'https://ariocase.com/product/galaxy-case/?utm_source=email' );
api_test( 'Pretty Permalink فقط با Slug دقیق به SKU و ID تبدیل می‌شود', is_array( $linked ) && 'LINK-100' === $linked['code'] && 321 === $linked['remote_id'] && 6.0 === $linked['stock']['quantity'] );
api_test( 'لینک به API محصول با پارامتر slug ترجمه می‌شود، نه Fetch صفحه', 1 === count( $GLOBALS['requests'] ) && 'https://ariocase.com/wp-json/wc/v3/products?status=publish&per_page=100&slug=galaxy-case' === $GLOBALS['requests'][0][0], isset( $GLOBALS['requests'][0][0] ) ? $GLOBALS['requests'][0][0] : 'no request' );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( 'id' => 321, 'sku' => 'LINK-100', 'name' => 'Linked case', 'type' => 'simple', 'stock_quantity' => 8 ) ) );
$short = TCAS_Api::fetch_reference( 'https://www.ariocase.com/?p=321' );
api_test( 'لینک کوتاه p=ID با تک‌محصولی ووکامرس Resolve می‌شود', is_array( $short ) && 'LINK-100' === $short['code'] && 'https://ariocase.com/wp-json/wc/v3/products/321' === $GLOBALS['requests'][0][0] );
configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( 'id' => 999, 'sku' => 'WRONG-ID', 'stock_quantity' => 1 ) ) );
$wrong_id = TCAS_Api::fetch_reference( 'https://ariocase.com/?p=321' );
api_test( 'پاسخ با Product ID متفاوت برای لینک کوتاه رد می‌شود', is_wp_error( $wrong_id ) && 'tcas_id_mismatch' === $wrong_id->get_error_code() );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( 'data' => array( 'products' => array( array( 'id' => 654, 'slug' => 'plain-case', 'sku' => 'PLAIN-1', 'stock_quantity' => 2 ) ) ) ) ) );
$plain = TCAS_Api::fetch_reference( 'https://ariocase.com/?product=plain-case' );
api_test( 'Plain Permalink و پاسخ Wrapped هم به SKU محصول می‌رسند', is_array( $plain ) && 'PLAIN-1' === $plain['code'] && 654 === $plain['remote_id'] && false !== strpos( $GLOBALS['requests'][0][0], 'slug=plain-case' ) );

configure( $actual );
$persian_slug = 'قاب-گوشی';
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 656, 'slug' => $persian_slug, 'sku' => 'FA-1', 'stock_quantity' => 1 ) ) ) );
$persian = TCAS_Api::fetch_reference( 'https://ariocase.com/product/' . rawurlencode( $persian_slug ) . '/' );
api_test( 'نامک فارسی URL-encoded بدون خرابی Resolve می‌شود', is_array( $persian ) && 'FA-1' === $persian['code'] && false !== strpos( $GLOBALS['requests'][0][0], 'slug=' . rawurlencode( $persian_slug ) ) );

configure( $actual );
$untrusted = TCAS_Api::fetch_reference( 'https://evil.example/product/galaxy-case/' );
api_test( 'لینک دامنهٔ دیگر بدون هیچ HTTP Request رد می‌شود', is_wp_error( $untrusted ) && 'tcas_untrusted_product_url' === $untrusted->get_error_code() && 0 === count( $GLOBALS['requests'] ) );
$insecure = TCAS_Api::fetch_reference( 'http://ariocase.com/product/galaxy-case/' );
api_test( 'لینک HTTP در حالت عادی رد می‌شود', is_wp_error( $insecure ) && 'tcas_untrusted_product_url' === $insecure->get_error_code() && 0 === count( $GLOBALS['requests'] ) );
$category = TCAS_Api::fetch_reference( 'https://ariocase.com/product-category/galaxy-case/' );
api_test( 'لینک دسته‌بندی به محصول تبدیل نمی‌شود', is_wp_error( $category ) && 'tcas_not_product_url' === $category->get_error_code() && 0 === count( $GLOBALS['requests'] ) );
configure( array_merge( $actual, array( 'slug_endpoint' => 'https://evil.example/{slug}' ) ) );
$bad_slug_endpoint = TCAS_Api::fetch_reference( 'https://ariocase.com/product/galaxy-case/' );
api_test( 'Endpoint لینک مطلق برای جلوگیری از نشت JWT رد می‌شود', is_wp_error( $bad_slug_endpoint ) && 'tcas_invalid_endpoint' === $bad_slug_endpoint->get_error_code() && 0 === count( $GLOBALS['requests'] ) );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 999, 'slug' => 'other-case', 'sku' => 'OTHER' ) ) ) );
$slug_mismatch = TCAS_Api::fetch_reference( 'https://ariocase.com/product/galaxy-case/' );
api_test( 'پاسخ با Slug متفاوت به محصول دیگر تبدیل نمی‌شود', is_wp_error( $slug_mismatch ) && 'tcas_slug_not_unique' === $slug_mismatch->get_error_code() );
configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 657, 'slug' => 'no-sku', 'sku' => '', 'stock_quantity' => 3 ) ) ) );
$no_sku = TCAS_Api::fetch_reference( 'https://ariocase.com/product/no-sku/' );
api_test( 'محصول لینک‌شدهٔ بدون SKU برای نگاشت رد می‌شود', is_wp_error( $no_sku ) && 'tcas_sku_missing' === $no_sku->get_error_code() );

configure( $actual );
$remote_parent = array( 'id' => 500, 'sku' => 'VAR-1', 'name' => 'Variable', 'type' => 'variable', 'variations' => array( 501, 502 ), 'attributes' => array( array( 'name' => 'Color', 'options' => array( 'Black', 'White' ), 'variation' => true ) ) );
$remote_variations = array(
	array( 'id' => 501, 'sku' => 'VAR-1-B', 'stock_quantity' => 2, 'stock_status' => 'instock', 'attributes' => array( array( 'name' => 'Color', 'option' => 'Black' ) ) ),
	array( 'id' => 502, 'sku' => 'VAR-1-W', 'stock_quantity' => 0, 'stock_status' => 'outofstock', 'attributes' => array( array( 'name' => 'Color', 'option' => 'White' ) ) ),
);
$GLOBALS['responses'][] = response( 200, json_encode( array( $remote_parent ) ) );
$GLOBALS['responses'][] = response( 200, json_encode( $remote_variations ) );
$variable = TCAS_Api::fetch_product( 'VAR-1' );
api_test( 'IDهای تنوع WooCommerce با درخواست دوم کامل می‌شوند', is_array( $variable ) && 2 === count( $variable['variations'] ) && 501 === $variable['variations'][0]['remote_id'] && empty( $variable['variations_missing'] ) );
api_test( 'Endpoint جزئیات تنوع و Attribute نوع option درست است', 2 === count( $GLOBALS['requests'] ) && false !== strpos( $GLOBALS['requests'][1][0], '/products/500/variations?status=publish&per_page=100&page=1' ) && 'Black' === $variable['variations'][0]['variation_attributes']['Color'] );

configure( $actual );
$many_variations = array();
for ( $i = 0; $i < 101; $i++ ) {
	$many_variations[] = array( 'id' => 601 + $i, 'sku' => 'PAGE-' . $i, 'stock_quantity' => 1 );
}
$GLOBALS['responses'][] = response( 200, json_encode( array( array( 'id' => 600, 'sku' => 'PAGED', 'type' => 'variable', 'variations' => range( 601, 701 ) ) ) ) );
$GLOBALS['responses'][] = response( 200, json_encode( array_slice( $many_variations, 0, 100 ) ) );
$GLOBALS['responses'][] = response( 200, json_encode( array_slice( $many_variations, 100 ) ) );
$paged = TCAS_Api::fetch_product( 'PAGED' );
api_test( 'بیش از ۱۰۰ تنوع با Pagination کامل خوانده می‌شود', is_array( $paged ) && 101 === count( $paged['variations'] ) && 3 === count( $GLOBALS['requests'] ) && false !== strpos( $GLOBALS['requests'][2][0], 'page=2' ) );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( array( 'id' => 123, 'sku' => 'CH100', 'stock_quantity' => 9, 'stock_status' => 'instock' ) ) );
$stock = TCAS_Api::fetch_stock( 'CH100', array( 'type' => 'simple', 'remote_id' => 123 ) );
api_test( 'موجودی محصول شناخته‌شده با تک‌محصولی ID خوانده می‌شود', is_array( $stock ) && 9.0 === $stock['quantity'] && 'https://ariocase.com/wp-json/wc/v3/products/123' === $GLOBALS['requests'][0][0] );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( $remote_variations[0] ) );
$stock = TCAS_Api::fetch_stock( 'VAR-1-B', array( 'type' => 'variation', 'remote_id' => 501, 'parent_remote_id' => 500 ) );
api_test( 'موجودی Variation با Parent ID و Variation ID خوانده می‌شود', is_array( $stock ) && 2.0 === $stock['quantity'] && 500 === $stock['parent_remote_id'] && 'https://ariocase.com/wp-json/wc/v3/products/500/variations/501' === $GLOBALS['requests'][0][0] );

configure( $actual );
$GLOBALS['responses'][] = response( 200, json_encode( $remote_parent ) );
$GLOBALS['responses'][] = response( 200, json_encode( $remote_variations ) );
$stock = TCAS_Api::fetch_stock( 'VAR-1-W', array( 'type' => 'variation', 'parent_remote_id' => 500, 'parent_code' => 'VAR-1' ) );
api_test( 'نگاشت قدیمی تنوع از والد Resolve و کد دقیق کنترل می‌شود', is_array( $stock ) && 0.0 === $stock['quantity'] && 502 === $stock['product']['remote_id'] && 2 === count( $GLOBALS['requests'] ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
