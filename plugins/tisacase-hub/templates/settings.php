<?php
/**
 * قالب «ظاهر و تنظیمات» — خلوت، چند کارت.
 *
 * @var array $settings تنظیمات فعلی
 * @var array $font     وضعیت فونت محلی
 * @var array $hidden   کلیدهای مخفی‌شده
 * @var array $items    همهٔ آیتم‌ها
 * @var array $unreg    افزونه‌های ثبت‌نشده
 * @var array $env      اطلاعات محیط
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

$opt     = TSH_OPTION;
$accents = array(
	'#0E7C6B' => __( 'تیساکیس', 'tisacase-hub' ),
	'#1467A8' => __( 'آبی', 'tisacase-hub' ),
	'#B5453A' => __( 'آجر', 'tisacase-hub' ),
	'#6D4AA8' => __( 'بنفش', 'tisacase-hub' ),
	'#1A7F37' => __( 'سبز', 'tisacase-hub' ),
	'#8A6116' => __( 'خاکی', 'tisacase-hub' ),
);
$repo    = class_exists( 'TSH_Remote' ) ? TSH_Remote::repo() : '';
$branch  = class_exists( 'TSH_Remote' ) ? TSH_Remote::branch() : '';
$tools   = admin_url( 'admin.php?page=' . TSH_SLUG );
?>
<div class="wrap tisa-wrap tisa-hub-wrap tsh-set" dir="rtl">

	<header class="tisa-hero tisa-hero--slim tsh-hero">
		<div class="tsh-hero__row">
			<span class="tisa-hero-mark" aria-hidden="true"><?php echo TSH_View::icon( 'gear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="tisa-hero-txt">
				<h1 class="tisa-hero-title"><?php esc_html_e( 'تنظیمات', 'tisacase-hub' ); ?></h1>
				<p class="tisa-lead"><?php esc_html_e( 'ظاهر هاب و جایگاه منو', 'tisacase-hub' ); ?></p>
			</div>
			<div class="tisa-hero-actions">
				<a class="tisa-btn tisa-btn--sm tisa-btn--on-dark" href="<?php echo esc_url( $tools ); ?>"><?php esc_html_e( 'ابزارها', 'tisacase-hub' ); ?></a>
			</div>
		</div>
	</header>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php?action=tisacase_hub_save' ) ); ?>" class="tsh-set-stack">
		<?php wp_nonce_field( 'tsh_save' ); ?>

		<section class="tisa-card tsh-set-card">
			<h2 class="tsh-set-card__h"><?php esc_html_e( 'ظاهر', 'tisacase-hub' ); ?></h2>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'رنگ برند', 'tisacase-hub' ); ?></b>
				</div>
				<div class="tisa-setrow__ctrl" id="tsh-accent-row" role="radiogroup" aria-label="<?php esc_attr_e( 'رنگ برند', 'tisacase-hub' ); ?>">
					<?php foreach ( $accents as $hex => $label ) : ?>
						<?php $is_on = strtolower( (string) $settings['accent'] ) === strtolower( $hex ); ?>
						<button type="button" class="tisa-accent__sw<?php echo $is_on ? ' is-on' : ''; ?>" role="radio" aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
							data-hex="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>"
							aria-label="<?php echo esc_attr( $label ); ?>" title="<?php echo esc_attr( $label ); ?>"></button>
					<?php endforeach; ?>
					<span class="tisa-input-group" style="max-width:150px">
						<span class="tisa-input-group__addon">#</span>
						<input type="text" name="<?php echo esc_attr( $opt ); ?>[accent]" id="tsh-accent" class="tisa-input tisa-input--code" value="<?php echo esc_attr( ltrim( (string) $settings['accent'], '#' ) ); ?>" maxlength="6" pattern="[0-9a-fA-F]{3,6}" dir="ltr">
					</span>
					<input type="color" id="tsh-accent-pick" class="tisa-accent__custom" value="<?php echo esc_attr( $settings['accent'] ); ?>" title="<?php esc_attr_e( 'پالت', 'tisacase-hub' ); ?>">
				</div>
			</div>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'حالت کم‌فضا', 'tisacase-hub' ); ?></b>
					<p class="tisa-meta"><?php esc_html_e( 'فاصله‌ها و کنترل‌ها جمع‌وجورتر می‌شوند.', 'tisacase-hub' ); ?></p>
				</div>
				<div class="tisa-setrow__ctrl">
					<label class="tisa-switch">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[compact]" value="1" <?php checked( ! empty( $settings['compact'] ) ); ?>>
						<span class="tisa-switch__track" aria-hidden="true"></span>
					</label>
				</div>
			</div>
		</section>

		<section class="tisa-card tsh-set-card">
			<h2 class="tsh-set-card__h"><?php esc_html_e( 'منو و صفحات', 'tisacase-hub' ); ?></h2>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'یکدست‌سازی صفحات افزونه‌ها', 'tisacase-hub' ); ?></b>
				</div>
				<div class="tisa-setrow__ctrl">
					<label class="tisa-switch">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[style_plugins]" value="1" <?php checked( ! empty( $settings['style_plugins'] ) ); ?>>
						<span class="tisa-switch__track" aria-hidden="true"></span>
					</label>
				</div>
			</div>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'لیست محصولات هم یکدست شود', 'tisacase-hub' ); ?></b>
				</div>
				<div class="tisa-setrow__ctrl">
					<label class="tisa-switch">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[style_product_screens]" value="1" <?php checked( ! empty( $settings['style_product_screens'] ) ); ?>>
						<span class="tisa-switch__track" aria-hidden="true"></span>
					</label>
				</div>
			</div>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'هاب تنها ورودی منو باشد', 'tisacase-hub' ); ?></b>
					<p class="tisa-meta"><?php esc_html_e( 'آیتم‌های پخش‌شده در ووکامرس از منو پنهان می‌شوند؛ صفحه‌ها سر جایشان می‌مانند.', 'tisacase-hub' ); ?></p>
				</div>
				<div class="tisa-setrow__ctrl">
					<label class="tisa-switch">
						<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[hide_scattered]" value="1" <?php checked( ! empty( $settings['hide_scattered'] ) ); ?>>
						<span class="tisa-switch__track" aria-hidden="true"></span>
					</label>
				</div>
			</div>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'جایگاه در نوار کنار', 'tisacase-hub' ); ?></b>
				</div>
				<div class="tisa-setrow__ctrl">
					<select name="<?php echo esc_attr( $opt ); ?>[menu_position]" class="tisa-select" id="tsh-pos">
						<?php foreach ( TSH_Admin::positions() as $pkey => $pinfo ) : ?>
							<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $settings['menu_position'], $pkey ); ?>><?php echo esc_html( $pinfo['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="text" name="<?php echo esc_attr( $opt ); ?>[menu_position_custom]" class="tisa-input tisa-input--number" style="max-width:88px" value="<?php echo esc_attr( (string) $settings['menu_position_custom'] ); ?>" placeholder="57.5" dir="ltr" aria-label="<?php esc_attr_e( 'عدد دلخواه', 'tisacase-hub' ); ?>">
				</div>
			</div>
			<?php if ( $hidden ) : ?>
			<div class="tisa-setrow">
				<div class="tisa-setrow__text">
					<b class="tisa-h3"><?php esc_html_e( 'کارت‌های مخفی', 'tisacase-hub' ); ?></b>
				</div>
				<div class="tisa-setrow__ctrl">
					<?php foreach ( $hidden as $hk ) : ?>
						<a class="tisa-btn tisa-btn--sm tisa-btn--secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tisacase_hub_action&task=unhide&item=' . $hk ), 'tsh_action' ) ); ?>">
							<?php echo esc_html( isset( $items[ $hk ] ) ? $items[ $hk ]['title'] : $hk ); ?> ↩
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>
		</section>

		<section class="tisa-card tsh-set-card">
			<h2 class="tsh-set-card__h"><?php esc_html_e( 'مخزن', 'tisacase-hub' ); ?></h2>
			<p class="tisa-meta" style="margin:0">
				<?php if ( $repo ) : ?>
					<?php echo esc_html( $repo ); ?>
					<?php if ( $branch ) : ?>
						<span class="tisa-code" dir="ltr">@<?php echo esc_html( $branch ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<?php esc_html_e( 'متصل نیست.', 'tisacase-hub' ); ?>
				<?php endif; ?>
				— <a href="<?php echo esc_url( $tools ); ?>"><?php esc_html_e( 'اتصال و همگام‌سازی از صفحهٔ ابزارها', 'tisacase-hub' ); ?></a>
			</p>
			<?php
			$cat     = class_exists( 'TSH_Remote' ) ? TSH_Remote::catalog() : array();
			$cat_n   = isset( $cat['items'] ) && is_array( $cat['items'] ) ? count( $cat['items'] ) : 0;
			$cat_at  = isset( $cat['at'] ) ? (int) $cat['at'] : 0;
			$cat_src = isset( $cat['source'] ) ? (string) $cat['source'] : '';
			$src_map = array(
				'github' => __( 'فهرست GitHub', 'tisacase-hub' ),
				'probe'  => __( 'کاوش مستقیم فایل‌های مخزن', 'tisacase-hub' ),
				'bundle' => __( 'فهرست همراه هاب (آفلاین)', 'tisacase-hub' ),
			);
			?>
			<p class="tisa-meta" style="margin:6px 0 0">
				<?php if ( $cat_at > 0 ) : ?>
					<?php
					printf(
						/* translators: 1: date/time, 2: item count, 3: source label */
						esc_html__( 'آخرین همگام‌سازی: %1$s — %2$s افزونه از %3$s.', 'tisacase-hub' ),
						esc_html( date_i18n( 'Y/m/d H:i', $cat_at ) ),
						esc_html( TSH_View::num( $cat_n ) ),
						esc_html( isset( $src_map[ $cat_src ] ) ? $src_map[ $cat_src ] : $cat_src )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'هنوز همگام‌سازی نشده، یا سرور به مخزن دسترسی نداشته است.', 'tisacase-hub' ); ?>
				<?php endif; ?>
			</p>
		</section>

		<div class="tsh-set-save">
			<?php submit_button( __( 'ذخیره', 'tisacase-hub' ), 'primary', 'submit', false ); ?>
		</div>
	</form>

	<details class="tsh-set-more">
		<summary><?php esc_html_e( 'موارد فنی', 'tisacase-hub' ); ?></summary>
		<div class="tisa-card tsh-set-card" style="margin-top:12px">
			<p class="tisa-meta">
				<?php esc_html_e( 'قلم:', 'tisacase-hub' ); ?>
				<?php echo $font['count'] ? esc_html( sprintf( /* translators: %s: weights */ __( 'Vazirmatn محلی (%s)', 'tisacase-hub' ), $font['weights'] ) ) : esc_html__( 'سیستم', 'tisacase-hub' ); ?>
				· PHP <?php echo esc_html( $env['php'] ); ?>
				· WC <?php echo esc_html( $env['wc'] ? $env['wc'] : '—' ); ?>
			</p>
		</div>
	</details>
</div>
