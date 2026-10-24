<?php defined( 'ABSPATH' ) || exit; ?>
<section class="tcas-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">۱</span><h2>کدهای محصول را وارد کنید</h2></div>
		<span class="tcas-muted">حداکثر <?php echo (int) TCAS_Core::MAX_CODES; ?> کد در هر دسته</span>
	</div>
	<p class="tcas-help">هر خط یک کد/SKU. ابتدا فقط پیش‌نمایش گرفته می‌شود و هیچ محصول یا موجودی تغییر نمی‌کند.</p>
	<label class="tcas-label" for="tcas-codes">کدهای تیساکیس / ArioCase</label>
	<textarea id="tcas-codes" class="tcas-textarea tcas-code-input" rows="7" dir="ltr" placeholder="CH1001&#10;CH1002&#10;AR-BLUE-17"></textarea>
	<div class="tcas-actions">
		<button type="button" id="tcas-preview-start" class="button button-primary tcas-btn-primary">دریافت پیش‌نمایش</button>
		<span id="tcas-preview-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
</section>

<section id="tcas-preview-panel" class="tcas-card" hidden>
	<div class="tcas-card-head">
		<div><span class="tcas-step">۲</span><h2>نتیجه را بررسی و تأیید کنید</h2></div>
		<button type="button" id="tcas-select-valid" class="button">انتخاب موارد قابل‌اجرا</button>
	</div>
	<div class="tcas-table-wrap">
		<table class="widefat striped tcas-table" id="tcas-preview-table">
			<thead><tr><th class="tcas-check-col">انتخاب</th><th>کد</th><th>محصول ArioCase</th><th>وضعیت محلی</th><th>موجودی خوانده‌شده</th><th>نتیجه / دلیل</th></tr></thead>
			<tbody></tbody>
		</table>
	</div>
	<div class="tcas-import-options">
		<label><input type="checkbox" id="tcas-import-prices" checked> قیمت محصولات جدید از API خوانده شود</label>
		<label><input type="checkbox" id="tcas-import-images" checked> حداکثر ۴ تصویر محصولات جدید دانلود شود</label>
		<span class="tcas-help">برای محصولات موجود فقط اتصال و موجودی اعمال می‌شود؛ عنوان، قیمت و تصویر قبلی بازنویسی نمی‌شود.</span>
	</div>
	<div class="tcas-actions">
		<button type="button" id="tcas-import-start" class="button button-primary tcas-btn-primary">ایمپورت / اتصال انتخابی‌ها</button>
		<span id="tcas-import-status" class="tcas-status" role="status" aria-live="polite"></span>
	</div>
	<div id="tcas-import-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
	<div id="tcas-import-results" class="tcas-results" aria-live="polite"></div>
</section>

<section class="tcas-card tcas-info-card">
	<h2>قواعد امن ایمپورت</h2>
	<ul class="tcas-rules">
		<li>SKU تکراری در ووکامرس یا کد تکراری در ArioCase متوقف می‌شود.</li>
		<li>محصول جدید همیشه پیش‌نویس است و خودکار منتشر نمی‌شود.</li>
		<li>محصول متغیر بدون جزئیات تنوع و ویژگی‌های آن ایمپورت نمی‌شود.</li>
		<li>پاسخ نامعتبر یا قطعی API به معنای موجودی صفر نیست.</li>
	</ul>
</section>
