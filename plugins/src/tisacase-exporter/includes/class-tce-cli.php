<?php
/**
 * دستورهای WP-CLI:
 *
 *   wp tisacase export [<section>] [--format=txt|csv|xls|json|pdf] [--columns=a,b]
 *                      [--dedup[=key]] [--file=<path>|-] [--filter key=value]...
 *   wp tisacase export-phones   (نام قدیمی، معادل wp tisacase export phones)
 *
 * سرعت‌ترین راه خروجی گرفتن برای فروشگاه‌های بزرگ؛ برای بخش شماره‌ها هیچ حافظه‌ای
 * با حجم داده رشد نمی‌کند و یکتاسازی (در صورت درخواست) با مرتب‌سازی تکه‌ای انجام می‌شود.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Cli' ) ) {

	final class TisaCase_Exporter_Cli {

		/**
		 * اجرای خروجی از خط فرمان.
		 *
		 * @param array $args  آرگومان‌های ترتیبی ([۰] = بخش).
		 * @param array $assoc آرگومان‌های کلیددار.
		 */
		public static function cli_export( $args, $assoc ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				\WP_CLI::error( 'WooCommerce is not active.' );
				return;
			}

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				\WP_CLI::error( 'Run this command with --user=<admin>.' );
				return;
			}

			$section = ! empty( $args[0] ) ? sanitize_key( (string) $args[0] ) : 'phones';
			$module  = TisaCase_Exporter_Modules::get( $section );

			if ( null === $module ) {
				$ids = array();

				foreach ( TisaCase_Exporter_Modules::all() as $id => $item ) {
					$ids[] = $id;
				}

				\WP_CLI::error( sprintf( 'Unknown section "%s". Available: %s', $section, implode( ', ', $ids ) ) );
				return;
			}

			$class   = $module['class'];
			$filters = call_user_func( array( $class, 'normalize_filters' ), self::filters( $assoc ) );
			$keys    = self::columns( $assoc, $class );
			$defs    = call_user_func( array( $class, 'columns_def' ), $keys );
			$format  = self::format( $assoc, $module );
			$dedup   = self::dedup( $assoc, $module );

			$labels = array();
			$cols   = array();

			foreach ( $defs as $col ) {
				$labels[] = isset( $col['label'] ) ? (string) $col['label'] : '';
				$cols[]   = isset( $col['key'] ) ? (string) $col['key'] : '';
			}

			if ( 'txt' !== $format && empty( $labels ) ) {
				$labels = array( 'value' );
			}

			$target = self::target( $assoc, $section, $format );

			if ( '-' === $target ) {
				$handle = fopen( 'php://stdout', 'wb' );
			} else {
				$handle = @fopen( $target, 'wb' );
			}

			if ( ! $handle ) {
				\WP_CLI::error( sprintf( 'Cannot open output for writing: %s', $target ) );
				return;
			}

			TisaCase_Exporter_Format::stream_open(
				$format,
				$labels,
				$cols,
				$handle,
				array(
					'title'   => isset( $module['label'] ) ? (string) $module['label'] : '',
					'site'    => (string) get_bloginfo( 'name' ),
					'date'    => (string) date_i18n( 'j F Y' ),
					'columns' => array_values( $defs ),
				)
			);

			\WP_CLI::log( sprintf( 'Exporting "%s" as %s%s...', $section, strtoupper( $format ), '' !== $dedup ? sprintf( ' (dedup by %s)', $dedup ) : '' ) );

			$cursor    = method_exists( $class, 'start_cursor' ) ? call_user_func( array( $class, 'start_cursor' ) ) : 0;
			$processed = 0;
			$exported  = 0;
			$skipped   = 0;
			$forced    = null;

			if ( '' !== $dedup ) {
				// یکتاسازی: خطوط به‌شکل «کلید + تب + ردیف» در یک پوشهٔ موقت جمع می‌شوند و در پایان مرتب می‌شوند.
				$temp = trailingslashit( get_temp_dir() ) . 'tisacase-cli-' . substr( md5( uniqid( '', true ) ), 0, 12 ) . '/';

				if ( ! wp_mkdir_p( $temp ) ) {
					fclose( $handle );
					\WP_CLI::error( 'Cannot create a temporary directory for dedup.' );
					return;
				}

				$forced = array(
					'dir'           => $temp,
					'module'        => $section,
					'format'        => $format,
					'started_at'    => time(),
					'files'         => array(),
					'current_file'  => 1,
					'current_count' => 0,
					'working_count' => 0,
					'exported'      => 0,
					'duplicates'    => 0,
					'run_id'        => 'cli',
				);
			}

			while ( true ) {
				$page = call_user_func( array( $class, 'fetch' ), $filters, $cursor, TisaCase_Exporter::CLI_BATCH, $cols );

				if ( ! is_array( $page ) || ! isset( $page['rows'] ) || ! is_array( $page['rows'] ) ) {
					fclose( $handle );
					\WP_CLI::error( 'Database read failed.' );
					return;
				}

				$buffer = array();

				foreach ( $page['rows'] as $row ) {
					$processed++;

					$tsv = TisaCase_Exporter_Format::row_to_tsv( $row, $defs );

					if ( '' !== (string) $module['skip_when'] && '' === TisaCase_Exporter_Format::column_value( $row, (string) $module['skip_when'], $defs ) ) {
						$skipped++;
						continue;
					}

					if ( '' === trim( str_replace( "\t", '', $tsv ) ) ) {
						$skipped++;
						continue;
					}

					$exported++;

					if ( '' !== $dedup ) {
						$key      = TisaCase_Exporter_Format::column_value( $row, $dedup, $defs );
						$buffer[] = TisaCase_Exporter_Pipeline::make_line( $key, $tsv );
					} else {
						TisaCase_Exporter_Format::stream_row( TisaCase_Exporter_Format::split_tsv( $tsv ), $cols );
					}
				}

				if ( ! empty( $buffer ) ) {
					$flush = TisaCase_Exporter_Pipeline::flush_working( $buffer, $forced );

					if ( is_wp_error( $flush ) ) {
						fclose( $handle );
						\WP_CLI::error( $flush->get_error_message() );
						return;
					}
				}

				$cursor = isset( $page['cursor'] ) ? $page['cursor'] : $cursor;

				if ( 0 === $processed % 50000 && $processed > 0 ) {
					\WP_CLI::log( sprintf( '  %d rows processed...', $processed ) );
				}

				if ( ! empty( $page['done'] ) ) {
					break;
				}

				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 );
				}
			}

			$unique = $exported;

			if ( '' !== $dedup ) {
				$finalize = TisaCase_Exporter_Pipeline::finalize_dedup( $forced );

				if ( is_wp_error( $finalize ) ) {
					fclose( $handle );
					\WP_CLI::error( $finalize->get_error_message() );
					return;
				}

				$unique = (int) $forced['exported'];

				foreach ( (array) $forced['files'] as $file ) {
					$path = trailingslashit( $forced['dir'] ) . $file['internal'];
					$in   = @fopen( $path, 'rb' );

					if ( ! $in ) {
						continue;
					}

					while ( false !== ( $line = fgets( $in, 1048576 ) ) ) {
						$line = rtrim( $line, "\r\n" );

						if ( '' === $line ) {
							continue;
						}

						TisaCase_Exporter_Format::stream_row( TisaCase_Exporter_Format::split_tsv( $line ), $cols );
					}

					fclose( $in );
				}

				TisaCase_Exporter_Storage::delete_directory( $forced['dir'] );
			}

			TisaCase_Exporter_Format::stream_close();

			if ( 'php://stdout' !== $target ) {
				fclose( $handle );
			}

			$message = sprintf( 'Exported %d rows', $unique );

			if ( $unique !== $exported ) {
				$message .= sprintf( ' (%d duplicates removed)', $exported - $unique );
			}

			if ( $skipped > 0 ) {
				$message .= sprintf( ', %d skipped', $skipped );
			}

			$message .= sprintf( ' of %d processed', $processed );

			if ( 'php://stdout' !== $target ) {
				$message .= sprintf( ' to %s', $target );
			}

			\WP_CLI::success( $message );
		}

		/* -----------------------------------------------------------------
		 * کمکی‌ها
		 * ----------------------------------------------------------------- */

		/** فیلترها از آرگومان‌های --filter key=value (تکراری مجاز). */
		private static function filters( $assoc ) {
			$out = array();

			if ( empty( $assoc['filter'] ) ) {
				return $out;
			}

			foreach ( (array) $assoc['filter'] as $pair ) {
				if ( ! is_string( $pair ) || false === strpos( $pair, '=' ) ) {
					continue;
				}

				list( $key, $value ) = explode( '=', $pair, 2 );
				$key                 = sanitize_key( trim( $key ) );

				if ( '' === $key ) {
					continue;
				}

				$parts            = array_map( 'trim', explode( ',', $value ) );
				$out[ $key ]      = ( 1 === count( $parts ) ) ? $parts[0] : $parts;
			}

			return $out;
		}

		/** ستون‌های درخواستی (پیش‌فرض: ستون‌های پیشنهادی همان بخش و قالب). */
		private static function columns( $assoc, $class ) {
			if ( ! empty( $assoc['columns'] ) && is_string( $assoc['columns'] ) ) {
				$keys = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $assoc['columns'] ) ) ) );

				if ( ! empty( $keys ) ) {
					return array_values( $keys );
				}
			}

			return call_user_func( array( $class, 'sanitize_columns' ), array() );
		}

		/** قالب درخواستی. */
		private static function format( $assoc, array $module ) {
			$format = ! empty( $assoc['format'] ) ? sanitize_key( (string) $assoc['format'] ) : '';

			if ( TisaCase_Exporter_Format::is_valid( $format ) ) {
				return $format;
			}

			$default = isset( $module['default_format'] ) ? (string) $module['default_format'] : 'csv';

			return TisaCase_Exporter_Format::is_valid( $default ) ? $default : 'csv';
		}

		/** کلید یکتاسازی: --dedup (بدون مقدار = کلید پیشنهادی بخش) یا --dedup=<key>. */
		private static function dedup( $assoc, array $module ) {
			if ( ! isset( $assoc['dedup'] ) ) {
				return '';
			}

			$keys = isset( $module['dedup'] ) && is_array( $module['dedup'] ) ? array_keys( $module['dedup'] ) : array();
			$want = is_string( $assoc['dedup'] ) ? sanitize_key( $assoc['dedup'] ) : '';

			if ( '' !== $want ) {
				if ( ! in_array( $want, $keys, true ) ) {
					\WP_CLI::warning( sprintf( 'Section "%s" has no dedup key "%s"; using "%s".', $module['id'], $want, implode( '/', $keys ) ) );
					return (string) reset( $keys );
				}

				return $want;
			}

			return (string) reset( $keys );
		}

		/** مقصد خروجی: --file، وگرنه خروجی استاندارد (برای بخش شماره‌ها همان نام قدیمی). */
		private static function target( $assoc, $section, $format ) {
			if ( ! empty( $assoc['file'] ) && is_string( $assoc['file'] ) ) {
				return $assoc['file'];
			}

			if ( 'phones' === $section && 'txt' === $format ) {
				return 'phones-989.txt'; // سازگاری با نسخهٔ ۱.x.
			}

			return '-';
		}
	}
}
