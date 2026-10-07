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

<p class="tcp-lead">هدف را انتخاب کن — از جمله «همهٔ محصولات سایت» — و ابتدا «بررسی قبل از اجرا» را بزن. این کار قیمت را در دیتابیس می‌نویسد (دائمی است، مثل قانون داینامیک فقط نمایش را عوض نمی‌کند). اجرا فقط پس از پیش‌نمایشِ تأییدشده فعال می‌شود و اگر اینترنت قطع شود از همان متغیر ادامه پیدا می‌کند.</p>

<div id="tcp-busy-note" class="tcp-alert tcp-alert--warn" style="display:none"></div>

<section class="tcp-card">
	<div class="tcp-card-head"><span class="tcp-step">۱</span><div><h2>انتخاب محصولات هدف</h2><p>روش انتخاب را مشخص کن؛ قبل از اجرا می‌توانی فهرست را بررسی و ویرایش کنی.</p></div></div>
	<div class="tcp-card-body">
		<div class="tcp-target-modes" role="radiogroup" aria-label="روش انتخاب محصولات">
			<label class="tcp-target-mode tcp-target-mode--catalog">
				<input type="radio" name="tcp_target" value="all">
				<span>همهٔ محصولات سایت</span>
			</label>
			<label class="tcp-target-mode">
				<input type="radio" name="tcp_target" value="category" checked>
				<span>انتخاب بر اساس دسته‌بندی</span>
			</label>
			<label class="tcp-target-mode">
				<input type="radio" name="tcp_target" value="name">
				<span>جستجو در نام محصول</span>
			</label>
			<label class="tcp-target-mode">
				<input type="radio" name="tcp_target" value="direct">
				<span>جستجوی تکی (نام، SKU یا شناسه)</span>
			</label>
			<label class="tcp-target-mode">
				<input type="radio" name="tcp_target" value="sku">
				<span>جستجو بر اساس SKU</span>
			</label>
		</div>

		<div id="tcp-all-box" class="tcp-field" style="display:none">
			<div class="tcp-alert tcp-alert--danger">
				<strong>نوشتن دائمی روی کل فروشگاه.</strong>
				قیمت در دیتابیس ذخیره می‌شود. اگر قانون سراسری داینامیک روشن بماند، افزایش دوباره روی قیمت جدید هم دیده می‌شود — قبل از اجرا آن را خاموش کن.
			</div>
			<ul class="tcp-all-points">
				<li>همهٔ عملیات همین صفحه (قیمت عادی، فروش ویژه، عمده) روی محصولاتِ مطابق فیلترهای پایین اعمال می‌شود.</li>
				<li>محصول متغیر، متغیر‌به‌متغیر از روی قیمت خودِ همان متغیر محاسبه می‌شود؛ نه یک عدد برای کل محصول.</li>
				<li>اگر اینترنت وسط کار قطع شود، از همان متغیر ادامه پیدا می‌کند و درصد دوباره روی متغیرهای انجام‌شده اعمال نمی‌شود. بستن صفحه هم کار را نیمه‌کاره نمی‌گذارد؛ در پس‌زمینه تمام می‌شود. دکمهٔ توقف، اجرا را قطع می‌کند.</li>
			</ul>
				<p class="tcp-muted">فیلترهای وضعیت، نوع، بازهٔ قیمت و استثناها اعمال می‌شوند. محصول گروهی قیمت مستقل ندارد و رد می‌شود. شناسه‌های فروشگاه بزرگ یکجا در حافظه جمع نمی‌شوند؛ پردازش هر مرحله حدود ۸ ثانیه بودجه دارد و اندازهٔ دسته از تنظیمات خوانده می‌شود.</p>
		</div>

		<div id="tcp-cat-box" class="tcp-field">
			<label class="tcp-label" for="tcp-cats">انتخاب یک یا چند دسته‌بندی</label>
			<select id="tcp-cats" class="wc-enhanced-select" multiple="multiple" data-tcp-w="wide" data-placeholder="دسته‌بندی را انتخاب کن…">
				<?php foreach ( $tcp_cats as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( TCP_Admin::cat_label( $cat ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( empty( $tcp_cats ) ) : ?>
				<div class="tcp-alert tcp-alert--warn">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ این لیست خالی است چون دسته‌ای وجود ندارد. از روش جستجوی نام محصول یا جستجوی تکی استفاده کن.</div>
			<?php endif; ?>
			<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-children"><span class="tisa-switch__track" aria-hidden="true"></span><span>زیردسته‌ها هم شامل شوند <span class="tcp-muted">— پیش‌فرض خاموش</span></span></label>
			<div id="tcp-children-warning" class="tcp-alert tcp-alert--danger" style="display:none"><strong>هشدار:</strong> محصولات تمام زیردسته‌های دسته‌های انتخاب‌شده هم وارد عملیات می‌شوند.</div>
		</div>

		<div id="tcp-product-box" class="tcp-field" style="display:none">
			<div id="tcp-name-search-panel" class="tcp-product-source" style="display:none">
				<div class="tcp-source-heading"><h3>جستجو و استخراج بر اساس نام</h3><p>عبارتی از نام محصول را بنویس؛ نتیجه‌ها به فهرست زیر اضافه می‌شوند.</p></div>
				<label class="tcp-label" for="tcp-name-search-term">فیلتر کلمهٔ کلیدی عنوان</label>
				<div class="tcp-name-search-row">
					<input type="search" id="tcp-name-search-term" class="tisa-input" autocomplete="off" placeholder="مثلاً قاب اسپیس">
					<button type="button" class="button button-primary" id="tcp-name-search-button">استخراج محصولات</button>
				</div>
				<div class="tcp-search-feedback">
					<p class="tcp-muted">حداقل ۲ حرف وارد کن. هر بار حداکثر ۱۰۰ نتیجه می‌آید؛ اگر نتیجه‌ها بیشتر باشند، صفحه‌های بعدی را هم اضافه کن.</p>
					<div class="tcp-name-search-actions">
					<span id="tcp-name-search-status" role="status" aria-live="polite"></span>
							<button type="button" class="button" id="tcp-name-load-more" style="display:none">نمایش موارد بعدی</button>
						</div>
					</div>
				</div>

				<div id="tcp-sku-search-panel" class="tcp-product-source" style="display:none">
					<div class="tcp-source-heading"><h3>جستجو و استخراج بر اساس SKU</h3><p>عبارتی از SKU را بنویس (مثلاً پیشوند یا بخشی از شناسه)؛ همهٔ محصولاتی که SKUشان این عبارت را **دارند** به فهرست زیر اضافه می‌شوند.</p></div>
					<label class="tcp-label" for="tcp-sku-search-term">فیلتر متن SKU</label>
					<div class="tcp-name-search-row">
						<input type="search" id="tcp-sku-search-term" class="tisa-input" autocomplete="off" spellcheck="false" dir="ltr" placeholder="مثلاً CH-100">
						<button type="button" class="button button-primary" id="tcp-sku-search-button">استخراج محصولات</button>
					</div>
					<div class="tcp-search-feedback">
						<p class="tcp-muted">جستجوی «شامل‌شونده» است (روی SKU خودِ محصول یا واریشن‌هایش)؛ حداقل ۲ حرف وارد کن. هر بار حداکثر ۱۰۰ نتیجه می‌آید؛ اگر بیشتر باشند، صفحه‌های بعدی را هم اضافه کن.</p>
						<div class="tcp-name-search-actions">
							<span id="tcp-sku-search-status" role="status" aria-live="polite"></span>
							<button type="button" class="button" id="tcp-sku-load-more" style="display:none">نمایش موارد بعدی</button>
						</div>
					</div>
				</div>

				<div id="tcp-direct-search-panel" class="tcp-product-source" style="display:none">
				<div class="tcp-source-heading"><h3>افزودن محصول به‌صورت تکی</h3><p>با انتخاب هر نتیجه، محصول به فهرست انتخاب‌شده‌ها افزوده می‌شود.</p></div>
				<div id="tcp-retail-product-search">
					<label class="tcp-label" for="tcp-products">جستجوی محصول (نام، شناسه یا SKU)</label>
					<select id="tcp-products" class="wc-product-search" multiple="multiple" data-tcp-w="wide" data-limit="100" data-placeholder="نام، شناسه یا SKU محصول را جستجو کن…" data-action="woocommerce_json_search_products"></select>
					<p class="tcp-muted">این کادر تا ۱۰۰ نتیجه نشان می‌دهد؛ برای افزودن دسته‌جمعی از «جستجو در نام» یا «جستجو در SKU» استفاده کن.</p>
				</div>
				<div id="tcp-wholesale-product-search" style="display:none">
					<label class="tcp-label" for="tcp-wholesale-products">جستجوی محصول‌های دارای قیمت عمده</label>
					<select id="tcp-wholesale-products" class="wc-product-search" multiple="multiple" data-tcp-w="wide" data-placeholder="فقط بین محصولات دارای قیمت عمده جستجو کن…" data-action="<?php echo esc_attr( TCP_Settings::AJAX_SEARCH ); ?>"></select>
					<p class="tcp-muted">فقط محصولاتی که خود یا یکی از واریشن‌هایشان قیمت عمده دارد.</p>
				</div>
			</div>

			<div class="tcp-product-selection">
				<div class="tcp-product-selection-header">
					<div>
						<div class="tcp-product-selection-title"><h3>فهرست محصولات انتخاب‌شده</h3><span id="tcp-selected-product-badge" class="tcp-selection-badge">۰ انتخاب‌شده</span></div>
						<p id="tcp-selected-product-count" class="tcp-muted" aria-live="polite">۰ انتخاب‌شده از ۰ محصول</p>
					</div>
					<button type="button" class="button" id="tcp-clear-products" disabled>پاک‌کردن فهرست</button>
				</div>
				<div id="tcp-product-selection-empty" class="tcp-product-empty">هنوز محصولی به فهرست اضافه نشده است؛ از یکی از روش‌های بالا استفاده کن.</div>
				<div id="tcp-product-selection-table-wrap" class="tcp-product-table-scroll" style="display:none">
					<table id="tcp-selected-products" class="tcp-product-table">
						<thead>
							<tr>
								<th class="tcp-product-check"><input type="checkbox" id="tcp-product-select-all" aria-label="انتخاب همهٔ محصولات"></th>
								<th>محصول</th>
								<th>SKU / شناسه</th>
								<th>دسته‌بندی</th>
								<th>نوع محصول</th>
								<th>حذف</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>


		</div>

		<details id="tcp-exceptions" class="tcp-exceptions">
			<summary>
				<span><strong>استثناها (اختیاری)</strong><small>محصول یا دسته‌ای را انتخاب کن تا از این اجرا کنار گذاشته شود.</small></span>
				<span id="tcp-exclusion-badge" class="tcp-selection-badge">بدون استثنا</span>
			</summary>
			<div class="tcp-exceptions-body">
				<p class="tcp-muted">استثناها روی همهٔ روش‌های انتخاب (همهٔ فروشگاه، دسته یا فهرست دستی) اولویت دارند. اگر محصول متغیر را انتخاب کنی، تمام واریشن‌های همان محصول هم مستثنا می‌شوند. پیش‌نمایش تعداد نهایی را بعد از اعمال استثناها نشان می‌دهد.</p>
				<div class="tcp-grid-2 tcp-exclusion-grid">
					<div>
						<label class="tcp-label" for="tcp-excluded-products">محصولات مستثنا</label>
						<select id="tcp-excluded-products" class="tcp-product-search" multiple="multiple" data-tcp-w="wide" data-placeholder="نام، SKU یا شناسهٔ محصول را جستجو کن…"></select>
						<p class="tcp-muted">جستجو در کل سایت (نام، توضیح، SKU خودِ محصول یا واریشن‌ها و شناسه) انجام می‌شود؛ هر بار ۱۰۰ نتیجه می‌آید و با اسکرول، موارد بعدی بارگذاری می‌شوند. حداکثر <?php echo esc_html( TCP_Ops::MAX_EXCLUDED_PRODUCTS ); ?> محصول.</p>
					</div>
					<div>
						<label class="tcp-label" for="tcp-excluded-cats">دسته‌های مستثنا</label>
						<select id="tcp-excluded-cats" class="wc-enhanced-select" multiple="multiple" data-tcp-w="wide" data-placeholder="دسته‌بندی را انتخاب کن…">
							<?php foreach ( $tcp_cats as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( TCP_Admin::cat_label( $cat ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="tcp-muted">حداکثر <?php echo esc_html( TCP_Ops::MAX_EXCLUDED_CATEGORIES ); ?> دسته.</p>
					</div>
				</div>
				<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-exclude-children" checked><span class="tisa-switch__track" aria-hidden="true"></span><span>زیردسته‌های دسته‌های مستثنا هم کنار گذاشته شوند <span class="tcp-muted">— پیش‌فرض روشن</span></span></label>
			</div>
		</details>
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
			<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-round" checked><span class="tisa-switch__track" aria-hidden="true"></span><span>رند به ۸ <span class="tcp-muted">— به نزدیک‌ترین <?php echo esc_html( TCP_Round::describe() ); ?>، نه همیشه پایین. ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰ و ۶۱۳٬۱۰۰ ← ۶۱۸٬۰۰۰. با گام ۱۰: ۳۷۷ ← ۳۷۸ نه ۳۶۸.</span></span></label>
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
