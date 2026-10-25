<?php
/**
 * نگاشت دقیق SKU ووکامرس به کد ArioCase.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Mapping' ) ) {

	final class TCAS_Mapping {
		const META_CODE       = '_tcas_remote_code';
		const META_ENABLED    = '_tcas_sync_enabled';
		const META_QTY        = '_tcas_remote_stock';
		const META_STATUS     = '_tcas_remote_stock_status';
		const META_FETCHED_AT = '_tcas_remote_fetched_at';
		const META_ERROR      = '_tcas_last_error';
		const META_REMOTE_ID  = '_tcas_remote_product_id';
		const META_PARENT_ID  = '_tcas_remote_parent_id';

		private static function query_ids( $meta_key, $value ) {
			global $wpdb;
			$sql = "SELECT p.ID FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value = %s
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.ID ASC";
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, $meta_key, (string) $value ) ) );
			$out = array();
			foreach ( $ids as $product_id ) {
				// Collation دیتابیس ممکن است Case/فاصلهٔ انتهایی را نادیده بگیرد؛ اتصال فقط با تطبیق واقعی مجاز است.
				$actual = get_post_meta( $product_id, $meta_key, true );
				if ( (string) $actual === (string) $value ) {
					$out[] = $product_id;
				}
			}
			return $out;
		}

		public static function local_ids_by_sku( $sku ) {
			return self::query_ids( '_sku', trim( (string) $sku ) );
		}

		public static function local_by_sku( $sku ) {
			$ids = self::local_ids_by_sku( $sku );
			if ( count( $ids ) > 1 ) {
				return new WP_Error( 'tcas_duplicate_sku', 'این SKU در چند محصول/Variation مشترک است؛ Variation را در تبدیل‌کننده انتخاب کنید تا با ID یکتا جفت شود.' );
			}
			return $ids ? (int) $ids[0] : 0;
		}

		public static function ids_by_remote_code( $code ) {
			return self::query_ids( self::META_CODE, trim( (string) $code ) );
		}

		public static function remote_code( $product_id ) {
			return trim( (string) get_post_meta( absint( $product_id ), self::META_CODE, true ) );
		}

		public static function remote_identifiers( $product_id ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );
			$out        = array(
				'type'            => $product ? $product->get_type() : '',
				'remote_id'       => absint( get_post_meta( $product_id, self::META_REMOTE_ID, true ) ),
				'parent_remote_id' => 0,
				'parent_code'     => '',
			);
			if ( $product && $product->is_type( 'variation' ) ) {
				$local_parent = absint( $product->get_parent_id() );
				$stored_parent = absint( get_post_meta( $product_id, self::META_PARENT_ID, true ) );
				$out['parent_remote_id'] = $stored_parent ? $stored_parent : absint( get_post_meta( $local_parent, self::META_REMOTE_ID, true ) );
				$out['parent_code']      = self::remote_code( $local_parent );
			}
			return $out;
		}

		public static function remember_product( $product_id, $remote, $parent_remote_id = 0 ) {
			$product_id = absint( $product_id );
			if ( ! $product_id || ! is_array( $remote ) ) {
				return;
			}
			$remote_id = isset( $remote['remote_id'] ) ? absint( $remote['remote_id'] ) : 0;
			if ( $remote_id ) {
				update_post_meta( $product_id, self::META_REMOTE_ID, $remote_id );
			}
			$parent_remote_id = absint( $parent_remote_id );
			if ( $parent_remote_id ) {
				update_post_meta( $product_id, self::META_PARENT_ID, $parent_remote_id );
			}
		}

		public static function is_enabled( $product_id ) {
			$product_id = absint( $product_id );
			return '' !== self::remote_code( $product_id ) && 'no' !== get_post_meta( $product_id, self::META_ENABLED, true );
		}

		public static function map( $product_id, $remote_code, $enabled = true ) {
			$product_id  = absint( $product_id );
			$remote_code = trim( sanitize_text_field( $remote_code ) );
			$product     = wc_get_product( $product_id );
			if ( ! $product || '' === $remote_code ) {
				return new WP_Error( 'tcas_invalid_mapping', 'محصول یا کد ArioCase معتبر نیست.' );
			}
			$other_ids = self::ids_by_remote_code( $remote_code );
			foreach ( $other_ids as $other_id ) {
				if ( $other_id !== $product_id ) {
					return new WP_Error( 'tcas_remote_code_used', sprintf( 'کد ArioCase قبلاً به محصول #%d متصل شده است.', $other_id ) );
				}
			}
			$current = self::remote_code( $product_id );
			if ( '' !== $current && $current !== $remote_code ) {
				return new WP_Error( 'tcas_mapping_exists', 'این محصول قبلاً به کد دیگری متصل شده است؛ ابتدا اتصال قبلی را حذف کنید.' );
			}
			update_post_meta( $product_id, self::META_CODE, $remote_code );
			update_post_meta( $product_id, self::META_ENABLED, $enabled ? 'yes' : 'no' );
			delete_post_meta( $product_id, self::META_ERROR );
			return true;
		}

		public static function forget( $product_id ) {
			$product_id = absint( $product_id );
			foreach ( array( self::META_CODE, self::META_ENABLED, self::META_QTY, self::META_STATUS, self::META_FETCHED_AT, self::META_ERROR, self::META_REMOTE_ID, self::META_PARENT_ID ) as $key ) {
				delete_post_meta( $product_id, $key );
			}
			return true;
		}

		public static function remember( $product_id, $stock ) {
			$product_id = absint( $product_id );
			$quantity   = isset( $stock['quantity'] ) && null !== $stock['quantity'] ? (float) $stock['quantity'] : null;
			$status     = isset( $stock['status'] ) ? (string) $stock['status'] : '';
			$old_qty    = get_post_meta( $product_id, self::META_QTY, true );
			$old_status = (string) get_post_meta( $product_id, self::META_STATUS, true );
			$qty_text   = null === $quantity ? '' : (string) $quantity;
			if ( (string) $old_qty === $qty_text && $old_status === $status && '' !== get_post_meta( $product_id, self::META_FETCHED_AT, true ) ) {
				return;
			}
			update_post_meta( $product_id, self::META_QTY, $qty_text );
			update_post_meta( $product_id, self::META_STATUS, $status );
			update_post_meta( $product_id, self::META_FETCHED_AT, time() );
			delete_post_meta( $product_id, self::META_ERROR );
		}

		public static function remember_error( $product_id, $message ) {
			update_post_meta( absint( $product_id ), self::META_ERROR, sanitize_text_field( $message ) );
		}

		public static function all( $limit = 500 ) {
			global $wpdb;
			$limit = max( 1, min( 1000, absint( $limit ) ) );
			$sql   = "SELECT p.ID, pm.meta_value AS remote_code FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND p.post_type IN ('product', 'product_variation')
				AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.ID DESC LIMIT %d";
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, self::META_CODE, $limit ), ARRAY_A );
			$out  = array();
			foreach ( (array) $rows as $row ) {
				$id      = absint( $row['ID'] );
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				$out[] = array(
					'id'          => $id,
					'parent_id'   => $product->get_parent_id(),
					'name'        => $product->get_name(),
					'sku'         => $product->get_sku(),
					'type'        => $product->get_type(),
					'remote_code' => (string) $row['remote_code'],
					'remote_id'   => absint( get_post_meta( $id, self::META_REMOTE_ID, true ) ),
					'enabled'     => self::is_enabled( $id ),
					'quantity'    => get_post_meta( $id, self::META_QTY, true ),
					'status'      => (string) get_post_meta( $id, self::META_STATUS, true ),
					'fetched_at'  => (int) get_post_meta( $id, self::META_FETCHED_AT, true ),
					'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
				);
			}
			return $out;
		}
	}
}
