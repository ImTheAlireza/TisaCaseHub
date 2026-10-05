<?php
/**
 * کاتالوگ افزونه‌های اختصاصی: چه چیزی، کجا باز می‌شود، روی چه اسکرین‌هایی استایل تزریق شود.
 *
 * هر آیتم می‌تواند از سه مسیر وارد شود (به ترتیب اولویت):
 *  ۱) فیلتر `tisacase_hub_items` (افزونه خودش را ثبت کند — دقیق‌ترین حالت)
 *  ۲) هدر `TisaCase Hub:` در فایل اصلی افزونه (بدون کد، فقط یک خط)
 *  ۳) فهرست پیش‌فرض همین کلاس (افزونه‌های فعلی تیساکیس)
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TSH_Registry' ) ) {

	/**
	 * ثبت و resolve آیتم‌های هاب.
	 */
	final class TSH_Registry {

		const FILTER = 'tisacase_hub_items';

		/**
		 * پایهٔ آدرس زیپ‌های مجموعه (در تنظیمات هاب قابل تغییر است).
		 * روی شاخهٔ main همان مخزنی است که این افزونه‌ها در آن زندگی می‌کنند.
		 */
		const ZIP_BASE = 'https://raw.githubusercontent.com/ImTheAlireza/TisaCaseHub/main/plugins/dist/';

		/** @var array|null آیتم‌های resolve‌شده در همین درخواست. */
		private static $items = null;

		/** @var array|null خروجی get_plugins() برای همین درخواست. */
		private static $plugins = null;

		/**
		 * گروه‌های نمایشی.
		 *
		 * @return array<string,string>
		 */
		public static function groups() {
			return array(
				'products' => __( 'محصول و محتوا', 'tisacase-hub' ),
				'pricing'  => __( 'قیمت‌گذاری', 'tisacase-hub' ),
				'orders'   => __( 'سفارش و ارسال', 'tisacase-hub' ),
			);
		}

		/**
		 * فهرست پیش‌فرضِ افزونه‌های تیساکیس.
		 *
		 * @return array<string,array>
		 */
		private static function defaults() {
			$items = array();

			$items['bdc'] = array(
				'title' => __( 'حذف انبوه پیش‌نویس', 'tisacase-hub' ),
				'desc'  => __( 'پاک‌سازی محصولات پیش‌نویس و تصویرهای یتیم، با پشتیبان و بازیابی.', 'tisacase-hub' ),
				'group' => 'products',
				'icon'  => 'trash',
				'dir'   => 'bulk-product-cleaner',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'پاک‌ساز', 'tisacase-hub' ),
						'path'   => 'admin.php?page=bdc-cleaner',
						'screen' => 'woocommerce_page_bdc-cleaner',
						'parent' => 'woocommerce',
						'slug'   => 'bdc-cleaner',
					),
					array(
						'label'  => __( 'پشتیبان و بازیابی', 'tisacase-hub' ),
						'path'   => 'admin.php?page=bdc-cleaner&tab=backups',
						'screen' => 'woocommerce_page_bdc-cleaner',
					),
				),
			);

			$items['desc'] = array(
				'title' => __( 'قوانین توضیحات محصول', 'tisacase-hub' ),
				'desc'  => __( 'درج خودکار توضیحات چاپی و هشدار قاب، با اسکن و بازگردانی.', 'tisacase-hub' ),
				'group' => 'products',
				'icon'  => 'doc',
				'dir'   => 'tisacase-product-description',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'تنظیمات', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-desc',
						'screen' => 'toplevel_page_tisacase-desc',
						'parent' => 'top',
						'slug'   => 'tisacase-desc',
					),
					array(
						'label'  => __( 'ابزارها', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-desc&tab=tools',
						'screen' => 'toplevel_page_tisacase-desc',
					),
					array(
						'label'  => __( 'بازگردانی', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-desc&tab=backups',
						'screen' => 'toplevel_page_tisacase-desc',
					),
				),
			);

			$items['importer'] = array(
				'title' => __( 'افزودن/شارژ محصول از تلگرام', 'tisacase-hub' ),
				'desc'  => __( 'تبدیل product.zip ربات به محصول متغیر پیش‌نویس؛ SKU و واریاسیون خودکار.', 'tisacase-hub' ),
				'group' => 'products',
				'icon'  => 'box',
				'dir'   => 'tisa-product-importer',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'ایمپورت', 'tisacase-hub' ),
						'path'   => 'edit.php?post_type=product&page=tisa-product-importer',
						'screen' => 'product_page_tisa-product-importer',
						'parent' => 'edit.php?post_type=product',
						'slug'   => 'tisa-product-importer',
					),
				),
			);

			$items['skubar'] = array(
				'title' => __( 'نوار پیشوند SKU', 'tisacase-hub' ),
				'desc'  => __( 'سری‌های SKU، SKU بعدی و چک تکراری روی لیست و ویرایش محصول. صفحهٔ مستقل ندارد.', 'tisacase-hub' ),
				'group' => 'products',
				'icon'  => 'hash',
				'dir'   => 'wc-sku-prefix-bar',
				'cap'   => 'edit_products',
				'tip'   => __( 'این افزونه صفحهٔ مستقل ندارد؛ نوار SKU روی لیست محصولات می‌نشیند.', 'tisacase-hub' ),
				'pages' => array(
					array(
						'label'  => __( 'لیست محصولات', 'tisacase-hub' ),
						'path'   => 'edit.php?post_type=product',
						'screen' => 'edit-product',
					),
				),
				// صفحهٔ ویرایش محصول عمداً در فهرست نیست: آن صفحه مال خودِ ووکامرس است و
				// دست‌زدن به جدول واریاسیون‌ها/پنل داده ریسک دارد. با فیلتر باز می‌شود.
				'screens' => (array) apply_filters( 'tisacase_hub_skubar_screens', array( 'edit-product' ) ),
			);

			$items['pricing'] = array(
				'title' => __( 'قیمت‌گذاری', 'tisacase-hub' ),
				'desc'  => __( 'قوانین داینامیک، تغییر گروهی امن قیمت‌ها با پیش‌نمایش و بازگردانی، و مدیریت کدهای تخفیف.', 'tisacase-hub' ),
				'group' => 'pricing',
				'icon'  => 'tag',
				'dir'   => 'tisacase-pricing',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'قوانین داینامیک', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing&tab=rules',
						'screen' => 'woocommerce_page_tisacase-pricing',
						'parent' => 'woocommerce',
						'slug'   => 'tisacase-pricing',
					),
					array(
						'label'  => __( 'تغییر گروهی', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing&tab=bulk',
						'screen' => 'woocommerce_page_tisacase-pricing',
					),
					array(
						'label'  => __( 'کد تخفیف', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing&tab=coupons',
						'screen' => 'woocommerce_page_tisacase-pricing',
					),
					array(
						'label'  => __( 'گزارش و بازگردانی', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing&tab=runs',
						'screen' => 'woocommerce_page_tisacase-pricing',
					),
					array(
						'label'  => __( 'تنظیمات', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing&tab=settings',
						'screen' => 'woocommerce_page_tisacase-pricing',
					),
				),
			);

			// دو افزونهٔ قدیمی (تا وقتی هنوز نصب‌اند) همچنان شناخته می‌شوند.
			// دو افزونهٔ بازنشسته (جایشان tisacase-pricing است) در مخزن زیپ ندارند.
			$items['tcbpm'] = array(
				'zip'   => false,
				'title' => __( 'قیمت گروهی', 'tisacase-hub' ),
				'desc'  => __( 'افزایش/کاهش/set قیمت عادی، فروش ویژه و عمده؛ پیش‌نمایش، لاگ و بازگردانی.', 'tisacase-hub' ),
				'group' => 'pricing',
				'icon'  => 'tag',
				'dir'   => 'tisacase-bulk-price-manager',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'اجرای گروهی', 'tisacase-hub' ),
						'path'   => 'edit.php?post_type=product&page=tisacase-bulk-price-manager',
						'screen' => 'product_page_tisacase-bulk-price-manager',
						'parent' => 'edit.php?post_type=product',
						'slug'   => 'tisacase-bulk-price-manager',
					),
					array(
						'label'  => __( 'اجراهای قبلی', 'tisacase-hub' ),
						'path'   => 'edit.php?post_type=product&page=tisacase-bulk-price-manager&tab=runs',
						'screen' => 'product_page_tisacase-bulk-price-manager',
					),
					array(
						'label'  => __( 'تنظیمات', 'tisacase-hub' ),
						'path'   => 'edit.php?post_type=product&page=tisacase-bulk-price-manager&tab=settings',
						'screen' => 'product_page_tisacase-bulk-price-manager',
					),
				),
			);

			$items['pm'] = array(
				'zip'   => false,
				'title' => __( 'قیمت‌گذاری داینامیک', 'tisacase-hub' ),
				'desc'  => __( 'قوانین درصدی بر اساس نقش کاربر/دسته؛ بدون نوشتن در دیتابیس.', 'tisacase-hub' ),
				'group' => 'pricing',
				'icon'  => 'bolt',
				'dir'   => 'tisacase-pricing-manager',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'قوانین', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-pricing-manager',
						'screen' => 'woocommerce_page_tisacase-pricing-manager',
						'parent' => 'woocommerce',
						'slug'   => 'tisacase-pricing-manager',
					),
				),
			);

			$items['package'] = array(
				'title' => __( 'پکیج ویژه قاب', 'tisacase-hub' ),
				'desc'  => __( 'افزودن گزینهٔ پکیج به سبد بر اساس کلیدواژهٔ عنوان، با استثنای SKU.', 'tisacase-hub' ),
				'group' => 'pricing',
				'icon'  => 'layers',
				'dir'   => 'case-special-package',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'تنظیمات پکیج', 'tisacase-hub' ),
						'path'   => 'admin.php?page=wcsp-settings',
						'screen' => 'woocommerce_page_wcsp-settings',
						'parent' => 'woocommerce',
						'slug'   => 'wcsp-settings',
					),
				),
			);

				$items['brandvars'] = array(
					'title' => __( 'گروه‌بندی متغیرها بر اساس برند', 'tisacase-hub' ),
					'desc'  => __( 'مدل‌های محصول را به برند (آیفون/سامسونگ/شیائومی) دسته‌بندی می‌کند؛ با پنل جستجو، خط جداکننده و سواچ رنگ. فقط نمایش سمت کاربر و در حالت تست فقط روی محصول‌های انتخابی.', 'tisacase-hub' ),
					'group' => 'products',
					'icon'  => 'grid',
					'dir'   => 'tisacase-brand-variations',
					'cap'   => 'manage_woocommerce',
					'pages' => array(
						array(
							'label'  => __( 'باز کردن', 'tisacase-hub' ),
							'path'   => 'admin.php?page=tisacase-brand-variations',
							'screen' => 'woocommerce_page_tisacase-brand-variations',
							'parent' => 'woocommerce',
							'slug'   => 'tisacase-brand-variations',
						),
					),
				);

				$items['bsm'] = array(
					'title' => __( 'مدیریت انبوه موجودی', 'tisacase-hub' ),
					'desc'  => __( 'جست‌وجوی محصول، کارت‌های کوچک متغیرها، فیلتر دسته با سلکت و اعمال یکجا موجودی روی انتخاب‌ها.', 'tisacase-hub' ),
					'group' => 'products',
					'icon'  => 'box',
					'dir'   => 'tisacase-bulk-stock-manager',
					'cap'   => 'manage_woocommerce',
					'pages' => array(
						array(
							'label'  => __( 'باز کردن', 'tisacase-hub' ),
							'path'   => 'admin.php?page=tisacase-bulk-stock-manager',
							'screen' => 'woocommerce_page_tisacase-bulk-stock-manager',
							'parent' => 'woocommerce',
							'slug'   => 'tisacase-bulk-stock-manager',
						),
					),
				);

			$items['tracking'] = array(
				'title' => __( 'آپلود کد رهگیری انبوه', 'tisacase-hub' ),
				'desc'  => __( 'نگاشت اکسل/CSV به سفارش‌ها و درج کد رهگیری؛ به‌همراه پاک‌سازی گروهی.', 'tisacase-hub' ),
				'group' => 'orders',
				'icon'  => 'truck',
				'dir'   => 'bulk-tracking-upload',
				'cap'   => 'manage_options',
				'pages' => array(
					array(
						'label'  => __( 'آپلود', 'tisacase-hub' ),
						'path'   => 'admin.php?page=bulk-tracking-upload',
						'screen' => 'woocommerce_page_bulk-tracking-upload',
						'parent' => 'woocommerce',
						'slug'   => 'bulk-tracking-upload',
					),
					array(
						'label'  => __( 'پاک کردن کدها', 'tisacase-hub' ),
						'path'   => 'admin.php?page=bwt-cleanup',
						'screen' => 'woocommerce_page_bwt-cleanup',
						'parent' => 'woocommerce',
						'slug'   => 'bwt-cleanup',
					),
				),
			);

			$items['telegram'] = array(
				'title' => __( 'ارسال سفارش‌ها به تلگرام', 'tisacase-hub' ),
				'desc'  => __( 'سفارش جدید، تغییر وضعیت، گزارش شبانه و هشدار موجودی در چت تلگرام؛ با لاگ رویدادها.', 'tisacase-hub' ),
				'group' => 'orders',
				'icon'  => 'send',
				'dir'   => 'wc-telegram-orders',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'سفارش‌ها و گزارش', 'tisacase-hub' ),
						'path'   => 'admin.php?page=wc-telegram-orders',
						'screen' => 'woocommerce_page_wc-telegram-orders',
						'parent' => 'woocommerce',
						'slug'   => 'wc-telegram-orders',
					),
					array(
						'label'  => __( 'اعلان موجودی', 'tisacase-hub' ),
						'path'   => 'admin.php?page=wc-telegram-orders&tab=products',
						'screen' => 'woocommerce_page_wc-telegram-orders',
					),
				),
			);

			$items['exporter'] = array(
				'title' => __( 'خروجی گرفتن', 'tisacase-hub' ),
				'desc'  => __( 'شماره‌ها، سفارش‌ها، مشتری‌ها، محصول‌ها و کدهای تخفیف — با فیلتر، انتخاب ستون، پیش‌نمایش، چهار قالب خروجی و پاک‌سازی خودکار فایل موقت.', 'tisacase-hub' ),
				'group' => 'orders',
				'icon'  => 'upload',
				'dir'   => 'tisacase-exporter',
				'cap'   => 'manage_woocommerce',
				'pages' => array(
					array(
						'label'  => __( 'خروجی گرفتن', 'tisacase-hub' ),
						'path'   => 'admin.php?page=tisacase-exporter',
						'screen' => 'woocommerce_page_tisacase-exporter',
						'parent' => 'woocommerce',
						'slug'   => 'tisacase-exporter',
					),
				),
			);

			return apply_filters( 'tisacase_hub_default_items', $items );
		}

		/**
		 * کشف خودکار افزونه‌هایی که هدر TisaCase Hub: دارند.
		 *
		 * قالب هدر (همهٔ بخش‌ها اختیاری به‌جز page یا screens):
		 * `TisaCase Hub: title="نام"; icon=tag; group=pricing; page=admin.php?page=x; screen=woocommerce_page_x; cap=manage_woocommerce; desc="توضیح"`
		 *
		 * @param array<string,array> $items آیتم‌های موجود (برای پر کردن جای خالی).
		 * @return array<string,array>
		 */
		private static function discover( $items ) {
			foreach ( self::plugins() as $basename => $data ) {
				$raw = isset( $data['TisaCase Hub'] ) ? (string) $data['TisaCase Hub'] : '';
				if ( '' === $raw ) {
					continue;
				}
				$parsed = self::parse_header( $raw );
				if ( empty( $parsed ) ) {
					continue;
				}
				$dir = dirname( $basename );
				$key = isset( $parsed['key'] ) ? sanitize_key( $parsed['key'] ) : sanitize_key( str_replace( '/', '-', $dir ) );
				if ( 'yes' === ( isset( $parsed['self'] ) ? $parsed['self'] : '' ) ) {
					continue; // خودِ هاب؛ در فهرست نمی‌آید.
				}
				$item = array(
					'title'  => isset( $parsed['title'] ) ? $parsed['title'] : $data['Name'],
					'desc'   => isset( $parsed['desc'] ) ? $parsed['desc'] : wp_strip_all_tags( (string) $data['Description'] ),
					'group'  => isset( $parsed['group'] ) ? $parsed['group'] : 'products',
					'icon'   => isset( $parsed['icon'] ) ? $parsed['icon'] : 'plug',
					'dir'    => $dir,
					'cap'    => isset( $parsed['cap'] ) ? $parsed['cap'] : 'manage_woocommerce',
					'pages'  => array(),
					'source' => 'header',
				);
				if ( ! empty( $parsed['page'] ) ) {
					$item['pages'][] = array(
						'label'  => __( 'باز کردن', 'tisacase-hub' ),
						'path'   => $parsed['page'],
						'screen' => isset( $parsed['screen'] ) ? $parsed['screen'] : '',
						'parent' => isset( $parsed['parent'] ) ? $parsed['parent'] : '',
						'slug'   => isset( $parsed['slug'] ) ? $parsed['slug'] : '',
					);
				}
				if ( ! empty( $parsed['screens'] ) ) {
					$item['screens'] = array_map( 'sanitize_key', explode( ',', $parsed['screens'] ) );
				}
				$items[ $key ] = isset( $items[ $key ] ) ? array_merge( $items[ $key ], $item ) : $item;
			}
			return $items;
		}

		/**
		 * پایهٔ نهایی آدرس زیپ‌ها (تنظیمات هاب، وگرنه ZIP_BASE) — با اسلش انتهایی.
		 *
		 * @return string
		 */
		public static function zip_base() {
			$base = '';
			if ( class_exists( 'TSH_UI' ) ) {
				$base = trim( (string) TSH_UI::setting( 'zip_base', '' ) );
			}
			if ( '' === $base && class_exists( 'TSH_Remote' ) ) {
				$base = 'https://raw.githubusercontent.com/' . TSH_Remote::repo() . '/refs/heads/' . TSH_Remote::branch() . '/plugins/dist/';
			}
			if ( '' === $base ) {
				$base = self::ZIP_BASE;
			}
			return (string) apply_filters( 'tisacase_hub_zip_base', trailingslashit( $base ) );
		}

		/**
		 * آدرس زیپ نصب/به‌روزرسانی یک آیتم.
		 *
		 * اولویت: مقدار `zip` خود آیتم → پایهٔ تنظیمات هاب → پایهٔ پیش‌فرض (ZIP_BASE).
		 * با فیلتر `tisacase_hub_zip_url` هم می‌شود برای هر آیتم آدرس دیگری داد.
		 *
		 * @param array $item آیتم.
		 * @return string
		 */
		public static function zip_url( $item ) {
			$dir = isset( $item['dir'] ) ? (string) $item['dir'] : '';
			if ( '' === $dir ) {
				return '';
			}
			// `'zip' => false` یعنی این آیتم زیپی در مخزن ندارد (مثلاً افزونهٔ بازنشسته).
			if ( isset( $item['zip'] ) && false === $item['zip'] ) {
				return '';
			}

			$base = self::zip_base();

			$custom = isset( $item['zip'] ) ? trim( (string) $item['zip'] ) : '';
			if ( '' === $custom ) {
				$url = $base . $dir . '.zip';
			} elseif ( 0 === strpos( $custom, 'http://' ) || 0 === strpos( $custom, 'https://' ) ) {
				$url = $custom;
			} else {
				$url = $base . ltrim( $custom, '/' ); // آدرس نسبی
			}

			return (string) apply_filters( 'tisacase_hub_zip_url', $url, $item );
		}

		/**
		 * تجزیهٔ رشتهٔ هدر به زوج کلید/مقدار.
		 *
		 * @param string $raw متن هدر.
		 * @return array<string,string>
		 */
		public static function parse_header( $raw ) {
			$out   = array();
			$parts = preg_split( '/;\s*/', trim( $raw ) );
			foreach ( (array) $parts as $part ) {
				if ( ! $part || false === strpos( $part, '=' ) ) {
					continue;
				}
				list( $k, $v ) = explode( '=', $part, 2 );
				$k = strtolower( trim( $k ) );
				$v = trim( trim( $v ), " \t\"'" );
				if ( '' === $k ) {
					continue;
				}
				$out[ $k ] = 'page' === $k || 'screens' === $k ? $v : sanitize_text_field( $v );
			}
			return $out;
		}

		/**
		 * آیتم‌های نهایی (پیش‌فرض + کشف‌شده + فیلتر).
		 *
		 * @param bool $refresh پاک کردن کش درخواستی.
		 * @return array<string,array>
		 */
		/**
		 * افزونه‌هایی که با همگام‌سازی مخزن پیدا شده‌اند و در فهرست داخلی نیستند.
		 *
		 * @param array $items آیتم‌ها.
		 * @return array
		 */
		private static function merge_catalog( $items ) {
			if ( ! class_exists( 'TSH_Remote' ) ) {
				return $items;
			}
			$pack  = TSH_Remote::catalog();
			$extra = isset( $pack['items'] ) && is_array( $pack['items'] ) ? $pack['items'] : array();
			$dirs  = array();
			foreach ( $items as $it ) {
				if ( ! empty( $it['dir'] ) ) {
					$dirs[ $it['dir'] ] = true;
				}
			}
			foreach ( $extra as $key => $it ) {
				if ( ! is_array( $it ) || empty( $it['dir'] ) ) {
					continue;
				}
				if ( isset( $items[ $key ] ) || isset( $dirs[ $it['dir'] ] ) ) {
					continue;
				}
				$items[ $key ] = $it;
			}
			return $items;
		}

		public static function items( $refresh = false ) {
			if ( null !== self::$items && ! $refresh ) {
				return self::$items;
			}
			$items = self::discover( self::defaults() );
			$items = apply_filters( self::FILTER, $items );
			if ( ! is_array( $items ) ) {
				$items = array();
			}
			foreach ( $items as $key => $item ) {
				$items[ $key ]['key'] = $key;
				if ( empty( $items[ $key ]['pages'] ) ) {
					$items[ $key ]['pages'] = array();
				}
			}
			self::$items = $items;
			return self::$items;
		}

		/**
		 * get_plugins() یک‌بار در هر درخواست.
		 *
		 * @return array<string,array>
		 */
		public static function plugins() {
			if ( null !== self::$plugins ) {
				return self::$plugins;
			}
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
			return self::$plugins;
		}

		/**
		 * پیدا کردن فایل اصلی افزونه از روی نام پوشه.
		 *
		 * @param string $dir نام پوشهٔ افزونه.
		 * @return string basename یا رشتهٔ خالی.
		 */
		public static function basename_for( $dir ) {
			if ( ! $dir ) {
				return '';
			}
			foreach ( array_keys( self::plugins() ) as $basename ) {
				if ( 0 === strpos( $basename, $dir . '/' ) ) {
					return $basename;
				}
			}
			return '';
		}

		/**
		 * آیتم کامل‌شده با وضعیت فعلی (نسخه، فعال/غیرفعال، URL مطلق، دسترسی، شمارنده).
		 *
		 * @param string $key   کلید آیتم.
		 * @param array  $items آیتم‌های خام.
		 * @return array
		 */
		private static function resolve( $key, $items ) {
			$item     = $items[ $key ];
			$dir      = isset( $item['dir'] ) ? (string) $item['dir'] : '';
			$basename = $dir ? self::basename_for( $dir ) : '';
			$plugins  = self::plugins();
			$data     = ( $basename && isset( $plugins[ $basename ] ) ) ? $plugins[ $basename ] : array();

			$state = 'missing';
			if ( $basename ) {
				$state = is_plugin_active( $basename ) ? 'active' : 'inactive';
			}

			$pages = array();
			foreach ( (array) $item['pages'] as $page ) {
				$page['url'] = admin_url( $page['path'] );
				if ( empty( $page['label'] ) ) {
					$page['label'] = __( 'باز کردن', 'tisacase-hub' );
				}
				$pages[] = $page;
			}

			// دسترسی همان چیزی است که خودِ افزونه روی آیتم منوی خودش گذاشته است؛
			// اگر منو هنوز ثبت نشده باشد، به پیش‌فرض registry برمی‌گردیم.
			$cap = self::page_cap( $item );
			if ( '' === $cap ) {
				$cap = isset( $item['cap'] ) ? $item['cap'] : 'manage_woocommerce';
			}

			$item['key']        = $key;
			$item['pages']      = $pages;
			$item['basename']   = $basename;
			$item['state']      = $state;
			$item['version']    = isset( $data['Version'] ) ? (string) $data['Version'] : '';
			$item['name']       = isset( $data['Name'] ) ? (string) $data['Name'] : ( isset( $item['title'] ) ? $item['title'] : '' );
			$item['cap_name']   = $cap;
			$item['can']        = current_user_can( $cap );
			$item['can_manage'] = current_user_can( 'activate_plugins' );
			$item['can_update'] = current_user_can( 'update_plugins' ) && current_user_can( 'upload_plugins' ) && '' !== $dir;
			$item['zip']        = self::zip_url( $item );
			$item['can_install'] = current_user_can( 'install_plugins' ) && current_user_can( 'upload_plugins' ) && '' !== $dir && '' !== $item['zip'];
			$item['screens']    = isset( $item['screens'] ) ? (array) $item['screens'] : array();
			foreach ( $pages as $page ) {
				if ( ! empty( $page['screen'] ) && ! in_array( $page['screen'], $item['screens'], true ) ) {
					$item['screens'][] = $page['screen'];
				}
			}
			return $item;
		}

		/**
		 * همهٔ آیتم‌ها، گروه‌بندی‌شده و آمادهٔ رندر.
		 *
		 * @return array<string,array> گروه => آیتم‌ها
		 */
		public static function grouped() {
			$items   = self::items();
			$hidden  = TSH_UI::setting( 'hidden', array() );
			$out     = array();
			$ordered = array();

			foreach ( array_keys( $items ) as $key ) {
				$ordered[ $key ] = self::resolve( $key, $items );
			}

			foreach ( self::groups() as $gkey => $glabel ) {
				$out[ $gkey ] = array(
					'label' => $glabel,
					'items' => array(),
				);
			}
			foreach ( $ordered as $key => $item ) {
				if ( in_array( $key, (array) $hidden, true ) ) {
					continue;
				}
				$group = isset( $item['group'] ) && isset( $out[ $item['group'] ] ) ? $item['group'] : 'products';
				$out[ $group ]['items'][] = $item;
			}
			$out = array_filter(
				$out,
				static function ( $g ) {
					return ! empty( $g['items'] );
				}
			);
			return array( 'groups' => $out, 'all' => $ordered );
		}

	/**
	 * دسترسیِ واقعیِ صفحهٔ افزونه، خوانده‌شده از آرایهٔ منوی وردپرس.
	 *
	 * @param array $item آیتم.
	 * @return string کلید دسترسی ('' یعنی پیدا نشد).
	 */
	public static function page_cap( $item ) {
		if ( empty( $item['pages'] ) ) {
			return '';
		}
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) {
			return '';
		}
		foreach ( (array) $item['pages'] as $page ) {
			if ( empty( $page['slug'] ) ) {
				continue;
			}
			$parent = isset( $page['parent'] ) ? $page['parent'] : '';
			if ( 'top' === $parent || '' === $parent ) {
				foreach ( (array) $menu as $row ) {
					if ( isset( $row[2] ) && false !== strpos( (string) $row[2], $page['slug'] ) ) {
						return isset( $row[1] ) ? (string) $row[1] : '';
					}
				}
				continue;
			}
			$rows = isset( $submenu[ $parent ] ) ? (array) $submenu[ $parent ] : array();
			foreach ( $rows as $row ) {
				if ( isset( $row[2] ) && false !== strpos( (string) $row[2], $page['slug'] ) ) {
					return isset( $row[1] ) ? (string) $row[1] : '';
				}
			}
		}
		return '';
	}

		/**
		 * فهرست اسکرین‌هایی که هاب در آن‌ها استایل تزریق می‌کند.
		 *
		 * @return array<string,string> screen id => کلید آیتم
		 */
		public static function screens() {
			$map = array();
			foreach ( self::items() as $key => $item ) {
				if ( empty( $item['pages'] ) && empty( $item['screens'] ) ) {
					continue;
				}
				foreach ( (array) $item['pages'] as $page ) {
					if ( ! empty( $page['screen'] ) ) {
						$map[ $page['screen'] ] = $key;
					}
				}
				if ( ! empty( $item['screens'] ) ) {
					foreach ( (array) $item['screens'] as $screen ) {
						$map[ $screen ] = $key;
					}
				}
			}
			return $map;
		}

		/**
	 * اسلگ‌های `?page=` که باید استایل بگیرند، حتی اگر وردپرس نام والد را جور دیگری بسازد
	 * (product_page_x و edit_page_x و woocommerce_page_x هر سه).
	 *
	 * @return array<string,string> page slug => کلید آیتم
	 */
		public static function page_slugs() {
			$map = array();
			foreach ( self::items() as $key => $item ) {
				if ( empty( $item['pages'] ) ) {
					continue;
				}
				foreach ( (array) $item['pages'] as $page ) {
					if ( empty( $page['path'] ) || false === strpos( $page['path'], 'page=' ) ) {
						continue;
					}
					$q = array();
					parse_str( (string) wp_parse_url( $page['path'], PHP_URL_QUERY ), $q );
					if ( empty( $q['page'] ) ) {
						continue;
					}
					$map[ sanitize_key( $q['page'] ) ] = $key;
				}
			}
			return $map;
		}

		/**
	 * آیتم‌های منویی که باید مخفی شوند (وقتی گزینهٔ «تک‌ورودی» روشن است).
		 *
		 * @return array<int,array{parent:string,slug:string}>
		 */
		public static function menu_entries() {
			$out = array();
			foreach ( self::items() as $item ) {
				if ( empty( $item['pages'] ) ) {
					continue;
				}
				foreach ( $item['pages'] as $page ) {
					if ( empty( $page['parent'] ) || empty( $page['slug'] ) ) {
						continue;
					}
					$out[] = array(
						'parent' => $page['parent'],
						'slug'   => $page['slug'],
					);
				}
			}
			return $out;
		}

		/**
		 * نام آیتم‌ها برای برچسب‌زدن در گزارش سلامت.
		 *
		 * @param string $dir پوشهٔ افزونه.
		 * @return string
		 */
		public static function title_for_dir( $dir ) {
			foreach ( self::items() as $item ) {
				if ( ! empty( $item['dir'] ) && $item['dir'] === $dir ) {
					return $item['title'];
				}
			}
			return $dir;
		}
	}
}
