<?php
/**
 * تب «قوانین داینامیک».
 *
 * ساختار: قانون سراسری ← محصولات تکی ← دسته‌بندی‌ها (هر کدام فقط «قانون») و یک بخش جدا
 * «استثناها» با سه فهرست: استثنای تکی، استثنای دسته‌بندی، استثنای شناسه (پیشوند SKU).
 * اولویت اجرا: قانون/استثنای تکی ← استثنای شناسه ← دسته‌بندی ← سراسری.
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

/** خلاصهٔ یک‌خطی قانون برای نمایش در سطر (استثنا: متن ثابت). */
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
	if ( ! empty( $rule['exclude'] ) ) {
		return '';
	}
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
				<?php if ( ! empty( $info['enabled_badge'] ) ) : ?>
					<span class="tcp-badge tcp-status <?php echo $info['enabled'] ? 'tcp-st-done' : 'tcp-st-cancelled'; ?>"><?php echo $info['enabled'] ? 'فعال' : 'غیرفعال'; ?></span>
				<?php endif; ?>
			</div>
			<div class="tcp-rule-chips"><?php echo $info['chips']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- چیپ‌ها پایین همین فایل با esc ساخته شده‌اند. ?></div>
		</div>
	</div>
	<?php
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

/**
 * یک سطر (قانون یا استثنا).
 *
 * @param string $kind  rule|exc.
 * @param string $group products|categories (نام فیلد POST).
 * @param int    $id    شناسه.
 * @param array  $rule  قانون نرمال‌شده.
 * @param array  $info  name, edit_url, image/icon, chips, search_text, item (داده برای جابه‌جایی بین فهرست‌ها).
 */
$tcp_rule_row = static function ( $kind, $group, $id, $rule, $info ) use ( $tcp_identity_cell, $tcp_rule_summary, $tcp_rule_sub ) {
	$id   = absint( $id );
	$name = isset( $info['name'] ) ? $info['name'] : '';
	if ( '' === $name ) {
		return;
	}
	$excluded = 'exc' === $kind;
	$enabled  = $excluded ? true : ! empty( $rule['enabled'] );
	$n        = esc_attr( $group ) . '[' . $id . ']';
	$summary  = $tcp_rule_summary( $rule );
	$sub      = $excluded ? '' : $tcp_rule_sub( $rule );
	$cls      = 'tcp-rule-row' . ( $excluded ? ' is-excluded' : '' ) . ( $enabled ? '' : ' is-off' );
	$item     = isset( $info['item'] ) ? $info['item'] : array( 'id' => $id, 'name' => $name );
	$search   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $info['search_text'], 'UTF-8' ) : strtolower( $info['search_text'] );
	$hidden   = static function ( $field, $value ) use ( $n ) {
		return '<input type="hidden" data-f="' . esc_attr( $field ) . '" name="' . $n . '[' . esc_attr( $field ) . ']" value="' . esc_attr( $value ) . '">';
	};
	?>
	<tr class="<?php echo esc_attr( $cls ); ?>" data-rule-id="<?php echo esc_attr( $id ); ?>" data-item="<?php echo esc_attr( wp_json_encode( $item ) ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
		<td class="tcp-cell-identity">
			<?php
			$tcp_identity_cell(
				array(
					'name'          => $name,
					'edit_url'      => isset( $info['edit_url'] ) ? $info['edit_url'] : '',
					'image'         => isset( $info['image'] ) ? $info['image'] : '',
					'icon'          => isset( $info['icon'] ) ? $info['icon'] : '',
					'chips'         => $info['chips'],
					'enabled'       => $enabled,
					'enabled_badge' => ! $excluded,
				)
			);
			?>
		</td>
		<td class="tcp-cell-rule">
			<div class="tcp-rule-sum"><?php echo esc_html( $summary ); ?></div>
			<div class="tcp-rule-sub<?php echo '' !== $sub ? '' : ' tcp-hidden'; ?>"><?php echo esc_html( $sub ); ?></div>
		</td>
		<?php if ( ! $excluded ) : ?>
			<td class="tcp-cell-flags">
				<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-enabled" <?php checked( $enabled, true ); ?>><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>
			</td>
		<?php endif; ?>
		<td class="tcp-cell-actions">
			<?php if ( $excluded ) : ?>
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-move-rule" data-to="rule" title="حذف از استثناها و افزودن به قوانین">به قوانین</button>
			<?php else : ?>
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-edit-rule">ویرایش</button>
				<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tcp-move-rule" data-to="exc" title="خارج کردن از همهٔ قوانین و افزودن به استثناها">به استثنا</button>
			<?php endif; ?>
			<button type="button" class="tisa-btn tisa-btn--danger-ghost tisa-btn--sm tcp-remove-rule" aria-label="<?php echo esc_attr( ( $excluded ? 'حذف استثنای ' : 'حذف قانون ' ) . $name ); ?>">حذف</button>
			<?php
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- خروجی hidden همین تابع esc_attr دارد.
			echo $hidden( 'exists', '1' ); // phpcs:ignore
			echo $hidden( 'increase', $rule['increase'] ); // phpcs:ignore
			echo $hidden( 'sale', $rule['sale'] ); // phpcs:ignore
			echo $hidden( 'mode', $rule['mode'] ); // phpcs:ignore
			echo $hidden( 'from', $rule['from'] ); // phpcs:ignore
			echo $hidden( 'to', $rule['to'] ); // phpcs:ignore
			echo $hidden( 'min', $rule['min'] ? $rule['min'] : '' ); // phpcs:ignore
			echo $hidden( 'max', $rule['max'] ? $rule['max'] : '' ); // phpcs:ignore
			echo $hidden( 'enabled', $enabled ? '1' : '0' ); // phpcs:ignore
			echo $hidden( 'exclude', $excluded ? '1' : '0' ); // phpcs:ignore
			// phpcs:enable
			?>
		</td>
	</tr>
	<?php
};

/** چیپ‌های بالای فهرست استثنای شناسه: شمارش محصولات منطبق. */
$tcp_prefix_chip = static function ( $prefix, $count ) {
	?>
	<span class="tcp-prefix-chip" data-prefix="<?php echo esc_attr( $prefix ); ?>">
		<code class="tcp-prefix-code" dir="ltr"><?php echo esc_html( $prefix ); ?></code>
		<span class="tcp-prefix-count"><?php echo null === $count ? 'پس از ذخیره' : esc_html( number_format_i18n( $count ) . ' محصول' ); ?></span>
		<button type="button" class="tcp-prefix-remove" aria-label="حذف شناسهٔ <?php echo esc_attr( $prefix ); ?>">×</button>
		<input type="hidden" name="prefixes[]" value="<?php echo esc_attr( $prefix ); ?>">
	</span>
	<?php
};

/**
 * یک فهرست (قانون یا استثنا) برای یک نوع (محصول/دسته).
 *
 * @param string $type    product|category.
 * @param string $kind    rule|exc.
 * @param array  $entries هر ورودی: id, rule, info.
 */
$tcp_lane = static function ( $type, $kind, $entries ) use ( $tcp_rule_row ) {
	$excluded = 'exc' === $kind;
	$group    = 'product' === $type ? 'products' : 'categories';
	$is_prod  = 'product' === $type;

	if ( $is_prod ) {
		$placeholder = $excluded ? 'محصول برای استثنا را جستجو کن… (نام، SKU یا شناسه؛ حداقل ۲ حرف)' : 'نام، SKU یا شناسهٔ محصول… (حداقل ۲ حرف)';
		$aria        = $excluded ? 'نتایج جستجوی محصول برای استثنا' : 'نتایج جستجوی محصول';
		$empty       = $excluded
			? 'هنوز محصولی مستثنا نشده است؛ از جستجوی بالا محصول را انتخاب و با دکمهٔ «افزودن به استثناها» اضافه کن.'
			: 'هنوز محصولی قانون تکی ندارد؛ از جستجوی بالا چند محصول را انتخاب و یک‌باره اضافه کن.';
		$name_head   = 'محصول';
	} else {
		$placeholder = 'نام دسته‌بندی… (حداقل ۲ حرف)';
		$aria        = $excluded ? 'نتایج جستجوی دسته‌بندی برای استثنا' : 'نتایج جستجوی دسته‌بندی';
		$empty       = $excluded
			? 'هنوز دسته‌ای مستثنا نشده است؛ از جستجوی بالا دسته را انتخاب و با دکمهٔ «افزودن به استثناها» اضافه کن.'
			: 'هنوز دسته‌ای قانون ندارد؛ از جستجوی بالا چند دسته را انتخاب و یک‌باره اضافه کن.';
		$name_head   = 'دسته‌بندی';
	}
	$lane_key = $type . '-' . $kind;

	$shown = 0;
	ob_start();
	foreach ( $entries as $entry ) {
		$tcp_rule_row( $kind, $group, $entry['id'], $entry['rule'], $entry['info'] );
		$shown++;
	}
	$rows_html = ob_get_clean();
	?>
	<div class="tcp-lane" data-lane="<?php echo esc_attr( $lane_key ); ?>" data-type="<?php echo esc_attr( $type ); ?>" data-kind="<?php echo esc_attr( $kind ); ?>">
		<div class="tcp-search-box tcp-search-box--multi">
			<input type="search" class="tisa-input tcp-lane-search" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" aria-label="<?php echo esc_attr( $placeholder ); ?>">
			<div class="tcp-search-results tcp-lane-results" role="listbox" aria-label="<?php echo esc_attr( $aria ); ?>"></div>
		</div>
		<div class="tcp-ex-toolbar">
			<span class="tcp-selection-badge tcp-lane-count"><?php echo esc_html( number_format_i18n( $shown ) . ' مورد' ); ?></span>
			<input type="search" class="tisa-input tisa-input--sm tcp-ex-filter tcp-lane-filter" placeholder="جستجو در همین لیست…" autocomplete="off" aria-label="جستجو در این فهرست">
		</div>
		<div class="tcp-lane-note" aria-live="polite"></div>
		<div class="tcp-rule-table-scroll"<?php echo $shown ? '' : ' style="display:none"'; ?>>
			<table class="tcp-rule-table<?php echo $excluded ? ' tcp-rule-table--exc' : ''; ?>">
				<thead><tr>
					<th class="tcp-col-identity"><?php echo esc_html( $name_head ); ?></th>
					<th class="tcp-col-rule"><?php echo esc_html( $excluded ? 'اثر' : 'قانون' ); ?></th>
					<?php if ( ! $excluded ) : ?>
						<th class="tcp-col-flags">وضعیت</th>
					<?php endif; ?>
					<th class="tcp-col-actions">عملیات</th>
				</tr></thead>
				<tbody class="tcp-lane-body"><?php echo $rows_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- هر سطر با esc ساخته شده است. ?></tbody>
			</table>
		</div>
		<div class="tcp-product-empty tcp-lane-empty"<?php echo $shown ? ' style="display:none"' : ''; ?>><?php echo esc_html( $empty ); ?></div>
	</div>
	<?php
	return $shown;
};

// ---- ورودی‌های محصول و دسته (یک‌بار ساخته می‌شوند؛ هر فهرست فقط فیلتر می‌شود).
$tcp_product_entries = array();
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
	$img_url = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
	$tcp_product_entries[] = array(
		'id'   => $id,
		'rule' => $rule,
		'info' => array(
			'name'        => $pname,
			'edit_url'    => (string) get_edit_post_link( $id, '' ),
			'image'       => $img_url,
			'chips'       => $tcp_product_chips( $id, $psku, $ptype ),
			'search_text' => $pname . ' ' . $psku . ' ' . $id,
			'item'        => array(
				'id'        => $id,
				'name'      => $pname,
				'sku'       => $psku,
				'type'      => $ptype,
				'image_url' => $img_url,
				'edit_url'  => (string) get_edit_post_link( $id, '' ),
			),
		),
	);
}

$tcp_category_entries = array();
foreach ( $tcp_rules['categories'] as $id => $rule ) {
	$id   = absint( $id );
	$term = get_term( $id, 'product_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		continue;
	}
	$path     = TCP_Admin::cat_label( $term );
	$edit_url = get_edit_term_link( $id, 'product_cat' );
	$edit_url = is_wp_error( $edit_url ) ? '' : (string) $edit_url;
	$tcp_category_entries[] = array(
		'id'   => $id,
		'rule' => $rule,
		'info' => array(
			'name'        => $term->name,
			'edit_url'    => $edit_url,
			'icon'        => 'dashicons-category',
			'chips'       => $tcp_cat_chips( $id, (int) $term->count, $path, $term->name ),
			'search_text' => $term->name . ' ' . $path . ' ' . $id,
			'item'        => array(
				'id'       => $id,
				'name'     => $term->name,
				'count'    => (int) $term->count,
				'path'     => $path,
				'edit_url' => $edit_url,
			),
		),
	);
}

/** فیلتر ورودی‌ها به یک فهرست: rule = غیراستثنا، exc = استثنا. */
$tcp_only = static function ( $entries, $kind ) {
	return array_values(
		array_filter(
			$entries,
			static function ( $entry ) use ( $kind ) {
				$is_exc = ! empty( $entry['rule']['exclude'] );
				return 'exc' === $kind ? $is_exc : ! $is_exc;
			}
		)
	);
};

// ---- شمارش‌ها برای برچسب تب‌های استثنا.
$tcp_ex_count = array(
	'product'  => count( $tcp_only( $tcp_product_entries, 'exc' ) ),
	'category' => count( $tcp_only( $tcp_category_entries, 'exc' ) ),
	'prefix'   => count( $tcp_rules['prefixes'] ),
);
?>

<p class="tcp-lead">قیمت‌ها هنگام نمایش محاسبه می‌شوند؛ چیزی در دیتابیس نوشته نمی‌شود. محصولی که فروش ویژهٔ واقعی دارد، و قیمت همکاری، دست‌نخورده می‌مانند. اولویت: <strong>محصول تکی ← استثنای شناسه ← دسته‌بندی ← سراسری</strong>.</p>

<div class="tcp-alert tcp-alert--warn">برای افزایش دائمی (مثلاً ۱۰٪ روی قیمت خودِ هر متغیر، نوشته‌شده در دیتابیس) از تب <a href="<?php echo esc_url( TCP_Admin::url( 'bulk', array( 'target' => 'all' ) ) ); ?>">تغییر گروهی قیمت</a> و حالت «همهٔ محصولات سایت» استفاده کن. اگر این قانون روشن بماند و قیمت دیتابیس را هم بالا ببری، مشتری هر دو افزایش را با هم می‌بیند.</div>

<div class="tcp-alert tcp-alert--danger" id="tcp-stale-assets" style="display:none" role="alert"><strong>فایل‌های <span id="tcp-stale-what">جاوااسکریپت</span> این صفحه قدیمی کش شده‌اند</strong> و ظاهر/رفتار تب درست کار نمی‌کند. یک‌بار صفحه را با <kbd>Ctrl</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd> (در مک: <kbd>Cmd</kbd>+<kbd>Shift</kbd>+<kbd>R</kbd>) تازه‌سازی کن؛ اگر درست نشد، کش افزونهٔ بهینه‌ساز/کش سایت را پاک کن.</div>
<span id="tcp-css-probe" class="tcp-css-probe" aria-hidden="true"></span>
<script>
/* آشکارساز asset کش‌شده: rules.js تازه پرچم می‌گذارد، CSS تازه روی probe متغیر می‌گذارد. */
(function () {
	function tcpStaleCheck() {
		var okJs = !!window.__tcpRulesV3;
		var okCss = false;
		try {
			var probe = document.getElementById('tcp-css-probe');
			okCss = !!probe && 'rules-v3' === String(window.getComputedStyle(probe).getPropertyValue('--tcp-probe') || '').trim().replace(/[\"']/g, '');
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
		<li><strong>استثنا</strong>: محصول، دسته یا شناسه‌ای که استثنا شده از همهٔ قوانین (حتی سراسری) خارج می‌شود و قیمت اصلی‌اش را نشان می‌دهد. اگر محصولی «قانون تکی» داشته باشد، همان قانون برایش اعمال می‌شود، حتی اگر در دسته یا شناسهٔ استثنا باشد.</li>
	</ol>
</details>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcp-rules-form">
	<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Rules::ACTION_SAVE ); ?>">
	<?php wp_nonce_field( TCP_Rules::ACTION_SAVE ); ?>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>قانون سراسری</h2><p>روی همهٔ محصولات فعلی و آینده، مگر آن‌که قانون یا استثنای محصول/دسته/شناسه داشته باشند.</p></div></div>
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
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>محصولات تکی</h2><p>قانون مخصوص هر محصول. جستجو کن، چند محصول را با هم تیک بزن و یک‌باره اضافه کن. محصولی که مستثنا باشد در تب «استثنای تکی» (بخش استثناها) است.</p></div></div>
		<div class="tcp-card-body">
			<?php
			$tcp_lane( 'product', 'rule', $tcp_only( $tcp_product_entries, 'rule' ) );
			?>
		</div>
	</section>

	<section class="tcp-card" id="tcp-cats-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>دسته‌بندی‌ها</h2><p>قانون دسته روی خود دسته و همهٔ زیردسته‌هایش اعمال می‌شود. چند دسته را با هم تیک بزن و یک‌باره اضافه کن.</p></div></div>
		<div class="tcp-card-body">
			<?php if ( ! $tcp_has_cats ) : ?>
				<div class="tcp-alert tcp-alert--warn">هیچ دستهٔ محصولی در فروشگاه ساخته نشده است؛ جستجوی پایین تا ساخت دسته چیزی برای نشان‌دادن ندارد.</div>
			<?php endif; ?>
			<?php
			$tcp_lane( 'category', 'rule', $tcp_only( $tcp_category_entries, 'rule' ) );
			?>
		</div>
	</section>

	<section class="tcp-card" id="tcp-exceptions-card">
		<div class="tcp-card-head"><span class="tcp-dot tcp-dot--muted"></span><div><h2>استثناها</h2><p>این موارد از همهٔ قوانین (حتی سراسری) خارج می‌شوند و قیمت اصلی‌شان را نشان می‌دهند. اولویت: <strong>استثنای تکی ← استثنای شناسه ← استثنای دسته‌بندی</strong>؛ یعنی قانون تکی هر محصول همیشه برنده است.</p></div></div>
		<div class="tcp-card-body">
			<div class="tcp-subnav tcp-ex-tabs" role="tablist" aria-label="نوع استثنا">
				<button type="button" class="tcp-subtab is-active" role="tab" aria-selected="true" data-ex-tab="product">استثنای تکی <span class="tcp-count" data-ex-count="product"><?php echo esc_html( number_format_i18n( $tcp_ex_count['product'] ) ); ?></span></button>
				<button type="button" class="tcp-subtab" role="tab" aria-selected="false" data-ex-tab="category">استثنای دسته‌بندی <span class="tcp-count" data-ex-count="category"><?php echo esc_html( number_format_i18n( $tcp_ex_count['category'] ) ); ?></span></button>
				<button type="button" class="tcp-subtab" role="tab" aria-selected="false" data-ex-tab="prefix">استثنای شناسه (پیشوند SKU) <span class="tcp-count" data-ex-count="prefix"><?php echo esc_html( number_format_i18n( $tcp_ex_count['prefix'] ) ); ?></span></button>
			</div>

			<div class="tcp-ex-panel" role="tabpanel" data-ex-panel="product">
				<p class="tcp-muted">محصول‌هایی که اینجا باشند از همهٔ قوانین خارج‌اند. اگر محصولی قانون تکی داشته باشد، آن قانون را در «محصولات تکی» بردار و به استثنا منتقل کن.</p>
				<?php
				$tcp_lane( 'product', 'exc', $tcp_only( $tcp_product_entries, 'exc' ) );
				?>
			</div>

			<div class="tcp-ex-panel" role="tabpanel" data-ex-panel="category" hidden>
				<p class="tcp-muted">همهٔ محصولات این دسته‌ها (و زیردسته‌ها) از قوانین خارج می‌شوند؛ مگر آن‌که قانون تکی یا استثنای تکی جداگانه داشته باشند.</p>
				<?php
				$tcp_lane( 'category', 'exc', $tcp_only( $tcp_category_entries, 'exc' ) );
				?>
			</div>

			<div class="tcp-ex-panel" role="tabpanel" data-ex-panel="prefix" hidden>
				<p class="tcp-muted">حروف ابتدای SKU را وارد کن (مثلاً <code dir="ltr">CH</code>)؛ همهٔ محصولاتی که SKUشان با آن شروع شود از قوانین خارج می‌شوند. SKU واریشن‌ها هم بررسی می‌شود. بزرگی و کوچکی حروف مهم نیست. چند مورد را با ویرگول یا Enter جدا کن.</p>
				<p class="tcp-muted">مثال: <code dir="ltr">CH</code> ← <code dir="ltr">CH-001</code> و <code dir="ltr">CHX-9</code> استثنا می‌شوند، <code dir="ltr">LP180</code> نه.</p>
				<div class="tcp-prefix-add">
					<input type="text" class="tisa-input" id="tcp-prefix-input" dir="ltr" maxlength="90" placeholder="مثلاً CH" autocomplete="off" aria-label="شناسهٔ استثنا (پیشوند SKU)">
					<button type="button" class="tisa-btn tisa-btn--secondary" id="tcp-prefix-add">افزودن</button>
				</div>
				<div class="tcp-prefix-msg" id="tcp-prefix-msg" aria-live="polite"></div>
				<div class="tcp-prefix-list" id="tcp-prefix-list">
					<?php foreach ( $tcp_rules['prefixes'] as $tcp_prefix ) : ?>
						<?php $tcp_prefix_chip( $tcp_prefix, TCP_Rules::prefix_product_count( $tcp_prefix ) ); ?>
					<?php endforeach; ?>
				</div>
				<div class="tcp-product-empty" id="tcp-prefix-empty"<?php echo empty( $tcp_rules['prefixes'] ) ? '' : ' style="display:none"'; ?>>هنوز شناسه‌ای استثنا نشده است؛ بالا حروف ابتدای SKU را وارد کن.</div>
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
			</div>
			<p class="tcp-modal__hint" id="tcp-modal-hint"></p>
		</div>
		<div class="tcp-modal__foot">
			<button type="button" class="tisa-btn tisa-btn--ghost" data-tcp-close>انصراف</button>
			<button type="button" class="tisa-btn tisa-btn--primary" id="tcp-modal-save">ذخیره</button>
		</div>
	</div>
</div>
