<?php
/**
 * Core rules engine.
 *
 * Keeps the exact behaviour of the original snippet:
 *   - "printed" products (SKU matches the pattern) get the printed description;
 *   - "frame" products (title contains the keyword) get the frame description;
 *   - everything else gets an empty description.
 *
 * @package TisaCase_Product_Description
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Desc_Core' ) ) {
	final class TisaCase_Desc_Core {

		const OPTION = 'tisacase_desc_options';

		public static function init() {
			// One canonical event covers new and updated products (admin and REST).
			// Unlike save_post plus new/update hooks, it runs only once per WC_Data::save().
			add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'sync_saved_product' ), 30, 2 );
		}

		/** Coalesced callback for WooCommerce's single post-save product event. */
		public static function sync_saved_product( $product, $data_store = null ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				self::sync_product( $product->get_id() );
			}
		}

		/**
		 * Default options (identical to the original snippet values).
		 */
		public static function defaults() {
			return array(
				'enabled'         => 1,                               // Auto-sync on save.
				'batch_size'      => 50,                              // Products per bounded repair/scan request.
				'printed_pattern' => '/^(?:CH|SB)(?:\d|$)/i',         // SKU regex for printed products.
				'printed_url'     => 'https://TISACHAP.COM',          // Shop URL used by the {url} token.
				'printed_desc'    => '<p>برای مشاهده محصولات چاپی بیشتر به سایت <strong><a href="{url}">TISACHAP.COM</a></strong> مراجعه کنید.</p><p>آماده سازی و تولید محصولات چاپی 7 تا 18 روزکاری زمان بر خواهد بود؛ از صبوری شما متشکریم</p>',
				'frame_keywords'  => 'قاب',                           // Comma-separated title keywords for frames.
				'frame_desc'      => '<p><strong>⚠️ توجه: تصاویر صرفاً برای نمایش رنگ و طرح محصول هستند. ظاهر نهایی قاب (گرد یا تخت بودن لبه‌ها، میزان برجستگی محافظ دوربین، محل دکمه‌ها و...) متناسب با مدل گوشی انتخابی شما تولید و ارسال می‌شود</strong></p>',
				'clear_unmatched' => 0,                               // Off by default: products matching no rule are left untouched.
				'skip_if_contains' => 1,                              // If current description already contains the new one, skip the edit.
				'prep_enabled'    => 0,                               // Auto-set preparation time for printed products.
				'prep_meta_key'   => '_tisacase_prep_time',           // Meta key of the "preparation time" field.
				'prep_value'      => '18',                            // Value (days) written for printed products.
				'prep_only_empty' => 0,                               // Only fill when the field is currently empty.
			);
		}

		/**
		 * Options merged with defaults.
		 */
		public static function get_options() {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$options = wp_parse_args( $saved, self::defaults() );
			$options['batch_size'] = max( 1, min( 100, (int) $options['batch_size'] ) );
			return $options;
		}

		public static function get_option( $key ) {
			$opts = self::get_options();
			return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
		}

		/**
		 * Product statuses considered by the bulk repair tool.
		 */
		public static function statuses() {
			return array( 'publish', 'draft', 'pending', 'private' );
		}

		/**
		 * Is this SKU a "printed" product?
		 */
		public static function is_printed( $sku, $pattern ) {
			$sku = strtoupper( trim( (string) $sku ) );

			$pattern = is_string( $pattern ) && '' !== $pattern ? $pattern : self::defaults()['printed_pattern'];

			// Fall back to the default if the saved regex is broken.
			if ( @preg_match( $pattern, '' ) === false ) {
				$pattern = self::defaults()['printed_pattern'];
			}

			return (bool) @preg_match( $pattern, $sku );
		}

		/**
		 * Does the title contain any of the frame keywords?
		 */
		public static function match_frame( $title, $keywords ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', (string) $keywords ) ) );
			foreach ( $keywords as $kw ) {
				if ( '' !== $kw && false !== mb_stripos( (string) $title, $kw, 0, 'UTF-8' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Replace {url} / {site} tokens inside a description.
		 */
		public static function replace_tokens( $html, $options ) {
			$tokens = array(
				'{url}'  => esc_url( $options['printed_url'] ),
				'{site}' => get_bloginfo( 'name' ),
			);
			return strtr( (string) $html, $tokens );
		}

		/**
		 * Figure out which rule (if any) applies to a product.
		 *
		 * @return array{rule:string, html:string}
		 */
		public static function match_info( $product, $options = null ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return array( 'rule' => 'none', 'html' => '' );
			}

			if ( null === $options ) {
				$options = self::get_options();
			}

			$sku   = (string) $product->get_sku();
			$title = (string) $product->get_name();

			if ( self::is_printed( $sku, $options['printed_pattern'] ) ) {
				return array( 'rule' => 'printed', 'html' => self::replace_tokens( $options['printed_desc'], $options ) );
			}

			if ( self::match_frame( $title, $options['frame_keywords'] ) ) {
				return array( 'rule' => 'frame', 'html' => self::replace_tokens( $options['frame_desc'], $options ) );
			}

			return array( 'rule' => 'none', 'html' => '' );
		}

		/**
		 * The exact description required for one product.
		 */
		public static function description_for( $product, $options = null ) {
			$info = self::match_info( $product, $options );
			return $info['html'];
		}

		/**
		 * The description a product *should* have, if a change is actually needed.
		 *
		 * Mirrors sync_product exactly, so the scan tool only reports products
		 * that would really be rewritten.
		 *
		 * @return string|null New description when a change is required, null when the product is already correct.
		 */
		public static function computed_description( $product, $options = null ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return null;
			}

			if ( null === $options ) {
				$options = self::get_options();
			}

			$new     = self::description_for( $product, $options );
			$current = $product->get_description();

			// Unmatched products keep their description when "clear unmatched" is off.
			if ( empty( $options['clear_unmatched'] ) && '' === $new && '' !== $current ) {
				return null;
			}

			// Already exact — nothing to do.
			if ( $current === $new ) {
				return null;
			}

			// "Contains" mode: if the current description already includes the
			// new text (as-is or after stripping tags/whitespace), leave it alone.
			if ( ! empty( $options['skip_if_contains'] ) && '' !== $new && self::contains_html( $current, $new ) ) {
				return null;
			}

			return $new;
		}

		/**
		 * Does $haystack contain $needle?
		 *
		 * Checks the raw HTML first, then falls back to a normalized plain-text
		 * comparison (tags stripped, whitespace collapsed) so small formatting
		 * differences do not break the match.
		 *
		 * @param string $haystack Current description.
		 * @param string $needle   New description.
		 * @return bool
		 */
		public static function contains_html( $haystack, $needle ) {
			$haystack = (string) $haystack;
			$needle   = (string) $needle;

			if ( '' === $needle || '' === $haystack ) {
				return false;
			}

			// Raw substring check.
			if ( false !== mb_stripos( $haystack, $needle, 0, 'UTF-8' ) ) {
				return true;
			}

			// Normalized plain-text check.
			$normalize = function ( $s ) {
				if ( function_exists( 'wp_strip_all_tags' ) ) {
					$s = wp_strip_all_tags( $s );
				} else {
					$s = preg_replace( '/<[^>]*>/', ' ', $s );
				}
				$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
				$s = preg_replace( '/\s+/u', ' ', $s );
				return trim( $s );
			};

			$h = $normalize( $haystack );
			$n = $normalize( $needle );

			return ( '' !== $n && false !== mb_stripos( $h, $n, 0, 'UTF-8' ) );
		}

		/**
		 * Force-apply the rules to one product (used by the selective apply tool).
		 *
		 * @return bool True when the product was changed.
		 */
		public static function apply_to( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}

			$new = self::computed_description( $product );
			if ( null === $new ) {
				return false;
			}

			$product->set_description( $new );
			$product->save();

			return true;
		}

		/**
		 * Synchronise one product's description with the rules.
		 *
		 * Also applies the preparation-time meta for printed products when
		 * that feature is enabled (independent of the description toggle).
		 *
		 * @param int  $product_id Product ID.
		 * @param bool $force      Ignore the "auto-sync enabled" option (used by the bulk tool).
		 */
		public static function sync_product( $product_id, $force = false ) {
			static $running = false;

			if ( $running || ! function_exists( 'wc_get_product' ) ) {
				return;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return;
			}

			$running = true;

			$options = self::get_options();
			$changed = false;

			// 1) Description rules.
			if ( $force || ! empty( $options['enabled'] ) ) {
				$new = self::computed_description( $product, $options );
				if ( null !== $new ) {
					$product->set_description( $new );
					$changed = true;
				}
			}

			// 2) Preparation time for printed products.
			if ( self::should_set_prep_time( $product, $options ) ) {
				$product->update_meta_data( $options['prep_meta_key'], $options['prep_value'] );
				$changed = true;
			}

			if ( $changed ) {
				$product->save();
			}

			$running = false;
		}

		/**
		 * Should we (re)write the preparation-time meta for this product?
		 *
		 * True only when the feature is enabled, the meta key/value are set,
		 * the product is a "printed" product, and the current value differs
		 * (respecting the "only when empty" toggle).
		 */
		public static function should_set_prep_time( $product, $options = null ) {
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				return false;
			}
			if ( null === $options ) {
				$options = self::get_options();
			}
			if ( empty( $options['prep_enabled'] ) ) {
				return false;
			}

			$key = isset( $options['prep_meta_key'] ) ? trim( (string) $options['prep_meta_key'] ) : '';
			$val = isset( $options['prep_value'] ) ? (string) $options['prep_value'] : '';
			if ( '' === $key || '' === $val ) {
				return false;
			}

			if ( ! self::is_printed( $product->get_sku(), isset( $options['printed_pattern'] ) ? $options['printed_pattern'] : null ) ) {
				return false;
			}

			$current = $product->get_meta( $key );

			if ( (string) $current === $val ) {
				return false;
			}

			if ( ! empty( $options['prep_only_empty'] ) && '' !== (string) $current && null !== $current ) {
				return false;
			}

			return true;
		}

		/**
		 * Force-apply the preparation time to one printed product.
		 *
		 * @return bool True when the meta was changed.
		 */
		public static function apply_prep_time( $product_id, $options = null ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return false;
			}
			if ( null === $options ) {
				$options = self::get_options();
			}
			if ( ! self::should_set_prep_time( $product, $options ) ) {
				return false;
			}

			$product->update_meta_data( $options['prep_meta_key'], $options['prep_value'] );
			$product->save();

			return true;
		}

		/** Legacy callback retained for compatibility; it is no longer registered. */
		public static function sync_post( $post_id, $post, $update ) {
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			if ( 'auto-draft' === $post->post_status ) {
				return;
			}
			self::sync_product( $post_id );
		}
	}
}
