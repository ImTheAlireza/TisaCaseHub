<?php
/**
 * اتصال جفتی دو محصول موجود؛ فقط نگاشت داخلی و موجودی تغییر می‌کند.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Pairing' ) ) {

	final class TCAS_Pairing {

		private static function row( $pair, $status, $message, $extra = array() ) {
			return array_merge( array(
				'local_code'   => isset( $pair['local_code'] ) ? (string) $pair['local_code'] : '',
				'remote_code'  => isset( $pair['remote_code'] ) ? (string) $pair['remote_code'] : '',
				'status'       => $status,
				'message'      => $message,
				'selectable'   => 'connect' === $status,
				'local_id'     => 0,
				'local_name'   => '',
				'remote_name'  => '',
				'local_type'   => '',
				'partner_id'   => isset( $pair['partner_id'] ) ? sanitize_key( $pair['partner_id'] ) : 'ariocase',
				'partner_label'=> isset( $pair['partner_label'] ) ? (string) $pair['partner_label'] : 'ArioCase',
				'stock'        => null,
				'shared_remote' => ! empty( $pair['shared_remote'] ),
			), $extra );
		}

		private static function local_reference_id( $reference ) {
			$matches = array();
			return preg_match( '/^ID:([1-9][0-9]*)$/i', trim( (string) $reference ), $matches ) ? absint( $matches[1] ) : 0;
		}

		private static function local_product( $local_code ) {
			$reference_id = self::local_reference_id( $local_code );
			$local_id     = $reference_id ? $reference_id : TCAS_Mapping::local_by_sku( $local_code );
			if ( is_wp_error( $local_id ) ) {
				return $local_id;
			}
			if ( ! $local_id ) {
				return new WP_Error( 'tcas_local_not_found', 'محصولی با این SKU در تیساکیس پیدا نشد؛ هیچ محصولی ساخته نمی‌شود.' );
			}
			$product = wc_get_product( $local_id );
			if ( ! $product || ( $reference_id && in_array( $product->get_status(), array( 'trash', 'auto-draft' ), true ) ) ) {
				return new WP_Error( 'tcas_local_not_found', 'محصول تیساکیس با این شناسه پیدا نشد.' );
			}
			if ( ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
				return new WP_Error( 'tcas_parent_not_supported', 'برای محصول متغیر باید کد هر Variation را جداگانه جفت کنید؛ والد مستقیماً سینک نمی‌شود.' );
			}
			return array( 'id' => (int) $local_id, 'product' => $product );
		}

		/** شناسهٔ محصول محلی از روی مرجع کارت («ID:123» یا SKU). */
		private static function reference_local_id( $reference ) {
			$reference = trim( (string) $reference );
			$id        = self::local_reference_id( $reference );
			if ( $id ) {
				return $id;
			}
			if ( '' === $reference || ! class_exists( 'TCAS_Mapping' ) ) {
				return 0;
			}
			$lookup = TCAS_Mapping::local_by_sku( $reference );
			return is_wp_error( $lookup ) ? 0 : absint( $lookup );
		}

		/**
		 * کارت‌هایی را علامت می‌زند که همین Variation از قبل به همان مرجع وصل و فعال
		 * است تا رابط آن‌ها را دوباره بررسی/ارسال نکند. فقط نگاشت داخلی همین سایت
		 * خوانده می‌شود؛ هیچ درخواستی به همکار نمی‌رود.
		 */
		/**
		 * کارت‌های تطبیق را بر اساس نگاشت‌های موجود دسته‌بندی می‌کند؛ فقط از دادهٔ
		 * همین سایت، بدون هیچ درخواستی به همکار:
		 *
		 * - connected: همین Variation از قبل به همین Variation همکار وصل است.
		 * - taken:     همین Variation همکار به Variation دیگری وصل است (و برچسب
		 *              همکار چندمدلی نیست)؛ کارت ساخته نمی‌شود تا خطای تکراری ندهد.
		 * - free:      بقیه.
		 *
		 * @param array  $matches    خروجی تطبیق.
		 * @param string $partner_id همکار.
		 * @return array{matches:array,connected:int,taken:array,skipped:int}
		 */
		public static function annotate( $matches, $partner_id = 'ariocase' ) {
			$matches    = array_values( (array) $matches );
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::normalize_id( $partner_id ) : 'ariocase';
			$out        = array( 'matches' => array(), 'connected' => 0, 'taken' => array(), 'skipped' => 0 );
			if ( ! class_exists( 'TCAS_Mapping' ) ) {
				$out['matches'] = $matches;
				return $out;
			}
			$holder_names = array();
			foreach ( $matches as $match ) {
				if ( ! is_array( $match ) ) {
					continue;
				}
				$local_id  = self::reference_local_id( isset( $match['local_code'] ) ? $match['local_code'] : '' );
				$reference = isset( $match['remote_code'] ) ? (string) $match['remote_code'] : '';
				if ( ! $local_id || '' === $reference ) {
					$out['matches'][] = $match;
					continue;
				}
				// ۱) همین Variation از قبل وصل است.
				$own_code = TCAS_Mapping::remote_code( $local_id );
				$own_same = '' !== $own_code && TCAS_Mapping::is_enabled( $local_id )
					&& TCAS_Mapping::partner_id( $local_id ) === $partner_id
					&& self::same_remote( $own_code, $reference, $local_id );
				if ( $own_same ) {
					$match['connected']      = true;
					$match['connected_code'] = $own_code;
					$out['matches'][]        = $match;
					$out['connected']++;
					continue;
				}
				// ۲) همین Variation همکار در اختیار Variation دیگری است.
				$holders = array_values( array_diff( TCAS_Mapping::holders_of( $reference, $partner_id ), array( $local_id ) ) );
				$holders = array_values( array_filter( $holders, function ( $id ) use ( $partner_id ) {
					return TCAS_Mapping::is_enabled( $id ) && TCAS_Mapping::partner_id( $id ) === $partner_id;
				} ) );
				if ( $holders && ! self::bundled_label( $match ) ) {
					if ( ! isset( $holder_names[ $holders[0] ] ) ) {
						$product = wc_get_product( $holders[0] );
						$holder_names[ $holders[0] ] = $product && is_object( $product ) ? (string) $product->get_name() : '';
					}
					$match['taken_by']      = (int) $holders[0];
					$match['taken_by_name'] = $holder_names[ $holders[0] ];
					$out['taken'][]         = $match;
					$out['skipped']++;
					continue;
				}
				// ۳) آزاد (یا برچسب چندمدلی که اجازهٔ اشتراک دارد).
				if ( $holders ) {
					$match['shared_remote'] = true;
				}
				$out['matches'][] = $match;
			}
			return $out;
		}

		/** سازگاری با فراخوانی‌های قبلی. */
		public static function mark_connected( $matches, $partner_id = 'ariocase' ) {
			$result = self::annotate( $matches, $partner_id );
			return array( 'matches' => $result['matches'], 'connected' => $result['connected'] );
		}

		/** آیا این کد ذخیره‌شده همان مرجع تازه است؟ (هویت یا رشته) */
		private static function same_remote( $stored_code, $reference, $local_id = 0 ) {
			$stored_code = trim( (string) $stored_code );
			$reference   = trim( (string) $reference );
			if ( '' === $stored_code || '' === $reference ) {
				return false;
			}
			if ( $stored_code === $reference ) {
				return true;
			}
			$stored = TCAS_Mapping::identity_from_reference( $stored_code );
			$fresh  = TCAS_Mapping::identity_from_reference( $reference );
			if ( $stored['remote_id'] && $fresh['remote_id'] ) {
				if ( $stored['remote_id'] !== $fresh['remote_id'] ) {
					return false;
				}
				return ( ! $stored['parent_id'] || ! $fresh['parent_id'] || $stored['parent_id'] === $fresh['parent_id'] );
			}
			// کد ذخیره‌شده SKU است: هویت ذخیره‌شدهٔ همان محصول را مقایسه می‌کنیم.
			if ( $local_id ) {
				$identity = TCAS_Mapping::remote_identity( $local_id );
				return $identity['remote_id'] && $identity['remote_id'] === $fresh['remote_id'];
			}
			return false;
		}

		/** برچسب خود Variation همکار چند مدل دارد؟ */
		private static function bundled_label( $match ) {
			if ( ! empty( $match['shared_remote'] ) ) {
				return true;
			}
			if ( ! class_exists( 'TCAS_Matcher' ) ) {
				return false;
			}
			$models = TCAS_Matcher::bundled_models( isset( $match['remote_label'] ) ? (string) $match['remote_label'] : '' );
			return count( $models ) >= 2;
		}

		/**
		 * آیا Variation همکار خودش چند مدل را فهرست کرده («Samsung A52 / A52s») و این
		 * محصول یکی از همان مدل‌هاست؟ ملاک اصلی «مرجع مشترک» همین است، چون پرچم مرورگر
		 * ممکن است در اجرای بعدی یا برای کارت‌های ذخیره‌شده نرسد.
		 */
		private static function bundled_remote( $remote, $product ) {
			if ( ! class_exists( 'TCAS_Matcher' ) || ! is_object( $product ) ) {
				return false;
			}
			$models = TCAS_Matcher::bundled_models( isset( $remote['name'] ) ? (string) $remote['name'] : '' );
			if ( count( $models ) < 2 ) {
				return false;
			}
			$labels = array();
			if ( method_exists( $product, 'get_name' ) ) {
				$labels[] = (string) $product->get_name();
			}
			if ( method_exists( $product, 'get_sku' ) ) {
				$labels[] = (string) $product->get_sku();
			}
			foreach ( $labels as $label ) {
				if ( '' !== trim( $label ) && TCAS_Matcher::label_matches_models( $label, $models ) ) {
					return true;
				}
			}
			return false;
		}

		public static function preview( $pair, $partner_id = 'ariocase' ) {
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::normalize_id( $partner_id ) : 'ariocase';
			$pair = wp_parse_args( is_array( $pair ) ? $pair : array(), array( 'local_code' => '', 'remote_code' => '', 'error' => '', 'shared_remote' => false ) );
			// برچسب چندمدلی همکار («A52 / A52s»): یک مرجع می‌تواند روی چند Variation تیساکیس بنشیند.
			$shared = ! empty( $pair['shared_remote'] );
			$pair['partner_id'] = $partner_id;
			$pair['partner_label'] = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase';
			if ( '' !== $pair['error'] ) {
				return array( 'row' => self::row( $pair, 'error', $pair['error'] ), 'remote' => null, 'local_id' => 0 );
			}
			$local = self::local_product( $pair['local_code'] );
			if ( is_wp_error( $local ) ) {
				return array( 'row' => self::row( $pair, 'error', $local->get_error_message() ), 'remote' => null, 'local_id' => 0 );
			}
			$product  = $local['product'];
			$local_id = $local['id'];
			$extra    = array(
				'local_id'   => $local_id,
				'local_name' => $product->get_name(),
				'local_type' => $product->get_type(),
			);
			$current   = TCAS_Mapping::remote_code( $local_id );
			$current_partner = TCAS_Mapping::partner_id( $local_id );
			$partner_label = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::label( $partner_id ) : 'ArioCase';
			$reference = $pair['remote_code'];
			$extra['partner_id'] = $partner_id;
			$extra['partner_label'] = $partner_label;
			if ( '' !== $current && $current_partner !== $partner_id ) {
				return array( 'row' => self::row( $pair, 'error', 'این محصول قبلاً به همکار دیگری متصل است؛ ابتدا اتصال قبلی را حذف کنید.', $extra ), 'remote' => null, 'local_id' => $local_id, 'partner_id' => $partner_id );
			}
			$is_url    = (bool) preg_match( '#^https?://#i', $reference );
			// قبلاً متصل شده و همان مرجع است (هویت یکی است، حتی اگر شکل کد فرق کند):
			// هیچ درخواستی به همکار زده نمی‌شود.
			if ( ! $is_url && '' !== $current && self::same_remote( $current, $reference, $local_id ) && TCAS_Mapping::is_enabled( $local_id ) ) {
				return array(
					'row'      => self::row( $pair, 'connected', 'این جفت قبلاً متصل شده است؛ برای پرهیز از درخواست تکراری دوباره خوانده نمی‌شود.', $extra ),
					'remote'   => null,
					'local_id' => $local_id,
				);
			}
			if ( ! $is_url && '' !== $current && $current !== $reference ) {
				return array( 'row' => self::row( $pair, 'error', 'این محصول قبلاً به مرجع دیگری از ' . $partner_label . ' متصل است؛ ابتدا اتصال قبلی را حذف کنید.', $extra ), 'remote' => null, 'local_id' => $local_id, 'partner_id' => $partner_id );
			}
			$remote = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::fetch_reference( $partner_id, $reference ) : TCAS_Api::fetch_reference( $reference );
			if ( is_wp_error( $remote ) ) {
				return array( 'row' => self::row( $pair, 'error', $remote->get_error_message(), $extra ), 'remote' => null, 'local_id' => $local_id );
			}
			$remote_key = isset( $remote['code'] ) ? trim( (string) $remote['code'] ) : '';
			$remote_id  = ! empty( $remote['remote_id'] ) ? absint( $remote['remote_id'] ) : 0;
			if ( '' === $remote_key && $remote_id ) {
				$parent_remote_id = ! empty( $remote['parent_remote_id'] ) ? absint( $remote['parent_remote_id'] ) : 0;
				if ( isset( $remote['type'] ) && 'variation' === $remote['type'] && ! $parent_remote_id ) {
					return array( 'row' => self::row( $pair, 'error', 'برای Variation بدون SKU، شناسهٔ والد باید در پاسخ همکار موجود باشد.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
				}
				$remote_key = 'ID:' . ( $parent_remote_id ? $parent_remote_id . '/' : '' ) . $remote_id;
			}
			if ( '' === $remote_key ) {
				return array( 'row' => self::row( $pair, 'error', 'پاسخ همکار باید SKU یا شناسهٔ محصول داشته باشد.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			$pair['remote_code'] = $remote_key;
			if ( $is_url && '' !== $current && $current !== $pair['remote_code'] ) {
				return array( 'row' => self::row( $pair, 'error', 'این محصول قبلاً به مرجع دیگری از ' . $partner_label . ' متصل است؛ ابتدا اتصال قبلی را حذف کنید.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			// مرجع مشترک: اگر برچسب خودِ Variation همکار چند مدل را با هم فهرست کرده باشد و
			// این محصول یکی از همان مدل‌ها باشد، چند Variation تیساکیس می‌توانند به همان
			// مرجع وصل شوند؛ پرچم کلاینت لازم نیست (کارت‌های ذخیره‌شدهٔ قبلی هم کار می‌کنند).
			if ( ! $shared && self::bundled_remote( $remote, $product ) ) {
				$shared = true;
			}
			$pair['shared_remote'] = $shared;
			if ( ! $shared ) {
				foreach ( TCAS_Mapping::holders_of( $pair['remote_code'], $partner_id ) as $mapped_id ) {
					if ( $mapped_id !== $local_id ) {
						return array( 'row' => self::row( $pair, 'error', sprintf( 'مرجع %s از قبل به محصول #%d متصل است.', $partner_label, $mapped_id ), $extra ), 'remote' => $remote, 'local_id' => $local_id );
					}
				}
			}
			$extra['remote_name'] = $remote['name'];
			$extra['stock']       = $remote['stock'];
			// موجودی فعلی سمت تیساکیس فقط برای نمایش کنار کارت؛ هیچ نوشتنی انجام نمی‌شود.
			$local_quantity = method_exists( $product, 'get_stock_quantity' ) ? $product->get_stock_quantity() : null;
			$local_status   = method_exists( $product, 'get_stock_status' ) ? (string) $product->get_stock_status() : '';
			$extra['local_stock'] = array(
				'quantity' => ( null === $local_quantity || '' === $local_quantity ) ? null : (float) $local_quantity,
				'status'   => $local_status,
			);
			$local_type           = $product->get_type();
			$remote_type          = (string) $remote['type'];
			$type_matches         = 'simple' === $local_type ? in_array( $remote_type, array( '', 'simple' ), true ) : 'variation' === $remote_type;
			if ( ! $type_matches ) {
				return array( 'row' => self::row( $pair, 'error', 'نوع دو محصول برای اتصال یکسان نیست؛ ساده با ساده و Variation با Variation جفت کنید.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			if ( ! TCAS_Stock::valid( $remote['stock'] ) ) {
				return array( 'row' => self::row( $pair, 'error', 'موجودی نرمال‌شده و معتبر از همکار دریافت نشد؛ اتصالی ساخته نشد.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			if ( $current === $pair['remote_code'] && TCAS_Mapping::is_enabled( $local_id ) ) {
				return array( 'row' => self::row( $pair, 'connected', 'این جفت قبلاً متصل شده است.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			return array(
				'row'      => self::row(
					$pair,
					'connect',
					$shared
						? 'برچسب همکار چند مدل را با هم دارد؛ همین موجودی روی این Variation و کارت‌های همان فهرست اعمال می‌شود.'
						: 'دو محصول موجود جفت می‌شوند؛ فقط موجودی از ' . $partner_label . ' خوانده و اعمال می‌شود.',
					$extra
				),
				'remote'   => $remote,
				'local_id' => $local_id,
			);
		}

		public static function connect( $preview ) {
			if ( ! is_array( $preview ) || empty( $preview['row'] ) ) {
				return new WP_Error( 'tcas_preview_missing', 'پیش‌نمایش معتبر برای این جفت در دسترس نیست.' );
			}
			$row = $preview['row'];
			if ( empty( $row['selectable'] ) || 'connect' !== $row['status'] ) {
				$message = ! empty( $row['message'] ) ? $row['message'] : 'این جفت قابل اتصال نیست.';
				return new WP_Error( 'tcas_not_connectable', $message );
			}
			if ( empty( $preview['remote'] ) ) {
				return new WP_Error( 'tcas_preview_missing', 'پاسخ همکار برای این جفت در دسترس نیست.' );
			}
			$local = self::local_product( $row['local_code'] );
			if ( is_wp_error( $local ) ) {
				return $local;
			}
			if ( $local['id'] !== (int) $preview['local_id'] ) {
				return new WP_Error( 'tcas_preview_stale', 'محصول تیساکیس بعد از پیش‌نمایش تغییر کرده است؛ دوباره پیش‌نمایش بگیرید.' );
			}
			$product           = $local['product'];
			$local_id          = $local['id'];
			$remote            = $preview['remote'];
			$partner_id        = isset( $row['partner_id'] ) ? sanitize_key( $row['partner_id'] ) : 'ariocase';
			$previous_code     = TCAS_Mapping::remote_code( $local_id );
			$previous_enabled  = TCAS_Mapping::is_enabled( $local_id );
			$previous_partner  = TCAS_Mapping::partner_id( $local_id );
			$shared            = ! empty( $row['shared_remote'] );
			$mapped            = TCAS_Mapping::map( $local_id, $row['remote_code'], true, $partner_id, $shared );
			if ( is_wp_error( $mapped ) ) {
				return $mapped;
			}
			$parent_remote_id = isset( $remote['parent_remote_id'] ) ? $remote['parent_remote_id'] : 0;
			TCAS_Mapping::remember_product( $local_id, $remote, $parent_remote_id );
			$applied = TCAS_Stock::apply( $product, $remote['stock'] );
			if ( is_wp_error( $applied ) ) {
				if ( '' !== $previous_code ) {
					TCAS_Mapping::map( $local_id, $previous_code, $previous_enabled, $previous_partner );
				} else {
					TCAS_Mapping::forget( $local_id );
				}
				return $applied;
			}
			TCAS_Mapping::remember( $local_id, $remote['stock'] );
			return array( 'product_id' => $local_id, 'message' => 'جفت متصل شد و فقط موجودی به‌روزرسانی شد.' );
		}
	}
}
