<?php
/** Report-index tests using the same SQLite/Woo stubs as the phone regression suite. */
define( 'TCP_TEST_REPORTS', true );
require __DIR__ . '/coupon-phones-tests.php';
require dirname( __DIR__ ) . '/includes/class-tcp-coupon-reports.php';
function wc_get_coupon_id_by_code( $code ) { return isset( $GLOBALS['coupons'][$code] ) ? $GLOBALS['coupons'][$code]->get_id() : 0; }
$wpdb->last_error = '';
$wpdb->db->exec( 'CREATE TABLE wp_tcp_coupon_orders (coupon_id INTEGER, order_id INTEGER, PRIMARY KEY (coupon_id,order_id))' );
$wpdb->db->exec( 'DELETE FROM wp_tcp_coupon_uses' );
$c = new WC_Coupon( 1 ); $c->save();
$c2 = new WC_Coupon( 2 ); $c2->save();
$o = new WC_Order( 15 );
TCP_Coupon_Reports::index_order( $o );
t( 'pending order appears in independent report', 1 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
t( 'report indexing never consumes quota', 0 === TCP_Coupon_Phones::count( 1 ) );
TCP_Coupon_Reports::index_order( $o );
t( 'repeated order save is deduplicated', 1 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
t( 'coupon reports isolated', 0 === TCP_Coupon_Reports::rows( 2, 1 )['total'] );
$o->codes = array( 'test1', 'test2' ); TCP_Coupon_Reports::index_order( $o );
t( 'multi-coupon order appears in both reports', 1 === TCP_Coupon_Reports::rows( 2, 1 )['total'] );
$o->status = 'completed'; TCP_Coupon_Phones::record( $c, $o );
t( 'union with ledger never duplicates order', 1 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
$o2 = new WC_Order( 16 ); $o2->status = 'completed'; TCP_Coupon_Phones::record( $c, $o2 );
t( 'pre-upgrade ledger rows visible before backfill', 2 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
t( 'order filter does not leak another coupon', 0 === TCP_Coupon_Reports::rows( 2, 1, 16 )['total'] );
t( 'exact order search works', array( 16 ) === TCP_Coupon_Reports::rows( 1, 1, 16 )['ids'] );
for ( $i = 20; $i < 50; $i++ ) { TCP_Coupon_Reports::link( 1, $i ); }
t( '25 rows maximum per page', 25 === count( TCP_Coupon_Reports::rows( 1, 1 )['ids'] ) );
t( 'out of range page clamped', 2 === TCP_Coupon_Reports::rows( 1, 900 )['page'] );
$orders = array();
for ( $i = 100; $i <= 200; $i++ ) { $orders[$i] = new WC_Order( $i ); $orders[$i]->codes = array( 'old' ); }
$GLOBALS['orders'] = $orders;
$wpdb->db->exec( 'DELETE FROM wp_woocommerce_order_items' );
foreach ( $orders as $o ) { $wpdb->db->exec( "INSERT INTO wp_woocommerce_order_items (order_id, order_item_type, order_item_name) VALUES ({$o->id}, 'coupon', 'old')" ); }
$c->update_meta_data( TCP_Coupon_Phones::META, array( 'aliases' => array( 'old' ) ) );
$state = TCP_Coupon_Reports::sync( $c );
t( 'backfill first batch bounded and unfinished', 100 === $state['checked'] && ! $state['done'] );
$state = TCP_Coupon_Reports::sync( $c );
t( 'backfill includes second page and pending alias orders', 101 === $state['checked'] && $state['done'] && 133 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
TCP_Coupon_Reports::sync( $c );
t( 'finished import is idempotent without explicit restart', 133 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
TCP_Coupon_Phones::reset( 1 );
t( 'quota reset does not delete report rows', 133 === TCP_Coupon_Reports::rows( 1, 1 )['total'] );
$wpdb->last_error = 'simulated failure';
try { TCP_Coupon_Reports::rows( 1, 1 ); t( 'report must surface DB error', false ); } catch ( Exception $e ) { t( 'report database failure surfaced', true ); }
$wpdb->last_error = '';
define( 'TCP_DIR', dirname( __DIR__ ) . '/' );
function esc_attr( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return esc_html( $v ); }
function wp_date( $fmt, $time = null ) { return gmdate( $fmt, $time ?? time() ); }
function number_format_i18n( $n ) { return number_format( $n ); }
function wp_kses_post( $s ) { return $s; }
function wc_price( $v, $args ) { return number_format( $v ) . ' ' . $args['currency']; }
function wc_get_order_status_name( $s ) { return $s; }
function get_userdata( $id ) { return (object) array( 'display_name' => 'Admin' ); }
class ReportOrder extends WC_Order {
	public function get_order_number() { return 'PUBLIC-' . $this->id; }
	public function get_edit_order_url() { return 'https://example.test/order/' . $this->id; }
	public function get_status() { return $this->status; }
	public function get_currency() { return 'IRT'; }
	public function get_billing_first_name() { return '<script>alert(1)</script>'; }
	public function get_billing_last_name() { return 'Customer'; }
	public function get_billing_email() { return 'customer@example.test'; }
	public function get_total() { return 999000; }
	public function get_total_refunded() { return 12000; }
	public function get_date_completed() { return null; }
	public function get_date_modified() { return new DateTimeImmutable( '@1700000000' ); }
	public function get_payment_method_title() { return 'Test gateway'; }
	public function get_transaction_id() { return 'TX-15'; }
	public function get_items( $type ) { return 'coupon' === $type ? array( new ReportCouponItem() ) : array(); }
}
class ReportCouponItem {
	public function get_code() { return 'test1'; }
	public function get_discount() { return 158000; }
	public function get_discount_tax() { return 1000; }
}
$orders[15] = new ReportOrder( 15 );
$html = TCP_Coupon_Reports::render( $c, 1, 15 );
t( 'report renders current pending status', false !== strpos( $html, 'pending' ) );
t( 'customer output is escaped', false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;' ) );
t( 'report shows discount for this coupon, not all coupons', false !== strpos( $html, '158,000 IRT' ) );
t( 'report includes payment transaction', false !== strpos( $html, 'TX-15' ) );
$orders[15]->status = 'completed';
$orders[15]->phone = '09999999999';
$html = TCP_Coupon_Reports::render( $c, 1, 15 );
t( 'refresh reads changed order status live', false !== strpos( $html, 'completed' ) && false === strpos( $html, '>pending<' ) );
t( 'changed billing phone and original consumption phone both visible', false !== strpos( $html, '09999999999' ) && false !== strpos( $html, '09123456789' ) );
unset( $orders[15] );
$html = TCP_Coupon_Reports::render( $c, 1, 15 );
t( 'deleted order retains historical row without fatal error', false !== strpos( $html, 'سفارش حذف شده' ) );
echo "COMBINED RESULT: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
