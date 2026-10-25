<?php
/** Grouped connected-product cards used by the pairs and mappings screens. */
defined( 'ABSPATH' ) || exit;
$groups = isset( $groups ) && is_array( $groups ) ? $groups : array();
?>
<?php if ( ! $groups ) : ?>
	<div class="tcas-empty">هنوز اتصالی ساخته نشده است.</div>
<?php else : ?>
	<div class="tcas-linked-groups">
		<?php foreach ( $groups as $group ) : ?>
			<details class="tcas-linked-group">
				<summary class="tcas-linked-group-summary">
					<span class="tcas-linked-group-title">
						<strong class="tcas-local-group-title">TisaCase · <?php echo esc_html( $group['local_parent_name'] ); ?></strong>
						<span aria-hidden="true">↔</span>
						<strong class="tcas-remote-group-title">ArioCase · <?php echo esc_html( $group['remote_parent_name'] ); ?></strong>
					</span>
					<span class="tcas-linked-group-count"><?php echo (int) $group['count']; ?> متغیر متصل</span>
				</summary>
				<div class="tcas-linked-variation-grid">
					<?php foreach ( $group['rows'] as $row ) : ?>
						<article class="tcas-linked-variation-card">
							<div class="tcas-linked-variation-heading">
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row['id'] . '&action=edit' ) ); ?>">#<?php echo (int) $row['id']; ?> · <?php echo esc_html( $row['name'] ); ?></a>
								<?php if ( $row['error'] ) : ?><small class="tcas-error-text"><?php echo esc_html( $row['error'] ); ?></small><?php endif; ?>
							</div>
							<div class="tcas-linked-codes">
								<div><span>TisaCase</span><code dir="ltr"><?php echo esc_html( '' !== (string) $row['sku'] ? $row['sku'] : 'ID:' . (int) $row['id'] ); ?></code></div>
								<div><span>ArioCase</span><?php if ( ! empty( $row['remote_name'] ) ) : ?><small><?php echo esc_html( $row['remote_name'] ); ?></small><?php endif; ?><code dir="ltr"><?php echo esc_html( $row['remote_code'] ); ?></code><?php if ( ! empty( $row['remote_id'] ) ) : ?><small dir="ltr">ID: <?php echo (int) $row['remote_id']; ?></small><?php endif; ?></div>
							</div>
							<div class="tcas-linked-variation-foot">
								<span class="tcas-stock-pill"><strong><?php echo '' !== (string) $row['quantity'] ? esc_html( $row['quantity'] ) : '—'; ?></strong> · <?php echo esc_html( TCAS_Stock::status_label( $row['status'] ) ); ?></span>
								<?php if ( $row['fetched_at'] ) : ?><small><?php echo esc_html( wp_date( 'Y/m/d H:i', $row['fetched_at'] ) ); ?></small><?php endif; ?>
								<span class="tcas-linked-actions">
									<?php if ( $row['enabled'] ) : ?><button type="button" class="button tcas-sync-one" data-product-id="<?php echo (int) $row['id']; ?>">سینک</button><?php endif; ?>
									<button type="button" class="tcas-disconnect" data-product-id="<?php echo (int) $row['id']; ?>">قطع اتصال</button>
								</span>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</details>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
