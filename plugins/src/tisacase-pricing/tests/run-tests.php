<?php
/**
 * تست منطق خالص افزونهٔ قیمت تیساکیس بدون وردپرس (با استاب).
 * اجرا:  php tests/run-tests.php
 *
 * @package TisaCase_Pricing
 */

require_once __DIR__ . '/stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-tcp-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-tcp-round.php';
require_once dirname( __DIR__ ) . '/includes/class-tcp-ops.php';
require_once dirname( __DIR__ ) . '/includes/class-tcp-rules.php';

/* ---------- هارنس ---------- */

$pass = 0;
$fail = 0;

function t( $label, $cond, $extra = '' ) {
	global $pass, $fail;
	if ( $cond ) {
		$pass++;
		echo "  OK   " . $label . "\n";
	} else {
		$fail++;
		echo "  FAIL " . $label . ( '' !== $extra ? '  >> ' . $extra : '' ) . "\n";
	}
}

function call_private( $class, $name, $args = array() ) {
	$rm = new ReflectionMethod( $class, $name );
	$rm->setAccessible( true );
	return $rm->invokeArgs( null, $args );
}

function mk_args( $over = array() ) {
	return array_merge( array(
		'operation'        => 'regular_decrease_percent',
		'target_type'      => 'category',
		'category_ids'     => array( 1, 2 ),
		'product_ids'      => array(),
		'include_children' => false,
		'round_mode'       => 'none',
		'value'            => 10.0,
		'filters'          => array(
			'only_sale'     => false,
			'only_wholesale'=> false,
			'price_min'     => null,
			'price_max'     => null,
			'types'         => array(),
			'statuses'      => array(),
		),
	), $over );
}

echo "--- 1) عدد و اعتبارسنجی (TCP_Ops) ---\n";
t( 'فارسی ۱٬۲۳۴ → 1234', TCP_Ops::number( '۱٬۲۳۴' ) === 1234.0, var_export( TCP_Ops::number( '۱٬۲۳۴' ), true ) );
t( '۱،۵۰۰،۰۰۰ → 1500000', TCP_Ops::number( '۱،۵۰۰،۰۰۰' ) === 1500000.0 );
t( 'ویرگول انگلیسی 1,234 → 1234', TCP_Ops::number( '1,234' ) === 1234.0 );
t( 'عدد نامعتبر → null', TCP_Ops::number( 'abc' ) === null && TCP_Ops::number( '' ) === null );
t( 'لیست شناسه‌ها پاکسازی می‌شود (تکراری/صفر/غیرعدد حذف)', TCP_Ops::ids( '1,2,0,abc,2' ) === array( 1, 2 ), implode( ',', TCP_Ops::ids( '1,2,0,abc,2' ) ) );
t( 'round_price: INF → false', TCP_Ops::round_price( INF ) === false );
t( 'round_price: منفی → صفر', 0.0 === (float) TCP_Ops::round_price( -5 ) );
t( 'round_price: منفی با min_zero=false دست‌نخورده', TCP_Ops::round_price( -5, false ) === -5.0 );
t( 'round_price: رشتهٔ غیرعدد → false', TCP_Ops::round_price( 'abc' ) === false );

echo "--- 2) کاتالوگ عملیات ---\n";
t( 'عملیات معتبر شناخته می‌شود', TCP_Ops::op_valid( 'regular_set' ) && TCP_Ops::op_valid( 'wholesale_from_retail_percent' ) );
t( 'عملیات نامعتبر رد می‌شود', TCP_Ops::op_valid( 'delete_everything' ) === false );
t( 'نوع درصد/مبلغ/تعیین/هیچ', TCP_Ops::op_kind( 'regular_increase_percent' ) === 'percent' && TCP_Ops::op_kind( 'regular_increase_fixed' ) === 'amount' && TCP_Ops::op_kind( 'regular_set' ) === 'set' && TCP_Ops::op_kind( 'sale_remove' ) === 'none' );
t( 'گروه عمده‌فروشی', TCP_Ops::is_wholesale_op( 'wholesale_set' ) && TCP_Ops::is_sale_op( 'sale_discount_percent' ) && TCP_Ops::is_regular_op( 'regular_set' ) );
t( 'سقف درصدِ عملیات‌های کاهشی = ۱۰۰', call_private( 'TCP_Ops', 'percent_cap_for', array( 'sale_discount_percent' ) ) === 100.0 );
t( 'سقف درصدِ افزایشی = PERCENT_CEIL', call_private( 'TCP_Ops', 'percent_cap_for', array( 'regular_increase_percent' ) ) === TCP_Settings::PERCENT_CEIL );

echo "--- 3) args_from_post (مسیر ورودی AJAX) ---\n";
$GLOBALS['tcp_can'] = false;
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x' ) );
t( 'بدون دسترسی → خطای forbidden', is_wp_error( $r ) && 'forbidden' === $r->get_error_code() );
$GLOBALS['tcp_can'] = true;
$GLOBALS['tcp_nonce_valid'] = false;
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x' ) );
t( 'nonce نامعتبر → خطای nonce', is_wp_error( $r ) && 'nonce' === $r->get_error_code() );
$GLOBALS['tcp_nonce_valid'] = true;
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'nope', 'target_type' => 'products', 'product_ids' => '1' ) );
t( 'عملیات نامعتبر → خطای op', is_wp_error( $r ) && 'op' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'all', 'product_ids' => '1' ) );
t( 'نوع انتخاب نامعتبر → خطای target', is_wp_error( $r ) && 'target' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'category' ) );
t( 'دستهٔ خالی → خطای empty', is_wp_error( $r ) && 'empty' === $r->get_error_code() );
$GLOBALS['tcp_terms'][55] = (object) array( 'term_id' => 55 );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'category', 'category_ids' => '999' ) );
t( 'دستهٔ ناموجود → خطای cat', is_wp_error( $r ) && 'cat' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'sale_discount_percent', 'target_type' => 'products', 'product_ids' => '1', 'value' => '120' ) );
t( 'تخفیف ۱۲۰٪ → خطای value (سقف ۱۰۰)', is_wp_error( $r ) && 'value' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_increase_fixed', 'target_type' => 'products', 'product_ids' => '1', 'value' => '-5' ) );
t( 'مقدار منفی → خطای value', is_wp_error( $r ) && 'value' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_increase_fixed', 'target_type' => 'products', 'product_ids' => '1', 'value' => '99999999999' ) );
t( 'مبلغ بیش از سقف مجاز → خطای value', is_wp_error( $r ) && 'value' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_decrease_percent', 'target_type' => 'products', 'product_ids' => '11,12', 'value' => '۱۵', 'round_mode' => 'bogus' ) );
t( 'ورودی درست → args سالم با عدد فارسی و round_mode نامعتبر→none',
	is_array( $r ) && $r['value'] === 15.0 && $r['product_ids'] === array( 11, 12 ) && $r['round_mode'] === 'none' );
$r2 = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'sale_remove', 'target_type' => 'products', 'product_ids' => '11' ) );
t( 'عملیات بدون مقدار → value=null', is_array( $r2 ) && null === $r2['value'] );

echo "--- 4) توکن اجرای گروهی (canonical/HMAC) ---\n";
$t1 = TCP_Ops::make_token( mk_args( array( 'category_ids' => array( 3, 1, 2 ) ) ) );
$t2 = TCP_Ops::make_token( mk_args( array( 'category_ids' => array( 1, 2, 3 ) ) ) );
t( 'ترتیب شناسه‌ها در توکن اثر ندارد', $t1 === $t2 );
t( 'verify_token رفت‌وبرگشت', TCP_Ops::verify_token( mk_args( array( 'category_ids' => array( 3, 1, 2 ) ) ), $t1 ) === true );
t( 'توکن خالی رد می‌شود', TCP_Ops::verify_token( mk_args(), '' ) === false );
t( 'آرگومان تغییریافته توکن را باطل می‌کند', TCP_Ops::verify_token( mk_args( array( 'value' => 11.0 ) ), $t1 ) === false );
$GLOBALS['tcp_user_id'] = 9;
$t3 = TCP_Ops::make_token( mk_args( array( 'category_ids' => array( 3, 1, 2 ) ) ) );
t( 'توکن به کاربر مقید است', $t1 !== $t3 );
t( 'args_hash مستقل از کاربر است', TCP_Ops::args_hash( mk_args() ) === TCP_Ops::args_hash( mk_args() ) );
$GLOBALS['tcp_user_id'] = 7;

echo "--- 5) محاسبهٔ قیمت عادی (calc_regular) ---\n";
TCP_Ops::set_round_mode( 'none' );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_increase_percent', '100000', 10 ) );
t( 'افزایش ۱۰٪: ۱۰۰٬۰۰۰ → ۱۱۰٬۰۰۰', $r['ok'] && (float) $r['new'] === 110000.0, var_export( $r, true ) );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_decrease_percent', '612300', 15 ) );
t( 'کاهش ۱۵٪ بدون رند: ۶۱۲٬۳۰۰ → ۵۲۰٬۴۵۵', $r['ok'] && (float) $r['new'] === 520455.0, var_export( $r['new'], true ) );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_increase_fixed', '100000', 5000 ) );
t( 'افزایش مبلغی: +۵٬۰۰۰', $r['ok'] && (float) $r['new'] === 105000.0 );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_set', '', 88000 ) );
t( 'تعیین قیمت روی محصول بی‌قیمت کار می‌کند', $r['ok'] && (float) $r['new'] === 88000.0 );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_increase_percent', '', 10 ) );
t( 'بدون قیمت فعلی (غیر set) → ok با پیام «قیمت عادی ندارد»', $r['ok'] && '' !== $r['msg'] );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_increase_fixed', '1000', 1e15 ) );
t( 'نتیجهٔ نجومی → ok=false (RESULT_CEIL)', $r['ok'] === false );
TCP_Ops::set_round_mode( 'round' );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_decrease_percent', '612300', 15 ) );
t( 'کاهش ۱۵٪ با رند: → ۵۱۸٬۰۰۰ (…۸٬۰۰۰)', $r['ok'] && (float) $r['new'] === 518000.0, var_export( $r['new'], true ) );
TCP_Ops::set_round_mode( 'jitter' );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_decrease_percent', '612300', 15, 42 ) );
t( 'حالت jitter روی …۸٬۰۰۰ می‌نشیند و زیر قیمت پایه است', $r['ok'] && fmod( (float) $r['new'], 10000 ) === 8000.0 && (float) $r['new'] < 612300, var_export( $r['new'], true ) );
TCP_Ops::set_round_mode( 'none' );

echo "--- 6) محاسبهٔ فروش ویژه (calc_sale) ---\n";
$r = call_private( 'TCP_Ops', 'calc_sale', array( 'sale_discount_percent', '200000', 10 ) );
t( 'تخفیف ۱۰٪ روی ۲۰۰٬۰۰۰ → ۱۸۰٬۰۰۰', $r['ok'] && (float) $r['new'] === 180000.0, var_export( $r, true ) );
$r = call_private( 'TCP_Ops', 'calc_sale', array( 'sale_set', '200000', 250000 ) );
t( 'فروش ویژه ≥ قیمت عادی رد می‌شود', $r['ok'] === false && strpos( $r['msg'], 'کمتر از قیمت عادی' ) !== false );
$r = call_private( 'TCP_Ops', 'calc_sale', array( 'sale_set', '200000', 150000 ) );
t( 'فروش ویژه < قیمت عادی قبول می‌شود', $r['ok'] && (float) $r['new'] === 150000.0 );
$r = call_private( 'TCP_Ops', 'calc_sale', array( 'sale_set', '', 150000 ) );
t( 'بدون قیمت عادی → خطا', $r['ok'] === false );
$r = call_private( 'TCP_Ops', 'calc_sale', array( 'sale_remove', '200000', 0 ) );
t( 'sale_remove → مقدار خالی', $r['ok'] && '' === $r['new'] );

echo "--- 7) محاسبهٔ قیمت عمده (calc_wholesale) ---\n";
$r = call_private( 'TCP_Ops', 'calc_wholesale', array( 'wholesale_from_retail_percent', '', '500000', 20 ) );
t( 'عمده = ۲۰٪ زیر خرده: ۵۰۰٬۰۰۰ → ۴۰۰٬۰۰۰', $r['ok'] && (float) $r['new'] === 400000.0, var_export( $r, true ) );
$r = call_private( 'TCP_Ops', 'calc_wholesale', array( 'wholesale_from_retail_percent', '', '', 20 ) );
t( 'بدون قیمت خرده → خطا', $r['ok'] === false );
$r = call_private( 'TCP_Ops', 'calc_wholesale', array( 'wholesale_decrease_percent', '1000', '', 100 ) );
t( 'عمدهٔ صفر/منفی رد می‌شود', $r['ok'] === false && strpos( $r['msg'], 'بیشتر از صفر' ) !== false );
$r = call_private( 'TCP_Ops', 'calc_wholesale', array( 'wholesale_increase_percent', '100000', '', 5 ) );
t( 'افزایش ۵٪ عمده: → ۱۰۵٬۰۰۰', $r['ok'] && (float) $r['new'] === 105000.0 );

echo "--- 8) موتور رند (TCP_Round) ---\n";
$GLOBALS['tcp_options']['tcp_settings'] = array(); // پیش‌فرض‌ها
t( 'گام پیش‌فرض IRT = ۱۰٬۰۰۰ و رقم ۸', TCP_Round::step() === 10000 && TCP_Round::digit() === 8 && TCP_Round::ending() === 8000 );
$GLOBALS['tcp_currency'] = 'IRR';
t( 'واحد ریال → گام ۱۰۰٬۰۰۰', TCP_Round::step() === 100000 && TCP_Round::ending() === 80000 );
$GLOBALS['tcp_currency'] = 'IRT';
$GLOBALS['tcp_options']['tcp_settings'] = array( 'round_step' => 5000, 'round_digit' => 5 );
t( 'گام/رقم دستی', TCP_Round::step() === 5000 && TCP_Round::ending() === 2500 );
$GLOBALS['tcp_options']['tcp_settings'] = array();
t( 'down: ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰', TCP_Round::down( 612300 ) === 608000.0, var_export( TCP_Round::down( 612300 ), true ) );
t( 'down: عددِ روی خط تکان نمی‌خورد', TCP_Round::down( 608000 ) === 608000.0 );
t( 'down: ۶۰۷٬۹۹۹ ← ۵۹۸٬۰۰۰', TCP_Round::down( 607999 ) === 598000.0 );
t( 'nearest: ۶۱۳٬۰۰۰ ← ۶۰۸٬۰۰۰ (فاصلهٔ برابر → پایین)', TCP_Round::nearest( 613000 ) === 608000.0 );
t( 'nearest: ۶۱۴٬۰۰۰ ← ۶۱۸٬۰۰۰', TCP_Round::nearest( 614000 ) === 618000.0 );
$u1 = TCP_Round::unit( 'seed-1' );
$u2 = TCP_Round::unit( 'seed-1' );
t( 'unit قطعی و در بازهٔ [0,1)', $u1 === $u2 && $u1 >= 0 && $u1 < 1 );
$j1 = TCP_Round::jittered_discount( 612300, 15, 'p-1' );
$j2 = TCP_Round::jittered_discount( 612300, 15, 'p-1' );
t( 'jittered_discount قطعی است', $j1 === $j2 );
t( 'jittered_discount: قیمت …۸٬۰۰۰ و کمتر از پایه', fmod( $j1['price'], 10000 ) === 8000.0 && $j1['price'] < 612300, var_export( $j1, true ) );
t( 'jittered_discount: درصد مؤثر داخل ۱۵±۵', $j1['percent'] >= 9.9 && $j1['percent'] <= 20.1, var_export( $j1['percent'], true ) );
$prices = array();
foreach ( range( 1, 40 ) as $seed ) { $prices[] = TCP_Round::jittered_discount( 612300, 15, 'x' . $seed )['price']; }
t( 'jittered_discount: تنوع بین شناسه‌ها (>۱ قیمت متفاوت)', count( array_unique( $prices ) ) > 1, 'distinct=' . count( array_unique( $prices ) ) );
$d = TCP_Round::discount( 100000, 10, 'none', 1 );
t( 'discount(none) = عدد خام', $d['price'] === 90000.0 );
$d = TCP_Round::discount( 612300, 15, 'round', 1 );
t( 'discount(round) = رند به پایین …۸٬۰۰۰', $d['price'] === 518000.0, var_export( $d['price'], true ) );
$d = TCP_Round::discount( 0, 10, 'round', 1 );
t( 'پایهٔ صفر → قیمت صفر (بدون تقسیم بر صفر)', $d['price'] === 8000.0 || $d['price'] === 0.0, var_export( $d, true ) );

echo "--- 9) تنظیمات (TCP_Settings) ---\n";
$GLOBALS['tcp_options'] = array();
$s = TCP_Settings::update_settings( array( 'batch_size' => '12', 'min_capability' => 'manage_options', 'round_digit' => '12', 'jitter_percent' => '999', 'logging' => '1' ) );
t( 'logging/rollback: کلید غایب → ۰، حاضر → ۱', 1 === $s['logging'] && 0 === $s['rollback'] && 0 === $s['scheduled'] );
t( 'round_digit به ۹ محدود می‌شود', 9 === $s['round_digit'] );
t( 'jitter_percent به ۵۰ محدود می‌شود', 50.0 === (float) $s['jitter_percent'] );
t( 'batch_size ذخیره‌شده خوانده می‌شود', TCP_Settings::batch_size() === 12 );
t( 'capability نامعتبر → پیش‌فرض manage_woocommerce', 'manage_woocommerce' === TCP_Settings::update_settings( array( 'min_capability' => 'hax0r' ) )['min_capability'] );
t( 'get_settings با defaults ادغام می‌شود و پیش‌نمایش پیش‌فرض ۳۰ ردیف دارد', TCP_Settings::get_settings()['confirm_threshold'] === 500 && TCP_Settings::get_settings()['sample_size'] === 30 );
TCP_Settings::update_settings( array( 'batch_size' => '500', 'lock_minutes' => '1', 'sample_size' => '999' ) );
t( 'clamp: batch→۱۰۰، lock→۲، sample→۱۰۰', TCP_Settings::batch_size() === 100 && TCP_Settings::lock_minutes() === 2 && TCP_Settings::sample_size() === 100 );
$GLOBALS['tcp_options'] = array( TCP_Settings::OPTION => array( 'sample_size' => 8, 'batch_size' => 12 ) );
TCP_Settings::maybe_migrate();
t( 'مهاجرت: پیش‌فرض قدیمی ۸ به ۳۰ می‌رسد و بقیهٔ تنظیم‌ها می‌مانند', TCP_Settings::sample_size() === 30 && TCP_Settings::batch_size() === 12 );
TCP_Settings::update_settings( array( 'sample_size' => '8' ) );
TCP_Settings::maybe_migrate();
t( 'بعد از مهاجرت، مقدار سفارشی ۸ قابل نگهداری است', TCP_Settings::sample_size() === 8 );
t( 'translation: کلید شناخته/ناشناخته', TCP_Settings::translation( 'done' ) === 'کامل شد' && TCP_Settings::translation( 'zzz' ) === 'zzz' );

echo "--- 10) محافظ CSV Injection (csv_cell) ---\n";
t( '= فرمول → خنثی', TCP_Settings::csv_cell( '=1+1' ) === "'=1+1" );
t( '+ فرمان → خنثی', TCP_Settings::csv_cell( '+cmd|calc' ) === "'+cmd|calc" );
t( '@ تابع → خنثی', TCP_Settings::csv_cell( '@SUM(A1)' ) === "'@SUM(A1)" );
t( '- غیرعددی → خنثی', TCP_Settings::csv_cell( '-2+3+cmd' ) === "'-2+3+cmd" );
t( 'عدد منفی سالم می‌ماند (ماهیت عددی)', TCP_Settings::csv_cell( '-5000' ) === '-5000' );
t( 'عدد و متن عادی دست‌نخورده', TCP_Settings::csv_cell( '5000' ) === '5000' && TCP_Settings::csv_cell( 'کیف چرم' ) === 'کیف چرم' );
t( 'رشتهٔ خالی → خالی', TCP_Settings::csv_cell( '' ) === '' );

echo "--- 11) قوانین داینامیک (TCP_Rules) ---\n";
function set_rules( $rules ) {
	$GLOBALS['tcp_options']['tcp_rules'] = $rules;
	$rc = new ReflectionClass( 'TCP_Rules' );
	foreach ( array( 'settings_cache' => null, 'rule_cache' => array(), 'category_ids' => array() ) as $prop => $val ) {
		$rp = $rc->getProperty( $prop );
		$rp->setAccessible( true );
		$rp->setValue( null, $val );
	}
}
$GLOBALS['tcp_post_terms'] = array( 11 => array( 55 ), 12 => array( 56 ), 13 => array( 77 ) );
$GLOBALS['tcp_term_children'] = array( 55 => array( 56 ) );

set_rules( array(
	'global'     => array( 'enabled' => 1, 'increase' => 30 ),
	'products'   => array( 11 => array( 'enabled' => 1, 'increase' => 10 ) ),
	'categories' => array(),
) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'اولویت: قانون محصول بر سراسری', $rule && 10.0 === (float) $rule['increase'], var_export( $rule, true ) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 13 ) ) );
t( 'بدون قانون محصول/دسته → سراسری', $rule && 30.0 === (float) $rule['increase'] );

set_rules( array(
	'global'   => array( 'enabled' => 1, 'increase' => 30 ),
	'products' => array( 11 => array( 'enabled' => 1, 'exclude' => 1 ) ),
) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'استثنای محصول → هیچ قانونی (حتی سراسری)', null === $rule );

set_rules( array(
	'global'     => array( 'enabled' => 1, 'increase' => 30 ),
	'categories' => array( 55 => array( 'enabled' => 1, 'increase' => 20 ) ),
) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'قانون دسته بر سراسری', $rule && 20.0 === (float) $rule['increase'] );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 12 ) ) );
t( 'زیردسته هم مشمول قانون والد است', $rule && 20.0 === (float) $rule['increase'], var_export( $rule, true ) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11, 'variation', 11 ) ) );
t( 'واریشن با شناسهٔ والد سنجیده می‌شود', $rule && 20.0 === (float) $rule['increase'] );

set_rules( array(
	'global'     => array( 'enabled' => 1, 'increase' => 30 ),
	'categories' => array( 55 => array( 'enabled' => 1, 'exclude' => 1 ) ),
) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'استثنای دسته → هیچ قانونی', null === $rule );

set_rules( array(
	'global'   => array( 'enabled' => 1, 'increase' => 30 ),
	'products' => array( 11 => array( 'enabled' => 1, 'increase' => 10, 'to' => '2020-01-01' ) ),
) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'قانون منقضی محصول → سقوط به سراسری', $rule && 30.0 === (float) $rule['increase'], var_export( $rule, true ) );

set_rules( array( 'global' => array( 'enabled' => 0 ) ) );
$rule = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 11 ) ) );
t( 'سراسری خاموش → هیچ قانونی', null === $rule );

$nr = TCP_Rules::normalize_rule( array( 'enabled' => 'yes', 'increase' => 99999, 'sale' => 500, 'min' => 100, 'max' => 50, 'mode' => 'bogus' ) );
t( 'normalize_rule: سقف increase=۵۰۰ و sale=۹۹.۹', 500.0 === (float) $nr['increase'] && 99.9 === (float) $nr['sale'], var_export( $nr, true ) );
t( 'normalize_rule: min>max → max=۰ و mode نامعتبر → round', 0.0 === (float) $nr['max'] && 'round' === $nr['mode'] && 1 === $nr['enabled'] );
t( 'rule_live: بازهٔ زمانی رعایت می‌شود',
	TCP_Rules::rule_live( TCP_Rules::normalize_rule( array( 'enabled' => 1 ) ) ) === true
	&& TCP_Rules::rule_live( TCP_Rules::normalize_rule( array( 'enabled' => 1, 'from' => '2027-01-01' ) ) ) === false
	&& TCP_Rules::rule_live( TCP_Rules::normalize_rule( array( 'enabled' => 1, 'to' => '2020-01-01' ) ) ) === false );

echo "--- 12) فهرست ثابت اهداف + بودجهٔ زمانی (مقاومت در برابر قطعی) ---\n";
t( 'encode: ورودی خالی → رشتهٔ خالی', TCP_Ops::encode_parent_ids( array() ) === '' );
$enc = TCP_Ops::encode_parent_ids( array( 5, 0, '9', 5, 3 ) );
t( 'encode: صفر حذف و تکراری یکی می‌شود', $enc === '5,9,3', $enc );
t( 'encode/decode رفت‌وبرگشت', TCP_Ops::decode_parent_ids( $enc ) === array( 5, 9, 3 ) );
t( 'decode رشتهٔ خراب/خالی/غیرعدد → آرایهٔ خالی',
	TCP_Ops::decode_parent_ids( '' ) === array()
	&& TCP_Ops::decode_parent_ids( null ) === array()
	&& TCP_Ops::decode_parent_ids( 'abc' ) === array() );
t( 'decode ترتیب فهرست را حفظ می‌کند (cursor همان ترتیب شروع اجراست)', TCP_Ops::decode_parent_ids( '10,7,300' ) === array( 10, 7, 300 ) );
$budget = TCP_Ops::time_budget();
t( 'time_budget همیشه زیر سقف PHP و بین ۵ تا ۲۰ ثانیه است', is_int( $budget ) && $budget >= 5 && $budget <= 20, 'budget=' . var_export( $budget, true ) );
$boost_ok = true;
try {
	TCP_Ops::runtime_boost();
} catch ( Throwable $e ) {
	$boost_ok = false;
}
t( 'runtime_boost بدون استثنا اجرا می‌شود', $boost_ok );

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit( $fail === 0 ? 0 : 1 );
