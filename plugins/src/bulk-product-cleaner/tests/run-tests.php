<?php
/** Small PHP-WASM tests for keyset scans and bounded gallery reads. */
error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/plugin/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

function apply_filters($tag, $value) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }

class BDC_Test_WPDB {
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $terms = 'wp_terms';
    public $queries = array();
    public $gallery_total = 20001;

    public function esc_like($value) { return addcslashes((string) $value, '_%\\'); }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($match) use (&$i, $args) {
            $value = isset($args[$i]) ? $args[$i] : '';
            $i++;
            return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, (string) $sql);
    }
    public function get_col($sql) {
        $this->queries[] = (string) $sql;
        return array(81, 82);
    }
    public function get_var($sql) {
        $this->queries[] = (string) $sql;
        return 3; // fixture: three distinct featured images.
    }
    public function get_results($sql, $output = 'OBJECT') {
        $sql = (string) $sql;
        $this->queries[] = $sql;
        if (false === strpos($sql, "meta_key = '_product_image_gallery'")) {
            return array();
        }
        preg_match('/meta_id > (\d+)/', $sql, $cursor_match);
        $after = isset($cursor_match[1]) ? (int) $cursor_match[1] : 0;
        preg_match('/LIMIT (\d+)/', $sql, $limit_match);
        $limit = isset($limit_match[1]) ? (int) $limit_match[1] : 2000;
        $start = $after + 1;
        $end = min($this->gallery_total, $start + $limit - 1);
        $rows = array();
        for ($id = $start; $id <= $end; $id++) {
            $rows[] = array('meta_id' => $id, 'meta_value' => '9001,9002');
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new BDC_Test_WPDB();
require_once '/plugin/includes/class-bdc-query.php';

$passed = 0;
$failed = 0;
function bdc_test($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  OK   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}\n";
    }
}

$filters = BDC_Query::sanitize_filters(array('status' => array('draft')));
$ids = BDC_Query::get_ids_after($filters, 100, 77);
$last_query = end($GLOBALS['wpdb']->queries);
bdc_test('ID scan uses exclusive keyset cursor and ascending ID order', $ids === array(81, 82) && strpos($last_query, 'p.ID > 77') !== false && strpos($last_query, 'ORDER BY p.ID ASC') !== false);
bdc_test('ID scan does not use OFFSET', strpos($last_query, 'OFFSET') === false);

$GLOBALS['wpdb']->queries = array();
$count = BDC_Query::count_images($filters);
$gallery_queries = array_values(array_filter($GLOBALS['wpdb']->queries, function ($query) {
    return false !== strpos($query, "meta_key = '_product_image_gallery'");
}));
$has_offset = false;
foreach ($gallery_queries as $query) {
    if (false !== strpos($query, 'OFFSET') || false !== strpos($query, 'LIMIT 20000')) {
        $has_offset = true;
    }
}
bdc_test('Gallery image count preserves distinct IDs under the 20,000-row safety ceiling', $count === 5 && count($gallery_queries) === 10);
bdc_test('Gallery pages use meta_id keyset while retaining the bounded 20,000-row ceiling', !$has_offset && strpos($gallery_queries[0], 'meta_id > 0') !== false);

echo "Result: {$passed} passed, {$failed} failed\n";
exit(0 === $failed ? 0 : 1);
