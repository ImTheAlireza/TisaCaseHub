<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/** Real PDO/SQLite transactions exercise repository SQL, not MySQL or a real Woo data store. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' );
$root = dirname( __DIR__ );
foreach ( array( 'helpers', 'settings', 'permissions', 'repository', 'storage', 'access', 'service', 'notifications', 'frontend', 'order-list', 'order-admin', 'admin' ) as $part ) { require_once $root . '/includes/class-tcc-' . $part . '.php'; }
require_once __DIR__ . '/stubs.php';
class TCC_SQLite_WPDB {
    public $prefix = 'wp_'; public $posts = 'wp_posts'; public $insert_id = 0; public $last_error = ''; public $fail_events = false; public $queries = 0; public $pdo; public $lock_result = '1';
    public function __construct() {
        $this->pdo = new PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->pdo->exec( "CREATE TABLE wp_tcc_attempts (order_id INTEGER PRIMARY KEY,revision INTEGER DEFAULT 1,status TEXT DEFAULT 'awaiting',amount TEXT,currency TEXT,card TEXT,latest_id INTEGER DEFAULT 0,expires_at TEXT,access_seed TEXT,access_hash TEXT,access_expires INTEGER,reminded INTEGER DEFAULT 0,updated_at TEXT)" );
        $this->pdo->exec( "CREATE TABLE wp_tcc_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,request_key TEXT,file_key TEXT DEFAULT '',mime TEXT DEFAULT '',file_hash TEXT DEFAULT '',file_bytes INTEGER DEFAULT 0,reference TEXT DEFAULT '',last4 TEXT DEFAULT '',customer_note TEXT,created_at TEXT,UNIQUE(order_id,request_key))" );
        $this->pdo->exec( "CREATE TABLE wp_tcc_events (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER,submission_id INTEGER DEFAULT 0,action TEXT,actor INTEGER,public_reason TEXT,private_note TEXT,created_at TEXT,notify_admin INTEGER DEFAULT 0,notify_customer INTEGER DEFAULT 0,notify_attempts INTEGER DEFAULT 0,next_notify INTEGER DEFAULT 0)" );
    }
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        $i = 0;
        return preg_replace_callback( '/%[ds]/', function ( $m ) use ( &$i, $args ) { $v = $args[$i++]; return '%d' === $m[0] ? (string) (int) $v : $this->pdo->quote( (string) $v ); }, $sql );
    }
    public function insert( $table, $data ) {
        if ( $this->fail_events && 'wp_tcc_events' === $table ) { return false; }
        $keys = array_keys( $data ); $q = 'INSERT INTO ' . $table . ' (' . implode( ',', $keys ) . ') VALUES (' . implode( ',', array_fill( 0, count( $keys ), '?' ) ) . ')';
        try { $s = $this->pdo->prepare( $q ); $s->execute( array_values( $data ) ); $this->insert_id = (int) $this->pdo->lastInsertId(); $this->last_error = ''; return $s->rowCount(); } catch ( PDOException $e ) { $this->last_error = $e->getMessage(); return false; }
    }
    public function update( $table, $changes, $where ) {
        $set = array(); $conds = array(); foreach ( $changes as $key => $value ) { $set[] = $key . '=?'; } foreach ( $where as $key => $value ) { $conds[] = $key . '=?'; }
        $s = $this->pdo->prepare( 'UPDATE ' . $table . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conds ) ); $s->execute( array_merge( array_values( $changes ), array_values( $where ) ) ); return $s->rowCount();
    }
    public function query( $sql ) { $this->queries++; if ( 'START TRANSACTION' === $sql ) { $sql = 'BEGIN TRANSACTION'; } return $this->pdo->exec( $sql ); }
    public function get_var( $sql ) { $this->queries++; if ( strpos( $sql, 'GET_LOCK' ) !== false || strpos( $sql, 'RELEASE_LOCK' ) !== false ) { return $this->lock_result; } $value = $this->pdo->query( $sql )->fetchColumn(); return false === $value ? null : $value; }
    public function get_row( $sql, $output = ARRAY_A ) { $this->queries++; return $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC ) ?: null; }
    public function get_results( $sql, $output = ARRAY_A ) { $this->queries++; return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC ); }
    public function get_col( $sql ) { $this->queries++; return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN ); }
}
$pass = 0; $fail = 0;
function t( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function rejects( $name, $cb ) { try { $cb(); t( $name, false ); } catch ( RuntimeException $e ) { t( $name, true ); } }
$GLOBALS['wpdb'] = new TCC_SQLite_WPDB(); $wpdb = $GLOBALS['wpdb']; $repo = new TCC_Repository();
$order = new WC_Order( 101 ); $order->status = 'on-hold'; $GLOBALS['orders'] = array( 101 => $order );
$a = array_merge( TCC_Access::credentials( $order ), array( 'order_id' => 101, 'revision' => 1, 'status' => 'awaiting', 'amount' => '485000.00000000', 'currency' => 'IRT', 'card' => wp_json_encode( array( 'number' => '6037990000000001', 'bank' => 'TEST BANK', 'holder' => '<script>owner</script>' ) ), 'latest_id' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 86400 ), 'reminded' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$repo->create( $a ); t( 'SQL attempt create/read round trip', (int) $repo->attempt( 101 )['order_id'] === 101 );
$submission = array( 'order_id' => 101, 'request_key' => str_repeat( 'b', 32 ), 'file_key' => str_repeat( 'a', 48 ) . '.png', 'mime' => 'image/png', 'file_hash' => hash( 'sha256', 'test' ), 'file_bytes' => 100, 'reference' => '000123', 'last4' => '1234', 'customer_note' => '<script>hello</script>', 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
$event = $repo->change( $a, array( 'status' => 'under_review' ), 'under_review', '', 'INTERNAL SECRET', $submission );
$updated = $repo->attempt( 101 );
t( 'SQL transaction commits submission, revision and event together', 2 === (int) $updated['revision'] && 1 === (int) $updated['latest_id'] && 'under_review' === $updated['status'] && 'INTERNAL SECRET' === $repo->event( $event )['private_note'] );
t( 'Idempotent lookup uses original request key', 1 === (int) $repo->retry( 101, str_repeat( 'b', 32 ) )['id'] );
t( 'SQL history and rate count bounded to order', 1 === count( $repo->history( 101 ) ) && 1 === $repo->recent_count( 101 ) && 0 === $repo->recent_count( 999 ) );
rejects( 'CAS rejects stale state revision', function () use ( $repo, $a ) { $repo->change( $a, array( 'status' => 'approved' ), 'approved' ); } );
t( 'Stale CAS rolls back without another event', 1 === count( $repo->history( 101 ) ) && 'under_review' === $repo->attempt( 101 )['status'] );
$wpdb->fail_events = true; $new = $submission; $new['request_key'] = str_repeat( 'c', 32 );
rejects( 'Fail event insert rolls back receipt + decision', function () use ( $repo, $updated, $new ) { $repo->change( $updated, array( 'status' => 'rejected' ), 'rejected', 'reason', '', $new ); } );
$wpdb->fail_events = false;
t( 'No orphan SQL receipt on transaction rollback', 1 === $repo->recent_count( 101 ) && 2 === (int) $repo->attempt( 101 )['revision'] && 1 === (int) $repo->attempt( 101 )['latest_id'] );
rejects( 'Unique request key prevents SQL duplicate receipt', function () use ( $repo, $updated, $submission ) { $repo->change( $updated, array( 'status' => 'under_review' ), 'under_review', '', '', $submission ); } );
t( 'Duplicate retry rollback does not change state', 2 === (int) $repo->attempt( 101 )['revision'] && 1 === count( $repo->history( 101 ) ) );
t( 'Same order is not duplicate-fraud flag', ! $repo->duplicate( $repo->submission( 1 ) ) );
$other = $submission; $other['order_id'] = 202; $wpdb->insert( 'wp_tcc_submissions', $other );
t( 'Exact image hash/ref across orders raises warning', $repo->duplicate( $repo->submission( 1 ) ) );
$lock = new TCC_Lock(); $lock->acquire( 101 ); $lock->release( 101 ); t( 'MySQL advisory lock SQL round trip adapter', true ); $wpdb->lock_result = '0'; rejects( 'Unavailable advisory lock fails closed', function () use ( $lock ) { $lock->acquire( 101 ); } ); $wpdb->lock_result = '1';
$clauses = TCC_Order_List::hpos_clauses( array( 'where' => '1=1' ), null, array( 'tcc_filter_state' => 'under_review' ) ); t( 'HPOS filter uses indexed subquery, not unbounded IDs', strpos( $clauses['where'], 'wc_orders.id IN (SELECT order_id FROM wp_tcc_attempts' ) !== false );
$unchanged = TCC_Order_List::hpos_clauses( array( 'where' => '1=1' ), null, array() ); t( 'Non-list queries untouched without scoped marker', '1=1' === $unchanged['where'] );
$GLOBALS['wp_list_table'] = (object) array( 'items' => array( $order, new WC_Order( 202 ) ) ); $before = $wpdb->queries;
ob_start(); TCC_Order_List::render( 'tcc_payment', $order ); $column = ob_get_clean();
t( 'Canonical method title visibly rendered', strpos( $column, TCC_TITLE ) !== false && strpos( $column, 'منتظر بررسی' ) !== false );
t( 'Review link stays on same order, not settings', strpos( $column, '#tcc-order-payment' ) !== false && strpos( $column, 'id=101' ) !== false && strpos( $column, 'page=tisacase-card-to-card' ) === false );
t( 'List cell does not expose receipt/token or image', strpos( $column, 'tcc_token' ) === false && strpos( $column, '<img' ) === false && strpos( $column, 'file_key' ) === false );
TCC_Order_List::render( 'unused', 0 ); ob_start(); TCC_Order_List::render( 'tcc_payment', 101 ); ob_end_clean(); t( 'List attempt cache reads page batch only once', 1 === $wpdb->queries - $before );
$order->method = 'bacs'; $order->title = 'انتقال بانکی اصلی'; ob_start(); TCC_Order_List::render( 'tcc_payment', $order ); $other_column = ob_get_clean(); t( 'Other gateways keep actual method, no review action', strpos( $other_column, 'انتقال بانکی اصلی' ) !== false && strpos( $other_column, 'بررسی پرداخت' ) === false ); $order->method = TCC_GATEWAY;
ob_start(); TCC_Order_Admin::box( $order ); $box = ob_get_clean();
t( 'Order box has receipt, approve and reject controls', strpos( $box, 'value="approve"' ) !== false && strpos( $box, 'value="reject"' ) !== false && strpos( $box, '<img' ) !== false );
t( 'Order box escapes user-supplied note and owner', strpos( $box, '<script>hello' ) === false && strpos( $box, '<script>owner' ) === false );
t( 'Decisions belong to native Woo form without a nested/standalone review form', strpos( $box, '<form' ) === false && strpos( $box, 'name="tcc_review[decision]"' ) !== false && strpos( $box, 'type="radio"' ) !== false );
t( 'Native order form carries revision/fingerprint/separate CSRF without changing Woo action', strpos( $box, 'name="tcc_review[revision]"' ) !== false && strpos( $box, 'name="tcc_review[fingerprint]"' ) !== false && strpos( $box, 'name="tcc_review[nonce]"' ) !== false && strpos( $box, 'name="action"' ) === false );
t( 'Matching payment snapshot does not repeat the same amount', strpos( $box, 'مبلغ ثبت‌شده پرداخت' ) === false );
$order->amount = '485001'; ob_start(); TCC_Order_Admin::box( $order ); $mismatch_box = ob_get_clean(); $order->amount = '485000';
t( 'Changed amount retains historical amount and visible approval warning', strpos( $mismatch_box, 'مبلغ ثبت‌شده پرداخت' ) !== false && strpos( $mismatch_box, 'تأیید تا تطبیق مالی مسدود است' ) !== false );
$wpdb->update( 'wp_tcc_attempts', array( 'status' => 'approving' ), array( 'order_id' => 101 ) );
ob_start(); TCC_Order_Admin::box( $order ); $interrupted_box = ob_get_clean();
t( 'Interrupted unpaid approval has warning and no replay action', strpos( $interrupted_box, 'تأیید قبلی ناتمام است' ) !== false && strpos( $interrupted_box, 'value="approve"' ) === false );
$order->paid = true; $order->status = 'processing'; ob_start(); TCC_Order_Admin::box( $order ); $recovery_box = ob_get_clean(); $order->paid = false; $order->status = 'on-hold';
t( 'Paid interrupted approval exposes ledger recovery only, not rejection', strpos( $recovery_box, 'تکمیل سابقه تأیید' ) !== false && strpos( $recovery_box, 'value="reject"' ) === false && strpos( $recovery_box, 'نه پرداخت دوباره' ) !== false );
$wpdb->update( 'wp_tcc_attempts', array( 'status' => 'under_review' ), array( 'order_id' => 101 ) );
$GLOBALS['review'] = false; ob_start(); TCC_Order_Admin::box( $order ); $forbidden = ob_get_clean(); t( 'Unauthorized order editor cannot see receipt or buttons', strpos( $forbidden, '<img' ) === false && strpos( $forbidden, 'value="approve"' ) === false && strpos( $forbidden, 'INTERNAL SECRET' ) === false ); $GLOBALS['review'] = true;
$id = 101; $a = $repo->attempt( 101 ); $settings = TCC_Settings::get(); $card = json_decode( $a['card'], true ); $latest = $repo->submission( 1 ); $reason = '<script>reason</script>'; $a['status'] = 'rejected'; $editable = true; $flash = null;
ob_start(); include $root . '/templates/payment.php'; $customer = ob_get_clean();
t( 'Customer template never leaks internal finance note', strpos( $customer, 'INTERNAL SECRET' ) === false );
t( 'Customer rejection reason and note escaped', strpos( $customer, '<script>reason' ) === false && strpos( $customer, '&lt;script&gt;reason' ) !== false && strpos( $customer, '<script>hello' ) === false );
ob_start(); TCC_Frontend::email_link( $order, false, false, null ); $initial_email = ob_get_clean();
t( 'Initial Woo customer email contains recoverable signed payment link', strpos( $initial_email, 'tcc_token=' ) !== false && strpos( $initial_email, 'ارسال رسید' ) !== false );
ob_start(); TCC_Frontend::email_link( $order, true, false, null ); $admin_email = ob_get_clean(); t( 'Core admin email does not get a customer bearer token', '' === $admin_email );

// HTTP controller guards and durable email behaviour, using the real repository.
class TCC_Test_HTTP extends RuntimeException {
    public $payload; public $status;
    public function __construct( $payload, $status ) { parent::__construct( 'http-result' ); $this->payload = $payload; $this->status = $status; }
}
function wp_verify_nonce( $value, $action ) { return empty( $GLOBALS['native_expired_nonce'] ) && hash_equals( wp_create_nonce( $action ), (string) $value ); }
function wp_doing_ajax() { return true; }
function wp_send_json_error( $data, $status = 200 ) { throw new TCC_Test_HTTP( array( 'success' => false, 'data' => $data ), $status ); }
function wp_send_json_success( $data ) { throw new TCC_Test_HTTP( array( 'success' => true, 'data' => $data ), 200 ); }
function response( $callback ) { try { $callback(); } catch ( TCC_Test_HTTP $e ) { return $e; } throw new RuntimeException( 'Expected JSON response' ); }
function wp_mail( $to, $subject, $message ) { $GLOBALS['mails'][] = array( $to, $subject, $message ); return $GLOBALS['mail_success']; }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'order_id' => '101', '_wpnonce' => 'wrong', 'decision' => 'approve', 'revision' => '2', 'confirmed' => 'yes', 'fingerprint' => TCC_Helpers::fingerprint( $order ) );
$out = response( array( 'TCC_Order_Admin', 'review' ) ); t( 'Review controller blocks invalid CSRF before side effects', ! $out->payload['success'] && ! $order->paid && 2 === (int) $repo->attempt( 101 )['revision'] );
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST['_wpnonce'] = wp_create_nonce( 'tcc_review_101' );
$out = response( array( 'TCC_Order_Admin', 'review' ) ); t( 'Review refuses GET even with valid nonce', ! $out->payload['success'] && ! $order->paid );
$_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['review'] = false;
$out = response( array( 'TCC_Order_Admin', 'review' ) ); t( 'Controller independently enforces finance permission', ! $out->payload['success'] && ! $order->paid ); $GLOBALS['review'] = true;
$_POST = array( 'order_id' => '101', '_wpnonce' => 'wrong' ); $_REQUEST = $_POST;
$out = response( array( 'TCC_Frontend', 'submit' ) ); t( 'Customer upload invalid CSRF refused', ! $out->payload['success'] && 1 === $repo->recent_count( 101 ) );
$_POST['_wpnonce'] = wp_create_nonce( 'tcc_submit_101' ); $_REQUEST = $_POST; $GLOBALS['review'] = false; $GLOBALS['uid'] = 2;
$out = response( array( 'TCC_Frontend', 'submit' ) ); t( 'Upload controller blocks another account with valid guest nonce', ! $out->payload['success'] && 1 === $repo->recent_count( 101 ) ); $GLOBALS['review'] = true; $GLOBALS['uid'] = 1;
$GLOBALS['mails'] = array(); $GLOBALS['mail_success'] = true;
TCC_Notifications::send( $event );
t( 'Receipt event sends two messages, not payment success', 2 === count( $GLOBALS['mails'] ) && strpos( $GLOBALS['mails'][1][2], 'پرداخت هنوز تأیید نشده' ) !== false );
t( 'Email never includes private note or receipt bytes', strpos( implode( '\n', $GLOBALS['mails'][1] ), 'INTERNAL SECRET' ) === false && strpos( $GLOBALS['mails'][0][2], 'tcc_receipt' ) === false );
TCC_Notifications::send( $event ); t( 'Sent event is not mailed repeatedly', 2 === count( $GLOBALS['mails'] ) );
$e2 = $repo->change( $repo->attempt( 101 ), array( 'status' => 'correction' ), 'correction', 'تصویر روشن‌تر لازم است', 'HIDDEN INTERNAL' );
$GLOBALS['mail_success'] = false; TCC_Notifications::send( $e2 ); $failed_event = $repo->event( $e2 );
t( 'Email failure uses bounded backoff without changing financial decision', (int) $failed_event['next_notify'] > time() && 1 === (int) $failed_event['notify_attempts'] && 'correction' === $repo->attempt( 101 )['status'] && ! $order->paid );
$before_mail = count( $GLOBALS['mails'] ); TCC_Notifications::send( $e2 ); t( 'Backoff prevents immediate retry storm', $before_mail === count( $GLOBALS['mails'] ) );
$_POST = array(); $_REQUEST = array();

// Render the exact settings template, including populated non-default values on every tab.
$ui_settings = array_merge( TCC_Settings::defaults(), array( 'enabled' => 'yes', 'max_mb' => 3, 'hours' => 12, 'guest_days' => 5, 'support' => 'پشتیبانی آزمایشی', 'cards' => array(
    array( 'id' => 'test-one', 'number' => '6037990000000001', 'holder' => '<script>owner</script>', 'bank' => 'بانک آزمایشی', 'label' => 'کارت فروشگاه', 'active' => true ),
    array( 'id' => 'test-two', 'number' => '6219860000000002', 'holder' => 'TEST ONLY', 'bank' => 'بانک دوم', 'label' => 'کارت پشتیبان', 'active' => false ),
), 'default_card' => 'test-one' ) );
$ui_status = array( array( 'ok', 'ذخیره رسید آماده است' ) );
ob_start(); TCC_Admin::render( $ui_settings, $ui_status, array( 2, 7 ) ); $settings_html = ob_get_clean();
t( 'Settings use one form across all four panels', 1 === substr_count( $settings_html, '<form ' ) && 4 === substr_count( $settings_html, 'data-tcc-panel=' ) );
t( 'Settings include every editable option, not only current tab', array_reduce( array_diff( array_keys( TCC_Settings::defaults() ), array( 'cards' ) ), function ( $ok, $key ) use ( $settings_html ) { return $ok && false !== strpos( $settings_html, 'name="settings[' . $key . ']"' ); }, true ) );
t( 'Settings preserve non-default values and reviewer identifiers', strpos( $settings_html, 'value="12"' ) !== false && strpos( $settings_html, 'value="2,7"' ) !== false && strpos( $settings_html, 'پشتیبانی آزمایشی' ) !== false );
t( 'Settings escape holder input and preserve CSRF', strpos( $settings_html, '<script>owner' ) === false && strpos( $settings_html, '&lt;script&gt;owner' ) !== false && strpos( $settings_html, 'name="_wpnonce"' ) !== false );
t( 'Settings omit hosting paths and long implementation tutorials', strpos( $settings_html, 'TCC_PRIVATE_DIR' ) === false && strpos( $settings_html, 'webroot' ) === false && strpos( $settings_html, 'دفتر رویداد' ) === false );
t( 'Auto-cancel retains no-receipt/no-declaration guard wording', strpos( $settings_html, 'فقط بدون رسید یا اعلام واریز' ) !== false );
$_GET['tab'] = 'access'; ob_start(); TCC_Admin::render( $ui_settings, $ui_status ); $access_html = ob_get_clean();
t( 'Selected settings tab survives full-page save redirect', strpos( $access_html, 'data-active-tab="access"' ) !== false );
$_GET['tab'] = array( 'malformed' ); ob_start(); TCC_Admin::render( $ui_settings, $ui_status ); $safe_tab_html = ob_get_clean();
t( 'Malformed tab input falls back safely', strpos( $safe_tab_html, 'data-active-tab="cards"' ) !== false ); unset( $_GET['tab'] );
ob_start(); TCC_Admin::render( TCC_Settings::defaults(), array( array( 'error', 'ذخیره خصوصی رسید آماده نیست' ), array( 'error', 'کارت مقصد فعال ندارید' ) ) ); $empty_settings = ob_get_clean();
t( 'First-run settings have an open card editor and concise readiness errors', strpos( $empty_settings, 'class="tcc-card-row" open' ) !== false && 2 === substr_count( $empty_settings, 'tcc-health-item is-error' ) );
t( 'Order review keeps less-used sections collapsed', strpos( $box, '<details class="tcc-private-note">' ) !== false && strpos( $box, '<details class="tcc-history">' ) !== false );

if ( getenv( 'TCC_TEST_OUTPUT' ) ) {
    $dir = getenv( 'TCC_TEST_OUTPUT' ); if ( ! is_dir( $dir ) ) { mkdir( $dir, 0755, true ); }
    file_put_contents( $dir . '/settings.html', '<!doctype html><html lang="fa" dir="rtl"><body class="woocommerce_page_tisacase-card-to-card">' . $settings_html . '</body></html>' );
    file_put_contents( $dir . '/settings-empty.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $empty_settings . '</body></html>' );
    $latest = null; $reason = ''; $a['status'] = 'awaiting';
    ob_start(); include $root . '/templates/payment.php'; $new_payment = ob_get_clean();
    file_put_contents( $dir . '/payment-new.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $new_payment . '</body></html>' );
    $latest = $repo->submission( 1 ); $a['status'] = 'under_review';
    ob_start(); include $root . '/templates/payment.php'; $pending_html = ob_get_clean();
    file_put_contents( $dir . '/payment-pending.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $pending_html . '</body></html>' );
    $latest = null; $a['status'] = 'awaiting'; $card['bank'] = 'سامان'; $card['number'] = '6219860000000002'; $card['holder'] = 'فروشگاه آزمایشی';
    ob_start(); include $root . '/templates/payment.php'; $saman_html = ob_get_clean();
    file_put_contents( $dir . '/payment-saman.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $saman_html . '</body></html>' );
    $order->paid = true; $a['status'] = 'approved'; $latest = $repo->submission( 1 ); $editable = false;
    ob_start(); include $root . '/templates/payment.php'; $paid_html = ob_get_clean();
    file_put_contents( $dir . '/payment-paid.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $paid_html . '</body></html>' );
    $order->paid = false;
    file_put_contents( $dir . '/customer.html', '<!doctype html><html lang="fa" dir="rtl"><body>' . $customer . '</body></html>' );
    $admin_fixture = '<!doctype html><html lang="fa" dir="rtl"><body><form id="post" method="post"><input type="hidden" name="action" value="editpost"><input name="order_total" value="485000"><button type="submit" id="publish" name="save" value="update">به‌روزرسانی سفارش</button>' . $box . '</form></body></html>';
    file_put_contents( $dir . '/admin.html', $admin_fixture );
    file_put_contents( $dir . '/admin-hpos.html', str_replace( array( 'id="post"', 'value="editpost"' ), array( 'id="order"', 'value="edit_order"' ), $admin_fixture ) );
}

// Customer state regressions use the real template; never modify order money.
function customer_markup( $root, $order, $repo, $state, $reason = '', $overrides = array() ) {
    $id = $order->get_id(); $a = $repo->attempt( $id ); $a['status'] = $state;
    $settings = array_merge( TCC_Settings::get(), $overrides ); $card = json_decode( $a['card'], true );
    $latest = 'awaiting' === $state ? null : $repo->submission( 1 ); $editable = true; $flash = null;
    ob_start(); include $root . '/templates/payment.php'; return ob_get_clean();
}
$rendered = customer_markup( $root, $order, $repo, 'awaiting' );
t( 'Customer displays/copies exact rials without changing stored IRT', false !== strpos( $rendered, 'data-tcc-copy="4850000"' ) && false !== strpos( $rendered, '۴٬۸۵۰٬۰۰۰' ) && '485000' === $order->get_total() && 'IRT' === $order->get_currency() );
$rendered = customer_markup( $root, $order, $repo, 'under_review' );
t( 'Customer review summary explicitly distinguishes receipt from paid money', 1 === substr_count( $rendered, 'class="tcc-status-card' ) && false !== strpos( $rendered, 'پرداخت هنوز تأیید نشده است' ) && false !== strpos( $rendered, 'دوباره واریز نکنید' ) );
t( 'Pending transfer and replacement are secondary collapsed details', false !== strpos( $rendered, '<details class="tcc-transfer-details">' ) && 1 === preg_match( '/<details class="tcc-upload[^"]*"\s*>/', $rendered ) && false !== strpos( $rendered, 'مشاهده تصویر رسید' ) );
$rendered = customer_markup( $root, $order, $repo, 'under_review', '', array( 'review_text' => 'زمان بررسی سفارشی <b>TEST</b>' ) );
t( 'Custom store review text remains available but escaped', false !== strpos( $rendered, 'زمان بررسی سفارشی &lt;b&gt;TEST&lt;/b&gt;' ) && false === strpos( $rendered, '<b>TEST</b>' ) );
foreach ( array( 'rejected', 'correction', 'reconcile' ) as $state ) {
    $rendered = customer_markup( $root, $order, $repo, $state, 'علت آزمایشی' );
    t( 'Customer ' . $state . ' keeps public reason and no-repeat-payment warning', false !== strpos( $rendered, 'علت آزمایشی' ) && false !== strpos( $rendered, 'دوباره واریز نکنید' ) );
}
foreach ( array( 'cancelled', 'failed', 'refunded' ) as $closed_status ) {
    $order->status = $closed_status; $order->paid = true;
    $rendered = customer_markup( $root, $order, $repo, 'approved' );
    t( 'Closed customer order takes precedence over historical paid flag: ' . $closed_status, false !== strpos( $rendered, 'این سفارش بسته شده است' ) && false === strpos( $rendered, 'پرداخت سفارش ثبت شد' ) && false === strpos( $rendered, 'tcc-upload-form' ) && false === strpos( $rendered, 'data-tcc-copy=' ) );
}
$order->status = 'processing'; $rendered = customer_markup( $root, $order, $repo, 'approved' );
t( 'Paid customer never gets another transfer/upload invitation', false !== strpos( $rendered, 'پرداخت سفارش ثبت شد' ) && false === strpos( $rendered, 'tcc-upload-form' ) && false === strpos( $rendered, 'data-tcc-copy=' ) );
$order->status = 'on-hold'; $order->paid = false;

// Native Woo Update bridge: real repository/service, simulated Woo save hooks.
class WC_Admin_Meta_Boxes { public static $meta_box_errors = array(); }
function native_fixture( $decision = 'reject' ) {
    static $next = 1000; $id = ++$next;
    $GLOBALS['uid'] = 1; $GLOBALS['review'] = true; $GLOBALS['edit'] = true; $_SERVER['REQUEST_METHOD'] = 'POST';
    $order = new WC_Order( $id ); $order->status = 'on-hold'; $GLOBALS['orders'][$id] = $order;
    $repo = new TCC_Repository();
    $row = array_merge( TCC_Access::credentials( $order ), array( 'order_id' => $id, 'revision' => 1, 'status' => 'awaiting', 'amount' => '485000.00000000', 'currency' => 'IRT', 'card' => wp_json_encode( array( 'bank' => 'TEST', 'holder' => 'TEST', 'number' => '6037990000000001' ) ), 'latest_id' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 ), 'reminded' => 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
    $repo->create( $row );
    $submission = array( 'order_id' => $id, 'request_key' => substr( hash( 'sha256', 'native-' . $id ), 0, 32 ), 'file_key' => str_repeat( 'a', 48 ) . '.png', 'mime' => 'image/png', 'file_hash' => hash( 'sha256', 'test' ), 'file_bytes' => 100, 'reference' => '000123', 'last4' => '1234', 'customer_note' => 'TEST', 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
    $repo->change( $row, array( 'status' => 'under_review' ), 'under_review', '', '', $submission );
    $_POST = array( 'action' => 'editpost', 'tcc_review' => array( 'order_id' => (string) $id, 'nonce' => wp_create_nonce( 'tcc_review_' . $id ), 'revision' => '2', 'fingerprint' => TCC_Helpers::fingerprint( $order ), 'decision' => $decision, 'reason' => 'تصویر خوانا نیست', 'private_note' => 'INTERNAL', 'confirmed' => 'yes' ) );
    return array( $id, $order, $repo, 'https://shop.example.test/wp-admin/post.php?post=' . $id );
}
foreach ( array( 'reject' => 'rejected', 'correct' => 'correction', 'reconcile' => 'reconcile' ) as $decision => $state ) {
    list( $id, $o, $r, $url ) = native_fixture( $decision ); $_POST['automatic_woo_widget'] = 'changed by Woo initialization';
    TCC_Order_Admin::capture_native( $id, $o );
    t( 'Selecting/capturing ' . $decision . ' has no financial side effects', 'under_review' === $r->attempt( $id )['status'] && 0 === $o->payment_calls );
    $location = TCC_Order_Admin::finalize_native( $url, $id );
    t( 'Native Update persists ' . $decision . ' after Woo save', $state === $r->attempt( $id )['status'] && $url === $location && 3 === (int) $r->attempt( $id )['revision'] );
    t( 'Native ' . $decision . ' does not cancel/pay the order or leak private reason', 'on-hold' === $o->status && ! $o->paid && 0 === $o->payment_calls && 'تصویر خوانا نیست' === $r->history( $id )[0]['public_reason'] );
    $notice = get_transient( 'tcc_native_notice_1_' . $id ); t( 'Native ' . $decision . ' reports success to same actor/order', false === $notice['error'] );
}
list( $id, $o, $r, $url ) = native_fixture( 'approve' );
TCC_Order_Admin::capture_native( $id, $o ); t( 'Approval intent waits for the completed Woo save', ! $o->paid && 'under_review' === $r->attempt( $id )['status'] );
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native confirmed approval uses standard Woo payment flow once', $o->paid && 1 === $o->payment_calls && 'approved' === $r->attempt( $id )['status'] );
TCC_Order_Admin::capture_native( $id, $o ); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Recursive/repeated hooks cannot replay approval in the same request', 1 === $o->payment_calls && 4 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $_POST['tcc_review']['nonce'] = 'invalid';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native CSRF rejects before ledger mutation', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $_POST['tcc_review']['order_id'] = '101';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native posted order ID cannot target a different saved order', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $GLOBALS['review'] = false;
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native ordinary order edit rights do not grant financial review', 2 === (int) $r->attempt( $id )['revision'] ); $GLOBALS['review'] = true;
list( $id, $o, $r, $url ) = native_fixture(); $GLOBALS['edit'] = false;
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native review still requires same-order edit permission', 2 === (int) $r->attempt( $id )['revision'] ); $GLOBALS['edit'] = true;
list( $id, $o, $r, $url ) = native_fixture(); $_POST['tcc_review']['decision'] = '';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Ordinary Update without a selected decision does not review payment', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $_SERVER['REQUEST_METHOD'] = 'GET';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'GET cannot stage native review', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); TCC_Order_Admin::capture_native( $id ); $o->amount = '600000';
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Finalization re-reads changed saved amount and refuses approval', ! $o->paid && 0 === $o->payment_calls && 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); TCC_Order_Admin::capture_native( $id ); $o->currency = 'IRR';
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Finalization revalidates saved currency', ! $o->paid && 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); $_POST['tcc_review']['confirmed'] = '';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native server rejects approval without bank confirmation', ! $o->paid && 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $_POST['tcc_review']['reason'] = '';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native server rejects missing public reason', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); $_POST['tcc_review']['revision'] = '1';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native server rejects stale receipt revision', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); TCC_Order_Admin::capture_native( $id ); $GLOBALS['review'] = false;
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Role revoked between Woo save and redirect blocks the decision', 2 === (int) $r->attempt( $id )['revision'] ); $GLOBALS['review'] = true;
list( $id, $o, $r, $url ) = native_fixture(); TCC_Order_Admin::capture_native( $id ); WC_Admin_Meta_Boxes::$meta_box_errors = array( 'Simulated failed Woo save' );
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Woo save errors prevent all financial finalization', 2 === (int) $r->attempt( $id )['revision'] ); WC_Admin_Meta_Boxes::$meta_box_errors = array();
list( $id, $o, $r, $url ) = native_fixture(); TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::unfinished_native(); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Aborted/no-redirect save does not become a deferred financial job', 2 === (int) $r->attempt( $id )['revision'] && get_transient( 'tcc_native_notice_1_' . $id )['error'] );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); $o->payment_error = 'before';
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Uncertain native approval keeps durable intent without blind retry', ! $o->paid && 1 === $o->payment_calls && 'approving' === $r->attempt( $id )['status'] );
TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id ); t( 'Uncertain native approval is not repeated on another redirect', 1 === $o->payment_calls );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); TCC_Order_Admin::capture_native( $id ); $o->method = 'bacs';
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Finalization revalidates freshly saved payment method', ! $o->paid && 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); TCC_Order_Admin::capture_native( $id ); $GLOBALS['native_expired_nonce'] = true;
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Nonce expiry between native save and redirect blocks finance', ! $o->paid && 2 === (int) $r->attempt( $id )['revision'] ); $GLOBALS['native_expired_nonce'] = false;
list( $id, $o, $r, $url ) = native_fixture( 'approve' ); TCC_Order_Admin::capture_native( $id ); $wpdb->lock_result = '0';
TCC_Order_Admin::finalize_native( $url, $id ); t( 'Native Update still fails closed on financial lock contention', ! $o->paid && 2 === (int) $r->attempt( $id )['revision'] ); $wpdb->lock_result = '1';
list( $id, $o, $r, $url ) = native_fixture( 'delete' ); TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Unknown native decision cannot mutate ledger', 2 === (int) $r->attempt( $id )['revision'] );
list( $id, $o, $r, $url ) = native_fixture(); TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id );
$notice_key = 'tcc_native_notice_1_' . $id; $marker = get_transient( $notice_key )['message']; $GLOBALS['uid'] = 2; ob_start(); TCC_Order_Admin::box( $o ); $another_actor = ob_get_clean(); $GLOBALS['uid'] = 1;
t( 'Native result notice is not shown to a different reviewer', false === strpos( $another_actor, esc_html( $marker ) ) && false !== get_transient( $notice_key ) );
ob_start(); TCC_Order_Admin::box( $o ); $own_result = ob_get_clean();
t( 'Native result notice is consumed only on its same-order actor view', false !== strpos( $own_result, esc_html( $marker ) ) && false === get_transient( $notice_key ) );
foreach ( array( 'approve', 'reject' ) as $legacy_decision ) {
    list( $id, $o, $r, $url ) = native_fixture( $legacy_decision );
    $payload = $_POST['tcc_review']; $_POST = $payload; $_POST['_wpnonce'] = $payload['nonce'];
    $retired = response( array( 'TCC_Order_Admin', 'review' ) );
    t( 'Valid old ' . $legacy_decision . ' endpoint cannot bypass native Update', ! $retired->payload['success'] && false !== strpos( $retired->payload['data']['message'], 'به‌روزرسانی سفارش' ) && ! $o->paid && 0 === $o->payment_calls && 2 === (int) $r->attempt( $id )['revision'] );
}
list( $id, $o, $r, $url ) = native_fixture( 'renew' ); $before_link = $r->attempt( $id )['access_seed']; TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Guest link renewal also commits only with native Update', $before_link !== $r->attempt( $id )['access_seed'] && ! $o->paid );
list( $id, $o, $r, $url ) = native_fixture(); define( 'DOING_AUTOSAVE', true ); TCC_Order_Admin::capture_native( $id ); TCC_Order_Admin::finalize_native( $url, $id );
t( 'Autosave cannot stage or execute a financial decision', 2 === (int) $r->attempt( $id )['revision'] );
$_POST = array(); $_SERVER['REQUEST_METHOD'] = 'POST';
echo "\nResult: $pass passed, $fail failed (SQLite adapter, NOT MySQL/Woo integration)\n"; exit( $fail ? 1 : 0 );
