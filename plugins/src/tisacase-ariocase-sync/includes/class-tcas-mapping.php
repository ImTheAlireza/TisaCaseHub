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
		const META_REMOTE_NAME = '_tcas_remote_product_name';
		const META_PARTNER_ID  = '_tcas_partner_id';

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

		public static function ids_by_remote_code( $code, $partner_id = 'ariocase' ) {
			$ids = self::query_ids( self::META_CODE, trim( (string) $code ) );
			$partner_id = sanitize_key( $partner_id );
			return array_values( array_filter( $ids, function( $product_id ) use ( $partner_id ) {
				return self::partner_id( $product_id ) === $partner_id;
			} ) );
		}

		public static function partner_id( $product_id ) {
			$id = sanitize_key( get_post_meta( absint( $product_id ), self::META_PARTNER_ID, true ) );
			return '' === $id ? 'ariocase' : $id;
		}

		public static function remote_code( $product_id ) {
			return trim( (string) get_post_meta( absint( $product_id ), self::META_CODE, true ) );
		}

		public static function remote_identifiers( $product_id ) {
			$product_id = absint( $product_id );
			$product    = wc_get_product( $product_id );
			$out        = array(
				'partner_id'      => self::partner_id( $product_id ),
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
			if ( isset( $remote['name'] ) && is_scalar( $remote['name'] ) && '' !== trim( (string) $remote['name'] ) ) {
				update_post_meta( $product_id, self::META_REMOTE_NAME, sanitize_text_field( $remote['name'] ) );
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

		/**
		 * @param bool $allow_shared اجازهٔ اشتراک یک مرجع همکار بین چند Variation محلی
		 *                           (برچسب چندمدلی مثل «A52 / A52s» که همکار یکی کرده است).
		 */
		public static function map( $product_id, $remote_code, $enabled = true, $partner_id = 'ariocase', $allow_shared = false ) {
			$product_id  = absint( $product_id );
			$remote_code = trim( sanitize_text_field( $remote_code ) );
			$partner_id  = sanitize_key( $partner_id );
			$product     = wc_get_product( $product_id );
			if ( ! $product || '' === $remote_code || '' === $partner_id || ( class_exists( 'TCAS_Partners' ) && ! TCAS_Partners::get( $partner_id ) ) ) {
				return new WP_Error( 'tcas_invalid_mapping', 'محصول یا مرجع همکار معتبر نیست.' );
			}
			$current_partner = self::partner_id( $product_id );
			$current = self::remote_code( $product_id );
			if ( '' !== $current && $current_partner !== $partner_id ) {
				return new WP_Error( 'tcas_mapping_partner_exists', 'این محصول به همکار دیگری متصل است؛ ابتدا اتصال قبلی را حذف کنید.' );
			}
			if ( ! $allow_shared ) {
				$other_ids = self::ids_by_remote_code( $remote_code, $partner_id );
				foreach ( $other_ids as $other_id ) {
					if ( $other_id !== $product_id ) {
						return new WP_Error( 'tcas_remote_code_used', sprintf( 'این مرجع %s قبلاً به محصول #%d متصل شده است.', class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'همکار', $other_id ) );
					}
				}
			}
			if ( '' !== $current && $current !== $remote_code ) {
				return new WP_Error( 'tcas_mapping_exists', 'این محصول قبلاً به کد دیگری متصل شده است؛ ابتدا اتصال قبلی را حذف کنید.' );
			}
			update_post_meta( $product_id, self::META_CODE, $remote_code );
			if ( 'ariocase' === $partner_id ) {
				delete_post_meta( $product_id, self::META_PARTNER_ID );
			} else {
				update_post_meta( $product_id, self::META_PARTNER_ID, $partner_id );
			}
			update_post_meta( $product_id, self::META_ENABLED, $enabled ? 'yes' : 'no' );
			delete_post_meta( $product_id, self::META_ERROR );
			return true;
		}

		public static function forget( $product_id ) {
			$product_id = absint( $product_id );
			foreach ( array( self::META_CODE, self::META_ENABLED, self::META_QTY, self::META_STATUS, self::META_FETCHED_AT, self::META_ERROR, self::META_REMOTE_ID, self::META_PARENT_ID, self::META_REMOTE_NAME, self::META_PARTNER_ID ) as $key ) {
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
			if ( (string) $old_qty !== $qty_text || $old_status !== $status ) {
				update_post_meta( $product_id, self::META_QTY, $qty_text );
				update_post_meta( $product_id, self::META_STATUS, $status );
			}
			// Refresh the age of a successful snapshot even when quantity/status did not change.
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
					'menu_order' => method_exists( $product, 'get_menu_order' ) ? (int) $product->get_menu_order() : $id,
					'remote_code' => (string) $row['remote_code'],
					'partner_id'  => self::partner_id( $id ),
					'partner_label' => class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( self::partner_id( $id ) ) : 'ArioCase',
					'remote_id'   => absint( get_post_meta( $id, self::META_REMOTE_ID, true ) ),
					'remote_parent_id' => absint( get_post_meta( $id, self::META_PARENT_ID, true ) ),
					'remote_name' => (string) get_post_meta( $id, self::META_REMOTE_NAME, true ),
					'enabled'     => self::is_enabled( $id ),
					'quantity'    => get_post_meta( $id, self::META_QTY, true ),
					'status'      => (string) get_post_meta( $id, self::META_STATUS, true ),
					'fetched_at'  => (int) get_post_meta( $id, self::META_FETCHED_AT, true ),
					'error'       => (string) get_post_meta( $id, self::META_ERROR, true ),
				);
			}
			return $out;
		}

		/** Group connected variations by the same local and remote parent products. */
		public static function group_rows( $rows ) {
			$groups = array();
			foreach ( (array) $rows as $row ) {
				$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
				if ( ! $id ) {
					continue;
				}
				$local_parent_id = ( isset( $row['type'] ) && 'variation' === $row['type'] && ! empty( $row['parent_id'] ) ) ? absint( $row['parent_id'] ) : $id;
				$remote_parent_id = isset( $row['remote_parent_id'] ) ? absint( $row['remote_parent_id'] ) : 0;
				if ( ! $remote_parent_id && ! empty( $row['remote_code'] ) && preg_match( '/^ID:([1-9][0-9]*)\/[1-9][0-9]*$/i', (string) $row['remote_code'], $parts ) ) {
					$remote_parent_id = absint( $parts[1] );
				}
				$remote_group = $remote_parent_id ? 'parent:' . $remote_parent_id : 'item:' . ( ! empty( $row['remote_id'] ) ? absint( $row['remote_id'] ) : (string) $row['remote_code'] );
				$partner_id = isset( $row['partner_id'] ) ? sanitize_key( $row['partner_id'] ) : 'ariocase';
				$key = $partner_id . '|' . $local_parent_id . '|' . $remote_group;
				if ( ! isset( $groups[ $key ] ) ) {
					$local_parent = $local_parent_id !== $id ? wc_get_product( $local_parent_id ) : false;
					$local_name = $local_parent && is_object( $local_parent ) ? $local_parent->get_name() : ( isset( $row['name'] ) ? (string) $row['name'] : 'محصول تیساکیس' );
					$remote_name = $remote_parent_id ? 'والد #' . $remote_parent_id : ( ! empty( $row['remote_name'] ) ? (string) $row['remote_name'] : (string) $row['remote_code'] );
					$groups[ $key ] = array(
						'partner_id'        => $partner_id,
						'partner_label'     => isset( $row['partner_label'] ) ? (string) $row['partner_label'] : ( class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase' ),
						'local_parent_id'   => $local_parent_id,
					'local_parent_name' => $local_name,
					'remote_parent_id'  => $remote_parent_id,
					'remote_parent_name'=> $remote_name,
					'rows'              => array(),
					'count'             => 0,
				);
				}
				$groups[ $key ]['rows'][] = $row;
				$groups[ $key ]['count']++;
			}
			foreach ( $groups as &$group ) {
				usort( $group['rows'], function( $a, $b ) {
					$order = (int) ( isset( $a['menu_order'] ) ? $a['menu_order'] : $a['id'] ) <=> (int) ( isset( $b['menu_order'] ) ? $b['menu_order'] : $b['id'] );
					return 0 !== $order ? $order : ( (int) $a['id'] <=> (int) $b['id'] );
				} );
			}
			unset( $group );
			return array_values( $groups );
		}
	}
}
