<?php
/**
 * موتور اعمال موجودی: نوشتن `_stock` روی متغیرها (یا محصول ساده) با
 * همگام‌سازی وضعیت والد، ترم‌های product_visibility و جدول lookup.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Stock' ) ) {

	final class TBSM_Stock {

		/**
		 * موجودی را روی فهرست متغیرها اعمال می‌کند.
		 *
		 * @param int   $product_id شناسهٔ محصول.
		 * @param array $items      آرایهٔ [ شناسهٔ متغیر => مقدار موجودی ].
		 * @return array            گزارش اعمال (ok, applied, report, errors, ...).
		 */
		public static function apply_stock( $product_id, array $items ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );

			if ( ! $product || ! $product->exists() ) {
				return array(
					'ok'    => false,
					'error' => 'محصول انتخابی پیدا نشد.',
				);
			}

			$is_variable = 'variable' === $product->get_type();
			$allowed_ids = $is_variable
				? array_map( 'absint', (array) $product->get_children() )
				: array( $product_id );

			if ( empty( $items ) ) {
				return array(
					'ok'    => false,
					'error' => 'هیچ متغیری برای اعمال انتخاب نشده است.',
				);
			}

			if ( count( $items ) > TBSM_Core::MAX_ITEMS_APPLY ) {
				return array(
					'ok'    => false,
					'error' => sprintf( 'سقف هر بار اعمال %d متغیر است.', TBSM_Core::MAX_ITEMS_APPLY ),
				);
			}

			$report         = array();
			$errors         = array();
			$enabled_manage = 0;

			foreach ( $items as $raw_id => $raw_qty ) {
				$var_id = absint( $raw_id );

				if ( ! in_array( $var_id, $allowed_ids, true ) ) {
					$errors[] = sprintf( 'متغیر #%d متعلق به این محصول نیست؛ رد شد.', $var_id );
					continue;
				}

				if ( ! is_numeric( $raw_qty ) ) {
					$errors[] = sprintf( 'موجودی متغیر #%d عدد معتبر نبود؛ رد شد.', $var_id );
					continue;
				}

				$qty = max( 0, min( 99999999, (int) $raw_qty ) );

				$variation = wc_get_product( $var_id );
				if ( ! $variation || ! $variation->exists() ) {
					$errors[] = sprintf( 'متغیر #%d پیدا نشد؛ رد شد.', $var_id );
					continue;
				}

				try {
					$from        = (int) $variation->get_stock_quantity();
					$was_managed = (bool) $variation->get_manage_stock();

					if ( ! $was_managed ) {
						$variation->set_manage_stock( true );
						$enabled_manage++;
					}

					$variation->set_stock( $qty );
					$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
					$variation->save();

					$report[] = array(
						'id'      => $var_id,
						'name'    => self::variation_label( $variation ),
						'sku'     => (string) $variation->get_sku(),
						'from'    => $from,
						'to'      => $qty,
						'enabled' => ! $was_managed,
					);
				} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					$errors[] = sprintf( 'خطا در ذخیرهٔ متغیر #%d: %s', $var_id, $e->getMessage() );
				}
			}

			$parent_status = null;
			if ( ! empty( $report ) ) {
				$parent_status = self::sync_parent( $product );
			}

			$result = array(
				'ok'             => ! empty( $report ),
				'applied'        => count( $report ),
				'enabled_manage' => $enabled_manage,
				'parent_status'  => $parent_status,
				'report'         => $report,
				'errors'         => $errors,
			);

			// اگر هیچ‌چیز اعمال نشد، یک پیام واحد برای لایهٔ بالای خود بساز.
			if ( empty( $report ) && ! empty( $errors ) ) {
				$result['error'] = 'هیچ متغیری اعمال نشد: ' . implode( ' ', $errors );
			}

			return $result;
		}

		/**
		 * وضعیت موجودی والد را از روی فرزندهایش محاسبه و همگام می‌کند
		 * (متای `_stock_status`، ترم‌های product_visibility و جدول lookup).
		 *
		 * @param \WC_Product $product محصول.
		 * @return string وضعیت نهایی ('instock' | 'outofstock').
		 */
		public static function sync_parent( $product ) {
			if ( 'variable' !== $product->get_type() ) {
				return (string) $product->get_stock_status();
			}

			$has_stock = false;
			foreach ( (array) $product->get_children() as $child_id ) {
				$child = wc_get_product( (int) $child_id );
				if ( $child && $child->is_in_stock() ) {
					$has_stock = true;
					break;
				}
			}

			$status = $has_stock ? 'instock' : 'outofstock';

			try {
				$product->set_stock_status( $status );
				$product->save();
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// شکست ذخیرهٔ والد نباید نوشتن متغیرها را باطل کند.
			}

			// جدول wp_wc_product_meta_lookup (فیلترهای فروشگاه و آرشیوها).
			if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
				wc_update_product_lookup_tables( (int) $product->get_id() );
			}

			return $status;
		}

		/**
		 * برچسب خوانا برای یک متغیر (ترکیب مقادیر ویژگی‌ها).
		 *
		 * @param \WC_Product $product شیء متغیر (یا محصول ساده).
		 * @return string
		 */
		public static function variation_label( $product ) {
			$parts = array();

			foreach ( (array) $product->get_attributes() as $tax => $value ) {
				if ( null === $value || '' === (string) $value ) {
					continue;
				}
				$label = $product->get_attribute( $tax );
				$parts[] = ( '' !== (string) $label ) ? (string) $label : (string) $value;
			}

			if ( empty( $parts ) ) {
				return 'متغیر #' . (int) $product->get_id();
			}

			return implode( ' · ', $parts );
		}
	}
}
