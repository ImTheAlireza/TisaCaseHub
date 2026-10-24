<?php defined( 'ABSPATH' ) || exit; ?>
<section class="tcas-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">＋</span><h2>افزودن جفت</h2></div>
		<span class="tcas-muted">حداکثر <?php echo (int) TCAS_Core::MAX_CODES; ?> جفت</span>
	</div>
	<label class="tcas-label" for="tcas-pairs">SKU تیساکیس <bdi>-</bdi> لینک یا SKU در ArioCase</label>
	<textarea id="tcas-pairs" class="tcas-textarea tcas-code-input" rows="5" dir="ltr" placeholder="AR120 - AR22&#10;CH100 - https://ariocase.com/product/case-slug/"></textarea>
	<div class="tcas-actions">
		<button type="button" id="tcas-preview-start" class="button button-primary tcas-btn-primary">بررسی و پیش‌نمایش</button>
		<span id="tcas-preview-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت بررسی"></progress><span>۰٪</span></div>
</section>

<section id="tcas-preview-panel" class="tcas-card" hidden>
	<div class="tcas-card-head">
		<div><span class="tcas-step">✓</span><h2>پیش‌نمایش اتصال</h2><span class="tcas-tag">فقط موجودی</span></div>
		<button type="button" id="tcas-select-valid" class="button">انتخاب همه</button>
	</div>
	<div class="tcas-table-wrap">
		<table class="widefat striped tcas-table" id="tcas-preview-table">
			<thead><tr><th class="tcas-check-col"><span class="screen-reader-text">انتخاب</span></th><th>تیساکیس</th><th>ArioCase</th><th>موجودی</th><th>وضعیت</th></tr></thead>
			<tbody></tbody>
		</table>
	</div>
	<div class="tcas-actions">
		<button type="button" id="tcas-connect-start" class="button button-primary tcas-btn-primary">اتصال انتخاب‌شده‌ها</button>
		<span id="tcas-connect-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0" aria-label="پیشرفت اتصال"></progress><span>۰٪</span></div>
	<div id="tcas-connect-results" class="tcas-results" aria-live="polite"></div>
</section>
