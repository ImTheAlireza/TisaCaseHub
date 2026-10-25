<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' );
$root = dirname( __DIR__ );
foreach ( array( 'helpers', 'settings', 'repository', 'storage', 'admin' ) as $class ) { require_once $root . '/includes/class-tcc-' . $class . '.php'; }
require_once __DIR__ . '/stubs.php';
$pass = 0; $fail = 0;
function t( $label, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
function pan( $prefix ) { foreach ( range( 0, 9 ) as $digit ) { $pan = $prefix . $digit; if ( TCC_Helpers::card( $pan ) ) { return $pan; } } throw new RuntimeException( 'Invalid test prefix' ); }
function row_html( $bank ) { ob_start(); TCC_Admin::card_row( array( 'bank' => $bank ), 0 ); return ob_get_clean(); }
$catalog = TCC_Helpers::bank_catalog(); $number = pan( '603799000000000' ); $second = pan( '621986000000000' );
$card = array( 'number' => $number, 'holder' => 'TEST ONLY', 'active' => 1 );
$GLOBALS['opts']['tcc_settings'] = array( 'cards' => array() );
t( 'One shared catalog covers 37 bank/brand/legacy names', 37 === count( $catalog ) );
foreach ( $catalog as $key => $bank ) {
    t( 'Catalog and local asset: ' . $key, $key === TCC_Helpers::bank_key( $bank['name'] ) && $key === TCC_Helpers::bank_key( $key ) && is_file( $root . '/assets/banks/' . $key . '.svg' ) );
    $aliases_ok = true;
    foreach ( array_merge( array( $bank['name'] ), $bank['aliases'] ) as $alias ) { $aliases_ok = $aliases_ok && $key . '.svg' === TCC_Helpers::bank_logo( $alias ) && $key . '.svg' === TCC_Helpers::bank_logo( 'بانک ' . $alias ); }
    t( 'Official/legacy aliases choose the same logo: ' . $key, $aliases_ok );
    $clean = TCC_Settings::sanitize( array( 'cards' => array( array_merge( $card, array( 'bank' => $bank['name'] ) ) ) ) );
    t( 'Known dropdown choice saves without changing destination: ' . $key, $bank['name'] === $clean['cards'][0]['bank'] && $number === $clean['cards'][0]['number'] && 'TEST ONLY' === $clean['cards'][0]['holder'] );
    $html = row_html( $bank['name'] );
    t( 'Native dropdown and local preview render: ' . $key, false !== strpos( $html, '<select data-card-field="bank"' ) && false === strpos( $html, '<input data-card-field="bank"' ) && false !== strpos( $html, 'assets/banks/' . $key . '.svg' ) && false !== strpos( $html, 'data-tcc-bank-logo' ) );
}
foreach ( array( '../../saman.svg', 'https://example.test/logo.svg', 'saman.svg', 'بانک دلخواه جدید', array( 'سامان' ), (object) array( 'bank' => 'سامان' ) ) as $bad ) {
    try { TCC_Settings::sanitize( array( 'cards' => array( array_merge( $card, array( 'bank' => $bad ) ) ) ) ); t( 'New unknown/malformed bank is rejected', false ); }
    catch ( InvalidArgumentException $e ) { t( 'New unknown/malformed bank is rejected', true ); }
}
foreach ( array( null, array(), new stdClass(), 123, "\xff", str_repeat( 'س', 300 ) ) as $bad ) { t( 'Unsafe logo input fails closed without a warning', '' === TCC_Helpers::bank_logo( $bad ) ); }
$canonical = TCC_Settings::sanitize( array( 'cards' => array( array_merge( $card, array( 'bank' => 'بانك ملي ايران' ) ) ) ) );
t( 'New recognized aliases save as the catalog spelling', 'ملی ایران' === $canonical['cards'][0]['bank'] );
foreach ( array( 'بانك ملي ايران', 'بانک ثبت‌شده قدیمی', '' ) as $legacy ) {
    $previous = array_merge( $card, array( 'bank' => $legacy, 'id' => 'legacy-id' ) ); $GLOBALS['opts']['tcc_settings'] = array( 'cards' => array( $previous ) );
    $before = $GLOBALS['opts']; $clean = TCC_Settings::sanitize( array( 'cards' => array( $previous ), 'hours' => 48 ) );
    t( 'Unchanged existing bank text is preserved verbatim', $legacy === $clean['cards'][0]['bank'] && $number === $clean['cards'][0]['number'] );
    t( 'Validation has no option or financial side effects', $before === $GLOBALS['opts'] );
}
$old_name = 'بانک ثبت‌شده قدیمی'; $GLOBALS['opts']['tcc_settings'] = array( 'cards' => array( array_merge( $card, array( 'bank' => $old_name ) ) ) );
try { TCC_Settings::sanitize( array( 'cards' => array( array_merge( $card, array( 'number' => $second, 'bank' => $old_name ) ) ) ) ); t( 'Legacy unknown name cannot be copied to another destination', false ); }
catch ( InvalidArgumentException $e ) { t( 'Legacy unknown name cannot be copied to another destination', true ); }
$html = row_html( 'بانك ملي ايران' );
t( 'Recognized old spelling remains the selected form value', false !== strpos( $html, 'value="بانك ملي ايران"' ) && false !== strpos( $html, '/melli.svg' ) );
$html = row_html( '"><img src=x onerror=alert(1)>' );
t( 'Legacy option is escaped and never becomes HTML or an image path', false === strpos( $html, '<img src=x' ) && false !== strpos( $html, '&lt;img' ) && false === strpos( $html, 'assets/banks/\"' ) );
$html = row_html( '' );
t( 'New row offers only catalog banks, not a free-text/custom name', 37 === substr_count( $html, 'data-bank-logo=' ) && false === strpos( $html, '(نام فعلی)' ) );
if ( getenv( 'TCC_TEST_OUTPUT' ) ) { file_put_contents( getenv( 'TCC_TEST_OUTPUT' ) . '/banks.json', wp_json_encode( $catalog ) ); }
echo "\nResult: $pass bank checks passed, $fail failed (catalog/settings/markup doubles, NOT bank verification)\n"; exit( $fail ? 1 : 0 );
