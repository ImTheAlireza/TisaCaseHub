<?php
/**
 * کارت افزونه در لانچر.
 *
 * مهندسی رنگ کارت: در هر کارت فقط **یک** عنصر پررنگ هست — دکمهٔ «باز کردن».
 * بقیه (وضعیت، نسخه، سنجاق، مخفی‌کردن، غیرفعال‌سازی) خاکستریِ آرام‌اند و تنها
 * با hover رنگ می‌گیرند. تأیید غیرفعال‌سازی پیش‌فرض بسته است و فقط وقتی کاربر
 * خودش ⏻ را بزند، جای ردیف اکشن را می‌گیرد (نه confirm() بومی).
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tsh_card' ) ) {

	/**
	 * رندر یک کارت.
	 *
	 * @param array $item     آیتم resolve‌شدهٔ registry.
	 * @param array $pins     کلیدهای سنجاق‌شده.
	 * @param array $settings تنظیمات هاب.
	 * @return string HTML.
	 */
	function tsh_card( $item, $pins, $settings ) {
		$state  = isset( $item['state'] ) ? $item['state'] : 'missing';
		$cls    = 'tisa-plugin-card tisa-hub-tile tsh-card';
		$pages  = ! empty( $item['pages'] ) ? (array) $item['pages'] : array();
		$main   = $pages ? $pages[0] : array();
		$url    = isset( $main['url'] ) ? $main['url'] : '';
		$tip    = isset( $item['tip'] ) ? (string) $item['tip'] : '';
		$open   = (bool) $url && 'active' === $state && ! empty( $item['can'] );
		$pinned = in_array( $item['key'], (array) $pins, true );

		if ( in_array( $state, array( 'inactive', 'missing' ), true ) ) {
			$cls .= ' is-inactive';
		}
		if ( $url && empty( $item['can'] ) ) {
			$cls .= ' is-nocap';
		}
		if ( $pinned ) {
			$cls .= ' is-pinned';
		}

		$search = strtolower(
			wp_strip_all_tags(
				implode(
					' ',
					array(
						$item['title'],
						$item['desc'],
						$item['dir'],
						$item['version'],
						$item['key'],
						isset( $item['group'] ) ? $item['group'] : '',
						isset( $item['name'] ) ? $item['name'] : '',
					)
				)
			)
		);

		$act_url = static function ( $task ) use ( $item ) {
			return wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=' . $task . '&item=' . $item['key'] ), 'tsh_action' );
		};

		ob_start();
		?>
		<article class="<?php echo esc_attr( $cls ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>" data-group="<?php echo esc_attr( isset( $item['group'] ) ? $item['group'] : 'products' ); ?>" data-search="<?php echo esc_attr( $search ); ?>" tabindex="-1">

			<div class="tsh-card__top">
				<span class="tisa-plugin-card__icon tsh-card__icon" aria-hidden="true"><?php echo TSH_View::icon( $item['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<div class="tsh-card__text">
					<?php if ( $open ) : ?>
						<a class="tisa-plugin-card__title tsh-card__title tisa-hub-tile__go" data-open="1" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>><?php echo esc_html( $item['title'] ); ?></a>
					<?php else : ?>
						<b class="tisa-plugin-card__title tsh-card__title"><?php echo esc_html( $item['title'] ); ?></b>
					<?php endif; ?>
					<span class="tsh-card__meta">
						<?php if ( 'active' === $state ) : ?>
							<span class="tsh-state is-on"><i></i><?php esc_html_e( 'فعال', 'tisacase-hub' ); ?></span>
						<?php elseif ( 'inactive' === $state ) : ?>
							<span class="tsh-state"><i></i><?php esc_html_e( 'غیرفعال', 'tisacase-hub' ); ?></span>
						<?php else : ?>
							<span class="tsh-state is-warn"><i></i><?php esc_html_e( 'نصب نیست', 'tisacase-hub' ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $item['version'] ) ) : ?>
							<span class="tsh-ver" dir="ltr"<?php echo ! empty( $item['dupes'] ) ? ' title="' . esc_attr( sprintf( /* translators: %s: folder names */ __( 'نسخهٔ دیگری از همین افزونه هم نصب است: %s', 'tisacase-hub' ), implode( '، ', (array) $item['dupes'] ) ) ) . '"' : ''; ?>><?php echo esc_html( $item['version'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $item['dupes'] ) ) : ?>
							<span class="tsh-ver" dir="ltr" title="<?php echo esc_attr( sprintf( /* translators: %s: folder names */ __( 'نسخهٔ دیگری از همین افزونه هم نصب است: %s', 'tisacase-hub' ), implode( '، ', (array) $item['dupes'] ) ) ); ?>">×<?php echo esc_html( TSH_View::num( count( (array) $item['dupes'] ) ) ); ?></span>
						<?php endif; ?>
					</span>
				</div>
				<button type="button" class="tsh-pin tisa-pin" data-pin="<?php echo esc_attr( $item['key'] ); ?>"
					aria-pressed="<?php echo $pinned ? 'true' : 'false'; ?>"
					title="<?php esc_attr_e( 'سنجاق در بالای صفحه', 'tisacase-hub' ); ?>"><?php echo TSH_View::icon( 'star' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'سنجاق', 'tisacase-hub' ); ?></span></button>
			</div>

			<div class="tsh-card__foot tisa-hub-tile__foot">
				<?php if ( $open ) : ?>
					<a class="tsh-open" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"<?php echo $tip ? ' title="' . esc_attr( $tip ) . '"' : ''; ?>><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?><?php echo TSH_View::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
				<?php elseif ( $url && empty( $item['can'] ) ) : ?>
					<span class="tsh-open is-disabled" aria-disabled="true"
						title="<?php echo esc_attr( sprintf( /* translators: %s: capability */ __( 'این برگهٔ افزونه با دسترسی «%s» قفل شده است.', 'tisacase-hub' ), isset( $item['cap_name'] ) ? $item['cap_name'] : '' ) ); ?>"><?php esc_html_e( 'باز کردن', 'tisacase-hub' ); ?></span>
				<?php elseif ( 'inactive' === $state && ! empty( $item['can_manage'] ) ) : ?>
					<a class="tsh-open tsh-open--secondary" href="<?php echo esc_url( $act_url( 'activate' ) ); ?>"><?php esc_html_e( 'فعال‌سازی', 'tisacase-hub' ); ?></a>
				<?php elseif ( ! empty( $item['can_install'] ) ) : ?>
					<form class="tsh-install" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="tisacase_hub_install">
						<input type="hidden" name="item" value="<?php echo esc_attr( $item['key'] ); ?>">
						<?php wp_nonce_field( 'tsh_install_' . $item['key'], '_tshnonce' ); ?>
						<button type="submit" class="tsh-open tsh-open--install"
							title="<?php echo esc_attr( sprintf( /* translators: %s: zip url */ __( 'نصب از مخزن: %s', 'tisacase-hub' ), $item['zip'] ) ); ?>">
							<?php esc_html_e( 'نصب از مخزن', 'tisacase-hub' ); ?>
						</button>
					</form>
				<?php elseif ( ! empty( $item['zip'] ) ) : ?>
					<span class="tsh-na"><?php esc_html_e( 'پوشهٔ افزونه روی این سرور نیست', 'tisacase-hub' ); ?></span>
					<span class="tsh-na tsh-na--hint" title="<?php echo esc_attr( $item['zip'] ); ?>"><?php esc_html_e( 'برای نصب، دسترسی نصب افزونه لازم است', 'tisacase-hub' ); ?></span>
				<?php else : ?>
					<span class="tsh-na"><?php esc_html_e( 'پوشهٔ افزونه روی این سرور نیست', 'tisacase-hub' ); ?></span>
				<?php endif; ?>

				<span class="tsh-card__tools">
					<?php if ( 'missing' !== $state && ! empty( $item['can_update'] ) && ! empty( $item['zip'] ) ) : ?>
						<form class="tisa-hub-tile__upd tsh-upd-repo" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="tisacase_hub_update_repo">
							<input type="hidden" name="item" value="<?php echo esc_attr( $item['key'] ); ?>">
							<?php wp_nonce_field( 'tsh_update_repo_' . $item['key'], '_tshnonce' ); ?>
							<button type="submit" class="tsh-tool tsh-tool--repo" title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'به‌روزرسانی «%s» از مخزن', 'tisacase-hub' ), $item['title'] ) ); ?>">
								<?php echo TSH_View::icon( 'refresh' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'به‌روزرسانی از مخزن', 'tisacase-hub' ); ?></span>
							</button>
						</form>
					<?php endif; ?>
					<?php if ( 'missing' !== $state && ! empty( $item['can_update'] ) ) : ?>
						<form class="tisa-hub-tile__upd" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-upd="1">
							<input type="hidden" name="action" value="tisacase_hub_update">
							<input type="hidden" name="item" value="<?php echo esc_attr( $item['key'] ); ?>">
							<?php wp_nonce_field( 'tsh_update_' . $item['key'], '_tshnonce' ); ?>
							<label class="tsh-tool" title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'به‌روزرسانی «%s» با فایل زیپ', 'tisacase-hub' ), $item['title'] ) ); ?>">
								<?php echo TSH_View::icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'به‌روزرسانی از فایل', 'tisacase-hub' ); ?></span>
								<input type="file" name="tsh_zip" accept=".zip,application/zip">
							</label>
							<noscript><button type="submit" class="tisa-btn tisa-btn--sm tisa-btn--ghost"><?php esc_html_e( 'نصب', 'tisacase-hub' ); ?></button></noscript>
						</form>
					<?php endif; ?>
					<?php if ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
						<button type="button" class="tsh-tool tsh-tool--off" data-confirm="1"
							title="<?php echo esc_attr( sprintf( /* translators: %s: tool title */ __( 'غیرفعال‌کردن «%s»', 'tisacase-hub' ), $item['title'] ) ); ?>">
							<?php echo TSH_View::icon( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span class="screen-reader-text"><?php esc_html_e( 'غیرفعال‌سازی', 'tisacase-hub' ); ?></span>
						</button>
					<?php endif; ?>
					<a class="tsh-tool" href="<?php echo esc_url( $act_url( 'hide' ) ); ?>"
						title="<?php esc_attr_e( 'مخفی کردن از هاب (از تنظیمات برمی‌گردد)', 'tisacase-hub' ); ?>">×<span class="screen-reader-text"><?php esc_html_e( 'مخفی کردن', 'tisacase-hub' ); ?></span></a>
				</span>
			</div>

			<?php if ( 'active' === $state && ! empty( $item['can_manage'] ) ) : ?>
				<div class="tisa-confirmbar tsh-confirm" hidden>
					<span><?php esc_html_e( 'غیرفعال شود؟', 'tisacase-hub' ); ?></span>
					<a class="tisa-btn tisa-btn--sm tisa-btn--danger-soft" href="<?php echo esc_url( $act_url( 'deactivate' ) ); ?>"><?php esc_html_e( 'بله', 'tisacase-hub' ); ?></a>
					<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--ghost" data-confirm-no="1"><?php esc_html_e( 'نه', 'tisacase-hub' ); ?></button>
				</div>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
