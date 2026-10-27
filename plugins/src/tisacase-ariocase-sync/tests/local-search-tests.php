<?php
/** جست‌وجوی محلی محصول: ساخت مرجع، محدودیت طول، حذف تکراری و رتبه‌بندی. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; public function __construct( $code = '', $message = '' ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
require_once dirname( __DIR__ ) . '/includes/class-tcas-local-search.php';
$pass = 0; $fail = 0;
function search_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }

// --- دیتابیس ساختگی: شکل کوئری‌ها و مسیر سریع را می‌سنجد ---
class TCAS_Test_Search_DB {
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $prefix = 'wp_';
	public $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';
	public $queries = array();
	public $columns = array(
		'wp_wc_product_meta_lookup' => array( 'product_id', 'sku', 'stock_quantity', 'stock_status', 'min_price' ),
		'wp_posts'                  => array( 'ID', 'post_title', 'post_type', 'post_parent', 'post_status' ),
	);
	public $tables = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) ) {
				$query = preg_replace( '/%d/', (string) $arg, $query, 1 );
			} else {
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
			}
		}
		return $query;
	}
	public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	public function get_results( $query ) {
		$this->queries[] = $query;
		if ( preg_match( '/^SHOW COLUMNS FROM (\S+)/', $query, $m ) ) {
			if ( ! isset( $this->columns[ $m[1] ] ) ) {
				return array();
			}
			$rows = array();
			foreach ( $this->columns[ $m[1] ] as $name ) {
				$rows[] = (object) array( 'Field' => $name );
			}
			return $rows;
		}
		foreach ( $this->tables as $needle => $rows ) {
			if ( false !== strpos( $query, $needle ) ) {
				if ( is_callable( $rows ) ) {
					return call_user_func( $rows, $query );
				}
				return $rows;
			}
		}
		return array();
	}
	public function seen( $needle ) {
		foreach ( $this->queries as $query ) {
			if ( false !== strpos( $query, $needle ) ) {
				return true;
			}
		}
		return false;
	}
}
function search_ids( ...$ids ) {
	$rows = array();
	foreach ( $ids as $id ) {
		$rows[] = (object) array( 'ID' => $id );
	}
	return $rows;
}
function lookup_row( $id, $sku ) {
	return (object) array( 'product_id' => $id, 'sku' => $sku, 'stock_quantity' => '4', 'stock_status' => 'instock' );
}


// --- مسیر سریع با دیتابیس: بدون JOIN روی postmeta ---
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$GLOBALS['wpdb']->tables = array(
	'wp_wc_product_meta_lookup' => function ( $query ) {
		if ( false !== strpos( $query, "sku = 'TS0100'" ) ) {
			return array( lookup_row( 41, 'TS0100' ) );
		}
		if ( false !== strpos( $query, 'product_id IN' ) ) {
			return array( lookup_row( 41, 'TS0100' ) );
		}
		return array();
	},
	'wp_posts' => function ( $query ) {
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array( (object) array( 'ID' => 41, 'post_title' => 'قاب شیائومی', 'post_type' => 'product', 'post_parent' => 0 ) );
		}
		return array();
	},
);
TCAS_Local_Search::$finder = null;
TCAS_Local_Search::flush_cache();
$fast = TCAS_Local_Search::search( 'TS0100' );
search_test( 'SKU دقیق در یک کوئری ایندکس‌شده پیدا می‌شود', 1 === count( $fast ) && 'TS0100' === $fast[0]['reference'] && 'sku' === TCAS_Local_Search::$last_source );
search_test( 'هیچ کوئری‌ای روی postmeta زده نمی‌شود', ! $GLOBALS['wpdb']->seen( 'wp_postmeta' ), implode( ' | ', $GLOBALS['wpdb']->queries ) );
search_test( 'ردیف‌ها از جدول ایندکس ووکامرس ساخته می‌شوند (SKU و موجودی)', 'TS0100' === $fast[0]['sku'] && 4.0 === $fast[0]['stock']['quantity'] && 'instock' === $fast[0]['stock']['status'] );
search_test( 'شناسهٔ عددی مستقیم همان محصول را می‌دهد', 'TS0100' === TCAS_Local_Search::search( '41' )[0]['reference'] && 'id' === TCAS_Local_Search::$last_source );

// ایندکس FiboSearch وقتی نصب باشد اولویت دارد
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$GLOBALS['wpdb']->columns['wp_dgwt_wcas_index'] = array( 'id', 'post_id', 'name', 'sku', 'sku_variations' );
$GLOBALS['wpdb']->tables = array(
	'wp_dgwt_wcas_index' => function ( $query ) {
		return array( (object) array( 'post_id' => 55 ), (object) array( 'post_id' => 56 ) );
	},
	'wp_wc_product_meta_lookup' => function ( $query ) {
		if ( false !== strpos( $query, 'product_id IN' ) ) {
			return array( lookup_row( 55, 'FIBO-55' ), lookup_row( 56, 'FIBO-56' ) );
		}
		return array();
	},
	'wp_posts' => function ( $query ) {
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array(
				(object) array( 'ID' => 55, 'post_title' => 'قاب پروانه آبی', 'post_type' => 'product', 'post_parent' => 0 ),
				(object) array( 'ID' => 56, 'post_title' => 'قاب پروانه صورتی', 'post_type' => 'product', 'post_parent' => 0 ),
			);
		}
		return array();
	},
);
TCAS_Local_Search::flush_cache();
$fibo = TCAS_Local_Search::search( 'پروانه' );
search_test( 'با ایندکس FiboSearch، جست‌وجو از همان ایندکس انجام می‌شود', 2 === count( $fibo ) && 'fibo' === TCAS_Local_Search::$last_source );
search_test( 'کوئری FiboSearch روی نام/SKU همان جدول است و JOIN روی postmeta ندارد', $GLOBALS['wpdb']->seen( 'FROM wp_dgwt_wcas_index WHERE ( name LIKE' ) && $GLOBALS['wpdb']->seen( 'sku_variations LIKE' ) && ! $GLOBALS['wpdb']->seen( 'wp_postmeta' ) );
search_test( 'عنوان محصولات یک‌جا (IN) خوانده می‌شود، نه یکی‌یکی', 1 === count( array_filter( $GLOBALS['wpdb']->queries, function ( $q ) { return false !== strpos( $q, 'ID IN' ); } ) ) );

// کش: عبارت تکراری هیچ کوئری تازه‌ای نمی‌زند
$before = count( $GLOBALS['wpdb']->queries );
$again = TCAS_Local_Search::search( 'پروانه' );
search_test( 'جست‌وجوی تکراری از حافظهٔ درون‌درخواستی جواب می‌دهد', $before === count( $GLOBALS['wpdb']->queries ) && 2 === count( $again ) && 'memo' === TCAS_Local_Search::$last_source );

// پیشوند SKU وقتی عبارت دقیق نیست
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$GLOBALS['wpdb']->tables = array(
	'wp_wc_product_meta_lookup' => function ( $query ) {
		if ( false !== strpos( $query, "sku LIKE 'TS01%'" ) ) {
			return array( lookup_row( 41, 'TS0100' ) );
		}
		if ( false !== strpos( $query, 'product_id IN' ) ) {
			return array( lookup_row( 41, 'TS0100' ) );
		}
		return array();
	},
	'wp_posts' => function ( $query ) {
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array( (object) array( 'ID' => 41, 'post_title' => 'قاب شیائومی', 'post_type' => 'product', 'post_parent' => 0 ) );
		}
		return array();
	},
);
TCAS_Local_Search::flush_cache();
$prefix = TCAS_Local_Search::search( 'TS01' );
search_test( 'پیشوند SKU با کلید یکتا پیدا می‌شود', 1 === count( $prefix ) && 'TS0100' === $prefix[0]['reference'] && 'sku' === TCAS_Local_Search::$last_source );

// عنوان: اول پیشوند، بعد شامل
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$title_queries = array();
$GLOBALS['wpdb']->tables = array(
	'wp_wc_product_meta_lookup' => function ( $query ) {
		if ( false !== strpos( $query, 'product_id IN' ) ) {
			return array( lookup_row( 70, 'TS70' ) );
		}
		return array();
	},
	'wp_posts' => function ( $query ) use ( &$title_queries ) {
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array( (object) array( 'ID' => 70, 'post_title' => 'قاب پروانه', 'post_type' => 'product', 'post_parent' => 0 ) );
		}
		$title_queries[] = $query;
		if ( false !== strpos( $query, "post_title LIKE 'پروانه%'" ) ) {
			return array();
		}
		if ( false !== strpos( $query, "post_title LIKE '%پروانه%'" ) ) {
			return search_ids( 70 );
		}
		return array();
	},
);
TCAS_Local_Search::flush_cache();
$by_title = TCAS_Local_Search::search( 'پروانه' );
search_test( 'جست‌وجوی عنوان اول پیشوند و بعد «شامل» را امتحان می‌کند', 2 === count( $title_queries ) && 1 === count( $by_title ) && 'title' === TCAS_Local_Search::$last_source );
search_test( 'کوئری عنوان بدون JOIN روی postmeta است', $GLOBALS['wpdb']->seen( 'FROM wp_posts' ) && ! $GLOBALS['wpdb']->seen( 'wp_postmeta' ) );

// Variation: نام والد و برچسب Variation درست ساخته می‌شود
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$GLOBALS['wpdb']->tables = array(
	'wp_wc_product_meta_lookup' => function ( $query ) {
		if ( false !== strpos( $query, 'product_id IN' ) ) {
			return array( (object) array( 'product_id' => 91, 'sku' => 'SHARED', 'stock_quantity' => '0', 'stock_status' => 'outofstock' ) );
		}
		return array();
	},
	'wp_posts' => function ( $query ) {
		if ( false !== strpos( $query, 'ID IN ( 91 )' ) ) {
			return array( (object) array( 'ID' => 91, 'post_title' => 'مشکی', 'post_type' => 'product_variation', 'post_parent' => 90 ) );
		}
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array( (object) array( 'ID' => 90, 'post_title' => 'قاب سامسونگ', 'post_type' => 'product', 'post_parent' => 0 ) );
		}
		return array();
	},
);
TCAS_Local_Search::flush_cache();
$variation_rows = TCAS_Local_Search::search( '91' );
search_test( 'Variation مرجع ID یکتا و نام والد می‌گیرد', 1 === count( $variation_rows ) && 'ID:91' === $variation_rows[0]['reference'] && 'قاب سامسونگ' === $variation_rows[0]['name'] && 'مشکی' === $variation_rows[0]['label'] && 'outofstock' === $variation_rows[0]['stock']['status'] );

// بدون ایندکس ووکامرس: مسیر پشتیبان با ووکامرس
$GLOBALS['wpdb'] = new TCAS_Test_Search_DB();
$GLOBALS['wpdb']->columns = array( 'wp_posts' => array( 'ID' ) );
class TCAS_Test_Search_Product {
	public function get_type() { return 'simple'; }
	public function get_name() { return 'قاب ساده'; }
	public function get_sku() { return 'TS99'; }
	public function get_stock_quantity() { return 2; }
	public function get_stock_status() { return 'instock'; }
}
function wc_get_product( $id ) { return 99 === (int) $id ? new TCAS_Test_Search_Product() : false; }
$GLOBALS['wpdb']->tables = array(
	'wp_posts' => function ( $query ) {
		if ( false !== strpos( $query, 'ID IN' ) ) {
			return array( (object) array( 'ID' => 99, 'post_title' => 'قاب ساده', 'post_type' => 'product', 'post_parent' => 0 ) );
		}
		return array( 'hits' );
	},
);
TCAS_Local_Search::flush_cache();
$fallback = TCAS_Local_Search::search( '99' );
search_test( 'نبود جدول ایندکس ووکامرس به مسیر پشتیبان ووکامرس می‌رود', 1 === count( $fallback ) && 'TS99' === $fallback[0]['reference'] );

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

// --- شکستن عبارت به واژه (جست‌وجوی واقعی مثل بقیهٔ جست‌وجوها) ---
search_test( 'عبارت چندواژه‌ای به واژه‌های جدا شکسته می‌شود', array( 'قاب', 'رویایی', 'پروانه', 'آبی' ) === TCAS_Local_Search::terms( 'قاب رویایی پروانه آبی' ) );
search_test( 'جداکننده‌ها و ویرگول هم واژه‌ها را جدا می‌کنند', array( 'قاب', 'پروانه', 'آبی' ) === TCAS_Local_Search::terms( 'قاب/پروانه، آبی' ) );
search_test( 'نویسهٔ کنترلی راست‌به‌چپ حذف می‌شود', array( 'قاب', 'گوشی' ) === TCAS_Local_Search::terms( "قاب گوشی\u{200e}" ) );
search_test( 'واژه‌های تکراری یک‌بار جست‌وجو می‌شوند', array( 'قاب', 'آبی' ) === TCAS_Local_Search::terms( 'قاب آبی قاب' ) );
search_test( 'واژه‌های تک‌حرفی در عبارت چندواژه‌ای حذف می‌شوند', array( 'قاب', 'آبی' ) === TCAS_Local_Search::terms( 'قاب a آبی' ) );
search_test( 'عبارت تک‌واژه‌ای حتی یک‌حرفی حفظ می‌شود', array( 'a' ) === TCAS_Local_Search::terms( 'a' ) );
search_test( 'تعداد واژه‌ها به شش محدود می‌شود', 6 === count( TCAS_Local_Search::terms( 'aa bb cc dd ee ff gg hh' ) ) );
search_test( 'عبارت خالی هیچ واژه‌ای ندارد', array() === TCAS_Local_Search::terms( '   ' ) );
search_test( 'پرانتز و گیومه از دو طرف واژه حذف می‌شوند', array( 'قاب', 'آبی' ) === TCAS_Local_Search::terms( '(قاب) "آبی"' ) );

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
unset( $GLOBALS['wpdb'] );
TCAS_Local_Search::flush_cache();
search_test( 'نبود دیتابیس و ووکامرس به خطا تبدیل نمی‌شود', array() === TCAS_Local_Search::search( 'محصول' ) );
$detail = TCAS_Local_Search::detail( 0 );
search_test( 'درخواست جزئیات بدون شناسه معتبر رد می‌شود', $detail instanceof WP_Error );
$detail = TCAS_Local_Search::detail( 41 );
search_test( 'بدون Resolver موجود، جزئیات محصول با خطای روشن رد می‌شود', $detail instanceof WP_Error );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
