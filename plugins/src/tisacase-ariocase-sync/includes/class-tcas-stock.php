<?php
/**
 * اعمال موجودی با CRUD ووکامرس؛ خطای API در این لایه به صفر تبدیل نمی‌شود.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Stock' ) ) {

	final class TCAS_Stock {

		public static function valid( $stock ) {
			return is_array( $stock ) && ( isset( $stock['quantity'] ) && null !== $stock['quantity'] || ! empty( $stock['status'] ) );
		}

		private static function targets( $stock ) {
			$quantity = isset( $stock['quantity'] ) && null !== $stock['quantity'] ? (float) $stock['quantity'] : null;
			$status   = isset( $stock['status'] ) ? (string) $stock['status'] : '';
			if ( null !== $quantity ) {
				if ( ! is_finite( $quantity ) || $quantity < 0 || $quantity > 1000000000 ) {
					return new WP_Error( 'tcas_invalid_stock', 'مقدار موجودی دریافتی در بازهٔ مجاز نیست.' );
				}
				if ( $quantity <= 0 ) {
					$status = 'outofstock';
				} elseif ( ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
					$status = 'instock';
				}
				return array( 'manage' => true, 'quantity' => $quantity, 'status' => $status );
			}
			if ( ! in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				return new WP_Error( 'tcas_invalid_stock', 'دادهٔ موجودی همکار معتبر نیست.' );
			}
			// در حالت Status-only، انبار عددی محلی نباید پاسخ سرویس دور را خنثی کند.
			return array( 'manage' => false, 'quantity' => null, 'status' => $status );
		}

		public static function populate( $product, $stock ) {
			$target = self::targets( $stock );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$product->set_manage_stock( $target['manage'] );
			if ( null !== $target['quantity'] ) {
				$product->set_stock_quantity( $target['quantity'] );
			}
			$product->set_stock_status( $target['status'] );
			return true;
		}

		/** @return bool|WP_Error true=نوشته شد، false=تغییر لازم نبود. */
		public static function apply( $product, $stock ) {
			if ( ! $product || ! is_object( $product ) || $product->is_type( 'variable' ) ) {
				return new WP_Error( 'tcas_stock_target', 'موجودی فقط روی محصول ساده یا Variation نوشته می‌شود.' );
			}
			$target = self::targets( $stock );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$old_manage  = (bool) $product->get_manage_stock( 'edit' );
			$old_qty     = $product->get_stock_quantity( 'edit' );
			$old_status  = (string) $product->get_stock_status( 'edit' );
			$qty_changed = null !== $target['quantity'] && ( null === $old_qty || abs( (float) $old_qty - $target['quantity'] ) > 0.00001 );
			if ( $old_manage === $target['manage'] && ! $qty_changed && $old_status === $target['status'] ) {
				return false;
			}
			$result = self::populate( $product, $stock );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			try {
				$product->save();
				$parent_id = $product->get_parent_id();
				if ( $parent_id && class_exists( 'WC_Product_Variable' ) && is_callable( array( 'WC_Product_Variable', 'sync_stock_status' ) ) ) {
					WC_Product_Variable::sync_stock_status( $parent_id, true );
				}
				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product->get_id() );
					if ( $parent_id ) {
						wc_delete_product_transients( $parent_id );
					}
				}
				return true;
			} catch ( Throwable $e ) {
				return new WP_Error( 'tcas_stock_save', 'ذخیرهٔ موجودی در ووکامرس ناموفق بود: ' . $e->getMessage() );
			}
		}

		public static function status_label( $status ) {
			$labels = array(
				'instock'     => 'موجود',
				'outofstock'  => 'ناموجود',
				'onbackorder' => 'پیش‌سفارش',
			);
			return isset( $labels[ $status ] ) ? $labels[ $status ] : 'نامشخص';
		}
	}
}
