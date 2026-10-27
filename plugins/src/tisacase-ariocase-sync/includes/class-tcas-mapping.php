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

		/**
		 * هویت یک مرجع همکار از روی خود مرجع، بدون هیچ درخواستی:
		 * «ID:45448/45453» => remote_id 45453 و parent_id 45448 ؛
		 * «ID:45453» => remote_id 45453. برای SKU هر دو صفر برمی‌گردد.
		 *
		 * کد ذخیره‌شده ممکن است فرم دیگری داشته باشد (SKU یا ID:)، پس مقایسهٔ
		 * درست «هویت» است نه رشتهٔ خام.
		 *
		 * @param string $reference مرجع.
		 * @return array{remote_id:int,parent_id:int}
		 */
		public static function identity_from_reference( $reference ) {
			$reference = trim( (string) $reference );
			$out       = array( 'remote_id' => 0, 'parent_id' => 0 );
			if ( preg_match( '/^ID:([1-9][0-9]*)[\/\\]([1-9][0-9]*)$/i', $reference, $parts ) ) {
				$out['parent_id'] = (int) $parts[1];
				$out['remote_id'] = (int) $parts[2];
			} elseif ( preg_match( '/^ID:([1-9][0-9]*)$/i', $reference, $parts ) ) {
				$out['remote_id'] = (int) $parts[1];
			}
			return $out;
		}

		/** هویت ذخیره‌شدهٔ یک Variation محلی. */
		public static function remote_identity( $product_id ) {
			$product_id = absint( $product_id );
			return array(
				'remote_id' => absint( get_post_meta( $product_id, self::META_REMOTE_ID, true ) ),
				'parent_id' => absint( get_post_meta( $product_id, self::META_PARENT_ID, true ) ),
			);
		}

		/**
		 * Variationهای محلی که همین Variation همکار را گرفته‌اند.
		 *
		 * @param int    $remote_id  شناسهٔ Variation همکار.
		 * @param string $partner_id همکار.
		 * @param int    $parent_id  شناسهٔ والد همکار (۰ = مهم نیست).
		 * @return array<int,int>
		 */
		public static function local_ids_by_remote_id( $remote_id, $partner_id = 'ariocase', $parent_id = 0 ) {
			$remote_id = absint( $remote_id );
			if ( ! $remote_id ) {
				return array();
			}
			$ids        = self::query_ids( self::META_REMOTE_ID, (string) $remote_id );
			$partner_id = sanitize_key( $partner_id );
			$parent_id  = absint( $parent_id );
			$out        = array();
			foreach ( $ids as $product_id ) {
				if ( self::partner_id( $product_id ) !== $partner_id ) {
					continue;
				}
				if ( $parent_id ) {
					$stored_parent = absint( get_post_meta( $product_id, self::META_PARENT_ID, true ) );
					if ( $stored_parent && $stored_parent !== $parent_id ) {
						continue;
					}
				}
				$out[] = (int) $product_id;
			}
			return $out;
		}

		/**
		 * آیا این مرجع همکار از قبل نگاشت دارد؟ اول با هویت (شناسه‌ها) و بعد با رشتهٔ
		 * کد؛ چون نگاشت‌های قدیمی ممکن است SKU ذخیره کرده باشند.
		 *
		 * @param string $reference  مرجع.
		 * @param string $partner_id همکار.
		 * @return array<int,int>
		 */
		public static function holders_of( $reference, $partner_id = 'ariocase', $active_only = true ) {
			$reference = trim( (string) $reference );
			$identity  = self::identity_from_reference( $reference );
			$ids       = array();
			if ( $identity['remote_id'] ) {
				$ids = self::local_ids_by_remote_id( $identity['remote_id'], $partner_id, $identity['parent_id'] );
			}
			if ( ! $ids && '' !== $reference ) {
				$ids = self::ids_by_remote_code( $reference, $partner_id );
			}
			if ( ! $active_only ) {
				return $ids;
			}
			// فقط نگاشت زنده «صاحب مرجع» است: محصولی که اتصالش حذف شده ولی شناسهٔ
			// قدیمی همکار رویش مانده نباید مانع اتصال Variation تازه شود.
			$partner_id = sanitize_key( $partner_id );
			return array_values( array_filter( $ids, function( $product_id ) use ( $partner_id ) {
				if ( ! self::is_enabled( $product_id ) || self::partner_id( $product_id ) !== $partner_id ) {
					return false;
				}
				// نگاشتِ خراب (محصول حذف‌شده یا ناسالم) صاحب مرجع نیست؛ وگرنه کاربر
				// پیام «از قبل متصل است» می‌گیرد برای محصولی که در فهرست هم نیست.
				return (bool) wc_get_product( $product_id );
			} ) );
		}

		/**
		 * پاک‌سازی «مرجع یتیم»: محصولاتی که شناسهٔ مرجع همکار را دارند ولی کد اتصالشان
		 * خالی شده است (نگاشت‌های نیمه‌حذف‌شدهٔ نسخه‌های قبل). این‌ها باعث می‌شدند
		 * Variationهای تازه پیام «از قبل به محصول #… متصل است» بگیرند بدون این‌که
		 * در «محصولات متصل» دیده شوند.
		 *
		 * @return int تعداد محصولات پاک‌شده.
		 */
		/** نام نمایشی محصولِ صاحب مرجع؛ حتی وقتی شیء ووکامرس ساخته نمی‌شود. */
		public static function holder_name( $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				return '';
			}
			$product = wc_get_product( $product_id );
			if ( $product && is_object( $product ) && method_exists( $product, 'get_name' ) ) {
				$name = (string) $product->get_name();
				if ( '' !== $name ) {
					return $name;
				}
			}
			if ( function_exists( 'get_the_title' ) ) {
				return (string) get_the_title( $product_id );
			}
			return '';
		}

		/**
		 * نگاشت‌هایی که در دیتابیس هستند ولی در «محصولات متصل» دیده نمی‌شوند:
		 * محصولشان حذف شده، در زباله‌دان است یا شیء ووکامرس برایشان ساخته نمی‌شود.
		 * همین‌ها باعث می‌شوند اتصال تازه پیام «از قبل متصل است» بگیرد بی‌آن‌که کسی
		 * آن محصول را در فهرست ببیند.
		 *
		 * @return array<int,array{id:int,code:string,title:string,reason:string}>
		 */
		public static function orphans( $limit = 200 ) {
			global $wpdb;
			$limit = max( 1, min( 1000, absint( $limit ) ) );
			$sql   = "SELECT pm.post_id AS id, pm.meta_value AS code FROM {$wpdb->postmeta} pm
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				ORDER BY pm.post_id ASC LIMIT %d";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, self::META_CODE, $limit ), ARRAY_A );
			$out  = array();
			foreach ( $rows as $row ) {
				$product_id = absint( isset( $row['id'] ) ? $row['id'] : 0 );
				if ( ! $product_id ) {
					continue;
				}
				$product = wc_get_product( $product_id );
				$reason  = '';
				if ( ! $product || ! is_object( $product ) ) {
					$reason = 'محصول حذف شده یا شیء ووکامرس برای آن ساخته نمی‌شود';
				} elseif ( method_exists( $product, 'get_status' ) && in_array( (string) $product->get_status(), array( 'trash', 'auto-draft' ), true ) ) {
					$reason = 'محصول در زباله‌دان است';
				}
				if ( '' === $reason ) {
					continue;
				}
				$out[] = array(
					'id'     => $product_id,
					'code'   => (string) $row['code'],
					'title'  => self::holder_name( $product_id ),
					'reason' => $reason,
				);
			}
			return $out;
		}

		/** حذف نگاشت‌های خراب؛ تعداد حذف‌شده را برمی‌گرداند. */
		public static function clear_broken() {
			$removed = 0;
			foreach ( self::orphans() as $row ) {
				self::forget( (int) $row['id'] );
				$removed++;
			}
			return $removed;
		}

		public static function prune_stale( $limit = 500 ) {
			global $wpdb;
			$limit = max( 1, min( 2000, absint( $limit ) ) );
			$sql   = "SELECT p.ID FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> ''
				AND p.post_type IN ('product', 'product_variation')
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} code
					WHERE code.post_id = pm.post_id AND code.meta_key = %s AND code.meta_value <> ''
				)
				ORDER BY p.ID ASC LIMIT %d";
			$ids = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( $sql, self::META_REMOTE_ID, self::META_CODE, $limit ) ) );
			$fixed = 0;
			foreach ( $ids as $product_id ) {
				if ( ! $product_id ) {
					continue;
				}
				foreach ( array( self::META_REMOTE_ID, self::META_PARENT_ID, self::META_REMOTE_NAME, self::META_QTY, self::META_STATUS, self::META_FETCHED_AT, self::META_ERROR, self::META_ENABLED, self::META_PARTNER_ID ) as $key ) {
					delete_post_meta( $product_id, $key );
				}
				$fixed++;
			}
			return $fixed;
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

		/**
		 * آزادکردن یک مرجع همکار از دست Variationهای دیگر (انتقال با تأیید کاربر).
		 *
		 * نگاشت Variationهای قبلی حذف می‌شود تا مرجع یکتا بماند؛ خروجی، snapshot
		 * همان نگاشت‌هاست تا در صورت خطا برگردانده شوند.
		 *
		 * @param string $reference     مرجع.
		 * @param int    $keep_local_id Variationی که باید بماند.
		 * @param string $partner_id    همکار.
		 * @return array<int,array{code:string,enabled:bool,partner:string}>
		 */
		public static function release( $reference, $keep_local_id = 0, $partner_id = 'ariocase' ) {
			$keep = absint( $keep_local_id );
			$out  = array();
			foreach ( self::holders_of( $reference, $partner_id ) as $product_id ) {
				$product_id = absint( $product_id );
				if ( ! $product_id || $product_id === $keep ) {
					continue;
				}
				$out[ $product_id ] = array(
					'code'    => self::remote_code( $product_id ),
					'enabled' => self::is_enabled( $product_id ),
					'partner' => self::partner_id( $product_id ),
				);
				self::forget( $product_id );
			}
			return $out;
		}

		/**
		 * برگرداندن نگاشت‌هایی که release() آزاد کرده است (Rollback).
		 *
		 * @param array $snapshot خروجی release().
		 */
		public static function restore_released( $snapshot ) {
			foreach ( (array) $snapshot as $product_id => $row ) {
				if ( ! is_array( $row ) || empty( $row['code'] ) ) {
					continue;
				}
				self::map( absint( $product_id ), $row['code'], ! empty( $row['enabled'] ), isset( $row['partner'] ) ? $row['partner'] : 'ariocase', true );
			}
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
