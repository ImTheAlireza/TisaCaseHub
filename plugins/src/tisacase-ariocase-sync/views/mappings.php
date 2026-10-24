<?php
defined( 'ABSPATH' ) || exit;
$mappings = TCAS_Mapping::all();
?>
<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">+</span><h2>اتصال دستی با SKU</h2></div></div>
	<p class="tcas-help">همان اتصال جفتی برای یک محصول: SKU دقیق تیساکیس و کد همان قلم در ArioCase. اتصال فقط بعد از تأیید هر دو محصول و خواندن موجودی انجام می‌شود.</p>
	<div class="tcas-inline-form">
		<label><span>SKU در تیساکیس</span><input id="tcas-map-sku" type="text" dir="ltr" placeholder="CH1001-RED"></label>
		<label><span>کد در ArioCase</span><input id="tcas-map-code" type="text" dir="ltr" placeholder="ARIO-8841"></label>
		<button type="button" id="tcas-map-add" class="button button-primary tcas-btn-primary">ساخت اتصال</button>
	</div>
	<div id="tcas-map-status" class="tcas-status" role="status" aria-live="polite"></div>
</section>

<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">↻</span><h2>محصولات متصل</h2></div><span class="tcas-muted"><?php echo count( $mappings ); ?> نگاشت</span></div>
	<div id="tcas-sync-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php if ( ! $mappings ) : ?>
		<div class="tcas-empty">هنوز محصولی متصل نشده است. از تب «اتصال جفتی» یا فرم بالا شروع کنید.</div>
	<?php else : ?>
	<div class="tcas-table-wrap">
		<table class="widefat striped tcas-table">
			<thead><tr><th>محصول</th><th>SKU محلی</th><th>کد ArioCase</th><th>نوع</th><th>آخرین موجودی</th><th>آخرین دریافت</th><th>عملیات</th></tr></thead>
			<tbody>
			<?php foreach ( $mappings as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['id'] . '&action=edit' ) ); ?>">#<?php echo (int) $row['id']; ?> — <?php echo esc_html( $row['name'] ); ?></a><?php if ( ! $row['enabled'] ) : ?><span class="tcas-tag">فقط والد</span><?php endif; ?><?php if ( $row['error'] ) : ?><small class="tcas-error-text"><?php echo esc_html( $row['error'] ); ?></small><?php endif; ?></td>
					<td><code dir="ltr"><?php echo esc_html( $row['sku'] ); ?></code></td>
					<td><code dir="ltr"><?php echo esc_html( $row['remote_code'] ); ?></code><?php if ( ! empty( $row['remote_id'] ) ) : ?><small dir="ltr">ID: <?php echo (int) $row['remote_id']; ?></small><?php endif; ?></td>
					<td><?php echo esc_html( $row['type'] ); ?></td>
					<td><strong><?php echo '' !== (string) $row['quantity'] ? esc_html( $row['quantity'] ) : '—'; ?></strong><small><?php echo esc_html( TCAS_Stock::status_label( $row['status'] ) ); ?></small></td>
					<td><?php echo $row['fetched_at'] ? esc_html( wp_date( 'Y/m/d H:i', $row['fetched_at'] ) ) : '—'; ?></td>
					<td class="tcas-row-actions">
						<?php if ( $row['enabled'] ) : ?><button type="button" class="button tcas-sync-one" data-product-id="<?php echo (int) $row['id']; ?>">سینک</button><?php endif; ?>
						<button type="button" class="button-link-delete tcas-disconnect" data-product-id="<?php echo (int) $row['id']; ?>">حذف اتصال</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</section>
