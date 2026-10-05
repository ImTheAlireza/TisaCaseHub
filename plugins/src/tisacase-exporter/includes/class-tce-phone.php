<?php
/**
 * نرمال‌سازی شماره موبایل ایرانی به قالب 989xxxxxxxxx.
 *
 * شماره‌هایی که با پیش‌شمارهٔ کشور ثبت شده‌اند (از جمله پیش‌شمارهٔ تکراریِ
 * 98) به یک مقدار canonical تبدیل می‌شوند؛ ورودی نامعتبر رشتهٔ خالی است.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Phone' ) ) {

	final class TisaCase_Exporter_Phone {

		/** تبدیل ارقام فارسی/عربی به لاتین. */
		private static function normalize_digits( $value ) {
			$value = (string) $value;

			$fa = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
			$ar = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
			$en = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );

			$value = str_replace( $fa, $en, $value );
			$value = str_replace( $ar, $en, $value );

			return $value;
		}

		/**
		 * تبدیل شکل‌های رایج شمارهٔ موبایل ایران به 989xxxxxxxxx.
		 *
		 * ورودی‌های +98، 0098، 09، رقم‌های فارسی/عربی و پیش‌شمارهٔ 98 تکراری
		 * پشتیبانی می‌شوند. تلفن ثابت، شمارهٔ ناقص و ورودی نامعتبر رد می‌شوند.
		 *
		 * @param mixed $raw شمارهٔ خام.
		 * @return string شمارهٔ canonical یا رشتهٔ خالی.
		 */
		public static function normalize_phone( $raw ) {
			$digits = preg_replace( '/\D+/', '', self::normalize_digits( $raw ) );

			if ( ! is_string( $digits ) || '' === $digits ) {
				return '';
			}

			// پیش‌شمارهٔ خروجی بین‌المللی (00) را حذف می‌کنیم؛ خود 98 پایین‌تر جدا می‌شود.
			if ( 0 === strpos( $digits, '00' ) ) {
				$digits = substr( $digits, 2 );
			}

			// بعضی فرم‌ها صفرِ trunk را پیش از کد کشور ذخیره می‌کنند: 0989123456789.
			if ( 0 === strpos( $digits, '098' ) ) {
				$digits = substr( $digits, 1 );
			}

			if ( 0 === strpos( $digits, '98' ) ) {
				$digits = substr( $digits, 2 );

				// بازیابی ورودی‌هایی مثل +98 98 912... (حتی اگر کد کشور چند بار تکرار شده باشد).
				while ( strlen( $digits ) > 10 && 0 === strpos( $digits, '98' ) ) {
					$digits = substr( $digits, 2 );
				}

				// شمارهٔ محلی ممکن است بعد از +98 هنوز صفرِ trunk داشته باشد.
				if ( 0 === strpos( $digits, '0' ) ) {
					$digits = substr( $digits, 1 );
				}
			} elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) {
				// 09123456789 → 9123456789.
				$digits = substr( $digits, 1 );
			}

			// مقدار نهایی باید دقیقاً کد ملی ۱۰ رقمی موبایل ایران را داشته باشد.
			if ( 10 === strlen( $digits ) && 0 === strpos( $digits, '9' ) ) {
				return '98' . $digits;
			}

			return '';
		}
	}
}
