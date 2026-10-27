<?php
defined( 'ABSPATH' ) || exit;
$all_mappings = TCAS_Mapping::all( 1000 );
$connected_groups = TCAS_Mapping::group_rows( $all_mappings );
$recent_groups = array_slice( $connected_groups, 0, 4 );
?>
<section class="tcas-card tcas-converter-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↗</span><h2>تبدیل لینک به شناسه</h2></div><span class="tcas-tag">TisaCase + <?php echo esc_html( $partner_label ); ?></span></div>
	<div class="tcas-converter-flow">
		<label class="tcas-converter-source"><span class="tcas-shop-label">جست‌وجوی محصول TisaCase (نام یا SKU) یا چسباندن لینک</span><span class="tcas-converter-input-row"><input id="tcas-link-input" type="text" dir="auto" placeholder="مثلاً قاب شیائومی یا TS0100 — یا لینک کامل محصول" autocomplete="off" spellcheck="false"><button type="button" id="tcas-link-resolve" class="button button-primary tcas-btn-primary">تبدیل لینک</button></span></label>
		<div id="tcas-link-result" class="tcas-converter-result" hidden>
			<div class="tcas-converter-result-meta"><span id="tcas-link-shop" class="tcas-shop-label">SKU / ID</span><small id="tcas-link-product"></small></div>
			<label id="tcas-link-variation-wrap" class="tcas-link-variation" hidden><span class="tcas-shop-label">انتخاب Variation</span><select id="tcas-link-variation" aria-label="انتخاب Variation"></select></label>
			<span class="tcas-converter-reference"><input id="tcas-link-sku" type="text" dir="ltr" placeholder="SKU / ID" readonly><button type="button" id="tcas-link-copy" class="button" disabled>کپی</button></span>
		</div>
		<div id="tcas-link-status" class="tcas-status" role="status" aria-live="polite"></div>
	</div>
</section>

<section class="tcas-card tcas-smart-card">
	<div class="tcas-card-head"><div><span class="tcas-step">⌁</span><h2>تطبیق خودکار Variationها</h2></div><span class="tcas-tag">مرجع دقیق</span></div>
	<div class="tcas-smart-grid">
		<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس <bdi>TisaCase</bdi></span><input id="tcas-smart-local" type="text" dir="auto" placeholder="جست‌وجوی نام/SKU محصول TisaCase یا لینک و ID" autocomplete="off" spellcheck="false"></label>
		<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label"><?php echo esc_html( $partner_label ); ?></span><input id="tcas-smart-remote" type="text" dir="ltr" placeholder="لینک، SKU یا ID محصول متغیر" autocomplete="off" spellcheck="false"></label>
		<div class="tcas-smart-actions"><button type="button" id="tcas-smart-match" class="button button-primary tcas-btn-primary">یافتن جفت‌ها</button><button type="button" id="tcas-smart-next" class="button" hidden>دسته بعد</button></div>
	</div>
	<div id="tcas-smart-status" class="tcas-status" role="status" aria-live="polite">موارد مبهم و بدون تطبیق وصل نمی‌شوند.</div>
	<section id="tcas-smart-issues" class="tcas-smart-issues" aria-live="polite" hidden>
		<div class="tcas-smart-issues-head"><h3>موارد نیازمند بررسی</h3><span id="tcas-smart-issues-count" class="tcas-smart-issues-count"></span></div>
		<p class="tcas-smart-issues-help">هر Variation رد‌شده با دلیل دقیق مشکلش علامت خورده است: مرجع تکراری، چند گزینهٔ محتمل، کلید مشترک، همتای نامعتبر، نبود مرجع یا نبود همتا. با «بررسی دستی» یک کارت جفت با همان مرجع ساخته می‌شود تا سمت مقابل را خودتان کامل کنید.</p>
		<div id="tcas-smart-issue-filters" class="tcas-smart-issue-filters" role="group" aria-label="فیلتر موارد نیازمند بررسی"></div>
		<div id="tcas-smart-issue-list" class="tcas-smart-issue-list" role="list"></div>
	</section>
</section>

<section class="tcas-card tcas-pairs-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">＋</span><h2>جفت‌های تازه</h2></div>
		<div class="tcas-pairs-actions">
			<span class="tcas-muted">حداکثر <?php echo (int) TCAS_Core::MAX_CODES; ?> جفت</span>
			<button type="button" id="tcas-select-valid" class="button">انتخاب همه</button>
			<button type="button" id="tcas-preview-start" class="button">بررسی همه دوباره</button>
			<button type="button" id="tcas-pair-add" class="button">افزودن جفت</button>
		</div>
	</div>
	<p class="tcas-pairs-help">هر کارت را خودش بررسی می‌کند: با اسپینر، موجودی تازهٔ هر دو فروشگاه و وضعیت قطعی/نیاز به بررسی همان بالا. کادر سبز یعنی جفت قطعی؛ زرد یعنی مشکوک و نیاز به بررسی؛ قرمز یعنی یک سمت پیدا نشده یا کد ندارد. بعد از هر ویرایش، همان کارت دوباره بررسی می‌شود.</p>
	<div id="tcas-pair-entries" class="tcas-pair-entries">
		<article class="tcas-pair-entry" data-state="idle" data-confidence="manual">
			<button type="button" class="tcas-remove-pair" aria-label="حذف جفت" disabled>×</button>
			<div class="tcas-pair-top">
				<span class="tcas-pair-flag tcas-pair-flag-idle">در انتظار بررسی</span>
				<span class="tcas-spinner" aria-hidden="true" hidden></span>
				<label class="tcas-pair-select"><input class="tcas-row-select" type="checkbox" disabled><span>انتخاب</span></label>
			</div>
			<small class="tcas-pair-flag-note" hidden></small>
			<div class="tcas-pair-fields">
				<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس <bdi>TisaCase</bdi></span><input class="tcas-local-code" type="text" dir="ltr" placeholder="TS0100 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
				<span class="tcas-pair-link" aria-hidden="true">+</span>
				<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label"><?php echo esc_html( $partner_label ); ?></span><input class="tcas-remote-code" type="text" dir="ltr" placeholder="SKU / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
			</div>
			<div class="tcas-pair-stock" hidden></div>
			<small class="tcas-pair-message"></small>
		</article>
	</div>
	<template id="tcas-pair-entry-template">
		<article class="tcas-pair-entry" data-state="idle" data-confidence="manual">
			<button type="button" class="tcas-remove-pair" aria-label="حذف جفت">×</button>
			<div class="tcas-pair-top">
				<span class="tcas-pair-flag tcas-pair-flag-idle">در انتظار بررسی</span>
				<span class="tcas-spinner" aria-hidden="true" hidden></span>
				<label class="tcas-pair-select"><input class="tcas-row-select" type="checkbox" disabled><span>انتخاب</span></label>
			</div>
			<small class="tcas-pair-flag-note" hidden></small>
			<div class="tcas-pair-fields">
				<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس <bdi>TisaCase</bdi></span><input class="tcas-local-code" type="text" dir="ltr" placeholder="TS0100 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
				<span class="tcas-pair-link" aria-hidden="true">+</span>
				<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label"><?php echo esc_html( $partner_label ); ?></span><input class="tcas-remote-code" type="text" dir="ltr" placeholder="SKU / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
			</div>
			<div class="tcas-pair-stock" hidden></div>
			<small class="tcas-pair-message"></small>
		</article>
	</template>
	<div class="tcas-pair-actions">
		<button type="button" id="tcas-connect-start" class="button button-primary tcas-btn-primary" disabled>اتصال انتخاب‌شده‌ها</button>
		<span id="tcas-preview-status" class="tcas-status" role="status" aria-live="polite"></span>
		<span id="tcas-connect-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت بررسی"></progress><span>۰٪</span></div>
	<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت اتصال"></progress><span>۰٪</span></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>اتصال‌های اخیر</h2></div><a class="tcas-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TCAS_Core::PAGE . '&tab=mappings&partner_id=' . rawurlencode( $partner_id ) ) ); ?>">مشاهده همه</a></div>
	<div id="tcas-sync-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php $groups = $recent_groups; require TCAS_PATH . 'views/linked-groups.php'; ?>
</section>
