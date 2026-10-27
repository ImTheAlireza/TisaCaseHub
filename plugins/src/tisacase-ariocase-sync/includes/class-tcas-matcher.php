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
			$value = (string) preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );
			$value = str_replace( array( 'سرمه ای', 'سرمه‌ی', 'سرمه‌ای', 'نقره ای', 'نقره‌ی', 'نقره‌ای', 'قهوه ای', 'قهوه‌ی', 'قهوه‌ای', 'ابی روشن', 'ابی تیره', 'نسل پنجم', 'نسل چهارم', 'فیروزه ای', 'فیروزه‌ای', 'شفاف', "‌", 'ـ' ), array( 'navy', 'navy', 'navy', 'silver', 'silver', 'silver', 'brown', 'brown', 'brown', 'lightblue', 'navy', '5g', '4g', 'turquoise', 'turquoise', 'clear', ' ', '' ), $value );
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
				'xiaomi' => true, 'redmi' => true, 'poco' => true, 'pocophone' => true, 'mi' => true,
				'phone' => true, 'mobile' => true,
				'smartphone' => true, 'product' => true, 'model' => true, 'color' => true,
				'colour' => true, 'size' => true, 'storage' => true, 'memory' => true,
				'version' => true, 'edition' => true, 'variant' => true, 'lte' => true, 'gsm' => true,
				'case' => true, 'cover' => true, 'for' => true, 'compatible' => true, 'with' => true,
				'سامسونگ' => true, 'گلکسی' => true, 'اپل' => true, 'ایفون' => true,
				'شیایومی' => true, 'ردمی' => true, 'پوکو' => true, 'گوشی' => true, 'موبایل' => true,
				'محصول' => true, 'مدل' => true, 'رنگ' => true, 'سایز' => true,
				'اندازه' => true, 'حافظه' => true, 'ظرفیت' => true, 'سری' => true,
				'نسخه' => true, 'ورژن' => true, 'مدل' => true, 'تنوع' => true,
				'قاب' => true, 'مناسب' => true, 'سازگار' => true, 'با' => true,
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
				$token = (string) preg_replace( '/^(?:samsung|apple|iphone|xiaomi|redmi|pocophone|poco)(?=[a-z]*\d)/u', '', $token );
				$token = (string) preg_replace( '/^mi(?=\d)/u', '', $token );
				// Keep phone-family model names stable when one catalog pads the number (A04s/A4s).
				$token = (string) preg_replace( '/^([a-z]+)0+([0-9]+[a-z]*)$/u', '$1$2', $token );
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

		private static function duplicate_references( $variations, $counts = null ) {
			$counts     = is_array( $counts ) ? $counts : self::reference_counts( $variations );
			$duplicates = array();
			foreach ( array_values( (array) $variations ) as $index => $variation ) {
				$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
				if ( '' !== $reference && isset( $counts[ $reference ] ) && $counts[ $reference ] > 1 ) {
					$duplicates[ $index ] = true;
				}
			}
			return $duplicates;
		}

		private static function index( $variations, $duplicates, $include_duplicates = false ) {
			$out = array();
			foreach ( array_values( (array) $variations ) as $index => $variation ) {
				if ( ! $include_duplicates && isset( $duplicates[ $index ] ) ) {
					continue;
				}
				foreach ( self::keys( $variation ) as $key => $score ) {
					$out[ $key ][ $index ] = $score;
				}
			}
			return $out;
		}

		/** نوع کلیدی که تطبیق را ساخته است؛ همان امتیازهای ثابت کلاس. */
		private static function key_type( $score ) {
			$score = (int) $score;
			if ( self::SCORE_SKU === $score ) {
				return 'sku';
			}
			return self::SCORE_LABEL === $score ? 'label' : 'tokens';
		}

		/** تعداد Variationهایی که همین مرجع را در همین فروشگاه تکرار کرده‌اند. */
		private static function reference_counts( $variations ) {
			$counts = array();
			foreach ( array_values( (array) $variations ) as $variation ) {
				$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
				if ( '' !== $reference ) {
					$counts[ $reference ] = isset( $counts[ $reference ] ) ? $counts[ $reference ] + 1 : 1;
				}
			}
			return $counts;
		}

		/** همتاهای همان مرجع در همان فروشگاه، برای نشان‌دادن منبع ابهام. */
		private static function reference_twins( $variations, $index, $limit = 3 ) {
			$self  = isset( $variations[ $index ] ) ? $variations[ $index ] : array();
			$mine  = isset( $self['reference'] ) ? trim( (string) $self['reference'] ) : '';
			$twins = array();
			if ( '' === $mine ) {
				return $twins;
			}
			foreach ( array_values( (array) $variations ) as $other_index => $variation ) {
				if ( $other_index === $index || ! isset( $variation['reference'] ) || $mine !== trim( (string) $variation['reference'] ) ) {
					continue;
				}
				$twins[] = array(
					'index'     => (int) $other_index,
					'reference' => $mine,
					'label'     => self::label( $variation, '#' . ( $other_index + 1 ) ),
				);
				if ( count( $twins ) >= $limit ) {
					break;
				}
			}
			return $twins;
		}

		/**
		 * همتاهای محتمل یک Variation در سمت مقابل، مستقل از یکتایی.
		 * فقط برای شفاف‌سازی دلیل رد شدن است و هرگز به جفت تبدیل نمی‌شود.
		 */
		private static function issue_candidates( $variation, $other_shop, $other_variations, $other_index, $other_duplicates, $limit = 5 ) {
			$found = array();
			foreach ( self::keys( $variation ) as $key => $score ) {
				if ( empty( $other_index[ $key ] ) ) {
					continue;
				}
				foreach ( $other_index[ $key ] as $other_id => $other_score ) {
					$other_id = (int) $other_id;
					if ( ! isset( $other_variations[ $other_id ] ) ) {
						continue;
					}
					$best = max( (int) $score, (int) $other_score );
					if ( isset( $found[ $other_id ] ) && $found[ $other_id ]['score'] >= $best ) {
						continue;
					}
					$other_reference = isset( $other_variations[ $other_id ]['reference'] ) ? trim( (string) $other_variations[ $other_id ]['reference'] ) : '';
					$found[ $other_id ] = array(
						'shop'      => $other_shop,
						'index'     => $other_id,
						'reference' => $other_reference,
						'label'     => self::label( $other_variations[ $other_id ], '#' . ( $other_id + 1 ) ),
						'score'     => $best,
						'key'       => self::key_type( $best ),
						'usable'    => '' !== $other_reference && ! isset( $other_duplicates[ $other_id ] ),
					);
				}
			}
			$candidates = array_values( $found );
			usort( $candidates, function( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $b['score'] <=> $a['score'];
			} );
			$usable = 0;
			foreach ( $candidates as $candidate ) {
				if ( ! empty( $candidate['usable'] ) ) {
					$usable++;
				}
			}
			return array( 'total' => count( $candidates ), 'usable' => $usable, 'items' => array_slice( $candidates, 0, $limit ) );
		}

		/** ساخت یک ردیف «نیازمند بررسی» با دلیل مشخص و شواهد قابل‌دیدن. */
		private static function issue( $shop, $variations, $index, $other_shop, $other_variations, $other_index, $other_duplicates, $counts, $possible ) {
			$variation = $variations[ $index ];
			$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
			$duplicate = '' !== $reference && isset( $counts[ $reference ] ) && $counts[ $reference ] > 1;
			$found     = self::issue_candidates( $variation, $other_shop, $other_variations, $other_index, $other_duplicates );
			if ( '' === $reference ) {
				$reason = 'missing_reference';
			} elseif ( $duplicate ) {
				$reason = 'duplicate_reference';
			} elseif ( 0 === $found['total'] ) {
				$reason = 'no_candidate';
			} elseif ( 0 === $found['usable'] ) {
				$reason = 'counterpart_unusable';
			} elseif ( $found['usable'] > 1 ) {
				$reason = 'multiple_candidates';
			} elseif ( isset( $possible[ $index ] ) ) {
				$reason = 'shared_key';
			} else {
				$reason = 'counterpart_unusable';
			}
			return array(
				'shop'            => $shop,
				'index'           => (int) $index,
				'reference'       => $reference,
				'label'           => self::label( $variation, '#' . ( $index + 1 ) ),
				'reason'          => $reason,
				'duplicate_count' => $duplicate ? (int) $counts[ $reference ] : 1,
				'twins'           => $duplicate ? self::reference_twins( $variations, $index ) : array(),
				'candidate_count' => (int) $found['total'],
				'usable_count'    => (int) $found['usable'],
				'candidates'      => $found['items'],
			);
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
			$local_diagnostic  = self::index( $local_variations, $duplicate_local, true );
			$remote_diagnostic = self::index( $remote_variations, $duplicate_remote, true );
			$local_counts      = self::reference_counts( $local_variations );
			$remote_counts     = self::reference_counts( $remote_variations );
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
					'matched_by'   => self::key_type( $edge['score'] ),
					'confidence'   => self::SCORE_SKU === (int) $edge['score'] ? 'exact' : 'fuzzy',
					'local_index'  => (int) $local_id,
					'remote_index' => (int) $remote_id,
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
				$item = self::issue( 'tisacase', $local_variations, $index, 'ariocase', $remote_variations, $remote_diagnostic, $duplicate_remote, $local_counts, $possible_local );
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
				$item = self::issue( 'ariocase', $remote_variations, $index, 'tisacase', $local_variations, $local_diagnostic, $duplicate_local, $remote_counts, $possible_remote );
				if ( isset( $duplicate_remote[ $index ] ) || isset( $possible_remote[ $index ] ) ) {
					$ambiguous[] = $item;
				} else {
					$unmatched[] = $item;
				}
			}
			$exact  = 0;
			$fuzzy  = 0;
			foreach ( $matches as $match ) {
				if ( 'exact' === $match['confidence'] ) {
					$exact++;
				} else {
					$fuzzy++;
				}
			}
			return array(
				'matches'   => $matches,
				'ambiguous' => $ambiguous,
				'unmatched' => $unmatched,
				'summary'   => array( 'exact' => $exact, 'fuzzy' => $fuzzy ),
			);
		}
	}
}
