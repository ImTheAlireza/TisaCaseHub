<?php
/**
 * Admin screens: cleaner UI and backups/restore UI.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Admin
 */
class BDC_Admin {

	const SLUG = 'bdc-cleaner';

	/** Tab identifiers. */
	const TAB_CLEANER = 'cleaner';
	const TAB_BACKUPS = 'backups';

	/**
	 * Hook suffixes of our screens.
	 *
	 * @var array<int,string>
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . BDC_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Adds the "Clean" shortcut to the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = self::tab_url( self::TAB_CLEANER );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'پاک‌سازی', 'bulk-product-cleaner' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Registers admin pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hooks[] = add_submenu_page(
			'woocommerce',
			__( 'حذف انبوه پیش‌نویس', 'bulk-product-cleaner' ),
			__( 'حذف انبوه پیش‌نویس', 'bulk-product-cleaner' ),
			BDC_Ajax::capability(),
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Tab definitions.
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function tabs() {
		return array(
			self::TAB_CLEANER => __( 'حذف انبوه', 'bulk-product-cleaner' ),
			self::TAB_BACKUPS => __( 'پشتیبان و بازیابی', 'bulk-product-cleaner' ),
		);
	}

	/**
	 * Returns the currently requested tab, validated against the whitelist.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::TAB_CLEANER;

		return array_key_exists( $tab, self::tabs() ) ? $tab : self::TAB_CLEANER;
	}

	/**
	 * Builds the URL of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return admin_url(
			'admin.php?page=' . self::SLUG . '&tab=' . rawurlencode( $tab )
		);
	}

	/**
	 * Single entry point: renders the shared header, the tab nav, then the
	 * active tab's body.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( BDC_Ajax::capability() ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'bulk-product-cleaner' ) );
		}

		$active = self::current_tab();
		?>
		<div class="wrap bdc-wrap tisa-wrap" dir="rtl">
			<header class="bdc-hero">
				<div class="bdc-hero__row">
					<span class="bdc-hero__mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg></span>
					<div class="bdc-hero__txt">
						<h1 class="bdc-title"><?php esc_html_e( 'حذف انبوه محصولات پیش‌نویس', 'bulk-product-cleaner' ); ?></h1>
						<p class="bdc-hero__sub"><?php esc_html_e( 'پاک‌سازی محصولات و تصویرهای یتیم، با پشتیبان و بازیابی', 'bulk-product-cleaner' ); ?></p>
					</div>
				</div>
			<nav class="bdc-tabs" aria-label="<?php esc_attr_e( 'بخش‌های افزونه', 'bulk-product-cleaner' ); ?>">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( self::tab_url( $slug ) ); ?>"
						class="bdc-tab <?php echo $slug === $active ? 'is-active' : ''; ?>"
						<?php echo $slug === $active ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( $label ); ?>
						<?php if ( self::TAB_BACKUPS === $slug ) : ?>
							<?php $count = BDC_Backup::count_runs(); ?>
							<?php if ( $count > 0 ) : ?>
								<span class="bdc-tab-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			</header>

			<div class="bdc-tab-body">
				<?php
				if ( self::TAB_BACKUPS === $active ) {
					$this->render_backups();
				} else {
					$this->render_cleaner();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues assets on our screens only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}

		// استایل به فایل رفت (کش‌شدنی)؛ بعد از لایهٔ توکن هاب اگر فعال باشد.
		wp_enqueue_style(
			'bdc-admin',
			BDC_URL . 'assets/admin.css',
			wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array(),
			BDC_VERSION
		);

		wp_register_script( 'bdc-admin', false, array(), BDC_VERSION, true );
		wp_enqueue_script( 'bdc-admin' );

		wp_localize_script(
			'bdc-admin',
			'BDC',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( BDC_Ajax::NONCE ),
				'retention' => BDC_Backup::retention_days(),
				'batchSize' => BDC_Ajax::batch_size(),
				'i18n'      => array(
					'confirmDelete'  => __( 'آیا مطمئن هستید؟ این عملیات آغاز می‌شود.', 'bulk-product-cleaner' ),
					'confirmRestore' => __( 'بازیابی این پشتیبان آغاز شود؟', 'bulk-product-cleaner' ),
					'confirmDrop'    => __( 'این پشتیبان برای همیشه حذف شود؟ پس از آن بازیابی ممکن نیست.', 'bulk-product-cleaner' ),
					'collecting'     => __( 'در حال جمع‌آوری شناسه‌ها…', 'bulk-product-cleaner' ),
					'starting'       => __( 'در حال ساخت پشتیبان…', 'bulk-product-cleaner' ),
					'working'        => __( 'در حال پردازش…', 'bulk-product-cleaner' ),
					'done'           => __( 'انجام شد.', 'bulk-product-cleaner' ),
					'stopped'        => __( 'متوقف شد.', 'bulk-product-cleaner' ),
					'networkError'   => __( 'خطای شبکه — عملیات متوقف شد.', 'bulk-product-cleaner' ),
					'nothingPicked'  => __( 'هیچ محصولی انتخاب نشده است.', 'bulk-product-cleaner' ),
					'selected'       => __( 'انتخاب‌شده', 'bulk-product-cleaner' ),
					/* translators: %s: number of rows on the current page. */
					'allOnPage'      => __( 'هر %s مورد این صفحه انتخاب شد.', 'bulk-product-cleaner' ),
					/* translators: %s: total number of products matching the filter. */
					'selectMatching' => __( 'انتخاب هر %s محصول منطبق با فیلتر', 'bulk-product-cleaner' ),
					/* translators: %s: total number of products matching the filter. */
					'allMatching'    => __( 'هر %s محصول منطبق با فیلتر انتخاب شد.', 'bulk-product-cleaner' ),
					'clearSelection' => __( 'پاک کردن انتخاب', 'bulk-product-cleaner' ),
					'previewMode'      => __( 'حالت', 'bulk-product-cleaner' ),
					'previewProducts'  => __( 'محصولات', 'bulk-product-cleaner' ),
					'previewVariations'=> __( 'واریانت‌ها', 'bulk-product-cleaner' ),
					'previewImagesDel' => __( 'تصاویر قابل حذف', 'bulk-product-cleaner' ),
					'previewImagesKept'=> __( 'تصاویری که حفظ می‌شوند', 'bulk-product-cleaner' ),
					'previewShared'    => __( 'حفظ‌شده (مشترک)', 'bulk-product-cleaner' ),
					'previewFiles'     => __( 'فایل‌ها', 'bulk-product-cleaner' ),
					'previewFreed'     => __( 'فضای آزادشده', 'bulk-product-cleaner' ),
					'previewSkipped'   => __( 'رد شده', 'bulk-product-cleaner' ),
					'hintTrash'        => __( 'محصولات به زباله‌دان می‌روند. هیچ تصویری حذف نمی‌شود و فضایی آزاد نمی‌گردد. هر زمان بخواهید می‌توانید آن‌ها را بازگردانید.', 'bulk-product-cleaner' ),
					'hintDelete'       => __( 'محصولات برای همیشه حذف می‌شوند، اما تصاویرشان در کتابخانه رسانه باقی می‌ماند — بنابراین فضای دیسک آزاد نمی‌شود.', 'bulk-product-cleaner' ),
					'hintDeleteMedia'  => __( 'محصولات و تصاویر اختصاصی‌شان برای همیشه حذف می‌شوند و فضای دیسک آزاد می‌گردد. تصاویری که در جای دیگری استفاده شده‌اند حفظ خواهند شد.', 'bulk-product-cleaner' ),
				),
			)
		);

		wp_add_inline_script( 'bdc-admin', $this->js() );
	}

	/* --------------------------------------------------------------------- */
	/* Cleaner screen                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Renders the cleaner tab body.
	 *
	 * @return void
	 */
	private function render_cleaner() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$raw_filters = array(
			'status'     => isset( $_GET['status'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['status'] ) ) : array( 'draft' ),
			'search'     => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'older_than' => isset( $_GET['older_than'] ) ? absint( wp_unslash( $_GET['older_than'] ) ) : 0,
			'date_from'  => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'    => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'cat'        => isset( $_GET['cat'] ) ? absint( wp_unslash( $_GET['cat'] ) ) : 0,
			'author'     => isset( $_GET['author'] ) ? absint( wp_unslash( $_GET['author'] ) ) : 0,
			'images'     => isset( $_GET['images'] ) ? sanitize_key( wp_unslash( $_GET['images'] ) ) : 'any',
			'ptype'      => isset( $_GET['ptype'] ) ? sanitize_key( wp_unslash( $_GET['ptype'] ) ) : 'any',
			'no_price'   => isset( $_GET['no_price'] ) ? 1 : 0,
			'orderby'    => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date',
			'order'      => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		);

		$paged    = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? absint( wp_unslash( $_GET['per_page'] ) ) : 20;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filters  = BDC_Query::sanitize_filters( $raw_filters );
		$per_page = max( 10, min( 200, $per_page ) );

		$total       = BDC_Query::count( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = max( 1, min( $paged, $total_pages ) );
		$products    = BDC_Query::get_page( $filters, $paged, $per_page );
		$image_total = BDC_Query::count_images( $filters );

		$ids = array();
		foreach ( $products as $product ) {
			$ids[] = (int) $product->ID;
		}
		BDC_Query::prime_meta( $ids );

		$writable = BDC_Install::ensure_directories();

		?>
			<?php if ( ! $writable ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'هشدار:', 'bulk-product-cleaner' ); ?></strong>
						<?php esc_html_e( 'پوشه پشتیبان قابل نوشتن نیست. حذف دائم تا رفع این مشکل غیرفعال است.', 'bulk-product-cleaner' ); ?>
						<code><?php echo esc_html( BDC_Install::backup_root() ); ?></code>
					</p>
				</div>
			<?php endif; ?>

			<div class="bdc-stats">
				<div class="bdc-stat">
					<span class="bdc-stat-num"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'محصول یافت‌شده', 'bulk-product-cleaner' ); ?></span>
				</div>
				<div class="bdc-stat">
					<span class="bdc-stat-num"><?php echo esc_html( number_format_i18n( $image_total ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'تصویر متصل', 'bulk-product-cleaner' ); ?></span>
				</div>
				<div class="bdc-stat">
					<span class="bdc-stat-num" id="bdc-count">0</span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'انتخاب‌شده', 'bulk-product-cleaner' ); ?></span>
				</div>
				<div class="bdc-stat bdc-stat-info">
					<span class="bdc-stat-num"><?php echo esc_html( number_format_i18n( BDC_Backup::retention_days() ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'روز نگه‌داری پشتیبان', 'bulk-product-cleaner' ); ?></span>
				</div>
			</div>

			<?php $this->render_filters( $filters, $per_page ); ?>

			<div class="bdc-card">
				<table class="widefat striped bdc-table">
					<thead>
						<tr>
							<td class="check-column">
								<input type="checkbox" id="bdc-all" aria-label="<?php esc_attr_e( 'انتخاب همه در این صفحه', 'bulk-product-cleaner' ); ?>">
							</td>
							<th scope="col"><?php esc_html_e( 'شناسه', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'تصویر', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'نام محصول', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'وضعیت', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'تاریخ', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'تعداد تصاویر', 'bulk-product-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="7" class="bdc-empty">
								<?php esc_html_e( 'هیچ محصولی با این فیلترها یافت نشد.', 'bulk-product-cleaner' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<?php
							$product_id = (int) $product->ID;
							$images     = BDC_Query::collect_image_ids( $product_id );
							$thumb_id   = (int) get_post_meta( $product_id, '_thumbnail_id', true );
							$thumb      = $thumb_id > 0 ? wp_get_attachment_image( $thumb_id, array( 44, 44 ), true, array( 'class' => 'bdc-thumb' ) ) : '';
							$status_obj = get_post_status_object( $product->post_status );
							$status_lbl = $status_obj ? $status_obj->label : $product->post_status;
							?>
							<tr>
								<th scope="row" class="check-column">
									<input type="checkbox" class="bdc-cb" value="<?php echo esc_attr( (string) $product_id ); ?>"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: product title. */ __( 'انتخاب %s', 'bulk-product-cleaner' ), $product->post_title ) ); ?>">
								</th>
								<td>#<?php echo esc_html( (string) $product_id ); ?></td>
								<td class="bdc-thumb-cell">
									<?php
									if ( $thumb ) {
										echo wp_kses_post( $thumb );
									} else {
										echo '<span class="bdc-nothumb" aria-hidden="true">—</span>';
									}
									?>
								</td>
								<td class="bdc-name">
									<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>" target="_blank" rel="noopener noreferrer">
										<?php echo esc_html( '' !== $product->post_title ? $product->post_title : __( '(بدون عنوان)', 'bulk-product-cleaner' ) ); ?>
									</a>
								</td>
								<td><span class="bdc-badge bdc-badge-<?php echo esc_attr( $product->post_status ); ?>"><?php echo esc_html( $status_lbl ); ?></span></td>
								<td><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $product->post_date ) ) ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( count( $images ) ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>

				<div
					class="bdc-selectall"
					id="bdc-selectall"
					data-page-rows="<?php echo esc_attr( (string) count( $products ) ); ?>"
					data-total="<?php echo esc_attr( (string) $total ); ?>"
					hidden
				>
					<span id="bdc-selectall-text"></span>
					<button type="button" class="button-link" id="bdc-select-matching"></button>
					<button type="button" class="button-link bdc-clear" id="bdc-clear-selection" hidden></button>
					<span id="bdc-matching-state"></span>
				</div>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => esc_url_raw( add_query_arg( array( 'tab' => self::TAB_CLEANER, 'paged' => '%#%' ) ) ),
										'format'    => '',
										'prev_text' => '‹',
										'next_text' => '›',
										'total'     => $total_pages,
										'current'   => $paged,
										'end_size'  => 1,
										'mid_size'  => 2,
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php $this->render_action_panel( $writable ); ?>

			<div class="bdc-card bdc-notes">
				<h3><?php esc_html_e( 'نکات مهم', 'bulk-product-cleaner' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'پیش از هر حذف دائم، یک پشتیبان کامل (رکورد دیتابیس + فایل تصاویر) ساخته می‌شود.', 'bulk-product-cleaner' ); ?></li>
					<li>
						<?php
						printf(
							/* translators: %s: retention days. */
							esc_html__( 'پشتیبان‌ها دقیقاً %s روز نگه‌داری می‌شوند و سپس به‌طور خودکار پاک می‌گردند.', 'bulk-product-cleaner' ),
							'<strong>' . esc_html( number_format_i18n( BDC_Backup::retention_days() ) ) . '</strong>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'تصاویری که در محصول یا پست دیگری هم استفاده شده‌اند هرگز حذف نمی‌شوند.', 'bulk-product-cleaner' ); ?></li>
					<li><?php esc_html_e( 'حذف از طریق APIهای رسمی ووکامرس انجام می‌شود، بنابراین واریانت‌ها، دسته‌بندی‌ها و جدول‌های lookup تمیز می‌مانند.', 'bulk-product-cleaner' ); ?></li>
					<li><?php esc_html_e( 'عملیات به‌صورت دسته‌ای اجرا می‌شود؛ می‌توانید هر لحظه آن را متوقف کنید.', 'bulk-product-cleaner' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the filter form.
	 *
	 * @param array $filters  Sanitized filters.
	 * @param int   $per_page Rows per page.
	 * @return void
	 */
	private function render_filters( array $filters, $per_page ) {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 300,
			)
		);
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$statuses = array(
			'draft'      => __( 'پیش‌نویس', 'bulk-product-cleaner' ),
			'pending'    => __( 'در انتظار بازبینی', 'bulk-product-cleaner' ),
			'private'    => __( 'خصوصی', 'bulk-product-cleaner' ),
			'auto-draft' => __( 'پیش‌نویس خودکار', 'bulk-product-cleaner' ),
			'trash'      => __( 'زباله‌دان', 'bulk-product-cleaner' ),
		);
		?>
		<form method="get" class="bdc-card bdc-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_CLEANER ); ?>">

			<div class="bdc-filter-grid">
				<fieldset class="bdc-fieldset">
					<legend><?php esc_html_e( 'وضعیت', 'bulk-product-cleaner' ); ?></legend>
					<?php foreach ( $statuses as $slug => $label ) : ?>
						<label class="bdc-chk">
							<input type="checkbox" name="status[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $filters['status'], true ) ); ?>>
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<p class="bdc-field">
					<label for="bdc-s"><?php esc_html_e( 'جستجو (عنوان یا SKU)', 'bulk-product-cleaner' ); ?></label>
					<input type="search" id="bdc-s" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
				</p>

				<p class="bdc-field">
					<label for="bdc-older"><?php esc_html_e( 'قدیمی‌تر از (روز)', 'bulk-product-cleaner' ); ?></label>
					<input type="number" min="0" step="1" id="bdc-older" name="older_than" value="<?php echo esc_attr( (string) $filters['older_than'] ); ?>">
				</p>

				<p class="bdc-field">
					<label for="bdc-from"><?php esc_html_e( 'از تاریخ', 'bulk-product-cleaner' ); ?></label>
					<input type="date" id="bdc-from" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
				</p>

				<p class="bdc-field">
					<label for="bdc-to"><?php esc_html_e( 'تا تاریخ', 'bulk-product-cleaner' ); ?></label>
					<input type="date" id="bdc-to" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
				</p>

				<p class="bdc-field">
					<label for="bdc-cat"><?php esc_html_e( 'دسته‌بندی', 'bulk-product-cleaner' ); ?></label>
					<select id="bdc-cat" name="cat">
						<option value="0"><?php esc_html_e( 'همه', 'bulk-product-cleaner' ); ?></option>
						<?php foreach ( $categories as $category ) : ?>
							<option value="<?php echo esc_attr( (string) $category->term_id ); ?>" <?php selected( $filters['cat'], (int) $category->term_id ); ?>>
								<?php echo esc_html( $category->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="bdc-field">
					<label for="bdc-images"><?php esc_html_e( 'تصاویر', 'bulk-product-cleaner' ); ?></label>
					<select id="bdc-images" name="images">
						<option value="any" <?php selected( $filters['images'], 'any' ); ?>><?php esc_html_e( 'مهم نیست', 'bulk-product-cleaner' ); ?></option>
						<option value="with" <?php selected( $filters['images'], 'with' ); ?>><?php esc_html_e( 'فقط دارای تصویر', 'bulk-product-cleaner' ); ?></option>
						<option value="without" <?php selected( $filters['images'], 'without' ); ?>><?php esc_html_e( 'فقط بدون تصویر', 'bulk-product-cleaner' ); ?></option>
					</select>
				</p>

				<p class="bdc-field">
					<label for="bdc-ptype"><?php esc_html_e( 'نوع محصول', 'bulk-product-cleaner' ); ?></label>
					<select id="bdc-ptype" name="ptype">
						<option value="any" <?php selected( $filters['ptype'], 'any' ); ?>><?php esc_html_e( 'همه', 'bulk-product-cleaner' ); ?></option>
						<option value="simple" <?php selected( $filters['ptype'], 'simple' ); ?>><?php esc_html_e( 'ساده', 'bulk-product-cleaner' ); ?></option>
						<option value="variable" <?php selected( $filters['ptype'], 'variable' ); ?>><?php esc_html_e( 'متغیر', 'bulk-product-cleaner' ); ?></option>
						<option value="grouped" <?php selected( $filters['ptype'], 'grouped' ); ?>><?php esc_html_e( 'گروهی', 'bulk-product-cleaner' ); ?></option>
						<option value="external" <?php selected( $filters['ptype'], 'external' ); ?>><?php esc_html_e( 'خارجی', 'bulk-product-cleaner' ); ?></option>
					</select>
				</p>

				<p class="bdc-field">
					<label for="bdc-perpage"><?php esc_html_e( 'تعداد در صفحه', 'bulk-product-cleaner' ); ?></label>
					<input type="number" min="10" max="200" step="10" id="bdc-perpage" name="per_page" value="<?php echo esc_attr( (string) $per_page ); ?>">
				</p>

				<p class="bdc-field bdc-field-inline">
					<label class="bdc-chk">
						<input type="checkbox" name="no_price" value="1" <?php checked( 1, $filters['no_price'] ); ?>>
						<span><?php esc_html_e( 'فقط بدون قیمت', 'bulk-product-cleaner' ); ?></span>
					</label>
				</p>
			</div>

			<p class="bdc-filter-actions">
				<button type="submit" class="button bdc-btn bdc-btn--primary"><?php esc_html_e( 'اعمال فیلتر', 'bulk-product-cleaner' ); ?></button>
				<a class="button bdc-btn bdc-btn--ghost" href="<?php echo esc_url( self::tab_url( self::TAB_CLEANER ) ); ?>"><?php esc_html_e( 'پاک کردن', 'bulk-product-cleaner' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Renders the mode selector, run controls and progress area.
	 *
	 * @param bool $writable Whether the backup directory is writable.
	 * @return void
	 */
	private function render_action_panel( $writable ) {
		?>
		<div class="bdc-card bdc-actions">
			<h2><?php esc_html_e( 'اجرای عملیات', 'bulk-product-cleaner' ); ?></h2>

			<fieldset class="bdc-modes">
				<legend class="screen-reader-text"><?php esc_html_e( 'حالت حذف', 'bulk-product-cleaner' ); ?></legend>
				<?php $first = true; ?>
				<?php foreach ( BDC_Deleter::modes() as $slug => $label ) : ?>
					<label class="bdc-mode bdc-mode-<?php echo esc_attr( $slug ); ?>">
						<input type="radio" name="bdc_mode" value="<?php echo esc_attr( $slug ); ?>"
							<?php checked( $first ); ?>
							<?php disabled( ! $writable && 'trash' !== $slug ); ?>>
						<span><?php echo esc_html( $label ); ?></span>
					</label>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</fieldset>

			<p class="bdc-mode-hint" id="bdc-mode-hint" role="status" aria-live="polite"></p>

			<p class="bdc-confirm">
				<label class="bdc-chk">
					<input type="checkbox" id="bdc-confirm">
					<strong><?php esc_html_e( 'تأیید می‌کنم که می‌خواهم موارد انتخاب‌شده حذف شوند.', 'bulk-product-cleaner' ); ?></strong>
				</label>
			</p>

			<p class="bdc-buttons">
				<button type="button" class="button bdc-btn bdc-btn--secondary" id="bdc-dry"><?php esc_html_e( 'پیش‌نمایش (بدون حذف)', 'bulk-product-cleaner' ); ?></button>
				<button type="button" class="button bdc-btn bdc-btn--danger bdc-danger" id="bdc-run" disabled><?php esc_html_e( 'شروع عملیات', 'bulk-product-cleaner' ); ?></button>
				<button type="button" class="button bdc-btn bdc-btn--secondary" id="bdc-stop" hidden><?php esc_html_e( 'توقف', 'bulk-product-cleaner' ); ?></button>
				<a class="button bdc-btn bdc-btn--ghost" href="<?php echo esc_url( self::tab_url( self::TAB_BACKUPS ) ); ?>">
					<?php esc_html_e( 'مشاهده پشتیبان‌ها', 'bulk-product-cleaner' ); ?>
				</a>
			</p>

			<div class="bdc-progress-wrap" id="bdc-progress-wrap" hidden>
				<div class="bdc-progress"><div class="bdc-bar" id="bdc-bar"></div></div>
				<p class="bdc-progress-text" id="bdc-progress-text" role="status" aria-live="polite"></p>
			</div>

			<div class="bdc-log" id="bdc-log" hidden role="log" aria-live="polite"></div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Backups screen                                                        */
	/* --------------------------------------------------------------------- */

	/**
	 * Renders the backups & restore tab body.
	 *
	 * @return void
	 */
	private function render_backups() {
		$runs      = BDC_Backup::list_runs( true );
		$retention = BDC_Backup::retention_days();
		$total_sz  = 0;
		foreach ( $runs as $run ) {
			$total_sz += (int) $run['size_on_disk'];
		}
		?>
			<div class="bdc-stats">
				<div class="bdc-stat">
					<span class="bdc-stat-num"><?php echo esc_html( number_format_i18n( count( $runs ) ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'پشتیبان موجود', 'bulk-product-cleaner' ); ?></span>
				</div>
				<div class="bdc-stat">
					<span class="bdc-stat-num"><?php echo esc_html( size_format( $total_sz, 2 ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'فضای اشغال‌شده', 'bulk-product-cleaner' ); ?></span>
				</div>
				<div class="bdc-stat bdc-stat-info">
					<span class="bdc-stat-num"><?php echo esc_html( number_format_i18n( $retention ) ); ?></span>
					<span class="bdc-stat-lbl"><?php esc_html_e( 'روز نگه‌داری', 'bulk-product-cleaner' ); ?></span>
				</div>
			</div>

			<div class="bdc-card">
				<p class="bdc-hint">
					<?php
					printf(
						/* translators: %s: retention days. */
						esc_html__( 'هر پشتیبان دقیقاً %s روز پس از ساخت نگه‌داری و سپس به‌طور خودکار پاک می‌شود. تا آن زمان می‌توانید کل عملیات را با یک کلیک بازگردانید.', 'bulk-product-cleaner' ),
						'<strong>' . esc_html( number_format_i18n( $retention ) ) . '</strong>'
					);
					?>
				</p>
				<p class="bdc-hint">
					<?php esc_html_e( 'محل ذخیره:', 'bulk-product-cleaner' ); ?>
					<code><?php echo esc_html( BDC_Install::backup_root() ); ?></code>
				</p>

				<table class="widefat striped bdc-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'شناسه پشتیبان', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'تاریخ', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'کاربر', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'محتویات', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'حجم', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'انقضا', 'bulk-product-cleaner' ); ?></th>
							<th scope="col"><?php esc_html_e( 'عملیات', 'bulk-product-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $runs ) ) : ?>
						<tr><td colspan="7" class="bdc-empty"><?php esc_html_e( 'هنوز هیچ پشتیبانی ساخته نشده است.', 'bulk-product-cleaner' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $runs as $run ) : ?>
							<?php
							$stats = isset( $run['stats'] ) && is_array( $run['stats'] ) ? $run['stats'] : array();
							$prod  = isset( $stats['products'] ) ? (int) $stats['products'] : 0;
							$img   = isset( $stats['attachments'] ) ? (int) $stats['attachments'] : 0;
							$vars  = isset( $stats['variations'] ) ? (int) $stats['variations'] : 0;
							?>
							<tr data-run="<?php echo esc_attr( $run['run_id'] ); ?>">
								<td><code class="bdc-runid"><?php echo esc_html( $run['run_id'] ); ?></code></td>
								<td><?php echo esc_html( wp_date( 'Y/m/d H:i', (int) $run['created_ts'] ) ); ?></td>
								<td><?php echo esc_html( $run['user_login'] ); ?></td>
								<td>
									<?php
									printf(
										/* translators: 1: products, 2: variations, 3: images. */
										esc_html__( '%1$s محصول · %2$s واریانت · %3$s تصویر', 'bulk-product-cleaner' ),
										esc_html( number_format_i18n( $prod ) ),
										esc_html( number_format_i18n( $vars ) ),
										esc_html( number_format_i18n( $img ) )
									);
									?>
								</td>
								<td><?php echo esc_html( size_format( (int) $run['size_on_disk'], 2 ) ); ?></td>
								<td>
									<?php if ( $run['is_expired'] ) : ?>
										<span class="bdc-badge bdc-badge-trash"><?php esc_html_e( 'منقضی', 'bulk-product-cleaner' ); ?></span>
									<?php else : ?>
										<?php
										printf(
											/* translators: %s: days remaining. */
											esc_html__( '%s روز مانده', 'bulk-product-cleaner' ),
											'<strong>' . esc_html( number_format_i18n( (int) $run['days_left'] ) ) . '</strong>'
										);
										?>
									<?php endif; ?>
								</td>
								<td class="bdc-row-actions">
									<?php if ( ! empty( $run['restored'] ) ) : ?>
										<span class="bdc-badge bdc-badge-draft"><?php esc_html_e( 'بازیابی‌شده', 'bulk-product-cleaner' ); ?></span>
									<?php endif; ?>
									<button type="button" class="button bdc-btn bdc-btn--primary bdc-btn--sm bdc-restore" data-run="<?php echo esc_attr( $run['run_id'] ); ?>">
										<?php esc_html_e( 'بازیابی', 'bulk-product-cleaner' ); ?>
									</button>
									<button type="button" class="button bdc-btn bdc-btn--ghost bdc-btn--sm bdc-drop" data-run="<?php echo esc_attr( $run['run_id'] ); ?>">
										<?php esc_html_e( 'حذف پشتیبان', 'bulk-product-cleaner' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>

				<div class="bdc-progress-wrap" id="bdc-restore-wrap" hidden>
					<div class="bdc-progress"><div class="bdc-bar" id="bdc-restore-bar"></div></div>
					<p class="bdc-progress-text" id="bdc-restore-text" role="status" aria-live="polite"></p>
				</div>

				<div class="bdc-log" id="bdc-restore-log" hidden role="log" aria-live="polite"></div>
			</div>
		<?php
	}

	/* --------------------------------------------------------------------- */
	/* Assets                                                                */
	/* --------------------------------------------------------------------- */


	/**
	 * Inline JS. No inline handlers, fully delegated.
	 *
	 * @return string
	 */
	private function js() {
		return <<<'JS'
( function () {
	'use strict';

	var t = ( window.BDC && window.BDC.i18n ) || {};

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', window.BDC.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			body.append( key, ( typeof value === 'object' && value !== null ) ? JSON.stringify( value ) : value );
		} );

		return fetch( window.BDC.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( t.networkError || 'Network error' );
			} );
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				var message = ( json && json.data && json.data.message ) ? json.data.message : ( t.networkError || 'Error' );
				throw new Error( message );
			}
			return json.data;
		} );
	}

	function byId( id ) {
		return document.getElementById( id );
	}

	function humanBytes( bytes ) {
		bytes = Number( bytes ) || 0;
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i === 0 ? bytes : bytes.toFixed( 2 ) ) + ' ' + units[ i ];
	}

	function esc( value ) {
		var div = document.createElement( 'div' );
		div.textContent = String( value == null ? '' : value );
		return div.innerHTML;
	}

	/* ------------------------------------------------------------------ */
	/* Cleaner screen                                                      */
	/* ------------------------------------------------------------------ */

	var runBtn      = byId( 'bdc-run' );
	var restoreRows = document.querySelectorAll( '.bdc-restore' );

	if ( runBtn ) {
		var allBox     = byId( 'bdc-all' );
		var confirmBox = byId( 'bdc-confirm' );
		var countEl    = byId( 'bdc-count' );
		var stopBtn    = byId( 'bdc-stop' );
		var dryBtn     = byId( 'bdc-dry' );
		var bar        = byId( 'bdc-bar' );
		var barWrap    = byId( 'bdc-progress-wrap' );
		var barText    = byId( 'bdc-progress-text' );
		var logEl      = byId( 'bdc-log' );
		var selectAll  = byId( 'bdc-selectall' );
		var selectText = byId( 'bdc-selectall-text' );
		var matchBtn   = byId( 'bdc-select-matching' );
		var clearBtn   = byId( 'bdc-clear-selection' );
		var matchState = byId( 'bdc-matching-state' );

		var pageRows  = selectAll ? parseInt( selectAll.getAttribute( 'data-page-rows' ), 10 ) || 0 : 0;
		var totalRows = selectAll ? parseInt( selectAll.getAttribute( 'data-total' ), 10 ) || 0 : 0;

		var extraIds = [];   // IDs pulled in via "select all matching".
		var aborted  = false;
		var running  = false;

		function fmt( n ) {
			return new Intl.NumberFormat().format( n );
		}

		function sprintf1( template, value ) {
			return String( template || '' ).replace( '%s', value );
		}

		function pageIds() {
			return Array.prototype.slice.call( document.querySelectorAll( '.bdc-cb:checked' ) )
				.map( function ( box ) { return parseInt( box.value, 10 ); } )
				.filter( function ( id ) { return id > 0; } );
		}

		function selectedIds() {
			if ( extraIds.length ) {
				return extraIds.slice();
			}
			return pageIds();
		}

		function refresh() {
			var onPage = pageIds().length;
			var count  = selectedIds().length;

			countEl.textContent = fmt( count );
			runBtn.disabled = ! ( count > 0 && confirmBox.checked && ! running );

			if ( dryBtn ) {
				dryBtn.disabled = running || count === 0;
			}

			// Keep the header checkbox honest: checked only when every row on
			// the page is ticked, indeterminate on a partial selection.
			if ( allBox ) {
				allBox.checked       = pageRows > 0 && onPage === pageRows;
				allBox.indeterminate = onPage > 0 && onPage < pageRows;
			}

			if ( ! selectAll ) {
				return;
			}

			if ( extraIds.length ) {
				// Every matching product across all pages is selected.
				selectAll.hidden      = false;
				selectText.textContent = sprintf1( t.allMatching, fmt( extraIds.length ) );
				matchBtn.hidden       = true;
				clearBtn.hidden       = false;
				clearBtn.textContent  = t.clearSelection || '';
				return;
			}

			// Only advertise "select all N matching" once the whole page is
			// selected, and only when there actually are more pages to add.
			if ( pageRows > 0 && onPage === pageRows && totalRows > pageRows ) {
				selectAll.hidden       = false;
				selectText.textContent = sprintf1( t.allOnPage, fmt( onPage ) );
				matchBtn.hidden        = false;
				matchBtn.textContent   = sprintf1( t.selectMatching, fmt( totalRows ) );
				clearBtn.hidden        = true;
			} else {
				selectAll.hidden       = true;
				matchState.textContent = '';
			}
		}

		function log( message, kind ) {
			logEl.hidden = false;
			var line = document.createElement( 'div' );
			line.className = kind || '';
			line.innerHTML = esc( message );
			logEl.appendChild( line );
			logEl.scrollTop = logEl.scrollHeight;
		}

		function progress( done, total ) {
			barWrap.hidden = false;
			var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;
			bar.style.width = pct + '%';
			barText.textContent = pct + '%  —  ' + new Intl.NumberFormat().format( done ) +
				' / ' + new Intl.NumberFormat().format( total );
		}

		function currentMode() {
			var picked = document.querySelector( 'input[name="bdc_mode"]:checked' );
			return picked ? picked.value : 'trash';
		}

		function updateModeHint() {
			var hint = byId( 'bdc-mode-hint' );
			if ( ! hint ) {
				return;
			}
			var mode = currentMode();
			if ( 'delete_media' === mode ) {
				hint.textContent = t.hintDeleteMedia || '';
				hint.className   = 'bdc-mode-hint is-danger';
			} else if ( 'delete' === mode ) {
				hint.textContent = t.hintDelete || '';
				hint.className   = 'bdc-mode-hint is-danger';
			} else {
				hint.textContent = t.hintTrash || '';
				hint.className   = 'bdc-mode-hint';
			}
		}

		function currentFilters() {
			var params = new URLSearchParams( window.location.search );
			return {
				status:     params.getAll( 'status[]' ).concat( params.getAll( 'status' ) ),
				search:     params.get( 's' ) || '',
				older_than: params.get( 'older_than' ) || 0,
				date_from:  params.get( 'date_from' ) || '',
				date_to:    params.get( 'date_to' ) || '',
				cat:        params.get( 'cat' ) || 0,
				author:     params.get( 'author' ) || 0,
				images:     params.get( 'images' ) || 'any',
				ptype:      params.get( 'ptype' ) || 'any',
				no_price:   params.get( 'no_price' ) ? 1 : 0
			};
		}

		document.addEventListener( 'change', function ( event ) {
			if ( event.target === allBox ) {
				document.querySelectorAll( '.bdc-cb' ).forEach( function ( box ) {
					box.checked = allBox.checked;
				} );
				extraIds = [];
				if ( matchState ) { matchState.textContent = ''; }
			}
			if ( event.target.matches( '.bdc-cb' ) ) {
				extraIds = [];
				if ( matchState ) { matchState.textContent = ''; }
			}
			if ( event.target.matches( 'input[name="bdc_mode"]' ) ) {
				updateModeHint();
			}
			if ( event.target === confirmBox || event.target.matches( '.bdc-cb' ) || event.target === allBox ) {
				refresh();
			}
		} );

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				extraIds = [];
				document.querySelectorAll( '.bdc-cb' ).forEach( function ( box ) {
					box.checked = false;
				} );
				matchState.textContent = '';
				refresh();
			} );
		}

		if ( matchBtn ) {
			matchBtn.addEventListener( 'click', function () {
				matchBtn.disabled = true;
				matchState.textContent = ' ' + ( t.collecting || '' );
				var collected = [];

				var filters = currentFilters();
				function pull( afterId ) {
					return post( 'bdc_collect_ids', { filters: filters, after_id: afterId } )
						.then( function ( data ) {
							collected = collected.concat( data.ids );
							if ( ! data.done ) {
								return pull( data.after_id );
							}
							return collected;
						} );
				}

				pull( 0 ).then( function ( ids ) {
					extraIds = ids;
					matchState.textContent = '';
					matchBtn.disabled = false;
					refresh();
				} ).catch( function ( error ) {
					matchState.textContent = ' ' + error.message;
					matchBtn.disabled = false;
				} );
			} );
		}

		if ( dryBtn ) {
			dryBtn.addEventListener( 'click', function () {
				var ids = selectedIds();
				if ( ! ids.length ) {
					window.alert( t.nothingPicked );
					return;
				}
				dryBtn.disabled = true;
				logEl.hidden = false;
				logEl.innerHTML = '';
				log( '── ' + ( t.working || '' ) + ' ──' );

				post( 'bdc_preview', { ids: ids, mode: currentMode() } ).then( function ( data ) {
					log( ( t.previewMode || 'Mode' ) + ': ' + data.mode_label, 'ok' );

					if ( data.notice ) {
						log( '! ' + data.notice, 'skip' );
					}

					log( ( t.previewProducts || 'Products' ) + ': ' + data.products +
						'   |   ' + ( t.previewVariations || 'Variations' ) + ': ' + data.variations, 'ok' );

					if ( data.deletes_media ) {
						log( ( t.previewImagesDel || 'Images to delete' ) + ': ' + data.images +
							' / ' + data.images_total +
							'   |   ' + ( t.previewShared || 'Kept (shared)' ) + ': ' + data.shared,
							data.images > 0 ? 'ok' : 'skip' );
						log( ( t.previewFiles || 'Files' ) + ': ' + data.files +
							'   |   ' + ( t.previewFreed || 'Disk freed' ) + ': ' + data.size,
							data.bytes > 0 ? 'ok' : 'skip' );
					} else {
						log( ( t.previewImagesKept || 'Images kept' ) + ': ' + data.images_total +
							'   (' + data.size_kept + ')   —   ' +
							( t.previewFreed || 'Disk freed' ) + ': ' + data.size, 'skip' );
					}

					if ( data.skipped ) {
						log( ( t.previewSkipped || 'Skipped' ) + ': ' + data.skipped, 'skip' );
					}

					if ( data.truncated ) {
						log( 'Preview limited to first ' + data.limit + ' of ' + data.total_selected + ' items.', 'skip' );
					}

					( data.rows || [] ).slice( 0, 60 ).forEach( function ( row ) {
						var imgPart = data.deletes_media
							? 'img:' + row.images + '/' + row.images_total + ' shared:' + row.shared
							: 'img:' + row.images_total + ' (kept)';
						log( '#' + row.id + '  ' + row.title +
							'  [' + imgPart + ' var:' + row.variations + ' ' + row.size + ']' );
					} );

					dryBtn.disabled = false;
				} ).catch( function ( error ) {
					log( error.message, 'fail' );
					dryBtn.disabled = false;
				} );
			} );
		}

		stopBtn.addEventListener( 'click', function () {
			aborted = true;
			stopBtn.disabled = true;
			log( t.stopped, 'skip' );
		} );

		runBtn.addEventListener( 'click', function () {
			var ids = selectedIds();
			if ( ! ids.length ) {
				window.alert( t.nothingPicked );
				return;
			}
			if ( ! window.confirm( t.confirmDelete ) ) {
				return;
			}

			var mode  = currentMode();
			var total = ids.length;
			var done  = 0;
			var stats = { ok: 0, fail: 0, skipped: 0, images: 0, bytes: 0 };

			aborted = false;
			running = true;
			runBtn.disabled  = true;
			dryBtn.disabled  = true;
			stopBtn.hidden   = false;
			stopBtn.disabled = false;
			logEl.hidden     = false;
			logEl.innerHTML  = '';
			progress( 0, total );
			log( '── ' + ( t.starting || '' ) + ' ──' );

			var stepSize = parseInt( window.BDC.batchSize, 10 ) || 40;

			post( 'bdc_start_run', { mode: mode, filters: currentFilters() } ).then( function ( startData ) {
				var runId = startData.run_id || '';
				if ( startData.message ) {
					log( startData.message, 'ok' );
				}

				function step( index ) {
					if ( aborted || index >= total ) {
						return post( 'bdc_finish_run', {
							run_id: runId,
							status: aborted ? 'aborted' : 'completed'
						} ).catch( function () { return null; } );
					}

					var slice = ids.slice( index, index + stepSize );

					return post( 'bdc_process_batch', {
						ids: slice,
						mode: mode,
						run_id: runId,
						batch_size: stepSize
					} ).then( function ( data ) {
						// The server measured itself and told us what size
						// keeps the next request near the target duration.
						// This is what stops a 512-product run from turning
						// into 52 separate WordPress boots.
						if ( data.next_batch ) {
							stepSize = parseInt( data.next_batch, 10 ) || stepSize;
						}
						stats.ok      += data.ok;
						stats.fail    += data.fail;
						stats.skipped += data.skipped;
						stats.images  += data.images;
						stats.bytes   += data.bytes;

						( data.log || [] ).forEach( function ( entry ) {
							log( '#' + entry.id + '  ' + entry.message, entry.state );
						} );
						( data.notices || [] ).forEach( function ( notice ) {
							log( notice, 'skip' );
						} );

						// Trust the server's own tally so the bar can never
						// outrun reality; fall back to the slice length.
						var handled = ( data.ok || 0 ) + ( data.skipped || 0 ) + ( data.fail || 0 );
						if ( handled <= 0 || handled > slice.length ) {
							handled = slice.length;
						}

						done = Math.min( index + handled, total );
						progress( done, total );

						return step( index + handled );
					} );
				}

				return step( 0 );
			} ).then( function () {
				running = false;
				stopBtn.hidden = true;
				log( '── ' + ( aborted ? t.stopped : t.done ) + ' ──', aborted ? 'skip' : 'ok' );
				log( 'OK: ' + stats.ok + '  |  Skipped: ' + stats.skipped + '  |  Failed: ' + stats.fail +
					'  |  Images: ' + stats.images + '  |  Freed: ' + humanBytes( stats.bytes ), 'ok' );
				refresh();
			} ).catch( function ( error ) {
				running = false;
				stopBtn.hidden = true;
				log( error.message, 'fail' );
				refresh();
			} );
		} );

		updateModeHint();
		refresh();
	}

	/* ------------------------------------------------------------------ */
	/* Backups screen                                                      */
	/* ------------------------------------------------------------------ */

	if ( restoreRows.length || document.querySelector( '.bdc-drop' ) ) {
		var rWrap = byId( 'bdc-restore-wrap' );
		var rBar  = byId( 'bdc-restore-bar' );
		var rText = byId( 'bdc-restore-text' );
		var rLog  = byId( 'bdc-restore-log' );

		function rlog( message, kind ) {
			rLog.hidden = false;
			var line = document.createElement( 'div' );
			line.className = kind || '';
			line.innerHTML = esc( message );
			rLog.appendChild( line );
			rLog.scrollTop = rLog.scrollHeight;
		}

		function rprogress( done, total ) {
			rWrap.hidden = false;
			var pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 100;
			rBar.style.width = pct + '%';
			rText.textContent = pct + '%  —  ' + new Intl.NumberFormat().format( done ) +
				' / ' + new Intl.NumberFormat().format( total );
		}

		document.addEventListener( 'click', function ( event ) {
			var restoreBtn = event.target.closest( '.bdc-restore' );
			var dropBtn    = event.target.closest( '.bdc-drop' );

			if ( restoreBtn ) {
				if ( ! window.confirm( t.confirmRestore ) ) {
					return;
				}
				var runId = restoreBtn.getAttribute( 'data-run' );
				document.querySelectorAll( '.bdc-restore, .bdc-drop' ).forEach( function ( button ) {
					button.disabled = true;
				} );
				rLog.innerHTML = '';
				rlog( '── ' + runId + ' ──' );

				post( 'bdc_restore_start', { run_id: runId } ).then( function ( data ) {
					var total = data.total;
					if ( ! total ) {
						rlog( 'Nothing to restore.', 'skip' );
						throw new Error( t.done );
					}
					rprogress( 0, total );

					var summary = { restored: 0, skipped: 0, failed: 0 };

					var rStep = 20;

					function step( offset ) {
						return post( 'bdc_restore_batch', { run_id: runId, offset: offset, batch_size: rStep } )
							.then( function ( batch ) {
								if ( batch.next_batch ) {
									rStep = parseInt( batch.next_batch, 10 ) || rStep;
								}
								summary.restored += batch.restored;
								summary.skipped  += batch.skipped;
								summary.failed   += batch.failed;

								( batch.log || [] ).forEach( function ( line ) {
									rlog( line, batch.failed ? 'skip' : 'ok' );
								} );

								rprogress( Math.min( batch.offset, total ), total );

								// Only continue when the cursor actually moved,
								// otherwise we would loop forever on a bad batch.
								if ( ! batch.eof && batch.done > 0 && batch.offset > offset ) {
									return step( batch.offset );
								}
								return summary;
							} );
					}

					return step( 0 );
				} ).then( function ( summary ) {
					rlog( '── ' + t.done + ' ──', 'ok' );
					if ( summary ) {
						rlog( 'Restored: ' + summary.restored + '  |  Skipped: ' + summary.skipped +
							'  |  Failed: ' + summary.failed, 'ok' );
					}
					document.querySelectorAll( '.bdc-restore, .bdc-drop' ).forEach( function ( button ) {
						button.disabled = false;
					} );
				} ).catch( function ( error ) {
					rlog( error.message, 'fail' );
					document.querySelectorAll( '.bdc-restore, .bdc-drop' ).forEach( function ( button ) {
						button.disabled = false;
					} );
				} );
			}

			if ( dropBtn ) {
				if ( ! window.confirm( t.confirmDrop ) ) {
					return;
				}
				var dropId = dropBtn.getAttribute( 'data-run' );
				dropBtn.disabled = true;

				post( 'bdc_delete_backup', { run_id: dropId } ).then( function ( data ) {
					var row = document.querySelector( 'tr[data-run="' + dropId + '"]' );
					if ( row ) {
						row.parentNode.removeChild( row );
					}
					rlog( data.message, 'ok' );
				} ).catch( function ( error ) {
					rlog( error.message, 'fail' );
					dropBtn.disabled = false;
				} );
			}
		} );
	}
}() );
JS;
	}
}
