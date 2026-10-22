<?php
/**
 * رابط خط فرمان حرفه‌ای `WP-CLI` برای پردازش‌های سنگین روی سرور بدون محدودیت زمان مرورگر.
 *
 * دستورات:
 *   wp tisacase optimize status
 *   wp tisacase optimize scan [--rebuild]
 *   wp tisacase optimize clean-unused [--permanent] [--yes]
 *   wp tisacase optimize clean-unscaled
 *   wp tisacase optimize compress [--webp] [--limit=500]
 *   wp tisacase optimize dedup [--permanent]
 *   wp tisacase optimize seo
 *   wp tisacase optimize regen [--limit=500]
 *   wp tisacase optimize vault [--purge-expired]
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_CLI' ) ) {

	/**
	 * دستورات WP-CLI مرکز بهینه‌سازی تیساکیس.
	 */
	final class TisaCase_Optimizer_CLI {

		/**
		 * اجرای زیرفرمان خط فرمان.
		 *
		 * @param string[]             $args       آرگومان‌های موقعیتی.
		 * @param array<string,string> $assoc_args آرگومان‌های کلیددار.
		 * @return void
		 */
		public static function handle( $args, $assoc_args ) {
			$sub = isset( $args[0] ) ? sanitize_key( $args[0] ) : 'status';

			switch ( $sub ) {
				case 'status':
				case 'scan':
					$ov = TisaCase_Optimizer_Media_Inspector::overview_stats( isset( $assoc_args['rebuild'] ) || 'scan' === $sub );
					\WP_CLI::log( sprintf( 'کل تصاویر: %d | کل فایل‌های فیزیکی (Inodes): %d', $ov['total_images'], $ov['total_inodes'] ) );
					\WP_CLI::log( sprintf( 'حجم کل رسانه: %s (اصلی: %s | زیرسایزها: %s | خام -scaled: %s)', size_format( $ov['media_bytes'] ), size_format( $ov['layers']['main_bytes'] ), size_format( $ov['layers']['subsizes_bytes'] ), size_format( $ov['layers']['unscaled_bytes'] ) ) );
					\WP_CLI::log( sprintf( 'مطلقاً بدون استفاده: %d مورد (%s) | تکراری: %d کپی در %d گروه (%s)', $ov['quick_wins']['unused']['count'], size_format( $ov['quick_wins']['unused']['bytes'] ), $ov['quick_wins']['duplicates']['count'], $ov['quick_wins']['duplicates']['groups'], size_format( $ov['quick_wins']['duplicates']['bytes'] ) ) );
					\WP_CLI::success( 'گزارش تحلیل رسانه آماده شد.' );
					break;

				case 'clean-unused':
					$mode  = isset( $assoc_args['permanent'] ) ? 'permanent' : 'quarantine';
					$query = TisaCase_Optimizer_Media_Inspector::query_items(
						array(
							'usage'        => 'strict_unused',
							'force_rescan' => true,
							'per_page'     => 100,
						)
					);
					$ids   = isset( $query['matching_ids'] ) ? (array) $query['matching_ids'] : array();
					if ( empty( $ids ) ) {
						\WP_CLI::success( 'هیچ تصویر بدون استفاده‌ای یافت نشد.' );
						return;
					}
					if ( ! isset( $assoc_args['yes'] ) ) {
						\WP_CLI::confirm( sprintf( 'آیا %d تصویر کاملاً بدون استفاده با حالت «%s» پردازش شوند؟', count( $ids ), $mode ) );
					}
					$saved   = 0;
					$done    = 0;
					$blocked = 0;
					foreach ( $ids as $aid ) {
						// راستی‌آزمایی زندهٔ استفاده پیش از حذف (همان محافظ رابط مدیریتی).
						$check = TisaCase_Optimizer_Usage::verify_attachment( $aid, false );
						if ( ! empty( $check['used'] ) ) {
							++$blocked;
							\WP_CLI::log( sprintf( 'رد شد #%d — %s', $aid, implode( ' | ', (array) $check['reasons'] ) ) );
							continue;
						}
						$res    = ( 'permanent' === $mode )
							? TisaCase_Optimizer_Vault::delete_attachment_permanently( $aid )
							: TisaCase_Optimizer_Vault::quarantine_attachment( $aid, 'unused' );
						if ( ! is_wp_error( $res ) ) {
							++$done;
							$saved += isset( $res['saved_bytes'] ) ? (int) $res['saved_bytes'] : (int) $res['total_bytes'];
						}
					}
					TisaCase_Optimizer_Reference_Scanner::invalidate_cache();
					\WP_CLI::success( sprintf( '%d تصویر پردازش شد (%s آزاد شد). %d مورد به‌دلیل استفادهٔ واقعی رد شد.', $done, size_format( $saved ), $blocked ) );
					break;

				case 'clean-unscaled':
					$query = TisaCase_Optimizer_Media_Inspector::query_items( array( 'opt_state' => 'has_unscaled' ) );
					$ids   = isset( $query['matching_ids'] ) ? (array) $query['matching_ids'] : array();
					$saved = 0;
					foreach ( $ids as $aid ) {
						$r      = TisaCase_Optimizer_Image_Processor::clean_unscaled_original( $aid );
						$saved += ! is_wp_error( $r ) ? (int) $r['saved_bytes'] : 0;
					}
					\WP_CLI::success( sprintf( '%d فایل خام پشت -scaled پاک شد (%s آزاد شد).', count( $ids ), size_format( $saved ) ) );
					break;

				case 'compress':
					$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 500;
					$ovr   = isset( $assoc_args['webp'] ) ? array( 'nextgen_format' => 'webp' ) : array();
					$query = TisaCase_Optimizer_Media_Inspector::query_items( array( 'opt_state' => 'unoptimized' ) );
					$ids   = array_slice( isset( $query['matching_ids'] ) ? (array) $query['matching_ids'] : array(), 0, $limit );
					$saved = 0;
					foreach ( $ids as $aid ) {
						$r      = TisaCase_Optimizer_Image_Processor::optimize_attachment( $aid, $ovr );
						$saved += ! is_wp_error( $r ) ? (int) $r['saved_bytes'] : 0;
					}
					\WP_CLI::success( sprintf( '%d تصویر بهینه شد (%s صرفه‌جویی شد).', count( $ids ), size_format( $saved ) ) );
					break;

				case 'dedup':
					$mode   = isset( $assoc_args['permanent'] ) ? 'permanent' : 'quarantine';
					$report = TisaCase_Optimizer_Deduplicator::find_duplicate_groups( true );
					$merged = 0;
					$saved  = 0;
					foreach ( $report['groups'] as $grp ) {
						$r = TisaCase_Optimizer_Deduplicator::merge_group( (int) $grp['master_id'], (array) $grp['dup_ids'], $mode );
						if ( ! is_wp_error( $r ) ) {
							$merged += $r['merged'];
							$saved  += $r['saved_bytes'];
						}
					}
					\WP_CLI::success( sprintf( '%d تصویر تکراری ادغام شد (%s آزاد شد).', $merged, size_format( $saved ) ) );
					break;

				case 'seo':
					$updated = TisaCase_Optimizer_Image_SEO::bulk_generate( array(), true );
					\WP_CLI::success( sprintf( 'متن جایگزین (Alt) برای %d تصویر تکمیل شد.', $updated ) );
					break;

				case 'vault':
					if ( isset( $assoc_args['purge-expired'] ) ) {
						$n = TisaCase_Optimizer_Vault::purge_expired_quarantine();
						\WP_CLI::success( sprintf( '%d بستهٔ منقضی از قرنطینه پاک شد.', $n ) );
						return;
					}
					$sum = TisaCase_Optimizer_Vault::summary();
					\WP_CLI::log( sprintf( 'قرنطینه: %d مورد (%s) | پشتیبان‌های اولیه: %d مورد (%s)', $sum['quarantine_count'], size_format( $sum['quarantine_bytes'] ), $sum['originals_count'], size_format( $sum['originals_bytes'] ) ) );
					break;

				default:
					\WP_CLI::error( 'زیرفرمان نامعتبر است. گزینه‌ها: status | scan | clean-unused | clean-unscaled | compress | dedup | seo | vault' );
			}
		}
	}
}
