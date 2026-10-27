<?php
defined( 'ABSPATH' ) || exit;
$mappings = TCAS_Mapping::all( 1000 );
$groups = TCAS_Mapping::group_rows( $mappings );
?>
<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">＋</span><h2>اتصال سریع</h2></div></div>
	<div class="tcas-inline-form">
		<label><span>SKU یا ID در تیساکیس</span><input id="tcas-map-sku" type="text" dir="ltr" placeholder="TS0100 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
		<label class="tcas-grow"><span>SKU در <?php echo esc_html( $partner_label ); ?></span><input id="tcas-map-code" type="text" dir="ltr" placeholder="SKU / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
		<button type="button" id="tcas-map-add" class="button button-primary tcas-btn-primary">اتصال</button>
	</div>
	<div id="tcas-map-status" class="tcas-status" role="status" aria-live="polite"></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>محصولات متصل</h2></div><span class="tcas-count"><?php echo count( $mappings ); ?></span></div>
	<div id="tcas-sync-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php require TCAS_PATH . 'views/linked-groups.php'; ?>
</section>

<?php $tcas_orphans = TCAS_Mapping::orphans(); ?>
<?php if ( $tcas_orphans ) : ?>
<section class="tcas-card tcas-broken-card" id="tcas-broken-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">!</span><h2>نگاشت‌های خراب</h2></div>
		<span class="tcas-count tcas-count-warn"><?php echo count( $tcas_orphans ); ?></span>
	</div>
	<p class="tcas-warn-help">
		این محصولات در فهرست «محصولات متصل» دیده نمی‌شوند (حذف شده‌اند یا در زباله‌دان‌اند) ولی مرجع همکار را گرفته‌اند؛
		همین‌ها باعث می‌شوند اتصال Variationهای تازه پیام «مرجع از قبل متصل است» بگیرد. تا وقتی اینجا هستند، فهرست بالا کامل نیست.
	</p>
	<div id="tcas-broken-status" class="tcas-status" role="status" aria-live="polite"></div>
	<ul class="tcas-broken-list">
		<?php foreach ( $tcas_orphans as $tcas_orphan ) : ?>
			<li class="tcas-broken-row">
				<code dir="ltr"><?php echo esc_html( $tcas_orphan['code'] ); ?></code>
				<span class="tcas-broken-id">#<?php echo (int) $tcas_orphan['id']; ?></span>
				<?php if ( '' !== (string) $tcas_orphan['title'] ) : ?><strong><?php echo esc_html( $tcas_orphan['title'] ); ?></strong><?php endif; ?>
				<small><?php echo esc_html( $tcas_orphan['reason'] ); ?></small>
				<button type="button" class="button tcas-disconnect" data-product-id="<?php echo (int) $tcas_orphan['id']; ?>">حذف</button>
			</li>
		<?php endforeach; ?>
	</ul>
	<button type="button" class="button button-primary tcas-clear-broken">پاک‌سازی همهٔ نگاشت‌های خراب</button>
</section>
<?php endif; ?>
