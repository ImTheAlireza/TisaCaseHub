<?php
/** Scope regressions: never broaden a category scope or modify archive dropdowns. */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['tcbv_test_saved_settings'] = array();
$GLOBALS['tcbv_test_terms'] = array(
	'phones'  => 21,
	'tablets' => 22,
);
$GLOBALS['tcbv_test_product_terms'] = array(
	301 => array( 21 ),
	302 => array( 22 ),
);
$GLOBALS['tcbv_test_pass'] = 0;
$GLOBALS['tcbv_test_fail'] = 0;
$GLOBALS['tcbv_test_is_product'] = false;

function get_option( $key, $default = false ) { return $GLOBALS['tcbv_test_saved_settings']; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $value ) ); }
function get_term_by( $field, $value, $taxonomy ) {
	if ( 'slug' !== $field || ! isset( $GLOBALS['tcbv_test_terms'][ $value ] ) ) { return false; }
	return (object) array( 'term_id' => $GLOBALS['tcbv_test_terms'][ $value ] );
}
function wp_get_post_terms( $product_id, $taxonomy, $args = array() ) { return isset( $GLOBALS['tcbv_test_product_terms'][ $product_id ] ) ? $GLOBALS['tcbv_test_product_terms'][ $product_id ] : array(); }
function is_product() { return $GLOBALS['tcbv_test_is_product']; }
function tcbv_test( $name, $condition ) {
	if ( $condition ) { $GLOBALS['tcbv_test_pass']++; echo "  OK   {$name}\n"; }
	else { $GLOBALS['tcbv_test_fail']++; echo "  FAIL {$name}\n"; }
}
function tcbv_test_settings( $settings ) {
	$GLOBALS['tcbv_test_saved_settings'] = $settings;
	$property = new ReflectionProperty( 'TCBV_Settings', 'cache' );
	$property->setAccessible( true );
	$property->setValue( null, null );
	$frontend_settings = new ReflectionProperty( 'TCBV_Frontend', 'settings' );
	$frontend_settings->setAccessible( true );
	$frontend_settings->setValue( null, null );
}

require_once dirname( __DIR__ ) . '/includes/class-tcbv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-tcbv-frontend.php';

// Test mode stays fail-closed until an explicit test product is chosen.
tcbv_test_settings( array() );
tcbv_test( 'حالت تست بدون انتخاب محصول روی هیچ محصولی فعال نمی‌شود', ! TCBV_Frontend::product_in_scope( 301 ) && ! TCBV_Frontend::product_in_scope( 0 ) );

// The all-products setting remains compatible with existing behavior.
tcbv_test_settings( array( 'test' => array( 'enabled' => 0 ), 'advanced' => array( 'products_scope' => 'all' ) ) );
tcbv_test( 'scope همه همچنان برای محصولات فعال است', TCBV_Frontend::product_in_scope( 301 ) );

// A category scope must never turn a missing product ID into a global match.
tcbv_test_settings( array( 'test' => array( 'enabled' => 0 ), 'advanced' => array( 'products_scope' => 'include', 'categories' => 'phones' ) ) );
tcbv_test( 'include با شناسهٔ محصول نامعلوم fail-closed است', ! TCBV_Frontend::product_in_scope( 0 ) && ! TCBV_Frontend::product_in_scope( -1 ) );
tcbv_test( 'include فقط محصولِ دارای دستهٔ انتخاب‌شده را می‌پذیرد', TCBV_Frontend::product_in_scope( 301 ) && ! TCBV_Frontend::product_in_scope( 302 ) );

// Exclusion is also unknown without an ID; do not touch ambiguous dropdowns.
tcbv_test_settings( array( 'test' => array( 'enabled' => 0 ), 'advanced' => array( 'products_scope' => 'exclude', 'categories' => 'phones' ) ) );
tcbv_test( 'exclude بدون شناسهٔ محصول fail-closed است', ! TCBV_Frontend::product_in_scope( 0 ) );
tcbv_test( 'exclude محصولِ دستهٔ مشخص را رد و بقیه را حفظ می‌کند', ! TCBV_Frontend::product_in_scope( 301 ) && TCBV_Frontend::product_in_scope( 302 ) );

// Even when global scope is configured, dropdown HTML is untouched away from a product page.
tcbv_test_settings( array( 'test' => array( 'enabled' => 0 ), 'advanced' => array( 'products_scope' => 'all' ) ) );
$GLOBALS['tcbv_test_is_product'] = false;
$html = '<select><option value="a">A</option><option value="b">B</option><option value="c">C</option></select>';
tcbv_test( 'dropdownهای آرشیو/فروشگاه خارج از صفحهٔ محصول دست‌نخورده می‌مانند', TCBV_Frontend::dropdown_html( $html, array( 'attribute' => 'pa_model' ) ) === $html );

 echo "نتیجه: {$GLOBALS['tcbv_test_pass']} موفق، {$GLOBALS['tcbv_test_fail']} ناموفق\n";
exit( $GLOBALS['tcbv_test_fail'] === 0 ? 0 : 1 );
