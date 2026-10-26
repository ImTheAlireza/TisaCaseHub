<?php
/** Resource-bound and batching regressions for Tisa Product ZIP Importer. */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['tpi_transients'] = array();
$GLOBALS['tpi_options'] = array();
$GLOBALS['tpi_scheduled_actions'] = 0;
$GLOBALS['tpi_password_counter'] = 0;
$GLOBALS['tpi_syncs'] = 0;
$GLOBALS['tpi_test_pass'] = 0;
$GLOBALS['tpi_test_fail'] = 0;
$GLOBALS['tpi_saved_skus'] = array( 'CASE4' => 1, 'CASE7' => 7 );
$GLOBALS['tpi_variations'] = array();
$GLOBALS['tpi_query_log'] = array();
$GLOBALS['tpi_existing_ids'] = array();

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $value ) ); }
function update_post_meta( $id, $key, $value ) { return true; }
function get_post_meta( $id, $key, $single = false ) { return isset( $GLOBALS['tpi_meta'][ $id ][ $key ] ) ? $GLOBALS['tpi_meta'][ $id ][ $key ] : ''; }
function clean_post_cache( $id ) {}
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['tpi_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return isset( $GLOBALS['tpi_transients'][ $key ] ) ? $GLOBALS['tpi_transients'][ $key ] : false; }
function delete_transient( $key ) { unset( $GLOBALS['tpi_transients'][ $key ] ); return true; }
function add_option( $key, $value = '', $deprecated = '', $autoload = null ) { if ( isset( $GLOBALS['tpi_options'][ $key ] ) ) return false; $GLOBALS['tpi_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['tpi_options'][ $key ] ); return true; }
function wp_generate_password( $length = 12, $special = true, $extra = false ) { $char = chr(97 + ($GLOBALS['tpi_password_counter']++ % 26)); return str_repeat( $char, $length ); }
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false ) { return ++$GLOBALS['tpi_scheduled_actions']; }
function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { return true; }
function wp_delete_attachment( $id, $force = false ) { return true; }
function wp_delete_post( $id, $force = false ) { return true; }
function get_children( $args = array() ) { return array(); }
function wc_get_product_id_by_sku( $sku ) { return isset( $GLOBALS['tpi_saved_skus'][ $sku ] ) ? $GLOBALS['tpi_saved_skus'][ $sku ] : 0; }
function tpi_test( $name, $condition ) {
	if ( $condition ) { $GLOBALS['tpi_test_pass']++; echo "OK {$name}\n"; }
	else { $GLOBALS['tpi_test_fail']++; echo "FAIL {$name}\n"; }
}

class TPI_Test_DB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
	public function prepare( $query, ...$args ) {
		$GLOBALS['tpi_query_log'][] = $query;
		return $query;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES' ) ) return 'wp_wc_product_meta_lookup';
		if ( false !== strpos( $query, 'wc_product_meta_lookup' ) ) return 7;
		return null;
	}
	public function get_col( $query ) { return $GLOBALS['tpi_existing_ids']; }
}
$GLOBALS['wpdb'] = new TPI_Test_DB();

class WC_Product_Variable { public static function sync( $product_id ) { $GLOBALS['tpi_syncs']++; } }

class WC_Product_Variation {
	private $parent;
	private $attributes = array();
	private $status;
	private $price;
	public function set_parent_id( $id ) { $this->parent = $id; }
	public function set_status( $status ) { $this->status = $status; }
	public function set_regular_price( $price ) { $this->price = $price; }
	public function set_price( $price ) { $this->price = $price; }
	public function set_manage_stock( $value ) {}
	public function set_stock_status( $status ) {}
	public function set_attributes( $attributes ) { $this->attributes = $attributes; }
	public function save() { $id = count( $GLOBALS['tpi_variations'] ) + 100; $GLOBALS['tpi_variations'][] = array( 'id' => $id, 'parent' => $this->parent, 'attrs' => $this->attributes, 'price' => $this->price, 'status' => $this->status ); return $id; }
}

require_once dirname( __DIR__ ) . '/tisa-product-importer.php';

function tpi_call_private( $method, ...$args ) {
	$reflection = new ReflectionClass( 'Tisa_Product_Zip_Importer' );
	$call = $reflection->getMethod( $method );
	$call->setAccessible( true );
	return $call->invokeArgs( null, $args );
}

$valid_entry = array( 'name' => 'product.json', 'size' => 100, 'compressed_size' => 50 );
tpi_test( 'ZIP entryهای مجاز قبول می‌شوند', tpi_call_private( 'validate_zip_entries', array( $valid_entry ) )['uncompressed_bytes'] === 100 );
tpi_test( 'نبود product.json پیش از extract رد می‌شود', false !== strpos( tpi_catch_message( array( array( 'name' => 'other.txt', 'size' => 10, 'compressed_size' => 10 ) ) ), 'دقیقاً یک product.json' ) );
tpi_test( 'مسیر traversal در ZIP رد می‌شود', false !== strpos( tpi_catch_message( array_merge( array( $valid_entry ), array( array( 'name' => '../escape.txt', 'size' => 1, 'compressed_size' => 1 ) ) ) ), 'مسیر ناامن' ) );
tpi_test( 'symlink داخل ZIP رد می‌شود', false !== strpos( tpi_catch_message( array( array( 'name' => 'link', 'size' => 1, 'compressed_size' => 1, 'symlink' => true ) ) ), 'مسیر ناامن' ) );
tpi_test( 'حجم uncompressed از سقف رد نمی‌شود', false !== strpos( tpi_catch_message( array( array( 'name' => 'large.bin', 'size' => Tisa_Product_Zip_Importer::MAX_UNCOMPRESSED_BYTES + 1, 'compressed_size' => 1 ) ) ), '۱۲۸ مگابایت' ) );
tpi_test( 'تعداد entryهای ZIP محدود است', false !== strpos( tpi_catch_message( array_fill( 0, Tisa_Product_Zip_Importer::MAX_ZIP_ENTRIES + 1, $valid_entry ) ), '۲۰۰' ) );
$images = array();
for ( $i = 0; $i <= Tisa_Product_Zip_Importer::MAX_IMAGES; $i++ ) $images[] = array( 'name' => 'img-' . $i . '.jpg', 'size' => 100, 'compressed_size' => 100 );
tpi_test( 'تعداد تصاویر داخل ZIP محدود است', false !== strpos( tpi_catch_message( $images ), '۴۰ تصویر' ) );
tpi_test( 'product.json تکراری مبهم رد می‌شود', false !== strpos( tpi_catch_message( array( $valid_entry, array( 'name' => 'sub/product.json', 'size' => 10, 'compressed_size' => 10 ) ) ), 'دقیقاً یک product.json' ) );

$axes = array( 'مدل' => array( 'A', 'B' ), 'رنگ' => array( 'قرمز', 'آبی', 'سبز' ) );
tpi_test( 'حاصل‌ضرب variationها پیش از ساخت درست شمرده می‌شود', tpi_call_private( 'variation_count', $axes ) === 6 );
tpi_test( 'ترکیب با index تولید می‌شود بدون ساخت matrix کامل', tpi_call_private( 'combination_at', $axes, 4 ) === array( 'مدل' => 'B', 'رنگ' => 'آبی' ) );
$too_many = array( 'a' => array_fill( 0, 5, 'a' ), 'b' => array_fill( 0, 5, 'b' ), 'c' => array_fill( 0, 5, 'c' ), 'd' => array_fill( 0, 5, 'd' ) );
tpi_test( 'حاصل‌ضرب بیش از ۵۰۰ پیش از هر ساختی رد می‌شود', false !== strpos( tpi_catch_variation_error( $too_many ), '۵۰۰' ) );
$batch_axes = array( 'model' => array( 'A', 'B' ), 'color' => array( 'red', 'blue', 'green' ) );
tpi_call_private( 'make_variations_batch', 77, $batch_axes, 10, array(), 2, 2 );
tpi_test( 'هر batch فقط همان slice محدود را می‌سازد', count( $GLOBALS['tpi_variations'] ) === 2 && $GLOBALS['tpi_variations'][0]['attrs'] === array( 'model' => 'a', 'color' => 'green' ) );
$job_dir = '/tmp/tpi-import-job-test';
if (!is_dir('/tmp')) @mkdir('/tmp', 0700, true);
if (!is_dir($job_dir)) @mkdir($job_dir, 0700, true);
$job_axes = array( 'model' => array( 'a', 'b', 'c', 'd', 'e' ), 'color' => array( 'red', 'blue', 'green', 'black', 'white' ) );
$job_id = tpi_call_private( 'queue_import_job', 88, $job_axes, 10, array(), $job_dir, array(), 25 );
$before_job_variations = count($GLOBALS['tpi_variations']);
tpi_call_private( 'process_import_batch', $job_id );
$job_after_first = get_transient( 'tisa_import_job_' . $job_id );
tpi_test( 'کار بزرگ پس‌زمینه در batchهای حداکثر ۲۰تایی جلو می‌رود', is_array($job_after_first) && $job_after_first['variation_offset'] === 20 && count($GLOBALS['tpi_variations']) - $before_job_variations === 20 && $GLOBALS['tpi_scheduled_actions'] >= 2 );
tpi_call_private( 'process_import_batch', $job_id );
$job_after_second = get_transient( 'tisa_import_job_' . $job_id );
tpi_test( 'کار پس‌زمینه پس از batch آخر sync می‌شود و کامل علامت می‌خورد', is_array($job_after_second) && $job_after_second['status'] === 'complete' && count($GLOBALS['tpi_variations']) - $before_job_variations === 25 && $GLOBALS['tpi_syncs'] === 1 );

$GLOBALS['tpi_existing_ids'] = range(1, Tisa_Product_Zip_Importer::MAX_EXISTING_VARIATIONS);
tpi_test( 'تا ۵۰۰ variation موجود برای پیش‌فحص محدود خوانده می‌شود', count(tpi_call_private('existing_variation_ids', 55)) === 500 );
$GLOBALS['tpi_existing_ids'] = range(1, Tisa_Product_Zip_Importer::MAX_EXISTING_VARIATIONS + 1);
tpi_test( 'بیش از ۵۰۰ variation موجود پیش از restock رد می‌شود', false !== strpos(tpi_catch_existing_variation_error(55), 'بیش از ۵۰۰') );
$GLOBALS['tpi_existing_ids'] = range(1, 21);
$update_plan = tpi_call_private('update_queue_plan', 55, array('price'=>25), '/tmp/not-used');
tpi_test( 'شارژ محصول با بیش از ۲۰ variation موجود به صف پس‌زمینه می‌رود', $update_plan['queued'] === true && $update_plan['variation_count'] === 0 );
$update_parent = '/tmp/tpi-update-job-test';
$update_dir = $update_parent . '/payload';
if (!is_dir($update_parent)) @mkdir($update_parent, 0700, true);
if (!is_dir($update_dir)) @mkdir($update_dir, 0700, true);
$update_job_id = tpi_call_private('queue_update_job', 55, array('price'=>25), $update_dir, 0);
$update_job = get_transient('tisa_import_job_' . $update_job_id);
tpi_test( 'شارژهای بزرگ با نوع job به‌درستی وارد Action Scheduler می‌شوند', is_array($update_job) && $update_job['type'] === 'update' && $update_job['status'] === 'queued' );
delete_transient('tisa_import_job_' . $update_job_id);
tpi_call_private('remove_dir', $update_dir);
@rmdir($update_parent);

$sku = tpi_call_private( 'next_sku', 'CASE' );
tpi_test( 'تولید SKU به lookup-table ایندکس‌شده متکی است', $sku === 'CASE8' && false !== strpos( implode( ' ', $GLOBALS['tpi_query_log'] ), 'wc_product_meta_lookup' ) );
tpi_test( 'ZIP data با axisهای نامحدود رد می‌شود', false !== strpos( tpi_catch_data_error( array( 'attributes' => array_fill( 0, 13, array( 'x', 'y' ) ) ) ), 'ویژگی‌ها' ) );

function tpi_catch_message( $entries ) {
	try { tpi_call_private( 'validate_zip_entries', $entries ); return ''; }
	catch ( Throwable $error ) { return $error->getMessage(); }
}
function tpi_catch_variation_error( $attrs ) {
	try { tpi_call_private( 'variation_count', $attrs ); return ''; }
	catch ( Throwable $error ) { return $error->getMessage(); }
}
function tpi_catch_data_error( $data ) {
	try { tpi_call_private( 'validate_product_data', $data ); return ''; }
	catch ( Throwable $error ) { return $error->getMessage(); }
}
function tpi_catch_existing_variation_error( $product_id ) {
	try { tpi_call_private( 'existing_variation_ids', $product_id ); return ''; }
	catch ( Throwable $error ) { return $error->getMessage(); }
}

echo "Result: {$GLOBALS['tpi_test_pass']} passed, {$GLOBALS['tpi_test_fail']} failed\n";
exit( $GLOBALS['tpi_test_fail'] === 0 ? 0 : 1 );
