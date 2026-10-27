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

		/**
		 * بازگرداندن فایل‌ها به آخرین checkpoint ثبت‌شده در State.
		 * اگر درخواست پس از append فایل و قبل از ذخیرهٔ State متوقف شده باشد،
		 * tail نوشته‌نشده حذف می‌شود تا اجرای دوبارهٔ همان Batch ردیف تکراری نسازد.
		 *
		 * @param array $state State ذخیره‌شده.
		 * @return true|WP_Error
		 */
		public static function reconcile_checkpoint( array $state ) {
			if ( empty( $state['dir'] ) || ! is_dir( $state['dir'] ) ) {
				return new WP_Error( 'checkpoint_dir', __( 'پوشهٔ جلسه برای بازیابی پیدا نشد.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			// جلسه‌های ساخته‌شده با نسخهٔ پیشین offset ندارند؛ فایل‌هایشان را دست‌نخورده می‌گذاریم.
			if ( ! array_key_exists( 'current_bytes', $state ) && ! array_key_exists( 'working_bytes', $state ) ) {
				return true;
			}

			$dedup_phase = isset( $state['dedup_finalize']['phase'] ) ? $state['dedup_finalize']['phase'] : '';
			if ( ! empty( $state['dedup'] ) && ! in_array( $dedup_phase, array( 'output', 'complete' ), true ) ) {
				$path     = self::working_path( $state );
				$expected = isset( $state['working_bytes'] ) ? max( 0, (int) $state['working_bytes'] ) : 0;

				if ( $expected > 0 && ! is_file( $path ) ) {
					return new WP_Error( 'checkpoint_missing', __( 'فایل موقت یکتاسازی ناقص است؛ برای جلوگیری از خروجی نادرست، این جلسه ادامه داده نشد.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				if ( file_exists( $path ) && ! self::truncate_file( $path, $expected ) ) {
					return new WP_Error( 'checkpoint_restore', __( 'بازیابی فایل موقت خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				return true;
			}

			$expected = array();

			foreach ( (array) ( isset( $state['files'] ) ? $state['files'] : array() ) as $file ) {
				if ( empty( $file['internal'] ) || ! isset( $file['bytes'] ) ) {
					// State قدیمی را تخریب نکن؛ از این به بعد نوشته‌ها offset خواهند داشت.
					return true;
				}

				$name = basename( (string) $file['internal'] );
				if ( 0 !== strpos( $name, TisaCase_Exporter::PART_PREFIX ) || '.tsv' !== substr( $name, -4 ) ) {
					continue;
				}

				$expected[ trailingslashit( $state['dir'] ) . $name ] = max( 0, (int) $file['bytes'] );
			}

			$part_number = isset( $state['current_file'] ) ? max( 1, (int) $state['current_file'] ) : 1;
			$current     = self::part_path( $state, $part_number );
			$expected[ $current ] = isset( $state['current_bytes'] ) ? max( 0, (int) $state['current_bytes'] ) : 0;

			foreach ( $expected as $path => $bytes ) {
				if ( $bytes > 0 && ! is_file( $path ) ) {
					return new WP_Error( 'checkpoint_missing', __( 'بخشی از فایل خروجی از دست رفته است؛ برای جلوگیری از خروجی ناقص، این جلسه ادامه داده نشد.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				if ( file_exists( $path ) && ! self::truncate_file( $path, $bytes ) ) {
					return new WP_Error( 'checkpoint_restore', __( 'بازیابی فایل خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}
			}

			foreach ( (array) glob( trailingslashit( $state['dir'] ) . TisaCase_Exporter::PART_PREFIX . '*.tsv' ) as $path ) {
				if ( ! isset( $expected[ $path ] ) ) {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}

			return true;
		}

		/** کوتاه‌کردن/حذف tail ناتمام. */
		private static function truncate_file( $path, $bytes ) {
			$bytes = max( 0, (int) $bytes );

			if ( ! is_file( $path ) ) {
				return 0 === $bytes;
			}

			clearstatcache( true, $path );
			$size = (int) filesize( $path );

			if ( $size < $bytes ) {
				return false;
			}

			if ( $size === $bytes ) {
				return true;
			}

			if ( 0 === $bytes ) {
				return @unlink( $path ) || ! file_exists( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$handle = @fopen( $path, 'c+b' );
			if ( ! $handle ) {
				return false;
			}

			$ok = ftruncate( $handle, $bytes );
			fflush( $handle );
			fclose( $handle );
			clearstatcache( true, $path );

			return $ok && (int) filesize( $path ) === $bytes;
		}

		/** پس از ذخیرهٔ State نهایی، فایل کاری دیگر برای recovery لازم نیست. */
		public static function cleanup_working( array $state ) {
			$path = self::working_path( $state );

			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( ! empty( $state['dir'] ) && is_dir( $state['dir'] ) ) {
				foreach ( (array) glob( trailingslashit( $state['dir'] ) . 'dedup-*' ) as $temporary ) {
					if ( is_file( $temporary ) ) {
						@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}

		/** ثبت پارت کامل‌شده در State (ایدمپوتنت). */
		public static function mark_part_complete( &$state, $part_number, $count ) {
			$internal = self::part_name( $part_number );

			foreach ( $state['files'] as $file ) {
				if ( isset( $file['internal'] ) && $internal === $file['internal'] ) {
					return;
				}
			}

			$path = self::part_path( $state, $part_number );
			clearstatcache( true, $path );

			$state['files'][] = array(
				'internal' => $internal,
				'name'     => self::download_name( $state, $part_number ),
				'count'    => (int) $count,
				'bytes'    => is_file( $path ) ? (int) filesize( $path ) : 0,
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
			$space = self::check_temp_space( $state['dir'] );
			if ( is_wp_error( $space ) ) {
				return $space;
			}
			$buffer    = array_values( $buffer );
			$file_size = TisaCase_Exporter::file_size();

			while ( ! empty( $buffer ) ) {
				$part  = (int) $state['current_file'];
				$count = (int) $state['current_count'];

				if ( $count >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file']  = $part + 1;
					$state['current_count'] = 0;
					$state['current_bytes'] = 0;
					continue;
				}

				$space  = $file_size - $count;
				$take   = array_slice( $buffer, 0, $space );
				$buffer = array_slice( $buffer, $space );

				$path = self::part_path( $state, $part );

				if ( ! isset( $state['current_bytes'] ) ) {
					clearstatcache( true, $path );
					$state['current_bytes'] = is_file( $path ) ? (int) filesize( $path ) : 0;
				}

				$payload = ( $count > 0 ? "\n" : '' ) . implode( "\n", $take );
				$written = @file_put_contents( $path, $payload, FILE_APPEND | LOCK_EX );

				if ( false === $written || (int) $written !== strlen( $payload ) ) {
					return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}

				$state['current_bytes'] = (int) $state['current_bytes'] + (int) $written;
				$state['current_count'] = $count + count( $take );

				if ( (int) $state['current_count'] >= $file_size ) {
					self::mark_part_complete( $state, $part, $file_size );
					$state['current_file']  = $part + 1;
					$state['current_count'] = 0;
					$state['current_bytes'] = 0;
				}
			}

			return true;
		}

		/** حالت یکتاسازی: خطوط «کلید + تب + ردیف» در فایل کاری جمع می‌شوند. */
		public static function flush_working( array $lines, &$state ) {
			$space = self::check_temp_space( $state['dir'] );
			if ( is_wp_error( $space ) ) {
				return $space;
			}
			if ( empty( $lines ) ) {
				return true;
			}

			$path = self::working_path( $state );
			$done = isset( $state['working_count'] ) ? (int) $state['working_count'] : 0;

			if ( ! isset( $state['working_bytes'] ) ) {
				clearstatcache( true, $path );
				$state['working_bytes'] = is_file( $path ) ? (int) filesize( $path ) : 0;
			}

			$payload = ( $done > 0 ? "\n" : '' ) . implode( "\n", $lines );
			$written = @file_put_contents( $path, $payload, FILE_APPEND | LOCK_EX );

			if ( false === $written || (int) $written !== strlen( $payload ) ) {
				return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت روی سرور ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			$state['working_count'] = $done + count( $lines );
			$state['working_bytes'] = (int) $state['working_bytes'] + (int) $written;

			return true;
		}

		/** ثبت آخرین پارت نیمه‌پر در حالت عادی. */
		public static function finalize_last_part( &$state ) {
			if ( (int) $state['current_count'] > 0 ) {
				self::mark_part_complete( $state, (int) $state['current_file'], (int) $state['current_count'] );
			}
		}

		/**
		 * یک گام از یکتاسازی بیرونی را اجرا می‌کند.
		 * Sort، ادغام چندمرحله‌ای و تولید پارت‌ها در درخواست‌های محدودشده ادامه می‌یابند.
		 *
		 * @param array $state State (مرجع).
		 * @return true|false|WP_Error true در پایان، false برای ادامه در درخواست بعد.
		 */
		public static function finalize_dedup( &$state ) {
			$dir     = trailingslashit( $state['dir'] );
			$working = self::working_path( $state );

			if ( empty( $state['dedup_finalize'] ) || ! is_array( $state['dedup_finalize'] ) ) {
				foreach ( (array) glob( $dir . TisaCase_Exporter::PART_PREFIX . '*.tsv' ) as $stale ) {
					@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				foreach ( (array) glob( $dir . 'dedup-*' ) as $stale ) {
					@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				$state['files'] = array();
				$state['dedup_finalize'] = array(
					'phase'         => 'sort',
					'offset'        => 0,
					'chunk_index'   => 0,
					'runs'          => array(),
					'level_runs'    => array(),
					'next_runs'     => array(),
					'group_offset'  => 0,
					'level'         => 0,
					'job'           => null,
					'pending_delete'=> array(),
					'output_offset' => 0,
					'last_key'      => null,
					'unique'        => 0,
				);
			}

			$fd = &$state['dedup_finalize'];
			if ( ! empty( $fd['pending_delete'] ) ) {
				foreach ( $fd['pending_delete'] as $name ) {
					$path = self::dedup_path( $state, $name );
					if ( is_file( $path ) ) {
						@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				$fd['pending_delete'] = array();
			}

			$space = self::check_dedup_space( $state );
			if ( is_wp_error( $space ) ) {
				return $space;
			}

			if ( 'sort' === $fd['phase'] ) {
				if ( ! is_file( $working ) ) {
					$fd['phase'] = 'merge';
				} else {
					$in = @fopen( $working, 'rb' );
					if ( ! $in ) {
						return new WP_Error( 'read_failed', __( 'خواندن فایل موقت خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
					}
					if ( 0 !== (int) $fd['offset'] && 0 !== fseek( $in, (int) $fd['offset'] ) ) {
						fclose( $in );
						return new WP_Error( 'seek_failed', __( 'ادامهٔ مرتب‌سازی خروجی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
					}

					$lines = array();
					$bytes = 0;
					$line_limit = self::dedup_chunk_lines();
					$byte_limit = self::dedup_chunk_bytes();
					while ( count( $lines ) < $line_limit && $bytes < $byte_limit ) {
						$line = self::read_bounded_line( $in );
						if ( is_wp_error( $line ) ) {
							fclose( $in );
							return $line;
						}
						if ( false === $line ) {
							break;
						}
						$line = rtrim( $line, "\r\n" );
						if ( '' !== $line ) {
							$lines[] = $line;
							$bytes += strlen( $line ) + 1;
						}
					}
					$next_offset = (int) ftell( $in );
					$eof = feof( $in ) || $next_offset >= (int) filesize( $working );
					fclose( $in );

					if ( ! empty( $lines ) ) {
						$lines = array_values( array_unique( $lines ) );
						sort( $lines, SORT_STRING );
						$name = sprintf( 'dedup-sort-%06d.tmp', (int) $fd['chunk_index'] );
						$tmp  = self::dedup_path( $state, $name . '.work' );
						$path = self::dedup_path( $state, $name );
						$data = implode( "\n", $lines ) . "\n";
						if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) || ! @rename( $tmp, $path ) ) {
							@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							return new WP_Error( 'chunk_failed', __( 'آماده‌سازی خروجی (مرتب‌سازی) ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
						}
						$fd['runs'][] = array( 'name' => $name, 'bytes' => (int) filesize( $path ) );
						$fd['chunk_index']++;
					}
					$fd['offset'] = $next_offset;

					if ( $eof ) {
						$fd['phase']      = 'merge';
						$fd['level_runs'] = $fd['runs'];
						$fd['runs']       = array();
					}
					TisaCase_Exporter_Session::lock_refresh( $state['run_id'] );
					return false;
				}
			}

			if ( 'merge' === $fd['phase'] ) {
				if ( count( $fd['level_runs'] ) <= 1 ) {
					$fd['final_run'] = empty( $fd['level_runs'] ) ? null : $fd['level_runs'][0];
					$fd['phase']     = 'output';
					return false;
				}

				$fan_in = self::dedup_fan_in();
				if ( null === $fd['job'] ) {
					if ( (int) $fd['group_offset'] >= count( $fd['level_runs'] ) ) {
						$fd['level_runs']   = $fd['next_runs'];
						$fd['next_runs']    = array();
						$fd['group_offset'] = 0;
						$fd['level']++;
						return false;
					}
					$inputs = array_slice( $fd['level_runs'], (int) $fd['group_offset'], $fan_in );
					if ( count( $inputs ) < 2 ) {
						$fd['next_runs'][] = $inputs[0];
						$fd['group_offset']++;
						return false;
					}
					$name = sprintf( 'dedup-merge-%03d-%06d.tmp', (int) $fd['level'], (int) $fd['group_offset'] );
					$path = self::dedup_path( $state, $name );
					if ( is_file( $path ) ) {
						@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					$fd['job'] = array(
						'inputs'       => $inputs,
						'positions'    => array_fill( 0, count( $inputs ), 0 ),
						'output'       => $name,
						'output_bytes' => 0,
						'last_key'     => null,
					);
				}

				$merged = self::advance_merge_job( $state, $fd['job'] );
				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
				if ( $merged ) {
					$fd['next_runs'][] = array( 'name' => $fd['job']['output'], 'bytes' => (int) $fd['job']['output_bytes'] );
					$fd['pending_delete'] = array_map( function ( $run ) { return $run['name']; }, $fd['job']['inputs'] );
					$fd['job'] = null;
					$fd['group_offset'] += count( $fd['pending_delete'] );
				}
				TisaCase_Exporter_Session::lock_refresh( $state['run_id'] );
				return false;
			}

			if ( 'output' === $fd['phase'] ) {
				if ( empty( $fd['final_run'] ) ) {
					$state['duplicates'] = (int) $state['exported'];
					$state['exported']   = 0;
					$fd['phase']         = 'complete';
					return true;
				}
				$run_path = self::dedup_path( $state, $fd['final_run']['name'] );
				$in = @fopen( $run_path, 'rb' );
				if ( ! $in ) {
					return new WP_Error( 'read_failed', __( 'خواندن خروجی مرتب‌شده ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}
				if ( 0 !== (int) $fd['output_offset'] && 0 !== fseek( $in, (int) $fd['output_offset'] ) ) {
					fclose( $in );
					return new WP_Error( 'seek_failed', __( 'ادامهٔ تولید فایل نهایی ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}
				$original_output = array( $fd['output_offset'], $fd['last_key'], $fd['unique'] );
				$rows = array();
				$bytes = 0;
				while ( count( $rows ) < self::dedup_output_rows() && $bytes < self::dedup_chunk_bytes() ) {
					$line = self::read_bounded_line( $in );
					if ( is_wp_error( $line ) ) {
						fclose( $in );
						return $line;
					}
					if ( false === $line ) {
						break;
					}
					$line = rtrim( $line, "\r\n" );
					$bytes += strlen( $line ) + 1;
					$key = self::line_key( $line );
					if ( null !== $fd['last_key'] && $key === $fd['last_key'] ) {
						continue;
					}
					$fd['last_key'] = $key;
					$rows[] = self::line_row( $line );
					$fd['unique']++;
				}
				$fd['output_offset'] = (int) ftell( $in );
				$eof = feof( $in ) || $fd['output_offset'] >= (int) filesize( $run_path );
				fclose( $in );
				if ( ! empty( $rows ) ) {
					$parts_checkpoint = array(
						'files'         => $state['files'],
						'current_file'  => $state['current_file'],
						'current_count' => $state['current_count'],
						'current_bytes' => isset( $state['current_bytes'] ) ? $state['current_bytes'] : 0,
					);
					$flush = self::flush_parts( $rows, $state );
					if ( is_wp_error( $flush ) ) {
						$state['files'] = $parts_checkpoint['files'];
						$state['current_file'] = $parts_checkpoint['current_file'];
						$state['current_count'] = $parts_checkpoint['current_count'];
						$state['current_bytes'] = $parts_checkpoint['current_bytes'];
						$fd['output_offset'] = $original_output[0];
						$fd['last_key'] = $original_output[1];
						$fd['unique'] = $original_output[2];
						return $flush;
					}
				}
				if ( $eof ) {
					self::finalize_last_part( $state );
					$original = isset( $state['source_exported'] ) ? (int) $state['source_exported'] : (int) $state['exported'];
					$state['duplicates'] = max( 0, $original - (int) $fd['unique'] );
					$state['exported']   = (int) $fd['unique'];
					$fd['phase']         = 'complete';
					$fd['pending_delete'] = array( $fd['final_run']['name'] );
					return true;
				}
				TisaCase_Exporter_Session::lock_refresh( $state['run_id'] );
				return false;
			}

			return 'complete' === $fd['phase'];
		}

		/** Merge one bounded slice of a fan-in group. */
		private static function advance_merge_job( &$state, &$job ) {
			$original_job = $job;
			$handles = array();
			$heads = array();
			$head_starts = array();
			foreach ( $job['inputs'] as $i => $run ) {
				$handle = @fopen( self::dedup_path( $state, $run['name'] ), 'rb' );
				if ( ! $handle ) {
					TisaCase_Exporter_Storage::close_all( $handles );
					return new WP_Error( 'merge_failed', __( 'بازکردن تکه‌های مرتب‌شده ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}
				$handles[] = $handle;
				if ( 0 !== (int) $job['positions'][ $i ] && 0 !== fseek( $handle, (int) $job['positions'][ $i ] ) ) {
					TisaCase_Exporter_Storage::close_all( $handles );
					return new WP_Error( 'merge_failed', __( 'ادامهٔ ادغام تکه‌ها ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
				}
				$head_starts[ $i ] = (int) $job['positions'][ $i ];
				$heads[ $i ] = self::read_bounded_line( $handle );
				if ( is_wp_error( $heads[ $i ] ) ) {
					TisaCase_Exporter_Storage::close_all( $handles );
					$job = $original_job;
					return $heads[ $i ];
				}
				if ( false !== $heads[ $i ] ) {
					$heads[ $i ] = rtrim( $heads[ $i ], "\r\n" );
				}
			}

			$out = '';
			$processed = 0;
			$max_rows = self::dedup_merge_rows();
			$max_bytes = self::dedup_chunk_bytes();
			while ( $processed < $max_rows && strlen( $out ) < $max_bytes ) {
				$best = -1;
				$best_line = null;
				foreach ( $heads as $i => $line ) {
					if ( false === $line ) {
						continue;
					}
					if ( null === $best_line || strcmp( $line, $best_line ) < 0 ) {
						$best = $i;
						$best_line = $line;
					}
				}
				if ( $best < 0 ) {
					break;
				}
				$key = self::line_key( $best_line );
				if ( null === $job['last_key'] || $key !== $job['last_key'] ) {
					$out .= ( '' === $out ? '' : "\n" ) . $best_line;
					$job['last_key'] = $key;
				}
				$head_starts[ $best ] = (int) ftell( $handles[ $best ] );
				$heads[ $best ] = self::read_bounded_line( $handles[ $best ] );
				if ( is_wp_error( $heads[ $best ] ) ) {
					TisaCase_Exporter_Storage::close_all( $handles );
					$job = $original_job;
					return $heads[ $best ];
				}
				if ( false !== $heads[ $best ] ) {
					$heads[ $best ] = rtrim( $heads[ $best ], "\r\n" );
				}
				$processed++;
			}

			$all_done = true;
			foreach ( $heads as $i => $line ) {
				if ( false !== $line ) {
					$job['positions'][ $i ] = $head_starts[ $i ];
					$all_done = false;
				} else {
					$job['positions'][ $i ] = (int) ftell( $handles[ $i ] );
				}
			}
			TisaCase_Exporter_Storage::close_all( $handles );

			if ( '' !== $out ) {
				$append = self::append_checkpoint( self::dedup_path( $state, $job['output'] ), (int) $job['output_bytes'], $out . "\n" );
				if ( is_wp_error( $append ) ) {
					$job = $original_job;
					return $append;
				}
				$job['output_bytes'] += strlen( $out ) + 1;
			}
			return $all_done;
		}

		private static function append_checkpoint( $path, $expected, $data ) {
			$handle = @fopen( $path, 'c+b' );
			if ( ! $handle ) {
				return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت ادغام ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			$actual = (int) fstat( $handle )['size'];
			if ( $actual < $expected ) {
				fclose( $handle );
				return new WP_Error( 'checkpoint_failed', __( 'فایل موقت ادغام ناقص است؛ اجرای خروجی را از ابتدا شروع کنید.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			if ( $actual > $expected && ! ftruncate( $handle, $expected ) ) {
				fclose( $handle );
				return new WP_Error( 'checkpoint_failed', __( 'بازیابی نقطهٔ ادامهٔ ادغام ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			if ( 0 !== fseek( $handle, $expected ) ) {
				fclose( $handle );
				return new WP_Error( 'seek_failed', __( 'ادامهٔ نوشتن فایل ادغام ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			$written = fwrite( $handle, $data );
			fflush( $handle );
			fclose( $handle );
			if ( false === $written || $written !== strlen( $data ) ) {
				return new WP_Error( 'write_failed', __( 'نوشتن فایل موقت ادغام کامل نشد.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			return true;
		}

		private static function read_bounded_line( $handle ) {
			$max = self::dedup_max_line_bytes();
			$line = fgets( $handle, $max + 2 );
			if ( false === $line ) {
				return false;
			}
			$content = rtrim( $line, "\r\n" );
			if ( strlen( $content ) > $max || ( strlen( $line ) > $max && ! feof( $handle ) && "\n" !== substr( $line, -1 ) && "\r" !== substr( $line, -1 ) ) ) {
				return new WP_Error( 'row_too_large', __( 'یک ردیف خروجی از حد ایمن پردازش Dedup بزرگ‌تر است؛ ستون‌های متنی را محدود کنید یا Dedup را خاموش کنید.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			return $line;
		}

		private static function dedup_path( $state, $name ) {
			return trailingslashit( $state['dir'] ) . basename( (string) $name );
		}

		private static function check_dedup_space( $state ) {
			return self::check_temp_space( $state['dir'] );
		}

		private static function check_temp_space( $dir ) {
			$free = @disk_free_space( $dir );
			if ( false !== $free && $free < self::dedup_min_free_bytes() ) {
				return new WP_Error( 'disk_low', __( 'فضای آزاد دیسک برای فایل‌های موقت خروجی کم است. پس از آزادکردن فضا، اجرای فعلی را ادامه دهید.', TisaCase_Exporter::TEXT_DOMAIN ) );
			}
			return true;
		}

		public static function dedup_chunk_lines() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_chunk_lines', 5000 );
			return max( 100, min( 10000, $value ) );
		}

		public static function dedup_merge_rows() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_merge_rows', 1000 );
			return max( 100, min( 5000, $value ) );
		}

		public static function dedup_output_rows() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_output_rows', 1000 );
			return max( 100, min( 5000, $value ) );
		}

		public static function dedup_fan_in() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_fan_in', 8 );
			return max( 2, min( 8, $value ) );
		}

		private static function dedup_chunk_bytes() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_chunk_bytes', 8 * 1024 * 1024 );
			return max( 256 * 1024, min( 16 * 1024 * 1024, $value ) );
		}

		private static function dedup_max_line_bytes() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_max_line_bytes', 1024 * 1024 );
			return max( 64 * 1024, min( 4 * 1024 * 1024, $value ) );
		}

		private static function dedup_min_free_bytes() {
			$value = (int) apply_filters( 'tisacase_exporter_dedup_min_free_bytes', 64 * 1024 * 1024 );
			return max( 8 * 1024 * 1024, $value );
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
