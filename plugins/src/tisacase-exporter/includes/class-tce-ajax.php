<?php
/**
 * اندپوینت‌های AJAX: شروع، پردازش Batch، لغو، پیش‌نمایش و پاک‌کردن تاریخچه.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Ajax' ) ) {

	final class TisaCase_Exporter_Ajax {

		/** شروع یک جلسهٔ خروجی جدید. */
		public static function ajax_start() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			$module = self::requested_module();

			if ( null === $module ) {
				wp_send_json_error( array( 'message' => __( 'بخش خروجی معتبر نیست.', TisaCase_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			// اگر پردازشی در جریان است، شروع جدید ممنوع است (تا پوشهٔ فعال پاک نشود).
			if ( TisaCase_Exporter_Session::lock_exists() ) {
				wp_send_json_error( array( 'message' => __( 'یک خروجی هم‌اکنون در تب دیگری در حال اجراست. ابتدا آن را کامل کنید یا دکمه «توقف و پاک‌سازی» را بزنید (اگر تب بسته شده، حداکثر ۲ دقیقه صبر کنید).', TisaCase_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			$run_id = strtolower( wp_generate_password( 16, false ) );

			if ( ! TisaCase_Exporter_Session::lock_acquire( $run_id ) ) {
				wp_send_json_error( array( 'message' => __( 'شروع خروجی جدید ناموفق بود؛ لطفاً دوباره تلاش کنید.', TisaCase_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			$dir = TisaCase_Exporter_Storage::create_clean_user_dir( $run_id );

			if ( is_wp_error( $dir ) ) {
				TisaCase_Exporter_Session::lock_release_if( $run_id );
				wp_send_json_error( array( 'message' => $dir->get_error_message() ), 500 );
			}

			$class    = $module['class'];
			$filters  = call_user_func( array( $class, 'normalize_filters' ), self::raw_filters() );
			$columns  = call_user_func( array( $class, 'sanitize_columns' ), self::raw_columns() );
			$defs     = call_user_func( array( $class, 'columns_def' ), $columns );
			$format   = self::requested_format( $module );
			$dedup    = self::requested_dedup( $module );
			$total    = (int) call_user_func( array( $class, 'count' ), $filters );
			$cursor   = method_exists( $class, 'start_cursor' ) ? call_user_func( array( $class, 'start_cursor' ) ) : 0;

			$state = array(
				'run_id'        => $run_id,
				'dir'           => $dir,
				'module'        => $module['id'],
				'module_title'  => isset( $module['title'] ) ? (string) $module['title'] : $module['id'],
				'cursor'        => $cursor,
				'processed'     => 0,
				'exported'      => 0,
				'skipped'       => 0,
				'duplicates'    => 0,
				'total'         => $total,
				'columns'       => $defs,
				'format'        => $format,
				'dedup'         => $dedup,
				'filters'       => $filters,
				'summary'       => call_user_func( array( $class, 'filter_summary' ), $filters ),
				'working_count' => 0,
				'current_file'  => 1,
				'current_count' => 0,
				'files'         => array(),
				'done'          => false,
				'storage'       => call_user_func( array( $class, 'storage_label' ) ),
				'started_at'    => time(),
			);

			TisaCase_Exporter_Session::delete_state();
			TisaCase_Exporter_Session::save_state( $state );

			wp_send_json_success( self::response_payload( $state, true ) );
		}

		/** یک گام پردازش (Batch). */
		public static function ajax_process() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
			$state  = TisaCase_Exporter_Session::get_state();

			if ( empty( $state ) || empty( $state['dir'] ) || empty( $state['run_id'] ) || empty( $state['module'] ) ) {
				wp_send_json_error( array( 'message' => __( 'جلسه خروجی پیدا نشد. دوباره «شروع خروجی» را بزنید.', TisaCase_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			// run_id ناهماهنگ = جلسه‌ای جدید در تب دیگری شروع شده؛ این حلقه باید متوقف شود.
			if ( '' !== $run_id && $run_id !== $state['run_id'] ) {
				wp_send_json_error( array( 'message' => __( 'جلسه خروجی جدیدی شروع شده است؛ این تب متوقف شد.', TisaCase_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			if ( ! empty( $state['done'] ) ) {
				wp_send_json_success( self::response_payload( $state, true ) );
			}

			if ( TisaCase_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
				TisaCase_Exporter_Session::cleanup_session( $state );
				wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
			}

			if ( ! TisaCase_Exporter_Session::lock_acquire( $state['run_id'] ) ) {
				wp_send_json_error( array( 'message' => __( 'یک پردازش هم‌اکنون در تب دیگری در جریان است.', TisaCase_Exporter::TEXT_DOMAIN ) ), 409 );
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 );
			}

			$module = TisaCase_Exporter_Modules::get( $state['module'] );

			if ( null === $module ) {
				wp_send_json_error( array( 'message' => __( 'بخش این خروجی دیگر فعال نیست.', TisaCase_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			$class    = $module['class'];
			$cursors  = $state['cursor'];
			$limit    = TisaCase_Exporter::batch_size();
			$column_keys = array();

			foreach ( (array) $state['columns'] as $col ) {
				$column_keys[] = isset( $col['key'] ) ? (string) $col['key'] : '';
			}

			$page = call_user_func( array( $class, 'fetch' ), $state['filters'], $cursors, $limit, $column_keys );

			if ( ! is_array( $page ) || ! isset( $page['rows'] ) || ! is_array( $page['rows'] ) ) {
				wp_send_json_error( array( 'message' => __( 'خواندن داده‌ها ناموفق بود.', TisaCase_Exporter::TEXT_DOMAIN ) ), 500 );
			}

			$skip_when = isset( $module['skip_when'] ) ? (string) $module['skip_when'] : '';
			$buffer    = array();

			foreach ( $page['rows'] as $row ) {
				$state['processed']++;

				$tsv = TisaCase_Exporter_Format::row_to_tsv( $row, $state['columns'] );

				if ( '' !== $skip_when ) {
					$value = TisaCase_Exporter_Format::column_value( $row, $skip_when, $state['columns'] );

					if ( '' === $value ) {
						$state['skipped']++;
						continue;
					}
				}

				if ( '' === trim( str_replace( "\t", '', $tsv ) ) ) {
					$state['skipped']++;
					continue;
				}

				$state['exported']++;

				if ( '' !== (string) $state['dedup'] ) {
					$key      = TisaCase_Exporter_Format::column_value( $row, (string) $state['dedup'], $state['columns'] );
					$buffer[] = TisaCase_Exporter_Pipeline::make_line( $key, $tsv );
				} else {
					$buffer[] = $tsv;
				}
			}

			if ( ! empty( $buffer ) ) {
				$flush = ( '' !== (string) $state['dedup'] )
					? TisaCase_Exporter_Pipeline::flush_working( $buffer, $state )
					: TisaCase_Exporter_Pipeline::flush_parts( $buffer, $state );

				if ( is_wp_error( $flush ) ) {
					if ( TisaCase_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
						TisaCase_Exporter_Session::cleanup_session( $state );
						wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
					}

					// State ذخیره می‌شود تا «ادامه» از همین‌جا ممکن باشد.
					TisaCase_Exporter_Session::save_state( $state );
					wp_send_json_error( array( 'message' => $flush->get_error_message() ), 500 );
				}
			}

			$state['cursor'] = isset( $page['cursor'] ) ? $page['cursor'] : $state['cursor'];

			if ( ! empty( $page['done'] ) ) {
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 300 );
				}

				$finalize = ( '' !== (string) $state['dedup'] )
					? TisaCase_Exporter_Pipeline::finalize_dedup( $state )
					: TisaCase_Exporter_Pipeline::finalize_last_part( $state );

				if ( is_wp_error( $finalize ) ) {
					if ( TisaCase_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
						TisaCase_Exporter_Session::cleanup_session( $state );
						wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
					}

					TisaCase_Exporter_Session::save_state( $state );
					wp_send_json_error( array( 'message' => $finalize->get_error_message() ), 500 );
				}

				$state['done']   = true;
				$state['total']  = max( (int) $state['processed'], (int) call_user_func( array( $class, 'count' ), $state['filters'] ) );

				TisaCase_Exporter_Session::lock_release_if( $state['run_id'] );
				self::record_history( $state );
			}

			if ( TisaCase_Exporter_Session::cancel_requested( $state['run_id'] ) ) {
				TisaCase_Exporter_Session::cleanup_session( $state );
				wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
			}

			// تازه‌سازی mtime پوشه تا اسکن کرونی به جلسهٔ فعال دست نزند.
			if ( is_string( $state['dir'] ) ) {
				@touch( $state['dir'] );
			}

			if ( ! TisaCase_Exporter_Session::save_state_guarded( $state ) ) {
				/*
				 * جلسه در همین لحظه لغو شده یا جلسهٔ جدیدی State را به دست گرفته است.
				 * به State/قفل فعلی دست نمی‌زنیم؛ فقط پوشهٔ همین run را پاک می‌کنیم.
				 */
				if ( ! empty( $state['dir'] ) && is_string( $state['dir'] ) ) {
					TisaCase_Exporter_Storage::delete_directory( $state['dir'] );
				}
				TisaCase_Exporter_Session::lock_release_if( $state['run_id'] );
				wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
			}

			wp_send_json_success( self::response_payload( $state, ! empty( $state['done'] ) ) );
		}

		/** پیش‌نمایش: PREVIEW_ROWS ردیف اول با همان فیلتر/ستون‌ها (بدون ساخت فایل). */
		public static function ajax_preview() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			$module = self::requested_module();

			if ( null === $module ) {
				wp_send_json_error( array( 'message' => __( 'بخش خروجی معتبر نیست.', TisaCase_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			$class   = $module['class'];
			$filters = call_user_func( array( $class, 'normalize_filters' ), self::raw_filters() );
			$keys    = call_user_func( array( $class, 'sanitize_columns' ), self::raw_columns() );
			$defs    = call_user_func( array( $class, 'columns_def' ), $keys );
			$cursor  = method_exists( $class, 'start_cursor' ) ? call_user_func( array( $class, 'start_cursor' ) ) : 0;
			$page    = call_user_func( array( $class, 'fetch' ), $filters, $cursor, TisaCase_Exporter::PREVIEW_ROWS, $keys );

			$rows = array();

			foreach ( (array) ( isset( $page['rows'] ) ? $page['rows'] : array() ) as $row ) {
				$line = array();
				foreach ( $defs as $col ) {
					$line[] = TisaCase_Exporter_Format::value( isset( $row[ $col['key'] ] ) ? $row[ $col['key'] ] : '', $col['type'] );
				}
				$rows[] = $line;
			}

			$labels = array();
			$keys   = array();
			foreach ( $defs as $col ) {
				$labels[] = $col['label'];
				$keys[]   = $col['key'];
			}

			wp_send_json_success(
				array(
					'columns' => $labels,
					'keys'    => $keys,
					'rows'    => $rows,
					'total'   => (int) call_user_func( array( $class, 'count' ), $filters ),
					'preview' => TisaCase_Exporter::PREVIEW_ROWS,
				)
			);
		}

		/**
		 * عیب‌یابی شمارش: چرا عدد خروجی این عدد است؟
		 * خروجی: نردبان فیلترها (سهم هر فیلتر)، شمارش هر وضعیت و آمار موبایل.
		 */
		public static function ajax_diagnose() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			$module = self::requested_module();

			if ( null === $module ) {
				wp_send_json_error( array( 'message' => __( 'بخش خروجی معتبر نیست.', TisaCase_Exporter::TEXT_DOMAIN ) ), 400 );
			}

			$class   = $module['class'];
			$filters = call_user_func( array( $class, 'normalize_filters' ), self::raw_filters() );

			wp_send_json_success( TisaCase_Exporter_Diagnostics::report( $module['id'], $filters ) );
		}

		/** لغو خروجی جاری + پاک‌سازی فوری. */
		public static function ajax_cancel() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			$run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
			$state  = TisaCase_Exporter_Session::get_state();

			$matches = ! empty( $state )
				&& ! empty( $state['run_id'] )
				&& ( '' === $run_id || $run_id === $state['run_id'] );

			if ( ! $matches ) {
				TisaCase_Exporter_Session::clear_cancel_flag( $run_id );

				if ( empty( $state ) ) {
					TisaCase_Exporter_Session::lock_release();
				}

				wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
			}

			// ۱) پرچم لغو: هر درخواست Processِ در حال اجرا در اولین ایستگاه متوقف می‌شود.
			TisaCase_Exporter_Session::set_cancel_flag( $state['run_id'] );
			// ۲) پاک‌سازی همین حالا (Idempotent).
			TisaCase_Exporter_Session::cleanup_session( $state );

			wp_send_json_success( array( 'cancelled' => true, 'history' => TisaCase_Exporter_History::for_display() ) );
		}

		/** پاک‌کردن کل تاریخچه + فایل‌های باقی‌مانده. */
		public static function ajax_history_clear() {
			TisaCase_Exporter_Session::ensure_access();
			check_ajax_referer( TisaCase_Exporter::NONCE_ACTION, 'nonce' );

			TisaCase_Exporter_History::clear( true );

			wp_send_json_success( array( 'history' => array() ) );
		}

		/* -----------------------------------------------------------------
		 * کمکی‌ها
		 * ----------------------------------------------------------------- */

		/** بخش درخواستی از POST. */
		private static function requested_module() {
			$id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';

			return '' === $id ? null : TisaCase_Exporter_Modules::get( $id );
		}

		/** فیلترهای خام POST. */
		private static function raw_filters() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce در ابتدای هر اندپوینت بررسی شده است.
			$raw = isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array();

			return is_array( $raw ) ? $raw : array();
		}

		/** ستون‌های خام POST. */
		private static function raw_columns() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = isset( $_POST['columns'] ) ? wp_unslash( $_POST['columns'] ) : array();

			return is_array( $raw ) ? $raw : array();
		}

		/** قالب درخواستی (با fallback به پیش‌فرض بخش). */
		private static function requested_format( array $module ) {
			$format = isset( $_POST['format'] ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( TisaCase_Exporter_Format::is_valid( $format ) ) {
				return $format;
			}

			$default = isset( $module['default_format'] ) ? (string) $module['default_format'] : 'csv';

			return TisaCase_Exporter_Format::is_valid( $default ) ? $default : 'csv';
		}

		/** کلید یکتاسازی درخواستی (فقط از کلیدهای مجاز همان بخش). */
		private static function requested_dedup( array $module ) {
			$dedup = isset( $_POST['dedup'] ) ? sanitize_key( wp_unslash( $_POST['dedup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$keys  = isset( $module['dedup'] ) && is_array( $module['dedup'] ) ? $module['dedup'] : array();

			return ( '' !== $dedup && array_key_exists( $dedup, $keys ) ) ? $dedup : '';
		}

		/** ثبت اجرا در تاریخچه (برای دانلود دوباره/اجرای مجدد). */
		private static function record_history( array $state ) {
			$files = array();

			foreach ( (array) $state['files'] as $file ) {
				if ( empty( $file['internal'] ) ) {
					continue;
				}
				$files[] = array(
					'internal' => (string) $file['internal'],
					'name'     => isset( $file['name'] ) ? (string) $file['name'] : (string) $file['internal'],
					'count'    => isset( $file['count'] ) ? (int) $file['count'] : 0,
				);
			}

			TisaCase_Exporter_History::add(
				array(
					'run_id'       => (string) $state['run_id'],
					'module'       => (string) $state['module'],
					'module_title' => isset( $state['module_title'] ) ? (string) $state['module_title'] : (string) $state['module'],
					'format'       => (string) $state['format'],
					'extension'    => TisaCase_Exporter_Format::ext( (string) $state['format'] ),
					'columns'      => (array) $state['columns'],
					'filters'      => (array) $state['filters'],
					'summary'      => (array) $state['summary'],
					'dedup'        => (string) $state['dedup'],
					'rows'         => (int) $state['exported'],
					'files'        => $files,
					'dir'          => TisaCase_Exporter_Storage::relative_name( (string) $state['dir'] ),
					'created_at'   => time(),
				)
			);
		}

		/**
		 * ساخت پاسخ JSON برای UI.
		 *
		 * @param array $state        State.
		 * @param bool  $with_history آیا تاریخچه هم برگردد؟
		 * @return array
		 */
		public static function response_payload( $state, $with_history = false ) {
			$files = array();

			if ( ! empty( $state['files'] ) && is_array( $state['files'] ) ) {
				foreach ( $state['files'] as $file ) {
					if ( empty( $file['internal'] ) ) {
						continue;
					}

					$files[] = array(
						'name'  => isset( $file['name'] ) ? (string) $file['name'] : (string) $file['internal'],
						'count' => isset( $file['count'] ) ? (int) $file['count'] : 0,
						'url'   => add_query_arg(
							array(
								'action'   => TisaCase_Exporter::DOWNLOAD,
								'run'      => isset( $state['run_id'] ) ? (string) $state['run_id'] : '',
								'part'     => (string) $file['internal'],
								'_wpnonce' => wp_create_nonce( TisaCase_Exporter::DOWNLOAD ),
							),
							admin_url( 'admin-post.php' )
						),
					);
				}
			}

			$payload = array(
				'run_id'      => isset( $state['run_id'] ) ? (string) $state['run_id'] : '',
				'module'      => isset( $state['module'] ) ? (string) $state['module'] : '',
				'processed'   => isset( $state['processed'] ) ? (int) $state['processed'] : 0,
				'total'       => isset( $state['total'] ) ? (int) $state['total'] : 0,
				'exported'    => isset( $state['exported'] ) ? (int) $state['exported'] : 0,
				'skipped'     => isset( $state['skipped'] ) ? (int) $state['skipped'] : 0,
				'duplicates'  => isset( $state['duplicates'] ) ? (int) $state['duplicates'] : 0,
				'dedup'       => isset( $state['dedup'] ) ? (string) $state['dedup'] : '',
				'format'      => isset( $state['format'] ) ? (string) $state['format'] : '',
				'done'        => ! empty( $state['done'] ),
				'storage'     => isset( $state['storage'] ) ? (string) $state['storage'] : '',
				'columns'     => isset( $state['columns'] ) ? array_values( (array) $state['columns'] ) : array(),
				'files'       => $files,
				'zip_url'     => '',
			);

			if ( count( $files ) > 1 && class_exists( 'ZipArchive' ) ) {
				$payload['zip_url'] = add_query_arg(
					array(
						'action'   => TisaCase_Exporter::DOWNLOAD_ZIP,
						'run'      => isset( $state['run_id'] ) ? (string) $state['run_id'] : '',
						'_wpnonce' => wp_create_nonce( TisaCase_Exporter::DOWNLOAD_ZIP ),
					),
					admin_url( 'admin-post.php' )
				);
			}

			if ( $with_history ) {
				$payload['history'] = TisaCase_Exporter_History::for_display();
			}

			return $payload;
		}
	}
}
