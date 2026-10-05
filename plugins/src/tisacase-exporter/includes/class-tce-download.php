<?php
/**
 * دانلود خروجی‌ها: تبدیل جریانی (Streaming) پارت‌های داخلی TSV به قالب نهایی
 * زمان دانلود انجام می‌شود؛ بنابراین فایل‌های روی دیسک همیشه کوچک و یکدست‌اند و
 * خروجی نهایی (مثلاً txt تک‌ستونی بخش شماره‌ها) دقیقاً همان چیزی است که ذخیره شده.
 *
 * دانلود فایل تکی و دانلود همهٔ پارت‌ها به‌صورت ZIP پشتیبانی می‌شود.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Download' ) ) {

	final class TisaCase_Exporter_Download {

		/** دانلود یک فایل خروجی. */
		public static function download_file() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز است.', TisaCase_Exporter::TEXT_DOMAIN ), '', array( 'response' => 403 ) );
			}

			check_admin_referer( TisaCase_Exporter::DOWNLOAD );

			$run_id = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';
			$part   = isset( $_GET['part'] ) ? wp_unslash( $_GET['part'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- با basename و whitelist اعتبارسنجی می‌شود.
			$part   = basename( (string) $part );

			$run = self::resolve_run( $run_id );

			if ( null === $run ) {
				self::fail( __( 'خروجی موردنظر پیدا نشد یا منقضی شده است.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$file = self::find_file( $run, $part );

			if ( null === $file ) {
				self::fail( __( 'فایل درخواستی بخشی از این خروجی نیست.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$path = trailingslashit( $run['dir'] ) . $file['internal'];

			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				self::fail( __( 'فایل خروجی روی سرور موجود نیست (احتمالاً به‌صورت خودکار پاک شده است).', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			self::stream_file( $path, $file['name'], $run['format'], $run['columns'], isset( $run['module'] ) ? (string) $run['module'] : '' );
		}

		/** دانلود همهٔ پارت‌های یک اجرا در یک فایل ZIP. */
		public static function download_zip() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز است.', TisaCase_Exporter::TEXT_DOMAIN ), '', array( 'response' => 403 ) );
			}

			check_admin_referer( TisaCase_Exporter::DOWNLOAD_ZIP );

			$run_id = isset( $_GET['run'] ) ? sanitize_key( wp_unslash( $_GET['run'] ) ) : '';
			$run    = self::resolve_run( $run_id );

			if ( null === $run ) {
				self::fail( __( 'خروجی موردنظر پیدا نشد یا منقضی شده است.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			if ( ! class_exists( 'ZipArchive' ) ) {
				self::fail( __( 'افزونهٔ Zip روی این سرور فعال نیست؛ لطفاً فایل‌ها را یکی‌یکی دانلود کنید.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$existing = array();

			foreach ( $run['files'] as $file ) {
				$path = trailingslashit( $run['dir'] ) . $file['internal'];

				if ( is_file( $path ) && is_readable( $path ) ) {
					$existing[] = array(
						'path' => $path,
						'name' => $file['name'],
					);
				}
			}

			if ( empty( $existing ) ) {
				self::fail( __( 'هیچ فایلی برای این خروجی روی سرور باقی نمانده است.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			// در حالت یکتاسازی، پارت‌ها TSV خام‌اند؛ برای ZIP، نسخهٔ تبدیل‌شده در temp ساخته می‌شود.
			$temp = array();
			$zip  = new ZipArchive();
			$zip_path = tempnam( $run['dir'], 'zip' );

			if ( false === $zip_path || true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
				self::fail( __( 'ساخت فایل ZIP روی سرور ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			foreach ( $existing as $item ) {
				$converted = self::convert_tsv_to_format( $item['path'], $run['format'], $run['columns'], isset( $run['module'] ) ? (string) $run['module'] : '' );

				if ( is_wp_error( $converted ) ) {
					continue;
				}

				$temp[] = $converted;
				$zip->addFile( $converted, $item['name'] );
			}

			$zip->close();
			$size = (int) filesize( $zip_path );

			if ( 0 === $size ) {
				TisaCase_Exporter_Storage::delete_files( $temp );
				@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				self::fail( __( 'فایل ZIP خالی ساخته شد؛ لطفاً فایل‌ها را یکی‌یکی دانلود کنید.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			self::clean_buffers();
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . self::zip_name( $run ) . '"' );
			header( 'Content-Length: ' . $size );
			header( 'X-Content-Type-Options: nosniff' );

			self::readfile_chunked( $zip_path );

			TisaCase_Exporter_Storage::delete_files( $temp );
			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			exit;
		}

		/* -----------------------------------------------------------------
		 * کمکی‌ها
		 * ----------------------------------------------------------------- */

		/**
		 * یافتن اجرا بر اساس run_id: ابتدا جلسهٔ فعال، سپس تاریخچهٔ کاربر.
		 *
		 * @return array|null آرایه‌ای با dir/files/format/columns/extension/created_at.
		 */
		public static function resolve_run( $run_id ) {
			if ( ! is_string( $run_id ) || '' === $run_id ) {
				return null;
			}

			$state = TisaCase_Exporter_Session::get_state();

			if ( ! empty( $state['run_id'] ) && $state['run_id'] === $run_id && ! empty( $state['dir'] ) ) {
				return array(
					'dir'       => (string) $state['dir'],
					'files'     => self::normalize_files( isset( $state['files'] ) ? $state['files'] : array() ),
					'format'    => isset( $state['format'] ) ? (string) $state['format'] : 'csv',
					'extension' => TisaCase_Exporter_Format::ext( isset( $state['format'] ) ? (string) $state['format'] : 'csv' ),
					'columns'   => isset( $state['columns'] ) && is_array( $state['columns'] ) ? array_values( $state['columns'] ) : array(),
					'module'    => isset( $state['module'] ) ? (string) $state['module'] : '',
				);
			}

			$entry = TisaCase_Exporter_History::find( $run_id );

			if ( null === $entry ) {
				return null;
			}

			$dir = TisaCase_Exporter_History::dir_of( $entry );

			if ( '' === $dir || ! is_dir( $dir ) || ! TisaCase_Exporter_Storage::is_allowed_dir( $dir ) ) {
				return null;
			}

			return array(
				'dir'       => $dir,
				'files'     => self::normalize_files( isset( $entry['files'] ) ? $entry['files'] : array() ),
				'format'    => isset( $entry['format'] ) ? (string) $entry['format'] : 'csv',
				'extension' => isset( $entry['extension'] ) ? (string) $entry['extension'] : 'csv',
				'columns'   => isset( $entry['columns'] ) && is_array( $entry['columns'] ) ? array_values( $entry['columns'] ) : array(),
				'module'    => isset( $entry['module'] ) ? (string) $entry['module'] : '',
				'created_at' => isset( $entry['created_at'] ) ? (int) $entry['created_at'] : 0,
			);
		}

		/** نرمال‌سازی فهرست فایل‌های یک اجرا (فقط نام داخلی مجاز). */
		private static function normalize_files( $files ) {
			$out = array();

			foreach ( (array) $files as $file ) {
				if ( empty( $file['internal'] ) ) {
					continue;
				}

				$internal = basename( (string) $file['internal'] );

				if ( ! preg_match( '/^part-\d{3,4}\.tsv$/', $internal ) ) {
					continue; // فقط پارت‌های تولیدشدهٔ خودمان.
				}

				$out[] = array(
					'internal' => $internal,
					'name'     => ! empty( $file['name'] ) ? basename( (string) $file['name'] ) : $internal,
					'count'    => isset( $file['count'] ) ? (int) $file['count'] : 0,
				);
			}

			return $out;
		}

		/** یافتن یک فایل در فهرست مجاز یک اجرا. */
		private static function find_file( array $run, $internal ) {
			foreach ( $run['files'] as $file ) {
				if ( $file['internal'] === $internal ) {
					return $file;
				}
			}

			return null;
		}

		/** نام فایل ZIP. */
		private static function zip_name( array $run ) {
			$module = '' !== $run['module'] ? preg_replace( '/[^a-z0-9\-_]/i', '', (string) $run['module'] ) : 'export';
			$stamp  = ! empty( $run['created_at'] ) ? gmdate( 'Y-m-d', (int) $run['created_at'] ) : gmdate( 'Y-m-d' );

			return $module . '-' . $stamp . '.zip';
		}

		/**
		 * ارسال یک پارت با تبدیل جریانی به قالب نهایی.
		 *
		 * @param string $path    مسیر فایل داخلی TSV.
		 * @param string $name    نام فایل دانلودی.
		 * @param string $format  قالب.
		 * @param array  $columns تعریف ستون‌ها (label/key/type).
		 */
		private static function stream_file( $path, $name, $format, array $columns, $module = '' ) {
			$in = @fopen( $path, 'rb' );

			if ( ! $in ) {
				self::fail( __( 'خواندن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$format = TisaCase_Exporter_Format::is_valid( $format ) ? $format : 'csv';
			$labels = array();
			$keys   = array();

			foreach ( $columns as $col ) {
				$labels[] = isset( $col['label'] ) ? (string) $col['label'] : '';
				$keys[]   = isset( $col['key'] ) ? (string) $col['key'] : '';
			}

			if ( empty( $labels ) ) {
				$labels = array( __( 'مقدار', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			self::clean_buffers();
			nocache_headers();
			header( 'Content-Type: ' . TisaCase_Exporter_Format::mime( $format ) );
			header( 'Content-Disposition: attachment; filename="' . self::safe_name( $name ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );

			TisaCase_Exporter_Format::stream_open( $format, $labels, $keys, null, self::pdf_meta( $module, $columns ) );

			while ( false !== ( $line = fgets( $in, 1048576 ) ) ) {
				$line = rtrim( $line, "\r\n" );

				if ( '' === $line ) {
					continue;
				}

				TisaCase_Exporter_Format::stream_row( TisaCase_Exporter_Format::split_tsv( $line ), $keys );
			}

			fclose( $in );
			TisaCase_Exporter_Format::stream_close();
			exit;
		}

		/**
		 * تبدیل یک پارت TSV به قالب نهایی در یک فایل موقت (برای ZIP).
		 *
		 * @return string|WP_Error مسیر فایل موقت.
		 */
		private static function convert_tsv_to_format( $path, $format, array $columns, $module = '' ) {
			$in = @fopen( $path, 'rb' );

			if ( ! $in ) {
				return new WP_Error( 'read_failed', __( 'خواندن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$format = TisaCase_Exporter_Format::is_valid( $format ) ? $format : 'csv';
			$labels = array();
			$keys   = array();

			foreach ( $columns as $col ) {
				$labels[] = isset( $col['label'] ) ? (string) $col['label'] : '';
				$keys[]   = isset( $col['key'] ) ? (string) $col['key'] : '';
			}

			if ( empty( $labels ) ) {
				$labels = array( __( 'مقدار', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$target = tempnam( dirname( $path ), 'tmp' );

			if ( false === $target ) {
				fclose( $in );
				return new WP_Error( 'temp_failed', __( 'ساخت فایل موقت ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$out = @fopen( $target, 'wb' );

			if ( ! $out ) {
				fclose( $in );
				return new WP_Error( 'temp_failed', __( 'ساخت فایل موقت ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			TisaCase_Exporter_Format::stream_open( $format, $labels, $keys, $out, self::pdf_meta( $module, $columns ) );

			while ( false !== ( $line = fgets( $in, 1048576 ) ) ) {
				$line = rtrim( $line, "\r\n" );

				if ( '' === $line ) {
					continue;
				}

				TisaCase_Exporter_Format::stream_row( TisaCase_Exporter_Format::split_tsv( $line ), $keys );
			}

			fclose( $in );
			TisaCase_Exporter_Format::stream_close();

			return $target;
		}

		/**
		 * فرادادهٔ قالب PDF: عنوان بخش، نام سایت، تاریخ و تعریف ستون‌ها.
		 *
		 * @param string $module  شناسهٔ بخش (مثلاً customers).
		 * @param array  $columns تعریف ستون‌های همان اجرا.
		 * @return array
		 */
		private static function pdf_meta( $module, array $columns ) {
			$title = '';

			if ( '' !== (string) $module && class_exists( 'TisaCase_Exporter_Modules' ) ) {
				$item = TisaCase_Exporter_Modules::get( (string) $module );

				if ( is_array( $item ) && ! empty( $item['label'] ) ) {
					$title = (string) $item['label'];
				}
			}

			$summary = array();

			if ( '' !== (string) $module && class_exists( 'TisaCase_Exporter_Modules' ) ) {
				$item = TisaCase_Exporter_Modules::get( (string) $module );

				if ( is_array( $item ) && ! empty( $item['class'] ) && method_exists( $item['class'], 'filter_summary' ) ) {
					$state = TisaCase_Exporter_Session::get_state();

					if ( ! empty( $state['filters'] ) && is_array( $state['filters'] ) ) {
						$summary = call_user_func( array( $item['class'], 'filter_summary' ), $state['filters'] );
					}
				}
			}

			return array(
				'title'   => $title,
				'filters' => implode( ' · ', $summary ),
				'site'    => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
				'date'    => function_exists( 'date_i18n' ) ? (string) date_i18n( 'j F Y' ) : gmdate( 'Y-m-d' ),
				'columns' => array_values( $columns ),
			);
		}

		/** نام فایل امن برای هدر Content-Disposition (ASCII + UTF-8). */
		private static function safe_name( $name ) {
			$name = basename( (string) $name );
			$name = preg_replace( '/[^A-Za-z0-9._\-]/', '-', $name );

			return ( '' !== $name ) ? $name : 'export.csv';
		}

		/** پاک‌کردن بافرهای خروجی قبل از ارسال هدرها. */
		private static function clean_buffers() {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 );
			}

			while ( ob_get_level() > 0 ) {
				if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					break;
				}
			}
		}

		/** خواندن فایل به‌صورت تکه‌ای (بدون بارگذاری کامل در حافظه). */
		private static function readfile_chunked( $path ) {
			$handle = @fopen( $path, 'rb' );

			if ( ! $handle ) {
				return;
			}

			while ( ! feof( $handle ) ) {
				$chunk = fread( $handle, 262144 );

				if ( false === $chunk ) {
					break;
				}

				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- محتوای فایل خروجی.
				flush();
			}

			fclose( $handle );
		}

		/** توقف با پیام خوانا. */
		private static function fail( $message ) {
			wp_die( esc_html( $message ), esc_html__( 'دانلود ناموفق', TisaCase_Exporter::TEXT_DOMAIN ), array( 'response' => 404 ) );
		}
	}
}
