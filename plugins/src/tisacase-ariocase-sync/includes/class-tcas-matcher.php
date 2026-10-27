<?php
/**
 * تطبیق یک‌به‌یک Variationها با کلیدهای یکتا و بدون حدس در موارد مبهم.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Matcher' ) ) {

	final class TCAS_Matcher {
		const SCORE_SKU        = 100;
		const SCORE_LABEL      = 95;
		const SCORE_TOKEN      = 90;
		const SCORE_FAMILY     = 88;
		const SCORE_LIST       = 84;
		const SCORE_SUGGESTION = 80;

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
				'کرم' => 'cream', 'بژ' => 'beige', 'نیلی' => 'navy', 'طوسی' => 'gray',
				'یشمی' => 'mint', 'زرشکی' => 'maroon', 'ارغوانی' => 'violet',
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

		/** شبکهٔ نسل (4G/5G) از توکن‌های مدل جدا می‌شود تا اشتباه 4G با 5G رخ ندهد. */
		private static function is_net_token( $token ) {
			return in_array( $token, array( '2g', '3g', '4g', '5g', 'lte' ), true );
		}

		/**
		 * رنگ هم مثل نسل شبکه یک «بُعد» جداست، نه بخشی از نام مدل:
		 * «A23 4G / آبی» و «Samsung A23 / آبی» یک مدل با یک رنگ‌اند.
		 */
		private static function is_color_token( $token ) {
			return in_array( $token, array(
				'black', 'white', 'blue', 'lightblue', 'darkblue', 'navy', 'red', 'maroon',
				'green', 'mint', 'teal', 'gold', 'silver', 'gray', 'grey', 'graphite',
				'purple', 'violet', 'pink', 'rose', 'yellow', 'orange', 'brown', 'cream',
				'beige', 'turquoise', 'clear', 'transparent',
			), true );
		}

		/** رنگ‌ها فقط وقتی تناقض ندارند که یکی خالی باشد یا هر دو یکی باشند. */
		private static function colors_compatible( $first, $second ) {
			$first  = (array) $first;
			$second = (array) $second;
			if ( ! $first || ! $second ) {
				return true;
			}
			return $first === $second;
		}

		/**
		 * پروفایل مدل یک Variation: توکن‌های مدل (بدون شبکه)، نسل شبکه، ارقام مدل
		 * و این‌که برچسب چند مدل جایگزین را با هم فهرست کرده است یا نه (A / B / C).
		 */
		private static function profile( $variation ) {
			$raw    = isset( $variation['label'] ) ? (string) $variation['label'] : '';
			$tokens = self::label_tokens( $raw );
			$models = array();
			$nets   = array();
			$digits = array();
			$colors = array();
			foreach ( $tokens as $token ) {
				if ( self::is_net_token( $token ) ) {
					$nets[ $token ] = true;
					continue;
				}
				if ( self::is_color_token( $token ) ) {
					$colors[ $token ] = true;
					continue;
				}
				$models[ $token ] = true;
				if ( preg_match_all( '/[0-9]+/', $token, $found ) ) {
					foreach ( $found[0] as $number ) {
						$digits[ $number ] = true;
					}
				}
			}
			// کلیدهای عددی PHP به int تبدیل می‌شوند؛ برای مقایسه و خروجی یکدست، رشته می‌مانند.
			$models = array_map( 'strval', array_keys( $models ) );
			$nets   = array_map( 'strval', array_keys( $nets ) );
			$digits = array_map( 'strval', array_keys( $digits ) );
			$colors = array_map( 'strval', array_keys( $colors ) );
			sort( $models, SORT_STRING );
			sort( $nets, SORT_STRING );
			sort( $digits, SORT_STRING );
			sort( $colors, SORT_STRING );
			$multi = (bool) preg_match( '#[/+|&،]#u', $raw )
				|| (bool) preg_match( '/(?:^|\s)(?:و|یا|or|and)(?:\s|$)/u', $raw );
			return array(
				'models'    => $models,
				'nets'      => $nets,
				'digits'    => $digits,
				'colors'    => $colors,
				'multi'     => $multi,
				'key'       => implode( '|', $models ),
				'color_key' => implode( '|', $colors ),
			);
		}

		private static function contains_all( $needle, $haystack ) {
			if ( ! $needle ) {
				return false;
			}
			foreach ( $needle as $item ) {
				if ( ! in_array( $item, $haystack, true ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * توکن‌های «معنادار»: هر توکنی که رقم یا نویسهٔ لاتین دارد. واژه‌های تزئینی فارسی
		 * («قاب»، «چرم»، «باک»، «روشن») که در نام Variation تکرار می‌شوند نادیده می‌مانند،
		 * ولی هیچ پسوند مدل لاتینی (pro/ultra/s/5g) حذف نمی‌شود.
		 */
		private static function significant_tokens( $tokens ) {
			$out = array();
			foreach ( (array) $tokens as $token ) {
				$token = (string) $token;
				if ( '' !== $token && preg_match( '/[0-9a-z]/u', $token ) ) {
					$out[] = $token;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * آیا این Variation تک‌مدلی همان مدلِ یک بخش از برچسب همکار است؟
		 * فقط واژه‌های تزئینی فارسی اجازهٔ اختلاف دارند؛ «A52» با «A52 Pro» یکی نیست.
		 */
		private static function model_equivalent( $local_models, $segment ) {
			$local = self::significant_tokens( $local_models );
			$part  = self::significant_tokens( $segment );
			if ( ! $local || ! $part || count( $local ) !== count( $part ) ) {
				return false;
			}
			sort( $local, SORT_STRING );
			sort( $part, SORT_STRING );
			return $local === $part;
		}

		/**
		 * بخش‌های مدل یک برچسب همکار که واقعاً مدل‌اند (رقم دارند):
		 * «Samsung A52 / A52s» => [[a52],[a52s]] ؛ برچسب تک‌مدلی آرایهٔ خالی می‌دهد.
		 */
		public static function bundled_models( $label ) {
			$out = array();
			foreach ( self::model_segments( $label ) as $segment ) {
				$significant = self::significant_tokens( $segment );
				if ( ! $significant ) {
					continue;
				}
				foreach ( $significant as $token ) {
					if ( preg_match( '/[0-9]/u', $token ) ) {
						$out[] = $significant;
						break;
					}
				}
			}
			return $out;
		}

		/** آیا این برچسب به یکی از مدل‌های فهرست‌شده اشاره می‌کند؟ */
		public static function label_matches_models( $label, $models ) {
			$tokens = self::significant_tokens( self::label_tokens( $label ) );
			if ( ! $tokens ) {
				return false;
			}
			foreach ( (array) $models as $model ) {
				if ( $model && count( array_intersect( (array) $model, $tokens ) ) === count( (array) $model ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * نسل شبکه فقط وقتی سازگار است که برابر باشد یا یک طرف هر دو نسل را فهرست کند.
		 * در مسیر «پیشنهاد» نبودِ نسل در یک برچسب، نامشخص حساب می‌شود نه ناسازگار؛
		 * ولی 4G در برابر 5G هرگز سازگار نیست.
		 */
		private static function nets_compatible( $first, $second, $allow_unspecified = false ) {
			if ( $first === $second ) {
				return true;
			}
			if ( $allow_unspecified && ( ! $first || ! $second ) ) {
				return true;
			}
			return self::contains_all( $first, $second ) || self::contains_all( $second, $first );
		}

		/** دست‌کم یک توکن غیرعددی (نام خانواده مثل note/ultra/pro) لازم است. */
		private static function has_family_word( $tokens ) {
			foreach ( (array) $tokens as $token ) {
				if ( ! preg_match( '/^[0-9]+$/u', (string) $token ) ) {
					return true;
				}
			}
			return false;
		}

		private static function profile_ready( $variation, $index, $duplicates ) {
			if ( isset( $duplicates[ $index ] ) ) {
				return false;
			}
			$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
			return '' !== $reference;
		}

		private static function family_entry( $local, $local_id, $remote, $remote_id, $score, $matched_by, $confidence ) {
			return array(
				'local_code'   => (string) $local['reference'],
				'remote_code'  => (string) $remote['reference'],
				'local_label'  => self::label( $local, '#' . ( $local_id + 1 ) ),
				'remote_label' => self::label( $remote, '#' . ( $remote_id + 1 ) ),
				'score'        => (int) $score,
				'matched_by'   => $matched_by,
				'confidence'   => $confidence,
				'local_index'  => (int) $local_id,
				'remote_index' => (int) $remote_id,
				'_local_index' => (int) $local_id,
			);
		}

		/**
		 * پاس «خانوادهٔ مدل»: دو برچسب با مجموعهٔ توکن مدل یکسان و نسل سازگار
		 * (مثل «Redmi Note 11 Pro/12 Pro 4G» و «Xiaomi Note 11 Pro / Note 12 Pro (4G)»
		 * یا «Note 14 Pro 4G» و «Note 14 Pro (4G / 5G)») یک‌به‌یک جفت می‌شوند.
		 */
		/**
		 * پاس «خانوادهٔ مدل»: نگارش‌های مختلف یک مدل را جفت می‌کند.
		 *
		 * رنگ یک بُعد مستقل است، نه بخشی از نام مدل؛ پس «A23 4G / آبی» با
		 * «Samsung A23 / آبی» هم‌مدل است و با «Samsung A23 / صورتی» نه. وقتی هر دو
		 * طرف چند Variation از یک مدل دارند، فقط از روی رنگ جفت می‌شوند و اگر رنگ
		 * تعیین‌کننده نباشد هیچ جفتی ساخته نمی‌شود (مبهم می‌ماند).
		 */
		private static function family_matches( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, &$matched_local, &$matched_remote ) {
			$local_groups  = array();
			$remote_groups = array();
			foreach ( $local_variations as $index => $variation ) {
				if ( isset( $matched_local[ $index ] ) || ! self::profile_ready( $variation, $index, $duplicate_local ) ) {
					continue;
				}
				$profile = self::profile( $variation );
				if ( ! $profile['models'] ) {
					continue;
				}
				$local_groups[ $profile['key'] ][] = array( 'index' => (int) $index, 'profile' => $profile );
			}
			foreach ( $remote_variations as $index => $variation ) {
				if ( isset( $matched_remote[ $index ] ) || ! self::profile_ready( $variation, $index, $duplicate_remote ) ) {
					continue;
				}
				$profile = self::profile( $variation );
				if ( ! $profile['models'] ) {
					continue;
				}
				$remote_groups[ $profile['key'] ][] = array( 'index' => (int) $index, 'profile' => $profile );
			}
			$found = array();
			foreach ( $local_groups as $key => $local_entries ) {
				if ( ! isset( $remote_groups[ $key ] ) ) {
					continue;
				}
				$remote_entries = $remote_groups[ $key ];
				// تک‌به‌تک: نسل نامشخص مانع نیست، ولی رنگ متناقض مانع است.
				if ( 1 === count( $local_entries ) && 1 === count( $remote_entries ) ) {
					$local_entry  = $local_entries[0];
					$remote_entry = $remote_entries[0];
					if ( ! self::colors_compatible( $local_entry['profile']['colors'], $remote_entry['profile']['colors'] ) ) {
						continue;
					}
					if ( ! self::nets_compatible( $local_entry['profile']['nets'], $remote_entry['profile']['nets'], true ) ) {
						continue;
					}
					$found[] = self::family_entry(
						$local_variations[ $local_entry['index'] ], $local_entry['index'],
						$remote_variations[ $remote_entry['index'] ], $remote_entry['index'],
						self::SCORE_FAMILY, 'model', 'family'
					);
					$matched_local[ $local_entry['index'] ]   = true;
					$matched_remote[ $remote_entry['index'] ] = true;
					continue;
				}
				// چندبه‌چند: فقط رنگ تعیین‌کننده است.
				foreach ( self::color_pairs( $local_entries, $remote_entries ) as $pair ) {
					list( $local_pos, $remote_pos ) = $pair;
					$local_entry  = $local_entries[ $local_pos ];
					$remote_entry = $remote_entries[ $remote_pos ];
					$found[]      = self::family_entry(
						$local_variations[ $local_entry['index'] ], $local_entry['index'],
						$remote_variations[ $remote_entry['index'] ], $remote_entry['index'],
						self::SCORE_FAMILY, 'color', 'color'
					);
					$matched_local[ $local_entry['index'] ]   = true;
					$matched_remote[ $remote_entry['index'] ] = true;
				}
			}
			return $found;
		}

		/**
		 * جفت‌کردن گروهی که هر دو طرفش چند Variation هم‌مدل دارد؛ فقط از روی رنگ.
		 * خروجی جفت موقعیت‌ها (position) در همان دو آرایه است.
		 */
		private static function color_pairs( $local_entries, $remote_entries ) {
			$pairs       = array();
			$used_local  = array();
			$used_remote = array();
			foreach ( $local_entries as $local_pos => $local_entry ) {
				$colors = $local_entry['profile']['colors'];
				if ( ! $colors ) {
					continue;
				}
				$candidates = array();
				foreach ( $remote_entries as $remote_pos => $remote_entry ) {
					if ( isset( $used_remote[ $remote_pos ] ) ) {
						continue;
					}
					if ( $remote_entry['profile']['colors'] !== $colors ) {
						continue;
					}
					if ( ! self::nets_compatible( $local_entry['profile']['nets'], $remote_entry['profile']['nets'], true ) ) {
						continue;
					}
					$candidates[] = $remote_pos;
				}
				if ( 1 !== count( $candidates ) ) {
					continue;
				}
				$used_local[ $local_pos ]     = true;
				$used_remote[ $candidates[0] ] = true;
				$pairs[]                       = array( $local_pos, $candidates[0] );
			}
			// اگر از هر طرف دقیقاً یکی ماند و یکی از دو رنگ نداشت، همان‌ها جفت می‌شوند.
			$left_local  = array_values( array_diff( array_keys( $local_entries ), array_keys( $used_local ) ) );
			$left_remote = array_values( array_diff( array_keys( $remote_entries ), array_keys( $used_remote ) ) );
			if ( 1 === count( $left_local ) && 1 === count( $left_remote ) ) {
				$local_profile  = $local_entries[ $left_local[0] ]['profile'];
				$remote_profile = $remote_entries[ $left_remote[0] ]['profile'];
				if ( ( ! $local_profile['colors'] || ! $remote_profile['colors'] )
					&& self::colors_compatible( $local_profile['colors'], $remote_profile['colors'] )
					&& self::nets_compatible( $local_profile['nets'], $remote_profile['nets'], true ) ) {
					$pairs[] = array( $left_local[0], $left_remote[0] );
				}
			}
			return $pairs;
		}

		/**
		 * پاس «پیشنهاد خانوادگی»: وقتی یک طرف چند مدل را با هم فهرست می‌کند
		 * (Note 11 Pro / Note 11s / Note 12s) و طرف دیگر تنها یکی از همان خانواده است
		 * (Note 11 4G)، جفت پیشنهادی ساخته می‌شود؛ فقط اگر انتخاب دوطرفه یکتا باشد.
		 */
		private static function suggestion_pairs( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, &$matched_local, &$matched_remote ) {
			$locals  = array();
			$remotes = array();
			foreach ( $local_variations as $index => $variation ) {
				if ( isset( $matched_local[ $index ] ) || ! self::profile_ready( $variation, $index, $duplicate_local ) ) {
					continue;
				}
				$profile = self::profile( $variation );
				if ( ! $profile['models'] ) {
					continue;
				}
				$locals[ (int) $index ] = $profile;
			}
			foreach ( $remote_variations as $index => $variation ) {
				if ( isset( $matched_remote[ $index ] ) || ! self::profile_ready( $variation, $index, $duplicate_remote ) ) {
					continue;
				}
				$profile = self::profile( $variation );
				if ( ! $profile['models'] ) {
					continue;
				}
				$remotes[ (int) $index ] = $profile;
			}
			$local_candidates  = array();
			$remote_candidates = array();
			foreach ( $locals as $local_id => $local ) {
				foreach ( $remotes as $remote_id => $remote ) {
					$pair = self::family_candidate( $local, $remote );
					if ( ! $pair ) {
						continue;
					}
					$local_candidates[ $local_id ][ $remote_id ]  = $pair;
					$remote_candidates[ $remote_id ][ $local_id ] = $pair;
				}
			}
			$found = array();
			foreach ( $local_candidates as $local_id => $candidates ) {
				if ( 1 !== count( $candidates ) ) {
					continue;
				}
				$remote_id = (int) array_key_first( $candidates );
				if ( empty( $remote_candidates[ $remote_id ] ) || 1 !== count( $remote_candidates[ $remote_id ] ) ) {
					continue;
				}
				$pair   = $candidates[ $remote_id ];
				$entry  = self::family_entry(
					$local_variations[ $local_id ], $local_id,
					$remote_variations[ $remote_id ], $remote_id,
					self::SCORE_SUGGESTION, 'family', 'suggested'
				);
				$entry['shared'] = $pair['shared'];
				$entry['extra']  = $pair['extra'];
				$found[] = $entry;
				$matched_local[ $local_id ]   = true;
				$matched_remote[ $remote_id ] = true;
			}
			return $found;
		}

		/**
		 * بخش‌های مدل یک برچسب چندمدلی: «Samsung A52 / A52s» → [['a52'], ['a52s']].
		 * نام برند و توکن نسل شبکه حذف می‌شود تا فقط مدل واقعی هر بخش بماند.
		 */
		private static function model_segments( $raw ) {
			$raw   = self::unicode_text( $raw );
			$parts = preg_split( '#[/+|&،]|\s+(?:و|یا|or|and)\s+#u', $raw );
			$out   = array();
			foreach ( (array) $parts as $part ) {
				$models = array();
				foreach ( self::label_tokens( $part ) as $token ) {
					if ( self::is_net_token( $token ) || self::is_color_token( $token ) ) {
						continue;
					}
					$models[ $token ] = true;
				}
				$models = array_map( 'strval', array_keys( $models ) );
				sort( $models, SORT_STRING );
				if ( $models ) {
					$out[] = $models;
				}
			}
			return $out;
		}

		/**
		 * پاس «فهرست مدل» (یک‌به‌چند): وقتی یک Variation همکار چند مدل را با هم دارد
		 * («Samsung A52 / A52s») و تیساکیس همان مدل‌ها را جدا دارد، برای هر Variation
		 * تیساکیس یک کارت ساخته می‌شود و همه به همان مرجع همکار وصل می‌شوند.
		 *
		 * هر مدل داخل برچسب همکار باید دقیقاً با مدل یک Variation تیساکیس برابر باشد
		 * (نه زیرمجموعه یا پیشوند)؛ وگرنه «Note 11» به «Note 11 Pro / 11s» وصل می‌شد.
		 * قواعد ایمنی: Variation محلی تک‌مدلیِ دارای شمارهٔ مدل؛ نسل شبکهٔ سازگار؛ هر
		 * Variation محلی فقط با یک برچسب چندمدلی و هر بخش برچسب فقط با یک Variation.
		 */
		private static function list_matches( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, &$matched_local, &$matched_remote ) {
			$locals = array();
			foreach ( $local_variations as $index => $variation ) {
				if ( isset( $matched_local[ $index ] ) || ! self::profile_ready( $variation, $index, $duplicate_local ) ) {
					continue;
				}
				$profile = self::profile( $variation );
				if ( ! $profile['models'] || $profile['multi'] ) {
					continue;
				}
				if ( ! preg_match( '/[0-9]/', implode( '', $profile['models'] ) ) ) {
					continue;
				}
				$locals[ (int) $index ] = $profile;
			}
			if ( ! $locals ) {
				return array();
			}
			$pairs   = array();
			$remotes = array();
			foreach ( $remote_variations as $remote_index => $variation ) {
				if ( isset( $matched_remote[ $remote_index ] ) || ! self::profile_ready( $variation, $remote_index, $duplicate_remote ) ) {
					continue;
				}
				$remote = self::profile( $variation );
				if ( ! $remote['multi'] ) {
					continue;
				}
				$segments = self::model_segments( isset( $variation['label'] ) ? $variation['label'] : '' );
				if ( count( $segments ) < 2 ) {
					continue;
				}
				$claims = array();
				foreach ( $segments as $segment ) {
					$candidates = array();
					foreach ( $locals as $local_index => $local ) {
						if ( ! self::model_equivalent( $local['models'], $segment ) ) {
							continue;
						}
						if ( ! self::nets_compatible( $local['nets'], $remote['nets'], true ) ) {
							continue;
						}
						$candidates[] = (int) $local_index;
					}
					if ( 1 !== count( $candidates ) ) {
						// هیچ Variation هم‌نامی ندارد یا چند Variation با همین مدل وجود دارد.
						continue;
					}
					$claims[ $candidates[0] ][] = $segment;
				}
				if ( ! $claims ) {
					continue;
				}
				$accepted = array();
				foreach ( $claims as $local_index => $segments_found ) {
					// یک Variation که به دو بخش از یک برچسب می‌خورد، مبهم است.
					if ( 1 !== count( $segments_found ) ) {
						continue;
					}
					$accepted[ (int) $local_index ] = $segments_found[0];
					$pairs[ (int) $local_index ][] = (int) $remote_index;
				}
				if ( $accepted ) {
					$remotes[ (int) $remote_index ] = array( 'profile' => $remote, 'locals' => $accepted );
				}
			}
			$found = array();
			foreach ( $pairs as $local_index => $remote_indexes ) {
				if ( 1 !== count( $remote_indexes ) ) {
					// این Variation محلی به بیش از یک برچسب چندمدلی می‌خورد؛ دستی بررسی شود.
					continue;
				}
				$remote_index = (int) $remote_indexes[0];
				$remote       = $remotes[ $remote_index ]['profile'];
				$local        = $locals[ $local_index ];
				$entry        = self::family_entry(
					$local_variations[ $local_index ], $local_index,
					$remote_variations[ $remote_index ], $remote_index,
					self::SCORE_LIST, 'list', 'list'
				);
				$entry['shared']        = self::significant_tokens( $local['models'] );
				$entry['extra']         = array_values( array_diff( self::significant_tokens( $remote['models'] ), $entry['shared'] ) );
				$entry['list_models']   = self::significant_tokens( $remote['models'] );
				$entry['shared_remote'] = true;
				$found[]                = $entry;
				$matched_local[ $local_index ]   = true;
				$matched_remote[ $remote_index ] = true;
			}
			return $found;
		}

		/** آیا این دو پروفایل یک خانوادهٔ مدل با یک طرف چندمدلی هستند؟ */
		private static function family_candidate( $local, $remote ) {
			if ( ! $local['multi'] && ! $remote['multi'] ) {
				return null;
			}
			if ( ! self::nets_compatible( $local['nets'], $remote['nets'], true ) ) {
				return null;
			}
			$pairs = array( array( $local, $remote ), array( $remote, $local ) );
			foreach ( $pairs as $pair ) {
				$small = $pair[0];
				$big   = $pair[1];
				if ( ! $small['multi'] ) {
					// یک توکن تنها (مثل «11») خانوادهٔ مدل نمی‌سازد؛ دست‌کم نام خانواده + شماره لازم است.
					if ( count( $small['models'] ) < 2 || ! self::has_family_word( $small['models'] ) ) {
						continue;
					}
					if ( ! self::contains_all( $small['models'], $big['models'] ) ) {
						continue;
					}
					if ( count( $big['models'] ) <= count( $small['models'] ) ) {
						continue;
					}
					// اشتراک واژه‌های تزئینی مدل نمی‌سازد؛ دست‌کم یک شمارهٔ مدل لازم است.
					if ( ! preg_match( '/[0-9]/', implode( '', $small['models'] ) ) ) {
						continue;
					}
					$extra = array_values( array_diff( $big['models'], $small['models'] ) );
					return array( 'shared' => $small['models'], 'extra' => $extra );
				}
				// هر دو طرف چندمدلی‌اند: مدل‌های یک طرف باید زیرمجموعهٔ دیگری باشد.
				if ( self::contains_all( $small['models'], $big['models'] ) && count( $big['models'] ) > count( $small['models'] ) ) {
					if ( ! preg_match( '/[0-9]/', implode( '', $small['models'] ) ) ) {
						continue;
					}
					return array( 'shared' => $small['models'], 'extra' => array_values( array_diff( $big['models'], $small['models'] ) ) );
				}
			}
			return null;
		}

		/** کاندیدهای خانوادگی یک Variation برای توضیح «چند خانواده» در فهرست بررسی. */
		private static function family_issue_candidates( $others, $other_shop, $self_profile, $limit = 5 ) {
			$found = array();
			foreach ( $others as $index => $other ) {
				$profile = self::profile( $other );
				if ( ! $profile['models'] || ! self::family_candidate( $self_profile, $profile ) ) {
					continue;
				}
				$found[] = array(
					'shop'      => $other_shop,
					'index'     => (int) $index,
					'reference' => isset( $other['reference'] ) ? (string) $other['reference'] : '',
					'label'     => self::label( $other, '#' . ( $index + 1 ) ),
					'score'     => self::SCORE_SUGGESTION,
					'key'       => 'family',
					'usable'    => '' !== ( isset( $other['reference'] ) ? trim( (string) $other['reference'] ) : '' ),
				);
				if ( count( $found ) >= $limit ) {
					break;
				}
			}
			return $found;
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
		private static function issue( $shop, $variations, $index, $other_shop, $other_variations, $other_index, $other_duplicates, $counts, $possible, $family = array() ) {
			$variation = $variations[ $index ];
			$reference = isset( $variation['reference'] ) ? trim( (string) $variation['reference'] ) : '';
			$duplicate = '' !== $reference && isset( $counts[ $reference ] ) && $counts[ $reference ] > 1;
			$found     = self::issue_candidates( $variation, $other_shop, $other_variations, $other_index, $other_duplicates );
			$family = array_values( (array) $family );
			if ( '' === $reference ) {
				$reason = 'missing_reference';
			} elseif ( $duplicate ) {
				$reason = 'duplicate_reference';
			} elseif ( count( $family ) > 1 ) {
				$reason = 'multiple_families';
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
		if ( 'multiple_families' === $reason ) {
			return array(
				'shop'            => $shop,
				'index'           => (int) $index,
				'reference'       => $reference,
				'label'           => self::label( $variation, '#' . ( $index + 1 ) ),
				'reason'          => $reason,
				'duplicate_count' => 1,
				'twins'           => array(),
				'candidate_count' => count( $family ),
				'usable_count'    => count( array_filter( $family, function( $candidate ) { return ! empty( $candidate['usable'] ); } ) ),
				'candidates'      => $family,
			);
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

			// پاس دوم: خانوادهٔ مدل (برچسب‌های هم‌معنی با نگارش متفاوت).
			$family_matches = array();
			foreach ( self::family_matches( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, $matched_local, $matched_remote ) as $entry ) {
				$family_matches[] = $entry;
			}
			// پاس سوم: فهرست مدل (یک Variation همکار ↔ چند Variation تیساکیس با همان مدل‌ها).
			$list_matches = array();
			foreach ( self::list_matches( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, $matched_local, $matched_remote ) as $entry ) {
				$list_matches[] = $entry;
			}
			// پاس چهارم: پیشنهاد خانوادگی برای برچسب‌های چندمدلی (پس از مصرف پاس‌های قبلی).
			$suggested = array();
			foreach ( self::suggestion_pairs( $local_variations, $remote_variations, $duplicate_local, $duplicate_remote, $matched_local, $matched_remote ) as $entry ) {
				$suggested[] = $entry;
			}
			$matches = array_merge( $matches, $family_matches, $list_matches, $suggested );
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
				$item = self::issue( 'tisacase', $local_variations, $index, 'ariocase', $remote_variations, $remote_diagnostic, $duplicate_remote, $local_counts, $possible_local, self::family_issue_candidates( $remote_variations, 'ariocase', self::profile( $local_variations[ $index ] ) ) );
				if ( isset( $duplicate_local[ $index ] ) || isset( $possible_local[ $index ] ) || 'multiple_families' === $item['reason'] ) {
					$ambiguous[] = $item;
				} else {
					$unmatched[] = $item;
				}
			}
			foreach ( $remote_variations as $index => $variation ) {
				if ( isset( $matched_remote[ $index ] ) ) {
					continue;
				}
				$item = self::issue( 'ariocase', $remote_variations, $index, 'tisacase', $local_variations, $local_diagnostic, $duplicate_local, $remote_counts, $possible_remote, self::family_issue_candidates( $local_variations, 'tisacase', self::profile( $remote_variations[ $index ] ) ) );
				if ( isset( $duplicate_remote[ $index ] ) || isset( $possible_remote[ $index ] ) || 'multiple_families' === $item['reason'] ) {
					$ambiguous[] = $item;
				} else {
					$unmatched[] = $item;
				}
			}
			$exact     = 0;
			$fuzzy     = 0;
			$family    = 0;
			$list      = 0;
			$suggested_count = 0;
			foreach ( $matches as $match ) {
				if ( 'suggested' === $match['confidence'] ) {
					$suggested_count++;
				} elseif ( 'list' === $match['confidence'] ) {
					$list++;
				} elseif ( 'family' === $match['confidence'] ) {
					$family++;
				} elseif ( 'exact' === $match['confidence'] ) {
					$exact++;
				} else {
					$fuzzy++;
				}
			}
			return array(
				'matches'   => $matches,
				'ambiguous' => $ambiguous,
				'unmatched' => $unmatched,
				'summary'   => array( 'exact' => $exact, 'fuzzy' => $fuzzy, 'family' => $family, 'list' => $list, 'suggested' => $suggested_count ),
			);
		}
	}
}
