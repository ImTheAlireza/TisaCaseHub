<?php
/**
 * تب «تغییر گروهی قیمت».
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! TCP_Settings::wc_active() ) {
	return;
}

$tcp_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
if ( is_wp_error( $tcp_cats ) ) {
	$tcp_cats = array();
}
$tcp_currency = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';
?>

<p class="tcp-lead">هدف را انتخاب کن و ابتدا «بررسی قبل از اجرا» را بزن؛ اجرا فقط پس از پیش‌نمایشِ تأییدشده فعال می‌شود و همهٔ تغییرات برای بازگردانی ثبت می‌گردد.</p>

<div id="tcp-busy-note" class="tcp-alert tcp-alert--warn" style="display:none"></div>

<section class="tcp-card">
	<div class="tcp-card-head"><span class="tcp-step">۱</span><div><h2>محصولات هدف</h2></div></div>
	<div class="tcp-card-body">
		<div class="tcp-seg">
			<label class="tcp-radio"><input type="radio" name="tcp_target" value="category" checked> دسته‌بندی</label>
			<label class="tcp-radio"><input type="radio" name="tcp_target" value="products"> انتخاب مستقیم محصول</label>
		</div>

		<div id="tcp-cat-box" class="tcp-field">
			<label class="tcp-label" for="tcp-cats">یک یا چند دسته‌بندی</label>
			<select id="tcp-cats" class="wc-enhanced-select" multiple="multiple" data-tcp-w="wide" data-placeholder="دسته‌بندی را انتخاب کن…">
				<?php foreach ( $tcp_cats as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( TCP_Admin::cat_label( $cat ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( empty( $tcp_cats ) ) : ?>
				<div class="tcp-alert tcp-alert--warn">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ این لیست خالی است چون دسته‌ای وجود ندارد. اول از «محصولات ← دسته‌ها» دسته بساز یا از گزینهٔ «انتخاب مستقیم محصول» استفاده کن.</div>
			<?php endif; ?>
			<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-children"><span class="tisa-switch__track" aria-hidden="true"></span><span>زیردسته‌ها هم شامل شوند <span class="tcp-muted">— پیش‌فرض خاموش</span></span></label>
			<div id="tcp-children-warning" class="tcp-alert tcp-alert--danger" style="display:none"><strong>هشدار:</strong> محصولات تمام زیردسته‌های دسته‌های انتخاب‌شده هم وارد عملیات می‌شوند.</div>
		</div>

		<div id="tcp-product-box" class="tcp-field" style="display:none">
			<div id="tcp-bulk-name-search" class="tcp-bulk-name-search">
				<label class="tcp-label" for="tcp-name-search-term">جستجوی چند محصول با بخشی از نام</label>
				<div class="tcp-name-search-row">
					<input type="search" id="tcp-name-search-term" class="regular-text" autocomplete="off" placeholder="مثلاً قاب اسپیس">
					<button type="button" class="button button-secondary" id="tcp-name-search-button">جستجو</button>
				</div>
				<p class="tcp-muted">همهٔ محصول‌هایی که نامشان این عبارت را دارد پیدا می‌شوند و ابتدا انتخاب هستند؛ برای کنار گذاشتن بعضی محصول‌ها تیکشان را بردار. اگر نتیجه‌ها از ۱۰۰ مورد بیشتر باشند، «نمایش موارد بعدی» را هم بزن.</p>
				<div id="tcp-name-search-status" class="tcp-name-search-status" role="status" aria-live="polite"></div>
				<div id="tcp-name-search-results" style="display:none">
					<div class="tcp-table-scroll tcp-name-search-scroll">
						<table class="widefat striped tcp-name-search-table">
							<thead><tr><th>انتخاب</th><th>نام محصول</th><th>شناسه</th></tr></thead>
							<tbody></tbody>
						</table>
					</div>
					<div class="tcp-name-search-actions">
						<button type="button" class="button" id="tcp-name-select-page">انتخاب همهٔ موارد نمایش‌داده‌شده</button>
						<button type="button" class="button" id="tcp-name-deselect-page">لغو انتخاب موارد نمایش‌داده‌شده</button>
						<button type="button" class="button button-secondary" id="tcp-name-load-more" style="display:none">نمایش موارد بعدی</button>
						<span id="tcp-name-page-count" class="tcp-muted"></span>
					</div>
				</div>
				<p id="tcp-selected-product-count" class="tcp-muted">انتخاب نهایی: ۰ محصول</p>
			</div>

			<div id="tcp-retail-product-search">
				<label class="tcp-label" for="tcp-products">یا انتخاب دستی محصول‌ها (با نام، شناسه یا SKU)</label>
				<select id="tcp-products" class="wc-product-search" multiple="multiple" data-tcp-w="wide" data-placeholder="نام، شناسه یا SKU محصول را جستجو کن…" data-action="woocommerce_json_search_products"></select>
			</div>
			<div id="tcp-wholesale-product-search" style="display:none">
				<label class="tcp-label" for="tcp-wholesale-products">یا انتخاب دستی محصول‌های دارای قیمت عمده</label>
				<select id="tcp-wholesale-products" class="wc-product-search" multiple="multiple" data-tcp-w="wide" data-placeholder="فقط بین محصولات دارای قیمت عمده جستجو کن…" data-action="<?php echo esc_attr( TCP_Settings::AJAX_SEARCH ); ?>"></select>
				<p class="tcp-muted">فقط محصولاتی که خود یا یکی از واریشن‌هایشان قیمت عمده دارد.</p>
			</div>
		</div>
	</div>
</section>

<section class="tcp-card">
	<div class="tcp-card-head"><span class="tcp-step">۲</span><div><h2>نوع تغییر قیمت</h2></div></div>
	<div class="tcp-card-body">
		<select id="tcp-op" class="tcp-select-op">
			<?php
			$tcp_groups = array( 'regular' => 'قیمت عادی', 'sale' => 'فروش ویژه', 'wholesale' => 'قیمت عمده' );
			foreach ( $tcp_groups as $g => $g_label ) :
				?>
				<optgroup label="<?php echo esc_attr( $g_label ); ?>">
					<?php foreach ( TCP_Ops::ops() as $slug => $meta ) : ?>
						<?php if ( $meta[2] === $g ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $meta[0] ); ?></option>
						<?php endif; ?>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>

		<div id="tcp-value-box" class="tcp-field">
			<label class="tcp-label" for="tcp-value" id="tcp-value-label">مقدار درصد</label>
			<div class="tcp-row">
				<input type="text" id="tcp-value" inputmode="decimal" autocomplete="off" placeholder="مثلاً 10" class="tcp-value">
				<span id="tcp-unit" class="tcp-unit">%</span>
			</div>
		</div>

		<div id="tcp-round-box" class="tcp-field tcp-round-box">
			<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-round" checked><span class="tisa-switch__track" aria-hidden="true"></span><span>رند به ۸ <span class="tcp-muted">— قیمت نهایی به پایین روی <?php echo esc_html( TCP_Round::describe() ); ?> می‌رود؛ مثلاً ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰</span></span></label>
			<div id="tcp-round-jitter-row" style="display:none">
				<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-round-jitter"><span class="tisa-switch__track" aria-hidden="true"></span><span>تخفیف متغیر <span class="tcp-muted">— به‌جای دقیقاً X٪، هر آیتم درصدی در بازهٔ X±<?php echo esc_html( TCP_Round::jitter() ); ?>٪ می‌گیرد که قیمتش دقیقاً روی ۸ بیفتد. دامنه در «تنظیمات».</span></span></label>
			</div>
		</div>

		<p class="tcp-muted">
			مبلغ ثابت را با واحد قیمت فروشگاه وارد کن<?php echo $tcp_currency ? ' (' . esc_html( $tcp_currency ) . ')' : ''; ?>.
			فروش ویژهٔ مساوی یا بیشتر از قیمت عادی ذخیره نمی‌شود. عملیات عمده فقط روی آیتم‌هایی که از قبل قیمت عمده دارند اثر می‌گذارد.
		</p>
	</div>
</section>

<section class="tcp-card">
	<div class="tcp-card-head"><span class="tcp-step">۳</span><div><h2>فیلترهای تکمیلی</h2><p>اختیاری — روی محصول مادر اعمال می‌شوند.</p></div></div>
	<div class="tcp-card-body">
		<div class="tcp-grid-4">
			<div>
				<label class="tcp-label" for="tcp-filter-types">نوع محصول</label>
				<select id="tcp-filter-types" class="wc-enhanced-select" multiple="multiple" data-tcp-w="full">
					<?php foreach ( TCP_Admin::product_types() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label class="tcp-label" for="tcp-filter-statuses">وضعیت انتشار</label>
				<select id="tcp-filter-statuses" class="wc-enhanced-select" multiple="multiple" data-tcp-w="full">
					<?php foreach ( TCP_Admin::post_statuses() as $k => $v ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( 'future' !== $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label class="tcp-label" for="tcp-price-min">قیمت عادی فعلی از</label>
				<input type="text" id="tcp-price-min" inputmode="decimal" class="tcp-price-input" placeholder="مثلاً 100000">
			</div>
			<div>
				<label class="tcp-label" for="tcp-price-max">تا</label>
				<input type="text" id="tcp-price-max" inputmode="decimal" class="tcp-price-input" placeholder="مثلاً 5000000">
			</div>
		</div>
		<div id="tcp-sale-only-row" class="tcp-field" style="display:none"><label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-filter-only-sale"><span class="tisa-switch__track" aria-hidden="true"></span><span>فقط محصولاتی که اکنون فروش ویژه دارند</span></label></div>
		<div id="tcp-wholesale-only-row" class="tcp-field" style="display:none"><label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-filter-only-wholesale"><span class="tisa-switch__track" aria-hidden="true"></span><span>فقط محصولاتی که اکنون قیمت عمده دارند</span></label></div>
	</div>
</section>

<section class="tcp-card">
	<div class="tcp-card-head"><span class="tcp-step">۴</span><div><h2>بررسی و اجرا</h2><p>تا پیش‌نمایش تأیید نشود، اجرا فعال نمی‌شود.</p></div></div>
	<div class="tcp-card-body">
		<div class="tcp-actions">
			<button type="button" class="button button-secondary button-large" id="tcp-preview">بررسی قبل از اجرا</button>
			<button type="button" class="button button-primary button-large" id="tcp-start" disabled>اجرای نهایی</button>
			<button type="button" class="button button-large" id="tcp-schedule" disabled title="به صف WP-Cron اضافه می‌شود">ثبت در صف زمان‌بندی</button>
			<button type="button" class="button button-large" id="tcp-stop" style="display:none">توقف بعد از این مرحله</button>
		</div>

		<div id="tcp-preview-box" style="display:none">
			<h3>نتیجهٔ بررسی</h3>
			<div id="tcp-preview-summary"></div>
			<div id="tcp-sample-box">
				<h3>نمونهٔ واقعی «قبل → بعد»</h3>
				<table class="widefat striped tcp-sample-table">
					<thead><tr><th>#</th><th>نام محصول</th><th>نوع</th><th>قیمت فعلی</th><th>قیمت جدید</th><th>وضعیت</th></tr></thead>
					<tbody></tbody>
				</table>
			</div>
		</div>

		<div id="tcp-progress" style="display:none">
			<div class="tcp-bar"><div id="tcp-bar"></div></div>
			<p id="tcp-status" class="tcp-status"></p>
			<p class="tcp-counters">
				بررسی‌شده: <strong id="tcp-parent-count">0</strong> ·
				تغییر: <strong id="tcp-updated-count">0</strong> ·
				ردشده: <strong id="tcp-skipped-count">0</strong> ·
				خطا: <strong id="tcp-error-count">0</strong>
			</p>
			<div id="tcp-errors" class="tcp-errors" style="display:none"></div>
		</div>
	</div>
</section>
