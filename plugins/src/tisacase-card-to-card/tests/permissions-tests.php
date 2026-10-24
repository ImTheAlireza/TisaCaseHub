<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' );
$root = dirname( __DIR__ );
foreach ( array( 'helpers', 'settings', 'permissions', 'repository', 'storage', 'access', 'service', 'notifications', 'admin' ) as $part ) { require_once $root . '/includes/class-tcc-' . $part . '.php'; }
require_once __DIR__ . '/stubs.php';
$pass = 0; $fail = 0;
function t( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function rejects( $name, $cb ) { try { $cb(); t( $name, false ); } catch ( RuntimeException $e ) { t( $name, true ); } }
function invalid( $name, $cb ) { try { $cb(); t( $name, false ); } catch ( InvalidArgumentException $e ) { t( $name, true ); } }
$GLOBALS['use_role_caps'] = true; $GLOBALS['strict_test_users'] = true;
$GLOBALS['test_users'] = array( 1 => new TCC_Test_User( 1, array( 'administrator' ) ), 2 => new TCC_Test_User( 2, array( 'shop_manager' ) ), 3 => new TCC_Test_User( 3, array( 'subscriber' ) ), 4 => new TCC_Test_User( 4, array( 'finance' ) ) );
$order = new WC_Order( 101 ); $GLOBALS['orders'] = array( 101 => $order );
t( 'Only administrator selected by default', array( 'administrator' ) === TCC_Permissions::selected_roles() );
t( 'Default admin can review, shop manager cannot yet', TCC_Helpers::reviewer( $order ) ); $GLOBALS['uid'] = 2; t( 'Unselected shop-manager role cannot review', ! TCC_Helpers::reviewer( $order ) );
$manager_caps = get_role( 'shop_manager' )->capabilities;
TCC_Permissions::assign( array( 'shop_manager' ), array() );
t( 'Selected role inherits finance capability', TCC_Helpers::reviewer( $order ) );
t( 'Administrator cannot be removed through the submitted role list', in_array( 'administrator', get_option( 'tcc_reviewer_roles' ), true ) && ! empty( get_role( 'administrator' )->capabilities['tcc_review_payments'] ) );
t( 'Role assignment adds exactly one financial capability', get_role( 'shop_manager' )->capabilities === array_merge( $manager_caps, array( 'tcc_review_payments' => true ) ) );
t( 'Reviewer does not gain settings or user-management rights', ! current_user_can( 'manage_options' ) && ! current_user_can( 'promote_users' ) && ! current_user_can( 'edit_users' ) );
t( 'Selecting roles never changes individual WordPress roles', array( 'shop_manager' ) === get_userdata( 2 )->roles );
$GLOBALS['denied_test_orders'] = array( 101 ); t( 'Role grant still requires permission for this exact order', ! TCC_Helpers::reviewer( $order ) ); $GLOBALS['denied_test_orders'] = array();
TCC_Permissions::assign( array( 'subscriber' ), array() ); $GLOBALS['uid'] = 3;
t( 'Role without order-edit permission remains unable to review', current_user_can( 'tcc_review_payments' ) && ! TCC_Helpers::reviewer( $order ) );
t( 'No order-edit capability is implicitly granted', ! current_user_can( 'edit_shop_orders' ) );
$GLOBALS['uid'] = 2; t( 'Unchecking role revokes financial review', ! TCC_Helpers::reviewer( $order ) );
TCC_Permissions::assign( array( 'finance' ), array() ); $GLOBALS['uid'] = 4;
t( 'Custom WordPress role works without special-case slugs', TCC_Helpers::reviewer( $order ) );
invalid( 'Unknown role is rejected', function () { TCC_Permissions::assign( array( 'nonexistent' ), array() ); } );
invalid( 'Nested role payload is rejected', function () { TCC_Permissions::validate_roles( array( array( 'shop_manager' ) ) ); } );
invalid( 'Scalar role payload is rejected', function () { TCC_Permissions::validate_roles( 'shop_manager' ); } );
t( 'Invalid role leaves existing role policy intact', TCC_Helpers::reviewer( $order ) && in_array( 'finance', get_option( 'tcc_reviewer_roles' ), true ) );
invalid( 'Nonexistent individual reviewer is rejected before grants', function () { TCC_Permissions::assign( array( 'shop_manager' ), array( 999 ) ); } );
t( 'Invalid user leaves existing grants intact', TCC_Helpers::reviewer( $order ) && empty( get_role( 'shop_manager' )->capabilities['tcc_review_payments'] ) );
TCC_Permissions::assign( array( 'shop_manager' ), array( 2 ) ); TCC_Permissions::assign( array(), array( 2 ) ); $GLOBALS['uid'] = 2;
t( 'Individual exception survives role revocation', TCC_Helpers::reviewer( $order ) );
TCC_Permissions::assign( array( 'shop_manager' ), array( 2 ) ); TCC_Permissions::assign( array( 'shop_manager' ), array() );
t( 'Removing individual exception preserves inherited selected-role access', TCC_Helpers::reviewer( $order ) && ! isset( get_userdata( 2 )->caps['tcc_review_payments'] ) );
TCC_Permissions::assign( array(), array() ); t( 'Removing both role and individual revokes review', ! TCC_Helpers::reviewer( $order ) );
get_role( 'finance' )->add_cap( 'tcc_review_payments' );
t( 'Existing role-editor grant is reflected as selected', in_array( 'finance', TCC_Permissions::selected_roles(), true ) );
t( 'Old already-open settings form preserves role grants', in_array( 'finance', TCC_Permissions::requested_roles( array() ), true ) );
t( 'New empty role selection keeps only protected administrator', array( 'administrator' ) === TCC_Permissions::requested_roles( array( 'reviewer_roles_present' => '1' ) ) );
invalid( 'Malformed role-presence marker is rejected', function () { TCC_Permissions::requested_roles( array( 'reviewer_roles_present' => array( '1' ) ) ); } );
TCC_Permissions::assign( array( 'shop_manager', 'shop_manager' ), array( 2, 2 ) );
t( 'Duplicate roles and user IDs are deduplicated', 2 === count( get_option( 'tcc_reviewer_roles' ) ) && array( 2 ) === get_option( 'tcc_reviewers' ) );
$GLOBALS['opts']['tcc_reviewer_roles'][] = 'deleted-role';
TCC_Permissions::uninstall();
t( 'Uninstall revokes capabilities from all roles and assigned individuals', empty( get_role( 'administrator' )->capabilities['tcc_review_payments'] ) && empty( get_role( 'shop_manager' )->capabilities['tcc_review_payments'] ) && ! isset( get_userdata( 2 )->caps['tcc_review_payments'] ) );
t( 'Uninstall retains assignments for controlled reinstall', in_array( 'shop_manager', get_option( 'tcc_reviewer_roles' ), true ) && array( 2 ) === get_option( 'tcc_reviewers' ) );
TCC_Permissions::restore();
t( 'Reinstall restores saved roles/users and tolerates deleted roles', TCC_Helpers::reviewer( $order ) && ! empty( get_role( 'administrator' )->capabilities['tcc_review_payments'] ) );

// Exercise the actual settings handler, including authorization, CSRF and audit-before-grant.
class TCC_Test_Settings_Result extends RuntimeException { public $status; public function __construct( $message, $status ) { parent::__construct( $message ); $this->status = $status; } }
function wp_die( $message, $title = '', $args = array() ) { throw new TCC_Test_Settings_Result( $message, $args['response'] ?? 500 ); }
function check_admin_referer( $action ) { if ( ! hash_equals( wp_create_nonce( $action ), TCC_Helpers::post( '_wpnonce' ) ) ) { wp_die( 'bad nonce', '', array( 'response' => 403 ) ); } }
function wp_safe_redirect( $url ) { throw new TCC_Test_Settings_Result( $url, 302 ); }
class TCC_Test_Settings_DB { public $prefix = 'wp_'; public $events = array(); public $fail = false; public function insert( $table, $row ) { if ( $this->fail ) { return false; } $this->events[] = $row; return 1; } }
$GLOBALS['wpdb'] = new TCC_Test_Settings_DB();
function settings_response() { try { TCC_Admin::save(); } catch ( TCC_Test_Settings_Result $e ) { return $e; } throw new RuntimeException( 'Expected settings result' ); }
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = array( '_wpnonce' => wp_create_nonce( 'tcc_settings' ), 'settings' => TCC_Settings::defaults(), 'reviewers' => '', 'reviewer_roles_present' => '1', 'reviewer_roles' => array( 'finance' ), 'ui_tab' => 'access' );
$before = count( $GLOBALS['wpdb']->events ); $result = settings_response();
t( 'Reviewer cannot grant roles via forged settings POST', 403 === $result->status && $before === count( $GLOBALS['wpdb']->events ) );
$GLOBALS['uid'] = 1; $_POST['_wpnonce'] = 'wrong'; $result = settings_response();
t( 'Settings CSRF fails before any permission changes', 403 === $result->status && $before === count( $GLOBALS['wpdb']->events ) );
$_POST['_wpnonce'] = wp_create_nonce( 'tcc_settings' ); $_SERVER['REQUEST_METHOD'] = 'GET'; $result = settings_response();
t( 'Settings GET cannot change grants', 403 === $result->status && $before === count( $GLOBALS['wpdb']->events ) ); $_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['reviewer_roles'] = array( 'unknown' ); $result = settings_response();
t( 'Invalid role fails whole settings save before audit or mutations', 400 === $result->status && $before === count( $GLOBALS['wpdb']->events ) && in_array( 'shop_manager', get_option( 'tcc_reviewer_roles' ), true ) );
$_POST['reviewer_roles'] = array( 'finance' ); $GLOBALS['wpdb']->fail = true; $result = settings_response();
t( 'Failed audit insert blocks role/user/configuration changes', 400 === $result->status && in_array( 'shop_manager', get_option( 'tcc_reviewer_roles' ), true ) && ! isset( $GLOBALS['opts']['tcc_settings'] ) );
$GLOBALS['wpdb']->fail = false; $result = settings_response();
t( 'Valid role selection saves and returns to access tab', 302 === $result->status && false !== strpos( $result->getMessage(), 'tab=access' ) && in_array( 'finance', get_option( 'tcc_reviewer_roles' ), true ) );
$event = end( $GLOBALS['wpdb']->events );
t( 'Role settings record actor and role list in private audit event', 1 === $event['actor'] && false !== strpos( $event['private_note'], 'finance' ) && 'settings_requested' === $event['action'] && '' === $event['public_reason'] );
$GLOBALS['uid'] = 4; t( 'New role becomes usable for review on next request', TCC_Helpers::reviewer( $order ) );
$GLOBALS['uid'] = 2; t( 'Old role loses review after saved replacement', ! TCC_Helpers::reviewer( $order ) );

// The service and private-receipt access consume the same role-derived capability.
$GLOBALS['uid'] = 1; $GLOBALS['opts']['tcc_settings']['cards'] = array( array( 'id' => 'test', 'number' => '6037990000000001', 'holder' => 'TEST ONLY', 'bank' => 'TEST', 'active' => true ) );
$repo = new TCC_Test_Repo(); $service = new TCC_Service( $repo, new TCC_Test_Lock(), new TCC_Test_Storage() );
$service->begin( 101 ); $service->submit( 101, array( 'request_key' => str_repeat( 'a', 32 ), 'reference' => '000123' ), array( 'error' => UPLOAD_ERR_OK ) );
$GLOBALS['uid'] = 4; $attempt = $repo->attempt( 101 );
t( 'Selected-role reviewer can view protected evidence', TCC_Access::can_view( $order, $attempt ) );
TCC_Permissions::assign( array(), array() );
t( 'Revoked role cannot view protected evidence without owner/token access', ! TCC_Access::can_view( $order, $attempt ) );
$data = array( 'revision' => $attempt['revision'], 'fingerprint' => TCC_Helpers::fingerprint( $order ), 'decision' => 'approve', 'confirmed' => 'yes' );
rejects( 'Revoked role cannot use a stale decision form', function () use ( $service, $data ) { $service->decide( 101, $data ); } );
t( 'Denied decision leaves order unpaid and receipt under review', ! $order->paid && 'under_review' === $repo->attempt( 101 )['status'] );
TCC_Permissions::assign( array( 'finance' ), array() ); $service->decide( 101, $data );
t( 'Authorized custom-role approval still uses standard payment flow once', $order->paid && 1 === $order->payment_calls && 'approved' === $repo->attempt( 101 )['status'] );
echo "\nResult: $pass permission checks passed, $fail failed (WordPress doubles, NOT live Woo/WordPress)\n"; exit( $fail ? 1 : 0 );
