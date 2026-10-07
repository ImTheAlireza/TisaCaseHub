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
			TCP_DB::maybe_install();
			TCP_Ops::runtime_boost(); // کاتالوگ بزرگ: حافظه/زمان کافی برای تحلیل کل محدوده
			$args = TCP_Ops::args_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $args ) ) {
				self::send_wp_error( $args );
			}
			// درخت دسته‌های هدف و استثنا فقط یک‌بار محاسبه می‌شود و در پیش‌نمایش، توکن و اجرا ثابت می‌ماند.
			$args['scan'] = array(
				'terms'          => TCP_DB::effective_terms( $args ),
				'excluded_terms' => TCP_DB::effective_excluded_terms( $args ),
			);

			$use_keyset     = 'all' === $args['target_type'];
			$preview_ceiling = 0;
			$preview_parents = null;
			if ( ! $use_keyset && 'category' === $args['target_type'] ) {
				$preview_ceiling = TCP_DB::product_id_ceiling();
				$preview_parents = TCP_DB::count_catalog_parents( $args, $preview_ceiling );
				$use_keyset      = $preview_parents > TCP_DB::KEYSET_AT;
			}

			if ( $use_keyset ) {
				$preview = TCP_DB::catalog_preview( $args, TCP_Settings::sample_size(), $preview_parents, $preview_ceiling );
				if ( empty( $preview['parents'] ) ) {
					wp_send_json_error( array( 'message' => 'هیچ محصولی در محدودهٔ انتخاب‌شده پیدا نشد (فیلترها را بررسی کن).' ), 400 );
				}
				TCP_Ops::set_round_mode( $args['round_mode'] );
				$samples = array();
				foreach ( $preview['samples'] as $oid => $info ) {
					$samples[] = TCP_Ops::sample_row( $oid, $info, $args['operation'], $args['value'] );
				}
				wp_send_json_success( self::preview_payload( $args, (int) $preview['parents'], (int) $preview['eligible'], $samples, (int) $preview['ceiling'] ) );
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

			wp_send_json_success( self::preview_payload( $args, count( $parents ), (int) $analysis['eligible'], $samples, 0 ) );
		}

		/**
		 * آرگومان ذخیره‌شدهٔ اجرا.
		 * «همه» و دسته‌های بزرگ با کلیدست می‌روند؛ انتخاب مستقیم همان فهرست یخ‌زده می‌ماند.
		 *
		 * @return array|WP_Error
		 */
		private static function prepare_execution( $args, $snapshot ) {
			$batch   = max( 1, min( 100, absint( TCP_Settings::batch_size() ) ) );
			$keyset  = 'all' === $args['target_type'];
			$args['scan'] = array(
				'terms'          => isset( $snapshot['terms'] ) ? TCP_Ops::ids( $snapshot['terms'] ) : array(),
				'excluded_terms' => isset( $snapshot['excluded_terms'] ) ? TCP_Ops::ids( $snapshot['excluded_terms'] ) : array(),
			);
			$ceiling      = 0;
			$catalog_count = null;

			if ( 'category' === $args['target_type'] ) {
				$ceiling = TCP_DB::product_id_ceiling();
				// تصمیم «کلیدست یا فهرست» با شمارش تازه انجام می‌شود؛ همان شمارش برای اجرا هم استفاده می‌شود.
				$catalog_count = TCP_DB::count_catalog_parents( $args, $ceiling );
				$keyset        = $catalog_count > TCP_DB::KEYSET_AT;
			}

			if ( $keyset ) {
				if ( ! $ceiling ) {
					$ceiling = TCP_DB::product_id_ceiling();
				}
				$store        = $args;
				$store['batch'] = $batch; // اندازهٔ دستهٔ تنظیم‌شده رعایت می‌شود؛ برای فروشگاه بزرگ حداقل ۴۰ تحمیل نمی‌شود.
				$scan = array(
					'mode'     => 'keyset',
					'ceiling'  => $ceiling,
					// درخت دسته‌های هدف/استثنا از لحظهٔ پیش‌نمایش تا پایان اجرا ثابت می‌ماند.
					'terms'          => $args['scan']['terms'],
					'excluded_terms' => $args['scan']['excluded_terms'],
				);
				$store['scan'] = $scan;
				$count = null === $catalog_count ? TCP_DB::count_catalog_parents( $store, $ceiling ) : (int) $catalog_count;
				if ( $count < 1 ) {
					return new WP_Error( 'empty', 'هیچ محصولی در محدودهٔ انتخاب‌شده پیدا نشد.' );
				}
				return array(
					'args'     => $store,
					'parents'  => $count,
					'pages'    => $count,
					'ceiling'  => $ceiling,
				);
			}

			$parents = TCP_DB::selection_parent_ids( $args );
			if ( empty( $parents ) ) {
				return new WP_Error( 'empty', 'هیچ محصولی در محدودهٔ انتخاب‌شده پیدا نشد.' );
			}
			$store = $args;
			$store['batch'] = $batch;
			// فهرست اهداف در شروع یک‌بار یخ می‌زند تا فیلترهای قیمت که خودِ اجرا عوض‌شان می‌کند مجموعه را کوچک نکند.
			$store['parent_ids'] = TCP_Ops::encode_parent_ids( $parents );
			$n = count( $parents );
			return array(
				'args'    => $store,
				'parents' => $n,
				'pages'   => $n,
				'ceiling' => 0,
			);
		}

		private static function stamp_scan( $run_id, $ceiling ) {
			if ( ! $run_id || ! $ceiling ) {
				return;
			}
			TCP_DB::update_run(
				$run_id,
				array(
					'scan_ceiling' => absint( $ceiling ),
					'scan_after'   => 0,
					'scan_pending' => 0,
					'scan_object'  => 0,
					'updated_at'   => TCP_DB::now(),
				)
			);
		}

		private static function preview_snapshot_key( $token ) {
			return 'tcp_preview_' . substr( hash( 'sha256', get_current_user_id() . '|' . (string) $token ), 0, 40 );
		}

		private static function preview_payload( $args, $parents, $eligible, $samples, $ceiling = 0 ) {
			$snapshot = array(
				'parents'        => (int) $parents,
				'ceiling'        => absint( $ceiling ),
				'terms'          => isset( $args['scan']['terms'] ) ? TCP_Ops::ids( $args['scan']['terms'] ) : array(),
				'excluded_terms' => isset( $args['scan']['excluded_terms'] ) ? TCP_Ops::ids( $args['scan']['excluded_terms'] ) : array(),
			);
			$token = TCP_Ops::make_token( $args, $snapshot );
			if ( function_exists( 'set_transient' ) ) {
				set_transient( self::preview_snapshot_key( $token ), $snapshot, 30 * MINUTE_IN_SECONDS );
			}
			return array(
				'preview_token'             => $token,
				'preview_parent_count'      => $snapshot['parents'],
				'preview_ceiling'           => $snapshot['ceiling'],
				'parent_count'              => $snapshot['parents'],
				'price_object_count'        => (int) $eligible,
				'target_type'               => $args['target_type'],
				'include_children'          => 'category' === $args['target_type'] && ! empty( $args['include_children'] ),
				'category_labels'           => 'category' === $args['target_type'] ? self::category_labels( isset( $args['category_ids'] ) ? $args['category_ids'] : array() ) : array(),
				'excluded_category_labels'  => self::category_labels( isset( $args['excluded_category_ids'] ) ? $args['excluded_category_ids'] : array() ),
				'excluded_product_count'    => count( isset( $args['excluded_product_ids'] ) ? (array) $args['excluded_product_ids'] : array() ),
				'exclude_category_children' => ! empty( $args['exclude_category_children'] ),
				'rollback_available'        => TCP_Settings::rollback_enabled() && TCP_DB::log_table_ready(),
				'operation_label'           => TCP_Ops::op_label( $args['operation'] ),
				'round_mode'                => $args['round_mode'],
				'round_label'               => TCP_Round::describe(),
				'jitter'                    => TCP_Round::jitter(),
				'samples'                   => $samples,
				'catalog'                   => 'all' === $args['target_type'],
				'chunked'                   => 'all' === $args['target_type'] || $snapshot['parents'] > TCP_DB::KEYSET_AT,
			);
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
			TCP_DB::maybe_install();
			TCP_Ops::runtime_boost();

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
				// اجرای نهایی‌شده (مثلاً پاسخ «تمام شد» در تلاش قبلی گم شده بود):
				// همان پاسخ تکمیل را بده تا کلاینت پیام درست ببیند، نه خطای 409.
				if ( in_array( $run['status'], array( 'done', 'stopped', 'rolled_back' ), true ) ) {
					wp_send_json_success( self::final_run_response( $run ) );
				}
				if ( 'interrupted' === $run['status'] ) {
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
				TCP_DB::touch_run_beat( $run_id );

				self::process_and_respond( $run_id );
				return;
			}

			// --- شروع اجرای جدید / ثبت در صف ---
			$args = TCP_Ops::args_from_post( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_wp_error( $args ) ) {
				self::send_wp_error( $args );
			}

				$preview_token = isset( $_POST['preview_token'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_token'] ) ) : '';
				$posted_snapshot = array(
					'parents' => isset( $_POST['preview_parent_count'] ) ? absint( $_POST['preview_parent_count'] ) : 0,
					'ceiling' => isset( $_POST['preview_ceiling'] ) ? absint( $_POST['preview_ceiling'] ) : 0,
				);
				$stored_snapshot = function_exists( 'get_transient' ) ? get_transient( self::preview_snapshot_key( $preview_token ) ) : false;
				if ( ! is_array( $stored_snapshot ) || ! isset( $stored_snapshot['parents'], $stored_snapshot['ceiling'] ) ||
					(int) $stored_snapshot['parents'] !== (int) $posted_snapshot['parents'] || (int) $stored_snapshot['ceiling'] !== (int) $posted_snapshot['ceiling'] ) {
					wp_send_json_error( array( 'message' => 'اعتبار پیش‌نمایش منقضی شده است؛ دوباره «بررسی قبل از اجرا» را بزن.' ), 409 );
				}
				$snapshot = array(
					'parents'        => absint( $stored_snapshot['parents'] ),
					'ceiling'        => absint( $stored_snapshot['ceiling'] ),
					'terms'          => TCP_Ops::ids( isset( $stored_snapshot['terms'] ) ? $stored_snapshot['terms'] : array() ),
					'excluded_terms' => TCP_Ops::ids( isset( $stored_snapshot['excluded_terms'] ) ? $stored_snapshot['excluded_terms'] : array() ),
				);
				if ( ! TCP_Ops::verify_token( $args, $preview_token, $snapshot ) ) {
				wp_send_json_error( array( 'message' => 'پیش‌نمایش معتبر نیست یا تنظیمات بعد از بررسی تغییر کرده است. دوباره «بررسی قبل از اجرا» را بزن.' ), 409 );
			}
			if ( 'all' === $args['target_type'] && ( ! TCP_Settings::rollback_enabled() || ! TCP_DB::log_table_ready() ) ) {
				wp_send_json_error( array( 'message' => 'برای تغییر همهٔ محصولات، ثبت لاگ و بازگردانی باید فعال باشد و جدول لاگ هم در دسترس باشد. تنظیمات را بررسی و دوباره پیش‌نمایش بگیر.' ), 409 );
			}

			$busy = TCP_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				// run_id برمی‌گردد تا کلاینت بتواند همان اجرای فعال (مثلاً شروعی که پاسخش گم شده) را ادامه دهد.
				wp_send_json_error( array(
					'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ' — توسط ' . (int) $busy['active']['user_id'] . ').',
					'run_id'  => (int) $busy['active']['id'],
					'mine'    => (int) $busy['active']['user_id'] === get_current_user_id(),
					'type'    => sanitize_key( $busy['active']['type'] ),
				), 409 );
			}

				$prepared = self::prepare_execution( $args, $snapshot );
			if ( is_wp_error( $prepared ) ) {
				self::send_wp_error( $prepared );
			}
			if ( (int) $prepared['parents'] !== (int) $snapshot['parents'] || (int) $prepared['ceiling'] !== (int) $snapshot['ceiling'] ) {
				wp_send_json_error( array( 'message' => 'فهرست یا تعداد محصولات از زمان پیش‌نمایش تغییر کرده است؛ برای جلوگیری از تغییر ناخواسته، دوباره «بررسی قبل از اجرا» را بزن.' ), 409 );
			}
			$confirm_threshold = TCP_Settings::confirm_threshold();
			if ( ! $confirm_threshold ) {
				$confirm_threshold = 500; // با مقدار پیش‌فرض رابط کاربری هم‌سو بماند.
			}
			$requires_confirmation = 'all' === $args['target_type'] || (int) $prepared['parents'] >= $confirm_threshold;
			if ( $requires_confirmation ) {
				$confirmation = isset( $_POST['confirmation'] ) && is_scalar( $_POST['confirmation'] )
					? trim( sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) )
					: '';
				$required_confirmation = 'تایید ' . (int) $prepared['parents'];
				if ( ! hash_equals( $required_confirmation, $confirmation ) ) {
					wp_send_json_error( array( 'message' => 'برای اجرای گسترده، عبارت تأیید «' . $required_confirmation . '» باید در درخواست ثبت شود.' ), 409 );
				}
			}
			$args_store    = $prepared['args'];
			$total_parents = (int) $prepared['parents'];
			$pages         = (int) $prepared['pages'];
			$ceiling       = (int) $prepared['ceiling'];
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
					'total_parents' => $total_parents,
				) );
				if ( ! $run_id ) {
					wp_send_json_error( array( 'message' => 'ثبت اجرا در صف ممکن نشد.' ), 500 );
				}
				self::stamp_scan( $run_id, $ceiling );
				TCP_DB::touch_run_beat( $run_id );
				TCP_Scheduler::arm_continue( $run_id );
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
				'total_parents' => $total_parents,
			) );
			if ( ! $run_id ) {
				wp_send_json_error( array( 'message' => 'شروع اجرا ممکن نشد (خطای دیتابیس).' ), 500 );
			}
			self::stamp_scan( $run_id, $ceiling );
			TCP_DB::touch_run_beat( $run_id );
			TCP_Scheduler::arm_continue( $run_id );
			// چک دوم پس از ثبت: پنجرهٔ رقابتِ شروع هم‌زمان دو اجرا را می‌بندد.
			$busy2 = TCP_DB::busy_slot( $run_id );
			if ( ! empty( $busy2['active'] ) ) {
				TCP_DB::delete_run( $run_id );
				wp_send_json_error( array(
					'message' => 'اجرای دیگری هم‌زمان شروع شده است (#' . (int) $busy2['active']['id'] . '); این اجرا لغو شد.',
					'run_id'  => (int) $busy2['active']['id'],
					'mine'    => (int) $busy2['active']['user_id'] === get_current_user_id(),
				), 409 );
			}
			// پردازش صفحهٔ اول عمداً در همین درخواست انجام نمی‌شود تا «شروع» سریع و
			// قابل‌تکرار بماند؛ کلاینت شناسه را می‌گیرد و پردازش را با run_id ادامه می‌دهد.
			// (اگر همین پاسخ گم شود، تلاش مجددِ شروع به همان اجرا می‌رسد و «ادامه» می‌دهد.)
			wp_send_json_success( array(
				'run_id'        => (int) $run_id,
				'status'        => 'running',
				'total_parents' => $total_parents,
				'progress'      => 0,
				'done'          => false,
				'message'       => 'اجرا شروع شد.',
			) );
		}

		/** پاسخ استاندارد «تمام شد» برای اجرای نهایی‌شده (بازیابی تلاش مجدد کلاینت). */
		private static function final_run_response( $run ) {
			return array(
				'run_id'       => (int) $run['id'],
				'status'       => $run['status'],
				'done'         => true,
				'progress'     => 100,
				'page'         => (int) $run['page'],
				'pages'        => (int) $run['total_pages'],
				'parents'      => 0,
				'updated'      => 0,
				'skipped'      => 0,
				'errors'       => array(),
				'errors_count' => 0,
				'totals'       => array(
					'parents' => (int) $run['count_updated'] + (int) $run['count_skipped'] + (int) $run['count_errors'],
					'updated' => (int) $run['count_updated'],
					'skipped' => (int) $run['count_skipped'],
					'errors'  => (int) $run['count_errors'],
				),
			);
		}

		/** پردازش یک صفحه و پاسخ استاندارد؛ در صورت اتمام، اجرا نهایی می‌شود. */
		private static function process_and_respond( $run_id ) {
			if ( ! TCP_DB::acquire_run_lock( $run_id ) ) {
				self::send_busy();
			}
				try {
					$payload = self::build_run_page( $run_id );
				} catch ( Throwable $e ) {
					TCP_DB::release_run_lock( $run_id );
					$unsafe = $e instanceof TCP_Unsafe_Exception;
					$detail = function_exists( 'mb_substr' ) ? mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 240 ) : substr( wp_strip_all_tags( $e->getMessage() ), 0, 240 );
					TCP_DB::update_run( $run_id, array(
						'status'     => $unsafe ? 'failed' : 'interrupted',
						'last_error' => $detail,
						'updated_at' => TCP_DB::now(),
					) );
					TCP_Scheduler::disarm_continue( $run_id );
					$message = $unsafe
						? 'به‌دلیل خطای ذخیره با وضعیت نامطمئن، ادامهٔ خودکار مسدود شد؛ محصول را دستی بررسی کن. خطا: ' . $detail
						: 'اجرا برای جلوگیری از ادامهٔ ناامن متوقف شد و از گزارش قابل ادامه است. خطا: ' . $detail;
					wp_send_json_error( array( 'message' => $message ), $unsafe ? 500 : 503 );
				}
				TCP_DB::release_run_lock( $run_id );
			if ( is_wp_error( $payload ) ) {
				if ( 'retry' === $payload->get_error_code() ) {
					self::send_busy();
				}
				$data   = $payload->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
				wp_send_json_error( array( 'message' => $payload->get_error_message() ), $status );
			}
			if ( empty( $payload['done'] ) && isset( $payload['status'] ) && 'running' === $payload['status'] ) {
				TCP_Scheduler::arm_continue( $run_id );
			} else {
				TCP_Scheduler::disarm_continue( $run_id );
			}
			wp_send_json_success( $payload );
		}

		/** 503 بدون JSON تا کلاینت آن را خطای منطقی نداند و دوباره تلاش کند. */
		private static function send_busy() {
			status_header( 503 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Retry-After: 2' );
			echo 'busy';
			exit;
		}

		/**
		 * یک صفحه را پردازش می‌کند. خروج نمی‌کند.
		 *
		 * @return array|WP_Error
		 */
		private static function build_run_page( $run_id ) {
			$run = TCP_DB::get_run( $run_id );
			if ( ! $run ) {
				return new WP_Error( 'missing', 'اجرا پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( 'running' !== $run['status'] ) {
				if ( in_array( $run['status'], array( 'done', 'stopped', 'rolled_back' ), true ) ) {
					return self::final_run_response( $run );
				}
				return new WP_Error( 'status', 'اجرا در وضعیت قابل‌ادامه نیست.', array( 'status' => 409 ) );
			}
			TCP_DB::touch_run_beat( $run_id );
			$res = TCP_Ops::run_next_page( $run );
			if ( ! $res['ok'] ) {
				if ( ! empty( $res['retry'] ) ) {
					return new WP_Error( 'retry', $res['msg'], array( 'status' => 503 ) );
				}
				TCP_DB::update_run( $run_id, array( 'status' => 'failed', 'last_error' => $res['msg'], 'updated_at' => TCP_DB::now() ) );
				TCP_Scheduler::disarm_continue( $run_id );
				return new WP_Error( 'run', $res['msg'], array( 'status' => 500 ) );
			}
			$data = $res['data'];

			$run = TCP_DB::get_run( $run_id );
			$response = array(
				'run_id'     => (int) $run_id,
				'status'     => $run['status'],
				'done'       => ! empty( $data['done'] ),
				'progress'   => $data['progress'],
				'page'       => isset( $data['page'] ) ? (int) $data['page'] : 0,
				'pages'      => isset( $data['pages'] ) ? (int) $data['pages'] : 0,
				'parents'    => $data['parents'],
				'updated'    => $data['updated'],
				'skipped'    => $data['skipped'],
				'errors'     => $data['errors'],
				'errors_count' => count( $data['errors'] ),
				'partial'    => ! empty( $data['partial'] ),
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
			return $response;
		}

		/**
		 * ادامهٔ پس‌زمینه. کوکی ادمین لازم نیست؛ امضا جای آن را می‌گیرد
		 * تا حلقهٔ داخلی سایت بعد از قطع مرورگر بتواند صفحهٔ بعد را بزند.
		 */
		public static function ajax_continue() {
			$run_id = isset( $_REQUEST['run_id'] ) ? absint( $_REQUEST['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$secret = isset( $_REQUEST['secret'] ) ? (string) wp_unslash( $_REQUEST['secret'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$force  = ! empty( $_REQUEST['force'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $run_id || ! hash_equals( TCP_Scheduler::continue_secret( $run_id ), $secret ) ) {
				status_header( 403 );
				exit;
			}
			TCP_Scheduler::drive_run( $run_id, ! $force );
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'ok';
			exit;
		}

		/** نهایی‌کردن اجرا به درخواست مشتری (توقف) یا ثبت «ناتمام» هنگام ترک موقت. */
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
			// leave=1 یعنی کلاینت ارتباطش را از دست داده و اجرا را رها می‌کند:
			// «ناتمام» ثبت می‌شود تا از تب گزارش بلافاصله قابل «ادامه» باشد.
			if ( ! empty( $_POST['leave'] ) ) {
				$leave_args = json_decode( (string) $run['args'], true );
				if ( is_array( $leave_args ) && TCP_Ops::is_catalog_run( $leave_args ) && 'running' === $run['status'] ) {
					TCP_Scheduler::arm_continue( $run_id );
					TCP_Scheduler::poke_continue( $run_id );
					wp_send_json_success( array(
						'message'    => 'ارتباط این صفحه قطع شد؛ نوشتن قیمت‌ها در پس‌زمینه از همان متغیر ادامه پیدا می‌کند. دکمهٔ توقف، اجرا را قطع می‌کند.',
						'status'     => 'running',
						'background' => true,
					) );
				}
				if ( 'interrupted' === $run['status'] ) {
					wp_send_json_success( array( 'message' => 'اجرا از قبل ناتمام ثبت شده و قابل ادامه است.', 'status' => 'interrupted' ) );
				}
				if ( 'running' === $run['status'] ) {
					TCP_Scheduler::disarm_continue( $run_id );
					TCP_DB::update_run( $run_id, array( 'status' => 'interrupted', 'updated_at' => TCP_DB::now() ) );
					wp_send_json_success( array( 'message' => 'اجرا ناتمام ثبت شد و از تب «گزارش و بازگردانی» قابل ادامه است.', 'status' => 'interrupted' ) );
				}
				wp_send_json_error( array( 'message' => 'اجرا در وضعیت قابل توقف نیست.' ), 409 );
			}
			if ( 'running' !== $run['status'] ) {
				wp_send_json_error( array( 'message' => 'اجرا در وضعیت قابل توقف نیست.' ), 409 );
			}
			// outcome=interrupted برای «توقفِ قابل ادامه» (مثلاً توقف بازگردانی)؛ پیش‌فرض stopped.
			$outcome = 'stopped';
			if ( ! empty( $_POST['outcome'] ) && 'interrupted' === sanitize_key( wp_unslash( $_POST['outcome'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$outcome = 'interrupted';
			}
			TCP_Scheduler::disarm_continue( $run_id );
			TCP_Ops::finalize_run( $run_id, $outcome );
			wp_send_json_success(
				'interrupted' === $outcome
					? array( 'message' => 'اجرا ناتمام ثبت شد و از تب «گزارش و بازگردانی» قابل ادامه است.', 'status' => 'interrupted' )
					: array( 'message' => 'اجرا متوقف شد.', 'status' => 'stopped' )
			);
		}

		/* -----------------------------------------------------------------
		 * بازگردانی
		 * --------------------------------------------------------------- */

		public static function ajax_rollback_start() {
			self::guard();
			$source_id = isset( $_POST['run_id'] ) ? absint( $_POST['run_id'] ) : 0;
			$source = $source_id ? TCP_DB::get_run( $source_id ) : null;
			if ( ! $source ) {
				wp_send_json_error( array( 'message' => 'اجرای مبدأ پیدا نشد.' ), 404 );
			}
			$source_args = json_decode( (string) $source['args'], true );
			$catalog_run = is_array( $source_args ) && isset( $source_args['target_type'] ) && 'all' === $source_args['target_type'];
			// اگر گزینهٔ عمومی بعداً خاموش شد، اجرای «همهٔ محصولات» که با لاگ اجباری شروع شده هنوز قابل بازگردانی بماند.
			if ( ! TCP_Settings::rollback_enabled() && ! $catalog_run ) {
				wp_send_json_error( array( 'message' => 'بازگردانی در تنظیمات غیرفعال است.' ), 403 );
			}
			if ( 'rollback' === $source['type'] || in_array( $source['status'], array( 'running', 'interrupted', 'queued', 'failed', 'rolled_back' ), true ) ) {
				wp_send_json_error( array( 'message' => 'این اجرا قابل بازگردانی نیست.' ), 409 );
			}
			$total_log = TCP_DB::count_log( $source_id );
			if ( $total_log < 1 ) {
				wp_send_json_error( array( 'message' => 'برای این اجرا رکوردی برای بازگردانی ثبت نشده است.' ), 409 );
			}

			$busy = TCP_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				wp_send_json_error( array(
					'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ').',
					'run_id'  => (int) $busy['active']['id'],
					'mine'    => (int) $busy['active']['user_id'] === get_current_user_id(),
					'type'    => sanitize_key( $busy['active']['type'] ),
				), 409 );
			}

			$batch  = max( 1, absint( TCP_Settings::batch_size() ) );
			$pages  = max( 1, (int) ceil( $total_log / $batch ) );

			$new_id = TCP_DB::create_run( array(
				'type' => 'rollback',
				'status' => 'running',
				'user_id' => get_current_user_id(),
				'operation' => $source['operation'],
				'value' => $source['value'],
				'args_hash' => '',
				'args' => wp_json_encode( array( 'batch' => $batch ) ),
				'page' => 0,
				// page = cursor ردیف‌های برگشت‌داده‌شده؛ مخرج پیشرفت = کل ردیف‌ها.
				'total_pages' => max( 1, (int) $total_log ),
				'total_parents' => 0,
				'parent_run_id' => $source_id,
			) );
			if ( ! $new_id ) {
				wp_send_json_error( array( 'message' => 'شروع بازگردانی ممکن نشد.' ), 500 );
			}
			$busy2 = TCP_DB::busy_slot( $new_id );
			if ( ! empty( $busy2['active'] ) ) {
				TCP_DB::delete_run( $new_id );
				wp_send_json_error( array(
					'message' => 'اجرای دیگری هم‌زمان شروع شده است; بازگردانی لغو شد.',
					'run_id'  => (int) $busy2['active']['id'],
					'mine'    => (int) $busy2['active']['user_id'] === get_current_user_id(),
				), 409 );
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
			if ( 'interrupted' === $run['status'] ) {
				// بازگردانی ناتمام (مثلاً بعد از قطع ارتباط) از همان cursor ادامه می‌یابد.
				TCP_DB::update_run( $run_id, array( 'status' => 'running', 'updated_at' => TCP_DB::now() ) );
				$run['status'] = 'running';
			}
			if ( 'running' !== $run['status'] ) {
				wp_send_json_error( array( 'message' => 'بازگردانی در وضعیت قابل ادامه نیست.' ), 409 );
			}
			$busy = TCP_DB::busy_slot( $run_id );
			if ( ! empty( $busy['active'] ) ) {
				wp_send_json_error( array( 'message' => 'اجرای دیگری در حال اجراست (#' . (int) $busy['active']['id'] . ').' ), 409 );
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
		 * جستجوی سراسری محصول (نام/توضیح/SKU/شناسه) با صفحه‌بندی
		 * برای «محصولات مستثنا»: کل کاتالوگ، بدون سقف ۳۰ موردی جستجوی ووکامرس.
		 * --------------------------------------------------------------- */

		public static function ajax_search_products_any() {
			self::guard();
			if ( ! TCP_Settings::wc_active() ) {
				wp_send_json_error( array( 'message' => 'ووکامرس فعال نیست.' ), 400 );
			}

			$raw_term = isset( $_POST['term'] ) && is_scalar( $_POST['term'] ) ? wp_unslash( $_POST['term'] ) : '';
			$term     = trim( sanitize_text_field( (string) $raw_term ) );
			$length   = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
			$page_raw = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? wp_unslash( $_POST['page'] ) : 1;
			$page     = max( 1, min( 100000, absint( $page_raw ) ) );

			if ( $length < 2 ) {
				wp_send_json_success( array( 'items' => array(), 'total' => 0, 'page' => $page, 'pages' => 0, 'per_page' => TCP_Ops::SEARCH_PER_PAGE ) );
			}

			wp_send_json_success( TCP_Ops::search_products( $term, array( 'page' => $page ) ) );
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
		 * جستجوی محصولات بر اساس SKU (شامل‌شونده).
		 * مثل جستجوی نام، ولی روی متن SKU: محصول یا یکی از واریشن‌هایش SKUای
		 * داشته باشد که عبارت جستجو در آن باشد (مثل نسخهٔ عمده‌فروشی).
		 * --------------------------------------------------------------- */

		public static function ajax_search_products_by_sku() {
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

			// جستجوی «شامل‌شونده» روی SKU: SKU خودِ محصول یا یکی از واریشن‌هایش.
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$where[] = "(
				EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} sk
					WHERE sk.post_id = p.ID AND sk.meta_key = '_sku' AND sk.meta_value <> '' AND sk.meta_value LIKE %s
				)
				OR EXISTS (
					SELECT 1 FROM {$wpdb->posts} v
					INNER JOIN {$wpdb->postmeta} vsk ON vsk.post_id = v.ID AND vsk.meta_key = '_sku'
					WHERE v.post_type = 'product_variation' AND v.post_status NOT IN ('trash','auto-draft')
					  AND v.post_parent = p.ID AND vsk.meta_value <> '' AND vsk.meta_value LIKE %s
				)
			)";
			$params[] = $like;
			$params[] = $like;

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
