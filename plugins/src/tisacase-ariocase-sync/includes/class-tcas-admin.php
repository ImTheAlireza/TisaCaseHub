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
				'همگام‌سازی موجودی با همکار',
				'همگام‌سازی موجودی با همکار',
				'manage_woocommerce',
				TCAS_Core::PAGE,
				array( __CLASS__, 'render' )
			);
		}

		public static function dependency_notice() {
			if ( ! class_exists( 'WooCommerce' ) ) {
				echo '<div class="notice notice-error"><p><strong>همگام‌سازی موجودی با همکار:</strong> برای استفاده از این افزونه، ووکامرس باید فعال باشد.</p></div>';
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
			$partner_id = TCAS_Partners::selected_id();
			wp_localize_script( 'tcas-admin', 'TCAS_ADMIN', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'partnerId' => $partner_id,
				'partnerLabel' => TCAS_Partners::label( $partner_id ),
				'mappingsUrl' => admin_url( 'admin.php?page=' . TCAS_Core::PAGE . '&tab=mappings&partner_id=' . rawurlencode( $partner_id ) ),
				'nonce'   => wp_create_nonce( TCAS_Core::NONCE ),
				'maxPairs' => TCAS_Core::MAX_CODES,
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
			$partner_id = isset( $_POST['partner_id'] ) ? sanitize_key( wp_unslash( $_POST['partner_id'] ) ) : TCAS_Partners::active_id();
			$input      = isset( $_POST['tcas_settings'] ) ? (array) wp_unslash( $_POST['tcas_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$saved      = TCAS_Partners::save_settings( $partner_id, $input );
			if ( is_wp_error( $saved ) ) {
				wp_die( esc_html( $saved->get_error_message() ) );
			}
			TCAS_Logger::add( 'settings', '', 'تنظیمات اتصال همکار ذخیره شد.' );
			wp_safe_redirect( add_query_arg( array( 'page' => TCAS_Core::PAGE, 'tab' => 'settings', 'partner_id' => $partner_id, 'tcas_notice' => 'settings_saved' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		private static function notice() {
			if ( empty( $_GET['tcas_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$notice = sanitize_key( wp_unslash( $_GET['tcas_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'settings_saved' === $notice ) {
				echo '<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>';
			}
		}

		public static function render() {
			if ( ! TCAS_Core::can() ) {
				wp_die( 'دسترسی غیرمجاز است.' );
			}
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pairs'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $tab, array( 'pairs', 'mappings', 'settings', 'logs' ), true ) ) {
				$tab = 'pairs';
			}
			$partner_id = TCAS_Partners::selected_id();
			$partner_label = TCAS_Partners::label( $partner_id );
			$settings = TCAS_Partners::settings( $partner_id );
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
					<div class="tcas-brand"><span class="tcas-brand-mark" aria-hidden="true">↔</span><div><h1>همگام‌سازی موجودی با همکار</h1><p>TisaCase ↔ <?php echo esc_html( $partner_label ); ?></p></div></div>
					<div class="tcas-hero-badges">
						<span class="tcas-badge <?php echo class_exists( 'WooCommerce' ) ? 'is-ok' : 'is-bad'; ?>">ووکامرس <?php echo class_exists( 'WooCommerce' ) ? 'فعال' : 'غیرفعال'; ?></span>
						<span class="tcas-badge <?php echo TCAS_Partners::is_configured( $partner_id ) ? 'is-ok' : 'is-warn'; ?>">اتصال <?php echo TCAS_Partners::is_configured( $partner_id ) ? 'آماده' : 'تنظیم‌نشده'; ?></span>
						<span class="tcas-badge">v<?php echo esc_html( TCAS_VERSION ); ?></span>
					</div>
				</header>
				<?php self::notice(); ?>
				<?php if ( ! TCAS_Partners::is_configured( $partner_id ) && 'settings' !== $tab ) : ?>
					<div class="notice notice-warning inline"><p>ابتدا تنظیمات اتصال <strong><?php echo esc_html( $partner_label ); ?></strong> را کامل کنید: <a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'settings', 'partner_id' => $partner_id ), $base ) ); ?>">تنظیمات همکار</a>.</p></div>
				<?php endif; ?>
				<form class="tcas-partner-select" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( TCAS_Core::PAGE ); ?>">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
					<label for="tcas-partner-select"><strong>همکار:</strong></label>
					<select id="tcas-partner-select" name="partner_id" onchange="this.form.submit()">
						<?php foreach ( TCAS_Partners::all() as $provider_id => $provider_option ) : ?>
							<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $partner_id, $provider_id ); ?>><?php echo esc_html( $provider_option->label() ); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button class="button" type="submit">انتخاب</button></noscript>
				</form>
				<nav class="tcas-tabs" aria-label="بخش‌های افزونه">
					<?php foreach ( $tabs as $key => $label ) : ?>
						<a class="<?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'tab' => $key, 'partner_id' => $partner_id ), $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
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
