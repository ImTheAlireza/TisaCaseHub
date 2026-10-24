<?php
defined( 'ABSPATH' ) || exit;
class TCC_Order_List {
    private static $cache = array();
    public static function init() {
        add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ), 30 );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ), 30 );
        add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render' ), 20, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render' ), 20, 2 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ), 20, 2 );
        add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filters' ), 20, 2 );
        add_action( 'pre_get_posts', array( __CLASS__, 'legacy_query' ) );
        add_filter( 'posts_clauses', array( __CLASS__, 'legacy_clauses' ), 20, 2 );
        add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_args' ) );
        add_filter( 'woocommerce_orders_table_query_clauses', array( __CLASS__, 'hpos_clauses' ), 20, 3 );
    }
    public static function columns( $columns ) {
        if ( isset( $columns['payment_method'] ) ) { $columns['tcc_receipt'] = 'وضعیت رسید'; }
        else { $columns['tcc_payment'] = 'روش پرداخت'; }
        return $columns;
    }
    private static function attempt( $id ) {
        global $wpdb, $wp_list_table, $wp_query;
        if ( ! array_key_exists( $id, self::$cache ) ) {
            $ids = array( $id );
            $items = isset( $wp_list_table->items ) ? $wp_list_table->items : ( isset( $wp_query->posts ) ? $wp_query->posts : array() );
            foreach ( (array) $items as $item ) { if ( $item instanceof WC_Order ) { $ids[] = $item->get_id(); } elseif ( is_object( $item ) && isset( $item->ID ) ) { $ids[] = $item->ID; } }
            $ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
            foreach ( $ids as $key ) { self::$cache[$key] = null; }
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT order_id,status FROM ' . TCC_Repository::table( 'attempts' ) . " WHERE order_id IN ($placeholders)", $ids ), ARRAY_A );
            foreach ( $rows as $row ) { self::$cache[$row['order_id']] = $row; }
        }
        return self::$cache[$id];
    }
    public static function render( $column, $source ) {
        if ( ! in_array( $column, array( 'tcc_payment', 'tcc_receipt' ), true ) ) { return; }
        $order = $source instanceof WC_Order ? $source : wc_get_order( $source ); if ( ! $order ) { return; }
        $ours = TCC_GATEWAY === $order->get_payment_method();
        if ( 'tcc_payment' === $column ) { echo '<strong class="tcc-method">' . esc_html( $ours ? TCC_TITLE : ( $order->get_payment_method_title() ?: '—' ) ) . '</strong>'; }
        if ( ! $ours ) { if ( 'tcc_receipt' === $column ) { echo '—'; } return; }
        $a = self::attempt( $order->get_id() );
        echo '<div class="tcc-list-state">' . ( $a ? TCC_Helpers::display_badge( $order, $a ) : '<span>بدون مسیر پرداخت</span>' ) . '</div>';
        echo '<a href="' . esc_url( $order->get_edit_order_url() . '#tcc-order-payment' ) . '">بررسی پرداخت</a>';
    }
    private static function selected( $key ) { return isset( $_GET[$key] ) && is_string( $_GET[$key] ) ? sanitize_key( wp_unslash( $_GET[$key] ) ) : ''; }
    public static function filters( $type = 'shop_order', $which = 'top' ) {
        if ( 'shop_order' !== $type || 'bottom' === $which ) { return; }
        echo '<label class="screen-reader-text" for="tcc-method-filter">روش کارت‌به‌کارت</label><select id="tcc-method-filter" name="tcc_method"><option value="">همه روش‌های پرداخت</option><option value="' . esc_attr( TCC_GATEWAY ) . '" ' . selected( self::selected( 'tcc_method' ), TCC_GATEWAY, false ) . '>کارت به کارت تیساکیس</option></select>';
        echo '<label class="screen-reader-text" for="tcc-state-filter">وضعیت رسید</label><select id="tcc-state-filter" name="tcc_state"><option value="">همه وضعیت‌های رسید</option>';
        foreach ( TCC_Helpers::states() as $state => $label ) { echo '<option value="' . esc_attr( $state ) . '" ' . selected( self::selected( 'tcc_state' ), $state, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select>';
    }
    public static function legacy_query( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) { return; }
        if ( TCC_GATEWAY === self::selected( 'tcc_method' ) ) { $meta = (array) $query->get( 'meta_query' ); $meta[] = array( 'key' => '_payment_method', 'value' => TCC_GATEWAY ); $query->set( 'meta_query', $meta ); }
        if ( isset( TCC_Helpers::states()[self::selected( 'tcc_state' )] ) ) { $query->set( 'tcc_filter_state', self::selected( 'tcc_state' ) ); }
    }
    public static function legacy_clauses( $clauses, $query ) {
        global $wpdb;
        $state = $query->get( 'tcc_filter_state' );
        if ( is_admin() && $query->is_main_query() && 'shop_order' === $query->get( 'post_type' ) && isset( TCC_Helpers::states()[$state] ) ) { $clauses['where'] .= $wpdb->prepare( " AND {$wpdb->posts}.ID IN (SELECT order_id FROM " . TCC_Repository::table( 'attempts' ) . ' WHERE status=%s)', $state ); }
        return $clauses;
    }
    public static function hpos_args( $args ) {
        if ( TCC_GATEWAY === self::selected( 'tcc_method' ) ) { $args['payment_method'] = TCC_GATEWAY; }
        if ( isset( TCC_Helpers::states()[self::selected( 'tcc_state' )] ) ) { $args['tcc_filter_state'] = self::selected( 'tcc_state' ); }
        return $args;
    }
    public static function hpos_clauses( $clauses, $query, $args ) {
        global $wpdb;
        if ( ! empty( $args['tcc_filter_state'] ) && isset( TCC_Helpers::states()[$args['tcc_filter_state']] ) ) { $clauses['where'] .= $wpdb->prepare( " AND {$wpdb->prefix}wc_orders.id IN (SELECT order_id FROM " . TCC_Repository::table( 'attempts' ) . ' WHERE status=%s)', $args['tcc_filter_state'] ); }
        return $clauses;
    }
}
