<?php
/**
 * اعمال موجودی با CRUD ووکامرس؛ خطای API در این لایه به صفر تبدیل نمی‌شود.
 *
 * دو حالت نوشتن پشتیبانی می‌شود:
 * - `flag` (پیش‌فرض): فقط وضعیت موجود/ناموجود از همکار نوشته می‌شود و انبار عددی
 *   محلی دست نمی‌خورد. با رسیدن موجودی همکار به صفر، همان Variation ناموجود می‌شود.
 * - `quantity`: عدد دقیق موجودی همکار در انبار ووکامرس نوشته می‌شود.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Stock' ) ) {

	final class TCAS_Stock {

		const MODE_FLAG     = 'flag';
		const MODE_QUANTITY = 'quantity';
		const STATUSES      = array( 'instock', 'outofstock', 'onbackorder' );

		public static function valid( $stock ) {
			return is_array( $stock ) && ( isset( $stock['quantity'] ) && null !== $stock['quantity'] || ! empty( $stock['status'] ) );
		}

		/** حالت نوشتن موجودی از تنظیمات خوانده می‌شود. */
		public static function mode() {
			$settings = class_exists( 'TCAS_Core' ) ? TCAS_Core::settings() : array();
			return isset( $settings['stock_mode'] ) && self::MODE_QUANTITY === $settings['stock_mode'] ? self::MODE_QUANTITY : self::MODE_FLAG;
		}

		/**
		 * مقصد نوشتن. در حالت flag همیشه quantity=null برمی‌گردد تا هیچ عددی به
		 * ووکامرس نوشته نشود؛ ولی خود عدد برای کنترل سبد استفاده می‌شود.
		 */
		private static function targets( $stock, $mode = '' ) {
			$mode     = '' === $mode ? self::mode() : $mode;
			$quantity = isset( $stock['quantity'] ) && null !== $stock['quantity'] ? (float) $stock['quantity'] : null;
			$status   = isset( $stock['status'] ) ? (string) $stock['status'] : '';
			if ( null !== $quantity ) {
				if ( ! is_finite( $quantity ) || $quantity < 0 || $quantity > 1000000000 ) {
					return new WP_Error( 'tcas_invalid_stock', 'مقدار موجودی دریافتی در بازهٔ مجاز نیست.' );
				}
				if ( $quantity <= 0 ) {
					$status = 'outofstock';
				} elseif ( ! in_array( $status, self::STATUSES, true ) ) {
					$status = 'instock';
				}
				if ( self::MODE_QUANTITY === $mode ) {
					return array( 'manage' => true, 'quantity' => $quantity, 'status' => $status, 'mode' => $mode );
				}
				return array( 'manage' => false, 'quantity' => null, 'status' => $status, 'mode' => $mode );
			}
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return new WP_Error( 'tcas_invalid_stock', 'دادهٔ موجودی همکار معتبر نیست.' );
			}
			// در حالت Status-only، انبار عددی محلی نباید پاسخ سرویس دور را خنثی کند.
			return array( 'manage' => false, 'quantity' => null, 'status' => $status, 'mode' => $mode );
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

			if ( self::MODE_QUANTITY === $target['mode'] ) {
				if ( $old_manage === $target['manage'] && ! $qty_changed && $old_status === $target['status'] ) {
					return false;
				}
			} elseif ( $old_status === $target['status'] ) {
				// حالت flag فقط وضعیت را می‌نویسد؛ انبار عددی محلی دست‌نخورده می‌ماند.
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
