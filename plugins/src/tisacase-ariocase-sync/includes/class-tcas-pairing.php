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
				'reclaim'      => ! empty( $pair['reclaim'] ) ? absint( $pair['reclaim'] ) : 0,
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
		 *              همکار چندمدلی نیست)؛ کارت ساخته می‌شود با پرچم reclaim تا با
		 *              تأیید صریح کاربر، مرجع از Variation قبلی منتقل شود.
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
					// کارت ساخته می‌شود تا Variation از چشم نیفتد، ولی بدون تأیید
					// صریح کاربر انتخاب و ارسال نمی‌شود (مرجع دست Variation دیگری است).
					$match['taken_by']      = (int) $holders[0];
					$match['taken_by_name'] = $holder_names[ $holders[0] ];
					$match['reclaim']       = true;
					$out['taken'][]         = $match;
					$out['skipped']++;
					$out['matches'][]       = $match;
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

		/**
		 * برچسب خود Variation همکار چند مدل دارد و مدل این کارت یکی از همانهاست؟
		 * وقتی «local_label» موجود باشد، صرفاً چندمدلیبودن برچسب کافی نیست؛
		 * مثلاً کارت «A54» نباید به بهانهٔ برچسب «A52 / A52s» آزاد حساب شود.
		 */
		private static function bundled_label( $match ) {
			$flagged = ! empty( $match['shared_remote'] );
			if ( ! class_exists( 'TCAS_Matcher' ) ) {
				return $flagged;
			}
			$remote_label = isset( $match['remote_label'] ) ? (string) $match['remote_label'] : '';
			$local_label  = isset( $match['local_label'] ) ? (string) $match['local_label'] : '';
			$models       = TCAS_Matcher::bundled_models( $remote_label );
			if ( count( $models ) < 2 ) {
				// کارت «فهرست مدل» را سرور ساخته؛ گاهی برچسب در نگاشت ذخیره نشده است.
				return $flagged;
			}
			if ( '' !== trim( $local_label ) ) {
				return TCAS_Matcher::label_matches_models( $local_label, $models );
			}
			return true;
		}

		/** برچسبهای محصول محلی: نام، SKU و مقادیر Attributeهای Variation. */
		private static function local_labels( $product ) {
			$labels = array();
			if ( ! is_object( $product ) ) {
				return $labels;
			}
			foreach ( array( 'get_name', 'get_sku' ) as $method ) {
				if ( method_exists( $product, $method ) ) {
					$labels[] = (string) $product->$method();
				}
			}
			if ( method_exists( $product, 'get_attributes' ) ) {
				foreach ( (array) $product->get_attributes() as $value ) {
					if ( is_scalar( $value ) ) {
						$labels[] = (string) $value;
					}
				}
			}
			return array_values( array_unique( array_filter( $labels, 'strlen' ) ) );
		}

		/**
		 * برچسبهای Variation همکار: نام، SKU/Slug و مقادیر Attributeها. خیلی از
		 * فروشگاهها فهرست مدلها را بهجای «name» در Attribute «مدل» میگذارند.
		 */
		private static function remote_labels( $remote ) {
			$labels = array();
			if ( ! is_array( $remote ) ) {
				return $labels;
			}
			foreach ( array( 'name', 'code', 'sku', 'slug', 'label' ) as $key ) {
				if ( ! empty( $remote[ $key ] ) && is_scalar( $remote[ $key ] ) ) {
					$labels[] = (string) $remote[ $key ];
				}
			}
			foreach ( array( 'variation_attributes', 'attributes' ) as $key ) {
				if ( empty( $remote[ $key ] ) || ! is_array( $remote[ $key ] ) ) {
					continue;
				}
				foreach ( $remote[ $key ] as $value ) {
					if ( is_scalar( $value ) ) {
						$labels[] = (string) $value;
					} elseif ( is_array( $value ) && ! empty( $value['options'] ) && is_array( $value['options'] ) ) {
						foreach ( $value['options'] as $option ) {
							if ( is_scalar( $option ) ) {
								$labels[] = (string) $option;
							}
						}
					}
				}
			}
			return array_values( array_unique( array_filter( $labels, 'strlen' ) ) );
		}

		/** مدرک سروری «مرجع مشترک»؛ هیچ پرچمی از مرورگر در آن دخیل نیست. */
		private static function shared_evidence( $remote, $product ) {
			if ( ! class_exists( 'TCAS_Matcher' ) ) {
				return array( 'shared' => false );
			}
			return TCAS_Matcher::shared_evidence( self::remote_labels( $remote ), self::local_labels( $product ) );
		}

		/** آیا دو هویت مدل، متفاوت ولی همخانوادهاند («A52» و «A52s» بله؛ «A52» و «A54» خیر)؟ */
		private static function models_related( $first, $second ) {
			$first  = array_values( (array) $first );
			$second = array_values( (array) $second );
			if ( ! $first || ! $second || $first === $second ) {
				return false;
			}
			if ( array_intersect( $first, $second ) ) {
				return true;
			}
			foreach ( $first as $a ) {
				foreach ( $second as $b ) {
					if ( preg_match( '/[0-9]/u', (string) $a )
						&& ( 0 === strpos( (string) $b, (string) $a ) || 0 === strpos( (string) $a, (string) $b ) ) ) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * مسیر پشتیبان: پرچم کلاینت بهتنهایی مجوز اشتراک نیست. اگر برچسب همکار در
		 * پاسخ Preview نیامد (مثلاً API فقط شناسه میدهد)، پرچم فقط وقتی پذیرفته
		 * میشود که همان مرجع از قبل روی Variation دیگری با مدلی متفاوت ولی
		 * همخانواده وصل باشد؛ پرچم کهنه یا دستکاریشده محافظ یکتایی را دور نمیزند.
		 */
		private static function corroborate_shared_mapping( $remote_code, $partner_id, $local_id, $product ) {
			if ( ! class_exists( 'TCAS_Mapping' ) || ! class_exists( 'TCAS_Matcher' ) ) {
				return false;
			}
			$mine = array();
			foreach ( self::local_labels( $product ) as $label ) {
				$tokens = TCAS_Matcher::model_tokens( $label );
				if ( $tokens ) {
					$mine[] = $tokens;
				}
			}
			if ( ! $mine ) {
				return false;
			}
			foreach ( TCAS_Mapping::holders_of( $remote_code, $partner_id ) as $holder_id ) {
				if ( (int) $holder_id === (int) $local_id
					|| ! TCAS_Mapping::is_enabled( $holder_id )
					|| TCAS_Mapping::partner_id( $holder_id ) !== $partner_id ) {
					continue;
				}
				$holder = function_exists( 'wc_get_product' ) ? wc_get_product( $holder_id ) : null;
				if ( ! is_object( $holder ) ) {
					continue;
				}
				foreach ( self::local_labels( $holder ) as $label ) {
					$tokens = TCAS_Matcher::model_tokens( $label );
					if ( ! $tokens ) {
						continue;
					}
					foreach ( $mine as $local_tokens ) {
						if ( self::models_related( $local_tokens, $tokens ) ) {
							return true;
						}
					}
				}
			}
			return false;
		}

		/**
		 * آیا Variation همکار خودش چند مدل را فهرست کرده و این محصول یکی از آنهاست؟
		 * حالا از نام، SKU و Attributeهای هر دو طرف مدرک میگیرد، نه فقط «name».
		 */
		private static function bundled_remote( $remote, $product ) {
			$evidence = self::shared_evidence( $remote, $product );
			return ! empty( $evidence['shared'] );
		}

		public static function preview( $pair, $partner_id = 'ariocase' ) {
			$partner_id = class_exists( 'TCAS_Partners' ) ? TCAS_Partners::normalize_id( $partner_id ) : 'ariocase';
			$pair = wp_parse_args( is_array( $pair ) ? $pair : array(), array( 'local_code' => '', 'remote_code' => '', 'error' => '', 'shared_remote' => false ) );
			// پرچم مرورگر فقط یک راهنماست؛ ملاک، مدرکی است که سرور از برچسب همکار میگیرد.
			$claimed_shared = ! empty( $pair['shared_remote'] );
			$shared         = false;
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
			// مرجع مشترک: مدرک اصلی خودِ پاسخ همکار است (نام، SKU یا Attributeها)
			// بهعلاوهٔ نام/SKU/Attributeهای Variation تیساکیس. پرچم مرورگر بهتنهایی
			// نمیتواند محافظ یکتایی مرجع را دور بزند؛ فقط وقتی نگاشت زندهای با مدلی
			// همخانواده همان مرجع را گرفته باشد، مسیر پشتیبان فعال میشود.
			$shared = self::bundled_remote( $remote, $product );
			if ( ! $shared && $claimed_shared ) {
				$shared = self::corroborate_shared_mapping( $pair['remote_code'], $partner_id, $local_id, $product );
			}
			$pair['shared_remote'] = $shared;
			$shared_rejected       = $claimed_shared && ! $shared;
			if ( $shared_rejected ) {
				$extra['shared_rejected'] = true;
			}
			// انتقال با تأیید کاربر: مرجع از Variation قبلی برداشته و به این Variation داده می‌شود.
			$reclaim_holder = 0;
			if ( ! $shared ) {
				foreach ( TCAS_Mapping::holders_of( $pair['remote_code'], $partner_id ) as $mapped_id ) {
					if ( $mapped_id !== $local_id ) {
						if ( ! empty( $pair['reclaim'] ) ) {
							$reclaim_holder = (int) $mapped_id;
							continue;
						}
						$holder_name      = TCAS_Mapping::holder_name( $mapped_id );
						$extra['holder']  = array( 'id' => (int) $mapped_id, 'name' => $holder_name );
						$holder_message = sprintf( 'مرجع %s از قبل به محصول #%d%s متصل است.', $partner_label, $mapped_id, '' !== $holder_name ? ' («' . $holder_name . '»)' : '' );
						if ( $shared_rejected ) {
							$holder_message .= ' نشان «مرجع مشترک» این کارت با فهرست مدلهای همکار تأیید نشد.';
						}
						return array(
							'row'      => self::row( $pair, 'error', $holder_message, $extra ),
							'remote'   => $remote,
							'local_id' => $local_id,
						);
					}
				}
			}
			if ( $reclaim_holder ) {
				$holder_product = wc_get_product( $reclaim_holder );
				$holder_name    = $holder_product && is_object( $holder_product ) ? (string) $holder_product->get_name() : '';
				$pair['reclaim'] = $reclaim_holder;
				$extra['reclaim'] = $reclaim_holder;
				$extra['reclaim_name'] = $holder_name;
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
			$connect_message = $shared
				? 'برچسب همکار چند مدل را با هم دارد؛ همین موجودی روی این Variation و کارت‌های همان فهرست اعمال می‌شود.'
				: 'دو محصول موجود جفت می‌شوند؛ فقط موجودی از ' . $partner_label . ' خوانده و اعمال می‌شود.';
			if ( $reclaim_holder ) {
				$connect_message = sprintf(
					'مرجع از محصول #%d%s برداشته و به این Variation منتقل می‌شود؛ اتصال قبلی آن قطع و موجودی‌اش دیگر از %s خوانده نمی‌شود. با تأیید شما همین حالا انجام می‌شود.',
					$reclaim_holder,
					'' !== $extra['reclaim_name'] ? ' («' . $extra['reclaim_name'] . '»)' : '',
					$partner_label
				);
			}
			return array(
				'row'      => self::row( $pair, 'connect', $connect_message, $extra ),
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
			// انتقال با تأیید: مرجع از Variationهای قبلی آزاد می‌شود (با snapshot برای بازگردانی).
			$released = ! empty( $row['reclaim'] ) ? TCAS_Mapping::release( $row['remote_code'], $local_id, $partner_id ) : array();
			$mapped   = TCAS_Mapping::map( $local_id, $row['remote_code'], true, $partner_id, $shared );
			if ( is_wp_error( $mapped ) ) {
				if ( $released ) {
					TCAS_Mapping::restore_released( $released );
				}
				return $mapped;
			}
			$parent_remote_id = isset( $remote['parent_remote_id'] ) ? $remote['parent_remote_id'] : 0;
			TCAS_Mapping::remember_product( $local_id, $remote, $parent_remote_id );
			$applied = TCAS_Stock::apply( $product, $remote['stock'] );
			if ( is_wp_error( $applied ) ) {
				if ( '' !== $previous_code ) {
					TCAS_Mapping::map( $local_id, $previous_code, $previous_enabled, $previous_partner, true );
				} else {
					TCAS_Mapping::forget( $local_id );
				}
				if ( $released ) {
					TCAS_Mapping::restore_released( $released );
				}
				return $applied;
			}
			TCAS_Mapping::remember( $local_id, $remote['stock'] );
			$message = 'جفت متصل شد و فقط موجودی به‌روزرسانی شد.';
			if ( $released ) {
				$message .= ' مرجع از محصول #' . implode( ' و #', array_keys( $released ) ) . ' برداشته شد و اتصال قبلی آن قطع شد.';
			}
			return array( 'product_id' => $local_id, 'message' => $message );
		}
	}
}
