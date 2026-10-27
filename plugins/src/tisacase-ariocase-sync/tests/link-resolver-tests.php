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
	$id = absint( $GLOBALS['slugs'][ $slug ] );
	$post_name = isset( $GLOBALS['slug_names'][ $id ] ) ? $GLOBALS['slug_names'][ $id ] : $slug;
	return (object) array( 'ID' => $id, 'post_name' => $post_name );
}
function url_to_postid( $url ) { $GLOBALS['url_calls'][] = $url; return isset( $GLOBALS['pretty'][ $url ] ) ? $GLOBALS['pretty'][ $url ] : 0; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : false; }
function get_posts( $args ) {
	$GLOBALS['post_calls'][] = $args;
	$parent = isset( $args['post_parent'] ) ? absint( $args['post_parent'] ) : 0;
	if ( 'product_variation' !== ( isset( $args['post_type'] ) ? $args['post_type'] : '' ) || ! $parent ) return array();
	return isset( $GLOBALS['variation_posts'][ $parent ] ) ? $GLOBALS['variation_posts'][ $parent ] : array();
}
function get_term_by( $field, $value, $taxonomy ) {
	$GLOBALS['term_calls'][] = array( $field, $value, $taxonomy );
	return isset( $GLOBALS['terms'][ $taxonomy ][ $value ] ) ? (object) array( 'name' => $GLOBALS['terms'][ $taxonomy ][ $value ] ) : null;
}
class TCAS_Test_Product {
	private $sku; private $name; private $type; private $children; private $attributes; private $parent_id;
	public static $children_args = array();
	public function __construct( $sku, $name = 'Product', $type = 'simple', $children = array(), $attributes = array(), $parent_id = 0 ) { $this->sku = $sku; $this->name = $name; $this->type = $type; $this->children = $children; $this->attributes = $attributes; $this->parent_id = $parent_id; }
	public function get_sku() { return $this->sku; }
	public function get_name() { return $this->name; }
	public function get_type() { return $this->type; }
	public function get_parent_id() { return $this->parent_id; }
	public function get_children( $visible_only = null ) { self::$children_args[] = $visible_only; return $this->children; }
	public function get_attributes() { return $this->attributes; }
}
class TCAS_Test_WPDB {
	public $posts = 'wp_posts'; public $queries = array();
	public function prepare( $query, $parent ) { return array( $query, absint( $parent ) ); }
	public function get_col( $prepared ) {
		$this->queries[] = $prepared;
		$parent = absint( $prepared[1] );
		return isset( $GLOBALS['variation_posts'][ $parent ] ) ? $GLOBALS['variation_posts'][ $parent ] : array();
	}
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
require_once dirname( __DIR__ ) . '/includes/class-tcas-local-variation-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-link-resolver.php';
$pass = 0; $fail = 0;
function resolver_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function reset_resolver_fixtures() {
	TCAS_Test_Product::$children_args = array();
	$GLOBALS['products'] = array( 41 => new TCAS_Test_Product( 'TS0100', 'Local case' ), 42 => new TCAS_Test_Product( '', 'No SKU' ) );
	$GLOBALS['slugs'] = array( 'local-case' => 41 );
	$GLOBALS['pretty'] = array( 'https://tisacase.com/product/local-case/' => 41 );
	$GLOBALS['url_calls'] = array(); $GLOBALS['slug_calls'] = array(); $GLOBALS['post_calls'] = array(); $GLOBALS['variation_posts'] = array(); $GLOBALS['sku_ids'] = array( 'TS0100' => array( 41 ) ); TCAS_Api::$calls = array(); TCAS_Api::$responses = array();
	$GLOBALS['wpdb'] = new TCAS_Test_WPDB(); $GLOBALS['terms'] = array(); $GLOBALS['term_calls'] = array();
}
function remote_result( $code = 'AR22' ) { return array( 'code' => $code, 'name' => 'Remote case', 'remote_id' => 222, 'type' => 'simple' ); }

reset_resolver_fixtures();
$local = TCAS_Link_Resolver::resolve( 'https://www.tisacase.com/product/local-case/' );
resolver_test( 'لینک Pretty تیساکیس با www به SKU محلی تبدیل می‌شود', is_array( $local ) && 'tisacase' === $local['shop'] && 'TS0100' === $local['sku'] && 41 === $local['product_id'] );
resolver_test( 'Rewrite محلی با Host canonical و بدون Fetch خارجی اجرا می‌شود', array( 'https://tisacase.com/product/local-case/' ) === $GLOBALS['url_calls'] && 0 === count( TCAS_Api::$calls ) );
$local_only_remote = TCAS_Link_Resolver::resolve_local_link( 'https://ariocase.com/product/not-local/' );
resolver_test( 'Resolver محلی دامنهٔ همکار را رد می‌کند و API خارجی صدا نمی‌زند', is_wp_error( $local_only_remote ) && 'tcas_not_local_shop' === $local_only_remote->get_error_code() && 0 === count( TCAS_Api::$calls ) );

reset_resolver_fixtures();
$persian_url = 'https://tisacase.com/product/%d9%82%d8%a7%d8%a8-%d8%a8%d8%b1%d8%a7%d8%b4-%d9%84%db%8c%d9%84%db%8c%d9%88%d9%85/';
$persian_slug = 'قاب-براش-لیلیوم';
$GLOBALS['slugs'][ $persian_slug ] = 41;
$persian = TCAS_Link_Resolver::resolve( $persian_url );
resolver_test( 'نامک فارسی URL-encoded هنگام شکست Rewrite با نامک دقیق محصول Resolve می‌شود', is_array( $persian ) && 'TS0100' === $persian['sku'] && 41 === $persian['product_id'] && array( $persian_url ) === $GLOBALS['url_calls'] && array( $persian_slug, 'product' ) === end( $GLOBALS['slug_calls'] ) && 0 === count( TCAS_Api::$calls ) );

reset_resolver_fixtures();
$encoded_persian_slug = basename( (string) parse_url( $persian_url, PHP_URL_PATH ) );
$GLOBALS['slugs'][ $encoded_persian_slug ] = 41;
$encoded_persian = TCAS_Link_Resolver::resolve( $persian_url );
resolver_test( 'نامک ذخیره‌شده به شکل Encoded هم مستقیم و بدون Decode دوباره Resolve می‌شود', is_array( $encoded_persian ) && 41 === $encoded_persian['product_id'] && array( array( $encoded_persian_slug, 'product' ) ) === $GLOBALS['slug_calls'] );

reset_resolver_fixtures();
$GLOBALS['slugs'][ $encoded_persian_slug ] = 42; // شبیه پاسخ اشتباه get_page_by_path برای slug رمزگذاری‌شده.
$GLOBALS['slugs'][ $persian_slug ] = 41;
$GLOBALS['slug_names'][42] = 'teddy-baby';
$GLOBALS['slug_names'][41] = $persian_slug;
$GLOBALS['pretty'][ $persian_url ] = 0;
$colliding_slug = TCAS_Link_Resolver::resolve( $persian_url );
resolver_test( 'پاسخ اشتباه lookup رمزگذاری‌شده با post_name اعتبارسنجی و رد می‌شود', is_array( $colliding_slug ) && 41 === $colliding_slug['product_id'] && 'TS0100' === $colliding_slug['sku'] );
reset_resolver_fixtures();
$GLOBALS['slugs'][ $persian_slug ] = 41;
$GLOBALS['pretty'][ $persian_url ] = 42; // Rewrite قدیمی به محصول دیگری اشاره می‌کند.
$conflicting_rewrite = TCAS_Link_Resolver::resolve( $persian_url );
resolver_test( 'نامک دقیق محصول بر ID اشتباه Rewrite اولویت دارد و محصول دیگری Load نمی‌شود', is_array( $conflicting_rewrite ) && 41 === $conflicting_rewrite['product_id'] && 'TS0100' === $conflicting_rewrite['sku'] );
$GLOBALS['slug_calls'] = array();
$wrong_product_path = TCAS_Link_Resolver::resolve( 'https://tisacase.com/category/' . $encoded_persian_slug . '/' );
resolver_test( 'Fallback نامک فقط مسیر واقعی محصول را می‌پذیرد', is_wp_error( $wrong_product_path ) && 'tcas_local_product_missing' === $wrong_product_path->get_error_code() && 0 === count( $GLOBALS['slug_calls'] ) );

reset_resolver_fixtures();
$short = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?p=41' );
resolver_test( 'لینک کوتاه تیساکیس مستقیم با Product ID خوانده می‌شود', is_array( $short ) && 'TS0100' === $short['sku'] && 0 === count( $GLOBALS['url_calls'] ) );
$plain = TCAS_Link_Resolver::resolve( 'https://tisacase.com/?product=local-case' );
resolver_test( 'Plain Permalink تیساکیس با نامک محصول Resolve می‌شود', is_array( $plain ) && 'TS0100' === $plain['sku'] && array( array( 'local-case', 'product' ) ) === $GLOBALS['slug_calls'] );

reset_resolver_fixtures();
$local_variable_url = 'https://tisacase.com/product/local-variable/';
$GLOBALS['products'][43] = new TCAS_Test_Product( 'TS-PARENT', 'Local variable', 'variable', array( 44 ) );
$GLOBALS['products'][44] = new TCAS_Test_Product( 'TS-RED', 'Local variable - Red', 'variation', array(), array( 'attribute_color' => 'قرمز' ), 43 );
$GLOBALS['products'][45] = new TCAS_Test_Product( '', 'Local variable - Blue', 'variation', array(), array( 'attribute_color' => 'آبی' ), 43 );
$GLOBALS['variation_posts'][43] = array( 44, 45 );
$GLOBALS['pretty'][ $local_variable_url ] = 43;
$local_variable = TCAS_Link_Resolver::resolve( $local_variable_url );
resolver_test( 'لینک والد تیساکیس، همهٔ Variationهای واقعی را با ID یکتا می‌آورد', is_array( $local_variable ) && 'variable' === $local_variable['type'] && 2 === count( $local_variable['variations'] ) && 'ID:44' === $local_variable['variations'][0]['reference'] && 'قرمز' === $local_variable['variations'][0]['label'] && 'ID:45' === $local_variable['variations'][1]['reference'] && array( false ) === TCAS_Test_Product::$children_args );
$children_query = $GLOBALS['wpdb']->queries ? $GLOBALS['wpdb']->queries[0] : array( '', 0 );
resolver_test( 'Reader جدید مستقیم از wp_posts، بدون get_posts و LIMIT، همان والد را می‌خواند', 43 === $children_query[1] && false !== strpos( $children_query[0], "post_type = 'product_variation'" ) && false !== strpos( $children_query[0], "post_status NOT IN ('trash', 'auto-draft')" ) && false === stripos( $children_query[0], 'LIMIT' ) && 0 === count( $GLOBALS['post_calls'] ) );
$local_by_id = TCAS_Link_Resolver::resolve_local_reference( 'ID:43' );
resolver_test( 'ID والد Local برای تطبیق هوشمند Resolve می‌شود', is_array( $local_by_id ) && 'variable' === $local_by_id['type'] && 2 === count( $local_by_id['variations'] ) );
$GLOBALS['sku_ids']['TS-PARENT'] = array( 43 );
$local_by_sku = TCAS_Link_Resolver::resolve_local_reference( 'TS-PARENT' );
resolver_test( 'SKU والد Local برای تطبیق هوشمند Resolve می‌شود', is_array( $local_by_sku ) && 'variable' === $local_by_sku['type'] && 43 === $local_by_sku['product_id'] );
$GLOBALS['sku_ids']['DUP-PARENT'] = array( 43, 43 );
$duplicate_parent = TCAS_Link_Resolver::resolve_local_reference( 'DUP-PARENT' );
resolver_test( 'SKU مشترک والد برای تطبیق خودکار رد می‌شود', is_wp_error( $duplicate_parent ) && 'tcas_duplicate_sku' === $duplicate_parent->get_error_code() );

reset_resolver_fixtures();
$GLOBALS['products'][50] = new TCAS_Test_Product( 'TERM-PARENT', 'Taxonomy parent', 'variable', array() );
$GLOBALS['products'][51] = new TCAS_Test_Product( 'TERM-SHARED', 'Taxonomy variation', 'variation', array(), array( 'attribute_pa_model' => 'iphone-13', 'attribute_color' => 'آبی' ), 50 );
$GLOBALS['variation_posts'][50] = array( 51 );
$GLOBALS['terms']['pa_model']['iphone-13'] = 'iPhone 13';
$taxonomy_local = TCAS_Link_Resolver::resolve_local_reference( 'ID:50' );
resolver_test( 'Reader برچسب Taxonomy را از Term واقعی و کنار Attribute سفارشی می‌خواند', is_array( $taxonomy_local ) && 'iPhone 13 / آبی' === $taxonomy_local['variations'][0]['label'] && array( array( 'slug', 'iphone-13', 'pa_model' ) ) === $GLOBALS['term_calls'] );

reset_resolver_fixtures();
$all_variation_ids = range( 5001, 5075 );
$GLOBALS['products'][49] = new TCAS_Test_Product( 'ALL-PARENT', 'All variations', 'variable', array_slice( $all_variation_ids, 0, 12 ) );
foreach ( $all_variation_ids as $index => $variation_id ) {
	$GLOBALS['products'][ $variation_id ] = new TCAS_Test_Product( 'SHARED-LOCAL', 'Variation ' . ( $index + 1 ), 'variation', array(), array( 'attribute_model' => 'Model ' . ( $index + 1 ) ), 49 );
}
$GLOBALS['variation_posts'][49] = $all_variation_ids;
$all_local = TCAS_Link_Resolver::resolve_local_reference( 'ID:49' );
resolver_test( 'حتی با Cache ناقص، هر ۷۵ Variation واقعی والد بدون محدودیت Load می‌شوند', is_array( $all_local ) && 75 === count( $all_local['variations'] ) && 'ID:5001' === $all_local['variations'][0]['reference'] && 'ID:5075' === $all_local['variations'][74]['reference'] && array( false ) === TCAS_Test_Product::$children_args );

reset_resolver_fixtures();
$GLOBALS['products'][60] = new TCAS_Test_Product( 'VALIDATE-PARENT', 'Validation parent', 'variable', array( 64 ) );
$GLOBALS['products'][61] = new TCAS_Test_Product( 'VALID-ONE', 'Valid variation', 'variation', array(), array( 'attribute_model' => 'Valid' ), 60 );
$GLOBALS['products'][62] = new TCAS_Test_Product( 'WRONG-TYPE', 'Not a variation', 'simple', array(), array(), 60 );
$GLOBALS['products'][63] = new TCAS_Test_Product( 'WRONG-PARENT', 'Other parent variation', 'variation', array(), array( 'attribute_model' => 'Other' ), 59 );
$GLOBALS['products'][64] = new TCAS_Test_Product( 'CACHE-ONLY', 'Cached only variation', 'variation', array(), array( 'attribute_model' => 'Cached' ), 60 );
$GLOBALS['variation_posts'][60] = array( 61, 61, 62, 63 );
$validated_ids = TCAS_Local_Variation_Reader::ids( $GLOBALS['products'][60], 60 );
$validated_local = TCAS_Link_Resolver::resolve_local_reference( 'ID:60' );
resolver_test( 'Reader شناسه‌های ووکامرس و SQL را ادغام، تکراری‌ها را حذف و ترتیب API را حفظ می‌کند', array( 64, 61, 62, 63 ) === $validated_ids );
resolver_test( 'Resolver همهٔ Variationهای ووکامرس را نگه می‌دارد و نوع/والد را اعتبارسنجی می‌کند', is_array( $validated_local ) && 2 === count( $validated_local['variations'] ) && array( 64, 61 ) === array_column( $validated_local['variations'], 'product_id' ) && array( false, false ) === TCAS_Test_Product::$children_args );

reset_resolver_fixtures();
$GLOBALS['products'][65] = new TCAS_Test_Product( 'EMPTY-PARENT', 'Empty database parent', 'variable', array( 66 ) );
$GLOBALS['products'][66] = new TCAS_Test_Product( 'CACHE-ONLY', 'Must not return', 'variation', array(), array( 'attribute_model' => 'Cached' ), 65 );
$GLOBALS['variation_posts'][65] = array();
$empty_local = TCAS_Link_Resolver::resolve_local_reference( 'ID:65' );
resolver_test( 'Variation ووکامرس حتی با نتیجهٔ خالی Query مستقیم شناسایی می‌شود', is_array( $empty_local ) && 1 === count( $empty_local['variations'] ) && 66 === $empty_local['variations'][0]['product_id'] && array( false ) === TCAS_Test_Product::$children_args && 1 === count( $GLOBALS['wpdb']->queries ) );

reset_resolver_fixtures();
$GLOBALS['products'][67] = new TCAS_Test_Product( 'FALLBACK-PARENT', 'Fallback parent', 'variable', array( 68 ) );
$GLOBALS['products'][68] = new TCAS_Test_Product( 'FALLBACK-CHILD', 'Fallback variation', 'variation', array(), array(), 67 );
unset( $GLOBALS['wpdb'] );
$fallback_ids = TCAS_Local_Variation_Reader::ids( $GLOBALS['products'][67], 67 );
resolver_test( 'Fallback فقط هنگام نبود لایهٔ دیتابیس از Children واقعی همان والد استفاده می‌کند', array( 68 ) === $fallback_ids && 1 === count( TCAS_Test_Product::$children_args ) && 0 === count( $GLOBALS['post_calls'] ) );

reset_resolver_fixtures();
$local_duplicate_url = 'https://tisacase.com/product/local-duplicate-skus/';
$GLOBALS['products'][46] = new TCAS_Test_Product( 'SB193', 'Shared SKU parent', 'variable', array( 47, 48 ) );
$GLOBALS['products'][47] = new TCAS_Test_Product( 'SB193', 'Shared SKU - iPhone 13', 'variation', array(), array( 'attribute_model' => 'iPhone 13' ), 46 );
$GLOBALS['products'][48] = new TCAS_Test_Product( 'SB193', 'Shared SKU - iPhone 13 Pro', 'variation', array(), array( 'attribute_model' => 'iPhone 13 Pro' ), 46 );
$GLOBALS['variation_posts'][46] = array( 47, 48 );
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

TCAS_Api::$responses['ID:222'] = $remote_variable_response;
$remote_by_reference = TCAS_Link_Resolver::resolve_remote_reference( 'ID:222' );
resolver_test( 'ورودی مستقیم ArioCase از شکل خام API به مرجع و برچسب تنوع تبدیل می‌شود', is_array( $remote_by_reference ) && 'ID:222' === $remote_by_reference['reference'] && 'ID:222/223' === $remote_by_reference['variations'][0]['reference'] && 'مشکی' === $remote_by_reference['variations'][0]['label'] && 223 === $remote_by_reference['variations'][0]['product_id'] && 222 === $remote_by_reference['variations'][0]['parent_id'] );

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
