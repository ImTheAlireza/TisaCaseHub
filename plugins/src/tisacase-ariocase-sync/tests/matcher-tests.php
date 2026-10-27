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
matcher_test( 'نویسه‌گذاری و فاصلهٔ رنگ‌های فارسی یکسان می‌شود', TCAS_Matcher::normalize_label( 'سرمه ای' ) === TCAS_Matcher::normalize_label( 'Navy' ) && TCAS_Matcher::normalize_label( 'قهوه‌ای' ) === TCAS_Matcher::normalize_label( 'Brown' ) && TCAS_Matcher::normalize_label( 'آبی روشن' ) === TCAS_Matcher::normalize_label( 'Light Blue' ) );
matcher_test( 'ظرفیت فارسی، واحدهای چسبیده و فاصله‌دار را یکسان می‌کند', TCAS_Matcher::normalize_label( '۱۲۸ گیگابایت' ) === TCAS_Matcher::normalize_label( '128 GB' ) );
matcher_test( 'مدل‌های دارای صفر پیشرو در دو فروشگاه هم‌ارز می‌شوند', TCAS_Matcher::normalize_label( 'Samsung A04s' ) === TCAS_Matcher::normalize_label( 'A4 S' ) );
$result = TCAS_Matcher::match( array( matcher_variation( 'ID:1', 'قاب مناسب گوشی A04s 128 گیگابایت', '' ) ), array( matcher_variation( 'ID:9/1', 'iPhone A4 S 128GB case', '' ) ) );
matcher_test( 'برچسب تزئینی، ترتیب واژه و واحد ظرفیت مانع تطبیق یکتا نمی‌شوند', array( 'ID:1=ID:9/1' ) === matcher_refs( $result ) );
$result = TCAS_Matcher::match( array( matcher_variation( 'ID:1', 'A34 4G', '' ) ), array( matcher_variation( 'ID:9/1', 'A34 5G', '' ) ) );
matcher_test( 'نسل شبکهٔ 4G و 5G با حذف تزئینات اشتباه یکی نمی‌شوند', 0 === count( $result['matches'] ) );
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


// ---- تشخیص دقیق دلیل رد شدن هر Variation (فلگ‌گذاری موارد مشکل‌دار) ----
$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', 'X' ), matcher_variation( 'ID:1', 'Blue', 'Y' ) ),
	array( matcher_variation( 'ID:9/1', 'Red', 'X' ), matcher_variation( 'ID:9/2', 'Blue', 'Y' ) )
);
$issue = $result['ambiguous'][0];
matcher_test( 'مرجع تکراری در همان فروشگاه با دلیل و شمارش تکرار علامت می‌خورد', 'duplicate_reference' === $issue['reason'] && 2 === $issue['duplicate_count'] && 1 === count( $issue['twins'] ) && 'Blue' === $issue['twins'][0]['label'] && 'ID:1' === $issue['twins'][0]['reference'], json_encode( $issue, JSON_UNESCAPED_UNICODE ) );
$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', 'X' ) ),
	array( matcher_variation( 'DUP', 'Red', 'X' ), matcher_variation( 'DUP', 'Blue', 'Y' ) )
);
$issue = $result['unmatched'][0];
matcher_test( 'همتای دارای مرجع تکراری به‌عنوان کاندید غیرقابل‌اتصال نشان داده می‌شود', 'counterpart_unusable' === $issue['reason'] && 1 === $issue['candidate_count'] && 'sku' === $issue['candidates'][0]['key'] && empty( $issue['candidates'][0]['usable'] ) && 'DUP' === $issue['candidates'][0]['reference'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Samsung A56', '' ) ),
	array( matcher_variation( 'ID:9/1', 'A56', 'A' ), matcher_variation( 'ID:9/2', 'Samsung A56', 'B' ) )
);
$issue = $result['ambiguous'][0];
matcher_test( 'چند گزینهٔ محتمل با دلیل مشخص و فهرست کاندیدها برمی‌گردد', 'multiple_candidates' === $issue['reason'] && 2 === $issue['candidate_count'] && 2 === $issue['usable_count'] && 2 === count( $issue['candidates'] ) && 'label' === $issue['candidates'][0]['key'], json_encode( $issue, JSON_UNESCAPED_UNICODE ) );
matcher_test( 'کاندیدهای ابهام مرجع دقیق و فروشگاه خودشان را دارند', 'ariocase' === $issue['candidates'][0]['shop'] && in_array( $issue['candidates'][0]['reference'], array( 'ID:9/1', 'ID:9/2' ), true ) );
matcher_test( 'کلید مشترک بدون گزینهٔ چندگانهٔ محلی هم فلگ می‌شود', 'shared_key' === $result['ambiguous'][1]['reason'] && 1 === $result['ambiguous'][1]['usable_count'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'iPhone 13', '' ) ),
	array( matcher_variation( 'ID:9/1', 'iPhone 14', '' ) )
);
$issue = $result['unmatched'][0];
matcher_test( 'Variation بدون همتا دلیل «بدون کاندید» و فهرست خالی دارد', 'no_candidate' === $issue['reason'] && 0 === $issue['candidate_count'] && array() === $issue['candidates'], json_encode( $issue, JSON_UNESCAPED_UNICODE ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Red', '' ) ),
	array( matcher_variation( 'DUP', 'Red', '' ), matcher_variation( 'DUP', 'Blue', '' ) )
);
$issue = $result['unmatched'][0];
matcher_test( 'همتای دارای مرجع تکراری به‌عنوان «غیرقابل‌اتصال» علامت می‌خورد', 'counterpart_unusable' === $issue['reason'] && $issue['candidate_count'] > 0 && 0 === $issue['usable_count'] && empty( $issue['candidates'][0]['usable'] ), json_encode( $issue, JSON_UNESCAPED_UNICODE ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( '', 'Same', 'X' ) ),
	array( matcher_variation( 'ID:9/1', 'Same', 'X' ) )
);
matcher_test( 'Variation بدون مرجع دقیق دلیل «مرجع ندارد» می‌گیرد', 'missing_reference' === $result['unmatched'][0]['reason'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'S25 Ultra', 'ABC-1' ) ),
	array( matcher_variation( 'ID:9/1', 's25ultra', 'ABC-1' ) )
);
matcher_test( 'تطبیق SKU با اطمینان کامل و بدون فلگ برمی‌گردد', 'sku' === $result['matches'][0]['matched_by'] && 'exact' === $result['matches'][0]['confidence'] && 1 === $result['summary']['exact'] && 0 === $result['summary']['fuzzy'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'S25 Ultra', '' ) ),
	array( matcher_variation( 'ID:9/1', 's25ultra', '' ) )
);
matcher_test( 'تطبیق از روی Label با فلگ بررسی و شمارش جداگانه برمی‌گردد', 'label' === $result['matches'][0]['matched_by'] && 'fuzzy' === $result['matches'][0]['confidence'] && 1 === $result['summary']['fuzzy'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'S25 Ultra black', '' ) ),
	array( matcher_variation( 'ID:9/1', 'black s25ultra', '' ) )
);
matcher_test( 'تطبیق از روی Token هم Fuzzy و قابل‌بررسی علامت می‌خورد', 'tokens' === $result['matches'][0]['matched_by'] && 'fuzzy' === $result['matches'][0]['confidence'] );


// ---- تطبیق هوشمند خانوادهٔ مدل (برچسب‌های هم‌معنی با نگارش متفاوت) ----
$result = TCAS_Matcher::match(
	array(
		matcher_variation( 'ID:401477', 'Redmi Note 11 Pro/12 Pro 4G', '' ),
		matcher_variation( 'ID:401476', 'Redmi Note 11 4G', '' ),
		matcher_variation( 'ID:401481', 'Redmi Note 14 Pro 4G', '' ),
	),
	array(
		matcher_variation( 'ID:45969/46049', 'Xiaomi Note 14 Pro (4G / 5G)', '' ),
		matcher_variation( 'ID:45969/46044', 'Xiaomi Note 11 Pro / Note 11s / Note 12s', '' ),
		matcher_variation( 'ID:45969/46045', 'Xiaomi Note 11 Pro / Note 12 Pro (4G)', '' ),
	)
);
matcher_test( 'برچسب هم‌معنی با نام برند متفاوت از راه خانوادهٔ مدل جفت می‌شود', in_array( 'ID:401477=ID:45969/46045', matcher_refs( $result ), true ) && 'family' === $result['matches'][0]['confidence'] && 'model' === $result['matches'][0]['matched_by'], json_encode( matcher_refs( $result ), JSON_UNESCAPED_UNICODE ) );
matcher_test( 'نسل شبکهٔ چندگانه (4G / 5G) مانع جفت‌شدن با 4G نمی‌شود', in_array( 'ID:401481=ID:45969/46049', matcher_refs( $result ), true ) && 'family' === $result['matches'][2]['confidence'] );
matcher_test( 'برچسب کوتاه خانواده با برچسب چندمدلی به‌عنوان پیشنهاد جفت می‌شود', in_array( 'ID:401476=ID:45969/46044', matcher_refs( $result ), true ) && 'suggested' === $result['matches'][1]['confidence'] && 'family' === $result['matches'][1]['matched_by'], json_encode( $result['matches'][1], JSON_UNESCAPED_UNICODE ) );
matcher_test( 'پیشنهاد خانوادگی مدل‌های مشترک و اضافه را برای بازبینی نشان می‌دهد', array( '11', 'note' ) === $result['matches'][1]['shared'] && in_array( '11s', $result['matches'][1]['extra'], true ) && in_array( '12s', $result['matches'][1]['extra'], true ) );
matcher_test( 'خلاصهٔ تطبیق تعداد خانواده و پیشنهاد را جدا می‌شمارد', 2 === $result['summary']['family'] && 1 === $result['summary']['suggested'] && 0 === $result['summary']['exact'] && 3 === count( $result['matches'] ) && array() === $result['ambiguous'] && array() === $result['unmatched'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 11 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 11s', '' ), matcher_variation( 'ID:9/2', 'Note 11 Pro / Note 12s', '' ) )
);
matcher_test( 'دو خانوادهٔ چندمدلی برای یک Variation، پیشنهاد خودکار نمی‌سازد و مبهم می‌ماند', 0 === count( $result['matches'] ) && 'multiple_families' === $result['ambiguous'][0]['reason'] && 2 === $result['ambiguous'][0]['candidate_count'], json_encode( $result['ambiguous'][0], JSON_UNESCAPED_UNICODE ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'A34 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'A34 5G', '' ) )
);
matcher_test( 'خانوادهٔ مدل هم 4G را به 5G وصل نمی‌کند', 0 === count( $result['matches'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 12 Pro 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 13 Pro 4G', '' ) )
);
matcher_test( 'مدل متفاوت در همان خانواده جفت نمی‌شود', 0 === count( $result['matches'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 11 Pro/12 Pro 4G', 'SKU-A' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 12 Pro 4G', '' ), matcher_variation( 'ID:9/2', 'SKU-A', 'SKU-A' ) )
);
matcher_test( 'اولویت SKU یکتا بر خانوادهٔ مدل حفظ می‌شود', array( 'ID:1=ID:9/2' ) === matcher_refs( $result ) && 'sku' === $result['matches'][0]['matched_by'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 11 Pro/12 Pro 4G', '' ), matcher_variation( 'ID:1', 'Note 11 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 12 Pro 4G', '' ) )
);
matcher_test( 'مرجع تکراری هرگز از راه خانوادهٔ مدل جفت نمی‌شود', 0 === count( $result['matches'] ) && 'duplicate_reference' === $result['ambiguous'][0]['reason'] );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 11 Pro/12 Pro 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 12 Pro 4G', '' ), matcher_variation( '', 'Note 11 Pro / Note 12 Pro 4G', '' ) )
);
matcher_test( 'Variation بدون مرجع از خانوادهٔ مدل کنار گذاشته می‌شود', array( 'ID:1=ID:9/1' ) === matcher_refs( $result ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'Note 11 Pro/12 Pro 4G', '' ), matcher_variation( 'ID:2', 'Note 11 Pro/12 Pro 4G', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 12 Pro 4G', '' ) )
);
matcher_test( 'خانوادهٔ مدل یک‌به‌یک است و دو Local به یک Remote وصل نمی‌شوند', 0 === count( $result['matches'] ) );


$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'iPhone 11', '' ) ),
	array( matcher_variation( 'ID:9/1', 'Note 11 Pro / Note 11s', '' ) )
);
matcher_test( 'برچسب تک‌توکنی (iPhone 11) با خانوادهٔ مدلی دیگر پیشنهاد نمی‌شود', 0 === count( $result['matches'] ) );

$result = TCAS_Matcher::match(
	array( matcher_variation( 'ID:1', 'A56', '' ) ),
	array( matcher_variation( 'ID:9/1', 'A56 / A56s', '' ) )
);
matcher_test( 'مدل تک‌توکنی بدون نام خانواده، پیشنهاد خانوادگی نمی‌سازد', 0 === count( $result['matches'] ) );

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! defined( 'TCAS_TEST_NO_EXIT' ) ) exit( $fail ? 1 : 0 );
