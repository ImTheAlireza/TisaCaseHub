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
require_once dirname( __DIR__ ) . '/includes/class-tcp-db.php';
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
		'product_ids'                => array(),
		'include_children'           => false,
		'excluded_category_ids'      => array(),
		'excluded_product_ids'       => array(),
		'exclude_category_children'  => false,
		'round_mode'                 => 'none',
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
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'nope', 'product_ids' => '1' ) );
t( 'نوع انتخاب نامعتبر → خطای target', is_wp_error( $r ) && 'target' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_increase_percent', 'target_type' => 'all', 'value' => '10' ) );
t( 'هدف «همه» پذیرفته می‌شود و به شناسهٔ محصول نیاز ندارد', is_array( $r ) && 'all' === $r['target_type'] && 10.0 === $r['value'] );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'category' ) );
t( 'دستهٔ خالی → خطای empty', is_wp_error( $r ) && 'empty' === $r->get_error_code() );
$GLOBALS['tcp_terms'][55] = (object) array( 'term_id' => 55 );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'category', 'category_ids' => '999' ) );
t( 'دستهٔ ناموجود → خطای cat', is_wp_error( $r ) && 'cat' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array(
	'nonce' => 'x', 'operation' => 'regular_increase_percent', 'target_type' => 'all', 'value' => '10',
	'excluded_category_ids' => '55', 'excluded_product_ids' => '11,12,11,0,bad', 'exclude_category_children' => '1',
) );
t( 'استثناهای معتبر پاکسازی و در آرگومان ذخیره می‌شوند',
	is_array( $r ) && $r['excluded_category_ids'] === array( 55 ) && $r['excluded_product_ids'] === array( 11, 12 ) && true === $r['exclude_category_children'] );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'all', 'excluded_category_ids' => '999' ) );
t( 'دستهٔ استثنا ناموجود رد می‌شود', is_wp_error( $r ) && 'excluded_cat' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'all', 'excluded_product_ids' => implode( ',', range( 1, 501 ) ) ) );
t( 'بیش از ۵۰۰ محصول استثنا رد می‌شود', is_wp_error( $r ) && 'exclude_limit' === $r->get_error_code() );
$r = TCP_Ops::args_from_post( array( 'nonce' => 'x', 'operation' => 'regular_set', 'target_type' => 'all', 'excluded_category_ids' => implode( ',', range( 1, 101 ) ) ) );
t( 'بیش از ۱۰۰ دستهٔ استثنا رد می‌شود', is_wp_error( $r ) && 'exclude_limit' === $r->get_error_code() );
t( 'لیست شناسهٔ غیرعددی/منفی/تو در تو نادیده گرفته می‌شود', TCP_Ops::ids( array( '4', '-5', 'abc', array( '6' ), '7' ) ) === array( 4, 7 ) );
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
t( 'توکن استثنای محصول را امضا می‌کند',
	TCP_Ops::make_token( mk_args( array( 'excluded_product_ids' => array( 10 ) ) ) ) !== TCP_Ops::make_token( mk_args( array( 'excluded_product_ids' => array( 11 ) ) ) ) );
t( 'توکن استثنای زیردسته را امضا می‌کند',
	TCP_Ops::make_token( mk_args( array( 'exclude_category_children' => true ) ) ) !== TCP_Ops::make_token( mk_args( array( 'exclude_category_children' => false ) ) ) );
$snapshot = array( 'parents' => 1200, 'ceiling' => 1200, 'terms' => array( 3, 2 ), 'excluded_terms' => array( 9, 8 ) );
$snapshot_token = TCP_Ops::make_token( mk_args(), $snapshot );
t( 'snapshot شمارش/سقف/درخت دسته با همان مقدار تأیید می‌شود', TCP_Ops::verify_token( mk_args(), $snapshot_token, $snapshot ) === true );
t( 'ترتیب درخت دسته بر توکن اثر ندارد', TCP_Ops::make_token( mk_args(), array_merge( $snapshot, array( 'terms' => array( 2, 3 ), 'excluded_terms' => array( 8, 9 ) ) ) ) === $snapshot_token );
t( 'تغییر شمارش snapshot توکن را باطل می‌کند', TCP_Ops::verify_token( mk_args(), $snapshot_token, array_merge( $snapshot, array( 'parents' => 1199 ) ) ) === false );
t( 'تغییر درخت دستهٔ مستثنا توکن را باطل می‌کند', TCP_Ops::verify_token( mk_args(), $snapshot_token, array_merge( $snapshot, array( 'excluded_terms' => array( 9, 10 ) ) ) ) === false );
t( 'توکن خالی رد می‌شود', TCP_Ops::verify_token( mk_args(), '' ) === false );
t( 'آرگومان تغییریافته توکن را باطل می‌کند', TCP_Ops::verify_token( mk_args( array( 'value' => 11.0 ) ), $t1 ) === false );
$GLOBALS['tcp_user_id'] = 9;
$t3 = TCP_Ops::make_token( mk_args( array( 'category_ids' => array( 3, 1, 2 ) ) ) );
t( 'توکن به کاربر مقید است', $t1 !== $t3 );
t( 'args_hash مستقل از کاربر است', TCP_Ops::args_hash( mk_args() ) === TCP_Ops::args_hash( mk_args() ) );
$GLOBALS['tcp_user_id'] = 7;

$GLOBALS['tcp_term_children'][1] = array( 2 );
$target_args = mk_args( array( 'target_type' => 'category', 'category_ids' => array( 1 ), 'include_children' => true ) );
$target_args['scan'] = array( 'terms' => TCP_DB::effective_terms( $target_args ) );
$GLOBALS['tcp_term_children'][1] = array( 3 );
t( 'درخت دستهٔ هدف بعد از snapshot به تغییر سلسله‌مراتب وابسته نمی‌شود', TCP_DB::effective_terms( $target_args ) === array( 1, 2 ) );
$GLOBALS['tcp_term_children'][55] = array( 56, 57 );
$GLOBALS['wpdb'] = new TCP_Test_WPDB();
$excluded_args = mk_args( array(
	'excluded_category_ids' => array( 55 ),
	'excluded_product_ids'  => array( 31, 32 ),
	'exclude_category_children' => true,
) );
t( 'گسترش دستهٔ مستثنا زیردسته‌ها را هم می‌گیرد', TCP_DB::effective_excluded_terms( $excluded_args ) === array( 55, 56, 57 ) );
t( 'دسته‌های استثنا در keyset از snapshot فریز‌شده خوانده می‌شوند',
	TCP_DB::effective_excluded_terms( array_merge( $excluded_args, array( 'scan' => array( 'excluded_terms' => array( 88, 89 ) ) ) ) ) === array( 88, 89 ) );
$exclusion_sql = call_private( 'TCP_DB', 'exclusion_filter_sql', array( 'p', $excluded_args ) );
t( 'SQL استثنای محصول، واریشن انتخاب‌شده را نیز از مادر خارج می‌کند',
	strpos( $exclusion_sql, 'ex.ID IN (31,32)' ) !== false && strpos( $exclusion_sql, "ex.post_type = 'product_variation'" ) !== false && strpos( $exclusion_sql, 'ex.post_parent = p.ID' ) !== false );
t( 'SQL استثنای دسته، دسته و زیردسته‌های snapshot را حذف می‌کند',
	strpos( $exclusion_sql, 'xtt.term_id IN (55,56,57)' ) !== false && strpos( $exclusion_sql, 'NOT EXISTS' ) !== false );

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
t( 'کاهش ۱۵٪ با رند: → ۵۱۸٬۰۰۰ (نزدیک‌ترین …۸٬۰۰۰)', $r['ok'] && (float) $r['new'] === 518000.0, var_export( $r['new'], true ) );
TCP_Ops::set_round_mode( 'round' );
$r = call_private( 'TCP_Ops', 'calc_regular', array( 'regular_increase_percent', '557364', 10 ) );
t( 'افزایش ۱۰٪ با رند: ۶۱۳٬۱۰۰٫۴ ← ۶۱۸٬۰۰۰ نه ۶۰۸٬۰۰۰', $r['ok'] && (float) $r['new'] === 618000.0, var_export( $r['new'], true ) );
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
t( 'nearest: ۶۱۳٬۱۰۰ ← ۶۱۸٬۰۰۰ (به بالا نزدیک‌تر است)', TCP_Round::nearest( 613100 ) === 618000.0 );
$GLOBALS['tcp_options']['tcp_settings'] = array( 'round_step' => 10, 'round_digit' => 8 );
t( 'nearest گام ۱۰: ۳۷۷ ← ۳۷۸ نه ۳۶۸', TCP_Round::nearest( 377 ) === 378.0 && TCP_Round::down( 377 ) === 368.0, var_export( TCP_Round::nearest( 377 ), true ) );
$GLOBALS['tcp_options']['tcp_settings'] = array();
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
t( 'discount(round) = نزدیک‌ترین …۸٬۰۰۰', $d['price'] === 518000.0, var_export( $d['price'], true ) );
$d = TCP_Round::discount( 620000, 15, 'round', 1 );
t( 'discount(round) اگر به ۸ بالاتر نزدیک‌تر باشد بالا می‌رود', $d['price'] === 528000.0, var_export( $d['price'], true ) );
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
t( 'time_budget هر درخواست را به ۵ تا ۸ ثانیه محدود می‌کند', is_int( $budget ) && $budget >= 5 && $budget <= 8, 'budget=' . var_export( $budget, true ) );
$boost_ok = true;
try {
	TCP_Ops::runtime_boost();
} catch ( Throwable $e ) {
	$boost_ok = false;
}
t( 'runtime_boost بدون استثنا اجرا می‌شود', $boost_ok );

echo "--- 13) همهٔ محصولات: کلیدست، نشان ضدِ اعمال دوباره، ادامه از متغیر ---\n";
t( 'is_catalog_run: هدف همه', TCP_Ops::is_catalog_run( array( 'target_type' => 'all' ) ) === true );
t( 'is_catalog_run: دستهٔ بزرگ با scan.keyset', TCP_Ops::is_catalog_run( array( 'target_type' => 'category', 'scan' => array( 'mode' => 'keyset' ) ) ) === true );
t( 'is_catalog_run: انتخاب مستقیم نیست', TCP_Ops::is_catalog_run( array( 'target_type' => 'products' ) ) === false );
t( 'pending_object_ids شناسهٔ تمام‌شده را دوباره برنمی‌گرداند', TCP_Ops::pending_object_ids( array( 5, 3, 0, 5, 9 ), 3 ) === array( 5, 9 ) );
t( 'pending_object_ids بدون cursor همه را مرتب برمی‌گرداند', TCP_Ops::pending_object_ids( array( 8, 2 ), 0 ) === array( 2, 8 ) );
$guard = TCP_Ops::guard_encode( 4, '100000', '110000' );
t( 'guard_encode/decode', TCP_Ops::guard_decode( $guard ) === array( 'run' => 4, 'before' => '100000', 'after' => '110000' ) );
t( 'نشانِ همان اجرا و قیمتِ بعد → ردِ اعمال دوباره', TCP_Ops::guard_should_skip( $guard, '110000', 4 ) === true );
t( 'نشان هست ولی قیمت هنوز «قبل» است → save کامل نشده و باید اعمال شود', TCP_Ops::guard_should_skip( $guard, '100000', 4 ) === false );
t( 'قیمت سوم با قبل و بعد هم‌خوان نیست و به‌عنوان تعارض شناخته می‌شود', TCP_Ops::guard_state( $guard, '105000', 4 ) === 'conflict' && TCP_Ops::guard_should_skip( $guard, '105000', 4 ) === false );
t( 'نشانِ اجرای دیگر نادیده گرفته می‌شود', TCP_Ops::guard_should_skip( $guard, '110000', 9 ) === false );

$simple = new WC_Product( 21, 'simple' );
$simple->regular = '100000';
$GLOBALS['tcp_products'][21] = $simple;
TCP_Ops::set_round_mode( 'none' );
$first = TCP_Ops::process_parent( 21, 'regular_increase_percent', 10, array( 'run_id' => 4 ) );
t( 'اعمال ۱۰٪ روی محصول ساده یک‌بار ذخیره می‌شود', 1 === $simple->saved && 1 === $first['updated'] && $first['complete'] === true, 'saved=' . $simple->saved . ' price=' . $simple->regular );
$second = TCP_Ops::process_parent( 21, 'regular_increase_percent', 10, array( 'run_id' => 4 ) );
t( 'قطع بعد از save: درصد دوباره اعمال نمی‌شود', 1 === $simple->saved && (float) $simple->regular === 110000.0, 'saved=' . $simple->saved . ' price=' . var_export( $simple->regular, true ) . ' second=' . var_export( $second['updated'], true ) );

$parent = new WC_Product( 30, 'variable' );
$parent->children = array( 31, 32, 33 );
$GLOBALS['tcp_products'][30] = $parent;
foreach ( array( 31, 32, 33 ) as $vid ) {
	$v = new WC_Product( $vid, 'variation', 30 );
	$v->regular = '200000';
	$GLOBALS['tcp_products'][ $vid ] = $v;
}
$partial = TCP_Ops::process_parent( 30, 'regular_increase_percent', 10, array( 'run_id' => 8, 'deadline' => microtime( true ) - 1 ) );
t( 'بودجهٔ تمام‌شده وسط مادر متغیر: فقط اولین متغیر و والد ناتمام',
	$partial['complete'] === false && 1 === $GLOBALS['tcp_products'][31]->saved && 0 === $GLOBALS['tcp_products'][32]->saved,
	var_export( array( $partial['complete'], $GLOBALS['tcp_products'][31]->saved, $GLOBALS['tcp_products'][32]->saved ), true ) );
$rest = TCP_Ops::process_parent( 30, 'regular_increase_percent', 10, array( 'run_id' => 8, 'after_object' => 31 ) );
t( 'ادامه از متغیر بعدی، متغیر انجام‌شده را دوباره ذخیره نمی‌کند',
	$rest['complete'] === true && 1 === $GLOBALS['tcp_products'][31]->saved && 1 === $GLOBALS['tcp_products'][32]->saved && 1 === $GLOBALS['tcp_products'][33]->saved && (float) $GLOBALS['tcp_products'][33]->regular === 220000.0,
	var_export( array( $GLOBALS['tcp_products'][31]->saved, $GLOBALS['tcp_products'][32]->saved, $GLOBALS['tcp_products'][33]->saved, $GLOBALS['tcp_products'][33]->regular ), true ) );
$grouped = new WC_Product( 40, 'grouped' );
$GLOBALS['tcp_products'][40] = $grouped;
$g = TCP_Ops::process_parent( 40, 'regular_increase_percent', 10, array( 'run_id' => 8 ) );
t( 'محصول گروهی رد می‌شود و ذخیره نمی‌شود', 1 === $g['skipped'] && 0 === $grouped->saved && true === $g['complete'] );

echo "--- 14) جستجوی محصول در کل کاتالوگ (TCP_Ops) ---\n";
$GLOBALS['wpdb'] = new TCP_Test_WPDB();
t( 'search_words: عبارت به کلمه‌های یکتا شکسته می‌شود', TCP_Ops::search_words( '  پک   محافظ شارژر ' ) === array( 'پک', 'محافظ', 'شارژر' ) );
t( 'search_words: کلمهٔ تکراری حذف می‌شود', TCP_Ops::search_words( 'قاب قاب گوشی' ) === array( 'قاب', 'گوشی' ) );
t( 'search_words: عبارت خالی → آرایهٔ خالی', TCP_Ops::search_words( '   ' ) === array() );
t( 'search_words: سقف SEARCH_MAX_WORDS رعایت می‌شود',
	count( TCP_Ops::search_words( 'a b c d e f g h i j' ) ) === TCP_Ops::SEARCH_MAX_WORDS );
$p = TCP_Ops::search_paging( 1, 250, 100 );
t( 'search_paging: ۲۵۰ نتیجه → ۳ صفحه، offset صفر', 3 === $p['pages'] && 0 === $p['offset'] && 1 === $p['page'] );
$p = TCP_Ops::search_paging( 2, 250, 100 );
t( 'search_paging: صفحهٔ دوم → offset ۱۰۰', 2 === $p['page'] && 100 === $p['offset'] );
$p = TCP_Ops::search_paging( 99, 250, 100 );
t( 'search_paging: صفحهٔ بیرون از محدوده به آخرین صفحه برمی‌گردد', 3 === $p['page'] && 200 === $p['offset'] );
$p = TCP_Ops::search_paging( 0, 0, 100 );
t( 'search_paging: بدون نتیجه → صفر صفحه', 0 === $p['pages'] && 1 === $p['page'] );

list( $where_sql, $where_params ) = TCP_Ops::product_search_where_sql( 'پک محافظ شارژر' );
t( 'WHERE جستجو فقط روی محصول مادر است', 0 === strpos( $where_sql, 'p.post_type = %s' ) && 'product' === $where_params[0] );
t( 'WHERE جستجو هر ۵ وضعیت قابل جستجو را می‌گیرد',
	strpos( $where_sql, 'p.post_status IN (%s, %s, %s, %s, %s)' ) !== false, $where_sql );
t( 'هر کلمه در نام، توضیح کوتاه و توضیح بلند جستجو می‌شود',
	3 === substr_count( $where_sql, 'p.post_title LIKE %s' ) && 3 === substr_count( $where_sql, 'p.post_excerpt LIKE %s' ) && 3 === substr_count( $where_sql, 'p.post_content LIKE %s' ) );
t( 'SKU خودِ محصول و SKU واریشن‌ها هم جستجو می‌شود (→ والد)',
	3 === substr_count( $where_sql, "psku.meta_key = '_sku'" ) && 3 === substr_count( $where_sql, "vsku_p.post_type = 'product_variation'" ) && 3 === substr_count( $where_sql, 'vsku_p.post_parent = p.ID' ) );
t( 'کلمه‌ها با AND ترکیب می‌شوند؛ پس ترتیب کلمه‌ها مهم نیست', 2 === substr_count( $where_sql, ') AND ( ' ) );
t( 'عبارت غیرعددی شاخهٔ شناسهٔ مستقیم ندارد', false === strpos( $where_sql, 'OR p.ID = %d' ) );
t( 'تعداد پارامترها با placeholderها می‌خواند (۱ نوع + ۵ وضعیت + ۳ کلمه × ۵ ستون)',
	21 === count( $where_params ), count( $where_params ) . ' پارامتر' );

list( $id_where, $id_params ) = TCP_Ops::product_search_where_sql( '1234' );
t( 'عبارت عددی، شناسهٔ مستقیم محصول را هم پیدا می‌کند',
	false !== strpos( $id_where, 'OR p.ID = %d' ) && 1234 === end( $id_params ) );
t( 'شناسهٔ واریشن هم پذیرفته می‌شود و والدش برمی‌گردد',
	false !== strpos( $id_where, "vid.post_type = 'product_variation'" ) && false !== strpos( $id_where, 'vid.post_parent = p.ID' )
	&& array( 1234, 1234 ) === array_slice( $id_params, -2 ), implode( ',', $id_params ) );
list( $bad_where, $bad_params ) = TCP_Ops::product_search_where_sql( 'قاب', array( 'trash' ) );
t( 'وضعیت نامعتبر → بدون نتیجه (1=0)', '1=0' === $bad_where && array() === $bad_params );
t( 'عبارت خالی → بدون نتیجه (1=0)', '1=0' === TCP_Ops::product_search_where_sql( '  ' )[0] );

list( $order_sql, $order_params ) = TCP_Ops::product_search_order_sql( 'پک محافظ شارژر' );
t( 'ترتیب: اول عنوان منطبق با کل عبارت، بعد بقیه به‌ترتیب نام (پایدار برای صفحه‌بندی)',
	0 === strpos( $order_sql, 'CASE WHEN p.post_title LIKE %s THEN 0 ELSE 1 END ASC' )
	&& false !== strpos( $order_sql, 'p.post_title ASC, p.ID ASC' )
	&& array( '%پک محافظ شارژر%' ) === $order_params );

echo "--- 13) استثناها: تکی > شناسه (پیشوند SKU) > دسته‌بندی > سراسری ---\n";
// محصولات: 201 و 2031 (واریشن والد 203) → پیشوند CH؛ 202 → LP؛ 204 قانون تکی + CH؛ 205 دستهٔ استثنا (۷۷)؛ 206 در دستهٔ ۵۵ بدون استثنا.
$GLOBALS['tcp_sku_rows'] = array(
	array( 'product', 201, 0, 'CH-001' ),
	array( 'product', 202, 0, 'LP-001' ),
	array( 'product', 203, 0, '' ),
	array( 'product_variation', 2031, 203, 'CH-RED' ),
	array( 'product', 204, 0, 'CH-HASRULE' ),
	array( 'product', 205, 0, 'LP-EXC' ),
	array( 'product', 206, 0, 'chx-lower' ),
);
$GLOBALS['tcp_post_terms'] = array( 201 => array( 55 ), 202 => array( 55 ), 203 => array( 55 ), 2031 => array( 55 ), 204 => array( 55 ), 205 => array( 77 ), 206 => array( 55 ) );
$GLOBALS['tcp_term_children'] = array( 55 => array( 56 ) );

$t_norm = TCP_Rules::normalize_prefixes( ' ch, lp ;CH  xyz! ab-1' );
t( 'normalize_prefixes: بزرگ‌حرف، یکتا، نامعتبر (xyz!) حذف', array( 'CH', 'LP', 'AB-1' ) === array_values( $t_norm ), var_export( $t_norm, true ) );
t( 'normalize_prefixes: ورودی آرایه با حروف کوچک', array( 'CH' ) === TCP_Rules::normalize_prefixes( array( 'ch', 'CH', '  ' ) ) );

$ex_rules = array(
	'global'     => array( 'enabled' => 1, 'increase' => 30 ),
	'products'   => array(
		204 => array( 'enabled' => 1, 'increase' => 10 ),
		205 => array( 'enabled' => 0, 'exclude' => 1 ),
	),
	'categories' => array(
		55 => array( 'enabled' => 1, 'increase' => 20 ),
		77 => array( 'enabled' => 1, 'exclude' => 1 ),
	),
	'prefixes'   => array( 'CH' ),
);
set_rules( $ex_rules ); // set_rules کش قوانین و کش شناسه‌ها را پاک می‌کند.

t( 'شناسه: محصول با SKU شروع‌شده با CH → استثنا (سراسری هم اعمال نمی‌شود)',
	null === call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 201 ) ) ) );
t( 'شناسه: واریشنِ SKU شروع‌شده با CH → والد استثنا می‌شود',
	null === call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 2031, 'variation', 203 ) ) ) );
t( 'شناسه: SKU بدون پیشوند → قانون سراسری/دسته',
	( $r202 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 202 ) ) ) ) && 20.0 === (float) $r202['increase'], var_export( $r202 ?? null, true ) );
t( 'شناسه: بی‌حساسیت به حروف کوچک/بزرگ (chx-lower ← CHX)',
	null === call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 206 ) ) ) );
t( 'اولویت: قانون تکی محصول بر استثنای شناسه برنده است (۲۰۴ با CH)',
	( $r204 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 204 ) ) ) ) && 10.0 === (float) $r204['increase'], var_export( $r204 ?? null, true ) );
t( 'اولویت: استثنای شناسه بر قانون دسته برنده است (۲۰۱ در دستهٔ ۵۵ با قانون ۲۰٪)',
	null === call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 201 ) ) ) );
t( 'دسته: استثنای دسته، محصول بدون شناسه را خارج می‌کند (۲۰۵ در دستهٔ استثناشدهٔ ۷۷)',
	null === call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 205 ) ) ) );

// استثنای تکی که خاموش ذخیره شده، باید همچنان استثنا باشد (استثنا همیشه فعال است).
$nr_exc = TCP_Rules::normalize_rule( array( 'exclude' => 1, 'enabled' => 0, 'from' => '2030-01-01' ) );
t( 'normalize_rule: استثنا همیشه فعال و بدون بازهٔ زمانی', 1 === $nr_exc['enabled'] && '' === $nr_exc['from'] && 1 === $nr_exc['exclude'] );

// تست بدون شناسه: همان رفتار قبلی (دسته و سراسری) دست‌نخورده.
set_rules( array( 'global' => array( 'enabled' => 1, 'increase' => 30 ), 'categories' => array( 55 => array( 'enabled' => 1, 'increase' => 20 ) ) ) );
t( 'بدون شناسه: دسته همچنان بر سراسری مقدم است', ( $r = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 202 ) ) ) ) && 20.0 === (float) $r['increase'] );

// نمونهٔ SQL: پیشوندهای چندتایی با OR و esc_like.
$sql_ids = TCP_Rules::prefix_product_ids( array( 'CH', 'LP' ) );
t( 'prefix_product_ids: CH و LP → همهٔ محصولات منطبق (۲۰۱…۲۰۶، واریشن ۲۰۳۱ → والد ۲۰۳)', array( 201, 202, 203, 204, 205, 206 ) === array_values( $sql_ids ), var_export( $sql_ids, true ) );
t( 'prefix_product_count: CH → ۴ محصول (201، 203 از واریشن، 204 و CHX-… در 206)', 4 === TCP_Rules::prefix_product_count( 'CH' ), (string) TCP_Rules::prefix_product_count( 'CH' ) );
t( 'prefix_product_count: LP → ۲ محصول (202، 205)', 2 === TCP_Rules::prefix_product_count( 'LP' ), (string) TCP_Rules::prefix_product_count( 'LP' ) );

echo "--- 14) شناسه: هر پیشوند استثنا یا قانون اختصاصی؛ بلندترین پیشوند برنده ---\n";
$t_map = TCP_Rules::normalize_prefix_map( array( 'ch' => array( 'enabled' => 1, 'increase' => 40, 'exclude' => 0 ), 'LP' => array( 'exclude' => 1 ), 'xyz!' => array( 'exclude' => 1 ) ) );
t( 'normalize_prefix_map: نقشهٔ جدید، کلید بزرگ‌حرف و کلید نامعتبر حذف', array( 'CH', 'LP' ) === array_keys( $t_map ) && 40.0 === (float) $t_map['CH']['increase'] && 1 === $t_map['LP']['exclude'], var_export( array_keys( $t_map ), true ) );
$t_legacy = TCP_Rules::normalize_prefix_map( array( 'CH', 'lp' ) );
t( 'normalize_prefix_map: شکل قدیمی (فهرست ساده) = استثنا', array( 'CH', 'LP' ) === array_keys( $t_legacy ) && 1 === $t_legacy['CH']['exclude'] && 1 === $t_legacy['LP']['exclude'] );

set_rules( array(
	'global'   => array( 'enabled' => 1, 'increase' => 30 ),
	'products' => array( 204 => array( 'enabled' => 1, 'increase' => 10 ) ),
	'prefixes' => array(
		'CH'   => array( 'enabled' => 1, 'increase' => 40, 'mode' => 'round' ),
		'CH-0' => array( 'enabled' => 1, 'exclude' => 1 ),
		'LP'   => array( 'enabled' => 0, 'increase' => 5 ),
	),
) );
$r201 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 201 ) ) );
t( 'شناسه با قانون: CH-001 زیر CH-0 (استثنای بلندتر) → استثنا', null === $r201, var_export( $r201, true ) );
$r2031 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 2031, 'variation', 203 ) ) );
t( 'شناسه با قانون: واریشن CH-RED → قانون CH (۴۰٪)', $r2031 && 40.0 === (float) $r2031['increase'], var_export( $r2031, true ) );
$r202 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 202 ) ) );
t( 'شناسه غیرفعال (LP) قانون ندارد؛ به سراسری (۳۰٪) می‌افتد', $r202 && 30.0 === (float) $r202['increase'], var_export( $r202, true ) );
$r204 = call_private( 'TCP_Rules', 'resolve_rule', array( new WC_Product( 204 ) ) );
t( 'اولویت: قانون تکی محصول (۱۰٪) بر قانون شناسه CH (۴۰٪) برنده است', $r204 && 10.0 === (float) $r204['increase'] );

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit( $fail === 0 ? 0 : 1 );
