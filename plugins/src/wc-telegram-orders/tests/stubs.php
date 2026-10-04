<?php
/**
 * محیط تقلبی وردپرس/ووکامرس برای تست منطق افزونه بدون نصب وردپرس.
 * فقط توابعی که مسیرهای تست‌شده صدا می‌زنند اینجا ساخته شده‌اند.
 *
 * @package WC_Telegram_Orders
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);

/* ---------- حافظهٔ تست ---------- */

$GLOBALS['wcto_options']    = [];   // نام آپشن => مقدار
$GLOBALS['wcto_cron']       = [];   // رویدادهای زمان‌بندی‌شده: [['hook'=>..,'args'=>..,'ts'=>..]]
$GLOBALS['wcto_transients'] = [];
$GLOBALS['wcto_postmeta']   = [];   // post_id => [key => value]
$GLOBALS['wcto_orders']     = [];   // order_id => WC_Order
$GLOBALS['wcto_hooks']      = [];   // هوک‌های ثبت‌شده (فقط برای شمارش)

function wcto_reset_env() {
    $GLOBALS['wcto_options']    = [];
    $GLOBALS['wcto_cron']       = [];
    $GLOBALS['wcto_transients'] = [];
    $GLOBALS['wcto_postmeta']   = [];
    $GLOBALS['wcto_orders']     = [];
}

/** رویدادهای زمان‌بندی‌شده با هوک مشخص */
function wcto_cron_hooks($hook) {
    $out = [];
    foreach ($GLOBALS['wcto_cron'] as $ev) {
        if ($ev['hook'] === $hook) {
            $out[] = $ev;
        }
    }
    return $out;
}

/* ---------- آپشن‌ها و ترنزینت‌ها ---------- */

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['wcto_options']) ? $GLOBALS['wcto_options'][$name] : $default;
}

function update_option($name, $value, $autoload = null) {
    $GLOBALS['wcto_options'][$name] = $value;
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($name, $GLOBALS['wcto_options'])) {
        return false;
    }
    $GLOBALS['wcto_options'][$name] = $value;
    return true;
}

function delete_option($name) {
    unset($GLOBALS['wcto_options'][$name]);
    return true;
}

function wp_parse_args($args, $defaults = []) {
    if (is_object($args)) {
        $parsed = get_object_vars($args);
    } elseif (is_string($args)) {
        parse_str($args, $parsed);
    } else {
        $parsed = (array) $args;
    }
    return array_merge((array) $defaults, $parsed);
}

function get_transient($key) {
    return isset($GLOBALS['wcto_transients'][$key]) ? $GLOBALS['wcto_transients'][$key] : false;
}

function set_transient($key, $value, $expiration = 0) {
    $GLOBALS['wcto_transients'][$key] = $value;
    return true;
}

function delete_transient($key) {
    unset($GLOBALS['wcto_transients'][$key]);
    return true;
}

/* ---------- هوک‌ها ---------- */

function add_action($hook, $cb, $priority = 10, $args = 1) {
    $GLOBALS['wcto_hooks'][$hook][] = $cb;
    return true;
}
function add_filter($hook, $cb, $priority = 10, $args = 1) { return add_action($hook, $cb, $priority, $args); }
function has_action($hook, $cb = false) { return !empty($GLOBALS['wcto_hooks'][$hook]); }
function has_filter($hook, $cb = false) { return has_action($hook, $cb); }
function remove_action($hook, $cb, $priority = 10) { return true; }
function remove_filter($hook, $cb, $priority = 10) { return true; }
function do_action($hook, ...$args) { return null; }
// فیلترها در تست دست‌نخورده برمی‌گردند تا مقدار پیش‌فرض افزونه بررسی شود
function apply_filters($hook, $value, ...$args) { return $value; }
function register_activation_hook($file, $cb) { return true; }
function register_deactivation_hook($file, $cb) { return true; }
function register_setting($group, $name, $args = []) { return true; }

/* ---------- زمان‌بندی (WP-Cron) ---------- */

function wp_schedule_single_event($timestamp, $hook, $args = []) {
    $GLOBALS['wcto_cron'][] = ['ts' => (int) $timestamp, 'hook' => $hook, 'args' => $args, 'schedule' => false];
    return true;
}

function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
    $GLOBALS['wcto_cron'][] = ['ts' => (int) $timestamp, 'hook' => $hook, 'args' => $args, 'schedule' => $recurrence];
    return true;
}

function wp_next_scheduled($hook, $args = []) {
    foreach ($GLOBALS['wcto_cron'] as $ev) {
        if ($ev['hook'] === $hook && $ev['args'] === $args) {
            return $ev['ts'];
        }
    }
    return false;
}

function wp_unschedule_event($timestamp, $hook, $args = []) {
    foreach ($GLOBALS['wcto_cron'] as $i => $ev) {
        if ($ev['ts'] === (int) $timestamp && $ev['hook'] === $hook && $ev['args'] === $args) {
            unset($GLOBALS['wcto_cron'][$i]);
            $GLOBALS['wcto_cron'] = array_values($GLOBALS['wcto_cron']);
            return true;
        }
    }
    return false;
}
function wp_clear_scheduled_hook($hook, $args = []) {
    $before = count($GLOBALS['wcto_cron']);
    $GLOBALS['wcto_cron'] = array_values(array_filter($GLOBALS['wcto_cron'], function ($event) use ($hook, $args) {
        return !($event['hook'] === $hook && $event['args'] === $args);
    }));
    return $before - count($GLOBALS['wcto_cron']);
}
function _get_cron_array() {
    $cron = [];
    foreach ($GLOBALS['wcto_cron'] as $event) {
        $args = isset($event['args']) && is_array($event['args']) ? $event['args'] : [];
        $key = md5(serialize($args));
        $cron[$event['ts']][$event['hook']][$key] = [
            'schedule' => isset($event['schedule']) ? $event['schedule'] : false,
            'args'     => $args,
        ];
    }
    return $cron;
}
function spawn_cron($timestamp = 0) { return true; }

/* ---------- متای پست (قفل ارسال) ---------- */

function get_post_meta($post_id, $key = '', $single = false) {
    $all = isset($GLOBALS['wcto_postmeta'][$post_id]) ? $GLOBALS['wcto_postmeta'][$post_id] : [];
    if ($key === '') {
        return $all;
    }
    if (!array_key_exists($key, $all)) {
        return $single ? '' : [];
    }
    return $single ? $all[$key] : [$all[$key]];
}

function add_post_meta($post_id, $key, $value, $unique = false) {
    if ($unique && isset($GLOBALS['wcto_postmeta'][$post_id][$key])) {
        return false;
    }
    $GLOBALS['wcto_postmeta'][$post_id][$key] = $value;
    return true;
}

function update_post_meta($post_id, $key, $value) {
    $GLOBALS['wcto_postmeta'][$post_id][$key] = $value;
    return true;
}

function delete_post_meta($post_id, $key) {
    unset($GLOBALS['wcto_postmeta'][$post_id][$key]);
    return true;
}

function wp_cache_delete($id, $group = '') { return true; }

/* ---------- پاک‌سازی و خروجی ---------- */

function sanitize_key($key) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}
function sanitize_text_field($str) { return trim(wp_strip_all_tags((string) $str)); }
function sanitize_title($title) { return sanitize_key($title); }
function wp_strip_all_tags($str, $remove_breaks = false) { return trim(strip_tags((string) $str)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function wp_kses($content, $allowed_html) { return $content; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return esc_html($text); }
function esc_textarea($text) { return esc_html($text); }
function esc_url($url) { return (string) $url; }
function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
function absint($maybeint) { return abs((int) $maybeint); }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, (int) $decimals); }
function get_bloginfo($show = '') { return 'فروشگاه تست'; }
function home_url($path = '') { return 'http://example.test' . $path; }
function admin_url($path = '') { return 'http://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function plugin_dir_url($file) { return 'http://example.test/wp-content/plugins/wc-telegram-orders/'; }
function wp_nonce_url($url, $action = -1) { return $url; }
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) { return ''; }
function add_query_arg(...$args) {
    // add_query_arg($k, $v, $url) یا add_query_arg(['k'=>'v'], $url)
    if (count($args) >= 3) {
        $k = $args[0]; $v = $args[1]; $url = (string) $args[2];
    } elseif (count($args) === 2 && is_array($args[0])) {
        $k = $args[0]; $v = null; $url = (string) $args[1];
    } else {
        return isset($args[0]) ? (string) $args[0] : '';
    }
    $sep = (strpos($url, '?') === false) ? '?' : '&';
    if (is_array($k)) {
        foreach ($k as $kk => $vv) {
            $url .= $sep . rawurlencode((string) $kk) . '=' . rawurlencode((string) $vv);
            $sep = '&';
        }
        return $url;
    }
    return $url . $sep . rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
}
function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
function checked($checked, $current = true, $echo = true) { return ((string) $checked === (string) $current) ? 'checked' : ''; }
function selected($selected, $current = true, $echo = true) { return ((string) $selected === (string) $current) ? 'selected' : ''; }
function date_i18n($format, $timestamp = false) { return gmdate($format, $timestamp ?: time()); }
function current_time($type, $gmt = 0) { return $type === 'timestamp' ? time() : gmdate($type); }
function wp_timezone_string() { return 'Asia/Tehran'; }
function wp_timezone() { return new DateTimeZone('Asia/Tehran'); }
function wp_rand($min = 0, $max = 0) { return mt_rand($min, $max); }
function wp_generate_uuid4() { static $i = 0; $i++; return sprintf('00000000-0000-4000-8000-%012d', $i); }

/* ---------- ووکامرس ---------- */

function wc_get_order($order_id) {
    $order_id = (int) $order_id;
    return isset($GLOBALS['wcto_orders'][$order_id]) ? $GLOBALS['wcto_orders'][$order_id] : false;
}

/**
 * در تست‌های جارو: همهٔ سفارش‌های ثبت‌شده برمی‌گردند؛
 * فراخوانی تست خودش فقط سفارش‌های معوق را در $GLOBALS['wcto_orders'] می‌گذارد.
 */
function wc_get_orders($args = []) {
    return array_values($GLOBALS['wcto_orders']);
}

function wc_get_order_status_name($status) {
    $status = str_replace('wc-', '', (string) $status);
    $names  = [
        'pending'    => 'در انتظار پرداخت',
        'processing' => 'در حال انجام',
        'on-hold'    => 'در انتظار بررسی',
        'completed'  => 'تکمیل شده',
        'cancelled'  => 'لغو شده',
        'refunded'   => 'مسترد شده',
        'failed'     => 'ناموفق',
        'trash'      => 'زباله‌دان',
    ];
    return isset($names[$status]) ? $names[$status] : $status;
}

function wc_get_order_statuses() {
    return [
        'wc-pending'    => 'در انتظار پرداخت',
        'wc-processing' => 'در حال انجام',
        'wc-on-hold'    => 'در انتظار بررسی',
        'wc-completed'  => 'تکمیل شده',
        'wc-cancelled'  => 'لغو شده',
        'wc-refunded'   => 'مسترد شده',
        'wc-failed'     => 'ناموفق',
    ];
}

function wc_get_is_paid_statuses() { return ['processing', 'completed']; }
function get_woocommerce_currency() { return 'IRT'; }
function wc_get_product($id) { return false; }
function wc_attribute_label($label, $name = '', $product = null) { return $label; }
function wc_is_attribute_in_product_name($attr, $name) { return false; }
function get_permalink($id) { return 'http://example.test/?p=' . (int) $id; }
function taxonomy_exists($tax) { return false; }
function get_term_by($field, $value, $tax) { return false; }
function wp_convert_hr_to_bytes($value) { return (int) $value; }
function wp_style_is($handle, $list = 'enqueued') { return false; }
function wp_enqueue_style(...$args) { return null; }
function wp_enqueue_script(...$args) { return null; }
function settings_fields($group) { return null; }
function settings_errors($setting = '', $sanitize = false, $hide_on_update = false) { return null; }
function current_user_can($cap) { return true; }
function check_admin_referer($action = -1, $query_arg = '_wpnonce') { return true; }
function nocache_headers() { return null; }
function wp_die($message = '', $title = '', $args = []) { throw new RuntimeException(is_string($message) ? $message : 'wp_die'); }
function site_url($path = '') { return 'http://example.test' . $path; }
function dbDelta($sql = '', $execute = true) { return []; }
function wp_remote_post($url, $args = []) {
    $GLOBALS['wcto_http'][] = ['url' => $url, 'args' => $args];
    if (!empty($GLOBALS['wcto_http_fail'])) {
        return ['response' => ['code' => 401], 'body' => '{"ok":false,"description":"Unauthorized"}'];
    }
    return ['response' => ['code' => 200], 'body' => '{"ok":true,"result":{"message_id":' . (count($GLOBALS['wcto_http']) + 100) . '}}'];
}
function wp_remote_retrieve_body($response) { return is_array($response) && isset($response['body']) ? $response['body'] : ''; }
function wp_remote_retrieve_response_code($response) { return is_array($response) && isset($response['response']['code']) ? $response['response']['code'] : 0; }
function is_wp_error($thing) { return false; }

/* ---------- دیتابیس (فقط تا جایی که لاگ لازم دارد) ---------- */

class WCTO_Fake_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $queries = [];
    public function get_charset_collate() { return ''; }
    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
    public function query($sql) { $this->queries[] = $sql; return 1; }
    public function prepare($sql, ...$args) {
        if (empty($args)) {
            return $sql;
        }
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use ($args, &$i) {
            $v = isset($args[$i]) ? $args[$i] : '';
            $i++;
            return is_int($v) ? (string) $v : "'" . addslashes((string) $v) . "'";
        }, (string) $sql);
    }
    public function insert($table, $data, $format = null) { $this->queries[] = ['insert', $table, $data]; return 1; }
    public function get_var($sql = null) {
        $sql = (string) $sql;
        if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
            // جدول لاگ «موجود» فرض می‌شود تا log_table_ready() درست کار کند
            return preg_match("/LIKE '([^']+)'/", $sql, $m) ? $m[1] : '';
        }
        if (strpos($sql, 'COUNT(*)') !== false) {
            return isset($GLOBALS['wcto_log_count']) ? (int) $GLOBALS['wcto_log_count'] : 0;
        }
        return 0;
    }
    public function get_col($sql = null) { return []; }
    public function get_results($sql = null, $output = 'OBJECT') { return []; }
    public function get_orders_table_name() { return 'wp_wc_orders'; }
}
$GLOBALS['wpdb'] = new WCTO_Fake_WPDB();

/* ---------- آیتم سفارش تقلبی ---------- */

class WC_Order_Item {
    private $name;
    private $qty;
    private $total;
    private $product_id;
    public function __construct($name, $qty = 1, $total = 0, $product_id = 0) {
        $this->name = $name;
        $this->qty = (int) $qty;
        $this->total = (float) $total;
        $this->product_id = (int) $product_id;
    }
    public function get_name() { return $this->name; }
    public function get_quantity() { return $this->qty; }
    public function get_total() { return $this->total; }
    public function get_total_tax() { return 0; }
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return 0; }
    public function get_type() { return 'line_item'; }
}

/* ---------- سفارش تقلبی ---------- */

/**
 * سفارش ساختگی با همان متدهایی که افزونه صدا می‌زند.
 * (نام کلاس WC_Order است چون افزونه با instanceof همین کلاس بررسی می‌کند)
 */
class WC_Order {
    private $id;
    private $status;
    private $meta = [];
    private $notes = [];
    private $items = 1;
    private $address = true;
    private $total = 100000;
    private $created;
    private $line_items = [];
    private $phone = '09120000000';
    private $paid_at = null;
    private $pay_title = 'درگاه تست';

    public function __construct($id, $status = 'pending', $opts = []) {
        $this->id      = (int) $id;
        $this->status  = $status;
        $this->items   = isset($opts['items']) ? (int) $opts['items'] : 1;
        $this->address = !isset($opts['address']) ? true : (bool) $opts['address'];
        $this->total   = isset($opts['total']) ? $opts['total'] : 100000;
        $this->created = isset($opts['created']) ? (int) $opts['created'] : time() - 60;
        $this->line_items = !empty($opts['line_items']) ? $opts['line_items'] : [];
        $this->phone = isset($opts['phone']) ? (string) $opts['phone'] : $this->phone;
        $this->pay_title = isset($opts['payment']) ? (string) $opts['payment'] : $this->pay_title;
        if (array_key_exists('paid_at', $opts)) {
            $this->paid_at = $opts['paid_at'] ? new DateTime('@' . (int) $opts['paid_at']) : null;
        } else {
            $this->paid_at = new DateTime('@' . ($this->created + 120));
        }
        if (!empty($opts['meta'])) {
            $this->meta = $opts['meta'];
        }
    }

    /* ---- شناسه و وضعیت ---- */
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_status() { return $this->status; }
    public function set_status($status) {
        $this->status = str_replace('wc-', '', (string) $status);
        return $this;
    }
    public function get_date_created() { return new DateTime('@' . $this->created); }
    public function get_edit_order_url() { return 'http://example.test/wp-admin/post.php?post=' . $this->id; }
    public function get_currency() { return 'IRT'; }
    public function get_total() { return $this->total; }
    public function get_formatted_billing_full_name() { return 'تست خریدار'; }
    public function get_billing_phone() { return $this->phone; }
    public function get_billing_email() { return 'buyer@example.test'; }
    public function get_payment_method_title() { return $this->pay_title; }
    public function get_customer_note() { return ''; }
    public function get_item_count() {
        if (!empty($this->line_items)) {
            $n = 0;
            foreach ($this->line_items as $it) { $n += $it->get_quantity(); }
            return $n;
        }
        return $this->items;
    }
    public function get_date_paid() { return $this->paid_at; }
    public function get_items($type = '') {
        // فقط «line_item» (یا بدون آرگومان) آیتم‌ها را برمی‌گرداند؛ fee خالی است
        return ($type === '' || $type === 'line_item') ? $this->line_items : [];
    }
    public function get_shipping_methods() { return []; }
    public function get_used_coupons() { return []; }
    public function get_coupon_codes() { return []; }
    public function get_total_discount() { return 0; }
    public function get_total_tax() { return 0; }
    public function get_shipping_total() { return 0; }
    public function get_total_refunded() { return 0; }
    public function get_discount_tax() { return 0; }
    public function get_shipping_tax() { return 0; }
    public function get_meta_data() { return []; }
    public function get_formatted_meta_data() { return []; }

    /* ---- آدرس ---- */
    public function get_shipping_address_1() { return $this->address ? 'خیابان تست، پلاک ۱' : ''; }
    public function get_shipping_address_2() { return ''; }
    public function get_shipping_city() { return $this->address ? 'تهران' : ''; }
    public function get_shipping_state() { return $this->address ? 'تهران' : ''; }
    public function get_shipping_country() { return 'IR'; }
    public function get_shipping_postcode() { return '1234567890'; }
    public function get_billing_address_1() { return $this->address ? 'خیابان تست، پلاک ۱' : ''; }
    public function get_billing_address_2() { return ''; }
    public function get_billing_city() { return $this->address ? 'تهران' : ''; }
    public function get_billing_state() { return $this->address ? 'تهران' : ''; }
    public function get_billing_country() { return 'IR'; }
    public function get_billing_postcode() { return '1234567890'; }
    public function get_formatted_shipping_address() { return ''; }
    public function get_formatted_billing_address() { return ''; }

    /* ---- متا و یادداشت ---- */
    public function get_meta($key, $single = true) {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; return $this; }
    public function delete_meta_data($key) { unset($this->meta[$key]); return $this; }
    public function save_meta_data() { return $this; }
    public function save() { return $this; }
    public function add_order_note($note, $is_customer = 0) { $this->notes[] = $note; return count($this->notes); }

    /* ---- کمک‌تست ---- */
    public function wcto_notes() { return $this->notes; }
    public function wcto_meta() { return $this->meta; }
}
