<?php
/** Isolated ledger regression suite; SQLite adapter, not a WooCommerce integration test. */
define( 'ABSPATH', __DIR__ . '/' );
function wp_parse_args( $a, $b ) { return array_merge( $b, $a ); }
function absint( $n ) { return abs( (int) $n ); }
function get_current_user_id() { return 7; }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return $v; }
function sanitize_text_field( $v ) { return trim( $v ); }
function wc_format_coupon_code( $v ) { return strtolower( $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function wp_die( $v ) { throw new Exception( $v ); }
function wc_add_notice( $v, $type ) { $GLOBALS['notices'][] = $v; }
function WC() { return $GLOBALS['wc']; }
function wc_get_order( $id ) { return $GLOBALS['orders'][ $id ] ?? null; }
function wc_get_order_statuses() { return array_fill_keys( array( 'pending', 'failed', 'cancelled', 'on-hold', 'processing', 'completed', 'refunded' ), '' ); }
function wc_get_orders( $args ) {
	$GLOBALS['last_args'] = $args;
	$out = array();
	foreach ( $GLOBALS['orders'] as $id => $o ) {
		if ( isset( $args['include'] ) && ! in_array( (int) $id, array_map( 'intval', $args['include'] ), true ) ) { continue; }
		$out[] = $o;
	}
	return array_slice( $out, 0, $args['limit'] ?? 100 );
}
class WC_Coupon {
	public $id, $meta = array(), $code, $native_limit = 3, $per_user = 1;
	public function __construct( $id = 1 ) {
		if ( is_string( $id ) && isset( $GLOBALS['coupons'][ $id ] ) ) {
			$c = $GLOBALS['coupons'][ $id ]; $this->id = $c->id; $this->code = $c->code; $this->meta = $c->meta;
		} else { $this->id = $id; $this->code = 'test' . $id; }
	}
	public function get_id() { return $this->id; }
	public function get_code() { return $this->code; }
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function set_usage_limit( $v ) { $this->native_limit = $v; }
	public function set_usage_limit_per_user( $v ) { $this->per_user = $v; }
	public function save() { $GLOBALS['coupons'][ $this->code ] = $this; }
}
class WC_Order {
	public $id, $status = 'pending', $phone = '09123456789', $codes = array( 'test1' ), $paid = false;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function get_type() { return 'shop_order'; }
	public function get_meta( $k ) { return ''; }
	public function get_billing_phone() { return $this->phone; }
	public function get_customer_id() { return 0; }
	public function get_coupon_codes() { return $this->codes; }
	public function has_status( $s ) { return in_array( $this->status, $s, true ); }
	public function get_date_paid() { return $this->paid ? new DateTimeImmutable( '@1700000000' ) : null; }
	public function get_date_created() { return new DateTimeImmutable( '@1690000000' ); }
}
class TestDB {
	public $prefix = 'wp_', $db, $last_error = '';
	public function __construct() {
		$this->db = new SQLite3( ':memory:' );
		$this->db->exec( 'CREATE TABLE wp_tcp_coupon_uses (id INTEGER PRIMARY KEY, coupon_id INTEGER, order_id INTEGER, phone TEXT, used_at INTEGER, released INTEGER DEFAULT 0, reset_by INTEGER DEFAULT 0, reset_at INTEGER DEFAULT 0, UNIQUE(coupon_id,order_id))' );
		$this->db->exec( 'CREATE TABLE wp_woocommerce_order_items (order_item_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, order_item_type TEXT, order_item_name TEXT)' );
	}
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) { $sql = preg_replace_callback( '/%[ds]/', function ( $m ) use ( $arg ) { return '%d' === $m[0] ? (string) (int) $arg : "'" . SQLite3::escapeString( $arg ) . "'"; }, $sql, 1 ); }
		return $sql;
	}
	public function query( $sql ) { return $this->db->exec( str_replace( 'INSERT IGNORE', 'INSERT OR IGNORE', $sql ) ); }
	public function get_var( $sql ) { return $this->db->querySingle( $sql ); }
	public function get_col( $sql ) { return array_map( function ( $r ) { return $r->order_id; }, $this->get_results( $sql ) ); }
	public function get_results( $sql ) {
		$r = $this->db->query( $sql ); $out = array();
		while ( $row = $r->fetchArray( SQLITE3_ASSOC ) ) { $out[] = (object) $row; }
		return $out;
	}
}
require dirname( __DIR__ ) . '/includes/class-tcp-coupon-phones.php';
$wpdb = new TestDB();
$pass = 0; $fail = 0;
function t( $label, $value ) { global $pass, $fail; if ( $value ) { $pass++; echo "OK $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
function ready( $c ) { $c->update_meta_data( '_tcp_phone_sync', array( 'done' => true ) ); $c->save(); }
$c = new WC_Coupon( 1 );
$input = array( 'phone_policy_present' => 1, 'phone_enabled' => 1, 'phone_selected' => 1, 'phone_list' => "۰۹۱۲۳۴۵۶۷۸۹\n+989123456789", 'phone_limit' => 1 );
foreach ( array( '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '+98 912 345 6789', '00989123456789', '9123456789', '0912-345-6789' ) as $phone ) {
	t( 'normalize ' . $phone, '09123456789' === TCP_Coupon_Phones::phone( $phone ) );
}
foreach ( array( '', '12309123456789', 'abc09123456789', array(), '091234567890', '+33123456789' ) as $phone ) {
	t( 'reject malformed phone', '' === TCP_Coupon_Phones::phone( $phone ) );
}
TCP_Coupon_Phones::save_policy( $c, $input );
t( 'deduplicate normalized whitelist', 1 === count( TCP_Coupon_Phones::policy( $c )['phones'] ) );
t( 'disable BOTH native pending reservations', 0 === $c->native_limit && 0 === $c->per_user );
t( 'initial import fails closed', '' !== TCP_Coupon_Phones::error( $c, '09123456789' ) );
ready( $c );
t( 'unused phone allowed', '' === TCP_Coupon_Phones::error( $c, '09123456789' ) );
t( 'unlisted phone rejected', '' !== TCP_Coupon_Phones::error( $c, '09999999999' ) );
t( 'empty phone rejected', '' !== TCP_Coupon_Phones::error( $c, '' ) );
t( 'native getter limit bypass only enabled coupon', 0 === TCP_Coupon_Phones::native_limit( 1, $c ) && 1 === TCP_Coupon_Phones::native_limit( 1, new WC_Coupon( 2 ) ) );
$o = new WC_Order( 10 );
foreach ( array( 'pending', 'failed', 'cancelled', 'on-hold' ) as $status ) {
	$o->status = $status; TCP_Coupon_Phones::record( $c, $o );
	t( $status . ' does not spend quota', 0 === TCP_Coupon_Phones::count( 1 ) && '' === TCP_Coupon_Phones::error( $c, $o->phone ) );
}
foreach ( array( 'pending', 'failed', 'cancelled', 'on-hold' ) as $status ) {
	$o->status = $status; $o->paid = true; TCP_Coupon_Phones::record( $c, $o );
	t( $status . ' with stray paid date still does not consume', 0 === TCP_Coupon_Phones::count( 1 ) );
}
$o->paid = false;
$o->status = 'processing'; TCP_Coupon_Phones::record( $c, $o );
t( 'successful order spends quota', 1 === TCP_Coupon_Phones::count( 1 ) );
t( 'new order blocked after success', '' !== TCP_Coupon_Phones::error( $c, $o->phone ) );
t( 'own successful order excluded on recalculation', '' === TCP_Coupon_Phones::error( $c, $o->phone, 10 ) );
$o->status = 'completed'; TCP_Coupon_Phones::record( $c, $o );
t( 'processing to completed counts once', 1 === TCP_Coupon_Phones::count( 1 ) );
TCP_Coupon_Phones::reset( 1, $o->phone );
TCP_Coupon_Phones::record( $c, $o );
t( 'old status event cannot undo reset', 0 === TCP_Coupon_Phones::count( 1 ) );
t( 'history retained after reset', 1 === count( TCP_Coupon_Phones::history( 1 ) ) );
t( 'reset actor recorded', 7 === (int) TCP_Coupon_Phones::history( 1 )[0]->reset_by );
$o2 = new WC_Order( 11 ); $o2->status = 'completed'; TCP_Coupon_Phones::record( $c, $o2 );
t( 'new successful order after reset counted', 1 === TCP_Coupon_Phones::count( 1 ) );
$c2 = new WC_Coupon( 2 ); TCP_Coupon_Phones::record( $c2, $o2 ); TCP_Coupon_Phones::reset( 1 );
t( 'coupon resets isolated', 0 === TCP_Coupon_Phones::count( 1 ) && 1 === TCP_Coupon_Phones::count( 2 ) );
$o3 = new WC_Order( 12 ); $o3->status = 'refunded'; $o3->paid = true; TCP_Coupon_Phones::record( $c, $o3 );
t( 'previously paid refund retained until manual reset', 1 === TCP_Coupon_Phones::count( 1 ) );
$notices = array(); TCP_Coupon_Phones::validate_pay( new WC_Order( 13 ) );
t( 'repay blocked with notice not fatal exception', 1 === count( $notices ) );
$p = TCP_Coupon_Phones::policy( $c ); $p['login'] = true; $c->update_meta_data( TCP_Coupon_Phones::META, $p );
t( 'guest order rejected if login required', '' !== TCP_Coupon_Phones::error( $c, $o->phone, 0, 0 ) );
$p['login'] = false; $p['selected'] = false; $p['limit'] = 2; $p['total'] = 1; $c->update_meta_data( TCP_Coupon_Phones::META, $p );
t( 'total quota enforced across phones', '' !== TCP_Coupon_Phones::error( $c, '09999999999' ) );
$p['total'] = 0; $c->update_meta_data( TCP_Coupon_Phones::META, $p );
t( 'per phone limit can exceed one', '' === TCP_Coupon_Phones::error( $c, $o->phone ) );
// Legacy orders without custom phone metadata; > 100 orders, alias and failed cases.
// Store-specific paid statuses (e.g. shipped to warehouse) count; cancelled after payment does not.
$before_custom = TCP_Coupon_Phones::count( 1 );
$o4 = new WC_Order( 13 ); $o4->status = 'wc-warehouse'; $o4->paid = true; $o4->phone = '09111111111'; TCP_Coupon_Phones::record( $c, $o4 );
t( 'custom paid status consumes quota', TCP_Coupon_Phones::count( 1 ) === $before_custom + 1 );
$o5 = new WC_Order( 14 ); $o5->status = 'cancelled'; $o5->paid = true; $o5->phone = '09122222222'; TCP_Coupon_Phones::record( $c, $o5 );
t( 'cancelled after payment does not consume quota', TCP_Coupon_Phones::count( 1 ) === $before_custom + 1 );
$orders = array();
for ( $i = 100; $i <= 200; $i++ ) { $orders[$i] = new WC_Order( $i ); }
$orders[200]->status = 'completed'; $orders[200]->codes = array( 'OLD-CODE' );
$p['aliases'] = array( 'old-code' ); $c->update_meta_data( TCP_Coupon_Phones::META, $p );
$GLOBALS['orders'] = $orders;
foreach ( $orders as $o ) { foreach ( $o->codes as $code ) { $wpdb->db->exec( "INSERT INTO wp_woocommerce_order_items (order_id, order_item_type, order_item_name) VALUES ({$o->id}, 'coupon', '$code')" ); } }
$before = TCP_Coupon_Phones::count( 1 );
TCP_Coupon_Phones::sync( $c );
t( 'history import uses bounded batches', 100 === $last_args['limit'] && 100 === count( $last_args['include'] ) );
t( 'first batch not marked finished', ! $c->get_meta( '_tcp_phone_sync' )['done'] );
t( 'cursor advances to last id of batch', 199 === $c->get_meta( '_tcp_phone_sync' )['after'] );
TCP_Coupon_Phones::sync( $c );
t( 'second batch loads only the coupon orders', 1 === count( $last_args['include'] ) && 200 === $last_args['include'][0] );
t( 'second batch includes old alias successful order', TCP_Coupon_Phones::count( 1 ) === $before + 1 );
t( 'history finished', $c->get_meta( '_tcp_phone_sync' )['done'] );
t( 'sync reports found and paid counts', 101 === $c->get_meta( '_tcp_phone_sync' )['found'] && 1 === $c->get_meta( '_tcp_phone_sync' )['paid'] );
$diag = TCP_Coupon_Phones::diagnose( $c ); 
t( 'diagnose reads coupon orders and recorded uses', 101 === $diag['in_db'] && 1 === $diag['paid'] && TCP_Coupon_Phones::count( 1 ) === $diag['uses'] );
t( 'candidate lookup ignores non-coupon orders', array() === TCP_Coupon_Phones::candidate_ids( new WC_Coupon( 1 ), 200 ) );
TCP_Coupon_Phones::reset( 1 ); TCP_Coupon_Phones::sync( $c ); TCP_Coupon_Phones::sync( $c );
t( 'reimport cannot undo resets', 0 === TCP_Coupon_Phones::count( 1 ) );
$wc = (object) array( 'customer' => new WC_Order( 5 ) );
$_POST['billing_phone'] = '';
$method = new ReflectionMethod( 'TCP_Coupon_Phones', 'current_phone' ); if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
t( 'explicit empty checkout phone never falls back', '' === $method->invoke( null ) );
unset( $_POST['billing_phone'] ); $_POST['post_data'] = 'billing_phone=';
t( 'empty serialized checkout phone never falls back', '' === $method->invoke( null ) );
$bad = $input; $bad['phone_list'] = 'garbage';
try { TCP_Coupon_Phones::save_policy( $c, $bad ); t( 'invalid input must fail', false ); } catch ( Exception $e ) { t( 'invalid phone rejected on save', true ); }
$wpdb->last_error = 'simulated database failure';
try { TCP_Coupon_Phones::count( 1 ); t( 'DB error must fail closed', false ); } catch ( Exception $e ) { t( 'DB error fails closed', true ); }
echo "RESULT: $pass passed, $fail failed\n";
if ( ! defined( 'TCP_TEST_REPORTS' ) ) { exit( $fail ? 1 : 0 ); }
