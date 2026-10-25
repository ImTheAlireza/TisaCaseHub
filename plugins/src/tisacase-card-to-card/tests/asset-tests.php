<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' );
define( 'TCC_URL', 'https://example.test/plugins/tisacase-card-to-card/' );
define( 'TCC_GATEWAY', 'tisacase_card_to_card' );
$root = dirname( __DIR__ );
preg_match( "/define\( 'TCC_VERSION', '([^']+)' \)/", file_get_contents( $root . '/tisacase-card-to-card.php' ), $version );
define( 'TCC_VERSION', $version[1] );
require_once $root . '/includes/class-tcc-frontend.php';
require_once $root . '/includes/class-tcc-order-admin.php';
$pass = 0; $fail = 0; $GLOBALS['hooks'] = array();
function t( $label, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][$hook][] = $callback; }
function is_checkout() { return ! empty( $GLOBALS['checkout'] ); }
function is_account_page() { return ! empty( $GLOBALS['account'] ); }
function wp_enqueue_style( $handle, $src, $deps = array(), $version = false ) { $GLOBALS['styles'][$handle] = array( $src, $deps, $version ); }
function wp_enqueue_script( $handle, $src, $deps = array(), $version = false, $footer = false ) { $GLOBALS['scripts'][$handle] = array( $src, $deps, $version, $footer ); }
function get_current_screen() { return $GLOBALS['screen']; }
TCC_Frontend::init();
t( 'Customer styles are registered on frontend hook only', 1 === count( $GLOBALS['hooks']['wp_enqueue_scripts'] ) && ! isset( $GLOBALS['hooks']['admin_enqueue_scripts'] ) );
$enqueue = $GLOBALS['hooks']['wp_enqueue_scripts'][0];
$GLOBALS['styles'] = array(); $GLOBALS['scripts'] = array(); $GLOBALS['checkout'] = true; $GLOBALS['account'] = false; $enqueue();
t( 'Checkout loads a separate blue customer stylesheet', array( 'tcc', 'tcc-customer' ) === array_keys( $GLOBALS['styles'] ) );
t( 'Blue stylesheet depends on shared styles and current release version', array( TCC_URL . 'assets/customer.css', array( 'tcc' ), TCC_VERSION ) === $GLOBALS['styles']['tcc-customer'] );
t( 'Frontend behaviour script is unchanged and versioned', array( TCC_URL . 'assets/frontend.js', array(), TCC_VERSION, true ) === $GLOBALS['scripts']['tcc-frontend'] );
$GLOBALS['styles'] = array(); $GLOBALS['checkout'] = false; $GLOBALS['account'] = true; $enqueue();
t( 'Customer account/order view also receives blue styles', isset( $GLOBALS['styles']['tcc-customer'] ) );
$GLOBALS['styles'] = array(); $GLOBALS['scripts'] = array(); $GLOBALS['account'] = false; $enqueue();
t( 'Unrelated storefront pages do not load gateway styles', empty( $GLOBALS['styles'] ) && empty( $GLOBALS['scripts'] ) );
foreach ( array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_tisacase-card-to-card' ) as $screen_id ) {
    $GLOBALS['screen'] = (object) array( 'id' => $screen_id ); $GLOBALS['styles'] = array(); $GLOBALS['scripts'] = array();
    TCC_Order_Admin::assets();
    t( 'Admin keeps only the original shared/green styles: ' . $screen_id, array( 'tcc', 'tcc-admin' ) === array_keys( $GLOBALS['styles'] ) && TCC_URL . 'assets/admin.css' === $GLOBALS['styles']['tcc-admin'][0] );
}
$GLOBALS['screen'] = (object) array( 'id' => 'dashboard' ); $GLOBALS['styles'] = array(); $GLOBALS['scripts'] = array(); TCC_Order_Admin::assets();
t( 'Unrelated admin screens receive neither customer nor gateway styles', empty( $GLOBALS['styles'] ) && empty( $GLOBALS['scripts'] ) );
t( 'Separate stylesheet is bundled locally', is_file( $root . '/assets/customer.css' ) );
echo "\nResult: $pass asset checks passed, $fail failed (WordPress hook doubles, NOT a live site)\n"; exit( $fail ? 1 : 0 );
