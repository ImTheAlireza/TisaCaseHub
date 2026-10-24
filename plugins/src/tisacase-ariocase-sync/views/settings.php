<?php
defined( 'ABSPATH' ) || exit;
$field = function( $key ) use ( $settings ) {
	return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
};
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcas-settings-form">
	<input type="hidden" name="action" value="tcas_save_settings">
	<?php wp_nonce_field( 'tcas_save_settings' ); ?>
	<section class="tcas-card">
		<div class="tcas-card-head"><div><span class="tcas-step">۱</span><h2>رفتار سینک</h2></div></div>
		<div class="tcas-settings-grid">
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[enabled]" <?php checked( $settings['enabled'], 'yes' ); ?>><span><strong>سینک فعال باشد</strong><small>با خاموش‌کردن، نگاشت‌ها حفظ و درخواست API متوقف می‌شود.</small></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[persist_stock]" <?php checked( $settings['persist_stock'], 'yes' ); ?>><span><strong>موجودی معتبر در ووکامرس هم ذخیره شود</strong><small>فقط تغییر واقعی نوشته می‌شود؛ خطای API هیچ نوشتنی ندارد.</small></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[checkout_guard]" <?php checked( $settings['checkout_guard'], 'yes' ); ?>><span><strong>نگهبان سبد و تسویه‌حساب</strong><small>موجودی تازه و مجموع تعداد هر قلم در سبد کنترل می‌شود.</small></span></label>
			<label><span class="tcas-label">دامنهٔ خواندن زنده</span><select name="tcas_settings[live_scope]"><option value="all" <?php selected( $settings['live_scope'], 'all' ); ?>>همهٔ درخواست‌های محصول در فروشگاه</option><option value="purchase" <?php selected( $settings['live_scope'], 'purchase' ); ?>>فقط صفحهٔ محصول، سبد، تسویه و AJAX</option></select><small class="tcas-help">در هر Request فقط یک بار برای هر کد API خوانده می‌شود. برای آرشیوهای شلوغ، گزینهٔ دوم سبک‌تر است.</small></label>
		</div>
	</section>

	<section class="tcas-card">
		<div class="tcas-card-head"><div><span class="tcas-step">۲</span><h2>آدرس و احراز هویت</h2></div></div>
		<div class="tcas-form-grid">
			<label class="tcas-span-2"><span class="tcas-label">آدرس پایهٔ API *</span><input type="url" name="tcas_settings[base_url]" value="<?php echo esc_attr( $field( 'base_url' ) ); ?>" dir="ltr" placeholder="https://ariocase.com/wp-json/wc/v3"><small class="tcas-help">بدون Query String. استفاده از HTTPS الزامی است.</small></label>
			<label><span class="tcas-label">Endpoint محصول *</span><input type="text" name="tcas_settings[product_endpoint]" value="<?php echo esc_attr( $field( 'product_endpoint' ) ); ?>" dir="ltr" placeholder="products?status=publish&amp;per_page=100&amp;sku={code}"><small class="tcas-help">پیش‌فرض ArioCase با SKU فیلتر می‌کند؛ {code} به‌صورت URL-encoded جایگزین می‌شود.</small></label>
			<label><span class="tcas-label">Endpoint موجودی (اختیاری)</span><input type="text" name="tcas_settings[stock_endpoint]" value="<?php echo esc_attr( $field( 'stock_endpoint' ) ); ?>" dir="ltr" placeholder="stock/{code}"><small class="tcas-help">خالی = همان Endpoint محصول.</small></label>
			<label><span class="tcas-label">نام پارامتر کد</span><input type="text" name="tcas_settings[code_parameter]" value="<?php echo esc_attr( $field( 'code_parameter' ) ); ?>" dir="ltr" placeholder="sku"><small class="tcas-help">وقتی Endpoint شامل {code} نباشد، به Query اضافه می‌شود.</small></label>
			<label><span class="tcas-label">Timeout</span><input type="number" min="2" max="20" name="tcas_settings[timeout]" value="<?php echo (int) $settings['timeout']; ?>"><small class="tcas-help">ثانیه؛ بین ۲ تا ۲۰.</small></label>
			<label><span class="tcas-label">روش احراز هویت</span><select name="tcas_settings[auth_type]"><option value="bearer" <?php selected( $settings['auth_type'], 'bearer' ); ?>>Bearer Token در Authorization</option><option value="header" <?php selected( $settings['auth_type'], 'header' ); ?>>کلید در Header سفارشی</option><option value="query" <?php selected( $settings['auth_type'], 'query' ); ?>>کلید در Query String</option><option value="none" <?php selected( $settings['auth_type'], 'none' ); ?>>بدون کلید</option></select></label>
			<label><span class="tcas-label">نام Header سفارشی</span><input type="text" name="tcas_settings[auth_header]" value="<?php echo esc_attr( $field( 'auth_header' ) ); ?>" dir="ltr" placeholder="X-API-Key"></label>
			<label><span class="tcas-label">نام پارامتر کلید در Query</span><input type="text" name="tcas_settings[auth_query]" value="<?php echo esc_attr( $field( 'auth_query' ) ); ?>" dir="ltr" placeholder="api_key"></label>
			<label class="tcas-span-2"><span class="tcas-label">API Key</span><input type="password" name="tcas_settings[api_key]" value="" autocomplete="new-password" dir="ltr" placeholder="برای حفظ کلید فعلی خالی بگذارید"><small class="tcas-help">مقدار ذخیره‌شده هرگز نمایش داده یا لاگ نمی‌شود. برای امنیت بیشتر می‌توانید ثابت <code>TISACASE_ARIOCASE_API_KEY</code> را در wp-config.php بگذارید.</small></label>
			<?php if ( defined( 'TISACASE_ARIOCASE_API_KEY' ) ) : ?><p class="tcas-notice-box">کلید از ثابت wp-config.php خوانده می‌شود و بر فیلد دیتابیس اولویت دارد.</p><?php endif; ?>
			<label class="tcas-check-line"><input type="checkbox" name="tcas_settings[clear_api_key]" value="1"> کلید ذخیره‌شده در دیتابیس پاک شود</label>
		</div>
	</section>

	<details class="tcas-card tcas-advanced">
		<summary><strong>۳) تنظیمات پیشرفتهٔ ساختار JSON</strong><span>فقط اگر تشخیص خودکار پاسخ کافی نبود</span></summary>
		<div class="tcas-form-grid">
			<label><span class="tcas-label">مسیر یک محصول</span><input type="text" name="tcas_settings[item_path]" value="<?php echo esc_attr( $field( 'item_path' ) ); ?>" dir="ltr" placeholder="data.product"></label>
			<label><span class="tcas-label">مسیر فهرست محصولات</span><input type="text" name="tcas_settings[items_path]" value="<?php echo esc_attr( $field( 'items_path' ) ); ?>" dir="ltr" placeholder="data.products"></label>
			<label><span class="tcas-label">فیلد کد</span><input type="text" name="tcas_settings[field_code]" value="<?php echo esc_attr( $field( 'field_code' ) ); ?>" dir="ltr" placeholder="sku"></label>
			<label><span class="tcas-label">فیلد نام</span><input type="text" name="tcas_settings[field_name]" value="<?php echo esc_attr( $field( 'field_name' ) ); ?>" dir="ltr" placeholder="name"></label>
			<label><span class="tcas-label">فیلد موجودی عددی</span><input type="text" name="tcas_settings[field_stock]" value="<?php echo esc_attr( $field( 'field_stock' ) ); ?>" dir="ltr" placeholder="inventory.quantity"></label>
			<label><span class="tcas-label">فیلد وضعیت موجودی</span><input type="text" name="tcas_settings[field_stock_status]" value="<?php echo esc_attr( $field( 'field_stock_status' ) ); ?>" dir="ltr" placeholder="stock_status"></label>
			<label><span class="tcas-label">فیلد تنوع‌ها</span><input type="text" name="tcas_settings[field_variations]" value="<?php echo esc_attr( $field( 'field_variations' ) ); ?>" dir="ltr" placeholder="variations"></label>
			<label><span class="tcas-label">فیلد ویژگی‌ها</span><input type="text" name="tcas_settings[field_attributes]" value="<?php echo esc_attr( $field( 'field_attributes' ) ); ?>" dir="ltr" placeholder="attributes"></label>
			<label><span class="tcas-label">فیلد تصاویر</span><input type="text" name="tcas_settings[field_images]" value="<?php echo esc_attr( $field( 'field_images' ) ); ?>" dir="ltr" placeholder="images"></label>
			<label><span class="tcas-label">فیلد قیمت</span><input type="text" name="tcas_settings[field_price]" value="<?php echo esc_attr( $field( 'field_price' ) ); ?>" dir="ltr" placeholder="regular_price"></label>
			<label class="tcas-span-2"><span class="tcas-label">فیلد توضیحات</span><input type="text" name="tcas_settings[field_description]" value="<?php echo esc_attr( $field( 'field_description' ) ); ?>" dir="ltr" placeholder="description"></label>
		</div>
		<p class="tcas-help">مسیرها با نقطه جدا می‌شوند؛ مثلاً <code dir="ltr">data.product.stock_quantity</code>. خالی‌گذاشتن هر فیلد، تشخیص خودکار نام‌های متداول را فعال نگه می‌دارد.</p>
	</details>

	<div class="tcas-sticky-actions"><button type="submit" class="button button-primary tcas-btn-primary">ذخیرهٔ تنظیمات</button></div>
</form>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">✓</span><h2>تست اتصال با یک کد واقعی</h2></div></div>
	<p class="tcas-help">تست فقط خواندنی است و هیچ محصول یا موجودی تغییر نمی‌دهد. تنظیمات را اول ذخیره کنید.</p>
	<div class="tcas-inline-form"><label><span>کد محصول در ArioCase</span><input id="tcas-test-code" type="text" dir="ltr" placeholder="CH1001"></label><button type="button" id="tcas-test-connection" class="button">تست اتصال</button></div>
	<div id="tcas-test-result" class="tcas-test-result" role="status" aria-live="polite"></div>
</section>
