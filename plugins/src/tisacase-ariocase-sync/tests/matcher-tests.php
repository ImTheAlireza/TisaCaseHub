<?php
/** تطبیق محافظه‌کارانهٔ Variationها و جلوگیری از اتصال مبهم. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/class-tcas-matcher.php';
$pass = 0; $fail = 0;
function matcher_test( $label, $condition, $detail = '' ) { global $pass, $fail; if ( $condition ) { $pass++; echo "OK   {$label}\n"; } else { $fail++; echo "FAIL {$label}" . ( $detail ? ' >> ' . $detail : '' ) . "\n"; } }
function matcher_variation( $reference, $label, $sku = '' ) { return array( 'reference' => $reference, 'label' => $label, 'sku' => $sku ); }
function matcher_refs( $result ) { return array_map( function( $match ) { return $match['local_code'] . '=' . $match['remote_code']; }, $result['matches'] ); }

matcher_test( 'SKU با فاصله و خط تیره نرمال می‌شود', 's25ultra' === TCAS_Matcher::normalize_sku( ' S25-Ultra ' ) );
matcher_test( 'اعداد فارسی و عربی در SKU نرمال می‌شوند', 's25ultra' === TCAS_Matcher::normalize_sku( 'S۲۵ Ultra' ) && 's25ultra' === TCAS_Matcher::normalize_sku( 'S٢٥ Ultra' ) );
matcher_test( 'برند و فاصلهٔ مدل در Label حذف می‌شود', 's25ultra' === TCAS_Matcher::normalize_label( 'Samsung Galaxy S25 Ultra' ) && 's25ultra' === TCAS_Matcher::normalize_label( 's25ultra' ) );
matcher_test( 'معادل فارسی مدل و رنگ نرمال می‌شود', 's25ultrablack' === TCAS_Matcher::normalize_label( 'گلکسی S۲۵ اولترا مشکی' ) );
matcher_test( 'Samsung A56 و A56 دقیقاً هم‌معنی‌اند', 'a56' === TCAS_Matcher::normalize_label( 'Samsung A56' ) && TCAS_Matcher::normalize_label( 'Samsung A56' ) === TCAS_Matcher::normalize_label( 'A56' ) );
matcher_test( 'برند چسبیده به مدل هم نرمال می‌شود', 'a56' === TCAS_Matcher::normalize_label( 'SamsungA56' ) && '13pro' === TCAS_Matcher::normalize_label( 'iPhone13Pro' ) && 'note14' === TCAS_Matcher::normalize_label( 'RedmiNote14' ) );
matcher_test( 'Poco و Mi و Redmi بدون تغییر مدل حذف می‌شوند', 'x7' === TCAS_Matcher::normalize_label( 'Poco X7' ) && '11tpro' === TCAS_Matcher::normalize_label( 'Mi 11T Pro' ) && 'note14pro5g' === TCAS_Matcher::normalize_label( 'Redmi Note 14 Pro 5G' ) );
$result = TCAS_Matcher::match( array( matcher_variation( 'ID:56', 'Samsung A56', '' ) ), array( matcher_variation( 'ID:9/56', 'A56', '' ), matcher_variation( 'ID:9/55', 'A55', '' ) ) );
matcher_test( 'A56 فقط به A56 وصل می‌شود نه A55', array( 'ID:56=ID:9/56' ) === matcher_refs( $result ) && 1 === count( $result['unmatched'] ) );

$local = array(
	matcher_variation( 'ID:101', 'Samsung Galaxy S25 Ultra', 'SHARED' ),
	matcher_variation( 'ID:102', 'Samsung Galaxy S24', 'SHARED' ),
);
$remote = array(
	matcher_variation( 'ID:900/901', 's25ultra', 'SHARED' ),
	matcher_variation( 'ID:900/902', 'S25', 'SHARED' ),
);
$result = TCAS_Matcher::match( $local, $remote );
matcher_test( 'S25 Ultra و s25ultra با SKU مشترک از روی Label جفت می‌شوند', array( 'ID:101=ID:900/901' ) === matcher_refs( $result ) );
matcher_test( 'S24 به S25 یا S25 Ultra وصل نمی‌شود', ! in_array( 'ID:102=ID:900/902', matcher_refs( $result ), true ) && 1 === count( $result['matches'] ) );
matcher_test( 'SKU مشترک به‌تنهایی همه را به هم وصل نمی‌کند', 1 === count( $result['matches'] ) && ! empty( $result['ambiguous'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Local first', 'LOCAL-1' ), matcher_variation( 'ID:2', 'Local second', 'LOCAL-2' ) ),
	array( matcher_variation( 'ID:8/1', 'Remote first', 'LOCAL-1' ), matcher_variation( 'ID:8/2', 'Remote second', 'LOCAL-2' ) )
);
matcher_test( 'SKU یکتا حتی با Label متفاوت در فروشگاه‌ها معتبر است', 2 === count( $result['matches'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Black', 'A' ), matcher_variation( 'ID:2', 'Other', 'B' ) ),
	array( matcher_variation( 'ID:9/1', 'Black', 'B' ), matcher_variation( 'ID:9/2', 'Other', 'C' ) )
);
matcher_test( 'SKU دقیق بر Label رقیب اولویت دارد و Remote دوباره استفاده نمی‌شود', array( 'ID:2=ID:9/1' ) === matcher_refs( $result ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', '' ), matcher_variation( 'ID:2', 'Red', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Red', '' ), matcher_variation( 'ID:9/2', 'Red', '' ) )
);
matcher_test( 'Label تکراری در دو سمت هیچ انتخاب دلخواهی ندارد', 0 === count( $result['matches'] ) && 4 === count( $result['ambiguous'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', '' ), matcher_variation( 'ID:2', 'Red', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Red', '' ) )
);
matcher_test( 'دو Local برای یک Remote جفت نمی‌شوند', 0 === count( $result['matches'] ) && 3 === count( $result['ambiguous'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'S25 Ultra black', '' ) ),
	array( matcher_variation( 'ID:9/1', 'black s25ultra', '' ) )
);
matcher_test( 'ترتیب متفاوت Tokenهای هم‌معنی با امتیاز پایین‌تر جفت می‌شود', array( 'ID:1=ID:9/1' ) === matcher_refs( $result ) && 90 === $result['matches'][0]['score'], json_encode( $result ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'آیفون ۱۳', '' ), matcher_variation( 'ID:2', 'آیفون ۱۳ پرو', '' ) ),
	array( matcher_variation( 'ID:9/1', 'iPhone 13 Pro', '' ), matcher_variation( 'ID:9/2', 'iPhone 13', '' ) )
);
matcher_test( 'مدل پایه و Pro با ترتیب متفاوت هم اشتباه نمی‌شوند', array( 'ID:1=ID:9/2', 'ID:2=ID:9/1' ) === matcher_refs( $result ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'قرمز', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Red', '' ), matcher_variation( 'ID:9/2', 'Blue', '' ) )
);
matcher_test( 'معادل رنگ فارسی/انگلیسی جفت و مورد نامرتبط کنار گذاشته می‌شود', array( 'ID:1=ID:9/1' ) === matcher_refs( $result ) && 1 === count( $result['unmatched'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( '', 'Same', 'X' ) ),
	array( matcher_variation( 'ID:9/1', 'Same', 'X' ) )
);
matcher_test( 'Variation بدون مرجع دقیق هرگز خروجی اتصال نمی‌گیرد', 0 === count( $result['matches'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', '' ), matcher_variation( 'ID:2', 'Blue', '' ) ),
	array( matcher_variation( 'DUP', 'Red', 'DUP' ), matcher_variation( 'DUP', 'Blue', 'DUP' ) )
);
matcher_test( 'مرجع خروجی تکراری حتی با Label متفاوت رد می‌شود', 0 === count( $result['matches'] ) && 2 === count( $result['ambiguous'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:2', 'Second', '' ), matcher_variation( 'ID:1', 'First', '' ) ),
	array( matcher_variation( 'ID:9/2', 'Second', '' ), matcher_variation( 'ID:9/1', 'First', '' ) )
);
matcher_test( 'خروجی به ترتیب Variationهای Local مرتب است', array( 'ID:2=ID:9/2', 'ID:1=ID:9/1' ) === matcher_refs( $result ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
