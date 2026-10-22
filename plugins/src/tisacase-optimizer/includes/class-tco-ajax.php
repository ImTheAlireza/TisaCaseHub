<?php
/**
 * مسیریاب مرکزی و ضد-تایم‌اوت AJAX برای تمام عملیات‌های مرکز بهینه‌سازی تیساکیس.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Ajax' ) ) {

	/**
	 * هندلر AJAX با سقف زمانی تطبیقی، اسکن مرحله‌ای `scan_step` و محافظ خطای Fatal سطح سرور.
	 */
	final class TisaCase_Optimizer_Ajax {

		const ACTION     = 'tisacase_optimizer';
		const NONCE_NAME = 'tisacase_optimizer_nonce';
		const BUDGET_SEC = 3.5;

		/** @var string|null بافر اضطراری برای گزارش JSON تمیز در صورت اتمام حافظه/زمان PHP */
		private static $emergency_buffer = null;

		/** @var bool آیا پاسخ JSON با موفقیت ارسال شد؟ */
		private static $responded = false;

		/**
		 * بررسی سطح دسترسی مجاز.
		 *
		 * @return bool
		 */
		public static function user_can_access() {
			$allowed = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
			return (bool) apply_filters( 'tisacase_optimizer_user_can_access', $allowed );
		}

		/**
		 * ثبت اکشن AJAX.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'dispatch' ) );
		}

		/**
		 * ارسال JSON موفقیت و علامت‌گذاری پایان سالم پاسخ.
		 *
		 * @param mixed $data دادهٔ پاسخ.
		 * @return void
		 */
		private static function ok( $data ) {
			self::$responded        = true;
			self::$emergency_buffer = null;
			wp_send_json_success( $data );
		}

		/**
		 * ارسال JSON خطا و علامت‌گذاری پایان پاسخ.
		 *
		 * @param string $message پیام خطا.
		 * @param int    $status کد HTTP.
		 * @return void
		 */
		private static function fail( $message, $status = 400 ) {
			self::$responded        = true;
			self::$emergency_buffer = null;
			wp_send_json_error( array( 'message' => $message ), $status );
		}

		/**
		 * دریافت، اعتبارسنجی و هدایت درخواست‌های AJAX با محافظت ۱۰۰٪ در برابر Fatal Error.
		 *
		 * @return void
		 */
		public static function dispatch() {
			self::$responded        = false;
			self::$emergency_buffer = str_repeat( 'x', 65536 );

			register_shutdown_function(
				static function () {
					self::$emergency_buffer = null;
					if ( self::$responded ) {
						return;
					}
					$err = error_get_last();
					if ( $err && in_array( (int) $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
						if ( ! headers_sent() ) {
							header( 'Content-Type: application/json; charset=utf-8' );
						}
						echo wp_json_encode(
							array(
								'success' => false,
								'data'    => array(
									'message' => sprintf(
										'خطای سطح سرور PHP: %s (خط %d در %s)',
										(string) $err['message'],
										(int) $err['line'],
										basename( (string) $err['file'] )
									),
								),
							),
							JSON_UNESCAPED_UNICODE
						);
					}
				}
			);

			if ( ! check_ajax_referer( self::NONCE_NAME, 'nonce', false ) ) {
				self::fail( __( 'نشست امنیتی منقضی شده است؛ صفحه را یک‌بار نوسازی کنید.', 'tisacase-optimizer' ), 403 );
			}
			if ( ! self::user_can_access() ) {
				self::fail( __( 'شما مجوز دسترسی به مرکز بهینه‌سازی را ندارید.', 'tisacase-optimizer' ), 403 );
			}

			if ( function_exists( 'ignore_user_abort' ) ) {
				ignore_user_abort( true );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';

			try {
				switch ( $op ) {
					case 'overview':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$force = ! empty( $_POST['force_rescan'] );
						if ( $force || ! TisaCase_Optimizer_Media_Inspector::has_ready_catalog() ) {
							self::ok( array( 'needs_scan' => true ) );
						}
						self::ok( TisaCase_Optimizer_Media_Inspector::overview_stats( false ) );
						break;

					case 'scan_step':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$reset       = ! empty( $_POST['reset'] );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$fast_finish = ! empty( $_POST['fast_finish'] );
						$step        = TisaCase_Optimizer_Reference_Scanner::step_scan( $reset, $fast_finish );
						if ( ! empty( $step['done'] ) ) {
							TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();
							$step['overview'] = TisaCase_Optimizer_Media_Inspector::overview_stats( false );
						}
						self::ok( $step );
						break;

					case 'query_media':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$raw  = wp_unslash( $_POST );
						$data = TisaCase_Optimizer_Media_Inspector::query_items( $raw );
						self::ok( $data );
						break;

					case 'toggle_pin':
						$ids    = self::read_int_list( 'ids' );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$pin    = ! empty( $_POST['pin'] );
						$pinned = TisaCase_Optimizer_Settings::set_pinned( $ids, $pin );
						TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();
						self::ok(
							array(
								'pinned_ids' => $pinned,
								'overview'   => TisaCase_Optimizer_Media_Inspector::overview_stats( false ),
							)
						);
						break;

					case 'remove_items':
						self::handle_remove_items();
						break;

					case 'optimize_batch':
						self::handle_optimize_batch();
						break;

					case 'clean_unscaled':
						self::handle_clean_unscaled();
						break;

					case 'get_duplicates':
						self::ok( TisaCase_Optimizer_Deduplicator::find_duplicate_groups( false ) );
						break;

					case 'merge_duplicates':
						self::handle_merge_duplicates();
						break;

					case 'get_subsizes':
						self::ok( array( 'sizes' => TisaCase_Optimizer_Subsizes::inspect_all_sizes() ) );
						break;

					case 'save_subsizes':
						$disabled = self::read_string_list( 'disabled_sizes' );
						$updated  = TisaCase_Optimizer_Settings::update( array( 'disabled_sizes' => $disabled ) );
						TisaCase_Optimizer_Session::log(
							'save_subsizes',
							__( 'به‌روزرسانی وضعیت تولید زیرسایزهای تصویر', 'tisacase-optimizer' ),
							count( $disabled ),
							0,
							sprintf( '%d سایز غیرفعال شد', count( $disabled ) )
						);
						self::ok(
							array(
								'settings' => $updated,
								'sizes'    => TisaCase_Optimizer_Subsizes::inspect_all_sizes(),
							)
						);
						break;

					case 'clean_disabled_subsizes':
						$disabled = (array) TisaCase_Optimizer_Settings::get( 'disabled_sizes', array() );
						$res      = TisaCase_Optimizer_Subsizes::clean_disabled_subsize_files( $disabled );
						TisaCase_Optimizer_Session::log(
							'clean_subsizes',
							__( 'پاک‌سازی فایل‌های زیرسایزهای غیرفعال‌شده از دیسک', 'tisacase-optimizer' ),
							$res['deleted_files'],
							$res['saved_bytes']
						);
						self::ok(
							array(
								'result'   => $res,
								'sizes'    => TisaCase_Optimizer_Subsizes::inspect_all_sizes(),
								'overview' => TisaCase_Optimizer_Media_Inspector::overview_stats( false ),
							)
						);
						break;

					case 'regen_batch':
						self::handle_regen_batch();
						break;

					case 'get_seo':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$flt  = isset( $_POST['seo_filter'] ) ? sanitize_key( wp_unslash( (string) $_POST['seo_filter'] ) ) : 'missing_alt';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
						self::ok( TisaCase_Optimizer_Image_SEO::audit( $flt, $page ) );
						break;

					case 'save_single_seo':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$aid   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$alt   = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['alt'] ) ) : '';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : null;
						TisaCase_Optimizer_Image_SEO::update_single( $aid, $alt, $title );
						self::ok( array( 'id' => $aid ) );
						break;

					case 'bulk_generate_seo':
						$ids        = self::read_int_list( 'ids' );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$only_empty = ! isset( $_POST['only_empty'] ) || ! empty( $_POST['only_empty'] );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						if ( isset( $_POST['seo_alt_pattern'] ) ) {
							TisaCase_Optimizer_Settings::update(
								array(
									// phpcs:ignore WordPress.Security.NonceVerification.Missing
									'seo_alt_pattern'   => wp_unslash( (string) $_POST['seo_alt_pattern'] ),
									// phpcs:ignore WordPress.Security.NonceVerification.Missing
									'seo_title_pattern' => isset( $_POST['seo_title_pattern'] ) ? wp_unslash( (string) $_POST['seo_title_pattern'] ) : '{product_title}',
									'seo_only_empty'    => $only_empty ? 1 : 0,
								)
							);
						}
						$count = TisaCase_Optimizer_Image_SEO::bulk_generate( $ids, $only_empty );
						TisaCase_Optimizer_Session::log(
							'bulk_seo',
							__( 'تکمیل خودکار متن جایگزین (Alt) و عنوان تصاویر', 'tisacase-optimizer' ),
							$count,
							0
						);
						self::ok(
							array(
								'updated' => $count,
								'audit'   => TisaCase_Optimizer_Image_SEO::audit( 'missing_alt', 1 ),
							)
						);
						break;

					case 'get_vault':
						self::ok( self::vault_payload() );
						break;

					case 'restore_quarantine':
						$qids         = self::read_string_list( 'item_ids' );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$batch_filter = isset( $_POST['batch_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['batch_id'] ) ) : '';
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$restore_all  = ! empty( $_POST['restore_all'] );

						if ( empty( $qids ) && ( '' !== $batch_filter || $restore_all ) ) {
							foreach ( TisaCase_Optimizer_Vault::list_quarantine() as $qi ) {
								if ( empty( $qi['id'] ) ) {
									continue;
								}
								if ( $restore_all || ( '' !== $batch_filter && isset( $qi['batch_id'] ) && $qi['batch_id'] === $batch_filter ) ) {
									$qids[] = (string) $qi['id'];
								}
							}
						}

						$restored     = 0;
						$restored_ids = array();
						foreach ( $qids as $qid ) {
							$r = TisaCase_Optimizer_Vault::restore_quarantine_item( $qid );
							if ( ! is_wp_error( $r ) ) {
								++$restored;
								if ( is_int( $r ) && $r > 0 ) {
									$restored_ids[] = $r;
								} elseif ( is_array( $r ) && ! empty( $r['attachment_id'] ) ) {
									$restored_ids[] = absint( $r['attachment_id'] );
								}
							}
						}
						if ( ! empty( $restored_ids ) && TisaCase_Optimizer_Media_Inspector::has_ready_catalog() ) {
							TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( $restored_ids );
						}
						TisaCase_Optimizer_Media_Inspector::flush_runtime_cache();
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						if ( empty( $_POST['silent_log'] ) && $restored > 0 ) {
							TisaCase_Optimizer_Session::log(
								'restore_quarantine',
								__( 'بازگردانی تصاویر از قرنطینه به مسیر و ID اصلی', 'tisacase-optimizer' ),
								$restored,
								0
							);
						}
						self::ok(
							array(
								'restored' => $restored,
								'vault'    => self::vault_payload(),
								'overview' => TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
									? TisaCase_Optimizer_Media_Inspector::overview_stats( false )
									: null,
							)
						);
						break;

					case 'purge_quarantine':
						$qids  = self::read_string_list( 'item_ids' );
						$freed = 0;
						$count = 0;
						if ( empty( $qids ) ) {
							foreach ( TisaCase_Optimizer_Vault::list_quarantine() as $qi ) {
								if ( ! empty( $qi['id'] ) ) {
									$freed += TisaCase_Optimizer_Vault::delete_quarantine_permanently( (string) $qi['id'] );
									++$count;
								}
							}
						} else {
							foreach ( $qids as $qid ) {
								$freed += TisaCase_Optimizer_Vault::delete_quarantine_permanently( $qid );
								++$count;
							}
						}
						TisaCase_Optimizer_Session::log(
							'purge_quarantine',
							__( 'حذف دائمی بسته‌های قرنطینه از دیسک خزانه', 'tisacase-optimizer' ),
							$count,
							$freed
						);
						self::ok(
							array(
								'deleted'     => $count,
								'saved_bytes' => $freed,
								'vault'       => self::vault_payload(),
							)
						);
						break;

					case 'restore_original':
						$ids = self::read_int_list( 'ids' );
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$aid = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
						if ( $aid > 0 && empty( $ids ) ) {
							$ids = array( $aid );
						}
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						if ( ! empty( $_POST['restore_all'] ) ) {
							$ids = array_map( 'absint', array_keys( TisaCase_Optimizer_Vault::list_original_backups() ) );
						}
						if ( empty( $ids ) ) {
							self::fail( __( 'شناسهٔ تصویر برای بازگردانی ارسال نشده است.', 'tisacase-optimizer' ), 400 );
						}
						$restored_orig = array();
						foreach ( $ids as $tid ) {
							$res = TisaCase_Optimizer_Vault::restore_original_backup( $tid );
							if ( ! is_wp_error( $res ) ) {
								$restored_orig[] = $tid;
							} elseif ( 1 === count( $ids ) ) {
								self::fail( $res->get_error_message(), 400 );
							}
						}
						if ( ! empty( $restored_orig ) && TisaCase_Optimizer_Media_Inspector::has_ready_catalog() ) {
							TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( $restored_orig );
						}
						if ( ! empty( $restored_orig ) ) {
							TisaCase_Optimizer_Session::log(
								'restore_original',
								__( 'بازگردانی فایل اصلی اولیهٔ پیش از فشرده‌سازی', 'tisacase-optimizer' ),
								count( $restored_orig ),
								0
							);
						}
						self::ok(
							array(
								'restored' => count( $restored_orig ),
								'vault'    => self::vault_payload(),
								'overview' => TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
									? TisaCase_Optimizer_Media_Inspector::overview_stats( false )
									: null,
							)
						);
						break;

					case 'purge_originals':
						$res = TisaCase_Optimizer_Vault::purge_original_backups();
						TisaCase_Optimizer_Session::log(
							'purge_originals',
							__( 'آزادسازی پوشهٔ نسخه‌های پشتیبان پیش از فشرده‌سازی', 'tisacase-optimizer' ),
							$res['count'],
							$res['bytes']
						);
						self::ok(
							array(
								'result' => $res,
								'vault'  => self::vault_payload(),
							)
						);
						break;

					case 'clear_history':
						TisaCase_Optimizer_Session::clear_history();
						self::ok( array( 'vault' => self::vault_payload() ) );
						break;

					case 'save_settings':
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$raw_settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
						$updated      = TisaCase_Optimizer_Settings::update( $raw_settings );
						TisaCase_Optimizer_Session::log( 'save_settings', __( 'ذخیرهٔ تنظیمات مرکز بهینه‌سازی', 'tisacase-optimizer' ), 0, 0, '', 'info' );
						self::ok( array( 'settings' => $updated ) );
						break;

					default:
						self::fail( __( 'عملیات درخواستی شناخته نشد.', 'tisacase-optimizer' ), 400 );
				}
			} catch ( Throwable $e ) {
				self::fail( $e->getMessage(), 500 );
			}
		}

		/**
		 * حذف یا قرنطینهٔ تکی/گروهی با به‌روزرسانی درجای کاتالوگ (بدون اسکن مجدد سنگین!).
		 *
		 * @return void
		 */
		private static function handle_remove_items() {
			$started     = microtime( true );
			$ids         = self::read_int_list( 'ids' );
			$rel_paths   = self::read_string_list( 'rel_paths' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$batch_id    = isset( $_POST['batch_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['batch_id'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode_raw    = isset( $_POST['delete_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['delete_mode'] ) ) : '';
			$mode        = in_array( $mode_raw, array( 'quarantine', 'permanent' ), true )
				? $mode_raw
				: (string) TisaCase_Optimizer_Settings::get( 'delete_mode', 'quarantine' );

			if ( '' === $batch_id && 'quarantine' === $mode ) {
				$batch_id = 'run' . gmdate( 'YmdHis' ) . strtolower( wp_generate_password( 4, false, false ) );
			}

			$pinned_map  = array_fill_keys( TisaCase_Optimizer_Settings::pinned_ids(), true );
			$done_ids    = array();
			$removed_ids = array();
			$done_paths  = array();
			$saved_bytes = 0;

			foreach ( $ids as $aid ) {
				if ( isset( $pinned_map[ $aid ] ) ) {
					$done_ids[] = $aid;
					continue;
				}
				if ( 'permanent' === $mode ) {
					$res = TisaCase_Optimizer_Vault::delete_attachment_permanently( $aid );
					if ( ! is_wp_error( $res ) ) {
						$saved_bytes   += (int) $res['saved_bytes'];
						$removed_ids[]  = $aid;
					}
				} else {
					$res = TisaCase_Optimizer_Vault::quarantine_attachment( $aid, 'unused', $batch_id );
					if ( ! is_wp_error( $res ) ) {
						$saved_bytes   += (int) $res['total_bytes'];
						$removed_ids[]  = $aid;
					}
				}
				$done_ids[] = $aid;
				if ( ( microtime( true ) - $started ) >= self::BUDGET_SEC ) {
					break;
				}
			}

			foreach ( $rel_paths as $rp ) {
				if ( ( microtime( true ) - $started ) >= self::BUDGET_SEC ) {
					break;
				}
				if ( 'permanent' === $mode ) {
					$uploads = wp_upload_dir();
					$safe    = TisaCase_Optimizer_Vault::assert_inside_uploads( trailingslashit( $uploads['basedir'] ) . ltrim( $rp, '/' ) );
					if ( false !== $safe && is_file( $safe ) ) {
						$fbytes       = max( 0, (int) @filesize( $safe ) );
						wp_delete_file( $safe );
						$saved_bytes += $fbytes;
						TisaCase_Optimizer_Settings::bump_stats(
							array(
								'deleted_count' => 1,
								'saved_bytes'   => $fbytes,
							)
						);
					}
				} else {
					$res = TisaCase_Optimizer_Vault::quarantine_orphan_file( $rp, $batch_id );
					if ( ! is_wp_error( $res ) ) {
						$saved_bytes += (int) $res['total_bytes'];
					}
				}
				$done_paths[] = $rp;
			}

			$count_done = count( $done_ids ) + count( $done_paths );
			TisaCase_Optimizer_Media_Inspector::remove_from_cached_catalog( $removed_ids, $done_paths );

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['log_final'] ) && $count_done > 0 ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$total_n = isset( $_POST['summary_count'] ) ? max( $count_done, absint( $_POST['summary_count'] ) ) : $count_done;
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$total_b = isset( $_POST['summary_bytes'] ) ? max( $saved_bytes, absint( $_POST['summary_bytes'] ) ) : $saved_bytes;
				TisaCase_Optimizer_Session::log(
					'permanent' === $mode ? 'delete_permanent' : 'move_quarantine',
					'permanent' === $mode
						? __( 'حذف قطعی تصاویر انتخاب‌شده از هاست و دیتابیس', 'tisacase-optimizer' )
						: __( 'انتقال ایمن تصاویر به قرنطینهٔ ۳۰ روزه (قابل بازگردانی)', 'tisacase-optimizer' ),
					$total_n,
					$total_b,
					'',
					'success',
					$batch_id
				);
			}

			self::ok(
				array(
					'batch_id'    => $batch_id,
					'done_ids'    => $done_ids,
					'done_paths'  => $done_paths,
					'saved_bytes' => $saved_bytes,
					'mode'        => $mode,
					'overview'    => TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
						? TisaCase_Optimizer_Media_Inspector::overview_stats( false )
						: null,
				)
			);
		}

		/**
		 * فشرده‌سازی و تبدیل دسته‌ای با به‌روزرسانی درجای کاتالوگ.
		 *
		 * @return void
		 */
		private static function handle_optimize_batch() {
			$started   = microtime( true );
			$ids       = self::read_int_list( 'ids' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$overrides = isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ? wp_unslash( $_POST['overrides'] ) : array();
			$clean_ovr = array();
			if ( ! empty( $overrides['force_webp'] ) ) {
				$clean_ovr['nextgen_format'] = 'webp';
			}

			if ( empty( $ids ) ) {
				$q   = TisaCase_Optimizer_Media_Inspector::query_items(
					array(
						'opt_state' => 'unoptimized',
						'sort_by'   => 'total_bytes',
						'per_page'  => 100,
					)
				);
				$ids = isset( $q['matching_ids'] ) ? array_slice( (array) $q['matching_ids'], 0, 500 ) : array();
				self::ok(
					array(
						'queue_ids' => $ids,
						'total'     => count( $ids ),
					)
				);
			}

			$processed   = array();
			$touched_ids = array();
			$saved_total = 0;

			foreach ( $ids as $aid ) {
				$res = TisaCase_Optimizer_Image_Processor::optimize_attachment( $aid, $clean_ovr );
				if ( is_wp_error( $res ) ) {
					$processed[] = array(
						'id'    => $aid,
						'ok'    => false,
						'error' => $res->get_error_message(),
					);
				} else {
					$saved_total  += (int) $res['saved_bytes'];
					$touched_ids[] = $aid;
					$processed[]   = array(
						'id'           => $aid,
						'ok'           => true,
						'before_bytes' => $res['before_bytes'],
						'after_bytes'  => $res['after_bytes'],
						'saved_bytes'  => $res['saved_bytes'],
						'nextgen'      => $res['nextgen'],
						'mode'         => $res['mode'],
					);
				}
				if ( ( microtime( true ) - $started ) >= self::BUDGET_SEC ) {
					break;
				}
			}

			if ( ! empty( $touched_ids ) ) {
				TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( $touched_ids );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['log_final'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$total_n = isset( $_POST['summary_count'] ) ? absint( $_POST['summary_count'] ) : count( $processed );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$total_b = isset( $_POST['summary_bytes'] ) ? absint( $_POST['summary_bytes'] ) : $saved_total;
				TisaCase_Optimizer_Session::log(
					'optimize_images',
					__( 'فشرده‌سازی و بهینه‌سازی نسل جدید تصاویر', 'tisacase-optimizer' ),
					$total_n,
					$total_b
				);
			}

			self::ok(
				array(
					'processed'   => $processed,
					'saved_bytes' => $saved_total,
				)
			);
		}

		/**
		 * پاک‌سازی فایل‌های خام پنهان پشت `-scaled` با به‌روزرسانی درجای کاتالوگ.
		 *
		 * @return void
		 */
		private static function handle_clean_unscaled() {
			$ids = self::read_int_list( 'ids' );
			if ( empty( $ids ) ) {
				$q   = TisaCase_Optimizer_Media_Inspector::query_items(
					array(
						'opt_state' => 'has_unscaled',
						'per_page'  => 100,
					)
				);
				$ids = isset( $q['matching_ids'] ) ? (array) $q['matching_ids'] : array();
			}

			$cleaned     = 0;
			$touched_ids = array();
			$saved_bytes = 0;
			foreach ( $ids as $aid ) {
				$res = TisaCase_Optimizer_Image_Processor::clean_unscaled_original( $aid );
				if ( ! is_wp_error( $res ) && $res['saved_bytes'] > 0 ) {
					++$cleaned;
					$touched_ids[] = $aid;
					$saved_bytes  += (int) $res['saved_bytes'];
				}
			}

			if ( ! empty( $touched_ids ) ) {
				TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( $touched_ids );
			}

			if ( $cleaned > 0 ) {
				TisaCase_Optimizer_Session::log(
					'clean_unscaled',
					__( 'حذف نسخه‌های خام غول‌پیکر پشت تصاویر -scaled', 'tisacase-optimizer' ),
					$cleaned,
					$saved_bytes
				);
			}

			self::ok(
				array(
					'cleaned'     => $cleaned,
					'saved_bytes' => $saved_bytes,
					'overview'    => TisaCase_Optimizer_Media_Inspector::overview_stats( false ),
				)
			);
		}

		/**
		 * ادغام هوشمند تکراری‌ها با حذف فوری نسخه‌های اضافی از کاتالوگ کش‌شده.
		 *
		 * @return void
		 */
		private static function handle_merge_duplicates() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode_raw  = isset( $_POST['delete_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['delete_mode'] ) ) : '';
			$mode      = in_array( $mode_raw, array( 'quarantine', 'permanent' ), true )
				? $mode_raw
				: (string) TisaCase_Optimizer_Settings::get( 'delete_mode', 'quarantine' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$master_id = isset( $_POST['master_id'] ) ? absint( $_POST['master_id'] ) : 0;
			$dup_ids   = self::read_int_list( 'dup_ids' );

			$merged_total   = 0;
			$relinked_total = 0;
			$saved_total    = 0;
			$gone_ids       = array();

			if ( $master_id > 0 && ! empty( $dup_ids ) ) {
				$res = TisaCase_Optimizer_Deduplicator::merge_group( $master_id, $dup_ids, $mode );
				if ( is_wp_error( $res ) ) {
					self::fail( $res->get_error_message(), 400 );
				}
				$merged_total   = $res['merged'];
				$relinked_total = $res['relinked'];
				$saved_total    = $res['saved_bytes'];
				$gone_ids       = $dup_ids;
			} else {
				$report = TisaCase_Optimizer_Deduplicator::find_duplicate_groups( false );
				foreach ( $report['groups'] as $grp ) {
					$res = TisaCase_Optimizer_Deduplicator::merge_group( (int) $grp['master_id'], (array) $grp['dup_ids'], $mode );
					if ( ! is_wp_error( $res ) ) {
						$merged_total   += $res['merged'];
						$relinked_total += $res['relinked'];
						$saved_total    += $res['saved_bytes'];
						$gone_ids        = array_merge( $gone_ids, (array) $grp['dup_ids'] );
					}
				}
			}

			if ( ! empty( $gone_ids ) ) {
				TisaCase_Optimizer_Media_Inspector::remove_from_cached_catalog( $gone_ids );
			}

			TisaCase_Optimizer_Session::log(
				'merge_duplicates',
				__( 'ادغام هوشمند تصاویر تکراری و انتقال خودکار لینک محصولات', 'tisacase-optimizer' ),
				$merged_total,
				$saved_total,
				sprintf( '%d ارجاع منتقل شد', $relinked_total )
			);

			self::ok(
				array(
					'merged'     => $merged_total,
					'relinked'   => $relinked_total,
					'saved_bytes' => $saved_total,
					'duplicates' => TisaCase_Optimizer_Deduplicator::find_duplicate_groups( false ),
					'overview'   => TisaCase_Optimizer_Media_Inspector::overview_stats( false ),
				)
			);
		}

		/**
		 * بازسازی دسته‌ای بندانگشتی‌ها با سقف زمانی تطبیقی.
		 *
		 * @return void
		 */
		private static function handle_regen_batch() {
			global $wpdb;

			$started = microtime( true );
			$ids     = self::read_int_list( 'ids' );
			if ( empty( $ids ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$all_ids = array_map(
					'absint',
					(array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' ORDER BY ID DESC" )
				);
				self::ok(
					array(
						'queue_ids' => $all_ids,
						'total'     => count( $all_ids ),
					)
				);
			}

			$done = array();
			foreach ( $ids as $aid ) {
				TisaCase_Optimizer_Subsizes::regenerate_one( $aid );
				$done[] = $aid;
				if ( ( microtime( true ) - $started ) >= self::BUDGET_SEC ) {
					break;
				}
			}

			if ( ! empty( $done ) ) {
				TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( $done );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! empty( $_POST['log_final'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$cnt = isset( $_POST['summary_count'] ) ? absint( $_POST['summary_count'] ) : count( $done );
				TisaCase_Optimizer_Session::log( 'regen_thumbnails', __( 'بازسازی دسته‌ای زیرسایزهای بندانگشتی', 'tisacase-optimizer' ), $cnt, 0 );
			}

			self::ok( array( 'done_ids' => $done ) );
		}

		/**
		 * بستهٔ کامل دادهٔ تب خزانهٔ قرنطینه و تاریخچه.
		 *
		 * @return array<string,mixed>
		 */
		private static function vault_payload() {
			$quarantine   = TisaCase_Optimizer_Vault::list_quarantine();
			$batch_counts = array();
			foreach ( $quarantine as $q ) {
				if ( ! empty( $q['batch_id'] ) ) {
					$bid                  = (string) $q['batch_id'];
					$batch_counts[ $bid ] = isset( $batch_counts[ $bid ] ) ? $batch_counts[ $bid ] + 1 : 1;
				}
			}

			$history = TisaCase_Optimizer_Session::history();
			foreach ( $history as $idx => $h ) {
				$bid                                  = isset( $h['batch_id'] ) ? (string) $h['batch_id'] : '';
				$history[ $idx ]['recoverable_count'] = ( '' !== $bid && isset( $batch_counts[ $bid ] ) ) ? (int) $batch_counts[ $bid ] : 0;
			}

			return array(
				'summary'    => TisaCase_Optimizer_Vault::summary(),
				'quarantine' => $quarantine,
				'originals'  => array_values( TisaCase_Optimizer_Vault::list_original_backups() ),
				'history'    => $history,
			);
		}

		/**
		 * خواندن آرایهٔ اعداد صحیح از `$_POST`.
		 *
		 * @param string $key کلید ورودی.
		 * @return int[]
		 */
		private static function read_int_list( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST[ $key ] ) ) {
				return array();
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST[ $key ] );
			$arr = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			return array_values( array_unique( array_filter( array_map( 'absint', $arr ) ) ) );
		}

		/**
		 * خواندن آرایهٔ رشته‌های امن از `$_POST`.
		 *
		 * @param string $key کلید ورودی.
		 * @return string[]
		 */
		private static function read_string_list( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( ! isset( $_POST[ $key ] ) ) {
				return array();
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST[ $key ] );
			$arr = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
			$out = array();
			foreach ( $arr as $v ) {
				$s = trim( str_replace( '..', '', sanitize_text_field( (string) $v ) ) );
				if ( '' !== $s ) {
					$out[] = $s;
				}
			}
			return array_values( array_unique( $out ) );
		}
	}
}
