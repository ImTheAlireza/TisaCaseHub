<?php
/**
 * تاریخچهٔ اجراها: آخرین HISTORY_MAX اجرای هر کاربر (برای دانلود دوباره، اجرای مجدد
 * با همان تنظیمات و نمایش وضعیت فایل‌ها). فایل‌ها با Sweep ساعتی پاک می‌شوند و
 * وضعیت «پاک‌شده» در همان لحظهٔ خواندن از وجود فایل تشخیص داده می‌شود.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_History' ) ) {

	final class TisaCase_Exporter_History {

		/** کلید آپشن تاریخچهٔ کاربر جاری. */
		private static function option_key( $user_id = 0 ) {
			$user_id = $user_id ? (int) $user_id : get_current_user_id();
			return 'tisacase_exporter_history_' . $user_id;
		}

		/**
		 * همهٔ اجراهای ثبت‌شده (تازه‌ترین اول).
		 *
		 * @param int $user_id کاربر (۰ = جاری).
		 * @return array<int,array>
		 */
		public static function all( $user_id = 0 ) {
			$entries = get_option( self::option_key( $user_id ), array() );

			return is_array( $entries ) ? array_values( $entries ) : array();
		}

		/** ذخیرهٔ فهرست اجراها. */
		private static function save( array $entries, $user_id = 0 ) {
			update_option( self::option_key( $user_id ), array_slice( array_values( $entries ), 0, TisaCase_Exporter::HISTORY_MAX ), false );
		}

		/**
		 * افزودن یک اجرا به تاریخچه (تکراری با همان run_id جایگزین می‌شود).
		 *
		 * @param array $entry ورودی تاریخچه.
		 */
		public static function add( array $entry ) {
			$entry['created_at'] = isset( $entry['created_at'] ) ? (int) $entry['created_at'] : time();

			$entries = self::all();
			$out     = array( $entry );

			foreach ( $entries as $old ) {
				if ( ! empty( $old['run_id'] ) && ! empty( $entry['run_id'] ) && $old['run_id'] === $entry['run_id'] ) {
					continue;
				}
				$out[] = $old;
			}

			self::save( $out );
		}

		/**
		 * یافتن یک اجرا با run_id.
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return array|null
		 */
		public static function find( $run_id ) {
			foreach ( self::all() as $entry ) {
				if ( isset( $entry['run_id'] ) && $entry['run_id'] === (string) $run_id ) {
					return $entry;
				}
			}

			return null;
		}

		/** مسیر پوشهٔ یک اجرا (از نام نسبی ذخیره‌شده). */
		public static function dir_of( array $entry ) {
			if ( empty( $entry['dir'] ) ) {
				return '';
			}

			return TisaCase_Exporter_Storage::absolute_from_relative( $entry['dir'] );
		}

		/**
		 * آیا فایل‌های این اجرا هنوز روی دیسک هستند؟
		 *
		 * @param array $entry ورودی.
		 * @return bool
		 */
		public static function files_exist( array $entry ) {
			$dir = self::dir_of( $entry );

			if ( '' === $dir || ! is_dir( $dir ) || empty( $entry['files'] ) || ! is_array( $entry['files'] ) ) {
				return false;
			}

			foreach ( $entry['files'] as $file ) {
				if ( empty( $file['internal'] ) ) {
					continue;
				}

				if ( ! file_exists( trailingslashit( $dir ) . basename( (string) $file['internal'] ) ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * فهرست آمادهٔ نمایش در UI/JSON (با وضعیت وجود فایل‌ها).
		 *
		 * @return array<int,array>
		 */
		public static function for_display() {
			$out = array();

			foreach ( self::all() as $entry ) {
				$exists  = self::files_exist( $entry );
				$files   = array();
				$count   = 0;

				foreach ( (array) ( isset( $entry['files'] ) ? $entry['files'] : array() ) as $file ) {
					if ( empty( $file['internal'] ) || empty( $file['name'] ) ) {
						continue;
					}
					$scripts  = isset( $file['count'] ) ? (int) $file['count'] : 0;
					$count   += $scripts;
					$files[] = array(
						'name'  => (string) $file['name'],
						'count' => $scripts,
						'url'   => $exists ? self::file_url( $entry, $file ) : '',
					);
				}

				$out[] = array(
					'run_id'       => isset( $entry['run_id'] ) ? (string) $entry['run_id'] : '',
					'module'       => isset( $entry['module'] ) ? (string) $entry['module'] : '',
					'module_title' => isset( $entry['module_title'] ) ? (string) $entry['module_title'] : '',
					'format'       => isset( $entry['format'] ) ? (string) $entry['format'] : '',
					'extension'    => isset( $entry['extension'] ) ? (string) $entry['extension'] : 'csv',
					'rows'         => isset( $entry['rows'] ) ? (int) $entry['rows'] : 0,
					'file_count'   => count( $files ),
					'rows_total'   => $count,
					'files'        => $files,
					'files_exist'  => $exists,
					'created_at'   => isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0,
					'created_fa'   => isset( $entry['created_at'] ) ? self::format_time( (int) $entry['created_at'] ) : '',
					'summary'      => isset( $entry['summary'] ) && is_array( $entry['summary'] ) ? array_values( $entry['summary'] ) : array(),
					'columns'      => isset( $entry['columns'] ) && is_array( $entry['columns'] ) ? array_values( $entry['columns'] ) : array(),
					'filters'      => isset( $entry['filters'] ) && is_array( $entry['filters'] ) ? $entry['filters'] : array(),
					'dedup'        => isset( $entry['dedup'] ) ? (string) $entry['dedup'] : '',
					'zip_url'      => ( $exists && count( $files ) > 1 && class_exists( 'ZipArchive' ) ) ? self::zip_url( $entry ) : '',
				);
			}

			return $out;
		}

		/** آدرس دانلود یک فایل با nonce. */
		public static function file_url( array $entry, array $file ) {
			return add_query_arg(
				array(
					'action'   => TisaCase_Exporter::DOWNLOAD,
					'run'      => isset( $entry['run_id'] ) ? (string) $entry['run_id'] : '',
					'part'     => (string) $file['internal'],
					'_wpnonce' => wp_create_nonce( TisaCase_Exporter::DOWNLOAD ),
				),
				admin_url( 'admin-post.php' )
			);
		}

		/** آدرس دانلود Zip همهٔ پارت‌ها. */
		public static function zip_url( array $entry ) {
			return add_query_arg(
				array(
					'action'   => TisaCase_Exporter::DOWNLOAD_ZIP,
					'run'      => isset( $entry['run_id'] ) ? (string) $entry['run_id'] : '',
					'_wpnonce' => wp_create_nonce( TisaCase_Exporter::DOWNLOAD_ZIP ),
				),
				admin_url( 'admin-post.php' )
			);
		}

		/** پاک‌سازی کل تاریخچه (+ فایل‌های باقی‌مانده). */
		public static function clear( $delete_files = true ) {
			foreach ( self::all() as $entry ) {
				$dir = self::dir_of( $entry );

				if ( $delete_files && '' !== $dir && is_dir( $dir ) && TisaCase_Exporter_Storage::is_allowed_dir( $dir ) ) {
					TisaCase_Exporter_Storage::delete_directory( $dir );
				}
			}

			delete_option( self::option_key() );
		}

		/** زمان خوانا (تاریخ محلی سایت). */
		private static function format_time( $timestamp ) {
			$format = 'Y-m-d H:i';

			if ( function_exists( 'wp_date' ) ) {
				return (string) wp_date( $format, $timestamp );
			}

			return (string) date_i18n( $format, $timestamp ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		}
	}
}
