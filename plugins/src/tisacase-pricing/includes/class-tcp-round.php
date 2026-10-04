<?php
/**
 * موتور رندکردن قیمت — منبع واحد برای قوانین داینامیک و عملیات گروهی.
 *
 * دو کار می‌کند:
 *  ۱) «رند به ۸»: قیمت را به نزدیک‌ترین عددی می‌برد که رقمِ انتخاب‌شده (پیش‌فرض ۸) را دارد؛
 *     بالا یا پایین، هر کدام نزدیک‌تر. مثلاً ۳۷۷ ← ۳۷۸ (نه ۳۶۸) و با گام ۱۰٬۰۰۰: ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰، ۶۱۳٬۱۰۰ ← ۶۱۸٬۰۰۰.
 *     فاصلهٔ برابر به پایین می‌رود. گام بر اساس واحد پول (ریال ۱۰۰٬۰۰۰ / تومان ۱۰٬۰۰۰) یا دستی.
 *  ۲) «تخفیف متغیر»: به‌جای این‌که همهٔ محصولات دقیقاً X٪ تخفیف بخورند، برای هر محصول درصدی در بازهٔ
 *     X±J انتخاب می‌شود (J در تنظیمات، پیش‌فرض ۵) طوری که قیمت نهایی دقیقاً روی ۸ بیفتد. انتخاب
 *     بر اساس شناسهٔ محصول قطعی است؛ یعنی هر بار همان عدد تولید می‌شود و چیزی ذخیره نمی‌شود.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Round' ) ) {

	final class TCP_Round {

		/** رقم پایانی (۰ تا ۹). */
		public static function digit() {
			$d = absint( TCP_Settings::setting( 'round_digit' ) );
			return min( 9, $d );
		}

		/** گام رندکردن: دستی از تنظیمات، وگرنه بر اساس واحد پول. */
		public static function step() {
			$manual = absint( TCP_Settings::setting( 'round_step' ) );
			if ( $manual > 0 ) {
				return $manual;
			}
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT';
			return 'IRR' === $currency ? 100000 : 10000;
		}

		/** مقدار پایانی (مثلاً ۸٬۰۰۰ برای گام ۱۰٬۰۰۰ و رقم ۸). */
		public static function ending() {
			return (int) ( self::step() / 10 * self::digit() );
		}

		/** دامنهٔ نوسان تخفیف متغیر (درصد، مثلاً ۵ یعنی ±۵). */
		public static function jitter() {
			$j = (float) TCP_Settings::setting( 'jitter_percent' );
			return max( 0.1, min( 50, $j > 0 ? $j : 5 ) );
		}

		/** توضیح کوتاه برای UI: «…۸٬۰۰۰». */
		public static function describe() {
			return number_format_i18n( self::ending() ) . '…';
		}

		/**
		 * رند به پایین روی رقم انتخاب‌شده (رفتار همیشگی: ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰).
		 */
		public static function down( $price ) {
			$price = (float) $price;
			$step  = self::step();
			$end   = self::ending();
			$v     = ( floor( $price / $step ) * $step ) + $end;
			if ( $v > $price ) {
				$v -= $step; // واقعاً «به پایین»: ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰ نه ۶۱۸٬۰۰۰.
			}
			return max( $end, $v );
		}

		/**
		 * نزدیک‌ترین عدد رند (بالا یا پایین). فاصلهٔ برابر به پایین می‌رود.
		 * مثلاً با گام ۱۰ و رقم ۸: ۳۷۷ ← ۳۷۸، نه ۳۶۸.
		 */
		public static function nearest( $price ) {
			$price = (float) $price;
			$step  = self::step();
			$lo    = self::down( $price );
			$hi    = $lo + $step;
			return ( $price - $lo ) <= ( $hi - $price ) ? $lo : $hi;
		}

		/**
		 * عدد قطعی در بازهٔ [0,1) برای یک شناسه — پایهٔ تخفیف متغیر.
		 */
		public static function unit( $seed ) {
			$h = crc32( 'tcp-jitter-' . (string) $seed );
			return ( $h % 10000 ) / 10000;
		}

		/**
		 * تخفیف متغیر: قیمت نهایی رند در بازهٔ percent±jitter از $base، به‌ازای $seed.
		 *
		 * @return array{price:float,percent:float} قیمت نهایی و درصد مؤثر.
		 */
		public static function jittered_discount( $base, $percent, $seed, $jitter = null ) {
			$base    = (float) $base;
			$percent = (float) $percent;
			$jitter  = null === $jitter ? self::jitter() : (float) $jitter;
			$step    = self::step();
			$end     = self::ending();

			$p_lo = max( 0, $percent - $jitter );
			$p_hi = min( 99.9, $percent + $jitter );
			$lo   = $base * ( 1 - $p_hi / 100 ); // قیمت پایین‌تر = تخفیف بیشتر.
			$hi   = $base * ( 1 - $p_lo / 100 );

			// همهٔ اعداد رند داخل بازه.
			$k_min = (int) ceil( ( $lo - $end ) / $step );
			$k_max = (int) floor( ( $hi - $end ) / $step );

			if ( $k_max >= $k_min ) {
				$count = $k_max - $k_min + 1;
				$k     = $k_min + (int) floor( self::unit( $seed ) * $count );
				$k     = min( $k_max, max( $k_min, $k ) );
				$price = $k * $step + $end;
			} else {
			// بازه آن‌قدر باریک است که عدد رندی در آن نیست: نزدیک‌ترین عدد رند به تخفیف پایه.
			$price = self::nearest( $base * ( 1 - $percent / 100 ) );
			}

			if ( $price >= $base ) {
				$price = self::down( $base ) ;
				if ( $price >= $base ) {
					$price -= $step;
				}
			}
			$price = max( 0, $price );
			$eff   = $base > 0 ? ( 1 - $price / $base ) * 100 : 0;
			return array( 'price' => (float) $price, 'percent' => round( $eff, 2 ) );
		}

		/**
		 * اعمال حالت رند روی یک تخفیف درصدی — تابع مشترک.
		 *
		 * @param float  $base    قیمت پایه.
		 * @param float  $percent درصد تخفیف.
		 * @param string $mode    none | round | jitter
		 * @param mixed  $seed    شناسهٔ محصول/واریشن.
		 * @return array{price:float,percent:float}
		 */
		public static function discount( $base, $percent, $mode, $seed ) {
			$base = (float) $base;
			if ( 'jitter' === $mode ) {
				return self::jittered_discount( $base, $percent, $seed );
			}
		$raw = $base * ( 1 - (float) $percent / 100 );
		if ( 'round' === $mode ) {
			$price = self::nearest( $raw );
			// تخفیف نباید قیمت را به پایه یا بالاتر برگرداند.
			if ( $base > 0 && $price >= $base ) {
				$price = max( 0, $price - self::step() );
			}
		} else {
			$price = $raw;
		}
			$eff = $base > 0 ? ( 1 - $price / $base ) * 100 : 0;
			return array( 'price' => (float) $price, 'percent' => round( $eff, 2 ) );
		}
	}
}
