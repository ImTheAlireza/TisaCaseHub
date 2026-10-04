<?php
/**
 * Plugin Name: پکیج ویژه قاب موبایل
 * Plugin URI:  https://example.com/wc-case-special-package
 * Description: افزودن گزینه «پکیج ویژه» با قیمت ثابت به محصولات قاب موبایل (تشخیص از روی عنوان/دسته‌بندی، با لیست استثنا بر اساس SKU و کلمات منفیِ وتوکننده). قیمت به ازای هر عدد محاسبه و در فاکتور، ایمیل و پیشخوان نمایش داده می‌شود.
 * Version:     1.5.3
 * Author:      علیرضا شعبان زاده
 * Text Domain: case-special-package
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCSP_MAIN_FILE', __FILE__ );
define( 'WCSP_VERSION', '1.5.3' );

/**
 * کلاس اصلی پلاگین.
 */
final class WC_Case_Special_Package {

	const OPTION_KEY   = 'wcsp_settings';
	const CART_KEY     = 'wcsp_package';
	const PRODUCT_META = '_wcsp_mode';

	/** @var WC_Case_Special_Package|null */
	private static $instance = null;

	/** @var array<string, bool>|null شناسهٔ اشیای محصولِ علامت‌خورده در سبدِ همین درخواست. */
	private static $packaged_cart_product_hashes = null;

	/** @var array<string, array<string, float|bool>> قیمت‌های پکیج که در این محاسبه اعمال و تأیید شده‌اند. */
	private static $applied_package_prices = array();

	/** @var array<string, bool> آیتم‌هایی که موتور قیمت‌گذاری برایشان خطا داده است. */
	private static $package_price_failures = array();

	/** @var bool جلوگیری از نمایش چندبارهٔ هشدار یکسان در همان درخواست. */
	private static $package_validation_notice_added = false;

	/**
	 * دریافت نمونه یکتا (Singleton).
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// هوک‌های ادمین.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wcsp_refresh_stats', array( __CLASS__, 'handle_refresh_stats' ) );
		add_action( 'admin_post_wcsp_emergency_pause', array( __CLASS__, 'handle_emergency_pause' ) );
		// اولویت ۲۰: بعد از ووکامرس تا هندل‌های select2/enhanced-select ثبت شده باشند.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 20 );

		// فیلد دستی روی صفحه ویرایش محصول.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_field' ) );

		// فرانت‌اند: نمایش چک‌باکس در صفحه محصول.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_checkbox' ) );

		// سبد خرید.
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );

		// افزونهٔ قیمت‌گذاری، ابتدا قیمت فعال (از جمله تخفیف داینامیک) را محاسبه می‌کند؛
		// سپس این فیلتر فقط روی همان آیتم سبد که پکیج را انتخاب کرده، مبلغ را می‌افزاید.
		add_filter( 'tisacase_pricing_final_active_price', array( $this, 'add_package_to_active_price' ), 10, 2 );

		// سازگاری با نسخه‌های قدیمی TisaCase Pricing که هوک بالایی را ندارند.
		// PHP_INT_MAX تضمین می‌کند قیمت داینامیکِ اولویت 99999 پیش از این فیلتر باشد.
		add_filter( 'woocommerce_product_get_price', array( $this, 'add_package_after_legacy_pricing' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'add_package_after_legacy_pricing' ), PHP_INT_MAX, 2 );

		// آغاز هر محاسبهٔ تازه؛ نتایج قبلی نباید به‌عنوان تأیید جدید استفاده شوند.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'begin_price_verification' ), 1, 0 );

		// مسیر قدیمی برای فروشگاه‌هایی که TisaCase Pricing فعال نیست.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ), 20, 1 );

		// اگر قیمت پکیج قابل تأیید نباشد، سبد/پرداخت را fail-closed می‌کنیم.
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate_package_pricing' ), PHP_INT_MAX, 0 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_checkout_package_pricing' ), PHP_INT_MAX, 2 );
		add_action( 'tisacase_pricing_active_price_calculation_failed', array( $this, 'mark_tisacase_price_failure' ), 10, 1 );

		// کش نگاشت شیء محصول به آیتم پکیج‌دار را با هر تغییر سبد باطل می‌کنیم.
		foreach ( array( 'woocommerce_cart_loaded_from_session', 'woocommerce_add_to_cart', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored', 'woocommerce_cart_emptied', 'woocommerce_cart_updated', 'woocommerce_after_cart_item_quantity_update' ) as $cart_change_hook ) {
			add_action( $cart_change_hook, array( $this, 'reset_cart_state' ), 10, 0 );
		}

		// سفارش / فاکتور / ایمیل.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// سازگاری با HPOS و اعلام ناسازگاری با بلوک‌های سبد/پرداخت (چون چک‌باکس کلاسیک است).
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		// باطل‌کردن کش آمار داشبورد در زمان‌های لازم.
		add_action( 'save_post_product', array( __CLASS__, 'flush_stats' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'flush_stats' ) );
		add_action( 'woocommerce_new_order_item', array( __CLASS__, 'flush_stats' ) );
		// با ذخیرهٔ تنظیمات، آمار (و پیش‌نمایش کلمات منفی) بلافاصله تازه می‌شود.
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_stats' ) );
	}

	public static function flush_stats() {
		delete_transient( 'wcsp_stats_v1' );
	}

	public static function handle_refresh_stats() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'wcsp_refresh_stats' );
		self::flush_stats();
		wp_safe_redirect( admin_url( 'admin.php?page=wcsp-settings&stats-refreshed=1#wcsp-dash' ) );
		exit;
	}

	/** توقف اضطراری فروش پکیج با nonce و capability؛ قیمت‌ها/محصولات ذخیره‌شده تغییر نمی‌کنند. */
	public static function handle_emergency_pause() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'wcsp_emergency_pause' );

		$settings            = self::get_settings();
		$settings['enabled'] = 'no';
		update_option( self::OPTION_KEY, $settings );
		self::flush_stats();

		wp_safe_redirect( admin_url( 'admin.php?page=wcsp-settings&emergency-paused=1#general' ) );
		exit;
	}

	public function declare_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
		}
	}

	/* ------------------------------------------------------------------
	 * تنظیمات
	 * ----------------------------------------------------------------*/

	public static function default_settings() {
		return array(
			'enabled'           => 'no',
			'label'             => 'پکیج ویژه',
			'checkbox_text'     => 'افزودن پکیج ویژه (به ازای هر عدد)',
			'price'             => 0,
			'keywords'          => 'قاب',
			'match_mode'        => 'contains', // contains | starts_with
			'negative_keywords' => '',
			'negative_mode'     => 'contains', // contains | word
			'categories'        => array(),
			'sku_exceptions'    => '',
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::default_settings(), $saved );
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'پکیج ویژه قاب',
			'پکیج ویژه قاب',
			'manage_woocommerce',
			'wcsp-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'wcsp_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$clean   = array();

		$clean['enabled']        = ( ! empty( $input['enabled'] ) && 'yes' === $input['enabled'] ) ? 'yes' : 'no';
		$clean['label']          = sanitize_text_field( isset( $input['label'] ) ? $input['label'] : '' );
		$clean['checkbox_text']  = sanitize_text_field( isset( $input['checkbox_text'] ) ? $input['checkbox_text'] : '' );
		$clean['price']          = isset( $input['price'] ) ? max( 0, (float) wc_format_decimal( $input['price'] ) ) : 0;
		$clean['keywords']       = sanitize_textarea_field( isset( $input['keywords'] ) ? $input['keywords'] : '' );
		$clean['match_mode']     = ( isset( $input['match_mode'] ) && 'starts_with' === $input['match_mode'] ) ? 'starts_with' : 'contains';
		$clean['sku_exceptions'] = sanitize_textarea_field( isset( $input['sku_exceptions'] ) ? $input['sku_exceptions'] : '' );

		$clean['negative_keywords'] = sanitize_textarea_field( isset( $input['negative_keywords'] ) ? $input['negative_keywords'] : '' );
		$clean['negative_mode']     = ( isset( $input['negative_mode'] ) && 'word' === $input['negative_mode'] ) ? 'word' : 'contains';

		$clean['categories'] = array();
		if ( ! empty( $input['categories'] ) && is_array( $input['categories'] ) ) {
			$clean['categories'] = array_values( array_filter( array_map( 'absint', $input['categories'] ) ) );
		}

		if ( '' === $clean['label'] ) {
			$clean['label'] = 'پکیج ویژه';
		}

		return $clean;
	}

	/**
	 * بارگذاری select2 به روش استاندارد ووکامرس.
	 *
	 * نکتهٔ مهم (رفع باگ ۱.۵.۱): در ووکامرس هندل «select2» فقط یک **اسکریپت** است
	 * (legacy alias → `wc-select2`) و هیچ استایل هم‌نامی وجود ندارد؛ CSS سلکت۲ داخل
	 * `woocommerce_admin_styles` (فایل admin.css) گنجانده شده است. بارگذاری قبلی
	 * (`wp_enqueue_style('select2')`) عملاً هیچ استایلی لود نمی‌کرد و در نتیجه منوی
	 * کشویی دسته‌بندی‌ها بدون CSS رندر می‌شد: نه باز می‌شد و نه آیتمی دیده می‌شد.
	 *
	 * @return array آرایهٔ هندل‌های استایل که باید وابستگی استایل افزونه شوند.
	 */
	private static function enqueue_enhanced_select() {
		$style_deps = array();

		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return $style_deps;
		}

		if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			// مسیر استاندارد: اسکریپت enhanced select ووکامرس + admin.css (شامل CSS سلکت۲).
			wp_enqueue_style( 'woocommerce_admin_styles' );
			wp_enqueue_script( 'wc-enhanced-select' );
			$style_deps[] = 'woocommerce_admin_styles';
		} elseif ( wp_script_is( 'select2', 'registered' ) ) {
			// نسخه‌های قدیمی/جایگزین: فقط اسکریپت select2 موجود است.
			wp_enqueue_script( 'select2' );
		} else {
			return $style_deps;
		}

		// کمربند ایمنی: اگر جایی استایل سلکت۲ را جدا ثبت کرده باشد، همان را می‌زنیم و
		// اگر نبود، فایل خود ووکامرس را مستقیم صف می‌کنیم تا با حذف/جابه‌جایی
		// `woocommerce_admin_styles` (بسیاری از افزونه‌های بهینه‌ساز این کار را می‌کنند)
		// منوی دسته‌بندی‌ها بی‌استایل و عملاً غیرقابل‌استفاده نشود.
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
			$style_deps[] = 'select2';
		} else {
			$rel = '/assets/css/select2.css';
			if ( file_exists( WC()->plugin_path() . $rel ) ) {
				wp_enqueue_style( 'wcsp-select2', WC()->plugin_url() . $rel, array(), '4.0.3' );
				$style_deps[] = 'wcsp-select2';
			}
		}

		return $style_deps;
	}

	public function enqueue_admin_assets( $hook ) {
		if ( false === strpos( $hook, 'wcsp-settings' ) ) {
			return;
		}
		// قلم از لایهٔ طراحی مشترک (هاب) می‌آید؛ بارگیری از CDN خارجی حذف شد (F5).
		$deps = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
		$deps = array_merge( $deps, self::enqueue_enhanced_select() );

		wp_enqueue_style( 'wcsp-admin', plugins_url( 'assets/admin.css', WCSP_MAIN_FILE ), $deps, WCSP_VERSION );
		wp_enqueue_script( 'wcsp-admin', plugins_url( 'assets/admin.js', WCSP_MAIN_FILE ), array( 'jquery' ), WCSP_VERSION, true );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s     = self::get_settings();
		$stats = self::get_stats();
		$opt   = self::OPTION_KEY;

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		// پیش‌نمایش زنده لیست استثنا: کدام SKUها به کدام محصول می‌خورند؟
		$exception_rows = array();
		foreach ( self::parse_list( $s['sku_exceptions'] ) as $sku ) {
			$pid              = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;
			$exception_rows[] = array(
				'sku'  => $sku,
				'name' => $pid ? get_the_title( $pid ) : '',
			);
		}

		$max = 1;
		foreach ( $stats['series'] as $pt ) {
			$max = max( $max, (int) $pt['count'] );
		}

		$sym = get_woocommerce_currency_symbol();
		$avg = $stats['orders'] > 0 ? $stats['revenue'] / $stats['orders'] : 0;

		// ----- ساخت نقاط نمودار سطحی (SVG) -----
		$cw    = 560;
		$ch    = 150;
		$padx  = 6;
		$top   = 12;
		$bot   = 10;
		$n     = count( $stats['series'] );
		$step  = ( $cw - $padx * 2 ) / max( 1, $n - 1 );
		$pts   = array();
		foreach ( $stats['series'] as $i => $pt ) {
			$x     = round( $padx + $i * $step, 1 );
			$ratio = $max > 0 ? (int) $pt['count'] / $max : 0;
			$y     = round( $ch - $bot - $ratio * ( $ch - $top - $bot ), 1 );
			$pts[] = array( $x, $y, (int) $pt['count'] );
		}
		$line = '';
		foreach ( $pts as $p ) {
			$line .= ( $line ? ' L ' : 'M ' ) . $p[0] . ' ' . $p[1];
		}
		$area = $line . ' L ' . $pts[ $n - 1 ][0] . ' ' . ( $ch - $bot ) . ' L ' . $pts[0][0] . ' ' . ( $ch - $bot ) . ' Z';
		?>
		<div class="wrap tisa-wrap wcsp-wrap" dir="rtl">
			<form method="post" action="options.php" id="wcsp-form">
				<?php settings_fields( 'wcsp_settings_group' ); ?>

				<header class="wcsp-hero">
					<div class="wcsp-hero-row">
						<div class="wcsp-hero-mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><line x1="10.5" y1="5.5" x2="13.5" y2="5.5"/><path d="M4 9v6M20 9v6"/></svg>
						</div>
						<div class="wcsp-hero-text">
							<h1 class="wcsp-hero-title">پکیج ویژه قاب</h1>
							<p class="wcsp-hero-sub">قیمت ثابت پکیج روی محصولات قاب، به ازای هر عدد — با محاسبه در سبد و ثبت در فاکتور</p>
						</div>
						<span class="wcsp-hero-state <?php echo 'yes' === $s['enabled'] ? 'is-on' : ''; ?>"><i></i><?php echo 'yes' === $s['enabled'] ? 'فعال' : 'غیرفعال'; ?></span>
						<span class="wcsp-hero-ver" dir="ltr">v<?php echo esc_html( WCSP_VERSION ); ?></span>
					</div>
					<nav class="wcsp-tabs" role="tablist">
						<button type="button" class="wcsp-tab" data-tab="dash">داشبورد</button>
						<button type="button" class="wcsp-tab" data-tab="general">تنظیمات</button>
						<button type="button" class="wcsp-tab" data-tab="detect">تشخیص محصولات</button>
						<button type="button" class="wcsp-tab" data-tab="exceptions">استثناها</button>
						<button type="button" class="wcsp-tab" data-tab="texts">متن‌ها</button>
						<button type="button" class="wcsp-tab" data-tab="help">راهنما</button>
					</nav>
				</header>

				<?php if ( isset( $_GET['emergency-paused'] ) ) : // phpcs:ignore ?>
					<div class="wcsp-flashbar" role="alert">فروش پکیج متوقف شد. آیتم‌های پکیج‌دارِ از قبل در سبد تا حذف یا رفع مشکل اجازهٔ پرداخت ندارند.</div>
				<?php elseif ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore ?>
					<div class="wcsp-flashbar" role="status">تنظیمات ذخیره شد.</div>
				<?php elseif ( isset( $_GET['stats-refreshed'] ) ) : // phpcs:ignore ?>
					<div class="wcsp-flashbar" role="status">آمار دوباره از سفارش‌ها محاسبه شد.</div>
				<?php endif; ?>

				<!-- ================= داشبورد ================= -->
				<section class="wcsp-panel" data-panel="dash">
					<div class="wcsp-kpis">
						<div class="wcsp-kpi">
							<div class="t">محصولات واجد شرایط</div>
							<div class="v"><?php echo esc_html( number_format_i18n( $stats['eligible'] ) ); ?></div>
							<div class="s">از <?php echo esc_html( number_format_i18n( $stats['total_products'] ) ); ?> محصول منتشرشده</div>
						</div>
						<div class="wcsp-kpi">
							<div class="t">قیمت پکیج</div>
							<div class="v"><?php echo esc_html( wc_format_localized_price( $s['price'] ) ); ?> <small><?php echo esc_html( $sym ); ?></small></div>
							<div class="s">به ازای هر عدد</div>
						</div>
						<div class="wcsp-kpi">
							<div class="t">سفارش‌های دارای پکیج</div>
							<div class="v"><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></div>
							<div class="s"><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?> سفارش امروز</div>
						</div>
						<div class="wcsp-kpi">
							<div class="t">درآمد پکیج</div>
							<div class="v"><?php echo esc_html( wc_format_localized_price( $stats['revenue'] ) ); ?> <small><?php echo esc_html( $sym ); ?></small></div>
							<div class="s">میانگین <?php echo esc_html( wc_format_localized_price( $avg ) ); ?> در هر سفارش</div>
						</div>
					</div>

					<div class="wcsp-grid">
						<section class="wcsp-card">
							<div class="wcsp-card-head"><span class="wcsp-dot"></span><div><h2>روند سفارش‌های پکیج</h2><p>۱۴ روز اخیر — سفارش‌های در حال انجام و تکمیل‌شده</p></div></div>
							<div class="wcsp-card-body">
								<svg class="wcsp-svg" viewBox="0 0 <?php echo (int) $cw; ?> <?php echo (int) $ch; ?>" preserveAspectRatio="none" role="img" aria-label="نمودار روند سفارش‌های پکیج در ۱۴ روز اخیر">
									<defs>
										<linearGradient id="wcspAreaGrad" x1="0" y1="0" x2="0" y2="1">
											<stop offset="0%" class="wcsp-grad-a"/>
											<stop offset="100%" class="wcsp-grad-b"/>
										</linearGradient>
									</defs>
									<line class="wcsp-axis" x1="0" y1="<?php echo (int) ( $ch - $bot ); ?>" x2="<?php echo (int) $cw; ?>" y2="<?php echo (int) ( $ch - $bot ); ?>"/>
									<path class="wcsp-area" d="<?php echo esc_attr( $area ); ?>" fill="url(#wcspAreaGrad)"/>
									<path class="wcsp-line" d="<?php echo esc_attr( $line ); ?>"/>
									<?php foreach ( $pts as $p ) : if ( $p[2] > 0 ) : ?>
										<circle class="wcsp-pt" cx="<?php echo esc_attr( $p[0] ); ?>" cy="<?php echo esc_attr( $p[1] ); ?>" r="4"/>
									<?php endif; endforeach; ?>
								</svg>
								<div class="wcsp-xlabels">
									<?php foreach ( $stats['series'] as $pt ) : ?>
										<span><?php echo esc_html( $pt['label'] ); ?></span>
									<?php endforeach; ?>
								</div>
							</div>
						</section>

						<section class="wcsp-card">
							<div class="wcsp-card-head"><span class="wcsp-dot wcsp-dot--muted"></span><div><h2>خلاصه</h2></div></div>
							<div class="wcsp-card-body">
								<dl class="wcsp-rows">
									<div><dt>سفارش‌های دارای پکیج</dt><dd><?php echo esc_html( number_format_i18n( $stats['orders'] ) ); ?></dd></div>
									<div><dt>سفارش امروز</dt><dd><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?></dd></div>
									<div><dt>محصولات مستثنی (SKU)</dt><dd><?php echo esc_html( number_format_i18n( $stats['exceptions'] ) ); ?></dd></div>
									<div><dt>کلمات منفی (وتو)</dt><dd><?php echo esc_html( number_format_i18n( $stats['negatives'] ) ); ?> — <?php echo 'word' === $s['negative_mode'] ? 'کلمهٔ کامل' : 'شامل کلمه'; ?></dd></div>
									<div><dt>محصولات وتوشده</dt><dd><?php echo esc_html( number_format_i18n( $stats['vetoed_total'] ) ); ?></dd></div>
									<div><dt>حالت تشخیص عنوان</dt><dd><?php echo 'starts_with' === $s['match_mode'] ? 'شروع با کلمه' : 'شامل کلمه'; ?></dd></div>
									<div><dt>کش آمار</dt><dd>۱ ساعته</dd></div>
								</dl>
								<div class="wcsp-quick">
									<a class="wcsp-refresh" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcsp_refresh_stats' ), 'wcsp_refresh_stats' ) ); ?>">بازمحاسبهٔ آمار</a>
									<a href="#" data-goto="general">قیمت و فعال‌سازی</a>
									<a href="#" data-goto="detect">کلمات کلیدی</a>
									<a href="#" data-goto="exceptions">لیست استثنا</a>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">سفارش‌ها</a>
								</div>
							</div>
						</section>
					</div>

					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot wcsp-dot--muted"></span><div><h2>آخرین سفارش‌های دارای پکیج</h2><p>۱۰ سفارش اخیر (در حال انجام / تکمیل‌شده) که آیتم پکیج ویژه دارند.</p></div></div>
						<div class="wcsp-card-body">
							<?php if ( empty( $stats['recent'] ) ) : ?>
								<p class="wcsp-hint">هنوز سفارشی با پکیج ویژه ثبت نشده — یا آمار نیاز به بازمحاسبه دارد.</p>
							<?php else : ?>
								<table class="wcsp-table">
									<thead><tr><th>سفارش</th><th>تاریخ</th><th>وضعیت</th><th>مبلغ پکیج</th></tr></thead>
									<tbody>
									<?php foreach ( $stats['recent'] as $r ) : ?>
										<tr>
											<td><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener">#<?php echo esc_html( $r['number'] ); ?></a></td>
											<td><?php echo esc_html( $r['date'] ); ?></td>
											<td><?php echo esc_html( $r['status'] ); ?></td>
											<td><?php echo esc_html( wc_format_localized_price( $r['total'] ) ); ?> <small><?php echo esc_html( $sym ); ?></small></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</section>
				</section>

				<!-- ================= تنظیمات ================= -->
				<section class="wcsp-panel" data-panel="general">
					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot"></span><div><h2>تنظیمات عمومی</h2><p>فعال‌سازی قابلیت و قیمت ثابت پکیج به ازای هر عدد.</p></div></div>
						<div class="wcsp-card-body">
							<label class="tisa-switch wcsp-toggle">
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="yes" <?php checked( $s['enabled'], 'yes' ); ?> />
								<span class="tisa-switch__track" aria-hidden="true"></span>
								<span>قابلیت پکیج ویژه فعال باشد</span>
							</label>

							<div class="wcsp-field">
								<p class="wcsp-hint">اگر محاسبهٔ پکیج مشکوک شد، این دکمه فوراً انتخاب پکیج را غیرفعال می‌کند. سبدهای دارای پکیج تا حذف آن یا رفع مشکل اجازهٔ پرداخت نمی‌گیرند.</p>
								<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wcsp_emergency_pause' ), 'wcsp_emergency_pause' ) ); ?>">توقف اضطراری فروش پکیج</a>
							</div>

							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_price">قیمت پکیج (به ازای هر عدد)</label>
								<div class="wcsp-money">
									<input type="text" class="tisa-input" id="wcsp_price" inputmode="decimal" name="<?php echo esc_attr( $opt ); ?>[price]" value="<?php echo esc_attr( wc_format_localized_price( $s['price'] ) ); ?>" />
									<span class="cur"><?php echo esc_html( $sym ); ?></span>
								</div>
								<p class="wcsp-hint">۵ عدد قاب = ۵ بار این مبلغ؛ در فاکتور به تفکیک نمایش داده می‌شود.</p>
							</div>

							<div class="wcsp-field">
								<span class="wcsp-label">حالت تطبیق عنوان محصول</span>
								<div class="wcsp-seg">
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[match_mode]" value="contains" <?php checked( $s['match_mode'], 'contains' ); ?> /><span>عنوان شامل کلمه باشد</span></label>
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[match_mode]" value="starts_with" <?php checked( $s['match_mode'], 'starts_with' ); ?> /><span>عنوان با کلمه شروع شود</span></label>
								</div>
							</div>
						</div>
					</section>
				</section>

				<!-- ================= تشخیص ================= -->
				<section class="wcsp-panel" data-panel="detect">
					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot"></span><div><h2>تشخیص محصولات قاب</h2><p>محصولات قدیمی و جدید خودکار بررسی می‌شوند؛ نیازی به ویرایش تک‌تک نیست.</p></div></div>
						<div class="wcsp-card-body">
							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_keywords">کلمه / کلمات کلیدی عنوان</label>
								<textarea class="tisa-input" id="wcsp_keywords" rows="3" name="<?php echo esc_attr( $opt ); ?>[keywords]" placeholder="قاب"><?php echo esc_textarea( $s['keywords'] ); ?></textarea>
								<p class="wcsp-hint">هر خط یا با کاما یک کلمه. ی/ي، ک/ك و نیم‌فاصله یکسان‌سازی می‌شوند.</p>
							</div>

							<div class="wcsp-field wcsp-field--veto">
								<label class="wcsp-label" for="wcsp_negative_keywords">کلمه / کلمات منفی (وتو) <span class="wcsp-opt">اختیاری</span></label>
								<textarea class="tisa-input" id="wcsp_negative_keywords" rows="3" name="<?php echo esc_attr( $opt ); ?>[negative_keywords]" placeholder="تبلت&#10;لپ‌تاپ"><?php echo esc_textarea( $s['negative_keywords'] ); ?></textarea>
								<p class="wcsp-hint">اگر یکی از این کلمات در عنوان محصول باشد، آن محصول <b>هیچ‌وقت</b> گزینهٔ پکیج را نمی‌گیرد — حتی اگر دسته‌بندی واجد شرایط باشد یا روی محصول «اجباراً فعال» گذاشته شده باشد. مثال: با کلمهٔ «تبلت»، محصول «قاب تبلت سامسونگ» نمایش داده نمی‌شود.</p>
								<p class="wcsp-hint">در ویرایش هر محصول، باکس «وضعیت کنونی» نشان می‌دهد همین حالا کدام کلمهٔ منفی آن را وتو کرده است.</p>
							</div>

							<div class="wcsp-field">
								<span class="wcsp-label">نحوهٔ تطبیق کلمات منفی</span>
								<div class="wcsp-seg">
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[negative_mode]" value="contains" <?php checked( $s['negative_mode'], 'contains' ); ?> /><span>شامل کلمه</span></label>
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[negative_mode]" value="word" <?php checked( $s['negative_mode'], 'word' ); ?> /><span>کلمهٔ کامل</span></label>
								</div>
								<p class="wcsp-hint">«شامل کلمه» مثل کلمات کلیدی کار می‌کند. «کلمهٔ کامل» مرز کلمه را رعایت می‌کند: «کیف» روی «کیفیت» اثر نمی‌گذارد، ولی «تبلت» باز هم «تبلت‌ها» و «تبلت‌های» را می‌گیرد.</p>
							</div>
							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_categories">دسته‌بندی‌های واجد شرایط <span class="wcsp-opt">اختیاری</span></label>
								<select id="wcsp_categories" class="wcsp-select2 wc-enhanced-select" multiple="multiple" name="<?php echo esc_attr( $opt ); ?>[categories][]" data-placeholder="دسته‌ها…">
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( (int) $term->term_id, array_map( 'intval', (array) $s['categories'] ), true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="wcsp-hint">محصولِ این دسته‌ها حتی بدون کلمه کلیدی در عنوان واجد شرایط می‌شود.</p>
								<?php if ( empty( $terms ) ) : ?>
									<div class="wcsp-flashbar wcsp-flashbar--warn" role="status">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ برای استفاده از این فیلد، اول در «محصولات ← دسته‌ها» دسته بساز.</div>
								<?php endif; ?>
							</div>
							<p class="wcsp-hint">کنترل دستی: در ویرایش هر محصول، بخش «اطلاعات عمومی»، فیلد «پکیج ویژه قاب» (خودکار / اجباراً فعال / اجباراً غیرفعال). برای محصولات متغیر، عنوان والد ملاک است.</p>

							<?php if ( $stats['negatives'] > 0 ) : ?>
								<div class="wcsp-field">
									<span class="wcsp-label">محصولاتی که کلمهٔ منفی وتو کرده <span class="wcsp-opt">در غیر این صورت واجد شرایط می‌شدند</span></span>
									<?php if ( empty( $stats['vetoed'] ) ) : ?>
										<p class="wcsp-hint">در حال حاضر هیچ محصول واجدشرطی با کلمات منفی وتو نشده است.</p>
									<?php else : ?>
										<ul class="wcsp-skus">
											<?php foreach ( $stats['vetoed'] as $row ) : ?>
												<li>
													<span class="tisa-code"><?php echo esc_html( $row['word'] ); ?></span>
													<a class="found" href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['title'] ); ?></a>
												</li>
											<?php endforeach; ?>
										</ul>
										<p class="wcsp-hint">
											<?php echo esc_html( number_format_i18n( $stats['vetoed_total'] ) ); ?> محصول وتو شده است
											<?php if ( $stats['vetoed_total'] > count( $stats['vetoed'] ) ) : ?>
												(۱۰ مورد نخست نمایش داده می‌شود)
											<?php endif; ?>
											— برای تازه‌سازی، «بازمحاسبهٔ آمار» را در داشبورد بزنید.
										</p>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</section>

				<!-- ================= استثناها ================= -->
				<section class="wcsp-panel" data-panel="exceptions">
					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot"></span><div><h2>لیست استثنا بر اساس SKU</h2><p>این محصولات هرگز گزینه پکیج نمی‌گیرند؛ بر همه قوانین (حتی «اجباراً فعال») مقدم است.</p></div></div>
						<div class="wcsp-card-body">
							<div class="wcsp-field">
								<label class="wcsp-label" for="wcsp_sku_exceptions">SKUهای مستثنی</label>
								<textarea class="tisa-input tisa-code" id="wcsp_sku_exceptions" rows="5" dir="ltr" name="<?php echo esc_attr( $opt ); ?>[sku_exceptions]" placeholder="LP180&#10;LP181"><?php echo esc_textarea( $s['sku_exceptions'] ); ?></textarea>
								<p class="wcsp-hint">هر خط یک SKU، یا با کاما.</p>
							</div>
							<?php if ( $exception_rows ) : ?>
								<div class="wcsp-field">
									<span class="wcsp-label">پیش‌نمایش تطبیق</span>
									<ul class="wcsp-skus">
										<?php foreach ( $exception_rows as $row ) : ?>
											<li><span class="tisa-code"><?php echo esc_html( $row['sku'] ); ?></span><?php if ( $row['name'] ) : ?><span class="found"><?php echo esc_html( $row['name'] ); ?></span><?php else : ?><span class="missing">محصولی با این SKU یافت نشد</span><?php endif; ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</div>
					</section>
				</section>

				<!-- ================= متن‌ها ================= -->
				<section class="wcsp-panel" data-panel="texts">
					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot"></span><div><h2>متن‌ها و نمایش</h2><p>عنوان در فاکتور/ایمیل/سبد؛ متن چک‌باکس در صفحه محصول.</p></div></div>
						<div class="wcsp-card-body">
							<div class="wcsp-grid-2">
								<div class="wcsp-field">
									<label class="wcsp-label" for="wcsp_label">عنوان گزینه (برچسب فاکتور)</label>
									<input type="text" class="tisa-input" id="wcsp_label" name="<?php echo esc_attr( $opt ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>" />
								</div>
								<div class="wcsp-field">
									<label class="wcsp-label" for="wcsp_checkbox_text">متن کنار چک‌باکس در صفحه محصول</label>
									<input type="text" class="tisa-input" id="wcsp_checkbox_text" name="<?php echo esc_attr( $opt ); ?>[checkbox_text]" value="<?php echo esc_attr( $s['checkbox_text'] ); ?>" />
								</div>
							</div>
							<div class="wcsp-field">
								<span class="wcsp-label">پیش‌نمایش صفحه محصول</span>
								<div class="wcsp-preview">
									<input type="checkbox" checked disabled />
									<span><?php echo esc_html( $s['checkbox_text'] ); ?> <span class="pv-price">+ <?php echo wp_kses_post( wc_price( $s['price'] ) ); ?> به ازای هر عدد</span></span>
								</div>
							</div>
						</div>
					</section>
				</section>

				<!-- ================= راهنما ================= -->
				<section class="wcsp-panel" data-panel="help">
					<section class="wcsp-card">
						<div class="wcsp-card-head"><span class="wcsp-dot wcsp-dot--muted"></span><div><h2>راهنما</h2></div></div>
						<div class="wcsp-card-body">
							<ul class="wcsp-help">
								<li><b>تشخیص خودکار:</b> عنوان محصول (والد در محصولات متغیر) با کلمه کلیدی یا دسته‌بندی انتخابی مطابقت کند.</li>
								<li><b>ترتیب اولویت:</b> استثنای SKU ← کلمات منفی عنوان ← تیک دستی محصول ← دسته‌بندی ← کلمه کلیدی عنوان. یعنی کلمهٔ منفی، «اجباراً فعال» را هم باطل می‌کند.</li>
								<li><b>کلمات منفی:</b> برای بیرون‌گذاشتن زیرگروه‌ها؛ مثلاً با کلمهٔ «تبلت» هیچ محصولی که «قاب تبلت» در عنوانش باشد گزینه نمی‌گیرد. حالت «کلمهٔ کامل» جلوی تطبیق‌های ناخواسته (کیف ↔ کیفیت) را می‌گیرد.</li>
								<li><b>محاسبه:</b> مبلغ پکیج به قیمت هر واحد اضافه می‌شود و با تعداد ضرب می‌شود.</li>
								<li><b>فاکتور و ایمیل:</b> زیر همان آیتم: «بله — X در هر عدد × N عدد = Y».</li>
								<li><b>امنیت:</b> واجد شرایط بودن هنگام افزودن به سبد دوباره سمت سرور بررسی می‌شود؛ قیمت نامعتبر یا قابل‌تأییدنبودن آیتم پکیج checkout را متوقف می‌کند.</li>
								<li><b>توقف اضطراری:</b> دکمهٔ تنظیمات قابلیت را خاموش می‌کند؛ سبدهای قدیمیِ پکیج‌دار تا حذف آیتم یا رفع مشکل پرداخت نمی‌شوند. این دکمه بازگشت کد نیست.</li>
								<li><b>اولویت استثنا:</b> لیست SKU بر همه قوانین مقدم است.</li>
								<li><b>سبد/پرداخت بلوکی:</b> چک‌باکس با صفحات کلاسیک (shortcode) کار می‌کند.</li>
								<li><b>کش آمار:</b> اعداد داشبورد تا یک ساعت کش می‌شوند و با ذخیره محصول یا تغییر سفارش تازه می‌شوند.</li>
							</ul>
						</div>
					</section>
				</section>

				<div class="wcsp-actions">
					<button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ تنظیمات</button>
					<span class="wcsp-hint">تغییرات بلافاصله روی همه محصولات اعمال می‌شود.</span>
				</div>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * ابزارهای تشخیص
	 * ----------------------------------------------------------------*/

	/**
	 * نرمال‌سازی متن فارسی/عربی برای تطبیق مطمئن‌تر.
	 */
	public static function normalize_text( $text ) {
		$text = (string) $text;
		// یکسان‌سازی ی/ک عربی و انواع الف.
		$map = array(
			'ي' => 'ی',
			'ك' => 'ک',
			'إ' => 'ا',
			'أ' => 'ا',
			'ٱ' => 'ا',
			'ة' => 'ه',
			'ؤ' => 'و',
			'ٔ' => '',
		);
		$text = str_replace( array_keys( $map ), array_values( $map ), $text );
		// حذف نیم‌فاصله، کشیده و فاصله‌های اضافی.
		$text = str_replace( array( "\u{200C}", "\u{200F}", "\u{200E}", "\u{0640}" ), array( ' ', '', '', '' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return mb_strtolower( trim( $text ), 'UTF-8' );
	}

	/**
	 * تبدیل رشته چندخطی/کاماجدا به آرایه.
	 */
	public static function parse_list( $raw ) {
		$raw   = (string) $raw;
		$parts = preg_split( '/[\n\r,،]+/u', $raw );
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * آیا متن شامل کلمه است؟ حالت «word» مرز کلمه را رعایت می‌کند.
	 *
	 * در حالت word، پسوندهای جمع فارسی مجاز شمرده می‌شوند تا «تبلت» هم «تبلت» و هم
	 * «تبلت‌ها/تبلت‌های» را بگیرد، ولی «کیف» روی «کیفیت» اثر نگذارد («ی» حرف است، نه پسوند).
	 *
	 * ورودی هر دو طرف باید از قبل با normalize_text یکسان‌سازی شده باشد.
	 *
	 * @param string $text متن نرمال‌شده (مثلاً عنوان محصول).
	 * @param string $word کلمه نرمال‌شده.
	 * @param string $mode contains | word
	 */
	public static function text_has_word( $text, $word, $mode = 'contains' ) {
		$text = (string) $text;
		$word = (string) $word;
		if ( '' === $text || '' === $word ) {
			return false;
		}

		if ( 'word' !== $mode ) {
			return false !== strpos( $text, $word );
		}

		$suffix  = '(?:(?:هایمان|هایتان|هایشان|هایم|هایت|هایش|های|هایی|هاست|ها))?';
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . $suffix . '(?![\p{L}\p{N}])/u';

		return (bool) preg_match( $pattern, $text );
	}

	/**
	 * نخستین کلمهٔ منفی که در عنوان محصول پیدا می‌شود (برای نمایش در پنل).
	 *
	 * @param string $title   عنوان محصول.
	 * @param array  $settings تنظیمات (اختیاری؛ برای جلوگیری از خواندن مکرر آپشن).
	 * @return string کلمهٔ منطبق، یا رشتهٔ خالی اگر هیچ کلمهٔ منفی نبود.
	 */
	public static function find_negative_word( $title, $settings = null ) {
		if ( null === $settings ) {
			$settings = self::get_settings();
		}

		$words = self::parse_list( isset( $settings['negative_keywords'] ) ? $settings['negative_keywords'] : '' );
		if ( empty( $words ) ) {
			return '';
		}

		$title = self::normalize_text( $title );
		$mode  = isset( $settings['negative_mode'] ) ? $settings['negative_mode'] : 'contains';

		foreach ( $words as $word ) {
			$word = self::normalize_text( $word );
			if ( '' === $word ) {
				continue;
			}
			if ( self::text_has_word( $title, $word, $mode ) ) {
				return $word;
			}
		}

		return '';
	}

	/**
	 * آیا محصول واجد شرایط پکیج ویژه است؟
	 *
	 * ترتیب اولویت:
	 * ۱) غیرفعال بودن کلی قابلیت
	 * ۲) لیست استثنا بر اساس SKU (همیشه برنده است)
	 * ۳) کلمات منفیِ عنوان — وتوی کامل: بر دسته‌بندی و «اجباراً فعال» هم مقدم است
	 * ۴) حالت دستی روی محصول (اجباراً فعال / اجباراً غیرفعال)
	 * ۵) دسته‌بندی واجد شرایط
	 * ۶) کلمه کلیدی در عنوان
	 *
	 * @param int|WC_Product $product    محصول یا شناسه آن (می‌تواند واریاسیون باشد).
	 * @param bool           $skip_veto  اگر true باشد، کلمات منفی نادیده گرفته می‌شوند.
	 *                                   فقط برای گزارش «چه چیزی وتو شد» در پنل استفاده می‌شود.
	 */
	public static function is_eligible( $product, $skip_veto = false ) {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		$settings = self::get_settings();

		if ( 'yes' !== $settings['enabled'] || (float) $settings['price'] <= 0 ) {
			return false;
		}

		if ( is_numeric( $product ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		// محصول والد (برای واریاسیون‌ها تشخیص بر اساس والد انجام می‌شود).
		$parent = $product;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof WC_Product ) {
				$parent = $product;
			}
		}

		// ۲) لیست استثنا بر اساس SKU — همیشه اولویت دارد.
		$exceptions = array_map( array( __CLASS__, 'normalize_text' ), self::parse_list( $settings['sku_exceptions'] ) );
		if ( ! empty( $exceptions ) ) {
			$skus = array( $parent->get_sku(), $product->get_sku() );
			foreach ( $skus as $sku ) {
				$sku = self::normalize_text( $sku );
				if ( '' !== $sku && in_array( $sku, $exceptions, true ) ) {
					return false;
				}
			}
		}

		// ۳) کلمات منفی — وتوی کامل روی عنوان (حتی بر «اجباراً فعال» مقدم است).
		if ( ! $skip_veto && '' !== self::find_negative_word( $parent->get_name(), $settings ) ) {
			return false;
		}

		// ۴) حالت دستی روی محصول.
		$mode = (string) $parent->get_meta( self::PRODUCT_META, true );
		if ( 'force_off' === $mode ) {
			return false;
		}
		if ( 'force_on' === $mode ) {
			return true;
		}

		// ۵) دسته‌بندی واجد شرایط.
		$cat_ids = array_map( 'intval', (array) $settings['categories'] );
		if ( ! empty( $cat_ids ) ) {
			$product_cats = wp_get_post_terms( $parent->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_cats ) && array_intersect( $cat_ids, array_map( 'intval', $product_cats ) ) ) {
				return true;
			}
		}

		// ۶) کلمه کلیدی در عنوان.
		$keywords = self::parse_list( $settings['keywords'] );
		if ( ! empty( $keywords ) ) {
			$title = self::normalize_text( $parent->get_name() );
			foreach ( $keywords as $keyword ) {
				$keyword = self::normalize_text( $keyword );
				if ( '' === $keyword ) {
					continue;
				}
				if ( 'starts_with' === $settings['match_mode'] ) {
					if ( 0 === strpos( $title, $keyword ) ) {
						return true;
					}
				} elseif ( false !== strpos( $title, $keyword ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * آمار داشبورد: محصولات واجد شرایط، سفارش‌های دارای پکیج و سری ۱۴ روز اخیر.
	 * نتیجه یک ساعت کش می‌شود.
	 */
	public static function get_stats() {
		// ساختار پیش‌فرض (هم برای محاسبهٔ تازه، هم برای پرکردن کلیدهای جدید
		// در کشِ نسخه‌های قبلی که «negatives/vetoed» را ندارد).
		$stats = array(
			'eligible'       => 0,
			'total_products' => 0,
			'exceptions'     => 0,
			'negatives'      => 0,
			'vetoed_total'   => 0,
			'vetoed'         => array(),
			'orders'         => 0,
			'revenue'        => 0.0,
			'today'          => 0,
			'series'         => array(),
			'recent'         => array(),
		);

		$cached = get_transient( 'wcsp_stats_v1' );
		if ( is_array( $cached ) ) {
			return array_merge( $stats, $cached );
		}

		for ( $i = 13; $i >= 0; $i-- ) {
			$ts             = strtotime( "-{$i} days" );
			$stats['series'][] = array(
				'label' => date_i18n( 'j F', $ts ),
				'date'  => gmdate( 'Y-m-d', $ts + ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) ),
				'count' => 0,
			);
		}

		if ( function_exists( 'WC' ) && WC() ) {
			$settings            = self::get_settings();
			$stats['exceptions'] = count( self::parse_list( $settings['sku_exceptions'] ) );
			$stats['negatives']  = count( self::parse_list( $settings['negative_keywords'] ) );

			// شمارش محصولات واجد شرایط + گزارش محصولاتی که کلمهٔ منفی وتو کرده است
			// (بدون کوئری اضافه: همین حلقه، هم شمارش و هم پیش‌نمایش را می‌سازد).
			$ids = get_posts(
				array(
					'post_type'   => 'product',
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => -1,
				)
			);
			foreach ( $ids as $pid ) {
				$p = wc_get_product( $pid );
				if ( ! $p ) {
					continue;
				}
				if ( self::is_eligible( $p ) ) {
					$stats['eligible']++;
					continue;
				}
				// اگر بدون کلمهٔ منفی واجد شرایط می‌بود، یعنی «وتو» شده است.
				if ( $stats['negatives'] > 0 && self::is_eligible( $p, true ) ) {
					$stats['vetoed_total']++;
					if ( count( $stats['vetoed'] ) < 10 ) {
						$stats['vetoed'][] = array(
							'id'    => $pid,
							'title' => $p->get_name(),
							'word'  => self::find_negative_word( $p->get_name(), $settings ),
							'url'   => admin_url( 'post.php?post=' . (int) $pid . '&action=edit' ),
						);
					}
				}
			}
			$stats['total_products'] = count( $ids );

			// سفارش‌های دارای پکیج: جست‌وجوی مستقیم روی متای آیتم‌های سفارش
			// (سریع، مستقل از HPOS، و بدون بارگذاری همهٔ سفارش‌ها).
			global $wpdb;
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT oi.order_id, SUM( im.meta_value + 0 ) AS total
				   FROM {$wpdb->prefix}woocommerce_order_itemmeta im
				  INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = im.order_item_id
				  WHERE im.meta_key = '_wcsp_package_total'
				    AND oi.order_item_type = 'line_item'
				  GROUP BY oi.order_id
				 HAVING total > 0
				  ORDER BY oi.order_id DESC"
			);
			$today    = gmdate( 'Y-m-d', time() + ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) );
			$statuses = array( 'processing', 'completed' );
			foreach ( (array) $rows as $row ) {
				$order = wc_get_order( (int) $row->order_id );
				if ( ! $order || ! in_array( $order->get_status(), $statuses, true ) ) {
					continue;
				}
				$total = (float) $row->total;
				$stats['orders']++;
				$stats['revenue'] += $total;
				$d = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
				if ( $d === $today ) {
					$stats['today']++;
				}
				foreach ( $stats['series'] as $k => $pt ) {
					if ( $pt['date'] === $d ) {
						$stats['series'][ $k ]['count']++;
						break;
					}
				}
				if ( count( $stats['recent'] ) < 10 ) {
					$stats['recent'][] = array(
						'id'     => $order->get_id(),
						'number' => $order->get_order_number(),
						'date'   => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j F Y' ) : '',
						'status' => wc_get_order_status_name( $order->get_status() ),
						'total'  => $total,
						'url'    => $order->get_edit_order_url(),
					);
				}
			}
		}

		set_transient( 'wcsp_stats_v1', $stats, $stats['orders'] > 0 ? HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/* ------------------------------------------------------------------
	 * فیلد دستی در صفحه ویرایش محصول
	 * ----------------------------------------------------------------*/

	public function product_field() {
		woocommerce_wp_select(
			array(
				'id'          => self::PRODUCT_META,
				'label'       => 'پکیج ویژه قاب',
				'description' => 'روی «خودکار» تشخیص بر اساس عنوان/دسته‌بندی انجام می‌شود. کلمات منفی همیشه مقدم‌اند.',
				'options'     => array(
					'auto'      => 'خودکار (پیش‌فرض)',
					'force_on'  => 'اجباراً فعال باشد',
					'force_off' => 'اجباراً فعال نباشد',
				),
			)
		);

		// وضعیت کنونی: اگر کلمهٔ منفی عنوان را وتو کرده باشد، همان‌جا در ویرایش محصول دیده می‌شود.
		global $post;
		if ( $post instanceof WP_Post ) {
			$word = self::find_negative_word( get_the_title( $post ) );
			?>
			<p class="form-field">
				<label><?php echo esc_html( 'وضعیت کنونی' ); ?></label>
				<span class="description">
					<?php if ( '' !== $word ) : ?>
						<?php
						printf(
							/* translators: %s: negative keyword */
							esc_html__( 'کلمهٔ منفی «%s» این محصول را وتو کرده است؛ گزینهٔ پکیج روی صفحهٔ محصول نمایش داده نمی‌شود.', 'case-special-package' ),
							esc_html( $word )
						);
						?>
					<?php else : ?>
						<?php esc_html_e( 'هیچ کلمهٔ منفی روی این عنوان اثر نگذاشته است.', 'case-special-package' ); ?>
					<?php endif; ?>
				</span>
			</p>
			<?php
		}
	}

	public function save_product_field( $post_id ) {
		if ( isset( $_POST[ self::PRODUCT_META ] ) ) {
			$mode = sanitize_key( wp_unslash( $_POST[ self::PRODUCT_META ] ) );
			if ( ! in_array( $mode, array( 'auto', 'force_on', 'force_off' ), true ) ) {
				$mode = 'auto';
			}
			if ( 'auto' === $mode ) {
				delete_post_meta( $post_id, self::PRODUCT_META );
			} else {
				update_post_meta( $post_id, self::PRODUCT_META, $mode );
			}
		}
	}

	/* ------------------------------------------------------------------
	 * فرانت‌اند: چک‌باکس در صفحه محصول
	 * ----------------------------------------------------------------*/

	public function render_checkbox() {
		global $product;

		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}
		if ( ! self::is_eligible( $product ) ) {
			return;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$text     = $settings['checkbox_text'];
		?>
		<p class="form-row form-row-wide wcsp-package-row">
			<label for="wcsp_package_checkbox" style="display:flex; align-items:center; gap:8px; cursor:pointer;">
				<input type="checkbox" id="wcsp_package_checkbox" name="<?php echo esc_attr( self::CART_KEY ); ?>" value="1" style="width:auto; margin:0;" />
				<span>
					<?php echo esc_html( $text ); ?>
					<?php
					echo wp_kses_post(
						sprintf(
							'<span class="wcsp-package-price">+ %s به ازای هر عدد</span>',
							wc_price( $price )
						)
					);
					?>
				</span>
			</label>
		</p>
		<?php
	}

	/* ------------------------------------------------------------------
	 * سبد خرید
	 * ----------------------------------------------------------------*/

	/**
	 * ذخیره انتخاب کاربر در آیتم سبد — با بررسی مجدد واجد شرایط بودن در سمت سرور.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( empty( $_POST[ self::CART_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $cart_item_data;
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$check_id     = $variation_id ? $variation_id : absint( $product_id );

		if ( self::is_eligible( $check_id ) ) {
			$cart_item_data[ self::CART_KEY ] = 1;
		}

		return $cart_item_data;
	}

	/**
	 * نمایش در سبد خرید و صفحه پرداخت.
	 */
	public function get_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $item_data;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$qty      = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;

		if ( 'yes' !== $settings['enabled'] || $price <= 0 || empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product || ! self::is_eligible( $cart_item['data'] ) ) {
			$item_data[] = array(
				'key'     => $settings['label'],
				'value'   => 'متوقف — این آیتم را از سبد حذف کنید یا با فروشگاه تماس بگیرید.',
				'display' => 'متوقف — این آیتم را از سبد حذف کنید یا با فروشگاه تماس بگیرید.',
			);
			return $item_data;
		}

		$item_data[] = array(
			'key'     => $settings['label'],
			'value'   => wp_strip_all_tags(
				sprintf(
					'بله — %s در هر عدد × %d = %s',
					wc_price( $price ),
					$qty,
					wc_price( $price * $qty )
				)
			),
			'display' => wp_kses_post(
				sprintf(
					'بله — %s در هر عدد × %d = %s',
					wc_price( $price ),
					$qty,
					wc_price( $price * $qty )
				)
			),
		);

		return $item_data;
	}

	/**
	 * مسیر پشتیبان برای افزودن قیمت پکیج به قیمت هر واحد، وقتی موتور قیمت‌گذاری تیساکیس فعال نیست.
	 * در این حالت از قیمت خام regular/sale استفاده می‌شود تا اجرای دوبارهٔ هوک، مبلغ را تکراری اضافه نکند.
	 */
	public function adjust_price( $cart ) {
		$this->reset_cart_product_cache();

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 3 ) {
			return; // محافظت در برابر حلقه؛ در صورت ناتمام‌ماندن تأیید، checkout مسدود می‌شود.
		}

		$settings = self::get_settings();
		$package  = (float) $settings['price'];
		if ( 'yes' !== $settings['enabled'] || $package <= 0 ) {
			return;
		}

		try {
			$cart_items = $cart->get_cart();
		} catch ( Throwable $error ) {
			return;
		}

		foreach ( $cart_items as $cart_item ) {
			if ( empty( $cart_item[ self::CART_KEY ] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}

			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			try {
				// اگر محصول دیگر واجد شرایط نیست، مبلغ اضافه نشود و validation پرداخت را متوقف کند.
				if ( ! self::is_eligible( $product ) ) {
					self::mark_package_price_failure( $product );
					continue;
				}

				// وقتی موتور قیمت‌گذاری تیساکیس فعال است، مبلغ از مسیر هوکِ قیمت نهایی می‌آید.
				if ( self::supports_tisacase_pricing_integration( $product ) || self::has_tisacase_price_filter( $product ) ) {
					continue;
				}

				$base_price = $product->is_on_sale() ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
				if ( ! is_numeric( $base_price ) || (float) $base_price < 0 ) {
					self::mark_package_price_failure( $product );
					continue;
				}

				$base  = (float) $base_price;
				$final = $base + $package;
				if ( ! is_finite( $base ) || ! is_finite( $final ) ) {
					self::mark_package_price_failure( $product );
					continue;
				}

				$product->set_price( $final );
				self::record_package_price( $product, $final );
			} catch ( Throwable $error ) {
				self::mark_package_price_failure( $product );
			}
		}
	}

	/**
	 * افزودن مبلغ پکیج پس از محاسبهٔ قیمت فعال توسط TisaCase Pricing.
	 *
	 * این هوک با قیمت عادی، حراج واقعی، قانون داینامیک و قیمت همکاری کار می‌کند؛
	 * مبلغ فقط برای شیء محصولی افزوده می‌شود که در سبد با گزینهٔ پکیج ثبت شده است.
	 *
	 * @param mixed      $price   قیمت فعال محاسبه‌شده.
	 * @param WC_Product $product محصول جاری.
	 * @return mixed
	 */
	public function add_package_to_active_price( $price, $product ) {
		return $this->add_package_to_cart_product_price( $price, $product );
	}

	/**
	 * پشتیبانی از نسخه‌های قدیمی موتور قیمت‌گذاری که هوک اختصاصی قیمت نهایی ندارند.
	 *
	 * @param mixed      $price   قیمت پس از فیلترهای قبلی ووکامرس.
	 * @param WC_Product $product محصول جاری.
	 * @return mixed
	 */
	public function add_package_after_legacy_pricing( $price, $product ) {
		if ( self::supports_tisacase_pricing_integration( $product ) || ! self::has_tisacase_price_filter( $product ) ) {
			return $price;
		}

		return $this->add_package_to_cart_product_price( $price, $product );
	}

	/**
	 * مبلغ پکیج را فقط برای آیتم انتخاب‌شده در سبد، بدون تغییر قیمت ذخیره‌شدهٔ محصول، اضافه می‌کند.
	 * هر خطا یا قیمت غیرعددی ثبت می‌شود تا اعتبارسنجی، پرداخت ناامن را مسدود کند.
	 *
	 * @param mixed      $price   قیمت فعال.
	 * @param WC_Product $product محصول جاری.
	 * @return mixed
	 */
	private function add_package_to_cart_product_price( $price, $product ) {
		if ( ! $product instanceof WC_Product ) {
			return $price;
		}

		try {
			if ( ! self::is_selected_cart_product( $product ) ) {
				return $price;
			}
			if ( ! is_numeric( $price ) || (float) $price < 0 ) {
				self::mark_package_price_failure( $product );
				return $price;
			}

			$settings = self::get_settings();
			$package  = (float) $settings['price'];
			if ( 'yes' !== $settings['enabled'] || $package <= 0 || ! self::is_eligible( $product ) ) {
				self::mark_package_price_failure( $product );
				return $price;
			}

			$final = (float) $price + $package;
			if ( ! is_finite( $final ) ) {
				self::mark_package_price_failure( $product );
				return $price;
			}

			self::record_package_price( $product, $final );
			return $final;
		} catch ( Throwable $error ) {
			self::mark_package_price_failure( $product );
			return $price;
		}
	}

	/** شروع نسل تازهٔ محاسبه؛ رکوردهای قیمت قبلی تأیید محسوب نمی‌شوند. */
	public function begin_price_verification() {
		self::$applied_package_prices          = array();
		self::$package_price_failures          = array();
		self::$package_validation_notice_added = false;
		$this->reset_cart_product_cache();
	}

	/** ثبت قیمت مورد انتظار پس از افزودن موفق پکیج. */
	private static function record_package_price( $product, $expected ) {
		$hash = spl_object_hash( $product );
		self::$applied_package_prices[ $hash ] = array(
			'expected' => (float) $expected,
			'verified' => true,
		);
		unset( self::$package_price_failures[ $hash ] );
	}

	/** ثبت خطای قیمت برای جلوگیری از پرداخت با مبلغ نامطمئن. */
	private static function mark_package_price_failure( $product ) {
		if ( $product instanceof WC_Product ) {
			self::$package_price_failures[ spl_object_hash( $product ) ] = true;
		}
	}

	/**
	 * اگر موتور داینامیک خطا داد، ثبت کن تا validation پرداخت را fail-closed متوقف کند.
	 *
	 * @param WC_Product $product محصولی که محاسبه‌اش شکست خورده است.
	 */
	public function mark_tisacase_price_failure( $product ) {
		// اگر محصول در سبد پکیج‌دار نباشد، رکورد بلااستفاده می‌ماند و checkout آن را نادیده می‌گیرد.
		if ( $product instanceof WC_Product ) {
			self::mark_package_price_failure( $product );
		}
	}

	/** قیمت مورد انتظار در مسیر پشتیبان، وقتی موتور قیمت‌گذاری تیساکیس فعال نیست. */
	private static function fallback_package_price( $product, $settings ) {
		try {
			$base_price = $product->is_on_sale() ? $product->get_sale_price( 'edit' ) : $product->get_regular_price( 'edit' );
			if ( ! is_numeric( $base_price ) || (float) $base_price < 0 ) {
				return null;
			}

			$expected = (float) $base_price + (float) $settings['price'];
			return is_finite( $expected ) ? $expected : null;
		} catch ( Throwable $error ) {
			return null;
		}
	}

	/** آیا قیمت مورد انتظار با مبلغ نهایی getter، با دقت اعشار فروشگاه، برابر است؟ */
	private static function package_prices_match( $actual, $expected ) {
		if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) ) {
			return false;
		}

		$decimals  = function_exists( 'wc_get_price_decimals' ) ? max( 0, min( 8, (int) wc_get_price_decimals() ) ) : 2;
		$tolerance = 0.5 / pow( 10, $decimals );
		return abs( (float) $actual - (float) $expected ) <= $tolerance;
	}

	/** پاک‌کردن نگاشت کش‌شده پس از تغییر محتوای سبد. */
	public function reset_cart_product_cache() {
		self::$packaged_cart_product_hashes = null;
	}

	/** بازنشانی وضعیت هشدار پس از تغییر سبد. */
	public function reset_cart_state() {
		$this->reset_cart_product_cache();
		self::$applied_package_prices          = array();
		self::$package_price_failures          = array();
		self::$package_validation_notice_added = false;
	}

	/** بررسی می‌کند همهٔ آیتم‌های پکیج‌دار واقعاً با قیمت درست وارد totals شده‌اند. */
	private function has_unverified_package_price() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->cart ) || ! is_object( $woocommerce->cart ) || ! method_exists( $woocommerce->cart, 'get_cart' ) ) {
			return false;
		}

		try {
			$cart_items = (array) $woocommerce->cart->get_cart();
		} catch ( Throwable $error ) {
			return true;
		}

		$has_package_line = false;
		foreach ( $cart_items as $cart_item ) {
			if ( ! empty( $cart_item[ self::CART_KEY ] ) ) {
				$has_package_line = true;
				break;
			}
		}
		if ( ! $has_package_line ) {
			return false;
		}

		// اطمینان از اجرای مسیر پشتیبان حتی اگر validation پیش از totals فراخوانی شده باشد.
		// در صورت فعال‌بودن TCP، این تابع قیمت خام را دست‌کاری نمی‌کند.
		$this->adjust_price( $woocommerce->cart );

		$settings = self::get_settings();
		foreach ( $cart_items as $cart_item ) {
			if ( empty( $cart_item[ self::CART_KEY ] ) ) {
				continue;
			}

			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			if ( ! $product instanceof WC_Product || 'yes' !== $settings['enabled'] || (float) $settings['price'] <= 0 ) {
				return true;
			}

			try {
				if ( ! self::is_eligible( $product ) ) {
					return true;
				}
				$actual_price = $product->get_price();
			} catch ( Throwable $error ) {
				self::mark_package_price_failure( $product );
				return true;
			}

			$hash = spl_object_hash( $product );
			if ( ! empty( self::$package_price_failures[ $hash ] ) ) {
				return true;
			}

			if ( ! empty( self::$applied_package_prices[ $hash ]['verified'] ) ) {
				$expected_price = self::$applied_package_prices[ $hash ]['expected'];
			} elseif ( ! self::has_tisacase_price_filter( $product ) ) {
				$expected_price = self::fallback_package_price( $product, $settings );
				if ( null === $expected_price ) {
					return true;
				}
			} else {
				return true;
			}

			if ( ! self::package_prices_match( $actual_price, $expected_price ) ) {
				return true;
			}
		}

		return false;
	}

	/** جلوگیری از پرداخت با مبلغ پکیجِ نامطمئن در سبد کلاسیک. */
	public function validate_package_pricing() {
		if ( $this->has_unverified_package_price() ) {
			$this->add_package_price_error_notice();
		}
	}

	/** جلوگیری از ثبت سفارش اگر اعتبارسنجی سبد در مسیر پرداخت رد شده باشد. */
	public function validate_checkout_package_pricing( $data, $errors ) {
		if ( ! $this->has_unverified_package_price() ) {
			return;
		}

		$message = self::package_price_error_message();
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wcsp_package_price_unverified', $message );
		} else {
			$this->add_package_price_error_notice();
		}
	}

	private function add_package_price_error_notice() {
		if ( self::$package_validation_notice_added || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		wc_add_notice( self::package_price_error_message(), 'error' );
		self::$package_validation_notice_added = true;
	}

	private static function package_price_error_message() {
		return __( 'قیمت پکیج این سبد قابل تأیید نیست یا فروش پکیج متوقف شده است. برای جلوگیری از ثبت سفارش با مبلغ اشتباه، آیتم پکیج‌دار را حذف کنید یا با فروشگاه تماس بگیرید.', 'case-special-package' );
	}

	/** آیا شیء جاری همان آیتم سبدی است که مشتری برایش پکیج را انتخاب کرده؟ */
	private static function is_selected_cart_product( $product ) {
		if ( ! $product instanceof WC_Product || ! function_exists( 'WC' ) ) {
			return false;
		}

		$woocommerce = WC();
		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->cart ) || ! is_object( $woocommerce->cart ) || ! method_exists( $woocommerce->cart, 'get_cart' ) ) {
			return false;
		}

		if ( null === self::$packaged_cart_product_hashes ) {
			self::$packaged_cart_product_hashes = array();
			foreach ( (array) $woocommerce->cart->get_cart() as $cart_item ) {
				if ( ! empty( $cart_item[ self::CART_KEY ] ) && isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
					self::$packaged_cart_product_hashes[ spl_object_hash( $cart_item['data'] ) ] = true;
				}
			}
		}

		return isset( self::$packaged_cart_product_hashes[ spl_object_hash( $product ) ] );
	}

	/** آیا نسخهٔ نصب‌شدهٔ TisaCase Pricing از هوک قیمت نهایی پشتیبانی می‌کند و فیلترش فعال است؟ */
	private static function supports_tisacase_pricing_integration( $product ) {
		return class_exists( 'TCP_Rules' )
			&& defined( 'TCP_Rules::FINAL_ACTIVE_PRICE_HOOK' )
			&& self::has_tisacase_price_filter( $product );
	}

	/** آیا فیلتر قیمت فعال TisaCase Pricing روی نوع محصول جاری نصب است؟ */
	private static function has_tisacase_price_filter( $product ) {
		if ( ! class_exists( 'TCP_Rules' ) || ! function_exists( 'has_filter' ) || ! $product instanceof WC_Product ) {
			return false;
		}

		$hook = $product->is_type( 'variation' ) ? 'woocommerce_product_variation_get_price' : 'woocommerce_product_get_price';
		return false !== has_filter( $hook, array( 'TCP_Rules', 'filter_active_price' ) );
	}

	/* ------------------------------------------------------------------
	 * سفارش / فاکتور
	 * ----------------------------------------------------------------*/

	/**
	 * ذخیره متای آیتم سفارش — به‌صورت خودکار در فاکتور، ایمیل و پیشخوان نمایش داده می‌شود.
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values[ self::CART_KEY ] ) ) {
			return;
		}

		$settings = self::get_settings();
		$price    = (float) $settings['price'];
		$qty      = (int) $item->get_quantity();
		$total    = $price * $qty;

		$item->add_meta_data(
			$settings['label'],
			sprintf(
				'بله — %s در هر عدد × %d عدد = %s',
				wp_strip_all_tags( wc_price( $price ) ),
				$qty,
				wp_strip_all_tags( wc_price( $total ) )
			),
			true
		);

		// مبلغ عددی خالص هم برای استفاده احتمالی سایر پلاگین‌های فاکتور.
		$item->add_meta_data( '_wcsp_package_unit_price', $price, true );
		$item->add_meta_data( '_wcsp_package_total', $total, true );
	}
}

/**
 * راه‌اندازی پس از بارگذاری ووکامرس.
 */
function wcsp_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>پلاگین «پکیج ویژه قاب موبایل» برای کار کردن به ووکامرس نیاز دارد.</p></div>';
			}
		);
		return;
	}
	WC_Case_Special_Package::instance();
}
add_action( 'plugins_loaded', 'wcsp_bootstrap' );
