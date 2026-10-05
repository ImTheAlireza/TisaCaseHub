<?php
/**
 * متن در PDF: تجزیهٔ دوجهتهٔ سادهشده، شکل‌دهی حروف فارسی/عربی (init · medi · fina ·
 * لیگاتور لام-الف) و اندازه‌گیری عرض — بدون هیچ وابستگی بیرونی.
 *
 * همهٔ جدول‌ها از خود قلم (Vazirmatn) استخراج شده‌اند و در class-tce-pdf-font-data.php
 * نشسته‌اند؛ این‌جا فقط منطق شکل‌دهی و چیدمان است.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Pdf_Text' ) ) {

	final class TisaCase_Exporter_Pdf_Text {

		/**
		 * متن → گلیف‌های بصری (آمادهٔ نوشتن در PDF).
		 *
		 * ترتیب خروجی «بصری» است: هم ترتیب ران‌ها و هم گلیف‌های هر ران راست‌به‌چپ
		 * برای PDF (که متن را چپ‌به‌راست می‌چیند) برگردانده می‌شوند؛ ران‌های لاتین
		 * مثل ایمیل، شماره و کد ترتیب خودشان را نگه می‌دارند.
		 *
		 * @param string $text        متن خام.
		 * @param array  $font        مشخصات قلم از TisaCase_Exporter_Pdf_Font_Data::FONTS.
		 * @param bool   $default_rtl جهت پایه وقتی متن هیچ حرف «قوی» ندارد.
		 * @return array گلیف‌ها + جهت پایه + عرض کل (واحد em).
		 */
		public static function layout( $text, array $font, $default_rtl = true ) {
			$font  = self::font( $font );
			$chars = self::chars( $text, $font );
			$rtl   = self::base_rtl( $chars, $default_rtl );
			$runs  = self::runs( $chars, $rtl );

			if ( $rtl ) {
				$runs = array_reverse( $runs );
			}

			$glyphs = array();

			foreach ( $runs as $run ) {
				$shaped = $run['rtl'] ? self::shape_rtl( $run['chars'], $font ) : self::plain( $run['chars'], $font );

				if ( $run['rtl'] ) {
					$shaped = self::visual_rtl( $shaped );
				}

				foreach ( $shaped as $glyph ) {
					$glyphs[] = $glyph;
				}
			}

			return array(
				'glyphs'      => $glyphs,
				'rtl'         => $rtl,
				'width'       => self::width( $glyphs, $font ),
				'actual_text' => (string) $text,
			);
		}

		/** عرض متن به واحد em. */
		public static function measure( $text, array $font, $default_rtl = true ) {
			$layout = self::layout( $text, $font, $default_rtl );

			return $layout['width'];
		}

		/** عرض مجموع گلیف‌ها (واحد em). */
		public static function width( array $glyphs, array $font ) {
			$font = self::font( $font );
			$sum  = 0;

			foreach ( $glyphs as $glyph ) {
				$sum += isset( $font['widths'][ $glyph['g'] ] ) ? (float) $font['widths'][ $glyph['g'] ] : 0;
			}

			return $sum;
		}

		/**
		 * شکستن متن به خطوط با عرض حداکثر $max (واحد em).
		 *
		 * @return array خطوط (حداقل یک خط، حتی برای متن خالی).
		 */
		public static function wrap( $text, $max, array $font, $default_rtl = true ) {
			$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );

			if ( '' === $text ) {
				return array( '' );
			}

			$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $words ) || empty( $words ) ) {
				return array( $text );
			}

			$lines = array();
			$line  = '';

			foreach ( $words as $word ) {
				$try = ( '' === $line ) ? $word : $line . ' ' . $word;

				if ( '' === $line || self::measure( $try, $font, $default_rtl ) <= $max ) {
					$line = $try;
					continue;
				}

				$lines[] = $line;
				$line    = $word;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
			}

			/* واژه‌های بلندتر از ستون (ایمیل، آدرس اینترنتی…) به اجبار شکسته می‌شوند. */
			$out = array();

			foreach ( $lines as $item ) {
				if ( self::measure( $item, $font, $default_rtl ) <= $max ) {
					$out[] = $item;
					continue;
				}

				foreach ( self::hard_break( $item, $max, $font, $default_rtl ) as $piece ) {
					$out[] = $piece;
				}
			}

			return empty( $out ) ? array( '' ) : $out;
		}

		/**
		 * عرض‌ها را از واحد فونت به نسبت em تبدیل می‌کند (یک‌بار برای هر قلم).
		 *
		 * بدون این کار «عرض متن» به واحد ۲۰۴۸/em برمی‌گشت و همهٔ واژه‌ها بلندتر از
		 * ستون دیده می‌شدند؛ پرچم `em` جلوی نرمال‌سازی دوباره را می‌گیرد.
		 */
		public static function font( array $spec ) {
			if ( ! empty( $spec['em'] ) || empty( $spec['widths'] ) ) {
				return $spec;
			}

			$upem = isset( $spec['metrics']['unitsPerEm'] ) ? max( 1, (int) $spec['metrics']['unitsPerEm'] ) : 1000;

			foreach ( $spec['widths'] as $gid => $width ) {
				$spec['widths'][ $gid ] = ( (float) $width ) / $upem;
			}

			$spec['em'] = true;

			return $spec;
		}

		/* -----------------------------------------------------------------
		 * تجزیهٔ متن
		 * ----------------------------------------------------------------- */

		/** متن → آرایهٔ نویسه‌ها با نوعشان. */
		private static function chars( $text, array $font ) {
			$text = (string) $text;

			/* نشانه‌های جهت و شکست خط، در PDF فقط فاصله‌اند. */
			$text = str_replace(
				array( "\r", "\n", "\t", "\xE2\x80\x8E", "\xE2\x80\x8F", "\xE2\x80\xAA", "\xE2\x80\xAB" ),
				' ',
				$text
			);

			$units = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $units ) ) {
				$units = array( '?' );
			}

			$out   = array();
			$count = count( $units );

			foreach ( $units as $index => $unit ) {
				$cp = self::cp( $unit );

				if ( $cp < 32 ) {
					$cp = 32;
				}

				$kind = self::kind( $cp );

				if ( 'zwnj' !== $kind && 'zwj' !== $kind && ! isset( $font['codepoints'][ $cp ] ) ) {
					$cp   = isset( $font['codepoints'][ 0x3F ] ) ? 0x3F : 0x20;
					$kind = self::kind( $cp );
				}

				/* پرانتز/کروشه پیش از عدد یا متن لاتین، «لاتین» شمرده می‌شود تا
				   جفت پرانتز دور شماره با خودش بماند و جای‌شان عوض نشود. */
				if ( 'neutral' === $kind && self::is_bracket( $cp ) ) {
					$next = self::next_kind( $units, $index, $font, $count );

					if ( 'num' === $next || 'ltr' === $next ) {
						$kind = 'ltr';
					}
				}

				$out[] = array( 'cp' => $cp, 'k' => $kind );
			}

			return $out;
		}

		/** پرانتز، کروشه، آکولاد و گیومهٔ لاتین. */
		private static function is_bracket( $cp ) {
			return in_array( $cp, array( 0x28, 0x29, 0x5B, 0x5D, 0x7B, 0x7D, 0x3C, 0x3E, 0x22, 0x27 ), true );
		}

		/** آینه‌کردن نشانه‌های جفت در یک ران راست‌به‌چپ. */
		private static function mirror( $cp ) {
			$pairs = array(
				0x28 => 0x29,
				0x29 => 0x28,
				0x5B => 0x5D,
				0x5D => 0x5B,
				0x7B => 0x7D,
				0x7D => 0x7B,
				0x3C => 0x3E,
				0x3E => 0x3C,
				0xAB => 0xBB,
				0xBB => 0xAB,
			);

			return isset( $pairs[ (int) $cp ] ) ? $pairs[ (int) $cp ] : (int) $cp;
		}

		/** نوع نخستین نویسهٔ غیرنشان بعد از $index (برای تشخیص پرانتزِ عدد). */
		private static function next_kind( array $units, $index, array $font, $count ) {
			for ( $i = $index + 1; $i < $count; $i++ ) {
				$cp   = self::cp( $units[ $i ] );
				$kind = self::kind( $cp );

				if ( 'mark' === $kind ) {
					continue;
				}

				return $kind;
			}

			return '';

			unset( $font );
		}

		/** کدپوینت یک نویسهٔ UTF-8 (بدون نیاز به mbstring). */
		private static function cp( $char ) {
			$len = strlen( $char );

			if ( 1 === $len ) {
				return ord( $char );
			}

			if ( 2 === $len ) {
				return ( ( ord( $char[0] ) & 0x1F ) << 6 ) | ( ord( $char[1] ) & 0x3F );
			}

			if ( 3 === $len ) {
				return ( ( ord( $char[0] ) & 0x0F ) << 12 ) | ( ( ord( $char[1] ) & 0x3F ) << 6 ) | ( ord( $char[2] ) & 0x3F );
			}

			if ( 4 === $len ) {
				return ( ( ord( $char[0] ) & 0x07 ) << 18 ) | ( ( ord( $char[1] ) & 0x3F ) << 12 ) | ( ( ord( $char[2] ) & 0x3F ) << 6 ) | ( ord( $char[3] ) & 0x3F );
			}

			return 0x3F;
		}

		/** نویسهٔ UTF-8 از کدپوینت. */
		private static function utf8( $cp ) {
			if ( $cp < 0x80 ) {
				return chr( $cp );
			}

			if ( $cp < 0x800 ) {
				return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
			}

			if ( $cp < 0x10000 ) {
				return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
			}

			return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}

		/** نوع نویسه: rtl | ltr | num | mark | zwnj | zwj | neutral. */
		private static function kind( $cp ) {
			if ( 0x200C === $cp ) {
				return 'zwnj';
			}

			if ( 0x200D === $cp ) {
				return 'zwj';
			}

			if ( self::is_mark( $cp ) ) {
				return 'mark';
			}

			/* ارقام لاتین، عربی و فارسی — همیشه به ترتیب چپ‌به‌راست. */
			if ( ( $cp >= 0x30 && $cp <= 0x39 ) || ( $cp >= 0x660 && $cp <= 0x669 ) || ( $cp >= 0x6F0 && $cp <= 0x6F9 ) ) {
				return 'num';
			}

			if ( ( $cp >= 0x600 && $cp <= 0x8FF ) || ( $cp >= 0xFB50 && $cp <= 0xFDFF ) || ( $cp >= 0xFE70 && $cp <= 0xFEFF ) ) {
				return 'rtl';
			}

			if ( ( $cp >= 0x41 && $cp <= 0x5A ) || ( $cp >= 0x61 && $cp <= 0x7A ) || ( $cp >= 0xC0 && $cp <= 0x24F ) ) {
				return 'ltr';
			}

			return 'neutral';
		}

		/** نشان اعرابی؟ (با کش برای سرعت) */
		private static function is_mark( $cp ) {
			if ( null === self::$marks ) {
				self::$marks = array();

				foreach ( TisaCase_Exporter_Pdf_Font_Data::MARKS as $mark ) {
					self::$marks[ (int) $mark ] = true;
				}
			}

			return isset( self::$marks[ $cp ] );
		}

		/** جهت پایه: نخستین نویسهٔ «قوی» تعیین می‌کند. */
		private static function base_rtl( array $chars, $default_rtl ) {
			foreach ( $chars as $char ) {
				if ( 'rtl' === $char['k'] ) {
					return true;
				}

				if ( 'ltr' === $char['k'] ) {
					return false;
				}
			}

			return (bool) $default_rtl;
		}

		/** تجزیه به ران‌های هم‌جهت (ترتیب منطقی). */
		private static function runs( array $chars, $base_rtl ) {
			$dir  = $base_rtl ? 'rtl' : 'ltr';
			$runs = array();

			foreach ( $chars as $char ) {
				$kind = $char['k'];

				if ( 'rtl' === $kind ) {
					$dir = 'rtl';
				} elseif ( 'ltr' === $kind || 'num' === $kind ) {
					$dir = 'ltr';
				}

				$last = count( $runs ) - 1;

				if ( $last >= 0 && $runs[ $last ]['dir'] === $dir ) {
					$runs[ $last ]['chars'][] = $char;
					continue;
				}

				$runs[] = array( 'dir' => $dir, 'rtl' => ( 'rtl' === $dir ), 'chars' => array( $char ) );
			}

			return $runs;
		}

		/** تبدیل ترتیب منطقی گلیف‌های RTL به ترتیب بصری برای موتور PDF. */
		private static function visual_rtl( array $glyphs ) {
			$clusters = array();

			foreach ( $glyphs as $glyph ) {
				// نشان‌های اعرابی را کنار حرف پایه نگه می‌داریم تا با برگرداندن متن جابه‌جا نشوند.
				if ( ! empty( $glyph['mark'] ) && ! empty( $clusters ) ) {
					$clusters[ count( $clusters ) - 1 ][] = $glyph;
					continue;
				}

				$clusters[] = array( $glyph );
			}

			$out = array();

			foreach ( array_reverse( $clusters ) as $cluster ) {
				foreach ( $cluster as $glyph ) {
					$out[] = $glyph;
				}
			}

			return $out;
		}

		/* -----------------------------------------------------------------
		 * شکل‌دهی
		 * ----------------------------------------------------------------- */

		/** ران لاتین/عددی: بدون شکل‌دهی، ترتیب بدون تغییر. */
		private static function plain( array $chars, array $font ) {
			$out = array();

			foreach ( $chars as $char ) {
				$out[] = self::glyph( self::gid( $char['cp'], $font ), $font );
			}

			return $out;
		}

		/**
		 * ران راست‌به‌چپ: انتخاب فرم هر حرف بر پایهٔ پیوند با همسایه‌ها.
		 *
		 * @return array گلیف‌ها به ترتیب منطقی (برگرداندن به‌عهدهٔ layout است).
		 */
		private static function shape_rtl( array $chars, array $font ) {
			$forms = $font['forms'];
			$out   = array();
			$count = count( $chars );
			$join  = false; // آیا حرف قبلی می‌تواند به بعدی بچسبد؟

			for ( $i = 0; $i < $count; $i++ ) {
				$cp   = (int) $chars[ $i ]['cp'];
				$kind = $chars[ $i ]['k'];

				if ( 'mark' === $kind ) {
					$out[] = array( 'g' => self::gid( $cp, $font ), 'mark' => true );
					continue;
				}

				if ( 'zwnj' === $kind ) {
					$join = false;
					continue;
				}

				if ( 'zwj' === $kind ) {
					continue;
				}

				$row  = isset( $forms[ $cp ] ) ? $forms[ $cp ] : null;
				$next = self::next_letter( $chars, $i );

				/* لام + الف → لیگاتور. */
				if ( null !== $row && 0x0644 === $cp && null !== $next ) {
					$key = $cp . '-' . (int) $next['cp'];

					if ( isset( $font['ligatures'][ $key ] ) ) {
						$pair = $font['ligatures'][ $key ];
						$form = ( $join && $row[1] > 0 ) ? (int) $pair[1] : (int) $pair[0];

						$out[] = self::glyph( $form, $font );
						$join  = false;
						$i     = (int) $next['index'];
						continue;
					}
				}

				if ( null !== $row ) {
					$joins_next = ( $row[2] > 0 || $row[3] > 0 ) && null !== $next && self::accepts_join( (int) $next['cp'], $font );
					$joins_prev = $join && $row[1] > 0;
					$form       = 0;

					if ( $joins_prev && $joins_next && $row[3] > 0 ) {
						$form = (int) $row[3];
					} elseif ( $joins_prev ) {
						$form = $row[1] > 0 ? (int) $row[1] : (int) $row[0];
					} elseif ( $joins_next ) {
						$form = $row[2] > 0 ? (int) $row[2] : (int) $row[0];
					} else {
						$form = (int) $row[0];
					}

					if ( $form <= 0 ) {
						$form = self::gid( $cp, $font );
					}

					$out[] = self::glyph( $form, $font );
					$join  = $joins_next;
					continue;
				}

				/* فاصله/نشانه/عددی که داخل ران راست‌به‌چپ افتاده است. */
				$out[] = self::glyph( self::gid( self::mirror( $cp ), $font ), $font );
				$join  = false;
			}

			return $out;
		}

		/** نخستین نویسهٔ غیرنشان بعد از $i (نیم‌فاصله را رد نمی‌کند). */
		private static function next_letter( array $chars, $i ) {
			$count = count( $chars );

			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( 'mark' === $chars[ $j ]['k'] ) {
					continue;
				}

				return array( 'cp' => (int) $chars[ $j ]['cp'], 'index' => $j );
			}

			return null;
		}

		/** آیا این نویسه می‌تواند از سمت راست به حرف قبلی بچسبد؟ */
		private static function accepts_join( $cp, array $font ) {
			return isset( $font['forms'][ $cp ] ) && $font['forms'][ $cp ][1] > 0;
		}

		/** ساخت یک گلیف. */
		private static function glyph( $gid, array $font ) {
			return array( 'g' => (int) $gid, 'mark' => false );
		}

		/** کدپوینت → GID. */
		private static function gid( $cp, array $font ) {
			return isset( $font['codepoints'][ $cp ] ) ? (int) $font['codepoints'][ $cp ] : 0;
		}

		/** شکستن اجباری یک واژهٔ بلند. */
		private static function hard_break( $text, $max, array $font, $default_rtl ) {
			$chars = self::chars( $text, $font );
			$out   = array();
			$buf   = '';

			foreach ( $chars as $char ) {
				if ( 'zwnj' === $char['k'] || 'zwj' === $char['k'] ) {
					continue;
				}

				$piece = self::utf8( $char['cp'] );
				$try   = $buf . $piece;

				if ( '' !== $buf && self::measure( $try, $font, $default_rtl ) > $max ) {
					$out[] = $buf;
					$buf   = $piece;
					continue;
				}

				$buf = $try;
			}

			if ( '' !== $buf ) {
				$out[] = $buf;
			}

			return empty( $out ) ? array( '' ) : $out;
		}

		/** نشان‌ها یک‌بار ساخته می‌شوند. */
		private static $marks = null;
	}
}
