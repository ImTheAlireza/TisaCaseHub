<?php
/**
 * تبدیل تاریخ میلادی ↔ شمسی (جلالی) و قالب‌بندی یک‌دست تاریخ‌ها در افزونه.
 *
 * همهٔ ذخیره‌سازی‌ها همچنان میلادی (Y-m-d / DATETIME) می‌مانند تا با ووکامرس و
 * منطق مقایسهٔ تاریخ‌ها سازگار بمانند؛ این کلاس فقط لایهٔ نمایش و ورودی کاربر است.
 * الگوریتم، معادل دقیق پیاده‌سازی مرجع jdatetime (چرخهٔ ۳۳ ساله) است و همان الگو
 * در assets/jalali.js هم تکرار شده تا مرورگر و سرور همیشه یک نتیجه بدهند.
 *
 * @package TisaCase_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TCP_Jalali
 */
class TCP_Jalali {

	/**
	 * نام ماه‌های شمسی.
	 *
	 * @var string[]
	 */
	public static $months = array(
		1  => 'فروردین',
		2  => 'اردیبهشت',
		3  => 'خرداد',
		4  => 'تیر',
		5  => 'مرداد',
		6  => 'شهریور',
		7  => 'مهر',
		8  => 'آبان',
		9  => 'آذر',
		10 => 'دی',
		11 => 'بهمن',
		12 => 'اسفند',
	);

	/**
	 * باقی‌مانده‌های سال‌های کبیسه در چرخهٔ ۳۳ سالهٔ جلالی.
	 *
	 * @var int[]
	 */
	private static $leap_remainders = array( 1, 5, 9, 13, 17, 22, 26, 30 );

	/**
	 * بازهٔ سال شمسی معتبر برای ورودی/نمایش.
	 *
	 * الگوریتم تبدیل برای بازه‌های متعارف طراحی شده و تاریخ‌های بسیار دور
	 * (مثل باگ قدیمی ذخیرهٔ سال ۱۴۰۵ میلادی) خروجی بی‌معنا می‌دهند؛
	 * چنین مقادیری نامعتبر شناخته می‌شوند تا قابل اصلاح باشند.
	 */
	const MIN_YEAR = 1100;
	const MAX_YEAR = 1500;

	/**
	 * آیا سال/ماه/روز شمسی در بازهٔ پشتیبانی‌شده است؟
	 *
	 * @param int $jy سال شمسی.
	 * @param int $jm ماه شمسی.
	 * @param int $jd روز شمسی.
	 * @return bool
	 */
	public static function is_sane_jalali( $jy, $jm = 1, $jd = 1 ) {
		return $jy >= self::MIN_YEAR && $jy <= self::MAX_YEAR
			&& $jm >= 1 && $jm <= 12
			&& $jd >= 1 && $jd <= self::month_length( $jy, $jm );
	}

	/**
	 * میلادی → شمسی.
	 *
	 * پورت دقیق الگوریتم مرجع پکیج jdatetime (کلاس GregorianToJalali)؛ همان الگو
	 * در assets/jalali.js هم پیاده شده و خروجی هر دو لایه یکسان است.
	 *
	 * @param int $gy سال میلادی.
	 * @param int $gm ماه میلادی (۱ تا ۱۲).
	 * @param int $gd روز میلادی.
	 * @return int[] [سال، ماه، روز] شمسی.
	 */
	public static function to_jalali( $gy, $gm, $gd ) {
		static $g_days = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );
		static $j_days = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );

		$gy = (int) $gy - 1600;
		$gm = (int) $gm - 1;
		$gd = (int) $gd;

		$j_day_no = 365 * $gy
			+ intdiv( $gy + 3, 4 )
			- intdiv( $gy + 99, 100 )
			+ intdiv( $gy + 399, 400 )
			+ $gd - 1 - 79;

		for ( $i = 0; $i < $gm; $i++ ) {
			$j_day_no += $g_days[ $i ];
		}
		if ( $gm > 1 && ( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400 ) ) {
			$j_day_no++; // سال کبیسهٔ میلادی و بعد از فوریه.
		}

		$j_np      = intdiv( $j_day_no, 12053 );
		$j_day_no %= 12053;
		$jy        = 979 + 33 * $j_np + 4 * intdiv( $j_day_no, 1461 );
		$j_day_no %= 1461;

		if ( $j_day_no >= 366 ) {
			$j_day_no--;
			$jy       += intdiv( $j_day_no, 365 );
			$j_day_no %= 365;
		}

		$i = 0;
		while ( $i < 11 && $j_day_no >= $j_days[ $i ] ) {
			$j_day_no -= $j_days[ $i ];
			$i++;
		}
		return array( $jy, $i + 1, $j_day_no + 1 );
	}

	/**
	 * شمسی → میلادی.
	 *
	 * پورت دقیق الگوریتم مرجع پکیج jdatetime (کلاس JalaliToGregorian).
	 *
	 * @param int $jy سال شمسی.
	 * @param int $jm ماه شمسی (۱ تا ۱۲).
	 * @param int $jd روز شمسی.
	 * @return int[] [سال، ماه، روز] میلادی.
	 */
	public static function to_gregorian( $jy, $jm, $jd ) {
		static $j_days = array( 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 );
		static $g_days = array( 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 );

		$jy = (int) $jy - 979;
		$jm = (int) $jm;
		$jd = (int) $jd;

		$g_day_no = 365 * $jy
			+ intdiv( $jy, 33 ) * 8
			+ intdiv( ( $jy % 33 ) + 3, 4 )
			+ $jd - 1 + 79;

		for ( $i = 0; $i < $jm - 1; $i++ ) {
			$g_day_no += $j_days[ $i ];
		}

		$gy        = 1600 + 400 * intdiv( $g_day_no, 146097 );
		$g_day_no %= 146097;
		$leap      = 1;

		if ( $g_day_no >= 36525 ) {
			$g_day_no--;
			$gy       += 100 * intdiv( $g_day_no, 36524 );
			$g_day_no %= 36524;
			if ( $g_day_no >= 365 ) {
				$g_day_no++;
			} else {
				$leap = 0;
			}
		}

		$gy       += 4 * intdiv( $g_day_no, 1461 );
		$g_day_no %= 1461;

		if ( $g_day_no >= 366 ) {
			$leap      = 0;
			$g_day_no--;
			$gy       += intdiv( $g_day_no, 365 );
			$g_day_no %= 365;
		}

		$i = 0;
		while ( $g_day_no >= $g_days[ $i ] + ( 1 === $i ? $leap : 0 ) ) {
			$g_day_no -= $g_days[ $i ] + ( 1 === $i ? $leap : 0 );
			$i++;
		}
		return array( $gy, $i + 1, $g_day_no + 1 );
	}

	/**
	 * آیا سال شمسی کبیسه است؟
	 *
	 * @param int $jy سال شمسی.
	 * @return bool
	 */
	public static function is_leap( $jy ) {
		return in_array( ( (int) $jy % 33 ), self::$leap_remainders, true );
	}

	/**
	 * تعداد روزهای یک ماه شمسی.
	 *
	 * @param int $jy سال شمسی.
	 * @param int $jm ماه شمسی.
	 * @return int
	 */
	public static function month_length( $jy, $jm ) {
		$jm = (int) $jm;
		if ( $jm <= 6 ) {
			return 31;
		}
		if ( $jm <= 11 ) {
			return 30;
		}
		return self::is_leap( $jy ) ? 30 : 29;
	}

	/**
	 * قالب‌بندی تایم‌استمپ به تاریخ شمسی (زمان بدون تغییر می‌ماند).
	 *
	 * توکن‌های پشتیبانی‌شده: Y (سال چهار رقمی)، m/n ماه، d/j روز،
	 * H/G ساعت، i دقیقه، s ثانیه — همان قرارداد date() خود PHP.
	 *
	 * @param string   $format قالب با توکن‌های بالا.
	 * @param int|null $timestamp تایم‌استمپ یونیکس؛ پیش‌فرض اکنون.
	 * @return string
	 */
	public static function format( $format, $timestamp = null ) {
		$timestamp = null === $timestamp ? time() : (int) $timestamp;

		// اجزای میلادی در منطقهٔ زمانی سایت (در تست‌های بدون وردپرس: UTC).
		if ( function_exists( 'wp_date' ) ) {
			$parts = wp_date( 'Y|n|j|G|i|s', $timestamp );
		} else {
			$parts = gmdate( 'Y|n|j|G|i|s', $timestamp );
		}
		$bits = array_map( 'intval', explode( '|', (string) $parts ) );
		list( $gy, $gm, $gd, $hour, $minute, $second ) = $bits;
		list( $jy, $jm, $jd ) = self::to_jalali( $gy, $gm, $gd );
		if ( $jy < self::MIN_YEAR || $jy > self::MAX_YEAR ) {
			return ''; // تاریخ خارج بازهٔ معتبر (مثل رکورد خراب قدیمی).
		}

		$map = array(
			'Y' => sprintf( '%04d', $jy ),
			'm' => sprintf( '%02d', $jm ),
			'n' => (string) $jm,
			'd' => sprintf( '%02d', $jd ),
			'j' => (string) $jd,
			'H' => sprintf( '%02d', $hour ),
			'G' => (string) $hour,
			'i' => sprintf( '%02d', $minute ),
			's' => sprintf( '%02d', $second ),
		);
		return strtr( (string) $format, $map );
	}

	/**
	 * قالب‌بندی رشتهٔ تاریخ MySQL (زمان محلی سایت) به شمسی.
	 *
	 * @param string $mysql_date مثل «2026-10-27 12:00:00».
	 * @param string $format قالب شمسی.
	 * @return string رشتهٔ خروجی یا «» برای ورودی خالی/نامعتبر.
	 */
	public static function from_mysql( $mysql_date, $format = 'Y/m/d H:i' ) {
		$mysql_date = trim( (string) $mysql_date );
		if ( '' === $mysql_date || '0000-00-00 00:00:00' === $mysql_date || '0000-00-00' === $mysql_date ) {
			return '';
		}
		if ( function_exists( 'mysql2date' ) ) {
			$ts = mysql2date( 'U', $mysql_date );
		} else {
			$ts = strtotime( $mysql_date );
		}
		return false === $ts || null === $ts ? '' : self::format( $format, (int) $ts );
	}

	/**
	 * تبدیل رشتهٔ میلادی Y-m-d به رشتهٔ نمایشی شمسی Y/m/d.
	 *
	 * @param string $gregorian تاریخ میلادی معتبر.
	 * @return string شمسی یا «» اگر ورودی خالی/نامعتبر باشد.
	 */
	public static function gregorian_to_text( $gregorian ) {
		$gregorian = trim( (string) $gregorian );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $gregorian ) ) {
			return '';
		}
		list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', $gregorian ) );
		if ( ! checkdate_compat( $gm, $gd, $gy ) ) {
			return '';
		}
		list( $jy, $jm, $jd ) = self::to_jalali( $gy, $gm, $gd );
		if ( $jy < self::MIN_YEAR || $jy > self::MAX_YEAR ) {
			return ''; // مثل رکورد قدیمیِ ذخیره‌شده با سال ۱۴۰۵ میلادی.
		}
		return sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );
	}

	/**
	 * نرمال‌سازی ارقام فارسی/عربی به لاتین.
	 *
	 * @param string $input ورودی خام.
	 * @return string
	 */
	public static function normalize_digits( $input ) {
		$input = (string) $input;
		$fa    = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$ar    = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$en    = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		return str_replace( array_merge( $fa, $ar ), array_merge( $en, $en ), $input );
	}

	/**
	 * تجزیهٔ ورودی شمسی کاربر و تبدیل به میلادی Y-m/d.
	 *
	 * فرمت‌های مجاز: 1405/08/08، 1405-8-8، 1405.08.08 (با ارقام فارسی هم).
	 *
	 * @param string $input ورودی خام.
	 * @return string میلادی Y-m-d یا «» اگر خالی/نامعتبر باشد.
	 */
	public static function parse_jalali( $input ) {
		$input = trim( self::normalize_digits( $input ) );
		if ( '' === $input ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})[\s\/\-.](\d{1,2})[\s\/\-.](\d{1,2})$/', $input, $m ) ) {
			return '';
		}
		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];
		if ( ! self::is_sane_jalali( $jy, $jm, $jd ) ) {
			return '';
		}
		list( $gy, $gm, $gd ) = self::to_gregorian( $jy, $jm, $jd );
		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	/**
	 * یک نقطهٔ ورودی واحد برای هر مقدار تاریخ که قرار است ذخیره شود.
	 *
	 * مقدار میلادی معتبر (خروجی فیلد مخفی) دست‌نخورده رد می‌شود؛ هر چیز دیگری
	 * به‌عنوان ورودی شمسی کاربر تجزیه می‌شود. قرارداد خروجی مثل گذشته:
	 * رشتهٔ میلادی Y-m-d یا «» (بدون تاریخ).
	 *
	 * @param string $input مقدار خام فرم.
	 * @return string
	 */
	public static function normalize_date_input( $input ) {
		$input = trim( self::normalize_digits( $input ) );
		if ( '' === $input ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input ) ) {
			list( $gy, $gm, $gd ) = array_map( 'intval', explode( '-', $input ) );
			return checkdate_compat( $gm, $gd, $gy ) ? $input : '';
		}
		return self::parse_jalali( $input );
	}
}

if ( ! function_exists( 'checkdate_compat' ) ) :
	/**
	 * اعتبارسنجی تاریخ میلادی بدون وابستگی به checkdate (برای تست‌های CLI).
	 *
	 * @param int $month ماه.
	 * @param int $day روز.
	 * @param int $year سال.
	 * @return bool
	 */
	function checkdate_compat( $month, $day, $year ) {
		if ( function_exists( 'checkdate' ) ) {
			return checkdate( (int) $month, (int) $day, (int) $year );
		}
		$lengths = array(
			1  => 31,
			2  => ( ( $year % 4 === 0 && $year % 100 !== 0 ) || $year % 400 === 0 ) ? 29 : 28,
			3  => 31,
			4  => 30,
			5  => 31,
			6  => 30,
			7  => 31,
			8  => 31,
			9  => 30,
			10 => 31,
			11 => 30,
			12 => 31,
		);
		return isset( $lengths[ $month ] ) && $day >= 1 && $day <= $lengths[ $month ];
	}
endif;
