<?php
/**
 * خط لولهٔ نوشتن: بافرشده (حداقل syscall)، شکستن به پارت‌های file_size تایی
 * و یکتاسازی با مرتب‌سازی تکه‌ای (External Sort) با حافظهٔ محدود.
 *
 * خطوط فایل کاری در حالت یکتاسازی به‌شکل «کلید + تب + ردیف» ذخیره می‌شوند تا
 * هم مرتب‌سازی درست باشد و هم در پایان فقط کلیدها مقایسه شوند.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Pipeline' ) ) {

	final class TisaCase_Exporter_Pipeline {

		/** نام پارت‌ها (داخلی، همیشه TSV). */
		public static function part_name( $part_number ) {
			return TisaCase_Exporter::PART_PREFIX . str_pad( (string) $part_number, 3, '0', STR_PAD_LEFT ) . '.tsv';
		}

		/** مسیر فایل یک پارت. */
		public static function part_path( $state, $part_number ) {
			return trailingslashit( $state['dir'] ) . self::part_name( $part_number );
		}

		/** مسیر فایل کاری. */
		public static function working_path( $state ) {
			return trailingslashit( $state['dir'] ) . TisaCase_Exporter::WORKING_FILE;
		}

		/** ثبت پارت کامل‌شده در State (ایدمپوتنت). */
		public static function mark_part_complete( &$state, $part_number, $count ) {
			$internal = self::part_name( $part_number );

			foreach ( $state['files'] as $file ) {
				if ( isset( $file['internal'] ) && $internal === $file['internal'] ) {
					return;
				}
			}

			$state['files'][] = array(
				'internal' => $internal,
				'name'     => self::download_name( $state, $part_number ),
				'count'    => (int) $count,
			);
		}

		/** نام فایل خروجی نهایی (بر اساس بخش/قالب/شمارهٔ پارت). */
		public static function download_name( $state, $part_number ) {
			$module  = isset( $state['module'] ) ? (string) $state['module'] : 'export';
			$format  = isset( $state['format'] ) ? (string) $state['format'] : 'csv';
			$date    = isset( $state['started_at'] ) ? gmdate( 'Y-m-d', (int) $state['started_at'] ) : gmdate( 'Y-m-d' );
			$ext     = TisaCase_Exporter_Format::ext( $format );
			$number  = str_pad( (string) $part_number, 3, '0', STR_PAD_LEFT );

			return $module . '-' . $date . '-' . $number . '.' . $ext;
		}

		/**
		 * حالت عادی: شکستن بافر (خطوط TSV) به پارت‌های file_size تایی.
		 *
		 * @param array $buffer خطوط TSV.
		 * @param array $state  State (با مرجع).
		 * @return true|WP_Error
		 */
		public static function flush_parts( array $buffer, &$state ) {
			$buffer    = array_values( $buffer );
			$file_size = TisaCase_Exporter::file_size();

			while ( ! empty( $buffer ) ) {
				$part  = (int) $state['current_file'];
				$count = (int) $state['current_count'];

				if ( $count >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file']  = $part + 1;
					$state['current_count'] = 0;
					continue;
				}

				$space  = $file_size - $count;
				$take   = array_slice( $buffer, 0, $space );
				$buffer = array_slice( $buffer, $space );

				$path   = self::part_path( $state, $part );
				$prefix = ( $count > 0 ) ? "\n" : '';

				if ( false === @file_put_contents( $path, $prefix . implode( "\n", $take ), FILE_APPEND | LOCK_EX ) ) {
					return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				$state['current_count'] += count( $take );

				if ( (int) $state['current_count'] >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file']  = $part + 1;
					$state['current_count'] = 0;
				}
			}

			return true;
		}

		/** حالت یکتاسازی: خطوط «کلید + تب + ردیف» در فایل کاری جمع می‌شوند. */
		public static function flush_working( array $lines, &$state ) {
			if ( empty( $lines ) ) {
				return true;
			}

			$path   = self::working_path( $state );
			$done   = isset( $state['working_count'] ) ? (int) $state['working_count'] : 0;
			$prefix = ( $done > 0 ) ? "\n" : '';

			if ( false === @file_put_contents( $path, $prefix . implode( "\n", $lines ), FILE_APPEND | LOCK_EX ) ) {
				return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$state['working_count'] = $done + count( $lines );

			return true;
		}

		/** ثبت آخرین پارت نیمه‌پر در حالت عادی. */
		public static function finalize_last_part( &$state ) {
			if ( (int) $state['current_count'] > 0 ) {
				self::mark_part_complete( $state, (int) $state['current_file'], (int) $state['current_count'] );
			}
		}

		/**
		 * پایان حالت یکتاسازی: مرتب‌سازی تکه‌ای + ادغام K-way + حذف تکراری + شکستن به پارت‌ها.
		 * خروجی بر اساس کلید صعودی مرتب می‌شود.
		 *
		 * @param array $state State (مرجع).
		 * @return true|WP_Error
		 */
		public static function finalize_dedup( &$state ) {
			$dir     = trailingslashit( $state['dir'] );
			$working = self::working_path( $state );

			// اجرای دوبارهٔ همین مرحله (بعد از خطای نوشتن): پارت‌های نیمه‌کارهٔ قبلی دور ریخته می‌شوند.
			foreach ( (array) glob( $dir . TisaCase_Exporter::PART_PREFIX . '*.tsv' ) as $stale ) {
				@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$state['files'] = array();

			if ( ! file_exists( $working ) ) {
				$state['duplicates'] = 0;
				return true;
			}

			$in = @fopen( $working, 'rb' );
			if ( ! $in ) {
				return new WP_Error( 'read_failed', __( 'خواندن فایل موقت خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			// مرحله ۱: مرتب‌سازی + یکتاسازیِ داخل هر تکه.
			$chunk_files = array();

			while ( ! feof( $in ) ) {
				$lines = array();

				while ( count( $lines ) < TisaCase_Exporter::CHUNK_LINES && false !== ( $line = fgets( $in ) ) ) {
					$line = rtrim( $line, "\r\n" );
					if ( '' !== $line ) {
						$lines[] = $line;
					}
				}

				if ( empty( $lines ) ) {
					break;
				}

				$lines = array_values( array_unique( $lines ) );
				sort( $lines, SORT_STRING );

				$chunk = $dir . 'chunk-' . count( $chunk_files ) . '.tmp';

				if ( false === @file_put_contents( $chunk, implode( "\n", $lines ) . "\n" ) ) {
					fclose( $in );
					TisaCase_Exporter_Storage::delete_files( $chunk_files );
					return new WP_Error( 'chunk_failed', __( 'آماده‌سازی خروجی (مرتب‌سازی) ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				$chunk_files[] = $chunk;
				TisaCase_Exporter_Session::lock_refresh( $state['run_id'] );
			}

			fclose( $in );

			// مرحله ۲: ادغام K-way + یکتاسازی بین تکه‌ها + نوشتن پارت‌ها.
			$handles = array();
			$current = array();
			$failed  = false;

			foreach ( $chunk_files as $chunk ) {
				$handle = @fopen( $chunk, 'rb' );

				if ( ! $handle ) {
					$failed = true;
					break;
				}

				$handles[] = $handle;
				$current[] = self::read_line( $handle );
			}

			if ( $failed ) {
				TisaCase_Exporter_Storage::close_all( $handles );
				TisaCase_Exporter_Storage::delete_files( $chunk_files );
				return new WP_Error( 'merge_failed', __( 'آماده‌سازی خروجی (ادغام) ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$file_size = TisaCase_Exporter::file_size();
			$part      = 1;
			$count     = 0;
			$unique    = 0;
			$last_key  = null;
			$out       = null;
			$buf       = '';
			$error     = null;

			while ( true ) {
				$best      = -1;
				$best_line = null;

				foreach ( $current as $i => $line ) {
					if ( null === $line ) {
						continue;
					}
					if ( null === $best_line || strcmp( $line, $best_line ) < 0 ) {
						$best_line = $line;
						$best      = $i;
					}
				}

				if ( $best < 0 ) {
					break;
				}

				$current[ $best ] = self::read_line( $handles[ $best ] );

				$key  = self::line_key( $best_line );
				$row  = self::line_row( $best_line );

				if ( null !== $last_key && $key === $last_key ) {
					continue; // تکراری: رد شد.
				}

				$last_key = $key;
				$unique++;

				if ( null === $out ) {
					$out = @fopen( self::part_path( $state, $part ), 'wb' );

					if ( ! $out ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
						break;
					}

					$count = 0;
					$buf   = '';
				}

				$buf .= ( $count > 0 ? "\n" : '' ) . $row;
				$count++;

				if ( 0 === ( $unique % 50000 ) ) {
					TisaCase_Exporter_Session::lock_refresh( $state['run_id'] );
				}

				if ( $count >= $file_size ) {
					if ( false === fwrite( $out, $buf ) ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
						break;
					}
					fclose( $out );
					$out = null;
					self::mark_part_complete( $state, $part, $count );
					$part++;
					$count = 0;
					$buf   = '';
				} elseif ( strlen( $buf ) >= TisaCase_Exporter::WRITE_BUF ) {
					if ( false === fwrite( $out, $buf ) ) {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
						break;
					}
					$buf = '';
				}
			}

			if ( null !== $out ) {
				if ( null === $error && $count > 0 ) {
					if ( false !== fwrite( $out, $buf ) ) {
						self::mark_part_complete( $state, $part, $count );
					} else {
						$error = new WP_Error( 'part_failed', __( 'نوشتن فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
					}
				}
				fclose( $out );
			}

			TisaCase_Exporter_Storage::close_all( $handles );
			TisaCase_Exporter_Storage::delete_files( $chunk_files );

			if ( null !== $error ) {
				// working.tsv حفظ می‌شود تا «ادامه» بتواند دوباره تلاش کند.
				return $error;
			}

			@unlink( $working );

			$before                = (int) $state['exported'];
			$state['exported']     = $unique;
			$state['duplicates']   = max( 0, $before - $unique );

			return true;
		}

		/** کلید یکتاسازی از خط فایل کاری. */
		public static function line_key( $line ) {
			$pos = strpos( (string) $line, "\t" );

			return false === $pos ? (string) $line : substr( (string) $line, 0, $pos );
		}

		/** ردیف (بدون کلید) از خط فایل کاری. */
		public static function line_row( $line ) {
			$pos = strpos( (string) $line, "\t" );

			return false === $pos ? '' : substr( (string) $line, $pos + 1 );
		}

		/** ساخت خط فایل کاری از کلید و ردیف. */
		public static function make_line( $key, $row ) {
			$key = (string) $key;

			// کلید خالی = ردیفی که یکتاسازی ندارد؛ با پیشوند کنترلی از بقیه جدا می‌شود.
			if ( '' === $key ) {
				$key = "\x01" . md5( (string) $row );
			}

			return $key . "\t" . $row;
		}

		/** خواندن خط غیرخالی بعدی از Handle ادغام. */
		private static function read_line( $handle ) {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$line = rtrim( $line, "\r\n" );
				if ( '' !== $line ) {
					return $line;
				}
			}

			return null;
		}
	}
}
