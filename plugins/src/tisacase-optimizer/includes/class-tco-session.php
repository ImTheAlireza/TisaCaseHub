<?php
/**
 * مدیریت قفل هم‌زمانی، نشست پردازش دسته‌ای و تاریخچهٔ ممیزی عملیات‌ها.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Session' ) ) {

	/**
	 * نشست، قفل و تاریخچهٔ عملیات‌ها.
	 */
	final class TisaCase_Optimizer_Session {

		const LOCK_TRANSIENT  = 'tco_active_lock';
		const STATE_PREFIX    = 'tco_batch_state_';
		const CANCEL_PREFIX   = 'tco_batch_cancel_';
		const OPTION_HISTORY  = 'tisacase_optimizer_history';
		const HISTORY_MAX     = 50;
		const LOCK_TTL        = 90;
		const STATE_TTL       = 43200; // ۱۲ ساعت.

		/**
		 * تولید شناسهٔ یکتای جلسه.
		 *
		 * @param string $type نوع عملیات.
		 * @return string
		 */
		public static function new_run_id( $type ) {
			return sanitize_key( $type ) . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
		}

		/**
		 * گرفتن قفل عملیات سنگین تا دو تب هم‌زمان تداخل نکنند.
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return bool
		 */
		public static function acquire_lock( $run_id ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				return true;
			}
			$existing = get_transient( self::LOCK_TRANSIENT );
			if ( is_array( $existing ) && ! empty( $existing['run_id'] ) && $existing['run_id'] !== $run_id ) {
				$age = time() - ( isset( $existing['at'] ) ? (int) $existing['at'] : 0 );
				if ( $age < self::LOCK_TTL ) {
					return false;
				}
			}
			set_transient(
				self::LOCK_TRANSIENT,
				array(
					'run_id'  => (string) $run_id,
					'user_id' => get_current_user_id(),
					'at'      => time(),
				),
				self::LOCK_TTL
			);
			return true;
		}

		/**
		 * آزادسازی قفل.
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return void
		 */
		public static function release_lock( $run_id = '' ) {
			$existing = get_transient( self::LOCK_TRANSIENT );
			if ( '' === $run_id || ( is_array( $existing ) && isset( $existing['run_id'] ) && $existing['run_id'] === $run_id ) ) {
				delete_transient( self::LOCK_TRANSIENT );
			}
		}

		/**
		 * ذخیرهٔ وضعیت اجرای دسته‌ای.
		 *
		 * @param string              $run_id شناسهٔ اجرا.
		 * @param array<string,mixed> $state  داده‌های وضعیت.
		 * @return void
		 */
		public static function save_state( $run_id, array $state ) {
			if ( self::is_cancelled( $run_id ) ) {
				return;
			}
			$state['updated_at'] = time();
			set_transient( self::STATE_PREFIX . sanitize_key( $run_id ), $state, self::STATE_TTL );
		}

		/**
		 * خواندن وضعیت اجرای دسته‌ای.
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return array<string,mixed>|null
		 */
		public static function get_state( $run_id ) {
			$state = get_transient( self::STATE_PREFIX . sanitize_key( $run_id ) );
			return is_array( $state ) ? $state : null;
		}

		/**
		 * علامت‌گذاری لغو اجرا (Tombstone) و آزادسازی قفل.
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return void
		 */
		public static function cancel( $run_id ) {
			$clean = sanitize_key( $run_id );
			set_transient( self::CANCEL_PREFIX . $clean, 1, HOUR_IN_SECONDS );
			delete_transient( self::STATE_PREFIX . $clean );
			self::release_lock( $run_id );
		}

		/**
		 * آیا اجرا لغو شده است؟
		 *
		 * @param string $run_id شناسهٔ اجرا.
		 * @return bool
		 */
		public static function is_cancelled( $run_id ) {
			return (bool) get_transient( self::CANCEL_PREFIX . sanitize_key( $run_id ) );
		}

		/**
		 * ثبت یک رویداد در تاریخچهٔ عملیات‌ها (حداکثر ۵۰ رکورد اخیر).
		 *
		 * @param string              $action      کلید عملیات.
		 * @param string              $label       عنوان فارسی عملیات.
		 * @param int                 $items_count تعداد موارد پردازش‌شده.
		 * @param int                 $saved_bytes بایت آزادشده/صرفه‌جویی‌شده.
		 * @param string              $note        توضیح کوتاه.
		 * @param string              $status      وضعیت (success | warning | info).
		 * @return array<string,mixed> رکورد ثبت‌شده.
		 */
		public static function log( $action, $label, $items_count = 0, $saved_bytes = 0, $note = '', $status = 'success' ) {
			$history = self::history();
			$user    = wp_get_current_user();
			$entry   = array(
				'id'          => 'log-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 4, false, false ) ),
				'action'      => sanitize_key( $action ),
				'label'       => sanitize_text_field( $label ),
				'items_count' => max( 0, (int) $items_count ),
				'saved_bytes' => max( 0, (int) $saved_bytes ),
				'note'        => sanitize_text_field( $note ),
				'status'      => in_array( $status, array( 'success', 'warning', 'info' ), true ) ? $status : 'success',
				'user'        => ( $user && $user->exists() ) ? $user->display_name : 'WP-CLI / Cron',
				'created_at'  => current_time( 'mysql' ),
				'timestamp'   => time(),
			);

			array_unshift( $history, $entry );
			if ( count( $history ) > self::HISTORY_MAX ) {
				$history = array_slice( $history, 0, self::HISTORY_MAX );
			}
			update_option( self::OPTION_HISTORY, $history, false );
			return $entry;
		}

		/**
		 * دریافت تاریخچهٔ عملیات‌ها.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function history() {
			$raw = get_option( self::OPTION_HISTORY, array() );
			return is_array( $raw ) ? $raw : array();
		}

		/**
		 * پاک‌سازی تاریخچهٔ عملیات‌ها.
		 *
		 * @return void
		 */
		public static function clear_history() {
			update_option( self::OPTION_HISTORY, array(), false );
		}
	}
}
