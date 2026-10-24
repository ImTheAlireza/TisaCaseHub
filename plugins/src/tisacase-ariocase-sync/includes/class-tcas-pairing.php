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
				'stock'        => null,
			), $extra );
		}

		private static function local_product( $local_code ) {
			$local_id = TCAS_Mapping::local_by_sku( $local_code );
			if ( is_wp_error( $local_id ) ) {
				return $local_id;
			}
			if ( ! $local_id ) {
				return new WP_Error( 'tcas_local_not_found', 'محصولی با این SKU در تیساکیس پیدا نشد؛ هیچ محصولی ساخته نمی‌شود.' );
			}
			$product = wc_get_product( $local_id );
			if ( ! $product ) {
				return new WP_Error( 'tcas_local_not_found', 'محصول تیساکیس پیدا نشد.' );
			}
			if ( ! in_array( $product->get_type(), array( 'simple', 'variation' ), true ) ) {
				return new WP_Error( 'tcas_parent_not_supported', 'برای محصول متغیر باید کد هر Variation را جداگانه جفت کنید؛ والد مستقیماً سینک نمی‌شود.' );
			}
			return array( 'id' => (int) $local_id, 'product' => $product );
		}

		public static function preview( $pair ) {
			$pair = wp_parse_args( is_array( $pair ) ? $pair : array(), array( 'local_code' => '', 'remote_code' => '', 'error' => '' ) );
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
			$current = TCAS_Mapping::remote_code( $local_id );
			if ( '' !== $current && $current !== $pair['remote_code'] ) {
				return array( 'row' => self::row( $pair, 'error', 'این محصول قبلاً به کد ArioCase دیگری متصل است؛ ابتدا اتصال قبلی را حذف کنید.', $extra ), 'remote' => null, 'local_id' => $local_id );
			}
			foreach ( TCAS_Mapping::ids_by_remote_code( $pair['remote_code'] ) as $mapped_id ) {
				if ( $mapped_id !== $local_id ) {
					return array( 'row' => self::row( $pair, 'error', sprintf( 'کد ArioCase از قبل به محصول #%d متصل است.', $mapped_id ), $extra ), 'remote' => null, 'local_id' => $local_id );
				}
			}

			$remote = TCAS_Api::fetch_product( $pair['remote_code'] );
			if ( is_wp_error( $remote ) ) {
				return array( 'row' => self::row( $pair, 'error', $remote->get_error_message(), $extra ), 'remote' => null, 'local_id' => $local_id );
			}
			$extra['remote_name'] = $remote['name'];
			$extra['stock']       = $remote['stock'];
			$local_type           = $product->get_type();
			$remote_type          = (string) $remote['type'];
			$type_matches         = 'simple' === $local_type ? in_array( $remote_type, array( '', 'simple' ), true ) : 'variation' === $remote_type;
			if ( ! $type_matches ) {
				return array( 'row' => self::row( $pair, 'error', 'نوع دو محصول برای اتصال یکسان نیست؛ ساده با ساده و Variation با Variation جفت کنید.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			if ( ! TCAS_Stock::valid( $remote['stock'] ) ) {
				return array( 'row' => self::row( $pair, 'error', 'موجودی معتبر در پاسخ ArioCase نیست؛ اتصالی ساخته نشد.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			if ( $current === $pair['remote_code'] && TCAS_Mapping::is_enabled( $local_id ) ) {
				return array( 'row' => self::row( $pair, 'connected', 'این جفت قبلاً متصل شده است.', $extra ), 'remote' => $remote, 'local_id' => $local_id );
			}
			return array(
				'row'      => self::row( $pair, 'connect', 'دو محصول موجود جفت می‌شوند؛ فقط موجودی از ArioCase خوانده و اعمال می‌شود.', $extra ),
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
				return new WP_Error( 'tcas_preview_missing', 'پاسخ ArioCase برای این جفت در دسترس نیست.' );
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
			$previous_code     = TCAS_Mapping::remote_code( $local_id );
			$previous_enabled  = TCAS_Mapping::is_enabled( $local_id );
			$mapped            = TCAS_Mapping::map( $local_id, $row['remote_code'], true );
			if ( is_wp_error( $mapped ) ) {
				return $mapped;
			}
			$parent_remote_id = isset( $remote['parent_remote_id'] ) ? $remote['parent_remote_id'] : 0;
			TCAS_Mapping::remember_product( $local_id, $remote, $parent_remote_id );
			$applied = TCAS_Stock::apply( $product, $remote['stock'] );
			if ( is_wp_error( $applied ) ) {
				if ( '' !== $previous_code ) {
					TCAS_Mapping::map( $local_id, $previous_code, $previous_enabled );
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
