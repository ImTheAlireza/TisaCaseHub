<?php
/**
 * Plugin Name: Automatic Postal Tracking for WooCommerce
 * Plugin URI: https://github.com/ImTheAlireza/TisaCaseHub
 * Description: کد رهگیری پستی را بدون فایل و بدون کار دستی از سورناپست/افزونه‌های حمل‌ونقل برمی‌دارد، روی سفارش ذخیره می‌کند و به مشتری با شمارهٔ سفارش نشان می‌دهد.
 * Version: 3.3.0
 * Author: علیرضا شعبان زاده
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 *
 * @package Bulk_Tracking_Upload
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BWT_VERSION', '3.3.0');
define('BWT_OPTION_SETTINGS', 'bwt_settings');
define('BWT_OPTION_LAST_SWEEP', 'bwt_last_sweep');
define('BWT_SYNC_BATCH_SIZE', 50);
define('BWT_SYNC_MAX_ORDERS', 500);
define('BWT_SWEEP_MAX_ORDERS', 50);
define('BWT_SWEEP_DAYS', 45);
define('BWT_CLEANUP_BATCH_SIZE', 500);
define('BWT_CLEANUP_SELECTED_BATCH_SIZE', 50);
define('BWT_CLEANUP_MAX_SELECTED', 1000);
define('BWT_TRACK_MAX_CODES', 10);
define('BWT_TRACK_URL_DEFAULT', 'https://tracking.post.ir/?id=%s');
// فایل PDF جایگزین (وقتی جست‌وجوی مشتری خطا می‌دهد داخل فرم نمایش داده می‌شود).
define('BWT_FALLBACK_PDF_DEFAULT', 'https://tisacase.com/wp-content/uploads/2026/10/tracking.pdf');

// ========== 1. تنظیمات ==========
function bwt_default_settings() {
    return array(
        'source_keys'   => array(),
        'scan_notes'    => 1,
        'sweep_enabled' => 1,
        'push_enabled'  => 0,
        'push_secret'   => '',
        'tracking_url'  => BWT_TRACK_URL_DEFAULT,
        'fallback_pdf'  => BWT_FALLBACK_PDF_DEFAULT,
        'table_source'  => array('table' => '', 'order_col' => '', 'code_col' => ''),
    );
}

/** نام جدول/ستون فقط با حروف لاتین، رقم و آندرلاین (برای درج امن در SQL). */
function bwt_sanitize_identifier($value) {
    $value = is_scalar($value) ? trim((string) $value) : '';
    return preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) ? $value : '';
}

function bwt_sanitize_table_source($input) {
    $input = is_array($input) ? $input : array();
    $clean = array(
        'table'     => bwt_sanitize_identifier(isset($input['table']) ? $input['table'] : ''),
        'order_col' => bwt_sanitize_identifier(isset($input['order_col']) ? $input['order_col'] : ''),
        'code_col'  => bwt_sanitize_identifier(isset($input['code_col']) ? $input['code_col'] : ''),
    );
    if ($clean['table'] === '' || $clean['order_col'] === '' || $clean['code_col'] === '' || $clean['order_col'] === $clean['code_col']) {
        return array('table' => '', 'order_col' => '', 'code_col' => '');
    }
    return $clean;
}

function bwt_sanitize_source_key($key) {
    $key = is_scalar($key) ? (string) $key : '';
    $key = trim($key);
    if ($key === '' || strlen($key) > 191) {
        return '';
    }
    // کلید متای وردپرس: حروف/رقم/ـ/دوطبع؛ کلید یادداشت سفارش هم پذیرفته می‌شود.
    return preg_match('/^[A-Za-z0-9_\-:.]{1,191}$/', $key) ? $key : '';
}

function bwt_sanitize_settings($input) {
    $defaults = bwt_default_settings();
    $input = is_array($input) ? $input : array();

    $keys = array();
    foreach ((array) (isset($input['source_keys']) ? $input['source_keys'] : array()) as $key) {
        $key = bwt_sanitize_source_key($key);
        if ($key !== '' && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
        if (count($keys) >= 20) {
            break;
        }
    }

    $url = isset($input['tracking_url']) ? trim((string) $input['tracking_url']) : '';
    if ($url === '' || strpos($url, '%s') === false || strpos($url, 'http') !== 0) {
        $url = BWT_TRACK_URL_DEFAULT;
    }

    // فایل PDF جایگزین: خالی = غیرفعال؛ فقط http/https پذیرفته می‌شود.
    $fallback_pdf = isset($input['fallback_pdf']) ? trim((string) $input['fallback_pdf']) : (string) $defaults['fallback_pdf'];
    if ($fallback_pdf !== '') {
        $fallback_pdf = esc_url_raw($fallback_pdf);
        if (!preg_match('#^https?://#i', $fallback_pdf)) {
            $fallback_pdf = '';
        }
    }

    $secret = isset($input['push_secret']) ? (string) $input['push_secret'] : (string) $defaults['push_secret'];
    $secret = preg_replace('/[^A-Za-z0-9_\-]/', '', $secret);
    if (strlen($secret) > 64) {
        $secret = substr($secret, 0, 64);
    }

    return array(
        'source_keys'   => $keys,
        'scan_notes'    => empty($input['scan_notes']) ? 0 : 1,
        'sweep_enabled' => empty($input['sweep_enabled']) ? 0 : 1,
        'push_enabled'  => empty($input['push_enabled']) ? 0 : 1,
        'push_secret'   => $secret,
        'tracking_url'  => $url,
        'fallback_pdf'  => $fallback_pdf,
        'table_source'  => bwt_sanitize_table_source(isset($input['table_source']) ? $input['table_source'] : array()),
    );
}

function bwt_get_settings() {
    $stored = get_option(BWT_OPTION_SETTINGS, array());
    if (!is_array($stored)) {
        $stored = array();
    }
    $merged = array_merge(bwt_default_settings(), $stored);
    return bwt_sanitize_settings($merged);
}

function bwt_save_settings($settings) {
    $clean = bwt_sanitize_settings($settings);
    update_option(BWT_OPTION_SETTINGS, $clean, false);
    return $clean;
}

function bwt_generate_push_secret() {
    try {
        return 'bwt_' . bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return 'bwt_' . md5(uniqid('bwt-push', true));
    }
}

// ========== 2. ابزارهای کد رهگیری ==========
/** تبدیل ارقام فارسی/عربی به لاتین (بدون تغییر بقیهٔ کاراکترها). */
function bwt_normalize_digits($str) {
    $str = (string) $str;
    $map = array(
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    );
    return strtr($str, $map);
}

/** پاک‌سازی کد: حذف فاصله/خط‌تیره، بزرگ‌کردن حروف، سقف طول. */
function bwt_clean_code($value) {
    if (!is_scalar($value) || is_bool($value)) {
        return '';
    }
    $code = bwt_normalize_digits(trim((string) $value));
    $code = preg_replace('/[\s\x{200c}\x{200f}\x{200e}\-_]+/u', '', $code);
    if (!is_string($code)) {
        return '';
    }
    $code = strtoupper($code);
    if (strlen($code) < 4 || strlen($code) > 60) {
        return '';
    }
    return $code;
}

/** شکل‌های شناخته‌شدهٔ کد رهگیری: بارکد ملی پست (۲۴ رقم)، استاندارد UPU و پیشوند حرفی. */
function bwt_is_tracking_like($value) {
    $code = bwt_clean_code($value);
    if ($code === '') {
        return false;
    }
    if (preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $code)) {
        return true;
    }
    if (preg_match('/^\d{10,30}$/', $code)) {
        return true;
    }
    if (preg_match('/^[A-Z]{2,6}\d{8,24}$/', $code)) {
        return true;
    }
    return false;
}

function bwt_unique_codes($codes) {
    $out = array();
    foreach ((array) $codes as $code) {
        $clean = bwt_clean_code($code);
        if ($clean === '' || !bwt_is_tracking_like($clean)) {
            continue;
        }
        if (!in_array($clean, $out, true)) {
            $out[] = $clean;
        }
        if (count($out) >= BWT_TRACK_MAX_CODES) {
            break;
        }
    }
    return $out;
}

/** کلیدهایی که به‌طور معمول کد رهگیری را نگه می‌دارند (قابل توسعه با فیلتر). */
function bwt_builtin_tracking_keys() {
    $keys = array(
        '_tracking_code',
        '_tracking_number',
        '_tracking_no',
        'tracking_code',
        'tracking_number',
        '_post_tracking_code',
        '_post_tracking_number',
        '_post_barcode',
        '_barcode',
        'barcode',
        '_sorna_tracking_code',
        '_sorna_post_tracking_code',
        '_sorna_tracking_number',
        '_sorna_barcode',
        '_shipment_tracking_code',
        '_shipment_tracking_number',
        '_wc_shipment_tracking_items',
        'wc_shipment_tracking_items',
        '_wcst_tracking_number',
        '_delivery_tracking_code',
    );
    return (array) apply_filters('bwt_tracking_meta_keys', $keys);
}

/** کلیدهای عمومی که «شبیه» نگه‌دارندهٔ کد رهگیری‌اند. */
function bwt_key_looks_like_tracking($key) {
    $key = (string) $key;
    if ($key === '') {
        return false;
    }
    return (bool) preg_match('/(track|barcode|رهگیری|بارکد|post_?code|shipping_?code|parcel)/iu', $key);
}

/** استخراج کد از هر شکل مقدار: رشته، عدد، آرایهٔ تودرتو، JSON، آبجکت. */
function bwt_extract_codes_from_value($value, $depth = 0) {
    if ($depth > 3) {
        return array();
    }
    if (is_object($value)) {
        $value = (array) $value;
    }
    if (is_array($value)) {
        $preferred = array('tracking_number', 'tracking_code', 'tracking_no', 'barcode', 'code', 'number');
        $codes = array();
        foreach ($preferred as $field) {
            if (isset($value[$field])) {
                $codes = array_merge($codes, bwt_extract_codes_from_value($value[$field], $depth + 1));
            }
        }
        if ($codes) {
            return bwt_unique_codes($codes);
        }
        foreach ($value as $item) {
            $codes = array_merge($codes, bwt_extract_codes_from_value($item, $depth + 1));
        }
        return bwt_unique_codes($codes);
    }
    if (is_bool($value) || $value === null) {
        return array();
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return array();
    }
    if (preg_match('/^[\[\{]/', $raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return bwt_extract_codes_from_value($decoded, $depth + 1);
        }
    }
    return bwt_is_tracking_like($raw) ? array(bwt_clean_code($raw)) : array();
}

/** نقشهٔ همهٔ متاهای سفارش، مستقل از ذخیره‌سازی (HPOS یا postmeta). */
function bwt_order_meta_map($order) {
    $map = array();
    if (!is_object($order)) {
        return $map;
    }
    if (method_exists($order, 'get_meta_data')) {
        foreach ((array) $order->get_meta_data() as $meta) {
            if (is_object($meta) && method_exists($meta, 'get_data')) {
                $data = (array) $meta->get_data();
            } elseif (is_array($meta)) {
                $data = $meta;
            } else {
                continue;
            }
            if (!isset($data['key']) || !is_scalar($data['key'])) {
                continue;
            }
            $key = (string) $data['key'];
            if ($key === '') {
                continue;
            }
            $map[$key] = isset($data['value']) ? $data['value'] : '';
        }
    }
    if (!$map && function_exists('get_post_meta') && method_exists($order, 'get_id')) {
        $all = get_post_meta((int) $order->get_id());
        if (is_array($all)) {
            foreach ($all as $key => $values) {
                $value = is_array($values) && count($values) === 1 ? $values[0] : $values;
                $map[(string) $key] = $value;
            }
        }
    }
    return $map;
}

/** کد رهگیری از یادداشت‌های سفارش (سورناپست گاهی فقط یادداشت می‌گذارد). */
function bwt_extract_codes_from_order_notes($order) {
    if (!is_object($order)) {
        return array();
    }
    $contents = array();
    if (method_exists($order, 'get_customer_order_notes')) {
        foreach ((array) $order->get_customer_order_notes() as $note) {
            if (is_object($note) && isset($note->content)) {
                $contents[] = (string) $note->content;
            } elseif (is_array($note) && isset($note['content'])) {
                $contents[] = (string) $note['content'];
            }
        }
    }
    if (!$contents && function_exists('wc_get_order_notes') && method_exists($order, 'get_id')) {
        $notes = wc_get_order_notes(array('order_id' => (int) $order->get_id(), 'limit' => 50));
        foreach ((array) $notes as $note) {
            if (is_object($note) && isset($note->content)) {
                $contents[] = (string) $note->content;
            } elseif (is_array($note) && isset($note['content'])) {
                $contents[] = (string) $note['content'];
            }
        }
    }

    $codes = array();
    foreach ($contents as $content) {
        if (!preg_match('/(رهگیری|بارکد|مرسوله|tracking|barcode|parcel)/iu', $content)) {
            continue;
        }
        $normalized = bwt_normalize_digits($content);
        if (preg_match_all('/[A-Za-z]{2}\d{9}[A-Za-z]{2}|\d{10,30}/', $normalized, $matches)) {
            $codes = array_merge($codes, bwt_unique_codes($matches[0]));
        }
    }
    return bwt_unique_codes($codes);
}

/**
 * حل کد رهگیری یک سفارش از همهٔ منابع.
 * خروجی: source (کلید برنده)، codes (کدهای برنده)، sources (همهٔ منابع پیدا‌شده).
 */
function bwt_resolve_order_tracking($order) {
    $meta = bwt_order_meta_map($order);
    $settings = bwt_get_settings();

    $ordered_keys = array();
    foreach ((array) $settings['source_keys'] as $key) {
        $ordered_keys[] = (string) $key;
    }
    foreach (bwt_builtin_tracking_keys() as $key) {
        $ordered_keys[] = (string) $key;
    }
    $meta_keys = array_keys($meta);
    sort($meta_keys);
    foreach ($meta_keys as $key) {
        if (bwt_key_looks_like_tracking($key)) {
            $ordered_keys[] = (string) $key;
        }
    }

    $sources = array();
    foreach (array_unique($ordered_keys) as $key) {
        if ($key === '' || !isset($meta[$key])) {
            continue;
        }
        $codes = bwt_extract_codes_from_value($meta[$key]);
        if ($codes) {
            $sources[$key] = $codes;
        }
    }

    $notes_selected = in_array('order_note', (array) $settings['source_keys'], true);
    if (!empty($settings['scan_notes']) || $notes_selected) {
        $note_codes = bwt_extract_codes_from_order_notes($order);
        if ($note_codes) {
            $sources['order_note'] = $note_codes;
        }
    }

    // آخرین راه‌حل: جدول اختصاصی سرویس پستی (اگر ادمین معرفی کرده باشد).
    if (!empty($settings['table_source']['table']) && method_exists($order, 'get_id')) {
        $table_codes = bwt_custom_table_codes((int) $order->get_id());
        if ($table_codes) {
            $sources['table:' . $settings['table_source']['table'] . '.' . $settings['table_source']['code_col']] = $table_codes;
        }
    }

    $canonical_keys = array('_tracking_code', '_tracking_codes');
    $winner_key = '';
    $winner_codes = array();
    foreach ($sources as $key => $codes) {
        if (in_array($key, $canonical_keys, true)) {
            continue;
        }
        $winner_key = $key;
        $winner_codes = $codes;
        break;
    }
    $external = $winner_key !== '';
    if (!$external) {
        foreach ($canonical_keys as $key) {
            if (!empty($sources[$key])) {
                $winner_codes = $sources[$key];
                break;
            }
        }
    }

    return array(
        'source'   => $winner_key,
        'codes'    => $winner_codes,
        'sources'  => $sources,
        'external' => $external,
    );
}

function bwt_tracking_url($code) {
    $settings = bwt_get_settings();
    $template = isset($settings['tracking_url']) ? (string) $settings['tracking_url'] : '';
    if ($template === '' || strpos($template, '%s') === false) {
        $template = BWT_TRACK_URL_DEFAULT;
    }
    return sprintf($template, rawurlencode((string) $code));
}

function bwt_flush_order_counts_cache() {
    delete_transient('bwt_count_with_code');
    delete_transient('bwt_count_without_code');
}

/**
 * نوشتن کد(ها) روی سفارش + ثبت منبع. تنها نقطهٔ نوشتن متای کد است.
 */
function bwt_apply_tracking_codes($order, $codes, $source = '', $context = 'sync', $save = true) {
    $codes = bwt_unique_codes($codes);
    if (!is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'update_meta_data') || !$codes) {
        return array('status' => 'no_source', 'order_id' => 0, 'codes' => array(), 'source' => '');
    }
    $order_id = (int) $order->get_id();
    if ($order_id <= 0) {
        return array('status' => 'no_source', 'order_id' => 0, 'codes' => array(), 'source' => '');
    }

    $current = method_exists($order, 'get_meta') ? bwt_clean_code($order->get_meta('_tracking_code')) : '';
    $stored_list = method_exists($order, 'get_meta') ? $order->get_meta('_tracking_codes') : array();
    $stored_list = is_array($stored_list) ? bwt_unique_codes($stored_list) : array();
    $stored_source = method_exists($order, 'get_meta') ? (string) $order->get_meta('_bwt_tracking_source') : '';
    $primary = $codes[0];
    if (!$stored_list && $current !== '') {
        // سفارش‌های تک‌کدی که فهرست جدا ندارند، برای مقایسه یکتا می‌شوند.
        $stored_list = array($current);
    }

    if ($current === $primary && $stored_list === $codes && ($source === '' || $stored_source === $source)) {
        return array('status' => 'unchanged', 'order_id' => $order_id, 'codes' => $codes, 'source' => $stored_source);
    }

    $order->update_meta_data('_tracking_code', $primary);
    if (count($codes) > 1) {
        $order->update_meta_data('_tracking_codes', array_values($codes));
    } elseif (method_exists($order, 'delete_meta_data')) {
        $order->delete_meta_data('_tracking_codes');
    }
    if ($source !== '') {
        $order->update_meta_data('_bwt_tracking_source', $source);
    }
    $order->update_meta_data('_bwt_tracking_synced_at', time());
    if (method_exists($order, 'delete_meta_data')) {
        // نوشتن عمدی کد، قفل «همگام‌سازی خودکار خاموش» را برمی‌دارد.
        $order->delete_meta_data('_bwt_autosync_off');
    }
    if ($save && method_exists($order, 'save')) {
        $order->save();
    }
    bwt_flush_order_counts_cache();
    do_action('bwt_tracking_synced', $order_id, $codes, $source, $context);

    return array(
        'status'   => $current === '' ? 'created' : 'updated',
        'order_id' => $order_id,
        'codes'    => $codes,
        'source'   => $source,
    );
}

function bwt_order_autosync_disabled($order) {
    if (!is_object($order) || !method_exists($order, 'get_meta')) {
        return false;
    }
    return (bool) $order->get_meta('_bwt_autosync_off');
}

/** همگام‌سازی یک سفارش از منابع بیرونی. */
function bwt_sync_order_tracking($order, $context = 'event') {
    static $running = array();
    if (!is_object($order) || !method_exists($order, 'get_id')) {
        return array('status' => 'skipped', 'order_id' => 0, 'codes' => array(), 'source' => '');
    }
    $order_id = (int) $order->get_id();
    if ($order_id <= 0) {
        return array('status' => 'skipped', 'order_id' => 0, 'codes' => array(), 'source' => '');
    }
    if (!empty($running[$order_id])) {
        return array('status' => 'skipped', 'order_id' => $order_id, 'codes' => array(), 'source' => '');
    }
    $running[$order_id] = true;
    try {
        if (bwt_order_autosync_disabled($order)) {
            return array('status' => 'disabled', 'order_id' => $order_id, 'codes' => array(), 'source' => '');
        }
        $resolved = bwt_resolve_order_tracking($order);
        if (empty($resolved['codes']) || empty($resolved['external'])) {
            return array('status' => 'no_source', 'order_id' => $order_id, 'codes' => array(), 'source' => '');
        }
        $result = bwt_apply_tracking_codes($order, $resolved['codes'], $resolved['source'], $context);
        if (!isset($result['order_id']) || !$result['order_id']) {
            $result['order_id'] = $order_id;
        }
        return $result;
    } finally {
        unset($running[$order_id]);
    }
}

function bwt_is_order_syncable($order) {
    if (!is_object($order) || !method_exists($order, 'get_status')) {
        return true;
    }
    $status = (string) $order->get_status();
    return !in_array($status, array('checkout-draft', 'trash', 'auto-draft'), true);
}

// ========== 2.b کار با دیتابیس: جدول اختصاصی و پیدا کردن کلید ==========
function bwt_db_table_exists($table) {
    global $wpdb;
    static $cache = array();
    $table = bwt_sanitize_identifier($table);
    if ($table === '' || !is_object($wpdb) || !method_exists($wpdb, 'get_var')) {
        return false;
    }
    if (!array_key_exists($table, $cache)) {
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $cache[$table] = ($found === $table);
    }
    return $cache[$table];
}

function bwt_db_table_columns($table) {
    global $wpdb;
    static $cache = array();
    $table = bwt_sanitize_identifier($table);
    if ($table === '' || !bwt_db_table_exists($table) || !method_exists($wpdb, 'get_col')) {
        return array();
    }
    if (!isset($cache[$table])) {
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $cache[$table] = is_array($columns) ? array_values(array_map('strval', $columns)) : array();
    }
    return $cache[$table];
}

/** جدول‌هایی که ممکن است سورناپست/سرویس پستی خودش ساخته باشد. */
function bwt_candidate_tables() {
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'get_results')) {
        return array();
    }
    $tables = $wpdb->get_col('SHOW TABLES');
    $out = array();
    foreach ((array) $tables as $table) {
        $table = (string) $table;
        if (!preg_match('/(sorna|surena|shipment|parcel|track|barcode|_post_)/iu', $table)) {
            continue;
        }
        if (in_array($table, bwt_meta_tables(), true)) {
            continue;
        }
        $columns = bwt_db_table_columns($table);
        if (!$columns) {
            continue;
        }
        $out[] = array('table' => $table, 'columns' => array_slice($columns, 0, 40));
        if (count($out) >= 8) {
            break;
        }
    }
    return $out;
}

function bwt_meta_tables() {
    global $wpdb;
    $tables = array();
    if (is_object($wpdb) && !empty($wpdb->postmeta)) {
        $tables[] = $wpdb->postmeta;
    }
    if (is_object($wpdb) && !empty($wpdb->prefix)) {
        $hpos = $wpdb->prefix . 'wc_orders_meta';
        if (bwt_db_table_exists($hpos)) {
            $tables[] = $hpos;
        }
    }
    return $tables;
}

/**
 * آخرین راه‌حل: خواندن کد از جدول اختصاصی سرویس پستی (اگر متا نباشد).
 * فقط وقتی ادمین جدول/ستون‌ها را در تنظیمات مشخص کرده باشد اجرا می‌شود.
 */
function bwt_custom_table_codes($order_id) {
    global $wpdb;
    static $cache = array();
    $order_id = (int) $order_id;
    if ($order_id <= 0 || !is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
        return array();
    }
    if (isset($cache[$order_id])) {
        return $cache[$order_id];
    }
    $cache[$order_id] = array();
    $settings = bwt_get_settings();
    $source = isset($settings['table_source']) ? $settings['table_source'] : array();
    if (empty($source['table'])) {
        return array();
    }
    $columns = bwt_db_table_columns($source['table']);
    if (!$columns || !in_array($source['order_col'], $columns, true) || !in_array($source['code_col'], $columns, true)) {
        return array();
    }
    $sql = $wpdb->prepare(
        "SELECT `{$source['code_col']}` FROM `{$source['table']}` WHERE `{$source['order_col']}` = %d LIMIT 10",
        $order_id
    );
    $values = $wpdb->get_col($sql);
    $cache[$order_id] = bwt_unique_codes(is_array($values) ? $values : array());
    return $cache[$order_id];
}

// ========== 3. گرفتن لحظه‌ای کد از افزونهٔ حمل‌ونقل ==========
add_action('woocommerce_new_order', 'bwt_on_order_event', 20, 1);
add_action('woocommerce_update_order', 'bwt_on_order_event', 20, 1);
add_action('woocommerce_order_status_changed', 'bwt_on_order_status_changed', 20, 4);

function bwt_on_order_event($order_id) {
    if (!function_exists('wc_get_order')) {
        return;
    }
    $order = wc_get_order($order_id);
    if (!$order || !bwt_is_order_syncable($order)) {
        return;
    }
    bwt_sync_order_tracking($order, 'order_event');
}

function bwt_on_order_status_changed($order_id, $from = '', $to = '', $order = null) {
    if (!$order && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
    }
    if (!$order || !bwt_is_order_syncable($order)) {
        return;
    }
    bwt_sync_order_tracking($order, 'status_changed');
}

// ========== 4. جاروی دوره‌ای (پوشش نوشتن‌های بدون رویداد) ==========
add_filter('cron_schedules', 'bwt_cron_schedules');
add_action('init', 'bwt_maybe_schedule_cron');
add_action('bwt_tracking_sweep', 'bwt_run_tracking_sweep');

function bwt_cron_schedules($schedules) {
    if (!isset($schedules['bwt_fifteen_min'])) {
        $schedules['bwt_fifteen_min'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'هر ۱۵ دقیقه (کد رهگیری تیساکیس)',
        );
    }
    return $schedules;
}

function bwt_maybe_schedule_cron() {
    if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
        return;
    }
    if (!wp_next_scheduled('bwt_tracking_sweep')) {
        wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'bwt_fifteen_min', 'bwt_tracking_sweep');
    }
}

function bwt_deactivate_plugin() {
    if (function_exists('wp_clear_scheduled_hook')) {
        wp_clear_scheduled_hook('bwt_tracking_sweep');
    }
}

function bwt_query_orders_missing_tracking($limit = BWT_SWEEP_MAX_ORDERS) {
    if (!function_exists('wc_get_orders')) {
        return array();
    }
    $orders = wc_get_orders(array(
        'limit'        => max(1, (int) $limit),
        'type'         => 'shop_order',
        'status'       => array('processing', 'completed', 'on-hold', 'shipped'),
        'meta_query'   => array(
            array('key' => '_tracking_code', 'compare' => 'NOT EXISTS'),
        ),
        'date_created' => '>' . (time() - BWT_SWEEP_DAYS * DAY_IN_SECONDS),
        'orderby'      => 'date',
        'order'        => 'DESC',
        'return'       => 'objects',
    ));
    return is_array($orders) ? $orders : array();
}

function bwt_sync_orders($orders, $context = 'sweep') {
    $stats = array('scanned' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'no_source' => 0, 'disabled' => 0, 'invalid' => 0);
    foreach ((array) $orders as $order) {
        $stats['scanned']++;
        if (!is_object($order)) {
            $stats['invalid']++;
            continue;
        }
        $result = bwt_sync_order_tracking($order, $context);
        $status = isset($result['status']) ? $result['status'] : 'skipped';
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
    return $stats;
}

function bwt_run_tracking_sweep() {
    $settings = bwt_get_settings();
    if (empty($settings['sweep_enabled'])) {
        return array('scanned' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'no_source' => 0, 'disabled' => 0, 'invalid' => 0);
    }
    $orders = bwt_query_orders_missing_tracking(BWT_SWEEP_MAX_ORDERS);
    $stats = bwt_sync_orders($orders, 'sweep');
    $stats['time'] = time();
    $stats['trigger'] = 'cron';
    update_option(BWT_OPTION_LAST_SWEEP, $stats, false);
    return $stats;
}

function bwt_last_sweep() {
    $last = get_option(BWT_OPTION_LAST_SWEEP, array());
    return is_array($last) ? $last : array();
}

// ========== 5. شمارش برای داشبورد ==========
function bwt_count_orders_with_code($has_code = true) {
    if (!function_exists('wc_get_orders')) {
        return 0;
    }
    $cache_key = $has_code ? 'bwt_count_with_code' : 'bwt_count_without_code';
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return (int) $cached;
    }
    $result = wc_get_orders(array(
        'limit'        => 1,
        'paginate'     => true,
        'type'         => 'shop_order',
        'status'       => array('processing', 'completed', 'on-hold', 'shipped'),
        'date_created' => '>' . (time() - BWT_SWEEP_DAYS * DAY_IN_SECONDS),
        'meta_query'   => array(
            array('key' => '_tracking_code', 'compare' => $has_code ? 'EXISTS' : 'NOT EXISTS'),
        ),
    ));
    $total = is_object($result) && isset($result->total) ? (int) $result->total : 0;
    set_transient($cache_key, $total, 5 * MINUTE_IN_SECONDS);
    return $total;
}

// ========== 6. منبع کد رهگیری (ذخیرهٔ کلید متا) ==========
add_action('wp_ajax_bwt_save_sources', 'bwt_ajax_save_sources');

/** ذخیرهٔ فهرست کلیدهای متایی که کد رهگیری را نگه می‌دارند. */
function bwt_ajax_save_sources() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
    }
    check_ajax_referer('bwt_save_sources', 'nonce');
    $keys = isset($_POST['keys']) ? wp_unslash($_POST['keys']) : array();
    if (!is_array($keys)) {
        $keys = array_filter(array_map('trim', explode(',', (string) $keys)));
    }
    $settings = bwt_get_settings();
    $settings['source_keys'] = $keys;
    $saved = bwt_save_settings($settings);
    wp_send_json_success(array(
        'source_keys' => $saved['source_keys'],
        'message'     => empty($saved['source_keys']) ? 'هیچ منبعی انتخاب نشد؛ فقط کلیدهای شناخته‌شده بررسی می‌شوند.' : 'منبع ذخیره شد.',
    ));
}

// ========== 7. همگام‌سازی دسته‌ای (بک‌فیل) ==========
add_action('wp_ajax_bwt_sync_create', 'bwt_ajax_sync_create');
add_action('wp_ajax_bwt_sync_batch', 'bwt_ajax_sync_batch');

function bwt_lock_key($name) {
    return 'bwt_lock_' . md5((string) $name);
}

function bwt_acquire_lock($name, $ttl = 60) {
    $key = bwt_lock_key($name);
    $now = time();
    $existing = get_option($key);
    if ($existing !== false && (int) $existing > $now) {
        return false;
    }
    update_option($key, $now + (int) $ttl, false);
    return (int) get_option($key) === $now + (int) $ttl;
}

function bwt_release_lock($name) {
    delete_option(bwt_lock_key($name));
}

function bwt_collect_sync_order_ids($mode) {
    if (!function_exists('wc_get_orders')) {
        return array();
    }
    $args = array(
        'limit'        => $mode === 'recent' ? 300 : BWT_SYNC_MAX_ORDERS,
        'type'         => 'shop_order',
        'status'       => array('processing', 'completed', 'on-hold', 'shipped'),
        'date_created' => '>' . (time() - BWT_SWEEP_DAYS * DAY_IN_SECONDS),
        'orderby'      => 'date',
        'order'        => 'DESC',
        'return'       => 'ids',
    );
    if ($mode === 'missing') {
        $args['limit'] = BWT_SYNC_MAX_ORDERS;
        $args['meta_query'] = array(
            array('key' => '_tracking_code', 'compare' => 'NOT EXISTS'),
        );
    }
    $ids = wc_get_orders($args);
    if (!is_array($ids)) {
        return array();
    }
    $out = array();
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

function bwt_create_sync_job($mode) {
    if (!current_user_can('manage_options')) {
        return array('job_id' => '', 'error' => 'دسترسی غیرمجاز است.');
    }
    if (!in_array($mode, array('missing', 'recent'), true)) {
        return array('job_id' => '', 'error' => 'نوع همگام‌سازی نامعتبر است.');
    }
    $ids = bwt_collect_sync_order_ids($mode);
    if (!$ids) {
        return array('job_id' => '', 'error' => 'سفارشی برای بررسی پیدا نشد.');
    }
    try {
        $raw = function_exists('wp_generate_uuid4') ? str_replace('-', '', wp_generate_uuid4()) : bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $raw = md5(uniqid('bwt-sync', true));
    }
    $job_id = bwt_sanitize_job_id($raw);
    if ($job_id === '') {
        return array('job_id' => '', 'error' => 'ساخت شناسهٔ کار همگام‌سازی ناموفق بود.');
    }
    $job = array(
        'user_id'    => (int) get_current_user_id(),
        'mode'       => $mode,
        'ids'        => $ids,
        'total'      => count($ids),
        'offset'     => 0,
        'scanned'    => 0,
        'created'    => 0,
        'updated'    => 0,
        'unchanged'  => 0,
        'no_source'  => 0,
        'disabled'   => 0,
        'invalid'    => 0,
        'done'       => false,
        'created_at' => time(),
    );
    if (!set_transient(bwt_sync_job_key($job_id), $job, HOUR_IN_SECONDS)) {
        return array('job_id' => '', 'error' => 'ذخیرهٔ وضعیت همگام‌سازی ممکن نشد؛ دوباره تلاش کنید.');
    }
    return array('job_id' => $job_id, 'error' => '');
}

function bwt_sync_job_key($job_id) {
    return 'bwt_sync_job_' . md5((string) $job_id);
}

function bwt_sanitize_job_id($job_id) {
    $job_id = is_scalar($job_id) ? strtolower(trim((string) $job_id)) : '';
    return preg_match('/^[a-f0-9]{8,64}$/', $job_id) ? $job_id : '';
}

function bwt_get_sync_job($job_id) {
    $job_id = bwt_sanitize_job_id($job_id);
    if ($job_id === '') {
        return false;
    }
    return get_transient(bwt_sync_job_key($job_id));
}

function bwt_sync_job_payload($job) {
    $total = isset($job['total']) ? (int) $job['total'] : 0;
    $offset = isset($job['offset']) ? (int) $job['offset'] : 0;
    $done = !empty($job['done']);
    return array(
        'mode'      => isset($job['mode']) ? (string) $job['mode'] : 'missing',
        'done'      => $done,
        'progress'  => $done ? 100 : ($total > 0 ? min(99, (int) floor(100 * $offset / $total)) : 0),
        'total'     => $total,
        'offset'    => $offset,
        'scanned'   => isset($job['scanned']) ? (int) $job['scanned'] : 0,
        'created'   => isset($job['created']) ? (int) $job['created'] : 0,
        'updated'   => isset($job['updated']) ? (int) $job['updated'] : 0,
        'unchanged' => isset($job['unchanged']) ? (int) $job['unchanged'] : 0,
        'no_source' => isset($job['no_source']) ? (int) $job['no_source'] : 0,
        'disabled'  => isset($job['disabled']) ? (int) $job['disabled'] : 0,
        'invalid'   => isset($job['invalid']) ? (int) $job['invalid'] : 0,
        'error'     => '',
    );
}

function bwt_process_sync_batch(&$job, $batch_size = BWT_SYNC_BATCH_SIZE) {
    $ids = isset($job['ids']) && is_array($job['ids']) ? $job['ids'] : array();
    $batch_size = max(1, min(BWT_SYNC_BATCH_SIZE, (int) $batch_size));
    $end = min(count($ids), (int) $job['offset'] + $batch_size);
    for ($i = (int) $job['offset']; $i < $end; $i++) {
        $job['scanned']++;
        $order = function_exists('wc_get_order') ? wc_get_order((int) $ids[$i]) : null;
        if (!$order) {
            $job['invalid']++;
            continue;
        }
        $result = bwt_sync_order_tracking($order, 'manual_sync');
        $status = isset($result['status']) ? $result['status'] : 'skipped';
        if (isset($job[$status])) {
            $job[$status]++;
        }
    }
    $processed = max(0, $end - (int) $job['offset']);
    $job['offset'] = $end;
    $job['done'] = $end >= count($ids);
    return $processed;
}

function bwt_ajax_sync_create() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
    }
    check_ajax_referer('bwt_sync_batch', 'nonce');
    $mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash($_POST['mode'])) : 'missing';
    $created = bwt_create_sync_job($mode);
    if (empty($created['job_id'])) {
        wp_send_json_error(array('message' => $created['error']), 400);
    }
    $job = bwt_get_sync_job($created['job_id']);
    $payload = is_array($job) ? bwt_sync_job_payload($job) : array();
    $payload['job_id'] = $created['job_id'];
    wp_send_json_success($payload);
}

function bwt_ajax_sync_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
    }
    check_ajax_referer('bwt_sync_batch', 'nonce');
    $job_id = isset($_POST['job_id']) ? bwt_sanitize_job_id(wp_unslash($_POST['job_id'])) : '';
    $job = $job_id !== '' ? bwt_get_sync_job($job_id) : false;
    if (!$job || !is_array($job) || empty($job['user_id']) || (int) $job['user_id'] !== (int) get_current_user_id()) {
        wp_send_json_error(array('message' => 'کار همگام‌سازی پیدا نشد یا دسترسی آن منقضی شده است.'), 404);
    }
    $payload = bwt_sync_job_payload($job);
    if (empty($job['done'])) {
        if (!bwt_acquire_lock('sync_job_' . $job_id, 120)) {
            wp_send_json_error(array('message' => 'یک دستهٔ دیگر در حال اجراست؛ لحظه‌ای دیگر ادامه می‌دهیم.'), 409);
        }
        $job = bwt_get_sync_job($job_id);
        if (is_array($job) && empty($job['done'])) {
            bwt_process_sync_batch($job, BWT_SYNC_BATCH_SIZE);
            set_transient(bwt_sync_job_key($job_id), $job, HOUR_IN_SECONDS);
        }
        bwt_release_lock('sync_job_' . $job_id);
        $payload = is_array($job) ? bwt_sync_job_payload($job) : $payload;
    }
    $payload['job_id'] = $job_id;
    wp_send_json_success($payload);
}

// ========== 8. نقطهٔ ورود بیرونی (Push API با HMAC) ==========
add_action('rest_api_init', 'bwt_register_rest_routes');

function bwt_register_rest_routes() {
    if (!function_exists('register_rest_route')) {
        return;
    }
    register_rest_route('bwt/v1', '/tracking', array(
        'methods'             => 'POST',
        'callback'            => 'bwt_rest_push_tracking',
        'permission_callback' => 'bwt_rest_push_permission',
    ));
}

function bwt_rest_push_permission($request) {
    $settings = bwt_get_settings();
    if (empty($settings['push_enabled']) || $settings['push_secret'] === '') {
        return new WP_Error('bwt_push_disabled', 'دریافت کد از سرویس بیرونی خاموش است.', array('status' => 403));
    }
    $signature = '';
    if (is_object($request) && method_exists($request, 'get_header')) {
        $signature = (string) $request->get_header('x_bwt_signature');
    }
    $raw = '';
    if (is_object($request) && method_exists($request, 'get_body')) {
        $raw = (string) $request->get_body();
    }
    $expected = hash_hmac('sha256', $raw, $settings['push_secret']);
    if ($signature === '' || !hash_equals($expected, strtolower(trim($signature)))) {
        return new WP_Error('bwt_push_signature', 'امضای درخواست معتبر نیست.', array('status' => 401));
    }
    return true;
}

/** کد ورودی از سرویس بیرونی: کدهای غیراستاندارد هم پذیرفته می‌شوند (حداقل ۴ رقم). */
function bwt_sanitize_pushed_code($value) {
    $code = bwt_clean_code($value);
    if ($code === '') {
        return '';
    }
    if (preg_match_all('/\d/', $code, $matches) && count($matches[0]) < 4) {
        return '';
    }
    return $code;
}

function bwt_rest_push_tracking($request) {
    $params = array();
    if (is_object($request) && method_exists($request, 'get_json_params')) {
        $json = $request->get_json_params();
        if (is_array($json)) {
            $params = $json;
        }
    }
    if (!$params && is_object($request) && method_exists($request, 'get_params')) {
        $params = (array) $request->get_params();
    }

    $order_id = isset($params['order_id']) ? (int) bwt_normalize_digits($params['order_id']) : 0;
    if ($order_id <= 0) {
        return new WP_Error('bwt_push_order', 'شمارهٔ سفارش معتبر نیست.', array('status' => 400));
    }

    $raw_codes = array();
    if (isset($params['tracking_codes']) && is_array($params['tracking_codes'])) {
        $raw_codes = $params['tracking_codes'];
    } elseif (isset($params['tracking_codes'])) {
        $raw_codes = array($params['tracking_codes']);
    } elseif (isset($params['tracking_code'])) {
        $raw_codes = array($params['tracking_code']);
    }
    $codes = array();
    foreach ($raw_codes as $raw) {
        $code = bwt_sanitize_pushed_code($raw);
        if ($code !== '' && !in_array($code, $codes, true)) {
            $codes[] = $code;
        }
        if (count($codes) >= BWT_TRACK_MAX_CODES) {
            break;
        }
    }
    if (!$codes) {
        return new WP_Error('bwt_push_code', 'کد رهگیری معتبر نیست.', array('status' => 400));
    }

    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (!$order) {
        return new WP_Error('bwt_push_not_found', 'سفارش با این شماره پیدا نشد.', array('status' => 404));
    }

    $result = bwt_apply_tracking_codes($order, $codes, 'push_api', 'push_api');
    return array(
        'status'        => $result['status'],
        'order_id'      => $order_id,
        'tracking_code' => isset($result['codes'][0]) ? $result['codes'][0] : '',
        'tracking_codes' => isset($result['codes']) ? array_values($result['codes']) : array(),
        'source'        => 'push_api',
    );
}

// ========== 9. پیشخوان: منو، استایل، صفحهٔ همگام‌سازی و پاک‌سازی ==========
add_action('admin_menu', 'bwt_add_admin_menu');
add_action('admin_enqueue_scripts', 'bwt_admin_assets');

function bwt_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'کد رهگیری پستی',
        'کد رهگیری',
        'manage_options',
        'bulk-tracking-upload',
        'bwt_tracking_page'
    );
    add_submenu_page(
        'woocommerce',
        'پاک کردن کدهای رهگیری',
        'پاک کردن کدها',
        'manage_options',
        'bwt-cleanup',
        'bwt_cleanup_page'
    );
}

function bwt_admin_assets($hook) {
    if ($hook !== 'woocommerce_page_bulk-tracking-upload' && $hook !== 'woocommerce_page_bwt-cleanup') {
        return;
    }
    wp_enqueue_style(
        'bwt-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        wp_style_is('tisacase-ui', 'registered') ? array('tisacase-ui') : array(),
        BWT_VERSION
    );
    wp_enqueue_script('bwt-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array(), BWT_VERSION, true);
    wp_localize_script('bwt-admin', 'BWTAdmin', array(
        'ajaxUrl'       => admin_url('admin-ajax.php'),
        'cleanupNonce'  => wp_create_nonce('bwt_cleanup_batch'),
        'sourceNonce'   => wp_create_nonce('bwt_save_sources'),
        'syncNonce'     => wp_create_nonce('bwt_sync_batch'),
        'retryLabel'    => 'تلاش دوباره / ادامه پردازش',
        'genericError'  => 'ارتباط با سرور ناموفق بود.',
    ));
}

/** سر سبز مشترک دو صفحه (زبان طراحی TisaCase). */
function bwt_render_hero($active) {
    $tabs = array(
        'sync'    => array('label' => 'همگام‌سازی خودکار', 'url' => admin_url('admin.php?page=bulk-tracking-upload')),
        'cleanup' => array('label' => 'پاک کردن کدها', 'url' => admin_url('admin.php?page=bwt-cleanup')),
    );
    ?>
    <header class="bwt-hero">
        <div class="bwt-hero-row">
            <div class="bwt-hero-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/></svg>
            </div>
            <div class="bwt-hero-text">
                <h1 class="bwt-hero-title">کد رهگیری پستی</h1>
                <p class="bwt-hero-sub">بدون فایل و بدون کار دستی — کدی که سورناپست صادر می‌کند، خودکار روی سفارش می‌نشیند</p>
            </div>
            <span class="bwt-hero-ver" dir="ltr">v<?php echo esc_html(BWT_VERSION); ?></span>
        </div>
        <nav class="bwt-tabs">
            <?php foreach ($tabs as $key => $tab) : ?>
                <a class="bwt-tab <?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url($tab['url']); ?>"><?php echo esc_html($tab['label']); ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php
}

function bwt_admin_notice($type, $text) {
    return '<div class="tisa-notice tisa-notice--' . esc_attr($type) . ' bwt-notice"><p>' . esc_html($text) . '</p></div>';
}

function bwt_tracking_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }

    $settings = bwt_get_settings();
    $message = '';

    if (isset($_POST['bwt_save_settings'])) {
        if (!isset($_POST['bwt_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_settings_nonce'])), 'bwt_settings_action')) {
            $message = bwt_admin_notice('danger', 'خطای امنیتی: نانس نامعتبر است. صفحه را رفرش کنید.');
        } else {
            $input = array(
                'source_keys'   => $settings['source_keys'],
                'scan_notes'    => isset($_POST['scan_notes']) ? 1 : 0,
                'sweep_enabled' => isset($_POST['sweep_enabled']) ? 1 : 0,
                'push_enabled'  => isset($_POST['push_enabled']) ? 1 : 0,
                'push_secret'   => isset($_POST['push_secret']) ? sanitize_text_field(wp_unslash($_POST['push_secret'])) : '',
                'tracking_url'  => isset($_POST['tracking_url']) ? esc_url_raw(wp_unslash($_POST['tracking_url'])) : '',
                'fallback_pdf'  => isset($_POST['fallback_pdf']) ? esc_url_raw(wp_unslash($_POST['fallback_pdf'])) : '',
            );
            if (!empty($_POST['bwt_regenerate_secret'])) {
                $input['push_secret'] = bwt_generate_push_secret();
            } elseif ($input['push_secret'] === '') {
                $input['push_secret'] = $settings['push_secret'];
            }
            if ($input['push_secret'] === '') {
                $input['push_secret'] = bwt_generate_push_secret();
            }
            $table_source = array(
                'table'     => isset($_POST['table_source_table']) ? sanitize_text_field(wp_unslash($_POST['table_source_table'])) : '',
                'order_col' => isset($_POST['table_source_order_col']) ? sanitize_text_field(wp_unslash($_POST['table_source_order_col'])) : '',
                'code_col'  => isset($_POST['table_source_code_col']) ? sanitize_text_field(wp_unslash($_POST['table_source_code_col'])) : '',
            );
            $input['table_source'] = $table_source;
            $settings = bwt_save_settings($input);
            $message = bwt_admin_notice('success', 'تنظیمات ذخیره شد.');
            if ($table_source['table'] !== '') {
                $wanted = bwt_sanitize_table_source($table_source);
                if (empty($settings['table_source']['table'])) {
                    $message = bwt_admin_notice('warning', 'نام جدول/ستون نامعتبر بود و ذخیره نشد (فقط حروف لاتین، رقم و آندرلاین).');
                } else {
                    $columns = bwt_db_table_columns($settings['table_source']['table']);
                    if (!$columns) {
                        $message = bwt_admin_notice('warning', 'جدول «' . $settings['table_source']['table'] . '» پیدا نشد؛ منبع جدول ذخیره شد ولی تا وجود جدول غیرفعال می‌ماند.');
                    } elseif (!in_array($settings['table_source']['order_col'], $columns, true) || !in_array($settings['table_source']['code_col'], $columns, true)) {
                        $message = bwt_admin_notice('warning', 'ستون‌های واردشده در جدول نیستند: ' . implode('، ', array_slice($columns, 0, 12)));
                    } else {
                        $message = bwt_admin_notice('success', 'تنظیمات و منبع جدول ذخیره شد.');
                    }
                }
                if ($wanted['table'] === '') {
                    $settings['table_source'] = array('table' => '', 'order_col' => '', 'code_col' => '');
                }
            }
        }
    }

    if (isset($_POST['bwt_manual_save'])) {
        if (!isset($_POST['bwt_manual_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_manual_nonce'])), 'bwt_manual_action')) {
            $message = bwt_admin_notice('danger', 'خطای امنیتی: نانس نامعتبر است.');
        } else {
            $order_id = (int) bwt_normalize_digits(isset($_POST['manual_order_id']) ? wp_unslash($_POST['manual_order_id']) : '');
            $code = bwt_sanitize_pushed_code(isset($_POST['manual_tracking_code']) ? wp_unslash($_POST['manual_tracking_code']) : '');
            $order = ($order_id > 0 && function_exists('wc_get_order')) ? wc_get_order($order_id) : null;
            if (!$order) {
                $message = bwt_admin_notice('danger', 'سفارش با شمارهٔ ' . $order_id . ' پیدا نشد.');
            } elseif ($code === '') {
                $message = bwt_admin_notice('danger', 'کد رهگیری معتبر نیست (حداقل ۴ کاراکتر و ۴ رقم).');
            } else {
                $result = bwt_apply_tracking_codes($order, array($code), 'manual', 'admin_manual');
                $message = bwt_admin_notice('success', 'کد رهگیری سفارش #' . $order_id . ' ثبت شد.');
            }
        }
    }

    $with_code = bwt_count_orders_with_code(true);
    $without_code = bwt_count_orders_with_code(false);
    $last_sweep = bwt_last_sweep();
    $push_endpoint = function_exists('rest_url') ? rest_url('bwt/v1/tracking') : home_url('/wp-json/bwt/v1/tracking');
    $prefill_order = isset($_GET['bwt_order']) ? (int) bwt_normalize_digits(wp_unslash($_GET['bwt_order'])) : 0;
    ?>
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('sync'); ?>
        <?php echo wp_kses_post($message); ?>

        <div class="bwt-stack">

            <section class="bwt-kpis" aria-label="خلاصهٔ وضعیت">
                <div class="bwt-kpi">
                    <span class="bwt-kpi__label">دارای کد رهگیری</span>
                    <span class="bwt-kpi__value"><?php echo esc_html(number_format_i18n($with_code)); ?></span>
                    <span class="bwt-kpi__meta">سفارش ۴۵ روز اخیر</span>
                </div>
                <div class="bwt-kpi <?php echo $without_code > 0 ? 'bwt-kpi--warn' : ''; ?>">
                    <span class="bwt-kpi__label">بدون کد رهگیری</span>
                    <span class="bwt-kpi__value"><?php echo esc_html(number_format_i18n($without_code)); ?></span>
                    <span class="bwt-kpi__meta">در انتظار صدور از سورناپست</span>
                </div>
                <div class="bwt-kpi">
                    <span class="bwt-kpi__label">آخرین جاروی خودکار</span>
                    <span class="bwt-kpi__value bwt-kpi__value--sm"><?php echo esc_html(!empty($last_sweep['time']) ? human_time_diff((int) $last_sweep['time']) . ' پیش' : 'اجرا نشده'); ?></span>
                    <span class="bwt-kpi__meta"><?php echo !empty($settings['sweep_enabled']) ? 'هر ۱۵ دقیقه فعال است' : 'خاموش است'; ?></span>
                </div>
            </section>

            <section class="bwt-card" id="bwt-source">
                <header class="bwt-card__head">
                    <div>
                        <h2 class="bwt-card__title">۱. کلید متای کد رهگیری</h2>
                        <p class="bwt-card__desc">نام متایی که سورناپست کد رهگیری را زیر آن روی سفارش می‌نویسد. یک بار ذخیره کنید؛ همگام‌سازی و جاروی خودکار از همین کلید می‌خوانند.</p>
                    </div>
                    <span class="bwt-badge-soft">گام ۱</span>
                </header>

                <div class="bwt-card__body">
                    <form class="bwt-field-row bwt-field-row--tight" data-bwt-source-form>
                        <input type="text" class="bwt-input bwt-input--mono" dir="rtl" placeholder="_surena_barcode" data-bwt-source-key aria-label="نام کلید متا" />
                        <button type="submit" class="bwt-btn bwt-btn--primary">ذخیرهٔ کلید</button>
                    </form>
                    <div class="bwt-status-line" data-bwt-source-status aria-live="polite"></div>

                    <div class="bwt-divider"></div>

                    <h3 class="bwt-subtitle">کلیدهای فعال</h3>
                    <div class="bwt-chips" data-bwt-source-chips>
                        <?php foreach ((array) $settings['source_keys'] as $key) : ?>
                            <span class="bwt-chip" data-key="<?php echo esc_attr($key); ?>" dir="ltr"><?php echo esc_html($key); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <p class="bwt-hint">هر کلیدی که اینجا اضافه کنید با اولویت خوانده می‌شود. کلیدهای رایج (<code dir="ltr">_tracking_code</code>، <code dir="ltr">_post_barcode</code>، <code dir="ltr">_wc_shipment_tracking_items</code>) و کد داخل یادداشت‌های سفارش هم بدون افزودن بررسی می‌شوند.</p>
                </div>
            </section>

            <section class="bwt-card">
                <header class="bwt-card__head">
                    <div>
                        <h2 class="bwt-card__title">۲. همگام‌سازی سفارش‌ها</h2>
                        <p class="bwt-card__desc">سفارش‌های قبلی را یک‌بار پر کنید؛ هر دسته ۵۰ سفارش است و اگر صفحه بسته شد، از همان‌جا ادامه می‌دهد.</p>
                    </div>
                    <span class="bwt-badge-soft">گام ۲</span>
                </header>
                <div class="bwt-card__body">
                    <div class="bwt-actions">
                        <button type="button" class="bwt-btn bwt-btn--primary" data-bwt-sync-mode="missing">همگام‌سازی سفارش‌های بدون کد</button>
                        <button type="button" class="bwt-btn" data-bwt-sync-mode="recent">بازبینی ۳۰۰ سفارش اخیر</button>
                    </div>

                    <div id="bwt-sync-progress" class="bwt-progress" data-job-id="" aria-live="polite">
                        <div class="bwt-progress__track"><progress class="bwt-progress__bar" data-bwt-progress max="100" value="0" aria-label="پیشرفت همگام‌سازی"></progress></div>
                        <p class="bwt-progress__meta" data-bwt-sync-counters>آماده</p>
                        <div class="bwt-actions"><button type="button" class="bwt-btn bwt-btn--sm" data-bwt-resume hidden>ادامهٔ همگام‌سازی</button></div>
                        <div data-bwt-sync-result></div>
                    </div>

                    <p class="bwt-hint">
                        جاروی خودکار هر ۱۵ دقیقه سفارش‌های ۴۵ روز اخیر را بررسی می‌کند.
                        <?php if (!empty($last_sweep['time'])) : ?>
                            آخرین اجرا: <?php echo esc_html(date_i18n('j F Y - H:i', (int) $last_sweep['time'])); ?>
                            · بررسی‌شده: <?php echo esc_html(number_format_i18n(isset($last_sweep['scanned']) ? (int) $last_sweep['scanned'] : 0)); ?>
                            · ثبت‌شده: <?php echo esc_html(number_format_i18n((isset($last_sweep['created']) ? (int) $last_sweep['created'] : 0) + (isset($last_sweep['updated']) ? (int) $last_sweep['updated'] : 0))); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </section>

            <form method="post" class="bwt-card" id="bwt-settings">
                <?php wp_nonce_field('bwt_settings_action', 'bwt_settings_nonce'); ?>
                <header class="bwt-card__head">
                    <div>
                        <h2 class="bwt-card__title">۳. تنظیمات</h2>
                        <p class="bwt-card__desc">پیش‌فرض‌ها برای سایت شما مناسب‌اند؛ این بخش را فقط اگر لازم شد باز کنید.</p>
                    </div>
                    <span class="bwt-badge-soft">پیشرفته</span>
                </header>
                <div class="bwt-card__body">
                    <div class="bwt-switches">
                        <label class="bwt-switch">
                            <input type="checkbox" name="sweep_enabled" value="1" <?php checked(!empty($settings['sweep_enabled'])); ?> />
                            <span class="bwt-switch__ui" aria-hidden="true"></span>
                            <span class="bwt-switch__text">جاروی خودکار هر ۱۵ دقیقه<small>سفارش‌های ۴۵ روز اخیر را می‌گردد.</small></span>
                        </label>
                        <label class="bwt-switch">
                            <input type="checkbox" name="scan_notes" value="1" <?php checked(!empty($settings['scan_notes'])); ?> />
                            <span class="bwt-switch__ui" aria-hidden="true"></span>
                            <span class="bwt-switch__text">خواندن کد از یادداشت‌های سفارش<small>اگر سورناپست فقط یادداشت بگذارد.</small></span>
                        </label>
                        <label class="bwt-switch">
                            <input type="checkbox" name="push_enabled" value="1" <?php checked(!empty($settings['push_enabled'])); ?> />
                            <span class="bwt-switch__ui" aria-hidden="true"></span>
                            <span class="bwt-switch__text">فعال‌کردن Push API<small>دریافت کد از سرویس بیرونی با امضای HMAC.</small></span>
                        </label>
                    </div>

                    <div class="bwt-form-grid">
                        <label class="bwt-field">
                            <span class="bwt-field__label">الگوی لینک پیگیری <code>%s</code> = کد</span>
                            <input type="text" name="tracking_url" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['tracking_url']); ?>" />
                            <span class="bwt-field__help">پیش‌فرض: <span dir="ltr">https://tracking.post.ir/?id=%s</span></span>
                        </label>
                        <label class="bwt-field">
                            <span class="bwt-field__label">کلید امضای Push</span>
                            <input type="text" name="push_secret" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['push_secret']); ?>" autocomplete="off" />
                            <span class="bwt-field__help"><label class="bwt-check"><input type="checkbox" name="bwt_regenerate_secret" value="1" /> ساخت کلید جدید</label></span>
                        </label>
                        <label class="bwt-field bwt-field--wide">
                            <span class="bwt-field__label">فایل PDF کدهای رهگیری (اختیاری)</span>
                            <input type="text" name="fallback_pdf" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['fallback_pdf']); ?>" placeholder="https://example.com/tracking.pdf" />
                            <span class="bwt-field__help">وقتی جست‌وجوی مشتری خطا می‌دهد، همین فایل داخل فرم و زیر پیام خطا نمایش داده می‌شود. خالی بگذارید تا نمایش داده نشود.</span>
                        </label>
                    </div>

                    <details class="bwt-details">
                        <summary>دریافت کد از سرویس بیرونی (Push API)</summary>
                        <p class="bwt-hint">نشانی: <code dir="ltr"><?php echo esc_html($push_endpoint); ?></code></p>
                        <pre class="bwt-code-block" dir="ltr">POST <?php echo esc_html($push_endpoint); ?>

{ "order_id": 1234, "tracking_code": "RR123456789IR" }

Header: X-BWT-Signature: hex( hmac_sha256( raw_body, secret ) )</pre>
                    </details>

                    <details class="bwt-details">
                        <summary>منبع جدول اختصاصی سورناپست (اگر کد در متای سفارش نیست)</summary>
                        <div class="bwt-form-grid bwt-form-grid--3">
                            <label class="bwt-field">
                                <span class="bwt-field__label">نام جدول</span>
                                <input type="text" name="table_source_table" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['table_source']['table']); ?>" placeholder="wp_surena_post_orders" />
                            </label>
                            <label class="bwt-field">
                                <span class="bwt-field__label">ستون شمارهٔ سفارش</span>
                                <input type="text" name="table_source_order_col" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['table_source']['order_col']); ?>" placeholder="order_id" />
                            </label>
                            <label class="bwt-field">
                                <span class="bwt-field__label">ستون کد رهگیری</span>
                                <input type="text" name="table_source_code_col" class="bwt-input bwt-input--mono" dir="ltr" value="<?php echo esc_attr($settings['table_source']['code_col']); ?>" placeholder="tracking_code" />
                            </label>
                        </div>
                        <?php $candidates = bwt_candidate_tables(); ?>
                        <?php if ($candidates) : ?>
                            <p class="bwt-hint">جدول‌های مشکوک روی این سایت (نشانگر را روی نام جدول نگه دارید تا ستون‌ها را ببینید):</p>
                            <div class="bwt-chips">
                                <?php foreach ($candidates as $candidate) : ?>
                                    <span class="bwt-chip bwt-chip--table" dir="ltr" title="<?php echo esc_attr(implode(', ', $candidate['columns'])); ?>"><?php echo esc_html($candidate['table']); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p class="bwt-hint">جدولی با نام مرتبط با پست/سورنا روی این سایت پیدا نشد.</p>
                        <?php endif; ?>
                    </details>

                    <div class="bwt-actions">
                        <button type="submit" name="bwt_save_settings" class="bwt-btn bwt-btn--primary">ذخیرهٔ تنظیمات</button>
                    </div>
                </div>
            </form>

            <div class="bwt-grid-2">
                <section class="bwt-card">
                    <header class="bwt-card__head">
                        <div>
                            <h2 class="bwt-card__title">ثبت دستی (اضطراری)</h2>
                            <p class="bwt-card__desc">فقط وقتی خودکار پیدا نشد.</p>
                        </div>
                    </header>
                    <div class="bwt-card__body">
                        <form method="post" class="bwt-form-stack">
                            <?php wp_nonce_field('bwt_manual_action', 'bwt_manual_nonce'); ?>
                            <label class="bwt-field">
                                <span class="bwt-field__label">شمارهٔ سفارش</span>
                                <input type="text" name="manual_order_id" class="bwt-input bwt-input--mono" dir="ltr" inputmode="numeric" value="<?php echo esc_attr($prefill_order > 0 ? $prefill_order : ''); ?>" />
                            </label>
                            <label class="bwt-field">
                                <span class="bwt-field__label">کد رهگیری</span>
                                <input type="text" name="manual_tracking_code" class="bwt-input bwt-input--mono" dir="ltr" />
                            </label>
                            <button type="submit" name="bwt_manual_save" class="bwt-btn">ثبت کد</button>
                        </form>
                    </div>
                </section>

                <section class="bwt-card">
                    <header class="bwt-card__head">
                        <div>
                            <h2 class="bwt-card__title">فرم پیگیری مشتری</h2>
                            <p class="bwt-card__desc">این شورت‌کد را در صفحهٔ «پیگیری سفارش» بگذارید.</p>
                        </div>
                    </header>
                    <div class="bwt-card__body">
                        <code class="bwt-shortcode" dir="ltr">[tracking_search_form]</code>
                        <p class="bwt-hint">مشتری با شمارهٔ سفارش + موبایل ثبت‌شده، کد رهگیری و لینک پیگیری پست را همان‌جا می‌بیند. تا وقتی کد نیامده، متن آماده‌سازی و بررسی خودکار نمایش داده می‌شود.</p>
                    </div>
                </section>
            </div>

        </div>
    </div>
    <?php
}

/** نمایش خلاصهٔ کد رهگیری در صفحهٔ سفارش (فقط خواندنی). */
add_action('add_meta_boxes', 'bwt_add_order_meta_box');

function bwt_add_order_meta_box() {
    if (!function_exists('add_meta_box')) {
        return;
    }
    $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    add_meta_box('bwt-tracking-summary', 'کد رهگیری پستی', 'bwt_render_order_tracking_box', $screen ? $screen : 'shop_order', 'side', 'high');
}

function bwt_render_order_tracking_box($post_or_order) {
    $order = null;
    if (is_object($post_or_order) && method_exists($post_or_order, 'get_id')) {
        $order = $post_or_order;
    } elseif (is_object($post_or_order) && isset($post_or_order->ID) && function_exists('wc_get_order')) {
        $order = wc_get_order((int) $post_or_order->ID);
    }
    if (!$order) {
        echo '<p>سفارش قابل خواندن نیست.</p>';
        return;
    }
    $codes = method_exists($order, 'get_meta') ? $order->get_meta('_tracking_codes') : array();
    $codes = is_array($codes) ? bwt_unique_codes($codes) : array();
    $primary = method_exists($order, 'get_meta') ? bwt_clean_code($order->get_meta('_tracking_code')) : '';
    $source = method_exists($order, 'get_meta') ? (string) $order->get_meta('_bwt_tracking_source') : '';
    $synced_at = method_exists($order, 'get_meta') ? (int) $order->get_meta('_bwt_tracking_synced_at') : 0;
    $off = method_exists($order, 'get_meta') ? (bool) $order->get_meta('_bwt_autosync_off') : false;

    if ($primary === '' && !$codes) {
        echo '<p class="bwt-hint">هنوز کدی ثبت نشده است.</p>';
    } else {
        $shown = $primary !== '' ? $primary : $codes[0];
        echo '<p><strong class="bwt-ltr">' . esc_html($shown) . '</strong></p>';
        if (count($codes) > 1) {
            echo '<p class="bwt-hint">' . esc_html(implode('، ', $codes)) . '</p>';
        }
        if ($source !== '') {
            echo '<p class="bwt-hint">منبع: <span class="bwt-ltr">' . esc_html($source) . '</span></p>';
        }
        if ($synced_at > 0) {
            echo '<p class="bwt-hint">آخرین همگام‌سازی: ' . esc_html(date_i18n('j F Y - H:i', $synced_at)) . '</p>';
        }
    }
    if ($off) {
        echo '<p class="bwt-hint">همگام‌سازی خودکار برای این سفارش خاموش است.</p>';
    }
    echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=bulk-tracking-upload&bwt_order=' . (int) $order->get_id())) . '">ثبت/ویرایش دستی کد</a></p>';
}

// ========== 10. فرم پیگیری مشتری (AJAX) ==========
add_action('wp_enqueue_scripts', 'bwt_register_frontend_assets');
add_shortcode('tracking_search_form', 'bwt_tracking_search_form');
add_action('wp_ajax_bwt_track_lookup', 'bwt_ajax_track_lookup');
add_action('wp_ajax_nopriv_bwt_track_lookup', 'bwt_ajax_track_lookup');

function bwt_register_frontend_assets() {
    if (!function_exists('wp_register_script')) {
        return;
    }
    wp_register_style('bwt-tracking', plugin_dir_url(__FILE__) . 'assets/tracking.css', array(), BWT_VERSION);
    wp_register_script('bwt-tracking', plugin_dir_url(__FILE__) . 'assets/tracking.js', array(), BWT_VERSION, true);
}

/**
 * نرمال‌سازی شمارهٔ موبایل به قالب یکتای 98 9XXXXXXXXX.
 * ورودی: فارسی/عربی، فاصله/خط‌تیره/پرانتز، پیشوندهای +98 / 0098 / 98 / 0.
 * خروجی نامعتبر = رشتهٔ خالی.
 */
function bwt_normalize_phone($input) {
    $s = bwt_normalize_digits((string) $input);
    $s = preg_replace('/[^0-9]/', '', $s);
    if ($s === '') {
        return '';
    }
    if (strpos($s, '0098') === 0) {
        $s = substr($s, 4);
    } elseif (strlen($s) === 12 && strpos($s, '98') === 0) {
        $s = substr($s, 2);
    }
    if (strpos($s, '0') === 0) {
        $s = substr($s, 1);
    }
    if (strlen($s) === 10 && strpos($s, '9') === 0) {
        return '98' . $s;
    }
    return '';
}

// مقایسهٔ شمارهٔ ثبت‌شده روی سفارش با ورودی کاربر (زمان‌ثابت)
function bwt_phones_match($stored, $input) {
    $a = bwt_normalize_phone($stored);
    $b = bwt_normalize_phone($input);
    if ($a === '' || $b === '') {
        return false;
    }
    return hash_equals($a, $b);
}

function bwt_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
}

function bwt_track_throttle_key() {
    return 'bwt_track_fail_' . md5(bwt_client_ip());
}

function bwt_track_is_throttled() {
    return (int) get_transient(bwt_track_throttle_key()) >= 10;
}

function bwt_track_note_fail() {
    $key = bwt_track_throttle_key();
    set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
}

function bwt_track_reset_fails() {
    delete_transient(bwt_track_throttle_key());
}

function bwt_pending_message_lines() {
    return array(
        'چنانچه در این بخش هنوز شماره مرسوله سفارش شما نمایش داده نشده است، به این معناست که سفارش در یکی از مراحل آماده‌سازی، تولید، بسته‌بندی یا ارسال قرار دارد و هنوز به شرکت حمل‌ونقل تحویل نشده است.',
        'کالاهای عادی: حداکثر ۷ روز کاری — کالاهای چاپی و سفارشی: بین ۷ تا ۱۸ روز کاری (با توجه به فرآیند تولید).',
        'روزهای کاری صرفاً از شنبه تا چهارشنبه محاسبه می‌شوند و پنج‌شنبه، جمعه و تعطیلات رسمی جزو روزهای کاری محسوب نمی‌گردند.',
        'پس از تحویل سفارش به شرکت حمل‌ونقل، شماره مرسوله به‌صورت خودکار در همین صفحه ثبت و قابل مشاهده خواهد بود.',
        'خواهشمند است تا پیش از ثبت شماره مرسوله، از ارسال درخواست یا پیگیری جداگانه از طریق پیام خصوصی یا ارتباط با ادمین خودداری فرمایید؛ زیرا تمامی مراحل اطلاع‌رسانی و ثبت اطلاعات مرسوله از طریق همین صفحه انجام می‌شود.',
        'از شکیبایی و همراهی ارزشمند شما سپاسگزاریم.',
    );
}

function bwt_tracking_search_form($atts = array()) {
    $defaults_atts = array('pdf' => '', 'pdf_text' => '');
    $atts = is_array($atts) ? $atts : array();
    if (function_exists('shortcode_atts')) {
        $atts = shortcode_atts($defaults_atts, $atts, 'tracking_search_form');
    } else {
        $atts = array_merge($defaults_atts, array_filter($atts, 'is_scalar'));
    }
    $settings = bwt_get_settings();
    $fallback_pdf = $atts['pdf'] !== '' ? $atts['pdf'] : (string) $settings['fallback_pdf'];
    $fallback_pdf = $fallback_pdf === '' ? '' : esc_url_raw($fallback_pdf);
    if ($fallback_pdf !== '' && !preg_match('#^https?://#i', $fallback_pdf)) {
        $fallback_pdf = '';
    }
    $fallback_text = $atts['pdf_text'] !== ''
        ? (string) $atts['pdf_text']
        : 'اگر شماره سفارش شما یافت نشد، می‌توانید از طریق فایل زیر کد رهگیری خود را مشاهده کنید.';

    if (function_exists('wp_enqueue_style')) {
        wp_enqueue_style('bwt-tracking');
    }
    if (function_exists('wp_enqueue_script')) {
        wp_enqueue_script('bwt-tracking');
        wp_localize_script('bwt-tracking', 'BWT_Tracking', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('bwt_track_lookup'),
            'pollSeconds' => 30,
            'pollMax'     => 20,
            'strings'    => array(
                'sending'   => 'در حال بررسی سفارش…',
                'generic'   => 'ارتباط با سرور ناموفق بود؛ دوباره تلاش کنید.',
                'copy'      => 'کپی کد',
                'copied'    => 'کپی شد',
                'retry'     => 'بررسی مجدد',
                'autoCheck' => 'بررسی خودکار بعدی تا',
                'seconds'   => 'ثانیه',
                'codeLabel' => 'کد رهگیری پستی',
                'linkLabel' => 'پیگیری مرسوله در سایت پست',
                'order'     => 'شماره سفارش',
                'date'      => 'تاریخ ثبت سفارش',
                'name'      => 'نام و نام خانوادگی',
                'address'   => 'آدرس',
                'status'    => 'وضعیت سفارش',
                'stopPoll'  => 'بررسی خودکار متوقف شد؛ دکمهٔ «بررسی مجدد» را بزنید.',
                'paused'    => 'بررسی خودکار موقتاً متوقف شد.',
            ),
        ));
    }

    ob_start();
    ?>
    <div class="bwt-track" data-bwt-tracking>
        <form class="bwt-track__form" data-bwt-form novalidate>
            <div class="bwt-track__fields">
                <div class="bwt-field">
                    <label class="bwt-field__label" for="bwt_order_id">شماره سفارش</label>
                    <input class="bwt-field__input" type="text" id="bwt_order_id" name="order_id" required dir="ltr" placeholder="مثلاً 424172" inputmode="numeric" autocomplete="off" />
                </div>
                <div class="bwt-field">
                    <label class="bwt-field__label" for="bwt_billing_phone">شماره موبایل ثبت‌شده روی سفارش</label>
                    <input class="bwt-field__input" type="text" id="bwt_billing_phone" name="billing_phone" required dir="ltr" placeholder="۰۹۱۲۱۲۳۴۵۶۷" inputmode="tel" autocomplete="tel" />
                </div>
            </div>
            <button type="submit" class="bwt-btn bwt-btn--primary bwt-btn--block">پیگیری سفارش</button>
        </form>

        <div class="bwt-track__result" data-bwt-result hidden aria-live="polite"></div>

        <?php if ($fallback_pdf !== '') : ?>
            <div class="bwt-track__fallback" data-bwt-fallback hidden>
                <p class="bwt-track__fallback-text"><?php echo esc_html($fallback_text); ?></p>
                <a class="bwt-btn bwt-btn--primary bwt-track__fallback-btn" href="<?php echo esc_url($fallback_pdf); ?>" download target="_blank" rel="noopener">
                    <span class="bwt-track__fallback-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="m7 11 5 5 5-5"/><path d="M5 21h14"/></svg>
                    </span>
                    دانلود فایل کدهای رهگیری
                </a>
            </div>
        <?php endif; ?>

        <noscript>
            <div class="bwt-track__result bwt-track__result--error">برای پیگیری، جاوااسکریپت مرورگر را فعال کنید.</div>
        </noscript>
    </div>
    <?php
    return ob_get_clean();
}

function bwt_ajax_track_lookup() {
    if (function_exists('check_ajax_referer')) {
        check_ajax_referer('bwt_track_lookup', 'nonce');
    }
    if (bwt_track_is_throttled()) {
        wp_send_json_error(array('message' => 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر دوباره تلاش کنید.'), 429);
    }

    $order_id_raw = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
    $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
    $order_id_normalized = bwt_normalize_digits($order_id_raw);

    if ($order_id_raw === '' || $phone === '') {
        wp_send_json_error(array('message' => 'وارد کردن شماره سفارش و شماره موبایلی که سفارش با آن ثبت شده، الزامی است.'), 400);
    }
    if (!ctype_digit($order_id_normalized)) {
        wp_send_json_error(array('message' => 'شماره سفارش باید عددی باشد.'), 400);
    }

    $order_id = (int) $order_id_normalized;
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;

    // احراز مالکیت: وجود سفارش لو نمی‌رود؛ پیام هر دو حالت یکسان است.
    $verified = $order && method_exists($order, 'get_billing_phone') && bwt_phones_match($order->get_billing_phone(), $phone);
    if (!$verified) {
        bwt_track_note_fail();
        wp_send_json_error(array('message' => 'شماره سفارش یا شماره موبایل واردشده معتبر نیست. شماره موبایل باید همان شماره‌ای باشد که سفارش با آن ثبت شده است.'), 403);
    }
    bwt_track_reset_fails();

    $resolved = bwt_resolve_order_tracking($order);
    $codes = $resolved['codes'];
    if ($codes) {
        // اگر سورناپست کد را نوشته ولی رویداد همگام‌سازی هنوز اجرا نشده، همین‌جا ثبت می‌شود.
        if (bwt_clean_code($order->get_meta('_tracking_code')) !== $codes[0] || $order->get_meta('_tracking_codes')) {
            bwt_apply_tracking_codes($order, $codes, $resolved['source'], 'customer_lookup');
        }
    }

    $date = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
    $date_formatted = ($date && method_exists($date, 'date_i18n')) ? $date->date_i18n('j F Y - H:i') : '—';
    $status = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
    $status_label = (function_exists('wc_get_order_status_name') && $status !== '') ? wc_get_order_status_name($status) : $status;

    $first_name = method_exists($order, 'get_billing_first_name') ? (string) $order->get_billing_first_name() : '';
    $last_name = method_exists($order, 'get_billing_last_name') ? (string) $order->get_billing_last_name() : '';
    $address_parts = array();
    foreach (array('get_billing_address_1', 'get_billing_address_2', 'get_billing_city', 'get_billing_state') as $method) {
        if (!method_exists($order, $method)) {
            continue;
        }
        $value = trim((string) $order->{$method}());
        if ($value !== '') {
            $address_parts[] = $value;
        }
    }
    $postcode = method_exists($order, 'get_billing_postcode') ? trim((string) $order->get_billing_postcode()) : '';
    if ($postcode !== '') {
        $address_parts[] = 'کد پستی: ' . $postcode;
    }

    $code_payload = array();
    foreach ($codes as $code) {
        $code_payload[] = array(
            'code' => $code,
            'url'  => bwt_tracking_url($code),
        );
    }

    $payload = array(
        'state'   => $codes ? 'ready' : 'pending',
        'order'   => array(
            'id'           => $order_id,
            'number'       => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order_id,
            'date'         => $date_formatted,
            'status'       => $status,
            'status_label' => $status_label,
        ),
        'customer' => array(
            'name'    => trim($first_name . ' ' . $last_name),
            'address' => implode('، ', $address_parts),
        ),
        'codes'   => $code_payload,
        'pending_lines' => $codes ? array() : bwt_pending_message_lines(),
        'poll'    => array('interval' => 30, 'max_attempts' => 20),
    );

    wp_send_json_success($payload);
}

// ========== 11. پاک کردن کدها (Job قابل ادامه) ==========
add_action('wp_ajax_bwt_cleanup_batch', 'bwt_ajax_cleanup_batch');
add_action('wp_ajax_bwt_cleanup_status', 'bwt_ajax_cleanup_status');

function bwt_cleanup_job_key($job_id) {
    return 'bwt_cleanup_job_' . md5((string) $job_id);
}

function bwt_get_cleanup_job($job_id) {
    $job_id = bwt_sanitize_job_id($job_id);
    return $job_id !== '' ? get_transient(bwt_cleanup_job_key($job_id)) : false;
}

function bwt_create_cleanup_job($mode, $order_ids = array()) {
    if (!current_user_can('manage_options')) {
        return array('job_id' => '', 'error' => 'دسترسی غیرمجاز است.');
    }
    if ($mode !== 'all' && $mode !== 'selected') {
        return array('job_id' => '', 'error' => 'نوع پاک‌سازی نامعتبر است.');
    }
    $ids = array();
    if ($mode === 'selected') {
        $examined = 0;
        foreach ((array) $order_ids as $id) {
            if (++$examined > BWT_CLEANUP_MAX_SELECTED) {
                break;
            }
            $id = (int) $id;
            if ($id > 0 && !isset($ids[$id])) {
                $ids[$id] = $id;
                if (count($ids) >= BWT_CLEANUP_MAX_SELECTED) {
                    break;
                }
            }
        }
        $ids = array_values($ids);
        if (!$ids) {
            return array('job_id' => '', 'error' => 'شماره سفارش معتبری یافت نشد.');
        }
    }
    try {
        $job_id = function_exists('wp_generate_uuid4') ? str_replace('-', '', wp_generate_uuid4()) : bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return array('job_id' => '', 'error' => 'ساخت شناسهٔ امن برای پاک‌سازی ممکن نشد.');
    }
    $job_id = bwt_sanitize_job_id($job_id);
    if ($job_id === '') {
        return array('job_id' => '', 'error' => 'ساخت شناسهٔ کار پاک‌سازی ناموفق بود.');
    }
    $job = array(
        'user_id'           => (int) get_current_user_id(),
        'mode'              => $mode,
        'ids'               => $ids,
        'offset'            => 0,
        'processed'         => 0,
        'deleted'           => 0,
        'not_found'         => 0,
        'invalid'           => 0,
        'details'           => array(),
        'details_truncated' => 0,
        'table_index'       => 0,
        'done'              => false,
        'file_error'        => '',
        'created_at'        => time(),
    );
    if (!set_transient(bwt_cleanup_job_key($job_id), $job, 3 * HOUR_IN_SECONDS)) {
        return array('job_id' => '', 'error' => 'ذخیرهٔ وضعیت پاک‌سازی ممکن نشد؛ دوباره تلاش کنید.');
    }
    return array('job_id' => $job_id, 'error' => '');
}

function bwt_cleanup_job_payload($job) {
    $selected = isset($job['mode']) && $job['mode'] === 'selected';
    $total = $selected && isset($job['ids']) && is_array($job['ids']) ? count($job['ids']) : 0;
    $processed = max(0, (int) $job['processed']);
    $progress = !empty($job['done']) ? 100 : ($selected && $total > 0 ? min(99, (int) floor(100 * $processed / $total)) : min(99, (int) $job['table_index'] * 50));
    return array(
        'mode'              => $selected ? 'selected' : 'all',
        'done'              => !empty($job['done']),
        'progress'          => $progress,
        'processed'         => $processed,
        'total'             => $total,
        'deleted'           => max(0, (int) $job['deleted']),
        'not_found'         => max(0, (int) $job['not_found']),
        'invalid'           => max(0, (int) $job['invalid']),
        'details'           => isset($job['details']) && is_array($job['details']) ? array_slice($job['details'], 0, 20) : array(),
        'details_truncated' => max(0, (int) $job['details_truncated']),
        'file_error'        => isset($job['file_error']) ? (string) $job['file_error'] : '',
    );
}

function bwt_cleanup_job_authorize() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
    }
    check_ajax_referer('bwt_cleanup_batch', 'nonce');
    $job_id = isset($_POST['job_id']) ? bwt_sanitize_job_id(wp_unslash($_POST['job_id'])) : '';
    $job = $job_id !== '' ? bwt_get_cleanup_job($job_id) : false;
    if (!$job || !is_array($job) || empty($job['user_id']) || (int) $job['user_id'] !== (int) get_current_user_id()) {
        wp_send_json_error(array('message' => 'کار پاک‌سازی پیدا نشد یا دسترسی آن منقضی شده است.'), 404);
    }
    return array($job_id, $job);
}

function bwt_cleanup_meta_keys() {
    return array('_tracking_code', '_tracking_codes');
}

/** محدود به حداکثر ۵۰۰ ردیف در هر دستور؛ نام جدول فقط از فهرست داخلی می‌آید. */
function bwt_delete_tracking_meta_batch($table, $limit = BWT_CLEANUP_BATCH_SIZE) {
    global $wpdb;
    if (!isset($wpdb->postmeta) || !isset($wpdb->prefix)) {
        return false;
    }
    $allowed = array($wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta');
    if (!in_array($table, $allowed, true)) {
        return false;
    }
    $limit = max(1, min(BWT_CLEANUP_BATCH_SIZE, (int) $limit));
    $keys = bwt_cleanup_meta_keys();
    $placeholders = implode(', ', array_fill(0, count($keys), '%s'));
    $sql = $wpdb->prepare("DELETE FROM {$table} WHERE meta_key IN ({$placeholders}) LIMIT %d", array_merge($keys, array($limit)));
    $result = $wpdb->query($sql);
    return false === $result ? false : (int) $result;
}

/** حذف کد از یک سفارش + خاموش‌کردن همگام‌سازی خودکار برای همان سفارش. */
function bwt_delete_tracking_from_order($order) {
    if (!is_object($order) || !method_exists($order, 'get_id')) {
        return false;
    }
    $order_id = (int) $order->get_id();
    if ($order_id <= 0) {
        return false;
    }
    $had_code = false;
    if (method_exists($order, 'get_meta')) {
        $had_code = bwt_clean_code($order->get_meta('_tracking_code')) !== '' || (bool) $order->get_meta('_tracking_codes');
    }
    if (function_exists('delete_post_meta')) {
        delete_post_meta($order_id, '_tracking_code');
        delete_post_meta($order_id, '_tracking_codes');
    }
    if (method_exists($order, 'delete_meta_data')) {
        $order->delete_meta_data('_tracking_code');
        $order->delete_meta_data('_tracking_codes');
        $order->delete_meta_data('_bwt_tracking_source');
        $order->update_meta_data('_bwt_autosync_off', 1);
        $order->delete_meta_data('_bwt_tracking_synced_at');
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }
    if ($had_code) {
        bwt_flush_order_counts_cache();
    }
    return $had_code;
}

function bwt_process_selected_cleanup_batch(&$job, $batch_size = BWT_CLEANUP_SELECTED_BATCH_SIZE) {
    $ids = isset($job['ids']) && is_array($job['ids']) ? $job['ids'] : array();
    $batch_size = max(1, min(BWT_CLEANUP_SELECTED_BATCH_SIZE, (int) $batch_size));
    $start = (int) $job['offset'];
    $end = min(count($ids), $start + $batch_size);
    for ($i = $start; $i < $end; $i++) {
        $order_id = (int) $ids[$i];
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) {
            $job['invalid']++;
            if (count($job['details']) < 20) {
                $job['details'][] = '#' . $order_id . ' (سفارش یافت نشد)';
            } else {
                $job['details_truncated']++;
            }
            continue;
        }
        if (bwt_delete_tracking_from_order($order)) {
            $job['deleted']++;
        } else {
            $job['not_found']++;
            if (count($job['details']) < 20) {
                $job['details'][] = '#' . $order_id . ' (کد نداشت)';
            } else {
                $job['details_truncated']++;
            }
        }
    }
    $processed = max(0, $end - $start);
    $job['offset'] = $end;
    $job['processed'] += $processed;
    $job['done'] = $end >= count($ids);
    return $processed;
}

function bwt_ajax_cleanup_status() {
    list($job_id, $job) = bwt_cleanup_job_authorize();
    wp_send_json_success(bwt_cleanup_job_payload($job));
}

function bwt_ajax_cleanup_batch() {
    list($job_id, $job) = bwt_cleanup_job_authorize();
    if (!empty($job['done'])) {
        wp_send_json_success(bwt_cleanup_job_payload($job));
    }
    if (!bwt_acquire_lock('cleanup_job_' . $job_id, 120)) {
        wp_send_json_error(array('message' => 'یک دستهٔ دیگر در حال اجراست؛ لحظه‌ای دیگر ادامه می‌دهیم.'), 409);
    }
    $job = bwt_get_cleanup_job($job_id);
    if (!$job || !is_array($job) || (int) $job['user_id'] !== (int) get_current_user_id()) {
        bwt_release_lock('cleanup_job_' . $job_id);
        wp_send_json_error(array('message' => 'کار پاک‌سازی پیدا نشد یا منقضی شده است.'), 404);
    }
    $batch_error = '';
    if (empty($job['done'])) {
        if ($job['mode'] === 'selected') {
            bwt_process_selected_cleanup_batch($job, BWT_CLEANUP_SELECTED_BATCH_SIZE);
        } else {
            global $wpdb;
            $tables = array($wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta');
            while ((int) $job['table_index'] < count($tables)) {
                $table = $tables[(int) $job['table_index']];
                if ($table === $tables[1]) {
                    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
                    if ($exists !== $table) {
                        $job['table_index']++;
                        continue;
                    }
                }
                $deleted = bwt_delete_tracking_meta_batch($table, BWT_CLEANUP_BATCH_SIZE);
                if (false === $deleted) {
                    $batch_error = 'حذف دسته‌ای از جدول اطلاعات سفارش ناموفق بود؛ دوباره تلاش کنید.';
                    break;
                }
                $job['deleted'] += $deleted;
                $job['processed'] += $deleted;
                if ($deleted < BWT_CLEANUP_BATCH_SIZE) {
                    $job['table_index']++;
                }
                break;
            }
            $job['done'] = (int) $job['table_index'] >= count($tables);
        }
        set_transient(bwt_cleanup_job_key($job_id), $job, 3 * HOUR_IN_SECONDS);
    }
    bwt_release_lock('cleanup_job_' . $job_id);
    if ($batch_error !== '') {
        wp_send_json_error(array('message' => $batch_error), 500);
    }
    wp_send_json_success(bwt_cleanup_job_payload($job));
}

function bwt_cleanup_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }
    $message = '';
    $cleanup_job_id = '';
    if (isset($_POST['bwt_delete_all']) || isset($_POST['bwt_delete_selected'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = bwt_admin_notice('danger', 'خطای امنیتی: نانس نامعتبر.');
        } else {
            $mode = isset($_POST['bwt_delete_all']) ? 'all' : 'selected';
            $order_ids = array();
            if ($mode === 'selected') {
                $raw = isset($_POST['order_ids']) ? sanitize_text_field(wp_unslash($_POST['order_ids'])) : '';
                if (strlen($raw) > 24000) {
                    $message = bwt_admin_notice('danger', 'فهرست شماره سفارش‌ها بیش از حد بزرگ است. حداکثر ۱۰۰۰ شماره در هر اجرا وارد کنید.');
                } else {
                    $parts = array_filter(array_map('trim', explode(',', bwt_normalize_digits($raw))));
                    foreach ($parts as $part) {
                        if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                            $order_ids[] = (int) $part;
                            if (count($order_ids) > BWT_CLEANUP_MAX_SELECTED) {
                                break;
                            }
                        }
                    }
                    $order_ids = array_values(array_unique($order_ids));
                    if (count($order_ids) > BWT_CLEANUP_MAX_SELECTED) {
                        $message = bwt_admin_notice('danger', 'حداکثر ۱۰۰۰ شماره سفارش در هر پاک‌سازی انتخابی مجاز است.');
                    } elseif (!$order_ids) {
                        $message = bwt_admin_notice('danger', 'شماره سفارش معتبری یافت نشد.');
                    }
                }
            }
            if ($message === '') {
                $created = bwt_create_cleanup_job($mode, $order_ids);
                if (empty($created['job_id'])) {
                    $message = bwt_admin_notice('danger', $created['error']);
                } else {
                    $cleanup_job_id = $created['job_id'];
                }
            }
        }
    }
    if (isset($_GET['bwt_cleanup_job'])) {
        $requested_id = bwt_sanitize_job_id(wp_unslash($_GET['bwt_cleanup_job']));
        $requested_job = $requested_id !== '' ? bwt_get_cleanup_job($requested_id) : false;
        if ($requested_job && (int) $requested_job['user_id'] === (int) get_current_user_id()) {
            $cleanup_job_id = $requested_id;
        } else {
            $message = bwt_admin_notice('danger', 'کار پاک‌سازی پیدا نشد یا منقضی شده است.');
        }
    }
    ?>
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('cleanup'); ?>
        <?php echo wp_kses_post($message); ?>

        <div class="bwt-stack">
            <?php if ($cleanup_job_id !== '') : ?>
                <section id="bwt-cleanup-progress" class="bwt-card" data-job-id="<?php echo esc_attr($cleanup_job_id); ?>" aria-live="polite">
                    <header class="bwt-card__head">
                        <div>
                            <h2 class="bwt-card__title">در حال پاک‌سازی</h2>
                            <p class="bwt-card__desc" data-bwt-cleanup-status>در حال آماده‌سازی…</p>
                        </div>
                    </header>
                    <div class="bwt-card__body">
                        <div class="bwt-progress__track"><progress class="bwt-progress__bar" data-bwt-cleanup-progress max="100" value="0" aria-label="پیشرفت پاک‌سازی"></progress></div>
                        <p class="bwt-progress__meta" data-bwt-cleanup-counters></p>
                        <div class="bwt-actions"><button type="button" class="bwt-btn bwt-btn--sm" data-bwt-cleanup-resume hidden>ادامهٔ پاک‌سازی</button></div>
                        <div data-bwt-cleanup-result></div>
                        <noscript><p class="bwt-hint">برای اجرای پاک‌سازی دسته‌ای، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>
                    </div>
                </section>
            <?php endif; ?>

            <form method="post" class="bwt-grid-2">
                <?php wp_nonce_field('bwt_cleanup_action', 'bwt_cleanup_nonce'); ?>
                <section class="bwt-card">
                    <header class="bwt-card__head">
                        <div>
                            <h2 class="bwt-card__title">پاک کردن انتخابی</h2>
                            <p class="bwt-card__desc">کد فقط از سفارش‌های واردشده حذف می‌شود؛ حداکثر ۱۰۰۰ شماره در هر اجرا.</p>
                        </div>
                    </header>
                    <div class="bwt-card__body">
                        <label class="bwt-field">
                            <span class="bwt-field__label">شمارهٔ سفارش‌ها (با کاما جدا کنید)</span>
                            <input type="text" id="bwt_order_ids" name="order_ids" class="bwt-input bwt-input--mono bwt-ids" dir="ltr" inputmode="numeric" placeholder="123,124,125" />
                        </label>
                        <p class="bwt-hint">برای این سفارش‌ها همگام‌سازی خودکار خاموش می‌شود تا کد دوباره برنگردد.</p>
                        <div class="bwt-actions">
                            <button type="submit" name="bwt_delete_selected" class="bwt-btn" onclick="return confirm('کد رهگیری سفارش‌های انتخابی پاک میشه؟')">پاک کردن انتخابی</button>
                        </div>
                    </div>
                </section>

                <section class="bwt-card">
                    <header class="bwt-card__head">
                        <div>
                            <h2 class="bwt-card__title">پاک کردن همهٔ کدها</h2>
                            <p class="bwt-card__desc">غیرقابل بازگشت. اگر سورناپست کد را داشته باشد، جاروی خودکار ممکن است دوباره ثبتش کند.</p>
                        </div>
                    </header>
                    <div class="bwt-card__body">
                        <p class="bwt-empty-note">پیشنهاد: پیش از این کار از دیتابیس بکاپ بگیرید.</p>
                        <div class="bwt-actions">
                            <button type="submit" name="bwt_delete_all" class="bwt-btn bwt-btn--danger" onclick="return confirm('همه کدهای رهگیری پاک میشه! مطمئنی؟ این کار برگشت نداره!')">پاک کردن همه کدهای رهگیری</button>
                        </div>
                    </div>
                </section>
            </form>
        </div>
    </div>
    <?php
}

// ========== 12. چرخهٔ عمر ==========
if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, 'bwt_activate_plugin');
}
if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, 'bwt_deactivate_plugin');
}
if (function_exists('add_action')) {
    add_action('before_woocommerce_init', 'bwt_declare_hpos_compatibility');
}

function bwt_activate_plugin() {
    bwt_maybe_schedule_cron();
}

function bwt_declare_hpos_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}
