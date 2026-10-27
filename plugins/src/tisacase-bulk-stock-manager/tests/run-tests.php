<?php
/** PHP-WASM tests for WooCommerce SKU lookup SQL selection. */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) define('ABSPATH', '/plugin/');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
function absint($value) { return abs((int) $value); }

class TBSM_Core {
    const MAX_SCOPE_PRODUCTS = 200;
    const MAX_MODEL_ROWS = 300;
    const MAX_MODEL_ATTRS = 60;
    const MAX_MODEL_VALUES = 300;
}

class TBSM_Test_WPDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $queries = array();
    public function esc_like($value) { return addcslashes((string) $value, '_%\\'); }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($match) use (&$i, $args) {
            $value = isset($args[$i]) ? $args[$i] : '';
            $i++;
            return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, (string) $sql);
    }
    public function get_var($sql) {
        $sql = (string) $sql;
        $this->queries[] = $sql;
        if (false !== strpos($sql, 'SHOW TABLES LIKE')) return 'wp_wc_product_meta_lookup';
        return 0;
    }
    public function get_row($sql, $output = 'OBJECT') {
        $this->queries[] = (string) $sql;
        return array('products' => 2, 'variations' => 7);
    }
}
$GLOBALS['wpdb'] = new TBSM_Test_WPDB();
require_once '/plugin/includes/class-tbsm-stock.php';

$passed = 0;
$failed = 0;
function tbsm_test($label, $condition) {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  OK   {$label}\n"; }
    else { $failed++; echo "  FAIL {$label}\n"; }
}

$result = TBSM_Stock::scope_summary(array('type' => 'sku', 'sku' => 'CASE-42', 'sku_mode' => 'prefix'));
$query = end($GLOBALS['wpdb']->queries);
tbsm_test('SKU scope aggregate still returns the requested aggregate shape', $result === array('products' => 2, 'variations' => 7));
tbsm_test('SKU prefix scope uses WooCommerce product lookup instead of postmeta', strpos($query, 'wp_wc_product_meta_lookup ssk') !== false && strpos($query, 'ssk.sku LIKE \'CASE-42%\'') !== false && strpos($query, 'postmeta ssk') === false);

TBSM_Stock::scope_summary(array('type' => 'sku', 'sku' => 'CASE', 'sku_mode' => 'contains'));
$query = end($GLOBALS['wpdb']->queries);
tbsm_test('Contains mode preserves full postmeta semantics and its leading wildcard', strpos($query, "ssk.meta_value LIKE '%CASE%'") !== false && strpos($query, 'wc_product_meta_lookup') === false);

echo "Result: {$passed} passed, {$failed} failed\n";
exit(0 === $failed ? 0 : 1);
