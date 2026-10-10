<?php
/** Successful-use ledger. Order IDs are immutable; resetting never deletes history. */
defined( 'ABSPATH' ) || exit;

final class TCP_Coupon_Phones {
	const META = '_tcp_phone_policy';
	const ACTION = 'tcp_coupon_phones';

	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'install' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'admin_action' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'admin_action' ) );
		add_filter( 'woocommerce_coupon_get_usage_limit_per_user', array( __CLASS__, 'native_limit' ), 99, 2 );
		add_filter( 'woocommerce_coupon_get_usage_limit', array( __CLASS__, 'native_limit' ), 99, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_coupon' ), 30, 3 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout' ), 30, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_store_order' ), 30, 1 );
		add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'validate_pay' ), 30, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'record_order' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'record_order' ), 30 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'record_order' ), 30 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tcp_coupon_uses';
	}

	public static function install() {
		if ( '1' === get_option( 'tcp_coupon_uses_schema' ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			coupon_id bigint(20) unsigned NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			phone varchar(11) NOT NULL,
			used_at bigint(20) unsigned NOT NULL,
			released tinyint(1) NOT NULL DEFAULT 0,
			reset_by bigint(20) unsigned NOT NULL DEFAULT 0,
			reset_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY coupon_order (coupon_id,order_id),
			KEY phone_usage (coupon_id,phone,released),
			KEY coupon_usage (coupon_id,released)
		) " . $wpdb->get_charset_collate() . ';' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) {
			update_option( 'tcp_coupon_uses_schema', '1', false );
		}
	}

	public static function phone( $raw ) {
		if ( ! is_scalar( $raw ) ) { return ''; }
		$raw = str_replace( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ), (string) $raw );
		$raw = preg_replace( '/[\s()\-+]/u', '', $raw );
		if ( preg_match( '/^00989\d{9}$/D', $raw ) ) { $raw = '0' . substr( $raw, 4 ); }
		elseif ( preg_match( '/^989\d{9}$/D', $raw ) ) { $raw = '0' . substr( $raw, 2 ); }
		elseif ( preg_match( '/^9\d{9}$/D', $raw ) ) { $raw = '0' . $raw; }
		return preg_match( '/^09\d{9}$/D', $raw ) ? $raw : '';
	}

	public static function policy( $coupon ) {
		$p = $coupon->get_meta( self::META );
		return wp_parse_args( is_array( $p ) ? $p : array(), array( 'enabled' => false, 'selected' => true, 'phones' => array(), 'limit' => 1, 'total' => 0, 'login' => false, 'aliases' => array() ) );
	}

	/** Validate all input before modifying the coupon. Never silently discard invalid phones. */
	public static function save_policy( $c, $input ) {
		if ( ! isset( $input['phone_policy_present'] ) ) { return; }
		$old = self::policy( $c );
		$p = array(
			'enabled' => ! empty( $input['phone_enabled'] ),
			'selected' => ! empty( $input['phone_selected'] ),
			'login' => ! empty( $input['phone_login'] ),
			'limit' => max( 1, min( 10000, absint( $input['phone_limit'] ?? 1 ) ) ),
			'total' => absint( $input['phone_total'] ?? 0 ),
			'phones' => array(), 'aliases' => array(),
		);
		foreach ( preg_split( '/[\r\n,;،]+/u', (string) ( $input['phone_list'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			if ( '' === trim( $line ) ) { continue; }
			$phone = self::phone( trim( $line ) );
			if ( ! $phone ) { wp_die( 'شماره نامعتبر است: ' . esc_html( $line ) . ' — هر شماره را در یک خط وارد کنید.' ); }
			$p['phones'][ $phone ] = $phone;
		}
		$p['phones'] = array_values( $p['phones'] );
		foreach ( preg_split( '/[\r\n,;]+/', (string) ( $input['phone_aliases'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ) as $code ) {
			$p['aliases'][] = wc_format_coupon_code( sanitize_text_field( trim( $code ) ) );
		}
		if ( $p['enabled'] ) {
			// Both native limits reserve pending orders. The ledger replaces BOTH limits.
			$c->set_usage_limit( 0 );
			$c->set_usage_limit_per_user( 0 );
			if ( ! $old['enabled'] || $old['aliases'] !== $p['aliases'] ) {
				$c->update_meta_data( '_tcp_phone_sync', array( 'page' => 1, 'until' => time(), 'done' => false ) );
			}
		}
		$c->update_meta_data( self::META, $p );
	}

	public static function native_limit( $value, $coupon ) {
		return self::policy( $coupon )['enabled'] ? 0 : $value;
	}

	public static function count( $id, $phone = '', $exclude_order = 0 ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE coupon_id=%d AND released=0 AND order_id<>%d', $id, $exclude_order );
		if ( '' !== $phone ) { $sql .= $wpdb->prepare( ' AND phone=%s', $phone ); }
		$result = $wpdb->get_var( $sql );
		if ( null === $result || $wpdb->last_error ) { throw new Exception( 'بررسی سابقه تخفیف ممکن نیست؛ لطفاً دوباره تلاش کنید.' ); }
		return (int) $result;
	}

	public static function error( $c, $raw_phone, $order_id = 0, $user_id = null ) {
		$p = self::policy( $c );
		if ( ! $p['enabled'] ) { return ''; }
		if ( function_exists( 'tisacase158_restrict_coupon_by_phone' ) && 'tisacase158' === strtolower( $c->get_code() ) ) {
			return 'مدیریت این کد در حال انتقال است؛ مدیر باید اسنیپت قدیمی ۱۵۸ را غیرفعال کند.';
		}
		$sync = $c->get_meta( '_tcp_phone_sync' );
		if ( empty( $sync['done'] ) ) { return 'سوابق این کد در حال آماده‌سازی است؛ لطفاً بعداً تلاش کنید.'; }
		$phone = self::phone( $raw_phone );
		if ( ! $phone ) { return 'برای این تخفیف، شماره موبایل معتبر صورتحساب را وارد کنید.'; }
		if ( $p['login'] && ! ( null === $user_id ? get_current_user_id() : $user_id ) ) { return 'برای استفاده از این تخفیف وارد حساب کاربری شوید.'; }
		if ( $p['selected'] && ! in_array( $phone, $p['phones'], true ) ) { return 'این کد برای شماره موبایل صورتحساب شما فعال نیست.'; }
		if ( self::count( $c->get_id(), $phone, $order_id ) >= $p['limit'] ) { return 'سهمیه خرید موفق این شماره برای این کد تخفیف مصرف شده است.'; }
		if ( $p['total'] && self::count( $c->get_id(), '', $order_id ) >= $p['total'] ) { return 'ظرفیت خرید موفق این کد تخفیف تکمیل شده است.'; }
		return '';
	}

	private static function current_phone() {
		// Never store a separate sticky session phone. An explicitly empty field remains empty.
		if ( isset( $_POST['billing_phone'] ) ) { return wp_unslash( $_POST['billing_phone'] ); } // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $data ); // phpcs:ignore WordPress.Security.NonceVerification
			return $data['billing_phone'] ?? '';
		}
		return WC()->customer ? WC()->customer->get_billing_phone() : '';
	}

	public static function validate_coupon( $valid, $coupon, $discounts ) {
		if ( ! $valid || ! self::policy( $coupon )['enabled'] ) { return $valid; }
		// Order recalculation (admin, refunds, Store API) must use the order, not the admin's phone.
		$object = method_exists( $discounts, 'get_object' ) ? $discounts->get_object() : null;
		if ( $object instanceof WC_Order ) {
			$message = self::error( $coupon, $object->get_billing_phone(), $object->get_id(), $object->get_customer_id() );
		} else {
			$message = self::error( $coupon, self::current_phone() );
		}
		if ( $message ) { throw new Exception( $message ); }
		return $valid;
	}

	public static function validate_checkout( $data, $errors ) {
		if ( ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_applied_coupons() as $code ) {
			try { $message = self::error( new WC_Coupon( $code ), $data['billing_phone'] ?? '' ); }
			catch ( Exception $e ) { $message = $e->getMessage(); }
			if ( $message ) { $errors->add( 'tcp_phone_' . sanitize_key( $code ), $message ); }
		}
	}

	public static function validate_store_order( $order ) {
		try { self::validate_order( $order ); }
		catch ( Exception $e ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'tcp_coupon_phone', $e->getMessage(), 400 );
		}
	}

	public static function validate_pay( $order ) {
		try { self::validate_order( $order ); }
		catch ( Exception $e ) { wc_add_notice( $e->getMessage(), 'error' ); }
	}

	public static function validate_order( $order ) {
		foreach ( $order->get_coupon_codes() as $code ) {
			$message = self::error( new WC_Coupon( $code ), $order->get_billing_phone(), $order->get_id(), $order->get_customer_id() );
			if ( $message ) { throw new Exception( $message ); }
		}
	}

	public static function successful( $order ) {
		// Statuses that never mean a completed payment. A stray date_paid here must NEVER spend a phone quota.
		$unpaid = array( 'pending', 'failed', 'cancelled', 'on-hold', 'draft', 'checkout-draft' );
		if ( $order->has_status( array( 'processing', 'completed' ) ) ) { return true; }
		// Refunded historical orders are imported only with evidence of earlier payment.
		if ( $order->has_status( array( 'refunded' ) ) ) { return (bool) $order->get_date_paid(); }
		// Store-specific paid statuses (e.g. shipped / warehouse) count when payment was recorded.
		return ! $order->has_status( $unpaid ) && (bool) $order->get_date_paid();
	}

	public static function record( $c, $order ) {
		if ( ! self::successful( $order ) ) { return; }
		$phone = self::phone( $order->get_meta( '_tisacase158_coupon_phone' ) ?: $order->get_billing_phone() );
		if ( ! $phone ) { return; }
		$date = $order->get_date_paid() ?: $order->get_date_created();
		global $wpdb;
		// No UPDATE on duplicates: status changes, retries and re-sync cannot undo a reset.
		$result = $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . self::table() . ' (coupon_id,order_id,phone,used_at) VALUES (%d,%d,%s,%d)',
			$c->get_id(), $order->get_id(), $phone, $date ? $date->getTimestamp() : time()
		) );
		if ( false === $result ) { throw new Exception( 'ثبت سابقه تخفیف ناموفق بود.' ); }
	}

	public static function record_order( $id ) {
		$order = wc_get_order( $id );
		if ( ! $order ) { return; }
		foreach ( $order->get_coupon_codes() as $code ) {
			$c = new WC_Coupon( $code );
			if ( self::policy( $c )['enabled'] ) {
				try { self::record( $c, $order ); }
				catch ( Exception $e ) {
					// Fail closed on future purchases, without interrupting a gateway's successful callback.
					$c->update_meta_data( '_tcp_phone_sync', array( 'page' => 1, 'until' => time(), 'done' => false ) );
					$c->save();
					wc_get_logger()->error( $e->getMessage(), array( 'source' => 'tcp-coupon-phones', 'order_id' => $id ) );
				}
			}
		}
	}

	/**
	 * Next batch (max 100) of order IDs above $after that carry this coupon or one of its aliases.
	 * Reads the order-items table directly, so only coupon orders are loaded, not the whole store.
	 * HPOS and legacy stores both keep coupon lines in {prefix}woocommerce_order_items.
	 */
	public static function candidate_ids( $c, $after = 0 ) {
		global $wpdb;
		$codes = array_values( array_unique( array_map( 'strtolower', array_merge( array( $c->get_code() ), self::policy( $c )['aliases'] ) ) ) );
		$marks = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$sql   = $wpdb->prepare(
			'SELECT DISTINCT order_id FROM ' . $wpdb->prefix . "woocommerce_order_items WHERE order_item_type='coupon' AND order_id>%d AND LOWER(order_item_name) IN ($marks) ORDER BY order_id ASC LIMIT 100",
			...array_merge( array( absint( $after ) ), $codes )
		);
		$ids = $wpdb->get_col( $sql );
		if ( null === $ids || $wpdb->last_error ) { throw new Exception( 'خواندن فهرست سفارش‌های این کد ممکن نیست؛ دوباره تلاش کنید.' ); }
		return array_map( 'intval', $ids );
	}

	/** Load exact orders by ID. Do not use wc_get_orders( 'include' ): it ignores IDs on some stores. */
	public static function orders_for_ids( $ids, $until = 0 ) {
		$out = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( (int) $id );
			if ( ! $order || 'shop_order' !== $order->get_type() ) { continue; }
			if ( $until ) {
				$created = $order->get_date_created();
				if ( $created && $created->getTimestamp() > $until ) { continue; }
			}
			$out[] = $order;
		}
		return $out;
	}

	/**
	 * Live diagnostic for the coupon screen. Read-only. Shows where the chain breaks:
	 * orders holding the code in the DB, their statuses, how many pass the paid rule, and recorded uses.
	 */
	public static function diagnose( $c ) {
		global $wpdb;
		$codes = array_values( array_unique( array_map( 'strtolower', array_merge( array( $c->get_code() ), self::policy( $c )['aliases'] ) ) ) );
		$marks = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$ids   = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT order_id FROM ' . $wpdb->prefix . "woocommerce_order_items WHERE order_item_type='coupon' AND LOWER(order_item_name) IN ($marks) ORDER BY order_id ASC",
			...$codes
		) );
		$ids   = array_map( 'intval', (array) $ids );
		$out   = array( 'in_db' => count( $ids ), 'loaded' => 0, 'paid' => 0, 'phoned' => 0, 'uses' => 0, 'statuses' => array(), 'error' => '' );
		foreach ( self::orders_for_ids( $ids ) as $order ) {
			$out['loaded']++;
			$st = $order->get_status();
			$out['statuses'][ $st ] = ( $out['statuses'][ $st ] ?? 0 ) + 1;
			if ( self::successful( $order ) ) {
				$out['paid']++;
				if ( self::phone( $order->get_meta( '_tisacase158_coupon_phone' ) ?: $order->get_billing_phone() ) ) { $out['phoned']++; }
			}
		}
		$out['uses'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE coupon_id=%d AND released=0', $c->get_id() ) );
		if ( $wpdb->last_error ) { $out['error'] = $wpdb->last_error; }
		return $out;
	}

	/** One bounded batch per authenticated request. Keyset cursor ('after') survives retries and stops. */
	public static function sync( $c ) {
		$s = $c->get_meta( '_tcp_phone_sync' );
		if ( ! is_array( $s ) || ! empty( $s['done'] ) ) { $s = array( 'page' => 1, 'after' => 0, 'until' => time(), 'done' => false, 'found' => 0, 'paid' => 0 ); }
		$ids = self::candidate_ids( $c, (int) ( $s['after'] ?? 0 ) );
		$orders = self::orders_for_ids( $ids, absint( $s['until'] ) );
		$codes = array_merge( array( strtolower( $c->get_code() ) ), self::policy( $c )['aliases'] );
		$paid  = 0;
		foreach ( $orders as $order ) {
			if ( array_intersect( $codes, array_map( 'strtolower', $order->get_coupon_codes() ) ) ) {
				if ( self::successful( $order ) && self::phone( $order->get_meta( '_tisacase158_coupon_phone' ) ?: $order->get_billing_phone() ) ) { $paid++; }
				self::record( $c, $order );
			}
		}
		if ( $ids ) { $s['after'] = max( $ids ); }
		$s['found'] = (int) ( $s['found'] ?? 0 ) + count( $ids );
		$s['paid']  = (int) ( $s['paid'] ?? 0 ) + $paid;
		$s['done'] = count( $ids ) < 100;
		$s['page'] = (int) $s['page'] + 1;
		$c->update_meta_data( '_tcp_phone_sync', $s );
		$c->save();
	}

	public static function reset( $id, $phone = '' ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'UPDATE ' . self::table() . ' SET released=1, reset_by=%d, reset_at=%d WHERE coupon_id=%d AND released=0', get_current_user_id(), time(), $id );
		if ( '' !== $phone ) { $sql .= $wpdb->prepare( ' AND phone=%s', $phone ); }
		if ( false === $wpdb->query( $sql ) ) { throw new Exception( 'ریست انجام نشد.' ); }
	}

	public static function history( $id, $page = 1 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE coupon_id=%d ORDER BY id DESC LIMIT 50 OFFSET %d', $id, ( max( 1, $page ) - 1 ) * 50 ) );
	}

	public static function admin_action() {
		if ( ! TCP_Settings::can() ) { wp_die( 'دسترسی غیرمجاز.' ); }
		check_admin_referer( self::ACTION );
		$id = absint( $_POST['coupon_id'] ?? 0 );
		if ( 'shop_coupon' !== get_post_type( $id ) ) { wp_die( 'کد تخفیف معتبر نیست.' ); }
		$c = new WC_Coupon( $id );
		$op = sanitize_key( $_POST['operation'] ?? '' );
		if ( wp_doing_ajax() && 'sync' !== $op ) { wp_send_json_error( array( 'message' => 'عملیات نامعتبر.' ), 400 ); }
		try {
			if ( 'sync' === $op ) { self::sync( $c ); }
			elseif ( in_array( $op, array( 'reset_one', 'reset_all' ), true ) ) {
				$s = $c->get_meta( '_tcp_phone_sync' );
				if ( empty( $s['done'] ) ) { wp_die( 'ابتدا همگام‌سازی سوابق را کامل کنید.' ); }
				$phone = self::phone( wp_unslash( $_POST['phone'] ?? '' ) );
				if ( 'reset_one' === $op && ! $phone ) { wp_die( 'شماره معتبر وارد کنید.' ); }
				self::reset( $id, 'reset_all' === $op ? '' : $phone );
			} else { wp_die( 'عملیات نامعتبر.' ); }
		} catch ( Exception $e ) {
			if ( wp_doing_ajax() ) { wp_send_json_error( array( 'message' => $e->getMessage() ), 500 ); }
			wp_die( esc_html( $e->getMessage() ) );
		}
		if ( wp_doing_ajax() ) { wp_send_json_success( $c->get_meta( '_tcp_phone_sync' ) ); }
		wp_safe_redirect( TCP_Admin::url( 'coupons', array( 'edit' => $id, 'phone_saved' => 1 ) ) );
		exit;
	}
}
