<?php
/**
 * رابط پیشخوان: یک صفحه با چهار تب (قوانین داینامیک / تغییر گروهی / گزارش و بازگردانی / تنظیمات).
 * منطق در Rules/Ops/Ajax است؛ اینجا فقط منو، asset و رندر view ها.
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCP_Admin' ) ) {

	final class TCP_Admin {

		const TABS = array( 'rules', 'bulk', 'coupons', 'runs', 'settings' );

		public static function tabs() {
			return array(
				'rules'    => 'قوانین داینامیک',
				'bulk'     => 'تغییر گروهی قیمت',
				'coupons'  => 'کد تخفیف',
				'runs'     => 'گزارش و بازگردانی',
				'settings' => 'تنظیمات',
			);
		}

		public static function current_tab() {
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rules'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return in_array( $tab, self::TABS, true ) ? $tab : 'rules';
		}

		public static function url( $tab = 'rules', $extra = array() ) {
			$args = array_merge( array( 'page' => TCP_Settings::MAIN_PAGE, 'tab' => $tab ), $extra );
			return add_query_arg( $args, admin_url( 'admin.php' ) );
		}

		public static function is_our_screen() {
			return isset( $_GET['page'] ) && TCP_Settings::MAIN_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		/* -----------------------------------------------------------------
		 * منو / لینک‌ها / نوتیس
		 * --------------------------------------------------------------- */

		public static function menus() {
			if ( ! TCP_Settings::can() ) {
				return;
			}
			add_submenu_page(
				'woocommerce',
				'قیمت‌گذاری TisaCase',
				'قیمت‌گذاری TisaCase',
				TCP_Settings::setting( 'min_capability' ),
				TCP_Settings::MAIN_PAGE,
				array( __CLASS__, 'render' )
			);
		}

		public static function action_links( $links ) {
			array_unshift( $links, '<a href="' . esc_url( self::url( 'rules' ) ) . '">قوانین</a>', '<a href="' . esc_url( self::url( 'bulk' ) ) . '">تغییر گروهی</a>', '<a href="' . esc_url( self::url( 'coupons' ) ) . '">کد تخفیف</a>' );
			return $links;
		}

		public static function notices() {
			if ( ! TCP_Settings::can() || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$legacy = TCP_Settings::legacy_active();
			if ( ! empty( $legacy ) ) {
				echo '<div class="notice notice-warning"><p><strong>TisaCase Pricing:</strong> افزونه‌های قدیمی («' . esc_html( implode( '» و «', $legacy ) ) . '») هنوز فعال‌اند و همان فیلترهای قیمت را دوباره اعمال می‌کنند. آن‌ها را غیرفعال و حذف کن.</p></div>';
			}
			if ( self::is_our_screen() && ! TCP_Settings::wc_active() ) {
				echo '<div class="notice notice-error"><p>ووکامرس باید فعال باشد.</p></div>';
			}
		}

		public static function icon_svg() {
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'
				. '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/>'
				. '<circle cx="7.5" cy="7.5" r="1.4" fill="currentColor" stroke="none"/>'
				. '</svg>';
		}

		/** ذخیرهٔ تنظیمات — روی admin_init تا پیش از هر خروجی بتوان redirect کرد. */
		public static function handle_settings_post() {
			if ( empty( $_POST['tcp_settings_save'] ) || ! self::is_our_screen() ) {
				return;
			}
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			check_admin_referer( TCP_Settings::NONCE, '_wpnonce' );
			TCP_Settings::update_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- در update_settings پاک‌سازی می‌شود.
			wp_safe_redirect( self::url( 'settings', array( 'saved' => 1 ) ) );
			exit;
		}

		/* -----------------------------------------------------------------
		 * asset ها
		 * --------------------------------------------------------------- */

		/**
		 * select2 / enhanced-select ووکامرس را برای فیلدهای جستجوی دسته و محصول تضمین می‌کند.
		 *
		 * نکته: در ووکامرس هندل «select2» فقط یک اسکریپت است (legacy alias → `wc-select2`) و
		 * CSS سلکت۲ داخل فایل admin.css (هندل `woocommerce_admin_styles`) گنجانده شده است.
		 * اگر جایی آن استایل حذف/جابه‌جا شود (افزونه‌های بهینه‌ساز ادمین این کار را می‌کنند)،
		 * منوی کشویی بدون CSS می‌ماند: نه باز می‌شود و نه آیتمی نشان می‌دهد. پس همان فایل
		 * select2.css خود ووکامرس را هم (در صورت نبود هندل اختصاصی) مستقیم صف می‌کنیم.
		 *
		 * @return string نام هندل استایلی که باید وابستگی استایل افزونه شود (یا '').
		 */
		private static function enhanced_select_assets() {
			if ( ! TCP_Settings::wc_active() ) {
				return '';
			}

			if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' ); // شامل CSS سلکت۲.
				wp_enqueue_script( 'wc-enhanced-select' );
			} elseif ( wp_script_is( 'select2', 'registered' ) ) {
				wp_enqueue_script( 'select2' );
			} else {
				return '';
			}

			if ( wp_style_is( 'select2', 'registered' ) ) {
				wp_enqueue_style( 'select2' );
				return 'select2';
			}

			if ( function_exists( 'WC' ) && WC() ) {
				$rel = '/assets/css/select2.css';
				if ( file_exists( WC()->plugin_path() . $rel ) ) {
					wp_enqueue_style( 'tcp-select2', WC()->plugin_url() . $rel, array(), '4.0.3' );
					return 'tcp-select2';
				}
			}

			return 'woocommerce_admin_styles';
		}

		public static function assets() {
			if ( ! self::is_our_screen() ) {
				return;
			}
			$tab  = self::current_tab();
			$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();

			// تب‌های «تغییر گروهی قیمت» و «کد تخفیف» فیلد دسته‌بندی/محصول دارند.
			if ( 'bulk' === $tab || 'coupons' === $tab ) {
				$select2_style = self::enhanced_select_assets();
				if ( $select2_style ) {
					$deps[] = $select2_style;
				}
			}

			wp_enqueue_style( 'tcp-admin', TCP_URL . 'assets/admin.css', $deps, TCP_VERSION );

			if ( 'rules' === $tab ) {
				wp_enqueue_script( 'tcp-rules', TCP_URL . 'assets/rules.js', array( 'jquery' ), TCP_VERSION, true );
				wp_localize_script(
					'tcp-rules',
					'TCP_RULES',
					array(
						'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
						'nonce'      => wp_create_nonce( TCP_Rules::SEARCH_NONCE ),
						'minChars'   => 2,
						'productAct' => TCP_Rules::AJAX_PRODUCTS,
						'catAct'     => TCP_Rules::AJAX_CATS,
						'modes'      => TCP_Rules::modes(),
					)
				);
				return;
			}

			if ( 'coupons' === $tab ) {
				wp_enqueue_script( 'tcp-coupons', TCP_URL . 'assets/coupons.js', array( 'jquery' ), TCP_VERSION, true );
				wp_localize_script( 'tcp-coupons', 'TCP_COUPONS', array( 'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '' ) );
				return;
			}

			wp_enqueue_script( 'tcp-bulk', TCP_URL . 'assets/bulk.js', array( 'jquery' ), TCP_VERSION, true );
			wp_localize_script( 'tcp-bulk', 'TCP_BULK', self::bulk_data( $tab ) );
		}

		private static function bulk_data( $tab ) {
			$ops = array();
			foreach ( TCP_Ops::ops() as $slug => $meta ) {
				$ops[ $slug ] = array(
					'label'  => $meta[0],
					'kind'   => $meta[1],
					'group'  => $meta[2],
					'cap100' => in_array( $slug, array( 'regular_decrease_percent', 'sale_discount_percent', 'wholesale_decrease_percent', 'wholesale_from_retail_percent' ), true ),
				);
			}
			return array(
				'page'     => TCP_Settings::MAIN_PAGE,
				'tab'      => $tab,
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( TCP_Settings::NONCE ),
				'currency' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
				'actions'  => array(
					'preview'        => TCP_Settings::AJAX_PREVIEW,
					'run'            => TCP_Settings::AJAX_RUN,
					'finish'         => TCP_Settings::AJAX_FINISH,
					'rollback_start' => TCP_Settings::AJAX_ROLLBACK_START,
					'rollback_page'  => TCP_Settings::AJAX_ROLLBACK_PAGE,
					'export'         => TCP_Settings::AJAX_EXPORT,
					'cancel'         => TCP_Settings::AJAX_CANCEL,
					'search'         => TCP_Settings::AJAX_SEARCH,
					'nameSearch'     => TCP_Settings::AJAX_PRODUCT_NAME_SEARCH,
					'skuSearch'      => TCP_Settings::AJAX_PRODUCT_SKU_SEARCH,
				),
				'ops'      => $ops,
				'filters'  => array(
					'types'    => self::product_types(),
					'statuses' => self::post_statuses(),
				),
				'limits'   => array(
					'threshold'        => TCP_Settings::confirm_threshold(),
					'batch'            => TCP_Settings::batch_size(),
					'sample'           => TCP_Settings::sample_size(),
					'maxAmount'        => TCP_Settings::max_amount(),
					'percentMax'       => TCP_Settings::PERCENT_CEIL,
					'logging'          => (int) TCP_Settings::logging_enabled(),
					'scheduledEnabled' => (int) ! empty( TCP_Settings::setting( 'scheduled' ) ),
					'rollbackEnabled'  => (int) TCP_Settings::rollback_enabled(),
				),
			);
		}

		public static function product_types() {
			return array( 'simple' => 'ساده', 'variable' => 'متغیر', 'grouped' => 'گروهی', 'external' => 'خارجی' );
		}

		public static function post_statuses() {
			return array( 'publish' => 'انتشار یافته', 'private' => 'خصوصی', 'draft' => 'پیش‌نویس', 'pending' => 'در انتظار بررسی', 'future' => 'زمان‌بندی‌شده' );
		}

		/** مسیر کامل دسته (والد ← فرزند) برای select. */
		public static function cat_label( $term ) {
			$parts  = array( $term->name );
			$parent = absint( $term->parent );
			$guard  = 0;
			while ( $parent && $guard < 10 ) {
				$p = get_term( $parent, 'product_cat' );
				if ( ! $p || is_wp_error( $p ) ) {
					break;
				}
				array_unshift( $parts, $p->name );
				$parent = absint( $p->parent );
				$guard++;
			}
			return implode( ' ← ', $parts );
		}

		/* -----------------------------------------------------------------
		 * رندر
		 * --------------------------------------------------------------- */

		public static function render() {
			if ( ! TCP_Settings::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			$tab = self::current_tab();

			if ( 'runs' === $tab ) {
				// اجراهای running که بیش از حدِ قفل به‌روز نشده‌اند interrupted می‌شوند تا دکمهٔ ادامه درست دیده شود.
				TCP_DB::busy_slot( 0 );
			}

			$view = TCP_DIR . 'views/' . $tab . '.php';
			?>
			<div class="wrap tisa-wrap tcp-wrap" dir="rtl">
				<header class="tcp-hero">
					<div class="tcp-hero-row">
						<div class="tcp-hero-mark" aria-hidden="true"><?php echo self::icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<div class="tcp-hero-text">
							<h1 class="tcp-hero-title">قیمت‌گذاری TisaCase</h1>
							<p class="tcp-hero-sub">قوانین داینامیک نمایش قیمت و تغییر گروهی امن قیمت‌ها، در یک‌جا</p>
						</div>
						<span class="tcp-hero-ver" dir="ltr">v<?php echo esc_html( TCP_VERSION ); ?></span>
					</div>
					<nav class="tcp-tabs" role="tablist">
						<?php foreach ( self::tabs() as $key => $label ) : ?>
							<a class="tcp-tab<?php echo $tab === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>
				</header>
				<?php self::flash(); ?>
				<div class="tcp-body">
					<?php
					if ( file_exists( $view ) ) {
						include $view;
					}
					?>
				</div>
			</div>
			<?php
		}

		/** پیام‌های یک‌بارمصرف بعد از redirect. */
		private static function flash() {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['saved'] ) ) {
				self::flash_box( 'success', 'rules' === self::current_tab() ? 'قوانین ذخیره شد و کش قیمت محصولات متغیر نسخهٔ جدید گرفت.' : ( 'coupons' === self::current_tab() ? 'کد تخفیف ذخیره شد.' : 'تنظیمات ذخیره شد.' ) );
			}
			if ( isset( $_GET['generated'] ) ) {
				self::flash_box( 'success', number_format_i18n( absint( $_GET['n'] ?? 0 ) ) . ' کد ساخته شد' . ( ! empty( $_GET['batch'] ) ? ' (گروه ' . sanitize_text_field( wp_unslash( $_GET['batch'] ) ) . ')' : '' ) . '. با فیلتر گروه می‌توانی CSV بگیری.' );
			}
			if ( isset( $_GET['deleted'] ) ) {
				self::flash_box( 'success', number_format_i18n( absint( $_GET['n'] ?? 0 ) ) . ' کد حذف شد.' );
			}
			if ( isset( $_GET['err'] ) ) {
				$msg = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
				self::flash_box( 'error', 'dupe' === $msg ? 'این کد قبلاً وجود دارد.' : 'کد را وارد کن.' );
			}
			if ( isset( $_GET['synced'] ) ) {
				self::flash_box( 'success', 'قانون سراسری «۱۰٪ افزایش + ۱۰٪ فروش ویژه» برای همهٔ محصولات فعلی و آینده فعال شد.' );
			}
			// phpcs:enable
		}

		private static function flash_box( $type, $text ) {
			echo '<div class="tcp-flashbar tcp-flashbar--' . esc_attr( $type ) . '" role="status">' . esc_html( $text ) . '</div>';
		}
	}
}
