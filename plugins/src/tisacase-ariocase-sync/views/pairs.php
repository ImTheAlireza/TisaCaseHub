<?php defined( 'ABSPATH' ) || exit; ?>
<section class="tcas-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">۱</span><h2>جفت‌های دو محصول را وارد کنید</h2></div>
		<span class="tcas-muted">حداکثر <?php echo (int) TCAS_Core::MAX_CODES; ?> جفت در هر دسته</span>
	</div>
	<p class="tcas-help">در هر خط اول SKU محصول موجود در تیساکیس، سپس <code>-</code> و کد همان قلم در ArioCase. هر دو محصول باید از قبل وجود داشته باشند.</p>
	<label class="tcas-label" for="tcas-pairs">SKU تیساکیس - کد ArioCase</label>
	<textarea id="tcas-pairs" class="tcas-textarea tcas-code-input" rows="7" dir="ltr" placeholder="AR120 - AR22&#10;CH100 - AR55"></textarea>
	<div class="tcas-actions">
		<button type="button" id="tcas-preview-start" class="button button-primary tcas-btn-primary">بررسی جفت‌ها</button>
		<span id="tcas-preview-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
</section>

<section id="tcas-preview-panel" class="tcas-card" hidden>
	<div class="tcas-card-head">
		<div><span class="tcas-step">۲</span><h2>جفت‌ها و موجودی را بررسی کنید</h2></div>
		<button type="button" id="tcas-select-valid" class="button">انتخاب موارد قابل‌اتصال</button>
	</div>
	<div class="tcas-table-wrap">
		<table class="widefat striped tcas-table" id="tcas-preview-table">
			<thead><tr><th class="tcas-check-col">انتخاب</th><th>محصول تیساکیس</th><th>محصول ArioCase</th><th>موجودی ArioCase</th><th>نتیجه / دلیل</th></tr></thead>
			<tbody></tbody>
		</table>
	</div>
	<p class="tcas-notice-box">اجرای اتصال فقط نگاشت داخلی و موجودی را تغییر می‌دهد. نام، قیمت، SKU، تصویر، توضیحات و سایر مشخصات هر دو محصول دست‌نخورده می‌مانند.</p>
	<div class="tcas-actions">
		<button type="button" id="tcas-connect-start" class="button button-primary tcas-btn-primary">اتصال جفت‌های انتخابی</button>
		<span id="tcas-connect-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
	<div id="tcas-connect-results" class="tcas-results" aria-live="polite"></div>
</section>

<section class="tcas-card tcas-info-card">
	<h2>قواعد اتصال جفتی</h2>
	<ul class="tcas-rules">
		<li>هیچ محصولی ساخته، حذف یا منتشر نمی‌شود؛ نبود هر یک از دو کد یعنی خطا.</li>
		<li>فقط موجودی خوانده و اعمال می‌شود؛ قیمت، نام و سایر فیلدها کپی یا سینک نمی‌شوند.</li>
		<li>هر SKU تیساکیس و هر کد ArioCase فقط در یک جفت فعال قرار می‌گیرد.</li>
		<li>برای محصول متغیر، هر Variation باید با Variation مقابل جفت شود؛ والد مستقیماً سینک نمی‌شود.</li>
	</ul>
</section>
