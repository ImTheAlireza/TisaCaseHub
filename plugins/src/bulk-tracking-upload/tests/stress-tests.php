<?php
/**
 * تست‌های رفتاری «کد رهگیری پستی» (v3) — بدون وردپرس/ووکامرس.
 * اجرا: node tests/php-tests.mjs 8.3   (یا php tests/run-tests.php)
 *
 * @package Bulk_Tracking_Upload
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

/* ---------- شمارنده ---------- */
$GLOBALS['bwt_ok'] = 0;
$GLOBALS['bwt_fail'] = 0;
$GLOBALS['bwt_failures'] = array();

function t($label, $condition) {
    if ($condition) {
        $GLOBALS['bwt_ok']++;
        echo "  OK   {$label}\n";
        return;
    }
    $GLOBALS['bwt_fail']++;
    $GLOBALS['bwt_failures'][] = $label;
    echo "  FAIL {$label}\n";
}

function section($title) {
    echo "--- {$title} ---\n";
}

/* ---------- پاسخ JSON به‌شکل استثنا ---------- */
class BWT_Test_Response extends Exception {
    public $success;
    public $payload;
    public $status;
    public function __construct($success, $payload, $status = 200) {
        parent::__construct(is_array($payload) && isset($payload['message']) ? $payload['message'] : 'response');
        $this->success = $success;
        $this->payload = $payload;
        $this->status = $status;
    }
}

class WP_Error {
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = array()) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
    public function get_status() { return isset($this->data['status']) ? (int) $this->data['status'] : 0; }
}

/* ---------- هوک‌ها ---------- */
$GLOBALS['bwt_actions'] = array();
$GLOBALS['bwt_filters'] = array();
$GLOBALS['bwt_shortcodes'] = array();
$GLOBALS['bwt_rest_routes'] = array();

function add_action($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['bwt_actions'][$hook][$priority][] = array('cb' => $callback, 'args' => $args);
    return true;
}
function has_action($hook) {
    return !empty($GLOBALS['bwt_actions'][$hook]);
}
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    $GLOBALS['bwt_filters'][$hook][$priority][] = array('cb' => $callback, 'args' => $args);
    return true;
}
function apply_filters($hook, $value) {
    if (empty($GLOBALS['bwt_filters'][$hook])) {
        return $value;
    }
    ksort($GLOBALS['bwt_filters'][$hook]);
    foreach ($GLOBALS['bwt_filters'][$hook] as $callbacks) {
        foreach ($callbacks as $entry) {
            $value = call_user_func($entry['cb'], $value);
        }
    }
    return $value;
}
function do_action($hook) {
    if (empty($GLOBALS['bwt_actions'][$hook])) {
        return;
    }
    $args = array_slice(func_get_args(), 1);
    ksort($GLOBALS['bwt_actions'][$hook]);
    foreach ($GLOBALS['bwt_actions'][$hook] as $callbacks) {
        foreach ($callbacks as $entry) {
            call_user_func_array($entry['cb'], array_slice($args, 0, (int) $entry['args']));
        }
    }
}
function add_shortcode($tag, $callback) {
    $GLOBALS['bwt_shortcodes'][$tag] = $callback;
    return true;
}
function register_activation_hook($file, $callback) { return true; }
function register_deactivation_hook($file, $callback) { return true; }

/* ---------- کاربر/گزینه/ترنزینت ---------- */
$GLOBALS['bwt_user_id'] = 7;
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_crons'] = array();

function current_user_can($cap) { return true; }
function get_current_user_id() { return $GLOBALS['bwt_user_id']; }
function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['bwt_options']) ? $GLOBALS['bwt_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null) {
    $GLOBALS['bwt_options'][$key] = $value;
    return true;
}
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') {
    if (array_key_exists($key, $GLOBALS['bwt_options'])) {
        return false;
    }
    $GLOBALS['bwt_options'][$key] = $value;
    return true;
}
function delete_option($key) {
    unset($GLOBALS['bwt_options'][$key]);
    return true;
}
function get_transient($key) {
    return array_key_exists($key, $GLOBALS['bwt_transients']) ? $GLOBALS['bwt_transients'][$key] : false;
}
function set_transient($key, $value, $expiration = 0) {
    $GLOBALS['bwt_transients'][$key] = $value;
    return true;
}
function delete_transient($key) {
    unset($GLOBALS['bwt_transients'][$key]);
    return true;
}
function wp_generate_uuid4() { return 'abcdef0123456789abcdef0123456789'; }
function wp_next_scheduled($hook) { return isset($GLOBALS['bwt_crons'][$hook]) ? $GLOBALS['bwt_crons'][$hook] : false; }
function wp_schedule_event($timestamp, $recurrence, $hook) {
    $GLOBALS['bwt_crons'][$hook] = $timestamp;
    return true;
}
function wp_clear_scheduled_hook($hook) {
    unset($GLOBALS['bwt_crons'][$hook]);
}

/* ---------- امنیت/خروجی ---------- */
function plugin_dir_url($file) { return 'https://store.example.test/wp-content/plugins/bulk-tracking-upload/'; }
function admin_url($path = '') { return 'https://store.example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://store.example.test' . $path; }
function rest_url($path = '') { return 'https://store.example.test/wp-json/' . $path; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'nonce-' . $action ? 1 : false; }
function check_ajax_referer($action, $query_arg = '_ajax_nonce') {
    if (!isset($_POST[$query_arg]) || !wp_verify_nonce(wp_unslash($_POST[$query_arg]), $action)) {
        throw new BWT_Test_Response(false, array('message' => 'nonce'), 403);
    }
    return 1;
}
function sanitize_text_field($value) { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }
function wp_unslash($value) {
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
}
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_url($value) { return (string) $value; }
function esc_url_raw($value) { return (string) $value; }
function wp_kses_post($value) { return (string) $value; }
function wp_die($message) { throw new RuntimeException('wp_die:' . $message); }
function wp_send_json_success($data = null, $status = 200) { throw new BWT_Test_Response(true, $data, $status); }
function wp_send_json_error($data = null, $status = 400) { throw new BWT_Test_Response(false, $data, $status); }
function number_format_i18n($number) { return (string) $number; }
function human_time_diff($from, $to = null) { return '۵ دقیقه'; }
function date_i18n($format, $timestamp = null) { return date($format, $timestamp ? $timestamp : 1700000000); }
function checked($checked, $current = true, $echo = true) {
    $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}
function wp_nonce_field($action, $name = '_wpnonce', $referer = true, $echo = true) {
    $field = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '" />';
    if ($echo) {
        echo $field;
    }
    return $field;
}
function wp_enqueue_style($handle) { return true; }
function wp_enqueue_script($handle) { return true; }
function wp_register_style($handle, $src = '', $deps = array(), $version = false) { return true; }
function wp_register_script($handle, $src = '', $deps = array(), $version = false, $footer = false) { return true; }
function wp_localize_script($handle, $name, $data) {
    $GLOBALS['bwt_localized'][$name] = $data;
    return true;
}
function wp_style_is($handle, $list = 'registered') { return false; }
function register_rest_route($namespace, $route, $args = array()) {
    $GLOBALS['bwt_rest_routes'][$namespace . $route] = $args;
    return true;
}

/* ---------- ووکامرس ---------- */
class BWT_Test_Meta {
    public $key;
    public $value;
    public function __construct($key, $value) {
        $this->key = $key;
        $this->value = $value;
    }
    public function get_data() {
        return array('id' => 0, 'key' => $this->key, 'value' => $this->value);
    }
}

class BWT_Test_Date {
    public function date_i18n($format) { return '۱۴۰۴/۰۶/۰۱ - ۱۰:۳۰'; }
}

class BWT_Test_Order {
    public $id;
    public $meta;
    public $saved = 0;
    public $status;
    public $phone;
    public $first;
    public $last;
    public $address1;
    public $address2;
    public $city;
    public $state;
    public $postcode;

    public function __construct($id, $meta = array(), $args = array()) {
        $this->id = (int) $id;
        $this->meta = $meta;
        $this->status = isset($args['status']) ? $args['status'] : 'processing';
        $this->phone = isset($args['phone']) ? $args['phone'] : '09121234567';
        $this->first = isset($args['first']) ? $args['first'] : 'سارا';
        $this->last = isset($args['last']) ? $args['last'] : 'رضایی';
        $this->address1 = isset($args['address1']) ? $args['address1'] : 'خیابان ولیعصر';
        $this->address2 = isset($args['address2']) ? $args['address2'] : 'پلاک ۱۲';
        $this->city = isset($args['city']) ? $args['city'] : 'تهران';
        $this->state = isset($args['state']) ? $args['state'] : 'تهران';
        $this->postcode = isset($args['postcode']) ? $args['postcode'] : '1234567890';
    }
    public function get_id() { return $this->id; }
    public function get_meta($key, $single = true) {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function get_meta_data() {
        $out = array();
        foreach ($this->meta as $key => $value) {
            $out[] = new BWT_Test_Meta($key, $value);
        }
        return $out;
    }
    public function save() {
        $this->saved++;
        if (!empty($GLOBALS['bwt_dispatch_on_save'])) {
            do_action('woocommerce_update_order', $this->id);
        }
        return $this->id;
    }
    public function get_billing_phone() { return $this->phone; }
    public function get_billing_first_name() { return $this->first; }
    public function get_billing_last_name() { return $this->last; }
    public function get_billing_address_1() { return $this->address1; }
    public function get_billing_address_2() { return $this->address2; }
    public function get_billing_city() { return $this->city; }
    public function get_billing_state() { return $this->state; }
    public function get_billing_postcode() { return $this->postcode; }
    public function get_date_created() { return new BWT_Test_Date(); }
    public function get_order_number() { return (string) $this->id; }
    public function get_status() { return $this->status; }
    public function get_customer_order_notes() {
        return isset($GLOBALS['bwt_customer_notes'][$this->id]) ? $GLOBALS['bwt_customer_notes'][$this->id] : array();
    }
}

$GLOBALS['bwt_orders'] = array();
$GLOBALS['bwt_customer_notes'] = array();

function wc_get_order($order_id) {
    return isset($GLOBALS['bwt_orders'][(int) $order_id]) ? $GLOBALS['bwt_orders'][(int) $order_id] : false;
}

function wc_get_orders($args = array()) {
    $GLOBALS['bwt_last_query'] = $args;
    $orders = array_values($GLOBALS['bwt_orders']);
    if (!empty($args['status']) && is_array($args['status'])) {
        $statuses = array_map(static function ($status) { return str_replace('wc-', '', (string) $status); }, $args['status']);
        $orders = array_values(array_filter($orders, static function ($order) use ($statuses) {
            return in_array($order->status, $statuses, true);
        }));
    }
    if (!empty($args['meta_query'])) {
        foreach ($args['meta_query'] as $clause) {
            if (!isset($clause['key']) || !isset($clause['compare'])) {
                continue;
            }
            $key = $clause['key'];
            if ($clause['compare'] === 'NOT EXISTS') {
                $orders = array_values(array_filter($orders, static function ($order) use ($key) {
                    return !isset($order->meta[$key]) || $order->meta[$key] === '';
                }));
            } elseif ($clause['compare'] === 'EXISTS') {
                $orders = array_values(array_filter($orders, static function ($order) use ($key) {
                    return isset($order->meta[$key]) && $order->meta[$key] !== '';
                }));
            }
        }
    }
    $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
    $total = count($orders);
    $sliced = $limit > 0 ? array_slice($orders, 0, $limit) : $orders;
    if (!empty($args['paginate'])) {
        return (object) array('orders' => $sliced, 'total' => $total);
    }
    if (isset($args['return']) && $args['return'] === 'ids') {
        return array_map(static function ($order) { return $order->id; }, $sliced);
    }
    return $sliced;
}

function wc_get_order_status_name($status) {
    $map = array(
        'processing' => 'در حال انجام',
        'completed'  => 'تکمیل‌شده',
        'pending'    => 'در انتظار پرداخت',
        'on-hold'    => 'در انتظار',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}
function wc_get_page_screen_id($type) { return 'woocommerce_page_wc-orders'; }
function wc_get_order_notes($args = array()) { return array(); }

function get_post_meta($post_id, $key = '', $single = false) {
    $order = isset($GLOBALS['bwt_orders'][(int) $post_id]) ? $GLOBALS['bwt_orders'][(int) $post_id] : null;
    if (!$order) {
        return $single ? '' : array();
    }
    if ($key === '') {
        return $order->meta;
    }
    $values = array();
    if (array_key_exists($key, $order->meta)) {
        $values[$key] = array($order->meta[$key]);
    }
    return $values;
}
function delete_post_meta($post_id, $key, $value = '') {
    $order = isset($GLOBALS['bwt_orders'][(int) $post_id]) ? $GLOBALS['bwt_orders'][(int) $post_id] : null;
    if (!$order) {
        return false;
    }
    unset($order->meta[$key]);
    return true;
}

/* ---------- wpdb ---------- */
$GLOBALS['bwt_db_tables'] = array(
    'wp_postmeta'       => array('meta_id', 'post_id', 'meta_key', 'meta_value'),
    'wp_wc_orders_meta' => array('id', 'order_id', 'meta_key', 'meta_value'),
    'wp_sorna_orders'   => array('id', 'order_id', 'tracking_code', 'barcode', 'created_at'),
    'wp_posts'          => array('ID', 'post_type'),
);
$GLOBALS['bwt_db_results'] = array();
$GLOBALS['bwt_db_cols'] = array();

class BWT_Test_WPDB {
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $prefix = 'wp_';
    public $last_sql = '';
    public $delete_return = 0;
    public function prepare($query) {
        $args = is_array(func_get_arg(1)) ? func_get_arg(1) : array_slice(func_get_args(), 1);
        $index = 0;
        return preg_replace_callback('/%[sd]/', static function ($match) use (&$index, $args) {
            $value = isset($args[$index]) ? $args[$index] : '';
            $index++;
            return $match[0] === '%d' ? (string) (int) $value : "'" . $value . "'";
        }, $query);
    }
    public function query($sql) {
        $this->last_sql = $sql;
        return $this->delete_return;
    }
    public function get_var($sql) {
        $this->last_sql = $sql;
        if (preg_match("/^SHOW TABLES LIKE '(.+)'$/", $sql, $m)) {
            return isset($GLOBALS['bwt_db_tables'][$m[1]]) ? $m[1] : null;
        }
        return null;
    }
    public function get_col($sql) {
        $this->last_sql = $sql;
        // شبیه‌سازی کوئری متای کد رهگیری روی هر دو ذخیره‌سازی (postmeta و HPOS).
        if ((strpos($sql, 'FROM `wp_postmeta`') !== false || strpos($sql, 'FROM `wp_wc_orders_meta`') !== false)
            && strpos($sql, 'meta_key') !== false) {
            $keys = array();
            if (preg_match("/meta_key = '([^']+)'/", $sql, $m)) {
                $keys = array($m[1]);
            } elseif (preg_match("/meta_key IN \\(([^)]+)\\)/", $sql, $m)) {
                preg_match_all("/'([^']+)'/", $m[1], $mm);
                $keys = $mm[1];
            }
            $valid_only = strpos($sql, 'REGEXP') !== false;
            $ids = array();
            // آخرین IN (...) فهرست شناسه‌هاست (IN کلیدها اول می‌آید).
            if (preg_match_all('/IN \(([^)]+)\)/', $sql, $all_in)) {
                $last = end($all_in[1]);
                $ids = array_map('intval', array_map('trim', explode(',', $last)));
            }
            $out = array();
            foreach ($ids as $id) {
                $order = isset($GLOBALS['bwt_orders'][$id]) ? $GLOBALS['bwt_orders'][$id] : null;
                if (!$order) {
                    continue;
                }
                $has = false;
                foreach ($keys as $key) {
                    if (!isset($order->meta[$key]) || $order->meta[$key] === '') {
                        continue;
                    }
                    if ($valid_only) {
                        $value = is_scalar($order->meta[$key]) ? (string) $order->meta[$key] : '';
                        if (!preg_match('/^[0-9]{24}$/', $value)) {
                            continue;
                        }
                    }
                    $has = true;
                    break;
                }
                if ($has) {
                    $out[] = $id;
                }
            }
            return $out;
        }
        if ($sql === 'SHOW TABLES') {
            return array_keys($GLOBALS['bwt_db_tables']);
        }
        if (preg_match('/^SHOW COLUMNS FROM `(.+)`$/', $sql, $m)) {
            return isset($GLOBALS['bwt_db_tables'][$m[1]]) ? $GLOBALS['bwt_db_tables'][$m[1]] : array();
        }
        foreach ($GLOBALS['bwt_db_cols'] as $needle => $values) {
            if (strpos($sql, $needle) !== false) {
                return $values;
            }
        }
        return array();
    }
    public function get_results($sql) {
        $this->last_sql = $sql;
        foreach ($GLOBALS['bwt_db_results'] as $needle => $rows) {
            if (strpos($sql, $needle) !== false) {
                return $rows;
            }
        }
        return array();
    }
}
$GLOBALS['wpdb'] = new BWT_Test_WPDB();

// استاب HPOS: رفتار OrderUtil وردپرس را شبیه‌سازی می‌کند تا هر دو مسیر تست شوند.
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static function custom_orders_table_usage_is_enabled() { return !empty($GLOBALS["bwt_hpos_enabled"]); } }');
$GLOBALS['bwt_hpos_enabled'] = false;

/* ---------- REST request ---------- */
class BWT_Test_Request {
    public $body;
    public $json;
    public $headers;
    public function __construct($body, $json = array(), $headers = array()) {
        $this->body = $body;
        $this->json = $json;
        $this->headers = $headers;
    }
    public function get_header($name) { return isset($this->headers[$name]) ? $this->headers[$name] : ''; }
    public function get_body() { return $this->body; }
    public function get_json_params() { return $this->json; }
    public function get_params() { return $this->json; }
}

/* ---------- بارگذاری افزونه ---------- */
require_once dirname(__DIR__) . '/bulk-tracking-upload.php';

/* ================= تست فشار ۱: مقیاس (۵۰۰۰ سفارش) ================= */
section('فشار ۱) مقیاس ۵۰۰۰ سفارش');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
$GLOBALS['bwt_customer_notes'] = array();
$GLOBALS['bwt_hpos_enabled'] = false;
bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'scan_notes' => 1, 'sweep_enabled' => 1));

$scale_start = microtime(true);
for ($i = 0; $i < 5000; $i++) {
    $id = 10000 + $i;
    if ($i < 2000) {
        $meta = array('_tracking_code' => '993200522000276250063003', '_sorna_barcode' => '993200522000276250063003');
        $status = 'processing';
    } elseif ($i < 4000) {
        $meta = array();
        $status = 'processing';
    } elseif ($i < 4500) {
        $meta = array('_tracking_code' => '1793394914');
        $status = 'processing';
    } elseif ($i < 4800) {
        $meta = array('_sorna_barcode' => '993200522000276250063003', '_bwt_autosync_off' => 1);
        $status = 'processing';
    } else {
        $meta = array('_sorna_barcode' => '993200522000276250063003');
        $status = 'checkout-draft';
    }
    $GLOBALS['bwt_orders'][$id] = new BWT_Test_Order($id, $meta, array('status' => $status));
}
$scale_setup_time = microtime(true) - $scale_start;

$scale_start = microtime(true);
$scale_with = bwt_count_orders_with_code(true);
$scale_without = bwt_count_orders_with_code(false);
$scale_total = bwt_count_orders_recent();
$scale_count_time = microtime(true) - $scale_start;
t('مقیاس: شمارش ۵۰۰۰ سفارش درست است (۲۰۰۰ دارای کد، ۲۸۰۰ بدون کد معتبر)',
    $scale_with === 2000 && $scale_without === 2800 && $scale_total === 4800);

$scale_start = microtime(true);
$scale_missing = bwt_collect_sync_order_ids('missing');
$scale_collect_time = microtime(true) - $scale_start;
t('مقیاس: بک‌فیل «بدون کد» سقف ۵۰۰ را رعایت می‌کند و سفارش دارای کد را دربر نمی‌گیرد',
    count($scale_missing) === 500 && !in_array(10000, $scale_missing, true));

$scale_start = microtime(true);
$scale_job = bwt_create_sync_job('missing');
$scale_state = bwt_get_sync_job($scale_job['job_id']);
$scale_batches = 0;
while (!empty($scale_state) && empty($scale_state['done']) && $scale_batches < 20) {
    bwt_process_sync_batch($scale_state, 50);
    $scale_batches++;
}
$scale_sync_time = microtime(true) - $scale_start;
t('مقیاس: ۵۰۰ سفارش در ۱۰ دستهٔ ۵۰تایی کامل می‌شود',
    !empty($scale_state['done']) && $scale_state['scanned'] === 500 && $scale_batches === 10);

$scale_sweep_orders = bwt_query_orders_missing_tracking(50);
$scale_sweep_ok = count($scale_sweep_orders) <= 50;
foreach ($scale_sweep_orders as $sweep_order) {
    $sweep_codes = bwt_read_stored_codes($sweep_order);
    $sweep_valid = false;
    foreach ($sweep_codes as $sweep_code) {
        if (bwt_is_tracking_like($sweep_code)) { $sweep_valid = true; break; }
    }
    if ($sweep_valid) { $scale_sweep_ok = false; break; }
}
t('مقیاس: جارو حداکثر ۵۰ سفارش و همه بدون کد معتبر برمی‌گرداند', $scale_sweep_ok);

$scale_total_time = $scale_setup_time + $scale_count_time + $scale_collect_time + $scale_sync_time;
t('مقیاس: کل عملیات زیر ۳۰ ثانیه انجام شد (PHP-WASM)',
    $scale_total_time < 30);

/* ================= تست فشار ۲: همزمانی و عدم تکرار ================= */
section('فشار ۲) همزمانی و عدم تکرار');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode')));

// قفل: گرفتن دوباره در حالی که قفل فعال است باید شکست بخورد.
t('قفل: گرفتن دوبارهٔ قفل فعال شکست می‌خورد',
    bwt_acquire_lock('stress_lock', 60) === true && bwt_acquire_lock('stress_lock', 60) === false);
bwt_release_lock('stress_lock');
t('قفل: بعد از آزادسازی دوباره گرفته می‌شود',
    bwt_acquire_lock('stress_lock', 60) === true);
bwt_release_lock('stress_lock');

// عدم تکرار همگام‌سازی: سه بار روی یک سفارش.
$idem_order = new BWT_Test_Order(11001, array('_sorna_barcode' => '993200522000276250063003'));
$GLOBALS['bwt_orders'][11001] = $idem_order;
$idem_first = bwt_sync_order_tracking($idem_order, 'stress');
$idem_saved_after_first = $idem_order->saved;
$idem_second = bwt_sync_order_tracking($idem_order, 'stress');
$idem_third = bwt_sync_order_tracking($idem_order, 'stress');
t('عدم تکرار: همگام‌سازی تکراری ذخیرهٔ اضافه نمی‌کند',
    $idem_first['status'] === 'created' && $idem_second['status'] === 'unchanged' && $idem_third['status'] === 'unchanged'
    && $idem_order->saved === $idem_saved_after_first);

// حلقهٔ بازگشتی: ذخیرهٔ سفارش دوباره رویداد می‌سازد؛ نباید بی‌نهایت شود.
$loop_order = new BWT_Test_Order(11002, array('_sorna_barcode' => '993200522000276250063007'));
$GLOBALS['bwt_orders'][11002] = $loop_order;
$GLOBALS['bwt_dispatch_on_save'] = true;
$loop_result = bwt_sync_order_tracking($loop_order, 'stress');
$GLOBALS['bwt_dispatch_on_save'] = false;
t('حلقهٔ بازگشتی: ذخیرهٔ تودرتو بی‌نهایت نمی‌شود',
    $loop_result['status'] === 'created' && $loop_order->saved <= 2);

// پاک‌سازی تکراری: حذف دوم چیزی ندارد.
$del_order = new BWT_Test_Order(11003, array('_tracking_code' => '993200522000276250063003'));
$GLOBALS['bwt_orders'][11003] = $del_order;
$del_first = bwt_delete_tracking_from_order($del_order);
$del_second = bwt_delete_tracking_from_order($del_order);
t('پاک‌سازی تکراری: حذف دوم چیزی ندارد و خطا نمی‌دهد',
    $del_first === true && $del_second === false && !isset($del_order->meta['_tracking_code']));

// جاب همگام‌سازی: پردازش دوبارهٔ همان دسته بدون قفل نباید offset را جلو ببرد.
// (با قفل، درخواست دوم ۴۰۹ می‌گیرد؛ اینجا مستقیم پردازش می‌کنیم تا offset یک‌بار جلو برود.)
$GLOBALS['bwt_orders'] = array();
for ($i = 0; $i < 100; $i++) {
    $GLOBALS['bwt_orders'][12000 + $i] = new BWT_Test_Order(12000 + $i, array('_sorna_barcode' => '993200522000276250063003'));
}
$conc_job = bwt_create_sync_job('recent');
$conc_state = bwt_get_sync_job($conc_job['job_id']);
$conc_before = $conc_state['offset'];
bwt_process_sync_batch($conc_state, 50);
t('همزمانی: یک دسته دقیقاً ۵۰ سفارش جلو می‌رود',
    $conc_state['offset'] === $conc_before + 50 && $conc_state['scanned'] === 50);

/* ================= تست فشار ۳: فاز ورودی‌های خراب ================= */
section('فشار ۳) فاز ورودی خراب');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();

$fuzz_strings = array(
    '', ' ', 'x', '123', '993200522000276250063003', 'RR123456789IR', 'سلام دنیا',
    '۱۲۳۴۵۶۷۸۹۰۱۲۳۴۵۶۷۸۹۰۱۲۳۴', '993200522000276250063003 ', ' 993200522000276250063003',
    str_repeat('9', 10000), str_repeat('a', 5000), "\x00\x01\x02", "a\nb\nc",
    '<script>alert(1)</script>', "'; DROP TABLE wp_posts; --", '{"tracking_number":"993200522000276250063003"}',
    '[1,2,3]', 'null', 'true', '0', '-1', '999999999999999999999999999999',
);
$fuzz_ok = true;
foreach ($fuzz_strings as $s) {
    $clean = bwt_clean_code($s);
    if (!is_string($clean)) { $fuzz_ok = false; break; }
    $like = bwt_is_tracking_like($s);
    if (!is_bool($like)) { $fuzz_ok = false; break; }
    $codes = bwt_extract_codes_from_value($s);
    if (!is_array($codes)) { $fuzz_ok = false; break; }
    $pushed = bwt_sanitize_pushed_code($s);
    if (!is_string($pushed)) { $fuzz_ok = false; break; }
    $phone = bwt_normalize_phone($s);
    if (!is_string($phone)) { $fuzz_ok = false; break; }
}
t('فاز: ۲۲ رشتهٔ خراب بدون خطا و با نوع درست پردازش می‌شوند', $fuzz_ok);

$fuzz_values = array(null, true, false, 0, 123, 1.5, array(), array('x'), array(array('tracking_number' => '993200522000276250063003')), (object) array('tracking_number' => '993200522000276250063003'));
$fuzz_values_ok = true;
foreach ($fuzz_values as $v) {
    try {
        $codes = bwt_extract_codes_from_value($v);
        if (!is_array($codes)) { $fuzz_values_ok = false; break; }
        $clean = bwt_clean_code($v);
        if (!is_string($clean)) { $fuzz_values_ok = false; break; }
    } catch (Throwable $e) {
        $fuzz_values_ok = false;
        break;
    }
}
t('فاز: ۱۰ مقدار غیررشته‌ای (null/bool/عدد/آرایه/آبجکت) بدون استثنا پردازش می‌شوند', $fuzz_values_ok);

$fuzz_keys = array('', 'x', '_sorna_barcode', 'order_note', 'a b', 'a;b', "a'b", 'a"b', 'a\\b', str_repeat('k', 500), '_bwt_tracking_synced_at', '_billing_postcode', 'track', 'رهگیری');
$fuzz_keys_ok = true;
foreach ($fuzz_keys as $k) {
    $san = bwt_sanitize_source_key($k);
    if (!is_string($san)) { $fuzz_keys_ok = false; break; }
    if ($san !== '' && !preg_match('/^[A-Za-z0-9_\-:.]{1,191}$/', $san)) { $fuzz_keys_ok = false; break; }
}
t('فاز: کلیدهای خراب یا خالی یا امن می‌شوند (بدون تزریق)', $fuzz_keys_ok);

$fuzz_tables = array(
    array('table' => 'wp_posts; DROP TABLE wp_posts; --', 'order_col' => 'id', 'code_col' => 'code'),
    array('table' => 'wp_sorna_orders', 'order_col' => 'id', 'code_col' => 'id'),
    array('table' => '', 'order_col' => '', 'code_col' => ''),
    array('table' => 'wp_sorna_orders', 'order_col' => 'order_id', 'code_col' => 'tracking_code'),
    'not-an-array', null, 123,
);
$fuzz_tables_ok = true;
foreach ($fuzz_tables as $t) {
    $san = bwt_sanitize_table_source($t);
    if (!is_array($san) || !isset($san['table'], $san['order_col'], $san['code_col'])) { $fuzz_tables_ok = false; break; }
    if ($san['table'] !== '' && !preg_match('/^[A-Za-z0-9_]{1,64}$/', $san['table'])) { $fuzz_tables_ok = false; break; }
}
t('فاز: منبع جدول با تزریق SQL خالی می‌شود، منبع درست می‌ماند', $fuzz_tables_ok);

$fuzz_csv = array(
    '', "\n\n\n", "a,b,c\n1,2,3", "order_id,tracking_code\n424172,993200522000276250063003\n",
    str_repeat("424172,993200522000276250063003\n", 6000), "\xEF\xBB\xBForder_id,tracking_code\n424172,993200522000276250063003",
    "order_id,tracking_code\n\"424172\",\"993200522000276250063003\"",
);
$fuzz_csv_ok = true;
foreach ($fuzz_csv as $csv) {
    $parsed = bwt_csv_parse_rows($csv);
    if (!is_array($parsed) || !isset($parsed['rows'], $parsed['skipped'], $parsed['total'], $parsed['error'])) { $fuzz_csv_ok = false; break; }
}
$big_csv = bwt_csv_parse_rows(str_repeat("424172,993200522000276250063003\n", 6000));
$GLOBALS['bwt_user_id'] = 7;
$big_job = bwt_create_csv_job($big_csv['rows'], array());
$fuzz_csv_ok = $fuzz_csv_ok && count($big_csv['rows']) === 6000 && !empty($big_job['job_id']);
$big_state = $big_job['job_id'] !== '' ? bwt_get_csv_job($big_job['job_id']) : false;
$fuzz_csv_ok = $fuzz_csv_ok && is_array($big_state) && $big_state['total'] === 5000;
t('فاز: ۷ فایل CSV خراب/بزرگ بدون خطا و سقف ۵۰۰۰ در ساخت Job رعایت می‌شود', $fuzz_csv_ok);

$fuzz_settings = array(
    array('source_keys' => array('x', '', null, 123, array('nested'))),
    array('tracking_url' => 'javascript:alert(1)'),
    array('tracking_url' => 'http://evil.test/%s'),
    array('fallback_pdf' => 'javascript:alert(1)'),
    array('push_secret' => str_repeat('s', 500)),
    'not-an-array', null,
);
$fuzz_settings_ok = true;
foreach ($fuzz_settings as $s) {
    $san = bwt_sanitize_settings($s);
    if (!is_array($san) || !isset($san['source_keys'], $san['tracking_url'], $san['fallback_pdf'])) { $fuzz_settings_ok = false; break; }
    if (strpos($san['tracking_url'], 'http') !== 0) { $fuzz_settings_ok = false; break; }
    if ($san['fallback_pdf'] !== '' && !preg_match('#^https?://#i', $san['fallback_pdf'])) { $fuzz_settings_ok = false; break; }
    if (strlen($san['push_secret']) > 64) { $fuzz_settings_ok = false; break; }
}
t('فاز: تنظیمات خراب امن می‌شوند (URL خطرناک حذف، سقف راز ۶۴)', $fuzz_settings_ok);

/* ================= تست فشار ۴: برابری HPOS و قدیمی ================= */
section('فشار ۴) برابری HPOS و قدیمی');
function bwt_stress_scenario() {
    $GLOBALS['bwt_options'] = array();
    $GLOBALS['bwt_transients'] = array();
    $GLOBALS['bwt_orders'] = array();
    $GLOBALS['bwt_customer_notes'] = array();
    bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'scan_notes' => 1));
    for ($i = 0; $i < 100; $i++) {
        $id = 20000 + $i;
        if ($i % 4 === 0) {
            $meta = array('_sorna_barcode' => '993200522000276250063003');
        } elseif ($i % 4 === 1) {
            $meta = array('_tracking_code' => '993200522000276250063003', '_sorna_barcode' => '993200522000276250063003');
        } elseif ($i % 4 === 2) {
            $meta = array('_tracking_code' => '1793394914');
        } else {
            $meta = array();
        }
        $GLOBALS['bwt_orders'][$id] = new BWT_Test_Order($id, $meta);
    }
    $results = array();
    foreach ($GLOBALS['bwt_orders'] as $id => $order) {
        $resolved = bwt_resolve_order_tracking($order);
        $results[$id] = array($resolved['source'], $resolved['codes'], $resolved['external'] ? 1 : 0);
    }
    $with_valid = bwt_ids_with_tracking_code(array_keys($GLOBALS['bwt_orders']), true);
    $with_any = bwt_ids_with_tracking_code(array_keys($GLOBALS['bwt_orders']));
    $count_with = bwt_count_orders_with_code(true);
    $count_without = bwt_count_orders_with_code(false);
    $sql_valid = $GLOBALS['wpdb']->last_sql;
    return array($results, $with_valid, $with_any, $count_with, $count_without, $sql_valid);
}

$GLOBALS['bwt_hpos_enabled'] = false;
$legacy = bwt_stress_scenario();
$GLOBALS['bwt_hpos_enabled'] = true;
$hpos = bwt_stress_scenario();
t('برابری: حل کد در HPOS و قدیمی یکسان است',
    $legacy[0] === $hpos[0] && $legacy[1] === $hpos[1] && $legacy[2] === $hpos[2]
    && $legacy[3] === $hpos[3] && $legacy[4] === $hpos[4]);
t('برابری: کوئری قدیمی postmeta و HPOS wc_orders_meta را می‌خواند',
    strpos($legacy[5], 'wp_postmeta') !== false && strpos($hpos[5], 'wp_wc_orders_meta') !== false);
$GLOBALS['bwt_hpos_enabled'] = false;

/* ================= تست فشار ۵: امنیت و سوءاستفاده ================= */
section('فشار ۵) امنیت و سوءاستفاده');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'push_enabled' => 1, 'push_secret' => 'secret123'));

// محدودیت تلاش مشتری: ۱۰ خطا و بعد ۴۲۹.
$throttle_order = new BWT_Test_Order(30001, array('_sorna_barcode' => '993200522000276250063003'));
$GLOBALS['bwt_orders'][30001] = $throttle_order;
$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '30001', 'billing_phone' => '09999999999');
$throttle_status = 0;
for ($i = 0; $i < 12; $i++) {
    try {
        bwt_ajax_track_lookup();
    } catch (BWT_Test_Response $response) {
        $throttle_status = $response->status;
    }
}
t('امنیت: بعد از ۱۰ تلاش ناموفق، جست‌وجو ۴۲۹ می‌دهد',
    $throttle_status === 429);
$_POST = array();

// Push: امضای خراب، replay، order_id آرایه‌ای.
$push_order = new BWT_Test_Order(30002, array());
$GLOBALS['bwt_orders'][30002] = $push_order;
$push_body = '{"order_id":30002,"tracking_code":"993200522000276250063003"}';
$push_bad_sig = bwt_rest_push_permission(new BWT_Test_Request($push_body, json_decode($push_body, true), array('x_bwt_signature' => 'deadbeef')));
$push_good_req = new BWT_Test_Request($push_body, json_decode($push_body, true), array('x_bwt_signature' => hash_hmac('sha256', $push_body, 'secret123')));
$push_first = bwt_rest_push_tracking($push_good_req);
$push_replay = bwt_rest_push_tracking($push_good_req);
t('امنیت: Push امضای خراب ۴۰۱ و replay بدون تغییر (unchanged)',
    $push_bad_sig instanceof WP_Error && $push_bad_sig->get_status() === 401
    && $push_first['status'] === 'created' && $push_replay['status'] === 'unchanged');

// ادمین: نانس خراب و دسترسی کاربر دیگر به Job.
$GLOBALS['bwt_user_id'] = 7;
$GLOBALS['bwt_transients'] = array();
$foreign_job = bwt_create_sync_job('missing');
$GLOBALS['bwt_user_id'] = 8;
$_POST = array('nonce' => 'nonce-bwt_sync_batch', 'job_id' => $foreign_job['job_id']);
$foreign_result = null;
try {
    bwt_ajax_sync_batch();
} catch (BWT_Test_Response $response) {
    $foreign_result = $response;
}
t('امنیت: Job متعلق به کاربر دیگر ۴۰۴ می‌دهد',
    $foreign_result instanceof BWT_Test_Response && !$foreign_result->success && $foreign_result->status === 404);
$GLOBALS['bwt_user_id'] = 7;
$_POST = array();

// تزریق در منبع جدول: نام جدول مخرب باید خالی شود و کوئری ساخته نشود.
$GLOBALS['bwt_options'] = array();
bwt_save_settings(array('table_source' => array('table' => 'wp_posts; DROP TABLE wp_posts; --', 'order_col' => 'id', 'code_col' => 'code')));
$inject_settings = bwt_get_settings();
t('امنیت: نام جدول مخرب ذخیره نمی‌شود',
    $inject_settings['table_source']['table'] === '');

// آپلود: پسوند اشتباه و فایل خالی رد می‌شوند.
t('امنیت: آپلود با پسوند اشتباه رد می‌شود',
    bwt_csv_read_upload(array('name' => 'evil.php', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => __FILE__))['error'] !== ''
    && bwt_csv_read_upload(array('name' => 'codes.csv', 'error' => UPLOAD_ERR_OK, 'size' => 0, 'tmp_name' => __FILE__))['error'] !== '');

echo "\nنتیجه فشار: {$GLOBALS['bwt_ok']} موفق، {$GLOBALS['bwt_fail']} ناموفق\n";
if ($GLOBALS['bwt_fail'] > 0) {
    echo "موارد ناموفق:\n";
    foreach ($GLOBALS['bwt_failures'] as $failure) {
        echo " - {$failure}\n";
    }
}
exit($GLOBALS['bwt_fail'] > 0 ? 1 : 0);
