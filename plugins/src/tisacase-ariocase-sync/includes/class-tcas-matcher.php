<?php
/**
 * تطبیق یک‌به‌یک Variationها با کلیدهای یکتا و بدون حدس در موارد مبهم.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Matcher' ) ) {

	final class TCAS_Matcher {
		const SCORE_SKU   = 100;
		const SCORE_LABEL = 95;
		const SCORE_TOKEN = 90;

		private static function unicode_text( $value ) {
			$value = trim( (string) $value );
			$value = strtr( $value, array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'ك' => 'ک', 'ي' => 'ی', 'ى' => 'ی', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
			) );
			$value = str_replace( array( 'نقره‌ای', 'قهوه‌ای', 'سرمه‌ای', "‌", 'ـ' ), array( 'silver', 'brown', 'navy', ' ', '' ), $value );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		}

		public static function normalize_sku( $value ) {
			$value = self::unicode_text( $value );
			return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $value );
		}

		private static function label_tokens( $value ) {
			$value = self::unicode_text( $value );
			$value = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value ) );
			if ( '' === $value ) {
				return array();
			}
			$stopwords = array(
				'samsung' => true, 'galaxy' => true, 'apple' => true, 'iphone' => true,
				'xiaomi' => true, 'redmi' => true, 'phone' => true, 'mobile' => true,
				'smartphone' => true, 'product' => true, 'model' => true, 'color' => true,
				'colour' => true, 'size' => true, 'storage' => true, 'memory' => true,
				'سامسونگ' => true, 'گلکسی' => true, 'اپل' => true, 'ایفون' => true,
				'شیایومی' => true, 'ردمی' => true, 'گوشی' => true, 'موبایل' => true,
				'محصول' => true, 'مدل' => true, 'رنگ' => true, 'سایز' => true,
				'اندازه' => true, 'حافظه' => true, 'ظرفیت' => true, 'سری' => true,
			);
			$aliases = array(
				'اولترا' => 'ultra', 'پرو' => 'pro', 'مکس' => 'max', 'پلاس' => 'plus',
				'مینی' => 'mini', 'لایت' => 'lite',
				'مشکی' => 'black', 'سفید' => 'white', 'ابی' => 'blue', 'قرمز' => 'red',
				'سبز' => 'green', 'طلایی' => 'gold', 'نقرهای' => 'silver', 'خاکستری' => 'gray',
				'بنفش' => 'purple', 'صورتی' => 'pink', 'زرد' => 'yellow', 'نارنجی' => 'orange',
				'قهوهای' => 'brown', 'گیگ' => 'gb', 'گیگابایت' => 'gb', 'ترابایت' => 'tb',
				'مگابایت' => 'mb',
			);
			$out = array();
			foreach ( preg_split( '/\s+/u', $value ) as $token ) {
				$token = trim( (string) $token );
				if ( '' === $token || isset( $stopwords[ $token ] ) ) {
					continue;
				}
				$out[] = isset( $aliases[ $token ] ) ? $aliases[ $token ] : $token;
			}
			return array_values( array_filter( $out, 'strlen' ) );
		}

		public static function normalize_label( $value ) {
			return implode( '', self::label_tokens( $value ) );
		}

		private static function sorted_token_key( $tokens ) {
			$expanded = array();
			foreach ( $tokens as $token ) {
				if ( preg_match( '/^(.+?\d+)(ultra|pro|max|plus|mini|lite|gb|tb|mb)$/u', $token, $parts ) ) {
					$expanded[] = $parts[1];
					$expanded[] = $parts[2];
				} else {
					$expanded[] = $token;
				}
			}
			sort( $expanded, SORT_STRING );
			return implode( '', $expanded );
		}

		private static function keys( $variation ) {
			$out  = array();
			$sku  = self::normalize_sku( isset( $variation['sku'] ) ? $variation['sku'] : '' );
			$ref  = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
			if ( '' === $ref ) {
				return $out;
			}
			if ( '' !== $sku ) {
				$out[ 'sku:' . $sku ] = self::SCORE_SKU;
			}
			$tokens = self::label_tokens( isset( $variation['label'] ) ? $variation['label'] : '' );
			$label  = implode( '', $tokens );
			if ( '' !== $label ) {
				$out[ 'label:' . $label ] = self::SCORE_LABEL;
				$token_key = self::sorted_token_key( $tokens );
				$out[ 'tokens:' . $token_key ] = self::SCORE_TOKEN;
			}
			return $out;
		}

		private static function duplicate_references( $variations ) {
			$counts = array();
			foreach ( array_values( (array) $variations ) as $variation ) {
				$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
				if ( '' !== $reference ) {
					$counts[ $reference ] = isset( $counts[ $reference ] ) ? $counts[ $reference ] + 1 : 1;
				}
			}
			$duplicates = array();
			foreach ( array_values( (array) $variations ) as $index => $variation ) {
				$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
				if ( '' !== $reference && $counts[ $reference ] > 1 ) {
					$duplicates[ $index ] = true;
				}
			}
			return $duplicates;
		}

		private static function index( $variations, $duplicates ) {
			$out = array();
			foreach ( array_values( (array) $variations ) as $index => $variation ) {
				if ( isset( $duplicates[ $index ] ) ) {
					continue;
				}
				foreach ( self::keys( $variation ) as $key => $score ) {
					$out[ $key ][ $index ] = $score;
				}
			}
			return $out;
		}

		private static function label( $variation, $fallback ) {
			$label = isset( $variation['label'] ) ? trim( (string) $variation['label'] ) : '';
			if ( '' === $label && isset( $variation['sku'] ) ) {
				$label = trim( (string) $variation['sku'] );
			}
			return '' !== $label ? $label : $fallback;
		}

		public static function match( $local_variations, $remote_variations ) {
			$local_variations  = array_values( (array) $local_variations );
			$remote_variations = array_values( (array) $remote_variations );
			$duplicate_local   = self::duplicate_references( $local_variations );
			$duplicate_remote  = self::duplicate_references( $remote_variations );
			$local_index       = self::index( $local_variations, $duplicate_local );
			$remote_index      = self::index( $remote_variations, $duplicate_remote );
			$edges             = array();
			$possible_local    = array();
			$possible_remote   = array();

			foreach ( $local_index as $key => $local_entries ) {
				if ( ! isset( $remote_index[ $key ] ) ) {
					continue;
				}
				$remote_entries = $remote_index[ $key ];
				foreach ( $local_entries as $local_id => $local_score ) {
					foreach ( $remote_entries as $remote_id => $remote_score ) {
						$possible_local[ $local_id ]   = true;
						$possible_remote[ $remote_id ] = true;
					}
				}
				if ( 1 !== count( $local_entries ) || 1 !== count( $remote_entries ) ) {
					continue;
				}
				$local_id  = (int) array_key_first( $local_entries );
				$remote_id = (int) array_key_first( $remote_entries );
				$score     = (int) max( $local_entries[ $local_id ], $remote_entries[ $remote_id ] );
				$edge_key  = $local_id . ':' . $remote_id;
				if ( ! isset( $edges[ $edge_key ] ) || $score > $edges[ $edge_key ]['score'] ) {
					$edges[ $edge_key ] = array( 'local' => (int) $local_id, 'remote' => (int) $remote_id, 'score' => $score );
				}
			}

			$best_local  = array();
			$best_remote = array();
			foreach ( $edges as $edge ) {
				$local_id  = $edge['local'];
				$remote_id = $edge['remote'];
				$best_local[ $local_id ]   = isset( $best_local[ $local_id ] ) ? max( $best_local[ $local_id ], $edge['score'] ) : $edge['score'];
				$best_remote[ $remote_id ] = isset( $best_remote[ $remote_id ] ) ? max( $best_remote[ $remote_id ], $edge['score'] ) : $edge['score'];
			}
			$eligible_local  = array();
			$eligible_remote = array();
			foreach ( $edges as $edge ) {
				$local_id  = $edge['local'];
				$remote_id = $edge['remote'];
				if ( $edge['score'] !== $best_local[ $local_id ] || $edge['score'] !== $best_remote[ $remote_id ] ) {
					continue;
				}
				$eligible_local[ $local_id ][]   = $edge;
				$eligible_remote[ $remote_id ][] = $edge;
			}

			$matches        = array();
			$matched_local  = array();
			$matched_remote = array();
			foreach ( $eligible_local as $local_id => $local_edges ) {
				if ( 1 !== count( $local_edges ) ) {
					continue;
				}
				$edge      = reset( $local_edges );
				$remote_id = $edge['remote'];
				if ( empty( $eligible_remote[ $remote_id ] ) || 1 !== count( $eligible_remote[ $remote_id ] ) ) {
					continue;
				}
				$local  = $local_variations[ $local_id ];
				$remote = $remote_variations[ $remote_id ];
				$matches[] = array(
					'local_code'   => (string) $local['reference'],
					'remote_code'  => (string) $remote['reference'],
					'local_label'  => self::label( $local, '#' . ( $local_id + 1 ) ),
					'remote_label' => self::label( $remote, '#' . ( $remote_id + 1 ) ),
					'score'        => (int) $edge['score'],
					'_local_index' => (int) $local_id,
				);
				$matched_local[ $local_id ]   = true;
				$matched_remote[ $remote_id ] = true;
			}
			usort( $matches, function( $a, $b ) {
				return $a['_local_index'] <=> $b['_local_index'];
			} );
			foreach ( $matches as &$match ) {
				unset( $match['_local_index'] );
			}
			unset( $match );

			$ambiguous = array();
			$unmatched = array();
			foreach ( $local_variations as $index => $variation ) {
				if ( isset( $matched_local[ $index ] ) ) {
					continue;
				}
				$item = array( 'shop' => 'tisacase', 'label' => self::label( $variation, '#' . ( $index + 1 ) ) );
				if ( isset( $duplicate_local[ $index ] ) || isset( $possible_local[ $index ] ) ) {
					$ambiguous[] = $item;
				} else {
					$unmatched[] = $item;
				}
			}
			foreach ( $remote_variations as $index => $variation ) {
				if ( isset( $matched_remote[ $index ] ) ) {
					continue;
				}
				$item = array( 'shop' => 'ariocase', 'label' => self::label( $variation, '#' . ( $index + 1 ) ) );
				if ( isset( $duplicate_remote[ $index ] ) || isset( $possible_remote[ $index ] ) ) {
					$ambiguous[] = $item;
				} else {
					$unmatched[] = $item;
				}
			}
			return array( 'matches' => $matches, 'ambiguous' => $ambiguous, 'unmatched' => $unmatched );
		}
	}
}
