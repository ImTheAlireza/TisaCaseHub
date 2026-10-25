<?php
/**
 * خواندن مستقیم Variationهای محلی از دیتابیس وردپرس و آماده‌سازی برچسب واقعی آن‌ها.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Local_Variation_Reader' ) ) {

	final class TCAS_Local_Variation_Reader {

		private static function database_ids( $product_id ) {
			global $wpdb;
			if ( ! is_object( $wpdb ) || empty( $wpdb->posts ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) ) {
				return null;
			}
			$sql = $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' AND post_status NOT IN ('trash', 'auto-draft') ORDER BY menu_order ASC, ID ASC",
				$product_id
			);
			$ids = $wpdb->get_col( $sql );
			return is_array( $ids ) ? $ids : null;
		}

		public static function ids( $product, $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return array();
			}
			$ids = self::database_ids( $product_id );
			// فقط نبود لایهٔ دیتابیس مجاز به Fallback است؛ نتیجهٔ صفر دیتابیس با Cache پر نمی‌شود.
			if ( null === $ids && is_object( $product ) && method_exists( $product, 'get_children' ) ) {
				$ids = $product->get_children();
			}
			$out = array();
			foreach ( (array) $ids as $variation_id ) {
				$variation_id = absint( $variation_id );
				if ( $variation_id ) {
					$out[ $variation_id ] = $variation_id;
				}
			}
			return array_values( $out );
		}

		public static function belongs_to( $variation, $product_id ) {
			if ( ! is_object( $variation ) || ! method_exists( $variation, 'get_type' ) || 'variation' !== $variation->get_type() ) {
				return false;
			}
			return ! method_exists( $variation, 'get_parent_id' ) || absint( $variation->get_parent_id() ) === absint( $product_id );
		}

		private static function taxonomy( $attribute_name ) {
			$attribute_name = (string) $attribute_name;
			if ( 0 === strpos( $attribute_name, 'attribute_' ) ) {
				$attribute_name = substr( $attribute_name, 10 );
			}
			return 0 === strpos( $attribute_name, 'pa_' ) ? $attribute_name : '';
		}

		public static function attributes( $variation ) {
			$attributes = method_exists( $variation, 'get_attributes' ) ? (array) $variation->get_attributes() : array();
			foreach ( $attributes as $name => $value ) {
				$taxonomy = self::taxonomy( $name );
				if ( ! $taxonomy || ! is_scalar( $value ) || '' === trim( (string) $value ) || ! function_exists( 'get_term_by' ) ) {
					continue;
				}
				$term = get_term_by( 'slug', (string) $value, $taxonomy );
				if ( is_object( $term ) && ! is_wp_error( $term ) && ! empty( $term->name ) ) {
					$attributes[ $name ] = (string) $term->name;
				}
			}
			return $attributes;
		}
	}
}
