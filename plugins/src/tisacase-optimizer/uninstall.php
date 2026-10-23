<?php
/**
 * پاک‌سازی ایمن تنظیمات و کرون‌ها هنگام حذف افزونه.
 *
 * نکتهٔ ایمنی مهم:
 * اگر فایلی هنوز داخل قرنطینه (`uploads/tisa-opt-vault/quarantine/`) باقی مانده باشد،
 * پوشهٔ قرنطینه عمداً حذف نمی‌شود تا هیچ تصویر قرنطینه‌شده‌ای پیش از بازبینی از دست نرود؛
 * فقط در صورتی که قرنطینه خالی باشد کل پوشهٔ خزانه نیز جمع‌آوری می‌گردد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'tisacase_optimizer_daily_vault_purge' );

delete_option( 'tisacase_optimizer_settings' );
delete_option( 'tisacase_optimizer_stats' );
delete_option( 'tisacase_optimizer_pinned_ids' );
delete_option( 'tisacase_optimizer_history' );
delete_transient( 'tco_active_lock' );

$quarantine = get_option( 'tisacase_optimizer_quarantine_items', array() );
if ( empty( $quarantine ) ) {
	delete_option( 'tisacase_optimizer_quarantine_items' );
	delete_option( 'tisacase_optimizer_original_backups' );
}
