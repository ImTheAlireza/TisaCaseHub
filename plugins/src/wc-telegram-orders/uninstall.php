<?php
/**
 * پاک‌سازی کامل هنگام حذف افزونه:
 * تنظیمات، وضعیت سنجاق گزارش، نقطه شروع گزارش روزانه،
 * جدول لاگ رویدادها، متاهای محصولات/سفارش‌ها و رویدادهای زمان‌بندی‌شده.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('wc_telegram_orders_settings');
delete_option('wc_telegram_pinned_messages');
delete_option('wc_telegram_last_report_end');
delete_option('wc_telegram_daily_report_lock');
delete_option('wc_telegram_migrated_version');
delete_option('wc_telegram_template_version');
delete_option('wc_telegram_log_db_version');
delete_option('wc_telegram_status_last_slot');
delete_option('wc_telegram_status_last_sent');
delete_option('wc_telegram_fail_streak');
delete_option('wc_telegram_health_alert');
delete_transient('wc_telegram_test_result');

wp_clear_scheduled_hook('wc_telegram_daily_report');
wp_clear_scheduled_hook('wc_telegram_send_order');
wp_clear_scheduled_hook('wc_telegram_stock_flush');
wp_clear_scheduled_hook('wc_telegram_maintenance');
wp_clear_scheduled_hook('wc_telegram_send_status');
wp_clear_scheduled_hook('wc_telegram_sweep_pending');

// رویدادهای باقی‌مانده (از جمله زمان‌بندی‌های قدیمی با آرگومان) مستقیماً از
// آرایهٔ کرون حذف می‌شوند تا هیچ callback افزونه پس از حذف اجرا نشود.
$crons = get_option('cron');
if (is_array($crons)) {
    $changed = false;
    foreach ($crons as $timestamp => $hooks) {
        if (!is_array($hooks)) {
            continue;
        }
        foreach (['wc_telegram_daily_report', 'wc_telegram_stock_flush', 'wc_telegram_send_order'] as $hook) {
            if (isset($hooks[$hook])) {
                unset($crons[$timestamp][$hook]);
                $changed = true;
            }
        }
        if (empty($crons[$timestamp])) {
            unset($crons[$timestamp]);
        }
    }
    if ($changed) {
        update_option('cron', $crons);
    }
}

// جدول لاگ رویدادها
$log_table = $wpdb->prefix . 'wc_telegram_logs';
$wpdb->query("DROP TABLE IF EXISTS {$log_table}");

// ترنزینت‌های بازه توقف اعلان‌های موجودی
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wc\_telegram\_alert\_%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_wc\_telegram\_alert\_%'");

// متاهای وضعیت روی محصولات و سفارش‌ها (حالت قدیمی CPT و متاهای سینک‌شده)
$meta_keys = "'_wc_telegram_stock_state','_wc_telegram_sent','_wc_telegram_pending','_wc_telegram_expired','_wc_telegram_needs_address','_wc_telegram_attempts','_wc_telegram_send_lock','_wc_telegram_force'";
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ({$meta_keys})");

// متاهای سفارش در حالت HPOS (جدول اختصاصی ووکامرس)
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)) === $hpos_meta_table) {
    $wpdb->query("DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE '\\_wc\\_telegram%'");
}
