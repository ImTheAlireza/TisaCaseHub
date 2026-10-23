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
		private static function fail( $message, $status = 400, array $extra = array() ) {
			self::$responded        = true;
			self::$emergency_buffer = null;
			$payload                = array( 'message' => $message );
			foreach ( $extra as $k => $v ) {
				$payload[ $k ] = $v;
			}
			wp_send_json_error( $payload, $status );
		}

		/**
		 * تعیین تکلیف کار قبلی پیش از شروع عملیات تازه.
		 *
		 * دو حالت دارد:
		 *   • کار قبلی «رهاشده» است (چند دقیقه هیچ پیشرفتی نداشته): بسته و
		 *     آزاد می‌شود تا کاربر پشت خطای همیشگی «در جریان است» گیر نکند.
		 *   • کار قبلی واقعاً زنده است: خطای ۴۰۹ با جزئیات کامل (نوع عملیات،
		 *     انجام‌شده، کل، دقیقه‌های بی‌خبری) تا رابط بتواند کارت «ادامه یا
		 *     رهاکردن» را دقیق نشان دهد — نه پیام مبهم.
		 *
		 * @return array<string,mixed>|null اطلاعات کار رهاشده (برای گزارش به کاربر).
		 */
		private static function clear_way_for_new_job() {
			$job = TisaCase_Optimizer_Job::get();
			if ( ! $job || 'running' !== (string) $job['status'] ) {
				return null;
			}

			$reclaimed = TisaCase_Optimizer_Job::reclaim_stale();
			if ( is_array( $reclaimed ) ) {
				return $reclaimed;
			}

			// هنوز زنده است: دقیقاً بگوییم چه کاری، کجا مانده و چه باید کرد.
			$sum = TisaCase_Optimizer_Job::summary( $job );
			self::fail(
				sprintf(
					/* translators: 1: operation label, 2: done, 3: total, 4: idle minutes */
					__( 'یک عملیات نیمه‌تمام در جریان است؛ ابتدا آن را ادامه دهید یا رها کنید. (%1$s: %2$s از %3$s — آخرین پیشرفت %4$s دقیقه پیش)', 'tisacase-optimizer' ),
					$sum['op_label'],
					number_format_i18n( (int) $sum['done'] ),
					number_format_i18n( (int) $sum['total'] ),
					number_format_i18n( max( 1, (int) ceil( (int) $sum['idle_sec'] / 60 ) ) )
				),
				409,
				array(
					'conflict' => true,
					'job'      => $sum,
				)
			);
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

					case 'inspect_sizes':
						self::handle_inspect_sizes();
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

					case 'job_status':
						self::handle_job_status();
						break;

					case 'job_cancel':
						self::handle_job_cancel();
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

					case 'preflight':
						self::ok( TisaCase_Optimizer_Preflight::run() );
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
						// phpcs:ignore WordPress.Security.NonceVerification.Missing
						$per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 0;
						self::ok( TisaCase_Optimizer_Image_SEO::audit( $flt, $page, $per_page ) );
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
					// «بازگردانی دسته‌ای بر اساس علت حذف»: کلاینت فقط کلید علت را
					// می‌فرستد، نه هزاران شناسه؛ فهرست روی سرور ساخته می‌شود.
					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					$reason_filter = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( (string) $_POST['reason'] ) ) : '';
					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					$job_id       = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';

					$job = TisaCase_Optimizer_Job::get();

					if ( '' !== $job_id ) {
						if ( ! $job || (string) $job['id'] !== $job_id ) {
							self::fail( __( 'این کار دیگر فعال نیست یا منقضی شده است.', 'tisacase-optimizer' ), 409 );
						}
					} else {
						if ( empty( $qids ) && ( '' !== $batch_filter || $restore_all || '' !== $reason_filter ) ) {
							foreach ( TisaCase_Optimizer_Vault::list_quarantine() as $qi ) {
								if ( empty( $qi['id'] ) ) {
									continue;
								}
								$qi_reason = isset( $qi['reason'] ) ? sanitize_key( (string) $qi['reason'] ) : '';
								if ( $restore_all
									|| ( '' !== $batch_filter && isset( $qi['batch_id'] ) && $qi['batch_id'] === $batch_filter )
									|| ( '' !== $reason_filter && $qi_reason === $reason_filter )
								) {
									$qids[] = (string) $qi['id'];
								}
							}
						}
						if ( empty( $qids ) ) {
							self::fail(
								'' !== $reason_filter
									? sprintf(
										/* translators: %s: reason label */
										__( 'هیچ بستهٔ قرنطینه‌ای با علت «%s» برای بازگردانی باقی نمانده است.', 'tisacase-optimizer' ),
										TisaCase_Optimizer_Vault::reason_label( $reason_filter )
									)
									: __( 'هیچ بستهٔ قرنطینه‌ای برای بازگردانی انتخاب نشده است.', 'tisacase-optimizer' ),
								400
							);
						}
						$reclaimed = self::clear_way_for_new_job();
						$job       = TisaCase_Optimizer_Job::start_restore( $qids );
					}

					$before_done = (int) $job['done'];

					try {
						$job = TisaCase_Optimizer_Job::advance( $job );
					} catch ( Throwable $e ) {
						$job = TisaCase_Optimizer_Job::mark_error( $job, $e->getMessage() );
						self::ok(
							array(
								'job'      => TisaCase_Optimizer_Job::summary( $job ),
								'restored' => (int) $job['done'],
								'vault'    => self::vault_payload(),
							)
						);
					}

					$summary  = TisaCase_Optimizer_Job::summary( $job );
					$finished = ! empty( $summary['finished'] );

					// phpcs:ignore WordPress.Security.NonceVerification.Missing
					if ( $finished && empty( $_POST['silent_log'] ) && (int) $job['done'] > 0 ) {
						TisaCase_Optimizer_Session::log(
							'restore_quarantine',
							__( 'بازگردانی تصاویر از قرنطینه به مسیر و ID اصلی', 'tisacase-optimizer' ),
							(int) $job['done'],
							0
						);
					}

					$this_tick = (int) $job['done'] - $before_done;

					self::ok(
						array(
							'job'           => $summary,
							'restored'      => (int) $job['done'],
							'restored_now'  => max( 0, $this_tick ),
							'restored_ids'  => array_slice( (array) $job['done_ids'], 0, 50 ),
							'reclaimed'     => $reclaimed,
							'vault'         => $finished ? self::vault_payload() : null,
							'overview'      => ( $finished && TisaCase_Optimizer_Media_Inspector::has_ready_catalog() )
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
		 * تفکیک زیرسایزهای یک تصویر (برای پاسخ روشن به «زیرسایز چیست و ۱۱٫۵ مگابایت از کجا آمد؟»).
		 *
		 * فقط یک پیوست را می‌خواند (متای آن در کش وردپرس است) و اندازهٔ هر فایل را
		 * جداگانه می‌سنجد؛ هیچ اسکن سنگینی اجرا نمی‌شود.
		 *
		 * @return void
		 */
		private static function handle_inspect_sizes() {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$aid = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
			if ( $aid <= 0 ) {
				self::fail( __( 'شناسهٔ تصویر نامعتبر است.', 'tisacase-optimizer' ), 400 );
			}

			$uploads = wp_upload_dir();
			$basedir = trailingslashit( wp_normalize_path( $uploads['basedir'] ) );
			$baseurl = trailingslashit( $uploads['baseurl'] );
			$meta    = wp_get_attachment_metadata( $aid );
			$rows    = array();
			$files   = array();
			$total   = 0;
			$main_b  = 0;
			$dir     = '';
			$rel_dir = '';

			/**
			 * ثبت یک فایل واقعی روی دیسک (اگر وجود داشته باشد).
			 *
			 * @param string $role نقش فارسی.
			 * @param string $rel  مسیر نسبی داخل uploads.
			 * @return int حجم فایل (بایت).
			 */
			$add_file = static function ( $role, $rel ) use ( $basedir, $baseurl, &$files, &$total ) {
				$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
				if ( '' === $rel ) {
					return 0;
				}
				$abs   = $basedir . $rel;
				$bytes = is_file( $abs ) ? max( 0, (int) @filesize( $abs ) ) : 0;
				if ( $bytes > 0 ) {
					$total += $bytes;
				}
				$files[] = array(
					'role'  => (string) $role,
					'rel'   => $rel,
					'url'   => $baseurl . $rel,
					'abs'   => $abs,
					'bytes' => $bytes,
					'exists' => is_file( $abs ),
				);
				return $bytes;
			};

			if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
				$rel_file = ltrim( wp_normalize_path( (string) $meta['file'] ), '/' );
				$rel_dir  = dirname( $rel_file );
				$dir      = ( '.' === $rel_dir || '' === $rel_dir ) ? '' : $rel_dir;

				$main_b = $add_file( __( 'فایل اصلی', 'tisacase-optimizer' ), $rel_file );

				// نسخهٔ خام پنهان پشت `-scaled` (اگر وجود داشته باشد).
				if ( ! empty( $meta['original_image'] ) ) {
					$add_file( __( 'نسخهٔ خام اصلی (پشت -scaled)', 'tisacase-optimizer' ), ( '' !== $dir ? $dir . '/' : '' ) . (string) $meta['original_image'] );
				}

				if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $key => $size ) {
						$file = isset( $size['file'] ) ? (string) $size['file'] : '';
						if ( '' === $file ) {
							continue;
						}
						$rel   = ( '' !== $dir ? $dir . '/' : '' ) . $file;
						$bytes = $add_file( sprintf( /* translators: %s: size name. */ __( 'زیرسایز %s', 'tisacase-optimizer' ), (string) $key ), $rel );
						$rows[] = array(
							'name'   => (string) $key,
							'w'      => isset( $size['width'] ) ? (int) $size['width'] : 0,
							'h'      => isset( $size['height'] ) ? (int) $size['height'] : 0,
							'bytes'  => $bytes,
							'exists' => is_file( $basedir . $rel ),
							'rel'    => $rel,
						);
					}
				}
			}

			// نسخه‌های موازی نسل جدید: همان مسیر + پسوند `.webp` / `.avif`.
			$sidecars = 0;
			$sidecar_bytes = 0;
			foreach ( array_slice( $files, 0, 60 ) as $f ) {
				foreach ( array( 'webp', 'avif' ) as $ext ) {
					$sc_rel = $f['rel'] . '.' . $ext;
					if ( ! is_file( $basedir . $sc_rel ) ) {
						continue;
					}
					$b = $add_file( sprintf( /* translators: 1: format, 2: role. */ __( 'نسخهٔ موازی %1$s از %2$s', 'tisacase-optimizer' ), strtoupper( $ext ), $f['role'] ), $sc_rel );
					++$sidecars;
					$sidecar_bytes += $b;
				}
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $b['bytes'] <=> $a['bytes'];
				}
			);

			self::ok(
				array(
					'id'             => $aid,
					'main_bytes'     => $main_b,
					'subsizes_bytes' => $total - $main_b - $sidecar_bytes,
					'sidecar_bytes'  => $sidecar_bytes,
					'sidecar_count'  => $sidecars,
					'total_bytes'    => $total,
					'count'          => count( $rows ),
					'dir'            => $dir,
					'sizes'          => array_slice( $rows, 0, 40 ),
					'files'          => array_slice( $files, 0, 60 ),
					'webp_mode'      => (string) TisaCase_Optimizer_Settings::get( 'webp_mode', 'sidecar' ),
					'delivery'       => (string) TisaCase_Optimizer_Settings::get( 'delivery_method', 'picture' ),
					'nextgen'        => (string) TisaCase_Optimizer_Settings::get( 'nextgen_format', 'webp' ),
				)
			);
		}

		/**
		 * حذف یا قرنطینهٔ دسته‌ای با موتور کار «قابل ادامه».
		 *
		 * قرارداد فراخوانی:
		 *   • اولین درخواست: `ids[]` و/یا `rel_paths[]` (+ `delete_mode`) ⇒ کار ساخته
		 *     می‌شود، همهٔ شناسه‌ها راستی‌آزمایی می‌شوند و یک تیک اجرا می‌گردد.
		 *   • درخواست‌های بعدی: فقط `job_id` ⇒ تیک بعدی.
		 * پاسخ همیشه شامل `job` (خلاصهٔ کامل) و `done_ids` همان تیک است.
		 *
		 * چرا؟ قبلاً پیمایش در مرورگر بود و اگر سرور در میانهٔ یک بسته به سقف
		 * زمانی می‌رسید، بقیهٔ شناسه‌ها برای همیشه رها می‌شدند. اکنون صف در
		 * سرور است و هیچ شناسه‌ای گم نمی‌شود.
		 *
		 * @return void
		 */
		private static function handle_remove_items() {
			$ids       = self::read_int_list( 'ids' );
			$rel_paths = self::read_string_list( 'rel_paths' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$job_id    = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['job_id'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$mode_raw  = isset( $_POST['delete_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['delete_mode'] ) ) : '';
			$mode      = in_array( $mode_raw, array( 'quarantine', 'permanent' ), true )
				? $mode_raw
				: (string) TisaCase_Optimizer_Settings::get( 'delete_mode', 'quarantine' );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$allow_draft = ! empty( $_POST['allow_draft_only'] );
			// علت حذف برای دسته‌بندی در «پشتیبان و بازگردانی»: اگر کاربر صریحاً
			// اجازهٔ حذف موارد «فقط پیش‌نویس» را داده باشد، علت همان است؛
			// در غیر این صورت «بدون استفاده».
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$reason_raw = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( (string) $_POST['reason'] ) ) : '';
			$reason     = in_array( $reason_raw, array( 'unused', 'draft_only', 'missing_file', 'manual_unused' ), true )
				? $reason_raw
				: ( $allow_draft ? 'draft_only' : 'unused' );

			$job       = TisaCase_Optimizer_Job::get();
			$reclaimed = null;

			// ادامهٔ کار موجود (حتی اگر مرورگر رفرش شده باشد).
			if ( '' !== $job_id ) {
				if ( ! $job || (string) $job['id'] !== $job_id ) {
					self::fail( __( 'این کار دیگر فعال نیست یا منقضی شده است.', 'tisacase-optimizer' ), 409 );
				}
				if ( (string) $job['status'] !== 'running' ) {
					self::ok(
						array(
							'job'      => TisaCase_Optimizer_Job::summary( $job ),
							'done_ids' => array(),
						)
					);
				}
			} elseif ( empty( $ids ) && empty( $rel_paths ) ) {
				self::fail( __( 'هیچ تصویری برای حذف انتخاب نشده است.', 'tisacase-optimizer' ), 400 );
			} else {
				$reclaimed = self::clear_way_for_new_job();
				$job       = TisaCase_Optimizer_Job::start_remove( $ids, $rel_paths, $mode, $allow_draft, $reason );
			}

			$before_done = (int) $job['done'];
			$before_ids  = count( (array) $job['done_ids'] ) + count( (array) $job['done_paths'] );

			try {
				$job = TisaCase_Optimizer_Job::advance( $job );
			} catch ( Throwable $e ) {
				// خطا نباید کار را نابود کند؛ وضعیت ذخیره‌شده می‌ماند تا قابل ادامه باشد.
				$job = TisaCase_Optimizer_Job::mark_error( $job, $e->getMessage() );
				self::ok(
					array(
						'job'      => TisaCase_Optimizer_Job::summary( $job ),
						'done_ids' => array_slice( (array) $job['done_ids'], $before_ids ),
						'error'    => $e->getMessage(),
					)
				);
			}

			$summary = TisaCase_Optimizer_Job::summary( $job );

			// گزارش نهایی فقط یک‌بار در پایان کار خوانده می‌شود (نه در هر تیک).
			$overview = null;
			if ( ! empty( $summary['finished'] ) ) {
				$overview = TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
					? TisaCase_Optimizer_Media_Inspector::overview_stats( false )
					: null;
			}

			self::ok(
				array(
					'job'           => $summary,
					'done_ids'      => array_slice( (array) $job['done_ids'], $before_ids ),
					'done_paths'    => array_slice( (array) $job['done_paths'], $before_ids ),
					'saved_bytes'   => max( 0, (int) $job['saved_bytes'] ),
					'batch_id'      => (string) $job['batch_id'],
					'mode'          => (string) $job['mode'],
					'blocked'       => $summary['blocked'],
					'blocked_paths' => $summary['blocked_paths'],
					'blocked_count' => $summary['blocked_count'],
					'needs_rescan'  => $summary['needs_rescan'],
					'coverage'      => $summary['coverage'],
					'hint'          => $summary['hint'],
					'block_reasons' => $summary['block_reasons'],
					'verify_chunk'  => (int) TisaCase_Optimizer_Settings::get( 'verify_chunk', 20 ),
					'overview'      => $overview,
					'processed_now' => (int) $job['done'] - $before_done,
					'reclaimed'     => $reclaimed,
				)
			);
		}

		/**
		 * وضعیت کار جاری (برای کارت «ادامهٔ عملیات نیمه‌تمام» بعد از رفرش یا قطعی).
		 *
		 * @return void
		 */
		private static function handle_job_status() {
			$job = TisaCase_Optimizer_Job::get();
			if ( ! $job ) {
				self::ok( array( 'job' => null ) );
			}
			self::ok( array( 'job' => TisaCase_Optimizer_Job::summary( $job ) ) );
		}

		/**
		 * رهاکردن کار نیمه‌تمام (با نهایی‌سازی همان مقدار انجام‌شده).
		 *
		 * @return void
		 */
		private static function handle_job_cancel() {
			$job = TisaCase_Optimizer_Job::get();
			if ( ! $job ) {
				self::ok(
					array(
						'job'      => null,
						'overview' => TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
							? TisaCase_Optimizer_Media_Inspector::overview_stats( false )
							: null,
					)
				);
			}

			$job     = TisaCase_Optimizer_Job::cancel( $job );
			$summary = TisaCase_Optimizer_Job::summary( $job );
			// کار رهاشده نباید به‌عنوان «کار فعال» باقی بماند؛ وگرنه عملیات بعدی
			// دوباره با همان خطای «در جریان است» روبه‌رو می‌شود.
			TisaCase_Optimizer_Job::clear();

			self::ok(
				array(
					'job'      => $summary,
					'overview' => TisaCase_Optimizer_Media_Inspector::has_ready_catalog()
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
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$overrides = isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ? wp_unslash( $_POST['overrides'] ) : array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['job_id'] ) ) : '';
			$ids    = self::read_int_list( 'ids' );

			$clean_ovr = array();
			if ( ! empty( $overrides['force_webp'] ) ) {
				$clean_ovr['nextgen_format'] = 'webp';
			}
			// جایگزینی مستقیم برای همین کار: فایل اصل با نسخهٔ نسل جدید عوض می‌شود
			// (لینک‌ها بازنویسی می‌شوند) تا حجم دیسک واقعاً کم شود.
			if ( ! empty( $overrides['replace_mode'] ) ) {
				$clean_ovr['nextgen_format'] = 'webp';
				$clean_ovr['webp_mode']      = 'replace';
			}

			$job       = TisaCase_Optimizer_Job::get();
			$reclaimed = null;

			if ( '' !== $job_id ) {
				if ( ! $job || (string) $job['id'] !== $job_id ) {
					self::fail( __( 'این کار دیگر فعال نیست یا منقضی شده است.', 'tisacase-optimizer' ), 409 );
				}
				if ( (string) $job['status'] !== 'running' ) {
					self::ok(
						array(
							'job'        => TisaCase_Optimizer_Job::summary( $job ),
							'processed'  => array(),
							'saved_bytes' => 0,
						)
					);
				}
			} elseif ( empty( $ids ) ) {
				// فقط «صف پیشنهادی» را برمی‌گردانیم؛ کار با اولین بستهٔ شناسه‌ها ساخته می‌شود.
				$wants_replace = ! empty( $clean_ovr['webp_mode'] ) && 'replace' === $clean_ovr['webp_mode'];

				// مسیر سریع: از فهرست سبک کاتالوگ استفاده می‌کنیم تا برای ساختن صف،
				// کاتالوگ سنگین رمزگشایی نشود. اگر فهرست نبود، همان مسیر قبلی.
				$light = TisaCase_Optimizer_Catalog_Summary::rows();
				if ( is_array( $light ) ) {
					$pick = array();
					foreach ( $light as $row ) {
						if ( ! is_array( $row ) || count( $row ) < 7 ) {
							continue;
						}
						$row_exists = ! empty( $row[6] );
						$row_is_opt = ! empty( $row[3] );
						$row_format = (string) $row[4];
						if ( ! $row_exists || 'missing_file' === (string) $row[2] ) {
							continue;
						}
						if ( $wants_replace ? ( 'WEBP' === $row_format ) : $row_is_opt ) {
							continue;
						}
						$pick[] = array( (int) $row[0], (int) $row[1] );
					}
					usort(
						$pick,
						static function ( $a, $b ) {
							return $b[1] <=> $a[1];
						}
					);
					$queue = array_map( 'intval', array_column( array_slice( $pick, 0, 5000 ), 0 ) );
				} else {
					$q     = TisaCase_Optimizer_Media_Inspector::query_items(
						array(
							'opt_state' => $wants_replace ? 'not_webp' : 'unoptimized',
							'sort_by'   => 'total_bytes',
							'per_page'  => 100,
						)
					);
					$queue = isset( $q['matching_ids'] ) ? array_slice( (array) $q['matching_ids'], 0, 5000 ) : array();
				}
				self::ok(
					array(
						'queue_ids'    => $queue,
						'total'        => count( $queue ),
						'replace_mode' => $wants_replace,
					)
				);
			} else {
				$reclaimed = self::clear_way_for_new_job();
				$job       = TisaCase_Optimizer_Job::start_optimize( $ids, $clean_ovr );
			}

			$before_done = (int) $job['done'];

			try {
				$job = TisaCase_Optimizer_Job::advance( $job );
			} catch ( Throwable $e ) {
				$job = TisaCase_Optimizer_Job::mark_error( $job, $e->getMessage() );
				self::ok(
					array(
						'job'        => TisaCase_Optimizer_Job::summary( $job ),
						'processed'  => array(),
						'error'      => $e->getMessage(),
						'saved_bytes' => max( 0, (int) $job['saved_bytes'] ),
					)
				);
			}

			$summary  = TisaCase_Optimizer_Job::summary( $job );
			$overview = null;
			if ( ! empty( $summary['finished'] ) && TisaCase_Optimizer_Media_Inspector::has_ready_catalog() ) {
				$overview = TisaCase_Optimizer_Media_Inspector::overview_stats( false );
			}

			self::ok(
				array(
					'job'           => $summary,
					'processed'     => array_slice( (array) $job['done_ids'], 0, 50 ),
					'failed'        => array_slice( (array) $job['failed'], 0, 50 ),
					'saved_bytes'   => max( 0, (int) $job['saved_bytes'] ),
					'processed_now' => (int) $job['done'] - $before_done,
					'replace_mode'  => 'replace' === (string) $job['mode'],
					'overview'      => $overview,
					'reclaimed'     => $reclaimed,
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
			$reasons      = array();
			foreach ( $quarantine as $idx => $q ) {
				if ( ! empty( $q['batch_id'] ) ) {
					$bid                  = (string) $q['batch_id'];
					$batch_counts[ $bid ] = isset( $batch_counts[ $bid ] ) ? $batch_counts[ $bid ] + 1 : 1;
				}

				// «علت حذف» به زبان فارسی + آدرس پیش‌نمایش/بندانگشتی همان بسته.
				// فایل‌های قرنطینه بیرون از `uploads/` هستند، پس آدرس عمومی
				// ندارند و پیش‌نمایش از مسیر امن خودِ افزونه سرو می‌شود.
				$rkey = isset( $q['reason'] ) ? sanitize_key( (string) $q['reason'] ) : '';
				$quarantine[ $idx ]['reason_key']   = $rkey;
				$quarantine[ $idx ]['reason_label'] = TisaCase_Optimizer_Vault::reason_label( $rkey );
				$quarantine[ $idx ]['thumb_url']    = TisaCase_Optimizer_Preview::url( 'quarantine', (string) $q['id'], '', true );
				$quarantine[ $idx ]['preview_url']  = TisaCase_Optimizer_Preview::url( 'quarantine', (string) $q['id'], '', false );
				$quarantine[ $idx ]['is_image']     = self::looks_like_image( isset( $q['filename'] ) ? (string) $q['filename'] : '' );

				if ( '' === $rkey ) {
					$rkey = 'unknown';
				}
				if ( ! isset( $reasons[ $rkey ] ) ) {
					$reasons[ $rkey ] = array(
						'key'   => $rkey,
						'label' => TisaCase_Optimizer_Vault::reason_label( $rkey ),
						'count' => 0,
						'bytes' => 0,
					);
				}
				++$reasons[ $rkey ]['count'];
				$reasons[ $rkey ]['bytes'] += isset( $q['total_bytes'] ) ? (int) $q['total_bytes'] : 0;
			}

			// علت‌های پرتکرار اول می‌آیند تا کاربر سریع دستهٔ بزرگ را ببیند.
			$reasons = array_values( $reasons );
			usort(
				$reasons,
				static function ( $a, $b ) {
					if ( $a['count'] === $b['count'] ) {
						return strcmp( $a['label'], $b['label'] );
					}
					return $b['count'] <=> $a['count'];
				}
			);

			$originals = array_values( TisaCase_Optimizer_Vault::list_original_backups() );
			foreach ( $originals as $oidx => $o ) {
				$originals[ $oidx ]['thumb_url']   = TisaCase_Optimizer_Preview::url( 'original', (string) $o['attachment_id'], '', true );
				$originals[ $oidx ]['preview_url'] = TisaCase_Optimizer_Preview::url( 'original', (string) $o['attachment_id'], '', false );
			}

			$history = TisaCase_Optimizer_Session::history();
			foreach ( $history as $idx => $h ) {
				$bid                                  = isset( $h['batch_id'] ) ? (string) $h['batch_id'] : '';
				$history[ $idx ]['recoverable_count'] = ( '' !== $bid && isset( $batch_counts[ $bid ] ) ) ? (int) $batch_counts[ $bid ] : 0;
			}

			return array(
				'summary'    => TisaCase_Optimizer_Vault::summary(),
				'quarantine' => $quarantine,
				'reasons'    => $reasons,
				'originals'  => $originals,
				'history'    => $history,
			);
		}

		/**
		 * آیا نام فایل نشان می‌دهد تصویر قابل نمایش است؟
		 *
		 * @param string $filename نام فایل.
		 * @return bool
		 */
		private static function looks_like_image( $filename ) {
			$ext = strtolower( (string) pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
			return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp' ), true );
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
