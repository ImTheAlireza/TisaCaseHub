<?php
/**
 * صفحهٔ مدیریت و دارایی‌های رابط کاربری.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TCAS_Admin' ) ) {

	final class TCAS_Admin {
		public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 35 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
			add_action( 'admin_post_tcas_save_settings', array( __CLASS__, 'save_settings' ) );
			add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
		}

		public static function menu() {
			add_submenu_page(
				'woocommerce',
				'جفت موجودی ArioCase',
				'جفت موجودی ArioCase',
				'manage_woocommerce',
				TCAS_Core::PAGE,
				array( __CLASS__, 'render' )
			);
		}

		public static function dependency_notice() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-error"><p><strong>جفت موجودی ArioCase:</strong> برای استفاده از این افزونه، ووکامرس باید فعال باشد.</p></div>';
			}
		}

		private static function is_screen( $hook = '' ) {
			if ( false !== strpos( (string) $hook, TCAS_Core::PAGE ) ) {
				return true;
			}
			return isset( $_GET['page'] ) && TCAS_Core::PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		public static function assets( $hook = '' ) {
			if ( ! self::is_screen( $hook ) ) {
				return;
			}
			$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
			wp_enqueue_style( 'tcas-admin', TCAS_URL . 'assets/admin.css', $deps, TCAS_VERSION );
			wp_enqueue_script( 'tcas-admin', TCAS_URL . 'assets/admin.js', array(), TCAS_VERSION, true );
			wp_localize_script( 'tcas-admin', 'TCAS_ADMIN', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( TCAS_Core::NONCE ),
				'strings' => array(
					'working'      => 'در حال انجام…',
					'preview'      => 'دریافت پیش‌نمایش',
					'connect'      => 'اتصال جفت‌های انتخابی',
					'failed'       => 'ارتباط با سرور سایت ناموفق بود.',
					'done'         => 'عملیات تمام شد.',
					'disconnect'   => 'فقط اتصال حذف می‌شود و محصول حذف نمی‌شود. ادامه می‌دهید؟',
					'selectRows'   => 'حداقل یک جفت قابل‌اتصال انتخاب کنید.',
					'unknownError' => 'خطای نامشخص',
				),
			) );
		}

		public static function save_settings() {
			if ( ! TCAS_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			check_admin_referer( 'tcas_save_settings' );
			$current  = TCAS_Core::settings();
			$input    = isset( $_POST['tcas_settings'] ) ? (array) wp_unslash( $_POST['tcas_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$settings = TCAS_Core::sanitize_settings( $input, $current );
			update_option( TCAS_Core::OPTION_SETTINGS, $settings, false );
			TCAS_Logger::add( 'settings', '', 'تنظیمات ذخیره شد.' );
			wp_safe_redirect( add_query_arg( array( 'page' => TCAS_Core::PAGE, 'tab' => 'settings', 'tcas_notice' => 'settings_saved' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		private static function notice() {
			if ( empty( $_GET['tcas_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$notice = sanitize_key( wp_unslash( $_GET['tcas_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'settings_saved' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد. قبل از ساخت جفت، «تست اتصال» را با یک کد واقعی انجام دهید.</p></div>';
			}
		}

		public static function render() {
			if ( ! TCAS_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pairs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'import' === $tab ) {
				$tab = 'pairs';
			}
			if ( ! in_array( $tab, array( 'pairs', 'mappings', 'settings', 'logs' ), true ) ) {
				$tab = 'pairs';
			}
			$settings = TCAS_Core::settings();
			$base     = admin_url( 'admin.php?page=' . TCAS_Core::PAGE );
			$tabs     = array(
				'pairs'    => 'اتصال جفتی',
				'mappings' => 'محصولات متصل',
				'settings' => 'تنظیمات API',
				'logs'     => 'گزارش عملیات',
			);
			?>
			<div class="wrap tisa-wrap tcas-wrap" dir="rtl">
				<header class="tcas-hero">
					<div>
						<span class="tcas-eyebrow">TisaCase × ArioCase</span>
						<h1>اتصال جفتی موجودی ArioCase</h1>
						<p>هر خط: SKU تیساکیس - کد ArioCase؛ فقط موجودی دو محصول موجود به هم متصل می‌شود.</p>
					</div>
					<div class="tcas-hero-badges">
						<span class="tcas-badge <?php echo class_exists( 'WooCommerce' ) ? 'is-ok' : 'is-bad'; ?>">ووکامرس <?php echo class_exists( 'WooCommerce' ) ? 'فعال' : 'غیرفعال'; ?></span>
						<span class="tcas-badge <?php echo TCAS_Core::is_configured( $settings ) ? 'is-ok' : 'is-warn'; ?>">API <?php echo TCAS_Core::is_configured( $settings ) ? 'تنظیم‌شده' : 'نیازمند تنظیم'; ?></span>
						<span class="tcas-badge">نسخه <?php echo esc_html( TCAS_VERSION ); ?></span>
					</div>
				</header>
				<?php self::notice(); ?>
				<?php if ( ! TCAS_Core::is_configured( $settings ) && 'settings' !== $tab ) : ?>
					<div class="notice notice-warning inline"><p>برای شروع، ابتدا <a href="<?php echo esc_url( $base . '&tab=settings' ); ?>">تنظیمات API</a> را کامل و تست اتصال را انجام دهید.</p></div>
				<?php endif; ?>
				<nav class="tcas-tabs" aria-label="بخش‌های افزونه">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a class="<?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=' . $key ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
				<main class="tcas-main">
					<?php
					$view = TCAS_PATH . 'views/' . $tab . '.php';
					if ( is_readable( $view ) ) {
						require $view;
					}
					?>
				</main>
			</div>
			<?php
		}
	}
}
