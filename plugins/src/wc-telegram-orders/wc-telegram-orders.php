<?php
/**
 * Plugin Name:       ارسال سفارش‌ها به تلگرام ووکامرس
 * Description:       ارسال خودکار سفارش‌های جدید ووکامرس به تلگرام با فرمت فارسی دلخواه + گزارش روزانه فروش (با سنجاق خودکار) + اعلان کمبود موجودی محصولات + سیستم لاگ رویدادها در پنل.
 * Version:           1.14.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            علیرضا شعبان زاده
 * License:           GPL-2.0-or-later
 * Requires Plugins:  woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WC_TELEGRAM_ORDERS_VERSION', '1.14.2');
define('WC_TELEGRAM_ORDERS_OPTION', 'wc_telegram_orders_settings');
define('WC_TELEGRAM_ORDERS_FILE', __FILE__);

/**
 * سازگاری با HPOS ووکامرس
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__);
    }
});

class WC_Telegram_Orders {

    const CRON_HOOK        = 'wc_telegram_daily_report';
    const SEND_ORDER_HOOK  = 'wc_telegram_send_order';       // ارسال غیرهمزمان پیام سفارش
    const STOCK_FLUSH_HOOK = 'wc_telegram_stock_flush';      // ارسال غیرهمزمان اعلان‌های موجودی
    const MAINT_HOOK       = 'wc_telegram_maintenance';      // نگهداری دوره‌ای (پاک‌سازی لاگ)
    const STATUS_HOOK      = 'wc_telegram_send_status';      // ارسال غیرهمزمان پیام تغییر وضعیت (صف + فاصله)
    const SWEEP_HOOK       = 'wc_telegram_sweep_pending';    // جاروی دوره‌ای سفارش‌های معوق
    const LOG_DB_OPTION    = 'wc_telegram_log_db_version';   // نسخه ساختار جدول لاگ
    const LOG_COOLDOWN     = 600;                            // بازه توقف اعلان تکراری هر محصول (ثانیه)
    const PINNED_OPTION    = 'wc_telegram_pinned_messages';  // آخرین پیام سنجاق‌شده در هر چت
    const LAST_REPORT_OPTION = 'wc_telegram_last_report_end'; // پایان بازه آخرین گزارش روزانه (UTC)
    const DAILY_REPORT_LOCK_OPTION = 'wc_telegram_daily_report_lock'; // قفل اتمیک برای جلوگیری از گزارش خودکار هم‌زمان
    const DAILY_REPORT_LOCK_TTL = 1800; // قفل پس از توقف غیرعادی حداکثر پس از ۳۰ دقیقه آزاد می‌شود
    const DAILY_REPORT_DUPLICATE_WINDOW = 900; // تکرار خودکار تا ۱۵ دقیقه پس از گزارش موفق نادیده گرفته می‌شود
    const MIGRATION_OPTION = 'wc_telegram_migrated_version';  // نسخه‌ای که مهاجرت قالب‌ها برایش انجام شده
    const TEMPLATE_VERSION_OPTION = 'wc_telegram_template_version'; // نسخهٔ قالب پیش‌فرضی که اعمال شده
    const TEMPLATE_VERSION = '1.12.4';                        // فقط با تغییرِ قالب پیش‌فرض بالا می‌رود
    const STOCK_STATE_META = '_wc_telegram_stock_state';      // وضعیت اعلان موجودی هر محصول: '' | low | out
    const HEALTH_STREAK_OPTION = 'wc_telegram_fail_streak';   // شمار ارسال‌های ناموفق پشت‌سرهم (هشدار سلامت)
    const HEALTH_ALERT_OPTION  = 'wc_telegram_health_alert';  // زمان آخرین هشدار سلامت (جلوگیری از تکرار)
    const FORCE_SEND_META      = '_wc_telegram_force';        // ارسال اجباریِ یک‌بارمصرف (بدون شرط پرداخت)

    // صف اعلان‌های موجودی همین درخواست — در پایان درخواست یکجا ارسال می‌شود
    // (سفارش چندقلمی → یک پیام، نه چند پیام پشت‌سرهم)
    private $stock_queue = [];
    private $settings_cache = null;
    private $log_table_ready = null; // نتیجه بررسی وجود جدول لاگ (یک بار در هر درخواست)

    public function __construct() {
        // ارسال با هر سفارش جدید (تسویه‌حساب، پیشخوان، REST، سازگار با HPOS)
        // اولویت دیر (999): افزونه‌های دیگر (مثل افزونه شهرها) اول شهر/استان را نهایی کنند
        add_action('woocommerce_new_order', [$this, 'on_new_order'], 999, 2);

        // صفحه تنظیمات
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_wc_telegram_test', [$this, 'handle_test_message']);
        add_action('admin_post_wc_telegram_reset', [$this, 'handle_reset_template']);
        add_action('admin_post_wc_telegram_daily_now', [$this, 'handle_daily_now']);
        add_action('admin_post_wc_telegram_debug', [$this, 'handle_debug']);
        add_action('admin_post_wc_telegram_stock_test', [$this, 'handle_stock_test']);
        add_action('admin_post_wc_telegram_stock_scan', [$this, 'handle_stock_scan']);

        // بخش لاگ رویدادها
        add_action('admin_post_wc_telegram_log_clear', [$this, 'handle_log_clear']);
        add_action('admin_post_wc_telegram_log_csv', [$this, 'handle_log_csv']);

        // اعلان کمبود موجودی محصولات (هر مسیر تغییر موجودی: سفارش، ویرایش دستی، REST، ایمپورت)
        add_action('woocommerce_product_set_stock', [$this, 'on_stock_changed']);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock_changed']);
        add_action('woocommerce_reduce_order_stock', [$this, 'attach_order_to_stock_queue']);
        add_action('shutdown', [$this, 'flush_stock_queue'], 5);

        // گزارش روزانه فروش (WP-Cron — هر شب ساعت مشخص)
        add_action('init', [$this, 'maybe_schedule_daily']);
        add_filter('cron_schedules', function ($sch) {
            if (!isset($sch['wc_telegram_15min'])) {
                $sch['wc_telegram_15min'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'هر ۱۵ دقیقه (تلگرام ووکامرس)'];
            }
            return $sch;
        });
        add_action(self::CRON_HOOK, [$this, 'send_daily_report']);
        add_action(self::MAINT_HOOK, [$this, 'prune_logs']);
        add_action(self::SEND_ORDER_HOOK, [$this, 'process_order_send']);
        add_action(self::STOCK_FLUSH_HOOK, [$this, 'process_stock_flush']);
        add_action(self::STATUS_HOOK, [$this, 'process_status_send'], 10, 4);
        add_action(self::SWEEP_HOOK, [$this, 'sweep_pending_orders']);
        // اقلام سفارش بعد از ساخت سفارش اضافه می‌شوند (درگاه‌های زودهنگام) — همان لحظه پیام معوق بررسی شود
        add_action('woocommerce_new_order_item', [$this, 'on_new_order_item'], 10, 3);
        add_action('woocommerce_after_order_object_save', [$this, 'on_order_saved'], 10, 1);
        add_action('update_option_' . WC_TELEGRAM_ORDERS_OPTION, [$this, 'reset_settings_cache'], 5, 0);
        add_action('update_option_' . WC_TELEGRAM_ORDERS_OPTION, [$this, 'reschedule_on_settings'], 10, 2);
        add_action('update_option_' . WC_TELEGRAM_ORDERS_OPTION, [$this, 'log_settings_update'], 20, 2);
        register_activation_hook(WC_TELEGRAM_ORDERS_FILE, [$this, 'on_activate']);
        register_deactivation_hook(WC_TELEGRAM_ORDERS_FILE, [$this, 'on_deactivate']);

        // دکمه «ارسال به تلگرام» در صفحه ویرایش سفارش (برای ارسال مجدد)
        add_filter('woocommerce_order_actions', [$this, 'add_order_action']);
        add_action('woocommerce_order_action_wc_telegram_resend', [$this, 'resend_via_order_action']);

        // اکشن گروهی در لیست سفارش‌ها — هر دو حالت: لیست قدیمی (CPT) و HPOS
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_actions']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_actions']);
        add_action('handle_bulk_actions-edit_shop_order', [$this, 'handle_bulk_send'], 10, 3);
        add_action('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_send'], 10, 3);
        add_action('admin_notices', [$this, 'bulk_send_notice']);

        // اطلاع کوتاه با هر تغییر وضعیت سفارش (لغو، تکمیل، استرداد و...)
        add_action('woocommerce_order_status_changed', [$this, 'on_status_changed'], 10, 4);

        // ارسال پیام معوق وقتی سفارش کامل شد (پرداخت/تکمیل آدرس)
        add_action('woocommerce_payment_complete', [$this, 'flush_pending_message']);
        // ذخیره دستی سفارش در پیشخوان (مثلاً اصلاح آدرس توسط مدیر) هم بررسی شود
        add_action('woocommerce_process_shop_order_meta', [$this, 'flush_pending_message']);
    }

    /* ---------------- تنظیمات ---------------- */

    public function defaults() {
        return [
            'enabled'       => 'yes',
            // شرط پرداخت: سفارش تا رسیدن به وضعیت مجاز (پیش‌فرض «در حال انجام» = پرداخت‌شده) معرفی نمی‌شود؛
            // سفارش در انتظار پرداخت/لغوشده هیچ پیامی نمی‌گیرد
            'paid_gate'     => 'yes',
            'send_statuses' => 'processing',
            'bot_token'     => '',
            'chat_ids'      => '',
            'template'      => $this->default_template(),
            'daily_enabled'   => 'yes',
            'daily_time'      => '23:59',
            'status_enabled'  => 'yes',
            'status_template' => $this->default_status_template(),
            'status_ignore'   => 'pws-in-stock',   // وضعیت‌هایی که پیام کوتاه نمی‌گیرند (با کاما)
            'status_gap'      => 3,                // فاصلهٔ حداقلی بین دو پیام وضعیت (ثانیه) — جلوگیری از 429
            'daily_paid_only' => 'yes',
            'daily_pin'       => 'yes',
            'timezone'        => 'Asia/Tehran',
            'currency_label'  => 'تومان',
            'items_link'      => 'yes',   // عنوان هر آیتم در پیام سفارش به صفحهٔ محصول لینک شود
            // تاریخ شمسی در پیام‌ها و پنل
            'jalali_date'     => 'yes',
            'jalali_digits'   => 'yes',   // رقم‌های تاریخ به فارسی (۱۴۰۵/۰۶/۲۶)
            // ارسال گروهی از لیست سفارش‌ها
            'bulk_gap'        => 2,       // فاصلهٔ بین پیام‌های ارسال گروهی (ثانیه) — ضد سیل
            'bulk_max'        => 50,      // سقف تعداد سفارش در هر ارسال گروهی
            // هشدار سلامت ربات
            'health_enabled'  => 'yes',
            'health_threshold'=> 5,       // چند ارسال ناموفقِ پشت‌سرهم = هشدار
            'health_cooldown' => 6,       // حداقل فاصلهٔ بین دو هشدار (ساعت)
            // تب «اعلانات محصولات»
            'stock_enabled'     => 'yes',
            'stock_threshold'   => 5,
            'stock_out_enabled' => 'yes',
            'stock_chat_ids'    => '',
            'stock_template'    => $this->default_stock_template(),
            // سیستم لاگ
            'log_enabled'        => 'yes',
            'log_level'          => 'info',   // debug | info | warning | error
            'log_retention_days' => 30,
            'log_max_rows'       => 2000,
        ];
    }

    public function get_settings() {
        if (is_array($this->settings_cache)) {
            return $this->settings_cache;
        }
        $saved = get_option(WC_TELEGRAM_ORDERS_OPTION, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $settings = wp_parse_args($saved, $this->defaults());
        $settings = $this->maybe_migrate_templates($settings);
        $this->settings_cache = $settings;
        return $settings;
    }

    public function reset_settings_cache() {
        $this->settings_cache = null;
    }

    // مهاجرت قالب‌های قدیمی — یک بار برای هر نسخه (به‌جای بررسی رشته‌ای در هر بار خواندن
    // که قالبی را که کاربر عمداً حاوی متن قدیمی ذخیره می‌کرد بی‌صدا ریست می‌کرد)
    private function normalize_template($t) {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $t));
    }

    private function maybe_migrate_templates($settings) {
        /*
         * ارتقای «قالب پیش‌فرض»: فقط وقتی قالبِ ذخیره‌شده دقیقاً همان قالب پیش‌فرضِ قبلی باشد
         * (یعنی مدیر آن را شخصی‌سازی نکرده) به قالب جدید ارتقا می‌یابد.
         * هر قالبِ دست‌کاری‌شده — حتی یک فاصلهٔ اضافه — دست‌نخورده باقی می‌ماند.
         */
        $tpl_ver = (string) get_option(self::TEMPLATE_VERSION_OPTION, '');
        if ($tpl_ver !== self::TEMPLATE_VERSION) {
            $stored = isset($settings['template']) ? (string) $settings['template'] : '';
            if ($stored !== '') {
                $stored_norm = $this->normalize_template($stored);
                // فقط قالب‌های پیش‌فرضِ «دست‌نخورده» ارتقا می‌یابند
                $is_untouched = ($stored_norm === $this->normalize_template($this->previous_default_template()))
                             || ($stored_norm === $this->normalize_template($this->legacy_default_template()));
                if ($is_untouched) {
                    $settings['template'] = $this->default_template();
                }
            }
            update_option(self::TEMPLATE_VERSION_OPTION, self::TEMPLATE_VERSION, false);
        }

        $done = (string) get_option(self::MIGRATION_OPTION, '');
        if ($done === WC_TELEGRAM_ORDERS_VERSION) {
            return $settings;
        }
        if ($done === '') {
            // قالب انگلیسی نسخه 1.0.0
            if (!empty($settings['template']) && strpos($settings['template'], 'New Order') !== false) {
                $settings['template'] = $this->default_template();
            }
            // قالب فارسی بدون لیبل (نسخه 1.2.1 و قبل)
            if (!empty($settings['template']) && strpos($settings['template'], "📞 {customer_phone}\n📍 {customer_address}\n✉️ {customer_postcode}") !== false) {
                $settings['template'] = $this->default_template();
            }
        }
        update_option(self::MIGRATION_OPTION, WC_TELEGRAM_ORDERS_VERSION, false);
        return $settings;
    }

    // قالب پیش‌فرضِ نسخهٔ ۱.۱۰.۱ — فقط برای تشخیصِ «قالبِ دست‌نخورده» هنگام ارتقا
    // (اگر مدیر قالب را شخصی‌سازی کرده باشد، هرگز با این یکی برابر نیست و دست نمی‌خورد)
    public function previous_default_template() {
        return "🛍️ <b>سفارش جدید با کد {order_number}</b>\n"
            . "📅 {order_date}\n"
            . "📦 <b>وضعیت سفارش: {order_status}</b>\n"
            . "--------------------------\n"
            . "🧾 <b>آیتم‌ها ({items_count}):</b>\n"
            . "\n"
            . "{items}\n"
            . "--------------------------\n"
            . "جمع محصولات: {subtotal}\n"
            . "حمل و نقل: {shipping_method} - {shipping_total}\n"
            . "💵 <b>مجموع سفارش: {order_total}</b>\n"
            // این خط فقط برای سفارش‌هایی نمایش داده می‌شود که بخشی از آن با کیف پول پرداخت شده باشد
            . "{if_wallet}💵 <b>پرداختی: {paid_amount}</b> ({wallet_amount} از کیف پول)\n{/if_wallet}"
            . "\n"
            . "💳 نحوه پرداخت: {payment_method}\n"
            . "--------------------------\n"
            . "👤 <b>اطلاعات مشتری:</b>\n"
            . "\n"
            . "نام: {customer_name}\n"
            . "\n"
            . "📞 تلفن: {customer_phone}\n"
            . "📍 آدرس: {customer_address}\n"
            . "✉️ کد پستی: {customer_postcode}\n"
            . "--------------------------\n"
            . "🔗 <a href=\"{order_url}\">مشاهده سفارش در پنل پیشخوان</a>";
    }


    // قالبِ خیلی قدیمی (۱.۱۰.۰ و قبل) — برای سازگاری با نصب‌های قدیمی‌تر
    private function legacy_default_template() {
        return "🛍️ <b>سفارش جدید با کد {order_number}</b>\n"
            . "\n"
            . "📅 {order_date}\n"
            . "\n"
            . "--------------------------\n"
            . "\n"
            . "🧾 <b>آیتم‌ها ({items_count}):</b>\n"
            . "\n"
            . "{items}\n"
            . "\n"
            . "--------------------------\n"
            . "\n"
            . "جمع محصولات: {subtotal}\n"
            . "حمل و نقل: {shipping_method} - {shipping_total}\n"
            . "💵 <b>مجموع: {order_total}</b>\n"
            . "\n"
            . "--------------------------\n"
            . "\n"
            . "👤 <b>اطلاعات مشتری:</b>\n"
            . "\n"
            . "نام: {customer_name}\n"
            . "📞 تلفن: {customer_phone}\n"
            . "📍 آدرس: {customer_address}\n"
            . "✉️ کد پستی: {customer_postcode}\n"
            . "\n"
            . "--------------------------\n"
            . "\n"
            . "🔗 <a href=\"{order_url}\">مشاهده سفارش در پنل پیشخوان</a>";
    }

    // قالب پیش‌فرض پیام سفارش جدید (۱.۱۰.۳)
    public function default_template() {
        return "🛍️ <b>سفارش جدید با کد {order_number}</b>\n"
            . "📅 {order_date}\n"
            . "📦 <b>وضعیت سفارش: {order_status}</b>\n"
            . "--------------------------\n"
            . "🧾 <b>آیتم‌ها ({items_count}):</b>\n"
            . "\n"
            . "{items}\n"
            . "--------------------------\n"
            . "جمع محصولات: {subtotal}\n"
            // فقط وقتی تخفیف/کوپن اعمال شده باشد
            . "{if_discount}🏷 تخفیف: {discount_total}- ({coupons})\n{/if_discount}"
            . "حمل و نقل: {shipping_method} - {shipping_total}\n"
            . "💵 <b>مجموع سفارش: {order_total}</b>\n"
            // این خط فقط برای سفارش‌هایی نمایش داده می‌شود که بخشی از آن با کیف پول پرداخت شده باشد
            . "{if_wallet}💵 <b>پرداختی: {paid_amount}</b> ({wallet_number}+ تومان از کیف پول)\n{/if_wallet}"
            . "💳 نحوه پرداخت: {payment_method}\n"
            . "--------------------------\n"
            . "👤 <b>اطلاعات مشتری:</b>\n"
            . "\n"
            . "نام: {customer_name}\n"
            . "\n"
            . "📞 تلفن: {customer_phone}\n"
            . "📍 آدرس: {customer_address}\n"
            . "✉️ کد پستی: {customer_postcode}\n"
            . "--------------------------\n"
            . "🔗 <a href=\"{order_url}\">مشاهده سفارش در پنل پیشخوان</a>";
    }

    public function default_status_template() {
        return "🔄 <b>تغییر وضعیت سفارش #{order_number}</b>\n"
            . "وضعیت: {old_status} ← {new_status}\n"
            . "👤 {customer_name} — 💵 {order_total}\n"
            . "🔗 <a href=\"{order_url}\">مشاهده سفارش</a>";
    }

    public function default_stock_template() {
        return "{stock_emoji} <b>{stock_label}</b>\n"
            . "🔸 {product_name}\n"
            . "{variation}\n"
            . "📦 موجودی فعلی: {stock} عدد (آستانه هشدار: کمتر از {threshold})\n"
            . "🏷️ SKU: {sku}\n"
            . "{order_info}\n"
            . "🔗 <a href=\"{product_url}\">ویرایش محصول</a>";
    }

    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'تلگرام ووکامرس',
            'ارسال سفارش‌ها به تلگرام',
            'manage_woocommerce',
            'wc-telegram-orders',
            [$this, 'render_settings_page']
        );
    }

    // استایل ادمین فقط روی صفحهٔ خودمان (بعد از لایهٔ توکن هاب اگر فعال باشد)
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'woocommerce_page_wc-telegram-orders') {
            return;
        }
        wp_enqueue_style(
            'wcto-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            wp_style_is('tisacase-ui', 'registered') ? ['tisacase-ui'] : [],
            WC_TELEGRAM_ORDERS_VERSION
        );
        wp_enqueue_script('wcto-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', [], WC_TELEGRAM_ORDERS_VERSION, true);
    }

    public function register_settings() {
        register_setting(
            'wc_telegram_orders_group',
            WC_TELEGRAM_ORDERS_OPTION,
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [],
            ]
        );
    }

    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : [];
        $tab = isset($input['_tab']) ? sanitize_key($input['_tab']) : '';
        $allowed = ['b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'code' => [], 'pre' => [], 'a' => ['href' => []]];

        // شروع از تنظیمات ذخیره‌شده فعلی؛ فقط فیلدهای تبِ ارسال‌شده بازنویسی می‌شوند
        // (بدون این، ذخیره تب «اعلانات محصولات» تنظیمات تب سفارش‌ها را به پیش‌فرض برمی‌گرداند و برعکس)
        $out = $this->get_settings();
        unset($out['_tab']);

        /* ---- تب «ارسال سفارشات جدید» ---- */
        if ($tab === '' || $tab === 'orders') {
            $out['enabled'] = (!empty($input['enabled']) && $input['enabled'] === 'yes') ? 'yes' : 'no';
            // تاریخ شمسی در پیام‌ها و پنل
            $out['jalali_date']   = (!empty($input['jalali_date']) && $input['jalali_date'] === 'yes') ? 'yes' : 'no';
            $out['jalali_digits'] = (!empty($input['jalali_digits']) && $input['jalali_digits'] === 'yes') ? 'yes' : 'no';
            // ارسال گروهی از لیست سفارش‌ها (ضد سیل)
            if (isset($input['bulk_gap'])) {
                $out['bulk_gap'] = max(0, min(60, (int) $input['bulk_gap']));
            }
            if (isset($input['bulk_max'])) {
                $out['bulk_max'] = max(1, min(200, (int) $input['bulk_max']));
            }
            // هشدار سلامت ربات
            $out['health_enabled'] = (!empty($input['health_enabled']) && $input['health_enabled'] === 'yes') ? 'yes' : 'no';
            if (isset($input['health_threshold'])) {
                $out['health_threshold'] = max(2, min(50, (int) $input['health_threshold']));
            }
            if (isset($input['health_cooldown'])) {
                $out['health_cooldown'] = max(1, min(168, (int) $input['health_cooldown']));
            }
            // شرط پرداخت: فقط سفارش‌های پرداخت‌شده (وضعیت مجاز) به تلگرام بروند
            $out['paid_gate'] = (!empty($input['paid_gate']) && $input['paid_gate'] === 'yes') ? 'yes' : 'no';
            if (isset($input['send_statuses'])) {
                $send_statuses = array_values(array_unique(array_filter(array_map(function ($x) {
                    // اول کوچک‌سازی، بعد حذف پیشوند wc- (ورودی می‌تواند WC-ON-HOLD باشد)
                    return sanitize_key(str_replace('wc-', '', strtolower(trim($x))));
                }, explode(',', (string) $input['send_statuses'])))));
                // خالی/نامعتبر → پیش‌فرض امن: فقط «در حال انجام»
                $out['send_statuses'] = $send_statuses ? implode(',', $send_statuses) : 'processing';
            }
            // توکن: اگر خالی ارسال شد، توکن قبلی حفظ می‌شود؛ تیک «حذف توکن» پاکش می‌کند
            $in_token = isset($input['bot_token']) ? sanitize_text_field(trim($input['bot_token'])) : '';
            if ($in_token !== '') {
                $out['bot_token'] = $in_token;
            } elseif (!empty($input['remove_token']) && $input['remove_token'] === 'yes') {
                $out['bot_token'] = '';
            }
            // شناسه چت: فقط عدد، منفی، ویرگول، فاصله و یوزرنیم مجاز است
            $raw_ids = isset($input['chat_ids']) ? sanitize_text_field($input['chat_ids']) : '';
            $out['chat_ids'] = preg_replace('/[^0-9,\-@_a-zA-Z\s]/', '', $raw_ids);
            $out['daily_enabled'] = (!empty($input['daily_enabled']) && $input['daily_enabled'] === 'yes') ? 'yes' : 'no';
            $time = isset($input['daily_time']) ? sanitize_text_field(trim($input['daily_time'])) : '23:59';
            if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) {
                $time = '23:59';
            }
            $out['daily_time'] = $time;
            $out['status_enabled'] = (!empty($input['status_enabled']) && $input['status_enabled'] === 'yes') ? 'yes' : 'no';
            if (isset($input['status_template'])) {
                $out['status_template'] = wp_kses(wp_unslash($input['status_template']), $allowed);
            }
            if (isset($input['status_ignore'])) {
                $ign = array_filter(array_map(function ($x) {
                    return sanitize_key(str_replace('wc-', '', trim($x)));
                }, explode(',', (string) $input['status_ignore'])));
                $out['status_ignore'] = implode(',', array_unique($ign));
            }
            if (isset($input['status_gap'])) {
                $out['status_gap'] = max(0, min(60, (int) $input['status_gap']));
            }
            $out['daily_paid_only'] = (!empty($input['daily_paid_only']) && $input['daily_paid_only'] === 'yes') ? 'yes' : 'no';
            $out['daily_pin'] = (!empty($input['daily_pin']) && $input['daily_pin'] === 'yes') ? 'yes' : 'no';
            $out['timezone'] = (isset($input['timezone']) && $input['timezone'] === 'site') ? 'site' : 'Asia/Tehran';
            // واحد پول نمایشی در مبالغ پیام‌ها
            if (isset($input['currency_label'])) {
                $out['currency_label'] = mb_substr(sanitize_text_field(trim($input['currency_label'])), 0, 24);
            }
            // لینک شدن عنوان آیتم‌ها به صفحهٔ محصول
            if (isset($input['items_link'])) {
                $out['items_link'] = ($input['items_link'] === 'yes') ? 'yes' : 'no';
            }
            // قالب پیام: فقط تگ‌های مجاز تلگرام (b, i, u, a, code, pre)
            if (isset($input['template'])) {
                $out['template'] = wp_kses(wp_unslash($input['template']), $allowed);
            }
        }

        /* ---- تب «اعلانات محصولات» ---- */
        if ($tab === '' || $tab === 'products') {
            $out['stock_enabled'] = (!empty($input['stock_enabled']) && $input['stock_enabled'] === 'yes') ? 'yes' : 'no';
            $out['stock_out_enabled'] = (!empty($input['stock_out_enabled']) && $input['stock_out_enabled'] === 'yes') ? 'yes' : 'no';
            // فیلد خالی = مقدار قبلی حفظ شود (قبلاً صفر می‌شد و به ۱ تبدیل می‌گشت)
            $threshold_raw = isset($input['stock_threshold']) ? trim((string) $input['stock_threshold']) : '';
            $threshold = ($threshold_raw !== '') ? absint($threshold_raw) : (int) $out['stock_threshold'];
            if ($threshold <= 0) {
                $threshold = 5; // مقدار نامعتبر → پیش‌فرض
            }
            $out['stock_threshold'] = max(1, min(1000, $threshold));
            $raw_stock_ids = isset($input['stock_chat_ids']) ? sanitize_text_field($input['stock_chat_ids']) : '';
            $out['stock_chat_ids'] = preg_replace('/[^0-9,\-@_a-zA-Z\s]/', '', $raw_stock_ids);
            if (isset($input['stock_template'])) {
                $out['stock_template'] = wp_kses(wp_unslash($input['stock_template']), $allowed);
            }
        }

        /* ---- بخش لاگ (پایین صفحه — مستقل از تب فعال) ---- */
        if (isset($input['log_enabled'])) {
            $out['log_enabled'] = ($input['log_enabled'] === 'yes') ? 'yes' : 'no';
        }
        if (isset($input['log_level'])) {
            $level = sanitize_key($input['log_level']);
            $out['log_level'] = array_key_exists($level, $this->log_levels()) ? $level : 'info';
        }
        if (isset($input['log_retention_days'])) {
            $days = (int) $input['log_retention_days'];
            $out['log_retention_days'] = max(0, min(365, $days)); // ۰ = بدون محدودیت زمانی
        }
        if (isset($input['log_max_rows'])) {
            $rows = (int) $input['log_max_rows'];
            $out['log_max_rows'] = max(100, min(50000, $rows));
        }
        return $out;
    }

    // ریدایرکت به تب فعلی بعد از اقدام‌های admin-post
    private function redirect_to_tab($tab = '') {
        if ($tab === '' && isset($_REQUEST['tab'])) {
            $tab = sanitize_key(wp_unslash($_REQUEST['tab']));
        }
        wp_safe_redirect($this->tab_url(in_array($tab, ['orders', 'products', 'stats'], true) ? $tab : 'orders'));
        exit;
    }

    // تب فعال صفحه تنظیمات (orders | products | stats) — از URL، با اعتبارسنجی
    private function current_tab() {
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'orders';
        return in_array($tab, ['orders', 'products', 'stats'], true) ? $tab : 'orders';
    }

    private function tab_url($tab) {
        return admin_url('admin.php?page=wc-telegram-orders&tab=' . rawurlencode($tab));
    }

    // نمایش ماسک‌شده توکن در صفحه تنظیمات — کاربر با دسترسی کمتر نتواند توکن کامل را ببیند
    private function mask_token($token) {
        $token = trim((string) $token);
        $len = strlen($token);
        if ($len <= 12) {
            return str_repeat('•', $len);
        }
        return substr($token, 0, 5) . str_repeat('•', 6) . substr($token, -4);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $s = $this->get_settings();
        $tab = $this->current_tab();
        $test_result = get_transient('wc_telegram_test_result');
        delete_transient('wc_telegram_test_result');
        $opt = WC_TELEGRAM_ORDERS_OPTION;
        ?>
        <div class="wrap tisa-wrap wcto-wrap" dir="rtl">
            <header class="wcto-hero">
                <div class="wcto-hero-row">
                    <div class="wcto-hero-mark" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 4 3 11l6 2.5L11.5 20l3-4.5L19 17z"/><path d="M9 13.5 19 6"/></svg>
                    </div>
                    <div class="wcto-hero-text">
                        <h1 class="wcto-hero-title">ارسال سفارش‌ها به تلگرام</h1>
                        <p class="wcto-hero-sub">سفارش جدید، تغییر وضعیت، گزارش شبانه و هشدار موجودی — مستقیم در چت تلگرام</p>
                    </div>
                    <span class="wcto-hero-state <?php echo ($s['enabled'] === 'yes' && !empty($s['bot_token'])) ? 'is-on' : ''; ?>"><i></i><?php echo empty($s['bot_token']) ? 'بدون توکن' : ($s['enabled'] === 'yes' ? 'فعال' : 'غیرفعال'); ?></span>
                    <span class="wcto-hero-ver" dir="ltr">v<?php echo esc_html(WC_TELEGRAM_ORDERS_VERSION); ?></span>
                </div>
                <nav class="wcto-tabs">
                    <a href="<?php echo esc_url($this->tab_url('orders')); ?>" class="wcto-tab <?php echo $tab === 'orders' ? 'is-active' : ''; ?>">سفارش‌ها و گزارش</a>
                    <a href="<?php echo esc_url($this->tab_url('products')); ?>" class="wcto-tab <?php echo $tab === 'products' ? 'is-active' : ''; ?>">اعلان موجودی</a>
                    <a href="<?php echo esc_url($this->tab_url('stats')); ?>" class="wcto-tab <?php echo $tab === 'stats' ? 'is-active' : ''; ?>">آمار فروش</a>
                    <a href="#wcto-log" class="wcto-tab wcto-tab--ghost">لاگ رویدادها</a>
                </nav>
            </header>

            <?php settings_errors(); ?>

            <?php if ($test_result): ?>
                <div class="tisa-notice tisa-notice--<?php echo $test_result['ok'] ? 'success' : 'danger'; ?> wcto-notice"><?php echo esc_html($test_result['message']); ?></div>
            <?php endif; ?>

            <?php if ($tab === 'products'): ?>
                <?php $this->render_products_tab($s, $opt); ?>
            <?php elseif ($tab === 'stats'): ?>
                <?php $this->render_stats_tab($s, $opt); ?>
            <?php else: ?>
                <?php $this->render_orders_tab($s, $opt); ?>
            <?php endif; ?>

            <?php $this->render_log_section($s, $opt); ?>
        </div>
        <?php
    }


    // چیپ‌های متغیر: کلیک → درج در جای مکان‌نما (assets/admin.js)
    private function var_chips(array $vars, $target_id) {
        $out = '<div class="wcto-vars" data-target="' . esc_attr($target_id) . '">';
        foreach ($vars as $v => $label) {
            $out .= '<button type="button" class="wcto-var" data-var="' . esc_attr($v) . '" title="' . esc_attr($label) . '"><span dir="ltr">' . esc_html($v) . '</span></button>';
        }
        return $out . '</div>';
    }

    private function order_vars() {
        return [
            '{order_number}' => 'شماره سفارش', '{order_date}' => 'تاریخ', '{order_status}' => 'وضعیت', '{order_total}' => 'مجموع سفارش',
            '{paid_amount}' => 'پرداختی نقدی', '{wallet_amount}' => 'سهم کیف پول', '{subtotal}' => 'جمع محصولات', '{discount_total}' => 'مبلغ تخفیف', '{coupons}' => 'کدهای تخفیف', '{shipping_total}' => 'هزینه ارسال',
            '{shipping_method}' => 'روش ارسال', '{payment_method}' => 'روش پرداخت', '{items}' => 'لیست آیتم‌ها', '{items_count}' => 'تعداد آیتم',
            '{customer_name}' => 'نام مشتری', '{customer_phone}' => 'تلفن', '{customer_email}' => 'ایمیل', '{customer_address}' => 'آدرس',
            '{customer_postcode}' => 'کد پستی', '{customer_note}' => 'یادداشت مشتری', '{order_url}' => 'لینک سفارش', '{site_name}' => 'نام سایت',
            '{if_wallet}…{/if_wallet}' => 'فقط وقتی کیف پول استفاده شده',
            '{if_discount}…{/if_discount}' => 'فقط وقتی تخفیف/کوپن اعمال شده',
        ];
    }

    private function status_vars() {
        return ['{order_number}' => 'شماره سفارش', '{old_status}' => 'وضعیت قبلی', '{new_status}' => 'وضعیت جدید', '{customer_name}' => 'نام مشتری', '{order_total}' => 'مجموع سفارش', '{order_url}' => 'لینک سفارش', '{site_name}' => 'نام سایت'];
    }

    private function stock_vars() {
        return ['{stock_emoji}' => 'ایموجی وضعیت', '{stock_label}' => 'عنوان هشدار', '{product_name}' => 'نام محصول', '{variation}' => 'متغیر (مدل/رنگ)', '{stock}' => 'موجودی فعلی', '{threshold}' => 'آستانه', '{sku}' => 'SKU', '{price}' => 'قیمت', '{order_info}' => 'سفارش عامل', '{product_link}' => 'لینک محصول', '{site_name}' => 'نام سایت'];
    }

    /* ---------- تب ۱: ارسال سفارشات جدید ---------- */
    private function render_orders_tab($s, $opt) {
        $has_token = !empty($s['bot_token']);
        // اسلاگ وضعیت‌های ثبت‌شده در همین سایت — برای راهنمای «وضعیت‌های مجاز ارسال»
        $status_slugs = function_exists('wc_get_order_statuses')
            ? array_map(function ($x) { return str_replace('wc-', '', $x); }, array_keys(wc_get_order_statuses()))
            : ['processing', 'completed', 'on-hold', 'pending', 'cancelled', 'refunded', 'failed'];
        ?>
        <?php $this->maybe_render_debug(); ?>

        <form method="post" action="options.php">
            <?php settings_fields('wc_telegram_orders_group'); ?>
            <input type="hidden" name="<?php echo esc_attr($opt); ?>[_tab]" value="orders" />

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>اتصال</h2><p>ربات را از <bdi>@BotFather</bdi> بسازید؛ ربات باید در گروه/کانال ادمین باشد.</p></div></div>
                <div class="wcto-card-body">
                    <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="yes" <?php checked($s['enabled'], 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>ارسال سفارش‌های جدید به تلگرام</span></label>

                    <div class="wcto-row">
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-token">توکن ربات</label>
                            <input type="password" id="wc-tg-token" name="<?php echo esc_attr($opt); ?>[bot_token]" value="" class="tisa-input tisa-input--code" autocomplete="off" dir="ltr"
                                placeholder="<?php echo $has_token ? esc_attr($this->mask_token($s['bot_token'])) : '123456789:AAH...'; ?>" />
                            <?php if ($has_token): ?>
                                <p class="wcto-hint">توکن ذخیره شده است؛ برای تغییر، توکن جدید را وارد کنید.</p>
                                <label class="wcto-check"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[remove_token]" value="yes" /> حذف توکن ذخیره‌شده</label>
                            <?php endif; ?>
                        </div>
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-chats">شناسه چت (Chat ID)</label>
                            <input type="text" id="wc-tg-chats" name="<?php echo esc_attr($opt); ?>[chat_ids]" value="<?php echo esc_attr($s['chat_ids']); ?>" class="tisa-input tisa-input--code" dir="ltr" placeholder="-1001234567890" />
                            <p class="wcto-hint">چند مقصد را با ویرگول جدا کنید.</p>
                        </div>
                    </div>
                    <div class="wcto-row">
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-currency">واحد پول در پیام‌ها</label>
                            <input type="text" id="wc-tg-currency" name="<?php echo esc_attr($opt); ?>[currency_label]" value="<?php echo esc_attr($s['currency_label']); ?>" class="tisa-input" />
                        </div>
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-wallet-key">کلید متای کیف پول <span class="wcto-opt">اختیاری</span></label>
                            <input type="text" id="wc-tg-wallet-key" name="<?php echo esc_attr($opt); ?>[wallet_meta_key]" value="<?php echo esc_attr(isset($s['wallet_meta_key']) ? $s['wallet_meta_key'] : ''); ?>" class="tisa-input tisa-input--code" dir="ltr" placeholder="خالی = تشخیص خودکار" />
                            <p class="wcto-hint">فقط اگر سهم کیف پول اشتباه تشخیص داده شد؛ کلید را از «عیب‌یابی سفارش» بردارید.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>شرط ارسال سفارش</h2><p>سفارش در انتظار پرداخت یا لغوشده به تلگرام نمی‌رود؛ با پرداخت شدن (تغییر وضعیت) معرفی می‌شود.</p></div></div>
                <div class="wcto-card-body">
                    <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[paid_gate]" value="yes" <?php checked(isset($s['paid_gate']) ? $s['paid_gate'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>فقط سفارش‌های پرداخت‌شده ارسال شوند</span></label>
                    <div class="wcto-field">
                        <label class="wcto-label" for="wc-tg-send-statuses">وضعیت‌های مجاز ارسال</label>
                        <input type="text" id="wc-tg-send-statuses" name="<?php echo esc_attr($opt); ?>[send_statuses]" value="<?php echo esc_attr(isset($s['send_statuses']) ? $s['send_statuses'] : 'processing'); ?>" class="tisa-input tisa-input--code" dir="ltr" placeholder="processing" />
                        <p class="wcto-hint">اسلاگ وضعیت‌ها با ویرگول؛ پیش‌فرض <code dir="ltr">processing</code> (در حال انجام = پرداخت‌شده). تا رسیدن به این وضعیت، پیام سفارش در انتظار می‌ماند و پیام «لغو» یا «در انتظار پرداخت» نمی‌رود.</p>
                        <p class="wcto-hint">وضعیت‌های این سایت: <code dir="ltr"><?php echo esc_html(implode(', ', $status_slugs)); ?></code></p>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>ارسال گروهی از لیست سفارش‌ها</h2><p>در لیست سفارش‌ها چند سفارش را انتخاب کنید و «ارسال به تلگرام» را بزنید.</p></div></div>
                <div class="wcto-card-body">
                    <div class="wcto-row">
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-bulk-gap">فاصلهٔ بین پیام‌ها</label>
                            <div class="wcto-unit"><input type="number" id="wc-tg-bulk-gap" name="<?php echo esc_attr($opt); ?>[bulk_gap]" value="<?php echo (int) (isset($s['bulk_gap']) ? $s['bulk_gap'] : 2); ?>" min="0" max="60" step="1" dir="ltr" class="tisa-input" /><span>ثانیه</span></div>
                            <p class="wcto-hint">ضد سیل: پیام‌ها پلکانی در صف می‌روند تا تلگرام ۴۲۹ ندهد.</p>
                        </div>
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-bulk-max">سقف تعداد در هر اجرا</label>
                            <input type="number" id="wc-tg-bulk-max" name="<?php echo esc_attr($opt); ?>[bulk_max]" value="<?php echo (int) (isset($s['bulk_max']) ? $s['bulk_max'] : 50); ?>" min="1" max="200" step="1" dir="ltr" class="tisa-input" />
                            <p class="wcto-hint">سفارش‌های بیشتر از این سقف در همان اجرا رد می‌شوند.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>تاریخ پیام‌ها</h2><p>تاریخ شمسی داخلی — نیازی به افزونهٔ جداگانه نیست.</p></div></div>
                <div class="wcto-card-body">
                    <div class="wcto-switches">
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[jalali_date]" value="yes" <?php checked(isset($s['jalali_date']) ? $s['jalali_date'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>تاریخ شمسی باشد</span></label>
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[jalali_digits]" value="yes" <?php checked(isset($s['jalali_digits']) ? $s['jalali_digits'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>رقم‌ها به فارسی (۱۴۰۵/۰۶/۲۶)</span></label>
                    </div>
                    <p class="wcto-hint">نمونه: <bdi><?php echo esc_html($this->format_date(get_option('date_format') . ' ' . get_option('time_format'), time())); ?></bdi> — چون مستقیماً از میلادی محاسبه می‌شود، با افزونه‌های شمسی دیگر تداخل نمی‌کند (تبدیل دوبار رخ نمی‌دهد).</p>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>هشدار سلامت ربات</h2><p>اگر ارسال‌ها پشت‌سرهم شکست بخورند، یک پیام هشدار به چت می‌رود.</p></div></div>
                <div class="wcto-card-body">
                    <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[health_enabled]" value="yes" <?php checked(isset($s['health_enabled']) ? $s['health_enabled'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>هشدار سلامت فعال باشد</span></label>
                    <div class="wcto-row">
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-health-th">آستانهٔ شکست</label>
                            <div class="wcto-unit"><input type="number" id="wc-tg-health-th" name="<?php echo esc_attr($opt); ?>[health_threshold]" value="<?php echo (int) (isset($s['health_threshold']) ? $s['health_threshold'] : 5); ?>" min="2" max="50" step="1" dir="ltr" class="tisa-input" /><span>ارسال ناموفق پشت‌سرهم</span></div>
                        </div>
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-health-cd">بازهٔ توقف هشدار</label>
                            <div class="wcto-unit"><input type="number" id="wc-tg-health-cd" name="<?php echo esc_attr($opt); ?>[health_cooldown]" value="<?php echo (int) (isset($s['health_cooldown']) ? $s['health_cooldown'] : 6); ?>" min="1" max="168" step="1" dir="ltr" class="tisa-input" /><span>ساعت</span></div>
                        </div>
                    </div>
                    <p class="wcto-hint">وضعیت فعلی: <?php echo esc_html($this->to_persian_digits((string) $this->health_streak())); ?> شکست پشت‌سرهم. اگر خودِ تلگرام قطع باشد، هشدار هم تحویل نمی‌شود — در آن حالت در تب «آمار فروش» و لاگ رویدادها دیده می‌شود.</p>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>پیام سفارش جدید</h2><p>روی هر متغیر کلیک کنید تا در جای مکان‌نما درج شود.</p></div></div>
                <div class="wcto-card-body">
                    <?php echo $this->var_chips($this->order_vars(), 'wc-tg-template'); // phpcs:ignore ?>
                    <textarea id="wc-tg-template" name="<?php echo esc_attr($opt); ?>[template]" rows="18" class="tisa-input tisa-input--code wcto-tpl" dir="auto"><?php echo esc_textarea($s['template']); ?></textarea>
                    <div class="wcto-between">
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[items_link]" value="yes" <?php checked(isset($s['items_link']) ? $s['items_link'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>عنوان هر آیتم به صفحهٔ محصول لینک شود</span></label>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wc_telegram_reset'), 'wc_telegram_reset_nonce')); ?>" class="tisa-btn tisa-btn--ghost tisa-btn--sm">بازنشانی به قالب پیش‌فرض</a>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>تغییر وضعیت سفارش</h2><p>پیام کوتاه با هر تغییر وضعیت (لغو، تکمیل، استرداد…).</p></div></div>
                <div class="wcto-card-body">
                    <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[status_enabled]" value="yes" <?php checked($s['status_enabled'], 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>ارسال پیام تغییر وضعیت</span></label>
                    <?php echo $this->var_chips($this->status_vars(), 'wc-tg-status-tpl'); // phpcs:ignore ?>
                    <textarea id="wc-tg-status-tpl" name="<?php echo esc_attr($opt); ?>[status_template]" rows="5" class="tisa-input tisa-input--code wcto-tpl" dir="auto"><?php echo esc_textarea($s['status_template']); ?></textarea>
                    <div class="wcto-row">
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-status-ignore">وضعیت‌های بدون پیام</label>
                            <input type="text" id="wc-tg-status-ignore" name="<?php echo esc_attr($opt); ?>[status_ignore]" value="<?php echo esc_attr(isset($s['status_ignore']) ? $s['status_ignore'] : ''); ?>" class="tisa-input tisa-input--code" dir="ltr" placeholder="pws-in-stock, completed" />
                            <p class="wcto-hint">اسلاگ وضعیت‌ها با کاما.</p>
                        </div>
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-status-gap">فاصلهٔ بین پیام‌ها</label>
                            <div class="wcto-unit"><input type="number" id="wc-tg-status-gap" name="<?php echo esc_attr($opt); ?>[status_gap]" value="<?php echo (int) (isset($s['status_gap']) ? $s['status_gap'] : 3); ?>" min="0" max="60" step="1" dir="ltr" class="tisa-input" /><span>ثانیه</span></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>گزارش روزانه فروش</h2><p>هر شب رأس ساعت تعیین‌شده؛ بازه از پایان گزارش قبلی حساب می‌شود.</p></div></div>
                <div class="wcto-card-body">
                    <div class="wcto-switches">
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[daily_enabled]" value="yes" <?php checked($s['daily_enabled'], 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>ارسال گزارش شبانه</span></label>
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[daily_paid_only]" value="yes" <?php checked(isset($s['daily_paid_only']) ? $s['daily_paid_only'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>در جزئیات فقط سفارش‌های پرداخت‌شده</span></label>
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[daily_pin]" value="yes" <?php checked(isset($s['daily_pin']) ? $s['daily_pin'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>گزارش در چت سنجاق شود</span></label>
                    </div>
                    <div class="wcto-row">
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-time">ساعت ارسال</label>
                            <input type="time" id="wc-tg-time" name="<?php echo esc_attr($opt); ?>[daily_time]" value="<?php echo esc_attr(isset($s['daily_time']) ? $s['daily_time'] : '23:59'); ?>" dir="ltr" class="tisa-input" />
                        </div>
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-tz">منطقه زمانی</label>
                            <select id="wc-tg-tz" name="<?php echo esc_attr($opt); ?>[timezone]" dir="ltr" class="tisa-input">
                                <option value="Asia/Tehran" <?php selected(isset($s['timezone']) ? $s['timezone'] : 'Asia/Tehran', 'Asia/Tehran'); ?>>Asia/Tehran</option>
                                <option value="site" <?php selected(isset($s['timezone']) ? $s['timezone'] : 'Asia/Tehran', 'site'); ?>>منطقه زمانی وردپرس</option>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <div class="wcto-savebar">
                <button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ تنظیمات</button>
            </div>
        </form>

        <div class="wcto-grid-3">
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>پیام تست</h2></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcto-card-body">
                    <input type="hidden" name="action" value="wc_telegram_test" />
                    <?php wp_nonce_field('wc_telegram_test_nonce'); ?>
                    <button type="submit" class="tisa-btn tisa-btn--secondary">ارسال تست به تلگرام</button>
                </form>
            </section>
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>گزارش دستی</h2><p>مستقل از گزارش شبانه.</p></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcto-card-body wcto-inline">
                    <input type="hidden" name="action" value="wc_telegram_daily_now" />
                    <select name="range" class="tisa-input">
                        <option value="today">امروز</option>
                        <option value="24h">۲۴ ساعت گذشته</option>
                        <option value="7d">۷ روز گذشته</option>
                    </select>
                    <?php wp_nonce_field('wc_telegram_daily_nonce'); ?>
                    <button type="submit" class="tisa-btn tisa-btn--secondary">ارسال</button>
                </form>
            </section>
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>عیب‌یابی سفارش</h2><p>آدرس و کیف پول یک سفارش.</p></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcto-card-body wcto-inline">
                    <input type="hidden" name="action" value="wc_telegram_debug" />
                    <?php wp_nonce_field('wc_telegram_debug_nonce'); ?>
                    <input type="number" name="order_id" value="" min="1" required dir="ltr" class="tisa-input" placeholder="شناسه سفارش" aria-label="شناسه سفارش" />
                    <button type="submit" class="tisa-btn tisa-btn--secondary">بررسی</button>
                </form>
            </section>
        </div>
        <?php
    }

    /* ---------- تب ۳: آمار فروش ---------- */

    const STATS_CACHE_PREFIX = 'wc_telegram_stats_';
    const STATS_ORDER_CAP    = 500;  // سقف سفارش‌هایی که در هر محاسبه بررسی می‌شوند (حافظه/زمان)

    /**
     * جمع‌آوری آمار بازهٔ انتخابی: پرفروش‌ترین محصولات، بزرگ‌ترین مشتریان،
     * شلوغ‌ترین ساعت‌ها، روش‌های پرداخت و میانگین فاصلهٔ سفارش‌های پرداخت‌شده.
     * نتیجه ۳۰ دقیقه کش می‌شود تا صفحهٔ تنظیمات سنگین نشود.
     */
    public function collect_stats($days = 30, $refresh = false) {
        $days      = in_array((int) $days, [7, 30, 90], true) ? (int) $days : 30;
        $cache_key = self::STATS_CACHE_PREFIX . $days;
        if (!$refresh) {
            $cached = get_transient($cache_key);
            if (is_array($cached) && isset($cached['generated'])) {
                return $cached;
            }
        }
        $empty = [
            'days' => $days, 'count' => 0, 'revenue' => 0.0, 'items' => 0, 'products' => [],
            'customers' => [], 'hours' => [], 'payments' => [], 'avg_gap' => 0, 'oldest' => 0,
            'newest' => 0, 'truncated' => false, 'generated' => time(), 'available' => false,
        ];
        if (!function_exists('wc_get_orders')) {
            return $empty;
        }
        $this->maybe_raise_memory();
        $paid_statuses = apply_filters('wc_telegram_paid_statuses', ['processing', 'completed']);
        $since         = time() - ($days * DAY_IN_SECONDS);
        $orders        = wc_get_orders([
            'status'       => $paid_statuses,
            'date_created' => '>=' . $since,
            'limit'        => self::STATS_ORDER_CAP,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'return'       => 'objects',
        ]);

        $data = $empty;
        $data['available'] = true;
        $paid_ts = [];
        foreach ((array) $orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            // محافظ: اگر کوئری وضعیت را فیلتر نکرده باشد، خودمان فقط پرداخت‌شده‌ها را حساب می‌کنیم
            if (!in_array(str_replace('wc-', '', (string) $order->get_status()), $paid_statuses, true)) {
                continue;
            }
            $amounts = $this->order_amounts($order);
            $grand   = (float) $amounts['grand'];
            $data['revenue'] += $grand;
            $data['count']++;

            $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
            if ($created > 0) {
                if (!$data['oldest'] || $created < $data['oldest']) {
                    $data['oldest'] = $created;
                }
                if ($created > $data['newest']) {
                    $data['newest'] = $created;
                }
                // ساعت با منطقهٔ زمانی پلاگین (بدون تبدیل شمسی/رقم فارسی — برای محاسبه است)
                $hour = (int) $this->plugin_date('G', $created);
                $data['hours'][$hour] = isset($data['hours'][$hour]) ? $data['hours'][$hour] + 1 : 1;
            }

            foreach ($order->get_items() as $item) {
                if (!is_object($item) || !method_exists($item, 'get_quantity')) {
                    continue;
                }
                $name = trim((string) $item->get_name());
                if ($name === '') {
                    continue;
                }
                $qty  = max(0, (int) $item->get_quantity());
                $line = (float) $item->get_total();
                $data['items'] += $qty;
                if (!isset($data['products'][$name])) {
                    $data['products'][$name] = ['qty' => 0, 'sum' => 0.0, 'orders' => 0];
                }
                $data['products'][$name]['qty']    += $qty;
                $data['products'][$name]['sum']    += $line;
                $data['products'][$name]['orders'] += 1;
            }

            $phone = $this->fix_phone($order->get_billing_phone());
            $key   = ($phone !== '—' && $phone !== '') ? $phone : trim((string) $order->get_billing_email());
            if ($key !== '') {
                $name = trim((string) $order->get_formatted_billing_full_name());
                if (!isset($data['customers'][$key])) {
                    $data['customers'][$key] = ['name' => '', 'count' => 0, 'sum' => 0.0];
                }
                $data['customers'][$key]['count'] += 1;
                $data['customers'][$key]['sum']   += $grand;
                if ($data['customers'][$key]['name'] === '' && $name !== '') {
                    $data['customers'][$key]['name'] = $name;
                }
            }

            $pm = trim((string) $order->get_payment_method_title());
            $pm = ($pm !== '') ? $pm : 'نامشخص';
            if (!isset($data['payments'][$pm])) {
                $data['payments'][$pm] = ['count' => 0, 'sum' => 0.0];
            }
            $data['payments'][$pm]['count'] += 1;
            $data['payments'][$pm]['sum']   += $grand;

            $paid_at = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
            if ($paid_at) {
                $paid_ts[] = $paid_at->getTimestamp();
            }
            $this->clean_order_cache($order->get_id());
        }

        // میانگین فاصلهٔ بین دو سفارش پرداخت‌شده (روی زمان پرداخت، نه ثبت)
        sort($paid_ts);
        if (count($paid_ts) > 1) {
            $data['avg_gap'] = (int) round(($paid_ts[count($paid_ts) - 1] - $paid_ts[0]) / (count($paid_ts) - 1));
        }

        $data['truncated'] = ($data['count'] >= self::STATS_ORDER_CAP);
        set_transient($cache_key, $data, 30 * MINUTE_IN_SECONDS);
        return $data;
    }

    // فهرست را بر اساس یک کلید، نزولی مرتب و به n ردیف اول محدود می‌کند
    private function stats_top(array $rows, $sort_key, $n = 5) {
        $val = function ($row) use ($sort_key) {
            if (is_array($row)) {
                return isset($row[$sort_key]) ? $row[$sort_key] : 0;
            }
            return is_numeric($row) ? $row : 0; // فهرست‌های ساده مثل «ساعت => تعداد»
        };
        uasort($rows, function ($a, $b) use ($val) {
            return $val($b) <=> $val($a);
        });
        return array_slice($rows, 0, max(1, (int) $n), true);
    }

    private function render_stats_tab($s, $opt) {
        $days    = isset($_GET['stats_days']) ? (int) $_GET['stats_days'] : 30;
        $days    = in_array($days, [7, 30, 90], true) ? $days : 30;
        $refresh = !empty($_GET['stats_refresh']);
        $d       = $this->collect_stats($days, $refresh);
        $avg_bag = ($d['count'] > 0) ? ($d['revenue'] / $d['count']) : 0;
        $rev     = max(0.0, (float) $d['revenue']);
        $pct     = function ($v) use ($rev) {
            return $rev > 0 ? (int) round((((float) $v) / $rev) * 100) : 0;
        };

        // آمار امروزِ ارسال‌ها از جدول لاگ
        try {
            $now = new \DateTime('now', $this->plugin_timezone());
            $now->setTime(0, 0, 0);
            $day_start = $now->getTimestamp();
        } catch (\Exception $e) {
            $day_start = time() - DAY_IN_SECONDS;
        }
        $sent_today  = $this->count_logs(['search' => 'order_sent', 'since' => $day_start]);
        $error_today = $this->count_logs(['level' => 'error', 'since' => $day_start]);
        $streak      = $this->health_streak();
        $threshold   = max(2, (int) (isset($s['health_threshold']) ? $s['health_threshold'] : 5));

        $period_url = function ($n) {
            return add_query_arg('stats_days', (string) $n, $this->tab_url('stats'));
        };
        ?>
        <div class="wcto-filters">
            <?php foreach ([7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز'] as $n => $label): ?>
                <a class="tisa-btn <?php echo $n === $days ? 'tisa-btn--primary' : 'tisa-btn--ghost'; ?>" href="<?php echo esc_url($period_url($n)); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
            <a class="tisa-btn tisa-btn--ghost" href="<?php echo esc_url(add_query_arg('stats_refresh', '1', $period_url($days))); ?>">بازمحاسبهٔ آمار</a>
            <span class="wcto-hint">محاسبهٔ آخر: <?php echo esc_html($this->format_date(get_option('date_format') . ' H:i', $d['generated'])); ?> — تا ۳۰ دقیقه کش می‌شود</span>
        </div>

        <?php if (empty($d['available']) || $d['count'] === 0): ?>
            <div class="tisa-notice tisa-notice--info wcto-notice">در این بازه سفارش پرداخت‌شده‌ای پیدا نشد<?php echo $d['available'] ? '' : ' (ووکامرس در دسترس نیست)'; ?>.</div>
        <?php endif; ?>

        <div class="wcto-kpis">
            <span class="wcto-kpi"><span class="t">سفارش‌های پرداخت‌شده</span><span class="v"><?php echo esc_html($this->to_persian_digits((string) $d['count'])); ?></span></span>
            <span class="wcto-kpi"><span class="t">جمع فروش</span><span class="v"><?php echo esc_html($this->money($d['revenue'])); ?></span></span>
            <span class="wcto-kpi"><span class="t">میانگین سبد</span><span class="v"><?php echo esc_html($this->money($avg_bag)); ?></span></span>
            <span class="wcto-kpi"><span class="t">میانگین فاصلهٔ بین دو سفارش پرداخت‌شده</span><span class="v"><?php echo esc_html($this->to_persian_digits($this->human_duration($d['avg_gap']))); ?></span></span>
            <span class="wcto-kpi"><span class="t">آیتم‌های فروخته‌شده</span><span class="v"><?php echo esc_html($this->to_persian_digits((string) $d['items'])); ?></span></span>
            <span class="wcto-kpi <?php echo $sent_today > 0 ? '' : 'wcto-kpi--warn'; ?>"><span class="t">ارسال موفق امروز (تلگرام)</span><span class="v"><?php echo esc_html($this->to_persian_digits((string) $sent_today)); ?></span></span>
            <span class="wcto-kpi <?php echo $error_today > 0 ? 'wcto-kpi--bad' : ''; ?>"><span class="t">خطاهای امروز</span><span class="v"><?php echo esc_html($this->to_persian_digits((string) $error_today)); ?></span></span>
            <span class="wcto-kpi <?php echo $streak >= $threshold ? 'wcto-kpi--bad' : ($streak > 0 ? 'wcto-kpi--warn' : ''); ?>"><span class="t">شکست‌های پشت‌سرهم ربات</span><span class="v"><?php echo esc_html($this->to_persian_digits((string) $streak)); ?></span></span>
        </div>

        <?php if (!empty($d['truncated'])): ?>
            <div class="tisa-notice tisa-notice--info wcto-notice">آمار بر اساس <?php echo esc_html($this->to_persian_digits((string) self::STATS_ORDER_CAP)); ?> سفارش اخیرِ بازه محاسبه شده است (سقف حافظه).</div>
        <?php endif; ?>

        <div class="wcto-grid-2">
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>پرفروش‌ترین محصولات</h2><p>بر اساس مبلغ فروش در بازهٔ انتخابی.</p></div></div>
                <div class="wcto-card-body">
                    <?php $products = $this->stats_top($d['products'], 'sum', 8); ?>
                    <?php if (empty($products)): ?>
                        <p class="wcto-empty">داده‌ای نیست.</p>
                    <?php else: ?>
                        <table class="wcto-stats-table">
                            <thead><tr><th></th><th>محصول</th><th>تعداد</th><th>سفارش</th><th>سهم</th><th>مبلغ</th></tr></thead>
                            <tbody>
                            <?php $r = 0; foreach ($products as $name => $row): $r++; ?>
                                <tr>
                                    <td><span class="rank <?php echo $r === 1 ? 'rank--1' : ''; ?>"><?php echo esc_html($this->to_persian_digits((string) $r)); ?></span></td>
                                    <td><?php echo esc_html($name); ?></td>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits((string) $row['qty'])); ?></td>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits((string) $row['orders'])); ?></td>
                                    <td class="num muted"><?php echo esc_html($this->to_persian_digits((string) $pct($row['sum']))); ?>٪</td>
                                    <td class="num"><?php echo esc_html($this->money($row['sum'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>بزرگ‌ترین مشتریان</h2><p>بیشترین مجموع خرید (با تلفن یا ایمیل شناسایی می‌شوند).</p></div></div>
                <div class="wcto-card-body">
                    <?php $customers = $this->stats_top($d['customers'], 'sum', 8); ?>
                    <?php if (empty($customers)): ?>
                        <p class="wcto-empty">مشتری با تلفن/ایمیل ثبت‌شده پیدا نشد.</p>
                    <?php else: ?>
                        <table class="wcto-stats-table">
                            <thead><tr><th></th><th>مشتری</th><th>تماس</th><th>سفارش</th><th>مجموع</th></tr></thead>
                            <tbody>
                            <?php $r = 0; foreach ($customers as $key => $row): $r++; ?>
                                <tr>
                                    <td><span class="rank <?php echo $r === 1 ? 'rank--1' : ''; ?>"><?php echo esc_html($this->to_persian_digits((string) $r)); ?></span></td>
                                    <td><?php echo esc_html($row['name'] !== '' ? $row['name'] : 'بدون نام'); ?></td>
                                    <td class="num muted"><?php echo esc_html($key); ?></td>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits((string) $row['count'])); ?></td>
                                    <td class="num"><?php echo esc_html($this->money($row['sum'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>شلوغ‌ترین ساعت‌های روز</h2><p>بر پایهٔ زمان ثبت سفارش (منطقهٔ زمانی پلاگین).</p></div></div>
                <div class="wcto-card-body">
                    <?php $hours = $this->stats_top($d['hours'], 0, 6); ?>
                    <?php if (empty($hours)): ?>
                        <p class="wcto-empty">داده‌ای نیست.</p>
                    <?php else: ?>
                        <?php $max_h = max(1, (int) max($hours)); ?>
                        <table class="wcto-stats-table">
                            <thead><tr><th>ساعت</th><th>سفارش</th><th>سهم از سفارش‌ها</th></tr></thead>
                            <tbody>
                            <?php foreach ($hours as $h => $c): ?>
                                <?php $w = (int) round((((int) $c) / $max_h) * 100); ?>
                                <tr>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits(str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00')); ?></td>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits((string) $c)); ?></td>
                                    <td><span class="wcto-bar"><i style="width: <?php echo esc_attr((string) $w); ?>%"></i></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>روش‌های پرداخت</h2><p>سهم هر درگاه از فروش بازه.</p></div></div>
                <div class="wcto-card-body">
                    <?php $payments = $this->stats_top($d['payments'], 'sum', 6); ?>
                    <?php if (empty($payments)): ?>
                        <p class="wcto-empty">داده‌ای نیست.</p>
                    <?php else: ?>
                        <table class="wcto-stats-table">
                            <thead><tr><th>روش</th><th>سفارش</th><th>سهم</th><th>مبلغ</th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $pm => $row): ?>
                                <tr>
                                    <td><?php echo esc_html($pm); ?></td>
                                    <td class="num"><?php echo esc_html($this->to_persian_digits((string) $row['count'])); ?></td>
                                    <td class="num muted"><?php echo esc_html($this->to_persian_digits((string) $pct($row['sum']))); ?>٪</td>
                                    <td class="num"><?php echo esc_html($this->money($row['sum'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <section class="wcto-card">
            <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>بازهٔ داده</h2></div></div>
            <div class="wcto-card-body">
                <p class="wcto-hint">
                    <?php if ($d['oldest'] && $d['newest']): ?>
                        از <?php echo esc_html($this->format_date(get_option('date_format') . ' H:i', $d['oldest'])); ?>
                        تا <?php echo esc_html($this->format_date(get_option('date_format') . ' H:i', $d['newest'])); ?>
                        — <?php echo esc_html($this->to_persian_digits((string) $d['count'])); ?> سفارش پرداخت‌شده
                        (وضعیت‌های <?php echo esc_html(implode(', ', (array) apply_filters('wc_telegram_paid_statuses', ['processing', 'completed']))); ?>).
                    <?php else: ?>
                        داده‌ای برای نمایش بازه نیست.
                    <?php endif; ?>
                </p>
            </div>
        </section>
        <?php
    }

    /* ---------- تب ۲: اعلانات محصولات ---------- */
    private function render_products_tab($s, $opt) {
        $threshold = isset($s['stock_threshold']) ? (int) $s['stock_threshold'] : 5;
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('wc_telegram_orders_group'); ?>
            <input type="hidden" name="<?php echo esc_attr($opt); ?>[_tab]" value="products" />

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>هشدار موجودی</h2><p>وقتی موجودی محصول (یا متغیرش) زیر آستانه برود، یک بار اعلان می‌رود؛ بعد از شارژ مجدد دوباره فعال می‌شود.</p></div></div>
                <div class="wcto-card-body">
                    <div class="wcto-switches">
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[stock_enabled]" value="yes" <?php checked(isset($s['stock_enabled']) ? $s['stock_enabled'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>اعلان کمبود موجودی</span></label>
                        <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[stock_out_enabled]" value="yes" <?php checked(isset($s['stock_out_enabled']) ? $s['stock_out_enabled'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>اعلان جداگانه برای اتمام موجودی</span></label>
                    </div>
                    <div class="wcto-row">
                        <div class="wcto-field wcto-field--sm">
                            <label class="wcto-label" for="wc-tg-stock-th">آستانه هشدار</label>
                            <div class="wcto-unit"><input type="number" id="wc-tg-stock-th" name="<?php echo esc_attr($opt); ?>[stock_threshold]" value="<?php echo (int) $threshold; ?>" min="1" max="1000" step="1" dir="ltr" class="tisa-input" /><span>عدد</span></div>
                            <p class="wcto-hint">کمتر از این مقدار → اعلان.</p>
                        </div>
                        <div class="wcto-field">
                            <label class="wcto-label" for="wc-tg-stock-chats">چت اعلان‌های موجودی <span class="wcto-opt">اختیاری</span></label>
                            <input type="text" id="wc-tg-stock-chats" name="<?php echo esc_attr($opt); ?>[stock_chat_ids]" value="<?php echo esc_attr(isset($s['stock_chat_ids']) ? $s['stock_chat_ids'] : ''); ?>" class="tisa-input tisa-input--code" dir="ltr" placeholder="<?php echo esc_attr($s['chat_ids'] ? $s['chat_ids'] : '-1001234567890'); ?>" />
                            <p class="wcto-hint">خالی = همان چت سفارش‌ها.</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot"></span><div><h2>قالب اعلان</h2><p>روی هر متغیر کلیک کنید تا در جای مکان‌نما درج شود.</p></div></div>
                <div class="wcto-card-body">
                    <?php echo $this->var_chips($this->stock_vars(), 'wc-tg-stock-tpl'); // phpcs:ignore ?>
                    <textarea id="wc-tg-stock-tpl" name="<?php echo esc_attr($opt); ?>[stock_template]" rows="8" class="tisa-input tisa-input--code wcto-tpl" dir="auto"><?php echo esc_textarea(!empty($s['stock_template']) ? $s['stock_template'] : $this->default_stock_template()); ?></textarea>
                </div>
            </section>

            <div class="wcto-savebar">
                <button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ تنظیمات</button>
            </div>
        </form>

        <div class="wcto-grid-2">
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>اعلان تست</h2><p>یک اعلان نمونه به چت موجودی.</p></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcto-card-body">
                    <input type="hidden" name="action" value="wc_telegram_stock_test" />
                    <?php wp_nonce_field('wc_telegram_stock_test_nonce'); ?>
                    <button type="submit" class="tisa-btn tisa-btn--secondary">ارسال اعلان تست</button>
                </form>
            </section>
            <section class="wcto-card">
                <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>اسکن انبار</h2><p>همهٔ محصولات زیر آستانه در یک پیام.</p></div></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wcto-card-body">
                    <input type="hidden" name="action" value="wc_telegram_stock_scan" />
                    <?php wp_nonce_field('wc_telegram_stock_scan_nonce'); ?>
                    <button type="submit" class="tisa-btn tisa-btn--secondary">اسکن و ارسال</button>
                </form>
            </section>
        </div>
        <?php
    }

    public function handle_test_message() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_test_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $site = get_bloginfo('name');
        $msg = sprintf('✅ اتصال تلگرام برقرار شد! پیام تست از %s. سفارش‌های جدید اینجا ارسال می‌شوند.', $site);
        $result = $this->send_to_all_chats($msg);
        $this->log(!empty($result['ok']) ? 'success' : 'error', 'settings', 'test_message',
            !empty($result['ok']) ? 'پیام تست اتصال ارسال شد.' : 'ارسال پیام تست ناموفق بود: ' . $result['message'],
            ['error' => empty($result['ok']) ? $result['message'] : ''], 0);

        set_transient('wc_telegram_test_result', $result, 60);
        wp_safe_redirect(admin_url('admin.php?page=wc-telegram-orders'));
        exit;
    }

    public function handle_reset_template() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_reset_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $s = $this->get_settings();
        $s['template'] = $this->default_template();
        update_option(WC_TELEGRAM_ORDERS_OPTION, $s);
        set_transient('wc_telegram_test_result', ['ok' => true, 'message' => 'قالب پیام به فرمت فارسی پیش‌فرض برگشت و ذخیره شد.'], 60);
        wp_safe_redirect(admin_url('admin.php?page=wc-telegram-orders'));
        exit;
    }

    /* ---------------- پردازش سفارش ---------------- */

    public function on_new_order($order_id, $order = null) {
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return;
        }
        // همیشه خواندن تازه از دیتابیس — آبجکت ورودی ممکن است قدیمی باشد،
        // چون افزونه‌های دیگر (مثل افزونه شهرها) بعد از ساخت سفارش شهر/استان را تکمیل می‌کنند
        $fresh = wc_get_order($order_id);
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }
        if (!$order instanceof WC_Order) {
            return;
        }
        // جلوگیری از ارسال تکراری (مثلاً اگر هوک دوبار اجرا شود)
        if ($order->get_meta('_wc_telegram_sent') === 'yes'
            || $order->get_meta('_wc_telegram_pending') === 'yes') {
            return;
        }

        // اگر آدرس یا اقلام هنوز ثبت نشده (درگاه‌هایی مثل ملت/دیجیکالا سفارش را زود می‌سازند)
        // یا سفارش هنوز پرداخت نشده، فعلاً هیچ پیامی نفرست؛
        // با پرداخت/تکمیل، فقط «یک» پیام کامل ارسال می‌شود — نه دو پیام
        $missing = [];
        if (!$this->has_address($order)) {
            $missing[] = 'آدرس';
        }
        if (!$this->has_items($order)) {
            $missing[] = 'اقلام سفارش';
        }
        if (!empty($missing)) {
            $order->update_meta_data('_wc_telegram_pending', 'yes');
            $order->add_order_note('⏳ ' . implode(' و ', $missing) . ' هنوز ثبت نشده؛ پیام تلگرام پس از تکمیل اطلاعات ارسال می‌شود.');
            $order->save_meta_data();
            $this->log('info', 'order', 'order_pending', sprintf('سفارش #%s معوق شد: %s هنوز ثبت نشده.', $order->get_order_number(), implode(' و ', $missing)), ['missing' => $missing], $order->get_id());
            return;
        }

        // شرط پرداخت: سفارش در انتظار پرداخت (pending) یا لغوشده معرفی نمی‌شود؛
        // پیام با تغییر وضعیت به وضعیت مجاز (پیش‌فرض «در حال انجام» = پرداخت‌شده) می‌رود
        if (!$this->order_send_is_allowed($order, $s)) {
            if ($this->order_status_is_dead($order->get_status())) {
                // سفارش از دست رفته — پرچمی نمی‌سازیم که جارو دنبالش بگردد
                $this->log('info', 'order', 'order_skipped_dead', sprintf('سفارش #%s با وضعیت «%s» به تلگرام ارسال نشد (سفارش مرده).', $order->get_order_number(), wc_get_order_status_name($order->get_status())), ['status' => $order->get_status()], $order->get_id());
                return;
            }
            $order->update_meta_data('_wc_telegram_pending', 'yes');
            $order->add_order_note(sprintf(
                '⏳ سفارش هنوز پرداخت نشده (وضعیت «%s»)؛ پیام تلگرام پس از تغییر وضعیت به «%s» ارسال می‌شود.',
                wc_get_order_status_name($order->get_status()),
                $this->allowed_statuses_label($s)
            ));
            $order->save_meta_data();
            $this->log('info', 'order', 'order_pending_payment', sprintf('سفارش #%s معرفی نشد: منتظر پرداخت است (وضعیت «%s»؛ مجاز: %s).', $order->get_order_number(), wc_get_order_status_name($order->get_status()), implode(',', $this->send_allowed_statuses($s))), ['status' => $order->get_status(), 'allowed' => $this->send_allowed_statuses($s)], $order->get_id());
            return;
        }

        // ارسال بلافاصله انجام نمی‌شود؛ به event کرون موکول می‌شود تا تسویه‌حساب
        // منتظر پاسخ تلگرام نماند و خطای موقت هم خودکار تلاش مجدد شود
        $this->schedule_order_send($order->get_id());
        $this->log('info', 'order', 'order_queued', sprintf('سفارش #%s برای ارسال به تلگرام زمان‌بندی شد.', $order->get_order_number()), ['total' => $this->money($order->get_total(), $order)], $order->get_id());
    }

    public function add_order_action($actions) {
        $actions['wc_telegram_resend'] = 'ارسال به تلگرام';
        return $actions;
    }

    public function resend_via_order_action($order) {
        if (!$order instanceof WC_Order) {
            return;
        }
        // پرچم ارسال پاک می‌شود تا دوباره ارسال شود
        $order->delete_meta_data('_wc_telegram_sent');
        $order->delete_meta_data('_wc_telegram_attempts');
        $message = $this->build_message($order);
        $result = $this->send_to_all_chats($message);
        if (!empty($result['ok'])) {
            $order->update_meta_data('_wc_telegram_sent', 'yes');
            $order->delete_meta_data('_wc_telegram_attempts');
            if ($this->order_ready($order)) {
                $order->delete_meta_data('_wc_telegram_pending');
                $order->delete_meta_data('_wc_telegram_needs_address');
            } else {
                // هنوز ناقص است — با کامل شدن، پیام کامل خودکار می‌رود
                $order->update_meta_data('_wc_telegram_pending', 'yes');
            }
            $order->add_order_note('✅ دوباره به تلگرام ارسال شد.');
            $this->log('success', 'order', 'manual_resend', sprintf('ارسال دستی سفارش #%s موفق بود.', $order->get_order_number()), ['chats' => array_keys($result['sent'])], $order->get_id());
        } else {
            $order->add_order_note(sprintf(
                '⚠️ ارسال مجدد به تلگرام ناموفق بود: %s',
                $result['message']
            ));
            $this->log('error', 'order', 'manual_resend_failed', sprintf('ارسال دستی سفارش #%s ناموفق بود: %s', $order->get_order_number(), $result['message']), ['error' => $result['message']], $order->get_id());
        }
        $order->save_meta_data();
    }

    /* ---------------- اکشن گروهی در لیست سفارش‌ها ---------------- */

    public function add_bulk_actions($actions) {
        $actions['wc_telegram_bulk_send']  = 'ارسال به تلگرام (فقط وضعیت‌های مجاز)';
        $actions['wc_telegram_bulk_force'] = 'ارسال به تلگرام (بدون شرط پرداخت)';
        return $actions;
    }

    /**
     * پردازش اکشن گروهی با محافظت در برابر سیل:
     * سقف تعداد در هر اجرا، فاصلهٔ زمانی بین پیام‌ها، قفل ضد دابل‌کلیک
     * و رد کردن سفارش‌هایی که از قبل در صف هستند.
     */
    public function handle_bulk_send($redirect_to, $doaction, $object_ids) {
        if (!in_array((string) $doaction, ['wc_telegram_bulk_send', 'wc_telegram_bulk_force'], true)) {
            return $redirect_to;
        }
        if (!current_user_can('manage_woocommerce')) {
            return $this->bulk_notice_url($redirect_to, 'forbidden');
        }
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return $this->bulk_notice_url($redirect_to, 'disabled');
        }
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $object_ids))));
        if (empty($ids)) {
            return $this->bulk_notice_url($redirect_to, 'empty');
        }
        // ضد سیل: دابل‌کلیک یا رفرش، دو بار پشت‌سرهم صف نمی‌سازد
        if (get_transient('wc_telegram_bulk_lock')) {
            return $this->bulk_notice_url($redirect_to, 'locked');
        }
        set_transient('wc_telegram_bulk_lock', 1, 30);

        $force  = ((string) $doaction === 'wc_telegram_bulk_force');
        $gap    = max(0, min(60, (int) (isset($s['bulk_gap']) ? $s['bulk_gap'] : 2)));
        $max    = max(1, min(200, (int) (isset($s['bulk_max']) ? $s['bulk_max'] : 50)));
        $cut    = count($ids) > $max;
        if ($cut) {
            $ids = array_slice($ids, 0, $max);
        }

        $this->maybe_raise_memory();
        $n = ['queued' => 0, 'status' => 0, 'dup' => 0, 'bad' => 0, 'cut' => count((array) $object_ids) - count($ids)];
        $i = 0;
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order instanceof WC_Order) {
                $n['bad']++;
                continue;
            }
            if (!$force && !$this->order_send_is_allowed($order, $s)) {
                $n['status']++; // وضعیت مجاز نیست (مثلاً هنوز پرداخت نشده)
                continue;
            }
            // اگر برای همین سفارش ارسال/تلاش مجدد از قبل زمان‌بندی شده، اسلات جدید نمی‌سازیم
            if (wp_next_scheduled(self::SEND_ORDER_HOOK, [$id])) {
                $n['dup']++;
                continue;
            }
            $order->delete_meta_data('_wc_telegram_sent');
            $order->delete_meta_data('_wc_telegram_attempts');
            $order->delete_meta_data('_wc_telegram_expired');
            if ($force) {
                // پرچم یک‌بارمصرف: پردازشگر کرون شرط پرداخت را برای همین سفارش نادیده می‌گیرد
                $order->update_meta_data(self::FORCE_SEND_META, 'yes');
                $order->delete_meta_data('_wc_telegram_pending');
            }
            $order->save_meta_data();
            // فاصلهٔ پلکانی بین پیام‌ها تا تلگرام 429 ندهد
            $this->schedule_order_send($id, $i * $gap);
            $i++;
            $n['queued']++;
            $this->clean_order_cache($id);
        }

        $this->log('info', 'order', 'bulk_send',
            sprintf('ارسال گروهی: %d سفارش در صف، %d رد (وضعیت)، %d تکراری، %d نامعتبر%s.', $n['queued'], $n['status'], $n['dup'], $n['bad'], $cut ? ' (محدود به ' . $max . ' سفارش)' : ''),
            $n + ['force' => $force, 'gap' => $gap], 0);
        set_transient('wc_telegram_bulk_result', $n + ['force' => $force, 'gap' => $gap], 120);
        return $this->bulk_notice_url($redirect_to, 'ok');
    }

    private function bulk_notice_url($redirect_to, $code) {
        return add_query_arg('wc_telegram_bulk', (string) $code, (string) $redirect_to);
    }

    public function bulk_send_notice() {
        if (!isset($_GET['wc_telegram_bulk'])) {
            return;
        }
        $code = sanitize_key(wp_unslash($_GET['wc_telegram_bulk']));
        $n    = get_transient('wc_telegram_bulk_result');
        delete_transient('wc_telegram_bulk_result');
        $texts = [
            'forbidden' => ['danger', 'اجازهٔ انجام این کار را ندارید.'],
            'disabled'  => ['warning', 'ارسال به تلگرام در تنظیمات غیرفعال است.'],
            'empty'     => ['warning', 'هیچ سفارشی انتخاب نشده بود.'],
            'locked'    => ['warning', 'یک ارسال گروهی همین حالا در جریان است؛ چند لحظه دیگر دوباره تلاش کنید.'],
        ];
        if (isset($texts[$code])) {
            echo '<div class="notice notice-' . esc_attr($texts[$code][0]) . '"><p>' . esc_html($texts[$code][1]) . '</p></div>';
            return;
        }
        if (!is_array($n)) {
            return;
        }
        $msg = sprintf(
            '%d سفارش برای ارسال به تلگرام در صف قرار گرفت%s.',
            (int) $n['queued'],
            ((int) $n['gap'] > 0) ? sprintf(' (با فاصلهٔ %d ثانیه بین پیام‌ها)', (int) $n['gap']) : ''
        );
        $extra = [];
        if (!empty($n['status'])) {
            $extra[] = sprintf('%d سفارش به دلیل وضعیت غیرمجاز رد شد', (int) $n['status']);
        }
        if (!empty($n['dup'])) {
            $extra[] = sprintf('%d سفارش از قبل در صف بود', (int) $n['dup']);
        }
        if (!empty($n['bad'])) {
            $extra[] = sprintf('%d شناسهٔ نامعتبر', (int) $n['bad']);
        }
        if (!empty($n['cut'])) {
            $extra[] = sprintf('%d سفارش به دلیل سقف تعداد رد شد', (int) $n['cut']);
        }
        if ($extra) {
            $msg .= ' — ' . implode('، ', $extra) . '.';
        }
        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '</p></div>';
    }

    // پیام کوتاه تغییر وضعیت — روی ایجاد سفارش اجرا نمی‌شود، فقط روی تغییر واقعی وضعیت
    public function on_status_changed($order_id, $old_status, $new_status, $order = null) {
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return;
        }
        if ($old_status === $new_status) {
            return;
        }
        // اول پیام معوق (اگر هست)؛ اگر همین حالا پیام کامل رفت، پیام کوتاه لازم نیست.
        // این بررسی مستقل از کلید «ارسال پیام تغییر وضعیت» است: سفارش پرداخت‌شده باید
        // با تغییر وضعیت به «در حال انجام» معرفی شود حتی وقتی پیام‌های کوتاه خاموش‌اند.
        $flushed = $this->flush_pending_message($order_id, $order);
        if ($s['status_enabled'] !== 'yes' || $flushed) {
            return;
        }
        $fresh_status = wc_get_order($order_id);
        if ($fresh_status instanceof WC_Order) {
            $order = $fresh_status;
        }
        if (!$order instanceof WC_Order) {
            return;
        }
        // سفارش‌هایی که هنوز در گروه معرفی نشده‌اند، پیام کوتاه یتیم نمی‌گیرند
        // (مثلاً لغو شدن سفارش دیجیکالا قبل از ثبت — که قبلاً بدون پیام ثبتی می‌آمد)
        if ($order->get_meta('_wc_telegram_pending') === 'yes'
            || $order->get_meta('_wc_telegram_sent') !== 'yes') {
            return;
        }
        // محافظ واقعیت: اگر وضعیت فعلی سفارش با وضعیت ادعاشده فرق دارد
        // (هوک کهنه به‌خاطر ذخیره مستقیم درگاه در دیتابیس)، پیام دروغ نفرست
        // مثلاً درگاه اول لغو و بعد تأیید می‌کند و هوک لغو دیر می‌رسد
        if ($order->get_status() !== $new_status) {
            return;
        }
        // وضعیت‌های نادیده‌گرفته‌شده (مثل «موجود در انبار» که تغییر داخلی/گروهی است)
        if ($this->status_is_ignored($new_status, $s)) {
            $this->log('debug', 'order', 'status_ignored', sprintf('تغییر وضعیت سفارش #%s به «%s» طبق تنظیمات نادیده گرفته شد.', $order->get_order_number(), wc_get_order_status_name($new_status)), ['old' => $old_status, 'new' => $new_status], $order_id);
            return;
        }
        // به صف بفرست: ارسال با فاصلهٔ تنظیم‌شده، بدون بلاک‌کردن ذخیرهٔ سفارش و بدون 429
        $this->enqueue_status_send($order_id, $old_status, $new_status);
    }

    private function status_is_ignored($status, $s = null) {
        $s = $s ?: $this->get_settings();
        $raw = isset($s['status_ignore']) ? (string) $s['status_ignore'] : '';
        if ($raw === '') {
            return false;
        }
        $list = array_filter(array_map('trim', explode(',', str_replace('wc-', '', $raw))));
        return in_array(str_replace('wc-', '', (string) $status), $list, true);
    }

    // صف پیام‌های وضعیت: هر پیام روی یک اسلات زمانی جدا (فاصلهٔ status_gap ثانیه از آخرین اسلات)
    private function enqueue_status_send($order_id, $old_status, $new_status) {
        $s   = $this->get_settings();
        $gap = isset($s['status_gap']) ? max(0, (int) $s['status_gap']) : 3;
        $order_id = (int) $order_id;
        // ضد سیل: اگر برای همین سفارش پیام وضعیتِ در صف هست، اسلات جدید نساز (پردازشگر وضعیت فعلی را می‌فرستد)
        if ($this->status_event_pending($order_id)) {
            return;
        }
        // سقف صف: اگر بیش از ۳۰۰ پیام وضعیت در انتظار است (تغییر گروهی خیلی بزرگ)، بقیه رها می‌شوند تا کرون/تلگرام خفه نشود
        if ($this->status_queue_size() >= 300) {
            $this->log('warning', 'order', 'status_dropped', sprintf('پیام وضعیت سفارش #%s ارسال نشد: صف پیام‌های وضعیت پر است (۳۰۰).', $order_id), ['old' => $old_status, 'new' => $new_status], $order_id);
            return;
        }
        $last = (int) get_option('wc_telegram_status_last_slot', 0);
        // اسلات‌ها بیش از ۳۰ دقیقه جلو نمی‌روند؛ صف پر شد → همان انتها
        $slot = min(max(time(), $last + $gap), time() + 30 * MINUTE_IN_SECONDS);
        update_option('wc_telegram_status_last_slot', $slot, false);
        $args = [$order_id, (string) $old_status, (string) $new_status, 1];
        if (wp_schedule_single_event($slot, self::STATUS_HOOK, $args)) {
            $this->log('debug', 'order', 'status_queued', sprintf('پیام تغییر وضعیت سفارش #%s در صف قرار گرفت (%d ثانیه دیگر).', $order_id, max(0, $slot - time())), ['old' => $old_status, 'new' => $new_status, 'slot' => $slot], $order_id);
            $this->spawn_cron_now();
            return;
        }
        // زمان‌بندی نشد — همان‌جا بفرست
        $this->process_status_send($order_id, $old_status, $new_status, 1);
    }

    private function status_event_pending($order_id) {
        $crons = _get_cron_array();
        if (empty($crons) || !is_array($crons)) {
            return false;
        }
        foreach ($crons as $cron) {
            if (empty($cron[self::STATUS_HOOK])) {
                continue;
            }
            foreach ($cron[self::STATUS_HOOK] as $data) {
                if (isset($data['args'][0]) && (int) $data['args'][0] === (int) $order_id) {
                    return true;
                }
            }
        }
        return false;
    }

    private function status_queue_size() {
        $crons = _get_cron_array();
        $n = 0;
        if (is_array($crons)) {
            foreach ($crons as $cron) {
                if (!empty($cron[self::STATUS_HOOK])) {
                    $n += count($cron[self::STATUS_HOOK]);
                }
            }
        }
        return $n;
    }

    // پردازشگر کرون پیام وضعیت — با تلاش مجدد (۳ بار) در صورت 429/خطای شبکه
    public function process_status_send($order_id, $old_status, $new_status, $attempt = 1) {
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes' || $s['status_enabled'] !== 'yes') {
            return;
        }
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        // اگر بین صف و ارسال دوباره عوض شده، پیام کهنه نفرست
        if ($order->get_status() !== $new_status) {
            $this->log('debug', 'order', 'status_stale', sprintf('پیام وضعیت سفارش #%s ارسال نشد: وضعیت دوباره تغییر کرده است.', $order->get_order_number()), ['queued_new' => $new_status, 'current' => $order->get_status()], (int) $order_id);
            return;
        }
        if ($order->get_meta('_wc_telegram_sent') !== 'yes') {
            return;
        }
        // فاصله‌گذاری سراسری: اگر پیام دیگری همین لحظه رفته، کمی جلوتر برو
        $gap  = isset($s['status_gap']) ? max(0, (int) $s['status_gap']) : 3;
        $last = (int) get_option('wc_telegram_status_last_sent', 0);
        if ($gap > 0 && (time() - $last) < $gap) {
            wp_schedule_single_event(time() + $gap, self::STATUS_HOOK, [(int) $order_id, (string) $old_status, (string) $new_status, (int) $attempt]);
            return;
        }

        $template = !empty($s['status_template']) ? $s['status_template'] : $this->default_status_template();

        $replacements = [
            '{order_number}'  => $order->get_order_number(),
            '{order_id}'      => $order->get_id(),
            '{old_status}'    => $this->status_emoji($old_status) . ' ' . wc_get_order_status_name($old_status),
            '{new_status}'    => $this->status_emoji($new_status) . ' ' . wc_get_order_status_name($new_status),
            '{customer_name}' => trim($order->get_formatted_billing_full_name()),
            '{order_total}'   => $this->money($this->order_amounts($order)['grand'], $order),
            '{order_url}'     => $order->get_edit_order_url(),
            '{site_name}'     => get_bloginfo('name'),
        ];

        $escaped = [];
        foreach ($replacements as $key => $value) {
            if ($key === '{order_url}') {
                $escaped[$key] = esc_url($value);
            } else {
                $escaped[$key] = htmlspecialchars(wp_strip_all_tags(html_entity_decode((string) $value)), ENT_QUOTES, 'UTF-8');
            }
        }

        update_option('wc_telegram_status_last_sent', time(), false);
        $result = $this->send_to_all_chats(strtr($template, $escaped));
        if (!empty($result['ok'])) {
            $this->log('success', 'order', 'status_sent', sprintf('پیام تغییر وضعیت سفارش #%s ارسال شد (%s ← %s).', $order->get_order_number(), wc_get_order_status_name($old_status), wc_get_order_status_name($new_status)), ['old' => $old_status, 'new' => $new_status, 'attempt' => (int) $attempt], (int) $order_id);
            return;
        }
        $attempt = (int) $attempt;
        if ($attempt < 3) {
            $delay = 60 * $attempt;
            wp_schedule_single_event(time() + $delay, self::STATUS_HOOK, [(int) $order_id, (string) $old_status, (string) $new_status, $attempt + 1]);
            $this->log('warning', 'order', 'status_retry', sprintf('ارسال پیام وضعیت سفارش #%s ناموفق بود (تلاش %d از ۳)؛ %d ثانیه دیگر دوباره تلاش می‌شود: %s', $order->get_order_number(), $attempt, $delay, $result['message']), ['old' => $old_status, 'new' => $new_status, 'attempt' => $attempt, 'error' => $result['message']], (int) $order_id);
            return;
        }
        $this->log('error', 'order', 'status_failed', sprintf('ارسال پیام تغییر وضعیت سفارش #%s پس از ۳ تلاش ناموفق ماند: %s', $order->get_order_number(), $result['message']), ['old' => $old_status, 'new' => $new_status, 'error' => $result['message']], (int) $order_id);
    }

    /* ---------------- سفارش‌های معوق: واکنش به تکمیل اقلام + جاروی دوره‌ای ---------------- */

    // درگاه‌هایی مثل ملت سفارش را خالی می‌سازند و اقلام را بعداً اضافه می‌کنند؛
    // با آمدن هر آیتم، اگر سفارش معوق است و حالا کامل شده، پیام کامل زمان‌بندی می‌شود (در shutdown تا همهٔ اقلام بیایند).
    public function on_new_order_item($item_id, $item, $order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0 || isset($this->pending_check[$order_id])) {
            return;
        }
        if (get_post_meta($order_id, '_wc_telegram_pending', true) !== 'yes' && !$this->hpos_pending($order_id)) {
            return;
        }
        $this->pending_check[$order_id] = true;
        if (!has_action('shutdown', [$this, 'flush_pending_checks'])) {
            add_action('shutdown', [$this, 'flush_pending_checks'], 4);
        }
    }

    private $pending_check = [];

    private function hpos_pending($order_id) {
        $o = wc_get_order($order_id);
        return $o instanceof WC_Order && $o->get_meta('_wc_telegram_pending') === 'yes';
    }

    public function flush_pending_checks() {
        $ids = array_keys($this->pending_check);
        $this->pending_check = [];
        foreach ($ids as $oid) {
            $this->flush_pending_message($oid);
        }
    }

    // هر ذخیرهٔ سفارش (از هر مسیری: REST، درگاه، افزونه) — ارزان است چون فقط سفارش‌های معوق را بررسی می‌کند
    public function on_order_saved($order) {
        if (!$order instanceof WC_Order || $order->get_meta('_wc_telegram_pending') !== 'yes') {
            return;
        }
        $oid = $order->get_id();
        if (isset($this->pending_check[$oid])) {
            return;
        }
        $this->pending_check[$oid] = true;
        if (!has_action('shutdown', [$this, 'flush_pending_checks'])) {
            add_action('shutdown', [$this, 'flush_pending_checks'], 4);
        }
    }

    /**
     * جاروی دوره‌ای (هر ۱۵ دقیقه): سفارش‌های معوقی که هیچ هوکی برایشان نیامد.
     *  - کامل شده یا به وضعیت پولی رسیده → پیام کامل
     *  - لغو/ناموفق → پرچم پاک
     *  - بیش از ۲۴ ساعت معوق و هنوز خالی (سبد رهاشده/پرداخت ناتمام) → بایگانی بی‌صدا
     */
    public function sweep_pending_orders() {
        if (!function_exists('wc_get_orders')) {
            return;
        }
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return;
        }
        $orders = wc_get_orders([
            'limit'      => 50,
            'orderby'    => 'date',
            'order'      => 'ASC',
            'meta_key'   => '_wc_telegram_pending',   // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_value' => 'yes',                    // phpcs:ignore WordPress.DB.SlowDBQuery
            'return'     => 'objects',
        ]);
        $n = ['flushed' => 0, 'dropped' => 0, 'expired' => 0, 'waiting' => 0];
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order) {
                continue;
            }
            $st = $order->get_status();
            if ($this->order_status_is_dead($st)) {
                $order->delete_meta_data('_wc_telegram_pending');
                $order->save_meta_data();
                $n['dropped']++;
                continue;
            }
            // فقط سفارشی که از نظر وضعیت مجاز است (پیش‌فرض: پرداخت‌شده = «در حال انجام») معرفی می‌شود
            if ($this->order_send_is_allowed($order, $s)) {
                $this->schedule_order_send($order->get_id());
                $n['flushed']++;
                continue;
            }
            $created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
            if ((time() - $created) > DAY_IN_SECONDS && in_array($st, ['pending', 'checkout-draft'], true)) {
                $order->delete_meta_data('_wc_telegram_pending');
                $order->update_meta_data('_wc_telegram_expired', 'yes');
                $order->save_meta_data();
                $n['expired']++;
                continue;
            }
            $n['waiting']++;
        }
        if (array_sum($n) > 0) {
            $this->log('info', 'order', 'pending_sweep', sprintf('جاروی معوق‌ها: %d ارسال، %d لغو، %d منقضی، %d هنوز منتظر.', $n['flushed'], $n['dropped'], $n['expired'], $n['waiting']), $n, 0);
        }
    }

    // آدرس واقعی دارد یا هنوز خالی است؟
    private function has_address($order) {
        return $this->full_address($order) !== '—';
    }

    // قلم دارد یا سفارش هنوز خالی است؟
    private function has_items($order) {
        return $order->get_item_count() > 0;
    }

    // سفارش برای معرفی کامل آماده است: هم آدرس، هم اقلام
    private function order_ready($order) {
        return $this->has_address($order) && $this->has_items($order);
    }

    /* ---------------- شرط ارسال: فقط سفارش‌های پرداخت‌شده ---------------- */

    // وضعیت‌هایی که ارسال پیام سفارش را آزاد می‌کنند (پیش‌فرض: processing = در حال انجام = پرداخت‌شده)
    private function send_allowed_statuses($s = null) {
        $s = $s ?: $this->get_settings();
        $raw = isset($s['send_statuses']) ? (string) $s['send_statuses'] : '';
        $list = array_values(array_unique(array_filter(array_map(function ($x) {
            // اول کوچک‌سازی، بعد حذف پیشوند wc- (ورودی می‌تواند WC-ON-HOLD باشد)
            return sanitize_key(str_replace('wc-', '', strtolower(trim($x))));
        }, explode(',', $raw)))));
        return $list ? $list : ['processing'];
    }

    // شرط پرداخت فعال است؟ (غیرفعال = رفتار نسخه‌های قبل)
    private function paid_gate_enabled($s = null) {
        $s = $s ?: $this->get_settings();
        return !isset($s['paid_gate']) || $s['paid_gate'] === 'yes';
    }

    // فهرست قدیمی «وضعیت‌های پولی» — فقط وقتی شرط پرداخت خاموش است استفاده می‌شود
    private function legacy_flush_statuses() {
        return apply_filters('wc_telegram_flush_statuses', ['processing', 'completed', 'on-hold']);
    }

    /**
     * آیا پیام این سفارش از نظر وضعیت مجاز به ارسال است؟
     * شرط پرداخت روشن (پیش‌فرض): فقط وضعیت‌های فهرست «وضعیت‌های مجاز ارسال» —
     * یعنی سفارش در انتظار پرداخت یا لغوشده هیچ پیامی نمی‌گیرد و با رسیدن به
     * «در حال انجام» (پرداخت‌شده) معرفی می‌شود.
     * شرط پرداخت خاموش: رفتار قبلی — آماده بودن سفارش یا رسیدن به وضعیت پولی.
     */
    private function order_send_is_allowed($order, $s = null) {
        $s = $s ?: $this->get_settings();
        $status = str_replace('wc-', '', (string) $order->get_status());
        if ($this->paid_gate_enabled($s)) {
            return in_array($status, $this->send_allowed_statuses($s), true);
        }
        return $this->order_ready($order) || in_array($status, $this->legacy_flush_statuses(), true);
    }

    // وضعیت‌های مرده: این سفارش دیگر هیچ پیامی نمی‌گیرد و پرچم‌هایش پاک می‌شود
    private function order_status_is_dead($status) {
        return in_array(str_replace('wc-', '', (string) $status), ['cancelled', 'auto-cancelled', 'refunded', 'failed', 'trash'], true);
    }

    // نام خوانای وضعیت‌های مجاز (برای یادداشت سفارش و لاگ)
    private function allowed_statuses_label($s = null) {
        $names = array_filter(array_map(function ($slug) {
            return function_exists('wc_get_order_status_name') ? wc_get_order_status_name($slug) : $slug;
        }, $this->send_allowed_statuses($s)));
        return $names ? implode(' یا ', $names) : 'پرداخت‌شده';
    }

    // بررسی پیام معوق — خروجی true یعنی پیام کامل جاری است و پیام کوتاه تغییر وضعیت لازم نیست.
    // (سفارش‌های دیجیکالا که موقع ثبت آدرس نداشتند، + سازگاری با پرچم نسخه 1.5.0)
    // خودِ ارسال به کرون موکول می‌شود تا پرداخت/تسویه‌حساب منتظر پاسخ تلگرام نماند.
    public function flush_pending_message($order_id, $order = null) {
        $fresh = wc_get_order($order_id);
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }
        if (!$order instanceof WC_Order) {
            return false;
        }
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return false;
        }
        $pending = ($order->get_meta('_wc_telegram_pending') === 'yes');
        $expired = ($order->get_meta('_wc_telegram_expired') === 'yes');
        $legacy  = ($order->get_meta('_wc_telegram_sent') === 'yes'
                 && $order->get_meta('_wc_telegram_needs_address') === 'yes');
        if (!$pending && !$legacy && !$expired) {
            return false;
        }
        $st = $order->get_status();
        // مرگ خاموش: لغو/استرداد/ناموفق قبل از اولین معرفی — بدون هیچ پیامی
        if ($this->order_status_is_dead($st)) {
            $order->delete_meta_data('_wc_telegram_pending');
            $order->delete_meta_data('_wc_telegram_needs_address');
            $order->delete_meta_data('_wc_telegram_expired');
            $order->save_meta_data();
            return false;
        }
        // شرط پرداخت: تا رسیدن به وضعیت مجاز (پیش‌فرض «در حال انجام» = پرداخت‌شده) صبر می‌کنیم؛
        // رسیدن به آن وضعیت حتی با آدرس/اقلام ناقص هم پیام را آزاد می‌کند
        if (!$this->order_send_is_allowed($order, $s)) {
            return false; // هنوز زود است — صبر کن
        }
        // سفارش منقضی (بیش از ۲۴ ساعت معوق) حالا پرداخت شده — دوباره وارد چرخهٔ ارسال
        // می‌شود تا پرداخت دیرهنگام بی‌پاسخ نماند (و از فهرست جارو هم خارج شده بود)
        if ($expired && !$pending) {
            $order->delete_meta_data('_wc_telegram_expired');
            $order->update_meta_data('_wc_telegram_pending', 'yes');
            $order->save_meta_data();
        }
        $this->schedule_order_send($order->get_id());
        $this->log('debug', 'order', 'flush_queued', sprintf('پیام معوق سفارش #%s زمان‌بندی شد.', $order->get_order_number()), ['status' => $st], $order->get_id());
        return true;
    }

    /* ---------------- ارسال غیرهمزمان (بدون بلاک کردن تسویه‌حساب) ---------------- */

    // ارسال پیام سفارش به event کرون موکول می‌شود؛ پردازشگر: process_order_send
    private function schedule_order_send($order_id, $delay = 0) {
        $order_id = (int) $order_id;
        if (wp_next_scheduled(self::SEND_ORDER_HOOK, [$order_id])) {
            return true; // برای همین سفارش ارسال/تلاش مجدد از قبل برنامه‌ریزی شده
        }
        if (wp_schedule_single_event(time() + max(0, (int) $delay), self::SEND_ORDER_HOOK, [$order_id])) {
            $this->spawn_cron_now();
            return true;
        }
        // زمان‌بندی نشد (خیلی نادر) — بدون تأخیر همان‌جا بفرست
        $this->process_order_send($order_id);
        return true;
    }

    // اجرای کرون را بیدار می‌کند تا پیام چندثانیه‌ای، نه چندساعتی، برود
    private function spawn_cron_now() {
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        // بیش از یک بار در هر ۱۰ ثانیه کرون را بیدار نکن (سیل درخواست به wp-cron.php)
        if (get_transient('wc_telegram_cron_spawned')) {
            return;
        }
        set_transient('wc_telegram_cron_spawned', 1, 10);
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            // کرون وردپرس خاموش است و کرون سرور هم ممکن است دیر اجرا شود؛
            // خودمان wp-cron.php را با یک درخواست غیرهمزمان (بدون انتظار پاسخ) بیدار می‌کنیم
            $this->ping_wp_cron();
            return;
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    // بیدار کردن wp-cron.php وقتی DISABLE_WP_CRON فعال است — غیرهمزمان و بی‌تأثیر بر زمان پاسخ صفحه
    private function ping_wp_cron() {
        if (!function_exists('site_url') || !function_exists('wp_remote_post')) {
            return;
        }
        $url = site_url('/wp-cron.php?doing_wp_cron=' . rawurlencode(microtime()));
        wp_remote_post($url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'cookies'   => [],
            'body'      => [],
        ]);
        $this->log('debug', 'system', 'cron_ping', 'کرون وردپرس خاموش است (DISABLE_WP_CRON)؛ wp-cron.php با درخواست غیرهمزمان بیدار شد.', ['url' => $url], 0);
    }

    // قفل اتمیک ارسال برای هر سفارش — دو درخواست هم‌زمان دو پیام نمی‌فرستند
    private function acquire_send_lock($order_id) {
        $order_id = (int) $order_id;
        // add_post_meta با unique=true اتمیک است: اگر متا از قبل موجود باشد false برمی‌گرداند
        if (add_post_meta($order_id, '_wc_telegram_send_lock', time(), true)) {
            return true;
        }
        $t = (int) get_post_meta($order_id, '_wc_telegram_send_lock', true);
        if ($t > 0 && (time() - $t) > 300) {
            // قفل مرده (پردازش نیمه‌کاره) — بعد از ۵ دقیقه قابل بازپس‌گیری است
            update_post_meta($order_id, '_wc_telegram_send_lock', time());
            return true;
        }
        return false;
    }

    private function release_send_lock($order_id) {
        delete_post_meta((int) $order_id, '_wc_telegram_send_lock');
    }

    // پردازشگر کرون: ارسال پیام سفارش (سفارش تازه یا معوق) با قفل و تلاش مجدد
    public function process_order_send($order_id) {
        $order_id = (int) $order_id;
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        if ($order->get_meta('_wc_telegram_sent') === 'yes') {
            return;
        }
        if ($order->get_meta('_wc_telegram_pending') === 'yes'
            || $order->get_meta('_wc_telegram_needs_address') === 'yes') {
            $this->process_pending_flush($order_id);
            return;
        }
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return;
        }
        // ارسال اجباری (از اکشن دستی/گروهی): یک‌بارمصرف است و شرط پرداخت را نادیده می‌گیرد
        $force = ($order->get_meta(self::FORCE_SEND_META) === 'yes');
        if ($force) {
            $order->delete_meta_data(self::FORCE_SEND_META);
        }
        // محافظ نهایی: اگر بین زمان‌بندی و اجرا وضعیت عوض شده (لغو شده یا هنوز پرداخت نشده)،
        // پیام نمی‌رود؛ پرچم معوق می‌ماند تا با رسیدن به وضعیت مجاز معرفی شود
        if (!$force && !$this->order_send_is_allowed($order, $s)) {
            if ($this->order_status_is_dead($order->get_status())) {
                $order->delete_meta_data('_wc_telegram_attempts');
                $order->save_meta_data();
                $this->log('info', 'order', 'order_skipped_dead', sprintf('سفارش #%s ارسال نشد: وضعیت «%s».', $order->get_order_number(), wc_get_order_status_name($order->get_status())), ['status' => $order->get_status()], $order_id);
                return;
            }
            $order->update_meta_data('_wc_telegram_pending', 'yes');
            $order->delete_meta_data('_wc_telegram_attempts');
            $order->save_meta_data();
            $this->log('debug', 'order', 'order_send_blocked', sprintf('سفارش #%s هنوز ارسال نمی‌شود: وضعیت «%s» (مجاز: %s).', $order->get_order_number(), wc_get_order_status_name($order->get_status()), implode(',', $this->send_allowed_statuses($s))), ['status' => $order->get_status()], $order_id);
            return;
        }
        if (!$this->acquire_send_lock($order_id)) {
            return; // پردازش دیگری در حال ارسال است
        }
        try {
            $result = $this->send_to_all_chats($this->build_message($order));
            if (!empty($result['ok'])) {
                $order->update_meta_data('_wc_telegram_sent', 'yes');
                $order->delete_meta_data('_wc_telegram_attempts');
                $order->save_meta_data();
                $this->log('success', 'order', 'order_sent', sprintf('پیام سفارش #%s به تلگرام ارسال شد.', $order->get_order_number()), ['chats' => array_keys($result['sent'])], $order_id);
            } else {
                $this->handle_send_failure($order, $result['message']);
            }
        } finally {
            $this->release_send_lock($order_id);
        }
    }

    // ارسال پیام معوق از مسیر کرون — همان منطق flush، با قفل و تلاش مجدد
    private function process_pending_flush($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        $s = $this->get_settings();
        if ($s['enabled'] !== 'yes') {
            return;
        }
        $pending = ($order->get_meta('_wc_telegram_pending') === 'yes');
        $legacy  = ($order->get_meta('_wc_telegram_sent') === 'yes'
                 && $order->get_meta('_wc_telegram_needs_address') === 'yes');
        if (!$pending && !$legacy) {
            return;
        }
        $st = $order->get_status();
        if ($this->order_status_is_dead($st)) {
            $order->delete_meta_data('_wc_telegram_pending');
            $order->delete_meta_data('_wc_telegram_needs_address');
            $order->save_meta_data();
            return;
        }
        // شرط پرداخت: اگر وضعیت عوض شده و دیگر مجاز نیست (مثلاً لغو شده)، پیام نمی‌رود
        if (!$this->order_send_is_allowed($order, $s)) {
            return; // پرچم معوق می‌ماند تا با تغییر وضعیت به حالت مجاز برود
        }
        if (!$this->acquire_send_lock($order_id)) {
            return;
        }
        try {
            $result = $this->send_to_all_chats($this->build_message($order));
            if (!empty($result['ok'])) {
                $order->update_meta_data('_wc_telegram_sent', 'yes');
                $order->delete_meta_data('_wc_telegram_pending');
                $order->delete_meta_data('_wc_telegram_needs_address');
                $order->delete_meta_data('_wc_telegram_expired');
                $order->delete_meta_data('_wc_telegram_attempts');
                $order->add_order_note('📍 پیام کامل سفارش به تلگرام ارسال شد.');
                $order->save_meta_data();
                $this->log('success', 'order', 'order_sent', sprintf('پیام کامل سفارش #%s به تلگرام ارسال شد.', $order->get_order_number()), ['chats' => array_keys($result['sent']), 'source' => 'pending_flush'], $order_id);
            } else {
                $this->handle_send_failure($order, $result['message']);
            }
        } finally {
            $this->release_send_lock($order_id);
        }
    }

    // خطای ارسال: تا ۵ بار با فاصله فزاینده (۱، ۳، ۹، ۲۷، ۸۱ دقیقه) خودکار دوباره تلاش می‌شود
    private function handle_send_failure($order, $error) {
        $attempt = (int) $order->get_meta('_wc_telegram_attempts') + 1;
        $order->update_meta_data('_wc_telegram_attempts', $attempt);
        $this->log($attempt > 5 ? 'error' : 'warning', 'order', 'order_send_failed',
            sprintf('ارسال سفارش #%s ناموفق بود (تلاش %d از ۵): %s', $order->get_order_number(), $attempt, $error),
            ['attempt' => $attempt, 'error' => $error], $order->get_id());
        if ($attempt <= 5) {
            if (wp_next_scheduled(self::SEND_ORDER_HOOK, [$order->get_id()])) {
                $order->save_meta_data();
                return; // تلاش مجدد از قبل برنامه‌ریزی شده
            }
            $delay = min(81 * 60, (int) (60 * pow(3, $attempt - 1)));
            wp_schedule_single_event(time() + $delay, self::SEND_ORDER_HOOK, [$order->get_id()]);
            $order->add_order_note(sprintf(
                '⚠️ ارسال به تلگرام ناموفق بود (تلاش %d از ۵): %s — به‌صورت خودکار دوباره تلاش می‌شود.',
                $attempt,
                $error
            ));
        } else {
            $order->add_order_note(sprintf(
                '⛔ ارسال به تلگرام پس از چند تلاش ناموفق ماند: %s — از اقدامات سفارش، «ارسال به تلگرام» را دستی بزنید.',
                $error
            ));
        }
        $order->save_meta_data();
    }

    /* ---------------- ساخت پیام ---------------- */

    public function build_message($order) {
        $s = $this->get_settings();
        $template = !empty($s['template']) ? $s['template'] : $this->default_template();

        // بلاک آیتم‌ها — عنوان در خط جدا می‌آید تا خط‌تیره‌های داخل
        // اسم محصول (مثل «قاب ... - A33») با جداکننده قاطی نشود:
        // 🔸 عنوان محصول
        // [عنوان متغیر]: [مقدار]
        // تعداد: X عدد | مبلغ: Y تومان
        $blocks = [];
        $link_tokens = [];   // عنوانِ هر آیتم بعد از escape به لینک تبدیل می‌شود
        $link_on = !isset($s['items_link']) || $s['items_link'] !== 'no'; // پیش‌فرض: فعال
        foreach ($order->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $qty  = $item->get_quantity();
            $line_total = $this->money($item->get_total() + $item->get_total_tax(), $order);

            // متغیرهای آیتم (مدل، رنگ، طرح و...) — مقدارهای خالی حذف می‌شوند
            $attrs = [];
            $formatted = $item->get_formatted_meta_data('_', true);
            if (!empty($formatted)) {
                foreach ($formatted as $m) {
                    $key = trim(wp_strip_all_tags(html_entity_decode((string) $m->display_key, ENT_QUOTES, 'UTF-8')));
                    $val = trim(wp_strip_all_tags(html_entity_decode((string) $m->display_value, ENT_QUOTES, 'UTF-8')));
                    if ($val === '') {
                        continue;
                    }
                    $attrs[] = ['k' => $key, 'v' => $val];
                }
            }

            // عنوان بدون تکرار متغیرها:
            // «قاب ماربل مات قهوه ای - iPhone 17 Pro Max» → «قاب ماربل مات قهوه ای» (مدل در خط خودش می‌آید)
            $original_name = trim((string) $item->get_name());
            $name = $this->item_base_name($item, array_map(function ($a) { return $a['v']; }, $attrs));
            $stripped = ($name !== $original_name);

            // عنوانِ آیتم لینک می‌شود به صفحهٔ همان محصول (برای متغیرها: لینک عمیق با انتخاب متغیر).
            // لینک بعد از escape جایگذاری می‌شود تا تگ <a> توسط htmlspecialchars خراب نشود.
            $name_token = $name;
            if ($link_on) {
                $url = $this->item_permalink($item);
                if ($url !== '') {
                    $token = '%%WC_TG_ITEM_LINK_' . count($link_tokens) . '%%';
                    $link_tokens[$token] = '<a href="' . esc_url($url) . '">' . $name . '</a>';
                    $name_token = $token;
                }
            }

            $block = '🔸 ' . $name_token;
            foreach ($attrs as $a) {
                // اگر پسوند از عنوان حذف نشد (فرمت غیرمنتظره)، حداقل خط تکراری چاپ نشود (همان منطق خود ووکامرس)
                if (!$stripped && function_exists('wc_is_attribute_in_product_name') && wc_is_attribute_in_product_name($a['v'], $name)) {
                    continue;
                }
                $block .= "\n" . ($a['k'] !== '' ? $a['k'] . ': ' : '') . $a['v'];
            }
            $block .= "\n" . 'تعداد: ' . $qty . ' عدد | مبلغ: ' . $line_total;
            $blocks[] = $block;
        }
        $items_block = $blocks ? implode("\n\n", $blocks) : '—';

        // آدرس و کد پستی: اول آدرس ارسال، اگر خالی بود آدرس صورتحساب
        $address = $this->full_address($order);
        $postcode = $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode();
        if (!$postcode) {
            $postcode = '—';
        }

        // پرداخت از کیف پول/اعتبار (اگر افزونهٔ کیف پول دارید)
        $amounts = $this->order_amounts($order);
        $wallet  = $amounts['wallet'];
        $paid    = $amounts['paid'];

        // تخفیف و کوپن‌ها
        $discount = (float) $order->get_total_discount() + (float) $order->get_discount_tax();
        $coupon_codes = method_exists($order, 'get_coupon_codes') ? (array) $order->get_coupon_codes() : (array) $order->get_used_coupons();
        $coupons = implode('، ', array_map('strtoupper', array_filter(array_map('trim', $coupon_codes))));
        if ($coupons === '' && $discount > 0) {
            $coupons = 'تخفیف';
        }

        $replacements = [
            '{order_number}'      => $order->get_order_number(),
            '{order_id}'          => $order->get_id(),
            '{order_date}'        => $order->get_date_created() ? $this->format_date(get_option('date_format') . ' ' . get_option('time_format'), $order->get_date_created()->getTimestamp()) : '',
            '{order_status}'      => wc_get_order_status_name($order->get_status()),
            '{order_total}'       => $this->money($amounts['grand'], $order),                          // مجموع سفارش = نقدی + کیف پول
            '{currency}'          => $order->get_currency(),
            '{subtotal}'          => $this->money($order->get_subtotal(), $order),
            '{discount_total}'    => $discount > 0 ? $this->money($discount, $order) : '',
            '{discount_number}'   => $discount > 0 ? $this->money_plain($discount) : '',
            '{coupons}'           => $coupons !== '' ? $coupons : '',
            '{shipping_total}'    => $this->money($order->get_shipping_total() + $order->get_shipping_tax(), $order),
            '{tax_total}'         => $this->money($order->get_total_tax(), $order),
            '{paid_amount}'       => $this->money($paid, $order),                                     // مبلغی که واقعاً پرداخت شده (بدون سهم کیف پول)
            '{wallet_amount}'     => $wallet > 0 ? $this->money($wallet, $order) : '',                 // سهم کیف پول (با واحد پول)
            '{wallet_number}'     => $wallet > 0 ? $this->money_plain($wallet) : '',                   // سهم کیف پول (فقط عدد)
            '{payment_method}'    => $order->get_payment_method_title(),
            '{shipping_method}'   => $this->shipping_methods($order),
            '{customer_name}'     => trim($order->get_formatted_billing_full_name()),
            '{customer_phone}'    => $this->fix_phone($order->get_billing_phone()),
            '{customer_email}'    => $order->get_billing_email(),
            '{customer_address}'  => $address,
            '{customer_postcode}' => $postcode,
            '{billing_address}'   => preg_replace('/<br\s*\/?>/i', ', ', $order->get_formatted_billing_address()),
            '{shipping_address}'  => preg_replace('/<br\s*\/?>/i', ', ', $order->get_formatted_shipping_address()),
            '{customer_note}'     => $order->get_customer_note() ? $order->get_customer_note() : '—',
            '{items}'             => $items_block,
            '{items_count}'       => $order->get_item_count(),
            '{site_name}'         => get_bloginfo('name'),
            '{order_url}'         => $order->get_edit_order_url(),
        ];

        // بلاک شرطیِ تخفیف: {if_discount} ... {/if_discount}
        if (strpos($template, '{if_discount}') !== false) {
            if ($discount > 0) {
                $template = preg_replace('/\{if_discount\}(.*?)\{\/if_discount\}/s', '$1', $template);
            } else {
                $template = preg_replace('/\{if_discount\}.*?\{\/if_discount\}/s', '', $template);
            }
        }

        // بلاک شرطیِ کیف پول: {if_wallet} ... {/if_wallet}
        // فقط وقتی سفارش بخشی از مبلغ را با کیف پول پرداخت کرده باشد نمایش داده می‌شود؛
        // در غیر این صورت کل بلاک همراه با خطِ خودش حذف می‌شود (ردیف خالی نمی‌ماند)
        if (strpos($template, '{if_wallet}') !== false) {
            if ($wallet > 0) {
                $template = preg_replace('/\{if_wallet\}(.*?)\{\/if_wallet\}/s', '$1', $template);
            } else {
                $template = preg_replace('/\{if_wallet\}.*?\{\/if_wallet\}/s', '', $template);
                // اگر حذفِ بلاک چند خط خالی پشت‌سرهم ساخت، به یک خط خالی تبدیل می‌شود
                $template = preg_replace("/\n{3,}/", "\n\n", $template);
            }
        }

        // مقادیر داینامیک برای حالت HTML تلگرام escape می‌شوند، تگ‌های قالب دست‌نخورده می‌مانند
        $escaped = [];
        foreach ($replacements as $key => $value) {
            if ($key === '{order_url}') {
                $escaped[$key] = esc_url($value);
            } else {
                // بلاک {items} چندخطی است — htmlspecialchars خط‌ها را حفظ می‌کند
                $escaped[$key] = htmlspecialchars(wp_strip_all_tags(html_entity_decode((string) $value)), ENT_QUOTES, 'UTF-8');
            }
        }

        $message = strtr($template, $escaped);

        // لینک عنوان آیتم‌ها بعد از escape اعمال می‌شود تا تگ <a> سالم بماند
        if (!empty($link_tokens)) {
            $message = strtr($message, $link_tokens);
        }

        // سقف تلگرام ۴۰۹۶ کاراکتر است — برش ایمن: داخل تگ/entity نمی‌برد و تگ‌های باز را می‌بندد
        $message = $this->html_safe_truncate($message, 4000);

        // اگر برشِ پیام نشانهٔ لینکی را ناتمام گذاشته بود، پاک می‌شود تا متنِ عجیب دیده نشود
        if (strpos($message, '%%WC_TG_ITEM_LINK_') !== false) {
            $message = preg_replace('/%%WC_TG_ITEM_LINK_\d+%%/', '', $message);
        }

        return $message;
    }


    /**
     * جمع واقعی سفارش پیش از کسر کیف پول:
     * آیتم‌ها (بعد از تخفیف) + مالیات + حمل‌ونقل + هزینه‌های مثبت.
     * بعضی افزونه‌های کیف پول سهم کیف پول را به‌صورت هزینهٔ منفی یا کاهش total ثبت می‌کنند؛
     * این عدد از آن‌ها مستقل است.
     */
    private function order_gross_total($order) {
        $gross = 0.0;
        foreach ($order->get_items() as $item) {
            $gross += (float) $item->get_total() + (float) $item->get_total_tax();
        }
        $gross += (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        foreach ($order->get_items('fee') as $fee) {
            $ft = (float) $fee->get_total();
            if ($ft > 0) {
                $gross += $ft + (float) $fee->get_total_tax();
            }
        }
        return round($gross, 2);
    }

    /**
     * مبالغ سفارش با احتساب کیف پول:
     *  - wallet : سهم کیف پول
     *  - grand  : مجموع سفارش (نقدی + کیف پول)
     *  - paid   : مبلغ نقدی/درگاه
     * اگر افزونهٔ کیف پول total را از قبل کم کرده باشد (total + wallet ≤ gross)، grand = total + wallet؛
     * وگرنه total خودش مجموع است و paid = total - wallet.
     */
    private function order_amounts($order) {
        $total  = (float) $order->get_total();
        $gross  = $this->order_gross_total($order);
        $wallet = $this->order_wallet_amount($order);
        if ($wallet > 0 && ($total + $wallet) <= ($gross + 1)) {
            $grand = $total + $wallet;
            $paid  = $total;
        } else {
            $grand = $total;
            $paid  = max(0, $total - $wallet);
        }
        return ['total' => $total, 'gross' => $gross, 'wallet' => $wallet, 'grand' => round($grand, 2), 'paid' => round($paid, 2)];
    }

    /**
     * مبلغی که از «کیف پول/اعتبار» مشتری برای این سفارش پرداخت شده (۰ اگر ندارد).
     *
     * ترتیب تشخیص:
     *   ۱) فیلتر wc_telegram_order_wallet_amount (برای هر افزونهٔ کیف پول/اعتباری)
     *   ۲) کلید متای تنظیم‌شده در صفحهٔ تنظیمات
     *   ۳) جست‌وجوی خودکار در متاهای سفارش (کلیدهای شبیه کیف پول/اعتبار/کردیت)
     *   ۴) ردیفِ هزینه (fee) ی منفی که نامش نشانِ کیف پول باشد
     */
    private function order_wallet_amount($order) {
        if (!$order instanceof WC_Order) {
            return 0.0;
        }
        $paid_total = (float) $order->get_total();
        $gross      = $this->order_gross_total($order);
        // سقف منطقی سهم کیف پول: جمع واقعی سفارش (نه totalِ کاهش‌یافته)
        $total = max($paid_total, $gross);
        // اختلاف total با جمع واقعی — اگر افزونهٔ کیف پول total را کم کرده باشد، همین سهم کیف پول است
        $gap   = round($gross - $paid_total, 2);

        // ۱) فیلتر — برای افزونه‌های خاص یا منطقِ سفارشی
        $filtered = apply_filters('wc_telegram_order_wallet_amount', null, $order);
        if ($filtered !== null && is_numeric($filtered)) {
            return $this->sanitize_wallet_amount((float) $filtered, $total);
        }

        // ۱.۵) هزینهٔ منفی با نام کیف پول/اعتبار — قابل‌اعتمادترین نشانه
        $match = '/(wallet|purse|credit|fund|deposit|cashback|کیف[_ -]?پول|اعتبار)/iu';
        foreach ($order->get_items('fee') as $fee) {
            $name = (string) $fee->get_name();
            if ($name !== '' && (float) $fee->get_total() < 0 && preg_match($match, $name)) {
                $val = $this->sanitize_wallet_amount(abs((float) $fee->get_total()), $total);
                if ($val > 0) {
                    return $val;
                }
            }
        }

        // ۲) کلید متای تنظیم‌شده توسط مدیر
        $s   = $this->get_settings();
        $key = trim((string) (isset($s['wallet_meta_key']) ? $s['wallet_meta_key'] : ''));
        if ($key !== '') {
            $v = $order->get_meta($key);
            if (is_numeric($v)) {
                $amount = $this->sanitize_wallet_amount(abs((float) $v), $total);
                if ($amount > 0) {
                    return $amount;
                }
            }
        }

        // ۳) اختلاف total با جمع واقعی: ووکامرس total را بعد از کسر کیف پول ذخیره می‌کند.
        //    هزینه‌های منفیِ غیرکیف‌پولی (مثلاً تخفیف دستی) از این اختلاف کم می‌شوند؛ باقی‌مانده = سهم کیف پول.
        if ($gap > 0) {
            $other_neg = 0.0;
            foreach ($order->get_items('fee') as $fee) {
                $ft = (float) $fee->get_total();
                if ($ft < 0 && !preg_match($match, (string) $fee->get_name())) {
                    $other_neg += abs($ft) + abs((float) $fee->get_total_tax());
                }
            }
            $by_gap = $this->sanitize_wallet_amount($gap - $other_neg, $total);
            if ($by_gap > 0) {
                return $by_gap;
            }
        }

        // ۴) جست‌وجوی خودکار در متاهای سفارش
        //    کلیدهایی که «موجودی/گزارش/شناسه» هستند کنار گذاشته می‌شوند تا اشتباه گرفته نشوند
        $skip  = '/(balance|log|note|status|user|customer|email|phone|date|time|restock|refund|transaction|_id$)/i';
        $strong= '/(amount|used|paid|deduct|partial|spent|consumed)/i';
        $candidates = [];
        foreach ($order->get_meta_data() as $m) {
            $k = is_object($m) ? (string) $m->key : (isset($m['key']) ? (string) $m['key'] : '');
            $v = is_object($m) ? $m->value : (isset($m['value']) ? $m['value'] : '');
            if ($k === '' || !is_numeric($v)) {
                continue;
            }
            if (preg_match($skip, $k) || !preg_match($match, $k)) {
                continue;
            }
            $val = $this->sanitize_wallet_amount(abs((float) $v), $total);
            if ($val > 0) {
                $candidates[] = ['key' => $k, 'value' => $val, 'strong' => preg_match($strong, $k) ? 1 : 0];
            }
        }
        if (!empty($candidates)) {
            // اولویت اول: کاندیدایی که دقیقاً برابر اختلاف total و جمع واقعی است (سهم کسرشدهٔ کیف پول)
            if ($gap > 0) {
                foreach ($candidates as $c) {
                    if (abs($c['value'] - $gap) <= 1) {
                        return (float) $c['value'];
                    }
                }
            }
            // بعد: کلیدهایی که صراحتاً «مبلغ» هستند
            usort($candidates, function ($a, $b) {
                if ($a['strong'] === $b['strong']) {
                    return ($a['value'] < $b['value']) ? 1 : -1;
                }
                return ($a['strong'] < $b['strong']) ? 1 : -1;
            });
            return (float) $candidates[0]['value'];
        }

        // ۵) ردیف هزینهٔ مثبت با نام کیف پول (بعضی افزونه‌ها این‌طوری ثبت می‌کنند)
        foreach ($order->get_items('fee') as $fee) {
            $name = (string) $fee->get_name();
            if ($name !== '' && preg_match($match, $name)) {
                $val = $this->sanitize_wallet_amount(abs((float) $fee->get_total()), $total);
                if ($val > 0) {
                    return $val;
                }
            }
        }

        return 0.0;
    }

    // مبلغ باید مثبت، بزرگ‌تر از صفر و حداکثر به اندازهٔ کل سفارش باشد
    private function sanitize_wallet_amount($amount, $total) {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return 0.0;
        }
        if ($total > 0 && $amount > $total) {
            return 0.0; // غیرمنطقی (مثلاً موجودی کیف پول) — نادیده گرفته می‌شود
        }
        return $amount;
    }

    // لینک صفحهٔ محصولِ یک آیتم سفارش (برای متغیرها: لینک عمیقِ همان متغیر)
    private function item_permalink($item) {
        if (!$item instanceof WC_Order_Item_Product) {
            return '';
        }
        $product = $item->get_product();
        if ($product && method_exists($product, 'get_permalink')) {
            $url = $product->get_permalink();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        $pid = (int) $item->get_product_id();
        if ($pid > 0 && function_exists('get_permalink')) {
            $url = get_permalink($pid);
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }
        return '';
    }

    // عنوان «پایه» آیتم بدون پسوند متغیرها.
    // ووکامرس برای متغیرها عنوان را به شکل «نام محصول - مقدار۱, مقدار۲» ذخیره می‌کند؛
    // چون همان مقادیر در خط‌های «مدل/رنگ/طرح» جدا می‌آیند، از عنوان حذف می‌شوند.
    private function item_base_name($item, $attr_values) {
        $name = trim((string) $item->get_name());
        if ($name === '') {
            return $name;
        }

        // ۱) مطمئن‌ترین راه: نام محصول مادر (اگر هنوز موجود است)
        if ($item instanceof WC_Order_Item_Product && $item->get_variation_id()) {
            $product = $item->get_product();
            $parent  = null;
            if ($product && $product->is_type('variation')) {
                $parent = wc_get_product($product->get_parent_id());
            } elseif ($item->get_product_id()) {
                $parent = wc_get_product($item->get_product_id());
            }
            if ($parent) {
                $parent_name = trim((string) $parent->get_name());
                $plen = strlen($parent_name);
                // عنوان آیتم باید دقیقاً «نام مادر + جداکننده» باشد؛ اگر مدیر عنوان را عوض کرده، دست نمی‌زنیم
                // (توابع بایتی برای UTF-8 اینجا امن‌اند چون جداکننده ASCII است و مقایسه روی کل رشته انجام می‌شود)
                if ($parent_name !== '' && $plen < strlen($name)
                    && stripos($name, $parent_name) === 0
                    && trim(substr($name, $plen, 3)) === '-') {
                    return $parent_name;
                }
            }
        }

        // ۲) حالت جایگزین (محصول حذف‌شده یا تغییر نام داده): پسوند « - مقدار۱, مقدار۲» را
        //    فقط وقتی حذف کن که دقیقاً از مقادیر متغیرها ساخته شده باشد؛ خط‌تیره‌ی
        //    داخل اسم واقعی محصول (مثل «قاب - سری A») دست نمی‌خورد.
        $attr_values = array_values(array_filter(array_map(function ($v) {
            return $this->fold_case(trim((string) $v));
        }, (array) $attr_values), 'strlen'));
        if (empty($attr_values)) {
            return $name;
        }
        $pos = strrpos($name, ' - ');
        while ($pos !== false && $pos > 0) {
            $suffix = substr($name, $pos + 3);
            $parts  = array_filter(array_map(function ($p) {
                return $this->fold_case(trim($p));
            }, explode(',', $suffix)), 'strlen');
            $all_match = !empty($parts);
            foreach ($parts as $p) {
                if (!in_array($p, $attr_values, true)) {
                    $all_match = false;
                    break;
                }
            }
            if ($all_match) {
                return trim(substr($name, 0, $pos));
            }
            // شاید مقدار متغیر خودش « - » داشته باشد؛ خط‌تیره‌ی قبلی را امتحان کن
            $pos = strrpos(substr($name, 0, $pos), ' - ');
        }
        return $name;
    }

    // حروف کوچک برای مقایسه (بدون وابستگی اجباری به mbstring)
    private function fold_case($s) {
        $s = (string) $s;
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    private function fix_phone($phone) {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '—';
        }
        // تبدیل اعداد فارسی/عربی به انگلیسی
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $phone = str_replace($fa, $en, $phone);
        $phone = preg_replace('/[\s\-]/', '', $phone);
        // اگر مشتری صفر اول موبایل را جا انداخته بود (مثل 9171687352) اضافه کن
        if (preg_match('/^9\d{9}$/', $phone)) {
            $phone = '0' . $phone;
        }
        return $phone;
    }

    private function full_address($order) {
        // ترتیب ایرانی: استان، شهر، خیابان، پلاک/واحد
        // هر فیلد: اول از آدرس ارسال، اگر خالی یا کد عددی بود از صورتحساب
        $addr1 = $this->first_text([$order->get_shipping_address_1(), $order->get_billing_address_1()]);
        $addr2 = $this->first_text([$order->get_shipping_address_2(), $order->get_billing_address_2()]);

        $city = $this->first_text([$order->get_shipping_city(), $order->get_billing_city()], true);

        // استان: کد (مثل TEH) به اسم تبدیل می‌شود؛ مقدار صرفاً عددی نادیده گرفته می‌شود
        $state_raw = trim((string) $order->get_shipping_state());
        $country   = $order->get_shipping_country();
        if ($state_raw === '' || is_numeric($state_raw)) {
            $state_raw = trim((string) $order->get_billing_state());
            $country   = $order->get_billing_country();
        }
        $state = $this->state_label($state_raw, $country);
        if (is_numeric($state)) {
            $state = '';
        }

        // هوک برای نگاشت کد شهر/استان به اسم (افزونه‌های انتخاب شهر ایران)
        $city  = apply_filters('wc_telegram_city_name', $city, $order);
        $state = apply_filters('wc_telegram_state_name', $state, $order);

        $parts = [];
        foreach ([$state, $city, $addr1, $addr2] as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $parts[] = $p;
            }
        }

        if (!empty($parts)) {
            return implode('، ', $parts);
        }
        // حالت جایگزین: آدرس فرمت‌شده ووکامرس
        $formatted = $order->get_formatted_shipping_address() ? $order->get_formatted_shipping_address() : $order->get_formatted_billing_address();
        $plain = trim(wp_strip_all_tags(preg_replace('/<br\s*\/?>/i', ', ', (string) $formatted)));
        return $plain ? $plain : '—';
    }

    // اولین مقدار متنی غیرخالی؛ اگر $skip_numeric درست باشد مقادیر صرفاً عددی (کدها) رد می‌شوند
    private function first_text($values, $skip_numeric = false) {
        foreach ((array) $values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            if ($skip_numeric && is_numeric($v)) {
                continue;
            }
            return $v;
        }
        return '';
    }

    private function state_label($state, $country) {
        $state = trim((string) $state);
        if ($state === '') {
            return '';
        }
        if (function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states($country);
            if (is_array($states) && isset($states[$state])) {
                return $states[$state];
            }
        }
        return $state;
    }

    private function shipping_methods($order) {
        $methods = [];
        foreach ($order->get_shipping_methods() as $m) {
            $methods[] = $m->get_method_title();
        }
        return $methods ? implode('، ', $methods) : '—';
    }

    private function money($amount, $order = null) {
        // واحد پول از تنظیمات («واحد پول در پیام‌ها») خوانده می‌شود؛ خالی = کد ارز ووکامرس
        $s = $this->get_settings();
        $label = isset($s['currency_label']) ? trim((string) $s['currency_label']) : '';
        if ($label === '' && function_exists('get_woocommerce_currency')) {
            $label = get_woocommerce_currency();
        }
        // مبالغ اعشار ندارند — به عدد صحیح گرد می‌شود
        return $this->money_plain($amount) . ($label !== '' ? ' ' . $label : '');
    }

    // فقط عددِ مبلغ (بدون واحد پول) — برای ترکیب‌های دلخواه در قالب
    private function money_plain($amount) {
        return number_format(round((float) $amount), 0, '.', ',');
    }

    /* ---------------- گزارش روزانه فروش ---------------- */

    public function on_activate() {
        // نکته: نقطهٔ شروعِ گزارش عمداً تنظیم نمی‌شود؛ اولین گزارشِ خودکار
        // ۲۴ ساعت گذشته را می‌گیرد (اگر نقطه‌ای نباشد یا قدیمی باشد، همان بازهٔ پشتیبان است)
        delete_option(self::LAST_REPORT_OPTION);
        $this->schedule_daily_report();

        // جدول لاگ + رویداد نگهداری (پاک‌سازی لاگ‌های قدیمی)
        $this->install_log_table();
        if (!wp_next_scheduled(self::MAINT_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::MAINT_HOOK);
        }
        if (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'wc_telegram_15min', self::SWEEP_HOOK);
        }
        $this->log('info', 'system', 'activated', 'افزونه فعال شد (نسخه ' . WC_TELEGRAM_ORDERS_VERSION . ').', [], 0);
    }

    public function on_deactivate() {
        $this->clear_daily_schedule();
        wp_clear_scheduled_hook(self::MAINT_HOOK);
        wp_clear_scheduled_hook(self::SWEEP_HOOK);
        $this->log('info', 'system', 'deactivated', 'افزونه غیرفعال شد.', [], 0);
    }

    // اگر جدول لاگ به هر دلیلی (آپدیت دستی، انتقال سایت) نساخته شده بود، در اولین نیاز ساخته می‌شود
    private function log_table_ready() {
        global $wpdb;
        if ($this->log_table_ready !== null) {
            return $this->log_table_ready;
        }
        $table = $this->log_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $this->log_table_ready = true;
            return true;
        }
        $this->install_log_table();
        $this->log_table_ready = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        return $this->log_table_ready;
    }

    // اگر به هر دلیلی زمان‌بندی پاک یا چندبار ثبت شده بود، آن را به یک رویداد روزانه برگردان.
    public function maybe_schedule_daily() {
        $s = $this->get_settings();
        $events = $this->daily_report_events();
        if ($s['enabled'] !== 'yes' || $s['daily_enabled'] !== 'yes') {
            if (!empty($events)) {
                $this->clear_daily_schedule();
            }
            return;
        }

        $valid_schedule = count($events) === 1
            && empty($events[0]['args'])
            && isset($events[0]['schedule'])
            && $events[0]['schedule'] === 'daily';
        if (!$valid_schedule) {
            $this->clear_daily_schedule();
            $this->schedule_daily_report();
        }

        // نگهداری لاگ مستقل از گزارش روزانه است (حتی اگر گزارش غیرفعال باشد لاگ پاک‌سازی می‌شود)
        if (!wp_next_scheduled(self::MAINT_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::MAINT_HOOK);
        }
        if (!wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'wc_telegram_15min', self::SWEEP_HOOK);
        }
    }

    public function reschedule_on_settings($old_value, $new_value) {
        $this->clear_daily_schedule();
        if (!empty($new_value['enabled']) && $new_value['enabled'] === 'yes'
            && !empty($new_value['daily_enabled']) && $new_value['daily_enabled'] === 'yes') {
            $ts = $this->next_run_timestamp(isset($new_value['daily_time']) ? $new_value['daily_time'] : '23:59');
            if ($ts) {
                wp_schedule_event($ts, 'daily', self::CRON_HOOK);
            }
        }
    }

    public function schedule_daily_report() {
        $s = $this->get_settings();
        $this->clear_daily_schedule();
        if ($s['enabled'] !== 'yes' || $s['daily_enabled'] !== 'yes') {
            return false;
        }
        $ts = $this->next_run_timestamp(isset($s['daily_time']) ? $s['daily_time'] : '23:59');
        if (!$ts) {
            return false;
        }
        return (bool) wp_schedule_event($ts, 'daily', self::CRON_HOOK);
    }

    private function daily_report_events() {
        $crons = function_exists('_get_cron_array') ? _get_cron_array() : [];
        if (!is_array($crons)) {
            return [];
        }

        $events = [];
        foreach ($crons as $timestamp => $hooks) {
            if (!isset($hooks[self::CRON_HOOK]) || !is_array($hooks[self::CRON_HOOK])) {
                continue;
            }
            foreach ($hooks[self::CRON_HOOK] as $event) {
                $events[] = [
                    'timestamp' => (int) $timestamp,
                    'schedule'  => isset($event['schedule']) ? (string) $event['schedule'] : '',
                    'args'      => isset($event['args']) && is_array($event['args']) ? $event['args'] : [],
                ];
            }
        }
        return $events;
    }

    private function clear_daily_schedule() {
        foreach ($this->daily_report_events() as $event) {
            wp_unschedule_event($event['timestamp'], self::CRON_HOOK, $event['args']);
        }
    }

    // منطقه زمانی مخصوص همین پلاگین (بدون دست‌کاری تنظیمات وردپرس)
    private function plugin_timezone() {
        $s = $this->get_settings();
        $id = (!empty($s['timezone']) && $s['timezone'] === 'Asia/Tehran') ? 'Asia/Tehran' : null;
        try {
            if ($id) {
                return new \DateTimeZone($id);
            }
            return wp_timezone();
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    // تاریخ با منطقه زمانی پلاگین، ولی همچنان سازگار با افزونه‌های تاریخ شمسی
    // (همان date_i18n صدا زده می‌شود — پس خروجی شمسی حفظ می‌شود)
    //
    // نکته مهم: date_i18n وقتی تایم‌استمپ می‌گیرد، انتظار «تایم‌استمپ + آفست منطقه زمانی» را دارد
    // (همان قراردادی که ووکامرس در WC_DateTime::getOffsetTimestamp استفاده می‌کند).
    // در نسخه‌های قبل تایم‌استمپ خام UTC داده می‌شد؛ نتیجه: ساعت UTC (۳:۳۰ عقب‌تر از تهران)
    // و چون نیمه‌شب تهران = ۲۰:۳۰ UTC روز قبل است، تاریخ گزارش هم یک روز عقب می‌افتاد.
    private function plugin_date($format, $timestamp) {
        $timestamp = (int) $timestamp;
        try {
            $tz     = $this->plugin_timezone();
            $offset = $tz->getOffset(new \DateTime('@' . $timestamp));
        } catch (\Exception $e) {
            $offset = (int) round((float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
        }
        return date_i18n($format, $timestamp + $offset);
    }

    /* ---------------- تاریخ شمسی (داخلی) ---------------- */

    /**
     * فرمت تاریخ بر اساس تنظیمات: شمسی (پیش‌فرض) یا میلادی.
     * مسیر شمسی مستقیماً از تاریخ میلادی محاسبه می‌شود و به date_i18n دست نمی‌زند؛
     * پس اگر افزونهٔ شمسیِ دیگری هم فعال باشد، تاریخ دوبار تبدیل نمی‌شود.
     */
    public function format_date($format, $timestamp) {
        $s = $this->get_settings();
        if (empty($s['jalali_date']) || $s['jalali_date'] !== 'yes') {
            return $this->plugin_date($format, $timestamp);
        }
        $digits = !isset($s['jalali_digits']) || $s['jalali_digits'] === 'yes';
        return $this->jalali_date($format, $timestamp, $digits);
    }

    // تاریخ شمسی با قالب date() مانند — توکن‌های پشتیبانی‌شده: Y y m n d j F M H G i s a A
    private function jalali_date($format, $timestamp, $persian_digits = true) {
        $timestamp = (int) $timestamp;
        try {
            $tz     = $this->plugin_timezone();
            $offset = $tz->getOffset(new \DateTime('@' . $timestamp));
        } catch (\Exception $e) {
            $offset = (int) round((float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS);
        }
        $ts = $timestamp + $offset;
        list($jy, $jm, $jd) = $this->gregorian_to_jalali((int) gmdate('Y', $ts), (int) gmdate('n', $ts), (int) gmdate('j', $ts));

        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        $map = [
            'Y' => str_pad((string) $jy, 4, '0', STR_PAD_LEFT),
            'y' => str_pad((string) ($jy % 100), 2, '0', STR_PAD_LEFT),
            'm' => str_pad((string) $jm, 2, '0', STR_PAD_LEFT),
            'n' => (string) $jm,
            'd' => str_pad((string) $jd, 2, '0', STR_PAD_LEFT),
            'j' => (string) $jd,
            'F' => $months[$jm - 1],
            'M' => $months[$jm - 1],
        ];

        $format = (string) $format;
        $len    = strlen($format);
        $out    = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $format[$i];
            if ($ch === '\\') { // کاراکتر فرار
                $i++;
                $out .= ($i < $len) ? $format[$i] : '';
                continue;
            }
            if (isset($map[$ch])) {
                $out .= $map[$ch];
                continue;
            }
            // بقیهٔ توکن‌ها (ساعت/دقیقه/ثانیه و…) از همان زمان محلی گرفته می‌شوند
            $out .= (strpos('HhGisauAlLwWztTeIOPZcrUD', $ch) !== false) ? gmdate($ch, $ts) : $ch;
        }
        return $persian_digits ? $this->to_persian_digits($out) : $out;
    }

    // تبدیل میلادی به شمسی (الگوریتم استاندارد jdf — دقیق برای سال‌های ۱۲۰۶ تا ۱۶۳۳ شمسی)
    private function gregorian_to_jalali($gy, $gm, $gd) {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy  = (int) $gy;
        $gm  = max(1, min(12, (int) $gm));
        $gd  = (int) $gd;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100) + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * (int) ($days / 12053));
        $days %= 12053;
        $jy += 4 * (int) ($days / 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + (int) ($days / 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + (int) (($days - 186) / 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    private function to_persian_digits($text) {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            (string) $text
        );
    }

    // «۳ ساعت و ۲۵ دقیقه» — برای نمایش فاصلهٔ میانگین بین سفارش‌ها
    private function human_duration($seconds) {
        $seconds = max(0, (int) $seconds);
        $parts = [];
        $d = (int) ($seconds / DAY_IN_SECONDS);
        $h = (int) (($seconds % DAY_IN_SECONDS) / HOUR_IN_SECONDS);
        $m = (int) (($seconds % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);
        if ($d > 0) {
            $parts[] = $d . ' روز';
        }
        if ($h > 0) {
            $parts[] = $h . ' ساعت';
        }
        if ($m > 0 || empty($parts)) {
            $parts[] = $m . ' دقیقه';
        }
        return implode(' و ', $parts);
    }

    // نزدیک‌ترین ساعت مشخص (به وقت منطقه زمانی پلاگین) — اگر امروز گذشته باشد، فردا
    private function next_run_timestamp($time) {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $time), $m)) {
            return false;
        }
        $h   = min(23, (int) $m[1]);
        $min = min(59, (int) $m[2]);
        $tz  = $this->plugin_timezone();
        $now = new \DateTime('now', $tz);
        $run = new \DateTime($now->format('Y-m-d') . sprintf(' %02d:%02d:00', $h, $min), $tz);
        if ($run <= $now) {
            $run->modify('+1 day');
        }
        return $run->getTimestamp();
    }

    public function handle_daily_now() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_daily_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        // گزارش دستی: کاملاً مستقل از گزارش خودکار شبانه — بازه‌اش را مدیر انتخاب می‌کند،
        // نقطهٔ پایانِ گزارش خودکار را جابه‌جا نمی‌کند و سنجاق آن را هم عوض نمی‌کند.
        $range = isset($_POST['range']) ? sanitize_key(wp_unslash($_POST['range'])) : 'today';
        $end   = time();
        if ($range === '24h') {
            $start = $end - DAY_IN_SECONDS;
            $label = '۲۴ ساعت گذشته';
        } elseif ($range === '7d') {
            $start = $end - 7 * DAY_IN_SECONDS;
            $label = '۷ روز گذشته';
        } else {
            $mid   = new \DateTime('today', $this->plugin_timezone());
            $start = $mid->getTimestamp();
            $label = 'امروز (از ابتدای روز)';
        }

        $result = $this->send_daily_report($start, $end, true);
        if (!empty($result['ok'])) {
            $extra = isset($result['message']) ? (string) $result['message'] : '';
            $result['message'] = sprintf('📊 گزارش «%s» ارسال شد؛ این گزارش روی گزارش خودکار شبانه اثری ندارد.', $label)
                // اگر نکتهٔ مهمی در پیام بود (مثل خالی بودن بازه) همراهِ پیام نمایش داده می‌شود
                . (($extra !== '' && (strpos($extra, 'خالی') !== false || strpos($extra, 'سفارشی ثبت نشده') !== false)) ? ' ' . $extra : '');
        }
        set_transient('wc_telegram_test_result', $result, 60);
        $this->redirect_to_tab();
    }

    public function handle_debug() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_debug_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        wp_safe_redirect(admin_url('admin.php?page=wc-telegram-orders&wc_telegram_debug_order=' . $id));
        exit;
    }

    // جدول مقادیر خام آدرس یک سفارش — برای پیدا کردن فیلد گمشده (مثل شهر)
    public function maybe_render_debug() {
        if (empty($_GET['wc_telegram_debug_order'])) {
            return;
        }
        $id = absint($_GET['wc_telegram_debug_order']);
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order) {
            echo '<div class="tisa-notice tisa-notice--danger wcto-notice">سفارش با این شناسه پیدا نشد.</div>';
            return;
        }
        $rows = [];
        $rows['نسخه پلاگین'] = WC_TELEGRAM_ORDERS_VERSION;
        $rows['صورتحساب: خیابان ۱'] = $order->get_billing_address_1();
        $rows['صورتحساب: خیابان ۲'] = $order->get_billing_address_2();
        $rows['صورتحساب: شهر'] = $order->get_billing_city();
        $rows['صورتحساب: استان'] = $order->get_billing_state();
        $rows['صورتحساب: کشور'] = $order->get_billing_country();
        $rows['صورتحساب: کد پستی'] = $order->get_billing_postcode();
        $rows['ارسال: خیابان ۱'] = $order->get_shipping_address_1();
        $rows['ارسال: خیابان ۲'] = $order->get_shipping_address_2();
        $rows['ارسال: شهر'] = $order->get_shipping_city();
        $rows['ارسال: استان'] = $order->get_shipping_state();
        $rows['ارسال: کشور'] = $order->get_shipping_country();
        $rows['ارسال: کد پستی'] = $order->get_shipping_postcode();
        $rows['آدرس نهایی در پیام تلگرام'] = $this->full_address($order);

        // متاهای مشکوک به آدرس (افزونه‌های انتخاب شهر/استان معمولاً اینجا ذخیره می‌کنند)
        foreach ($order->get_meta_data() as $m) {
            $k = is_object($m) ? $m->key : (isset($m['key']) ? $m['key'] : '');
            $v = is_object($m) ? $m->value : (isset($m['value']) ? $m['value'] : '');
            if ($k === '' || $k[0] === '_') {
                // متاهای خصوصی هم بررسی شوند چون شهر/استان گاهی آنجاست
            }
            if (preg_match('/city|state|address|country|postcode|zip|town|province|شهر|استان|خیابان|محله/i', (string) $k)) {
                if (is_array($v) || is_object($v)) {
                    $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                }
                $v = (string) $v;
                if (mb_strlen($v) > 300) {
                    $v = mb_substr($v, 0, 300) . '…';
                }
                $rows['meta: ' . $k] = $v;
            }
        }

        echo '<section class="wcto-card wcto-debug"><div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>نتیجه عیب‌یابی سفارش #' . esc_html($order->get_order_number()) . '</h2></div></div><div class="wcto-card-body">';
        echo '<p>از این جدول اسکرین‌شات بگیرید یا متن آن را برای پشتیبانی بفرستید:</p>';
        echo '<table class="tisa-table wcto-kv"><tbody>';
        foreach ($rows as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th>'
               . '<td dir="auto">' . ($v === '' ? '<i class="wcto-empty-val">(خالی)</i>' : esc_html($v)) . '</td></tr>';
        }
        echo '</tbody></table>';

        // مبلغ کیف پولِ تشخیص‌داده‌شده — برای اطمینان از اینکه خط کیف پول در پیام درست چاپ می‌شود
        $amounts = $this->order_amounts($order);
        $wallet  = $amounts['wallet'];
        echo '<h3 class="wcto-sub">کیف پول / اعتبار</h3>';
        echo '<table class="tisa-table wcto-kv"><tbody>';
        echo '<tr><th>total ووکامرس (get_total)</th><td dir="auto">' . esc_html($this->money($amounts['total'], $order)) . '</td></tr>';
        echo '<tr><th>جمع واقعی (آیتم‌ها + حمل + هزینه‌ها)</th><td dir="auto">' . esc_html($this->money($amounts['gross'], $order)) . '</td></tr>';
        echo '<tr><th>مبلغ کیف پولِ تشخیص‌داده‌شده</th><td dir="auto">'
           . ($wallet > 0 ? '<b>' . esc_html($this->money($wallet, $order)) . '</b>' : '<i class="wcto-empty-val">تشخیص داده نشد (۰)</i>') . '</td></tr>';
        echo '<tr><th>مجموع سفارش در پیام ({order_total})</th><td dir="auto"><b>' . esc_html($this->money($amounts['grand'], $order)) . '</b></td></tr>';
        echo '<tr><th>پرداختی نقدی در پیام ({paid_amount})</th><td dir="auto"><b>' . esc_html($this->money($amounts['paid'], $order)) . '</b></td></tr>';
        echo '<tr><th>کلید متای تنظیم‌شده</th><td dir="auto">'
           . (isset($s['wallet_meta_key']) && $s['wallet_meta_key'] !== '' ? '<code dir="ltr">' . esc_html($s['wallet_meta_key']) . '</code>' : '<i class="wcto-empty-val">(خالی — تشخیص خودکار)</i>') . '</td></tr>';
        echo '</tbody></table>';

        // همهٔ متاهای سفارش — برای پیدا کردن کلیدِ کیف پول در افزونه‌های مختلف
        echo '<details class="wcto-details"><summary>نمایش همهٔ متاهای این سفارش (برای پیدا کردن کلیدِ کیف پول)</summary>';
        echo '<table class="tisa-table wcto-kv"><thead><tr><th>کلید متا</th><th>مقدار</th></tr></thead><tbody>';
        foreach ($order->get_meta_data() as $m) {
            $k = is_object($m) ? (string) $m->key : (isset($m['key']) ? (string) $m['key'] : '');
            $v = is_object($m) ? $m->value : (isset($m['value']) ? $m['value'] : '');
            if ($k === '') {
                continue;
            }
            if (is_array($v) || is_object($v)) {
                $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $v = (string) $v;
            if (mb_strlen($v) > 300) {
                $v = mb_substr($v, 0, 300) . '…';
            }
            echo '<tr><th dir="ltr">' . esc_html($k) . '</th>'
               . '<td dir="auto">' . ($v === '' ? '<i class="wcto-empty-val">(خالی)</i>' : esc_html($v)) . '</td></tr>';
        }
        echo '</tbody></table></details></div></section>';
    }

    // گزارش فروش یک بازه زمانی.
    // اجرای خودکار (کرون): بازه = از پایان گزارش قبلی تا همین لحظه — حتی اگر کرون با تأخیر
    // اجرا شود هیچ سفارشی بین دو گزارش جا نمی‌ماند (قبلاً بازه «امروز» بود و سفارش‌های
    // دقیقه آخر روز یا روزی که کرون دیر اجرا می‌شد کاملاً از دست می‌رفتند).
    // اجرای دستی: بازه مشخص از طریق $day_start/$day_end.
    /**
     * گزارش خودکار را در کل سایت به‌صورت اتمیک قفل می‌کند؛ گزارش دستی عمداً مستقل می‌ماند.
     * قفل از ارسال موازیِ چند رویداد WP-Cron جلوگیری می‌کند و پایان آخرین گزارش موفق،
     * تکرارهای پشت‌سرهم همان اجرا را هم تا چند دقیقه بعد بی‌اثر می‌کند.
     */
    public function send_daily_report($day_start = null, $day_end = null, $manual = false) {
        if (!function_exists('wc_get_orders')) {
            return ['ok' => false, 'message' => 'ووکامرس فعال نیست.'];
        }
        if ($manual) {
            return $this->send_daily_report_unlocked($day_start, $day_end, true);
        }

        $lock_token = $this->acquire_daily_report_lock();
        if ($lock_token === false) {
            $this->log('info', 'daily', 'duplicate_blocked',
                'اجرای تکراری گزارش روزانه نادیده گرفته شد؛ یک گزارش دیگر در حال ارسال است.',
                ['reason' => 'lock_held'], 0);
            return [
                'ok'        => true,
                'duplicate' => true,
                'sent'      => [],
                'message'   => 'گزارش خودکار دیگری در حال ارسال است؛ اجرای تکراری نادیده گرفته شد.',
            ];
        }

        try {
            $now  = time();
            $last = (int) get_option(self::LAST_REPORT_OPTION, 0);
            if ($last > 0 && abs($now - $last) < self::DAILY_REPORT_DUPLICATE_WINDOW) {
                $this->log('info', 'daily', 'duplicate_blocked',
                    'اجرای تکراری گزارش روزانه نادیده گرفته شد؛ گزارش اخیر قبلاً با موفقیت ارسال شده است.',
                    ['reason' => 'recent_success', 'last_report_end' => $last], 0);
                return [
                    'ok'        => true,
                    'duplicate' => true,
                    'sent'      => [],
                    'message'   => 'گزارش خودکار این نوبت قبلاً ارسال شده است؛ اجرای تکراری نادیده گرفته شد.',
                ];
            }

            return $this->send_daily_report_unlocked($day_start, $day_end, false);
        } finally {
            $this->release_daily_report_lock($lock_token);
        }
    }

    /** قفل یکتا در جدول options؛ برخلاف transient، add_option در دیتابیس اتمیک است. */
    private function acquire_daily_report_lock() {
        $now   = time();
        $token = wp_generate_uuid4();
        $lock  = [
            'token'   => $token,
            'expires' => $now + self::DAILY_REPORT_LOCK_TTL,
        ];

        if (add_option(self::DAILY_REPORT_LOCK_OPTION, $lock, '', 'no')) {
            return $token;
        }

        $existing = get_option(self::DAILY_REPORT_LOCK_OPTION, false);
        if (is_array($existing) && isset($existing['expires']) && (int) $existing['expires'] > $now) {
            return false;
        }

        // قفلِ مانده از توقف غیرعادی: با compare-and-swap جایگزینش کن تا دو فرایند
        // هم‌زمان نتوانند یک قفلِ منقضی را هم‌زمان تصاحب کنند.
        global $wpdb;
        if (is_array($existing) && isset($wpdb->options) && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'query')) {
            $query = $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                maybe_serialize($lock),
                self::DAILY_REPORT_LOCK_OPTION,
                maybe_serialize($existing)
            );
            if (1 !== (int) $wpdb->query($query)) {
                return false;
            }
            wp_cache_delete(self::DAILY_REPORT_LOCK_OPTION, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            return $token;
        }

        // مسیر سازگار برای محیط‌های تست یا دیتابیس‌های mock شده.
        delete_option(self::DAILY_REPORT_LOCK_OPTION);
        return add_option(self::DAILY_REPORT_LOCK_OPTION, $lock, '', 'no') ? $token : false;
    }

    private function release_daily_report_lock($token) {
        $lock = get_option(self::DAILY_REPORT_LOCK_OPTION, false);
        if (is_array($lock) && isset($lock['token']) && hash_equals((string) $lock['token'], (string) $token)) {
            delete_option(self::DAILY_REPORT_LOCK_OPTION);
        }
    }

    private function send_daily_report_unlocked($day_start = null, $day_end = null, $manual = false) {
        if (!function_exists('wc_get_orders')) {
            return ['ok' => false, 'message' => 'ووکامرس فعال نیست.'];
        }
        $tz = $this->plugin_timezone();
        if ($day_start === null) {
            // بازهٔ گزارشِ خودکار: از پایانِ «گزارش خودکار قبلی» تا همین لحظه.
            // گزارش‌های دستی این نقطه را جابه‌جا نمی‌کنند (تغییرِ ۱.۱۰.۲) — پس هر چند بار
            // هم که گزارش دستی بگیرید، گزارش شبانه کلِ ۲۴ ساعت را پوشش می‌دهد.
            $last = (int) get_option(self::LAST_REPORT_OPTION, 0);
            // اگر نقطه‌ای نداریم یا خیلی قدیمی است (سایت مدتی خاموش بوده)،
            // بازهٔ پشتیبان = ۲۴ ساعت گذشته (قابل تغییر با فیلتر)
            $window = (int) apply_filters('wc_telegram_daily_fallback_window', DAY_IN_SECONDS);
            $fallback = time() - max(HOUR_IN_SECONDS, $window);
            if ($last <= 0 || $last < $fallback || $last > time()) {
                $day_start = $fallback;
            } else {
                $day_start = $last + 1;
            }
        }
        if ($day_end === null || $day_end > time()) {
            $day_end = time();
        }
        if ($day_end <= $day_start) {
            return ['ok' => true, 'message' => 'بازه گزارش خالی است؛ چیزی ارسال نشد.'];
        }

        // فقط شناسه سفارش‌های بازه با یک کوئری سبک گرفته می‌شود
        // (بارگذاری همه سفارش‌ها با limit=-1 حافظه را تمام می‌کند)
        $this->maybe_raise_memory();
        $ids = $this->get_day_order_ids($day_start, $day_end);

        // استخراج اطلاعات لازم از هر سفارش و آزادسازی فوری شیء — حافظه در حد یک سفارش می‌ماند
        $rows = [];
        foreach ($ids as $oid) {
            $o = wc_get_order($oid);
            if (!$o instanceof WC_Order) {
                continue;
            }
            $rows[] = [
                'number'   => $o->get_order_number(),
                'name'     => trim($o->get_formatted_billing_full_name()),
                'total'    => (float) $this->order_amounts($o)['grand'],
                'refunded' => (float) $o->get_total_refunded(),
                'status'   => $o->get_status(),
                'items'    => $o->get_item_count(),
                'pay'      => $o->get_payment_method_title() ? $o->get_payment_method_title() : 'نامشخص',
            ];
            unset($o);
            $this->clean_order_cache($oid);
        }

        $message = $this->build_daily_report($rows, $day_start, $day_end);
        $result = $this->send_long_to_all_chats($message);

        // پایان بازه فقط برای گزارشِ «خودکار» ذخیره می‌شود تا گزارش بعدی دقیقاً از همین‌جا ادامه یابد.
        // گزارشِ دستی آن را جابه‌جا نمی‌کند — وگرنه گزارش شبانه فقط از لحظهٔ آخرین
        // گزارش دستی به بعد را پوشش می‌داد و بخش بزرگی از روز جا می‌ماند.
        if (!empty($result['ok'])) {
            if (!$manual) {
                $prev = (int) get_option(self::LAST_REPORT_OPTION, 0);
                if ((int) $day_end > $prev) {
                    update_option(self::LAST_REPORT_OPTION, (int) $day_end, false);
                }
            }
            $this->log('success', 'daily', $manual ? 'manual_report_sent' : 'report_sent',
                sprintf('گزارش %s با %d سفارش ارسال شد.', $manual ? 'دستی' : 'روزانه', count($rows)),
                ['orders' => count($rows), 'from' => $day_start, 'to' => $day_end, 'manual' => (bool) $manual, 'chats' => array_keys(isset($result['sent']) ? $result['sent'] : [])], 0);
        } else {
            $this->log('error', 'daily', 'report_failed', 'ارسال گزارش روزانه ناموفق بود: ' . (isset($result['message']) ? $result['message'] : 'خطای نامشخص'), ['orders' => count($rows), 'from' => $day_start, 'to' => $day_end, 'manual' => (bool) $manual], 0);
        }

        // سنجاق خودکار گزارش (اختیاری) — فقط برای گزارش خودکار؛
        // گزارش دستی سنجاقِ گزارش شبانه را عوض نمی‌کند
        $s = $this->get_settings();
        if (!$manual && !empty($result['ok']) && !empty($s['daily_pin']) && $s['daily_pin'] === 'yes' && !empty($result['sent'])) {
            $pin = $this->pin_report_messages($result['sent']);
            if (!empty($pin['message'])) {
                $result['message'] .= ' ' . $pin['message'];
            }
        }
        return $result;
    }

    // شناسه سفارش‌های یک روز — یک کوئری سبک، سازگار با HPOS و حالت قدیمی
    private function get_day_order_ids($day_start, $day_end) {
        global $wpdb;
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
             && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ($hpos && method_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class, 'get_orders_table_name')) {
            // HPOS: تاریخ‌ها به وقت UTC ذخیره می‌شوند
            $table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if ($exists === $table) {
                return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE type = %s AND status NOT IN ('trash','checkout-draft','auto-draft') AND date_created_gmt >= %s AND date_created_gmt <= %s ORDER BY date_created_gmt ASC",
                    'shop_order',
                    gmdate('Y-m-d H:i:s', $day_start),
                    gmdate('Y-m-d H:i:s', $day_end)
                )));
            }
        }

        // حالت قدیمی (CPT): post_date به وقت محلی «وردپرس» ذخیره می‌شود (نه منطقه زمانی پلاگین)
        $ltz = wp_timezone();
        $after_local  = (new \DateTime('@' . $day_start))->setTimezone($ltz)->format('Y-m-d H:i:s');
        $before_local = (new \DateTime('@' . $day_end))->setTimezone($ltz)->format('Y-m-d H:i:s');
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_date >= %s AND post_date <= %s ORDER BY post_date ASC",
            'shop_order',
            $after_local,
            $before_local
        )));
    }

    // اگر سقف حافظه هاست کم است، موقتاً تا ۵۱۲ مگ بالا ببر (اگر هاست اجازه دهد)
    private function maybe_raise_memory() {
        if (!function_exists('wp_convert_hr_to_bytes')) {
            return;
        }
        $bytes = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($bytes > 0 && $bytes < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    // پاک‌سازی کش آبجکت یک سفارش بعد از پردازش — جلوگیری از انباشته شدن حافظه
    private function clean_order_cache($order_id) {
        $order_id = (int) $order_id;
        wp_cache_delete($order_id, 'posts');
        wp_cache_delete($order_id, 'post_meta');
        wp_cache_delete($order_id, 'orders');
        wp_cache_delete($order_id, 'order_addresses');
        wp_cache_delete($order_id, 'orders_meta');
        wp_cache_delete('order-items-' . $order_id, 'orders');
    }

    private function status_emoji($status) {
        $map = [
            'pending'    => '⏳',
            'on-hold'    => '⏸️',
            'processing' => '🔄',
            'completed'  => '✅',
            'cancelled'  => '❌',
            'refunded'   => '↩️',
            'failed'     => '⚠️',
        ];
        return isset($map[$status]) ? $map[$status] : '📦';
    }

    // ورودی: آرایه‌های سبک ['number','name','total','refunded','status','items','pay']
    public function build_daily_report($rows, $day_start, $day_end = null) {
        if ($day_end === null) {
            $day_end = $day_start + DAY_IN_SECONDS - 1;
        }
        $paid_statuses = apply_filters('wc_telegram_daily_paid_statuses', ['processing', 'completed']);

        // بازه در یک روز تقویمی؟ فقط همان تاریخ؛ وگرنه بازه کامل (کرون دیر → بازه چندروزه)
        if ($this->format_date('Y-m-d', $day_start) === $this->format_date('Y-m-d', $day_end)) {
            $date_str = $this->format_date(get_option('date_format'), $day_start);
            if (($day_end - $day_start) < DAY_IN_SECONDS - 1) {
                $date_str .= ' — ⏰ تا ساعت ' . $this->format_date('H:i', $day_end);
            }
        } else {
            $date_str = $this->format_date(get_option('date_format'), $day_start)
                      . ' تا ' . $this->format_date(get_option('date_format'), $day_end);
        }

        $msg = "📊 <b>گزارش فروش</b>\n"
             . "📅 " . $date_str . "\n"
             . "--------------------------\n";

        if (empty($rows)) {
            $msg .= "امروز سفارشی ثبت نشده است. 🎉\n";
            return $msg;
        }

        $counts = [];
        $revenue = 0.0;
        $refunded = 0.0;
        $items = 0;
        $pay = [];
        foreach ($rows as $r) {
            $st = $r['status'];
            $counts[$st] = isset($counts[$st]) ? $counts[$st] + 1 : 1;
            $refunded += (float) $r['refunded'];
            if (in_array($st, $paid_statuses, true)) {
                $revenue += (float) $r['total'];
                $items += (int) $r['items'];
                $pm = $r['pay'] ? $r['pay'] : 'نامشخص';
                if (!isset($pay[$pm])) {
                    $pay[$pm] = ['c' => 0, 's' => 0.0];
                }
                $pay[$pm]['c']++;
                $pay[$pm]['s'] += (float) $r['total'];
            }
        }

        $msg .= "🧾 تعداد سفارش‌ها: " . count($rows) . "\n";
        foreach ($counts as $st => $c) {
            $msg .= $this->status_emoji($st) . ' ' . wc_get_order_status_name($st) . ': ' . $c . "\n";
        }
        $msg .= "💵 <b>جمع فروش (پرداخت‌شده): " . $this->money($revenue) . "</b>\n";
        if ($refunded > 0) {
            $msg .= "↩️ مجموع استرداد: " . $this->money($refunded) . "\n";
        }
        $msg .= "📦 آیتم‌های فروخته‌شده: " . $items . "\n";

        if (!empty($pay)) {
            $msg .= "💳 تفکیک روش پرداخت:\n";
            foreach ($pay as $method => $d) {
                $msg .= "▪️ " . $method . ': ' . $d['c'] . ' سفارش — ' . $this->money($d['s']) . "\n";
            }
        }

        // لیست فشرده و جمع‌شونده: فقط پرداخت‌شده‌ها (اختیاری)، خط‌های کوتاه، داخل یک پیام
        $ds = $this->get_settings();
        $list = $rows;
        $filtered_note = '';
        if (!empty($ds['daily_paid_only']) && $ds['daily_paid_only'] === 'yes') {
            $list = array_values(array_filter($rows, function ($r) use ($paid_statuses) {
                return in_array($r['status'], $paid_statuses, true);
            }));
            if (count($list) < count($rows)) {
                $filtered_note = ' (فقط پرداخت‌شده‌ها)';
            }
        }

        // جدیدترین سفارش اول
        $list = array_reverse($list);

        // راهنمای ایموجی‌ها — فقط وضعیت‌های موجود در همین لیست
        $legend = [];
        foreach ($list as $r) {
            if (!isset($legend[$r['status']])) {
                $legend[$r['status']] = $this->status_emoji($r['status']) . ' ' . wc_get_order_status_name($r['status']);
            }
        }

        $msg .= "--------------------------\n🧾 <b>جزئیات سفارش‌ها" . $filtered_note . ":</b>\n";
        if ($legend) {
            $msg .= 'راهنما: ' . implode(' • ', $legend) . "\n";
        }
        $msg .= "مبالغ به تومان است. 👇 برای دیدن لیست باز کنید:\n";
        $msg .= "<blockquote expandable>\n";

        $all_lines = [];
        $n = 0;
        foreach ($list as $r) {
            $n++;
            $name = trim((string) $r['name']);
            if ($name === '') {
                $name = '—';
            }
            $name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $all_lines[] = $n . '. #' . $r['number'] . ' | ' . $name . ' | '
                         . number_format(round((float) $r['total']), 0, '.', ',') . ' | '
                         . $this->status_emoji($r['status']) . ' ' . wc_get_order_status_name($r['status']);
        }

        // به‌اندازه‌ای خط بردار که کل پیام داخل یک پیام تلگرام جا شود
        $budget = 3400 - mb_strlen($msg);
        $body = '';
        $shown = 0;
        foreach ($all_lines as $line) {
            $add = ($body === '' ? '' : "\n") . $line;
            if ($body !== '' && (mb_strlen($body) + mb_strlen($add)) > $budget) {
                break;
            }
            $body .= $add;
            $shown++;
        }
        $msg .= $body . "\n";
        $rest = count($all_lines) - $shown;
        if ($rest > 0) {
            $msg .= '… و ' . $rest . ' سفارش دیگر.' . "\n";
        }
        $msg .= "</blockquote>";
        $msg .= "\n--------------------------\n🕐 تولید: " . $this->format_date(get_option('date_format') . ' ' . get_option('time_format'), time()) . ' (نسخه ' . WC_TELEGRAM_ORDERS_VERSION . ')';

        return $msg;
    }

    /* ---------------- اعلانات محصولات (کمبود موجودی) ---------------- */

    // مقصد اعلان‌های محصولات: چت مخصوص، اگر خالی بود همان چت سفارش‌ها
    private function stock_alert_chats($s = null) {
        if ($s === null) {
            $s = $this->get_settings();
        }
        return (isset($s['stock_chat_ids']) && trim((string) $s['stock_chat_ids']) !== '') ? $s['stock_chat_ids'] : null;
    }

    private function get_stock_state($product_id) {
        return (string) get_post_meta((int) $product_id, self::STOCK_STATE_META, true);
    }

    private function set_stock_state($product_id, $state) {
        $product_id = (int) $product_id;
        if ($state === '' || $state === null) {
            delete_post_meta($product_id, self::STOCK_STATE_META);
        } else {
            update_post_meta($product_id, self::STOCK_STATE_META, $state);
        }
    }

    // محصول (و برای متغیر: محصول مادر) منتشر شده باشد — پیش‌نویس/زباله‌دان اعلان نمی‌گیرند
    private function stock_product_is_live($product) {
        $live = ['publish', 'private'];
        if ($product->is_type('variation')) {
            // متغیر غیرفعال (private) اعلان نمی‌گیرد
            if ($product->get_status() !== 'publish') {
                return false;
            }
            $parent = wc_get_product($product->get_parent_id());
            return $parent && in_array($parent->get_status(), $live, true);
        }
        return in_array($product->get_status(), $live, true);
    }

    // هوک تغییر موجودی (هر مسیر: ثبت سفارش، ویرایش دستی، REST، ایمپورت، بازگشت موجودی)
    // ماشین وضعیت هر محصول: '' (عادی) → low (هشدار رفته) → out (اتمام رفته) → با شارژ مجدد '' می‌شود
    public function on_stock_changed($product) {
        if (!$product instanceof WC_Product) {
            return;
        }
        $s = $this->get_settings();
        if (empty($s['stock_enabled']) || $s['stock_enabled'] !== 'yes') {
            return;
        }
        $managing = $product->managing_stock();
        if ($managing === 'parent') {
            // موجودی روی محصول مادر است
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent) {
                return;
            }
            $product = $parent;
        } elseif (!$managing) {
            return;
        }
        $qty = $product->get_stock_quantity();
        if ($qty === null || $qty === '') {
            return;
        }
        $qty       = (int) $qty;
        $threshold = max(1, (int) $s['stock_threshold']);
        $pid       = $product->get_id();
        $state     = $this->get_stock_state($pid);
        $out_on    = (!empty($s['stock_out_enabled']) && $s['stock_out_enabled'] === 'yes');

        // شارژ شد و به بالای آستانه رسید → بازنشانی بی‌صدا؛ دفعه بعد دوباره هشدار می‌دهد
        if ($qty >= $threshold) {
            if ($state !== '') {
                $this->set_stock_state($pid, '');
                $this->log('debug', 'stock', 'state_reset', sprintf('موجودی محصول #%d به %d رسید؛ وضعیت اعلان بازنشانی شد (قبلاً %s بود).', $pid, $qty, $state), ['product_id' => $pid, 'qty' => $qty], $pid);
            }
            unset($this->stock_queue[$pid]);
            return;
        }
        if (!$this->stock_product_is_live($product)) {
            return;
        }

        if ($qty <= 0) {
            if ($state === 'out') {
                unset($this->stock_queue[$pid]);
                $this->log('debug', 'stock', 'alert_skipped', sprintf('اتمام موجودی محصول #%d از قبل اعلام شده؛ پیامی ارسال نمی‌شود.', $pid), ['product_id' => $pid, 'qty' => $qty], $pid);
                return; // قبلاً اعلان اتمام رفته
            }
            if ($out_on) {
                $this->queue_stock_alert($pid, $qty, 'out', 'out');
            } elseif ($state === '') {
                // اعلان اتمام غیرفعال است ولی تا حالا هیچ هشداری نرفته → همان هشدار کمبود برود
                $this->queue_stock_alert($pid, $qty, 'low', 'out');
            } else {
                $this->set_stock_state($pid, 'out');
                unset($this->stock_queue[$pid]);
            }
            return;
        }

        // 0 < qty < threshold
        if ($state === 'low') {
            $this->log('debug', 'stock', 'alert_skipped', sprintf('هشدار کمبود محصول #%d از قبل ارسال شده؛ تا شارژ مجدد تکرار نمی‌شود.', $pid), ['product_id' => $pid, 'qty' => $qty], $pid);
            return; // قبلاً هشدار رفته؛ تا شارژ مجدد تکرار نمی‌شود
        }
        if ($state === 'out') {
            // کمی شارژ شد ولی هنوز زیر آستانه است — مدیر خودش خبر دارد؛ بی‌صدا
            $this->set_stock_state($pid, 'low');
            unset($this->stock_queue[$pid]);
            return;
        }
        $this->queue_stock_alert($pid, $qty, 'low', 'low');
    }

    private function queue_stock_alert($pid, $qty, $kind, $new_state) {
        $order = isset($this->stock_queue[$pid]['order']) ? $this->stock_queue[$pid]['order'] : '';
        $this->stock_queue[$pid] = [
            'id'        => (int) $pid,
            'qty'       => (int) $qty,
            'kind'      => $kind,
            'new_state' => $new_state,
            'order'     => $order,
        ];
    }

    // بعد از کسر موجودی یک سفارش: شماره سفارش به اعلان‌های همان محصولات وصل می‌شود
    public function attach_order_to_stock_queue($order) {
        if (empty($this->stock_queue)) {
            return;
        }
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order);
        }
        if (!$order instanceof WC_Order) {
            return;
        }
        $name = trim((string) $order->get_formatted_billing_full_name());
        $info = '#' . $order->get_order_number() . ($name !== '' ? ' — ' . $name : '');
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            foreach ([$item->get_variation_id(), $item->get_product_id()] as $pid) {
                $pid = (int) $pid;
                if ($pid && isset($this->stock_queue[$pid]) && $this->stock_queue[$pid]['order'] === '') {
                    $this->stock_queue[$pid]['order'] = $info;
                }
            }
        }
    }

    // پایان درخواست: اعلان‌های صف به یک event کرون موکول می‌شود تا درخواست (و ایمپورت‌های بزرگ) بلاک نشود.
    //
    // ضدتکرار (علت ارسال‌های تکراری در نسخه‌های قبل): قبلاً هر درخواست event جدا می‌ساخت،
    // چون بررسی تکرار فقط روی «آرگومان‌های کاملاً یکسان» جواب می‌داد (wp_next_scheduled با args).
    // نتیجه: ده درخواست هم‌زمان → ده event → ده پیام یکسان.
    // حالا همیشه فقط «یک» event در انتظار است و صفِ درخواست‌های هم‌زمان در همان event ادغام می‌شود.
    public function flush_stock_queue() {
        if (empty($this->stock_queue)) {
            return;
        }
        $queue = $this->stock_queue;
        $this->stock_queue = [];

        // اگر رویدادی از قبل در انتظار است، صف آن را با صف فعلی ادغام کن (یک پیام به‌جای چند پیام)
        $pending = $this->pending_stock_flush_event();
        if ($pending !== null) {
            $pending_queue = isset($pending['args'][0]) && is_array($pending['args'][0]) ? $pending['args'][0] : [];
            $queue = $this->merge_stock_queues($pending_queue, $queue);
            wp_unschedule_event((int) $pending['timestamp'], self::STOCK_FLUSH_HOOK, $pending['args']);
        }
        // سقف صف: ایمپورت‌های خیلی بزرگ آرگومان غول‌آسا در کرون نسازند
        if (count($queue) > 200) {
            $queue = array_slice($queue, 0, 200, true);
        }

        if (wp_schedule_single_event(time(), self::STOCK_FLUSH_HOOK, [$queue])) {
            $this->log('debug', 'stock', 'flush_scheduled', sprintf('ارسال %d اعلان موجودی به کرون سپرده شد.', count($queue)), ['ids' => array_keys($queue)], 0);
            $this->spawn_cron_now();
            return;
        }
        // زمان‌بندی نشد — همان‌جا ارسال کن
        $this->process_stock_flush($queue);
    }

    // اولین رویدادِ در انتظارِ ارسال اعلان‌های موجودی (با آرگومان‌هایش) — یا null
    private function pending_stock_flush_event() {
        $crons = _get_cron_array();
        if (empty($crons) || !is_array($crons)) {
            return null;
        }
        foreach ($crons as $timestamp => $cron) {
            if (!isset($cron[self::STOCK_FLUSH_HOOK]) || !is_array($cron[self::STOCK_FLUSH_HOOK])) {
                continue;
            }
            foreach ($cron[self::STOCK_FLUSH_HOOK] as $data) {
                $args  = isset($data['args']) && is_array($data['args']) ? $data['args'] : [];
                $queue = isset($args[0]) && is_array($args[0]) ? $args[0] : [];
                return ['timestamp' => (int) $timestamp, 'args' => [$queue]];
            }
        }
        return null;
    }

    // ادغام دو صف اعلان (ورودی جدید برای یک محصول برنده است؛ شماره سفارش قبلی حفظ می‌شود)
    private function merge_stock_queues($a, $b) {
        $a = is_array($a) ? $a : [];
        $b = is_array($b) ? $b : [];
        foreach ($b as $pid => $item) {
            if (!is_array($item)) {
                continue;
            }
            $pid = (int) $pid;
            if (isset($a[$pid]) && is_array($a[$pid]) && !empty($a[$pid]['order']) && empty($item['order'])) {
                $item['order'] = $a[$pid]['order'];
            }
            $a[$pid] = $item;
        }
        return $a;
    }

    /**
     * ادعای اتمیک وضعیت اعلان یک محصول — قلبِ جلوگیری از پیام تکراری.
     * فقط یکی از اجراهای هم‌زمان (یا اجرای تکراری همان رویداد کرون) می‌تواند ادعا کند.
     *
     * خروجی: ['claimed' => bool, 'previous' => string, 'reason' => string]
     */
    private function claim_stock_state($pid, $state) {
        global $wpdb;
        $pid  = (int) $pid;
        $key  = self::STOCK_STATE_META;
        $current = (string) get_post_meta($pid, $key, true);

        if ($current === $state) {
            return ['claimed' => false, 'previous' => $current, 'reason' => 'already_' . $state];
        }
        if ($current === 'out') {
            return ['claimed' => false, 'previous' => $current, 'reason' => 'already_out'];
        }
        if ($current === '') {
            // add_post_meta با unique=true اتمیک است: فقط یکی از پردازش‌های هم‌زمان true می‌گیرد
            if (add_post_meta($pid, $key, $state, true)) {
                return ['claimed' => true, 'previous' => '', 'reason' => ''];
            }
            return ['claimed' => false, 'previous' => '', 'reason' => 'claimed_by_other_process'];
        }
        // current === 'low' و درخواست 'out' → ارتقا با UPDATE شرطی (CAS) تا دو پردازش هم‌زمان هر دو نفرستند
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = %s AND meta_value = %s",
            $state, $pid, $key, $current
        ));
        wp_cache_delete($pid, 'post_meta');
        if ($updated) {
            return ['claimed' => true, 'previous' => $current, 'reason' => ''];
        }
        return ['claimed' => false, 'previous' => $current, 'reason' => 'claimed_by_other_process'];
    }

    // بازگرداندن ادعا (وقتی ارسال ناموفق بود) تا بعداً دوباره تلاش شود
    private function release_stock_claim($pid, $previous) {
        $pid = (int) $pid;
        if ((string) $previous === '') {
            delete_post_meta($pid, self::STOCK_STATE_META);
        } else {
            update_post_meta($pid, self::STOCK_STATE_META, $previous);
        }
        wp_cache_delete($pid, 'post_meta');
    }

    // بازه توقف: اگر برای همین محصول و همین نوع اعلان به‌تازگی پیام رفته، دوباره نفرست
    // (نوسان موجودی بین صفر و بالای آستانه — مثلاً همگام‌سازی/ایمپورت — باعث spam نمی‌شود)
    private function alert_on_cooldown($pid, $state) {
        $cooldown = (int) apply_filters('wc_telegram_stock_alert_cooldown', self::LOG_COOLDOWN, (int) $pid, (string) $state);
        if ($cooldown <= 0) {
            return false;
        }
        return get_transient('wc_telegram_alert_' . (int) $pid . '_' . $state) !== false;
    }

    private function set_alert_cooldown($pid, $state) {
        $cooldown = (int) apply_filters('wc_telegram_stock_alert_cooldown', self::LOG_COOLDOWN, (int) $pid, (string) $state);
        if ($cooldown <= 0) {
            return;
        }
        set_transient('wc_telegram_alert_' . (int) $pid . '_' . $state, time(), $cooldown);
    }

    // ارسال همه اعلان‌های صف در «یک» پیام (چند محصول یک سفارش → یک پیام)
    //
    // سه لایه ضدتکرار (هر سه در لاگ ثبت می‌شوند):
    //   ۱) ادعای اتمیک وضعیتِ هر محصول قبل از ارسال (claim_stock_state) —
    //      اجرای تکراریِ همان رویداد کرون یا رویداد موازی، وضعیت را پر‌شده می‌بیند و رد می‌شود
    //   ۲) بازه توقف برای هر محصول (cooldown) — نوسان سریع موجودی spam نمی‌سازد
    //   ۳) فقط یک رویداد در انتظار + ادغام صف‌ها (در flush_stock_queue)
    public function process_stock_flush($queue = null) {
        if (empty($queue) || !is_array($queue) || !function_exists('wc_get_product')) {
            return;
        }
        $s = $this->get_settings();
        if (empty($s['stock_enabled']) || $s['stock_enabled'] !== 'yes') {
            return;
        }

        $blocks  = [];
        $claimed = [];  // pid => وضعیت قبلی (برای بازگردانی در صورت خطای ارسال)
        $skipped = [];  // pid => دلیل رد شدن
        $max     = 30;  // سقف هر پیام — برای ایمپورت‌های بزرگ

        foreach ($queue as $pid => $q) {
            if (count($blocks) >= $max) {
                break;
            }
            if (!is_array($q)) {
                continue;
            }
            $pid = isset($q['id']) && (int) $q['id'] > 0 ? (int) $q['id'] : (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $kind      = (isset($q['kind']) && $q['kind'] === 'out') ? 'out' : 'low';
            $new_state = isset($q['new_state']) ? (string) $q['new_state'] : $kind;
            if ($new_state !== 'low' && $new_state !== 'out') {
                $new_state = $kind;
            }

            // لایه ۲: بازه توقف
            if ($this->alert_on_cooldown($pid, $new_state)) {
                $skipped[$pid] = 'cooldown';
                continue;
            }
            // لایه ۱: ادعای اتمیک — اینجا جلوی پیام‌های تکراری گرفته می‌شود
            $claim = $this->claim_stock_state($pid, $new_state);
            if (empty($claim['claimed'])) {
                $skipped[$pid] = $claim['reason'];
                continue;
            }

            $product = wc_get_product($pid);
            if (!$product instanceof WC_Product) {
                $this->release_stock_claim($pid, $claim['previous']);
                continue;
            }
            $qty = isset($q['qty']) ? (int) $q['qty'] : (int) $product->get_stock_quantity();
            $blocks[]  = $this->build_stock_message($product, $qty, $kind, isset($q['order']) ? (string) $q['order'] : '', $s);
            $claimed[$pid] = $claim['previous'];
        }

        if (!empty($skipped)) {
            $this->log('info', 'stock', 'duplicate_blocked',
                sprintf('%d اعلان موجودی تکراری تشخیص داده شد و ارسال نشد.', count($skipped)),
                ['blocked' => $skipped, 'blocked_count' => count($skipped)], 0);
        }
        if (empty($blocks)) {
            // همهٔ موارد تکراری/مسدود بودند → هیچ پیامی ارسال نمی‌شود
            $this->log('debug', 'stock', 'flush_nothing', 'هیچ اعلان موجودی جدیدی ارسال نشد (همه تکراری بودند).', ['queue_count' => count($queue)], 0);
            return;
        }

        $message = implode("\n\n", $blocks);
        $rest = count($queue) - count($blocks);
        if ($rest > 0) {
            $message .= "\n\n… و " . $rest . ' محصول دیگر زیر آستانه رفتند (برای لیست کامل از «اسکن انبار» استفاده کنید).';
        }

        // لایه ۳: هش پیام — اگر دو رویداد دقیقاً هم‌زمان اجرا شوند و هر دو از ادعای وضعیت
        // عبور کنند، متن یکسانِ پیام در اینجا جلوی ارسال دوم را می‌گیرد
        $hash      = md5($message);
        $guard_key = 'wc_telegram_msg_' . $hash;
        if (get_transient($guard_key) !== false) {
            foreach ($claimed as $pid => $prev) {
                $this->release_stock_claim($pid, $prev);
            }
            $this->log('warning', 'stock', 'duplicate_blocked',
                'ارسال اعلان موجودی مسدود شد: همین پیام در چند دقیقهٔ اخیر ارسال شده بود.',
                ['hash' => $hash, 'product_ids' => array_keys($claimed)], 0);
            return;
        }

        $result = $this->send_long_to_all_chats($message, 3900, $this->stock_alert_chats($s));
        if (!empty($result['ok'])) {
            set_transient($guard_key, time(), 300);
            foreach ($claimed as $pid => $prev) {
                $this->set_alert_cooldown($pid, $this->claimed_state_of($pid, $prev));
            }
            $this->log('success', 'stock', 'alert_sent',
                sprintf('%d اعلان موجودی ارسال شد.', count($blocks)),
                ['product_ids' => array_keys($claimed), 'chats' => array_keys(isset($result['sent']) ? $result['sent'] : [])], 0);
        } else {
            // ارسال ناموفق → ادعا پس گرفته می‌شود تا با تغییر بعدی موجودی دوباره تلاش شود
            foreach ($claimed as $pid => $prev) {
                $this->release_stock_claim($pid, $prev);
            }
            $this->log('error', 'stock', 'alert_failed',
                'ارسال اعلان موجودی ناموفق بود: ' . (isset($result['message']) ? $result['message'] : 'خطای نامشخص'),
                ['product_ids' => array_keys($claimed)], 0);
        }
    }

    // وضعیتی که برای یک محصول ادعا شده (برای ثبت بازه توقف)
    private function claimed_state_of($pid, $previous) {
        $current = (string) get_post_meta((int) $pid, self::STOCK_STATE_META, true);
        return $current !== '' ? $current : ((string) $previous === '' ? 'low' : (string) $previous);
    }

    // خطوط «مدل: X / رنگ: Y» برای یک متغیر (بدون slug — اسم واقعی ترم‌ها)
    private function variation_lines($variation) {
        $lines = [];
        if (!$variation instanceof WC_Product_Variation) {
            return $lines;
        }
        foreach ($variation->get_attributes() as $name => $value) {
            $value = (string) $value;
            if ($value === '') {
                continue; // «هر مقدار»
            }
            // همان منطق wc_get_formatted_variation: ترم تاکسونومی → اسم؛ ویژگی سفارشی → مقدار خام
            if (taxonomy_exists($name)) {
                $term = get_term_by('slug', $value, $name);
                if ($term && !is_wp_error($term) && !empty($term->name)) {
                    $value = $term->name;
                }
            } else {
                $value = rawurldecode($value);
            }
            $label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $variation) : $name;
            $lines[] = trim((string) $label) . ': ' . $value;
        }
        return $lines;
    }

    // پیام یک محصول بر اساس قالب تب «اعلانات محصولات»
    private function build_stock_message($product, $qty, $kind, $order_info, $s) {
        $is_var = $product->is_type('variation');
        $parent = $is_var ? wc_get_product($product->get_parent_id()) : null;
        $base   = $parent ? $parent : $product;

        $sku = $product->get_sku();
        if ($sku === '' && $parent) {
            $sku = $parent->get_sku();
        }
        $price = $product->get_price();

        $vars = [
            'kind'         => $kind,
            'product_name' => $base->get_name(),
            'variation'    => implode("\n", $is_var ? $this->variation_lines($product) : []),
            'stock'        => (int) $qty,
            'threshold'    => max(1, (int) $s['stock_threshold']),
            'sku'          => $sku !== '' ? $sku : '—',
            'price'        => ($price !== '' && $price !== null) ? $this->money($price) : '—',
            'order_info'   => $order_info !== '' ? '🧾 سفارش: ' . $order_info : '',
            'product_url'  => admin_url('post.php?post=' . $base->get_id() . '&action=edit'),
            'product_link' => get_permalink($base->get_id()),
        ];
        return $this->render_stock_template($vars, $s);
    }

    private function render_stock_template(array $vars, $s) {
        $template = !empty($s['stock_template']) ? $s['stock_template'] : $this->default_stock_template();
        $kind = isset($vars['kind']) && $vars['kind'] === 'out' ? 'out' : 'low';

        $replacements = [
            '{stock_emoji}'  => $kind === 'out' ? '🚫' : '⚠️',
            '{stock_label}'  => $kind === 'out' ? 'اتمام موجودی' : 'هشدار کمبود موجودی',
            '{product_name}' => isset($vars['product_name']) ? $vars['product_name'] : '',
            '{variation}'    => isset($vars['variation']) ? $vars['variation'] : '',
            '{stock}'        => isset($vars['stock']) ? $vars['stock'] : '',
            '{threshold}'    => isset($vars['threshold']) ? $vars['threshold'] : '',
            '{sku}'          => isset($vars['sku']) ? $vars['sku'] : '—',
            '{price}'        => isset($vars['price']) ? $vars['price'] : '—',
            '{order_info}'   => isset($vars['order_info']) ? $vars['order_info'] : '',
            '{product_url}'  => isset($vars['product_url']) ? $vars['product_url'] : '',
            '{product_link}' => isset($vars['product_link']) ? $vars['product_link'] : '',
            '{site_name}'    => get_bloginfo('name'),
        ];

        // متغیرهای چندخطی/اختیاری که خالی‌اند، همراه با خط خودشان حذف می‌شوند تا خط خالی نماند
        foreach (['{variation}', '{order_info}'] as $opt_key) {
            if (trim((string) $replacements[$opt_key]) === '') {
                $template = str_replace([$opt_key . "\r\n", $opt_key . "\n"], '', $template);
                $template = str_replace($opt_key, '', $template);
            }
        }

        $escaped = [];
        foreach ($replacements as $key => $value) {
            if ($key === '{product_url}' || $key === '{product_link}') {
                $escaped[$key] = esc_url($value);
            } else {
                $escaped[$key] = htmlspecialchars(wp_strip_all_tags(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8');
            }
        }
        $message = strtr($template, $escaped);
        if (mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 4000) . "\n…";
        }
        return $message;
    }

    public function handle_stock_test() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_stock_test_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $s = $this->get_settings();
        $msg = $this->render_stock_template([
            'kind'         => 'low',
            'product_name' => 'قاب ماربل مات قهوه ای (نمونه تست)',
            'variation'    => "مدل: iPhone 17 Pro Max\nرنگ: قهوه ای",
            'stock'        => max(1, (int) $s['stock_threshold'] - 1),
            'threshold'    => (int) $s['stock_threshold'],
            'sku'          => 'TEST-1001',
            'price'        => $this->money(538000),
            'order_info'   => '🧾 سفارش: #311094 — عسل صادقی',
            'product_url'  => admin_url('edit.php?post_type=product'),
            'product_link' => home_url('/'),
        ], $s);
        $result = $this->send_to_all_chats("🧪 <b>پیام تست اعلانات محصولات</b>\n\n" . $msg, $this->stock_alert_chats($s));
        $this->log(!empty($result['ok']) ? 'success' : 'error', 'stock', 'test_message',
            !empty($result['ok']) ? 'اعلان تست موجودی ارسال شد.' : 'ارسال اعلان تست موجودی ناموفق بود: ' . $result['message'],
            ['error' => empty($result['ok']) ? $result['message'] : ''], 0);
        set_transient('wc_telegram_test_result', $result, 60);
        wp_safe_redirect($this->tab_url('products'));
        exit;
    }

    // اسکن انبار: همه محصولات/متغیرهای زیر آستانه در یک پیام خلاصه + علامت‌گذاری وضعیت
    public function handle_stock_scan() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_stock_scan_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $result = $this->send_stock_scan_report();
        $this->log(!empty($result['ok']) ? 'success' : 'error', 'stock', 'scan',
            isset($result['message']) ? $result['message'] : 'اسکن انبار انجام شد.',
            ['ok' => !empty($result['ok'])], 0);
        set_transient('wc_telegram_test_result', $result, 60);
        wp_safe_redirect($this->tab_url('products'));
        exit;
    }

    public function send_stock_scan_report() {
        global $wpdb;
        if (!function_exists('wc_get_product')) {
            return ['ok' => false, 'message' => 'ووکامرس فعال نیست.'];
        }
        $s = $this->get_settings();
        $threshold = max(1, (int) $s['stock_threshold']);
        $this->maybe_raise_memory();

        // محصولات و متغیرهایی که خودشان موجودی را مدیریت می‌کنند و زیر آستانه‌اند
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_manage_stock' AND ms.meta_value = 'yes'
             INNER JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '_stock'
             WHERE p.post_type IN ('product', 'product_variation')
               AND p.post_status IN ('publish', 'private')
               AND st.meta_value <> ''
               AND CAST(st.meta_value AS DECIMAL(20,4)) < %d
             ORDER BY CAST(st.meta_value AS DECIMAL(20,4)) ASC, p.ID ASC
             LIMIT 500",
            $threshold
        ));

        $out = [];
        $low = [];
        foreach ((array) $ids as $pid) {
            $product = wc_get_product((int) $pid);
            if (!$product instanceof WC_Product || $product->managing_stock() !== true) {
                continue;
            }
            if (!$this->stock_product_is_live($product)) {
                continue;
            }
            $qty = $product->get_stock_quantity();
            if ($qty === null || (int) $qty >= $threshold) {
                continue;
            }
            $qty = (int) $qty;
            $is_var = $product->is_type('variation');
            $parent = $is_var ? wc_get_product($product->get_parent_id()) : null;
            $base   = $parent ? $parent : $product;
            $name   = $base->get_name();
            $vl     = $is_var ? $this->variation_lines($product) : [];
            $sku    = $product->get_sku();
            if ($sku === '' && $parent) {
                $sku = $parent->get_sku();
            }
            $row = [
                'id'   => (int) $pid,
                'name' => $name . ($vl ? ' — ' . implode('، ', $vl) : ''),
                'sku'  => $sku,
                'qty'  => $qty,
                'url'  => admin_url('post.php?post=' . $base->get_id() . '&action=edit'),
            ];
            if ($qty <= 0) {
                $out[] = $row;
            } else {
                $low[] = $row;
            }
        }

        $total = count($out) + count($low);
        if ($total === 0) {
            return ['ok' => true, 'message' => sprintf('✅ هیچ محصولی زیر آستانه (کمتر از %d عدد) نیست؛ چیزی ارسال نشد.', $threshold)];
        }

        $esc = function ($v) {
            return htmlspecialchars(wp_strip_all_tags(html_entity_decode((string) $v, ENT_QUOTES, 'UTF-8')), ENT_QUOTES, 'UTF-8');
        };
        $line = function ($n, $r) use ($esc) {
            return $n . '. <a href="' . esc_url($r['url']) . '">' . $esc($r['name']) . '</a>'
                 . ($r['sku'] !== '' ? ' | SKU: ' . $esc($r['sku']) : '')
                 . ' | ' . (int) $r['qty'] . ' عدد';
        };

        $msg  = "📦 <b>اسکن انبار — محصولات زیر آستانه (کمتر از " . $threshold . " عدد)</b>\n";
        $msg .= '🕐 ' . $this->format_date(get_option('date_format') . ' ' . get_option('time_format'), time()) . "\n";
        $msg .= "--------------------------\n";
        if ($out) {
            $msg .= '🚫 <b>اتمام موجودی (' . count($out) . "):</b>\n";
            $n = 0;
            foreach ($out as $r) {
                $msg .= $line(++$n, $r) . "\n";
            }
            $msg .= "--------------------------\n";
        }
        if ($low) {
            $msg .= '⚠️ <b>کم‌موجود (' . count($low) . "):</b>\n";
            $n = 0;
            foreach ($low as $r) {
                $msg .= $line(++$n, $r) . "\n";
            }
            $msg .= "--------------------------\n";
        }
        $msg .= 'جمع: ' . $total . ' مورد' . (count($ids) >= 500 ? ' (۵۰۰ مورد اول)' : '') . "\n";
        $msg .= 'این محصولات علامت خوردند؛ تا شارژ مجدد، اعلان تکی نمی‌گیرند.';

        $result = $this->send_long_to_all_chats($msg, 3900, $this->stock_alert_chats($s));
        if (!empty($result['ok'])) {
            foreach ($out as $r) {
                $this->set_stock_state($r['id'], 'out');
            }
            foreach ($low as $r) {
                $this->set_stock_state($r['id'], 'low');
            }
            $result['message'] = sprintf('📦 لیست %d محصول زیر آستانه ارسال شد (%d اتمام، %d کم‌موجود).', $total, count($out), count($low));
        }
        return $result;
    }

    /* ---------------- ارسال ---------------- */

    private function parse_chat_ids($raw) {
        $parts = preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_filter(array_map('trim', (array) $parts))));
    }

    // $chat_ids_raw = null → چت‌های تنظیمات سفارش‌ها؛ رشته → مقصد سفارشی (مثل چت اعلانات محصولات)
    // خروجی: ['ok', 'message', 'sent' => [chat_id => message_id]]
    public function send_to_all_chats($message, $chat_ids_raw = null) {
        $s = $this->get_settings();

        if (empty($s['bot_token'])) {
            return ['ok' => false, 'message' => 'توکن ربات وارد نشده. از بخش ووکامرس ← ارسال سفارش‌ها به تلگرام وارد کنید.', 'sent' => []];
        }
        $chats = $this->parse_chat_ids($chat_ids_raw === null ? $s['chat_ids'] : $chat_ids_raw);
        if (empty($chats)) {
            return ['ok' => false, 'message' => 'شناسه چت وارد نشده. از بخش ووکامرس ← ارسال سفارش‌ها به تلگرام وارد کنید.', 'sent' => []];
        }

        $errors = [];
        $sent = [];
        foreach ($chats as $chat_id) {
            $res = $this->send_to_telegram($s['bot_token'], $chat_id, $message);
            if (empty($res['ok'])) {
                $errors[] = $chat_id . ': ' . $res['message'];
            } elseif (!empty($res['message_id'])) {
                $sent[(string) $chat_id] = (int) $res['message_id'];
            }
        }
        if ($errors) {
            $this->track_send_result(false, implode(' | ', $errors));
            return ['ok' => false, 'message' => implode(' | ', $errors), 'sent' => $sent];
        }
        $this->track_send_result(true, '');
        return ['ok' => true, 'message' => sprintf('پیام به %d مقصد ارسال شد.', count($chats)), 'sent' => $sent];
    }

    /* ---------------- هشدار سلامت ربات ---------------- */

    // در میانهٔ ارسالِ خودِ هشدار سلامت، شمارنده دست نمی‌خورد (وگرنه بازگشت بی‌نهایت یا صفر شدن اشتباه)
    private $sending_health_alert = false;

    private function health_enabled($s = null) {
        $s = $s ?: $this->get_settings();
        return !empty($s['health_enabled']) && $s['health_enabled'] === 'yes';
    }

    /** شمارش نتایج ارسال‌ها؛ N شکست پشت‌سرهم → یک هشدار به چت (با بازهٔ توقف) */
    private function track_send_result($ok, $error = '') {
        if ($this->sending_health_alert) {
            return;
        }
        if ($ok) {
            if ((int) get_option(self::HEALTH_STREAK_OPTION, 0) !== 0) {
                update_option(self::HEALTH_STREAK_OPTION, 0, false);
            }
            return;
        }
        $s = $this->get_settings();
        if (!$this->health_enabled($s)) {
            return;
        }
        $streak = (int) get_option(self::HEALTH_STREAK_OPTION, 0) + 1;
        update_option(self::HEALTH_STREAK_OPTION, $streak, false);

        $threshold = max(2, (int) (isset($s['health_threshold']) ? $s['health_threshold'] : 5));
        if ($streak < $threshold) {
            return;
        }
        $cooldown = max(1, (int) (isset($s['health_cooldown']) ? $s['health_cooldown'] : 6)) * HOUR_IN_SECONDS;
        $last     = (int) get_option(self::HEALTH_ALERT_OPTION, 0);
        if ((time() - $last) < $cooldown) {
            return; // هنوز در بازهٔ توقف هستیم
        }
        update_option(self::HEALTH_ALERT_OPTION, time(), false);

        $msg = "⚠️ <b>هشدار سلامت ربات تلگرام</b>\n"
             . '🔴 ' . $this->to_persian_digits((string) $streak) . ' ارسال پشت‌سرهم ناموفق بود.' . "\n"
             . '🕐 ' . $this->format_date(get_option('date_format') . ' ' . get_option('time_format'), time()) . "\n"
             . 'آخرین خطا: ' . htmlspecialchars(mb_substr(wp_strip_all_tags((string) $error), 0, 300), ENT_QUOTES, 'UTF-8') . "\n"
             . 'موارد رایج: توکن عوض شده، ربات از گروه حذف شده، یا محدودیت نرخ تلگرام (429).';

        $this->sending_health_alert = true;
        $res = $this->send_to_all_chats($msg);
        $this->sending_health_alert = false;

        $this->log('error', 'system', 'health_alert',
            sprintf('هشدار سلامت ارسال شد: %d شکست پشت‌سرهم. تحویل خودِ هشدار: %s', $streak, empty($res['ok']) ? 'ناموفق' : 'موفق'),
            ['streak' => $streak, 'threshold' => $threshold, 'delivered' => !empty($res['ok']), 'error' => mb_substr((string) $error, 0, 300)], 0);
    }

    // شمار فعلی شکست‌های پشت‌سرهم (برای داشبورد)
    public function health_streak() {
        return (int) get_option(self::HEALTH_STREAK_OPTION, 0);
    }

    // ارسال پیام‌های طولانی (مثل گزارش روزانه) در چند تکه — تکه‌بندی ایمن از نظر HTML
    public function send_long_to_all_chats($message, $limit = 3900, $chat_ids_raw = null) {
        $chunks = $this->chunk_message($message, $limit);
        if (empty($chunks)) {
            return ['ok' => false, 'message' => 'متن پیام خالی است.'];
        }

        $errors = [];
        $sent = 0;
        $first_ids = []; // شناسه پیام تکه اول در هر چت (همان که سنجاق می‌شود — خلاصه آمار آنجاست)
        foreach ($chunks as $i => $chunk) {
            $res = $this->send_to_all_chats($chunk, $chat_ids_raw);
            if ($i === 0 && !empty($res['sent'])) {
                $first_ids = $res['sent'];
            }
            if (!empty($res['ok'])) {
                $sent++;
            } else {
                $errors[] = $res['message'];
            }
        }
        if ($errors) {
            return ['ok' => false, 'message' => implode(' | ', $errors), 'sent' => $first_ids];
        }
        if (count($chunks) > 1) {
            return ['ok' => true, 'message' => sprintf('گزارش در %d پیام ارسال شد.', count($chunks)), 'sent' => $first_ids];
        }
        return ['ok' => true, 'message' => sprintf('پیام به مقصد ارسال شد (%d پیام).', $sent), 'sent' => $first_ids];
    }

    // تکه‌بندی روی مرز خطوط + حفظ توازن تگ‌های HTML بین تکه‌ها.
    // (تلگرام HTML ناقص را رد می‌کند؛ قبلاً تکه اول ممکن بود با تگ بسته‌نشده تمام شود
    // و تکه دوم با تگ بستهٔ بی‌ربط شروع شود — نتیجه: کل پیام از دست می‌رفت)
    private function chunk_message($message, $limit) {
        $chunks = [];
        $current = '';
        foreach (explode("\n", (string) $message) as $line) {
            // خطوط بلندتر از سقف هم شکسته می‌شوند
            $parts = [];
            while (mb_strlen($line) > $limit) {
                $parts[] = mb_substr($line, 0, $limit);
                $line = mb_substr($line, $limit);
            }
            $parts[] = $line;

            foreach ($parts as $part) {
                $add = ($current === '' ? '' : "\n") . $part;
                if ($current !== '' && (mb_strlen($current) + mb_strlen($add)) > $limit) {
                    $open = $this->telegram_open_stack($current);
                    $chunks[] = $current . $this->close_tags_html($open);
                    // تگ‌های باز در تکه بعدی دوباره باز می‌شوند (به‌جز لینک که href لازم دارد)
                    $reopen = '';
                    foreach ($open as $t) {
                        if ($t !== 'a') {
                            $reopen .= '<' . $t . '>';
                        }
                    }
                    $current = $reopen . $part;
                } else {
                    $current .= $add;
                }
            }
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }
        if (empty($chunks)) {
            $chunks = [(string) $message];
        }
        return $chunks;
    }

    // پشته تگ‌های HTML شناخته‌شده تلگرام که در انتهای رشته باز مانده‌اند
    private function telegram_open_stack($html) {
        $known = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'code', 'pre', 'a', 'blockquote', 'tg-spoiler'];
        if (!preg_match_all('/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/', (string) $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        $stack = [];
        foreach ($m as $t) {
            $tag = strtolower($t[2]);
            if (!in_array($tag, $known, true)) {
                continue;
            }
            if ($t[1] === '/') {
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i] === $tag) {
                        array_splice($stack, $i, 1);
                        break;
                    }
                }
            } else {
                $stack[] = $tag;
            }
        }
        return $stack;
    }

    private function close_tags_html(array $stack) {
        $out = '';
        foreach (array_reverse($stack) as $t) {
            $out .= '</' . $t . '>';
        }
        return $out;
    }

    // برش ایمن: داخل تگ یا HTML-entity نمی‌برد و تگ‌های باز را در انتها می‌بندد
    private function html_safe_truncate($message, $limit) {
        $message = (string) $message;
        if (mb_strlen($message) <= $limit) {
            return $message;
        }
        $cut = mb_substr($message, 0, $limit);
        // برش داخل تگ؟
        $lt = mb_strrpos($cut, '<');
        $gt = mb_strrpos($cut, '>');
        if ($lt !== false && ($gt === false || $gt < $lt)) {
            $cut = mb_substr($cut, 0, $lt);
        }
        // برش داخل entity؟
        $amp = mb_strrpos($cut, '&');
        $semi = mb_strrpos($cut, ';');
        if ($amp !== false && ($semi === false || $semi < $amp) && (mb_strlen($cut) - $amp) <= 12) {
            $cut = mb_substr($cut, 0, $amp);
        }
        $cut .= "\n…";
        return $cut . $this->close_tags_html($this->telegram_open_stack($cut));
    }

    private function send_to_telegram($bot_token, $chat_id, $message) {
        $res = $this->telegram_api($bot_token, 'sendMessage', [
            'chat_id'                  => $chat_id,
            'text'                     => $message,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);
        // اگر HTML قالب مشکل داشت، یک بار به‌صورت متن ساده بفرست تا پیام کامل از دست نرود
        if (empty($res['ok']) && $this->is_parse_error($res['message'])) {
            $res = $this->telegram_api($bot_token, 'sendMessage', [
                'chat_id'                  => $chat_id,
                'text'                     => $this->html_to_plain($message),
                'disable_web_page_preview' => 'true',
            ]);
        }
        if (!empty($res['ok'])) {
            $res['message_id'] = isset($res['result']['message_id']) ? (int) $res['result']['message_id'] : 0;
        }
        return $res;
    }

    private function is_parse_error($desc) {
        $d = strtolower((string) $desc);
        return strpos($d, 'parse entities') !== false
            || strpos($d, 'parse error') !== false
            || strpos($d, 'unclosed') !== false
            || strpos($d, 'unterminated') !== false
            || strpos($d, 'unsupported start tag') !== false
            || strpos($d, 'tag must not be empty') !== false
            || strpos($d, 'not found at byte') !== false;
    }

    // تبدیل پیام HTML به متن ساده (لینک‌ها به شکل «متن (آدرس)» حفظ می‌شوند)
    private function html_to_plain($html) {
        $html = preg_replace('/<a\s[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', (string) $html);
        return trim(preg_replace("/\n{3,}/", "\n\n", wp_strip_all_tags($html)));
    }

    // فراخوانی عمومی Bot API — خروجی: ['ok', 'message', 'result' => آرایه پاسخ تلگرام]
    private function telegram_api($bot_token, $method, $params, $_retried = false) {
        // اگر تلگرام همین الان 429 داده، قبل از تلاش تازه تا پایان retry_after صبر کن (بین همهٔ درخواست‌ها مشترک)
        $until = (int) get_transient('wc_telegram_429_until');
        if ($until > time()) {
            $wait = min(30, $until - time());
            if ($wait > 0) {
                sleep($wait);
            }
        }
        $url = 'https://api.telegram.org/bot' . trim($bot_token) . '/' . $method;
        $response = wp_remote_post($url, [
            'timeout' => 15,
            'body'    => $params,
        ]);

        if (is_wp_error($response)) {
            $this->log('error', 'telegram', 'api_error', 'خطای ارتباط با تلگرام (' . $method . '): ' . $response->get_error_message(), ['method' => $method, 'error' => $response->get_error_message()], 0);
            return ['ok' => false, 'message' => $response->get_error_message(), 'result' => null];
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && !empty($body['ok'])) {
            return ['ok' => true, 'message' => 'OK', 'result' => isset($body['result']) ? $body['result'] : null];
        }
        // محدودیت نرخ تلگرام: طبق retry_after صبر و یک بار دوباره تلاش کن
        if ($code === 429 && !$_retried) {
            $wait = isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : 3;
            set_transient('wc_telegram_429_until', time() + $wait, max(1, $wait));
            $this->log('warning', 'telegram', 'rate_limited', sprintf('محدودیت نرخ تلگرام (429) هنگام %s؛ %d ثانیه صبر و تلاش مجدد.', $method, $wait), ['method' => $method, 'retry_after' => $wait], 0);
            sleep(max(1, min($wait, 30)));
            return $this->telegram_api($bot_token, $method, $params, true);
        }
        $desc = isset($body['description']) ? $body['description'] : ('HTTP ' . $code);
        $this->log('error', 'telegram', 'api_error', 'تلگرام خطا داد (' . $method . '): ' . $desc, ['method' => $method, 'code' => $code, 'description' => $desc], 0);
        return ['ok' => false, 'message' => $desc, 'result' => null];
    }

    /* ---------------- سیستم لاگ رویدادها ---------------- */

    // سطوح لاگ (هرچه عدد بزرگ‌تر، مهم‌تر)
    private function log_levels() {
        return [
            'debug'   => 'جزئی',
            'info'    => 'اطلاعات',
            'success' => 'موفق',
            'warning' => 'هشدار',
            'error'   => 'خطا',
        ];
    }

    private function log_level_rank($level) {
        $map = ['debug' => 10, 'info' => 20, 'success' => 25, 'warning' => 30, 'error' => 40];
        return isset($map[$level]) ? (int) $map[$level] : 20;
    }

    // بخش‌های مختلف افزونه که در لاگ تفکیک می‌شوند
    private function log_channels() {
        return [
            'order'    => 'سفارش',
            'stock'    => 'موجودی',
            'daily'    => 'گزارش روزانه',
            'telegram' => 'تلگرام',
            'settings' => 'تنظیمات',
            'system'   => 'سیستم',
        ];
    }

    private function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'wc_telegram_logs';
    }

    // ساخت/به‌روزرسانی جدول لاگ (dbDelta فقط در صورت نیاز تغییر می‌دهد)
    public function install_log_table() {
        global $wpdb;
        $table   = $this->log_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(20) NOT NULL DEFAULT 'info',
  channel varchar(32) NOT NULL DEFAULT 'system',
  event varchar(64) NOT NULL DEFAULT '',
  object_type varchar(20) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  message text NOT NULL,
  context longtext DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY level (level),
  KEY channel (channel),
  KEY object_id (object_id)
) {$charset};";

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta($sql);
        update_option(self::LOG_DB_OPTION, '1.0', false);
    }

    /**
     * ثبت یک ردیف لاگ.
     *
     * @param string $level     debug | info | success | warning | error
     * @param string $channel   order | stock | daily | telegram | settings | system
     * @param string $event     شناسه کوتاه رویداد (مثل alert_sent یا duplicate_blocked)
     * @param string $message   پیام خوانا برای مدیر
     * @param mixed  $context   داده‌های تکمیلی (آرایه → JSON)
     * @param int    $object_id شناسه سفارش/محصول مرتبط
     */
    public function log($level, $channel, $event, $message, $context = [], $object_id = 0) {
        global $wpdb;

        $s = $this->get_settings();
        if (empty($s['log_enabled']) || $s['log_enabled'] !== 'yes') {
            return false;
        }
        $level = strtolower((string) $level);
        if (!isset($this->log_levels()[$level])) {
            $level = 'info';
        }
        // آستانه ثبت: فقط سطوح بالاتر از حد تنظیم‌شده ذخیره می‌شوند
        $min = !empty($s['log_level']) ? strtolower((string) $s['log_level']) : 'info';
        if ($this->log_level_rank($level) < $this->log_level_rank($min)) {
            return false;
        }
        if (!$this->log_table_ready()) {
            return false;
        }

        $object_type = ($channel === 'order') ? 'order' : (($channel === 'stock') ? 'product' : '');

        $json = (is_array($context) || is_object($context))
            ? wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $context;
        if ($json !== '' && mb_strlen($json) > 12000) {
            $json = mb_substr($json, 0, 12000) . '…(بریده‌شده)';
        }

        $inserted = $wpdb->insert(
            $this->log_table(),
            [
                'created_at'  => gmdate('Y-m-d H:i:s'),
                'level'       => $level,
                'channel'     => sanitize_key((string) $channel),
                'event'       => mb_substr(sanitize_key((string) $event), 0, 64),
                'object_type' => $object_type,
                'object_id'   => (int) $object_id,
                'message'     => mb_substr(wp_strip_all_tags((string) $message), 0, 1900),
                'context'     => ($json === '' ? null : $json),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        // پاک‌سازی احتمالی (تقریباً ۲٪ درخواست‌ها) تا جدول بی‌انتها بزرگ نشود
        if ($inserted && wp_rand(1, 50) === 1) {
            $this->prune_logs();
        }
        return (bool) $inserted;
    }

    // حذف ردیف‌های قدیمی‌تر از بازه نگهداری + محدود کردن تعداد کل ردیف‌ها
    public function prune_logs() {
        global $wpdb;
        if (!$this->log_table_ready()) {
            return;
        }
        $s     = $this->get_settings();
        $days  = isset($s['log_retention_days']) ? (int) $s['log_retention_days'] : 30;
        $max   = isset($s['log_max_rows']) ? (int) $s['log_max_rows'] : 2000;
        $table = $this->log_table();

        if ($days > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS))
            ));
        }
        if ($max > 0) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            if ($count > $max) {
                $keep_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} ORDER BY id DESC LIMIT %d,1", $max - 1));
                if ($keep_id > 0) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id < %d", $keep_id));
                }
            }
        }
    }

    private function normalize_log_filters($args = []) {
        $levels   = array_keys($this->log_levels());
        $channels = array_keys($this->log_channels());
        $level    = isset($args['level']) ? strtolower(sanitize_key($args['level'])) : '';
        $channel  = isset($args['channel']) ? strtolower(sanitize_key($args['channel'])) : '';
        return [
            'level'     => in_array($level, $levels, true) ? $level : '',
            'channel'   => in_array($channel, $channels, true) ? $channel : '',
            'search'    => isset($args['search']) ? mb_substr(sanitize_text_field((string) $args['search']), 0, 120) : '',
            'object_id' => isset($args['object_id']) ? absint($args['object_id']) : 0,
            'since'     => isset($args['since']) ? (int) $args['since'] : 0,
            'per_page'  => min(500, max(10, isset($args['per_page']) ? (int) $args['per_page'] : 50)),
            'page'      => max(1, isset($args['page']) ? (int) $args['page'] : 1),
        ];
    }

    private function log_query_where($f) {
        global $wpdb;
        $where = ['1=1'];
        if ($f['level'] !== '') {
            $where[] = $wpdb->prepare('level = %s', $f['level']);
        }
        if ($f['channel'] !== '') {
            $where[] = $wpdb->prepare('channel = %s', $f['channel']);
        }
        if ($f['object_id'] > 0) {
            $where[] = $wpdb->prepare('object_id = %d', $f['object_id']);
        }
        if (!empty($f['since'])) {
            // created_at به وقت UTC ذخیره می‌شود
            $where[] = $wpdb->prepare('created_at >= %s', gmdate('Y-m-d H:i:s', (int) $f['since']));
        }
        if ($f['search'] !== '') {
            $like    = '%' . $wpdb->esc_like($f['search']) . '%';
            $where[] = $wpdb->prepare('(message LIKE %s OR event LIKE %s OR context LIKE %s)', $like, $like, $like);
        }
        return implode(' AND ', $where);
    }

    public function get_logs($args = []) {
        global $wpdb;
        if (!$this->log_table_ready()) {
            return [];
        }
        $f      = $this->normalize_log_filters($args);
        $where  = $this->log_query_where($f);
        $offset = ($f['page'] - 1) * $f['per_page'];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->log_table()} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $f['per_page'],
            $offset
        ));
    }

    public function count_logs($args = []) {
        global $wpdb;
        if (!$this->log_table_ready()) {
            return 0;
        }
        $f     = $this->normalize_log_filters($args);
        $where = $this->log_query_where($f);
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table()} WHERE {$where}");
    }

    public function log_counts() {
        global $wpdb;
        $out = ['total' => 0, 'error' => 0, 'warning' => 0, 'day' => 0];
        if (!$this->log_table_ready()) {
            return $out;
        }
        $table = $this->log_table();
        $rows  = $wpdb->get_results("SELECT level, COUNT(*) AS c FROM {$table} GROUP BY level");
        foreach ((array) $rows as $r) {
            $out['total'] += (int) $r->c;
            if (isset($out[$r->level])) {
                $out[$r->level] = (int) $r->c;
            }
        }
        $out['day'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
        ));
        return $out;
    }

    public function clear_logs() {
        global $wpdb;
        if (!$this->log_table_ready()) {
            return;
        }
        $wpdb->query('DELETE FROM ' . $this->log_table());
    }

    // ثبت تغییرات تنظیمات در لاگ — توکن ربات هرگز ثبت نمی‌شود
    public function log_settings_update($old_value, $new_value) {
        $old = is_array($old_value) ? $old_value : [];
        $new = is_array($new_value) ? $new_value : [];
        $changed = [];
        foreach ($new as $key => $value) {
            if ($key === 'bot_token') {
                continue; // امنیت: مقدار توکن در لاگ نوشته نشود
            }
            $before = isset($old[$key]) ? $old[$key] : null;
            if ((string) $before !== (string) $value) {
                $changed[] = $key;
            }
        }
        $old_token = isset($old['bot_token']) ? (string) $old['bot_token'] : '';
        $new_token = isset($new['bot_token']) ? (string) $new['bot_token'] : '';
        if ($old_token !== $new_token) {
            $changed[] = 'bot_token (مقدار ثبت نمی‌شود)';
        }
        if (empty($changed)) {
            return;
        }
        $this->log('info', 'settings', 'settings_saved', 'تنظیمات ذخیره شد: ' . implode('، ', $changed), ['changed' => $changed], 0);
    }

    public function handle_log_clear() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_log_clear_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $this->clear_logs();
        $this->log('info', 'system', 'logs_cleared', 'لاگ‌ها توسط مدیر پاک شدند.', [], 0);
        set_transient('wc_telegram_test_result', ['ok' => true, 'message' => '🗑️ لاگ‌ها پاک شدند.'], 60);
        $this->redirect_to_tab();
    }

    public function handle_log_csv() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wc_telegram_log_csv_nonce')) {
            wp_die('دسترسی غیرمجاز.');
        }
        $labels   = $this->log_levels();
        $channels = $this->log_channels();
        $f = $this->normalize_log_filters([
            'level'    => isset($_GET['log_level']) ? sanitize_key(wp_unslash($_GET['log_level'])) : '',
            'channel'  => isset($_GET['log_channel']) ? sanitize_key(wp_unslash($_GET['log_channel'])) : '',
            'search'   => isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '',
            'per_page' => 5000,
            'page'     => 1,
        ]);
        $rows = $this->get_logs($f);

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename=wc-telegram-logs-' . gmdate('Ymd-His') . '.csv');
        $handle = fopen('php://output', 'w');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM برای نمایش درست فارسی در اکسل
        fputcsv($handle, ['ردیف', 'زمان', 'سطح', 'بخش', 'رویداد', 'شناسه مرتبط', 'پیام', 'جزئیات']);
        foreach ((array) $rows as $r) {
            fputcsv($handle, [
                (int) $r->id,
                $this->format_date(get_option('date_format') . ' H:i:s', strtotime($r->created_at . ' UTC')),
                isset($labels[$r->level]) ? $labels[$r->level] : $r->level,
                isset($channels[$r->channel]) ? $channels[$r->channel] : $r->channel,
                $r->event,
                $r->object_id,
                $r->message,
                $r->context,
            ]);
        }
        fclose($handle);
        $this->log('info', 'system', 'logs_exported', sprintf('%d ردیف لاگ به صورت CSV خروجی گرفته شد.', count((array) $rows)), [], 0);
        exit;
    }

    // نشان رنگی سطح لاگ
    private function log_badge($level) {
        $labels = $this->log_levels();
        $lvl    = isset($labels[$level]) ? $level : 'info';
        return '<span class="wcto-badge wcto-badge--' . esc_attr($lvl) . '">' . esc_html($labels[$lvl]) . '</span>';
    }

    // لینک به سفارش/محصول مرتبط با هر ردیف لاگ
    private function log_object_link($row) {
        $id = (int) $row->object_id;
        if ($id <= 0) {
            return '—';
        }
        if ($row->object_type === 'order') {
            $hpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
                 && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $url  = $hpos
                ? admin_url('admin.php?page=wc-orders&action=edit&id=' . $id)
                : admin_url('post.php?post=' . $id . '&action=edit');
            return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">#' . $id . '</a>';
        }
        if ($row->object_type === 'product') {
            return '<a href="' . esc_url(admin_url('post.php?post=' . $id . '&action=edit')) . '" target="_blank" rel="noopener">#' . $id . '</a>';
        }
        return '—';
    }

    // بخش نمایش لاگ — پایین صفحه تنظیمات (هر دو تب)
    public function render_log_section($s, $opt) {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $levels   = $this->log_levels();
        $channels = $this->log_channels();
        $tab      = $this->current_tab();

        $f = $this->normalize_log_filters([
            'level'    => isset($_GET['log_level']) ? sanitize_key(wp_unslash($_GET['log_level'])) : '',
            'channel'  => isset($_GET['log_channel']) ? sanitize_key(wp_unslash($_GET['log_channel'])) : '',
            'search'   => isset($_GET['log_search']) ? sanitize_text_field(wp_unslash($_GET['log_search'])) : '',
            'per_page' => isset($_GET['log_per']) ? (int) $_GET['log_per'] : 50,
            'page'     => isset($_GET['log_page']) ? (int) $_GET['log_page'] : 1,
        ]);

        $ready = $this->log_table_ready();
        $rows  = $ready ? $this->get_logs($f) : [];
        $total = $ready ? $this->count_logs($f) : 0;
        $pages = max(1, (int) ceil($total / max(1, $f['per_page'])));
        if ($f['page'] > $pages) {
            $f['page'] = $pages;
            $rows      = $ready ? $this->get_logs($f) : [];
        }
        $counts = $ready ? $this->log_counts() : ['total' => 0, 'error' => 0, 'warning' => 0, 'day' => 0];

        $base_url = admin_url('admin.php?page=wc-telegram-orders&tab=' . rawurlencode($tab));
        $keep     = [];
        if ($f['level'] !== '') {
            $keep['log_level'] = $f['level'];
        }
        if ($f['channel'] !== '') {
            $keep['log_channel'] = $f['channel'];
        }
        if ($f['search'] !== '') {
            $keep['log_search'] = $f['search'];
        }
        if ($f['per_page'] !== 50) {
            $keep['log_per'] = $f['per_page'];
        }
        $page_url = function ($page) use ($base_url, $keep) {
            return esc_url(add_query_arg(array_merge($keep, ['log_page' => (int) $page]), $base_url));
        };
        $csv_url = esc_url(wp_nonce_url(
            add_query_arg(array_merge($keep, ['action' => 'wc_telegram_log_csv']), admin_url('admin-post.php')),
            'wc_telegram_log_csv_nonce'
        ));
        ?>
        <section class="wcto-card wcto-log" id="wcto-log">
        <div class="wcto-card-head"><span class="wcto-dot wcto-dot--muted"></span><div><h2>لاگ رویدادها</h2><p>ارسال سفارش، اعلان موجودی، خطاهای تلگرام، تغییر تنظیمات، اجرای کرون و … اینجا ثبت می‌شود.
            <?php if (empty($s['log_enabled']) || $s['log_enabled'] !== 'yes'): ?>
                <b class="wcto-warn">در حال حاضر ضبط لاگ غیرفعال است.</b>
            <?php endif; ?>
            <?php if (!$ready): ?>
                <b class="wcto-warn">جدول لاگ ساخته نشد — افزونه را یک بار غیرفعال و دوباره فعال کنید.</b>
            <?php endif; ?></p></div></div>
        <div class="wcto-card-body">

        <div class="wcto-kpis">
            <div class="wcto-kpi"><div class="t">کل ردیف‌ها</div><div class="v"><?php echo esc_html(number_format_i18n((int) $counts['total'])); ?></div></div>
            <div class="wcto-kpi"><div class="t">۲۴ ساعت اخیر</div><div class="v"><?php echo esc_html(number_format_i18n((int) $counts['day'])); ?></div></div>
            <a class="wcto-kpi wcto-kpi--bad" href="<?php echo esc_url(add_query_arg(array_merge($keep, ['log_level' => 'error']), $base_url)); ?>"><div class="t">خطاها</div><div class="v"><?php echo esc_html(number_format_i18n((int) $counts['error'])); ?></div></a>
            <a class="wcto-kpi wcto-kpi--warn" href="<?php echo esc_url(add_query_arg(array_merge($keep, ['log_level' => 'warning']), $base_url)); ?>"><div class="t">هشدارها</div><div class="v"><?php echo esc_html(number_format_i18n((int) $counts['warning'])); ?></div></a>
        </div>

        <form method="get" class="wcto-filters">
            <input type="hidden" name="page" value="wc-telegram-orders" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
            <select name="log_level" class="tisa-input tisa-input--sm">
                <option value="">همه سطوح</option>
                <?php foreach ($levels as $slug => $label): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($f['level'], $slug); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="log_channel" class="tisa-input tisa-input--sm">
                <option value="">همه بخش‌ها</option>
                <?php foreach ($channels as $slug => $label): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($f['channel'], $slug); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" name="log_search" value="<?php echo esc_attr($f['search']); ?>"
                   placeholder="جستجو در پیام / رویداد / جزئیات" class="tisa-input tisa-input--sm wcto-search" />
            <select name="log_per" class="tisa-input tisa-input--sm">
                <?php foreach ([25, 50, 100, 200] as $n): ?>
                    <option value="<?php echo (int) $n; ?>" <?php selected($f['per_page'], $n); ?>><?php echo (int) $n; ?> ردیف</option>
                <?php endforeach; ?>
            </select>
            <button class="tisa-btn tisa-btn--secondary tisa-btn--sm">اعمال فیلتر</button>
            <a class="tisa-btn tisa-btn--ghost tisa-btn--sm" href="<?php echo esc_url($base_url); ?>">حذف فیلترها</a>
            <a class="tisa-btn tisa-btn--ghost tisa-btn--sm" href="<?php echo esc_url(add_query_arg($keep, $base_url)); ?>">بروزرسانی</a>
        </form>

        <div class="tisa-table-scroll">
        <table class="tisa-table wcto-table">
            <thead>
                <tr>
                    <th class="col-time">زمان</th>
                    <th class="col-level">سطح</th>
                    <th class="col-chan">بخش</th>
                    <th class="col-event">رویداد</th>
                    <th>پیام</th>
                    <th class="col-obj">مرتبط</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="wcto-empty">هیچ ردیفی با این فیلتر پیدا نشد.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="wcto-time">
                                <?php echo esc_html($this->format_date(get_option('date_format') . ' H:i:s', strtotime($r->created_at . ' UTC'))); ?>
                            </td>
                            <td><?php echo $this->log_badge($r->level); ?></td>
                            <td><?php echo esc_html(isset($channels[$r->channel]) ? $channels[$r->channel] : $r->channel); ?></td>
                            <td><span class="tisa-code" dir="ltr"><?php echo esc_html($r->event); ?></span></td>
                            <td>
                                <?php echo esc_html($r->message); ?>
                                <?php if (!empty($r->context)): ?>
                                    <details class="wcto-details">
                                        <summary>جزئیات</summary>
                                        <pre dir="auto" class="wcto-pre"><?php
                                            $decoded = json_decode((string) $r->context, true);
                                            echo esc_html($decoded === null
                                                ? (string) $r->context
                                                : wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                                        ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $this->log_object_link($r); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="wcto-pager">
                <span class="wcto-hint">صفحه <?php echo esc_html(number_format_i18n((int) $f['page'])); ?> از <?php echo esc_html(number_format_i18n((int) $pages)); ?> — <?php echo esc_html(number_format_i18n((int) $total)); ?> ردیف</span>
                <?php if ($f['page'] > 1): ?>
                    <a class="tisa-btn tisa-btn--ghost tisa-btn--sm" href="<?php echo $page_url($f['page'] - 1); ?>">قبلی</a>
                <?php endif; ?>
                <?php
                $from = max(1, $f['page'] - 2);
                $to   = min($pages, $from + 4);
                $from = max(1, $to - 4);
                for ($p = $from; $p <= $to; $p++):
                    ?>
                    <a class="tisa-btn tisa-btn--sm <?php echo $p === $f['page'] ? 'tisa-btn--secondary is-current' : 'tisa-btn--ghost'; ?>" href="<?php echo $page_url($p); ?>"><?php echo esc_html(number_format_i18n((int) $p)); ?></a>
                <?php endfor; ?>
                <?php if ($f['page'] < $pages): ?>
                    <a class="tisa-btn tisa-btn--ghost tisa-btn--sm" href="<?php echo $page_url($f['page'] + 1); ?>">بعدی</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="wcto-inline wcto-log-actions">
            <a class="tisa-btn tisa-btn--secondary tisa-btn--sm" href="<?php echo $csv_url; ?>">خروجی CSV</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('همه ردیف‌های لاگ حذف می‌شوند. مطمئن هستید؟');">
                <input type="hidden" name="action" value="wc_telegram_log_clear" />
                <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
                <?php wp_nonce_field('wc_telegram_log_clear_nonce'); ?>
                <button type="submit" class="tisa-btn tisa-btn--ghost tisa-btn--sm wcto-danger-link">پاک کردن لاگ‌ها</button>
            </form>
        </div>

        <form method="post" action="options.php" class="wcto-log-settings">
            <?php settings_fields('wc_telegram_orders_group'); ?>
            <input type="hidden" name="<?php echo esc_attr($opt); ?>[_tab]" value="<?php echo esc_attr($tab); ?>" />
            <div class="wcto-row wcto-row--4">
                <div class="wcto-field">
                    <span class="wcto-label">ضبط لاگ</span>
                    <label class="tisa-switch wcto-switch"><input type="checkbox" name="<?php echo esc_attr($opt); ?>[log_enabled]" value="yes" <?php checked(isset($s['log_enabled']) ? $s['log_enabled'] : 'yes', 'yes'); ?> /><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>
                </div>
                <div class="wcto-field">
                    <label class="wcto-label" for="wc-tg-log-level">حداقل سطح</label>
                    <select id="wc-tg-log-level" name="<?php echo esc_attr($opt); ?>[log_level]" class="tisa-input">
                        <?php
                        $min_levels = ['debug' => 'همه چیز', 'info' => 'اطلاعات به بالا', 'warning' => 'هشدار و خطا', 'error' => 'فقط خطا'];
                        foreach ($min_levels as $slug => $label):
                            ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected(isset($s['log_level']) ? $s['log_level'] : 'info', $slug); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wcto-field">
                    <label class="wcto-label" for="wc-tg-log-days">نگهداری</label>
                    <div class="wcto-unit"><input type="number" id="wc-tg-log-days" name="<?php echo esc_attr($opt); ?>[log_retention_days]" value="<?php echo (int) (isset($s['log_retention_days']) ? $s['log_retention_days'] : 30); ?>" min="0" max="365" step="1" dir="ltr" class="tisa-input" /><span>روز</span></div>
                </div>
                <div class="wcto-field">
                    <label class="wcto-label" for="wc-tg-log-max">حداکثر ردیف</label>
                    <input type="number" id="wc-tg-log-max" name="<?php echo esc_attr($opt); ?>[log_max_rows]" value="<?php echo (int) (isset($s['log_max_rows']) ? $s['log_max_rows'] : 2000); ?>" min="100" max="50000" step="100" dir="ltr" class="tisa-input" />
                </div>
            </div>
            <div class="wcto-actions"><button type="submit" class="tisa-btn tisa-btn--secondary">ذخیرهٔ تنظیمات لاگ</button></div>
        </form>
        </div>
        </section>
        <?php
    }

    /* ---------------- سنجاق (پین) گزارش ---------------- */

    // پیام‌های ارسال‌شده ([chat_id => message_id]) را سنجاق می‌کند و سنجاق گزارش قبلی همان چت را برمی‌دارد.
    // بی‌صدا (disable_notification) تا اعضای گروه دوبار نوتیف نگیرند.
    public function pin_report_messages(array $sent) {
        $s = $this->get_settings();
        if (empty($s['bot_token']) || empty($sent)) {
            return ['ok' => false, 'message' => ''];
        }
        $pinned = get_option(self::PINNED_OPTION, []);
        if (!is_array($pinned)) {
            $pinned = [];
        }
        $ok_count = 0;
        $errors = [];
        foreach ($sent as $chat_id => $message_id) {
            $chat_id = (string) $chat_id;
            $message_id = (int) $message_id;
            if ($message_id <= 0) {
                continue;
            }
            $res = $this->telegram_api($s['bot_token'], 'pinChatMessage', [
                'chat_id'              => $chat_id,
                'message_id'           => $message_id,
                'disable_notification' => 'true',
            ]);
            if (empty($res['ok'])) {
                $errors[] = $chat_id . ': ' . $this->humanize_pin_error($res['message']);
                continue;
            }
            $ok_count++;
            // اول سنجاق جدید، بعد برداشتن قبلی — اگر دسترسی نبود، سنجاق قبلی هم از دست نمی‌رود
            $prev = isset($pinned[$chat_id]) ? (int) $pinned[$chat_id] : 0;
            if ($prev > 0 && $prev !== $message_id) {
                $this->telegram_api($s['bot_token'], 'unpinChatMessage', [
                    'chat_id'    => $chat_id,
                    'message_id' => $prev,
                ]); // خطا مهم نیست (شاید قبلاً دستی برداشته یا پاک شده)
            }
            $pinned[$chat_id] = $message_id;
        }
        update_option(self::PINNED_OPTION, $pinned, false);

        if ($errors) {
            $this->log('warning', 'daily', 'pin_failed', 'سنجاق گزارش روزانه ناموفق بود: ' . implode(' | ', $errors), ['errors' => $errors], 0);
            return ['ok' => false, 'message' => '📌 سنجاق نشد — ' . implode(' | ', $errors)];
        }
        if ($ok_count) {
            $this->log('success', 'daily', 'pinned', sprintf('گزارش روزانه در %d چت سنجاق شد.', $ok_count), ['chats' => array_keys($sent)], 0);
        }
        return ['ok' => true, 'message' => $ok_count ? '📌 گزارش سنجاق شد.' : ''];
    }

    private function humanize_pin_error($desc) {
        $d = strtolower((string) $desc);
        if (strpos($d, 'not enough rights') !== false || strpos($d, 'chat_admin_required') !== false || strpos($d, 'administrator') !== false) {
            return 'ربات دسترسی «Pin Messages» ندارد؛ ربات را ادمین کنید و اجازه سنجاق را فعال کنید.';
        }
        if (strpos($d, 'message to pin not found') !== false) {
            return 'پیام برای سنجاق پیدا نشد.';
        }
        return (string) $desc;
    }
}

new WC_Telegram_Orders();
