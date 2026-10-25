<?php
/** اتصال جفتی: فقط محصولات موجود و فقط فیلدهای Stock. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; private $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function absint( $v ) { return abs( (int) $v ); }
function get_post_meta( $id, $key, $single = true ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : null; }
function wc_delete_product_transients( $id ) {}
class TCAS_Test_WPDB {
	public $postmeta = 'wp_postmeta'; public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) { foreach ( $args as $arg ) { $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ); $query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ); } return $query; }
	public function get_col( $query ) {
		preg_match( "/pm\.meta_key = '([^']+)'/", $query, $key ); preg_match( "/pm\.meta_value = '([^']*)'/", $query, $value ); $out = array();
		foreach ( $GLOBALS['meta'] as $id => $meta ) if ( isset( $key[1], $value[1], $meta[ $key[1] ] ) && 0 === strcasecmp( (string) $meta[ $key[1] ], (string) $value[1] ) ) $out[] = $id;
		sort( $out ); return $out;
	}
}
$GLOBALS['wpdb'] = new TCAS_Test_WPDB(); $GLOBALS['meta'] = array(); $GLOBALS['products'] = array();
class TCAS_Test_Product {
	private $id; private $type; private $parent; private $sku; private $name; private $price; private $status; private $description;
	private $manage = false; private $qty = null; private $stock_status = 'instock'; private $fail_save;
	public $saved = 0;
	public function __construct( $id, $sku, $type = 'simple', $parent = 0, $fail_save = false ) { $this->id = $id; $this->sku = $sku; $this->type = $type; $this->parent = $parent; $this->fail_save = $fail_save; $this->name = 'نام اصلی ' . $sku; $this->price = 9000.0; $this->status = 'publish'; $this->description = 'توضیح اصلی'; }
	public function get_id() { return $this->id; } public function get_parent_id() { return $this->parent; } public function get_type() { return $this->type; }
	public function is_type( $type ) { return $this->type === $type; } public function get_sku() { return $this->sku; } public function get_name() { return $this->name; }
	public function get_regular_price() { return $this->price; } public function get_status() { return $this->status; } public function get_description() { return $this->description; }
	public function get_manage_stock( $context = 'view' ) { return $this->manage; } public function get_stock_quantity( $context = 'view' ) { return $this->qty; } public function get_stock_status( $context = 'view' ) { return $this->stock_status; }
	public function set_manage_stock( $v ) { $this->manage = (bool) $v; } public function set_stock_quantity( $v ) { $this->qty = (float) $v; } public function set_stock_status( $v ) { $this->stock_status = $v; }
	public function save() { if ( $this->fail_save ) throw new Exception( 'save failed' ); $this->saved++; return $this->id; }
	public function __call( $method, $args ) { throw new Exception( 'Forbidden product field method: ' . $method ); }
}
class WC_Product_Variable { public static function sync_stock_status( $id, $save = true ) {} }
class TCAS_Api {
	public static $responses = array(); public static $calls = array();
	public static function fetch_product( $code ) { return self::fetch_reference( $code ); }
	public static function fetch_reference( $reference ) { self::$calls[] = $reference; return isset( self::$responses[ $reference ] ) ? self::$responses[ $reference ] : new WP_Error( 'not_found', 'محصول ArioCase پیدا نشد.' ); }
}
require_once dirname( __DIR__ ) . '/includes/class-tcas-mapping.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-stock.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-pairing.php';
$pass = 0; $fail = 0;
function pair_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function local_product( $id, $sku, $type = 'simple', $parent = 0, $fail_save = false ) { $product = new TCAS_Test_Product( $id, $sku, $type, $parent, $fail_save ); $GLOBALS['products'][ $id ] = $product; $GLOBALS['meta'][ $id ]['_sku'] = $sku; return $product; }
function remote_product( $code, $over = array() ) { return array_merge( array( 'remote_id' => 100, 'parent_remote_id' => 0, 'code' => $code, 'name' => 'نام متفاوت ریموت', 'type' => 'simple', 'price' => 1.0, 'stock' => array( 'quantity' => 4.0, 'status' => 'instock' ), 'variations' => array() ), $over ); }
function pair_of( $local, $remote ) { return array( 'local_code' => $local, 'remote_code' => $remote, 'error' => '' ); }
function connect_pair( $local, $remote ) { $preview = TCAS_Pairing::preview( pair_of( $local, $remote ) ); return array( $preview, TCAS_Pairing::connect( $preview ) ); }

$product = local_product( 10, 'AR120' );
TCAS_Api::$responses['AR22'] = remote_product( 'AR22', array( 'remote_id' => 222 ) );
list( $preview, $result ) = connect_pair( 'AR120', 'AR22' );
pair_test( 'جفت دو کد متفاوت Preview و Connect می‌شود', 'connect' === $preview['row']['status'] && is_array( $result ) );
pair_test( 'هیچ محصولی ساخته یا حذف نمی‌شود', 1 === count( $GLOBALS['products'] ) && 10 === $result['product_id'] );
pair_test( 'نام، قیمت، SKU، توضیح و انتشار تغییر نمی‌کنند', 'نام اصلی AR120' === $product->get_name() && 9000.0 === $product->get_regular_price() && 'AR120' === $product->get_sku() && 'توضیح اصلی' === $product->get_description() && 'publish' === $product->get_status() );
pair_test( 'فقط Stock و نگاشت داخلی به‌روز می‌شوند', 4.0 === $product->get_stock_quantity() && $product->get_manage_stock() && 'AR22' === TCAS_Mapping::remote_code( 10 ) && 222 === TCAS_Mapping::remote_identifiers( 10 )['remote_id'] );
$again = TCAS_Pairing::preview( pair_of( 'AR120', 'AR22' ) );
pair_test( 'اجرای دوباره همان جفت را Connected نشان می‌دهد', 'connected' === $again['row']['status'] && ! $again['row']['selectable'] );

$before_calls = count( TCAS_Api::$calls ); $before_products = count( $GLOBALS['products'] );
$missing = TCAS_Pairing::preview( pair_of( 'NO-LOCAL', 'AR22' ) );
pair_test( 'محصول مفقود تیساکیس خطا است و API/ساخت محصول ندارد', 'error' === $missing['row']['status'] && $before_calls === count( TCAS_Api::$calls ) && $before_products === count( $GLOBALS['products'] ) );

local_product( 11, 'LOCAL-11' );
$missing_remote = connect_pair( 'LOCAL-11', 'NO-REMOTE' );
pair_test( 'محصول مفقود ArioCase هیچ نگاشتی نمی‌سازد', is_wp_error( $missing_remote[1] ) && '' === TCAS_Mapping::remote_code( 11 ) );

local_product( 12, 'LOCAL-12' ); local_product( 13, 'LOCAL-13' );
TCAS_Mapping::map( 12, 'OTHER-REMOTE', true );
$other = TCAS_Pairing::preview( pair_of( 'LOCAL-12', 'AR22' ) );
pair_test( 'کد دوم متفاوت با نگاشت قبلی رد می‌شود', 'error' === $other['row']['status'] && 'OTHER-REMOTE' === TCAS_Mapping::remote_code( 12 ) );
TCAS_Mapping::map( 13, 'USED-REMOTE', true ); local_product( 22, 'LOCAL-22' );
$used = TCAS_Pairing::preview( pair_of( 'LOCAL-22', 'USED-REMOTE' ) );
pair_test( 'کد ArioCase نمی‌تواند به دو SKU متصل شود', 'error' === $used['row']['status'] && '' === TCAS_Mapping::remote_code( 22 ) );

local_product( 14, 'lower-case' );
$case = TCAS_Pairing::preview( pair_of( 'LOWER-CASE', 'AR22' ) );
pair_test( 'Collation Case-insensitive باعث جفت اشتباه نمی‌شود', 'error' === $case['row']['status'] && '' === TCAS_Mapping::remote_code( 14 ) );

local_product( 15, 'LOCAL-15' ); TCAS_Api::$responses['REMOTE-VARIABLE'] = remote_product( 'REMOTE-VARIABLE', array( 'type' => 'variable' ) );
$mismatch = connect_pair( 'LOCAL-15', 'REMOTE-VARIABLE' );
pair_test( 'ساده با والد متغیر جفت نمی‌شود', is_wp_error( $mismatch[1] ) && '' === TCAS_Mapping::remote_code( 15 ) );
local_product( 16, 'PARENT-16', 'variable' ); $before_calls = count( TCAS_Api::$calls );
$parent = TCAS_Pairing::preview( pair_of( 'PARENT-16', 'REMOTE-VARIABLE' ) );
pair_test( 'والد متغیر بدون API رد می‌شود', 'error' === $parent['row']['status'] && $before_calls === count( TCAS_Api::$calls ) );

$variation = local_product( 17, 'LOCAL-VAR', 'variation', 100 );
TCAS_Api::$responses['REMOTE-VAR'] = remote_product( 'REMOTE-VAR', array( 'remote_id' => 601, 'parent_remote_id' => 600, 'type' => 'variation', 'stock' => array( 'quantity' => 7.0, 'status' => 'instock' ) ) );
list( $variation_preview, $variation_result ) = connect_pair( 'LOCAL-VAR', 'REMOTE-VAR' );
$variation_ids = TCAS_Mapping::remote_identifiers( 17 );
pair_test( 'Variation با Variation و با ID والد جفت می‌شود', is_array( $variation_result ) && 7.0 === $variation->get_stock_quantity() && 601 === $variation_ids['remote_id'] && 600 === $variation_ids['parent_remote_id'] );

local_product( 18, 'LOCAL-18' ); TCAS_Api::$responses['NO-STOCK'] = remote_product( 'NO-STOCK', array( 'stock' => array( 'quantity' => null, 'status' => null ) ) );
$no_stock = connect_pair( 'LOCAL-18', 'NO-STOCK' );
pair_test( 'نبود موجودی معتبر یعنی عدم اتصال', is_wp_error( $no_stock[1] ) && '' === TCAS_Mapping::remote_code( 18 ) );

local_product( 19, 'STALE-LOCAL' ); TCAS_Api::$responses['STALE-REMOTE'] = remote_product( 'STALE-REMOTE' );
$stale_preview = TCAS_Pairing::preview( pair_of( 'STALE-LOCAL', 'STALE-REMOTE' ) );
unset( $GLOBALS['products'][19], $GLOBALS['meta'][19] ); local_product( 20, 'STALE-LOCAL' );
$stale_result = TCAS_Pairing::connect( $stale_preview );
pair_test( 'Preview کهنه به محصول جایگزین وصل نمی‌شود', is_wp_error( $stale_result ) && '' === TCAS_Mapping::remote_code( 20 ) );

local_product( 21, 'FAIL-SAVE', 'simple', 0, true ); TCAS_Api::$responses['FAIL-REMOTE'] = remote_product( 'FAIL-REMOTE' );
$failed = connect_pair( 'FAIL-SAVE', 'FAIL-REMOTE' );
pair_test( 'خطای ذخیرهٔ Stock نگاشت تازه را Rollback می‌کند', is_wp_error( $failed[1] ) && '' === TCAS_Mapping::remote_code( 21 ) );

local_product( 23, 'LINK-LOCAL' );
$link = 'https://ariocase.com/product/resolved-case/';
TCAS_Api::$responses[ $link ] = remote_product( 'LINK-RESOLVED', array( 'remote_id' => 777 ) );
list( $link_preview, $link_result ) = connect_pair( 'LINK-LOCAL', $link );
pair_test( 'لینک به SKU واقعی تبدیل و فقط همان SKU نگاشت می‌شود', is_array( $link_result ) && 'LINK-RESOLVED' === $link_preview['row']['remote_code'] && 'LINK-RESOLVED' === TCAS_Mapping::remote_code( 23 ) && 777 === TCAS_Mapping::remote_identifiers( 23 )['remote_id'] );
$link_again = TCAS_Pairing::preview( pair_of( 'LINK-LOCAL', $link ) );
pair_test( 'پیش‌نمایش دوباره با همان لینک، اتصال موجود را تشخیص می‌دهد', 'connected' === $link_again['row']['status'] && 'LINK-RESOLVED' === $link_again['row']['remote_code'] );

local_product( 24, 'ID-LINK-LOCAL' );
$id_link = 'https://ariocase.com/product/id-only-case/';
TCAS_Api::$responses[ $id_link ] = remote_product( '', array( 'remote_id' => 658 ) );
list( $id_link_preview, $id_link_result ) = connect_pair( 'ID-LINK-LOCAL', $id_link );
pair_test( 'لینک بدون SKU به ID canonical نگاشت و فقط Stock اعمال می‌شود', is_array( $id_link_result ) && 'ID:658' === $id_link_preview['row']['remote_code'] && 'ID:658' === TCAS_Mapping::remote_code( 24 ) && 658 === TCAS_Mapping::remote_identifiers( 24 )['remote_id'] && 4.0 === $GLOBALS['products'][24]->get_stock_quantity() );
$id_link_again = TCAS_Pairing::preview( pair_of( 'ID-LINK-LOCAL', $id_link ) );
pair_test( 'لینک ID-only در Preview دوباره Connected تشخیص داده می‌شود', 'connected' === $id_link_again['row']['status'] && 'ID:658' === $id_link_again['row']['remote_code'] );

local_product( 25, 'ID-DIRECT-LOCAL' );
TCAS_Api::$responses['ID:659'] = remote_product( '', array( 'remote_id' => 659 ) );
list( $id_direct_preview, $id_direct_result ) = connect_pair( 'ID-DIRECT-LOCAL', 'ID:659' );
pair_test( 'مرجع مستقیم ID در Preview و Connect حفظ می‌شود', is_array( $id_direct_result ) && 'ID:659' === $id_direct_preview['row']['remote_code'] && 'ID:659' === TCAS_Mapping::remote_code( 25 ) && 659 === TCAS_Mapping::remote_identifiers( 25 )['remote_id'] );
local_product( 26, 'NO-IDENTITY-LOCAL' );
TCAS_Api::$responses['NO-IDENTITY'] = remote_product( '', array( 'remote_id' => 0 ) );
$no_identity = TCAS_Pairing::preview( pair_of( 'NO-IDENTITY-LOCAL', 'NO-IDENTITY' ) );
pair_test( 'محصول بدون SKU و ID نگاشت جعلی نمی‌سازد', 'error' === $no_identity['row']['status'] && '' === TCAS_Mapping::remote_code( 26 ) );

local_product( 27, 'LOCAL-ID-VAR', 'variation', 100 );
TCAS_Api::$responses['ID:700/701'] = remote_product( '', array( 'remote_id' => 701, 'parent_remote_id' => 700, 'type' => 'variation', 'stock' => array( 'quantity' => 8.0, 'status' => 'instock' ) ) );
list( $id_variation_preview, $id_variation_result ) = connect_pair( 'LOCAL-ID-VAR', 'ID:700/701' );
$id_variation_ids = TCAS_Mapping::remote_identifiers( 27 );
pair_test( 'Variation بدون SKU با مرجع والد/تنوع جفت و نگاشت می‌شود', is_array( $id_variation_result ) && 'ID:700/701' === $id_variation_preview['row']['remote_code'] && 'ID:700/701' === TCAS_Mapping::remote_code( 27 ) && 701 === $id_variation_ids['remote_id'] && 700 === $id_variation_ids['parent_remote_id'] && 8.0 === $GLOBALS['products'][27]->get_stock_quantity() );
$id_variation_again = TCAS_Pairing::preview( pair_of( 'LOCAL-ID-VAR', 'ID:700/701' ) );
pair_test( 'مرجع Variation در Preview دوباره Connected می‌شود', 'connected' === $id_variation_again['row']['status'] && 'ID:700/701' === $id_variation_again['row']['remote_code'] );

local_product( 200, 'SB193', 'variable' );
local_product( 201, 'SB193', 'variation', 200 );
local_product( 202, 'SB193', 'variation', 200 );
TCAS_Api::$responses['ID:700/702'] = remote_product( '', array( 'remote_id' => 702, 'parent_remote_id' => 700, 'type' => 'variation', 'stock' => array( 'quantity' => 6.0, 'status' => 'instock' ) ) );
list( $shared_id_preview, $shared_id_result ) = connect_pair( 'ID:201', 'ID:700/702' );
pair_test( 'با SKU مشترک، ID تیساکیس فقط همان Variation را جفت می‌کند', is_array( $shared_id_result ) && 201 === $shared_id_result['product_id'] && 'ID:201' === $shared_id_preview['row']['local_code'] && 'ID:700/702' === TCAS_Mapping::remote_code( 201 ) && 6.0 === $GLOBALS['products'][201]->get_stock_quantity() && '' === TCAS_Mapping::remote_code( 202 ) );
$shared_id_again = TCAS_Pairing::preview( pair_of( 'ID:201', 'ID:700/702' ) );
pair_test( 'اتصال Variation با ID در Preview دوباره Connected می‌شود', 'connected' === $shared_id_again['row']['status'] && 201 === $shared_id_again['local_id'] );
$before_shared_sku_calls = count( TCAS_Api::$calls );
$shared_sku = TCAS_Pairing::preview( pair_of( 'SB193', 'ID:700/703' ) );
pair_test( 'ورود دستی SKU مشترک بدون API و با راهنمای ID رد می‌شود', 'error' === $shared_sku['row']['status'] && false !== strpos( $shared_sku['row']['message'], 'ID' ) && $before_shared_sku_calls === count( TCAS_Api::$calls ) );
$missing_local_id = TCAS_Pairing::preview( pair_of( 'ID:999', 'ID:700/704' ) );
pair_test( 'ID ناموجود تیساکیس محصول جعلی نمی‌سازد', 'error' === $missing_local_id['row']['status'] && 0 === $missing_local_id['local_id'] && ! isset( $GLOBALS['products'][999] ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
