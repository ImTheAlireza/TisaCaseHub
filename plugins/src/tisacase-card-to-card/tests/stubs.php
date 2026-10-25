<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/** Minimal doubles; these do NOT replace a real WordPress/WooCommerce integration test. */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', '/wordpress/' ); }
define( 'TCC_PATH', dirname( __DIR__ ) . '/' );
define( 'TCC_URL', 'https://shop.example.test/wp-content/plugins/tisacase-card-to-card/' );
define( 'TCC_FILE', TCC_PATH . 'tisacase-card-to-card.php' );
define( 'TCC_VERSION', '1.3.1' );
define( 'TCC_TITLE', 'کارت به کارت تیساکیس' );
define( 'TCC_GATEWAY', 'tisacase_card_to_card' );
define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 ); define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['opts'] = array( 'admin_email' => 'admin@example.test' ); $GLOBALS['uid'] = 1; $GLOBALS['review'] = true; $GLOBALS['edit'] = true; $GLOBALS['transients'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['queue'] = array();
require_once __DIR__ . '/role-stubs.php';
function get_option( $key, $default = false ) { return $GLOBALS['opts'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['opts'][$key] = $value; return true; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function sanitize_textarea_field( $s ) { return sanitize_text_field( $s ); }
function sanitize_email( $s ) { return filter_var( $s, FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
function absint( $x ) { return abs( (int) $x ); }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $s ) { return json_encode( $s, JSON_UNESCAPED_UNICODE ); }
function get_current_user_id() { return $GLOBALS['uid']; }
function current_user_can( $cap, ...$args ) { return ! empty( $GLOBALS['use_role_caps'] ) ? tcc_test_current_user_can( $cap, ...$args ) : ( 'tcc_review_payments' === $cap ? $GLOBALS['review'] : $GLOBALS['edit'] ); }
function wp_salt( $scheme ) { return 'synthetic-test-salt-not-a-credential'; }
function wc_format_decimal( $x, $precision = 2, $trim = false ) { $s = number_format( (float) $x, $precision, '.', '' ); return $trim && strpos( $s, '.' ) !== false ? rtrim( rtrim( $s, '0' ), '.' ) : $s; }
function wc_get_price_decimals() { return 0; }
function wc_price( $x, $args = array() ) { return '<span>' . number_format( (float) $x ) . ' ' . ( $args['currency'] ?? 'IRT' ) . '</span>'; }
function wc_get_order( $id ) { return $GLOBALS['orders'][$id] ?? false; }
function get_transient( $key ) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][$key] ); }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook][] = array( $callback, $priority, $args ); }
function add_filter( ...$args ) { add_action( ...$args ); }
function as_enqueue_async_action( $hook, $args, $group ) { $GLOBALS['queue'][] = $args[0]; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function esc_url_raw( $s ) { return $s; }
function wp_mkdir_p( $dir ) { return is_dir( $dir ) || mkdir( $dir, 0700, true ); }
function wp_delete_file( $path ) { unlink( $path ); }
function wp_kses_post( $s ) { return $s; }
function wp_date( $format, $timestamp ) { return gmdate( $format, (int) $timestamp ); }
function size_format( $bytes ) { return round( $bytes / 1024 / 1024 ) . ' MB'; }
function wp_max_upload_size() { return 8 * 1024 * 1024; }
function wp_create_nonce( $s ) { return hash( 'sha256', $s ); }
function wp_nonce_field( $s ) { echo '<input type="hidden" name="_wpnonce" value="' . wp_create_nonce( $s ) . '">'; }
function selected( $a, $b, $echo = true ) { $s = $a === $b ? 'selected' : ''; if ( $echo ) { echo $s; } return $s; }
function checked( $a, $b, $echo = true ) { $s = $a === $b ? 'checked' : ''; if ( $echo ) { echo $s; } return $s; }
function admin_url( $path = '' ) { return 'https://shop.example.test/wp-admin/' . $path; }
function add_query_arg( $key, $value, $url = null ) { if ( is_array( $key ) ) { $url = $value; $params = $key; } else { $params = array( $key => $value ); } return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $params ); }
function get_userdata( $id ) { return tcc_test_user( $id ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function wp_upload_dir() { return array( 'basedir' => '/wordpress/wp-content/uploads', 'error' => false ); }
function home_url( $p ) { return 'https://shop.example.test' . $p; }
function is_admin() { return true; }
class WC_Order {
    public $id; public $amount = '485000'; public $currency = 'IRT'; public $customer = 1; public $method = TCC_GATEWAY; public $title = TCC_TITLE; public $status = 'pending'; public $paid = false; public $payment_calls = 0; public $stock = 0; public $notes = array(); public $payment_error = ''; public $coupons = array();
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_total() { return $this->amount; }
    public function get_currency() { return $this->currency; }
    public function get_payment_method() { return $this->method; }
    public function get_payment_method_title() { return $this->title; }
    public function set_payment_method_title( $title ) { $this->title = $title; }
    public function get_customer_id() { return $this->customer; }
    public function get_order_key() { return 'test-key-' . $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
    public function is_paid() { return $this->paid; }
    public function update_status( $status, $note = '' ) { if ( 'on-hold' === $status && 'pending' === $this->status ) { $this->stock++; } if ( 'cancelled' === $status && 'on-hold' === $this->status ) { $this->stock--; } $this->status = $status; }
    public function save() { return $this->id; }
    public function get_formatted_order_total() { return wc_price( $this->amount ); }
    public function get_checkout_order_received_url() { return 'https://shop.example.test/checkout/order-received/' . $this->id . '/?key=' . $this->get_order_key(); }
    public function get_edit_order_url() { return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $this->id ); }
    public function get_billing_email() { return 'customer@example.test'; }
    public function get_coupon_codes() { return $this->coupons; }
    public function add_order_note( $note ) { $this->notes[] = $note; }
    public function payment_complete() {
        $this->payment_calls++;
        if ( 'before' === $this->payment_error ) { throw new RuntimeException( 'simulated payment failure' ); }
        if ( 'unpaid' === $this->payment_error ) { return false; }
        $this->paid = true; $this->status = 'processing';
        if ( 'false_after' === $this->payment_error ) { return false; }
        if ( 'after' === $this->payment_error ) { throw new RuntimeException( 'simulated interrupted response after payment' ); }
        return true;
    }
}
class TCP_Coupon_Phones {
    public static $error = false;
    public static function validate_order( $order ) { if ( self::$error ) { throw new RuntimeException( 'coupon capacity reached' ); } }
}
class TCC_Test_Repo extends TCC_Repository {
    public $attempts = array(); public $submissions = array(); public $events = array(); public $fail = false; public $on_change = null;
    public function attempt( $id ) { return $this->attempts[$id] ?? null; }
    public function create( $row ) { $this->attempts[$row['order_id']] = $row; return $row; }
    public function submission( $id ) { return $this->submissions[$id] ?? null; }
    public function event( $id ) { return $this->events[$id] ?? null; }
    public function retry( $order, $key ) { foreach ( $this->submissions as $row ) { if ( $row['order_id'] == $order && $row['request_key'] === $key ) { return $row; } } return null; }
    public function total_count( $order ) { return $this->recent_count( $order ); }
    public function recent_count( $order ) { return count( array_filter( $this->submissions, function ( $s ) use ( $order ) { return $s['order_id'] === $order; } ) ); }
    public function change( $a, $changes, $action, $reason = '', $note = '', $submission = null ) {
        if ( $this->fail ) { throw new RuntimeException( 'simulated ledger failure' ); }
        if ( $a['revision'] !== $this->attempts[$a['order_id']]['revision'] ) { throw new RuntimeException( 'stale revision' ); }
        if ( $submission ) { $id = count( $this->submissions ) + 1; $submission['id'] = $id; $this->submissions[$id] = $submission; $changes['latest_id'] = $id; }
        $this->attempts[$a['order_id']] = array_merge( $a, $changes, array( 'revision' => $a['revision'] + 1 ) );
        $id = count( $this->events ) + 1;
        $this->events[$id] = array( 'id' => $id, 'order_id' => $a['order_id'], 'submission_id' => $this->attempts[$a['order_id']]['latest_id'], 'action' => $action, 'actor' => get_current_user_id(), 'public_reason' => $reason, 'private_note' => $note, 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
        if ( $this->on_change ) { ( $this->on_change )( $action ); }
        return $id;
    }
}
class TCC_Test_Lock extends TCC_Lock {
    public $held = array(); public $blocked = false;
    public function acquire( $id ) { if ( $this->blocked || isset( $this->held[$id] ) ) { throw new RuntimeException( 'locked' ); } $this->held[$id] = true; }
    public function release( $id ) { unset( $this->held[$id] ); }
}
class TCC_Test_Storage extends TCC_Storage {
    public $files = 0; public $deleted = 0; public $unsafe = false;
    public function base() { if ( $this->unsafe ) { throw new RuntimeException( 'unsafe storage' ); } return '/private-test'; }
    public function receive( $file ) { $this->base(); $this->files++; return array( 'file_key' => str_repeat( 'a', 48 ) . '.png', 'mime' => 'image/png', 'file_hash' => hash( 'sha256', 'synthetic image' ), 'file_bytes' => 100 ); }
    public function discard( $key ) { $this->deleted++; }
}
