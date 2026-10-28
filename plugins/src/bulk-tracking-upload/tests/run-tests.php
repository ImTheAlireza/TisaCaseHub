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
            $key = preg_match("/meta_key = '([^']+)'/", $sql, $m) ? $m[1] : '';
            $ids = array();
            if (preg_match('/IN \(([^)]+)\)/', $sql, $in)) {
                $ids = array_map('intval', array_map('trim', explode(',', $in[1])));
            }
            $out = array();
            foreach ($ids as $id) {
                $order = isset($GLOBALS['bwt_orders'][$id]) ? $GLOBALS['bwt_orders'][$id] : null;
                if ($order && isset($order->meta[$key]) && $order->meta[$key] !== '') {
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

/* ================= ۱) ابزارهای کد ================= */
section('۱) پاک‌سازی و تشخیص کد رهگیری');

t('ارقام فارسی/فاصله/خط‌تیره پاک و حروف بزرگ می‌شوند', bwt_clean_code(' ۱۲۳۴ ۵۶۷۸-۹۰ab ') === '1234567890AB');
t('شکل‌های استاندارد کد رهگیری پذیرفته می‌شوند',
    bwt_is_tracking_like('RR123456789IR')
    && bwt_is_tracking_like('123456789012345678901234')
    && bwt_is_tracking_like('TIPAX12345678'));
t('مقادیر کوتاه/متنی/خالی رد می‌شوند',
    !bwt_is_tracking_like('123') && !bwt_is_tracking_like('سلام') && !bwt_is_tracking_like(''));

$nested = array(array('tracking_number' => 'RR123456789IR', 'tracking_provider' => 'post'));
t('کد از آرایهٔ تودرتوی Shipment Tracking استخراج می‌شود', bwt_extract_codes_from_value($nested) === array('RR123456789IR'));
t('کد از رشتهٔ JSON استخراج می‌شود', bwt_extract_codes_from_value('{"tracking_number":"242111111111111111111111"}') === array('242111111111111111111111'));

$key_cases = array(
    '_sorna_post_tracking_code' => true,
    '_post_barcode'             => true,
    'barcode'                   => true,
    '_billing_phone'            => false,
    '_order_total'              => false,
);
$key_ok = true;
foreach ($key_cases as $key => $expected) {
    if (bwt_key_looks_like_tracking($key) !== $expected) {
        $key_ok = false;
    }
}
t('کلیدهای شبیه کد رهگیری فقط از الگوی مرتبط تشخیص داده می‌شوند', $key_ok);

$meta_order = new BWT_Test_Order(9001, array('_sorna_barcode' => 'RR999999999IR', '_billing_phone' => '09121234567'));
$meta_map = bwt_order_meta_map($meta_order);
t('نقشهٔ متا از WC_Meta_Data ساخته می‌شود', isset($meta_map['_sorna_barcode']) && $meta_map['_sorna_barcode'] === 'RR999999999IR');

/* ================= ۲) حل منبع و همگام‌سازی ================= */
section('۲) حل منبع و همگام‌سازی');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();

bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'scan_notes' => 1, 'sweep_enabled' => 1));
$order = new BWT_Test_Order(1001, array(
    '_tracking_code' => '111111111111111111111111',
    '_sorna_barcode' => 'RR123456789IR',
));
$resolved = bwt_resolve_order_tracking($order);
t('منبع انتخاب‌شده بر کد کانونیک قبلی اولویت دارد', $resolved['source'] === '_sorna_barcode' && $resolved['codes'] === array('RR123456789IR'));

$only_canonical = new BWT_Test_Order(1002, array('_tracking_code' => '222222222222222222222222'));
$resolved_canonical = bwt_resolve_order_tracking($only_canonical);
t('در نبود منبع بیرونی، کد کانونیک برگردانده می‌شود ولی منبع خارجی نیست',
    $resolved_canonical['source'] === '' && empty($resolved_canonical['external']) && $resolved_canonical['codes'] === array('222222222222222222222222'));

$note_order = new BWT_Test_Order(1003, array());
$GLOBALS['bwt_customer_notes'][1003] = array((object) array('content' => 'کد رهگیری مرسوله: RR555555555IR'));
t('کد از یادداشت سفارش با بافت مرتبط خوانده می‌شود', bwt_extract_codes_from_order_notes($note_order) === array('RR555555555IR'));

$phone_note = new BWT_Test_Order(1004, array());
$GLOBALS['bwt_customer_notes'][1004] = array((object) array('content' => 'شماره تماس مشتری 09121234567'));
t('یادداشت بدون بافت رهگیری/مرسوله نادیده گرفته می‌شود', bwt_extract_codes_from_order_notes($phone_note) === array());

bwt_save_settings(array('source_keys' => array('order_note'), 'scan_notes' => 0, 'sweep_enabled' => 1));
$note_selected = bwt_resolve_order_tracking($note_order);
t('انتخاب صریح order_note حتی با خاموش‌بودن گزینهٔ یادداشت‌ها کار می‌کند', $note_selected['source'] === 'order_note' && $note_selected['codes'] === array('RR555555555IR'));

bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'scan_notes' => 1, 'sweep_enabled' => 1));
$sync_order = new BWT_Test_Order(1005, array('_sorna_barcode' => 'RR123456789IR'));
$sync_result = bwt_sync_order_tracking($sync_order, 'test');
t('همگام‌سازی روی سفارش، کد + منبع + زمان ثبت می‌کند (تک‌کد فهرست جدا ندارد)',
    $sync_result['status'] === 'created'
    && $sync_order->meta['_tracking_code'] === 'RR123456789IR'
    && !isset($sync_order->meta['_tracking_codes'])
    && $sync_order->meta['_bwt_tracking_source'] === '_sorna_barcode'
    && !empty($sync_order->meta['_bwt_tracking_synced_at']));
$flag_order = new BWT_Test_Order(1010, array('_bwt_autosync_off' => 1));
bwt_apply_tracking_codes($flag_order, array('RR555555555IR'), 'manual', 'test');
t('نوشتن عمدی کد، قفل «همگام‌سازی خاموش» را برمی‌دارد',
    !isset($flag_order->meta['_bwt_autosync_off']) && $flag_order->meta['_tracking_code'] === 'RR555555555IR');

$saved_before = $sync_order->saved;
$again = bwt_sync_order_tracking($sync_order, 'test');
t('همگام‌سازی تکراری بدون تغییر، ذخیرهٔ اضافه انجام نمی‌دهد', $again['status'] === 'unchanged' && $sync_order->saved === $saved_before);

$sync_order->meta['_sorna_barcode'] = 'RR987654321IR';
$updated = bwt_sync_order_tracking($sync_order, 'test');
t('تغییر کد در منبع، کد کانونیک را به‌روز می‌کند', $updated['status'] === 'updated' && $sync_order->meta['_tracking_code'] === 'RR987654321IR');

$multi = new BWT_Test_Order(1006, array('_wc_shipment_tracking_items' => array(
    array('tracking_number' => 'RR111111111IR'),
    array('tracking_number' => 'RR222222222IR'),
)));
$multi_result = bwt_sync_order_tracking($multi, 'test');
t('چند مرسوله: کد نخست کانونیک و بقیه در فهرست ذخیره می‌شوند',
    $multi_result['status'] === 'created'
    && $multi->meta['_tracking_code'] === 'RR111111111IR'
    && $multi->meta['_tracking_codes'] === array('RR111111111IR', 'RR222222222IR'));

$disabled = new BWT_Test_Order(1007, array('_sorna_barcode' => 'RR333333333IR', '_bwt_autosync_off' => 1));
$disabled_result = bwt_sync_order_tracking($disabled, 'test');
t('سفارش با «همگام‌سازی خاموش» دست‌نخورده می‌ماند', $disabled_result['status'] === 'disabled' && !isset($disabled->meta['_tracking_code']));

$existing = new BWT_Test_Order(1008, array('_tracking_code' => 'RR777777777IR'));
$no_source = bwt_sync_order_tracking($existing, 'test');
t('نبود منبع، کد موجود را پاک نمی‌کند', $no_source['status'] === 'no_source' && $existing->meta['_tracking_code'] === 'RR777777777IR');

$loop_order = new BWT_Test_Order(1009, array('_sorna_barcode' => 'RR444444444IR'));
$GLOBALS['bwt_orders'][1009] = $loop_order;
$GLOBALS['bwt_dispatch_on_save'] = true;
$loop_result = bwt_sync_order_tracking($loop_order, 'test');
$GLOBALS['bwt_dispatch_on_save'] = false;
t('ذخیرهٔ سفارش، حلقهٔ بازگشتی همگام‌سازی نمی‌سازد', $loop_result['status'] === 'created' && $loop_order->saved <= 2 && $loop_order->meta['_tracking_code'] === 'RR444444444IR');

/* ================= ۳) Push API ================= */
section('۳) Push API با امضای HMAC');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$push_order = new BWT_Test_Order(2001, array());
$GLOBALS['bwt_orders'][2001] = $push_order;

$disabled_push = new BWT_Test_Request('', array(), array());
$disabled_result = bwt_rest_push_permission($disabled_push);
t('Push در حالت خاموش رد می‌شود (۴۰۳)', $disabled_result instanceof WP_Error && $disabled_result->get_status() === 403);

bwt_save_settings(array('push_enabled' => 1, 'push_secret' => 'secret123'));
$body = '{"order_id":2001,"tracking_code":"RR123456789IR"}';
$bad_request = new BWT_Test_Request($body, json_decode($body, true), array('x_bwt_signature' => 'deadbeef'));
$bad_result = bwt_rest_push_permission($bad_request);
t('امضای نادرست رد می‌شود (۴۰۱)', $bad_result instanceof WP_Error && $bad_result->get_status() === 401);

$good_request = new BWT_Test_Request($body, json_decode($body, true), array(
    'x_bwt_signature' => hash_hmac('sha256', $body, 'secret123'),
));
t('امضای درست پذیرفته می‌شود', bwt_rest_push_permission($good_request) === true);

$push_result = bwt_rest_push_tracking($good_request);
t('Push کد را ثبت و منبع را push_api می‌کند',
    $push_result['status'] === 'created'
    && $push_result['tracking_code'] === 'RR123456789IR'
    && $push_order->meta['_tracking_code'] === 'RR123456789IR'
    && $push_order->meta['_bwt_tracking_source'] === 'push_api');

$push_again = bwt_rest_push_tracking(new BWT_Test_Request($body, json_decode($body, true)));
t('Push تکراری، تغییری ایجاد نمی‌کند', $push_again['status'] === 'unchanged');

$no_code = bwt_rest_push_tracking(new BWT_Test_Request('', array('order_id' => 2001, 'tracking_code' => '')));
t('Push بدون کد معتبر خطای ۴۰۰ می‌دهد', $no_code instanceof WP_Error && $no_code->get_status() === 400);

$unknown = bwt_rest_push_tracking(new BWT_Test_Request('', array('order_id' => 999999, 'tracking_code' => 'RR123456789IR')));
t('Push برای سفارش ناموجود خطای ۴۰۴ می‌دهد', $unknown instanceof WP_Error && $unknown->get_status() === 404);

t('مسیر REST در هوک rest_api_init ثبت شده است', empty($GLOBALS['bwt_rest_routes']) || isset($GLOBALS['bwt_rest_routes']['bwt/v1/tracking']));

/* ================= ۴) فرم پیگیری مشتری ================= */
section('۴) جست‌وجوی مشتری با شمارهٔ سفارش');
$GLOBALS['bwt_transients'] = array();
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$GLOBALS['bwt_options'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode')));

$lookup_order = new BWT_Test_Order(3001, array('_sorna_barcode' => 'RR123456789IR'));
$GLOBALS['bwt_orders'][3001] = $lookup_order;

$_POST = array('nonce' => 'wrong', 'order_id' => '3001', 'billing_phone' => '09121234567');
$nonce_failed = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $nonce_failed = $response;
}
t('درخواست بدون نانس معتبر رد می‌شود', $nonce_failed instanceof BWT_Test_Response && !$nonce_failed->success && $nonce_failed->status === 403);

$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '3001', 'billing_phone' => '');
$missing_phone = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $missing_phone = $response;
}
t('نبود موبایل خطای ۴۰۰ و پیام راهنما می‌دهد', $missing_phone instanceof BWT_Test_Response && $missing_phone->status === 400);

$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '3001', 'billing_phone' => '09350000000');
$wrong_phone = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $wrong_phone = $response;
}
t('موبایل نامطابق ۴۰۳ و پیام یکسان (بدون افشا) می‌دهد', $wrong_phone instanceof BWT_Test_Response && $wrong_phone->status === 403 && strpos($wrong_phone->payload['message'], 'معتبر نیست') !== false);
t('تلاش ناموفق شمرده می‌شود', (int) get_transient('bwt_track_fail_' . md5('203.0.113.7')) === 1);

for ($i = 0; $i < 9; $i++) {
    bwt_track_note_fail();
}
$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '3001', 'billing_phone' => '09121234567');
$throttled = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $throttled = $response;
}
t('پس از ۱۰ تلاش ناموفق، ۱۵ دقیقه محدودیت اعمال می‌شود', $throttled instanceof BWT_Test_Response && $throttled->status === 429);

$GLOBALS['bwt_transients'] = array();
$fresh_order = new BWT_Test_Order(3002, array('_sorna_barcode' => 'RR123456789IR'));
$GLOBALS['bwt_orders'][3002] = $fresh_order;
$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '۳۰۰۲', 'billing_phone' => '۰۹۱۲۱۲۳۴۵۶۷');
$ready = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $ready = $response;
}
t('پاسخ موفق شامل کد، لینک پیگیری، شماره و وضعیت سفارش است',
    $ready instanceof BWT_Test_Response && $ready->success
    && $ready->payload['state'] === 'ready'
    && $ready->payload['codes'][0]['code'] === 'RR123456789IR'
    && strpos($ready->payload['codes'][0]['url'], 'tracking.post.ir') !== false
    && $ready->payload['order']['number'] === '3002'
    && $ready->payload['order']['status_label'] === 'در حال انجام');
t('جست‌وجوی مشتری، کد را روی سفارش ثبت می‌کند', $fresh_order->meta['_tracking_code'] === 'RR123456789IR' && $fresh_order->meta['_bwt_tracking_source'] === '_sorna_barcode');
t('شمارهٔ موبایل با ارقام فارسی و قالب‌های مختلف یکی‌سازی می‌شود', bwt_normalize_phone('۰۰۹۸۹۱۲۱۲۳۴۵۶۷') === '989121234567' && bwt_normalize_phone('+98 912 123 4567') === '989121234567');

$pending_order = new BWT_Test_Order(3003, array());
$GLOBALS['bwt_orders'][3003] = $pending_order;
$_POST = array('nonce' => 'nonce-bwt_track_lookup', 'order_id' => '3003', 'billing_phone' => '09121234567');
$pending = null;
try {
    bwt_ajax_track_lookup();
} catch (BWT_Test_Response $response) {
    $pending = $response;
}
t('سفارش بدون کد، وضعیت pending و متن آماده‌سازی می‌دهد',
    $pending instanceof BWT_Test_Response && $pending->success
    && $pending->payload['state'] === 'pending'
    && empty($pending->payload['codes'])
    && count($pending->payload['pending_lines']) >= 4
    && $pending->payload['poll']['interval'] === 30);
$_POST = array();

/* فایل PDF جایگزین: پیش‌فرض، فعال/غیرفعال، اعتبارسنجی و بازنویسی با اتِریبیوت */
$GLOBALS['bwt_options'] = array();
$fallback_default = bwt_get_settings();
t('پیش‌فرض فایل PDF جایگزین تنظیم شده است',
    isset($fallback_default['fallback_pdf'])
    && $fallback_default['fallback_pdf'] === BWT_FALLBACK_PDF_DEFAULT
    && strpos(BWT_FALLBACK_PDF_DEFAULT, 'tracking.pdf') !== false);

$form_default = bwt_tracking_search_form();
t('فرم، جعبهٔ دانلود PDF را مخفی و با لینک درست رندر می‌کند',
    strpos($form_default, 'data-bwt-fallback hidden') !== false
    && strpos($form_default, 'href="' . esc_url(BWT_FALLBACK_PDF_DEFAULT) . '"') !== false
    && strpos($form_default, 'دانلود فایل کدهای رهگیری') !== false
    && strpos($form_default, 'download') !== false);

bwt_save_settings(array('fallback_pdf' => ''));
$form_off = bwt_tracking_search_form();
t('با خالی‌بودن آدرس، جعبهٔ دانلود PDF رندر نمی‌شود', strpos($form_off, 'data-bwt-fallback') === false);

$form_attr = bwt_tracking_search_form(array('pdf' => 'https://cdn.example.test/rahgiri.pdf'));
t('اتِریبیوت pdf، آدرس تنظیمات را بازنویسی می‌کند',
    strpos($form_attr, 'https://cdn.example.test/rahgiri.pdf') !== false);

$form_bad_attr = bwt_tracking_search_form(array('pdf' => 'javascript:alert(1)'));
t('آدرس خطرناک اتِریبیوت PDF نادیده گرفته می‌شود', strpos($form_bad_attr, 'data-bwt-fallback') === false);

$pdf_clean = bwt_sanitize_settings(array('fallback_pdf' => 'javascript:alert(1)'));
t('آدرس غیر http در تنظیمات ذخیره نمی‌شود', $pdf_clean['fallback_pdf'] === '');
$pdf_ok = bwt_sanitize_settings(array('fallback_pdf' => 'https://tisacase.com/files/codes.pdf'));
t('آدرس http(s) در تنظیمات پذیرفته می‌شود', $pdf_ok['fallback_pdf'] === 'https://tisacase.com/files/codes.pdf');
bwt_save_settings(array('fallback_pdf' => BWT_FALLBACK_PDF_DEFAULT));

$layout_markup = bwt_tracking_search_form();
t('فرم، چیدمان A+ را دارد: بلوک راهنمای کوتاه + دکمهٔ غیربلوک',
    strpos($layout_markup, 'bwt-track__lead') !== false
    && strpos($layout_markup, 'کد رهگیری مرسوله') !== false
    && strpos($layout_markup, 'bwt-btn--block') === false
    && strpos($layout_markup, 'bwt-track__fields') !== false);

t('فرم کوتاه‌کد ثبت شده و فیلد موبایل اجباری دارد',
    isset($GLOBALS['bwt_shortcodes']['tracking_search_form'])
    && strpos(bwt_tracking_search_form(), 'name="billing_phone"') !== false
    && strpos(bwt_tracking_search_form(), 'data-bwt-tracking') !== false
    && strpos(bwt_tracking_search_form(), 'bwt-btn bwt-btn--primary') !== false
    && strpos(bwt_tracking_search_form(), 'bwt-field__input') !== false);

/* ================= ۵) تنظیمات، کشف منبع، اسکن ================= */
section('۵) تنظیمات و کلید منبع');
$GLOBALS['bwt_options'] = array();
$defaults = bwt_get_settings();
t('تنظیمات پیش‌فرض: جارو روشن، یادداشت روشن، Push خاموش و لینک پست',
    $defaults['sweep_enabled'] === 1 && $defaults['scan_notes'] === 1 && $defaults['push_enabled'] === 0
    && $defaults['tracking_url'] === 'https://tracking.post.ir/?id=%s');

$clean = bwt_sanitize_settings(array(
    'source_keys'   => array('_a', 'bad key!', '_a', 'order_note'),
    'scan_notes'    => '0',
    'sweep_enabled' => '1',
    'push_enabled'  => '1',
    'push_secret'   => 'ab c$#12',
    'tracking_url'  => 'https://example.test/track/%s',
));
t('کلیدهای نامعتبر/تکراری حذف و کلید order_note نگه داشته می‌شود',
    $clean['source_keys'] === array('_a', 'order_note') && $clean['scan_notes'] === 0 && $clean['push_enabled'] === 1);
t('کلید امضا فقط کاراکترهای مجاز را نگه می‌دارد', $clean['push_secret'] === 'abc12');
t('الگوی لینک بدون %s به پیش‌فرض برمی‌گردد',
    bwt_sanitize_settings(array('tracking_url' => 'https://example.test/track'))['tracking_url'] === 'https://tracking.post.ir/?id=%s');
bwt_save_settings(array('tracking_url' => 'https://example.test/t/%s'));
t('لینک پیگیری، کد را URL-encode می‌کند', bwt_tracking_url('RR 123') === 'https://example.test/t/RR%20123');

$GLOBALS['bwt_orders'] = array();
$GLOBALS['bwt_options'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'scan_notes' => 1));

bwt_save_settings(array('source_keys' => array()));
$_POST = array('nonce' => 'nonce-bwt_save_sources', 'keys' => array('_sorna_barcode', 'bad key!', '_sorna_barcode'));
$saved_sources = null;
try {
    bwt_ajax_save_sources();
} catch (BWT_Test_Response $response) {
    $saved_sources = $response;
}
t('ذخیرهٔ منابع، کلید نامعتبر و تکراری را حذف می‌کند', $saved_sources instanceof BWT_Test_Response && $saved_sources->payload['source_keys'] === array('_sorna_barcode'));
t('منبع ذخیره‌شده در تنظیمات می‌نشیند', bwt_get_settings()['source_keys'] === array('_sorna_barcode'));

$_POST = array('nonce' => 'wrong');
$save_denied = null;
try {
    bwt_ajax_save_sources();
} catch (BWT_Test_Response $response) {
    $save_denied = $response;
}
t('ذخیرهٔ کلید منبع بدون نانس معتبر رد می‌شود', $save_denied instanceof BWT_Test_Response && !$save_denied->success);
$_POST = array();

/* ================= ۶) همگام‌سازی دسته‌ای ================= */
section('۶) همگام‌سازی دسته‌ای');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode')));
for ($i = 1; $i <= 55; $i++) {
    $GLOBALS['bwt_orders'][5000 + $i] = new BWT_Test_Order(5000 + $i, array('_sorna_barcode' => 'RR1234567' . sprintf('%02d', $i) . 'IR'));
}
$GLOBALS['bwt_orders'][5100] = new BWT_Test_Order(5100, array('_tracking_code' => 'RR888888888IR'));

$job_created = bwt_create_sync_job('missing');
t('ساخت Job فقط سفارش‌های بدون کد را برمی‌دارد', !empty($job_created['job_id']) && count(bwt_get_sync_job($job_created['job_id'])['ids']) === 55);

$sync_state = bwt_get_sync_job($job_created['job_id']);
$first_batch = bwt_process_sync_batch($sync_state, BWT_SYNC_BATCH_SIZE);
t('هر batch حداکثر ۵۰ سفارش را پردازش می‌کند', $first_batch === 50 && $sync_state['created'] === 50 && !$sync_state['done']);
bwt_process_sync_batch($sync_state, BWT_SYNC_BATCH_SIZE);
t('batch دوم بقیهٔ سفارش‌ها را تمام و Job را کامل می‌کند', $sync_state['done'] && $sync_state['created'] === 55);
t('کد سفارش‌های پردازش‌شده روی سفارش نشسته است', $GLOBALS['bwt_orders'][5001]->meta['_tracking_code'] === 'RR123456701IR');

$recent_job = bwt_create_sync_job('recent');
$recent_state = bwt_get_sync_job($recent_job['job_id']);
bwt_process_sync_batch($recent_state, 100);
bwt_process_sync_batch($recent_state, 100);
t('همگام‌سازی «اخیر» سفارش دارای کد را unchanged و بدون منبع را no_source می‌شمارد',
    $recent_state['done'] && $recent_state['unchanged'] >= 55 && $recent_state['no_source'] >= 1 && $recent_state['invalid'] === 0);

$bad_mode = bwt_create_sync_job('nonsense');
t('نوع نامعتبر همگام‌سازی رد می‌شود', $bad_mode['job_id'] === '' && $bad_mode['error'] !== '');

$GLOBALS['bwt_orders'][5200] = new BWT_Test_Order(5200, array('_sorna_barcode' => 'RR123456799IR'));
$_POST = array('nonce' => 'nonce-bwt_sync_batch', 'mode' => 'missing');
$create_response = null;
try {
    bwt_ajax_sync_create();
} catch (BWT_Test_Response $response) {
    $create_response = $response;
}
t('AJAX ساخت Job پاسخ موفق با job_id می‌دهد', $create_response instanceof BWT_Test_Response && $create_response->success && !empty($create_response->payload['job_id']));

$_POST = array('nonce' => 'nonce-bwt_sync_batch', 'job_id' => $create_response->payload['job_id']);
$batch_response = null;
try {
    bwt_ajax_sync_batch();
} catch (BWT_Test_Response $response) {
    $batch_response = $response;
}
t('AJAX پردازش batch پیشرفت را برمی‌گرداند', $batch_response instanceof BWT_Test_Response && $batch_response->success && isset($batch_response->payload['progress']));

$_POST = array('nonce' => 'nonce-bwt_sync_batch', 'job_id' => 'ffffffffffffffff');
$missing_job = null;
try {
    bwt_ajax_sync_batch();
} catch (BWT_Test_Response $response) {
    $missing_job = $response;
}
t('Job ناشناس/منقضی ۴۰۴ می‌دهد', $missing_job instanceof BWT_Test_Response && $missing_job->status === 404);
$_POST = array();

/* ================= ۷) جاروی دوره‌ای ================= */
section('۷) جاروی دوره‌ای');
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'sweep_enabled' => 1));
$GLOBALS['bwt_orders'][6001] = new BWT_Test_Order(6001, array('_sorna_barcode' => 'RR111222333IR'));
$sweep = bwt_run_tracking_sweep();
t('جارو سفارش بدون کد را پیدا و کد را ثبت می‌کند', $sweep['scanned'] === 1 && $sweep['created'] === 1 && $GLOBALS['bwt_orders'][6001]->meta['_tracking_code'] === 'RR111222333IR');
$last = bwt_last_sweep();
t('آمار آخرین جارو ذخیره می‌شود', !empty($last['time']) && $last['created'] === 1);

/* رگرسیون: سفارش‌های تازه‌تر که کد دارند نباید جای سفارش‌های قدیمی‌ترِ بدون کد را در جارو بگیرند */
$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode'), 'sweep_enabled' => 1));
$GLOBALS['bwt_orders'][6101] = new BWT_Test_Order(6101, array('_tracking_code' => 'RR111222333IR', '_sorna_barcode' => 'RR111222333IR'));
$GLOBALS['bwt_orders'][6102] = new BWT_Test_Order(6102, array('_tracking_code' => 'RR444555666IR', '_sorna_barcode' => 'RR444555666IR'));
$GLOBALS['bwt_orders'][6103] = new BWT_Test_Order(6103, array('_sorna_barcode' => 'RR777888999IR'));
$missing_ids = bwt_collect_sync_order_ids('missing');
t('همگام‌سازی «بدون کد» سفارش‌های دارای کد را کنار می‌گذارد', $missing_ids === array(6103));
$sweep2 = bwt_run_tracking_sweep();
t('جارو سراغ سفارش قدیمی‌ترِ بدون کد می‌رود، نه سفارش‌های تازه‌ترِ دارای کد',
    $sweep2['scanned'] === 1 && $sweep2['created'] === 1
    && $GLOBALS['bwt_orders'][6103]->meta['_tracking_code'] === 'RR777888999IR'
    && $GLOBALS['bwt_orders'][6101]->meta['_tracking_code'] === 'RR111222333IR');
$GLOBALS['bwt_orders'][6104] = new BWT_Test_Order(6104, array());
$GLOBALS['bwt_orders'][6105] = new BWT_Test_Order(6105, array('_tracking_code' => ''));
$GLOBALS['bwt_transients'] = array();
$empty_with = bwt_count_orders_with_code(true);
$empty_without = bwt_count_orders_with_code(false);
t('سفارش با متای کدِ خالی، «بدون کد» حساب می‌شود',
    $empty_with === 3 && $empty_without === 2 && $empty_with + $empty_without === bwt_count_orders_recent());

bwt_save_settings(array('sweep_enabled' => 0, 'source_keys' => array('_sorna_barcode')));
$GLOBALS['bwt_options']['bwt_last_sweep'] = array('time' => 123, 'created' => 9);
$GLOBALS['bwt_orders'][6002] = new BWT_Test_Order(6002, array('_sorna_barcode' => 'RR444555666IR'));
$sweep_off = bwt_run_tracking_sweep();
t('جاروی خاموش هیچ سفارشی را نمی‌بیند و آمار قبلی را دست نمی‌زند', $sweep_off['scanned'] === 0 && bwt_last_sweep()['created'] === 9);

$schedules = bwt_cron_schedules(array());
t('بازهٔ ۱۵ دقیقه‌ای برای جارو ثبت می‌شود', isset($schedules['bwt_fifteen_min']) && $schedules['bwt_fifteen_min']['interval'] === 900);

$GLOBALS['bwt_crons'] = array();
bwt_maybe_schedule_cron();
t('در نبود زمان‌بندی، جارو برنامه‌ریزی می‌شود', !empty($GLOBALS['bwt_crons']['bwt_tracking_sweep']));
bwt_deactivate_plugin();
t('غیرفعال‌سازی، زمان‌بندی جارو را پاک می‌کند', empty($GLOBALS['bwt_crons']['bwt_tracking_sweep']));

/* ================= ۸) پاک‌سازی کدها ================= */
section('۸) پاک‌سازی کدها');
$GLOBALS['wpdb']->delete_return = 500;
$deleted = bwt_delete_tracking_meta_batch('wp_postmeta', 99999);
t('حذف SQL به ۵۰۰ ردیف و هر دو کلید کد محدود است',
    $deleted === 500
    && strpos($GLOBALS['wpdb']->last_sql, 'LIMIT 500') !== false
    && strpos($GLOBALS['wpdb']->last_sql, "'_tracking_code'") !== false
    && strpos($GLOBALS['wpdb']->last_sql, "'_tracking_codes'") !== false);
t('نام جدول خارج از فهرست مجاز رد می‌شود', bwt_delete_tracking_meta_batch('wp_users', 1) === false);

$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode')));
$cleanup_target = new BWT_Test_Order(7001, array(
    '_tracking_code' => 'RR123456789IR',
    '_tracking_codes' => array('RR123456789IR'),
    '_sorna_barcode' => 'RR987654321IR',
));
$GLOBALS['bwt_orders'][7001] = $cleanup_target;
$cleanup_job = bwt_create_cleanup_job('selected', array(7001, 999999));
$cleanup_state = bwt_get_cleanup_job($cleanup_job['job_id']);
$cleanup_batch = bwt_process_selected_cleanup_batch($cleanup_state, 50);
t('پاک‌سازی انتخابی، هر دو کلید را حذف و سفارش ناموجود را می‌شمارد',
    $cleanup_batch === 2
    && $cleanup_state['deleted'] === 1
    && $cleanup_state['invalid'] === 1
    && !isset($cleanup_target->meta['_tracking_code'])
    && !isset($cleanup_target->meta['_tracking_codes'])
    && $cleanup_state['done']);
t('پاک‌سازی، همگام‌سازی خودکار را برای سفارش خاموش می‌کند', (int) $cleanup_target->meta['_bwt_autosync_off'] === 1);
$resync_after_cleanup = bwt_sync_order_tracking($cleanup_target, 'test');
t('پس از پاک‌سازی عمدی، کد توسط جارو برنمی‌گردد', $resync_after_cleanup['status'] === 'disabled' && !isset($cleanup_target->meta['_tracking_code']));

$many_ids = array();
for ($i = 0; $i < 60; $i++) {
    $many_ids[] = 8000 + $i;
}
$many_job = bwt_create_cleanup_job('selected', $many_ids);
$many_state = bwt_get_cleanup_job($many_job['job_id']);
$many_first = bwt_process_selected_cleanup_batch($many_state, 99999);
t('پاک‌سازی انتخابی حتی با سقف بالاتر، ۵۰ سفارش در هر batch می‌پردازد', $many_first === 50 && !$many_state['done']);
bwt_process_selected_cleanup_batch($many_state, 50);
t('پاک‌سازی با checkpoint تا انتهای فهرست ادامه می‌یابد', $many_state['done'] && $many_state['processed'] === 60);

$all_job = bwt_create_cleanup_job('all');
t('Job پاک‌سازی «همه» بدون فهرست ساخته می‌شود', !empty($all_job['job_id']));

$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_user_id'] = 7;
$GLOBALS['wpdb']->delete_return = 0;
$all_job = bwt_create_cleanup_job('all');
$_POST = array('nonce' => 'nonce-bwt_cleanup_batch', 'job_id' => $all_job['job_id']);
$all_payloads = array();
for ($call = 0; $call < 4; $call++) {
    try {
        bwt_ajax_cleanup_batch();
    } catch (BWT_Test_Response $response) {
        $all_payloads[] = $response;
        if (!$response->success || !empty($response->payload['done'])) {
            break;
        }
    }
}
$all_last = end($all_payloads);
t('AJAX پاک‌سازی همه، جداول را تا انتها می‌پیماید و تمام می‌شود',
    $all_last instanceof BWT_Test_Response && $all_last->success && !empty($all_last->payload['done'])
    && $all_last->payload['deleted'] === 0);

$GLOBALS['bwt_user_id'] = 8;
$foreign_job = null;
try {
    bwt_ajax_cleanup_status();
} catch (BWT_Test_Response $response) {
    $foreign_job = $response;
}
t('Job پاک‌سازی متعلق به کاربر دیگر قابل استفاده نیست', $foreign_job instanceof BWT_Test_Response && !$foreign_job->success);
$GLOBALS['bwt_user_id'] = 7;
$_POST = array();

$payload_job = bwt_get_cleanup_job($all_job['job_id']);
$payload = bwt_cleanup_job_payload($payload_job);
t('پاسخ Job پاک‌سازی سقف ۲۰ جزئیات دارد', count($payload['details']) <= 20);

/* ================= ۹) رابط پیشخوان ================= */
section('۹) رابط پیشخوان');
$_GET = array();
$_POST = array();
ob_start();
bwt_render_hero('sync');
$hero = ob_get_clean();
t('سر صفحه دو تب «همگام‌سازی» و «پاک کردن کدها» و نسخه را دارد',
    strpos($hero, 'همگام‌سازی خودکار') !== false
    && strpos($hero, 'پاک کردن کدها') !== false
    && strpos($hero, 'v3.4.1') !== false);

$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array('source_keys' => array('_sorna_barcode')));
ob_start();
bwt_tracking_page();
$page = ob_get_clean();
t('صفحهٔ اصلی، شورت‌کد، فرم دستی، نشانی Push و کلیدهای منبع را نشان می‌دهد',
    strpos($page, '[tracking_search_form]') !== false
    && strpos($page, 'bwt_manual_nonce') !== false
    && strpos($page, 'wp-json/bwt/v1/tracking') !== false
    && strpos($page, 'data-key="_sorna_barcode"') !== false
    && strpos($page, 'data-bwt-sync-mode="missing"') !== false);
t('صفحهٔ اصلی، غیرفعال‌بودن CSV را نشان می‌دهد (بدون فیلد فایل)', strpos($page, 'type="file"') === false);
t('صفحهٔ اصلی، «منبع جدول اختصاصی» را دارد',
    strpos($page, 'name="table_source_table"') !== false
    && strpos($page, 'name="table_source_code_col"') !== false);
t('جدول‌های مشکوک در صفحهٔ تنظیمات فهرست می‌شوند', strpos($page, 'bwt-chip--table') !== false && strpos($page, 'wp_sorna_orders') !== false);

t('صفحهٔ اصلی سه بخش شماره‌دار ۱ تا ۳ است و ابزار «پیدا کردن کلید» حذف شده',
    strpos($page, '۱. کلید متای کد رهگیری') !== false
    && strpos($page, '۲. همگام‌سازی سفارش‌ها') !== false
    && strpos($page, '۳. تنظیمات') !== false
    && strpos($page, 'پیدا کردن کلید') === false
    && strpos($page, 'شناسایی منابع') === false
    && strpos($page, 'تعداد سفارش نمونه') === false
    && strpos($page, 'bwt-card__head') !== false
    && strpos($page, 'bwt-stack') !== false);
t('صفحهٔ اصلی، قلاب‌های JS لازم را دارد و قلاب‌های حذف‌شده را نه',
    strpos($page, 'data-bwt-source-form') !== false
    && strpos($page, 'data-bwt-source-key') !== false
    && strpos($page, 'data-bwt-source-status') !== false
    && strpos($page, 'data-bwt-sync-counters') !== false
    && strpos($page, 'data-bwt-source-chips') !== false
    && strpos($page, 'data-bwt-scan') === false
    && strpos($page, 'data-bwt-find-key') === false);
t('مسیرهای کشف خودکار کلید از افزونه حذف شده‌اند',
    !function_exists('bwt_scan_order_sources')
    && !function_exists('bwt_find_meta_keys_by_value')
    && !defined('BWT_SCAN_SAMPLE_ORDERS')
    && !has_action('wp_ajax_bwt_scan_sources')
    && !has_action('wp_ajax_bwt_find_key'));
t('ارقام نمایش، فارسی می‌شوند', bwt_fmt_digits('1,234') === '۱٬۲۳۴' && bwt_fmt_digits(207) === '۲۰۷');
t('کارت‌های خلاصه ارقام فارسی و جمع‌بندی کل بازه را نشان می‌دهند',
    strpos($page, 'bwt-kpi-note') !== false
    && preg_match('/bwt-kpi__value">[۰-۹٬]+</u', $page) === 1
    && preg_match('/از [۰-۹٬]+ سفارش ۴۵ روز اخیر/u', $page) === 1);

$notice_probe = bwt_admin_notice('danger', 'x');
t('پیام‌های پیشخوان کلاس bwt-notice دارند',
    strpos($notice_probe, 'bwt-notice') !== false && strpos($notice_probe, 'tisa-notice--danger') !== false);

$_POST = array(
    'bwt_manual_save'      => '1',
    'bwt_manual_nonce'     => 'nonce-bwt_manual_action',
    'manual_order_id'      => '9001',
    'manual_tracking_code' => 'rr 123-456-789 ir',
);
$manual_order = new BWT_Test_Order(9001, array());
$GLOBALS['bwt_orders'][9001] = $manual_order;
ob_start();
bwt_tracking_page();
$page_manual = ob_get_clean();
t('ثبت دستی کد، کد را نرمال و روی سفارش ذخیره می‌کند',
    $manual_order->meta['_tracking_code'] === 'RR123456789IR'
    && $manual_order->meta['_bwt_tracking_source'] === 'manual'
    && strpos($page_manual, 'ثبت شد') !== false);

$_POST = array(
    'bwt_manual_save'      => '1',
    'bwt_manual_nonce'     => 'nonce-bwt_manual_action',
    'manual_order_id'      => '9001',
    'manual_tracking_code' => '!!!',
);
ob_start();
bwt_tracking_page();
$page_bad_manual = ob_get_clean();
t('کد دستی نامعتبر ذخیره نمی‌شود و پیام خطا دارد',
    $manual_order->meta['_tracking_code'] === 'RR123456789IR' && strpos($page_bad_manual, 'معتبر نیست') !== false);

$_POST = array(
    'bwt_save_settings'   => '1',
    'bwt_settings_nonce'  => 'nonce-bwt_settings_action',
    'sweep_enabled'       => '1',
    'scan_notes'          => '1',
    'push_enabled'        => '1',
    'tracking_url'        => 'https://track.example.test/?code=%s',
);
ob_start();
bwt_tracking_page();
ob_get_clean();
$saved_settings = bwt_get_settings();
t('ذخیرهٔ تنظیمات از فرم پیشخوان اعمال می‌شود',
    $saved_settings['push_enabled'] === 1 && $saved_settings['tracking_url'] === 'https://track.example.test/?code=%s');
$_POST = array();

$meta_box_order = new BWT_Test_Order(9100, array(
    '_tracking_code'       => 'RR123456789IR',
    '_bwt_tracking_source' => '_sorna_barcode',
    '_bwt_tracking_synced_at' => 1700000000,
));
ob_start();
bwt_render_order_tracking_box($meta_box_order);
$meta_box = ob_get_clean();
t('متاباکس سفارش، کد/منبع/زمان و لینک ویرایش دستی را نشان می‌دهد',
    strpos($meta_box, 'RR123456789IR') !== false
    && strpos($meta_box, '_sorna_barcode') !== false
    && strpos($meta_box, 'bwt_order=9100') !== false);

ob_start();
bwt_render_order_tracking_box(new BWT_Test_Order(9101, array()));
$meta_box_empty = ob_get_clean();
t('متاباکس سفارش بدون کد، خطا نمی‌دهد', strpos($meta_box_empty, 'هنوز کدی ثبت نشده') !== false);

$with = new BWT_Test_Order(9201, array('_tracking_code' => 'RR111111111IR'));
$GLOBALS['bwt_orders'] = array(9201 => $with, 9202 => new BWT_Test_Order(9202, array()));
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_options'] = array();
$count_with = bwt_count_orders_with_code(true);
$count_without = bwt_count_orders_with_code(false);
t('شمارندهٔ داشبورد، سفارش‌های دارای/بدون کد را جدا می‌شمارد', $count_with === 1 && $count_without === 1);
t('شمارندهٔ داشبورد نتیجه را کش می‌کند', get_transient('bwt_count_with_code') === 1);

/* دو عدد داشبورد باید همیشه با هم بخوانند: دارای کد + بدون کد = کل سفارش‌های بازه */
$GLOBALS['bwt_orders'] = array(
    9210 => new BWT_Test_Order(9210, array('_tracking_code' => 'RR111111111IR')),
    9211 => new BWT_Test_Order(9211, array('_tracking_code' => 'RR222222222IR')),
    9212 => new BWT_Test_Order(9212, array()),
    9213 => new BWT_Test_Order(9213, array('_tracking_code' => 'RR333333333IR', '_sorna_barcode' => 'RR333333333IR')),
    9214 => new BWT_Test_Order(9214, array('_tracking_code' => '')),
);
$GLOBALS['bwt_transients'] = array();
$sum_with = bwt_count_orders_with_code(true);
$sum_without = bwt_count_orders_with_code(false);
$sum_total = bwt_count_orders_recent();
t('دارای کد و بدون کد با هم برابر نیستند و جمعشان کل سفارش‌های بازه است',
    $sum_with === 3 && $sum_without === 2 && $sum_total === 5 && $sum_with + $sum_without === $sum_total);
t('کل سفارش‌های بازه کش می‌شود', get_transient('bwt_count_total') === 5);
bwt_flush_order_counts_cache();
t('پاک‌کردن کش شمارنده‌ها هر سه کلید را می‌زداید',
    get_transient('bwt_count_with_code') === false
    && get_transient('bwt_count_without_code') === false
    && get_transient('bwt_count_total') === false);

/* مسیر HPOS: جدول wp_wc_orders_meta و ستون order_id */
$GLOBALS['bwt_orders'] = array(
    9220 => new BWT_Test_Order(9220, array('_tracking_code' => 'RR444444444IR')),
    9221 => new BWT_Test_Order(9221, array()),
);
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_hpos_enabled'] = true;
$hpos_with = bwt_count_orders_with_code(true);
$hpos_without = bwt_count_orders_with_code(false);
$hpos_sql = $GLOBALS['wpdb']->last_sql;
t('شمارش در حالت HPOS از جدول سفارش‌های سفارشی می‌خواند',
    $hpos_with === 1 && $hpos_without === 1
    && strpos($hpos_sql, 'wp_wc_orders_meta') !== false
    && strpos($hpos_sql, '`order_id`') !== false);
$GLOBALS['bwt_hpos_enabled'] = false;
$GLOBALS['bwt_transients'] = array();

$_GET = array('bwt_cleanup_job' => 'invalid-id');
$_POST = array();
ob_start();
bwt_cleanup_page();
$cleanup_page = ob_get_clean();
t('صفحهٔ پاک‌سازی با Job نامعتبر، پیام خطا و هر دو کارت را نشان می‌دهد',
    strpos($cleanup_page, 'کار پاک‌سازی پیدا نشد') !== false
    && strpos($cleanup_page, 'پاک کردن انتخابی') !== false
    && strpos($cleanup_page, 'پاک کردن همهٔ کدها') !== false);
$_GET = array();

/* ================= ۱۰) پیدا کردن کلید با کد + جدول اختصاصی ================= */
section('۱۰) منبع جدول اختصاصی (پشتیبان)');

// همان کد واقعی سورناپست از پنل کاربر (۲۴ رقم).
$real_code = '799400522201761978143147';

$tables_found = bwt_candidate_tables();
$table_names = array_column($tables_found, 'table');
t('جدول‌های مشکوک فقط نام‌های مرتبط را برمی‌گردانند و جدول متا را نه',
    in_array('wp_sorna_orders', $table_names, true)
    && !in_array('wp_postmeta', $table_names, true)
    && !in_array('wp_wc_orders_meta', $table_names, true)
    && !in_array('wp_posts', $table_names, true));

$clean_table = bwt_sanitize_table_source(array(
    'table'     => 'wp_sorna_orders',
    'order_col' => 'order_id',
    'code_col'  => 'tracking_code',
));
t('منبع جدول معتبر ذخیره می‌شود', $clean_table['table'] === 'wp_sorna_orders' && $clean_table['code_col'] === 'tracking_code');
t('منبع جدول با شناسهٔ نامعتبر یا ستون تکراری پاک می‌شود',
    bwt_sanitize_table_source(array('table' => 'wp_sorna_orders; DROP', 'order_col' => 'order_id', 'code_col' => 'code'))['table'] === ''
    && bwt_sanitize_table_source(array('table' => 'wp_sorna_orders', 'order_col' => 'x', 'code_col' => 'x'))['table'] === '');

$GLOBALS['bwt_options'] = array();
$GLOBALS['bwt_transients'] = array();
$GLOBALS['bwt_orders'] = array();
bwt_save_settings(array(
    'source_keys'  => array(),
    'table_source' => array('table' => 'wp_sorna_orders', 'order_col' => 'order_id', 'code_col' => 'tracking_code'),
));
$GLOBALS['bwt_db_cols'] = array(
    'SELECT `tracking_code` FROM `wp_sorna_orders` WHERE `order_id` = 9500' => array($real_code),
    'SELECT `tracking_code` FROM `wp_sorna_orders` WHERE `order_id` = 9501' => array('RR123456789IR', $real_code),
);
$table_order = new BWT_Test_Order(9500, array());
$GLOBALS['bwt_orders'][9500] = $table_order;
$table_result = bwt_sync_order_tracking($table_order, 'test');
t('کد از جدول اختصاصی خوانده و با نام منبع table: روی سفارش ثبت می‌شود',
    $table_result['status'] === 'created'
    && $table_order->meta['_tracking_code'] === $real_code
    && strpos((string) $table_order->meta['_bwt_tracking_source'], 'table:wp_sorna_orders.tracking_code') === 0);
t('کد ۲۴ رقمی پست با الگوی تشخیص کد رهگیری سازگار است', bwt_is_tracking_like($real_code));

$multi_table = new BWT_Test_Order(9501, array());
$GLOBALS['bwt_orders'][9501] = $multi_table;
bwt_sync_order_tracking($multi_table, 'test');
t('چند ردیف در جدول اختصاصی، به چند مرسوله تبدیل می‌شود',
    $multi_table->meta['_tracking_code'] === 'RR123456789IR'
    && $multi_table->meta['_tracking_codes'] === array('RR123456789IR', $real_code));

bwt_save_settings(array('table_source' => array('table' => 'wp_unknown_table', 'order_col' => 'order_id', 'code_col' => 'code')));
$GLOBALS['bwt_db_cols'] = array();
$missing_table_order = new BWT_Test_Order(9502, array());
$GLOBALS['bwt_orders'][9502] = $missing_table_order;
t('جدول ناموجود، خطا نمی‌دهد و کدی ثبت نمی‌شود',
    bwt_sync_order_tracking($missing_table_order, 'test')['status'] === 'no_source'
    && !isset($missing_table_order->meta['_tracking_code']));

bwt_save_settings(array('table_source' => array('table' => 'wp_sorna_orders', 'order_col' => 'missing_col', 'code_col' => 'tracking_code')));
t('ستون ناموجود در جدول، منبع را بی‌اثر می‌کند', bwt_custom_table_codes(9503) === array());

bwt_save_settings(array('table_source' => array()));

/* ================= ۱۱) حذف مسیر CSV ================= */
section('۱۱) حذف کامل مسیر CSV');
t('توابع قدیمی آپلود CSV حذف شده‌اند',
    !function_exists('bwt_create_upload_job')
    && !function_exists('bwt_process_tracking_csv_row')
    && !function_exists('bwt_process_csv_batch_handle')
    && !function_exists('bwt_ajax_upload_batch'));
t('هیچ اکشن آپلود فایلی ثبت نشده است', !isset($GLOBALS['bwt_actions']['wp_ajax_bwt_upload_batch']));
t('نسخهٔ افزونه 3.4.1 است', defined('BWT_VERSION') && BWT_VERSION === '3.4.1');

/* ---------- نتیجه ---------- */
echo "\nنتیجه: {$GLOBALS['bwt_ok']} موفق، {$GLOBALS['bwt_fail']} ناموفق\n";
if ($GLOBALS['bwt_fail'] > 0) {
    echo "موارد ناموفق:\n";
    foreach ($GLOBALS['bwt_failures'] as $failure) {
        echo " - {$failure}\n";
    }
}
exit($GLOBALS['bwt_fail'] > 0 ? 1 : 0);
