<?php
/**
 * منو، صفحه‌ها، اقدام‌ها (فعال/غیرفعال/مخفی) و AJAX.
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Admin' ) ) {

	/**
	 * لایهٔ مدیریتی هاب.
	 */
	final class TSH_Admin {

		/** @var string capability صفحه‌ها. */
		private static $cap = 'manage_woocommerce';

		/** @var string هوک صفحهٔ اصلی. */
		private static $main = '';

		/**
		 * اتصال هوک‌ها.
		 *
		 * @return void
		 */
		public static function init() {
			self::$cap = (string) apply_filters( 'tisacase_hub_cap', 'manage_woocommerce' );

			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 5 );
			add_action( 'admin_print_styles', array( __CLASS__, 'hide_scattered' ), 5 );

			// نگاشت اسکرین‌ها به فهرست افزونه‌ها وابسته است؛ با هر تغییر کش می‌رود.
			add_action( 'activated_plugin', array( 'TSH_UI', 'flush' ) );
			add_action( 'deactivated_plugin', array( 'TSH_UI', 'flush' ) );
			add_action( 'switch_theme', array( 'TSH_UI', 'flush' ) );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_post_tisacase_hub_action', array( __CLASS__, 'handle_action' ) );
			add_action( 'admin_post_tisacase_hub_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_tisacase_hub_update', array( __CLASS__, 'handle_update' ) );
			add_action( 'admin_post_tisacase_hub_update_repo', array( __CLASS__, 'handle_update_repo' ) );
			add_action( 'admin_post_tisacase_hub_install', array( __CLASS__, 'handle_install' ) );
			if ( class_exists( 'TSH_Remote' ) ) {
				TSH_Remote::allow();
			}
			// بنرهای افزونه‌های دیگر (آپدیت دیجی‌پی، ووکامرس، …) روی صفحه‌های هاب چاپ نشوند.
			add_action( 'in_admin_header', array( __CLASS__, 'mute_foreign_notices' ), 999 );
			add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 61 );

			add_action( 'wp_ajax_tsh_pin', array( __CLASS__, 'ajax_pin' ) );
			add_action( 'wp_ajax_tsh_prefs', array( __CLASS__, 'ajax_prefs' ) );
			add_action( 'wp_ajax_tsh_repo_test', array( __CLASS__, 'ajax_repo_test' ) );
			add_action( 'wp_ajax_tsh_repo_connect', array( __CLASS__, 'ajax_repo_connect' ) );
			add_action( 'wp_ajax_tsh_repo_sync', array( __CLASS__, 'ajax_repo_sync' ) );
		}

		/**
		 * ثبت منو.
		 *
		 * @return void
		 */
		public static function menu() {
			$position = self::menu_position();
			$icon     = TSH_View::icon_data_uri( 'grid' );

			self::$main = ( '' === $position )
				? add_menu_page( __( 'اختصاصی تیساکیس', 'tisacase-hub' ), __( 'اختصاصی تیساکیس', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ), $icon )
				: add_menu_page( __( 'اختصاصی تیساکیس', 'tisacase-hub' ), __( 'اختصاصی تیساکیس', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ), $icon, $position );

			add_submenu_page( TSH_SLUG, __( 'ابزارها', 'tisacase-hub' ), __( 'ابزارها', 'tisacase-hub' ), self::$cap, TSH_SLUG, array( __CLASS__, 'render_hub' ) );
			add_submenu_page( TSH_SLUG, __( 'ظاهر و تنظیمات', 'tisacase-hub' ), __( 'ظاهر و تنظیمات', 'tisacase-hub' ), self::$cap, TSH_SLUG . '-settings', array( __CLASS__, 'render_settings' ) );

			// عمداً هیچ ورودی‌ای داخل منوی ووکامرس ثبت نمی‌شود: هاب یک گزینهٔ مستقل در
			// نوار کنار است. (جایگاهش از تنظیمات قابل تغییر است.)
		}

		/**
		 * جایگاه‌های آماده در نوار کنار (عددِ جایگاه وردپرس + توضیحش).
		 *
		 * @return array<string,array{label:string,pos:string}>
		 */
		public static function positions() {
			return array(
				'top'         => array(
					'label' => __( 'بالا — درست بعد از داشبورد', 'tisacase-hub' ),
					'pos'   => '2.5',
				),
				'before_wc'   => array(
					'label' => __( 'بالای ووکامرس', 'tisacase-hub' ),
					'pos'   => '55.4',
				),
				'after_products' => array(
					'label' => __( 'بعد از محصولات (جای همیشگی این ابزارها)', 'tisacase-hub' ),
					'pos'   => '30.5',
				),
				'before_tools' => array(
					'label' => __( 'نزدیک پایین — قبل از ابزارها', 'tisacase-hub' ),
					'pos'   => '74.5',
				),
				'default'     => array(
					'label' => __( 'پیش‌فرض وردپرس (انتهای فهرست)', 'tisacase-hub' ),
					'pos'   => '',
				),
				'custom'      => array(
					'label' => __( 'عدد دلخواه', 'tisacase-hub' ),
					'pos'   => 'custom',
				),
			);
		}

		/**
		 * عدد جایگاه نهایی.
		 *
		 * @return string رشتهٔ خالی یعنی «به وردپرس بسپار».
		 */
		public static function menu_position() {
			$key  = (string) TSH_UI::setting( 'menu_position', 'top' );
			$list = self::positions();
			if ( ! isset( $list[ $key ] ) ) {
				$key = 'top';
			}
			$pos = $list[ $key ]['pos'];
			if ( 'custom' === $pos ) {
				$raw = (string) TSH_UI::setting( 'menu_position_custom', '' );
				return preg_match( '/^\d{1,2}(\.\d{1,2})?$/', $raw ) ? $raw : '';
			}
			return (string) apply_filters( 'tisacase_hub_menu_position', $pos, $key );
		}
		/**
		 * مخفی‌کردن ورودی‌های پراکندهٔ افزونه‌ها از منوی وردپرس.
		 *
		 * این کار عمداً فقط ظاهری است: هیچ درای از $menu/$submenu کم نمی‌شود.
		 * وردپرس مسیرِ `admin.php?page=…` را از همان آرایهٔ منو پیدا می‌کند، پس
		 * remove_submenu_page() صفحه را می‌کُشد و URL مستقیم با خطای «شما اجازهٔ
		 * دسترسی به این برگه را ندارید» رد می‌شود. اینجا آیتم سر جایش می‌ماند و
		 * تنها با CSS دیده نمی‌شود؛ با CSS-بلاک‌شدن هم صفحه باز است.
		 *
		 * @return void
		 */
		public static function hide_scattered() {
			if ( is_network_admin() || is_user_admin() || ! TSH_UI::setting( 'hide_scattered' ) ) {
				return;
			}
			$css = self::scattered_css();
			if ( '' === $css ) {
				return;
			}
			echo '<style id="tisa-hide-scattered">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * قاعدهٔ CSS برای هر آیتم منویی که افزونه‌های ما ثبت کرده‌اند.
		 *
		 * @return string
		 */
		private static function scattered_css() {
			$out = array();
			foreach ( TSH_Registry::menu_entries() as $entry ) {
				$slug = (string) $entry['slug'];
				if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9_.\-\/?&=]+$/', $slug ) ) {
					continue;
				}
				if ( 'top' === $entry['parent'] ) {
					$out[] = '#adminmenu #toplevel_page_' . $slug . '{display:none!important}';
					continue;
				}
				// قاعدهٔ اول li را می‌کَنَد؛ دومی فقط لینک را (مرورگری که :has ندارد).
				// جدا نوشته می‌شوند، چون یک سلکتور نامعتبر کل rule را باطل می‌کند.
				$out[] = '#adminmenu .wp-submenu li:has(> a[href*="' . $slug . '"]){display:none!important}';
				$out[] = '#adminmenu .wp-submenu li> a[href*="' . $slug . '"]{display:none!important}';
			}
			return implode( '', $out );
		}

		/**
		 * میان‌بُر در نوار بالا.
		 *
		 * @param WP_Admin_Bar $bar نوار.
		 * @return void
		 */
		public static function admin_bar( $bar ) {
			if ( ! is_admin_bar_showing() || ! current_user_can( self::$cap ) ) {
				return;
			}
			$bar->add_node(
				array(
					'id'    => 'tisacase-hub',
					'title' => __( 'تیساکیس', 'tisacase-hub' ),
					'href'  => admin_url( 'admin.php?page=' . TSH_SLUG ),
					'meta'  => array( 'title' => __( 'اختصاصی تیساکیس — همهٔ ابزارها', 'tisacase-hub' ) ),
				)
			);
			foreach ( array_slice( array_keys( TSH_Registry::items() ), 0, 6 ) as $key ) {
				$items = TSH_Registry::items();
				if ( empty( $items[ $key ]['pages'][0]['path'] ) ) {
					continue;
				}
				$bar->add_node(
					array(
						'id'     => 'tisacase-hub-' . $key,
						'parent' => 'tisacase-hub',
						'title'  => $items[ $key ]['title'],
						'href'   => admin_url( $items[ $key ]['pages'][0]['path'] ),
					)
				);
			}
		}

		/**
		 * تنظیمات.
		 *
		 * @return void
		 */
		public static function register_settings() {
			register_setting(
				'tisacase_hub_group',
				TSH_OPTION,
				array(
					'type'              => 'array',
					'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
					'default'           => TSH_UI::defaults(),
				)
			);
		}

		/**
		 * پاک‌سازی ورودی تنظیمات.
		 *
		 * @param mixed $input ورودی خام.
		 * @return array
		 */
		public static function sanitize_settings( $input ) {
			$in       = is_array( $input ) ? $input : array();
			$defaults = TSH_UI::defaults();
			$out      = array();

			$accent = isset( $in['accent'] ) ? trim( (string) $in['accent'] ) : $defaults['accent'];
			if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $accent ) ) {
				$accent = $defaults['accent'];
			}
			$out['accent'] = TSH_UI::normalize_hex( $accent );

			foreach ( array( 'compact', 'hide_scattered', 'style_plugins', 'style_product_screens' ) as $bool ) {
				$out[ $bool ] = empty( $in[ $bool ] ) ? 0 : 1;
			}

			// این کلید در فرم نیست (از روی کارت‌ها عوض می‌شود) → دست نخورد.
			$pos_keys = array_keys( self::positions() );
			$pos      = isset( $in['menu_position'] ) ? sanitize_key( $in['menu_position'] ) : 'top';
			$out['menu_position'] = in_array( $pos, $pos_keys, true ) ? $pos : 'top';
			$custom               = isset( $in['menu_position_custom'] ) ? trim( (string) $in['menu_position_custom'] ) : '';
			$out['menu_position_custom'] = preg_match( '/^\d{1,2}(\.\d{1,2})?$/', $custom ) ? $custom : '';

			// پایهٔ آدرس زیپ‌های مجموعه (کارت‌های «نصب از مخزن»).
			$zip_base = isset( $in['zip_base'] ) ? trim( (string) $in['zip_base'] ) : '';
			if ( '' !== $zip_base ) {
				$zip_base = esc_url_raw( $zip_base, array( 'http', 'https' ) );
				if ( $zip_base && '/' !== substr( $zip_base, -1 ) ) {
					$zip_base .= '/';
				}
			}
			$current = TSH_UI::settings();
			if ( array_key_exists( 'zip_base', $in ) ) {
				$out['zip_base'] = $zip_base;
			} else {
				$out['zip_base'] = isset( $current['zip_base'] ) ? $current['zip_base'] : '';
			}

			if ( isset( $in['repo'] ) ) {
				$repo = trim( (string) $in['repo'] );
				$out['repo'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : ( isset( $current['repo'] ) ? $current['repo'] : 'ImTheAlireza/TisaCaseHub' );
			} else {
				$out['repo'] = isset( $current['repo'] ) ? $current['repo'] : 'ImTheAlireza/TisaCaseHub';
			}
			if ( isset( $in['branch'] ) ) {
				$branch = preg_replace( '#[^A-Za-z0-9._/-]#', '', trim( (string) $in['branch'] ) );
				$out['branch'] = $branch ? $branch : 'main';
			} else {
				$out['branch'] = isset( $current['branch'] ) ? $current['branch'] : 'main';
			}
			$hidden  = isset( $in['hidden'] ) ? (array) $in['hidden'] : (array) ( isset( $current['hidden'] ) ? $current['hidden'] : array() );
			$hidden  = array_values( array_unique( array_map( 'sanitize_key', array_filter( $hidden ) ) ) );
			$known   = array_keys( TSH_Registry::items() );
			$out['hidden'] = array_values( array_intersect( $hidden, $known ) );

			TSH_UI::flush();
			return apply_filters( 'tisacase_hub_sanitize_settings', $out, $in );
		}

		/* * * * * * * * * * * رندر صفحه‌ها * * * * * * * * * * * */

		/**
		 * صفحهٔ لانچر.
		 *
		 * @return void
		 */
		public static function render_hub() {
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			self::view( 'hub.php', self::hub_data() );
		}

		/**
		 * دادهٔ لازم برای لانچر.
		 *
		 * @param bool $refresh شمارنده‌ها را دوباره حساب کند.
		 * @return array
		 */
		private static function hub_data() {
			$data = TSH_Registry::grouped();
			return array(
				'groups'   => $data['groups'],
				'all'      => $data['all'],
				'pins'     => self::pins(),
				'settings' => TSH_UI::settings(),
				'hidden'   => (array) TSH_UI::setting( 'hidden', array() ),
			);
		}

		/**
		 * صفحهٔ تنظیمات.
		 *
		 * @return void
		 */
		public static function render_settings() {
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			$all    = TSH_Registry::grouped()['all'];
			$hidden = (array) TSH_UI::setting( 'hidden', array() );
			self::view(
				'settings.php',
				array(
					'settings' => TSH_UI::settings(),
					'font'     => TSH_UI::font_status(),
					'hidden'   => $hidden,
					'items'    => $all,
					'env'      => self::env(),
				)
			);
		}

		/**
		 * بارگذاری قالب.
		 *
		 * @param string $file نام فایل قالب.
		 * @param array  $data متغیرهای قالب.
		 * @return void
		 */
		private static function view( $file, $data ) {
			$path = TSH_DIR . 'templates/' . $file;
			if ( ! file_exists( $path ) ) {
				echo '<div class="wrap"><p>' . esc_html__( 'قالب پیدا نشد.', 'tisacase-hub' ) . '</p></div>';
				return;
			}
			extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $path;
		}

		/* * * * * * * * * * * اقدام‌ها * * * * * * * * * * * */

		/**
		 * ذخیرهٔ تنظیمات.
		 *
		 * عمداً به‌جای options.php: آن مسیر manage_options می‌خواهد و shop manager
		 * را رد می‌کند، در حالی که این تنظیمات فقط ظاهر را عوض می‌کنند.
		 *
		 * @return void
		 */
		public static function handle_save() {
			check_admin_referer( 'tsh_save' );
			if ( ! current_user_can( self::$cap ) ) {
				wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
			}
			$raw = isset( $_POST[ TSH_OPTION ] ) ? wp_unslash( $_POST[ TSH_OPTION ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$in  = is_array( $raw ) ? $raw : array();

			// جعبه‌های تیک‌دار نیستم؛ نبودنشان یعنی خاموش.
			foreach ( array( 'compact', 'hide_scattered', 'style_plugins', 'style_product_screens' ) as $flag ) {
				$in[ $flag ] = isset( $in[ $flag ] ) ? 1 : 0;
			}

			$clean = self::sanitize_settings( $in );
			update_option( TSH_OPTION, $clean );
			TSH_UI::flush();

			wp_safe_redirect( add_query_arg( 'tsh_msg', 'saved', admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' ) ) );
			exit;
		}

		/**
		 * فعال/غیرفعال کردن، مخفی کردن کارت، تازه‌سازی گزارش.
		 *
		 * @return void
		 */
		public static function handle_action() {
			$task = isset( $_GET['task'] ) ? sanitize_key( wp_unslash( $_GET['task'] ) ) : '';
			$key  = isset( $_GET['item'] ) ? sanitize_key( wp_unslash( $_GET['item'] ) ) : '';

			check_admin_referer( 'tsh_action' );

			$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			$ref  = wp_get_referer();
			if ( ! $ref ) {
				$ref = wp_get_raw_referer();
			}
			if ( $ref ) {
				$checked = wp_validate_redirect( $ref, '' );
				if ( $checked ) {
					$back = $checked;
				}
			}
			$back = remove_query_arg( array( 'tsh_msg', 'tsh_err', 'tsh_item', 'refresh', '_wpnonce', 'action', 'task', 'item' ), $back );
			if ( false === strpos( $back, 'page=' ) ) {
				$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			}


			$items = TSH_Registry::items();
			if ( ! isset( $items[ $key ] ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}
			$item = $items[ $key ];

			if ( 'hide' === $task || 'unhide' === $task ) {
				if ( ! current_user_can( self::$cap ) ) {
					wp_die( esc_html__( 'دسترسی ندارید.', 'tisacase-hub' ) );
				}
				$hidden = (array) TSH_UI::setting( 'hidden', array() );
				$hidden = array_values( array_unique( array_map( 'sanitize_key', $hidden ) ) );
				if ( 'hide' === $task ) {
					$hidden[] = $key;
				} else {
					$hidden = array_values( array_diff( $hidden, array( $key ) ) );
				}
				$saved          = TSH_UI::settings();
				$saved['hidden'] = $hidden;
				update_option( TSH_OPTION, $saved );
				TSH_UI::flush();
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'hidden', $back ) );
				exit;
			}

			if ( 'activate' === $task || 'deactivate' === $task ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					wp_die( esc_html__( 'برای فعال/غیرفعال کردن افزونه اجازه ندارید.', 'tisacase-hub' ) );
				}
				if ( empty( $item['dir'] ) ) {
					wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
					exit;
				}
				$basename = TSH_Registry::basename_for( $item['dir'] );
				if ( ! $basename || ! file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
					wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'notfound', 'tsh_err' => rawurlencode( (string) $item['dir'] ) ), $back ) );
					exit;
				}
				if ( 'deactivate' === $task ) {
					deactivate_plugins( array( $basename ), false, is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $basename ) );
					$msg = 'deactivated';
				} else {
					$result = activate_plugin( $basename );
					if ( is_wp_error( $result ) ) {
						wp_safe_redirect(
							add_query_arg(
								array(
									'tsh_msg' => 'failed',
									'tsh_err' => rawurlencode( $result->get_error_message() ),
								),
								$back
							)
						);
						exit;
					}
					$msg = 'activated';
				}
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => $msg, 'tsh_item' => $key ), $back ) );
				exit;
			}

			wp_safe_redirect( $back );
			exit;
		}

		/**
		 * فقط روی صفحه‌های هاب: همهٔ admin_notices/all_admin_notices دیگران حذف،
		 * پیام خود هاب دوباره وصل می‌شود. کاری با خودِ افزونه‌ها ندارد.
		 *
		 * @return void
		 */
		public static function mute_foreign_notices() {
			$screen = TSH_UI::screen_id();
			$scope  = TSH_UI::scope( $screen );
			if ( '' === $scope ) {
				return;
			}
			if ( 'plugin' === $scope && ! TSH_UI::setting( 'style_plugins' ) ) {
				return;
			}

			// فقط کال‌بک‌هایی می‌مانند که فایل‌شان داخل پوشهٔ همین افزونه (یا هاب) است.
			$allow = array( wp_normalize_path( TSH_DIR ) );
			if ( 'plugin' === $scope ) {
				foreach ( TSH_Registry::items() as $item ) {
					if ( empty( $item['dir'] ) ) {
						continue;
					}
					foreach ( (array) $item['screens'] as $sc ) {
						if ( $sc === $screen ) {
							$allow[] = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $item['dir'] . '/' );
						}
					}
				}
			}

			global $wp_filter;
			foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
				if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof WP_Hook ) ) {
					continue;
				}
				foreach ( $wp_filter[ $hook ]->callbacks as $priority => $cbs ) {
					foreach ( $cbs as $id => $cb ) {
						$file = self::callback_file( $cb['function'] );
						$keep = false;
						foreach ( $allow as $dir ) {
							if ( $file && 0 === strpos( $file, $dir ) ) {
								$keep = true;
								break;
							}
						}
						if ( ! $keep ) {
							remove_filter( $hook, $cb['function'], $priority );
						}
					}
				}
			}
		}

		/**
		 * مسیر فایلِ یک کال‌بک (برای تشخیص «مال کدام افزونه است»).
		 *
		 * @param callable $fn کال‌بک.
		 * @return string
		 */
		private static function callback_file( $fn ) {
			try {
				if ( $fn instanceof Closure || ( is_string( $fn ) && function_exists( $fn ) ) ) {
					$r = new ReflectionFunction( $fn );
				} elseif ( is_array( $fn ) && 2 === count( $fn ) ) {
					$r = new ReflectionMethod( $fn[0], $fn[1] );
				} elseif ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
					list( $c, $m ) = explode( '::', $fn, 2 );
					$r = new ReflectionMethod( $c, $m );
				} else {
					return '';
				}
				return wp_normalize_path( (string) $r->getFileName() );
			} catch ( Throwable $e ) { // phpcs:ignore
				return '';
			}
		}

		/**
		 * نصب افزونه‌ای که روی سرور نیست، مستقیم از مخزن (زیپ روی ZIP_BASE).
		 *
		 * امنیت: nonce مخصوص همان کارت + دسترسی `install_plugins`. زیپ قبل از نصب
		 * باز می‌شود و باید پوشهٔ اولش دقیقاً همان پوشهٔ مورد انتظار باشد؛ بعد
		* Plugin_Upgrader نصب می‌کند و در پایان — اگر دسترسی بود — فعال هم می‌شود.
		*
		* @return void
		*/
		public static function handle_install() {
			$key = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
			check_admin_referer( 'tsh_install_' . $key, '_tshnonce' );

			$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'upload_plugins' ) ) {
				wp_die( esc_html__( 'برای نصب افزونه اجازه ندارید.', 'tisacase-hub' ) );
			}

			$items = TSH_Registry::items();
			if ( ! isset( $items[ $key ] ) || empty( $items[ $key ]['dir'] ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}
			$dir  = (string) $items[ $key ]['dir'];
			$urls = class_exists( 'TSH_Remote' ) ? TSH_Remote::mirrors( $items[ $key ] ) : array( TSH_Registry::zip_url( $items[ $key ] ) );
			if ( empty( $urls ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';

			$tmp = TSH_Remote::download_zip( $urls );
			if ( is_wp_error( $tmp ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'tsh_msg' => 'inst_failed',
							'tsh_err' => rawurlencode( $tmp->get_error_message() ),
						),
						$back
					)
				);
				exit;
			}

			// زیپ باید پوشهٔ همین افزونه را داشته باشد؛ نه چیز دیگری.
			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				$top = '';
				if ( true === $zip->open( $tmp ) ) {
					$top = strtok( (string) $zip->getNameIndex( 0 ), '/' );
					$zip->close();
				}
				if ( $top !== $dir ) {
					wp_delete_file( $tmp );
					wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_wrong', 'tsh_err' => rawurlencode( $top . ' ≠ ' . $dir ) ), $back ) );
					exit;
				}
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( $tmp, array( 'overwrite_package' => false ) );
			wp_delete_file( $tmp );

			if ( is_wp_error( $result ) || ! $result ) {
				$err = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $skin->get_upgrade_messages() );
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'inst_failed', 'tsh_err' => rawurlencode( $err ) ), $back ) );
				exit;
			}

			TSH_UI::flush();
			wp_clean_plugins_cache( true );
			self::sweep_temp_write_tests();

			// نصب که تمام شد، اگر اجازهٔ فعال‌سازی هست، همان‌جا فعالش می‌کنیم.
			$base     = $upgrader->plugin_info();
			$activated = false;
			if ( $base && current_user_can( 'activate_plugins' ) && ! is_plugin_active( $base ) ) {
				$activated = ! is_wp_error( activate_plugin( $base ) );
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'tsh_msg'  => $activated ? 'installed' : 'installed_off',
						'tsh_item' => $key,
					),
					$back
				)
			);
			exit;
		}

		/**
		 * به‌روزرسانی یک افزونه از روی کارت با فایل زیپ (Plugin_Upgrader با overwrite).
		 *
		 * @return void
		 */
		public static function handle_update() {
			$key = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
			check_admin_referer( 'tsh_update_' . $key, '_tshnonce' );

			$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'upload_plugins' ) ) {
				wp_die( esc_html__( 'برای به‌روزرسانی افزونه اجازه ندارید.', 'tisacase-hub' ) );
			}

			$items = TSH_Registry::items();
			if ( ! isset( $items[ $key ] ) || empty( $items[ $key ]['dir'] ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}
			$dir = (string) $items[ $key ]['dir'];

			if ( empty( $_FILES['tsh_zip']['tmp_name'] ) || ! empty( $_FILES['tsh_zip']['error'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_failed', 'tsh_err' => rawurlencode( __( 'فایلی نرسید.', 'tisacase-hub' ) ) ), $back ) );
				exit;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';

			$was_active = false;
			$old_base   = TSH_Registry::basename_for( $dir );
			if ( $old_base ) {
				$was_active = is_plugin_active( $old_base );
			}

			$overrides = array(
				'test_form' => false,
				'test_type' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			);
			$upload    = wp_handle_upload( $_FILES['tsh_zip'], $overrides ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! empty( $upload['error'] ) ) {
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_failed', 'tsh_err' => rawurlencode( (string) $upload['error'] ) ), $back ) );
				exit;
			}

			// زیپ باید همان پوشهٔ این کارت را داشته باشد؛ نه افزونه‌ای دیگر.
			$zip = new ZipArchive();
			$top = '';
			if ( true === $zip->open( $upload['file'] ) ) {
				$first = (string) $zip->getNameIndex( 0 );
				$top   = strtok( $first, '/' );
				$zip->close();
			}
			if ( $top !== $dir ) {
				wp_delete_file( $upload['file'] );
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_wrong', 'tsh_err' => rawurlencode( $top . ' ≠ ' . $dir ) ), $back ) );
				exit;
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install(
				$upload['file'],
				array(
					'overwrite_package' => true,
					'clear_update_cache' => true,
				)
			);
			wp_delete_file( $upload['file'] );

			if ( is_wp_error( $result ) || ! $result ) {
				$err = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $skin->get_upgrade_messages() );
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_failed', 'tsh_err' => rawurlencode( $err ) ), $back ) );
				exit;
			}

			TSH_UI::flush();
			wp_clean_plugins_cache( true );
			self::sweep_temp_write_tests();

			if ( $was_active ) {
				$new_base = $upgrader->plugin_info();
				if ( $new_base && ! is_plugin_active( $new_base ) ) {
					activate_plugin( $new_base, '', is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $old_base ) );
				}
			}

			wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'updated', 'tsh_item' => $key ), $back ) );
			exit;
		}

		/**
		 * به‌روزرسانی از مخزن (همان زیپ نصب از مخزن، با بازنویسی پوشهٔ فعلی).
		 *
		 * @return void
		 */
		public static function handle_update_repo() {
			$key = isset( $_POST['item'] ) ? sanitize_key( wp_unslash( $_POST['item'] ) ) : '';
			check_admin_referer( 'tsh_update_repo_' . $key, '_tshnonce' );

			$back = admin_url( 'admin.php?page=' . TSH_SLUG );
			if ( ! current_user_can( 'update_plugins' ) || ! current_user_can( 'upload_plugins' ) ) {
				wp_die( esc_html__( 'برای به‌روزرسانی افزونه اجازه ندارید.', 'tisacase-hub' ) );
			}

			$items = TSH_Registry::items();
			if ( ! isset( $items[ $key ] ) || empty( $items[ $key ]['dir'] ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}
			$dir  = (string) $items[ $key ]['dir'];
			$urls = class_exists( 'TSH_Remote' ) ? TSH_Remote::mirrors( $items[ $key ] ) : array( TSH_Registry::zip_url( $items[ $key ] ) );
			if ( empty( $urls ) ) {
				wp_safe_redirect( add_query_arg( 'tsh_msg', 'bad', $back ) );
				exit;
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';

			$was_active = false;
			$old_base   = TSH_Registry::basename_for( $dir );
			if ( $old_base ) {
				$was_active = is_plugin_active( $old_base );
			}

			$tmp = TSH_Remote::download_zip( $urls );
			if ( is_wp_error( $tmp ) ) {
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_failed', 'tsh_err' => rawurlencode( $tmp->get_error_message() ) ), $back ) );
				exit;
			}

			if ( class_exists( 'ZipArchive' ) ) {
				$zip = new ZipArchive();
				$top = '';
				if ( true === $zip->open( $tmp ) ) {
					$top = strtok( (string) $zip->getNameIndex( 0 ), '/' );
					$zip->close();
				}
				if ( $top !== $dir ) {
					wp_delete_file( $tmp );
					wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_wrong', 'tsh_err' => rawurlencode( $top . ' ≠ ' . $dir ) ), $back ) );
					exit;
				}
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install(
				$tmp,
				array(
					'overwrite_package'  => true,
					'clear_update_cache' => true,
				)
			);
			wp_delete_file( $tmp );

			if ( is_wp_error( $result ) || ! $result ) {
				$err = is_wp_error( $result ) ? $result->get_error_message() : implode( ' ', (array) $skin->get_upgrade_messages() );
				wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'upd_failed', 'tsh_err' => rawurlencode( $err ) ), $back ) );
				exit;
			}

			TSH_UI::flush();
			wp_clean_plugins_cache( true );
			self::sweep_temp_write_tests();

			if ( $was_active ) {
				$new_base = $upgrader->plugin_info();
				if ( $new_base && ! is_plugin_active( $new_base ) ) {
					activate_plugin( $new_base, '', is_multisite() && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $old_base ) );
				}
			}

			wp_safe_redirect( add_query_arg( array( 'tsh_msg' => 'updated_repo', 'tsh_item' => $key ), $back ) );
			exit;
		}

		/**
		 * پاک‌کردن فایل‌های صفر بایتی «temp-write-test-*» که هستهٔ وردپرس هنگام
		 * تشخیص روش فایل‌سیستم می‌سازد و گاهی جا می‌گذارد. فقط فایل‌های خالی با همین
		 * الگو، فقط در ریشه‌های شناخته‌شده و بدون پیمایش بازگشتی.
		 *
		 * @return int تعداد حذف‌شده.
		 */
		public static function sweep_temp_write_tests() {
			$roots = array( WP_CONTENT_DIR, WP_PLUGIN_DIR, untrailingslashit( ABSPATH ), untrailingslashit( ABSPATH ) . '/wp-admin' );
			$up    = wp_upload_dir( null, false );
			if ( empty( $up['error'] ) && ! empty( $up['basedir'] ) ) {
				$roots[] = $up['basedir'];
			}
			$n = 0;
			foreach ( array_unique( $roots ) as $root ) {
				$files = glob( trailingslashit( $root ) . 'temp-write-test-*' );
				foreach ( (array) $files as $f ) {
					if ( is_file( $f ) && 0 === (int) filesize( $f ) && preg_match( '/temp-write-test-[0-9a-f]+-\d+$/', $f ) ) {
						if ( wp_delete_file( $f ) || ! file_exists( $f ) ) {
							$n++;
						}
					}
				}
			}
			return $n;
		}

		/**
		 * owner/name از POST — بدون وابستگی به فیلد URL که WAF ممکن است خالی کند.
		 *
		 * @return array|\\WP_Error
		 */
		private static function posted_github() {
			$owner  = isset( $_POST['gh_owner'] ) ? wp_unslash( $_POST['gh_owner'] ) : '';
			$name   = isset( $_POST['gh_name'] ) ? wp_unslash( $_POST['gh_name'] ) : '';
			$branch = isset( $_POST['gh_branch'] ) ? wp_unslash( $_POST['gh_branch'] ) : '';
			$owner  = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $owner );
			$name   = preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name );
			$branch = preg_replace( '#[^A-Za-z0-9._/-]#', '', (string) $branch );
			if ( $owner && $name ) {
				return array(
					'repo'   => $owner . '/' . $name,
					'branch' => $branch ? $branch : 'main',
				);
			}
			$link = '';
			if ( isset( $_POST['repo_url'] ) ) {
				$link = wp_unslash( $_POST['repo_url'] );
			} elseif ( isset( $_POST['url'] ) ) {
				$link = wp_unslash( $_POST['url'] );
			}
			return TSH_Remote::parse_github_url( $link );
		}

		/* * * * * * * * * * * AJAX * * * * * * * * * * * */

		/**
		 * سنجاق (در متای کاربر).
		 *
		 * @return void
		 */
		public static function ajax_repo_test() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'msg' => 'cap' ), 403 );
			}
			$link = '';
			if ( isset( $_POST['repo_url'] ) ) {
				$link = wp_unslash( $_POST['repo_url'] );
			} elseif ( isset( $_POST['url'] ) ) {
				$link = wp_unslash( $_POST['url'] );
			}
			$parsed = TSH_Remote::parse_github_url( $link );
			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error( array( 'msg' => $parsed->get_error_message() ) );
			}
			$test = TSH_Remote::test_connection( $parsed['repo'], $parsed['branch'] );
			if ( is_wp_error( $test ) ) {
				$pack = TSH_Remote::bundled_catalog();
				$n    = ( ! is_wp_error( $pack ) && ! empty( $pack['items'] ) ) ? count( $pack['items'] ) : 0;
				wp_send_json_success(
					array(
						'repo'   => $parsed['repo'],
						'branch' => $parsed['branch'],
						'count'  => $n,
						'remote' => false,
						'msg'    => $test->get_error_message(),
					)
				);
			}
			wp_send_json_success(
				array(
					'repo'   => $parsed['repo'],
					'branch' => $parsed['branch'],
					'count'  => $test['count'],
					'remote' => ! empty( $test['remote'] ),
				)
			);
		}

		public static function ajax_repo_connect() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'msg' => 'cap' ), 403 );
			}
			$parsed = self::posted_github();
			if ( is_wp_error( $parsed ) ) {
				wp_send_json_error( array( 'msg' => $parsed->get_error_message() ) );
			}
			TSH_Remote::save_connection( $parsed['repo'], $parsed['branch'] );
			$test = TSH_Remote::test_connection( $parsed['repo'], $parsed['branch'] );
			$count  = 0;
			$remote = false;
			if ( ! is_wp_error( $test ) ) {
				$count  = (int) $test['count'];
				$remote = ! empty( $test['remote'] );
			} else {
				$pack = TSH_Remote::bundled_catalog();
				if ( ! is_wp_error( $pack ) ) {
					$pack['repo']   = $parsed['repo'];
					$pack['branch'] = $parsed['branch'];
					update_option( 'tisacase_hub_catalog', $pack, false );
					$count = count( $pack['items'] );
				}
			}
			wp_send_json_success(
				array(
					'repo'   => $parsed['repo'],
					'branch' => $parsed['branch'],
					'count'  => $count,
					'remote' => $remote,
				)
			);
		}

		public static function ajax_repo_sync() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_send_json_error( array( 'msg' => 'cap' ), 403 );
			}
			$pack = TSH_Remote::sync_catalog();
			if ( is_wp_error( $pack ) ) {
				wp_send_json_error( array( 'msg' => $pack->get_error_message() ) );
			}
			$items = TSH_Registry::items( true );
			$src   = isset( $pack['source'] ) ? (string) $pack['source'] : '';
			// «آنلاین» یعنی فهرست واقعاً از مخزن خوانده شده؛ نه فالبکِ فهرست همراه هاب.
			$online = ( 'bundle' !== $src ) && ! empty( isset( $pack['at'] ) ? $pack['at'] : 0 );
			wp_send_json_success(
				array(
					'count'   => isset( $pack['items'] ) ? count( $pack['items'] ) : 0,
					'tools'   => is_array( $items ) ? count( $items ) : 0,
					'branch'  => isset( $pack['branch'] ) ? (string) $pack['branch'] : '',
					'source'  => $src,
					'offline' => ! $online,
				)
			);
		}

		public static function ajax_pin() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! is_user_logged_in() ) {
				wp_send_json_error( array( 'msg' => 'auth' ), 401 );
			}
			$key  = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
			$on   = ! empty( $_POST['on'] );
			if ( ! $key ) {
				wp_send_json_error( array( 'msg' => 'bad key' ), 400 );
			}
			$pins = self::pins();
			if ( $on ) {
				$pins[] = $key;
			} else {
				$pins = array_values( array_diff( $pins, array( $key ) ) );
			}
			$pins = array_values( array_unique( array_map( 'sanitize_key', $pins ) ) );
			update_user_meta( get_current_user_id(), TSH_META_PINS, $pins );
			wp_send_json_success( array( 'pins' => $pins ) );
		}

		/**
		 * ذخیرهٔ سریع یک تنظیم (رنگ برند / چگالی / شمارنده‌ها).
		 *
		 * @return void
		 */
		public static function ajax_prefs() {
			check_ajax_referer( 'tsh_hub', 'nonce' );
			if ( ! current_user_can( self::$cap ) ) {
				wp_send_json_error( array( 'msg' => 'cap' ), 403 );
			}
			$allow = array( 'accent', 'compact' );
			$saved = TSH_UI::settings();
			$done  = array();
			foreach ( $allow as $k ) {
				if ( ! isset( $_POST[ $k ] ) ) {
					continue;
				}
				$v = wp_unslash( $_POST[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( 'accent' === $k ) {
					if ( ! TSH_UI::is_hex( $v ) ) {
						continue;
					}
					$saved[ $k ] = TSH_UI::normalize_hex( $v );
				} elseif ( 'compact' === $k ) {
					$saved[ $k ] = ( $v === '0' || $v === 'false' || '' === $v ) ? 0 : 1;
				} else {
					$saved[ $k ] = empty( $v ) || '0' === $v ? 0 : 1;
				}
				$done[] = $k;
			}
			if ( $done ) {
				update_option( TSH_OPTION, $saved );
				TSH_UI::flush();
			}
			wp_send_json_success( array( 'saved' => $done, 'vars' => TSH_UI::vars() ) );
		}

		/* * * * * * * * * * * ابزارها * * * * * * * * * * * */

		/**
		 * سنجاق‌های کاربر جاری.
		 *
		 * @return array<int,string>
		 */
		public static function pins() {
			$pins = get_user_meta( get_current_user_id(), TSH_META_PINS, true );
			return is_array( $pins ) ? array_values( array_map( 'sanitize_key', $pins ) ) : array();
		}

		/**
		 * اطلاعات محیط برای نوار پایین.
		 *
		 * @return array<string,string>
		 */
		public static function env() {
			global $wp_version;
			$wc  = defined( 'WC_VERSION' ) ? WC_VERSION : '';
			$hpos = '—';
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && function_exists( 'wc_get_option' ) ) {
				$cpt = get_option( 'woocommerce_custom_orders_table_enabled' );
				$hpos = 'yes' === $cpt ? __( 'روشن', 'tisacase-hub' ) : ( 'no' === $cpt ? __( 'خاموش', 'tisacase-hub' ) : '—' );
			} elseif ( function_exists( 'wc_get_option' ) ) {
				$cpt  = get_option( 'woocommerce_custom_orders_table_enabled' );
				$hpos = 'yes' === $cpt ? __( 'روشن', 'tisacase-hub' ) : __( 'خاموش', 'tisacase-hub' );
			}
			$font = TSH_UI::font_status();
			return array(
				'wp'      => (string) $wp_version,
				'php'     => PHP_VERSION,
				'wc'      => $wc ? (string) $wc : __( 'فعال نیست', 'tisacase-hub' ),
				'hpos'    => $hpos,
				'locale'  => (string) get_locale(),
				'rtl'     => is_rtl() ? 'RTL' : 'LTR',
				'memory'  => function_exists( 'size_format' ) ? size_format( (int) memory_get_usage( true ) ) : '',
				'fonts'   => $font['count'] ? sprintf( /* translators: %d: count */ __( '%d فایل قلم محلی', 'tisacase-hub' ), $font['count'] ) : __( 'قلم سیستم', 'tisacase-hub' ),
				'theme'   => (string) ( wp_get_theme() ? wp_get_theme()->get( 'Name' ) : '' ),
				'items'   => (string) count( TSH_Registry::items() ),
			);
		}

		/**
		 * پیام بعد از اقدام.
		 *
		 * @return void
		 */
		public static function admin_notice() {
			if ( empty( $_GET['tsh_msg'] ) || ! TSH_UI::is_hub_screen() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return;
			}
			$msg = sanitize_key( wp_unslash( $_GET['tsh_msg'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$err = isset( $_GET['tsh_err'] ) ? sanitize_text_field( wp_unslash( $_GET['tsh_err'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$texts = array(
				'activated'   => array( 'success', __( 'افزونه فعال شد.', 'tisacase-hub' ) ),
				'deactivated' => array( 'success', __( 'افزونه غیرفعال شد.', 'tisacase-hub' ) ),
				'hidden'      => array( 'info', sprintf( /* translators: %s: settings url */ __( 'ابزار مخفی شد. %sبازگردانی%s', 'tisacase-hub' ), '<a href="' . esc_url( admin_url( 'admin.php?page=' . TSH_SLUG . '-settings' ) ) . '">', '</a>' ) ),
				'saved'       => array( 'success', __( 'تنظیمات ذخیره شد و روی همهٔ صفحه‌ها اعمال می‌شود.', 'tisacase-hub' ) ),
				'notfound'    => array( 'error', sprintf( /* translators: %s: dir */ __( 'پوشهٔ افزونه (%s) روی این سرور نیست.', 'tisacase-hub' ), $err ) ),
				'failed'      => array( 'error', sprintf( /* translators: %s: error */ __( 'فعال‌سازی نشد: %s', 'tisacase-hub' ), $err ) ),
				'bad'         => array( 'error', __( 'اقدام نامعتبر بود.', 'tisacase-hub' ) ),
				'updated'     => array( 'success', __( 'افزونه به‌روزرسانی شد.', 'tisacase-hub' ) ),
				'updated_repo'=> array( 'success', __( 'افزونه از مخزن به‌روزرسانی شد.', 'tisacase-hub' ) ),
				'upd_failed'  => array( 'error', sprintf( /* translators: %s: error */ __( 'به‌روزرسانی نشد: %s', 'tisacase-hub' ), $err ) ),
				'upd_wrong'   => array( 'error', sprintf( /* translators: %s: dirs */ __( 'این زیپ مال این کارت نیست (%s).', 'tisacase-hub' ), $err ) ),
				'installed'   => array( 'success', __( 'افزونه از مخزن نصب و فعال شد.', 'tisacase-hub' ) ),
				'installed_off' => array( 'success', __( 'افزونه از مخزن نصب شد. برای فعال‌سازی، دکمهٔ «فعال‌سازی» روی همان کارت را بزنید.', 'tisacase-hub' ) ),
				'inst_failed' => array( 'error', sprintf( /* translators: %s: error */ __( 'نصب از مخزن انجام نشد: %s', 'tisacase-hub' ), $err ) ),
				'sync_ok'     => array( 'success', sprintf( /* translators: %s: count */ __( 'فهرست مخزن همگام شد (%s افزونه). کارت‌های جدید در صفحهٔ ابزارها ظاهر می‌شوند.', 'tisacase-hub' ), $err ) ),
				'sync_fail'   => array( 'error', sprintf( /* translators: %s: error */ __( 'همگام‌سازی مخزن نشد: %s', 'tisacase-hub' ), $err ) ),
			);
			if ( ! isset( $texts[ $msg ] ) ) {
				return;
			}
			list( $type, $text ) = $texts[ $msg ];
			$cls = 'info' === $type ? 'info' : $type;
			printf(
				'<div class="notice tsh-notice tsh-notice--%1$s" role="status"><span class="tsh-notice__ic" aria-hidden="true">%3$s</span><p>%2$s</p><button type="button" class="tsh-notice__x" aria-label="%4$s">&times;</button></div>',
				esc_attr( $cls ),
				wp_kses(
					$text,
					array(
						'a'      => array( 'href' => array() ),
						'b'      => array(),
						'code'   => array(),
						'span'   => array( 'class' => array() ),
					)
				),
				'success' === $cls
					? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
					: ( 'error' === $cls
						? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 7v6M12 17h.01"/></svg>'
						: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 11v6M12 7h.01"/></svg>' ),
				esc_attr__( 'بستن', 'tisacase-hub' )
			);
		}

		/**
		 * پیش‌فرض‌ها هنگام فعال‌سازی.
		 *
		 * @return void
		 */
		public static function activate() {
			if ( false === get_option( TSH_OPTION, false ) ) {
				add_option( TSH_OPTION, TSH_UI::defaults() );
			}
			delete_transient( 'tsh_health' );
		}

		/**
		 * پاک‌کاری هنگام غیرفعال کردن (داده‌ها می‌مانند؛ فقط کش).
		 *
		 * @return void
		 */
		public static function deactivate() {
			delete_transient( 'tsh_health' );
		}
	}

	// پیام‌ها بعد از redirect — روی همهٔ صفحه‌های هاب.
	add_action( 'admin_notices', array( 'TSH_Admin', 'admin_notice' ) );
	// بعد از هر به‌روزرسانی (هاب یا صفحهٔ افزونه‌های وردپرس) فایل‌های تست نوشتن پاک شوند.
	add_action( 'upgrader_process_complete', array( 'TSH_Admin', 'sweep_temp_write_tests' ), 99, 0 );
}
