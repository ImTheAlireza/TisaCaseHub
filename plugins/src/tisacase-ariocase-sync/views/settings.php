<?php
defined( 'ABSPATH' ) || exit;
$field = function( $key ) use ( $settings ) {
	return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
};
$provider = TCAS_Partners::get( $partner_id );
if ( 'ariocase' !== $partner_id ) :
	$provider_fields = $provider ? $provider->settings_fields() : array();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcas-settings-form">
		<input type="hidden" name="action" value="tcas_save_settings"><input type="hidden" name="partner_id" value="<?php echo esc_attr( $partner_id ); ?>">
		<?php wp_nonce_field( 'tcas_save_settings' ); ?>
		<section class="tcas-card">
			<div class="tcas-card-head"><div><span class="tcas-step">⚙</span><h2>اتصال <?php echo esc_html( $partner_label ); ?></h2></div></div>
			<?php if ( ! $provider_fields ) : ?><p>برای این همکار، فرم تنظیمات اختصاصی در دسترس نیست؛ provider باید تنظیمات خود را با رابط settings_fields ارائه کند.</p><?php else : ?>
				<div class="tcas-form-grid">
					<?php foreach ( $provider_fields as $key => $definition ) :
						$key = sanitize_key( $key );
						$definition = is_array( $definition ) ? $definition : array();
						$type = isset( $definition['type'] ) ? sanitize_key( $definition['type'] ) : 'text';
						$value = isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
						$label = isset( $definition['label'] ) ? (string) $definition['label'] : $key;
						$placeholder = isset( $definition['placeholder'] ) ? (string) $definition['placeholder'] : '';
						$required = ! empty( $definition['required'] ) ? ' required' : '';
						?>
						<label class="<?php echo ! empty( $definition['wide'] ) ? 'tcas-span-2' : ''; ?>"><span class="tcas-label"><?php echo esc_html( $label ); ?></span>
						<?php if ( 'select' === $type ) : ?><select name="tcas_settings[<?php echo esc_attr( $key ); ?>]"><?php foreach ( (array) ( $definition['options'] ?? array() ) as $option_value => $option_label ) : ?><option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option><?php endforeach; ?></select>
						<?php elseif ( 'checkbox' === $type ) : ?><input type="checkbox" name="tcas_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $value, '1' ); ?>>
						<?php else : ?><input type="<?php echo esc_attr( in_array( $type, array( 'url', 'password', 'number', 'email' ), true ) ? $type : 'text' ); ?>" name="tcas_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo 'password' === $type ? '' : esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" <?php echo 'password' === $type ? 'autocomplete="new-password"' : ''; ?><?php echo $required; ?>><?php endif; ?>
						<?php if ( ! empty( $definition['description'] ) ) : ?><small><?php echo esc_html( $definition['description'] ); ?></small><?php endif; ?></label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<section class="tcas-card"><div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>رفتار موجودی مشترک</h2></div></div><div class="tcas-settings-grid">
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[enabled]" <?php checked( $settings['enabled'], 'yes' ); ?>><span><strong>همگام‌سازی فعال</strong></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[persist_stock]" <?php checked( $settings['persist_stock'], 'yes' ); ?>><span><strong>ذخیره در ووکامرس</strong></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[checkout_guard]" <?php checked( $settings['checkout_guard'], 'yes' ); ?>><span><strong>کنترل سبد و تسویه</strong></span></label>
			<label><span class="tcas-label">منبع موجودی فروشگاه</span><select name="tcas_settings[live_scope]"><option value="purchase" <?php selected( $settings['live_scope'], 'purchase' ); ?>>کش محلی + تازه‌سازی پس‌زمینه؛ کنترل زنده هنگام خرید</option><option value="all" <?php selected( $settings['live_scope'], 'all' ); ?>>درخواست API برای هر Getter</option></select></label>
		</div></section>
		<div class="tcas-sticky-actions"><button type="submit" class="button button-primary tcas-btn-primary">ذخیره تنظیمات <?php echo esc_html( $partner_label ); ?></button></div>
	</form>
	<section class="tcas-card"><div class="tcas-card-head"><div><span class="tcas-step">✓</span><h2>تست اتصال</h2></div><span class="tcas-tag">فقط خواندنی</span></div><div class="tcas-inline-form"><label class="tcas-grow"><span>شناسه یا مرجع محصول در <?php echo esc_html( $partner_label ); ?></span><input id="tcas-test-code" type="text" dir="ltr" placeholder="SKU / ID / URL"></label><button type="button" id="tcas-test-connection" class="button">تست</button></div><div id="tcas-test-result" class="tcas-test-result" role="status" aria-live="polite"></div></section>
	<?php return; ?>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcas-settings-form">
	<input type="hidden" name="action" value="tcas_save_settings"><input type="hidden" name="partner_id" value="<?php echo esc_attr( $partner_id ); ?>">
	<?php wp_nonce_field( 'tcas_save_settings' ); ?>
	<section class="tcas-card">
		<div class="tcas-card-head"><div><span class="tcas-step">⚙</span><h2>اتصال <?php echo esc_html( $partner_label ); ?></h2></div><?php if ( TCAS_Core::api_key( $settings ) ) : ?><span class="tcas-tag">کلید فعال</span><?php endif; ?></div>
		<div class="tcas-form-grid">
			<label class="tcas-span-2"><span class="tcas-label">آدرس پایهٔ API</span><input type="url" name="tcas_settings[base_url]" value="<?php echo esc_attr( $field( 'base_url' ) ); ?>" dir="ltr" placeholder="https://ariocase.com/wp-json/wc/v3" required></label>
			<label class="tcas-span-2"><span class="tcas-label">Endpoint محصول</span><input type="text" name="tcas_settings[product_endpoint]" value="<?php echo esc_attr( $field( 'product_endpoint' ) ); ?>" dir="ltr" placeholder="products?status=publish&amp;per_page=100&amp;sku={code}" required></label>
			<label><span class="tcas-label">احراز هویت</span><select name="tcas_settings[auth_type]"><option value="bearer" <?php selected( $settings['auth_type'], 'bearer' ); ?>>Bearer / JWT</option><option value="header" <?php selected( $settings['auth_type'], 'header' ); ?>>Header سفارشی</option><option value="query" <?php selected( $settings['auth_type'], 'query' ); ?>>Query String</option><option value="none" <?php selected( $settings['auth_type'], 'none' ); ?>>بدون کلید</option></select></label>
			<label><span class="tcas-label">Timeout <small>(ثانیه)</small></span><input type="number" min="2" max="20" name="tcas_settings[timeout]" value="<?php echo (int) $settings['timeout']; ?>"></label>
			<label class="tcas-span-2"><span class="tcas-label">API Key / JWT</span><input type="password" name="tcas_settings[api_key]" value="" autocomplete="new-password" dir="ltr" placeholder="••••••••"></label>
			<?php if ( ! empty( $settings['api_key'] ) ) : ?><label class="tcas-check-line"><input type="checkbox" name="tcas_settings[clear_api_key]" value="1"> پاک‌کردن کلید ذخیره‌شده</label><?php endif; ?>
		</div>
	</section>

	<section class="tcas-card">
		<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>موجودی</h2></div></div>
		<div class="tcas-settings-grid">
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[enabled]" <?php checked( $settings['enabled'], 'yes' ); ?>><span><strong>سینک فعال</strong></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[persist_stock]" <?php checked( $settings['persist_stock'], 'yes' ); ?>><span><strong>ذخیره در ووکامرس</strong></span></label>
			<label class="tcas-switch-row"><input type="checkbox" name="tcas_settings[checkout_guard]" <?php checked( $settings['checkout_guard'], 'yes' ); ?>><span><strong>کنترل سبد و تسویه</strong></span></label>
			<label><span class="tcas-label">منبع موجودی فروشگاه</span><select name="tcas_settings[live_scope]"><option value="purchase" <?php selected( $settings['live_scope'], 'purchase' ); ?>>پیشنهادی: کش محلی + تازه‌سازی پس‌زمینه؛ کنترل زنده هنگام خرید</option><option value="all" <?php selected( $settings['live_scope'], 'all' ); ?>>همهٔ Getterها: درخواست هم‌زمان API برای هر محصول</option></select></label>
		</div>
	</section>

	<details class="tcas-card tcas-advanced">
		<summary><strong>تنظیمات پیشرفته</strong><span>Endpoints و فیلدهای JSON</span></summary>
		<div class="tcas-form-grid">
			<label class="tcas-span-2"><span class="tcas-label">Endpoint لینک / Slug</span><input type="text" name="tcas_settings[slug_endpoint]" value="<?php echo esc_attr( $field( 'slug_endpoint' ) ); ?>" dir="ltr" placeholder="products?status=publish&amp;per_page=100&amp;slug={slug}"></label>
			<label><span class="tcas-label">Endpoint موجودی</span><input type="text" name="tcas_settings[stock_endpoint]" value="<?php echo esc_attr( $field( 'stock_endpoint' ) ); ?>" dir="ltr" placeholder="خالی = Endpoint محصول"></label>
			<label><span class="tcas-label">پارامتر SKU</span><input type="text" name="tcas_settings[code_parameter]" value="<?php echo esc_attr( $field( 'code_parameter' ) ); ?>" dir="ltr" placeholder="sku"></label>
			<label><span class="tcas-label">Header سفارشی</span><input type="text" name="tcas_settings[auth_header]" value="<?php echo esc_attr( $field( 'auth_header' ) ); ?>" dir="ltr" placeholder="X-API-Key"></label>
			<label><span class="tcas-label">پارامتر کلید Query</span><input type="text" name="tcas_settings[auth_query]" value="<?php echo esc_attr( $field( 'auth_query' ) ); ?>" dir="ltr" placeholder="api_key"></label>
			<label><span class="tcas-label">مسیر یک محصول</span><input type="text" name="tcas_settings[item_path]" value="<?php echo esc_attr( $field( 'item_path' ) ); ?>" dir="ltr" placeholder="data.product"></label>
			<label><span class="tcas-label">مسیر فهرست محصولات</span><input type="text" name="tcas_settings[items_path]" value="<?php echo esc_attr( $field( 'items_path' ) ); ?>" dir="ltr" placeholder="data.products"></label>
			<label><span class="tcas-label">فیلد کد</span><input type="text" name="tcas_settings[field_code]" value="<?php echo esc_attr( $field( 'field_code' ) ); ?>" dir="ltr" placeholder="sku"></label>
			<label><span class="tcas-label">فیلد نام</span><input type="text" name="tcas_settings[field_name]" value="<?php echo esc_attr( $field( 'field_name' ) ); ?>" dir="ltr" placeholder="name"></label>
			<label><span class="tcas-label">موجودی عددی</span><input type="text" name="tcas_settings[field_stock]" value="<?php echo esc_attr( $field( 'field_stock' ) ); ?>" dir="ltr" placeholder="stock_quantity"></label>
			<label><span class="tcas-label">وضعیت موجودی</span><input type="text" name="tcas_settings[field_stock_status]" value="<?php echo esc_attr( $field( 'field_stock_status' ) ); ?>" dir="ltr" placeholder="stock_status"></label>
			<label><span class="tcas-label">فیلد تنوع‌ها</span><input type="text" name="tcas_settings[field_variations]" value="<?php echo esc_attr( $field( 'field_variations' ) ); ?>" dir="ltr" placeholder="variations"></label>
			<label><span class="tcas-label">فیلد ویژگی‌ها</span><input type="text" name="tcas_settings[field_attributes]" value="<?php echo esc_attr( $field( 'field_attributes' ) ); ?>" dir="ltr" placeholder="attributes"></label>
			<label><span class="tcas-label">فیلد تصاویر</span><input type="text" name="tcas_settings[field_images]" value="<?php echo esc_attr( $field( 'field_images' ) ); ?>" dir="ltr" placeholder="images"></label>
			<label><span class="tcas-label">فیلد قیمت</span><input type="text" name="tcas_settings[field_price]" value="<?php echo esc_attr( $field( 'field_price' ) ); ?>" dir="ltr" placeholder="regular_price"></label>
			<label class="tcas-span-2"><span class="tcas-label">فیلد توضیحات</span><input type="text" name="tcas_settings[field_description]" value="<?php echo esc_attr( $field( 'field_description' ) ); ?>" dir="ltr" placeholder="description"></label>
		</div>
	</details>

	<div class="tcas-sticky-actions"><button type="submit" class="button button-primary tcas-btn-primary">ذخیره تنظیمات <?php echo esc_html( $partner_label ); ?></button></div>
</form>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">✓</span><h2>تست اتصال</h2></div><span class="tcas-tag">فقط خواندنی</span></div>
	<div class="tcas-inline-form"><label class="tcas-grow"><span>لینک، SKU یا ID در <?php echo esc_html( $partner_label ); ?></span><input id="tcas-test-code" type="text" dir="ltr" placeholder="https://ariocase.com/product/case-slug/"></label><button type="button" id="tcas-test-connection" class="button">تست</button></div>
	<div id="tcas-test-result" class="tcas-test-result" role="status" aria-live="polite"></div>
</section>
