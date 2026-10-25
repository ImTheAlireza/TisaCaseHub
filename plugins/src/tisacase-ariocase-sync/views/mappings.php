<?php
defined( 'ABSPATH' ) || exit;
$mappings = TCAS_Mapping::all( 1000 );
$groups = TCAS_Mapping::group_rows( $mappings );
?>
<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">＋</span><h2>اتصال سریع</h2></div></div>
	<div class="tcas-inline-form">
		<label><span>SKU یا ID در تیساکیس</span><input id="tcas-map-sku" type="text" dir="ltr" placeholder="TS0100 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
		<label class="tcas-grow"><span>SKU در ArioCase</span><input id="tcas-map-code" type="text" dir="ltr" placeholder="AR22 / ID:123" maxlength="190" autocomplete="off" spellcheck="false"></label>
		<button type="button" id="tcas-map-add" class="button button-primary tcas-btn-primary">اتصال</button>
	</div>
	<div id="tcas-map-status" class="tcas-status" role="status" aria-live="polite"></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>محصولات متصل</h2></div><span class="tcas-count"><?php echo count( $mappings ); ?></span></div>
	<div id="tcas-sync-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php require TCAS_PATH . 'views/linked-groups.php'; ?>
</section>
