<?php
/**
 * رابط کاربری ادمین: منو، استایل/اسکریپت و رندر صفحهٔ سه‌گام.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TBSM_Admin' ) ) {

	final class TBSM_Admin {

		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 30 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_check_notice' ) );
		}

		/**
		 * آیا صفحهٔ فعلی، صفحهٔ این افزونه است؟
		 */
		public static function is_our_screen() {
			if ( isset( $_GET['page'] ) && TBSM_Core::PAGE_SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->id ) && false !== strpos( $screen->id, TBSM_Core::PAGE_SLUG ) ) {
					return true;
				}
			}
			return false;
		}

		public static function register_menus() {
			if ( ! TBSM_Core::can() ) {
				return;
			}

			// زیرمنوی ووکامرس
			add_submenu_page(
				'woocommerce',
				'مدیریت انبوه موجودی',
				'موجودی TisaCase',
				'manage_woocommerce',
				TBSM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);

			// میان‌بر در منوی محصولات
			add_submenu_page(
				'edit.php?post_type=product',
				'مدیریت انبوه موجودی',
				'موجودی انبوه',
				'manage_woocommerce',
				TBSM_Core::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		public static function woocommerce_check_notice() {
			if ( self::is_our_screen() && ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-error"><p><strong>مدیریت انبوه موجودی TisaCase:</strong> برای استفاده از این افزونه، نصب و فعال‌سازی ووکامرس الزامی است.</p></div>';
			}
		}

		public static function enqueue_assets( $hook = '' ) {
			if ( ! self::is_our_screen() && false === strpos( (string) $hook, TBSM_Core::PAGE_SLUG ) ) {
				return;
			}

			$css_deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();

			$css_file = TBSM_PATH . 'assets/admin.css';
			$ver      = TBSM_VERSION . '.' . ( file_exists( $css_file ) ? filemtime( $css_file ) : time() );

			wp_enqueue_style(
				'tbsm-admin-css',
				TBSM_URL . 'assets/admin.css',
				$css_deps,
				$ver
			);

			wp_enqueue_script(
				'tbsm-admin-js',
				TBSM_URL . 'assets/admin.js',
				array( 'jquery' ),
				$ver,
				true
			);

			wp_localize_script(
				'tbsm-admin-js',
				'tbsmData',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( TBSM_Core::NONCE_ACTION ),
					'i18n'    => array(
						'searching'          => 'در حال جست‌وجو…',
						'noResults'          => 'محصولی با این مشخصات پیدا نشد.',
						'searchError'        => 'جست‌وجو ناموفق بود.',
						'variable'           => 'متغیر',
						'simple'             => 'ساده',
						'draft'              => 'پیش‌نویس',
						'pending'            => 'در انتظار',
						'private'            => 'خصوصی',
						'variationsUnit'     => 'متغیر',
						'simpleNote'         => 'محصول ساده — موجودی خودِ محصول قابل ویرایش است.',
						'allVariations'      => 'همهٔ متغیرها',
						'shownOf'            => 'نمایش: %1$d از %2$d متغیر',
						'selectedCount'      => 'انتخاب‌شده: %d',
						'selectAtLeastOne'   => 'حداقل یک کارت را تیک بزنید تا موجودی‌اش اعمال شود.',
						'invalidInputs'      => 'موجودی %d کارت عدد معتبر نیست؛ آن‌ها را بررسی کنید.',
						'applyBtn'           => 'اعمال موجودی روی %d متغیر',
						'applyConfirm'       => 'تأیید: اعمال روی %d متغیر؟',
						'applying'           => 'در حال اعمال…',
						'loadError'          => 'بارگذاری محصول ناموفق بود.',
						'changeProduct'      => 'تغییر محصول',
						'stockLabel'         => 'موجودی',
						'manageOffNote'      => 'موجودی‌گیری خاموش است — با اعمال روشن می‌شود',
						'reportTitle'        => 'موجودی %d متغیر اعمال شد',
						'parentSynced'       => 'وضعیت موجودی والد به «%s» همگام شد.',
						'inStockLabel'       => 'موجود',
						'outOfStockLabel'    => 'ناموجود',
						'enabledManage'      => 'موجودی‌گیری برای %d متغیر خاموش بود و هنگام اعمال روشن شد.',
						'errorCount'         => '%d مورد خطا داشت:',
						'applyError'         => 'اعمال ناموفق بود: %s',
						'errorApplyEmpty'    => 'مقداری برای اعمال پیدا نشد.',
					),
				)
			);
		}

		/**
		 * آیکون SVG (هم‌پایهٔ مجموعهٔ آیکون‌های هاب).
		 *
		 * @param string $name   کلید آیکون.
		 * @param string $class  کلاس.
		 * @return string
		 */
		private static function icon( $name, $class = '' ) {
			$paths = array(
				'box'    => '<path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/>',
				'search' => '<path d="M11 19a8 8 0 100-16 8 8 0 000 16zM21 21l-4.5-4.5"/>',
				'box-s'  => '<path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/>',
			);
			$set = isset( $paths[ $name ] ) ? $paths[ $name ] : $paths['box'];
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . $set . '</svg>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		public static function render_page() {
			if ( ! TBSM_Core::can() ) {
				wp_die( esc_html( 'دسترسی غیرمجاز است.' ) );
			}
			?>
			<div class="wrap tbsm-wrap" dir="rtl">
				<div class="tbsm-hero" role="banner">
					<div class="tbsm-hero-row">
						<span class="tbsm-hero-mark"><?php echo self::icon( 'box' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="tbsm-hero-text">
							<h1 class="tbsm-hero-title">مدیریت انبوه موجودی</h1>
							<p class="tbsm-hero-sub">محصول را با جست‌وجو انتخاب کنید، دستهٔ متغیرها را با سلکت فیلتر کنید، موجودی را روی کارت‌ها تغییر دهید و یک‌جا اعمال کنید.</p>
						</div>
						<span class="tbsm-hero-ver" dir="ltr">v<?php echo esc_html( TBSM_VERSION ); ?></span>
					</div>
				</div>

				<div class="tbsm-notice" id="tbsm-notice" hidden role="alert"></div>

				<!-- گام ۱: انتخاب محصول -->
				<div class="tbsm-card">
					<div class="tbsm-step-head">
						<span class="tbsm-step-num">۱</span>
						<div class="tbsm-step-titles">
							<h2 class="tbsm-step-title">انتخاب محصول</h2>
							<p class="tbsm-step-sub">جست‌وجو بر اساس نام، SKU یا شناسه</p>
						</div>
					</div>
					<div class="tbsm-search">
						<span class="tbsm-search-icon"><?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<input type="search" id="tbsm-search" class="tbsm-input tbsm-search-input" placeholder="نام یا SKU محصول را بنویسید…" autocomplete="off">
					</div>
					<div id="tbsm-results" class="tbsm-results" hidden></div>
					<div id="tbsm-selected" class="tbsm-selected" hidden></div>
				</div>

				<!-- گام ۲: دستهٔ متغیرها -->
				<div class="tbsm-card tbsm-step-locked" id="tbsm-step-category">
					<div class="tbsm-step-head">
						<span class="tbsm-step-num">۲</span>
						<div class="tbsm-step-titles">
							<h2 class="tbsm-step-title">دستهٔ متغیرها</h2>
							<p class="tbsm-step-sub">با انتخاب دسته، فقط همان متغیرها نشان داده و تیک می‌خورند؛ می‌توانید تیک‌ها را دستی جابه‌جا کنید.</p>
						</div>
					</div>
					<select id="tbsm-cat-select" class="tbsm-input tbsm-cat-select" disabled>
						<option value="all">—</option>
					</select>
					<div class="tbsm-counts" id="tbsm-counts"></div>
				</div>

				<!-- گام ۳: کارت‌های متغیرها -->
				<div class="tbsm-card tbsm-step-locked" id="tbsm-step-cards">
					<div class="tbsm-step-head">
						<span class="tbsm-step-num">۳</span>
						<div class="tbsm-step-titles">
							<h2 class="tbsm-step-title">کارت‌های متغیرها</h2>
							<p class="tbsm-step-sub">موجودیِ انتخابی‌ها را تغییر دهید و با یک کلیک اعمال کنید</p>
						</div>
					</div>
					<div class="tbsm-toolbar">
						<div class="tbsm-quickset">
							<label for="tbsm-quickset">درج روی همهٔ انتخابی:</label>
							<input type="number" id="tbsm-quickset" class="tbsm-input tbsm-num" min="0" inputmode="numeric" placeholder="مثلاً ۰">
							<button type="button" class="tisa-btn tbsm-btn-quick" id="tbsm-quickset-btn" disabled>درج</button>
						</div>
						<button type="button" class="tisa-btn tisa-btn--primary tbsm-btn-apply" id="tbsm-apply" disabled>اعمال موجودی</button>
					</div>
					<div class="tbsm-grid" id="tbsm-grid" hidden></div>
					<div class="tbsm-report" id="tbsm-report" hidden></div>
				</div>
			</div>
			<?php
		}
	}
}
