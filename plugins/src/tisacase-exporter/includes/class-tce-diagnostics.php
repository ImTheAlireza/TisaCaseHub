<?php
/**
 * عیب‌یابی شمارش: توضیح می‌دهد عدد خروجی از کجا می‌آید، کدام فیلتر چند ردیف را حذف
 * کرده و (برای بخش‌های سفارشی) هر وضعیت چند سفارش دارد.
 *
 * هدف: اگر مدیر سایت انتظار ۳۰۰ ردیف دارد و خروجی ۲۷ ردیف است، همین گزارش نشان
 * می‌دهد تفاوت از کدام فیلتر (وضعیت/تاریخ/موبایل/…) و از کدام منبع داده (HPOS یا
 * جدول‌های قدیمی) می‌آید — بدون اینکه لازم باشد کسی به دیتابیس دست بزند.
 *
 * @package TisaCase_Exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'TisaCase_Exporter_Diagnostics' ) ) {

	final class TisaCase_Exporter_Diagnostics {

		/** حداکثر تعداد پله‌های نردبان فیلترها (محافظت از کارایی). */
		const MAX_STEPS = 8;

		/**
		 * گزارش کامل یک بخش با فیلترهای جاری.
		 *
		 * @param string $section_id شناسهٔ بخش (orders/phones/...).
		 * @param array  $filters    فیلترهای نرمال‌شده (خروجی normalize_filters).
		 * @return array
		 */
		public static function report( $section_id, array $filters ) {
			$module = TisaCase_Exporter_Modules::get( $section_id );

			if ( null === $module ) {
				return array(
					'ok'       => false,
					'message'  => __( 'بخش خواسته‌شده پیدا نشد.', TisaCase_Exporter::TEXT_DOMAIN ),
					'warnings' => array(),
					'steps'    => array(),
					'statuses' => array(),
					'phones'   => array(),
				);
			}

			$class = $module['class'];
			$unit  = isset( $module['unit'] ) && '' !== (string) $module['unit'] ? (string) $module['unit'] : __( 'ردیف', TisaCase_Exporter::TEXT_DOMAIN );

			$report = array(
				'ok'            => true,
				'section'       => (string) $section_id,
				'section_title' => isset( $module['title'] ) ? (string) $module['title'] : (string) $section_id,
				'storage'       => call_user_func( array( $class, 'storage_label' ) ),
				'hpos'          => (bool) call_user_func( array( $class, 'hpos_enabled' ) ),
				'unit'          => $unit,
				'summary'       => call_user_func( array( $class, 'filter_summary' ), $filters ),
				'total'         => 0,
				'baseline'      => 0,
				'steps'         => array(),
				'statuses'      => array(),
				'phones'        => array(),
				'reconcile'     => array(),
				'dedup'         => array(),
				'warnings'      => array(),
			);

			try {
				$report['steps']    = self::ladder( $class, $filters );
				$report['total']    = (int) call_user_func( array( $class, 'count' ), $filters );
				$report['baseline'] = isset( $report['steps'][0]['count'] ) ? (int) $report['steps'][0]['count'] : 0;
				$report['statuses'] = self::statuses( $class, $filters );
				$report['phones']   = self::phones( $class, $filters );
				$report['reconcile'] = self::reconcile( $class, $filters );
				$report['dedup']     = self::dedup_info( $module );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- گزارش جایگزین پایین ساخته می‌شود.
				$report['warnings'][] = array(
					'level' => 'fail',
					'text'  => sprintf(
						/* translators: %s: error message. */
						__( 'اجرای گزارش عیب‌یابی با خطا متوقف شد: %s', TisaCase_Exporter::TEXT_DOMAIN ),
						$e->getMessage()
					),
				);
			}

			$report['warnings'] = array_merge( $report['warnings'], self::warnings( $report ) );

			return $report;
		}


		/* -----------------------------------------------------------------
		 * یکتاسازی (dedup)
		 * ----------------------------------------------------------------- */

		/**
		 * اطلاعات یکتاسازی بخش: کلید پیش‌فرض و برچسب کلیدها.
		 *
		 * @param array $module ردیف بخش از رجیستری.
		 * @return array{default:string,label:string}
		 */
		private static function dedup_info( array $module ) {
			$default = isset( $module['default_dedup'] ) ? (string) $module['default_dedup'] : '';

			if ( '' === $default ) {
				return array();
			}

			$keys  = isset( $module['dedup'] ) && is_array( $module['dedup'] ) ? $module['dedup'] : array();
			$label = isset( $keys[ $default ] ) ? (string) $keys[ $default ] : $default;

			return array(
				'default' => $default,
				'label'   => $label,
			);
		}

		/* -----------------------------------------------------------------
		 * نردبان فیلترها
		 * ----------------------------------------------------------------- */

		/**
		 * تعداد ردیف‌ها را پله‌پله با اعمال فیلترها می‌سنجد تا سهم هر فیلتر روشن شود.
		 *
		 * @param string $class   کلاس بخش.
		 * @param array  $filters فیلترهای جاری.
		 * @return array<int,array>
		 */
		private static function ladder( $class, array $filters ) {
			$schema = call_user_func( array( $class, 'filters_schema' ) );
			$steps  = array();

			// پلهٔ صفر: هیچ فیلتری فعال نیست (سقف ممکن برای این بخش).
			$neutral = self::neutral_filters( $schema );

			$steps[] = array(
				'label' => __( 'بدون هیچ فیلتری (همهٔ وضعیت‌ها، همهٔ تاریخ‌ها)', TisaCase_Exporter::TEXT_DOMAIN ),
				'hint'  => __( 'اگر این عدد هم کم است، محدودیت از فیلترها نیست؛ منبع داده را در همین گزارش ببینید.', TisaCase_Exporter::TEXT_DOMAIN ),
				'count' => (int) call_user_func( array( $class, 'count' ), $neutral ),
				'delta' => 0,
				'field' => '',
			);

			$current = $neutral;

			foreach ( $schema as $field ) {
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';

				if ( '' === $name || ! array_key_exists( $name, $filters ) ) {
					continue;
				}

				$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';
				$value   = $filters[ $name ];
				$default = isset( $current[ $name ] ) ? $current[ $name ] : '';

				if ( self::is_neutral( $type, $value, $default ) ) {
					continue;
				}

				$current[ $name ] = $value;

				if ( count( $steps ) >= self::MAX_STEPS ) {
					break;
				}

				$count = (int) call_user_func( array( $class, 'count' ), $current );
				$last  = (int) $steps[ count( $steps ) - 1 ]['count'];

				$steps[] = array(
					'label' => self::step_label( $field, $value ),
					'hint'  => '',
					'count' => $count,
					'delta' => $count - $last,
					'field' => $name,
				);
			}

			// پلهٔ آخر باید مو‌به‌مو همان عدد خروجی باشد؛ اگر یک فیلتر ناشناخته باقی مانده
			// باشد (مثلاً فیلتر افزودهٔ خودِ سایت)، همان‌جا اضافه می‌شود.
			$final = (int) call_user_func( array( $class, 'count' ), $filters );
			$last  = (int) $steps[ count( $steps ) - 1 ]['count'];

			if ( $final !== $last ) {
				$steps[] = array(
					'label' => __( 'با همهٔ فیلترهای همین اجرا', TisaCase_Exporter::TEXT_DOMAIN ),
					'hint'  => __( 'این عدد، دقیقاً همان چیزی است که خروجی می‌دهد (پیش از حذف تکراری‌ها).', TisaCase_Exporter::TEXT_DOMAIN ),
					'count' => $final,
					'delta' => $final - $last,
					'field' => '',
				);
			}

			return $steps;
		}

		/** فیلترهای «خنثی» = مقدار پیش‌فرض/بی‌اثر هر فیلد طبق طرح همان بخش. */
		private static function neutral_filters( array $schema ) {
			$out = array();

			foreach ( $schema as $field ) {
				$name = isset( $field['name'] ) ? (string) $field['name'] : '';

				if ( '' === $name ) {
					continue;
				}

				$type    = isset( $field['type'] ) ? (string) $field['type'] : 'text';
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

				switch ( $type ) {
					case 'select':
						$out[ $name ] = array_key_exists( 'all', $options ) ? 'all' : '';
						break;

					case 'number':
						$out[ $name ] = 0;
						break;

					case 'switch':
						$out[ $name ] = false;
						break;

					case 'multiselect':
						// عمداً تعریف نشده تا ماژول از پیش‌فرض خودش (همهٔ وضعیت‌ها) استفاده کند.
						break;

					default: // date / text
						$out[ $name ] = '';
						break;
				}
			}

			return $out;
		}

		/** آیا مقدار یک فیلد همان مقدار خنثی است؟ (یعنی روی عدد اثری ندارد) */
		private static function is_neutral( $type, $value, $neutral ) {
			switch ( $type ) {
				case 'number':
					return (float) $value === (float) $neutral;

				case 'switch':
					return empty( $value ) === empty( $neutral );

				case 'multiselect':
					return empty( $value );

				default:
					return (string) $value === (string) $neutral;
			}
		}

		/** برچسب خوانای یک پله: «وضعیت سفارش: در حال انجام (۳ وضعیت)». */
		private static function step_label( array $field, $value ) {
			$label = isset( $field['label'] ) ? (string) $field['label'] : '';
			$type  = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			switch ( $type ) {
				case 'multiselect':
					$count = is_array( $value ) ? count( $value ) : 0;

					return sprintf(
						/* translators: 1: field label, 2: number of selected items. */
						__( '%1$s: %2$s مورد انتخاب‌شده', TisaCase_Exporter::TEXT_DOMAIN ),
						$label,
						number_format_i18n( $count )
					);

				case 'switch':
					return $label;

				case 'number':
					return $label . ': ' . number_format_i18n( (float) $value );

				default:
					$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

					if ( isset( $options[ $value ] ) ) {
						$value = wp_strip_all_tags( (string) $options[ $value ] );
					}

					return $label . ': ' . (string) $value;
			}
		}

		/* -----------------------------------------------------------------
		 * وضعیت‌ها و شماره‌ها
		 * ----------------------------------------------------------------- */

		/** شمارش هر وضعیت + این‌که در فیلتر جاری انتخاب شده یا نه. */
		private static function statuses( $class, array $filters ) {
			$schema    = call_user_func( array( $class, 'filters_schema' ) );
			$has_field = false;

			foreach ( $schema as $field ) {
				if ( isset( $field['name'] ) && 'statuses' === $field['name'] ) {
					$has_field = true;
					break;
				}
			}

			if ( ! $has_field || ! method_exists( $class, 'order_status_counts' ) ) {
				return array();
			}

			$counts  = (array) call_user_func( array( $class, 'order_status_counts' ) );
			$options = array();

			// برچسب وضعیت‌ها از خودِ طرح فیلترها خوانده می‌شود (همان چیزی که در فرم دیده می‌شود).
			foreach ( $schema as $field ) {
				if ( isset( $field['name'] ) && 'statuses' === $field['name'] && isset( $field['options'] ) && is_array( $field['options'] ) ) {
					$options = $field['options'];
					break;
				}
			}
			$selected = isset( $filters['statuses'] ) && is_array( $filters['statuses'] ) ? array_map( 'strval', $filters['statuses'] ) : array();
			$explicit = ! empty( $selected );
			$out      = array();

			foreach ( $options as $key => $label ) {
				$key   = (string) $key;
				$out[] = array(
					'key'      => $key,
					'label'    => wp_strip_all_tags( (string) $label ),
					'count'    => isset( $counts[ $key ] ) ? (int) $counts[ $key ] : 0,
					'selected' => ! $explicit || in_array( $key, $selected, true ),
				);
			}

			// کلیدهایی که در ووکامرس ثبت نشده‌اند ولی در دیتابیس هستند (وضعیت یتیم).
			foreach ( $counts as $key => $count ) {
				if ( '__sources__' === $key || ! is_numeric( $count ) ) {
					continue; // نگاشت منابع داده، وضعیت نیست.
				}

				if ( ! isset( $options[ $key ] ) && $count > 0 ) {
					$out[] = array(
						'key'      => (string) $key,
						'label'    => sprintf(
							/* translators: %s: raw status key from the database. */
							__( '%s (ثبت‌نشده در ووکامرس)', TisaCase_Exporter::TEXT_DOMAIN ),
							(string) $key
						),
						'count'    => (int) $count,
						'selected' => false,
						'orphan'   => true,
					);
				}
			}

			return $out;
		}

		/** آمار موبایل: چند سفارش شماره دارد، چند تا ندارد و چند شماره یکتاست. */
		private static function phones( $class, array $filters ) {
			if ( ! method_exists( $class, 'phone_stats' ) ) {
				return array();
			}

			// تاریخ و وضعیت جاری اعمال می‌شود، ولی فیلترهای دیگر (نوع مشتری/مبلغ/موبایل)
			// کنار می‌روند تا تصویر کلی «چند سفارش شماره دارد» به دست بیاید.
			$scope = $filters;

			unset( $scope['has_phone'], $scope['min_total'], $scope['min_orders'] );
			$scope['customer_type'] = 'all';

			$stats = (array) call_user_func( array( $class, 'phone_stats' ), $scope );

			if ( empty( $stats ) ) {
				return array();
			}

			$stats['missing'] = max( 0, (int) $stats['total'] - (int) $stats['with_phone'] );

			return $stats;
		}

		/** مقایسهٔ منبع دادهٔ فعلی با منبع دیگر (HPOS در برابر جدول‌های قدیمی). */
		private static function reconcile( $class, array $filters ) {
			if ( ! method_exists( $class, 'order_status_counts' ) ) {
				return array();
			}

			$counts = (array) call_user_func( array( $class, 'order_status_counts' ) );

			if ( empty( $counts['__sources__'] ) || ! is_array( $counts['__sources__'] ) ) {
				return array();
			}

			return $counts['__sources__'];
		}

		/* -----------------------------------------------------------------
		 * هشدارها
		 * ----------------------------------------------------------------- */

		private static function warnings( array $report ) {
			$out  = array();
			$unit = isset( $report['unit'] ) ? (string) $report['unit'] : '';

			if ( ! empty( $report['baseline'] ) && 0 === (int) $report['total'] ) {
				$out[] = array(
					'level' => 'warn',
					'text'  => __( 'فیلترهای انتخابی همهٔ ردیف‌ها را حذف کرده‌اند (عدد نهایی صفر است). «انتخاب همه» برای وضعیت‌ها یا «بدون محدودیت تاریخ» را امتحان کنید.', TisaCase_Exporter::TEXT_DOMAIN ),
				);
			}

			// سهم حذف‌شده‌ها را پیدا کن.
			if ( ! empty( $report['steps'] ) ) {
				$worst = null;

				foreach ( $report['steps'] as $step ) {
					if ( $step['delta'] < 0 && ( null === $worst || $step['delta'] < $worst['delta'] ) ) {
						$worst = $step;
					}
				}

				if ( null !== $worst && $worst['delta'] < 0 ) {
					$out[] = array(
						'level' => 'info',
						'text'  => sprintf(
							/* translators: 1: filter label, 2: number of removed rows. */
							__( 'بیشترین کاهش از «%1$s» می‌آید: %2$s ردیف کمتر.', TisaCase_Exporter::TEXT_DOMAIN ),
							(string) $worst['label'],
							number_format_i18n( abs( (int) $worst['delta'] ) )
						),
					);
				}
			}

			// هشدار تفاوت منبع داده (فقط برای بخش‌هایی که شمارش سفارش دارند).
			$active_key = ! empty( $report['hpos'] ) ? 'hpos' : 'legacy';
			$other_key  = 'hpos' === $active_key ? 'legacy' : 'hpos';
			$mine       = isset( $report['reconcile'][ $active_key ] ) ? (int) $report['reconcile'][ $active_key ] : 0;
			$other      = isset( $report['reconcile'][ $other_key ] ) ? (int) $report['reconcile'][ $other_key ] : 0;

			if ( ! empty( $report['reconcile'] ) ) {
				if ( $other > $mine ) {
					$out[] = array(
						'level' => 'warn',
						'text'  => sprintf(
							/* translators: 1: rows in the inactive storage, 2: active storage label, 3: rows in the active storage. */
							__( 'در منبع دادهٔ دیگر %1$s ردیف هست، ولی منبع فعال (%2$s) %3$s ردیف دارد. یعنی بخشی از سفارش‌ها هنوز به منبع فعال منتقل نشده و همین می‌تواند علت کم‌بودن تعداد خروجی باشد.', TisaCase_Exporter::TEXT_DOMAIN ),
							number_format_i18n( $other ),
							'HPOS' === (string) $report['storage'] ? 'HPOS' : 'Legacy',
							number_format_i18n( $mine )
						),
					);
				} else {
					$out[] = array(
						'level' => 'ok',
						'text'  => sprintf(
							/* translators: 1: rows in the inactive storage, 2: active storage label. */
							__( 'منبع دادهٔ فعال (%2$s) با منبع دیگر هم‌خوان است (منبع دیگر: %1$s ردیف).', TisaCase_Exporter::TEXT_DOMAIN ),
							number_format_i18n( $other ),
							'HPOS' === (string) $report['storage'] ? 'HPOS' : 'Legacy'
						),
					);
				}
			}

			/*
			 * هشدار یکتاسازی: اگر بخش به‌صورت پیش‌فرض یکتاسازی می‌کند، عدد نهایی خروجی
			 * طبیعتاً کمتر از عدد «کل» است (مثلاً ۳۰۰ سفارش با ۲۷ شمارهٔ یکتا). همین
			 * تفاوت قبلاً باعث سوءتفاهم «کم‌بودن خروجی» می‌شد.
			 */
			if ( ! empty( $report['dedup']['default'] ) && ! empty( $report['phones'] ) ) {
				$with = isset( $report['phones']['with_phone'] ) ? (int) $report['phones']['with_phone'] : 0;
				$uniq = isset( $report['phones']['unique'] ) ? (int) $report['phones']['unique'] : 0;

				if ( $with > 0 && $uniq > 0 && $uniq < $with ) {
					$out[] = array(
						'level' => 'info',
						'text'  => sprintf(
							/* translators: 1: dedup key label, 2: unique rows, 3: rows before dedup. */
							__( 'یکتاسازی «%1$s» روشن است: از %3$s ردیف، %2$s ردیف یکتا می‌ماند. برای دیدن همهٔ ردیف‌ها، یکتاسازی را روی «بدون یکتاسازی» بگذارید.', TisaCase_Exporter::TEXT_DOMAIN ),
							(string) $report['dedup']['label'],
							number_format_i18n( $uniq ),
							number_format_i18n( $with )
						),
					);
				}
			}

			// هشدار شماره‌ها.
			if ( ! empty( $report['phones'] ) ) {
				$total = isset( $report['phones']['total'] ) ? (int) $report['phones']['total'] : 0;
				$with  = isset( $report['phones']['with_phone'] ) ? (int) $report['phones']['with_phone'] : 0;
				$uniq  = isset( $report['phones']['unique'] ) ? (int) $report['phones']['unique'] : 0;

				if ( $total > 0 && $with < $total ) {
					$out[] = array(
						'level' => $with > 0 ? 'warn' : 'fail',
						'text'  => sprintf(
							/* translators: 1: orders with phone, 2: total orders, 3: orders without phone. */
							__( 'از %2$s سفارش این محدوده، فقط %1$s سفارش شمارهٔ موبایل دارند (%3$s سفارش بدون شماره). بخش «شماره تماس‌ها» طبیعتاً برای آن‌ها ردیفی نمی‌سازد.', TisaCase_Exporter::TEXT_DOMAIN ),
							number_format_i18n( $with ),
							number_format_i18n( $total ),
							number_format_i18n( max( 0, $total - $with ) )
						),
					);
				}

				$out[] = array(
					'level' => 'info',
					'text'  => sprintf(
						/* translators: 1: unique phone count, 2: orders with phone. */
						__( 'شماره‌های یکتا: %1$s (بخش «شماره تماس‌ها» به‌صورت پیش‌فرض تکراری‌ها را حذف می‌کند؛ اگر روی همان شماره چند سفارش باشد، تعداد ردیف‌های خروجی از %2$s کمتر می‌شود).', TisaCase_Exporter::TEXT_DOMAIN ),
						number_format_i18n( $uniq ),
						number_format_i18n( $with )
					),
				);
			}

			$out[] = array(
				'level' => 'info',
				'text'  => sprintf(
					/* translators: %s: section unit (e.g. سفارش). */
					__( 'عدد «نهایی» این گزارش، دقیقاً همان تعدادی است که خروجی این بخش گزارش می‌کند (واحد: %s). برای مقایسه با عدد ووکامرس، همان وضعیت‌ها و تاریخ را در فهرست سفارش‌های ووکامرس هم اعمال کنید.', TisaCase_Exporter::TEXT_DOMAIN ),
					$unit
				),
			);

			return $out;
		}
	}
}
