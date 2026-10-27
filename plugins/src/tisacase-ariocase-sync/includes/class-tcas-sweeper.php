<?php
/**
 * جاروی دوره‌ای موجودی همهٔ محصولات متصل.
 *
 * چرا؟ تازه‌سازی قبلی فقط با بازدید مشتری یا خرید همان محصول اتفاق می‌افتاد؛ پس
 * Variation‌ای که کسی سراغش نمی‌رفت روزها «موجود» می‌ماند. این کلاس یک صف کوچک
 * و کنترل‌شده در پس‌زمینه می‌سازد تا هر کد حداکثر یک‌بار در هر چرخه خوانده شود و
 * تعداد درخواست‌ها روی API همکار فشار نیاورد:
 *
 * - هر اجرا فقط چند آیتم کهنه را می‌خواند (دستهٔ کوچک)، بین درخواست‌ها مکث می‌کند
 *   و اگر زمان اجرا تمام شود باقی صف به اجرای بعدی می‌رسد.
 * - تا وقتی صف خالی نشده، یک اجرای پیوسته با فاصلهٔ کوتاه زمان‌بندی می‌شود تا همهٔ
 *   کدها داخل همان چرخه خوانده شوند، ولی هیچ‌وقت یک درخواست سنگین ساخته نمی‌شود.
 * - روی ۴۱۸/۴۲۹/۴۰۳ یا خطای احراز هویت، همکار برای مدتی کنار گذاشته می‌شود
 *   (Backoff) و همان Snapshot قبلی برای فروش استفاده می‌شود.
 * - قفل سراسری مانع اجرای هم‌زمان دو چرخه (Cron + دکمهٔ دستی) می‌شود.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Sweeper' ) ) {

	final class TCAS_Sweeper {
		const HOOK          = 'tcas_sweep_mapped_stock';
		const CONTINUE_HOOK = 'tcas_sweep_continue_queue';
		const OPTION_STATE  = 'tcas_sweep_state';
		const LOCK_KEY      = 'tcas_sweep_lock';
		const BACKOFF_KEY   = 'tcas_sweep_backoff';
		/** تعداد آیتم در هر اجرا؛ عمداً کوچک تا هر اجرا سریع و سبک بماند. */
		const BATCH         = 20;
		const MAX_BATCH     = 50;
		/** مکث بین دو درخواست (میکروثانیه) تا نرخ درخواست زیر یک در ثانیه بماند. */
		const GAP_US        = 300000;
		/** بودجهٔ زمانی هر اجرا (ثانیه)؛ باقی صف به اجرای بعدی می‌رسد. */
		const TIME_BUDGET   = 25;
		const LOCK_TTL      = 600;
		const BACKOFF_TTL   = 1800;
		/** کدهایی که قابل خواندن نیستند (والد متغیر، محصول حذف‌شده، همکار تنظیم‌نشده) موقتاً از صف کنار می‌روند. */
		const SKIP_META     = '_tcas_sweep_skipped_at';
		const SKIP_TTL      = 43200;
		/** تلاش دوباره برای کد خطادار (ثانیه). */
		const RETRY_TTL     = 900;
		/** حداکثر اجرای پیوستهٔ پشت‌سرهم؛ بعد از آن صف تا Cron بعدی می‌ماند. */
		const MAX_CHAIN     = 60;
		/** فاصلهٔ پایهٔ Cron؛ تنظیم کاربر طول عمر هر Snapshot را تعیین می‌کند. */
		const BASE_MINUTES  = 5;
		/** اگر صف باقی ماند، اجرای پیوستهٔ بعدی چند ثانیه بعد. */
		const CONTINUE_DELAY = 60;

		public static function init() {
			add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
			add_action( self::HOOK, array( __CLASS__, 'cron_run' ) );
			add_action( self::CONTINUE_HOOK, array( __CLASS__, 'continue_run' ) );
			add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 40 );
		}

		/** گزینه‌های چرخهٔ تازه‌سازی؛ عدد = دقیقه، صفر = خاموش. */
		public static function intervals() {
			return array(
				0  => 'خاموش (فقط دستی)',
				15 => 'هر ۱۵ دقیقه',
				30 => 'هر ۳۰ دقیقه',
				60 => 'هر ۶۰ دقیقه',
			);
		}

		public static function interval() {
			$settings = TCAS_Core::settings();
			$minutes  = isset( $settings['sweep_interval'] ) ? absint( $settings['sweep_interval'] ) : 30;
			$allowed  = self::intervals();
			return array_key_exists( $minutes, $allowed ) ? $minutes : 30;
		}

		private static function schedule_key( $minutes ) {
			return 'tcas_sweep_' . absint( $minutes );
		}

		/** زمان‌بندی‌های اختصاصی افزونه برای Cron وردپرس. */
		public static function schedules( $schedules ) {
			$schedules = is_array( $schedules ) ? $schedules : array();
			$schedules[ self::schedule_key( self::BASE_MINUTES ) ] = array(
				'interval' => self::BASE_MINUTES * MINUTE_IN_SECONDS,
				'display'  => 'همگام‌سازی موجودی همکار (پایه)',
			);
			return $schedules;
		}

		/** زمان‌بندی را با تنظیم فعلی هم‌گام می‌کند؛ روی هر بارگذاری ارزان است. */
		public static function ensure_schedule() {
			if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) || ! function_exists( 'wp_clear_scheduled_hook' ) ) {
				return;
			}
			$settings = TCAS_Core::settings();
			$off      = 0 === self::interval() || 'yes' !== $settings['enabled'];
			if ( $off ) {
				if ( wp_next_scheduled( self::HOOK ) ) {
					wp_clear_scheduled_hook( self::HOOK );
				}
				return;
			}
			if ( wp_next_scheduled( self::HOOK ) ) {
				return;
			}
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::schedule_key( self::BASE_MINUTES ), self::HOOK );
		}

		/** حذف زمان‌بندی‌ها هنگام غیرفعال‌سازی. */
		public static function unschedule() {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::HOOK );
				wp_clear_scheduled_hook( self::CONTINUE_HOOK );
			}
		}

		/** حداکثر سن مجاز Snapshot؛ از تنظیم چرخه گرفته می‌شود. */
		public static function max_age() {
			$minutes = self::interval();
			return max( 1, $minutes ) * MINUTE_IN_SECONDS;
		}

		public static function batch() {
			$settings = TCAS_Core::settings();
			$batch    = isset( $settings['sweep_batch'] ) ? absint( $settings['sweep_batch'] ) : self::BATCH;
			return max( 1, min( self::MAX_BATCH, $batch ) );
		}

		/**
		 * شناسهٔ محصولاتی که یا هرگز خوانده نشده‌اند یا Snapshot‌شان از حد مجاز کهنه‌تر است.
		 * قدیمی‌ترین‌ها اول می‌آیند تا هیچ کدی گرسنه نماند.
		 */
		public static function stale_ids( $limit = 0, $max_age = 0 ) {
			global $wpdb;
			if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
				return array();
			}
			$limit   = $limit ? absint( $limit ) : self::batch();
			$limit   = max( 1, min( self::MAX_BATCH, $limit ) );
			$max_age = $max_age ? absint( $max_age ) : self::max_age();
			$cutoff  = time() - $max_age;
			$sql     = "SELECT p.ID FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				LEFT JOIN {$wpdb->postmeta} age ON age.post_id = p.ID AND age.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} en ON en.post_id = p.ID AND en.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND ( en.meta_value IS NULL OR en.meta_value <> 'no' )
				AND ( sk.meta_value IS NULL OR sk.meta_value = '' OR CAST(sk.meta_value AS UNSIGNED) <= %d )
				AND ( age.meta_value IS NULL OR age.meta_value = '' OR CAST(age.meta_value AS UNSIGNED) <= %d )
				ORDER BY CASE WHEN age.meta_value IS NULL OR age.meta_value = '' THEN 0 ELSE CAST(age.meta_value AS UNSIGNED) END ASC, p.ID ASC
				LIMIT %d";
			$prepared = $wpdb->prepare( $sql, TCAS_Mapping::META_FETCHED_AT, TCAS_Mapping::META_ENABLED, self::SKIP_META, TCAS_Mapping::META_CODE, time() - self::SKIP_TTL, $cutoff, $limit );
			return array_values( array_unique( array_map( 'absint', (array) $wpdb->get_col( $prepared ) ) ) );
		}

		/** تعداد کل کدهای کهنه؛ برای نمایش «در صف» در تنظیمات. */
		public static function remaining() {
			global $wpdb;
			if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
				return 0;
			}
			$cutoff   = time() - self::max_age();
			$sql      = "SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				LEFT JOIN {$wpdb->postmeta} age ON age.post_id = p.ID AND age.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} en ON en.post_id = p.ID AND en.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = %s
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND ( en.meta_value IS NULL OR en.meta_value <> 'no' )
				AND ( sk.meta_value IS NULL OR sk.meta_value = '' OR CAST(sk.meta_value AS UNSIGNED) <= %d )
				AND ( age.meta_value IS NULL OR age.meta_value = '' OR CAST(age.meta_value AS UNSIGNED) <= %d )";
			$prepared = $wpdb->prepare( $sql, TCAS_Mapping::META_FETCHED_AT, TCAS_Mapping::META_ENABLED, self::SKIP_META, TCAS_Mapping::META_CODE, time() - self::SKIP_TTL, $cutoff );
			return absint( $wpdb->get_var( $prepared ) );
		}

		public static function in_backoff() {
			$entry = function_exists( 'get_transient' ) ? get_transient( self::BACKOFF_KEY ) : false;
			return is_array( $entry ) && ! empty( $entry['until'] ) && time() < (int) $entry['until'];
		}

		public static function backoff() {
			$entry = function_exists( 'get_transient' ) ? get_transient( self::BACKOFF_KEY ) : false;
			return is_array( $entry ) ? $entry : array();
		}

		public static function set_backoff( $minutes, $reason ) {
			$minutes = max( 1, absint( $minutes ) );
			$entry   = array(
				'until'   => time() + ( $minutes * MINUTE_IN_SECONDS ),
				'minutes' => $minutes,
				'reason'  => (string) $reason,
			);
			if ( function_exists( 'set_transient' ) ) {
				set_transient( self::BACKOFF_KEY, $entry, $minutes * MINUTE_IN_SECONDS + MINUTE_IN_SECONDS );
			}
			return $entry;
		}

		public static function clear_backoff() {
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::BACKOFF_KEY );
			}
		}

		public static function cron_run() {
			self::run( 'cron' );
		}

		public static function continue_run() {
			self::run( 'continue' );
		}

		private static function fa_number( $value ) {
			return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
		}

		private static function clock( $timestamp ) {
			if ( function_exists( 'wp_date' ) ) {
				return wp_date( 'H:i', $timestamp );
			}
			return date( 'H:i', (int) $timestamp );
		}

		public static function save_state( $summary ) {
			$summary = is_array( $summary ) ? $summary : array();
			$summary['next'] = self::next_run();
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION_STATE, $summary, false );
			}
			return $summary;
		}

		public static function state() {
			$state = function_exists( 'get_option' ) ? get_option( self::OPTION_STATE, array() ) : array();
			return is_array( $state ) ? $state : array();
		}

		public static function next_run() {
			if ( ! function_exists( 'wp_next_scheduled' ) ) {
				return 0;
			}
			$next = wp_next_scheduled( self::HOOK );
			$cont = wp_next_scheduled( self::CONTINUE_HOOK );
			if ( $cont && ( ! $next || $cont < $next ) ) {
				return (int) $cont;
			}
			return (int) $next;
		}

		/** خلاصهٔ خوانا برای رابط: «۱۲ مورد در ۲۴ ساعت گذشته، ۰ خطا». */
		public static function summary_text() {
			$state = self::state();
			if ( empty( $state ) || empty( $state['at'] ) ) {
				return 'هنوز هیچ جارویی اجرا نشده است.';
			}
			$parts = array();
			$parts[] = 'آخرین اجرا: ' . self::clock( $state['at'] );
			$parts[] = self::fa_number( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) . ' کد بررسی شد';
			$parts[] = self::fa_number( isset( $state['changed'] ) ? (int) $state['changed'] : 0 ) . ' تغییر موجودی';
			if ( ! empty( $state['errors'] ) ) {
				$parts[] = self::fa_number( (int) $state['errors'] ) . ' خطا';
			}
			return implode( ' · ', $parts );
		}

		/** خطای غیرمحدودیت‌ساز: چند دقیقه بعد دوباره تلاش می‌شود، نه هر دقیقه. */
		private static function mark_failed( $id ) {
			$id = absint( $id );
			if ( $id && function_exists( 'update_post_meta' ) ) {
				update_post_meta( $id, self::SKIP_META, time() - self::SKIP_TTL + self::RETRY_TTL );
			}
		}

		/** کد غیرقابل‌خواندن را چند ساعت از صف بیرون می‌گذارد تا بقیه گرسنه نمانند. */
		private static function mark_skipped( $id ) {
			$id = absint( $id );
			if ( $id && function_exists( 'update_post_meta' ) ) {
				update_post_meta( $id, self::SKIP_META, time() );
			}
		}

		private static function is_pressure_error( $state ) {
			if ( ! is_object( $state ) || ! method_exists( $state, 'get_error_code' ) ) {
				return false;
			}
			return in_array( (string) $state->get_error_code(), array( 'tcas_rate_limited', 'tcas_auth_error', 'tcas_http_status' ), true );
		}

		/**
		 * یک اجرای کامل (یا بخشی از صف) را انجام می‌دهد.
		 *
		 * @param string $source  cron | continue | manual.
		 * @param int    $limit   تعداد آیتم؛ صفر = اندازهٔ تنظیم‌شده.
		 * @param bool   $force   اجرای دستی حتی وقتی چرخه «خاموش» است.
		 * @return array خلاصهٔ اجرا.
		 */
		public static function run( $source = 'cron', $limit = 0, $force = false ) {
			$started = microtime( true );
			$summary = array(
				'ran'       => false,
				'at'        => time(),
				'source'    => (string) $source,
				'processed' => 0,
				'changed'   => 0,
				'errors'    => 0,
				'skipped'   => 0,
				'backlog'   => 0,
				'message'   => '',
				'ok'        => true,
			);

			$settings = TCAS_Core::settings();
			if ( 'yes' !== $settings['enabled'] ) {
				$summary['message'] = 'همگام‌سازی خاموش است؛ جارو اجرا نشد.';
				$summary['ok']      = false;
				return self::save_state( $summary );
			}
			if ( class_exists( 'TCAS_Partners' ) && ! TCAS_Partners::is_configured( TCAS_Partners::active_id() ) ) {
				$summary['message'] = 'اتصال همکار تنظیم نشده است؛ جارو اجرا نشد.';
				$summary['ok']      = false;
				return self::save_state( $summary );
			}
			if ( ! $force && 0 === self::interval() ) {
				$summary['message'] = 'چرخهٔ خودکار خاموش است؛ فقط اجرای دستی ممکن است.';
				return self::save_state( $summary );
			}
			if ( self::in_backoff() ) {
				$backoff            = self::backoff();
				$summary['message'] = sprintf( 'همکار محدودیت اعمال کرده است؛ جارو تا %s متوقف است.', self::clock( isset( $backoff['until'] ) ? $backoff['until'] : time() ) );
				$summary['ok']      = false;
				return self::save_state( $summary );
			}
			if ( function_exists( 'get_transient' ) && false !== get_transient( self::LOCK_KEY ) ) {
				$summary['message'] = 'یک چرخهٔ دیگر در حال اجراست؛ این اجرا رد شد.';
				return self::save_state( $summary );
			}
			if ( function_exists( 'set_transient' ) ) {
				set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
			}

			$ids             = self::stale_ids( $limit );
			$summary['ran']  = true;
			$deadline        = $started + self::TIME_BUDGET;
			$pressure        = false;

			foreach ( $ids as $id ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
				if ( ! $product || $product->is_type( 'variable' ) ) {
					self::mark_skipped( $id );
					$summary['skipped']++;
					continue;
				}
				$before_status = (string) get_post_meta( $id, TCAS_Mapping::META_STATUS, true );
				$before_qty    = (string) get_post_meta( $id, TCAS_Mapping::META_QTY, true );
				$state         = class_exists( 'TCAS_Live' ) ? TCAS_Live::refresh( $id ) : null;
				if ( is_wp_error( $state ) ) {
					$summary['processed']++;
					$summary['errors']++;
					$summary['ok'] = false;
					if ( self::is_pressure_error( $state ) ) {
						$pressure = true;
						self::set_backoff( self::BACKOFF_TTL / MINUTE_IN_SECONDS, $state->get_error_message() );
						break;
					}
					self::mark_failed( $id );
				} elseif ( null === $state ) {
					self::mark_skipped( $id );
					$summary['skipped']++;
				} else {
					$summary['processed']++;
					$after_status = (string) get_post_meta( $id, TCAS_Mapping::META_STATUS, true );
					$after_qty    = (string) get_post_meta( $id, TCAS_Mapping::META_QTY, true );
					if ( $after_status !== $before_status || $after_qty !== $before_qty ) {
						$summary['changed']++;
					}
				}
				if ( self::GAP_US > 0 && function_exists( 'usleep' ) ) {
					usleep( self::GAP_US );
				}
			}

			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( self::LOCK_KEY );
			}

			$previous           = self::state();
			$chain              = 'cron' === $source ? 0 : ( isset( $previous['chain'] ) ? absint( $previous['chain'] ) : 0 );
			$summary['backlog'] = $pressure ? count( $ids ) : self::remaining();
			if ( 0 === $summary['backlog'] ) {
				$chain = 0;
			} elseif ( $chain < self::MAX_CHAIN && ! $pressure && function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
				if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
					wp_schedule_single_event( time() + self::CONTINUE_DELAY, self::CONTINUE_HOOK );
				}
				$chain++;
			}
			$summary['chain'] = $chain;

			$summary['message'] = self::build_message( $summary, $pressure );
			$summary            = self::save_state( $summary );
			if ( class_exists( 'TCAS_Logger' ) && ( $summary['changed'] || $summary['errors'] || 'manual' === $source ) ) {
				TCAS_Logger::add( 'sweep', (string) $source, $summary['message'] );
			}
			return $summary;
		}

		private static function build_message( $summary, $pressure ) {
			if ( $pressure ) {
				$backoff = self::backoff();
				return sprintf(
					'همکار درخواست‌ها را محدود کرد؛ جارو متوقف شد و تا %s ادامه نمی‌یابد. موجودی قبلی برای فروش استفاده می‌شود.',
					self::clock( isset( $backoff['until'] ) ? $backoff['until'] : time() )
				);
			}
			$message = sprintf(
				'%s کد بررسی شد؛ %s تغییر موجودی و %s خطا.',
				self::fa_number( $summary['processed'] ),
				self::fa_number( $summary['changed'] ),
				self::fa_number( $summary['errors'] )
			);
			if ( $summary['backlog'] > 0 ) {
				$message .= ' ' . self::fa_number( $summary['backlog'] ) . ' کد در صف است و در اجرای بعدی خوانده می‌شود.';
			}
			return $message;
		}
	}
}
