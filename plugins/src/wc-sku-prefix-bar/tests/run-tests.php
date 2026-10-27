<?php
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

class WCSPB_Test_Response extends Exception {
    public $success;
    public $payload;
    public function __construct($success, $payload) { $this->success = $success; $this->payload = $payload; }
}

$GLOBALS['wcspb_hooks'] = array('action' => array(), 'filter' => array());
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['wcspb_hooks']['action'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['wcspb_hooks']['filter'][$hook][] = $callback; }
function register_deactivation_hook(...$args) {}
function current_user_can($cap) { return true; }
function check_ajax_referer($action, $field) { if ($action !== 'wcspb' || !isset($_POST[$field]) || $_POST[$field] !== 'valid') throw new RuntimeException('Bad nonce'); }
function wp_send_json_success($data = null) { throw new WCSPB_Test_Response(true, $data); }
function wp_send_json_error($data = null) { throw new WCSPB_Test_Response(false, $data); }
function get_post_meta($id, $key, $single = false) {
    $values = isset($GLOBALS['wcspb_postmeta'][$id][$key]) ? $GLOBALS['wcspb_postmeta'][$id][$key] : array();
    return $single ? (isset($values[0]) ? $values[0] : '') : $values;
}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['wcspb_options']) ? $GLOBALS['wcspb_options'][$key] : $default; }
function add_option($key, $value, $deprecated = '', $autoload = true) {
    if (array_key_exists($key, $GLOBALS['wcspb_options'])) return false;
    $GLOBALS['wcspb_options'][$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = null) { $GLOBALS['wcspb_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['wcspb_options'][$key]); return true; }
$GLOBALS['wcspb_options'] = array();
$GLOBALS['wcspb_transients'] = array();
function get_transient($key) { return isset($GLOBALS['wcspb_transients'][$key]) ? $GLOBALS['wcspb_transients'][$key] : false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['wcspb_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['wcspb_transients'][$key]); return true; }
function wp_generate_uuid4() { static $n = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$n); }
function __( $text, $domain = null ) { return $text; }
function esc_html_e($text, $domain = null) { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr_e($text, $domain = null) { echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function sanitize_text_field($text) { return is_string($text) ? trim(strip_tags($text)) : ''; }
function sanitize_key($text) { return is_string($text) ? strtolower(preg_replace('/[^a-z0-9_\-]/', '', $text)) : ''; }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function number_format_i18n($number) { return (string) $number; }
function wp_style_is($handle, $state) { return false; }
function plugins_url($path, $file) { return $path; }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script(...$args) {}
function admin_url($path = '') { return '/wp-admin/' . $path; }
function wp_create_nonce($action) { return 'valid'; }
function get_the_ID() { return 0; }
function absint($value) { return abs((int) $value); }
function wp_get_post_parent_id($id) { return 0; }
function get_the_title($id) { return ''; }
function get_edit_post_link($id, $context = '') { return ''; }
function rest_ensure_response($value) { return $value; }
class WP_REST_Server { const READABLE = 'GET'; }
class WP_REST_Request { public function get_param($key) { return ''; } }
class WP_Error { public $code; public $message; public $data; public function __construct($code, $message, $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; } }
function register_rest_route(...$args) {}

class WCSPB_Test_DB {
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $lookup_exists = true;
    public $lookup_rows = array();
    public $postmeta_rows = array();
    public $queries = array();
    public $max_rows = 0;
    public $get_col_calls = 0;
    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1);
        }
        return $query;
    }
    public function get_var($query) {
        if (strpos($query, 'SHOW TABLES') !== false) return $this->lookup_exists ? 'wp_wc_product_meta_lookup' : null;
        return null;
    }
    public function get_col($query) { $this->get_col_calls++; return array(); }
    public function get_results($query, $output = OBJECT) {
        $this->queries[] = $query;
        if (strpos($query, 'FROM wp_wc_product_meta_lookup') !== false) {
            preg_match('/product_id > (\d+)/', $query, $match);
            $cursor = isset($match[1]) ? (int) $match[1] : 0;
            $rows = array_values(array_filter($this->lookup_rows, function ($row) use ($cursor) { return (int) $row['product_id'] > $cursor; }));
        } else {
            preg_match('/pm\.meta_id > (\d+)/', $query, $match);
            $cursor = isset($match[1]) ? (int) $match[1] : 0;
            $rows = array_values(array_filter($this->postmeta_rows, function ($row) use ($cursor) { return (int) $row['meta_id'] > $cursor; }));
        }
        preg_match('/LIMIT (\d+)/', $query, $limit_match);
        $limit = isset($limit_match[1]) ? (int) $limit_match[1] : 0;
        $rows = array_slice($rows, 0, $limit);
        $this->max_rows = max($this->max_rows, count($rows));
        return $rows;
    }
}
$GLOBALS['wpdb'] = new WCSPB_Test_DB();
$GLOBALS['wcspb_postmeta'] = array();
require_once dirname(__DIR__) . '/wc-sku-prefix-bar.php';
WC_SKU_Prefix_Bar::init();

$pass = 0;
$fail = 0;
function wcspb_test($name, $ok, $info = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($info !== '' ? ' >> ' . $info : '') . "\n"; }
}
function make_sku_rows($count, $prefix = 'AB') {
    $rows = array();
    for ($i = 1; $i <= $count; $i++) $rows[] = array('product_id' => $i, 'sku' => $prefix . str_pad((string) $i, 6, '0', STR_PAD_LEFT));
    return $rows;
}
function catch_ajax($callback) {
    try { $callback(); } catch (WCSPB_Test_Response $response) { return $response; }
    return null;
}

$plugin = new WC_SKU_Prefix_Bar();
$GLOBALS['wpdb']->lookup_rows = make_sku_rows(1205, 'AB');
$state1 = $plugin->get_latest_state();
$state2 = $plugin->get_latest_state();
$state3 = $plugin->get_latest_state();
wcspb_test('cold-cache scan advances in bounded 500-row checkpoints', !$state1['done'] && $state1['processed'] === 500 && !$state2['done'] && $state2['processed'] === 1000 && $state3['done'] && $state3['processed'] === 1205);
wcspb_test('no query reads more than 500 SKU rows and old unbounded get_col is never used', $GLOBALS['wpdb']->max_rows <= 500 && $GLOBALS['wpdb']->get_col_calls === 0 && count($GLOBALS['wpdb']->queries) === 3);
wcspb_test('prefix summary retains the highest numeric SKU and zero-padding', count($state3['items']) === 1 && $state3['items'][0]['label'] === 'AB001205' && $state3['items'][0]['next'] === 'AB001206');
$queries_before_cache = count($GLOBALS['wpdb']->queries);
$cached = $plugin->get_latest_state();
wcspb_test('completed six-hour cache hit does not query SKU tables', $cached['done'] && count($GLOBALS['wpdb']->queries) === $queries_before_cache);
$_POST = array('nonce' => 'valid', 'surface' => 'editor');
$summary_response = catch_ajax(array($plugin, 'ajax_summary'));
wcspb_test('summary AJAX returns the completed cached series list for the SKU editor', $summary_response instanceof WCSPB_Test_Response && $summary_response->success && $summary_response->payload['done'] && count($summary_response->payload['items']) === 1 && !isset($summary_response->payload['html']));

$_POST = array('nonce' => 'valid', 'prefix' => 'AB');
$next_response = catch_ajax(array($plugin, 'ajax_next'));
wcspb_test('ajax_next uses the completed cache and returns next SKU without rebuilding it', $next_response instanceof WCSPB_Test_Response && $next_response->success && $next_response->payload['next'] === 'AB001206' && count($GLOBALS['wpdb']->queries) === $queries_before_cache);
$generation_before_same = get_option(WC_SKU_Prefix_Bar::GENERATION_OPTION);
$GLOBALS['wcspb_postmeta'][7]['_sku'] = array('AB001205');
WC_SKU_Prefix_Bar::flush_after_product_save_if_sku_changed(7);
wcspb_test('saving the same SKU keeps the cached summary', get_option(WC_SKU_Prefix_Bar::GENERATION_OPTION) === $generation_before_same && is_array(get_transient(WC_SKU_Prefix_Bar::TRANSIENT)));
WC_SKU_Prefix_Bar::flush_after_sku_update(99, 7, '_sku', 'AB001206');
wcspb_test('changing SKU invalidates the final cache after the metadata write', false === get_transient(WC_SKU_Prefix_Bar::TRANSIENT) && get_option(WC_SKU_Prefix_Bar::GENERATION_OPTION) !== $generation_before_same);
$generation_after_meta = get_option(WC_SKU_Prefix_Bar::GENERATION_OPTION);
set_transient(WC_SKU_Prefix_Bar::TRANSIENT, array('generation' => $generation_after_meta, 'items' => array()), WC_SKU_Prefix_Bar::TTL);
WC_SKU_Prefix_Bar::flush_after_product_save_if_sku_changed(7);
wcspb_test('WooCommerce lookup-table sync gets a second conditional invalidation only after SKU metadata changed', false === get_transient(WC_SKU_Prefix_Bar::TRANSIENT) && get_option(WC_SKU_Prefix_Bar::GENERATION_OPTION) !== $generation_after_meta);

$before_actions = $GLOBALS['wcspb_hooks']['action'];
wcspb_test('SKU metadata events and conditional sync hooks replace broad product-save invalidation', !isset($before_actions['save_post_product']) && !isset($before_actions['save_post_product_variation']) && isset($before_actions['woocommerce_update_product']) && isset($before_actions['added_post_meta']) && isset($before_actions['updated_post_meta']) && isset($before_actions['deleted_post_meta']));

WC_SKU_Prefix_Bar::flush();
$GLOBALS['wpdb']->lookup_rows = array();
$GLOBALS['wpdb']->lookup_exists = false;
$GLOBALS['wpdb']->postmeta_rows = array();
for ($i = 1; $i <= 620; $i++) $GLOBALS['wpdb']->postmeta_rows[] = array('meta_id' => $i, 'meta_value' => 'ZX' . str_pad((string) $i, 5, '0', STR_PAD_LEFT));
$fallback1 = $plugin->get_latest_state();
$fallback2 = $plugin->get_latest_state();
wcspb_test('postmeta fallback is paged and resumes by meta_id', !$fallback1['done'] && $fallback1['processed'] === 500 && $fallback2['done'] && $fallback2['processed'] === 620 && $fallback2['items'][0]['next'] === 'ZX00621');

WC_SKU_Prefix_Bar::flush();
$GLOBALS['wpdb']->lookup_exists = true;
$GLOBALS['wpdb']->lookup_rows = array();
for ($i = 0; $i < 1005; $i++) {
    $letters = '';
    $n = $i + 1;
    while ($n > 0) { $n--; $letters = chr(65 + ($n % 26)) . $letters; $n = (int) floor($n / 26); }
    $GLOBALS['wpdb']->lookup_rows[] = array('product_id' => $i + 1, 'sku' => 'P' . $letters . '01');
}
$capped = $plugin->get_latest_state();
while (!$capped['done']) $capped = $plugin->get_latest_state();
wcspb_test('prefix aggregation has a hard cap and reports truncation', count($capped['items']) === WC_SKU_Prefix_Bar::MAX_PREFIXES && $capped['truncated']);

WC_SKU_Prefix_Bar::flush();
$GLOBALS['wpdb']->lookup_rows = make_sku_rows(1205, 'AB');
$_POST = array('nonce' => 'valid', 'prefix' => 'AB');
$pending_response = catch_ajax(array($plugin, 'ajax_next'));
wcspb_test('ajax_next reports pending while a bounded summary is incomplete rather than using a partial maximum', $pending_response instanceof WCSPB_Test_Response && $pending_response->success && !empty($pending_response->payload['pending']));

wcspb_test('batch SQL includes a fixed LIMIT and stable indexed cursor', count(array_filter($GLOBALS['wpdb']->queries, function ($query) { return strpos($query, 'LIMIT 500') !== false && (strpos($query, 'product_id >') !== false || strpos($query, 'meta_id >') !== false); })) >= 5);

echo "\nResult: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
