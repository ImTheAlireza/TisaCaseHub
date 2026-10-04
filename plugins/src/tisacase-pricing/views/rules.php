<?php
/**
 * تب «قوانین داینامیک».
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

$tcp_rules  = TCP_Rules::settings();
$tcp_modes  = TCP_Rules::modes();
$tcp_ending = TCP_Round::describe();
$tcp_jitter = TCP_Round::jitter();

// اگر فروشگاه هیچ دسته‌ای ندارد، جستجوی دسته‌بندی طبعاً نتیجه‌ای نمی‌دهد؛ علتش را بگو.
$tcp_has_cats = (bool) get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'number'     => 1,
		'fields'     => 'ids',
	)
);

/** سلکت حالت رند (مشترک سراسری/ردیف). */
$tcp_mode_select = static function ( $name, $value, $class = 'tisa-input' ) use ( $tcp_modes ) {
	?>
	<select class="<?php echo esc_attr( $class ); ?> tcp-mode" name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $tcp_modes as $k => $label ) : ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $value, $k ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
};

/** یک ردیف قانون (محصول یا دسته). */
$tcp_rule_row = static function ( $type, $id, $rule ) use ( $tcp_mode_select ) {
	$id   = absint( $id );
	$rule = TCP_Rules::normalize_rule( $rule );
	if ( 'products' === $type ) {
		$name = get_the_title( $id );
	} else {
		$term = get_term( $id, 'product_cat' );
		$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
	}
	if ( '' === $name ) {
		return;
	}
	$n = esc_attr( $type ) . '[' . $id . ']';
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $n از absint/esc_attr ساخته شده.
	?>
	<tr data-rule-id="<?php echo esc_attr( $id ); ?>" class="<?php echo $rule['exclude'] ? 'is-excluded' : ''; ?>">
		<td class="tcp-rule-name">
			<strong><?php echo esc_html( $name ); ?></strong>
			<small class="tisa-code">#<?php echo esc_html( $id ); ?></small>
			<input type="hidden" name="<?php echo $n; ?>[exists]" value="1">
		</td>
		<td class="tcp-rule-num"><input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="<?php echo $n; ?>[increase]" value="<?php echo esc_attr( $rule['increase'] ); ?>"></td>
		<td class="tcp-rule-num"><input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="<?php echo $n; ?>[sale]" value="<?php echo esc_attr( $rule['sale'] ); ?>"></td>
		<td><?php $tcp_mode_select( $n . '[mode]', $rule['mode'], 'tisa-input tisa-input--sm' ); ?></td>
		<td class="tcp-rule-dates">
			<input type="date" class="tisa-input tisa-input--sm" name="<?php echo $n; ?>[from]" value="<?php echo esc_attr( $rule['from'] ); ?>" title="از تاریخ">
			<input type="date" class="tisa-input tisa-input--sm" name="<?php echo $n; ?>[to]" value="<?php echo esc_attr( $rule['to'] ); ?>" title="تا تاریخ">
		</td>
		<td class="tcp-rule-limits">
			<input type="number" class="tisa-input tisa-input--sm" min="0" step="1000" name="<?php echo $n; ?>[min]" value="<?php echo esc_attr( $rule['min'] ? $rule['min'] : '' ); ?>" placeholder="کف">
			<input type="number" class="tisa-input tisa-input--sm" min="0" step="1000" name="<?php echo $n; ?>[max]" value="<?php echo esc_attr( $rule['max'] ? $rule['max'] : '' ); ?>" placeholder="سقف">
		</td>
		<td class="tcp-rule-flags">
			<input type="hidden" name="<?php echo $n; ?>[enabled]" value="0">
			<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" name="<?php echo $n; ?>[enabled]" value="1" <?php checked( $rule['enabled'], 1 ); ?>><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>
			<input type="hidden" name="<?php echo $n; ?>[exclude]" value="0">
			<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-exclude" name="<?php echo $n; ?>[exclude]" value="1" <?php checked( $rule['exclude'], 1 ); ?>><span class="tisa-switch__track" aria-hidden="true"></span><span>استثنا</span></label>
		</td>
		<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tcp-remove-rule">حذف</button></td>
	</tr>
	<?php
	// phpcs:enable
};

$tcp_table_head = static function ( $first ) {
	?>
	<thead><tr>
		<th><?php echo esc_html( $first ); ?></th>
		<th>افزایش ٪</th>
		<th>فروش ویژه ٪</th>
		<th>رند</th>
		<th>بازهٔ زمانی</th>
		<th>کف / سقف قیمت</th>
		<th>وضعیت</th>
		<th></th>
	</tr></thead>
	<?php
};
?>

<p class="tcp-lead">قیمت‌ها هنگام نمایش محاسبه می‌شوند؛ چیزی در دیتابیس نوشته نمی‌شود. محصولی که فروش ویژهٔ واقعی دارد، و قیمت همکاری، دست‌نخورده می‌مانند. اولویت: <strong>محصول ← دسته‌بندی ← سراسری</strong>.</p>

<div class="tcp-alert tcp-alert--warn">برای افزایش دائمی (مثلاً ۱۰٪ روی قیمت خودِ هر متغیر، نوشته‌شده در دیتابیس) از تب <a href="<?php echo esc_url( TCP_Admin::url( 'bulk', array( 'target' => 'all' ) ) ); ?>">تغییر گروهی قیمت</a> و حالت «همهٔ محصولات سایت» استفاده کن. اگر این قانون روشن بماند و قیمت دیتابیس را هم بالا ببری، مشتری هر دو افزایش را با هم می‌بیند.</div>

<details class="tcp-help">
	<summary>هر تخفیف چطور محاسبه می‌شود؟</summary>
	<ol>
		<li><strong>قیمت جدید</strong> = قیمت عادی محصول + «افزایش ٪». اگر کف/سقف داده باشی، در همان بازه نگه داشته می‌شود.</li>
		<li><strong>فروش ویژه</strong> = قیمت جدید − «فروش ویژه ٪». مشتری قیمت جدید را خط‌خورده و فروش ویژه را می‌بیند.</li>
		<li><strong>رند</strong> تعیین می‌کند عدد نهایی چه شکلی شود:
			<ul>
				<li><em>بدون رند</em>: عدد خام؛ مثلاً ۵۹۹٬۴۰۰.</li>
				<li><em>رند به ۸</em>: نزدیک‌ترین <?php echo esc_html( $tcp_ending ); ?>، بالا یا پایین. ۵۹۹٬۴۰۰ ← ۵۹۸٬۰۰۰ و ۶۱۳٬۱۰۰ ← ۶۱۸٬۰۰۰. فاصلهٔ برابر به پایین می‌رود.</li>
				<li><em>تخفیف متغیر</em>: عدد واردشده «میانگین» است. هر محصول درصدی در بازهٔ ±<?php echo esc_html( $tcp_jitter ); ?>٪ می‌گیرد که قیمتش دقیقاً روی ۸ بیفتد؛ مثلاً یکی ۷٫۸٪ و دیگری ۱۲٫۱٪. انتخاب هر محصول ثابت است و با هر بار نمایش عوض نمی‌شود. دامنه و رقم رند در تب «تنظیمات».</li>
			</ul>
		</li>
		<li><strong>بازهٔ زمانی</strong>: قانون فقط بین این دو تاریخ (شامل هر دو) فعال است؛ خالی = همیشه.</li>
		<li><strong>استثنا</strong>: آن محصول/دسته از همهٔ قوانین (حتی سراسری) خارج می‌شود و قیمت اصلی‌اش را نشان می‌دهد.</li>
	</ol>
</details>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Rules::ACTION_SAVE ); ?>">
	<?php wp_nonce_field( TCP_Rules::ACTION_SAVE ); ?>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>قانون سراسری</h2><p>روی همهٔ محصولات فعلی و آینده، مگر آن‌که قانون یا استثنای محصول/دسته داشته باشند.</p></div></div>
		<div class="tcp-card-body">
			<input type="hidden" name="global[enabled]" value="0">
			<label class="tisa-switch tcp-toggle tcp-global-switch">
				<input type="checkbox" name="global[enabled]" value="1" <?php checked( $tcp_rules['global']['enabled'], 1 ); ?>>
				<span class="tisa-switch__track" aria-hidden="true"></span>
				<span>فعال برای همهٔ محصولات</span>
			</label>
			<div class="tcp-grid-3">
				<label>درصد افزایش قیمت اصلی
					<input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" name="global[increase]" value="<?php echo esc_attr( $tcp_rules['global']['increase'] ); ?>">
				</label>
				<label>درصد فروش ویژه از قیمت افزایش‌یافته
					<input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" name="global[sale]" value="<?php echo esc_attr( $tcp_rules['global']['sale'] ); ?>">
				</label>
				<label>رند
					<?php $tcp_mode_select( 'global[mode]', $tcp_rules['global']['mode'] ); ?>
				</label>
			</div>
			<div class="tcp-grid-4 tcp-field">
				<label class="tcp-stack">از تاریخ <input type="date" class="tisa-input" name="global[from]" value="<?php echo esc_attr( $tcp_rules['global']['from'] ); ?>"></label>
				<label class="tcp-stack">تا تاریخ <input type="date" class="tisa-input" name="global[to]" value="<?php echo esc_attr( $tcp_rules['global']['to'] ); ?>"></label>
				<label class="tcp-stack">کف قیمت جدید <input type="number" class="tisa-input" min="0" step="1000" name="global[min]" value="<?php echo esc_attr( $tcp_rules['global']['min'] ? $tcp_rules['global']['min'] : '' ); ?>" placeholder="خالی = بدون کف"></label>
				<label class="tcp-stack">سقف قیمت جدید <input type="number" class="tisa-input" min="0" step="1000" name="global[max]" value="<?php echo esc_attr( $tcp_rules['global']['max'] ? $tcp_rules['global']['max'] : '' ); ?>" placeholder="خالی = بدون سقف"></label>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>محصولات تکی</h2><p>محصول متغیر یعنی همهٔ واریشن‌های آن. «استثنا» = این محصول از همهٔ قوانین خارج شود.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-search-box">
				<input type="search" class="tisa-input" id="tcp-product-search" placeholder="حداقل ۲ حرف از نام محصول…" autocomplete="off">
				<div id="tcp-product-results" class="tcp-search-results"></div>
			</div>
			<div class="tcp-table-scroll">
				<table class="tisa-table tcp-rules-table">
					<?php $tcp_table_head( 'محصول' ); ?>
					<tbody id="tcp-product-rules">
						<?php foreach ( $tcp_rules['products'] as $id => $rule ) { $tcp_rule_row( 'products', $id, $rule ); } ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>دسته‌بندی‌ها</h2><p>قانون دسته روی خود دسته و همهٔ زیردسته‌هایش اعمال می‌شود. «استثنا» = این دسته از همهٔ قوانین خارج شود.</p></div></div>
		<div class="tcp-card-body">
			<?php if ( ! $tcp_has_cats ) : ?>
				<div class="tcp-alert tcp-alert--warn">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ جستجوی بالا تا ساخت دسته چیزی برای نشان‌دادن ندارد.</div>
			<?php endif; ?>
			<div class="tcp-search-box">
				<input type="search" class="tisa-input" id="tcp-category-search" placeholder="حداقل ۲ حرف از نام دسته‌بندی…" autocomplete="off">
				<div id="tcp-category-results" class="tcp-search-results"></div>
			</div>
			<div class="tcp-table-scroll">
				<table class="tisa-table tcp-rules-table">
					<?php $tcp_table_head( 'دسته‌بندی' ); ?>
					<tbody id="tcp-category-rules">
						<?php foreach ( $tcp_rules['categories'] as $id => $rule ) { $tcp_rule_row( 'categories', $id, $rule ); } ?>
					</tbody>
				</table>
			</div>
		</div>
	</section>

	<div class="tcp-actions">
		<button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ قوانین</button>
	</div>
</form>

<section class="tcp-card tcp-card--dashed">
	<div class="tcp-card-head"><span class="tcp-dot tcp-dot--muted"></span><div><h2>همگام‌سازی سریع کل فروشگاه</h2><p>قانون سراسری را روی <strong>۱۰٪ افزایش</strong> و <strong>۱۰٪ فروش ویژه</strong> فعال می‌کند؛ بدون بازنویسی محصولات، شامل محصولات آینده. حالت رند و بقیهٔ تنظیمات سراسری حفظ می‌شوند.</p></div></div>
	<div class="tcp-card-body">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('قانون سراسری ۱۰٪ افزایش + ۱۰٪ فروش ویژه برای همهٔ محصولات بدون فروش ویژهٔ واقعی فعال شود؟');">
			<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Rules::ACTION_SYNC ); ?>">
			<?php wp_nonce_field( TCP_Rules::ACTION_SYNC ); ?>
			<button type="submit" class="tisa-btn tisa-btn--secondary">فعال‌سازی ۱۰٪ + ۱۰٪ سراسری</button>
		</form>
	</div>
</section>
