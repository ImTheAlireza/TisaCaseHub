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
			add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		}

		/**
		 * کلاس `tbsm-page` فقط روی صفحهٔ خودمان.
		 *
		 * چرا: فوترِ پیشخوان (خط «نگارش ۷٫۱٫۳» و متن سپاسگزاری) در پایین
		 * `#wpbody-content` چاپ می‌شود و روی این صفحه، وسط ابزارها/نوار چسبانِ
		 * پایین می‌افتد. با همین کلاس، فقط در همین صفحه پنهان می‌شود؛ هیچ صفحهٔ
		 * دیگری از پیشخوان وردپرس دست نمی‌خورد.
		 *
		 * @param string $classes کلاس‌های بدنه (رشتهٔ جداشده با فاصله).
		 * @return string
		 */
		public static function body_class( $classes ) {
			if ( self::is_our_screen() ) {
				$classes .= ' tbsm-page';
			}
			return $classes;
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
					'version' => TBSM_VERSION,
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
						'unifiedInvalid'     => 'موجودی همگانی را با عدد صحیح و غیرمنفی بنویسید.',
						'unifiedBtn'         => 'اعمال همگانی',
						'unifiedConfirm'     => 'تأیید: درج %1$s روی %2$d متغیر؟',
						'unifiedStatusConfirm' => 'تأیید: حالت «%1$s» روی %2$d متغیر؟',
						'fillDone'           => 'درج شد روی %d کارت انتخابی',
						'fillStatusDone'     => 'حالت «%1$s» روی %2$d کارت تنظیم شد.',
						'bottomHint'         => 'اعمال با مقادیر داخل هر کارت، روی انتخابی‌ها',
						'applyBtn'           => 'اعمال موجودی روی %d متغیر',
						'applyConfirm'       => 'تأیید: اعمال روی %d متغیر؟',
						'applying'           => 'در حال اعمال…',
						'loadError'          => 'بارگذاری محصول ناموفق بود.',
						'changeProduct'      => 'تغییر محصول',
						'stockLabel'         => 'موجودی',
						'modeLabel'          => 'نوع موجودی',
						'modeQty'            => 'عددی',
						'modeInStock'        => 'موجود (بدون تعداد)',
						'modeOutOfStock'     => 'ناموجود',
						'manageOffNote'      => 'موجودی‌گیری خاموش است — با اعمال روشن می‌شود',
						'reportTitle'        => 'موجودی %d متغیر اعمال شد',
						'parentSynced'       => 'وضعیت موجودی والد به «%s» همگام شد.',
						'inStockLabel'       => 'موجود',
						'outOfStockLabel'    => 'ناموجود',
						'enabledManage'      => 'موجودی‌گیری برای %d متغیر خاموش بود و هنگام اعمال روشن شد.',
						'errorCount'         => '%d مورد خطا داشت:',
						'applyError'         => 'اعمال ناموفق بود: %s',
						'errorApplyEmpty'    => 'مقداری برای اعمال پیدا نشد.',

						// حالت گروهی: محدودهٔ محصولات ← ویژگی/مقدار مدل.
						'groupHintEmpty'     => 'ابتدا محدودهٔ محصولات را مشخص کنید، بعد ویژگی و مقدار مدل را انتخاب کنید.',
						'scopePickHint'      => 'نام، SKU یا شناسهٔ محصول را بنویسید و از فهرست تیک بزنید (می‌توانید چند بار جست‌وجو کنید).',
						'scopeSearchEmpty'   => 'محصولی با این مشخصات پیدا نشد.',
						'scopeSearching'     => 'در حال جست‌وجو…',
						'scopeSearchError'   => 'جست‌وجوی محصول ناموفق بود.',
						'scopeSelectAll'     => 'افزودن همهٔ نتایج',
						'scopePicked'        => '%d محصول انتخاب‌شده',
						'scopeClear'         => 'پاک‌کردن انتخاب‌ها',
						'scopeApply'         => 'نمایش ویژگی‌ها',
						'scopeApplying'      => 'در حال بررسی محدوده…',
						'scopeNeedProducts'  => 'حداقل یک محصول انتخاب کنید.',
						'scopeNeedCategory'  => 'یک دسته‌بندی انتخاب کنید.',
						'scopeError'         => 'بررسی محدوده ناموفق بود.',
						'scopeSummary'       => '%1$s — %2$d محصول · %3$d متغیر',
						'scopeProductsUnit'  => 'محصول',
						'scopeTooMany'       => 'حداکثر %d محصول را می‌توانید دستی انتخاب کنید.',
						'scopeAllLoading'    => 'در حال شمارش محصولات و متغیرهای فروشگاه…',
						'scopeAllEmpty'      => 'هنوز محصولی در فروشگاه ثبت نشده است.',
						'groupAttrEmpty'     => 'در محدودهٔ انتخابی هیچ ویژگی متغیری پیدا نشد؛ محدوده را تغییر دهید.',
						'groupAttrLoading'   => 'در حال بارگذاری…',
						'groupValuesError'   => 'بارگذاری مقدارهای این ویژگی ناموفق بود.',
						'groupMergedKeys'    => '%d کلید ادغام‌شده',
						'groupTermSearch'    => 'جست‌وجو در مقدارها… (مثلاً a55 یا iPhone 11)',
						'groupTermFound'     => 'نمایش %1$d از %2$d مقدار',
						'groupTermTruncated' => 'فهرست مقدارها بریده شد؛ محدودهٔ کوچک‌تری انتخاب کنید تا همهٔ مقدارها بیاید.',
						'scopeCategorySearch' => 'جست‌وجوی دسته‌بندی… (مثلاً iPhone 11)',
						'scopeCategoryFound'  => 'نمایش %1$d دسته از %2$d',
						'scopeCategoryEmpty'  => 'دسته‌ای با این نام پیدا نشد.',
						'scopeErrorBadResponse' => 'پاسخ نامعتبر از سرور (احتمالاً کش یا ناهم‌خوانی نسخهٔ فایل‌ها).',
						'scopeErrorVersion' => 'نسخهٔ رابط (%1$s) با نسخهٔ سرور (%2$s) یکی نیست؛ افزونه را دوباره نصب یا کش را پاک کنید.',
						'scopeErrorNet'     => 'ارتباط با سرور برقرار نشد (اینترنت یا فایروال هاست).',
						'scopeErrorHttp'    => 'سرور خطای %d برگرداند.',
						'scopeErrorTimeout' => 'پاسخ سرور طول کشید (timeout)؛ محدودهٔ کوچک‌تری انتخاب کنید.',
						'groupTermPick'      => '— انتخاب کنید —',
						'groupFindError'     => 'فهرست‌کردن متغیرها ناموفق بود.',
						'groupNeedSelection' => 'ابتدا ویژگی و مقدار مدل را انتخاب کنید.',
						'groupSearching'     => 'در حال فهرست‌کردن متغیرها…',
						'groupEmpty'         => 'برای این مدل در این محدوده متغیری پیدا نشد.',
						'groupNote'          => '%1$s • %2$s — %3$d متغیر در %4$d محصول',
						'groupTruncated'     => 'فهرست به %d مورد محدود شد؛ محدوده یا مقدار مدل را دقیق‌تر کنید.',
						'groupApplyConfirm'  => 'تأیید: اعمال روی %d متغیر گروهی؟',
						'parentsSynced'      => 'وضعیت موجودی %d محصول والد همگام شد.',
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
							<p class="tbsm-hero-sub">یک محصول را با جست‌وجو انتخاب کنید و موجودی متغیرهایش را تغییر دهید؛ یا در تب «گروهی» اول محدودهٔ محصولات را مشخص کنید (چند محصول، دسته‌بندی یا شناسه)، بعد ویژگی و مقدار مدل را از میان ویژگی‌های همان محدوده انتخاب کنید و موجودی را تکی یا یک‌جا روی همهٔ آن‌ها اعمال کنید.</p>
						</div>
						<span class="tbsm-hero-ver" dir="ltr">v<?php echo esc_html( TBSM_VERSION ); ?></span>
					</div>
				</div>

				<div class="tbsm-notice" id="tbsm-notice" hidden role="alert"></div>

				<!-- انتخاب حالت کار: تک‌محصول یا گروهی بر اساس مدل -->
				<div class="tbsm-tabs" role="tablist" aria-label="حالت کار">
					<button type="button" class="tbsm-tab is-active" id="tbsm-tab-single" data-mode="single" role="tab" aria-selected="true">تک‌محصول</button>
					<button type="button" class="tbsm-tab" id="tbsm-tab-group" data-mode="group" role="tab" aria-selected="false">گروهی — چند محصول</button>
				</div>

				<div class="tbsm-panel" id="tbsm-panel-single">

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
						<input type="text" id="tbsm-search" class="tbsm-input tbsm-search-input" placeholder="نام یا SKU محصول را بنویسید…" autocomplete="off">
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
				</div>

				</div><!-- /#tbsm-panel-single -->

				<!-- حالت گروهی: محدودهٔ محصولات ← ویژگی و مقدار مدل -->
				<div class="tbsm-panel" id="tbsm-panel-group" hidden>

					<!-- گام ۱ گروهی: محدودهٔ محصولات -->
					<div class="tbsm-card">
						<div class="tbsm-step-head">
							<span class="tbsm-step-num">۱</span>
							<div class="tbsm-step-titles">
								<h2 class="tbsm-step-title">محدودهٔ محصولات</h2>
								<p class="tbsm-step-sub">اول مشخص کنید روی کدام محصولات کار می‌کنید؛ بعد فقط ویژگی‌های همان‌ها فهرست می‌شود.</p>
							</div>
						</div>

						<div class="tbsm-seg" role="tablist" aria-label="روش انتخاب محدوده">
							<button type="button" class="tbsm-seg-btn is-active" data-scope="products" role="tab" aria-selected="true">چند محصول</button>
							<button type="button" class="tbsm-seg-btn" data-scope="category" role="tab" aria-selected="false">دسته‌بندی</button>
							<button type="button" class="tbsm-seg-btn" data-scope="sku" role="tab" aria-selected="false">شناسهٔ (SKU)</button>
							<button type="button" class="tbsm-seg-btn" data-scope="all" role="tab" aria-selected="false">همهٔ محصولات</button>
						</div>

						<!-- محدوده: چند محصول با جست‌وجو -->
						<div class="tbsm-scope-pane" id="tbsm-scope-pane-products">
							<p class="tbsm-hint-line">نام، SKU یا شناسهٔ محصول را بنویسید و از فهرست تیک بزنید (می‌توانید چند بار جست‌وجو کنید).</p>
							<div class="tbsm-search">
								<span class="tbsm-search-icon"><?php echo self::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<input type="text" id="tbsm-scope-search" class="tbsm-input tbsm-search-input" placeholder="نام یا SKU محصول…" autocomplete="off">
							</div>
							<div class="tbsm-scope-results" id="tbsm-scope-results" hidden></div>
							<div class="tbsm-picked" id="tbsm-scope-picked" hidden></div>
						</div>

						<!-- محدوده: دسته‌بندی -->
						<div class="tbsm-scope-pane" id="tbsm-scope-pane-category" hidden>
							<p class="tbsm-hint-line">همهٔ محصولات این دسته و زیردسته‌هایش بررسی می‌شوند.</p>
							<label class="tbsm-field tbsm-field--wide" for="tbsm-scope-category-search">
								<span class="tbsm-field-label">جست‌وجوی دسته</span>
								<input type="text" id="tbsm-scope-category-search" class="tbsm-input" placeholder="جست‌وجوی دسته‌بندی… (مثلاً iPhone 11)" autocomplete="off">
							</label>
							<label class="tbsm-field tbsm-field--wide" for="tbsm-scope-category">
								<span class="tbsm-field-label">دسته‌بندی محصول</span>
								<select id="tbsm-scope-category" class="tbsm-input tbsm-select">
									<option value="">در حال بارگذاری…</option>
								</select>
							</label>
							<p class="tbsm-hint-line" id="tbsm-scope-category-hint" hidden></p>
						</div>

						<!-- محدوده: شناسه -->
						<div class="tbsm-scope-pane" id="tbsm-scope-pane-sku" hidden>
							<p class="tbsm-hint-line">مثلاً CH یا «CH184» — با «شامل می‌شود» هر شناسه‌ای که این متن را داشته باشد پیدا می‌شود.</p>
							<div class="tbsm-form-row">
								<label class="tbsm-field tbsm-field--grow" for="tbsm-scope-sku">
									<span class="tbsm-field-label">شناسهٔ (SKU) محصول</span>
									<input type="text" id="tbsm-scope-sku" class="tbsm-input" placeholder="مثلاً CH" autocomplete="off">
								</label>
								<label class="tbsm-field" for="tbsm-scope-sku-mode">
									<span class="tbsm-field-label">نوع تطبیق</span>
									<select id="tbsm-scope-sku-mode" class="tbsm-input tbsm-select">
										<option value="prefix">شروع می‌شود با</option>
										<option value="contains">شامل می‌شود</option>
									</select>
								</label>
							</div>
						</div>

						<!-- محدوده: همهٔ محصولات -->
						<div class="tbsm-scope-pane" id="tbsm-scope-pane-all" hidden>
							<p class="tbsm-hint-line">همهٔ محصولات فروشگاه یک‌جا بررسی می‌شوند و ویژگی‌های مدلشان فهرست می‌شود؛ مناسب وقتی می‌خواهید روی کل فروشگاه کار کنید و نمی‌خواهید محصول‌ها را دستی انتخاب کنید.</p>
							<div class="tbsm-scope-stat" id="tbsm-scope-all-stat" aria-live="polite"></div>
						</div>

						<div class="tbsm-scope-actions">
							<button type="button" class="tisa-btn tisa-btn--primary tbsm-form-action" id="tbsm-scope-apply">نمایش ویژگی‌ها</button>
							<span class="tbsm-scope-summary" id="tbsm-scope-summary" hidden></span>
						</div>

					</div>
					</div>

					<!-- گام ۲ گروهی: ویژگی و مقدار مدل -->
					<div class="tbsm-card tbsm-step-locked" id="tbsm-step-model">
						<div class="tbsm-step-head">
							<span class="tbsm-step-num">۲</span>
							<div class="tbsm-step-titles">
								<h2 class="tbsm-step-title">ویژگی و مقدار مدل</h2>
								<p class="tbsm-step-sub">فقط ویژگی‌های موجود در محدودهٔ انتخابی فهرست می‌شوند؛ بعد از انتخاب مقدار، کارت‌ها ساخته می‌شوند.</p>
							</div>
						</div>
						<div class="tbsm-form-row">
							<label class="tbsm-field tbsm-field--grow" for="tbsm-group-attr">
								<span class="tbsm-field-label">ویژگی مدل</span>
								<select id="tbsm-group-attr" class="tbsm-input tbsm-select" disabled>
									<option value="">— ابتدا محدوده را مشخص کنید —</option>
								</select>
							</label>
						</div>
						<div class="tbsm-form-row">
							<label class="tbsm-field tbsm-field--grow" for="tbsm-group-term">
								<span class="tbsm-field-label">مقدار مدل</span>
								<input type="text" id="tbsm-group-term-search" class="tbsm-input tbsm-term-search" placeholder="جست‌وجو در مقدارها… (مثلاً a55 یا iPhone 11)" autocomplete="off" disabled>
								<select id="tbsm-group-term" class="tbsm-input tbsm-select" disabled>
									<option value="">— انتخاب کنید —</option>
								</select>
							</label>
						</div>
						<p class="tbsm-hint-line" id="tbsm-group-term-hint" hidden></p>
						<p class="tbsm-hint-line" id="tbsm-group-hint" hidden></p>
					</div>

				</div><!-- /#tbsm-panel-group -->

				<!-- گام ۳: کارت‌های متغیرها -->
				<div class="tbsm-card tbsm-step-locked" id="tbsm-step-cards">
					<div class="tbsm-step-head">
						<span class="tbsm-step-num">۳</span>
						<div class="tbsm-step-titles">
							<h2 class="tbsm-step-title">کارت‌های متغیرها</h2>
							<p class="tbsm-step-sub">موجودیِ انتخابی‌ها را روی کارت‌ها تغییر دهید و از پایین اعمال کنید</p>
						</div>
					</div>
					<div class="tbsm-toolbar">
						<div class="tbsm-counts" id="tbsm-counts"></div>
						<div class="tbsm-unified">
							<label for="tbsm-bulk-mode">موجودی همگانی:</label>
							<select id="tbsm-bulk-mode" class="tbsm-input tbsm-select tbsm-bulk-mode" aria-label="نوع موجودی همگانی">
								<option value="qty">تعداد</option>
								<option value="in_stock">موجود بدون تعداد</option>
								<option value="out_of_stock">ناموجود</option>
							</select>
							<span class="tbsm-quickset-wrap" id="tbsm-quickset-wrap">
								<input type="number" id="tbsm-quickset" class="tbsm-input tbsm-quickset-input" min="0" inputmode="numeric" placeholder="مثلاً ۰">
							</span>
							<button type="button" class="tisa-btn tbsm-btn-fill" id="tbsm-quickset-btn" disabled>درج</button>
							<button type="button" class="tisa-btn tisa-btn--primary tbsm-btn-unified" id="tbsm-unified" disabled>اعمال همگانی</button>
						</div>
					</div>
					<div class="tbsm-group-note" id="tbsm-group-note" hidden></div>
					<div class="tbsm-grid" id="tbsm-grid" hidden></div>
					<div class="tbsm-bottombar">
						<span class="tbsm-bottombar-hint" id="tbsm-bottombar-hint"></span>
						<button type="button" class="tisa-btn tisa-btn--primary tbsm-btn-apply" id="tbsm-apply" disabled>اعمال موجودی</button>
					</div>
					<div class="tbsm-report" id="tbsm-report" hidden></div>
				</div>
			</div>
			<?php
		}
	}
}
