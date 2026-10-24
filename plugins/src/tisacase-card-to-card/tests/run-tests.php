<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
// Load pure/runtime code before test doubles that extend it.
define( 'TCC_TEST_LOADING', true );
$root = dirname( __DIR__ );
// Stubs need parent class definitions; these have no side effects on inclusion.
define( 'ABSPATH', '/wordpress/' );
foreach ( array( 'helpers', 'settings', 'permissions', 'repository', 'storage', 'access', 'service', 'notifications', 'order-list' ) as $part ) { require_once $root . '/includes/class-tcc-' . $part . '.php'; }
require_once __DIR__ . '/stubs.php';
$pass = 0; $fail = 0;
function t( $label, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
function rejects( $label, $callback ) { try { $callback(); t( $label, false ); } catch ( RuntimeException $e ) { t( $label, true ); } }
function fixture( $id = 101 ) {
    $GLOBALS['uid'] = 1; $GLOBALS['review'] = true; $GLOBALS['edit'] = true; $GLOBALS['transients'] = array(); $GLOBALS['queue'] = array(); TCP_Coupon_Phones::$error = false;
    // Synthetic card generated only for testing; never preconfigured in the plugin.
    $GLOBALS['opts']['tcc_settings'] = array( 'cards' => array( array( 'id' => 'test', 'number' => '6037991234567890', 'holder' => 'TEST ONLY', 'bank' => 'بانک آزمایشی', 'label' => 'test', 'active' => true ) ), 'default_card' => 'test' );
    $o = new WC_Order( $id ); $GLOBALS['orders'] = array( $id => $o );
    $r = new TCC_Test_Repo(); $l = new TCC_Test_Lock(); $f = new TCC_Test_Storage(); $s = new TCC_Service( $r, $l, $f );
    $s->begin( $id ); return array( $o, $r, $l, $f, $s );
}
function upload( $s, $id = 101, $data = array() ) { return $s->submit( $id, array_merge( array( 'request_key' => str_repeat( 'a', 32 ), 'reference' => '۰۰۰۱۲۳', 'last4' => '۱۲۳۴', 'note' => '<b>test</b>', 'token' => '' ), $data ), array( 'error' => UPLOAD_ERR_OK ) ); }
function decision( $r, $o, $action = 'approve', $overrides = array() ) { return array_merge( array( 'revision' => $r->attempt( $o->id )['revision'], 'fingerprint' => TCC_Helpers::fingerprint( $o ), 'decision' => $action, 'confirmed' => 'yes', 'reason' => 'تصویر ناخواناست', 'private_note' => 'INTERNAL TEST NOTE' ), $overrides ); }

t( 'Persian + Arabic digits normalize, leading zeros preserved', TCC_Helpers::digits( '۰۰٠١۲۳' ) === '000123' );
t( 'Reject repeated/non-digit/wrong length destination cards', '' === TCC_Helpers::card( str_repeat( '1', 16 ) ) && '' === TCC_Helpers::card( '123' ) && '' === TCC_Helpers::card( 'bad' ) );
$prefix = '603799123456789'; $valid = ''; for ( $i = 0; $i < 10; $i++ ) { if ( TCC_Helpers::card( $prefix . $i ) ) { $valid = $prefix . $i; break; } }
t( 'Luhn-valid synthetic destination accepted', strlen( $valid ) === 16 );
$clean = TCC_Settings::sanitize( array( 'cards' => array( array( 'number' => $valid, 'holder' => '<b>TEST</b>', 'active' => 1 ) ), 'max_mb' => 999, 'hours' => 0, 'guest_days' => 500, 'recipients' => 'a@example.test,invalid' ) );
t( 'Safe setting ranges', 8 === $clean['max_mb'] && 1 === $clean['hours'] && 30 === $clean['guest_days'] );
t( 'Email validation and HTML stripped', 'a@example.test' === $clean['recipients'] && 'TEST' === $clean['cards'][0]['holder'] );
t( 'New gateway disabled and automatic cancellation off by default', 'no' === $clean['enabled'] && 'no' === $clean['auto_cancel'] );
try { TCC_Settings::sanitize( array( 'cards' => array( array( 'number' => '123', 'holder' => 'Bad' ) ) ) ); t( 'Invalid card fails whole save', false ); } catch ( InvalidArgumentException $e ) { t( 'Invalid card fails whole save', true ); }
list( $o, $r, $l, $f, $s ) = fixture();
t( 'Begin holds order, does not mark paid, stock once', 'on-hold' === $o->status && ! $o->paid && 1 === $o->stock && TCC_TITLE === $o->title );
$original = $r->attempt( 101 ); $GLOBALS['opts']['tcc_settings']['cards'][0]['holder'] = 'Changed'; $s->begin( 101 );
t( 'Repeated begin preserves card snapshot and stock', $r->attempt( 101 )['card'] === $original['card'] && 1 === $o->stock );
t( 'Money matches exact order currency', TCC_Helpers::matches( $o, $original ) );
$o->currency = 'IRR'; t( 'Currency mismatch not a silent conversion', ! TCC_Helpers::matches( $o, $original ) );
rejects( 'Begin blocks changed currency', function () use ( $s ) { $s->begin( 101 ); } ); $o->currency = 'IRT';
$f->unsafe = true; rejects( 'Unsafe storage fails closed', function () use ( $s ) { $s->begin( 101 ); } ); $f->unsafe = false;
$o->method = 'bacs'; rejects( 'Does not relabel/accept other gateways', function () use ( $s ) { $s->begin( 101 ); } ); $o->method = TCC_GATEWAY;
$o->amount = '485001'; rejects( 'Changed amount blocks submission', function () use ( $s ) { upload( $s ); } ); $o->amount = '485000';
t( 'Submission succeeds', 'submitted' === upload( $s ) );
t( 'Receipt does NOT complete payment/stock/coupon success', ! $o->paid && 0 === $o->payment_calls && 1 === $o->stock && 'under_review' === $r->attempt( 101 )['status'] );
t( 'Normalized optional fields stored without HTML', '000123' === $r->submission( 1 )['reference'] && '1234' === $r->submission( 1 )['last4'] && 'test' === $r->submission( 1 )['customer_note'] );
t( 'Upload retry idempotent, no second file/version', 'duplicate' === upload( $s ) && 1 === count( $r->submissions ) && 1 === $f->files );
rejects( 'Full origin card rejected', function () use ( $s ) { upload( $s, 101, array( 'request_key' => str_repeat( 'b', 32 ), 'last4' => '1234567812345678' ) ); } );
rejects( 'Stale reviewer revision rejected', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o, 'reject', array( 'revision' => 1 ) ) ); } );
rejects( 'Bank confirmation mandatory', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o, 'approve', array( 'confirmed' => '' ) ) ); } );
rejects( 'Rejection needs customer-facing reason', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o, 'reject', array( 'reason' => '' ) ) ); } );
$s->decide( 101, decision( $r, $o, 'reject' ) );
t( 'Reject receipt does not cancel order or restore stock', 'rejected' === $r->attempt( 101 )['status'] && 'on-hold' === $o->status && 1 === $o->stock );
upload( $s, 101, array( 'request_key' => str_repeat( 'b', 32 ) ) );
t( 'New version after rejection keeps history', 2 === count( $r->submissions ) && 2 === $r->attempt( 101 )['latest_id'] && 'under_review' === $r->attempt( 101 )['status'] );
$s->decide( 101, decision( $r, $o, 'correct' ) ); t( 'Correction supported in order service', 'correction' === $r->attempt( 101 )['status'] );
$s->decide( 101, decision( $r, $o, 'reconcile' ) ); t( 'Reconcile stays unpaid', 'reconcile' === $r->attempt( 101 )['status'] && ! $o->paid );
TCP_Coupon_Phones::$error = true; rejects( 'Coupon conflict blocks approval before paid', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } ); TCP_Coupon_Phones::$error = false;
t( 'Conflicting coupon does not cancel paid transfer claim', 'on-hold' === $o->status && 0 === $o->payment_calls && empty( $l->held ) );
$o->coupons = array( 'b', 'a' ); $s->decide( 101, decision( $r, $o ) );
t( 'Approval calls standard payment completion exactly once', $o->paid && 1 === $o->payment_calls && 'approved' === $r->attempt( 101 )['status'] && 1 === $o->stock );
t( 'Locks all released including coupons', empty( $l->held ) );
rejects( 'Repeat approval cannot call payment again', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } );
rejects( 'Cannot reject after payment', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o, 'reject' ) ); } );
rejects( 'New receipt after payment rejected', function () use ( $s ) { upload( $s, 101, array( 'request_key' => str_repeat( 'c', 32 ) ) ); } );
t( 'Paid replay has no stock/payment side effects', 1 === $o->payment_calls && 1 === $o->stock );
foreach ( array( 'before', 'after', 'false_after', 'unpaid' ) as $fault ) {
    list( $o, $r, $l, $f, $s ) = fixture(); upload( $s ); $o->payment_error = $fault;
    rejects( "Interrupted approval ($fault)", function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } );
    t( "Durable approval intent survives ($fault)", 'approving' === $r->attempt( 101 )['status'] && empty( $l->held ) );
    if ( in_array( $fault, array( 'after', 'false_after' ), true ) ) { $s->decide( 101, decision( $r, $o ) ); t( 'Recover paid intent without replaying payment hooks', 'approved' === $r->attempt( 101 )['status'] && 1 === $o->payment_calls ); }
    else { rejects( 'Unpaid interrupted intent never auto-replays payment', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } ); t( 'No unsafe automatic retry', 1 === $o->payment_calls ); }
}
list( $o, $r, $l, $f, $s ) = fixture(); upload( $s ); $r->on_change = function ( $action ) use ( $o ) { if ( 'approval_intent' === $action ) { $o->status = 'cancelled'; } };
rejects( 'External cancellation just before completion rechecked', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } ); t( 'Cancelled order not paid', ! $o->paid && 0 === $o->payment_calls );
list( $o, $r, $l, $f, $s ) = fixture(); $l->blocked = true; rejects( 'Concurrent order lock denied', function () use ( $s ) { upload( $s ); } ); $l->blocked = false;
$GLOBALS['review'] = false; rejects( 'Order editor without finance cap cannot approve', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } );
$GLOBALS['review'] = true; $GLOBALS['edit'] = false; rejects( 'Finance cap without order permission cannot approve', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } ); $GLOBALS['edit'] = true;
$GLOBALS['review'] = false; $GLOBALS['uid'] = 2;
t( 'Non-owner cannot read member order even with token', ! TCC_Access::can_view( $o, $r->attempt( 101 ), TCC_Access::token( $o, $r->attempt( 101 )['access_seed'] ) ) );
$GLOBALS['uid'] = 1; t( 'Member owner can view', TCC_Access::can_view( $o, $r->attempt( 101 ) ) );
$o->customer = 0; $GLOBALS['uid'] = 0; $a = $r->attempt( 101 ); $token = TCC_Access::token( $o, $a['access_seed'] );
t( 'Guest needs secret token, not just order ID', ! TCC_Access::can_view( $o, $a ) && TCC_Access::can_view( $o, $a, $token ) );
t( 'Token tampering rejected', ! TCC_Access::can_view( $o, $a, str_repeat( '0', 64 ) ) );
$bad_seed = $a; $bad_seed['access_seed'] = 'tampered'; t( 'Token seed/hash mismatch fails closed', ! TCC_Access::can_view( $o, $bad_seed, $token ) );
$expired = $a; $expired['access_expires'] = time() - 1; t( 'Expired guest token rejected', ! TCC_Access::can_view( $o, $expired, $token ) );
$other = new WC_Order( 202 ); $other->customer = 0; $other_access = TCC_Access::credentials( $other ); $other_a = array_merge( $a, $other_access ); t( 'Token cannot access another order', ! TCC_Access::can_view( $other, $other_a, $token ) );
rejects( 'Unauthorized guest upload denied', function () use ( $s ) { upload( $s ); } );
t( 'Guest can submit with valid scoped token', 'submitted' === upload( $s, 101, array( 'token' => $token ) ) );
$GLOBALS['uid'] = 1; $GLOBALS['review'] = true; $s->decide( 101, decision( $r, $o, 'renew' ) ); $fresh = $r->attempt( 101 ); $GLOBALS['uid'] = 0; $GLOBALS['review'] = false;
t( 'Renewal revokes previous token', ! TCC_Access::can_view( $o, $fresh, $token ) && TCC_Access::can_view( $o, $fresh, TCC_Access::token( $o, $fresh['access_seed'] ) ) );
list( $o, $r, $l, $f, $s ) = fixture(); $r->fail = true;
rejects( 'Ledger failure reported, never marks paid', function () use ( $s ) { upload( $s ); } );
t( 'Retain evidence on ambiguous DB commit failure', 1 === $f->files && 0 === $f->deleted && ! $o->paid && empty( $l->held ) );
list( $o, $r, $l, $f, $s ) = fixture();
$GLOBALS['opts']['tcc_settings']['receipt_required'] = 'no';
rejects( 'Optional image still needs reference', function () use ( $s ) { $s->submit( 101, array( 'request_key' => str_repeat( 'a', 32 ) ), null ); } );
t( 'Reference-only claim accepted when configured', 'submitted' === $s->submit( 101, array( 'request_key' => str_repeat( 'a', 32 ), 'reference' => '00123' ), null ) && 0 === $f->files );
list( $o, $r, $l, $f, $s ) = fixture();
for ( $i = 1; $i <= 5; $i++ ) { upload( $s, 101, array( 'request_key' => str_repeat( (string) $i, 32 ) ) ); }
rejects( 'Per-order rate limit enforced', function () use ( $s ) { upload( $s, 101, array( 'request_key' => str_repeat( '6', 32 ) ) ); } );
t( 'Quota rejection does not store another file', 5 === $f->files );
foreach ( array( false, true ) as $cancel ) {
    list( $o, $r, $l, $f, $s ) = fixture(); $r->attempts[101]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 100 );
    $GLOBALS['opts']['tcc_settings']['auto_cancel'] = $cancel ? 'yes' : 'no'; $s->deadline( 101 );
    t( $cancel ? 'Opt-in deadline cancels only unclaimed order' : 'Default deadline reminder does not cancel', $cancel ? ( 'cancelled' === $o->status && 0 === $o->stock ) : ( 'on-hold' === $o->status && 1 === $o->stock ) );
    $s->deadline( 101 ); t( 'Deadline repeated without extra stock changes', $cancel ? 0 === $o->stock : 1 === $o->stock );
    list( $o, $r, $l, $f, $s ) = fixture(); upload( $s ); $r->attempts[101]['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 100 ); $GLOBALS['opts']['tcc_settings']['auto_cancel'] = 'yes'; $s->deadline( 101 );
    t( 'Submitted receipt never auto-cancelled for review delay', 'on-hold' === $o->status && ! $r->attempt( 101 )['reminded'] );
}
t( 'Storage path checks distinguish sibling directories', TCC_Storage::within( '/var/www/html/files', '/var/www/html' ) && ! TCC_Storage::within( '/var/www/html-private', '/var/www/html' ) );
rejects( 'Storage filename traversal rejected before reading', function () { ( new TCC_Storage() )->path( '../../wp-config.php' ); } );
rejects( 'Executable receipt extension rejected', function () { ( new TCC_Storage() )->path( str_repeat( 'a', 48 ) . '.php' ); } );
$columns = TCC_Order_List::columns( array( 'order_number' => 'Order', 'total' => 'Total' ) ); t( 'Adds visible payment method column', isset( $columns['tcc_payment'] ) );
$columns = TCC_Order_List::columns( array( 'payment_method' => 'Payment' ) ); t( 'Avoids duplicate payment-method column', ! isset( $columns['tcc_payment'] ) && isset( $columns['tcc_receipt'] ) );
$_GET = array( 'tcc_method' => TCC_GATEWAY, 'tcc_state' => 'under_review' ); $args = TCC_Order_List::hpos_args( array( 'limit' => 20 ) ); t( 'HPOS filters stable gateway ID + state', $args['payment_method'] === TCC_GATEWAY && $args['tcc_filter_state'] === 'under_review' && 20 === $args['limit'] );
$_GET['tcc_state'] = "approved' OR 1=1"; $args = TCC_Order_List::hpos_args( array() ); t( 'Filter status allowlist rejects injection', ! isset( $args['tcc_filter_state'] ) ); $_GET = array();
TCC_Order_List::init(); t( 'Both order lists registered', isset( $GLOBALS['hooks']['manage_edit-shop_order_columns'], $GLOBALS['hooks']['manage_woocommerce_page_wc-orders_columns'], $GLOBALS['hooks']['manage_shop_order_posts_custom_column'], $GLOBALS['hooks']['manage_woocommerce_page_wc-orders_custom_column'] ) );

list( $o, $r, $l, $f, $s ) = fixture(); upload( $s ); $o->amount = '485001';
rejects( 'Changed snapshot cannot be approved after refreshing form', function () use ( $s, $r, $o ) { $s->decide( 101, decision( $r, $o ) ); } );
$s->decide( 101, decision( $r, $o, 'reconcile' ) ); t( 'Amount mismatch can still be referred for reconciliation inside order', 'reconcile' === $r->attempt( 101 )['status'] && ! $o->paid );

// Real template rendering with escaped user content; no mocked HTML string.
list( $order, $repo, $lock, $files, $service ) = fixture(); $order->customer = 1; $a = $repo->attempt( 101 ); $id = 101; $settings = TCC_Settings::get(); $card = json_decode( $a['card'], true ); $latest = null; $reason = ''; $editable = true; $flash = array( 'error' => true, 'message' => '<script>bad()</script>' );
ob_start(); include $root . '/templates/payment.php'; $html = ob_get_clean();
t( 'Actual payment template has copy and upload controls', strpos( $html, 'data-tcc-copy="485000"' ) !== false && strpos( $html, 'enctype="multipart/form-data"' ) !== false );
t( 'Customer-facing HTML escapes flash and card owner', strpos( $html, '<script>bad()' ) === false && strpos( $html, '&lt;script&gt;' ) !== false );
t( 'Form has scoped token, CSRF and idempotency key', strpos( $html, 'name="tcc_token"' ) !== false && strpos( $html, 'name="_wpnonce"' ) !== false && strpos( $html, 'name="request_key"' ) !== false );
$editable = false; $order->paid = true; $order->status = 'processing'; $a['status'] = 'approved'; ob_start(); include $root . '/templates/payment.php'; $paid_html = ob_get_clean();
t( 'Paid template hides upload', strpos( $paid_html, 'tcc-upload-form' ) === false );

echo "\nResult: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
