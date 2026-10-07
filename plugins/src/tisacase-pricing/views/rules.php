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
$tcp_types  = TCP_Admin::product_types();

// اگر فروشگاه هیچ دسته‌ای ندارد، جستجوی دسته‌بندی طبعاً نتیجه‌ای نمی‌دهد؛ علتش را بگو.
$tcp_has_cats = (bool) get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
		'number'     => 1,
		'fields'     => 'ids',
	)
);

/** سلکت حالت رند (مشترک سراسری/مودال). */
$tcp_mode_select = static function ( $name, $value, $class = 'tisa-input', $id = '' ) use ( $tcp_modes ) {
	?>
	<select class="<?php echo esc_attr( $class ); ?> tcp-mode" name="<?php echo esc_attr( $name ); ?>"<?php echo $id ? ' id="' . esc_attr( $id ) . '"' : ''; ?>>
		<?php foreach ( $tcp_modes as $k => $label ) : ?>
			<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $value, $k ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
};

/** خلاصهٔ یک‌خطی قانون برای نمایش در سطر. */
$tcp_rule_summary = static function ( $rule ) use ( $tcp_modes ) {
	if ( ! empty( $rule['exclude'] ) ) {
		return 'از همهٔ قوانین (حتی سراسری) خارج است';
	}
	$parts   = array();
	$parts[] = '↑ ' . $rule['increase'] . '٪';
	if ( (float) $rule['sale'] > 0 ) {
		$parts[] = 'فروش ویژه ' . $rule['sale'] . '٪';
	} else {
		$parts[] = 'بدون فروش ویژه';
	}
	$parts[] = isset( $tcp_modes[ $rule['mode'] ] ) ? $tcp_modes[ $rule['mode'] ] : $rule['mode'];
	return implode( ' · ', $parts );
};

/** خط دوم جزئیات (بازه/کف‌سقف)؛ خالی یعنی چیزی برای نمایش نیست. */
$tcp_rule_sub = static function ( $rule ) {
	$parts = array();
	if ( '' !== $rule['from'] || '' !== $rule['to'] ) {
		$parts[] = 'بازه: ' . ( '' !== $rule['from'] ? $rule['from'] : '…' ) . ' تا ' . ( '' !== $rule['to'] ? $rule['to'] : '…' );
	}
	if ( ! empty( $rule['min'] ) || ! empty( $rule['max'] ) ) {
		$parts[] = 'کف/سقف: ' . ( ! empty( $rule['min'] ) ? number_format_i18n( $rule['min'] ) : '…' ) . ' تا ' . ( ! empty( $rule['max'] ) ? number_format_i18n( $rule['max'] ) : '…' );
	}
	return implode( ' · ', $parts );
};

/** سلول هویت: عکس + نام (لینک ویرایش) + بج‌ها + چیپ‌ها. */
$tcp_identity_cell = static function ( $info ) {
	?>
	<div class="tcp-rule-identity">
		<?php if ( ! empty( $info['image'] ) ) : ?>
			<img class="tcp-rule-thumb" src="<?php echo esc_url( $info['image'] ); ?>" alt="" loading="lazy">
		<?php elseif ( ! empty( $info['icon'] ) ) : ?>
			<span class="tcp-rule-thumb tcp-rule-thumb--icon" aria-hidden="true"><span class="dashicons <?php echo esc_attr( $info['icon'] ); ?>"></span></span>
		<?php else : ?>
			<span class="tcp-rule-thumb tcp-rule-thumb--empty" aria-hidden="true">□</span>
		<?php endif; ?>
		<div class="tcp-rule-idmain">
			<div class="tcp-rule-title">
				<?php if ( ! empty( $info['edit_url'] ) ) : ?>
					<a class="tcp-rule-name" href="<?php echo esc_url( $info['edit_url'] ); ?>" target="_blank" rel="noopener" title="باز کردن صفحهٔ ویرایش در تب جدید"><?php echo esc_html( $info['name'] ); ?></a>
				<?php else : ?>
					<span class="tcp-rule-name"><?php echo esc_html( $info['name'] ); ?></span>
				<?php endif; ?>
				<span class="tcp-badge tcp-status <?php echo $info['enabled'] ? 'tcp-st-done' : 'tcp-st-cancelled'; ?>"><?php echo $info['enabled'] ? 'فعال' : 'غیرفعال'; ?></span>
				<span class="tcp-badge tcp-exbadge tcp-cp-expired"<?php echo $info['excluded'] ? '' : ' style="display:none"'; ?>>استثنا</span>
			</div>
			<div class="tcp-rule-chips"><?php echo $info['chips']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- چیپ‌ها پایین همین فایل با esc ساخته شده‌اند. ?></div>
		</div>
	</div>
	<?php
};

/**
 * یک سطر قانون (محصول یا دسته).
 *
 * @param string $type  products|categories.
 * @param int    $id    شناسه.
 * @param array  $rule  قانون نرمال‌شده.
 * @param array  $info  name, edit_url, image/icon, chips, summary, sub, search.
 */
$tcp_rule_row = static function ( $type, $id, $rule, $info ) use ( $tcp_identity_cell ) {
	$id       = absint( $id );
	$n        = esc_attr( $type ) . '[' . $id . ']';
	$name     = isset( $info['name'] ) ? $info['name'] : '';
	if ( '' === $name ) {
		return;
	}
	$excluded = ! empty( $rule['exclude'] );
	$enabled  = ! empty( $rule['enabled'] );
	$cls      = 'tcp-rule-row' . ( $excluded ? ' is-excluded' : '' ) . ( $enabled ? '' : ' is-off' );
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $n از absint/رشتهٔ ثابت ساخته شده.
	?>
	<tr class="<?php echo esc_attr( $cls ); ?>" data-rule-id="<?php echo esc_attr( $id ); ?>" data-search="<?php echo esc_attr( isset( $info['search'] ) ? $info['search'] : '' ); ?>">
		<td class="tcp-cell-identity">
			<?php
			$tcp_identity_cell(
				array(
					'name'     => $name,
					'edit_url' => isset( $info['edit_url'] ) ? $info['edit_url'] : '',
					'image'    => isset( $info['image'] ) ? $info['image'] : '',
					'icon'     => isset( $info['icon'] ) ? $info['icon'] : '',
					'chips'    => $info['chips'],
					'enabled'  => $enabled,
					'excluded' => $excluded,
				)
			);
			?>
		</td>
		<td class="tcp-cell-rule">
			<div class="tcp-rule-sum"><?php echo esc_html( $info['summary'] ); ?></div>
			<?php if ( '' !== $info['sub'] ) : ?>
				<div class="tcp-rule-sub"><?php echo esc_html( $info['sub'] ); ?></div>
			<?php else : ?>
				<div class="tcp-rule-sub" style="display:none"></div>
			<?php endif; ?>
		</td>
		<td class="tcp-cell-flags">
			<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-enabled" <?php checked( $enabled, true ); ?>><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>
			<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-exclude" <?php checked( $excluded, true ); ?>><span class="tisa-switch__track" aria-hidden="true"></span><span>استثنا</span></label>
		</td>
		<td class="tcp-cell-actions">
			<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-edit-rule">ویرایش</button>
			<button type="button" class="tisa-btn tisa-btn--danger-ghost tisa-btn--sm tcp-remove-rule" aria-label="حذف قانون <?php echo esc_attr( $name ); ?>">حذف</button>
			<input type="hidden" name="<?php echo $n; ?>[exists]" value="1">
			<input type="hidden" data-f="increase" name="<?php echo $n; ?>[increase]" value="<?php echo esc_attr( $rule['increase'] ); ?>">
			<input type="hidden" data-f="sale" name="<?php echo $n; ?>[sale]" value="<?php echo esc_attr( $rule['sale'] ); ?>">
			<input type="hidden" data-f="mode" name="<?php echo $n; ?>[mode]" value="<?php echo esc_attr( $rule['mode'] ); ?>">
			<input type="hidden" data-f="from" name="<?php echo $n; ?>[from]" value="<?php echo esc_attr( $rule['from'] ); ?>">
			<input type="hidden" data-f="to" name="<?php echo $n; ?>[to]" value="<?php echo esc_attr( $rule['to'] ); ?>">
			<input type="hidden" data-f="min" name="<?php echo $n; ?>[min]" value="<?php echo esc_attr( $rule['min'] ? $rule['min'] : '' ); ?>">
			<input type="hidden" data-f="max" name="<?php echo $n; ?>[max]" value="<?php echo esc_attr( $rule['max'] ? $rule['max'] : '' ); ?>">
			<input type="hidden" data-f="enabled" name="<?php echo $n; ?>[enabled]" value="<?php echo $enabled ? '1' : '0'; ?>">
			<input type="hidden" data-f="exclude" name="<?php echo $n; ?>[exclude]" value="<?php echo $excluded ? '1' : '0'; ?>">
		</td>
	</tr>
	<?php
	// phpcs:enable
};

/** چیپ‌های محصول: SKU + شناسه + نوع. */
$tcp_product_chips = static function ( $id, $sku, $type ) use ( $tcp_types ) {
	$out = '';
	if ( '' !== (string) $sku ) {
		$out .= '<code class="tcp-sku" dir="ltr" title="SKU">' . esc_html( $sku ) . '</code>';
	} else {
		$out .= '<code class="tcp-sku tcp-sku--empty" title="SKU ثبت نشده">بدون SKU</code>';
	}
	$out .= '<span class="tcp-ex-id tisa-code">#' . esc_html( $id ) . '</span>';
	if ( '' !== (string) $type ) {
		$label = isset( $tcp_types[ $type ] ) ? $tcp_types[ $type ] : $type;
		$out  .= '<span>' . esc_html( $label ) . '</span>';
	}
	return $out;
};

/** چیپ‌های دسته: شناسه + تعداد محصول + مسیر کامل. */
$tcp_cat_chips = static function ( $id, $count, $path, $name ) {
	$out  = '<span class="tcp-ex-id tisa-code">#' . esc_html( $id ) . '</span>';
	$out .= '<span>' . esc_html( number_format_i18n( $count ) . ' محصول' ) . '</span>';
	if ( '' !== $path && $path !== $name ) {
		$out .= '<span class="tcp-ex-path">' . esc_html( $path ) . '</span>';
	}
	return $out;
};

/** شمارش برای بج هر گروه. */
$tcp_count_text = static function ( $rules ) {
	$n = count( $rules );
	$e = 0;
	foreach ( $rules as $r ) {
		if ( ! empty( $r['exclude'] ) ) {
			$e++;
		}
	}
	$text = number_format_i18n( $n ) . ' مورد';
	if ( $e ) {
		$text .= ' · ' . number_format_i18n( $e ) . ' استثنا';
	}
	return $text;
};
?>

<p class="tcp-lead">قیمت‌ها هنگام نمایش محاسبه می‌شوند؛ چیزی در دیتابیس نوشته نمی‌شود. محصولی که فروش ویژهٔ واقعی دارد، و قیمت همکاری، دست‌نخورده می‌مانند. اولویت: <strong>محصول ← دسته‌بندی ← سراسری</strong>.</p>

<div class="tcp-alert tcp-alert--warn">برای افزایش دائمی (مثلاً ۱۰٪ روی قیمت خودِ هر متغیر، نوشته‌شده در دیتابیس) از تب <a href="<?php echo esc_url( TCP_Admin::url( 'bulk', array( 'target' => 'all' ) ) ); ?>">تغییر گروهی قیمت</a> و حالت «همهٔ محصولات سایت» استفاده کن. اگر این قانون روشن بماند و قیمت دیتابیس را هم بالا ببری، مشتری هر دو افزایش را با هم می‌بیند.</div>

<div class="tcp-alert tcp-alert--danger" id="tcp-stale-assets" style="display:none" role="alert"><strong>فایل‌های <span id="tcp-stale-what">جاوااسکریپت</span> این صفحه قدیمی کش شده‌اند</strong> و ظاهر/رفتار تب درست کار نمی‌کند. یک‌بار صفحه را با <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> (در مک: <kbd>Cmd</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd>) تازه‌سازی کن؛ اگر درست نشد، کش افزونهٔ بهینه‌ساز/کش سایت را پاک کن.</div>
<span id="tcp-css-probe" class="tcp-css-probe" aria-hidden="true"></span>
<script>
/* آشکارساز asset کش‌شده: rules.js تازه پرچم می‌گذارد، CSS تازه روی probe متغیر می‌گذارد. */
(function () {
	function tcpStaleCheck() {
		var okJs = !!window.__tcpRulesV2;
		var okCss = false;
		try {
			var probe = document.getElementById('tcp-css-probe');
			okCss = !!probe && 'rules-v2' === String(window.getComputedStyle(probe).getPropertyValue('--tcp-probe') || '').trim().replace(/["']/g, '');
		} catch (e) { okCss = false; }
		if (okJs && okCss) { return; }
		var n = document.getElementById('tcp-stale-assets');
		if (!n) { return; }
		var parts = [];
		if (!okJs) { parts.push('جاوااسکریپت'); }
		if (!okCss) { parts.push('استایل'); }
		var what = document.getElementById('tcp-stale-what');
		if (what) { what.textContent = parts.join(' و '); }
		n.style.display = '';
	}
	if ('complete' === document.readyState) {
		window.setTimeout(tcpStaleCheck, 900);
	} else {
		window.addEventListener('load', function () { window.setTimeout(tcpStaleCheck, 900); });
	}
})();
</script>

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

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcp-rules-form">
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

	<section class="tcp-card" id="tcp-products-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>محصولات تکی</h2><p>جستجو کن، چند محصول را با هم تیک بزن و یک‌باره اضافه کن. ویرایش هر قانون با دکمهٔ «ویرایش» در پنجره باز می‌شود.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-search-box tcp-search-box--multi">
				<input type="search" class="tisa-input" id="tcp-product-search" placeholder="نام، SKU یا شناسهٔ محصول… (حداقل ۲ حرف)" autocomplete="off">
				<div id="tcp-product-results" class="tcp-search-results" role="listbox" aria-label="نتایج جستجوی محصول"></div>
			</div>
			<div class="tcp-ex-toolbar">
				<span class="tcp-selection-badge" id="tcp-product-count"><?php echo esc_html( $tcp_count_text( $tcp_rules['products'] ) ); ?></span>
				<input type="search" class="tisa-input tisa-input--sm tcp-ex-filter" id="tcp-product-filter" placeholder="جستجو در همین لیست (نام / SKU / شناسه)…" autocomplete="off" aria-label="جستجو در لیست محصولات">
			</div>
			<div class="tcp-rule-table-scroll"<?php echo empty( $tcp_rules['products'] ) ? ' style="display:none"' : ''; ?>>
				<table class="tcp-rule-table">
					<thead><tr>
						<th class="tcp-col-identity">محصول</th>
						<th class="tcp-col-rule">قانون</th>
						<th class="tcp-col-flags">وضعیت</th>
						<th class="tcp-col-actions">عملیات</th>
					</tr></thead>
					<tbody id="tcp-product-rules">
						<?php
						foreach ( $tcp_rules['products'] as $id => $rule ) {
							$id      = absint( $id );
							$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
							if ( $product ) {
								$pname  = $product->get_name();
								$psku   = (string) $product->get_sku();
								$ptype  = (string) $product->get_type();
								$img_id = absint( $product->get_image_id() );
							} else {
								$pname  = get_the_title( $id );
								$psku   = (string) get_post_meta( $id, '_sku', true );
								$ptype  = '';
								$img_id = absint( get_post_meta( $id, '_thumbnail_id', true ) );
							}
							if ( '' === $pname ) {
								continue;
							}
							$tcp_rule_row(
								'products',
								$id,
								$rule,
								array(
									'name'     => $pname,
									'edit_url' => get_edit_post_link( $id, '' ),
									'image'    => $img_id ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '',
									'chips'    => $tcp_product_chips( $id, $psku, $ptype ),
									'summary'  => $tcp_rule_summary( $rule ),
									'sub'      => $tcp_rule_sub( $rule ),
									'search'   => function_exists( 'mb_strtolower' ) ? mb_strtolower( $pname . ' ' . $psku . ' ' . $id, 'UTF-8' ) : strtolower( $pname . ' ' . $psku . ' ' . $id ),
								)
							);
						}
						?>
					</tbody>
				</table>
			</div>
			<div class="tcp-product-empty" id="tcp-product-empty"<?php echo empty( $tcp_rules['products'] ) ? '' : ' style="display:none"'; ?>>هنوز محصولی اضافه نشده است؛ از جستجوی بالا چند محصول را انتخاب و یک‌باره اضافه کن.</div>
		</div>
	</section>

	<section class="tcp-card" id="tcp-cats-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>دسته‌بندی‌ها</h2><p>قانون دسته روی خود دسته و همهٔ زیردسته‌هایش اعمال می‌شود. چند دسته را با هم تیک بزن و یک‌باره اضافه کن.</p></div></div>
		<div class="tcp-card-body">
			<?php if ( ! $tcp_has_cats ) : ?>
				<div class="tcp-alert tcp-alert--warn">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ جستجوی پایین تا ساخت دسته چیزی برای نشان‌دادن ندارد.</div>
			<?php endif; ?>
			<div class="tcp-search-box tcp-search-box--multi">
				<input type="search" class="tisa-input" id="tcp-category-search" placeholder="نام دسته‌بندی… (حداقل ۲ حرف)" autocomplete="off">
				<div id="tcp-category-results" class="tcp-search-results" role="listbox" aria-label="نتایج جستجوی دسته‌بندی"></div>
			</div>
			<div class="tcp-ex-toolbar">
				<span class="tcp-selection-badge" id="tcp-category-count"><?php echo esc_html( $tcp_count_text( $tcp_rules['categories'] ) ); ?></span>
				<input type="search" class="tisa-input tisa-input--sm tcp-ex-filter" id="tcp-category-filter" placeholder="جستجو در همین لیست…" autocomplete="off" aria-label="جستجو در لیست دسته‌بندی‌ها">
			</div>
			<div class="tcp-rule-table-scroll"<?php echo empty( $tcp_rules['categories'] ) ? ' style="display:none"' : ''; ?>>
				<table class="tcp-rule-table">
					<thead><tr>
						<th class="tcp-col-identity">دسته‌بندی</th>
						<th class="tcp-col-rule">قانون</th>
						<th class="tcp-col-flags">وضعیت</th>
						<th class="tcp-col-actions">عملیات</th>
					</tr></thead>
					<tbody id="tcp-category-rules">
						<?php
						foreach ( $tcp_rules['categories'] as $id => $rule ) {
							$id   = absint( $id );
							$term = get_term( $id, 'product_cat' );
							if ( ! $term || is_wp_error( $term ) ) {
								continue;
							}
							$path     = TCP_Admin::cat_label( $term );
							$edit_url = get_edit_term_link( $id, 'product_cat' );
							$tcp_rule_row(
								'categories',
								$id,
								$rule,
								array(
									'name'     => $term->name,
									'edit_url' => is_wp_error( $edit_url ) ? '' : $edit_url,
									'icon'     => 'dashicons-category',
									'chips'    => $tcp_cat_chips( $id, (int) $term->count, $path, $term->name ),
									'summary'  => $tcp_rule_summary( $rule ),
									'sub'      => $tcp_rule_sub( $rule ),
									'search'   => function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name . ' ' . $path . ' ' . $id, 'UTF-8' ) : strtolower( $term->name . ' ' . $path . ' ' . $id ),
								)
							);
						}
						?>
					</tbody>
				</table>
			</div>
			<div class="tcp-product-empty" id="tcp-category-empty"<?php echo empty( $tcp_rules['categories'] ) ? '' : ' style="display:none"'; ?>>هنوز دسته‌ای اضافه نشده است؛ از جستجوی بالا چند دسته را انتخاب و یک‌باره اضافه کن.</div>
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

<div class="tcp-modal" id="tcp-rule-modal" hidden>
	<div class="tcp-modal__backdrop" data-tcp-close></div>
	<div class="tcp-modal__box" role="dialog" aria-modal="true" aria-labelledby="tcp-modal-title">
		<div class="tcp-modal__head">
			<div class="tcp-modal__titlewrap">
				<span class="tcp-modal__icon" aria-hidden="true"><span class="dashicons dashicons-edit"></span></span>
				<div>
					<h3 id="tcp-modal-title">ویرایش قانون</h3>
					<p id="tcp-modal-sub"></p>
				</div>
			</div>
			<button type="button" class="tcp-modal__x" data-tcp-close aria-label="بستن">×</button>
		</div>
		<div class="tcp-modal__body">
			<div class="tcp-modal__grid tcp-modal__grid--3">
				<label>افزایش ٪
					<input class="tisa-input tisa-input--number" type="number" min="0" max="500" step="0.1" id="tcp-m-increase">
				</label>
				<label>فروش ویژه ٪
					<input class="tisa-input tisa-input--number" type="number" min="0" max="99.9" step="0.1" id="tcp-m-sale">
				</label>
				<label>رند
					<?php $tcp_mode_select( 'tcp_modal_mode', 'round', 'tisa-input', 'tcp-m-mode' ); ?>
				</label>
			</div>
			<div class="tcp-modal__grid tcp-modal__grid--2">
				<label>از تاریخ <input type="date" class="tisa-input" id="tcp-m-from"></label>
				<label>تا تاریخ <input type="date" class="tisa-input" id="tcp-m-to"></label>
				<label>کف قیمت جدید <input type="number" class="tisa-input" min="0" step="1000" id="tcp-m-min" placeholder="خالی = بدون کف"></label>
				<label>سقف قیمت جدید <input type="number" class="tisa-input" min="0" step="1000" id="tcp-m-max" placeholder="خالی = بدون سقف"></label>
			</div>
			<div class="tcp-modal__switches">
				<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-m-enabled"><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>
				<label class="tisa-switch tcp-toggle"><input type="checkbox" id="tcp-m-exclude"><span class="tisa-switch__track" aria-hidden="true"></span><span>استثنا <span class="tcp-muted">— از همهٔ قوانین خارج شود</span></span></label>
			</div>
			<p class="tcp-modal__hint" id="tcp-modal-hint"></p>
		</div>
		<div class="tcp-modal__foot">
			<button type="button" class="tisa-btn tisa-btn--ghost" data-tcp-close>انصراف</button>
			<button type="button" class="tisa-btn tisa-btn--primary" id="tcp-modal-save">ذخیره</button>
		</div>
	</div>
</div>
