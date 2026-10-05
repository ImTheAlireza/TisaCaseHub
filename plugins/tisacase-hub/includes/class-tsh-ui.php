<?php
/**
 * لایهٔ رابط: تنظیمات، تزریق CSS زبان طراحی روی اسکرین‌های افزونه‌ها، توکن‌های رنگ/چگالی.
 *
 * هیچ افزونه‌ای نباید هاب را بشناسد؛ هاب خودش روی اسکرین‌های آن‌ها استایل تزریق می‌کند.
 * چون CSS افزونه‌ها در اولویت پیش‌فرض (۱۰) enqueue می‌شوند و اینجا اولویت ۱ است،
 * ترتیب کسکید هم درست می‌ماند.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_UI' ) ) {

	/**
	 * تنظیمات + تزریق استایل.
	 */
	final class TSH_UI {

		/** اسکرین‌هایی که مال همهٔ محصول‌هاست (نه فقط ابزارهای ما). */
		const SHARED = array( 'edit-product' );

		/** @var array|null تنظیمات merge‌شدهٔ همین درخواست. */
		private static $settings = null;

		/** @var array<string,array> نگاشت اسکرین/اسلگ (کش درخواستی). */
		private static $screens = null;

		/**
		 * مقادیر پیش‌فرض.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				'accent'         => '#0E7C6B',
				'compact'        => 0,
				'hide_scattered' => 1,
				'style_plugins'  => 1,
				'style_product_screens' => 1,
				'menu_position'         => 'top',
				'menu_position_custom'  => '',
				'hidden'         => array(),
				// پایهٔ آدرس زیپ‌های مجموعه برای دکمهٔ «نصب از مخزن» (خالی = پیش‌فرض هاب).
				'zip_base'       => '',
				'repo'           => 'ImTheAlireza/TisaCaseHub',
				'branch'         => 'main',
			);
		}

		/**
		 * همهٔ تنظیمات.
		 *
		 * @return array
		 */
		public static function settings() {
			if ( null === self::$settings ) {
				$saved = get_option( TSH_OPTION, array() );
				if ( ! is_array( $saved ) ) {
					$saved = array();
				}
				self::$settings = wp_parse_args( $saved, self::defaults() );
			}
			return self::$settings;
		}

		/**
		 * یک کلید تنظیمی با پیش‌فرض.
		 *
		 * @param string $key      کلید.
		 * @param mixed  $fallback پیش‌فرض در صورت نبود.
		 * @return mixed
		 */
		public static function setting( $key, $fallback = null ) {
			$s = self::settings();
			if ( ! isset( $s[ $key ] ) ) {
				return $fallback;
			}
			return $s[ $key ];
		}

		/**
		 * اتصال هوک‌ها.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'inject' ), 1 );
			add_filter( 'body_class', array( __CLASS__, 'body_class' ), 20 );
		}

		/**
		 * آیا این اسکرین باید استایل هاب را بگیرد؟
		 *
		 * @return bool
		 */
		public static function is_hub_screen() {
			$screen = self::screen_id();
			return (bool) preg_match( '/(^|_)(' . preg_quote( TSH_SLUG, '/' ) . ')(-|$)/', (string) $screen );
		}

		/**
	 * تطبیق ذخیره: اگر `_page_<slug>` اسکرین، یکی از صفحه‌های ثبت‌شده باشد.
	 *
	 * وردپرس نام اسکرین را از والد می‌سازد (product_page_x / edit_page_x /
	 * woocommerce_page_x)؛ این پسوند آن را بی‌نیاز از درست‌گمان‌کردن والد می‌کند.
	 *
	 * @param string $screen شناسهٔ اسکرین.
	 * @return string|null کلید آیتم
	 */
		private static function match_by_suffix( $screen ) {
			if ( '' === $screen || false === strpos( $screen, '_page_' ) ) {
				return null;
			}
			$map  = self::map();
			$slugs = isset( $map['slugs'] ) ? $map['slugs'] : array();
			foreach ( $slugs as $slug => $key ) {
				$tail = '_page_' . $slug;
				if ( substr( $screen, -strlen( $tail ) ) === $tail ) {
					return $key;
				}
			}
			return null;
		}

		/**
	 * شناسهٔ اسکرین فعلی (بدون وابستگی به $hook).
		 *
		 * @return string
		 */
		public static function screen_id() {
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->id ) ) {
					return (string) $screen->id;
				}
			}
			global $current_screen;
			return ( isset( $current_screen->id ) && $current_screen->id ) ? (string) $current_screen->id : '';
		}

		/**
		 * نقشهٔ اسکرین => افزونه. کش می‌شود تا `get_plugins()` پشتِ هر بار
		 * باز کردن یک صفحهٔ ادمین اجرا نشود؛ با فعال/غیرفعال شدن افزونه یا
		 * ذخیرهٔ تنظیمات، کش پاک می‌شود.
		 *
		 * @return array{screens:array,slugs:array}
		 */
		public static function map() {
			if ( null === self::$screens ) {
				$cached = get_transient( 'tsh_screen_map' );
				if ( is_array( $cached ) && isset( $cached['screens'], $cached['slugs'] ) ) {
					self::$screens = $cached;
				} else {
					$screens = TSH_Registry::screens();
					foreach ( array( 'toplevel_page_' . TSH_SLUG, TSH_SLUG . '_page_' . TSH_SLUG . '-settings' ) as $own ) {
						$screens[ $own ] = 'hub';
					}
					self::$screens = array(
						'screens' => $screens,
						'slugs'   => TSH_Registry::page_slugs(),
					);
					set_transient( 'tsh_screen_map', self::$screens, 12 * HOUR_IN_SECONDS );
				}
			}
			return self::$screens;
		}

		/**
		 * نقشهٔ اسکرین => افزونه.
		 *
		 * @return array<string,string>
		 */
		public static function allowlist() {
			$map = self::map();
			return isset( $map['screens'] ) ? $map['screens'] : array();
		}

		/**
	 * دامنهٔ اسکرین: 'hub' (خودِ صفحه‌های هاب) یا 'plugin' (صفحهٔ یک افزونه) یا ''.
	 *
	 * @param string $screen شناسهٔ اسکرین.
	 * @return string
	 */
		public static function scope( $screen ) {
			if ( '' === $screen ) {
				return '';
			}
			// صفحه‌های مشترک (لیست و ویرایش محصول) را می‌توان جدا خاموش کرد:
			// جدول واریاسیون‌ها و پنل دادهٔ محصول، ووکامرس را هم در بر می‌گیرد.
			if ( in_array( $screen, self::SHARED, true ) && ! self::setting( 'style_product_screens' ) ) {
				return '';
			}
			$map = self::allowlist();
			if ( self::is_hub_screen() || ( isset( $map[ $screen ] ) && 'hub' === $map[ $screen ] ) ) {
				return 'hub';
			}
			if ( isset( $map[ $screen ] ) ) {
				return 'plugin';
			}
			return self::match_by_suffix( $screen ) ? 'plugin' : '';
		}

		/**
		 * تزریق CSS/JS. اولویت ۱ تا قبل از استایل خود افزونه‌ها چاپ شود.
		 *
		 * @param string $hook هوک صفحه (استفادهٔ جانبی؛ ملاک اصلی screen id است).
		 * @return void
		 */
		public static function inject( $hook = '' ) {
			$screen = self::screen_id();
			if ( '' === $screen ) {
				$screen = (string) $hook;
			}
			$scope     = self::scope( $screen );
			$on_hub    = 'hub' === $scope;
			$on_plugin = 'plugin' === $scope;

			if ( ! $on_hub && ! ( $on_plugin && self::setting( 'style_plugins' ) ) ) {
				return;
			}

			$base = TSH_URL . 'assets/';

			wp_enqueue_style( 'tisacase-ui', $base . 'tisacase-ui.css', array(), tsh_ver( 'assets/tisacase-ui.css' ) );
			wp_add_inline_style( 'tisacase-ui', self::css() );

			$fonts = self::font_face();
			if ( $fonts ) {
				wp_add_inline_style( 'tisacase-ui', $fonts );
			}

			if ( $on_hub ) {
				wp_enqueue_style( 'tisacase-hub', $base . 'hub.css', array( 'tisacase-ui' ), tsh_ver( 'assets/hub.css' ) );
				wp_enqueue_script( 'tisacase-hub', $base . 'hub.js', array(), tsh_ver( 'assets/hub.js' ), true );
				wp_localize_script(
					'tisacase-hub',
					'TisaCaseHub',
					array(
						'ajax'   => admin_url( 'admin-ajax.php' ),
						'nonce'  => wp_create_nonce( 'tsh_hub' ),
						'rest'   => '',
						'screen' => $screen,
						// اگر شماره‌گذاریِ قلم فارسی است، JS هم همان ارقام را بنویسد.
						'fa'     => (bool) preg_match( '/^[\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', TSH_View::num( 10 ) ),
					'i18n'   => array(
						'pinned'     => __( 'سنجاق شد', 'tisacase-hub' ),
						'unpinned'   => __( 'از سنجاق خارج شد', 'tisacase-hub' ),
						'error'      => __( 'خطا در ارتباط با سرور.', 'tisacase-hub' ),
						'updating'   => __( 'در حال نصب…', 'tisacase-hub' ),
						'zipOnly'    => __( 'فقط فایل .zip پذیرفته می‌شود.', 'tisacase-hub' ),
						'saved'      => __( 'ذخیره شد', 'tisacase-hub' ),
						'copied'     => __( 'کپی شد', 'tisacase-hub' ),
						'tools'      => __( 'ابزار', 'tisacase-hub' ),
						'match'      => __( 'نتیجه', 'tisacase-hub' ),
						'syncedOk'   => __( 'فهرست مخزن همگام شد (%s افزونه).', 'tisacase-hub' ),
						'syncedOkOffline' => __( 'دسترسی به مخزن نبود؛ فهرست همراه خودِ هاب استفاده شد (%s افزونه).', 'tisacase-hub' ),
						'syncedOkProbe' => __( 'سقف API گیت‌هاب اجازه نداد؛ فهرست با بررسی مستقیم فایل‌های مخزن ساخته شد (%s افزونه).', 'tisacase-hub' ),
						'syncedTools' => __( 'ابزارهای صفحه: %s.', 'tisacase-hub' ),
						'syncedBranch' => __( 'شاخه: %s', 'tisacase-hub' ),
						'syncReloading' => __( 'صفحه تازه می‌شود…', 'tisacase-hub' ),
					),
					)
				);
			}
		}

		/**
		 * کلاس بدنه: فعال‌ساز لایهٔ نرمال‌ساز + حالت کم‌فضا.
		 *
		 * @param array $classes کلاس‌ها.
		 * @return array
		 */
		public static function body_class( $classes ) {
			$scope = self::scope( self::screen_id() );
			if ( '' === $scope ) {
				return $classes;
			}
			if ( 'plugin' === $scope && ! self::setting( 'style_plugins' ) ) {
				return $classes;
			}
			$classes[] = 'tisa-scope';
			if ( 'hub' === $scope ) {
				$classes[] = 'tisa-hub-page';
			}
			if ( self::setting( 'compact' ) ) {
				$classes[] = 'tisa-compact';
			}
			return array_values( array_unique( (array) $classes ) );
		}

		/**
		 * توکن‌های انتخابی کاربر → CSS.
		 *
		 * @return string
		 */
		public static function css() {
			$vars = self::vars();
			if ( ! $vars ) {
				return '';
			}
			$parts = array();
			foreach ( $vars as $name => $value ) {
				$parts[] = $name . ':' . $value;
			}
			return ':root{' . implode( ';', $parts ) . ';}';
		}

		/**
		 * مجموعهٔ متغیرهای قابل‌بازنویسی (رنگ برند + سایه‌های مشتق).
		 *
		 * @return array<string,string>
		 */
		public static function vars() {
			$accent = self::setting( 'accent', '#0E7C6B' );
			if ( ! self::is_hex( $accent ) ) {
				return array();
			}
			$p = self::palette( $accent );
			$out = array();
			foreach ( $p as $k => $v ) {
				$out[ '--tisa-' . $k ] = $v;
			}
			return $out;
		}

		/**
		 * ساخت پالت کامل از یک رنگ. (همان کاری که در پیش‌نمایش با JS انجام می‌شد.)
		 *
		 * @param string $hex رنگ اصلی.
		 * @return array<string,string>
		 */
		public static function palette( $hex ) {
			$hex   = self::normalize_hex( $hex );
			return array(
				'primary'         => $hex,
				'primary-ink'     => self::mix( $hex, '#000000', 0.2 ),
				'primary-deep'    => self::mix( $hex, '#000000', 0.42 ),
				'primary-bright'  => self::mix( $hex, '#ffffff', 0.16 ),
				'primary-soft'    => self::mix( $hex, '#ffffff', 0.9 ),
				'primary-tint'    => self::mix( $hex, '#ffffff', 0.955 ),
				'border-strong'   => self::mix( $hex, '#ffffff', 0.75 ),
				'ring'            => '0 0 0 3px ' . self::alpha( $hex, 0.22 ),
				'ring-danger'     => '0 0 0 3px rgba(181,69,58,.22)',
			);
		}

		/**
		 * اعتبارسنجی هگز.
		 *
		 * @param mixed $hex مقدار.
		 * @return bool
		 */
		public static function is_hex( $hex ) {
			return (bool) preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string) $hex );
		}

		/**
		 * نرمال‌سازی هگز سه‌رقمی و کوچک‌کردن حروف.
		 *
		 * @param string $hex رنگ.
		 * @return string
		 */
		public static function normalize_hex( $hex ) {
			$hex = strtolower( ltrim( (string) $hex, '#' ) );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			return '#' . $hex;
		}

		/**
		 * تبدیل هگز به آرایهٔ RGB.
		 *
		 * @param string $hex رنگ.
		 * @return array{0:int,1:int,2:int}
		 */
		private static function rgb( $hex ) {
			$hex = ltrim( self::normalize_hex( $hex ), '#' );
			return array(
				(int) hexdec( substr( $hex, 0, 2 ) ),
				(int) hexdec( substr( $hex, 2, 2 ) ),
				(int) hexdec( substr( $hex, 4, 2 ) ),
			);
		}

		/**
		 * قاطی کردن دو رنگ.
		 *
		 * @param string $a   رنگ مبدا.
		 * @param string $b   رنگ مقصد.
		 * @param float  $pct نسبت (۰ تا ۱).
		 * @return string هگز.
		 */
		private static function mix( $a, $b, $pct ) {
			$ra = self::rgb( $a );
			$rb = self::rgb( $b );
			$out = array();
			for ( $i = 0; $i < 3; $i++ ) {
				$v       = (int) round( $ra[ $i ] * ( 1 - $pct ) + $rb[ $i ] * $pct );
				$out[] = str_pad( dechex( max( 0, min( 255, $v ) ) ), 2, '0', STR_PAD_LEFT );
			}
			return '#' . implode( '', $out );
		}

		/**
		 * رنگ با آلفا.
		 *
		 * @param string $hex  رنگ.
		 * @param float  $alpha شفافیت.
		 * @return string
		 */
		private static function alpha( $hex, $alpha ) {
			$c = self::rgb( $hex );
			return 'rgba(' . $c[0] . ',' . $c[1] . ',' . $c[2] . ',' . $alpha . ')';
		}

		/**
		 * @font-face برای فونت‌های محلی، اگر فایلشان نصب شده باشد.
		 *
		 * نام فایل باید وزن را داشته باشد: vazirmatn-400.woff2 … vazirmatn-800.woff2
		 * تا هاب بداند برای چه وزنی است. نبود فایل = استفاده از قلم سیستم (بی‌ضرر).
		 *
		 * @return string
		 */
		public static function font_face() {
			static $cached = null;
			if ( null !== $cached ) {
				return $cached;
			}
			$dir   = TSH_DIR . 'assets/fonts';
			$files = is_dir( $dir ) ? (array) glob( $dir . '/*.woff2' ) : array();
			$css   = '';
			foreach ( $files as $file ) {
				$name = basename( $file );
				if ( ! preg_match( '/(\d{3})/', $name, $m ) ) {
					continue;
				}
				$weight = (int) $m[1];
				if ( $weight < 100 || $weight > 900 ) {
					continue;
				}
				$style = false !== stripos( $name, 'italic' ) ? 'italic' : 'normal';
				$css  .= sprintf(
					'@font-face{font-family:"Vazirmatn";src:url(%s) format("woff2");font-weight:%d;font-style:%s;font-display:swap;}',
					wp_json_encode( TSH_URL . 'assets/fonts/' . $name ),
					$weight,
					$style
				);
			}
			$cached = $css;
			return $cached;
		}

		/**
		 * وضعیت فونت محلی (برای صفحهٔ تنظیمات).
		 *
		 * @return array{count:int,weights:string}
		 */
		public static function font_status() {
			$dir   = TSH_DIR . 'assets/fonts';
			$files = is_dir( $dir ) ? (array) glob( $dir . '/*.woff2' ) : array();
			$w     = array();
			foreach ( $files as $file ) {
				if ( preg_match( '/(\d{3})/', basename( $file ), $m ) ) {
					$w[] = (int) $m[1];
				}
			}
			sort( $w );
			return array(
				'count'   => count( $files ),
				'weights' => $w ? implode( ', ', array_map( 'strval', $w ) ) : '',
			);
		}

		/**
		 * پاک کردن کش‌های مرتبط (بعد از ذخیرهٔ تنظیمات).
		 *
		 * @return void
		 */
		public static function flush() {
			self::$settings = null;
			self::$screens  = null;
			delete_transient( 'tsh_screen_map' );
		}
	}
}
