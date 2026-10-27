<?php
/**
 * تست‌های تبدیل و قالب‌بندی تاریخ شمسی (TCP_Jalali).
 * از داخل run-tests.php فراخوانی می‌شود و از هارنس t() همان فایل استفاده می‌کند.
 *
 * @package TisaCase_Pricing
 */

echo "--- ۱۶) تاریخ شمسی: تبدیل دوطرفه، اعتبارسنجی و قالب‌بندی ---\n";

// نقاط لنگر با مرجع مستقل (پکیج jdatetime).
$tcp_anchors = array(
	array( array( 2026, 10, 30 ), array( 1405, 8, 8 ) ),
	array( array( 2024, 3, 20 ), array( 1403, 1, 1 ) ),
	array( array( 2025, 3, 21 ), array( 1404, 1, 1 ) ),
	array( array( 2026, 3, 21 ), array( 1405, 1, 1 ) ),
	array( array( 2027, 3, 20 ), array( 1405, 12, 29 ) ),
	array( array( 2026, 3, 20 ), array( 1404, 12, 29 ) ),
	array( array( 2024, 3, 19 ), array( 1402, 12, 29 ) ),
	array( array( 2025, 3, 20 ), array( 1403, 12, 30 ) ), // ۱۴۰۳ کبیسه.
	array( array( 2021, 3, 21 ), array( 1400, 1, 1 ) ),
);
foreach ( $tcp_anchors as $tcp_a ) {
	list( $g, $j ) = $tcp_a;
	t(
		"میلادی {$g[0]}/{$g[1]}/{$g[2]} → شمسی {$j[0]}/{$j[1]}/{$j[2]}",
		TCP_Jalali::to_jalali( $g[0], $g[1], $g[2] ) === $j,
		var_export( TCP_Jalali::to_jalali( $g[0], $g[1], $g[2] ), true )
	);
	t(
		"برگشت شمسی {$j[0]}/{$j[1]}/{$j[2]} → میلادی {$g[0]}/{$g[1]}/{$g[2]}",
		TCP_Jalali::to_gregorian( $j[0], $j[1], $j[2] ) === $g,
		var_export( TCP_Jalali::to_gregorian( $j[0], $j[1], $j[2] ), true )
	);
}

// رفت‌وبرگشت کاملِ روزبه‌روز در بازهٔ ۲۰۱۸ تا ابتدای ۲۰۳۱.
$tcp_rt_fails = 0;
$tcp_rt_count = 0;
$tcp_begin    = new DateTime( '2018-01-01', new DateTimeZone( 'UTC' ) );
$tcp_end      = new DateTime( '2031-01-01', new DateTimeZone( 'UTC' ) );
while ( $tcp_begin < $tcp_end ) {
	$gy = (int) $tcp_begin->format( 'Y' );
	$gm = (int) $tcp_begin->format( 'n' );
	$gd = (int) $tcp_begin->format( 'j' );
	$jj = TCP_Jalali::to_jalali( $gy, $gm, $gd );
	if ( $jj[1] < 1 || $jj[1] > 12 || $jj[2] < 1 || $jj[2] > TCP_Jalali::month_length( $jj[0], $jj[1] ) ) {
		$tcp_rt_fails++;
		break;
	}
	if ( TCP_Jalali::to_gregorian( $jj[0], $jj[1], $jj[2] ) !== array( $gy, $gm, $gd ) ) {
		$tcp_rt_fails++;
		break;
	}
	$tcp_rt_count++;
	$tcp_begin->modify( '+1 day' );
}
t( "رفت‌وبرگشت کامل $tcp_rt_count روز بدون خطا", 0 === $tcp_rt_fails && $tcp_rt_count > 4700, "fails=$tcp_rt_fails count=$tcp_rt_count" );

// سال‌های کبیسه و طول اسفند.
t( 'کبیسه: ۱۴۰۳ بله، ۱۴۰۴ نه، ۱۳۹۹ بله', TCP_Jalali::is_leap( 1403 ) && ! TCP_Jalali::is_leap( 1404 ) && TCP_Jalali::is_leap( 1399 ) );
t( 'طول ماه‌ها: ۳۱ روزه تا شهریور، ۳۰ روزه مهر تا بهمن', 31 === TCP_Jalali::month_length( 1405, 6 ) && 30 === TCP_Jalali::month_length( 1405, 11 ) );
t( 'اسفند ۱۴۰۳ سی روزه و اسفند ۱۴۰۴ بیست‌ونه روزه', 30 === TCP_Jalali::month_length( 1403, 12 ) && 29 === TCP_Jalali::month_length( 1404, 12 ) );

// تجزیهٔ ورودی شمسی کاربر.
t( 'parse_jalali: 1405/08/08 → 2026-10-30', '2026-10-30' === TCP_Jalali::parse_jalali( '1405/08/08' ) );
t( 'parse_jalali: ارقام فارسی و جداکنندهٔ خط تیره', '2026-10-30' === TCP_Jalali::parse_jalali( '۱۴۰۵-۸-۸' ) );
t( 'parse_jalali: اسفند سی‌اُم سال کبیسه مجاز است', '2025-03-20' === TCP_Jalali::parse_jalali( '1403/12/30' ) );
t( 'parse_jalali: اسفند سی‌اُم سال عادی رد می‌شود', '' === TCP_Jalali::parse_jalali( '1404/12/30' ) );
t( 'parse_jalali: ماه نامعتبر رد می‌شود', '' === TCP_Jalali::parse_jalali( '1405/13/01' ) );
t( 'parse_jalali: ورودی نامرتبط رد می‌شود', '' === TCP_Jalali::parse_jalali('garbage') );
t( 'parse_jalali: ورودی خالی = بدون تاریخ', '' === TCP_Jalali::parse_jalali( '   ' ) );

// نرمال‌سازی ورودی فرم (میلادی معتبر دست‌نخورده رد می‌شود).
t( 'normalize: میلادی Y-m-d عبور می‌کند', '2026-10-30' === TCP_Jalali::normalize_date_input( '2026-10-30' ) );
t( 'normalize: شمسی کاربر به میلادی تبدیل می‌شود', '2026-10-30' === TCP_Jalali::normalize_date_input( '1405/08/08' ) );
t( 'normalize: میلادی نامعتبر رد می‌شود', '' === TCP_Jalali::normalize_date_input( '2026-13-40' ) );
t( 'normalize: خالی = بدون تاریخ', '' === TCP_Jalali::normalize_date_input( '' ) );

// تبدیل نمایشی و قالب‌بندی (در محیط بدون وردپرس، لایهٔ زمان UTC است).
t( 'gregorian_to_text: 2026-10-30 → 1405/08/08', '1405/08/08' === TCP_Jalali::gregorian_to_text( '2026-10-30' ) );
t( 'gregorian_to_text: ورودی نامعتبر → رشتهٔ خالی', '' === TCP_Jalali::gregorian_to_text( 'not-a-date' ) );
$tcp_ts = gmmktime( 10, 11, 12, 10, 30, 2026 );
t( 'format: تاریخ و زمان شمسی', '1405/08/08 10:11:12' === TCP_Jalali::format( 'Y/m/d H:i:s', $tcp_ts ) );
t( 'format: توکن‌های بدون صفر (n/j/G)', '1405/8/8 10:11' === TCP_Jalali::format( 'Y/n/j G:i', $tcp_ts ) );
t( 'from_mysql: رشتهٔ DATETIME → شمسی', '1405/08/08 10:11' === TCP_Jalali::from_mysql( '2026-10-30 10:11:00', 'Y/m/d H:i' ) );
t( 'from_mysql: تهی و صفرِ MySQL → خالی', '' === TCP_Jalali::from_mysql( '' ) && '' === TCP_Jalali::from_mysql( '0000-00-00 00:00:00' ) );
t( 'normalize_digits: ارقام فارسی و عربی لاتین می‌شوند', '14050808' === TCP_Jalali::normalize_digits( '۱۴۰۵٠٨٠٨' ) );
