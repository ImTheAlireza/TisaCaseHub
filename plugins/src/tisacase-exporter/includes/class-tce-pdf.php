<?php
/**
 * قالب PDF: چیدمان جدولی راست‌به‌چپ با قلم جاسازی‌شدهٔ وزیرمتن — بدون وابستگی بیرونی.
 *
 * قلم‌ها زیرمجموعهٔ Vazirmatn (OFL-1.1) هستند و همراه افزونه توزیع می‌شوند؛ شکل‌دهی
 * فارسی هم با جدول‌های استخراج‌شده از همان قلم انجام می‌شود (class-tce-pdf-text.php).
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Pdf' ) ) {

	final class TisaCase_Exporter_Pdf {

		/* -----------------------------------------------------------------
		 * طرح صفحه (هم‌رنگ با رابط افزونه)
		 * ----------------------------------------------------------------- */

		private static $ink         = array( 0.122, 0.165, 0.180 ); // #1F2A2E
		private static $muted       = array( 0.376, 0.408, 0.435 ); // #60686F
		private static $line_color  = array( 0.890, 0.882, 0.855 ); // #E3E1DA
		private static $zebra       = array( 0.969, 0.965, 0.953 ); // #F7F6F3
		private static $accent      = array( 0.055, 0.486, 0.420 ); // #0E7C6B
		private static $accent_dark = array( 0.039, 0.373, 0.322 ); // #0A5F52
		private static $accent_soft = array( 0.914, 0.953, 0.945 ); // #E9F3F1
		private static $white       = array( 1.0, 1.0, 1.0 );

		/** ارتفاع خط سطر جدول (pt) و حاشیه‌ها. */
		private static $line_h  = 12.9;
		private static $head_lh = 11.6;
		private static $size    = 8.4;
		private static $head_size = 8.6;
		private static $mx     = 32.0;
		private static $mb     = 46.0;
		private static $pad_x  = 5.0;
		private static $pad_y  = 3.4;

		/* -----------------------------------------------------------------
		 * وضعیت
		 * ----------------------------------------------------------------- */

		private static $writer    = null;
		private static $meta      = array();
		private static $cols      = array();
		private static $fonts     = array();
		private static $font_pt   = array();
		private static $degraded  = false;
		private static $kids      = array();
		private static $buf       = '';
		private static $y         = 0.0;
		private static $pwidth    = 595.28;
		private static $pheight   = 841.89;
		private static $content   = 0.0;
		private static $rows      = 0;
		private static $page      = 0;
		private static $page_top  = 0.0;
		private static $page_head = 0.0;
		private static $sampling  = false;
		private static $sample    = array();
		private static $open      = false;
		private static $obj_pages   = 0;
		private static $obj_catalog = 0;
		private static $obj_info    = 0;

		/** چند ردیف اول برای اندازه‌گیری عرض ستون‌ها نگه داشته می‌شود. */
		const SAMPLE_ROWS = 40;

		/** قلم‌ها موجودند؟ */
		public static function supported() {
			return '' !== self::font_file( self::spec( 'regular' ) );
		}

		/* -----------------------------------------------------------------
		 * واسط قالب
		 * ----------------------------------------------------------------- */

		/**
		 * آغاز ساخت PDF.
		 *
		 * @param resource $handle مقصد.
		 * @param array    $labels برچسب ستون‌ها.
		 * @param array    $keys   کلید لاتین ستون‌ها (در PDF استفاده نمی‌شود).
		 * @param array    $meta   عنوان · سایت · تاریخ · تعریف ستون‌ها.
		 */
		public static function open( $handle, array $labels, array $keys = array(), array $meta = array() ) {
			self::reset();
			self::$writer = new TisaCase_Exporter_Pdf_Writer( $handle );
			self::$meta   = $meta;
			self::$writer->begin();

			self::$obj_pages   = self::$writer->reserve();
			self::$obj_catalog = self::$writer->reserve();
			self::$obj_info    = self::$writer->reserve();

			if ( ! self::supported() ) {
				self::degraded_open();
				return;
			}

			self::embed( 'regular' );
			self::embed( 'bold' );

			self::$font_pt = isset( self::$fonts['regular']['spec'] ) ? self::$fonts['regular']['spec'] : array();

			$defs = isset( $meta['columns'] ) && is_array( $meta['columns'] ) ? $meta['columns'] : array();
			$list = self::label_list( $labels );
			$wide = count( $list ) > 1;

			/* جهت کاغذ باید *پیش از* محاسبهٔ ستون‌ها معلوم باشد؛ وگرنه عرض ستون‌ها
			   با عرض اشتباه حساب می‌شود و متن بیرون صفحه می‌افتد. */
			self::$pwidth  = $wide ? 841.89 : 595.28;
			self::$pheight = $wide ? 595.28 : 841.89;
			self::$content = self::$pwidth - ( 2 * self::$mx );

			self::$cols = self::columns( $list, $defs );

			/* عرض ستون‌ها از «خودِ داده» درمی‌آید نه از طول عنوان؛ پس چند ردیف اول
			   را نگه می‌داریم تا ستون‌ها را اندازه‌گیری کنیم (class-tce-pdf::fit). */
			self::$sampling = true;
			self::$sample   = array();

			unset( $keys );
		}

		/** افزودن یک ردیف به جدول (تا کامل‌شدن نمونهٔ اندازه‌گیری، بافر می‌شود). */
		public static function row( array $values ) {
			if ( self::$degraded || null === self::$writer || empty( self::$cols ) ) {
				return;
			}

			if ( self::$sampling ) {
				self::$sample[] = $values;

				if ( count( self::$sample ) >= self::SAMPLE_ROWS ) {
					self::start_table();
				}

				return;
			}

			self::write_row( $values );
		}

		/** تعیین عرض ستون‌ها از روی نمونه، سپس نوشتن ردیف‌های بافرشده. */
		private static function start_table() {
			self::fit_columns( self::$sample );
			self::place_columns();
			self::new_page();

			self::$sampling = false;
			$buffered       = self::$sample;
			self::$sample   = array();

			foreach ( $buffered as $values ) {
				self::write_row( $values );
			}
		}

		/**
		 * نوشتن واقعی یک ردیف.
		 *
		 * سقف خطِ هر ستون فقط در اندازه‌گیری عرض اثر دارد؛ خودِ داده هرگز بریده
		 * نمی‌شود و ردیف بلندتر از یک صفحه به‌جای سرریز، بین صفحه‌ها ادامه می‌یابد.
		 */
		private static function write_row( array $values ) {
			self::$rows++;
			$lines = array();
			$total = 0;

			foreach ( self::$cols as $i => $col ) {
				$text = isset( $values[ $i ] ) ? (string) $values[ $i ] : '';
				$max  = ( $col['w'] - ( 2 * self::$pad_x ) ) / self::$size;
				$item = TisaCase_Exporter_Pdf_Text::wrap( $text, $max, self::$font_pt, $col['rtl'] );

				/* هیچ متنی کوتاه نمی‌شود: این خروجی برای چاپ و بایگانی است و نباید
				   بخشی از نشانی/یادداشت از قلم بیفتد. سقف خط فقط برای «اندازه‌گیری
				   عرض ستون» به کار می‌رود، نه برای بریدن داده. */
				$lines[ $i ] = $item;
				$total       = max( $total, count( $item ) );
			}

			$drawn = 0;

			while ( $drawn < $total ) {
				$fit = (int) floor( ( self::$y - self::$mb - ( 2 * self::$pad_y ) ) / self::$line_h );

				if ( $fit < 1 ) {
					self::new_page();
					continue;
				}

				$take   = min( $fit, $total - $drawn );
				$seg_h  = ( $take * self::$line_h ) + ( 2 * self::$pad_y );
				$top    = self::$y;
				$bottom = $top - $seg_h;

				if ( 0 === self::$rows % 2 ) {
					self::rect( self::$mx, $bottom, self::$content, $seg_h, self::$zebra, null );
				}

				$block = '';

				foreach ( self::$cols as $i => $col ) {
					$y = $top - self::$pad_y - ( self::$size * 0.86 );

					for ( $k = $drawn; $k < ( $drawn + $take ); $k++ ) {
						$line = isset( $lines[ $i ][ $k ] ) ? (string) $lines[ $i ][ $k ] : '';

						if ( '' !== trim( $line ) ) {
							$layout = TisaCase_Exporter_Pdf_Text::layout( $line, self::$font_pt, $col['rtl'] );
							$x      = ( 'left' === $col['align'] )
								? ( $col['x'] + self::$pad_x )
								: ( $col['x'] + $col['w'] - self::$pad_x - ( $layout['width'] * self::$size ) );

							$block .= self::inline_text( $x, $y, $layout['glyphs'], self::$size, $layout['actual_text'] );
						}

						$y -= self::$line_h;
					}
				}

				if ( '' !== $block ) {
					self::$buf .= sprintf(
						"q %.3F %.3F %.3F rg %sQ\n",
						self::$ink[0],
						self::$ink[1],
						self::$ink[2],
						$block
					);
				}

				self::line( self::$mx, $bottom, self::$mx + self::$content, $bottom, self::$line_color, 0.35 );
				self::$y = $bottom;
				$drawn  += $take;

				if ( $drawn < $total ) {
					self::new_page();
				}
			}
		}

		/** پایان و نوشتن ساختار فایل. */
		public static function close() {
			if ( null === self::$writer ) {
				return;
			}

			if ( self::$degraded ) {
				self::degraded_close();
				self::$writer = null;
				return;
			}

			if ( self::$sampling ) {
				self::start_table(); // جدول بدون ردیف هم باید سرستون بگیرد.
			}

			self::end_page( true );

			$kids = array();

			foreach ( self::$kids as $kid ) {
				$kids[] = (int) $kid . ' 0 R';
			}

			self::$writer->emit(
				self::$obj_pages,
				'<< /Type /Pages /Kids [ ' . implode( ' ', $kids ) . ' ] /Count ' . count( self::$kids ) . ' >>'
			);
			self::$writer->emit( self::$obj_catalog, '<< /Type /Catalog /Pages ' . self::$obj_pages . ' 0 R >>' );

			$title = ( '' !== (string) self::meta_value( 'title' ) ) ? (string) self::meta_value( 'title' ) : __( 'مرکز خروجی تیساکیس', TisaCase_Exporter::TEXT_DOMAIN );

			self::$writer->emit(
				self::$obj_info,
				sprintf(
					'<< /Title <%s> /Producer <%s> /Creator <%s> /CreationDate (%s) >>',
					self::text_hex( $title ),
					self::text_hex( 'مرکز خروجی تیساکیس' ),
					self::text_hex( 'TisaCase Exporter' ),
					self::pdf_date()
				)
			);

			self::$writer->finish( self::$obj_catalog, self::$obj_info );
			self::$writer = null;
		}

		/* -----------------------------------------------------------------
		 * صفحه و جدول
		 * ----------------------------------------------------------------- */

		/** فهرست برچسب ستون‌ها (حتی وقتی خالی است، یک ستون «مقدار»). */
		private static function label_list( array $labels ) {
			$labels = array_values( $labels );

			if ( empty( $labels ) ) {
				$labels = array( __( 'مقدار', TisaCase_Exporter::TEXT_DOMAIN ) );
			}

			return $labels;
		}

		/** تعریف ستون‌ها: اندازه، سقف خط، تراز و جهت (عرض در fit_columns می‌آید). */
		private static function columns( array $labels, array $defs ) {
			$count = count( $labels );

			self::$size      = self::table_size( $count );
			self::$line_h    = self::$size * 1.55;
			self::$head_lh   = self::$size * 1.42;
			self::$head_size = self::$size * 1.04;

			/* ستون‌های کوتاه (عدد، تاریخ، وضعیت…) نباید بشکنند؛ ستون‌های متنی
			   حداکثر چند خط می‌گیرند تا ردیف‌ها کوتاه و جدول مرتب بماند. */
			$caps = array(
				'text'        => 3,
				'code'        => 2,
				'date'        => 1,
				'phone'       => 1,
				'num'         => 1,
				'money'       => 1,
				'status'      => 1,
				'stock'       => 1,
				'bool'        => 1,
				'coupon_type' => 1,
			);
			$shares = array(
				'text' => 0.42,
				'code' => 0.30,
			);

			$cols = array();

			foreach ( array_values( $labels ) as $i => $label ) {
				$def  = isset( $defs[ $i ] ) && is_array( $defs[ $i ] ) ? $defs[ $i ] : array();
				$type = isset( $def['type'] ) ? (string) $def['type'] : 'text';
				$rtl  = ! in_array( $type, array( 'num', 'money', 'date', 'phone', 'code', 'stock', 'coupon_type' ), true );

				$cols[] = array(
					'label'   => (string) $label,
					'type'    => $type,
					'rtl'     => $rtl,
					'align'   => 'right',
					'cap'     => isset( $caps[ $type ] ) ? $caps[ $type ] : 2,
					'share'   => isset( $shares[ $type ] ) ? $shares[ $type ] : 0.24,
					'need'    => 0.0,
					'w'       => 0.0,
					'x'       => self::$mx,
					'slot'    => 0,
				);
			}

			$even = self::$content / max( 1, $count );

			foreach ( $cols as $i => $col ) {
				$head = TisaCase_Exporter_Pdf_Text::layout( (string) $col['label'], self::bold_spec(), $col['rtl'] );

				$cols[ $i ]['need'] = max( ( $head['width'] * self::$head_size ) + ( 2 * self::$pad_x ) + 4, 34.0 );
				$cols[ $i ]['min']  = $cols[ $i ]['need'];
				$cols[ $i ]['max']  = max( 40.0, self::$content * (float) $col['share'] );
				$cols[ $i ]['w']    = $even;
			}

			return $cols;
		}

		/** اندازهٔ قلم جدول بر اساس تعداد ستون (جدول پهن‌تر = قلم کوچک‌تر). */
		private static function table_size( $count ) {
			if ( $count <= 1 ) {
				return 9.6;
			}

			if ( $count <= 3 ) {
				return 9.2;
			}

			if ( $count <= 6 ) {
				return 8.8;
			}

			if ( $count <= 9 ) {
				return 8.2;
			}

			if ( $count <= 12 ) {
				return 7.7;
			}

			return 7.2;
		}

		/**
		 * عرض ستون‌ها را از روی «نمونهٔ داده» تعیین می‌کند.
		 *
		 * برای هر ستون پهن‌ترین مقدار نمونه (و عنوان) اندازه‌گیری می‌شود و بعد عرض
		 * با یک تخصیص ساده (کفِ خوانا، سقفِ سهم، و پخش مانده به ستون‌های متنی) در
		 * کل عرض صفحه جا داده می‌شود؛ ستون‌های عددی/تاریخ کفِ خودشان را می‌گیرند تا
		 * هرگز روی ستون بغل نیفتند.
		 */
		private static function fit_columns( array $samples ) {
			$count = count( self::$cols );

			if ( 0 === $count ) {
				return;
			}

			foreach ( self::$cols as $i => $col ) {
				$single = (float) $col['need'];
				$need   = (float) $col['need'];

				foreach ( $samples as $values ) {
					if ( ! isset( $values[ $i ] ) ) {
						continue;
					}

					$text = trim( (string) $values[ $i ] );

					if ( '' === $text ) {
						continue;
					}

					$layout = TisaCase_Exporter_Pdf_Text::layout( $text, self::$font_pt, $col['rtl'] );

					if ( $layout['width'] <= 0 ) {
						continue;
					}

					$one = ( $layout['width'] * self::$size ) + ( 2 * self::$pad_x ) + 4;

					if ( $one > $single ) {
						$single = $one;
					}

					/* ستون‌های متنی مجازند چند خط شوند، پس با سقف خط تخفیف می‌خورند. */
					$room = max( 1, (int) $col['cap'] );
					$want = ( $layout['width'] * self::$size / $room ) + ( 2 * self::$pad_x ) + 4;

					if ( $want > $need ) {
						$need = $want;
					}
				}

				/* کف: ستون‌های تک‌خطی (عدد، تاریخ، موبایل…) باید در یک خط جا شوند. */
				if ( (int) $col['cap'] <= 1 ) {
					$need = max( $need, $single );
					self::$cols[ $i ]['min'] = min( $single, (float) $col['max'] );
				} else {
					self::$cols[ $i ]['min'] = min( max( $need, self::$cols[ $i ]['min'] ), (float) $col['max'] );
				}

				self::$cols[ $i ]['need'] = $need;
			}

			$widths = self::allocate();

			foreach ( self::$cols as $i => $col ) {
				self::$cols[ $i ]['w'] = $widths[ $i ];
			}
		}

		/**
		 * پخش عرض کل بین ستون‌ها با کف و سقف هر ستون.
		 *
		 * @return array عرض هر ستون به ترتیب فهرست.
		 */
		private static function allocate() {
			$total = self::$content;
			$w     = array();
			$min   = array();
			$max   = array();

			foreach ( self::$cols as $i => $col ) {
				$min[ $i ] = (float) $col['min'];
				$max[ $i ] = max( (float) $col['min'], (float) $col['max'] );
				$w[ $i ]   = max( $min[ $i ], min( (float) $col['need'], $max[ $i ] ) );
			}

			for ( $pass = 0; $pass < 24; $pass++ ) {
				$sum = array_sum( $w );

				if ( abs( $sum - $total ) < 0.5 ) {
					break;
				}

				if ( $sum > $total ) {
					$room = 0.0;
					$free = array();

					foreach ( $w as $i => $value ) {
						$slack = $value - $min[ $i ];

						if ( $slack > 0.5 ) {
							$free[ $i ] = $slack;
							$room      += $slack;
						}
					}

					if ( $room <= 0.01 ) {
						break;
					}

					$extra = $sum - $total;

					foreach ( $free as $i => $slack ) {
						$w[ $i ] -= ( $extra * $slack / $room );
					}

					continue;
				}

				$room = 0.0;
				$free = array();

				foreach ( $w as $i => $value ) {
					$slack = $max[ $i ] - $value;

					if ( $slack > 0.5 ) {
						$free[ $i ] = $slack;
						$room      += $slack;
					}
				}

				if ( $room <= 0.01 ) {
					break;
				}

				$extra = $total - $sum;

				foreach ( $free as $i => $slack ) {
					$w[ $i ] += ( $extra * $slack / $room );
				}
			}

			foreach ( $w as $i => $value ) {
				$w[ $i ] = max( 1.0, $value );
			}

			return $w;
		}

		/** جای‌گذاری افقی ستون‌ها (جدول راست‌به‌چپ: ستون اول سمت راست). */
		private static function place_columns() {
			$rtl   = self::table_rtl();
			$count = count( self::$cols );
			$width = array();

			foreach ( self::$cols as $i => $col ) {
				$slot           = $rtl ? ( $count - 1 - $i ) : $i;
				$width[ $slot ] = (float) $col['w'];
				self::$cols[ $i ]['slot'] = $slot;
			}

			ksort( $width );

			$pos = self::$mx;

			foreach ( $width as $slot => $col_w ) {
				foreach ( self::$cols as $i => $col ) {
					if ( $col['slot'] === $slot ) {
						self::$cols[ $i ]['x'] = $pos;
						break;
					}
				}

				$pos += $col_w;
			}
		}

		/** آرایش همهٔ جدول‌های PDF فارسی RTL است؛ نوع داده فقط جهت متن درون سلول را تعیین می‌کند. */
		private static function table_rtl() {
			return true;
		}

		/** آغاز صفحهٔ تازه: نوار عنوان + سرستون‌ها. */
		private static function new_page() {
			if ( self::$open ) {
				self::end_page( false );
			}

			self::$page++;
			self::$open = true;
			self::$buf  = '';

			$top         = self::$pheight - self::$mx;
			$band_h      = 30.0;
			$band_bottom = $top - $band_h;

			self::rect( self::$mx, $band_bottom, self::$content, $band_h, self::$accent, null );

			$title   = ( '' !== (string) self::meta_value( 'title' ) ) ? (string) self::meta_value( 'title' ) : __( 'مرکز خروجی تیساکیس', TisaCase_Exporter::TEXT_DOMAIN );
			$layout  = TisaCase_Exporter_Pdf_Text::layout( $title, self::bold_spec(), true );
			$size    = 12.5;
			$x_title = self::$mx + self::$content - 9 - ( $layout['width'] * $size );

			self::text( $x_title, $band_bottom + 17.5, $layout['glyphs'], $size, true, self::$white, $layout['actual_text'] );

			$sub    = trim( (string) self::meta_value( 'site' ) . ' · ' . (string) self::meta_value( 'date' ) );
			$sublay = TisaCase_Exporter_Pdf_Text::layout( $sub, self::$font_pt, true );

			self::text(
				self::$mx + self::$content - 9 - ( $sublay['width'] * 7.4 ),
				$band_bottom + 7.5,
				$sublay['glyphs'],
				7.4,
				false,
				self::$accent_soft,
				$sublay['actual_text']
			);

			$mark    = __( 'مرکز خروجی تیساکیس', TisaCase_Exporter::TEXT_DOMAIN );
			$marklay = TisaCase_Exporter_Pdf_Text::layout( $mark, self::bold_spec(), true );

			self::text( self::$mx + 9, $band_bottom + 12, $marklay['glyphs'], 8.6, true, self::$accent_soft, $marklay['actual_text'] );

			/* خلاصهٔ فیلترها در نوار جداگانه می‌آید تا با نام سایت/تاریخ تداخل نکند. */
			$filters = trim( (string) self::meta_value( 'filters' ) );
			$head_top = $band_bottom - 5.0;

			if ( '' !== $filters ) {
				$filter_size   = 7.0;
				$filter_line_h = 8.6;
				$filter_text   = __( 'فیلترها: ', TisaCase_Exporter::TEXT_DOMAIN ) . $filters;
				$filter_max    = max( 20.0, ( self::$content - 18.0 ) / $filter_size );
				$filter_lines  = TisaCase_Exporter_Pdf_Text::wrap( $filter_text, $filter_max, self::$font_pt, true );
				$filter_height = ( count( $filter_lines ) * $filter_line_h ) + 5.0;
				$filter_top    = $band_bottom - 4.0;
				$filter_bottom = $filter_top - $filter_height;

				self::rect( self::$mx, $filter_bottom, self::$content, $filter_height, self::$accent_soft, null );

				$filter_y = $filter_top - 3.6 - ( $filter_size * 0.86 );

				foreach ( $filter_lines as $filter_line ) {
					$filter_layout = TisaCase_Exporter_Pdf_Text::layout( $filter_line, self::$font_pt, true );
					$filter_x      = self::$mx + self::$content - 9 - ( $filter_layout['width'] * $filter_size );

					self::text( $filter_x, $filter_y, $filter_layout['glyphs'], $filter_size, false, self::$accent_dark, $filter_layout['actual_text'] );
					$filter_y -= $filter_line_h;
				}

				$head_top = $filter_bottom - 5.0;
			}

			/* عنوان ستون‌ها هم می‌تواند دو خط شود (مثل «موبایل (989xxxxxxx)»)؛
			   ارتفاع سرستون از تعداد خط‌های واقعی درمی‌آید. */
			$head_lines = array();
			$head_rows  = 1;

			foreach ( self::$cols as $i => $col ) {
				$max   = ( $col['w'] - ( 2 * self::$pad_x ) ) / self::$head_size;
				$lines = TisaCase_Exporter_Pdf_Text::wrap( (string) $col['label'], $max, self::bold_spec(), $col['rtl'] );

				if ( count( $lines ) > 2 ) {
					$lines = array_slice( $lines, 0, 2 );
				}

				$head_lines[ $i ] = $lines;
				$head_rows        = max( $head_rows, count( $lines ) );
			}

			$head_h      = ( $head_rows * self::$head_lh ) + 6.0;
			$head_bottom = $head_top - $head_h;

			self::rect( self::$mx, $head_bottom, self::$content, $head_h, self::$accent_soft, null );

			foreach ( self::$cols as $i => $col ) {
				$y = $head_top - 3.4 - ( self::$head_size * 0.86 );

				foreach ( $head_lines[ $i ] as $line ) {
					$lab = TisaCase_Exporter_Pdf_Text::layout( $line, self::bold_spec(), $col['rtl'] );
					$lx  = ( 'left' === $col['align'] )
						? ( $col['x'] + self::$pad_x )
						: ( $col['x'] + $col['w'] - self::$pad_x - ( $lab['width'] * self::$head_size ) );

					self::text( $lx, $y, $lab['glyphs'], self::$head_size, true, self::$accent_dark, $lab['actual_text'] );
					$y -= self::$head_lh;
				}
			}

			self::$page_top  = $head_bottom;
			self::$page_head = $head_top;
			self::$y         = $head_bottom;
		}

		/** پایان صفحه: پاصفحه + شیء جریان محتوا + شیء صفحه. */
		private static function end_page( $last ) {
			if ( ! self::$open ) {
				return;
			}

			if ( count( self::$cols ) > 1 ) {
				foreach ( self::$cols as $col ) {
					if ( $col['slot'] > 0 && self::$y < self::$page_head ) {
						self::line( $col['x'], self::$y, $col['x'], self::$page_head, self::$line_color, 0.3 );
					}
				}
			}

			self::footer( $last );

			$stream = self::content_object( self::$buf );
			$page   = self::$writer->object(
				sprintf(
					'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> /ProcSet [/PDF /Text] >> /Contents %d 0 R >>',
					self::$obj_pages,
					self::$pwidth,
					self::$pheight,
					self::$fonts['regular']['obj'],
					self::$fonts['bold']['obj'],
					$stream
				)
			);

			self::$kids[] = $page;
			self::$open   = false;
			self::$buf    = '';
		}

		/** جریان محتوای صفحه (در صورت امکان فشرده). */
		private static function content_object( $body ) {
			if ( function_exists( 'gzcompress' ) ) {
				$zipped = gzcompress( (string) $body, 6 );

				if ( false !== $zipped && '' !== $zipped ) {
					return self::$writer->stream( '/Filter /FlateDecode', $zipped );
				}
			}

			return self::$writer->stream( '', $body );
		}

		/** پاصفحه. */
		private static function footer( $last ) {
			$y = self::$mb - 24.0;

			self::line( self::$mx, $y + 11, self::$mx + self::$content, $y + 11, self::$line_color, 0.5 );

			$items = array();

			if ( '' !== (string) self::meta_value( 'site' ) ) {
				$items[] = (string) self::meta_value( 'site' );
			}

			$items[] = (string) self::meta_value( 'date' );

			$lay = TisaCase_Exporter_Pdf_Text::layout( implode( ' · ', $items ), self::$font_pt, true );

			self::text( self::$mx + self::$content - ( $lay['width'] * 7.2 ), $y + 14.0, $lay['glyphs'], 7.2, false, self::$muted, $lay['actual_text'] );

			$page_label = sprintf(
				/* translators: %s: شمارهٔ صفحه */
				__( 'صفحهٔ %s', TisaCase_Exporter::TEXT_DOMAIN ),
				self::fa_digits( (string) self::$page )
			);

			if ( $last && self::$rows > 0 ) {
				$page_label .= ' · ' . sprintf(
					/* translators: %s: تعداد ردیف‌ها */
					__( 'مجموع %s ردیف', TisaCase_Exporter::TEXT_DOMAIN ),
					self::fa_digits( (string) self::$rows )
				);
			}

			$play = TisaCase_Exporter_Pdf_Text::layout( $page_label, self::$font_pt, true );

			self::text( self::$mx + self::$content - ( $play['width'] * 7.2 ), $y + 2.5, $play['glyphs'], 7.2, false, self::$muted, $play['actual_text'] );
		}

		/* -----------------------------------------------------------------
		 * جاسازی قلم
		 * ----------------------------------------------------------------- */

		/** جاسازی یک وزن قلم (F1 = معمولی · F2 = پررنگ). */
		private static function embed( $key ) {
			$spec = self::spec( $key );
			$file = self::font_file( $spec );
			$res  = ( 'regular' === $key ) ? 'F1' : 'F2';

			if ( '' === $file ) {
				if ( isset( self::$fonts['regular'] ) ) {
					self::$fonts[ $key ] = self::$fonts['regular'];
				}

				return;
			}

			$m     = $spec['metrics'];
			$upem  = (int) $m['unitsPerEm'];
			$scale = 1000 / max( 1, $upem );
			$bbox  = sprintf(
				'[ %d %d %d %d ]',
				(int) round( $m['xMin'] * $scale ),
				(int) round( $m['yMin'] * $scale ),
				(int) round( $m['xMax'] * $scale ),
				(int) round( $m['yMax'] * $scale )
			);
			$size    = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- مسیر داخلی افزونه.
			$program = self::$writer->file_stream( '/Length1 ' . $size, $file );
			$descr   = self::$writer->object(
				sprintf(
					'<< /Type /FontDescriptor /FontName /%s /Flags 4 /FontBBox %s /ItalicAngle %s /Ascent %d /Descent %d /CapHeight %d /StemV %d /FontFile2 %d 0 R >>',
					$spec['family'],
					$bbox,
					( 0.0 === (float) $m['italicAngle'] ) ? '0' : (string) $m['italicAngle'],
					(int) round( $m['ascent'] * $scale ),
					(int) round( $m['descent'] * $scale ),
					(int) round( $m['capHeight'] * $scale ),
					(int) $m['stemV'],
					$program
				)
			);
			$cid     = self::$writer->object(
				sprintf(
					'<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /DW 1000 /W %s /CIDToGIDMap /Identity >>',
					$spec['family'],
					$descr,
					self::width_array( $spec )
				)
			);
			$cmap    = self::$writer->stream( '', self::cmap( $spec ) );
			$type0   = self::$writer->object(
				sprintf(
					'<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [ %d 0 R ] /ToUnicode %d 0 R >>',
					$spec['family'],
					$cid,
					$cmap
				)
			);

			self::$fonts[ $key ] = array( 'res' => $res, 'obj' => $type0, 'spec' => TisaCase_Exporter_Pdf_Text::font( $spec ) );
		}

		/** آرایهٔ عرض گلیف‌ها (۱/۱۰۰۰ em) با فشرده‌سازی بازه‌های یکسان. */
		private static function width_array( array $spec ) {
			$upem  = (int) $spec['metrics']['unitsPerEm'];
			$parts = array();
			$start = -1;
			$value = -1;
			$run   = array();

			foreach ( $spec['widths'] as $gid => $width ) {
				$val = (int) round( $width * 1000 / max( 1, $upem ) );

				if ( $start >= 0 && (int) $gid === ( $start + count( $run ) ) && $val === $value ) {
					$run[] = $val;
					continue;
				}

				if ( $start >= 0 ) {
					$parts[] = $start . ' [' . implode( ' ', $run ) . ']';
				}

				$start = (int) $gid;
				$value = $val;
				$run   = array( $val );
			}

			if ( $start >= 0 ) {
				$parts[] = $start . ' [' . implode( ' ', $run ) . ']';
			}

			return '[ ' . implode( ' ', $parts ) . ' ]';
		}

		/** نقشهٔ گلیف → یونیکد (برای کپی/جست‌وجو در PDF). */
		private static function cmap( array $spec ) {
			$map = array();

			foreach ( $spec['codepoints'] as $cp => $gid ) {
				if ( $gid > 0 ) {
					$map[ (int) $gid ] = self::utf16hex( self::utf8( (int) $cp ) );
				}
			}

			foreach ( $spec['forms'] as $cp => $forms ) {
				foreach ( $forms as $gid ) {
					if ( $gid > 0 ) {
						$map[ (int) $gid ] = self::utf16hex( self::utf8( (int) $cp ) );
					}
				}
			}

			foreach ( $spec['ligatures'] as $key => $gids ) {
				$pair = explode( '-', (string) $key );
				$text = '';

				if ( 2 === count( $pair ) ) {
					$text = self::utf8( (int) $pair[0] ) . self::utf8( (int) $pair[1] );
				}

				foreach ( $gids as $gid ) {
					if ( $gid > 0 ) {
						$map[ (int) $gid ] = self::utf16hex( $text );
					}
				}
			}

			ksort( $map );

			$out = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
				. "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
				. "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
				. "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";

			foreach ( array_chunk( $map, 100, true ) as $chunk ) {
				$out .= count( $chunk ) . " beginbfchar\n";

				foreach ( $chunk as $gid => $hex ) {
					$out .= sprintf( "<%04X> <%s>\n", $gid, $hex );
				}

				$out .= "endbfchar\n";
			}

			return $out . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
		}

		/** مشخصات یک وزن قلم. */
		private static function spec( $key ) {
			$fonts = TisaCase_Exporter_Pdf_Font_Data::FONTS;

			if ( isset( $fonts[ $key ] ) ) {
				return $fonts[ $key ];
			}

			return $fonts['regular'];
		}

		/** مشخصات قلم پررنگ. */
		private static function bold_spec() {
			if ( ! empty( self::$fonts['bold']['spec'] ) ) {
				return self::$fonts['bold']['spec'];
			}

			return self::spec( 'bold' );
		}

		/** مسیر فایل قلم. */
		private static function font_file( array $spec ) {
			if ( empty( $spec['file'] ) || ! defined( 'TISA_EXPORTER_DIR' ) ) {
				return '';
			}

			$path = TISA_EXPORTER_DIR . 'assets/fonts/' . $spec['file'];

			return is_readable( $path ) ? $path : '';
		}

		/* -----------------------------------------------------------------
		 * حالت بدون قلم (فقط پیام خطا)
		 * ----------------------------------------------------------------- */

		/** اگر فایل‌های قلم نبودند: یک صفحهٔ ساده با پیام ASCII. */
		private static function degraded_open() {
			self::$degraded = true;
			$font           = self::$writer->object( '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>' );

			self::$fonts['regular'] = array( 'res' => 'F1', 'obj' => $font, 'spec' => array() );
			self::$fonts['bold']    = self::$fonts['regular'];
			self::$pwidth           = 595.28;
			self::$pheight          = 841.89;
			self::$content          = self::$pwidth - ( 2 * self::$mx );
			self::$cols             = array();
			self::$page             = 1;
			self::$open             = true;
			self::$buf              = "BT /F1 12 Tf 60 780 Td (TisaCase Exporter: PDF font files are missing.) Tj ET\n"
				. "BT /F1 10 Tf 60 760 Td (Reinstall the plugin to restore assets/fonts/.) Tj ET\n";
		}

		/** بستن حالت بدون قلم. */
		private static function degraded_close() {
			$stream = self::$writer->stream( '', self::$buf );
			$page   = self::$writer->object(
				sprintf(
					'<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 %d 0 R >> /ProcSet [/PDF /Text] >> /Contents %d 0 R >>',
					self::$obj_pages,
					self::$pwidth,
					self::$pheight,
					self::$fonts['regular']['obj'],
					$stream
				)
			);

			self::$writer->emit( self::$obj_pages, '<< /Type /Pages /Kids [ ' . $page . ' 0 R ] /Count 1 >>' );
			self::$writer->emit( self::$obj_catalog, '<< /Type /Catalog /Pages ' . self::$obj_pages . ' 0 R >>' );
			self::$writer->emit( self::$obj_info, '<< /Producer (TisaCase Exporter) /CreationDate (' . self::pdf_date() . ') >>' );
			self::$writer->finish( self::$obj_catalog, self::$obj_info );
			self::$open = false;
		}

		/* -----------------------------------------------------------------
		 * واژه‌های روی صفحه
		 * ----------------------------------------------------------------- */

		/** نوشتن گلیف‌ها با موقعیت، اندازه، رنگ و متن منطقی برای کپی/جست‌وجو. */
		private static function text( $x, $y, array $glyphs, $size, $bold, array $rgb, $actual_text = '' ) {
			$hex = '';

			foreach ( $glyphs as $glyph ) {
				if ( $glyph['g'] > 0 ) {
					$hex .= sprintf( '%04X', (int) $glyph['g'] );
				}
			}

			if ( '' === $hex ) {
				return;
			}

			$res         = ( $bold && ! empty( self::$fonts['bold']['res'] ) ) ? self::$fonts['bold']['res'] : self::$fonts['regular']['res'];
			$mark_start = '' !== (string) $actual_text ? '/Span << /ActualText <' . self::text_hex( $actual_text ) . '> >> BDC ' : '';
			$mark_end   = '' !== $mark_start ? ' EMC' : '';

			self::$buf .= sprintf(
				"q %.3F %.3F %.3F rg %sBT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET%s Q\n",
				$rgb[0],
				$rgb[1],
				$rgb[2],
				$mark_start,
				$res,
				$size,
				$x,
				$y,
				$hex,
				$mark_end
			);
		}

		/** یک قطعهٔ متن مستقل در ردیف، با ActualText برای استخراج درست فارسی. */
		private static function inline_text( $x, $y, array $glyphs, $size, $actual_text = '' ) {
			$hex = '';

			foreach ( $glyphs as $glyph ) {
				if ( $glyph['g'] > 0 ) {
					$hex .= sprintf( '%04X', (int) $glyph['g'] );
				}
			}

			if ( '' === $hex ) {
				return '';
			}

			$mark_start = '' !== (string) $actual_text ? '/Span << /ActualText <' . self::text_hex( $actual_text ) . '> >> BDC ' : '';
			$mark_end   = '' !== $mark_start ? ' EMC' : '';

			return $mark_start . sprintf(
				'BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm <%s> Tj ET',
				self::$fonts['regular']['res'],
				$size,
				$x,
				$y,
				$hex
			) . $mark_end . ' ';
		}

		/** مستطیل پر. */
		private static function rect( $x, $y, $w, $h, $fill, $stroke ) {
			unset( $stroke );

			if ( ! is_array( $fill ) ) {
				return;
			}

			self::$buf .= sprintf(
				"q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q\n",
				$fill[0],
				$fill[1],
				$fill[2],
				$x,
				$y,
				$w,
				$h
			);
		}

		/** خط. */
		private static function line( $x1, $y1, $x2, $y2, array $rgb, $width ) {
			self::$buf .= sprintf(
				"q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q\n",
				$rgb[0],
				$rgb[1],
				$rgb[2],
				$width,
				$x1,
				$y1,
				$x2,
				$y2
			);
		}

		/* -----------------------------------------------------------------
		 * کمکی
		 * ----------------------------------------------------------------- */

		/** مقدار متادیتا. */
		private static function meta_value( $key ) {
			return isset( self::$meta[ $key ] ) ? (string) self::$meta[ $key ] : '';
		}

		/** عدد با ارقام فارسی. */
		private static function fa_digits( $value ) {
			return str_replace(
				array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' ),
				array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ),
				(string) $value
			);
		}

		/** متن → هگز UTF-16BE با BOM (برای رشته‌های متنی PDF). */
		private static function text_hex( $text ) {
			return 'FEFF' . self::utf16hex( $text );
		}

		/** UTF-8 → هگز UTF-16BE (بدون BOM). */
		private static function utf16hex( $text ) {
			$text = (string) $text;

			if ( function_exists( 'mb_convert_encoding' ) ) {
				$utf16 = mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' );
			} else {
				$utf16 = '';
				$units = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

				foreach ( (array) $units as $unit ) {
					$cp = self::cp( $unit );

					if ( $cp > 0xFFFF ) {
						$cp -= 0x10000;
						$high   = 0xD800 | ( $cp >> 10 );
						$low    = 0xDC00 | ( $cp & 0x3FF );
					$utf16 .= chr( ( $high >> 8 ) & 0xFF ) . chr( $high & 0xFF ) . chr( ( $low >> 8 ) & 0xFF ) . chr( $low & 0xFF );
					} else {
						$utf16 .= chr( ( $cp >> 8 ) & 0xFF ) . chr( $cp & 0xFF );
					}
				}
			}

			$out = '';

			for ( $i = 0, $len = strlen( $utf16 ); $i < $len; $i++ ) {
				$out .= sprintf( '%02X', ord( $utf16[ $i ] ) );
			}

			return $out;
		}

		/** کدپوینت یک نویسهٔ UTF-8. */
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

			$out = 0x3F;

			if ( 4 === $len ) {
				$out = ( ( ord( $char[0] ) & 0x07 ) << 18 ) | ( ( ord( $char[1] ) & 0x3F ) << 12 ) | ( ( ord( $char[2] ) & 0x3F ) << 6 ) | ( ord( $char[3] ) & 0x3F );
			}

			return $out;
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

		/** تاریخ فشردهٔ PDF با اختلاف زمانی وردپرس. */
		private static function pdf_date() {
			$offset = 0.0;

			if ( function_exists( 'get_option' ) ) {
				$offset = (float) get_option( 'gmt_offset', 0 );
			}

			$sign  = ( $offset < 0 ) ? '-' : '+';
			$hours = (int) floor( abs( $offset ) );
			$mins  = (int) round( ( abs( $offset ) - $hours ) * 60 );

			return gmdate( 'YmdHis' ) . $sign . sprintf( '%02d', $hours ) . "'" . sprintf( '%02d', $mins ) . "'";
		}

		/** صفرکردن وضعیت. */
		private static function reset() {
			self::$pwidth   = 595.28;
			self::$pheight  = 841.89;
			self::$content  = self::$pwidth - ( 2 * self::$mx );
			self::$meta     = array();
			self::$cols     = array();
			self::$fonts    = array();
			self::$font_pt  = array();
			self::$degraded = false;
			self::$kids     = array();
			self::$buf      = '';
			self::$y        = 0.0;
			self::$rows     = 0;
			self::$page     = 0;
			self::$sampling = false;
			self::$sample   = array();
			self::$open     = false;
			self::$writer   = null;
		}
	}
}
