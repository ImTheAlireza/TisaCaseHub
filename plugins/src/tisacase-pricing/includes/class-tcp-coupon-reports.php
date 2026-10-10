<?php
/** Per-coupon order index; customer details are read live, never duplicated. */
defined( 'ABSPATH' ) || exit;
final class TCP_Coupon_Reports {
	const ACTION = 'tcp_coupon_report';
	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'install' ) );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'index_order' ), 40 );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}
	public static function table() { global $wpdb; return $wpdb->prefix . 'tcp_coupon_orders'; }
	public static function install() {
		if ( '1' === get_option( 'tcp_coupon_report_schema' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE $table (
			coupon_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (coupon_id,order_id)
		) " . $wpdb->get_charset_collate() . ';' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { update_option( 'tcp_coupon_report_schema', '1', false ); }
	}
	public static function link( $coupon_id, $order_id ) {
		global $wpdb;
		if ( false === $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::table() . ' (coupon_id,order_id) VALUES (%d,%d)', $coupon_id, $order_id ) ) ) {
			throw new Exception( 'ثبت نمایه گزارش ناموفق بود؛ همگام‌سازی را دوباره اجرا کنید.' );
		}
	}
	public static function index_order( $order ) {
		if ( ! $order instanceof WC_Order || 'shop_order' !== $order->get_type() ) { return; }
		foreach ( $order->get_coupon_codes() as $code ) {
			$id = wc_get_coupon_id_by_code( $code );
			if ( $id ) {
				try { self::link( $id, $order->get_id() ); }
				catch ( Exception $e ) { wc_get_logger()->error( $e->getMessage(), array( 'source' => 'tcp-coupon-report', 'order_id' => $order->get_id() ) ); }
			}
		}
	}
	/** Index all statuses, not just paid orders. No analytics-table/HPOS assumptions. */
	public static function sync( $coupon, $restart = false ) {
		$s = $coupon->get_meta( '_tcp_report_sync' );
		if ( $restart || ! is_array( $s ) ) { $s = array( 'page' => 1, 'until' => time(), 'done' => false, 'checked' => 0 ); }
		if ( ! empty( $s['done'] ) ) { return $s; }
		$ids = TCP_Coupon_Phones::candidate_ids( $coupon, (int) ( $s['after'] ?? 0 ) );
		$orders = TCP_Coupon_Phones::orders_for_ids( $ids, absint( $s['until'] ) );
		$codes = self::codes( $coupon );
		foreach ( $orders as $order ) {
			if ( array_intersect( $codes, array_map( 'strtolower', $order->get_coupon_codes() ) ) ) { self::link( $coupon->get_id(), $order->get_id() ); }
		}
		$s['checked'] += count( $ids );
		if ( $ids ) { $s['after'] = max( $ids ); }
		$s['done'] = count( $ids ) < 100;
		$s['page']++;
		$coupon->update_meta_data( '_tcp_report_sync', $s );
		$coupon->save();
		return $s;
	}
	public static function codes( $coupon ) {
		return array_map( 'strtolower', array_merge( array( $coupon->get_code() ), TCP_Coupon_Phones::policy( $coupon )['aliases'] ) );
	}
	public static function rows( $coupon_id, $page, $order_id = 0 ) {
		global $wpdb;
		// UNION includes pre-upgrade successful-use records even before report backfill finishes.
		$sql = $wpdb->prepare( 'SELECT order_id FROM ' . self::table() . ' WHERE coupon_id=%d UNION SELECT order_id FROM ' . TCP_Coupon_Phones::table() . ' WHERE coupon_id=%d', $coupon_id, $coupon_id );
		$where = $order_id ? $wpdb->prepare( ' WHERE order_id=%d', $order_id ) : '';
		$total = $wpdb->get_var( 'SELECT COUNT(*) FROM (' . $sql . ') AS report_orders' . $where );
		if ( null === $total || $wpdb->last_error ) { throw new Exception( 'خواندن گزارش ممکن نیست؛ دوباره تلاش کنید.' ); }
		$pages = max( 1, (int) ceil( $total / 25 ) );
		$page = min( max( 1, $page ), $pages );
		$ids = $wpdb->get_col( 'SELECT order_id FROM (' . $sql . ') AS report_orders' . $where . $wpdb->prepare( ' ORDER BY order_id DESC LIMIT 25 OFFSET %d', ( $page - 1 ) * 25 ) );
		if ( $wpdb->last_error ) { throw new Exception( 'خواندن گزارش ممکن نیست.' ); }
		return array( 'ids' => $ids, 'total' => (int) $total, 'pages' => $pages, 'page' => $page );
	}
	public static function ledger( $coupon_id, $ids ) {
		if ( ! $ids ) { return array(); }
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . TCP_Coupon_Phones::table() . ' WHERE coupon_id=%d AND order_id IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')', $coupon_id ) );
		if ( $wpdb->last_error ) { throw new Exception( 'خواندن سابقه سهمیه ممکن نیست.' ); }
		$out = array();
		foreach ( $rows as $row ) { $out[ $row->order_id ] = $row; }
		return $out;
	}
	public static function render( $coupon, $page = 1, $order_id = 0 ) {
		$data = self::rows( $coupon->get_id(), $page, $order_id );
		$ledger = self::ledger( $coupon->get_id(), $data['ids'] );
		ob_start();
		require TCP_DIR . 'views/coupon-report-table.php';
		return ob_get_clean();
	}
	public static function ajax() {
		if ( ! TCP_Settings::can() ) { wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز.' ), 403 ); }
		check_ajax_referer( self::ACTION, 'nonce' );
		$id = absint( $_POST['coupon_id'] ?? 0 );
		if ( 'shop_coupon' !== get_post_type( $id ) ) { wp_send_json_error( array( 'message' => 'کوپن معتبر نیست.' ), 404 ); }
		$c = new WC_Coupon( $id );
		try {
			if ( 'sync' === ( $_POST['operation'] ?? '' ) ) {
				wp_send_json_success( self::sync( $c, ! empty( $_POST['restart'] ) ) );
			}
			wp_send_json_success( array( 'html' => self::render( $c, absint( $_POST['report_page'] ?? 1 ), absint( $_POST['order_id'] ?? 0 ) ) ) );
		} catch ( Exception $e ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 500 ); }
	}
}
