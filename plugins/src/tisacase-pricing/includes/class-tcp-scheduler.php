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
			// اگر در صف چیزی هست و رویداد تیک فعال نیست، زمان بده.
			if ( TCP_DB::count_queued() > 0 && ! wp_next_scheduled( TCP_Settings::CRON_TICK ) ) {
				wp_schedule_single_event( time() + 30, TCP_Settings::CRON_TICK );
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

		/** اجرای تیک کرون: چند صفحه از اجرای در صف را پردازش کن. */
		public static function cron_tick() {
			if ( ! TCP_Settings::wc_active() || TCP_DB::count_queued() < 1 ) {
				return;
			}

			// اگر اجرای فعالِ دستی/بازگردانی در جریان است، این تیک را رها کن.
			$busy = TCP_DB::busy_slot( 0 );
			if ( ! empty( $busy['active'] ) ) {
				self::schedule_tick();
				return;
			}

			$run = TCP_DB::claim_queued_run();
			if ( ! $run ) {
				return;
			}

			$start    = time();
			$max_pages = max( 1, min( 500, absint( TCP_Settings::setting( 'cron_pages' ) ) ) );
			$done     = false;

			// سهمیهٔ زمانی تیک: هر صفحه خودش بودجهٔ زمانی دارد؛ اینجا فقط کل تیک
			// را کوتاه نگه می‌داریم تا درخواست کرون (loopback) قطع نشود.
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

			if ( $done ) {
				$final = TCP_DB::get_run( (int) $run['id'] );
				if ( $final && 'running' === $final['status'] ) {
					TCP_Ops::finalize_run( (int) $run['id'], 'done' );
				}
			} else {
				TCP_DB::update_run( (int) $run['id'], array( 'updated_at' => TCP_DB::now() ) );
			}

			// اگر هنوز اجرای در صف مانده، تیک بعدی را زمان بده.
			if ( TCP_DB::count_queued() > 0 ) {
				self::schedule_tick();
			}
		}
	}
}
