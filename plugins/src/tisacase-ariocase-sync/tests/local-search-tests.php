<?php
/** جست‌وجوی محلی محصول: ساخت مرجع، محدودیت طول، حذف تکراری و رتبه‌بندی. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; public function __construct( $code = '', $message = '' ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
require_once dirname( __DIR__ ) . '/includes/class-tcas-local-search.php';
$pass = 0; $fail = 0;
function search_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }

// --- قواعد ساخت مرجع ---
$simple = TCAS_Local_Search::row( array( 'product_id' => 41, 'type' => 'simple', 'sku' => 'TS0100', 'name' => 'قاب شیائومی' ) );
search_test( 'محصول ساده با SKU، همان SKU را مرجع می‌گیرد', is_array( $simple ) && 'TS0100' === $simple['reference'] && 'simple' === $simple['type'] );
$no_sku = TCAS_Local_Search::row( array( 'product_id' => 42, 'type' => 'simple', 'sku' => '  ', 'name' => 'بدون SKU' ) );
search_test( 'محصول ساده بدون SKU مرجع ID می‌گیرد', is_array( $no_sku ) && 'ID:42' === $no_sku['reference'] );
$variation = TCAS_Local_Search::row( array( 'product_id' => 77, 'parent_id' => 70, 'type' => 'variation', 'sku' => 'SHARED', 'name' => 'قاب', 'label' => 'مشکی' ) );
search_test( 'Variation تیساکیس همیشه مرجع ID یکتا می‌گیرد تا SKU مشترک والد مانع نشود', is_array( $variation ) && 'ID:77' === $variation['reference'] && 'SHARED' === $variation['sku'] && 70 === $variation['parent_id'] );
search_test( 'موجودی هر ردیف از همان محصول خوانده می‌شود', is_array( TCAS_Local_Search::row( array( 'product_id' => 5, 'type' => 'simple', 'sku' => 'A', 'quantity' => '3', 'status' => 'instock' ) ) ) && 3.0 === TCAS_Local_Search::row( array( 'product_id' => 5, 'type' => 'simple', 'sku' => 'A', 'quantity' => '3', 'status' => 'instock' ) )['stock']['quantity'] );
search_test( 'کاندید بدون شناسهٔ محصول نادیده گرفته می‌شود', null === TCAS_Local_Search::row( array( 'product_id' => 0, 'sku' => 'X' ) ) && null === TCAS_Local_Search::row( 'not-an-array' ) );
search_test( 'نام خالی جای خود را به مرجع می‌دهد تا ردیف بی‌عنوان نماند', 'TS9' === TCAS_Local_Search::row( array( 'product_id' => 9, 'type' => 'simple', 'sku' => 'TS9' ) )['name'] );

// --- طول عبارت ---
search_test( 'عبارت یک‌حرفی جست‌وجو نمی‌شود', TCAS_Local_Search::too_short( 'a' ) && TCAS_Local_Search::too_short( ' ' ) );
search_test( 'عبارت دوحرفی یا بیشتر مجاز است', ! TCAS_Local_Search::too_short( 'ab' ) && ! TCAS_Local_Search::too_short( 'قاب' ) );
search_test( 'عبارت کوتاه هیچ کوئری‌ای اجرا نمی‌کند', array() === TCAS_Local_Search::search( 'a' ) && array() === TCAS_Local_Search::search( '   ' ) );

// --- جست‌وجو با منبع تستی ---
$calls = 0;
TCAS_Local_Search::$finder = function ( $term, $limit ) use ( &$calls ) {
	$calls++;
	return array(
		array( 'product_id' => 11, 'type' => 'simple', 'sku' => 'TS11', 'name' => 'قاب شیائومی', 'quantity' => 2, 'status' => 'instock' ),
		array( 'product_id' => 11, 'type' => 'simple', 'sku' => 'TS11', 'name' => 'قاب شیائومی تکراری' ),
		array( 'product_id' => 12, 'type' => 'variation', 'parent_id' => 10, 'sku' => 'TS12', 'name' => 'قاب سامسونگ', 'label' => 'آبی' ),
		array( 'product_id' => 0, 'sku' => 'BAD' ),
		array( 'product_id' => 13, 'type' => 'simple', 'sku' => 'TS13', 'name' => 'کاور' ),
	);
};
$rows = TCAS_Local_Search::search( 'قاب' );
search_test( 'ردیف‌های تکراری و بدون شناسه حذف می‌شوند', 3 === count( $rows ) && 1 === $calls, json_encode( array_column( $rows, 'reference' ) ) );
search_test( 'ترتیب مرجع‌ها با ترتیب کاندیدها یکی است', array( 'TS11', 'ID:12', 'TS13' ) === array_column( $rows, 'reference' ) );
TCAS_Local_Search::$finder = function () {
	return array(
		array( 'product_id' => 21, 'type' => 'simple', 'sku' => 'TS21', 'name' => 'الف' ),
		array( 'product_id' => 22, 'type' => 'simple', 'sku' => 'TS22', 'name' => 'ب' ),
		array( 'product_id' => 23, 'type' => 'simple', 'sku' => 'EXACT', 'name' => 'ج' ),
	);
};
$ranked = TCAS_Local_Search::search( 'exact' );
search_test( 'تطبیق دقیق SKU اول فهرست می‌آید', 'EXACT' === $ranked[0]['reference'] && 3 === count( $ranked ) );
search_test( 'حرف بزرگ و کوچک در رتبه‌بندی یکسان است', 'EXACT' === TCAS_Local_Search::search( 'ExAcT' )[0]['reference'] );
TCAS_Local_Search::$finder = function () {
	$items = array();
	for ( $i = 1; $i <= 60; $i++ ) {
		$items[] = array( 'product_id' => $i, 'type' => 'simple', 'sku' => 'SKU-' . $i, 'name' => 'محصول ' . $i );
	}
	return $items;
};
search_test( 'تعداد نتایج به سقف پیش‌فرض محدود می‌شود', TCAS_Local_Search::LIMIT === count( TCAS_Local_Search::search( 'محصول' ) ) );
search_test( 'سقف درخواستی هرگز از حداکثر مجاز بیشتر نمی‌شود', TCAS_Local_Search::MAX_LIMIT === count( TCAS_Local_Search::search( 'محصول', 500 ) ) );
search_test( 'سقف کوچک‌تر رعایت می‌شود', 3 === count( TCAS_Local_Search::search( 'محصول', 3 ) ) );
TCAS_Local_Search::$finder = null;
search_test( 'نبود دیتابیس و ووکامرس به خطا تبدیل نمی‌شود', array() === TCAS_Local_Search::search( 'محصول' ) );
$detail = TCAS_Local_Search::detail( 0 );
search_test( 'درخواست جزئیات بدون شناسه معتبر رد می‌شود', $detail instanceof WP_Error );
$detail = TCAS_Local_Search::detail( 41 );
search_test( 'بدون Resolver موجود، جزئیات محصول با خطای روشن رد می‌شود', $detail instanceof WP_Error );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
