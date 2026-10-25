<?php
/**
 * آزمون واقعی Normalizer با PHP؛ بدون وردپرس/ووکامرس.
 * اجرا در هاست: php tests/normalizer-tests.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) { return trim( (string) $value ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( ...$args ) {}
}

require_once dirname( __DIR__ ) . '/includes/class-tcas-core.php';
require_once dirname( __DIR__ ) . '/includes/class-tcas-normalizer.php';

$pass = 0;
$fail = 0;
function tcas_test( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "OK   {$label}\n";
	} else {
		$fail++;
		echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n";
	}
}
function tcas_product( $payload, $code = 'CH1', $settings = array() ) {
	return TCAS_Normalizer::product( $payload, $code, $settings );
}

$simple = tcas_product( array( array( 'sku' => 'CH1', 'name' => 'قاب', 'stock_quantity' => 7, 'stock_status' => 'instock', 'regular_price' => '250000' ) ) );
tcas_test( 'فهرست تک‌محصولی ووکامرس', is_array( $simple ) && 7.0 === $simple['stock']['quantity'] && 'قاب' === $simple['name'] );
tcas_test( 'قیمت عادی خوانده می‌شود', 250000.0 === $simple['price'] );

$nested = tcas_product( array( 'success' => true, 'data' => array( 'product' => array( 'code' => 'CH1', 'title' => 'Case', 'inventory' => array( 'quantity' => '۱۲', 'status' => 'available' ) ) ) ) );
tcas_test( 'Wrapper تودرتو + ارقام فارسی + موجودی تودرتو', is_array( $nested ) && 12.0 === $nested['stock']['quantity'] && 'instock' === $nested['stock']['status'] );

$single_without_sku = tcas_product( array( array( 'stock' => 4 ) ), 'CH1' );
tcas_test( 'تک‌نتیجهٔ بدون SKU برای کد درخواستی پذیرفته نمی‌شود', is_wp_error( $single_without_sku ) && 'tcas_code_mismatch' === $single_without_sku->get_error_code() );
$list = tcas_product( array( 'data' => array( 'products' => array( array( 'sku' => 'OTHER', 'stock' => 1 ), array( 'sku' => 'CH1', 'stock' => 4 ) ) ) ) );
tcas_test( 'انتخاب کد دقیق از فهرست چندمحصولی', is_array( $list ) && 4.0 === $list['stock']['quantity'] );
$missing = tcas_product( array( 'data' => array( 'products' => array( array( 'sku' => 'A' ), array( 'sku' => 'B' ) ) ) ) );
tcas_test( 'نبود کد در فهرست چندمحصولی خطا است', is_wp_error( $missing ) );

$zero = tcas_product( array( 'stock' => 0 ) );
tcas_test( 'صفر واقعی حفظ و به ناموجود تبدیل می‌شود', is_array( $zero ) && 0.0 === $zero['stock']['quantity'] && 'outofstock' === $zero['stock']['status'] );
$available = tcas_product( array( 'available' => true ) );
tcas_test( 'available بولی، موجودی عددی نمی‌شود', is_array( $available ) && null === $available['stock']['quantity'] && 'instock' === $available['stock']['status'] );
$unavailable = tcas_product( array( 'in_stock' => false ) );
tcas_test( 'in_stock=false تشخیص ناموجود', is_array( $unavailable ) && 'outofstock' === $unavailable['stock']['status'] );

$negative = tcas_product( array( 'stock' => -4 ) );
tcas_test( 'عدد منفی به موجودی معتبر تبدیل نمی‌شود', is_array( $negative ) && null === $negative['stock']['quantity'] );
$invalid = tcas_product( array( 'success' => true, 'message' => 'ok' ) );
tcas_test( 'JSON بدون محصول خطای ساختار می‌دهد', is_wp_error( $invalid ) );

$custom = tcas_product( array( 'payload' => array( 'item' => array( 'identity' => 'CH1', 'warehouse' => array( 'free' => '1,234' ) ) ) ), 'CH1', array( 'item_path' => 'payload.item', 'field_code' => 'identity', 'field_stock' => 'warehouse.free' ) );
tcas_test( 'مسیرهای سفارشی + جداکنندهٔ هزارگان', is_array( $custom ) && 1234.0 === $custom['stock']['quantity'] );
$bad_path = tcas_product( array( 'data' => array() ), 'CH1', array( 'item_path' => 'data.product' ) );
tcas_test( 'مسیر سفارشی اشتباه Fallback مخفی ندارد', is_wp_error( $bad_path ) && 'tcas_item_path' === $bad_path->get_error_code() );

$variable = tcas_product( array(
	'sku' => 'CH1', 'name' => 'قاب متغیر', 'type' => 'variable',
	'attributes' => array( array( 'name' => 'رنگ', 'options' => array( 'مشکی', 'سفید' ) ) ),
	'images' => array( array( 'src' => 'https://example.com/a.jpg' ), 'https://example.com/b.jpg' ),
	'variations' => array(
		array( 'sku' => 'CH1-B', 'attributes' => array( array( 'name' => 'رنگ', 'option' => 'مشکی' ) ), 'stock_quantity' => 2, 'price' => 125000 ),
		array( 'sku' => 'CH1-W', 'attributes' => array( 'رنگ' => 'سفید' ), 'stock' => array( 'quantity' => 0 ) ),
	),
) );
tcas_test( 'محصول متغیر و دو تنوع نرمال می‌شود', is_array( $variable ) && 'variable' === $variable['type'] && 2 === count( $variable['variations'] ) );
tcas_test( 'ویژگی option و assoc تنوع پشتیبانی می‌شود', is_array( $variable ) && 'مشکی' === $variable['variations'][0]['variation_attributes']['رنگ'] && 'سفید' === $variable['variations'][1]['variation_attributes']['رنگ'] );
tcas_test( 'موجودی هر تنوع مستقل است', is_array( $variable ) && 2.0 === $variable['variations'][0]['stock']['quantity'] && 'outofstock' === $variable['variations'][1]['stock']['status'] );
tcas_test( 'تصویر object و رشته هر دو خوانده می‌شود', is_array( $variable ) && 2 === count( $variable['images'] ) );

$ids_only = tcas_product( array( 'sku' => 'CH1', 'type' => 'variable', 'variations' => array( 11, 12 ) ) );
tcas_test( 'فهرست فقط-ID تنوع کامل علامت‌گذاری نمی‌شود', is_array( $ids_only ) && ! empty( $ids_only['variations_missing'] ) && array() === $ids_only['variations'] );

$fallback = tcas_product( array( 'quantity' => 3 ), 'ARIO-X' );
tcas_test( 'پاسخ تک‌محصولی بدون کد، کد درخواست را حفظ می‌کند', is_array( $fallback ) && 'ARIO-X' === $fallback['code'] );
tcas_test( 'اعداد نامعتبر Null می‌شوند', null === TCAS_Normalizer::normalize_number( 'abc' ) && null === TCAS_Normalizer::normalize_number( INF ) );
tcas_test( 'وضعیت فارسی تشخیص داده می‌شود', 'instock' === TCAS_Normalizer::normalize_status( 'موجود' ) && 'outofstock' === TCAS_Normalizer::normalize_status( 'ناموجود' ) );
tcas_test( 'JSON List خالی محصول جعلی نمی‌سازد', is_wp_error( tcas_product( array() ) ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) {
	exit( $fail ? 1 : 0 );
}
