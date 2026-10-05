<?php
/**
 * رجیستری بخش‌ها + رندر نوار ناوبری («هدر جدا») و هدر هر بخش.
 *
 * افزودن بخش جدید = یک کلاس که از TisaCase_Exporter_Module ارث می‌برد و ثبت با فیلتر
 * `tisacase_exporter_modules`؛ صفحهٔ مدیریت و موتور خروجی دست نمی‌خورند.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Modules' ) ) {

	final class TisaCase_Exporter_Modules {

		/** کش فهرست بخش‌ها در همان درخواست. */
		private static $cache = null;

		/**
		 * همهٔ بخش‌های ثبت‌شده: id => کلاس.
		 *
		 * @return array<string,string>
		 */
		public static function registry() {
			$modules = array(
				'phones'    => 'TisaCase_Exporter_Module_Phones',
				'orders'    => 'TisaCase_Exporter_Module_Orders',
				'customers' => 'TisaCase_Exporter_Module_Customers',
				'products'  => 'TisaCase_Exporter_Module_Products',
				'coupons'   => 'TisaCase_Exporter_Module_Coupons',
			);

			/**
			 * فهرست بخش‌ها — برای افزودن/حذف بخش بدون ویرایش افزونه.
			 *
			 * @param array<string,string> $modules نگاشت شناسهٔ بخش به نام کلاس.
			 */
			$modules = apply_filters( 'tisacase_exporter_modules', $modules );

			$clean = array();

			foreach ( (array) $modules as $id => $class ) {
				$id = sanitize_key( $id );
				if ( '' === $id || ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, 'TisaCase_Exporter_Module' ) ) {
					continue;
				}
				$clean[ $id ] = $class;
			}

			return $clean;
		}

		/**
		 * فهرست بخش‌ها با متادیتا و ستون‌ها (کش‌شده).
		 *
		 * @return array<string,array>
		 */
		public static function all() {
			if ( is_array( self::$cache ) ) {
				return self::$cache;
			}

			$out = array();

			foreach ( self::registry() as $id => $class ) {
				$meta = (array) call_user_func( array( $class, 'meta' ) );

				$out[ $id ] = array_merge(
					array(
						'default_format' => 'csv',
						'skip_when'      => '',
						'default_dedup'  => '',
						'unit'           => '',
						'kpi'            => array(),
						'badge'          => '',
					),
					$meta,
					array(
						'id'      => $id,
						'class'   => $class,
						'columns' => (array) call_user_func( array( $class, 'columns' ) ),
						'filters' => (array) call_user_func( array( $class, 'filters_schema' ) ),
						'dedup'   => (array) call_user_func( array( $class, 'dedup_keys' ) ),
					)
				);
			}

			self::$cache = $out;

			return $out;
		}

		/**
		 * یک بخش با شناسه.
		 *
		 * @param string $id شناسه.
		 * @return array|null
		 */
		public static function get( $id ) {
			$all = self::all();
			$id  = sanitize_key( $id );

			return isset( $all[ $id ] ) ? $all[ $id ] : null;
		}

		/** بخش جاری از پارامتر `section`؛ پیش‌فرض: اولین بخش. */
		public static function current() {
			$all = self::all();

			if ( empty( $all ) ) {
				return '';
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$requested = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

			if ( '' !== $requested && isset( $all[ $requested ] ) ) {
				return $requested;
			}

			$keys = array_keys( $all );

			return (string) $keys[0];
		}

		/** آدرس یک بخش در صفحهٔ «مرکز خروجی تیساکیس». */
		public static function url( $id ) {
			return add_query_arg(
				array(
					'page'    => TisaCase_Exporter::MENU_SLUG,
					'section' => sanitize_key( $id ),
				),
				admin_url( 'admin.php' )
			);
		}

		/** بدنهٔ SVG آیکون بخش (رشتهٔ ثابت داخلی). */
		private static function icon( $section ) {
			$body = isset( $section['icon'] ) ? (string) $section['icon'] : '';

			if ( '' === $body ) {
				return '';
			}

			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
				. ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
				. $body . '</svg>';
		}

		/** نوار ناوبری بخش‌ها — «هدر جدای» بالای محتوا. */
		public static function render_nav() {
			$all     = self::all();
			$current = self::current();

			if ( empty( $all ) ) {
				return;
			}

			echo '<nav class="tisa-exp__nav" aria-label="' . esc_attr__( 'بخش‌های خروجی', TisaCase_Exporter::TEXT_DOMAIN ) . '">';

			foreach ( $all as $id => $section ) {
				$title = isset( $section['title'] ) ? (string) $section['title'] : $id;
				$icon  = self::icon( $section ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- رشتهٔ ثابت داخلی.

				printf(
					'<a class="tisa-exp__nav-link%1$s" href="%2$s"%3$s>%4$s<span>%5$s</span></a>',
					$id === $current ? ' is-current' : '',
					esc_url( self::url( $id ) ),
					$id === $current ? ' aria-current="page"' : '',
					$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $title )
				);
			}

			echo '</nav>';
		}

		/** هدر بخش جاری: آیکون، عنوان، توضیح و جایگاه آن در نوار ناوبری. */
		public static function render_head( $section ) {
			$title    = isset( $section['title'] ) ? (string) $section['title'] : '';
			$sub      = isset( $section['sub'] ) ? (string) $section['sub'] : '';
			$id       = isset( $section['id'] ) ? (string) $section['id'] : '';
			$ids      = array_keys( self::all() );
			$position = array_search( $id, $ids, true );
			$position  = false === $position ? 1 : $position + 1;
			$icon     = self::icon( $section ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- رشتهٔ ثابت داخلی.
			?>
			<div class="tisa-exp__section-head">
				<div class="tisa-exp__section-icon" aria-hidden="true"><?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="tisa-exp__section-copy">
					<p class="tisa-exp__section-eyebrow"><?php esc_html_e( 'بخش انتخاب‌شده', TisaCase_Exporter::TEXT_DOMAIN ); ?></p>
					<h2 class="tisa-exp__section-title"><?php echo esc_html( $title ); ?></h2>
					<?php if ( '' !== $sub ) : ?>
						<p class="tisa-exp__section-sub"><?php echo esc_html( $sub ); ?></p>
					<?php endif; ?>
				</div>
				<span class="tisa-exp__section-step">
					<strong dir="ltr"><?php echo esc_html( number_format_i18n( $position, 0 ) . ' / ' . number_format_i18n( count( $ids ), 0 ) ); ?></strong>
					<span><?php esc_html_e( 'بخش', TisaCase_Exporter::TEXT_DOMAIN ); ?></span>
				</span>
			</div>
			<?php
		}
	}
}
