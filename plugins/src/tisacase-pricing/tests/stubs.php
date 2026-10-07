<?php
/**
 * استاب‌های وردپرس/ووکامرس برای تست بدون وردپرس.
 *
 * @package TisaCase_Pricing
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/* ---------- حالت قابل کنترل تست ---------- */
$GLOBALS['tcp_options']       = array();
$GLOBALS['tcp_can']           = true;
$GLOBALS['tcp_user_id']       = 7;
$GLOBALS['tcp_nonce_valid']   = true;
$GLOBALS['tcp_currency']      = 'IRT';
$GLOBALS['tcp_terms']         = array();
$GLOBALS['tcp_term_children'] = array();
$GLOBALS['tcp_post_terms']    = array();
$GLOBALS['tcp_products']      = array();
$GLOBALS['tcp_filters']       = array();
$GLOBALS['tcp_notices']       = array();
$GLOBALS['tcp_wc']            = null;

/* ---------- توابع وردپرس ---------- */
function __( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_sql( $s ) { return addslashes( (string) $s ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function number_format_i18n( $v, $dec = 0 ) { return number_format( (float) $v, $dec ); }
function current_time( $type ) { return 'Y-m-d' === $type ? '2026-09-17' : '2026-09-17 12:00:00'; }
function add_action( ...$a ) {}
function do_action( ...$a ) {}
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	if ( ! isset( $GLOBALS['tcp_filters'][ $tag ] ) ) {
		$GLOBALS['tcp_filters'][ $tag ] = array();
	}
	if ( ! isset( $GLOBALS['tcp_filters'][ $tag ][ $priority ] ) ) {
		$GLOBALS['tcp_filters'][ $tag ][ $priority ] = array();
	}
	$GLOBALS['tcp_filters'][ $tag ][ $priority ][] = array( $callback, (int) $accepted_args );
	return true;
}
function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['tcp_filters'][ $tag ] ) ) {
		return $value;
	}
	ksort( $GLOBALS['tcp_filters'][ $tag ], SORT_NUMERIC );
	foreach ( $GLOBALS['tcp_filters'][ $tag ] as $callbacks ) {
		foreach ( $callbacks as $registered ) {
			$call_args = array_merge( array( $value ), $args );
			$call_args = array_slice( $call_args, 0, $registered[1] );
			$value     = call_user_func_array( $registered[0], $call_args );
		}
	}
	return $value;
}
function has_filter( $tag, $callback = false ) {
	if ( empty( $GLOBALS['tcp_filters'][ $tag ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['tcp_filters'][ $tag ] as $priority => $callbacks ) {
		foreach ( $callbacks as $registered ) {
			if ( false === $callback || $registered[0] === $callback ) {
				return (int) $priority;
			}
		}
	}
	return false;
}
function plugin_basename( $f ) { return basename( $f ); }
function is_user_logged_in() { return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['tcp_can'] ); }
function user_can( $user, $cap = null ) { return false; }
function get_current_user_id() { return (int) $GLOBALS['tcp_user_id']; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function did_action( $tag ) { return 0; }
function get_post_status( $id = null ) { return 'publish'; }
function wp_get_current_user() { return new WP_User(); }
class WP_User { public $roles = array(); }
function wp_verify_nonce( $n, $a = null ) { return ! empty( $GLOBALS['tcp_nonce_valid'] ); }
function wp_create_nonce( $a = null ) { return 'nonce-stub'; }
function wp_salt( $s = 'auth' ) { return 'salt-stub-' . $s; }

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['tcp_options'] ) ? $GLOBALS['tcp_options'][ $k ] : $default;
}
function update_option( $k, $v, $autoload = null ) { $GLOBALS['tcp_options'][ $k ] = $v; return true; }
function add_option( $k, $v = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $k, $GLOBALS['tcp_options'] ) ) {
		return false;
	}
	$GLOBALS['tcp_options'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['tcp_options'][ $k ] ); return true; }

function get_term( $id, $tax = '' ) {
	$id = (int) $id;
	return isset( $GLOBALS['tcp_terms'][ $id ] ) ? $GLOBALS['tcp_terms'][ $id ] : null;
}
function get_term_children( $id, $tax = '' ) {
	$id = (int) $id;
	return isset( $GLOBALS['tcp_term_children'][ $id ] ) ? $GLOBALS['tcp_term_children'][ $id ] : array();
}
function wp_get_post_terms( $pid, $tax = '', $args = array() ) {
	$pid = (int) $pid;
	return isset( $GLOBALS['tcp_post_terms'][ $pid ] ) ? $GLOBALS['tcp_post_terms'][ $pid ] : array();
}

/** حداقل wpdb برای تست‌های SQLمحور بدون نصب وردپرس. */
class TCP_Test_WPDB {
	public $prefix = 'wp_';
	public $posts = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $terms = 'wp_terms';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy = 'wp_term_taxonomy';
	public $last_error = '';

	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%d/', (string) absint( $arg ), $query, 1 );
		}
		return $query;
	}

	/** همان رفتار wpdb::esc_like — خنثی‌سازی نویسه‌های ویژهٔ LIKE. */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_col( $query ) {
		if ( false !== strpos( $query, 'SELECT ID FROM' ) && preg_match( '/post_parent = ([0-9]+)/', $query, $m ) ) {
			$parent = absint( $m[1] );
			$product = isset( $GLOBALS['tcp_products'][ $parent ] ) ? $GLOBALS['tcp_products'][ $parent ] : null;
			return $product && method_exists( $product, 'get_children' ) ? array_map( 'absint', $product->get_children() ) : array();
		}
		if ( false !== strpos( $query, 'SELECT object_id FROM' ) ) {
			return array();
		}
		return array();
	}
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

/* ---------- توابع ووکامرس ---------- */
function wc_format_decimal( $v ) { return (string) (float) $v; }
function wc_get_price_decimals() { return 0; }
function wc_add_notice( $message, $type = 'success' ) { $GLOBALS['tcp_notices'][] = array( 'message' => $message, 'type' => $type ); }
function get_woocommerce_currency() { return $GLOBALS['tcp_currency']; }
function WC() { return isset( $GLOBALS['tcp_wc'] ) && is_object( $GLOBALS['tcp_wc'] ) ? $GLOBALS['tcp_wc'] : new stdClass(); }
function wc_get_product( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['tcp_products'][ $id ] ) ? $GLOBALS['tcp_products'][ $id ] : null;
}

class WooCommerce {}

class WC_Product {
	protected $id;
	protected $parent_id;
	protected $type;
	public $regular = '';
	public $sale = '';
	public $price = '';
	public $meta = array();
	public $saved = 0;
	public $children = array();
	public $name = 'قاب گوشی';
	public $sku = '';
	public function __construct( $id, $type = 'simple', $parent_id = 0 ) {
		$this->id = (int) $id; $this->type = $type; $this->parent_id = (int) $parent_id;
	}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent_id; }
	public function is_type( $t ) { return $this->type === $t; }
	public function is_on_sale() { return '' !== (string) $this->sale && is_numeric( $this->sale ) && (float) $this->sale > 0; }
	public function get_regular_price( $context = 'view' ) {
		$price = $this->regular;
		if ( 'view' === $context && function_exists( 'apply_filters' ) ) {
			$hook = $this->is_type( 'variation' ) ? 'woocommerce_product_variation_get_regular_price' : 'woocommerce_product_get_regular_price';
			$price = apply_filters( $hook, $price, $this );
		}
		return $price;
	}
	public function get_sale_price( $context = 'view' ) {
		$price = $this->sale;
		if ( 'view' === $context && function_exists( 'apply_filters' ) ) {
			$hook = $this->is_type( 'variation' ) ? 'woocommerce_product_variation_get_sale_price' : 'woocommerce_product_get_sale_price';
			$price = apply_filters( $hook, $price, $this );
		}
		return $price;
	}
	public function get_price( $context = 'view' ) {
		$price = '' !== (string) $this->price ? $this->price : ( '' !== (string) $this->sale ? $this->sale : $this->regular );
		if ( 'view' === $context && function_exists( 'apply_filters' ) ) {
			$hook = $this->is_type( 'variation' ) ? 'woocommerce_product_variation_get_price' : 'woocommerce_product_get_price';
			$price = apply_filters( $hook, $price, $this );
		}
		return $price;
	}
	public function set_price( $v ) { $this->price = $v; }
	public function set_regular_price( $v ) { $this->regular = $v; }
	public function set_sale_price( $v ) { $this->sale = $v; }
	public function set_date_on_sale_from( $v ) {}
	public function set_date_on_sale_to( $v ) {}
	public function save() { $this->saved++; return $this->id; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_children() { return $this->children; }
}

class TCP_Test_WC_Cart {
	public $items = array();
	public function __construct( $items = array() ) { $this->items = $items; }
	public function get_cart() { return $this->items; }
}
