<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) { if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } return false; } );
define( 'ABSPATH', '/wordpress/' ); require_once dirname( __DIR__ ) . '/includes/class-tcc-helpers.php';
$pass = 0; $fail = 0;
function t( $label, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "OK $label\n"; } else { $fail++; echo "FAIL $label\n"; } }
$cases = array(
    array( '776000.00000000', 'IRT', '7760000' ), array( '485000', 'IRT', '4850000' ), array( '7760000.00000000', 'IRR', '7760000' ),
    array( '000000.00000000', 'IRR', '0' ), array( '0', 'IRT', '0' ), array( '00012.30', 'IRT', '123' ),
    array( '0.1', 'IRT', '1' ), array( '0.01', 'IRT', '0.1' ), array( '0.00000001', 'IRT', '0.0000001' ),
    array( '12.34567890', 'IRT', '123.456789' ), array( '12.34567890', 'IRR', '12.3456789' ),
    array( '999999999999999999.99999999', 'IRT', '9999999999999999999.9999999' ),
    array( '999999999999999999.99999999', 'IRR', '999999999999999999.99999999' ),
    array( '۱۲۳۴۵۶.۵', 'IRT', '1234565' ), array( '١٢٣.٥', 'IRR', '123.5' ),
);
foreach ( $cases as $case ) { t( 'Exact rial conversion ' . $case[0] . ' ' . $case[1], $case[2] === TCC_Helpers::rial_amount( $case[0], $case[1] ) ); }
foreach ( array( '-1', '+1', '1e6', '1,000', ' 100', '100 ', '1.123456789', '1000000000000000000', 'NaN', '<b>100</b>', '', array(), null ) as $bad ) { t( 'Malformed/unsafe amount fails closed', '' === TCC_Helpers::rial_amount( $bad, 'IRT' ) ); }
foreach ( array( 'USD', 'IRHT', 'IRHR', 'irt', '' ) as $currency ) { t( 'Unsupported currency is not silently relabeled as IRR', '' === TCC_Helpers::rial_amount( '100', $currency ) ); }
t( 'Persian amount grouping includes exact rials', '۷٬۷۶۰٬۰۰۰' === TCC_Helpers::persian_number( '7760000' ) );
t( 'Fractional rials are preserved, never rounded away', '۱۲۳٫۴۵۶۷۸۹' === TCC_Helpers::persian_number( '123.456789' ) );
t( 'Large rial display never goes through floating point', '۹٬۹۹۹٬۹۹۹٬۹۹۹٬۹۹۹٬۹۹۹٬۹۹۹٫۹۹۹۹۹۹۹' === TCC_Helpers::persian_number( '9999999999999999999.9999999' ) );
t( 'Invalid formatted input rejected', '' === TCC_Helpers::persian_number( '1,000' ) );
t( 'Saman bank mark is local and recognized by Persian name', 'saman.svg' === TCC_Helpers::bank_logo( 'بانک سامان' ) );
t( 'Arabic character variants are normalized', 'melli.svg' === TCC_Helpers::bank_logo( 'بانك ملي ايران' ) );
t( 'Zero-width separators are normalized', 'eghtesad.svg' === TCC_Helpers::bank_logo( 'بانک اقتصاد‌نوین' ) );
t( 'Unknown merchant bank names use generic mark', '' === TCC_Helpers::bank_logo( 'بانک آزمایشی' ) );
t( 'Bank name cannot become an asset path', '' === TCC_Helpers::bank_logo( '../../saman.svg' ) );
t( 'HTML in a bank name cannot choose an external asset', '' === TCC_Helpers::bank_logo( '<img src=https://example.test>' ) );
foreach ( array( 'سامان', 'ملی', 'ملت', 'تجارت', 'سپه', 'پارسیان', 'پاسارگاد', 'صادرات', 'مسکن', 'رفاه', 'کشاورزی', 'اقتصاد نوین', 'شهر', 'سینا', 'مهر ایران', 'رسالت', 'پست بانک', 'دی', 'آینده', 'بلوبانک' ) as $name ) { t( 'Bundled bank logo exists: ' . $name, is_file( dirname( __DIR__ ) . '/assets/banks/' . TCC_Helpers::bank_logo( $name ) ) ); }
t( 'UI icons are static accessible decorative SVGs', false !== strpos( TCC_Helpers::ui_icon( 'copy' ), 'aria-hidden="true"' ) && false === strpos( TCC_Helpers::ui_icon( '<script>' ), '<script>' ) );
echo "\nResult: $pass presentation checks passed, $fail failed (exact decimal display only)\n"; exit( $fail ? 1 : 0 );
