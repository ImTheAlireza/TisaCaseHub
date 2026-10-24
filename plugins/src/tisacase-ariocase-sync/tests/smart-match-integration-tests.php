<?php
/** مسیر واقعی Smart Match: خروجی خام Normalizer → Resolver → Matcher؛ بدون ساخت محصول یا ذخیرهٔ نگاشت. */
define( 'ABSPATH', __DIR__ . '/' ); define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error {
	private $code; private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class TCAS_JSON_Response extends Exception {
	public $success; public $data; public $status;
	public function __construct( $success, $data, $status ) { $this->success = $success; $this->data = $data; $this->status = $status; parent::__construct( 'json' ); }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return trim( (string) $value ); }
function wp_unslash( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'tcas_admin_nonce' === $action; }
function wp_send_json_success( $data ) { throw new TCAS_JSON_Response( true, $data, 200 ); }
function wp_send_json_error( $data, $status = 400 ) { throw new TCAS_JSON_Response( false, $data, $status ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '' ) { return 'https://tisacase.com' . $path; }
function site_url() { return home_url(); }
function url_to_postid( $url ) { return isset( $GLOBALS['local_reference'] ) && $url === $GLOBALS['local_reference'] ? $GLOBALS['local_parent_id'] : 0; }
class TCAS_Core {
	const NONCE = 'tcas_admin_nonce';
	public static function can() { return true; }
	public static function is_configured() { return true; }
	public static function settings() { return array( 'base_url' => 'https://ariocase.com/wp-json/wc/v3' ); }
}
class TCAS_Api {
	public static $product; public static $reference;
	public static function fetch_reference( $reference ) { self::$reference = $reference; return self::$product; }
}
class TCAS_Integration_Product {
	private $id; private $type; private $name; private $sku; private $attributes; private $children;
	public function __construct( $id, $type, $name, $sku, $attributes = array(), $children = array() ) {
		$this->id = $id; $this->type = $type; $this->name = $name; $this->sku = $sku; $this->attributes = $attributes; $this->children = $children;
	}
	public function get_type() { return $this->type; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_attributes() { return $this->attributes; }
	public function get_children( $visible = true ) { $GLOBALS['children_visibility'][] = $visible; return $this->children; }
}
function wc_get_product( $id ) { return isset( $GLOBALS['integration_products'][ $id ] ) ? $GLOBALS['integration_products'][ $id ] : false; }
require_once dirname( __DIR__ ) . '/includes/class-tcas-link-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-ajax.php';
$pass = 0; $fail = 0;
function smart_integration_test( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; }
}

// شناسه‌ها صرفاً Fixture هستند و به محصولات واقعی فروشگاه اشاره ندارند.
$local_parent_id  = 10000;
$remote_parent_id = 20000;
$local_reference  = 'https://tisacase.com/product/fixture-parent/';
$remote_reference = 'https://ariocase.com/product/fixture-parent/';
$GLOBALS['local_parent_id'] = $local_parent_id;
$GLOBALS['local_reference'] = $local_reference;
$local_labels = array(
	'iPhone 13', 'iPhone 13 Pro Max', 'iPhone 16', 'iPhone 16 Pro Max', 'iPhone 17 Pro Max',
	'Samsung Galaxy S23 Ultra', 'Samsung Galaxy S24 Ultra', 'Samsung Galaxy S25 Ultra',
	'iPhone 11', 'iPhone 12 Pro', 'Samsung Galaxy Note 20', 'Samsung Galaxy A52',
	'Xiaomi Redmi Note 12', 'Poco X6 Pro', 'iPhone 15 Plus', 'Samsung Galaxy Z Flip 5', 'Xiaomi 13T',
);
$remote_labels = array(
	'iPhone 13', 'iphone13promax', 'iPhone 16', 'IPHONE 16 PRO MAX', 'iPhone17 Pro Max',
	'Samsung S23 Ultra', 's24ultra', 's25ultra', 'Samsung A56',
	'iPhone 11 Pro', 'iPhone 12', 'iPhone 12 Pro Max', 'iPhone 14', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
	'iPhone 15', 'iPhone 15 Pro', 'iPhone 15 Pro Max', 'iPhone 16 Plus', 'iPhone 17',
	'Samsung Galaxy S21 FE', 'Samsung Galaxy S22 Ultra', 'Samsung Galaxy A55', 'Samsung Galaxy A54',
	'Samsung Galaxy A35', 'Samsung Galaxy A34', 'Xiaomi Redmi Note 13', 'Xiaomi Redmi Note 13 Pro',
	'Poco F6', 'Poco F6 Pro', 'Xiaomi 14T', 'Xiaomi 14T Pro', 'Nothing Phone 2',
);
$local_ids = array();
foreach ( $local_labels as $index => $label ) {
	$variation_id = $local_parent_id + $index + 1;
	$local_ids[]  = $variation_id;
	$GLOBALS['integration_products'][ $variation_id ] = new TCAS_Integration_Product( $variation_id, 'variation', 'Local - ' . $label, 'SHARED-LOCAL', array( 'Model' => $label ) );
}
// A56 فقط گزینهٔ Attribute والد است و عمداً Variation واقعی ندارد.
$GLOBALS['integration_products'][ $local_parent_id ] = new TCAS_Integration_Product(
	$local_parent_id, 'variable', 'Local parent', 'LOCAL-PARENT',
	array( array( 'name' => 'Model', 'options' => array_merge( $local_labels, array( 'A56' ) ) ) ), $local_ids
);
$raw_remote = array(
	'remote_id' => $remote_parent_id, 'parent_remote_id' => 0, 'code' => '', 'name' => 'Remote parent', 'type' => 'variable', 'variations' => array(),
);
foreach ( $remote_labels as $index => $label ) {
	$raw_remote['variations'][] = array(
		'remote_id' => $remote_parent_id + $index + 1,
		'parent_remote_id' => $remote_parent_id,
		'code' => 'SHARED-REMOTE',
		'name' => 'Remote - ' . $label,
		'variation_attributes' => array( 'Model' => $label ),
	);
}
TCAS_Api::$product = $raw_remote;
$_REQUEST['nonce'] = 'valid'; $_POST['local_code'] = $local_reference; $_POST['remote_code'] = $remote_reference;
try { TCAS_Ajax::match_variations(); $response = null; } catch ( TCAS_JSON_Response $caught ) { $response = $caught; }
smart_integration_test( 'شکل خام API بدون reference/label در مسیر واقعی AJAX پذیرفته می‌شود', $response && $response->success && ! isset( $raw_remote['variations'][0]['reference'], $raw_remote['variations'][0]['label'] ) );
smart_integration_test( 'هر ۱۷ Variation محلی و ۳۳ Variation ریموت شمارش می‌شوند', $response && 17 === $response->data['local']['count'] && 33 === $response->data['remote']['count'] && array( false ) === $GLOBALS['children_visibility'], $response ? json_encode( array( $response->data['local'], $response->data['remote'], $GLOBALS['children_visibility'] ), JSON_UNESCAPED_UNICODE ) : 'no response' );
$matches = $response && $response->success ? $response->data['matches'] : array();
$expected = array( 'iPhone 13', 'iPhone 13 Pro Max', 'iPhone 16', 'iPhone 16 Pro Max', 'iPhone 17 Pro Max', 'Samsung Galaxy S23 Ultra', 'Samsung Galaxy S24 Ultra', 'Samsung Galaxy S25 Ultra' );
smart_integration_test( 'هشت معادل یکتای مورد انتظار پیدا می‌شوند', array_column( $matches, 'local_label' ) === $expected, json_encode( array_column( $matches, 'local_label' ), JSON_UNESCAPED_UNICODE ) );
smart_integration_test( 'از ۵۰ تنوع فقط ۳۴ مورد بدون جفت می‌مانند', $response && 34 === count( $response->data['ambiguous'] ) + count( $response->data['unmatched'] ), $response ? ( count( $response->data['ambiguous'] ) . ' ambiguous + ' . count( $response->data['unmatched'] ) . ' unmatched' ) : 'no response' );
$exact_references = 8 === count( $matches );
foreach ( $matches as $index => $match ) {
	$exact_references = $exact_references && 'ID:' . ( $local_parent_id + $index + 1 ) === $match['local_code'] && 1 === preg_match( '/^ID:' . $remote_parent_id . '\\/[1-9][0-9]*$/', $match['remote_code'] );
}
smart_integration_test( 'خروجی فقط مرجع ID دقیق Local و Parent/Variation ریموت دارد', $exact_references && $remote_reference === TCAS_Api::$reference );
$remaining = $response && $response->success ? array_merge( $response->data['ambiguous'], $response->data['unmatched'] ) : array();
$a56_remaining = false; $a56_matched = false;
foreach ( $remaining as $item ) { if ( 'a56' === TCAS_Matcher::normalize_label( $item['label'] ) && 'ariocase' === $item['shop'] ) $a56_remaining = true; }
foreach ( $matches as $match ) { if ( 'a56' === TCAS_Matcher::normalize_label( $match['local_label'] ) || 'a56' === TCAS_Matcher::normalize_label( $match['remote_label'] ) ) $a56_matched = true; }
smart_integration_test( 'A56 بدون Variation واقعی Local از Attribute والد جفت نمی‌شود', $a56_remaining && ! $a56_matched && 17 === count( $local_ids ) );
smart_integration_test( 'برچسب s25ultra از variation_attributes خام بازیابی شده است', $response && 's25ultra' === $matches[7]['remote_label'] );
echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
