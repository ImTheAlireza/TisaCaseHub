<?php
/**
 * صفحهٔ «خروجی گرفتن»: سرصفحه، نوار بخش‌ها (هدر جدا)، فرم فیلتر/ستون/قالب،
 * اجرا و پیشرفت، پیش‌نمایش و تاریخچه — همه بر اساس طرح هر بخش (Schema-driven)
 * ساخته می‌شود؛ افزودن بخش جدید هیچ تغییری در این فایل لازم ندارد.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Admin_Page' ) ) {

	final class TisaCase_Exporter_Admin_Page {

		/** بدنهٔ صفحه. */
		public static function admin_page() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			// خودترمیمی: اگر رویداد کرون حذف شده باشد، دوباره زمان‌بندی می‌شود.
			if ( ! wp_next_scheduled( TisaCase_Exporter::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', TisaCase_Exporter::CRON_HOOK );
			}

			$section = self::current_section();

			if ( null === $section ) {
				echo '<div class="wrap tisa-wrap tisa-exp" dir="rtl"><div class="tisa-empty">'
					. esc_html__( 'هیچ بخش خروجی فعالی ثبت نشده است.', TisaCase_Exporter::TEXT_DOMAIN )
					. '</div></div>';

				return;
			}

			$storage    = self::storage_note();
			$standalone = '' === TisaCase_Exporter::hub_ui();
			?>
			<div class="wrap tisa-wrap tisa-exp<?php echo $standalone ? ' is-standalone' : ''; ?>" dir="rtl">
				<header class="tisa-exp__hero">
					<div class="tisa-exp__hero-mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M4 17v3h16v-3"/></svg>
					</div>
					<div class="tisa-exp__hero-text">
						<p class="tisa-exp__eyebrow"><?php esc_html_e( 'ابزار مدیریت داده‌های ووکامرس', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
						<h1 class="tisa-exp__title"><?php esc_html_e( 'خروجی گرفتن', TisaCase_Exporter::TEXT_DOMAIN ); ?></h1>
						<p class="tisa-exp__sub"><?php esc_html_e( 'فیلتر کنید، ستون‌ها را بچینید و فایل آمادهٔ دانلود بسازید.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<div class="tisa-exp__hero-meta" role="group" aria-label="<?php esc_attr_e( 'اطلاعات نسخه و منبع داده', TisaCase_Exporter::TEXT_DOMAIN ); ?>">
						<span class="tisa-exp__hero-pill tisa-exp__hero-pill--storage">
							<span class="tisa-exp__hero-dot" aria-hidden="true"></span>
							<span><?php esc_html_e( 'منبع داده', TisaCase_Exporter::TEXT_DOMAIN ); ?></span>
							<strong><?php echo esc_html( 'HPOS' === $storage ? 'HPOS' : __( 'ذخیره‌سازی قدیمی', TisaCase_Exporter::TEXT_DOMAIN ) ); ?></strong>
						</span>
						<span class="tisa-exp__hero-pill tisa-exp__hero-pill--version" dir="ltr"><span>v</span><?php echo esc_html( TISA_EXPORTER_VERSION ); ?></span>
					</div>
				</header>

				<?php TisaCase_Exporter_Modules::render_nav(); ?>
				<?php TisaCase_Exporter_Modules::render_head( $section ); ?>

				<div class="tisa-exp__stack">
					<?php
					self::render_filters_card( $section );
					self::render_output_card( $section );
					self::render_run_card( $section );
					self::render_diagnose_card();
					self::render_preview_card();
					self::render_history_card();
					?>
				</div>

				<?php self::render_help( $section ); ?>
			</div>

			<div class="tisa-exp__toast" id="tisa-exp-toast" role="status" aria-live="polite" hidden></div>
			<?php
		}

		/**
		 * تنظیمات اسکریپت (wp_localize_script).
		 *
		 * @return array
		 */
		public static function script_config() {
			$section = self::current_section();
			$state   = TisaCase_Exporter_Session::get_state();
			$initial = ! empty( $state ) ? TisaCase_Exporter_Ajax::response_payload( $state, true ) : array(
				'run_id'      => '',
				'processed'   => 0,
				'total'       => 0,
				'exported'    => 0,
				'skipped'     => 0,
				'duplicates'  => 0,
				'done'        => false,
				'files'       => array(),
				'zip_url'     => '',
				'history'     => TisaCase_Exporter_History::for_display(),
			);

			$history = isset( $initial['history'] ) ? $initial['history'] : array();
			unset( $initial['history'] );

			$default_columns = array();

			if ( null !== $section ) {
				$class           = $section['class'];
				$default_columns = (array) call_user_func( array( $class, 'sanitize_columns' ), array() );
			}

			return array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( TisaCase_Exporter::NONCE_ACTION ),
				'section'    => null === $section ? '' : (string) $section['id'],
				'sectionTitle' => null === $section ? '' : (string) $section['title'],
				'actions'    => array(
					'start'   => TisaCase_Exporter::AJAX_START,
					'process' => TisaCase_Exporter::AJAX_PROCESS,
					'cancel'  => TisaCase_Exporter::AJAX_CANCEL,
					'preview' => TisaCase_Exporter::AJAX_PREVIEW,
					'diagnose' => TisaCase_Exporter::AJAX_DIAGNOSE,
					'history' => TisaCase_Exporter::AJAX_HISTORY,
				),
				'batch'      => TisaCase_Exporter::batch_size(),
				'fileSize'   => TisaCase_Exporter::file_size(),
				'previewRows' => TisaCase_Exporter::PREVIEW_ROWS,
				'kpi'        => null === $section ? array() : (array) $section['kpi'],
				'unit'       => null === $section ? '' : (string) $section['unit'],
				'defaultColumns' => $default_columns,
				'formats'    => TisaCase_Exporter_Format::formats(),
				'initial'    => $initial,
				'history'    => $history,
				'storage'    => self::storage_note(),
				'l10n'       => self::l10n(),
			);
		}

		/* -----------------------------------------------------------------
		 * کارت‌ها
		 * ----------------------------------------------------------------- */

		/** کارت فیلترها (Schema-driven). */
		private static function render_filters_card( array $section ) {
			$filters = isset( $section['filters'] ) ? (array) $section['filters'] : array();
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-filters">
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step" aria-hidden="true">۱</span>
					<div>
						<h2><?php esc_html_e( 'فیلترها', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p><?php esc_html_e( 'خروجی فقط شامل ردیف‌هایی می‌شود که با این فیلترها بخوانند. خالی‌بودن یک فیلتر یعنی «همه».', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tisa-exp__reset" id="tisa-exp-reset"><?php esc_html_e( 'پاک‌کردن فیلترها', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
				</div>
				<div class="tisa-exp__card-body">
					<?php if ( empty( $filters ) ) : ?>
						<p class="tisa-exp__note"><?php esc_html_e( 'این بخش فیلتری ندارد؛ همهٔ ردیف‌ها خوانده می‌شود.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					<?php else : ?>
						<div class="tisa-exp__fields">
							<?php
							foreach ( $filters as $field ) {
								self::render_filter_field( $field );
							}
							?>
						</div>
					<?php endif; ?>
				</div>
			</section>
			<?php
		}

		/** یک فیلد فیلتر بر اساس نوع. */
		private static function render_filter_field( $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				return;
			}

			$name    = (string) $field['name'];
			$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';
			$label   = isset( $field['label'] ) ? (string) $field['label'] : $name;
			$default = isset( $field['default'] ) ? $field['default'] : '';
			$field_attr = 'tisa-exp-field-' . $name;
			?>
			<div class="tisa-exp__field" data-filter="<?php echo esc_attr( $name ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
				<label class="tisa-label" for="<?php echo esc_attr( $field_attr ); ?>"><?php echo esc_html( $label ); ?></label>
				<?php
				switch ( $type ) {
					case 'multiselect':
						$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
						$picked  = is_array( $default ) ? array_map( 'strval', $default ) : array();
						$counts  = ( 'statuses' === $name ) ? self::status_counts() : array();
						?>
						<div class="tisa-exp__checks" role="group" aria-label="<?php echo esc_attr( $label ); ?>">
							<?php foreach ( $options as $value => $option_label ) : ?>
								<label class="tisa-check tisa-exp__check">
									<input type="checkbox" name="filters[<?php echo esc_attr( $name ); ?>][]" value="<?php echo esc_attr( $value ); ?>"<?php checked( in_array( (string) $value, $picked, true ) ); ?>>
									<span><?php echo esc_html( wp_strip_all_tags( (string) $option_label ) ); ?><?php
										if ( isset( $counts[ (string) $value ] ) ) {
											echo ' <b class="tisa-exp__check-count" dir="ltr">' . esc_html( number_format_i18n( (int) $counts[ (string) $value ] ) ) . '</b>';
										}
									?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<div class="tisa-exp__field-actions">
							<button type="button" class="tisa-btn tisa-btn--link tisa-exp__check-all" data-target="<?php echo esc_attr( $name ); ?>" data-state="1"><?php esc_html_e( 'انتخاب همه', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
							<button type="button" class="tisa-btn tisa-btn--link tisa-exp__check-all" data-target="<?php echo esc_attr( $name ); ?>" data-state="0"><?php esc_html_e( 'هیچ‌کدام', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
						</div>
						<?php
						break;

					case 'select':
						$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
						?>
						<select class="tisa-select" id="<?php echo esc_attr( $field_attr ); ?>" name="filters[<?php echo esc_attr( $name ); ?>]">
							<?php foreach ( $options as $value => $option_label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>"<?php selected( (string) $default, (string) $value ); ?>><?php echo esc_html( wp_strip_all_tags( (string) $option_label ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						break;

					case 'number':
						?>
						<input class="tisa-input tisa-input--number" id="<?php echo esc_attr( $field_attr ); ?>" type="number" min="0" step="1" dir="ltr" inputmode="numeric"
							name="filters[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $default ); ?>">
						<?php
						break;

					case 'switch':
						?>
						<label class="tisa-switch">
							<input type="checkbox" id="<?php echo esc_attr( $field_attr ); ?>" name="filters[<?php echo esc_attr( $name ); ?>]" value="1"<?php checked( ! empty( $default ) ); ?>>
							<span class="tisa-switch__track" aria-hidden="true"></span>
							<span><?php esc_html_e( 'فعال', TisaCase_Exporter::TEXT_DOMAIN ); ?></span>
						</label>
						<?php
						break;

					case 'date':
						?>
						<input class="tisa-input" id="<?php echo esc_attr( $field_attr ); ?>" type="date" dir="ltr"
							name="filters[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $default ); ?>">
						<?php
						break;

					default:
						?>
						<input class="tisa-input" id="<?php echo esc_attr( $field_attr ); ?>" type="text"
							name="filters[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( (string) $default ); ?>">
						<?php
						break;
				}
				?>
			</div>
			<?php
		}

		/** کارت خروجی: ستون‌ها، قالب، یکتاسازی. */
		private static function render_output_card( array $section ) {
			$columns = isset( $section['columns'] ) ? (array) $section['columns'] : array();
			$class   = $section['class'];
			$default = TisaCase_Exporter_Format::is_valid( $section['default_format'] ) ? (string) $section['default_format'] : 'csv';
			$defs    = (array) call_user_func( array( $class, 'default_columns' ), $default );
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-output">
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step" aria-hidden="true">۲</span>
					<div>
						<h2><?php esc_html_e( 'ستون‌ها و قالب خروجی', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p><?php esc_html_e( 'ستون‌هایی که لازم دارید را انتخاب کنید؛ بقیه وارد فایل نمی‌شوند.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<div class="tisa-exp__head-actions">
						<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tisa-exp-cols-all"><?php esc_html_e( 'همه ستون‌ها', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
						<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tisa-exp-cols-default"><?php esc_html_e( 'پیش‌فرض', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
					</div>
				</div>
				<div class="tisa-exp__card-body tisa-exp__card-body--wide">
					<div class="tisa-exp__cols" role="group" aria-label="<?php esc_attr_e( 'ستون‌های خروجی', TisaCase_Exporter::TEXT_DOMAIN ); ?>">
						<?php foreach ( $columns as $key => $col ) : ?>
							<label class="tisa-check tisa-exp__check" data-column="<?php echo esc_attr( $key ); ?>" data-default="<?php echo in_array( (string) $key, array_map( 'strval', $defs ), true ) ? '1' : '0'; ?>">
								<input type="checkbox" name="columns[]" value="<?php echo esc_attr( $key ); ?>"<?php checked( in_array( (string) $key, array_map( 'strval', $defs ), true ) ); ?>>
								<span><?php echo esc_html( (string) $col['label'] ); ?></span>
								<small dir="ltr"><?php echo esc_html( (string) $key ); ?></small>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="tisa-exp__format">
						<h3 class="tisa-exp__sub-h"><?php esc_html_e( 'قالب فایل', TisaCase_Exporter::TEXT_DOMAIN ); ?></h3>
						<div class="tisa-exp__choices">
							<?php foreach ( TisaCase_Exporter_Format::formats() as $key => $label ) : ?>
								<label class="tisa-choice<?php echo ( $key === $default ) ? ' is-selected' : ''; ?>">
									<input type="radio" name="format" value="<?php echo esc_attr( $key ); ?>"<?php checked( $key, $default ); ?>>
									<span>
										<span class="tisa-choice__title">
											<?php echo esc_html( $label ); ?>
											<?php if ( $key === $default ) : ?>
												<span class="tisa-badge tisa-badge--outline"><?php esc_html_e( 'پیشنهادی', TisaCase_Exporter::TEXT_DOMAIN ); ?></span>
											<?php endif; ?>
										</span>
										<span class="tisa-choice__desc"><?php echo esc_html( self::format_desc( $key ) ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<?php if ( ! empty( $section['dedup'] ) ) : ?>
						<div class="tisa-exp__dedup">
							<h3 class="tisa-exp__sub-h"><?php esc_html_e( 'حذف تکراری‌ها', TisaCase_Exporter::TEXT_DOMAIN ); ?></h3>
							<div class="tisa-exp__dedup-row">
								<select class="tisa-select" name="dedup" id="tisa-exp-dedup">
									<option value=""><?php esc_html_e( 'بدون حذف تکراری — همهٔ ردیف‌ها', TisaCase_Exporter::TEXT_DOMAIN ); ?></option>
									<?php foreach ( $section['dedup'] as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"<?php selected( (string) $section['default_dedup'], (string) $key ); ?>>
											<?php
											/* translators: %s: نام ستون کلید یکتاسازی. */
											printf( esc_html__( 'هر %s یک‌بار (مرتب‌شده بر اساس همان ستون)', TisaCase_Exporter::TEXT_DOMAIN ), esc_html( $label ) );
											?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="tisa-exp__hint">
									<?php esc_html_e( 'ترتیب ردیف‌ها بر اساس ستون کلید می‌شود و ردیف‌های تکراری دقیقاً یک‌بار می‌آیند؛ برای داده‌های زیاد این کار روی سرور و با حافظهٔ محدود انجام می‌شود.', TisaCase_Exporter::TEXT_DOMAIN ); ?>
								</p>
							</div>
						</div>
					<?php endif; ?>

					<p class="tisa-exp__hint" id="tisa-exp-output-hint"></p>
				</div>
			</section>
			<?php
		}

		/** کارت اجرا + پیشرفت. */
		private static function render_run_card( array $section ) {
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-run" aria-busy="false">
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step" aria-hidden="true">۳</span>
					<div>
						<h2><?php esc_html_e( 'اجرای خروجی', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p><?php echo esc_html( self::run_note( $section ) ); ?></p>
					</div>
				</div>
				<div class="tisa-exp__card-body">
					<div class="tisa-exp__between tisa-exp__run-row">
						<div class="tisa-exp__actions">
							<button type="button" class="tisa-btn tisa-btn--primary" id="tisa-exp-start"><?php esc_html_e( 'شروع خروجی جدید', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
							<button type="button" class="tisa-btn tisa-btn--secondary" id="tisa-exp-continue" hidden><?php esc_html_e( 'ادامه خروجی', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
							<button type="button" class="tisa-btn tisa-btn--secondary" id="tisa-exp-preview"><?php esc_html_e( 'پیش‌نمایش', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
							<button type="button" class="tisa-btn tisa-btn--ghost" id="tisa-exp-status"><?php esc_html_e( 'آخرین وضعیت', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-exp__danger" id="tisa-exp-cancel"><?php esc_html_e( 'توقف و پاک‌سازی', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
						</div>
						<span class="tisa-exp__meta" id="tisa-exp-meta"></span>
					</div>

					<div class="tisa-exp__box" id="tisa-exp-progress-box" hidden>
						<div class="tisa-exp__kpis" id="tisa-exp-kpis"></div>
						<div class="tisa-progress" id="tisa-exp-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div class="tisa-progress__bar" id="tisa-exp-bar" style="width:0%"></div>
						</div>
						<p class="tisa-progress-text" id="tisa-exp-state" aria-live="polite"></p>
						<div class="tisa-exp__files" id="tisa-exp-files"></div>
					</div>
				</div>
			</section>
			<?php
		}

		/** کارت پیش‌نمایش. */
		/** شمارش سفارش‌ها به تفکیک وضعیت برای نمایش کنار چک‌باکس‌ها (کش‌شده). */
		private static function status_counts() {
			static $cache = null;

			if ( is_array( $cache ) ) {
				return $cache;
			}

			$cache   = array();
			$section = self::current_section();

			if ( null === $section ) {
				return $cache;
			}

			$class = $section['class'];

			if ( ! method_exists( $class, 'order_status_counts' ) ) {
				return $cache;
			}

			$counts = (array) call_user_func( array( $class, 'order_status_counts' ) );
			unset( $counts['__sources__'] );

			$cache = $counts;

			return $cache;
		}

		/** کارت عیب‌یابی شمارش (چرا عدد خروجی این عدد است؟). */
		private static function render_diagnose_card() {
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-diagnose">
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step tisa-exp__step--soft" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
					</span>
					<div>
						<h2><?php esc_html_e( 'عیب‌یابی شمارش', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p><?php esc_html_e( 'این بررسی فقط شمارش می‌کند و هیچ فایلی نمی‌سازد؛ اگر عدد این‌جا با عدد ووکامرس نمی‌خواند، علتش را دقیقاً نشان می‌دهد.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tisa-exp-diagnose"><?php esc_html_e( 'بررسی شمارش', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
				</div>
				<div class="tisa-exp__card-body" id="tisa-exp-diagnose-out"></div>
			</section>
			<?php
		}

		private static function render_preview_card() {
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-preview" hidden>
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step tisa-exp__step--soft" aria-hidden="true">۴</span>
					<div>
						<h2><?php esc_html_e( 'پیش‌نمایش', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p id="tisa-exp-preview-note"><?php esc_html_e( 'چند ردیف اول با همین فیلترها و ستون‌ها؛ هیچ فایلی ساخته نمی‌شود.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tisa-exp__reset" id="tisa-exp-preview-close"><?php esc_html_e( 'بستن', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
				</div>
				<div class="tisa-exp__card-body tisa-exp__card-body--wide">
					<div class="tisa-table-scroll">
						<table class="tisa-table tisa-exp__preview-table">
							<thead id="tisa-exp-preview-head"></thead>
							<tbody id="tisa-exp-preview-body"></tbody>
						</table>
					</div>
				</div>
			</section>
			<?php
		}

		/** کارت تاریخچه. */
		private static function render_history_card() {
			?>
			<section class="tisa-exp__card" id="tisa-exp-card-history">
				<div class="tisa-exp__card-head">
					<span class="tisa-exp__step tisa-exp__step--soft" aria-hidden="true">۵</span>
					<div>
						<h2><?php esc_html_e( 'تاریخچهٔ اجراها', TisaCase_Exporter::TEXT_DOMAIN ); ?></h2>
						<p><?php esc_html_e( 'آخرین ۲۰ خروجی شما؛ فایل‌ها ۲۴ ساعت روی سرور می‌مانند و بعد خودکار پاک می‌شوند.', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					</div>
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tisa-exp__danger" id="tisa-exp-history-clear"><?php esc_html_e( 'پاک‌کردن تاریخچه', TisaCase_Exporter::TEXT_DOMAIN ); ?></button>
				</div>
				<div class="tisa-exp__card-body" id="tisa-exp-history"></div>
			</section>
			<?php
		}

		/** راهنمای پایین صفحه. */
		private static function render_help( array $section ) {
			?>
			<details class="tisa-exp__help">
				<summary><?php esc_html_e( 'این بخش چطور کار می‌کند؟', TisaCase_Exporter::TEXT_DOMAIN ); ?></summary>
				<p><?php echo esc_html( self::help_text( $section ) ); ?></p>
			</details>
			<?php
		}

		/* -----------------------------------------------------------------
		 * متن‌ها و کمکی‌ها
		 * ----------------------------------------------------------------- */

		/** بخش جاری. */
		private static function current_section() {
			$id = TisaCase_Exporter_Modules::current();

			return ( '' === $id ) ? null : TisaCase_Exporter_Modules::get( $id );
		}

		/** برچسب انبار خروجی: HPOS/Legacy. */
		private static function storage_note() {
			$section = self::current_section();

			if ( null === $section ) {
				return '';
			}

			$class = $section['class'];

			return (string) call_user_func( array( $class, 'storage_label' ) );
		}

		/** توضیح هر قالب. */
		private static function format_desc( $format ) {
			switch ( $format ) {
				case 'txt':
					return __( 'یک ردیف در هر خط، بدون سرستون — مناسب شماره‌ها و ابزارهای ارسال پیامک.', TisaCase_Exporter::TEXT_DOMAIN );
				case 'csv':
					return __( 'سرستون فارسی + BOM؛ بهترین گزینه برای اکسل و گوگل‌شیت.', TisaCase_Exporter::TEXT_DOMAIN );
				case 'xls':
					return __( 'فایل اکسل کلاسیک (SpreadsheetML) سازگار با نسخه‌های قدیمی آفیس.', TisaCase_Exporter::TEXT_DOMAIN );
				case 'pdf':
					return __( 'جدول راست‌به‌چپ با قلم فارسی جاسازی‌شده — مناسب چاپ، ارسال به مشتری و بایگانی.', TisaCase_Exporter::TEXT_DOMAIN );
				case 'json':
					return __( 'آرایهٔ استاندارد JSON برای اتصال به نرم‌افزارهای دیگر.', TisaCase_Exporter::TEXT_DOMAIN );
			}

			return '';
		}

		/** توضیح روش اجرا برای هر بخش. */
		private static function run_note( array $section ) {
			$unit = isset( $section['unit'] ) ? (string) $section['unit'] : '';

			return sprintf(
				/* translators: 1: تعداد ردیف هر فایل، 2: تعداد ردیف هر گام، 3: واحد شمارش. */
				__( 'پردازش گام‌به‌گام انجام می‌شود (هر گام %2$s ردیف) و فایل‌ها هر %1$s ردیف یک‌بار ساخته می‌شوند؛ توقف وسط کار مشکلی ندارد و بعداً ادامه می‌دهید. واحد شمارش: %3$s.', TisaCase_Exporter::TEXT_DOMAIN ),
				number_format_i18n( TisaCase_Exporter::file_size() ),
				number_format_i18n( TisaCase_Exporter::batch_size() ),
				'' !== $unit ? $unit : __( 'ردیف', TisaCase_Exporter::TEXT_DOMAIN )
			);
		}

		/** متن راهنما. */
		private static function help_text( array $section ) {
			$title = isset( $section['title'] ) ? (string) $section['title'] : '';

			return sprintf(
				/* translators: %s: نام بخش. */
				__( 'بخش «%s» بدون خواندن کل داده در حافظه کار می‌کند: هر گام فقط یک Batch خوانده می‌شود، نتیجه در فایل موقت روی سرور نوشته می‌شود و در پایان به فایل‌های آمادهٔ دانلود تبدیل می‌شود. فایل‌ها داخل پوشهٔ اختصاصی و محافظت‌شدهٔ همین کاربر ساخته می‌شوند، با غیرفعال‌شدن افزونه یا بعد از ۲۴ ساعت خودکار پاک می‌شوند و خروجی گرفتن هیچ ردی روی سرعت سایت نمی‌گذارد.', TisaCase_Exporter::TEXT_DOMAIN ),
				$title
			);
		}

		/** رشته‌های JS. */
		private static function l10n() {
			return array(
				'preparing'   => __( 'در حال آماده‌سازی خروجی...', TisaCase_Exporter::TEXT_DOMAIN ),
				'running'     => __( 'در حال پردازش...', TisaCase_Exporter::TEXT_DOMAIN ),
				'doneFiles'   => __( 'عملیات کامل شد — %1 فایل آماده دانلود است.', TisaCase_Exporter::TEXT_DOMAIN ),
				'doneEmpty'   => __( 'خروجی ساخته شد اما هیچ ردیفی با این فیلترها پیدا نشد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'cancelled'   => __( 'خروجی لغو شد و فایل‌های موقت پاک شدند.', TisaCase_Exporter::TEXT_DOMAIN ),
				'queued'      => __( 'در صف پردازش...', TisaCase_Exporter::TEXT_DOMAIN ),
				'rowsPer'     => __( 'ردیف', TisaCase_Exporter::TEXT_DOMAIN ),
				'of'          => __( 'از', TisaCase_Exporter::TEXT_DOMAIN ),
				'percent'     => __( 'درصد', TisaCase_Exporter::TEXT_DOMAIN ),
				'timeSpent'   => __( 'زمان سپری‌شده: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'eta'         => __( 'تخمین باقی‌مانده: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'storage'     => __( 'محل ذخیره: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'filesReady'  => __( 'فایل‌های آماده', TisaCase_Exporter::TEXT_DOMAIN ),
				'download'    => __( 'دانلود فایل', TisaCase_Exporter::TEXT_DOMAIN ),
				'downloadAll' => __( 'دانلود همه (ZIP)', TisaCase_Exporter::TEXT_DOMAIN ),
				'print'       => __( 'چاپ', TisaCase_Exporter::TEXT_DOMAIN ),
				'rowCount'    => __( '%1 ردیف', TisaCase_Exporter::TEXT_DOMAIN ),
				'numberUnit'  => __( 'شماره', TisaCase_Exporter::TEXT_DOMAIN ),
				'noSession'   => __( 'خروجی فعالی وجود ندارد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'lastState'   => __( 'وضعیت جلسهٔ فعلی روی سرور نمایش داده شد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'filesGone'   => __( 'فایل‌های این خروجی پاک شده‌اند.', TisaCase_Exporter::TEXT_DOMAIN ),
				'historyTitle' => __( 'خروجی', TisaCase_Exporter::TEXT_DOMAIN ),
				'historyEmpty' => __( 'هنوز خروجی‌ای نگرفته‌اید؛ اولین خروجی که بسازید این‌جا نگه داشته می‌شود.', TisaCase_Exporter::TEXT_DOMAIN ),
				'reuse'       => __( 'اجرای مجدد با همین تنظیمات', TisaCase_Exporter::TEXT_DOMAIN ),
				'clearConfirm' => __( 'همهٔ تاریخچه و فایل‌های باقی‌مانده پاک شود؟', TisaCase_Exporter::TEXT_DOMAIN ),
				'cancelConfirm' => __( 'خروجی در حال اجرا متوقف و فایل‌های موقت پاک شوند؟', TisaCase_Exporter::TEXT_DOMAIN ),
				'unloadMsg'   => __( 'خروجی در حال پردازش است؛ با بستن صفحه متوقف می‌شود (بعداً قابل ادامه است).', TisaCase_Exporter::TEXT_DOMAIN ),
				'startError'  => __( 'شروع خروجی ناموفق بود:', TisaCase_Exporter::TEXT_DOMAIN ),
				'processError' => __( 'خطا در پردازش:', TisaCase_Exporter::TEXT_DOMAIN ),
				'ajaxError'   => __( 'ارتباط با سرور برقرار نشد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'previewError' => __( 'پیش‌نمایش ناموفق بود:', TisaCase_Exporter::TEXT_DOMAIN ),
				'previewNote' => __( '%1 ردیف اول (از %2 ردیف) با همین ستون‌ها.', TisaCase_Exporter::TEXT_DOMAIN ),
				'previewEmpty' => __( 'با این فیلترها ردیفی پیدا نشد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'resumed'     => __( 'خروجی نیمه‌کاره پیدا شد؛ برای ادامه دکمهٔ «ادامه خروجی» را بزنید.', TisaCase_Exporter::TEXT_DOMAIN ),
				'needColumns' => __( 'حداقل یک ستون انتخاب کنید.', TisaCase_Exporter::TEXT_DOMAIN ),
				'noStatus'    => __( 'هیچ وضعیتی انتخاب نشده است؛ با این حالت هیچ سفارشی خروجی نمی‌گیرد. «انتخاب همه» یا «هیچ‌کدام» را بررسی کنید.', TisaCase_Exporter::TEXT_DOMAIN ),
				'exportedShort' => __( 'خروجی: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'skippedShort' => __( 'کنارگذاشته: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'duplicatesShort' => __( 'تکراری حذف‌شده: %1', TisaCase_Exporter::TEXT_DOMAIN ),
				'invalidDates' => __( 'تاریخ «از» بعد از تاریخ «تا» است.', TisaCase_Exporter::TEXT_DOMAIN ),
				'outputHint'  => __( 'خروجی: %1 ردیف در هر فایل، %2 ستون', TisaCase_Exporter::TEXT_DOMAIN ),
				'filteredBy'  => __( 'فیلترها', TisaCase_Exporter::TEXT_DOMAIN ),
				'columnsLabel' => __( 'ستون‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'deepInfo'    => __( 'داده‌ها روی سرور شما می‌مانند و هرگز جایی ارسال نمی‌شوند.', TisaCase_Exporter::TEXT_DOMAIN ),
				/* عیب‌یابی شمارش */
				'diagnose'    => __( 'بررسی شمارش', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseBusy' => __( 'در حال بررسی…', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseError' => __( 'بررسی شمارش ناموفق بود:', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseHeadline' => __( 'عدد نهایی این فیلترها: %1 (واحد: %2)', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseLadder' => __( 'سهم هر فیلتر در کم‌شدن تعداد', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseStatuses' => __( 'شمارش سفارش‌ها به تفکیک وضعیت (کل سایت، بدون فیلتر تاریخ)', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseSelected' => __( 'انتخاب‌شده در فرم', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnosePhones' => __( 'وضعیت شمارهٔ موبایل در همین محدوده', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseTotal' => __( 'کل سفارش‌ها', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseWithPhone' => __( 'دارای شمارهٔ موبایل', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseWithoutPhone' => __( 'بدون شمارهٔ موبایل', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseUniquePhones' => __( 'شمارهٔ یکتا', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseNoChange' => __( 'بدون تغییر', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseStorage' => __( 'منبع داده', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseHint' => __( 'این بررسی فقط شمارش می‌کند و هیچ فایلی نمی‌سازد؛ اگر عدد این‌جا با عدد ووکامرس نمی‌خواند، علتش را دقیقاً نشان می‌دهد.', TisaCase_Exporter::TEXT_DOMAIN ),
				'diagnoseNoStatuses' => __( 'هیچ وضعیتی انتخاب نشده؛ با این حالت خروجی خالی می‌شود.', TisaCase_Exporter::TEXT_DOMAIN ),
				'skippedHint' => __( 'ردیف کنارگذاشته‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
			);
		}
	}
}
