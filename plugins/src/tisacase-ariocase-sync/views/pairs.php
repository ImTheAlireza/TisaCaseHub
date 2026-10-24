<?php
defined( 'ABSPATH' ) || exit;
$mappings = TCAS_Mapping::all( 6 );
?>
<section class="tcas-card tcas-converter-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↗</span><h2>تبدیل لینک به شناسه</h2></div><span class="tcas-tag">TisaCase + ArioCase</span></div>
	<div class="tcas-converter-layout">
		<label class="tcas-converter-source"><span class="tcas-shop-label">لینک محصول</span><span class="tcas-input-action"><input id="tcas-link-input" type="url" dir="ltr" placeholder="https://..." autocomplete="off"><button type="button" id="tcas-link-resolve" class="button button-primary tcas-btn-primary">تبدیل</button></span></label>
		<span class="tcas-converter-arrow" aria-hidden="true">←</span>
		<div id="tcas-link-result" class="tcas-converter-result">
			<span id="tcas-link-shop" class="tcas-shop-label">SKU / ID</span>
			<span class="tcas-input-action"><input id="tcas-link-sku" type="text" dir="ltr" placeholder="SKU / ID" readonly><button type="button" id="tcas-link-copy" class="button" disabled>کپی</button></span>
			<small id="tcas-link-product"></small>
		</div>
	</div>
	<div id="tcas-link-status" class="tcas-status" role="status" aria-live="polite"></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">＋</span><h2>جفت‌های تازه</h2></div>
		<span class="tcas-muted">حداکثر <?php echo (int) TCAS_Core::MAX_CODES; ?> جفت</span>
	</div>
	<div id="tcas-pair-entries" class="tcas-pair-entries">
		<article class="tcas-pair-entry">
			<button type="button" class="tcas-remove-pair" aria-label="حذف جفت" disabled>×</button>
			<div class="tcas-pair-fields">
				<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس <bdi>TisaCase</bdi></span><input class="tcas-local-code" type="text" dir="ltr" placeholder="TS0100" maxlength="190" autocomplete="off" spellcheck="false"></label>
				<span class="tcas-pair-link" aria-hidden="true">+</span>
				<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label">آریوکیس <bdi>ArioCase</bdi></span><input class="tcas-remote-code" type="text" dir="ltr" placeholder="AR22 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
			</div>
		</article>
	</div>
	<template id="tcas-pair-entry-template">
		<article class="tcas-pair-entry">
			<button type="button" class="tcas-remove-pair" aria-label="حذف جفت">×</button>
			<div class="tcas-pair-fields">
				<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس <bdi>TisaCase</bdi></span><input class="tcas-local-code" type="text" dir="ltr" placeholder="TS0100" maxlength="190" autocomplete="off" spellcheck="false"></label>
				<span class="tcas-pair-link" aria-hidden="true">+</span>
				<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label">آریوکیس <bdi>ArioCase</bdi></span><input class="tcas-remote-code" type="text" dir="ltr" placeholder="AR22 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
			</div>
		</article>
	</template>
	<div class="tcas-actions">
		<button type="button" id="tcas-preview-start" class="button button-primary tcas-btn-primary">بررسی و پیش‌نمایش</button>
		<button type="button" id="tcas-pair-add" class="button">افزودن جفت</button>
		<span id="tcas-preview-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت بررسی"></progress><span>۰٪</span></div>
</section>

<section id="tcas-preview-panel" class="tcas-card" hidden>
	<div class="tcas-card-head">
		<div><span class="tcas-step">✓</span><h2>پیش‌نمایش اتصال</h2><span class="tcas-tag">فقط موجودی</span></div>
		<button type="button" id="tcas-select-valid" class="button">انتخاب همه</button>
	</div>
	<div id="tcas-preview-list" class="tcas-preview-list"></div>
	<div class="tcas-actions">
		<button type="button" id="tcas-connect-start" class="button button-primary tcas-btn-primary">اتصال انتخاب‌شده‌ها</button>
		<span id="tcas-connect-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت اتصال"></progress><span>۰٪</span></div>
	<div id="tcas-connect-results" class="tcas-results" aria-live="polite"></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>اتصال‌های اخیر</h2></div><a class="tcas-card-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TCAS_Core::PAGE . '&tab=mappings' ) ); ?>">مشاهده همه</a></div>
	<div id="tcas-sync-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php if ( ! $mappings ) : ?>
		<div class="tcas-empty">هنوز اتصالی ساخته نشده است.</div>
	<?php else : ?>
		<div class="tcas-pair-list">
			<?php foreach ( $mappings as $row ) : ?>
				<article class="tcas-pair-card">
					<div class="tcas-pair-fields">
						<label class="tcas-shop-field tcas-shop-local"><span class="tcas-shop-label">تیساکیس</span><input type="text" dir="ltr" value="<?php echo esc_attr( $row['sku'] ); ?>" readonly></label>
						<span class="tcas-pair-link" aria-hidden="true">+</span>
						<label class="tcas-shop-field tcas-shop-remote"><span class="tcas-shop-label">آریوکیس</span><input type="text" dir="ltr" value="<?php echo esc_attr( $row['remote_code'] ); ?>" readonly></label>
					</div>
					<div class="tcas-pair-card-foot">
						<span class="tcas-stock-pill"><?php echo '' !== (string) $row['quantity'] ? esc_html( $row['quantity'] ) : '—'; ?> · <?php echo esc_html( TCAS_Stock::status_label( $row['status'] ) ); ?></span>
						<span class="tcas-pair-card-actions"><?php if ( $row['enabled'] ) : ?><button type="button" class="button tcas-sync-one" data-product-id="<?php echo (int) $row['id']; ?>">سینک</button><?php endif; ?><button type="button" class="tcas-disconnect" data-product-id="<?php echo (int) $row['id']; ?>">قطع اتصال</button></span>
					</div>
					<a class="tcas-pair-product" href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) ); ?>">#<?php echo (int) $row['id']; ?> · <?php echo esc_html( $row['name'] ); ?></a>
					<?php if ( $row['error'] ) : ?><small class="tcas-error-text"><?php echo esc_html( $row['error'] ); ?></small><?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
