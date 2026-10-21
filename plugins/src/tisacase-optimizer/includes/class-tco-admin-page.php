<?php
/**
 * صفحهٔ مدیریت پیشخوان، یکپارچه‌سازی با TisaCase Hub و بارگذاری استایل/اسکریپت.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Admin_Page' ) ) {

	/**
	 * کنترلر صفحهٔ مدیریت مرکز بهینه‌سازی تیساکیس.
	 */
	final class TisaCase_Optimizer_Admin_Page {

		const SLUG = 'tisacase-optimizer';

		/** @var string پسوند hook صفحهٔ ثبت‌شده */
		private static $hook_suffix = '';

		/**
		 * راه‌اندازی قلاب‌های پیشخوان.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 58 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
			add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ), 20 );
			add_filter( 'tisacase_hub_items', array( __CLASS__, 'register_hub_item' ) );
			add_filter( 'plugin_action_links_' . TCO_BASENAME, array( __CLASS__, 'action_links' ) );
		}

		/**
		 * ثبت منوی افزونه زیر ووکامرس (و در نبود ووکامرس زیر رسانه تا همیشه در دسترس باشد).
		 *
		 * @return void
		 */
		public static function register_menu() {
			$cap    = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';
			$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'upload.php';

			self::$hook_suffix = (string) add_submenu_page(
				$parent,
				__( 'مرکز بهینه‌سازی تیساکیس', 'tisacase-optimizer' ),
				__( 'مرکز بهینه‌سازی', 'tisacase-optimizer' ),
				$cap,
				self::SLUG,
				array( __CLASS__, 'render_page' )
			);
		}

		/**
		 * معرفی کامل به لانچر `tisacase-hub` در گروه «بهینه‌سازی و نگهداری».
		 *
		 * @param array<int,array<string,mixed>> $items آیتم‌های هاب.
		 * @return array<int,array<string,mixed>>
		 */
		public static function register_hub_item( $items ) {
			if ( ! is_array( $items ) ) {
				$items = array();
			}
			$items['optimizer'] = array(
				'key'     => 'optimizer',
				'title'   => __( 'مرکز بهینه‌سازی تیساکیس', 'tisacase-optimizer' ),
				'desc'    => __( 'تحلیل حجم واقعی دیسک و Inode، کاوشگر سنگین‌ترین تصاویر، اسکنر ۱۲لایهٔ تصاویر بدون استفاده با قرنطینهٔ ۳۰ روزه، فشرده‌سازی و WebP، ادغام تکراری‌ها و سئوی خودکار.', 'tisacase-optimizer' ),
				'group'   => 'maintenance',
				'icon'    => 'bolt',
				'dir'     => 'tisacase-optimizer',
				'cap'     => 'manage_woocommerce',
				'pages'   => array(
					array(
						'label'  => __( 'استودیو تصاویر', 'tisacase-optimizer' ),
						'path'   => 'admin.php?page=' . self::SLUG,
						'screen' => 'woocommerce_page_' . self::SLUG,
						'parent' => 'woocommerce',
						'slug'   => self::SLUG,
					),
					array(
						'label'  => __( 'قرنطینه و تاریخچه', 'tisacase-optimizer' ),
						'path'   => 'admin.php?page=' . self::SLUG . '&module=vault',
						'screen' => 'woocommerce_page_' . self::SLUG,
					),
				),
				'screens' => array(
					'woocommerce_page_' . self::SLUG,
					'media_page_' . self::SLUG,
				),
			);
			return $items;
		}

		/**
		 * لینک سریع در صفحهٔ افزونه‌ها.
		 *
		 * @param string[] $links لینک‌ها.
		 * @return string[]
		 */
		public static function action_links( $links ) {
			$open = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '"><strong>'
				. esc_html__( 'باز کردن مرکز بهینه‌سازی', 'tisacase-optimizer' )
				. '</strong></a>';
			array_unshift( $links, $open );
			return $links;
		}

		/**
		 * افزودن کلاس بدنهٔ اختصاصی صفحه برای قفل چیدمان و خکوب‌سازی منو.
		 *
		 * @param string $classes کلاس‌های فعلی.
		 * @return string
		 */
		public static function body_class( $classes ) {
			if ( self::is_our_screen() ) {
				$classes .= ' tco-page';
				if ( ! class_exists( 'TSH_UI' ) ) {
					$classes .= ' tco-standalone';
				}
			}
			return $classes;
		}

		/**
		 * آیا در اسکرین خودمان هستیم؟
		 *
		 * @return bool
		 */
		private static function is_our_screen() {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && ( $screen->id === self::$hook_suffix || false !== strpos( (string) $screen->id, self::SLUG ) ) ) {
				return true;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
		}

		/**
		 * بارگذاری CSS و JS فقط روی صفحهٔ خود افزونه.
		 *
		 * @return void
		 */
		public static function enqueue_assets() {
			if ( ! self::is_our_screen() ) {
				return;
			}

			$style_deps = wp_style_is( 'tisacase-ui', 'registered' ) || wp_style_is( 'tisacase-ui', 'enqueued' )
				? array( 'tisacase-ui' )
				: array();

			wp_enqueue_style(
				'tisacase-optimizer-admin',
				TCO_URL . 'assets/admin.css',
				$style_deps,
				TCO_VERSION
			);

			wp_enqueue_script(
				'tisacase-optimizer-admin',
				TCO_URL . 'assets/admin.js',
				array( 'jquery' ),
				TCO_VERSION,
				true
			);

			$modules_meta = array();
			$modules_data = array();
			foreach ( TisaCase_Optimizer_Modules::all() as $mod ) {
				$modules_meta[]             = $mod->meta();
				$modules_data[ $mod->id() ] = $mod->boot_payload();
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$initial_module = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : 'images';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$initial_tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';

			wp_localize_script(
				'tisacase-optimizer-admin',
				'tcoBoot',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'editPostBase'  => admin_url( 'post.php?action=edit&post=' ),
					'action'        => TisaCase_Optimizer_Ajax::ACTION,
					'nonce'         => wp_create_nonce( TisaCase_Optimizer_Ajax::NONCE_NAME ),
					'version'       => TCO_VERSION,
					'initialModule' => $initial_module,
					'initialTab'    => $initial_tab,
					'settings'      => TisaCase_Optimizer_Settings::get_all(),
					'stats'         => TisaCase_Optimizer_Settings::stats(),
					'modules'       => $modules_meta,
					'moduleBoot'    => $modules_data,
				)
			);
		}

		/**
		 * رندر پوستهٔ اصلی صفحه (هم‌ریخت ۱۰۰٪ با پیش‌نمایش زنده در `design/preview/optimizer-live.html`).
		 *
		 * @return void
		 */
		public static function render_page() {
			if ( ! TisaCase_Optimizer_Ajax::user_can_access() ) {
				wp_die( esc_html__( 'دسترسی غیرمجاز است.', 'tisacase-optimizer' ) );
			}

			$standalone = ! class_exists( 'TSH_UI' ) ? ' is-standalone' : '';
			$caps       = TisaCase_Optimizer_Image_Processor::capabilities();
			?>
			<div id="tco-app" class="wrap tisa-wrap tco-wrap<?php echo esc_attr( $standalone ); ?>" dir="rtl">
				<header class="tco-hero">
					<div class="tco-hero__mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M13 3L5 14h5l-1 7 8-11h-5z"/></svg>
					</div>
					<div class="tco-hero__text">
						<span class="tco-hero__eyebrow"><?php esc_html_e( 'سوئیت نگهداری و سرعت فروشگاه تیساکیس', 'tisacase-optimizer' ); ?></span>
						<h1 class="tco-hero__title"><?php esc_html_e( 'مرکز بهینه‌سازی تیساکیس', 'tisacase-optimizer' ); ?></h1>
						<p class="tco-hero__sub"><?php esc_html_e( 'مدیریت عمیق تصاویر، پاک‌سازی ۱۲لایه با قرنطینهٔ ۳۰ روزه، فشرده‌سازی بومی WebP/AVIF، ادغام هوشمند تکراری‌ها و پایش منابع هاست.', 'tisacase-optimizer' ); ?></p>
					</div>
					<div class="tco-hero__pills">
						<span class="tco-hero__pill"><?php echo esc_html( 'موتور: ' . $caps['engine'] ); ?></span>
						<span class="tco-hero__pill" dir="ltr"><?php echo esc_html( 'v' . TCO_VERSION ); ?></span>
					</div>
				</header>

				<nav class="tco-nav" id="tco-module-nav" aria-label="<?php esc_attr_e( 'بخش‌های مرکز بهینه‌سازی', 'tisacase-optimizer' ); ?>"></nav>

				<main class="tco-main" id="tco-main" aria-live="polite">
					<div class="tco-card">
						<div class="tco-loading-state">
							<span class="tco-spinner" aria-hidden="true"></span>
							<span><?php esc_html_e( 'در حال بارگذاری استودیو بهینه‌سازی و تحلیل کتابخانهٔ رسانه…', 'tisacase-optimizer' ); ?></span>
						</div>
					</div>
				</main>

				<div class="tco-modal" id="tco-modal" hidden>
					<div class="tco-modal__backdrop" data-modal-close="1"></div>
					<div class="tco-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tco-modal-title">
						<div class="tco-modal__head">
							<h2 id="tco-modal-title"></h2>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-modal-close="1"><?php esc_html_e( 'بستن ✕', 'tisacase-optimizer' ); ?></button>
						</div>
						<div class="tco-modal__body" id="tco-modal-body"></div>
					</div>
				</div>

				<div class="tco-toast-stack" id="tco-toasts" aria-live="polite"></div>
			</div>
			<?php
		}
	}
}
