<?php
/**
 * کدهای تخفیف — روی کوپن‌های خود ووکامرس (shop_coupon). این کلاس فقط ساخت/مدیریت را
 * داخل افزونه می‌آورد و یک قابلیت اضافه دارد: «رند به ۸» مبلغ نهایی سبد بعد از اعمال کد.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Coupons' ) ) {

	final class TCP_Coupons {

		const ACTION_SAVE     = 'tcp_coupon_save';
		const ACTION_GENERATE = 'tcp_coupon_generate';
		const ACTION_TOGGLE   = 'tcp_coupon_toggle';
		const ACTION_DELETE   = 'tcp_coupon_delete';
		const ACTION_EXPORT   = 'tcp_coupon_export';
		const META_ROUND      = '_tcp_round_to_8';
		const META_BATCH      = '_tcp_batch';

		public static function hooks() {
			TCP_Coupon_Phones::hooks();
			TCP_Coupon_Reports::hooks();
			add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'save' ) );
			add_action( 'admin_post_' . self::ACTION_GENERATE, array( __CLASS__, 'generate' ) );
			add_action( 'admin_post_' . self::ACTION_TOGGLE, array( __CLASS__, 'toggle' ) );
			add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'delete' ) );
			add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'export_csv' ) );

			if ( class_exists( 'WooCommerce' ) ) {
				// رند به ۸: ووکامرس اول اقلام و کوپن‌ها را حساب می‌کند، بعد fee ها را؛ اینجا اختلاف تا
				// عدد رند پایین‌تر را به‌صورت یک خط تخفیف منفی اضافه می‌کنیم.
				add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'round_fee' ), 999 );
			}
		}

		/* -----------------------------------------------------------------
		 * خواندن
		 * --------------------------------------------------------------- */

		public static function types() {
			return array(
				'percent'       => 'درصدی روی سبد',
				'fixed_cart'    => 'مبلغ ثابت روی سبد',
				'fixed_product' => 'مبلغ ثابت روی هر محصول',
			);
		}

		/**
		 * فهرست کوپن‌ها با وضعیت محاسبه‌شده.
		 *
		 * @return array[] هر آیتم: id, code, type, amount, status(active|off|expired|used_up), usage, limit, expires, free_shipping, round, batch
		 */
		public static function list_coupons( $search = '', $batch = '', $limit = 300 ) {
			$args = array(
				'post_type'      => 'shop_coupon',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			);
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
			if ( '' !== $batch ) {
				$args['meta_key']   = self::META_BATCH; // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['meta_value'] = $batch;           // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			$q   = new WP_Query( $args );
			$out = array();
			foreach ( $q->posts as $id ) {
				$c = new WC_Coupon( $id );
				if ( ! $c->get_id() ) {
					continue;
				}
				$out[] = self::row( $c );
			}
			return $out;
		}

		public static function row( WC_Coupon $c ) {
			$expires = $c->get_date_expires();
			$limit   = (int) $c->get_usage_limit();
			$usage   = (int) $c->get_usage_count();
			$phone_policy = TCP_Coupon_Phones::policy( $c );
			if ( $phone_policy['enabled'] ) {
				$limit = $phone_policy['total'];
				$usage = TCP_Coupon_Phones::count( $c->get_id() );
			}
			$status  = 'active';
			if ( 'publish' !== get_post_status( $c->get_id() ) ) {
				$status = 'off';
			} elseif ( $expires && $expires->getTimestamp() < time() ) {
				$status = 'expired';
			} elseif ( $limit > 0 && $usage >= $limit ) {
				$status = 'used_up';
			}
			return array(
				'id'            => $c->get_id(),
				'code'          => $c->get_code(),
				'type'          => $c->get_discount_type(),
				'amount'        => (float) $c->get_amount(),
				'status'        => $status,
				'usage'         => $usage,
				'limit'         => $limit,
				'expires'       => $expires ? $expires->date_i18n( 'Y/m/d' ) : '',
				'free_shipping' => (bool) $c->get_free_shipping(),
				'round'         => 'yes' === $c->get_meta( self::META_ROUND ),
				'batch'         => (string) $c->get_meta( self::META_BATCH ),
				'min'           => (float) $c->get_minimum_amount(),
				'max'           => (float) $c->get_maximum_amount(),
				'per_user'      => (int) $c->get_usage_limit_per_user(),
				'individual'    => (bool) $c->get_individual_use(),
				'products'      => $c->get_product_ids(),
				'excl_products' => $c->get_excluded_product_ids(),
				'cats'          => $c->get_product_categories(),
				'excl_cats'     => $c->get_excluded_product_categories(),
				'emails'        => $c->get_email_restrictions(),
				'excl_sale'     => (bool) $c->get_exclude_sale_items(),
				'description'   => $c->get_description(),
				'phone_policy' => TCP_Coupon_Phones::policy( $c ),
			);
		}

		public static function status_label( $s ) {
			$m = array( 'active' => 'فعال', 'off' => 'غیرفعال', 'expired' => 'منقضی', 'used_up' => 'تمام‌شده' );
			return isset( $m[ $s ] ) ? $m[ $s ] : $s;
		}

		/** گروه‌های تولید انبوه: batch => تعداد. */
		public static function batches() {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_value AS b, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = 'shop_coupon' AND p.post_status IN ('publish','draft') GROUP BY pm.meta_value ORDER BY MAX(p.post_date) DESC LIMIT 50", self::META_BATCH ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out  = array();
			foreach ( (array) $rows as $r ) {
				$out[ (string) $r['b'] ] = (int) $r['n'];
			}
			return $out;
		}

		/* -----------------------------------------------------------------
		 * نوشتن
		 * --------------------------------------------------------------- */

		private static function guard( $action ) {
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( $action );
			if ( ! class_exists( 'WC_Coupon' ) ) {
				wp_die( 'ووکامرس فعال نیست.' );
			}
		}

		private static function back( $flag, $extra = array() ) {
			wp_safe_redirect( TCP_Admin::url( 'coupons', array_merge( array( $flag => 1 ), $extra ) ) );
			exit;
		}

		private static function ids_from( $key ) {
			return isset( $_POST[ $key ] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		/** فیلدهای مشترک فرم → روی شیء کوپن. */
		private static function fill( WC_Coupon $c, $p ) {
			$type = isset( $p['discount_type'] ) ? sanitize_key( $p['discount_type'] ) : 'percent';
			$type = array_key_exists( $type, self::types() ) ? $type : 'percent';
			$amt  = TCP_Ops::number( isset( $p['amount'] ) ? $p['amount'] : 0 );
			$amt  = null === $amt ? 0 : max( 0, $amt );
			if ( 'percent' === $type ) {
				$amt = min( 100, $amt );
			}
			$c->set_discount_type( $type );
			$c->set_amount( $amt );
			$c->set_description( isset( $p['description'] ) ? sanitize_text_field( $p['description'] ) : '' );
			$c->set_free_shipping( ! empty( $p['free_shipping'] ) );
			$c->set_individual_use( ! empty( $p['individual_use'] ) );
			$c->set_exclude_sale_items( ! empty( $p['exclude_sale_items'] ) );

			$exp = isset( $p['expires'] ) ? trim( (string) $p['expires'] ) : '';
			$c->set_date_expires( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $exp ) ? strtotime( $exp . ' 23:59:59' ) : null );

			$min = TCP_Ops::number( isset( $p['minimum_amount'] ) ? $p['minimum_amount'] : '' );
			$max = TCP_Ops::number( isset( $p['maximum_amount'] ) ? $p['maximum_amount'] : '' );
			$c->set_minimum_amount( $min > 0 ? $min : '' );
			$c->set_maximum_amount( $max > 0 ? $max : '' );

			$c->set_usage_limit( isset( $p['usage_limit'] ) ? absint( $p['usage_limit'] ) : 0 );
			$c->set_usage_limit_per_user( isset( $p['usage_limit_per_user'] ) ? absint( $p['usage_limit_per_user'] ) : 0 );

			$c->set_product_ids( self::ids_from( 'product_ids' ) );
			$c->set_excluded_product_ids( self::ids_from( 'exclude_product_ids' ) );
			$c->set_product_categories( self::ids_from( 'product_categories' ) );
			$c->set_excluded_product_categories( self::ids_from( 'exclude_product_categories' ) );

			$emails = isset( $p['emails'] ) ? array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $p['emails'] ) ) ) : array();
			$c->set_email_restrictions( array_values( $emails ) );

			TCP_Coupon_Phones::save_policy( $c, $p );
			$c->update_meta_data( self::META_ROUND, empty( $p['round_to_8'] ) ? 'no' : 'yes' );
		}

		public static function save() {
			self::guard( self::ACTION_SAVE );
			$p    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$code = isset( $p['code'] ) ? wc_format_coupon_code( sanitize_text_field( $p['code'] ) ) : '';
			$id   = isset( $p['coupon_id'] ) ? absint( $p['coupon_id'] ) : 0;
			if ( '' === $code ) {
				self::back( 'err', array( 'msg' => 'nocode' ) );
			}
			$existing = wc_get_coupon_id_by_code( $code, $id );
			if ( $existing && $existing !== $id ) {
				self::back( 'err', array( 'msg' => 'dupe' ) );
			}
			if ( $id && 'shop_coupon' !== get_post_type( $id ) ) { wp_die( 'کد تخفیف معتبر نیست.' ); }
			$c = new WC_Coupon( $id );
			$c->set_code( $code );
			self::fill( $c, $p );
			$c->set_status( 'publish' );
			$c->save();
			self::back( 'saved', array( 'edit' => $c->get_id() ) );
		}

		/** تولید انبوه کدهای یک‌بارمصرف. */
		public static function generate() {
			self::guard( self::ACTION_GENERATE );
			$p      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$count  = isset( $p['count'] ) ? max( 1, min( 1000, absint( $p['count'] ) ) ) : 10;
			$prefix = isset( $p['prefix'] ) ? strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $p['prefix'] ) ) : '';
			$len    = isset( $p['length'] ) ? max( 4, min( 16, absint( $p['length'] ) ) ) : 8;
			$batch  = $prefix ? $prefix . '-' . gmdate( 'ymd-Hi' ) : 'B' . gmdate( 'ymd-Hi' );
			$made   = 0;
			$tries  = 0;
			$alpha  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // بدون I/O/0/1
			while ( $made < $count && $tries < $count * 5 ) {
				$tries++;
				$rand = '';
				for ( $i = 0; $i < $len; $i++ ) {
					$rand .= $alpha[ wp_rand( 0, strlen( $alpha ) - 1 ) ];
				}
				$code = wc_format_coupon_code( $prefix ? $prefix . '-' . $rand : $rand );
				if ( wc_get_coupon_id_by_code( $code ) ) {
					continue;
				}
				$c = new WC_Coupon();
				$c->set_code( $code );
				self::fill( $c, $p );
				$c->set_usage_limit( 1 );
				$c->set_usage_limit_per_user( 1 );
				$c->update_meta_data( self::META_BATCH, $batch );
				$c->set_status( 'publish' );
				$c->save();
				$made++;
			}
			self::back( 'generated', array( 'n' => $made, 'batch' => $batch ) );
		}

		public static function toggle() {
			self::guard( self::ACTION_TOGGLE );
			$id = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;
			if ( $id && 'shop_coupon' === get_post_type( $id ) ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' === get_post_status( $id ) ? 'draft' : 'publish' ) );
			}
			self::back( 'saved' );
		}

		public static function delete() {
			self::guard( self::ACTION_DELETE );
			$ids   = isset( $_POST['coupon_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['coupon_ids'] ) ) : array();
			$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
			if ( '' !== $batch ) {
				$ids = array_merge( $ids, wp_list_pluck( self::list_coupons( '', $batch, 2000 ), 'id' ) );
			}
			$n = 0;
			foreach ( array_unique( array_filter( $ids ) ) as $id ) {
				if ( 'shop_coupon' === get_post_type( $id ) && wp_delete_post( $id, true ) ) {
					$n++;
				}
			}
			self::back( 'deleted', array( 'n' => $n ) );
		}

		public static function export_csv() {
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز.' );
			}
			check_admin_referer( self::ACTION_EXPORT );
			$batch = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
			$rows  = self::list_coupons( '', $batch, 5000 );
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="coupons-' . ( $batch ? $batch : 'all' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' );
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array( 'code', 'type', 'amount', 'status', 'usage', 'limit', 'expires', 'batch' ) );
			foreach ( $rows as $r ) {
				fputcsv( $out, array( TCP_Settings::csv_cell( $r['code'] ), $r['type'], $r['amount'], $r['status'], $r['usage'], $r['limit'], $r['expires'], TCP_Settings::csv_cell( $r['batch'] ) ) );
			}
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}

		/* -----------------------------------------------------------------
		 * رند به ۸ در سبد
		 * --------------------------------------------------------------- */

		public static function round_fee( $cart ) {
			if ( ! $cart instanceof WC_Cart || ( is_admin() && ! wp_doing_ajax() ) ) {
				return;
			}
			$has = false;
			foreach ( $cart->get_applied_coupons() as $code ) {
				$c = new WC_Coupon( $code );
				if ( $c->get_id() && 'yes' === $c->get_meta( self::META_ROUND ) ) {
					$has = true;
					break;
				}
			}
			if ( ! $has ) {
				return;
			}
			// مبنا: جمع اقلام بعد از تخفیف کدها (بدون حمل)؛ مطابق قیمت‌هایی که مشتری می‌بیند.
			$incl  = $cart->display_prices_including_tax();
			$goods = $incl
				? (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - (float) $cart->get_discount_total() - (float) $cart->get_discount_tax()
				: (float) $cart->get_subtotal() - (float) $cart->get_discount_total();
			$step = TCP_Round::step();
			if ( $goods < 2 * $step ) {
				return;
			}
			$target = TCP_Round::nearest( $goods );
			$diff   = round( $target - $goods, wc_get_price_decimals() );
			if ( 0.0 === (float) $diff ) {
				return;
			}
			// مثبت = چند واحد تا ۸ بالاتر؛ منفی = تا ۸ پایین‌تر. هر کدام نزدیک‌تر باشد.
			$cart->add_fee( 'رند قیمت', $diff, false );
		}
	}
}
