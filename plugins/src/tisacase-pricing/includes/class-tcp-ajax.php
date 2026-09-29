<?php
/**
 * همهٔ مسیرهای AJAX افزونه.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Ajax' ) ) {

	final class TCP_Ajax {

		/** بررسی دسترسی عمومی + nonce برای درخواست‌های POST. */
		private static function guard() {
			if ( ! TCP_Settings::can() ) {
				wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز است.' ), 403 );
			}
			if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), TCP_Settings::NONCE ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- درون شرط
				wp_send_json_error( array( 'message' => 'درخواست معتبر نیست؛ صفحه را تازه‌سازی کن.' ), 403 );
			}
		}

		private static function send_wp_error( $e, $code = 400 ) {
			wp_send_json_error( array( 'message' => $e->get_error_message() ), $code );
		}

		/* -----------------------------------------------------------------
		 * پیش‌نمایش
		 * --------------------------------------------------------------- */

		public static function ajax_preview() {
			self::guard(); // nonce + capability — پیش‌نمایش هم مثل بقیهٔ اندپوینت‌ها محافظت می‌شود
			$args = TCP_Ops::args_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $args ) ) {
				self::send_wp_error( $args );
			}

			$parents = TCP_DB::selection_parent_ids( $args );
			if ( empty( $parents ) ) {
				wp_send_json_error( array( 'message' => 'هیچ محصولی در محدودهٔ انتخاب‌شده پیدا نشد (فیلترها را بررسی کن).' ), 400 );
			}

			$analysis = TCP_DB::analyze_targets( $parents, $args['operation'], TCP_Settings::sample_size() );

			// ساخت نمونه‌ها با همان فرمول اجرا.
			TCP_Ops::set_round_mode( $args['round_mode'] );
			$samples = array();
			foreach ( $analysis['samples'] as $oid => $info ) {
				$samples[] = TCP_Ops::sample_row( $oid, $info, $args['operation'], $args['value'] );
			}

			wp_send_json_success( array(
				'preview_token'      => TCP_Ops::make_token( $args ),
				'parent_count'       => count( $parents ),
				'price_object_count' => (int) $analysis['eligible'],
				'target_type'        => $args['target_type'],
				'include_children'   => ! empty( $args['include_children'] ),
				'category_labels'    => self::category_labels( $args['category_ids'] ),
				'operation_label'    => TCP_Ops::op_label( $args['operation'] ),
				'round_mode'         => $args['round_mode'],
				'round_label'        => TCP_Round::describe(),
				'jitter'             => TCP_Round::jitter(),
				'samples'            => $samples,
			) );
		}

		private static function category_labels( $cat_ids ) {
			$labels = array();
			foreach ( (array) $cat_ids as $cid ) {
				$term = get_term( absint( $cid ), 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$parts  = array( $term->name );
					$parent = absint( $term->parent );
					$guard  = 0;
					while ( $parent && $guard < 10 ) {
						$p = get_term( $parent, 'product_cat' );
						if ( ! $p || is_wp_error( $p ) ) {
							break;
						}
						array_unshift( $parts, $p->name );
						$parent = absint( $p->parent );
						$guard++;
					}
					$labels[] = implode( ' ← ', $parts );
				}
			}
			return $labels;
		}

		/* -----------------------------------------------------------------
		 * اجرا (شروع / ادامه / از سرگیری)
		 * --------------------------------------------------------------- */

		public static function ajax_run() {
			self::guard();

			$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;

			// --- ادامه/ازسرگیری اجرای موجود ---
			if ( $run_id ) {
				$run = TCP_DB::get_run( $run_id );
				if ( ! $run ) {
					wp_send_json_error( array( 'message' => 'اجرا پیدا نشد.' ), 404 );
				}
				if ( (int) $run['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( array( 'message' => 'این اجرا متعلق به شما نیست.' ), 403 );
				}
				if ( 'rollback' === $run['type'] ) {
					wp_send_json_error( array( 'message' => 'اجرای بازگردانی با این مسیر ادامه نمی‌یابد.' ), 409 );
				}
				if ( 'running' === $run['status'] && ! empty( $_POST['resume'] ) ) {
					// بی‌اثر؛ اجرا از همان page ادامه می‌یابد.
				} elseif ( 'interrupted' === $run['status'] ) {
					TCP_DB::update_run( $run_id, array( 'status' => 'running', 'updated_at' => TCP_DB::now() ) );
					$run['status'] = 'running';
				} elseif ( 'running' !== $run['status'] ) {
					wp_send_json_error( array( 'message' => 'اجرا در وضعیت قابل‌ادامه نیست (' . TCP_Settings::translation( $run['status'] ) . ').' ), 409 );
				}

				$busy = TCP_DB::busy_slot( $run_id );
				if ( ! empty( $busy['active'] ) ) {
					wp_send_json_error( array( 'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ').' ), 409 );
				}
				TCP_DB::update_run( $run_id, array( 'updated_at' => TCP_DB::now() ) );

				self::process_and_respond( $run_id );
				return;
			}

			// --- شروع اجرای جدید / ثبت در صف ---
			$args = TCP_Ops::args_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $args ) ) {
				self::send_wp_error( $args );
			}

			$preview_token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
			if ( ! TCP_Ops::verify_token( $args, $preview_token ) ) {
				wp_send_json_error( array( 'message' => 'پیش‌نمایش معتبر نیست یا تنظیمات بعد از بررسی تغییر کرده است. دوباره «بررسی قبل از اجرا» را بزن.' ), 409 );
			}

			$busy = TCP_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				wp_send_json_error( array( 'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ' — توسط ' . (int) $busy['active']['user_id'] . ').' ), 409 );
			}

			$parents = TCP_DB::selection_parent_ids( $args );
			if ( empty( $parents ) ) {
				wp_send_json_error( array( 'message' => 'هیچ محصولی در محدودهٔ انتخاب‌شده پیدا نشد.' ), 400 );
			}

			$batch = max( 1, absint( TCP_Settings::batch_size() ) );
			$pages = max( 1, (int) ceil( count( $parents ) / $batch ) );
			$args_store = $args;
			$args_store['batch'] = $batch; // ثابت‌ماندن مرز صفحه‌ها در طول اجرا.
			$schedule = ! empty( $_POST['schedule'] ) && ! empty( TCP_Settings::setting( 'scheduled' ) );

			if ( $schedule ) {
				if ( TCP_DB::count_queued() > 0 ) {
					wp_send_json_error( array( 'message' => 'هم‌اکنون یک اجرای زمان‌بندی‌شده در صف است؛ اول آن را کنسل یا منتظر اجرایش بمان.' ), 409 );
				}
				$run_id = TCP_DB::create_run( array(
					'type' => 'scheduled',
					'status' => 'queued',
					'user_id' => get_current_user_id(),
					'operation' => $args['operation'],
					'value' => $args['value'],
					'args_hash' => TCP_Ops::args_hash( $args ),
					'args' => wp_json_encode( $args_store ),
					'page' => 0,
					'total_pages' => $pages,
					'total_parents' => count( $parents ),
				) );
				if ( ! $run_id ) {
					wp_send_json_error( array( 'message' => 'ثبت اجرا در صف ممکن نشد.' ), 500 );
				}
				TCP_Scheduler::schedule_tick();
				wp_send_json_success( array(
					'run_id' => (int) $run_id,
					'status' => 'queued',
					'message' => 'اجرا به صف زمان‌بندی اضافه شد و به‌زودی توسط WP-Cron اجرا می‌شود.',
				) );
			}

			$run_id = TCP_DB::create_run( array(
				'type' => 'bulk',
				'status' => 'running',
				'user_id' => get_current_user_id(),
				'operation' => $args['operation'],
				'value' => $args['value'],
				'args_hash' => TCP_Ops::args_hash( $args ),
				'args' => wp_json_encode( $args_store ),
				'page' => 0,
				'total_pages' => $pages,
				'total_parents' => count( $parents ),
			) );
			if ( ! $run_id ) {
				wp_send_json_error( array( 'message' => 'شروع اجرا ممکن نشد (خطای دیتابیس).' ), 500 );
			}
			// چک دوم پس از ثبت: پنجرهٔ رقابتِ شروع هم‌زمان دو اجرا را می‌بندد.
			$busy2 = TCP_DB::busy_slot( $run_id );
			if ( ! empty( $busy2['active'] ) ) {
				TCP_DB::delete_run( $run_id );
				wp_send_json_error( array( 'message' => 'اجرای دیگری هم‌زمان شروع شده است (#' . (int) $busy2['active']['id'] . '); این اجرا لغو شد.' ), 409 );
			}
			self::process_and_respond( $run_id );
		}

		/** پردازش یک صفحه و پاسخ استاندارد؛ در صورت اتمام، اجرا نهایی می‌شود. */
		private static function process_and_respond( $run_id ) {
			$run = TCP_DB::get_run( $run_id );
			if ( ! $run ) {
				wp_send_json_error( array( 'message' => 'اجرا پیدا نشد.' ), 404 );
			}
			$res = TCP_Ops::run_next_page( $run );
			if ( ! $res['ok'] ) {
				TCP_DB::update_run( $run_id, array( 'status' => 'failed', 'last_error' => $res['msg'], 'updated_at' => TCP_DB::now() ) );
				wp_send_json_error( array( 'message' => $res['msg'] ), 500 );
			}
			$data = $res['data'];

			$run = TCP_DB::get_run( $run_id );
			$response = array(
				'run_id'     => (int) $run_id,
				'status'     => $run['status'],
				'done'       => ! empty( $data['done'] ),
				'progress'   => $data['progress'],
				'parents'    => $data['parents'],
				'updated'    => $data['updated'],
				'skipped'    => $data['skipped'],
				'errors'     => $data['errors'],
				'errors_count' => count( $data['errors'] ),
				'totals'     => array(
					'parents' => (int) $run['count_updated'] + (int) $run['count_skipped'] + (int) $run['count_errors'],
					'updated' => (int) $run['count_updated'],
					'skipped' => (int) $run['count_skipped'],
					'errors'  => (int) $run['count_errors'],
				),
			);

			if ( $data['done'] ) {
				TCP_Ops::finalize_run( $run_id, 'done' );
				$run = TCP_DB::get_run( $run_id );
				$response['status'] = $run['status'];
				$response['totals'] = array(
					'parents' => (int) $run['count_updated'] + (int) $run['count_skipped'] + (int) $run['count_errors'],
					'updated' => (int) $run['count_updated'],
					'skipped' => (int) $run['count_skipped'],
					'errors'  => (int) $run['count_errors'],
				);
			}
			wp_send_json_success( $response );
		}

		/** نهایی‌کردن اجرا به درخواست مشتری (توقف). */
		public static function ajax_finish() {
			self::guard();
			$run_id  = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
			$run = $run_id ? TCP_DB::get_run( $run_id ) : null;
			if ( ! $run ) {
				wp_send_json_error( array( 'message' => 'اجرا پیدا نشد.' ), 404 );
			}
			if ( (int) $run['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'این اجرا متعلق به شما نیست.' ), 403 );
			}
			if ( in_array( $run['status'], array( 'done', 'rolled_back' ), true ) ) {
				wp_send_json_success( array( 'message' => 'اجرا از قبل نهایی شده بود.', 'status' => $run['status'] ) );
			}
			if ( 'running' !== $run['status'] ) {
				wp_send_json_error( array( 'message' => 'اجرا در وضعیت قابل توقف نیست.' ), 409 );
			}
			TCP_Ops::finalize_run( $run_id, 'stopped' );
			wp_send_json_success( array( 'message' => 'اجرا متوقف شد.', 'status' => 'stopped' ) );
		}

		/* -----------------------------------------------------------------
		 * بازگردانی
		 * --------------------------------------------------------------- */

		public static function ajax_rollback_start() {
			self::guard();
			if ( ! TCP_Settings::rollback_enabled() ) {
				wp_send_json_error( array( 'message' => 'بازگردانی در تنظیمات غیرفعال است.' ), 403 );
			}
			$source_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
			$source = $source_id ? TCP_DB::get_run( $source_id ) : null;
			if ( ! $source ) {
				wp_send_json_error( array( 'message' => 'اجرای مبدأ پیدا نشد.' ), 404 );
			}
			if ( 'rollback' === $source['type'] || in_array( $source['status'], array( 'running', 'interrupted', 'queued', 'rolled_back' ), true ) ) {
				wp_send_json_error( array( 'message' => 'این اجرا قابل بازگردانی نیست.' ), 409 );
			}
			$total_log = TCP_DB::count_log( $source_id );
			if ( $total_log < 1 ) {
				wp_send_json_error( array( 'message' => 'برای این اجرا رکوردی برای بازگردانی ثبت نشده است.' ), 409 );
			}

			$busy = TCP_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				wp_send_json_error( array( 'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ').' ), 409 );
			}

			$batch = max( 1, absint( TCP_Settings::batch_size() ) );
			$pages = max( 1, (int) ceil( $total_log / $batch ) );

			$new_id = TCP_DB::create_run( array(
				'type' => 'rollback',
				'status' => 'running',
				'user_id' => get_current_user_id(),
				'operation' => $source['operation'],
				'value' => $source['value'],
				'args_hash' => '',
				'args' => wp_json_encode( array( 'batch' => $batch ) ),
				'page' => 0,
				'total_pages' => $pages,
				'total_parents' => 0,
				'parent_run_id' => $source_id,
			) );
			if ( ! $new_id ) {
				wp_send_json_error( array( 'message' => 'شروع بازگردانی ممکن نشد.' ), 500 );
			}
			$busy2 = TCP_DB::busy_slot( $new_id );
			if ( ! empty( $busy2['active'] ) ) {
				TCP_DB::delete_run( $new_id );
				wp_send_json_error( array( 'message' => 'اجرای دیگری هم‌زمان شروع شده است; بازگردانی لغو شد.' ), 409 );
			}
			wp_send_json_success( array(
				'run_id'   => (int) $new_id,
				'source'   => (int) $source_id,
				'rows'     => (int) $total_log,
				'pages'    => (int) $pages,
				'progress' => 0,
			) );
		}

		public static function ajax_rollback_page() {
			self::guard();
			$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
			$run    = $run_id ? TCP_DB::get_run( $run_id ) : null;
			if ( ! $run ) {
				wp_send_json_error( array( 'message' => 'بازگردانی پیدا نشد.' ), 404 );
			}
			if ( 'rollback' !== $run['type'] ) {
				wp_send_json_error( array( 'message' => 'نوع اجرا درست نیست.' ), 409 );
			}
			if ( (int) $run['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'این بازگردانی متعلق به شما نیست.' ), 403 );
			}
			if ( 'running' !== $run['status'] ) {
				wp_send_json_error( array( 'message' => 'بازگردانی در وضعیت قابل ادامه نیست.' ), 409 );
			}
			$busy = TCP_DB::busy_slot( $run_id );
			if ( ! empty( $busy['active'] ) ) {
				wp_send_json_error( array( 'message' => 'اجرای دیگری در حال اجراست.' ), 409 );
			}

			$res = TCP_Ops::rollback_next_page( $run );
			if ( ! $res['ok'] ) {
				TCP_DB::update_run( $run_id, array( 'status' => 'failed', 'last_error' => $res['msg'], 'updated_at' => TCP_DB::now() ) );
				wp_send_json_error( array( 'message' => $res['msg'] ), 500 );
			}
			$data = $res['data'];

			if ( $data['done'] ) {
				// اجرای مبدأ را به «بازگردانی شد» تغییر بده و خود بازگردانی را نهایی کن.
				$src = TCP_DB::get_run( (int) $run['parent_run_id'] );
				if ( $src && in_array( $src['status'], array( 'done', 'stopped' ), true ) ) {
					TCP_DB::update_run( (int) $src['id'], array( 'status' => 'rolled_back', 'updated_at' => TCP_DB::now() ) );
				}
				TCP_Ops::finalize_run( $run_id, 'done' );
			}
			wp_send_json_success( array(
				'done'     => ! empty( $data['done'] ),
				'progress' => $data['progress'],
				'updated'  => $data['updated'],
				'skipped'  => $data['skipped'],
				'errors'   => $data['errors'],
			) );
		}

		/* -----------------------------------------------------------------
		 * CSV
		 * --------------------------------------------------------------- */

		public static function ajax_export_csv() {
			$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0;
			if ( ! $run_id || ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'tcp_export_' . $run_id ) ) {
				wp_die( 'درخواست معتبر نیست.' );
			}
			$run = TCP_DB::get_run( $run_id );
			if ( ! $run ) {
				wp_die( 'اجرا پیدا نشد.' );
			}

			$log_count = TCP_DB::count_log( $run_id );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="tcp-run-' . (int) $run_id . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
			echo "\xEF\xBB\xBF"; // BOM برای اکسل فارسی.

			$out = fopen( 'php://output', 'w' );
			$op_label = TCP_Ops::op_label( $run['operation'] );

			fputcsv( $out, array( 'شماره اجرا', (int) $run_id ) );
			fputcsv( $out, array( 'نوع', TCP_Settings::translation( $run['type'] ) ) );
			fputcsv( $out, array( 'عملیات', $op_label ) );
			fputcsv( $out, array( 'وضعیت', TCP_Settings::translation( $run['status'] ) ) );
			fputcsv( $out, array( 'تغییر یافته', (int) $run['count_updated'] ) );
			fputcsv( $out, array( 'رد شده', (int) $run['count_skipped'] ) );
			fputcsv( $out, array( 'خطا', (int) $run['count_errors'] ) );
			fputcsv( $out, array( 'تعداد ردیف', (int) $log_count ) );
			fputcsv( $out, array() );
			fputcsv( $out, array( 'ردیف', 'شماره اجرا', 'نوع فیلد', 'شناسه والد', 'نام والد', 'SKU والد', 'شناسه شیء', 'نام شیء', 'SKU شیء', 'مقدار قبل', 'مقدار بعد', 'زمان' ) );

			$offset = 0;
			$step   = 500;
			$row_no = 0;
			while ( true ) {
				$rows = TCP_DB::get_log_page( $run_id, $offset, $step );
				if ( empty( $rows ) ) {
					break;
				}
				$obj_ids = array();
				foreach ( $rows as $rr ) {
					$obj_ids[] = (int) $rr['object_id'];
					if ( (int) $rr['parent_id'] ) {
						$obj_ids[] = (int) $rr['parent_id'];
					}
				}
				$titles = TCP_DB::title_map( array_unique( $obj_ids ) );
				$skus   = TCP_DB::sku_map( array_unique( $obj_ids ) );

				foreach ( $rows as $rr ) {
					$row_no++;
					$oid    = (int) $rr['object_id'];
					$pid    = (int) $rr['parent_id'];
					$obj_title = isset( $titles[ $oid ] ) ? $titles[ $oid ]['title'] : '';
					$par_title = isset( $titles[ $pid ] ) ? $titles[ $pid ]['title'] : '';
					if ( '' === $obj_title && '0' !== $pid && $par_title ) {
						$obj_title = $par_title . ' (وریشن)';
					}
					fputcsv( $out, array(
						$row_no,
						(int) $rr['run_id'],
						TCP_Settings::csv_cell( $rr['object_type'] ),
						$pid ? $pid : $oid,
						TCP_Settings::csv_cell( $pid ? $par_title : $obj_title ),
						TCP_Settings::csv_cell( isset( $skus[ $pid ] ) ? $skus[ $pid ] : '' ),
						$oid,
						TCP_Settings::csv_cell( $obj_title ),
						TCP_Settings::csv_cell( isset( $skus[ $oid ] ) ? $skus[ $oid ] : '' ),
						TCP_Settings::csv_cell( $rr['before_value'] ),
						TCP_Settings::csv_cell( $rr['after_value'] ),
						(string) $rr['created_at'],
					) );
				}
				$offset += $step;
				if ( count( $rows ) < $step ) {
					break;
				}
			}
			fclose( $out );
			exit;
		}

		/* -----------------------------------------------------------------
		 * انصراف اجرای در صف
		 * --------------------------------------------------------------- */

		public static function ajax_cancel_scheduled() {
			self::guard();
			$run_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
			$run = $run_id ? TCP_DB::get_run( $run_id ) : null;
			if ( ! $run || 'scheduled' !== $run['type'] || 'queued' !== $run['status'] ) {
				wp_send_json_error( array( 'message' => 'اجرای در صفی برای انصراف پیدا نشد.' ), 404 );
			}
			if ( (int) $run['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'این اجرا متعلق به شما نیست.' ), 403 );
			}
			TCP_DB::delete_run( $run_id );
			wp_send_json_success( array( 'message' => 'اجرا از صف حذف شد.' ) );
		}

		/* -----------------------------------------------------------------
		 * جستجوی عبارتی محصول‌ها برای تغییر گروهی
		 * --------------------------------------------------------------- */

		/** جستجوی زیررشته در نام محصولات؛ نتایج صفحه‌بندی می‌شوند تا فهرست‌های بزرگ سبک بمانند. */
		public static function ajax_search_products_by_name() {
			self::guard();
			if ( ! TCP_Settings::wc_active() ) {
				wp_send_json_error( array( 'message' => 'ووکامرس فعال نیست.' ), 400 );
			}

			$raw_term = isset( $_POST['term'] ) && is_scalar( $_POST['term'] ) ? wp_unslash( $_POST['term'] ) : '';
			$term     = trim( sanitize_text_field( (string) $raw_term ) );
			$length   = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
			$page_raw = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : 1;
			$page     = max( 1, min( 100000, absint( $page_raw ) ) );
			$per_page = 100;

			if ( $length < 2 ) {
				wp_send_json_success( array( 'items' => array(), 'total' => 0, 'page' => $page, 'pages' => 0, 'per_page' => $per_page ) );
			}

			global $wpdb;
			$filters  = TCP_Ops::parse_filters( isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : '' );
			$statuses = array_values( array_intersect( array( 'publish', 'private', 'draft', 'pending', 'future' ), (array) $filters['statuses'] ) );
			if ( empty( $statuses ) ) {
				wp_send_json_success( array( 'items' => array(), 'total' => 0, 'page' => $page, 'pages' => 0, 'per_page' => $per_page ) );
			}

			$where  = array( 'p.post_type = %s' );
			$params = array( 'product' );
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where[] = "p.post_status IN ({$status_placeholders})";
			$params  = array_merge( $params, $statuses );
			$where[] = 'p.post_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $term ) . '%';

			// فیلتر نوع محصول را هم روی همان فهرست اعمال می‌کنیم؛ نوع سادهٔ بدون term هم حفظ می‌شود.
			$types = array_values( array_intersect( array( 'simple', 'variable', 'grouped', 'external' ), (array) $filters['types'] ) );
			if ( ! empty( $types ) ) {
				$type_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
				$type_clause = "EXISTS (
					SELECT 1
					FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = p.ID AND tt.taxonomy = 'product_type' AND t.slug IN ({$type_placeholders})
				)";
				$params = array_merge( $params, $types );
				if ( in_array( 'simple', $types, true ) ) {
					$type_clause = "({$type_clause} OR NOT EXISTS (
						SELECT 1
						FROM {$wpdb->term_relationships} tr_simple
						INNER JOIN {$wpdb->term_taxonomy} tt_simple ON tt_simple.term_taxonomy_id = tr_simple.term_taxonomy_id
						WHERE tr_simple.object_id = p.ID AND tt_simple.taxonomy = 'product_type'
					))";
				}
				$where[] = $type_clause;
			}

			$wholesale_only = ! empty( $filters['only_wholesale'] ) || ( isset( $_POST['wholesale_only'] ) && '1' === (string) wp_unslash( $_POST['wholesale_only'] ) );
			if ( $wholesale_only ) {
				$where[] = "(
					EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} wm
						WHERE wm.post_id = p.ID AND wm.meta_key = %s AND wm.meta_value <> ''
						  AND CAST(wm.meta_value AS DECIMAL(20,4)) > 0
					)
					OR EXISTS (
						SELECT 1 FROM {$wpdb->posts} v
						INNER JOIN {$wpdb->postmeta} vwm ON vwm.post_id = v.ID AND vwm.meta_key = %s
						WHERE v.post_type = 'product_variation' AND v.post_status NOT IN ('trash','auto-draft')
						  AND v.post_parent = p.ID AND vwm.meta_value <> ''
						  AND CAST(vwm.meta_value AS DECIMAL(20,4)) > 0
					)
				)";
				$params[] = TCP_WHOLESALE_META;
				$params[] = TCP_WHOLESALE_META;
			}

			$where_sql = implode( ' AND ', $where );
			$count_sql = $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE {$where_sql}",
				$params
			);
			$total = absint( $wpdb->get_var( $count_sql ) );
			$pages = $total ? (int) ceil( $total / $per_page ) : 0;
			$offset = ( $page - 1 ) * $per_page;

			$items = array();
			if ( $total && $offset < $total ) {
				$query_params = array_merge( $params, array( $per_page, $offset ) );
				$sql = $wpdb->prepare(
					"SELECT p.ID, p.post_title
					FROM {$wpdb->posts} p
					WHERE {$where_sql}
					ORDER BY p.post_title ASC, p.ID ASC
					LIMIT %d OFFSET %d",
					$query_params
				);
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				$product_ids = array_map( 'absint', wp_list_pluck( (array) $rows, 'ID' ) );
				$categories_by_product = array();
				if ( ! empty( $product_ids ) ) {
					update_meta_cache( 'post', $product_ids );
					$product_categories = wp_get_object_terms( $product_ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );
					if ( ! is_wp_error( $product_categories ) ) {
						foreach ( $product_categories as $category ) {
							$object_id = isset( $category->object_id ) ? absint( $category->object_id ) : 0;
							if ( $object_id ) {
								$categories_by_product[ $object_id ][] = $category->name;
							}
						}
					}
				}
				foreach ( (array) $rows as $row ) {
					$product = wc_get_product( absint( $row['ID'] ) );
					$image_id = $product ? absint( $product->get_image_id() ) : absint( get_post_meta( $row['ID'], '_thumbnail_id', true ) );
					$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
					$items[] = array(
						'id'        => absint( $row['ID'] ),
						'name'      => wp_specialchars_decode( $row['post_title'], ENT_QUOTES ),
						'sku'       => $product ? (string) $product->get_sku() : (string) get_post_meta( $row['ID'], '_sku', true ),
						'type'      => $product ? (string) $product->get_type() : '',
						'categories' => isset( $categories_by_product[ absint( $row['ID'] ) ] ) ? implode( ', ', $categories_by_product[ absint( $row['ID'] ) ] ) : '',
						'image_url'  => $image_url ? esc_url_raw( $image_url ) : '',
					);
				}
			}

			wp_send_json_success( array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'pages'    => $pages,
				'per_page' => $per_page,
			) );
		}

		/* -----------------------------------------------------------------
		 * جستجوی محصولات دارای قیمت عمده (مشابه نسخهٔ ۱)
		 * --------------------------------------------------------------- */

		public static function ajax_search_wholesale_products() {
			check_ajax_referer( 'search-products', 'security' );
			if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( -1 );
			}
			global $wpdb;
			$term = isset( $_GET['term'] ) ? (string) wc_clean( wp_unslash( $_GET['term'] ) ) : '';
			if ( '' === trim( $term ) ) {
				wp_die();
			}
			$limit = ! empty( $_GET['limit'] ) ? absint( $_GET['limit'] ) : 30;
			$limit = max( 1, min( 50, $limit ) );
			$like  = '%' . $wpdb->esc_like( $term ) . '%';
			$numeric_id = is_numeric( $term ) ? absint( $term ) : 0;

			$sql = "SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'product'
				  AND p.post_status NOT IN ('trash','auto-draft')
				  AND (
					EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} wm
						WHERE wm.post_id = p.ID
						  AND wm.meta_key = %s
						  AND CAST(wm.meta_value AS DECIMAL(20,4)) > 0
					)
					OR EXISTS (
						SELECT 1 FROM {$wpdb->posts} v
						INNER JOIN {$wpdb->postmeta} vwm ON vwm.post_id = v.ID AND vwm.meta_key = %s
						WHERE v.post_type = 'product_variation'
						  AND v.post_parent = p.ID
						  AND CAST(vwm.meta_value AS DECIMAL(20,4)) > 0
					)
				  )
				  AND (
					p.post_title LIKE %s
					OR p.ID = %d
					OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sku WHERE sku.post_id = p.ID AND sku.meta_key = '_sku' AND sku.meta_value LIKE %s )
					OR EXISTS (
						SELECT 1 FROM {$wpdb->posts} sv
						INNER JOIN {$wpdb->postmeta} svsku ON svsku.post_id = sv.ID AND svsku.meta_key = '_sku'
						WHERE sv.post_type = 'product_variation'
						  AND sv.post_parent = p.ID
						  AND svsku.meta_value LIKE %s
					)
				  )
				ORDER BY p.post_title ASC
				LIMIT %d";

			$ids = $wpdb->get_col(
				$wpdb->prepare( $sql, TCP_WHOLESALE_META, TCP_WHOLESALE_META, $like, $numeric_id, $like, $like, $limit )
			);
			$products = array();
			foreach ( $ids as $id ) {
				$product = wc_get_product( absint( $id ) );
				if ( ! $product ) {
					continue;
				}
				if ( function_exists( 'wc_products_array_filter_readable' ) && ! wc_products_array_filter_readable( $product ) ) {
					continue;
				}
				$products[ $product->get_id() ] = rawurldecode( wp_strip_all_tags( $product->get_formatted_name() ) );
			}
			wp_send_json( $products );
		}
	}
}
