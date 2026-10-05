<?php
/**
 * نرمال‌سازی شماره موبایل ایرانی به فرمت 989xxxxxxxxx.
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

		/** تبدیل همه فرم‌های شماره ایرانی به 989xxxxxxxxx؛ نامعتبر = رشته خالی. */
		public static function normalize_phone( $raw ) {
			$digits = preg_replace( '/\D+/', '', self::normalize_digits( $raw ) );

			if ( ! is_string( $digits ) || '' === $digits ) {
				return '';
			}

			// 00989123456789 -> 989123456789
			if ( 0 === strpos( $digits, '0098' ) ) {
				$digits = substr( $digits, 2 );
			}

			// 0989123456789 -> 989123456789
			if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '0989' ) ) {
				$digits = substr( $digits, 1 );
			}

			// 09123456789 -> 989123456789
			if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '09' ) ) {
				$digits = '98' . substr( $digits, 1 );
			}

			// 9123456789 -> 989123456789
			if ( 10 === strlen( $digits ) && '9' === substr( $digits, 0, 1 ) ) {
				$digits = '98' . $digits;
			}

			if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '989' ) ) {
				return $digits;
			}

			return '';
		}
	}
}
