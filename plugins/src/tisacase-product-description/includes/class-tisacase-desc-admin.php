<?php
/**
 * Admin panel: settings + bulk repair tools.
 *
 * @package TisaCase_Product_Description
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Desc_Admin' ) ) {
	final class TisaCase_Desc_Admin {

		const MENU_SLUG = 'tisacase-desc';
		const NONCE     = 'tisacase_desc_nonce';

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );

			add_action( 'admin_post_tisacase_desc_save', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_post_tisacase_desc_reset', array( __CLASS__, 'reset_settings' ) );

			add_action( 'wp_ajax_tisacase_desc_repair', array( __CLASS__, 'ajax_repair' ) );
			add_action( 'wp_ajax_tisacase_desc_preview', array( __CLASS__, 'ajax_preview' ) );
			add_action( 'wp_ajax_tisacase_desc_scan', array( __CLASS__, 'ajax_scan' ) );
			add_action( 'wp_ajax_tisacase_desc_apply', array( __CLASS__, 'ajax_apply' ) );
			add_action( 'wp_ajax_tisacase_desc_restore', array( __CLASS__, 'ajax_restore' ) );
			add_action( 'wp_ajax_tisacase_desc_backup_delete', array( __CLASS__, 'ajax_backup_delete' ) );
			add_action( 'wp_ajax_tisacase_desc_meta_detect', array( __CLASS__, 'ajax_meta_detect' ) );
			add_action( 'wp_ajax_tisacase_desc_prep_apply', array( __CLASS__, 'ajax_prep_apply' ) );
		}

		/* ---------------------------------------------------------------- */
		/* Menu + assets                                                     */
		/* ---------------------------------------------------------------- */

		public static function menu() {
			$icon = 'data:image/svg+xml;base64,' . base64_encode( self::icon_svg() );

			add_menu_page(
				__( 'قوانین توضیحات محصول', 'tisacase-desc' ),
				__( 'TisaCase', 'tisacase-desc' ),
				'manage_woocommerce',
				self::MENU_SLUG,
				array( __CLASS__, 'render' ),
				$icon,
				56
			);
		}

		public static function icon_svg() {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">'
				. '<rect x="3" y="4" width="18" height="16" rx="3.5" fill="#0E7C6B"/>'
				. '<path d="M7 9.2h10M7 12.6h10M7 16h6" stroke="#FFFFFF" stroke-width="1.9" stroke-linecap="round"/>'
				. '</svg>';
		}

		public static function enqueue( $hook ) {
			if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'tisacase-desc-admin',
				TISACASE_DESC_URL . 'assets/admin.css',
				wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array(),
				TISACASE_DESC_VERSION
			);

			wp_enqueue_script(
				'tisacase-desc-admin',
				TISACASE_DESC_URL . 'assets/admin.js',
				array( 'jquery' ),
				TISACASE_DESC_VERSION,
				true
			);

			wp_localize_script(
				'tisacase-desc-admin',
				'tisa',
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( self::NONCE ),
					'i18n'  => array(
						'confirm'     => __( 'این عملیات توضیحات همه محصولات را بر اساس قوانین بازنویسی می‌کند. مطمئن هستید؟', 'tisacase-desc' ),
						'running'     => __( 'در حال پردازش…', 'tisacase-desc' ),
						'done'        => __( 'پایان یافت!', 'tisacase-desc' ),
						'error'       => __( 'خطایی رخ داد. دوباره تلاش کنید.', 'tisacase-desc' ),
						'productNotFound' => __( 'محصولی با این شناسه پیدا نشد.', 'tisacase-desc' ),
						'noId'        => __( 'شناسه محصول را وارد کنید.', 'tisacase-desc' ),
						'scanRunning' => __( 'در حال اسکن…', 'tisacase-desc' ),
						'scanDone'    => __( 'اسکن کامل شد.', 'tisacase-desc' ),
						'noMismatch'  => __( 'همه محصولات با قوانین مطابقت دارند — چیزی برای تغییر نیست.', 'tisacase-desc' ),
						'mismatchSummary' => __( '{n} محصول نیاز به تغییر دارند.', 'tisacase-desc' ),
						'allDone'     => __( 'همه موارد بررسی و اصلاح شد.', 'tisacase-desc' ),
						'applied'     => __( '{changed} محصول اصلاح شد، {unchanged} محصول بدون تغییر (از قبل صحیح بود).', 'tisacase-desc' ),
						'applyConfirm'=> __( 'توضیحات {n} محصول انتخاب‌شده بازنویسی شود؟', 'tisacase-desc' ),
						'selected'    => __( 'انتخاب‌شده', 'tisacase-desc' ),
						'currentLabel'=> __( 'فعلی', 'tisacase-desc' ),
						'newLabel'    => __( 'جدید', 'tisacase-desc' ),
						'emptyDesc'   => __( '(توضیحات خالی)', 'tisacase-desc' ),
						'clearDesc'   => __( 'خالی می‌شود', 'tisacase-desc' ),
						'showFull'    => __( 'نمایش کامل', 'tisacase-desc' ),
						'hideFull'    => __( 'بستن', 'tisacase-desc' ),
						'restoreConfirm' => __( 'توضیحات محصولات این پشتیبان به وضعیت قبل از اصلاح برگردانده شود؟', 'tisacase-desc' ),
						'restoring'   => __( 'در حال بازگردانی…', 'tisacase-desc' ),
						'restoreDone' => __( 'بازگردانی کامل شد.', 'tisacase-desc' ),
						'restoreSummary' => __( '{restored} محصول بازگردانده شد و {skipped} محصول رد شد (تغییر دستی داشته یا حذف شده بود).', 'tisacase-desc' ),
						'deleteConfirm'=> __( 'این پشتیبان برای همیشه حذف شود؟', 'tisacase-desc' ),
						'invalidResponse' => __( 'پاسخ نامعتبر از سرور دریافت شد.', 'tisacase-desc' ),
						'detectRunning' => __( 'در حال بررسی متای محصول…', 'tisacase-desc' ),
						'detectHint'    => __( 'متای محصول {name} (شناسه {id}). روی کلید موردنظر کلیک کنید:', 'tisacase-desc' ),
						'prepApplyConfirm' => __( 'زمان آماده‌سازی برای همه محصولات چاپی ثبت شود؟ (قبل از آن پشتیبان خودکار گرفته می‌شود)', 'tisacase-desc' ),
						'prepApplying'  => __( 'در حال اعمال زمان آماده‌سازی…', 'tisacase-desc' ),
						'prepDone'      => __( 'پایان یافت — {n} محصول اصلاح شد.', 'tisacase-desc' ),
						'sessionExpired'=> __( 'نشست منقضی شده یا درخواست نامعتبر است. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.', 'tisacase-desc' ),
					),
				)
			);
		}

		public static function woocommerce_notice() {
			if ( class_exists( 'WooCommerce' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>TisaCase:</strong> '
				. esc_html__( 'این افزونه برای کارکرد به ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'tisacase-desc' )
				. '</p></div>';
		}

		/* ---------------------------------------------------------------- */
		/* Settings save / reset                                             */
		/* ---------------------------------------------------------------- */

		/**
		 * Catch fatal errors during an AJAX request and return them as JSON,
		 * so the user sees the real reason instead of a generic "خطایی رخ داد".
		 */
		private static function ajax_guard() {
			register_shutdown_function( function () {
				$err = function_exists( 'error_get_last' ) ? error_get_last() : null;
				if ( $err && in_array( $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					$msg = 'PHP Error: ' . $err['message'] . ' در ' . basename( $err['file'] ) . ':' . $err['line'];
					if ( ! headers_sent() && function_exists( 'wp_send_json_error' ) ) {
						wp_send_json_error( array( 'message' => $msg ) );
					} else {
						echo esc_html( $msg );
					}
				}
			} );
		}

		public static function save_settings() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-desc' ) );
			}
			check_admin_referer( 'tisacase_desc_save' );

			$opts = TisaCase_Desc_Core::get_options();

			$opts['enabled']         = isset( $_POST['enabled'] ) ? 1 : 0;
			$opts['clear_unmatched'] = isset( $_POST['clear_unmatched'] ) ? 1 : 0;
			$opts['skip_if_contains'] = isset( $_POST['skip_if_contains'] ) ? 1 : 0;

			$opts['prep_enabled']    = isset( $_POST['prep_enabled'] ) ? 1 : 0;
			$opts['prep_only_empty'] = isset( $_POST['prep_only_empty'] ) ? 1 : 0;
			$opts['prep_meta_key']   = isset( $_POST['prep_meta_key'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['prep_meta_key'] ) ) )
				: '_tisacase_prep_time';
			$opts['prep_value']      = isset( $_POST['prep_value'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['prep_value'] ) ) )
				: '18';
			$opts['batch_size']      = isset( $_POST['batch_size'] ) ? max( 1, min( 100, absint( $_POST['batch_size'] ) ) ) : 50;

			$opts['printed_pattern'] = isset( $_POST['printed_pattern'] )
				? sanitize_text_field( wp_unslash( $_POST['printed_pattern'] ) )
				: TisaCase_Desc_Core::defaults()['printed_pattern'];

			$opts['printed_url'] = isset( $_POST['printed_url'] )
				? esc_url_raw( wp_unslash( $_POST['printed_url'] ) )
				: 'https://TISACHAP.COM';

			$opts['printed_desc'] = isset( $_POST['printed_desc'] )
				? wp_kses_post( wp_unslash( $_POST['printed_desc'] ) )
				: '';

			$opts['frame_keywords'] = isset( $_POST['frame_keywords'] )
				? sanitize_text_field( wp_unslash( $_POST['frame_keywords'] ) )
				: 'قاب';

			$opts['frame_desc'] = isset( $_POST['frame_desc'] )
				? wp_kses_post( wp_unslash( $_POST['frame_desc'] ) )
				: '';

			update_option( TisaCase_Desc_Core::OPTION, $opts );

			wp_safe_redirect( add_query_arg(
				array( 'page' => self::MENU_SLUG, 'tab' => 'settings', 'saved' => 1 ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		public static function reset_settings() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-desc' ) );
			}
			check_admin_referer( 'tisacase_desc_reset' );

			update_option( TisaCase_Desc_Core::OPTION, TisaCase_Desc_Core::defaults() );

			wp_safe_redirect( add_query_arg(
				array( 'page' => self::MENU_SLUG, 'tab' => 'settings', 'saved' => 1, 'reset' => 1 ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		/* ---------------------------------------------------------------- */
		/* AJAX: bulk repair                                                 */
		/* ---------------------------------------------------------------- */

		public static function ajax_repair() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$options = TisaCase_Desc_Core::get_options();
			$batch   = self::bulk_batch_size( $options );
			$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
			$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$dry     = ! empty( $_POST['dry'] );

			// Snapshot (rollback point) — only created when actually writing.
			$snapshot = '';
			if ( ! $dry ) {
				$snapshot = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
				if ( '' === $snapshot || ! TisaCase_Desc_Backup::get( $snapshot ) ) {
					$snapshot = TisaCase_Desc_Backup::create( __( 'اصلاح انبوه', 'tisacase-desc' ) );
				}
			}

			$started = microtime( true );
			$budget  = self::ajax_budget_seconds();
			$counted = wc_get_products( array(
				'status'   => TisaCase_Desc_Core::statuses(),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			) );
			$total = isset( $counted->total ) ? (int) $counted->total : 0;

			$ids = wc_get_products( array(
				'status'  => TisaCase_Desc_Core::statuses(),
				'limit'   => $batch,
				'page'    => $page + 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			) );

			$processed   = 0;
			$changed     = 0;
			$next_offset = min( $offset, count( $ids ) );

			for ( $index = $next_offset; $index < count( $ids ); $index++ ) {
				if ( $processed > 0 && microtime( true ) - $started >= $budget ) {
					break;
				}
				$product_id  = $ids[ $index ];
				$next_offset = $index + 1;
				$processed++;
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				if ( $dry ) {
					if ( null !== TisaCase_Desc_Core::computed_description( $product, $options ) ) {
						$changed++;
					} elseif ( TisaCase_Desc_Core::should_set_prep_time( $product, $options ) ) {
						$changed++;
					}
				} elseif ( TisaCase_Desc_Backup::apply_with_backup( $snapshot, $product_id, true ) ) {
					$changed++;
				}
			}

			$page_complete = $next_offset >= count( $ids );
			$done = $page_complete && count( $ids ) < $batch;
			$next_page = $page_complete ? $page + 1 : $page;
			if ( $page_complete ) {
				$next_offset = 0;
			}

			// Drop an empty snapshot (nothing was actually changed).
			if ( $done && ! $dry && '' !== $snapshot ) {
				$snap = TisaCase_Desc_Backup::get( $snapshot );
				if ( $snap && empty( $snap['items'] ) ) {
					TisaCase_Desc_Backup::delete( $snapshot );
					$snapshot = '';
				}
			}

			wp_send_json_success( array(
				'page'      => $page,
				'processed' => $processed,
				'changed'   => $changed,
				'total'     => $total,
				'done'      => $done,
				'next'        => $next_page,
				'next_page'   => $next_page,
				'next_offset' => $next_offset,
				'snapshot'    => $snapshot,
			) );
		}

		/* ---------------------------------------------------------------- */
		/* AJAX: scan (read-only) + selective apply                          */
		/* ---------------------------------------------------------------- */

		public static function ajax_scan() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$options = TisaCase_Desc_Core::get_options();
			$batch   = self::bulk_batch_size( $options );
			$page    = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
			$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

			$started = microtime( true );
			$budget  = self::ajax_budget_seconds();
			$counted = wc_get_products( array(
				'status'   => TisaCase_Desc_Core::statuses(),
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			) );
			$total = isset( $counted->total ) ? (int) $counted->total : 0;

			$ids = wc_get_products( array(
				'status'  => TisaCase_Desc_Core::statuses(),
				'limit'   => $batch,
				'page'    => $page + 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			) );

			$mismatches  = array();
			$processed   = 0;
			$next_offset = min( $offset, count( $ids ) );

			for ( $index = $next_offset; $index < count( $ids ); $index++ ) {
				if ( $processed > 0 && microtime( true ) - $started >= $budget ) {
					break;
				}
				$product_id  = $ids[ $index ];
				$next_offset = $index + 1;
				$processed++;
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$new = TisaCase_Desc_Core::computed_description( $product, $options );
				if ( null === $new ) {
					continue; // Product is already correct — never touched.
				}

				$info   = TisaCase_Desc_Core::match_info( $product, $options );
				$status = $product->get_status();

				$mismatches[] = array(
					'id'           => $product->get_id(),
					'name'         => $product->get_name(),
					'sku'          => $product->get_sku(),
					'status'       => $status,
					'status_label' => self::status_label( $status ),
					'rule'         => $info['rule'],
					'rule_label'   => self::rule_label( $info['rule'] ),
					'current'      => $product->get_description(),
					'new'          => $new,
					'edit_link'    => get_edit_post_link( $product->get_id(), 'raw' ),
				);
			}

			$page_complete = $next_offset >= count( $ids );
			$done = $page_complete && count( $ids ) < $batch;
			$next_page = $page_complete ? $page + 1 : $page;
			if ( $page_complete ) {
				$next_offset = 0;
			}

			wp_send_json_success( array(
				'page'        => $page,
				'processed'   => $processed,
				'mismatches'  => $mismatches,
				'total'       => $total,
				'done'        => $done,
				'next'        => $next_page,
				'next_page'   => $next_page,
				'next_offset' => $next_offset,
			) );
		}

		public static function ajax_apply() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
			$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
			$ids = array_slice( array_unique( $ids ), 0, 1000 );

			if ( empty( $ids ) ) {
				wp_send_json_error( array( 'message' => __( 'هیچ محصولی انتخاب نشده است.', 'tisacase-desc' ) ) );
			}

			$offset = min( isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0, count( $ids ) );
			$options = TisaCase_Desc_Core::get_options();
			$batch = self::bulk_batch_size( $options );
			$snapshot = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
			if ( '' === $snapshot || ! TisaCase_Desc_Backup::get( $snapshot ) ) {
				$snapshot = TisaCase_Desc_Backup::create( __( 'اصلاح انتخابی', 'tisacase-desc' ) );
			}

			$started = microtime( true );
			$budget = self::ajax_budget_seconds();
			$processed = 0;
			$changed = 0;
			$unchanged = 0;
			$next_offset = $offset;
			for ( $index = $offset; $index < count( $ids ); $index++ ) {
				if ( $processed >= $batch || ( $processed > 0 && microtime( true ) - $started >= $budget ) ) {
					break;
				}
				$next_offset = $index + 1;
				$processed++;
				if ( TisaCase_Desc_Backup::apply_with_backup( $snapshot, $ids[ $index ] ) ) {
					$changed++;
				} else {
					$unchanged++;
				}
			}

			$done = $next_offset >= count( $ids );
			if ( $done ) {
				$snap = TisaCase_Desc_Backup::get( $snapshot );
				if ( $snap && empty( $snap['items'] ) ) {
					TisaCase_Desc_Backup::delete( $snapshot );
					$snapshot = '';
				}
			}

			wp_send_json_success( array(
				'changed'     => $changed,
				'unchanged'   => $unchanged,
				'processed'   => $processed,
				'total'       => count( $ids ),
				'done'        => $done,
				'next_offset' => $next_offset,
				'snapshot'    => $snapshot,
			) );
		}

		/* ---------------------------------------------------------------- */
		/* AJAX: restore a snapshot (batched) + delete snapshot              */
		/* ---------------------------------------------------------------- */

		public static function ajax_restore() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$snapshot = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
			$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;

			$snap = TisaCase_Desc_Backup::get( $snapshot );
			if ( ! $snap ) {
				wp_send_json_error( array( 'message' => __( 'پشتیبان موردنظر پیدا نشد.', 'tisacase-desc' ) ) );
			}

			$items   = isset( $snap['items'] ) && is_array( $snap['items'] ) ? $snap['items'] : array();
			$batch   = 100;
			$restored = 0;
			$skipped  = 0;
			$processed = 0;

			$remaining = TisaCase_Desc_Backup::restore_batch( $snapshot, $page, $batch, $restored, $skipped, $processed );

			if ( $remaining <= 0 ) {
				TisaCase_Desc_Backup::mark_restored( $snapshot );
			}

			wp_send_json_success( array(
				'page'      => $page,
				'processed' => $processed,
				'restored'  => $restored,
				'skipped'   => $skipped,
				'total'     => count( $items ),
				'done'      => $remaining <= 0,
				'next'      => $page + 1,
			) );
		}

		public static function ajax_backup_delete() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$snapshot = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
			if ( '' !== $snapshot ) {
				TisaCase_Desc_Backup::delete( $snapshot );
			}

			wp_send_json_success();
		}

		/* ---------------------------------------------------------------- */
		/* AJAX: detect the prep-time meta key + apply prep time             */
		/* ---------------------------------------------------------------- */

		public static function ajax_meta_detect() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$options    = TisaCase_Desc_Core::get_options();
			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product    = $product_id ? wc_get_product( $product_id ) : null;

			// Prefer a printed product so the field is present in its meta.
			if ( ! $product ) {
				for ( $p = 1; $p <= 6; $p++ ) {
					$ids = wc_get_products( array(
						'status'  => TisaCase_Desc_Core::statuses(),
						'limit'   => 50,
						'page'    => $p,
						'orderby' => 'ID',
						'order'   => 'ASC',
						'return'  => 'ids',
					) );
					if ( empty( $ids ) ) {
						break;
					}
					foreach ( $ids as $pid ) {
						$cand = wc_get_product( $pid );
						if ( $cand && TisaCase_Desc_Core::is_printed( $cand->get_sku(), $options['printed_pattern'] ) ) {
							$product = $cand;
							break 2;
						}
					}
				}
			}

			// Fallback: any product at all.
			if ( ! $product ) {
				$ids = wc_get_products( array(
					'status' => TisaCase_Desc_Core::statuses(),
					'limit'  => 1,
					'return' => 'ids',
				) );
				$product = $ids ? wc_get_product( $ids[0] ) : null;
			}

			if ( ! $product ) {
				wp_send_json_error( array( 'message' => __( 'محصولی برای بررسی پیدا نشد.', 'tisacase-desc' ) ) );
			}

			$meta = get_post_meta( $product->get_id() );
			$list = array();

			foreach ( $meta as $key => $vals ) {
				$value = isset( $vals[0] ) ? $vals[0] : '';
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				}
				$value = (string) $value;

				$list[] = array(
					'key'    => $key,
					'value'  => ( mb_strlen( $value ) > 48 ) ? mb_substr( $value, 0, 48 ) . '…' : $value,
					'likely' => (bool) preg_match( '/prep|lead.?time|production|amade|time|آماده|زمان|day/i', $key ),
				);
			}

			usort( $list, function ( $a, $b ) {
				if ( $a['likely'] !== $b['likely'] ) {
					return $a['likely'] ? -1 : 1;
				}
				return strcmp( $a['key'], $b['key'] );
			} );

			wp_send_json_success( array(
				'product_id' => $product->get_id(),
				'name'       => $product->get_name(),
				'sku'        => $product->get_sku(),
				'meta'       => $list,
			) );
		}

		public static function ajax_prep_apply() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$options = TisaCase_Desc_Core::get_options();

			// Take the values typed in the form, so the button works even
			// before pressing "ذخیره تنظیمات". They are persisted as well.
			$overrides = array();
			if ( isset( $_POST['prep_meta_key'] ) && '' !== trim( (string) sanitize_text_field( wp_unslash( $_POST['prep_meta_key'] ) ) ) ) {
				$overrides['prep_meta_key'] = trim( sanitize_text_field( wp_unslash( $_POST['prep_meta_key'] ) ) );
			}
			if ( isset( $_POST['prep_value'] ) && '' !== trim( (string) sanitize_text_field( wp_unslash( $_POST['prep_value'] ) ) ) ) {
				$overrides['prep_value'] = trim( sanitize_text_field( wp_unslash( $_POST['prep_value'] ) ) );
			}
			if ( isset( $_POST['prep_only_empty'] ) ) {
				$overrides['prep_only_empty'] = empty( $_POST['prep_only_empty'] ) ? 0 : 1;
			}
			if ( ! empty( $overrides ) ) {
				$overrides['prep_enabled'] = 1; // Running the tool implies the feature is on.
				$options = array_merge( $options, $overrides );
				update_option( TisaCase_Desc_Core::OPTION, $options );
			}

			if ( empty( $options['prep_enabled'] ) || '' === trim( (string) $options['prep_meta_key'] ) || '' === trim( (string) $options['prep_value'] ) ) {
				wp_send_json_error( array( 'message' => __( 'ابتدا بخش زمان آماده‌سازی را فعال و کلید فیلد و مقدار را تعیین کنید.', 'tisacase-desc' ) ) );
			}

			$batch  = self::bulk_batch_size( $options );
			$page   = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

			$snapshot = isset( $_POST['snapshot'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot'] ) ) : '';
			if ( '' === $snapshot || ! TisaCase_Desc_Backup::get( $snapshot ) ) {
				$snapshot = TisaCase_Desc_Backup::create( __( 'زمان آماده‌سازی چاپی', 'tisacase-desc' ) );
			}

			$started = microtime( true );
			$budget  = self::ajax_budget_seconds();

			$ids = wc_get_products( array(
				'status'  => TisaCase_Desc_Core::statuses(),
				'limit'   => $batch,
				'page'    => $page + 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			) );

			$processed   = 0;
			$changed     = 0;
			$next_offset = min( $offset, count( $ids ) );

			for ( $index = $next_offset; $index < count( $ids ); $index++ ) {
				if ( $processed > 0 && microtime( true ) - $started >= $budget ) {
					break;
				}
				$product_id  = $ids[ $index ];
				$next_offset = $index + 1;
				$processed++;
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}
				if ( TisaCase_Desc_Backup::apply_prep_with_backup( $snapshot, $product_id ) ) {
					$changed++;
				}
			}

			$page_complete = $next_offset >= count( $ids );
			$done = $page_complete && count( $ids ) < $batch;
			$next_page = $page_complete ? $page + 1 : $page;
			if ( $page_complete ) {
				$next_offset = 0;
			}

			if ( $done ) {
				$snap = TisaCase_Desc_Backup::get( $snapshot );
				if ( $snap && empty( $snap['items'] ) ) {
					TisaCase_Desc_Backup::delete( $snapshot );
					$snapshot = '';
				}
			}

			wp_send_json_success( array(
				'page'      => $page,
				'processed' => $processed,
				'changed'   => $changed,
				'done'      => $done,
				'next'        => $next_page,
				'next_page'   => $next_page,
				'next_offset' => $next_offset,
				'snapshot'    => $snapshot,
			) );
		}

		/** Conservative hard ceiling shared by scan and bulk mutation endpoints. */
		private static function bulk_batch_size( $options ) {
			$size = isset( $options['batch_size'] ) ? absint( $options['batch_size'] ) : 50;
			return max( 1, min( 100, $size ) );
		}

		/** Per-request wall-clock budget; a batch resumes from its exact item offset. */
		private static function ajax_budget_seconds() {
			$seconds = (int) apply_filters( 'tisacase_desc_ajax_budget_seconds', 5 );
			return max( 1, min( 10, $seconds ) );
		}

		private static function status_label( $status ) {
			$map = array(
				'publish' => __( 'منتشرشده', 'tisacase-desc' ),
				'draft'   => __( 'پیش‌نویس', 'tisacase-desc' ),
				'pending' => __( 'در انتظار بازبینی', 'tisacase-desc' ),
				'private' => __( 'خصوصی', 'tisacase-desc' ),
			);
			return isset( $map[ $status ] ) ? $map[ $status ] : $status;
		}

		private static function rule_label( $rule ) {
			$map = array(
				'printed' => __( 'محصول چاپی', 'tisacase-desc' ),
				'frame'   => __( 'محصول قاب', 'tisacase-desc' ),
				'none'    => __( 'بدون قانون', 'tisacase-desc' ),
			);
			return isset( $map[ $rule ] ) ? $map[ $rule ] : $rule;
		}

		/* ---------------------------------------------------------------- */
		/* AJAX: live preview                                                */
		/* ---------------------------------------------------------------- */

		public static function ajax_preview() {
			self::ajax_guard();
			check_ajax_referer( self::NONCE, 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'message' => __( 'دسترسی کافی ندارید.', 'tisacase-desc' ) ) );
			}

			$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
			$product    = wc_get_product( $product_id );

			if ( ! $product ) {
				wp_send_json_error( array( 'message' => __( 'محصولی با این شناسه پیدا نشد.', 'tisacase-desc' ) ) );
			}

			$options = TisaCase_Desc_Core::get_options();
			$info    = TisaCase_Desc_Core::match_info( $product, $options );

			$labels = array(
				'printed' => __( 'محصول چاپی', 'tisacase-desc' ),
				'frame'   => __( 'محصول قاب', 'tisacase-desc' ),
				'none'    => __( 'بدون قانون', 'tisacase-desc' ),
			);

			wp_send_json_success( array(
				'id'      => $product->get_id(),
				'name'    => $product->get_name(),
				'sku'     => $product->get_sku(),
				'rule'    => $info['rule'],
				'label'   => isset( $labels[ $info['rule'] ] ) ? $labels[ $info['rule'] ] : '',
				'html'    => $info['html'],
				'current' => $product->get_description(),
			) );
		}

		/* ---------------------------------------------------------------- */
		/* Render                                                             */
		/* ---------------------------------------------------------------- */

		public static function render() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'دسترسی کافی ندارید.', 'tisacase-desc' ) );
			}

			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
			if ( ! in_array( $tab, array( 'settings', 'tools', 'backups' ), true ) ) {
				$tab = 'settings';
			}

			$opts = TisaCase_Desc_Core::get_options();
			?>
			<div class="tc-wrap wrap" dir="rtl">
				<header class="tc-hero">
					<div class="tc-hero-row">
						<div class="tc-hero-mark" aria-hidden="true"><?php echo self::icon_svg(); // phpcs:ignore ?></div>
						<div class="tc-hero-text">
							<h1 class="tc-hero-title"><?php echo esc_html__( 'قوانین توضیحات محصول', 'tisacase-desc' ); ?></h1>
							<p class="tc-hero-sub"><?php echo esc_html__( 'درج خودکار توضیحات چاپی و هشدار قاب، با اسکن و بازگردانی', 'tisacase-desc' ); ?></p>
						</div>
						<span class="tc-hero-ver" dir="ltr">v<?php echo esc_html( TISACASE_DESC_VERSION ); ?></span>
					</div>
				<nav class="tc-tabs" role="tablist">
					<a class="tc-tab <?php echo 'settings' === $tab ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( 'settings' ) ); ?>">
						<?php echo esc_html__( 'تنظیمات', 'tisacase-desc' ); ?>
					</a>
					<a class="tc-tab <?php echo 'tools' === $tab ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( 'tools' ) ); ?>">
						<?php echo esc_html__( 'ابزارها', 'tisacase-desc' ); ?>
					</a>
					<a class="tc-tab <?php echo 'backups' === $tab ? 'is-active' : ''; ?>"
						href="<?php echo esc_url( self::url( 'backups' ) ); ?>">
						<?php echo esc_html__( 'بازگردانی', 'tisacase-desc' ); ?>
					</a>
				</nav>
				</header>
				<?php self::notices(); ?>

				<?php if ( 'tools' === $tab ) : ?>
					<?php self::render_tools( $opts ); ?>
				<?php elseif ( 'backups' === $tab ) : ?>
					<?php self::render_backups( $opts ); ?>
				<?php else : ?>
					<?php self::render_settings( $opts ); ?>
				<?php endif; ?>

			</div>
			<?php
		}

		private static function url( $tab ) {
			return add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
		}

		private static function header() {
			?>
			<header class="tc-hero">
				<div class="tc-hero-mark" aria-hidden="true"><?php echo self::icon_svg(); // phpcs:ignore ?></div>
				<div class="tc-hero-text">
					<h1 class="tc-hero-title"><?php echo esc_html__( 'قوانین توضیحات محصول', 'tisacase-desc' ); ?></h1>
					<p class="tc-hero-sub"><?php echo esc_html__( 'مدیریت خودکار توضیحات محصولات چاپی و قاب در فروشگاه ووکامرس شما', 'tisacase-desc' ); ?></p>
				</div>
				<div class="tc-hero-badges">
					<span class="tc-badge tc-badge--teal"><?php echo esc_html__( 'ووکامرس', 'tisacase-desc' ); ?></span>
					<span class="tc-badge">v<?php echo esc_html( TISACASE_DESC_VERSION ); ?></span>
				</div>
			</header>
			<?php
		}

		private static function notices() {
			if ( isset( $_GET['saved'] ) ) { // phpcs:ignore
				$msg = isset( $_GET['reset'] ) // phpcs:ignore
					? __( 'تنظیمات به پیش‌فرض بازگردانده شد.', 'tisacase-desc' )
					: __( 'تنظیمات با موفقیت ذخیره شد.', 'tisacase-desc' );
				echo '<div class="tc-notice tc-notice--success">' . esc_html( $msg ) . '</div>';
			}
		}

		/* ---------------------------------------------------------------- */
		/* Settings tab                                                      */
		/* ---------------------------------------------------------------- */

		private static function render_settings( $opts ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tc-form">
				<input type="hidden" name="action" value="tisacase_desc_save">
				<?php wp_nonce_field( 'tisacase_desc_save' ); ?>

				<!-- General -->
				<section class="tc-card">
					<header class="tc-card-head">
						<span class="tc-card-icon tc-card-icon--ink" aria-hidden="true"></span>
						<div>
							<h2><?php echo esc_html__( 'تنظیمات عمومی', 'tisacase-desc' ); ?></h2>
							<p><?php echo esc_html__( 'رفتار کلی افزونه و همگام‌سازی خودکار.', 'tisacase-desc' ); ?></p>
						</div>
					</header>
					<div class="tc-card-body">
						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-enabled"><?php echo esc_html__( 'همگام‌سازی خودکار', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'هنگام ایجاد یا ویرایش محصول (دستی، ادمین یا REST)، توضیحات به‌صورت خودکار اصلاح شود.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<label class="tc-switch">
									<input type="checkbox" name="enabled" id="tc-enabled" value="1" <?php checked( ! empty( $opts['enabled'] ) ); ?>>
									<span class="tc-switch-slider"></span>
								</label>
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-clear"><?php echo esc_html__( 'پاک‌کردن توضیحات نامرتبط', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'اگر روشن باشد، توضیحات محصولاتی که مشمول هیچ قانونی نیستند خالی می‌شود. به‌صورت پیش‌فرض خاموش است تا محصولاتِ بدون قانون دست‌نخورده بمانند.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<label class="tc-switch">
									<input type="checkbox" name="clear_unmatched" id="tc-clear" value="1" <?php checked( ! empty( $opts['clear_unmatched'] ) ); ?>>
									<span class="tc-switch-slider"></span>
								</label>
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-contains"><?php echo esc_html__( 'رد در صورت شامل بودن', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'اگر توضیحات فعلی محصول از قبل شامل متنِ توضیحات جدید باشد، ویرایش انجام نشود (صرفِ شامل بودن کافی است).', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<label class="tc-switch">
									<input type="checkbox" name="skip_if_contains" id="tc-contains" value="1" <?php checked( ! empty( $opts['skip_if_contains'] ) ); ?>>
									<span class="tc-switch-slider"></span>
								</label>
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-batch"><?php echo esc_html__( 'تعداد محصول در هر دسته', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'برای اسکن، اصلاح انبوه و زمان آماده‌سازی؛ سقف ۱۰۰ و عدد کوچک‌تر یعنی فشار کمتر روی سرور.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<input type="number" name="batch_size" id="tc-batch" min="1" max="100" step="1"
									value="<?php echo esc_attr( $opts['batch_size'] ); ?>" class="tc-input tc-input--num">
							</div>
						</div>
					</div>
				</section>

				<!-- Printed products -->
				<section class="tc-card">
					<header class="tc-card-head">
						<span class="tc-card-icon tc-card-icon--teal" aria-hidden="true"></span>
						<div>
							<h2><?php echo esc_html__( 'محصولات چاپی', 'tisacase-desc' ); ?></h2>
							<p><?php echo esc_html__( 'محصولاتی که شناسه (SKU) آن‌ها با الگوی زیر مطابقت دارد.', 'tisacase-desc' ); ?></p>
						</div>
					</header>
					<div class="tc-card-body">
						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-pattern"><?php echo esc_html__( 'الگوی SKU (Regex)', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'به‌صورت پیش‌فرض SKUهایی که با CH یا SB شروع می‌شوند.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<input type="text" name="printed_pattern" id="tc-pattern" dir="ltr"
									value="<?php echo esc_attr( $opts['printed_pattern'] ); ?>"
									class="tc-input tc-input--code" placeholder="/^(?:CH|SB)(?:\d|$)/i">
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-url"><?php echo esc_html__( 'آدرس سایت چاپ', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'در توضیحات با توکن {url} جایگزین می‌شود.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<input type="url" name="printed_url" id="tc-url" dir="ltr"
									value="<?php echo esc_attr( $opts['printed_url'] ); ?>"
									class="tc-input" placeholder="https://TISACHAP.COM">
							</div>
						</div>

						<div class="tc-row tc-row--top">
							<div class="tc-row-label">
								<label for="tc-printed-desc"><?php echo esc_html__( 'متن توضیحات (HTML)', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'توکن‌های مجاز: {url} و {site}.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<textarea name="printed_desc" id="tc-printed-desc" rows="5" class="tc-input tc-textarea"><?php echo esc_textarea( $opts['printed_desc'] ); ?></textarea>
							</div>
						</div>
					</div>
				</section>

				<!-- Preparation time -->
				<section class="tc-card">
					<header class="tc-card-head">
						<span class="tc-card-icon tc-card-icon--teal" aria-hidden="true"></span>
						<div>
							<h2><?php echo esc_html__( 'زمان آماده‌سازی محصولات چاپی', 'tisacase-desc' ); ?></h2>
							<p><?php echo esc_html__( 'برای محصولاتی که SKU آن‌ها با الگوی چاپی (CH/SB) مطابقت دارد، مقدار فیلد «زمان آماده‌سازی» به‌صورت خودکار ثبت می‌شود.', 'tisacase-desc' ); ?></p>
						</div>
					</header>
					<div class="tc-card-body">
						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-prep-enabled"><?php echo esc_html__( 'فعال‌سازی', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'ثبت خودکار زمان آماده‌سازی برای محصولات چاپی جدید و موجود.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<label class="tc-switch">
									<input type="checkbox" name="prep_enabled" id="tc-prep-enabled" value="1" <?php checked( ! empty( $opts['prep_enabled'] ) ); ?>>
									<span class="tc-switch-slider"></span>
								</label>
							</div>
						</div>

						<div class="tc-row tc-row--top">
							<div class="tc-row-label">
								<label for="tc-prep-key"><?php echo esc_html__( 'کلید فیلد (Meta Key)', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'کلید دقیق فیلد «زمان آماده‌سازی». با دکمه «تشخیص خودکار» می‌توانید کلید درست را از روی متای یک محصول چاپی پیدا کنید.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field tc-row-field--col">
								<div class="tc-inline">
									<input type="text" name="prep_meta_key" id="tc-prep-key" dir="ltr"
										value="<?php echo esc_attr( $opts['prep_meta_key'] ); ?>"
										class="tc-input tc-input--code" placeholder="_tisacase_prep_time">
									<button type="button" class="tc-btn tc-btn--secondary" id="tc-meta-detect"><?php echo esc_html__( 'تشخیص خودکار', 'tisacase-desc' ); ?></button>
								</div>
								<div id="tc-meta-detect-result" class="tc-detect"></div>
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-prep-value"><?php echo esc_html__( 'مقدار (روز)', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'عددی که برای زمان آماده‌سازی نوشته می‌شود.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<input type="number" name="prep_value" id="tc-prep-value" min="0" step="1"
									value="<?php echo esc_attr( $opts['prep_value'] ); ?>" class="tc-input tc-input--num">
							</div>
						</div>

						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-prep-onlyempty"><?php echo esc_html__( 'فقط در صورت خالی بودن', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'اگر روشن باشد، فقط محصولاتی که این فیلد را ندارند مقدار می‌گیرند و مقادیر موجود دست‌نخورده می‌مانند.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<label class="tc-switch">
									<input type="checkbox" name="prep_only_empty" id="tc-prep-onlyempty" value="1" <?php checked( ! empty( $opts['prep_only_empty'] ) ); ?>>
									<span class="tc-switch-slider"></span>
								</label>
							</div>
						</div>

						<div class="tc-tools-actions tc-tools-actions--tight">
							<button type="button" class="tc-btn tc-btn--primary" id="tc-prep-apply"><?php echo esc_html__( 'اعمال به محصولات چاپی موجود', 'tisacase-desc' ); ?></button>
							<span class="tc-hint-inline"><?php echo esc_html__( 'محصولات جدید هم هنگام ذخیره به‌صورت خودکار مقدار می‌گیرند.', 'tisacase-desc' ); ?></span>
						</div>
						<div class="tc-progress-wrap" id="tc-prep-progress" hidden>
							<div class="tc-progress"><div class="tc-progress-fill" id="tc-prep-fill"></div></div>
							<div class="tc-progress-text" id="tc-prep-text"></div>
						</div>
					</div>
				</section>

				<!-- Frame products -->
				<section class="tc-card">
					<header class="tc-card-head">
						<span class="tc-card-icon tc-card-icon--sage" aria-hidden="true"></span>
						<div>
							<h2><?php echo esc_html__( 'محصولات قاب', 'tisacase-desc' ); ?></h2>
							<p><?php echo esc_html__( 'محصولاتی که عنوان آن‌ها شامل کلمات کلیدی زیر باشد.', 'tisacase-desc' ); ?></p>
						</div>
					</header>
					<div class="tc-card-body">
						<div class="tc-row">
							<div class="tc-row-label">
								<label for="tc-keywords"><?php echo esc_html__( 'کلمات کلیدی عنوان', 'tisacase-desc' ); ?></label>
								<small><?php echo esc_html__( 'چند کلمه را با ویرگول (،) جدا کنید.', 'tisacase-desc' ); ?></small>
							</div>
							<div class="tc-row-field">
								<input type="text" name="frame_keywords" id="tc-keywords"
									value="<?php echo esc_attr( $opts['frame_keywords'] ); ?>"
									class="tc-input" placeholder="قاب">
							</div>
						</div>

						<div class="tc-row tc-row--top">
							<div class="tc-row-label">
								<label for="tc-frame-desc"><?php echo esc_html__( 'متن توضیحات (HTML)', 'tisacase-desc' ); ?></label>
							</div>
							<div class="tc-row-field">
								<textarea name="frame_desc" id="tc-frame-desc" rows="5" class="tc-input tc-textarea"><?php echo esc_textarea( $opts['frame_desc'] ); ?></textarea>
							</div>
						</div>
					</div>
				</section>

				<div class="tc-actions">
					<button type="submit" class="tc-btn tc-btn--primary"><?php echo esc_html__( 'ذخیره تنظیمات', 'tisacase-desc' ); ?></button>
				</div>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tc-reset">
				<input type="hidden" name="action" value="tisacase_desc_reset">
				<?php wp_nonce_field( 'tisacase_desc_reset' ); ?>
				<button type="submit" class="tc-btn tc-btn--ghost" onclick="return confirm('<?php echo esc_js( __( 'تنظیمات به حالت پیش‌فرض برگردد؟', 'tisacase-desc' ) ); ?>');">
					<?php echo esc_html__( 'بازگردانی به پیش‌فرض', 'tisacase-desc' ); ?>
				</button>
			</form>
			<?php
		}

		/* ---------------------------------------------------------------- */
		/* Backups tab                                                      */
		/* ---------------------------------------------------------------- */

		private static function render_backups( $opts ) {
			$backups = TisaCase_Desc_Backup::all();
			uasort( $backups, function ( $a, $b ) {
				return (int) $b['time'] - (int) $a['time'];
			} );
			?>
			<section class="tc-card">
				<header class="tc-card-head">
					<span class="tc-card-icon tc-card-icon--sage" aria-hidden="true"></span>
					<div>
						<h2><?php echo esc_html__( 'بازگردانی (Rollback)', 'tisacase-desc' ); ?></h2>
						<p><?php echo esc_html__( 'قبل از هر اصلاح انبوه یا انتخابی، به‌صورت خودکار از توضیحات قبلی محصولات پشتیبان گرفته می‌شود. اگر نتیجه مطابق انتظار نبود، از اینجا به حالت قبل برگردید.', 'tisacase-desc' ); ?></p>
					</div>
				</header>
				<div class="tc-card-body">
					<?php if ( empty( $backups ) ) : ?>
						<div class="tc-notice tc-notice--success">
							<span class="tc-notice-icon">✔</span>
							<?php echo esc_html__( 'هنوز پشتیبانی ایجاد نشده است. اولین باری که «اصلاح انبوه» یا «اصلاح موارد انتخاب‌شده» را اجرا کنید، اینجا یک پشتیبان ساخته می‌شود.', 'tisacase-desc' ); ?>
						</div>
					<?php else : ?>
						<div class="tc-backup-list">
							<?php foreach ( $backups as $b ) : ?>
								<div class="tc-backup-row" data-snapshot="<?php echo esc_attr( $b['id'] ); ?>">
									<div class="tc-backup-icon" aria-hidden="true">🗂</div>
									<div class="tc-backup-info">
										<span class="tc-backup-label"><?php echo esc_html( $b['label'] ); ?></span>
										<span class="tc-backup-meta">
											<?php
											echo esc_html( date_i18n( 'Y/m/d — H:i', (int) $b['time'] ) );
											echo ' &middot; ';
											$n = isset( $b['items'] ) && is_array( $b['items'] ) ? count( $b['items'] ) : 0;
											echo esc_html( sprintf( _n( '%d محصول', '%d محصول', $n, 'tisacase-desc' ), $n ) );
											?>
										</span>
									</div>
									<?php if ( ! empty( $b['restored'] ) ) : ?>
										<span class="tc-pill tc-pill--none"><?php echo esc_html__( 'بازگردانده‌شده', 'tisacase-desc' ); ?></span>
									<?php else : ?>
										<button type="button" class="tc-btn tc-btn--secondary tc-restore-btn"><?php echo esc_html__( 'بازگردانی', 'tisacase-desc' ); ?></button>
									<?php endif; ?>
									<button type="button" class="tc-btn tc-btn--ghost tc-backup-delete"><?php echo esc_html__( 'حذف', 'tisacase-desc' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="tc-progress-wrap" id="tc-restore-progress" hidden>
							<div class="tc-progress"><div class="tc-progress-fill" id="tc-restore-fill"></div></div>
							<div class="tc-progress-text" id="tc-restore-text"></div>
						</div>
						<div id="tc-restore-note"></div>
					<?php endif; ?>
				</div>
			</section>

			<section class="tc-card">
				<header class="tc-card-head">
					<span class="tc-card-icon tc-card-icon--ink" aria-hidden="true"></span>
					<div>
						<h2><?php echo esc_html__( 'نحوه کار بازگردانی', 'tisacase-desc' ); ?></h2>
						<p><?php echo esc_html__( 'چند نکته برای اطمینان از سلامت محصولات:', 'tisacase-desc' ); ?></p>
					</div>
				</header>
				<div class="tc-card-body">
					<ul class="tc-notes">
						<li><?php echo esc_html__( 'بازگردانی فقط محصولاتی را برمی‌گرداند که توضیحاتشان همچنان همان مقداری است که این افزونه نوشته بود.', 'tisacase-desc' ); ?></li>
						<li><?php echo esc_html__( 'اگر بعد از اصلاح، توضیحات محصولی را دستی ویرایش کرده باشید، آن محصول در بازگردانی رد می‌شود تا ویرایش شما از بین نرود.', 'tisacase-desc' ); ?></li>
						<li><?php echo esc_html__( 'تا ۱۰ پشتیبان اخیر نگهداری می‌شود؛ قدیمی‌ترها به‌صورت خودکار حذف می‌شوند.', 'tisacase-desc' ); ?></li>
						<li><?php echo esc_html__( 'حالت آزمایشی (dry-run) و پیش‌نمایش زنده هیچ تغییری اعمال نمی‌کنند و پشتیبانی هم نمی‌سازند.', 'tisacase-desc' ); ?></li>
					</ul>
				</div>
			</section>
			<?php
		}

		/* ---------------------------------------------------------------- */
		/* Tools tab                                                         */
		/* ---------------------------------------------------------------- */

		private static function render_tools( $opts ) {
			$has_wc = function_exists( 'wc_get_products' );
			?>
			<section class="tc-card">
				<header class="tc-card-head">
					<span class="tc-card-icon tc-card-icon--teal" aria-hidden="true"></span>
					<div>
						<h2><?php echo esc_html__( 'اسکن محصولات و اصلاح انتخابی', 'tisacase-desc' ); ?></h2>
						<p><?php echo esc_html__( 'محصولاتی که توضیحاتشان با قوانین مطابقت ندارد را پیدا می‌کند و در یک لیست نشان می‌دهد؛ هیچ تغییری اعمال نمی‌شود مگر اینکه خودتان موارد را انتخاب و اصلاح کنید.', 'tisacase-desc' ); ?></p>
					</div>
				</header>
				<div class="tc-card-body">
					<?php if ( ! $has_wc ) : ?>
						<div class="tc-notice tc-notice--error"><?php echo esc_html__( 'ووکامرس فعال نیست.', 'tisacase-desc' ); ?></div>
					<?php else : ?>
						<div class="tc-tools-actions">
							<button type="button" class="tc-btn tc-btn--primary" id="tc-scan">
								<?php echo esc_html__( 'شروع اسکن محصولات', 'tisacase-desc' ); ?>
							</button>
							<span class="tc-hint-inline"><?php echo esc_html__( 'اسکن فقط بررسی می‌کند و چیزی را تغییر نمی‌دهد.', 'tisacase-desc' ); ?></span>
						</div>

						<div class="tc-progress-wrap" id="tc-scan-progress-wrap" hidden>
							<div class="tc-progress"><div class="tc-progress-fill" id="tc-scan-progress-fill"></div></div>
							<div class="tc-progress-text" id="tc-scan-progress-text"><?php echo esc_html__( 'در حال اسکن…', 'tisacase-desc' ); ?></div>
						</div>

						<div id="tc-scan-result" hidden>
							<div class="tc-scan-toolbar">
								<div class="tc-scan-filters" id="tc-scan-filters">
									<button type="button" class="tc-chip is-active" data-filter="all"><?php echo esc_html__( 'همه', 'tisacase-desc' ); ?></button>
									<button type="button" class="tc-chip" data-filter="printed"><?php echo esc_html__( 'چاپی', 'tisacase-desc' ); ?></button>
									<button type="button" class="tc-chip" data-filter="frame"><?php echo esc_html__( 'قاب', 'tisacase-desc' ); ?></button>
									<button type="button" class="tc-chip" data-filter="none"><?php echo esc_html__( 'بدون قانون', 'tisacase-desc' ); ?></button>
								</div>
								<label class="tc-check" id="tc-scan-selectall-wrap">
									<input type="checkbox" id="tc-scan-selectall">
									<span><?php echo esc_html__( 'انتخاب همه', 'tisacase-desc' ); ?></span>
								</label>
							</div>

							<div class="tc-scan-summary" id="tc-scan-summary"></div>
							<div id="tc-scan-note"></div>
							<div class="tc-scan-list" id="tc-scan-list"></div>

							<div class="tc-scan-apply">
								<button type="button" class="tc-btn tc-btn--primary" id="tc-apply" disabled>
									<?php echo esc_html__( 'اصلاح موارد انتخاب‌شده', 'tisacase-desc' ); ?>
								</button>
								<span class="tc-hint" id="tc-apply-hint"></span>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<section class="tc-card">
				<header class="tc-card-head">
					<span class="tc-card-icon tc-card-icon--teal" aria-hidden="true"></span>
					<div>
						<h2><?php echo esc_html__( 'اصلاح انبوه توضیحات', 'tisacase-desc' ); ?></h2>
						<p><?php echo esc_html__( 'همه محصولات (قدیمی و جدید) را بررسی کرده و توضیحات را مطابق قوانین بازنویسی می‌کند.', 'tisacase-desc' ); ?></p>
					</div>
				</header>
				<div class="tc-card-body">
					<?php if ( ! $has_wc ) : ?>
						<div class="tc-notice tc-notice--error"><?php echo esc_html__( 'ووکامرس فعال نیست.', 'tisacase-desc' ); ?></div>
					<?php else : ?>
						<div class="tc-stats">
							<div class="tc-stat">
								<span class="tc-stat-value" id="tc-total">—</span>
								<span class="tc-stat-label"><?php echo esc_html__( 'کل محصولات', 'tisacase-desc' ); ?></span>
							</div>
							<div class="tc-stat">
								<span class="tc-stat-value" id="tc-processed">—</span>
								<span class="tc-stat-label"><?php echo esc_html__( 'پردازش‌شده', 'tisacase-desc' ); ?></span>
							</div>
							<div class="tc-stat tc-stat--accent">
								<span class="tc-stat-value" id="tc-changed">—</span>
								<span class="tc-stat-label"><?php echo esc_html__( 'اصلاح‌شده', 'tisacase-desc' ); ?></span>
							</div>
						</div>

						<div class="tc-progress-wrap" id="tc-progress-wrap" hidden>
							<div class="tc-progress"><div class="tc-progress-fill" id="tc-progress-fill"></div></div>
							<div class="tc-progress-text" id="tc-progress-text"><?php echo esc_html__( 'در حال پردازش…', 'tisacase-desc' ); ?></div>
						</div>

						<div class="tc-tools-actions">
							<label class="tc-check">
								<input type="checkbox" id="tc-dry" value="1">
								<span><?php echo esc_html__( 'حالت آزمایشی (فقط محاسبه، بدون ذخیره)', 'tisacase-desc' ); ?></span>
							</label>
							<button type="button" class="tc-btn tc-btn--primary" id="tc-run">
								<?php echo esc_html__( 'شروع اصلاح همه محصولات', 'tisacase-desc' ); ?>
							</button>
						</div>
						<p class="tc-hint"><?php echo esc_html__( 'پردازش در دسته‌های کوچک انجام می‌شود تا سرور دچار مشکل نشود؛ صفحه را نبندید.', 'tisacase-desc' ); ?></p>
					<?php endif; ?>
				</div>
			</section>

			<section class="tc-card">
				<header class="tc-card-head">
					<span class="tc-card-icon tc-card-icon--sage" aria-hidden="true"></span>
					<div>
						<h2><?php echo esc_html__( 'پیش‌نمایش زنده', 'tisacase-desc' ); ?></h2>
						<p><?php echo esc_html__( 'شناسه یک محصول را وارد کنید تا ببینید کدام قانون روی آن اعمال می‌شود.', 'tisacase-desc' ); ?></p>
					</div>
				</header>
				<div class="tc-card-body">
					<div class="tc-preview-form">
						<input type="number" id="tc-preview-id" class="tc-input tc-input--num" placeholder="<?php echo esc_attr__( 'شناسه محصول، مثلاً ۱۲۳', 'tisacase-desc' ); ?>">
						<button type="button" class="tc-btn tc-btn--secondary" id="tc-preview-run"><?php echo esc_html__( 'پیش‌نمایش', 'tisacase-desc' ); ?></button>
					</div>
					<div id="tc-preview-result" class="tc-preview-result" hidden></div>
				</div>
			</section>
			<?php
		}
	}
}
