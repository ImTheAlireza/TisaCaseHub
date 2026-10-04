<?php
/**
 * زمان‌بندی اجراها با WP-Cron برای حجم‌های بزرگ یا اجرای خودکار.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Scheduler' ) ) {

	final class TCP_Scheduler {

		/** رویداد تکرارشوندهٔ پاک‌سازی روزانه را ثبت کن. */
		public static function register_cron() {
			if ( ! wp_next_scheduled( TCP_Settings::CRON_CLEAN ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', TCP_Settings::CRON_CLEAN );
			}
			// صف، یا اجرای زمان‌بندی‌شده‌ای که هنوز running است (تیک قبلی تمامش نکرده).
			if ( ( TCP_DB::count_queued() > 0 || TCP_DB::has_running_scheduled() ) && ! wp_next_scheduled( TCP_Settings::CRON_TICK ) ) {
				wp_schedule_single_event( time() + 30, TCP_Settings::CRON_TICK );
			}
			$queue = get_option( TCP_Settings::OPT_CONTINUE, array() );
			if ( is_array( $queue ) && ! empty( $queue ) && ! wp_next_scheduled( TCP_Settings::CRON_CONTINUE ) ) {
				wp_schedule_single_event( time() + 20, TCP_Settings::CRON_CONTINUE );
			}
			// به‌روزرسانی جدول lookup هنوز انجام نشده (مثلاً کرون قبلی اجرا نشده) → دوباره زمان بده.
			if ( get_option( TCP_Settings::OPT_LOOKUP_PENDING, false ) && ! wp_next_scheduled( TCP_Settings::CRON_LOOKUP ) ) {
				wp_schedule_single_event( time() + 30, TCP_Settings::CRON_LOOKUP );
			}
		}

		/** اطمینان از وجود یک تیک آینده (بعد از افزودن به صف). */
		public static function schedule_tick() {
			if ( ! wp_next_scheduled( TCP_Settings::CRON_TICK ) ) {
				wp_schedule_single_event( time() + 30, TCP_Settings::CRON_TICK );
			}
		}

		/** اجرای تیک کرون: چند صفحه از اجرای در صف، یا ادامهٔ همان اجرای زمان‌بندی‌شده. */
		public static function cron_tick() {
			if ( ! TCP_Settings::wc_active() ) {
				return;
			}

			$busy = TCP_DB::busy_slot( 0 );
			$run  = null;
			if ( ! empty( $busy['active'] ) ) {
				// اجرای دستی را ندزد؛ فقط زمان‌بندی‌شدهٔ ناتمام را در تیک بعد ادامه بده.
				if ( 'scheduled' !== $busy['active']['type'] ) {
					if ( TCP_DB::count_queued() > 0 ) {
						self::schedule_tick();
					}
					return;
				}
				$run = $busy['active'];
			}
			if ( ! $run ) {
				if ( TCP_DB::count_queued() < 1 ) {
					return;
				}
				$run = TCP_DB::claim_queued_run();
				if ( ! $run ) {
					return;
				}
			}

			if ( ! TCP_DB::acquire_run_lock( (int) $run['id'] ) ) {
				self::schedule_tick();
				return;
			}

			$start    = time();
			$max_pages = max( 1, min( 500, absint( TCP_Settings::setting( 'cron_pages' ) ) ) );
			$done     = false;

			// سهمیهٔ زمانی تیک: هر صفحه خودش بودجهٔ زمانی دارد؛ اینجا فقط کل تیک
			// را کوتاه نگه می‌داریم تا درخواست کرون (loopback) قطع نشود.
			try {
			for ( $i = 0; $i < $max_pages; $i++ ) {
				if ( ( time() - $start ) > 12 ) {
					break; // ادامه در تیک بعد.
				}
				$fresh = TCP_DB::get_run( (int) $run['id'] );
				if ( ! $fresh || 'running' !== $fresh['status'] ) {
					$done = true;
					break;
				}
				$res = TCP_Ops::run_next_page( $fresh );
				if ( ! $res['ok'] ) {
					if ( ! empty( $res['retry'] ) ) {
						break;
					}
					TCP_DB::update_run( (int) $run['id'], array(
						'status'      => 'failed',
						'last_error'  => mb_substr( $res['msg'], 0, 255 ),
						'updated_at'  => TCP_DB::now(),
					) );
					$done = true;
					break;
				}
				if ( ! empty( $res['data']['done'] ) ) {
					$done = true;
					break;
				}
			}
			} finally {
				TCP_DB::release_run_lock( (int) $run['id'] );
			}

			if ( $done ) {
				$final = TCP_DB::get_run( (int) $run['id'] );
				if ( $final && 'running' === $final['status'] ) {
					TCP_Ops::finalize_run( (int) $run['id'], 'done' );
				}
				self::disarm_continue( (int) $run['id'] );
			} else {
				TCP_DB::update_run( (int) $run['id'], array( 'updated_at' => TCP_DB::now() ) );
				// تیک تمام شد ولی اجرا نه — بدون این، اجرای زمان‌بندی‌شده بعد از ۱۲ ثانیه می‌ایستد.
				self::schedule_tick();
				self::arm_continue( (int) $run['id'] );
			}

			if ( TCP_DB::count_queued() > 0 ) {
				self::schedule_tick();
			}
		}

		public static function continue_secret( $run_id ) {
			return hash_hmac( 'sha256', 'tcp-continue|' . absint( $run_id ), wp_salt( 'auth' ) );
		}

		/** نگهبان: اگر مرورگر قطع شد، این اجرا در صف ادامه می‌ماند. */
		public static function arm_continue( $run_id ) {
			$run_id = absint( $run_id );
			if ( ! $run_id ) {
				return;
			}
			$queue = get_option( TCP_Settings::OPT_CONTINUE, array() );
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}
			$queue[ $run_id ] = time();
			update_option( TCP_Settings::OPT_CONTINUE, $queue, false );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( TCP_Settings::CRON_CONTINUE ) ) {
				wp_schedule_single_event( time() + 25, TCP_Settings::CRON_CONTINUE );
			}
		}

		public static function disarm_continue( $run_id ) {
			$run_id = absint( $run_id );
			$queue  = get_option( TCP_Settings::OPT_CONTINUE, array() );
			if ( ! is_array( $queue ) || ! isset( $queue[ $run_id ] ) ) {
				return;
			}
			unset( $queue[ $run_id ] );
			update_option( TCP_Settings::OPT_CONTINUE, $queue, false );
		}

		/** کرون نگهبان: فقط اجراهایی که مرورگرشان مدتی است ضربان نفرستاده. */
		public static function continue_due() {
			$queue = get_option( TCP_Settings::OPT_CONTINUE, array() );
			if ( ! is_array( $queue ) || empty( $queue ) ) {
				return;
			}
			foreach ( array_keys( $queue ) as $run_id ) {
				self::drive_run( absint( $run_id ), true );
			}
			$left = get_option( TCP_Settings::OPT_CONTINUE, array() );
			if ( is_array( $left ) && ! empty( $left ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( TCP_Settings::CRON_CONTINUE ) ) {
				wp_schedule_single_event( time() + 25, TCP_Settings::CRON_CONTINUE );
			}
		}

		/**
		 * یک صفحه از اجرا را، اگر مرورگر فعال نباشد، جلو ببر و در صورت نیاز زنجیره را ادامه بده.
		 *
		 * @param bool $only_if_stale true = نگهبان؛ false = زنجیرهٔ پس‌زمینه بعد از قطع مرورگر.
		 */
		public static function drive_run( $run_id, $only_if_stale ) {
			$run_id = absint( $run_id );
			if ( ! $run_id || ! TCP_Settings::wc_active() ) {
				return;
			}
			$run = TCP_DB::get_run( $run_id );
			if ( ! $run || ! in_array( $run['status'], array( 'running', 'interrupted' ), true ) ) {
				self::disarm_continue( $run_id );
				return;
			}
			// قفل یتیم اجرا را interrupted می‌کند؛ اگر هنوز در صف ادامه باشد باید برگردد، نه رها شود.
			if ( 'interrupted' === $run['status'] ) {
				TCP_DB::update_run( $run_id, array( 'status' => 'running', 'updated_at' => TCP_DB::now() ) );
				$run['status'] = 'running';
			}
			if ( $only_if_stale && TCP_DB::run_beat_age( $run_id ) < 25 ) {
				return;
			}
			if ( ! TCP_DB::acquire_run_lock( $run_id ) ) {
				return;
			}
			$before = self::cursor_key( $run );
			$done   = false;
			$failed = false;
			try {
				$fresh = TCP_DB::get_run( $run_id );
				if ( ! $fresh || 'running' !== $fresh['status'] ) {
					$done = true;
				} else {
					TCP_DB::touch_run_beat( $run_id );
					$res = TCP_Ops::run_next_page( $fresh );
					if ( ! $res['ok'] ) {
						if ( ! empty( $res['retry'] ) ) {
							self::arm_continue( $run_id );
							return;
						}
						TCP_DB::update_run( $run_id, array(
							'status'     => 'failed',
							'last_error' => function_exists( 'mb_substr' ) ? mb_substr( $res['msg'], 0, 255 ) : substr( $res['msg'], 0, 255 ),
							'updated_at' => TCP_DB::now(),
						) );
						$failed = true;
					} elseif ( ! empty( $res['data']['done'] ) ) {
						TCP_Ops::finalize_run( $run_id, 'done' );
						$done = true;
					}
				}
			} finally {
				TCP_DB::release_run_lock( $run_id );
			}
			if ( $failed || $done ) {
				self::disarm_continue( $run_id );
				return;
			}
			$after = TCP_DB::get_run( $run_id );
			if ( ! $after || 'running' !== $after['status'] ) {
				self::disarm_continue( $run_id );
				return;
			}
			// بدون پیشرفت، زنجیرهٔ فوری نساز تا حلقهٔ بی‌پایان نشود.
			if ( self::cursor_key( $after ) === $before ) {
				self::arm_continue( $run_id );
				return;
			}
			self::arm_continue( $run_id );
			self::poke_continue( $run_id );
		}

		/** درخواست سبک به خود سایت تا صفحهٔ بعد بدون مرورگر اجرا شود. */
		public static function poke_continue( $run_id ) {
			$run_id = absint( $run_id );
			if ( ! $run_id || ! function_exists( 'wp_remote_post' ) || ! function_exists( 'admin_url' ) ) {
				return;
			}
			wp_remote_post(
				admin_url( 'admin-ajax.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => false,
					'body'      => array(
						'action' => TCP_Settings::AJAX_CONTINUE,
						'run_id' => $run_id,
						'secret' => self::continue_secret( $run_id ),
						'force'  => '1',
					),
				)
			);
		}

		private static function cursor_key( $run ) {
			return implode( ':', array(
				isset( $run['page'] ) ? (int) $run['page'] : 0,
				isset( $run['scan_after'] ) ? (int) $run['scan_after'] : 0,
				isset( $run['scan_pending'] ) ? (int) $run['scan_pending'] : 0,
				isset( $run['scan_object'] ) ? (int) $run['scan_object'] : 0,
				isset( $run['count_updated'] ) ? (int) $run['count_updated'] : 0,
			) );
		}
	}
}
