<?php
/**
 * لایهٔ نمایش سمت کاربر.
 *
 * دو کار می‌کند:
 *   ۱) فیلتر ووکامرس روی HTML دراپ‌داون ویژگی: گزینه‌ها را به ترتیب برند
 *      مرتب و در <optgroup> می‌گذارد (بنابراین حتی بدون جاوااسکریپت هم
 *      لیست دسته‌بندی‌شده است).
 *   ۲) بارگذاری CSS/JS پنل جستجوپذیر روی صفحهٔ محصول.
 *
 * هیچ داده‌ای خوانده/نوشته نمی‌شود جز تنظیمات خود افزونه.
 *
 * @package TisaCase_Brand_Variations
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCBV_Frontend' ) ) {

	final class TCBV_Frontend {

		/** @var array|null تنظیمات همان درخواست. */
		private static $settings = null;

		public static function init() {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
			add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( __CLASS__, 'dropdown_html' ), 20, 2 );
			add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
			add_action( 'woocommerce_before_variations_form', array( __CLASS__, 'test_badge' ) );
		}

		/**
		 * تنظیمات (کش‌شده).
		 *
		 * @return array
		 */
		public static function settings() {
			if ( ! is_array( self::$settings ) ) {
				self::$settings = TCBV_Settings::get();
			}
			return self::$settings;
		}

		/**
		 * آیا محصول جاری در محدودهٔ فعال‌بودن افزونه است؟
		 *
		 * @param int $product_id شناسهٔ محصول.
		 * @return bool
		 */
		public static function product_in_scope( $product_id ) {
			$product_id = (int) $product_id;
			$settings   = self::settings();

			// «حالت تست» بر همهٔ دامنه‌ها اولویت دارد: تا وقتی روشن است فقط
			// محصول‌های انتخاب‌شده دیده می‌شوند و بقیهٔ فروشگاه دست‌نخورده است.
			if ( TCBV_Settings::test_mode_on( $settings ) ) {
				return $product_id && in_array( (int) $product_id, TCBV_Settings::test_products( $settings ), true );
			}

			$scope = isset( $settings['advanced']['products_scope'] ) ? $settings['advanced']['products_scope'] : 'all';

			if ( 'all' === $scope ) {
				return true;
			}
			// در scopeهای دسته‌ای، نبودن شناسه نباید به معنی «اجرا روی همه» باشد.
			// بدون زمینهٔ قطعی محصول، امن‌ترین رفتار عدم‌تغییر است.
			if ( $product_id <= 0 ) {
				return false;
			}

			$terms = TCBV_Settings::lines( $settings['advanced']['categories'] );
			if ( ! $terms ) {
				// فهرست خالی: «فقط این‌ها» هیچ‌چیز را شامل نمی‌شود، «همه جز این‌ها» همه را.
				return 'exclude' === $scope;
			}

			$wanted = array();
			foreach ( $terms as $term ) {
				$term = trim( $term );
				$term = rawurldecode( $term );
				if ( is_numeric( $term ) ) {
					$wanted[] = (int) $term;
					continue;
				}
				$obj = get_term_by( 'slug', sanitize_title( $term ), 'product_cat' );
				if ( ! $obj && $term !== sanitize_title( $term ) ) {
					$obj = get_term_by( 'slug', $term, 'product_cat' );
				}
				if ( $obj ) {
					$wanted[] = (int) $obj->term_id;
				}
			}

			if ( ! $wanted ) {
				return 'exclude' === $scope;
			}

			$assigned = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
			$assigned = is_array( $assigned ) ? array_map( 'intval', $assigned ) : array();
			$hit      = (bool) array_intersect( $wanted, $assigned );

			return ( 'include' === $scope ) ? $hit : ! $hit;
		}

		/**
		 * آیا همین حالا باید استایل/اسکریپت بارگذاری شود؟
		 *
		 * @return bool
		 */
		public static function is_active_here() {
			$settings = self::settings();

			if ( empty( $settings['enabled'] ) ) {
				return false;
			}
			if ( 'off' === $settings['ui']['mode'] ) {
				return false;
			}
			if ( is_admin() || is_feed() || is_robots() ) {
				return false;
			}

			$is_product = function_exists( 'is_product' ) && is_product();
			$product_id = $is_product ? (int) get_queried_object_id() : 0;

			$load = $is_product && self::product_in_scope( $product_id );

			return (bool) apply_filters( 'tcbv_load', $load, $product_id );
		}

		/**
		 * کلاس بدنه (برای قالب‌هایی که می‌خواهند استایل خودشان را تنظیم کنند).
		 *
		 * @param array $classes کلاس‌ها.
		 * @return array
		 */
		public static function body_class( $classes ) {
			if ( self::is_active_here() ) {
				$classes[] = 'tcbv-active';
			}
			return $classes;
		}

		/**
		 * نشان «حالت تست» روی صفحهٔ محصول — فقط برای مدیر و فقط وقتی حالت تست روشن است.
		 * برای بازدیدکنندهٔ عادی هیچ خروجی‌ای ندارد.
		 */
		public static function test_badge() {
			$settings = self::settings();

			if ( empty( $settings['test']['badge'] ) || ! TCBV_Settings::test_mode_on( $settings ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_active_here() ) {
				return;
			}

			$page = class_exists( 'TCBV_Admin' ) ? TCBV_Admin::PAGE : 'tisacase-brand-variations';
			printf(
				'<div class="tcbv-test-badge" dir="rtl"><span class="tcbv-test-badge-ico" aria-hidden="true">🧪</span><span>حالت تست روشن است — گروه‌بندی بر اساس برند فعلاً فقط روی محصول‌های انتخاب‌شده اعمال می‌شود.</span><a href="%s">تنظیمات</a></div>',
				esc_url( admin_url( 'admin.php?page=' . $page ) )
			);
		}

		/**
		 * بارگذاری دارایی‌ها.
		 */
		public static function assets() {
			if ( ! self::is_active_here() ) {
				return;
			}

			$settings = self::settings();

			wp_enqueue_style(
				'tcbv-vazir',
				'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css',
				array(),
				'33.003'
			);
			wp_enqueue_style( 'tcbv-frontend', TCBV_URL . 'assets/frontend.css', array( 'tcbv-vazir' ), TCBV_VERSION );
			wp_add_inline_style( 'tcbv-frontend', self::css_vars( $settings ) );

			$panel_on = ( 'panel' === $settings['ui']['mode'] ) && empty( $settings['advanced']['safe_mode'] );
			if ( ! $panel_on ) {
				return; // حالت «فقط‌مرتب‌سازی»: بدون پنل، فقط <optgroup> سرور.
			}

			wp_enqueue_script( 'tcbv-frontend', TCBV_URL . 'assets/frontend.js', array(), TCBV_VERSION, true );
			wp_add_inline_script(
				'tcbv-frontend',
				'window.TCBV_CFG = ' . wp_json_encode( TCBV_Rules::js_config( $settings ) ) . ';',
				'before'
			);
		}

		/**
		 * متغیرهای CSS از تنظیمات.
		 *
		 * @param array $settings تنظیمات.
		 * @return string
		 */
		public static function css_vars( $settings ) {
			$t  = $settings['theme'];
			$ui = $settings['ui'];

			$vars = array(
				'--tcbv-accent'   => $t['accent'],
				'--tcbv-bg'       => $t['bg'],
				'--tcbv-bg-alt'   => $t['bg_alt'],
				'--tcbv-border'   => $t['border'],
				'--tcbv-text'     => $t['text'],
				'--tcbv-muted'    => $t['muted'],
				'--tcbv-sep'      => $t['sep'],
				'--tcbv-hover'    => $t['hover'],
				'--tcbv-sel-bg'   => $t['sel_bg'],
				'--tcbv-sel-text' => $t['sel_text'],
				'--tcbv-radius'   => (int) $t['radius'] . 'px',
				'--tcbv-fs'       => (int) $t['font'] . 'px',
				'--tcbv-pad'      => (int) $t['item_pad'] . 'px',
				'--tcbv-max-h'    => (int) $ui['max_height'] . 'px',
				'--tcbv-sw-size'  => (int) $settings['swatch']['size'] . 'px',
			);

			$css = '.tcbv{';
			foreach ( $vars as $name => $value ) {
				$css .= $name . ':' . $value . ';';
			}
			$css .= '}';

			if ( ! empty( $t['shadow'] ) ) {
				$css .= '.tcbv-pop{box-shadow:0 12px 36px rgba(31,42,46,.14)}';
			}

			return $css;
		}

		/**
		 * فیلتر HTML دراپ‌داون ویژگی: مرتب‌سازی بر اساس برند + <optgroup>.
		 *
		 * @param string $html خروجی HTML ووکامرس.
		 * @param array  $args آرگومان‌های wc_dropdown_variation_attribute_options.
		 * @return string
		 */
		public static function dropdown_html( $html, $args ) {
			// افزونه فقط روی صفحهٔ تکی محصول اثر می‌گذارد؛ dropdownهای آرشیو،
			// ابزارک‌ها و سایر صفحات فروشگاه باید کاملاً دست‌نخورده بمانند.
			if ( ! function_exists( 'is_product' ) || ! is_product() ) {
				return $html;
			}

			$settings = self::settings();

			if ( empty( $settings['enabled'] ) || 'off' === $settings['ui']['mode'] ) {
				return $html;
			}
			if ( ! is_string( $html ) || false === stripos( $html, '<select' ) || false === stripos( $html, '<option' ) ) {
				return $html;
			}

			// همان دامنهٔ فعال‌بودن (شامل حالت تست): محصول بیرون از دامنه دست‌نخورده می‌ماند.
			if ( ! self::product_in_scope( self::args_product_id( $args ) ) ) {
				return $html;
			}

			$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
			if ( '' === $attribute ) {
				return $html;
			}

			if ( ! preg_match( '#(<select\b[^>]*>)(.*?)(</select>)#is', $html, $matches ) ) {
				return $html;
			}

			$open  = $matches[1];
			$inner = $matches[2];
			$close = $matches[3];

			if ( ! empty( $settings['advanced']['respect_optgroups'] ) && false !== stripos( $inner, '<optgroup' ) ) {
				return $html; // قالب/افزونهٔ دیگری از قبل گروه‌بندی کرده است.
			}

			preg_match_all( '#<option\b[^>]*>(?:.*?</option>)?#is', $inner, $options );
			$tags = isset( $options[0] ) ? $options[0] : array();
			if ( count( $tags ) < 3 ) {
				return $html;
			}

			$placeholders = array();
			$by_value     = array();
			$order        = array();
			$labels       = array();

			foreach ( $tags as $tag ) {
				$value = self::option_value( $tag );
				if ( '' === $value ) {
					$placeholders[] = $tag;
					continue;
				}
				if ( ! isset( $labels[ $value ] ) ) {
					$text = self::option_text( $tag );
					if ( '' !== $text ) {
						$labels[ $value ] = $text;
					}
				}
				if ( ! isset( $by_value[ $value ] ) ) {
					$by_value[ $value ] = $tag;
					$order[]            = $value;
				} else {
					$by_value[ $value ] .= $tag;
				}
			}

			if ( count( $order ) < 2 ) {
				return $html;
			}

			TCBV_Rules::set_labels( $labels );

			if ( ! TCBV_Rules::should_group( $attribute, $order, $settings ) ) {
				return $html;
			}

			$groups = TCBV_Rules::group( $order, $settings );
			$real   = 0;
			foreach ( $groups as $group ) {
				if ( empty( $group['unknown'] ) && $group['count'] > 0 ) {
					$real++;
				}
			}
			if ( $real < 2 ) {
				return $html; // فقط یک برند: گروه‌بندی معنا ندارد.
			}

			$counts = ! empty( $settings['ui']['counts'] );
			$used   = array();
			$body   = '';

			foreach ( $placeholders as $tag ) {
				$body .= $tag;
			}

			foreach ( $groups as $group ) {
				if ( empty( $group['count'] ) ) {
					continue;
				}
				$label = $group['label'];
				if ( $counts ) {
					$label .= ' (' . number_format_i18n( $group['count'] ) . ')';
				}
				$body .= '<optgroup label="' . esc_attr( $label ) . '">';
				foreach ( $group['values'] as $value ) {
					if ( isset( $by_value[ $value ] ) ) {
						$body .= $by_value[ $value ];
						$used[ $value ] = true;
					}
				}
				$body .= '</optgroup>';
			}

			// هر گزینه‌ای که به هر دلیلی جا نمانده باشد، به ترتیب اول برمی‌گردد.
			foreach ( $order as $value ) {
				if ( empty( $used[ $value ] ) ) {
					$body .= $by_value[ $value ];
				}
			}

			$open = preg_replace( '/>$/', ' data-tcbv="grouped">', $open, 1 );

			return $open . $body . $close;
		}

		/**
		 * شناسهٔ محصول از آرگومان‌های دراپ‌داون ووکامرس.
		 *
		 * @param array $args آرگومان‌ها.
		 * @return int
		 */
		private static function args_product_id( $args ) {
			if ( isset( $args['product'] ) && is_object( $args['product'] ) && method_exists( $args['product'], 'get_id' ) ) {
				return (int) $args['product']->get_id();
			}
			if ( isset( $args['product_id'] ) ) {
				return (int) $args['product_id'];
			}
			$object = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
			return (int) $object;
		}

		/**
		 * مقدار یک تگ <option>.
		 *
		 * @param string $tag تگ.
		 * @return string
		 */
		/**
		 * متن دیده‌شدهٔ یک <option>.
		 *
		 * @param string $tag تگ گزینه.
		 * @return string
		 */
		private static function option_text( $tag ) {
			if ( preg_match( '#<option\b[^>]*>(.*?)(?:</option>)?$#is', $tag, $m ) ) {
				return html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ), ENT_QUOTES, 'UTF-8' );
			}
			return '';
		}

		private static function option_value( $tag ) {
			if ( preg_match( '#\bvalue\s*=\s*"([^"]*)"#i', $tag, $m ) ) {
				return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			}
			if ( preg_match( "#\\bvalue\\s*=\\s*'([^']*)'#i", $tag, $m ) ) {
				return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
			}
			if ( preg_match( '#<option\b[^>]*>(.*?)(?:</option>)?$#is', $tag, $m ) ) {
				return html_entity_decode( trim( wp_strip_all_tags( $m[1] ) ), ENT_QUOTES, 'UTF-8' );
			}
			return '';
		}
	}
}
