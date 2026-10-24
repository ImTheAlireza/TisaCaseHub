<?php
/** آزمون ایمپورت و اتصال با مدل‌های دوبل ووکامرس و wpdb. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; private $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_title( $v ) { return strtolower( str_replace( ' ', '-', trim( (string) $v ) ) ); }
function absint( $v ) { return abs( (int) $v ); }
function get_post_meta( $id, $key, $single = true ) { return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function wc_get_product( $id ) { return isset( $GLOBALS['products'][ $id ] ) ? $GLOBALS['products'][ $id ] : null; }
function wc_delete_product_transients( $id ) {}
function get_children( $args ) { $out = array(); foreach ( $GLOBALS['products'] as $id => $product ) { if ( $product->get_parent_id() === (int) $args['post_parent'] && $product->is_type( 'variation' ) ) $out[] = $id; } return $out; }
function wp_delete_post( $id, $force = false ) { unset( $GLOBALS['products'][ $id ], $GLOBALS['meta'][ $id ] ); return true; }
function esc_url_raw( $url ) { return $url; }
function wp_http_validate_url( $url ) { return true; }
function set_post_thumbnail( $id, $attachment ) { return true; }
class TCAS_Test_WPDB {
	public $postmeta = 'wp_postmeta';
	public $posts = 'wp_posts';
	public function prepare( $query, ...$args ) { foreach ( $args as $arg ) { $query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ); $query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ); } return $query; }
	public function get_col( $query ) {
		preg_match( "/pm\.meta_key = '([^']+)'/", $query, $key );
		preg_match( "/pm\.meta_value = '([^']*)'/", $query, $value );
		$out = array();
		foreach ( $GLOBALS['meta'] as $id => $meta ) if ( isset( $key[1], $value[1], $meta[ $key[1] ] ) && 0 === strcasecmp( (string) $meta[ $key[1] ], (string) $value[1] ) ) $out[] = $id;
		sort( $out ); return $out;
	}
}
$GLOBALS['wpdb'] = new TCAS_Test_WPDB();
$GLOBALS['meta'] = array();
$GLOBALS['products'] = array();
class WC_Product_Attribute {
	private $data = array();
	public function set_id( $v ) { $this->data['id'] = $v; } public function set_name( $v ) { $this->data['name'] = $v; }
	public function set_options( $v ) { $this->data['options'] = $v; } public function set_position( $v ) { $this->data['position'] = $v; }
	public function set_visible( $v ) { $this->data['visible'] = $v; } public function set_variation( $v ) { $this->data['variation'] = $v; }
}
class WC_Product {
	protected $id = 0; protected $type = 'simple'; protected $parent = 0; protected $sku = ''; protected $name = ''; protected $status = 'draft'; protected $description = ''; protected $price = null; protected $manage = false; protected $qty = null; protected $stock_status = 'instock'; protected $attributes = array();
	public static $next = 100;
	public function get_id() { return $this->id; } public function get_parent_id() { return $this->parent; } public function get_type() { return $this->type; }
	public function is_type( $type ) { return $this->type === $type; } public function get_sku() { return $this->sku; } public function get_name() { return $this->name; }
	public function get_manage_stock( $context = 'view' ) { return $this->manage; } public function get_stock_quantity( $context = 'view' ) { return $this->qty; }
	public function get_stock_status( $context = 'view' ) { return $this->stock_status; } public function get_status() { return $this->status; }
	public function get_regular_price() { return $this->price; } public function get_attributes() { return $this->attributes; }
	public function set_name( $v ) { $this->name = $v; } public function set_sku( $v ) { $this->sku = $v; } public function set_status( $v ) { $this->status = $v; }
	public function set_description( $v ) { $this->description = $v; } public function set_regular_price( $v ) { $this->price = (float) $v; }
	public function set_manage_stock( $v ) { $this->manage = (bool) $v; } public function set_stock_quantity( $v ) { $this->qty = (float) $v; }
	public function set_stock_status( $v ) { $this->stock_status = $v; } public function set_attributes( $v ) { $this->attributes = $v; }
	public function set_parent_id( $v ) { $this->parent = (int) $v; }
	public function save() { if ( ! $this->id ) $this->id = ++self::$next; $GLOBALS['products'][ $this->id ] = $this; $GLOBALS['meta'][ $this->id ]['_sku'] = $this->sku; return $this->id; }
}
class WC_Product_Variable extends WC_Product { protected $type = 'variable'; public static function sync( $id ) {} public static function sync_stock_status( $id, $save = true ) {} }
class WC_Product_Variation extends WC_Product { protected $type = 'variation'; }
class TCAS_Api { public static $responses = array(); public static function fetch_product( $code ) { return isset( self::$responses[ $code ] ) ? self::$responses[ $code ] : new WP_Error( 'not_found', 'not found' ); } }

require_once dirname( __DIR__ ) . '/includes/class-tcas-mapping.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-stock.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-importer.php';

$pass = 0; $fail = 0;
function import_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function remote_item( $code, $over = array() ) {
	return array_merge( array( 'remote_id' => 0, 'code' => $code, 'name' => 'محصول ' . $code, 'type' => 'simple', 'description' => '', 'price' => 1000.0, 'stock' => array( 'quantity' => 4.0, 'status' => 'instock' ), 'attributes' => array(), 'variation_attributes' => array(), 'images' => array(), 'variations' => array() ), $over );
}
function existing_product( $id, $sku, $type = 'simple', $parent = 0, $name = 'محصول قدیمی' ) {
	$product = 'variable' === $type ? new WC_Product_Variable() : ( 'variation' === $type ? new WC_Product_Variation() : new WC_Product() );
	$product->set_sku( $sku ); $product->set_name( $name ); $product->set_parent_id( $parent ); $product->set_status( 'publish' ); $product->set_regular_price( 9000 );
	$property = new ReflectionProperty( 'WC_Product', 'id' ); $property->setAccessible( true ); $property->setValue( $product, $id );
	$GLOBALS['products'][ $id ] = $product; $GLOBALS['meta'][ $id ]['_sku'] = $sku; return $product;
}
function run_import( $code, $options = array() ) { $preview = TCAS_Importer::preview( $code ); return array( $preview, TCAS_Importer::import( $preview, array_merge( array( 'prices' => true, 'images' => false ), $options ) ) ); }

TCAS_Api::$responses['NEW-1'] = remote_item( 'NEW-1', array( 'remote_id' => 1001 ) );
list( $preview, $result ) = run_import( 'NEW-1' );
import_test( 'محصول سادهٔ جدید Preview و Import می‌شود', 'import' === $preview['row']['status'] && is_array( $result ) );
$simple_id = is_array( $result ) ? $result['product_id'] : 0;
import_test( 'محصول جدید پیش‌نویس و دارای SKU و موجودی است', $simple_id && 'draft' === $GLOBALS['products'][ $simple_id ]->get_status() && 4.0 === $GLOBALS['products'][ $simple_id ]->get_stock_quantity() );
import_test( 'نگاشت محصول جدید ساخته شده است', $simple_id && 'NEW-1' === TCAS_Mapping::remote_code( $simple_id ) && TCAS_Mapping::is_enabled( $simple_id ) );
$simple_identifiers = $simple_id ? TCAS_Mapping::remote_identifiers( $simple_id ) : array();
import_test( 'شناسهٔ عددی ArioCase برای درخواست تک‌محصولی ذخیره می‌شود', 1001 === $simple_identifiers['remote_id'] );
$again = TCAS_Importer::preview( 'NEW-1' );
import_test( 'اجرای دوباره به‌جای تکرار، وضعیت متصل می‌دهد', 'connected' === $again['row']['status'] );

$case_product = existing_product( 5, 'lower-case' );
import_test( 'جست‌وجوی Case-insensitive دیتابیس به اتصال اشتباه تبدیل نمی‌شود', 0 === TCAS_Mapping::local_by_sku( 'LOWER-CASE' ) );
import_test( 'SKU دقیق با همان حروف پیدا می‌شود', 5 === TCAS_Mapping::local_by_sku( 'lower-case' ) );
TCAS_Mapping::map( 5, 'lower-remote', true );
import_test( 'کد ArioCase هم با حروف دقیق بررسی می‌شود', array() === TCAS_Mapping::ids_by_remote_code( 'LOWER-REMOTE' ) );
TCAS_Api::$responses['lower-remote'] = remote_item( 'lower-remote' );
$already_mapped = TCAS_Importer::preview( 'lower-remote' );
import_test( 'نگاشت دستی با SKU متفاوت در Preview تکراری محسوب می‌شود', 'connected' === $already_mapped['row']['status'] && 5 === $already_mapped['row']['local_id'] );

$existing = existing_product( 10, 'LOCAL-1' );
TCAS_Api::$responses['LOCAL-1'] = remote_item( 'LOCAL-1', array( 'name' => 'نام جدید سرویس', 'price' => 555.0, 'stock' => array( 'quantity' => 2.0, 'status' => 'instock' ) ) );
list( $preview, $result ) = run_import( 'LOCAL-1' );
import_test( 'محصول موجود فقط متصل و مشخصاتش بازنویسی نمی‌شود', 'connect' === $preview['row']['status'] && 'محصول قدیمی' === $existing->get_name() && 9000.0 === $existing->get_regular_price() );
import_test( 'موجودی محصول موجود هنگام اتصال به‌روز می‌شود', 2.0 === $existing->get_stock_quantity() );

existing_product( 11, 'TYPE-1' );
TCAS_Api::$responses['TYPE-1'] = remote_item( 'TYPE-1', array( 'type' => 'variable', 'variations' => array( remote_item( 'TYPE-1-A' ) ) ) );
$mismatch = TCAS_Importer::preview( 'TYPE-1' );
import_test( 'عدم تطابق ساده/متغیر متوقف می‌شود', 'error' === $mismatch['row']['status'] );
TCAS_Api::$responses['NO-VARS'] = remote_item( 'NO-VARS', array( 'type' => 'variable' ) );
$no_vars = TCAS_Importer::preview( 'NO-VARS' );
import_test( 'محصول متغیر بدون جزئیات تنوع ایمپورت نمی‌شود', 'error' === $no_vars['row']['status'] );
TCAS_Api::$responses['VAR-IDS'] = remote_item( 'VAR-IDS', array( 'variations_missing' => true ) );
$variation_ids = TCAS_Importer::preview( 'VAR-IDS' );
import_test( 'فهرست فقط‌ID تنوع به محصول ساده تبدیل نمی‌شود', 'error' === $variation_ids['row']['status'] );
TCAS_Api::$responses['VAR-PARTIAL'] = remote_item( 'VAR-PARTIAL', array( 'variations_missing' => true, 'variations' => array( remote_item( 'VAR-PARTIAL-A' ) ) ) );
$partial_variations = TCAS_Importer::preview( 'VAR-PARTIAL' );
import_test( 'ایمپورت ناقص وقتی فقط بخشی از تنوع‌ها جزئیات دارند رد می‌شود', 'error' === $partial_variations['row']['status'] );

$variations = array(
	remote_item( 'VAR-1-B', array( 'remote_id' => 2002, 'variation_attributes' => array( 'Color' => 'Black Blue' ), 'stock' => array( 'quantity' => 1.0, 'status' => 'instock' ) ) ),
	remote_item( 'VAR-1-W', array( 'remote_id' => 2003, 'variation_attributes' => array( 'Color' => 'White' ), 'stock' => array( 'quantity' => 0.0, 'status' => 'outofstock' ) ) ),
);
TCAS_Api::$responses['VAR-1'] = remote_item( 'VAR-1', array( 'remote_id' => 2001, 'type' => 'variable', 'variations' => $variations ) );
list( $preview, $result ) = run_import( 'VAR-1' );
$variable_id = is_array( $result ) ? $result['product_id'] : 0;
$children = $variable_id ? get_children( array( 'post_parent' => $variable_id ) ) : array();
import_test( 'محصول متغیر با دو تنوع ساخته می‌شود', $variable_id && 2 === count( $children ) );
import_test( 'تنوع‌ها نگاشت مستقل و والد غیرمستقیم دارند', $variable_id && ! TCAS_Mapping::is_enabled( $variable_id ) && TCAS_Mapping::is_enabled( $children[0] ) && TCAS_Mapping::is_enabled( $children[1] ) );
$variation_identifiers = $children ? TCAS_Mapping::remote_identifiers( $children[0] ) : array();
import_test( 'شناسهٔ تنوع والد برای Endpoint مستقیم ذخیره می‌شود', 2002 === $variation_identifiers['remote_id'] && 2001 === $variation_identifiers['parent_remote_id'] );
import_test( 'ویژگی والد برای تنوع ساخته شده است', $variable_id && 1 === count( $GLOBALS['products'][ $variable_id ]->get_attributes() ) );
$first_variation_attributes = $children ? $GLOBALS['products'][ $children[0] ]->get_attributes() : array();
import_test( 'مقدار Custom Attribute بدون Slug کردن حفظ می‌شود', isset( $first_variation_attributes['color'] ) && 'Black Blue' === $first_variation_attributes['color'] );

$before = count( $GLOBALS['products'] );
TCAS_Api::$responses['BAD-VAR'] = remote_item( 'BAD-VAR', array( 'type' => 'variable', 'variations' => array( remote_item( 'BAD-VAR-A' ) ) ) );
list( $preview, $result ) = run_import( 'BAD-VAR' );
import_test( 'تنوع بدون ویژگی با Rollback رد می‌شود', is_wp_error( $result ) && $before === count( $GLOBALS['products'] ) );

$parent = existing_product( 20, 'EXIST-VAR', 'variable' );
$child = existing_product( 21, 'EXIST-VAR-A', 'variation', 20 );
TCAS_Api::$responses['EXIST-VAR'] = remote_item( 'EXIST-VAR', array( 'type' => 'variable', 'variations' => array( remote_item( 'EXIST-VAR-A', array( 'stock' => array( 'quantity' => 8.0, 'status' => 'instock' ) ) ) ) ) );
list( $preview, $result ) = run_import( 'EXIST-VAR' );
import_test( 'والد موجود و تنوع هم‌کد متصل می‌شوند', is_array( $result ) && TCAS_Mapping::is_enabled( 21 ) && ! TCAS_Mapping::is_enabled( 20 ) && 8.0 === $child->get_stock_quantity() );

$other = existing_product( 30, 'OTHER' );
TCAS_Api::$responses['ROLLBACK'] = remote_item( 'ROLLBACK' );
$preview = TCAS_Importer::preview( 'ROLLBACK' );
TCAS_Mapping::map( 30, 'ROLLBACK', true ); // تداخل بعد از Preview ایجاد می‌شود تا Rollback واقعی آزمون شود.
$before = count( $GLOBALS['products'] );
$result = TCAS_Importer::import( $preview, array( 'images' => false ) );
import_test( 'خطای نگاشت بعد از Save، محصول نیمه‌ساخته را حذف می‌کند', is_wp_error( $result ) && $before === count( $GLOBALS['products'] ) );

TCAS_Api::$responses['STALE'] = remote_item( 'STALE' );
$stale_preview = TCAS_Importer::preview( 'STALE' );
existing_product( 31, 'STALE' );
$stale_result = TCAS_Importer::import( $stale_preview, array( 'images' => false ) );
import_test( 'Preview کهنه نمی‌تواند محصول تکراری بسازد', is_wp_error( $stale_result ) && 'tcas_preview_stale' === $stale_result->get_error_code() );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) { exit( $fail ? 1 : 0 ); }
