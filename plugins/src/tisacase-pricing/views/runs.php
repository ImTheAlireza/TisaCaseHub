<?php
/**
 * تب «گزارش و بازگردانی».
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

$tcp_rows          = TCP_DB::list_runs( 100 );
$tcp_show_rollback = TCP_Settings::rollback_enabled();
$tcp_uid           = get_current_user_id();
?>

<p class="tcp-lead">تغییراتِ دارای لاگ تا پایان «مدت نگهداری لاگ» قابل بازگردانی یا دریافت به‌صورت CSV هستند. اجرای بی‌حرکت «ناتمام» می‌شود و با «ادامه» از همان نقطه پی گرفته می‌شود.</p>

<?php if ( empty( $tcp_rows ) ) : ?>
	<div class="tcp-card tcp-card--empty">هنوز اجرایی ثبت نشده است.</div>
<?php else : ?>
	<div class="tcp-card tcp-card--table">
		<table class="widefat striped tcp-runs-table">
			<thead>
				<tr>
					<th>#</th><th>نوع</th><th>عملیات</th><th>پیشرفت</th><th>آمار</th><th>وضعیت</th><th>کاربر</th><th>تاریخ</th><th>اقدامات</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $tcp_rows as $r ) :
					$row_id       = (int) $r['id'];
					$type         = sanitize_key( $r['type'] );
					$status       = sanitize_key( $r['status'] );
					$run_args     = json_decode( (string) $r['args'], true );
					$catalog_run  = is_array( $run_args ) && isset( $run_args['target_type'] ) && 'all' === $run_args['target_type'];
					$can_rollback = ( $tcp_show_rollback || $catalog_run ) && 'rollback' !== $type && in_array( $status, array( 'done', 'stopped' ), true );
					$can_resume   = 'interrupted' === $status && (int) $r['user_id'] === $tcp_uid;
					$can_stop     = 'running' === $status && (int) $r['user_id'] === $tcp_uid && 'rollback' !== $type;
					$can_cancel   = 'scheduled' === $type && 'queued' === $status;
					$finished     = in_array( $status, array( 'done', 'rolled_back' ), true );
					$progress     = ( (int) $r['total_pages'] > 0 && ! $finished )
						? min( 100, (int) round( ( (int) $r['page'] / (int) $r['total_pages'] ) * 100 ) )
						: ( $finished ? 100 : (int) $r['page'] );
					$op_val       = '' !== (string) $r['operation'] ? TCP_Ops::op_label( $r['operation'] ) : '—';
					if ( $catalog_run ) {
						$op_val .= ' — همهٔ محصولات';
					}
					if ( null !== $r['value'] && 'none' !== TCP_Ops::op_kind( $r['operation'] ) ) {
						$op_val .= ' (' . number_format_i18n( (float) $r['value'] ) . ')';
					}
					$export_url = add_query_arg(
						array( 'action' => TCP_Settings::AJAX_EXPORT, 'run_id' => $row_id, '_wpnonce' => wp_create_nonce( 'tcp_export_' . $row_id ) ),
						admin_url( 'admin-ajax.php' )
					);
					?>
					<tr data-run="<?php echo esc_attr( $row_id ); ?>" data-status="<?php echo esc_attr( $status ); ?>" data-type="<?php echo esc_attr( $type ); ?>">
						<td><span class="tisa-code"><?php echo esc_html( $row_id ); ?></span></td>
						<td><?php echo esc_html( TCP_Settings::translation( $type ) ); ?></td>
						<td><?php echo esc_html( $op_val ); ?></td>
						<td class="tcp-run-progress">
							<div class="tcp-bar"><div style="width:<?php echo esc_attr( $progress ); ?>%"></div></div>
							<span class="tcp-muted"><?php echo esc_html( $progress ); ?>٪</span>
						</td>
						<td class="tcp-run-stats">
							تغییر: <b><?php echo esc_html( number_format_i18n( (int) $r['count_updated'] ) ); ?></b> /
							رد: <b><?php echo esc_html( number_format_i18n( (int) $r['count_skipped'] ) ); ?></b> /
							خطا: <b><?php echo esc_html( number_format_i18n( (int) $r['count_errors'] ) ); ?></b>
							<?php if ( 'rollback' === $type ) : ?>
								<div class="tcp-muted">از اجرای #<?php echo esc_html( (int) $r['parent_run_id'] ); ?></div>
							<?php endif; ?>
						</td>
						<td><span class="tcp-badge tcp-st-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( TCP_Settings::translation( $status ) ); ?></span></td>
						<td><?php echo esc_html( $r['user_label'] ); ?></td>
						<td><?php echo esc_html( mysql2date( 'Y/m/d H:i', $r['created_at'] ) ); ?></td>
						<td class="tcp-run-actions">
							<a class="button button-small" href="<?php echo esc_url( $export_url ); ?>">CSV</a>
							<?php if ( $can_resume ) : ?><button type="button" class="button button-small tcp-act-resume">ادامه</button><?php endif; ?>
							<?php if ( $can_stop ) : ?><button type="button" class="button button-small tcp-act-stop">توقف</button><?php endif; ?>
							<?php if ( $can_rollback ) : ?><button type="button" class="button button-small tcp-act-rollback">بازگردانی</button><?php endif; ?>
							<?php if ( $can_cancel ) : ?><button type="button" class="button button-small tcp-act-cancel">انصراف از صف</button><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
