<?php
/**
 * موتور راستی‌آزمایی نهایی «آیا این تصویر واقعاً هیچ‌جا استفاده نشده است؟».
 *
 * چرا این فایل لازم شد؟
 * اسکنر کاتالوگ، تمام مراجع را در یک پاس کامل جمع می‌کند، اما بین «لحظهٔ اسکن» و
 * «لحظهٔ حذف» ممکن است مدیر سایت عکسی را به محصولی وصل کند، یا کاتالوگ قدیمی باشد.
 * همچنین چند کلاس مرجع وجود دارد که در متا فقط با «شناسهٔ عددی» ذخیره می‌شوند
 * (گالری‌های افزونه‌های ثالث، لوگوی قالب، سواچ دسته‌بندی) و با جستجوی رشتهٔ
 * `uploads` پیدا نمی‌شوند.
 *
 * بنابراین پیش از هر حذف/قرنطینه، هر شناسه به‌صورت زنده در این منابع بررسی می‌شود:
 *   ۱) `_thumbnail_id` همهٔ نوشته‌ها، متغیرها، سفارش‌ها و برگه‌ها.
 *   ۲) کلیدهای گالری/متای تصویری (لیست شناسه‌ای، سریالایز، JSON یا مقدار تکی).
 *   ۳) متای دسته‌ها/برندها/سواچ‌ها (`termmeta`).
 *   ۴) تنظیمات سایت: فاوآیکون، لوگو، تصویر پیش‌فرض ووکامرس، `theme_mods` و ابزارک‌ها.
 *   ۵) محتوای نوشته‌ها و توضیحات محصولات بر اساس «نام واقعی فایل» و `wp-image-ID`.
 *   ۶) فایل‌های یتیم روی دیسک: اگر مسیرشان در محتوای سایت ذکر شده باشد، «بدون استفاده» نیستند.
 *
 * نتیجهٔ هر بررسی، دلیل فارسی دقیق مرجع را برمی‌گرداند تا کاربر بداند چرا حذف نشد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Usage' ) ) {

	/**
	 * راستی‌آزمای زندهٔ استفادهٔ تصاویر و فایل‌ها.
	 */
	final class TisaCase_Optimizer_Usage {

		/** حداکثر تعداد شناسه‌ای که در یک درخواست «بازرسی محتوا» می‌شود (نگه‌دارندهٔ سرعت). */
		const MAX_CONTENT_PROBES = 25;

		/** حداکثر تعداد الگوی LIKE در هر کوئری (جلوگیری از کوئری غول‌آسا). */
		const MAX_PATTERNS_PER_QUERY = 40;

		/** حداکثر تعداد دلیل نمایش‌داده‌شده برای هر آیتم. */
		const MAX_REASONS = 4;

		/** @var array<int,array<int,string>>|null کش در-درخواستی مراجع زنده. */
		private static $live_cache = null;

		/**
		* پاک‌سازی کش درون‌درخواستی (پس از هر حذف/بازگردانی).
		*
		* @return void
		*/
		public static function flush() {
			self::$live_cache = null;
		}

		/**
		 * کلیدهای متایی که مقدارشان «شناسهٔ تصویر» است (تکی، لیستی یا سریالایز).
		 *
		 * @return string[]
		 */
		public static function gallery_meta_keys() {
			$keys = array(
				'_thumbnail_id',
				'_product_image_gallery',
				'_wc_additional_variation_images',
				'_variation_image_gallery',
				'woodmart_wc_video_gallery',
				'_yoast_wpseo_opengraph-image-id',
				'_yoast_wpseo_twitter-image-id',
				'rank_math_facebook_image_id',
				'rank_math_twitter_image_id',
				'_elementor_page_image',
				'_fl_builder_data_image',
			);

			/**
			 * افزودن کلیدهای متای سفارشیِ ذخیره‌کنندهٔ شناسهٔ تصویر.
			 *
			 * @param string[] $keys کلیدهای پیش‌فرض.
			 */
			$extra = (array) apply_filters( 'tisacase_optimizer_media_meta_keys', array() );

			$all = array();
			foreach ( array_merge( $keys, $extra ) as $key ) {
				$key = trim( (string) $key );
				if ( '' !== $key ) {
					$all[ $key ] = true;
				}
			}

			return array_keys( $all );
		}

		/**
		 * گزینه‌هایی که مقدارشان مستقیم شناسهٔ پیوست است.
		 *
		 * @return array<string,string>
		 */
		public static function direct_option_labels() {
			return array(
				'site_icon'                     => 'فاوآیکون سایت',
				'custom_logo'                   => 'لوگوی اصلی سایت',
				'woocommerce_placeholder_image' => 'تصویر پیش‌فرض ووکامرس',
			);
		}

		/**
		 * آیا مقدار متا شامل این شناسه است؟ (تکی، لیست کامایی، سریالایز، JSON)
		 *
		 * این تابع «محافظه‌کار» است: در هر ابهامی «بله» می‌گوید تا عکسی که ممکن است
		 * در جایی استفاده شده باشد هرگز حذف نشود.
		 *
		 * @param mixed $value مقدار خام متا.
		 * @param int   $id    شناسهٔ پیوست.
		 * @return bool
		 */
		public static function id_list_contains( $value, $id ) {
			$id = absint( $id );
			if ( $id <= 0 || ! is_scalar( $value ) ) {
				return false;
			}

			$raw = trim( (string) $value );
			if ( '' === $raw ) {
				return false;
			}

			if ( ctype_digit( $raw ) ) {
				return (int) $raw === $id;
			}

			if ( ! preg_match( '/\d/', $raw ) ) {
				return false;
			}

			// لیست خالص شناسه‌ها: «12,34,56» یا «12|34» یا «12 34».
			if ( preg_match( '/^\d+(?:\s*[,|;\s]\s*\d+)*$/', $raw ) ) {
				foreach ( preg_split( '/\s*[,|;\s]\s*/', $raw ) as $piece ) {
					if ( '' !== $piece && (int) $piece === $id ) {
						return true;
					}
				}
				return false;
			}

			// سریالایز/JSON/متن ترکیبی: فقط تطابق عددی کامل (نه بخشی از عدد بزرگ‌تر).
			return (bool) preg_match( '/(?<!\d)' . $id . '(?!\d)/', $raw );
		}

		/**
		 * استخراج همهٔ شناسه‌های عددیِ یک مقدار متا (برای متای دسته‌ها و تنظیمات).
		 *
		 * @param mixed $value مقدار خام.
		 * @return int[]
		 */
		public static function numeric_ids_in( $value ) {
			if ( ! is_scalar( $value ) ) {
				return array();
			}
			$raw = (string) $value;
			if ( '' === $raw || ! preg_match( '/\d/', $raw ) ) {
				return array();
			}

			$out = array();
			if ( preg_match_all( '/\d{1,12}/', $raw, $m ) ) {
				foreach ( $m[0] as $num ) {
					$n = (int) $num;
					if ( $n > 0 ) {
						$out[ $n ] = true;
					}
				}
			}

			return array_keys( $out );
		}

		/**
		 * ریشهٔ نام فایل‌های یک پیوست (بدون پسوند، با و بدون `-scaled`).
		 *
		 * @param int $aid شناسهٔ پیوست.
		 * @return string[]
		 */
		public static function file_stems( $aid ) {
			$aid = absint( $aid );
			if ( $aid <= 0 ) {
				return array();
			}

			$stems = array();
			$rel   = (string) get_post_meta( $aid, '_wp_attached_file', true );
			if ( '' !== $rel ) {
				$base  = basename( $rel );
				$noext = preg_replace( '/\.[a-z0-9]+$/i', '', $base );
				if ( is_string( $noext ) && '' !== $noext ) {
					$stems[] = $noext;
					$clean   = preg_replace( '/-scaled$/i', '', $noext );
					if ( is_string( $clean ) && '' !== $clean ) {
						$stems[] = $clean;
					}
					$clean = preg_replace( '/-rotated$/i', '', (string) $clean );
					if ( is_string( $clean ) && '' !== $clean ) {
						$stems[] = $clean;
					}
				}
			}

			$meta = wp_get_attachment_metadata( $aid );
			if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
				$orig  = preg_replace( '/\.[a-z0-9]+$/i', '', basename( (string) $meta['original_image'] ) );
				if ( is_string( $orig ) && '' !== $orig ) {
					$stems[] = $orig;
				}
			}

			$stems = array_values( array_unique( array_filter( array_map( 'strval', $stems ) ) ) );

			return array_slice( $stems, 0, 4 );
		}

		/**
		 * ساخت نقشهٔ مراجع زندهٔ دیتابیس برای یک دسته شناسه.
		 *
		 * @param int[] $ids شناسه‌های پیوست.
		 * @return array<int,string[]> شناسه => فهرست دلایل.
		 */
		private static function live_refs( array $ids ) {
			global $wpdb;

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			if ( empty( $ids ) ) {
				return array();
			}

			$out   = array();
			$strs = array_map( 'strval', $ids );
			$s_ph = implode( ', ', array_fill( 0, count( $strs ), '%s' ) );

			// ۱) تصویر شاخص هر نوشته/محصول/متغیر (دقیق و ایندکس‌شده روی meta_key).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$thumb_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key = '_thumbnail_id' AND TRIM( meta_value ) IN ( {$s_ph} )",
					$strs
				),
				ARRAY_A
			);
			foreach ( (array) $thumb_rows as $row ) {
				$aid = (int) $row['meta_value'];
				$out[ $aid ][] = 'تصویر شاخص نوشته/محصول #' . absint( $row['post_id'] );
			}

			// ۲) گالری‌ها و متاهای شناسه‌ای (لیست، سریالایز یا JSON).
			$keys       = self::gallery_meta_keys();
			$keys       = array_values( array_diff( $keys, array( '_thumbnail_id' ) ) );
			if ( empty( $keys ) ) {
				$keys = array( '_product_image_gallery' );
			}
			$key_ph     = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
			$like_parts = array();
			$like_args  = array();
			foreach ( $ids as $id ) {
				$like_parts[] = 'meta_value LIKE %s';
				$like_args[]  = '%' . $id . '%';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$gal_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					 WHERE meta_key IN ( {$key_ph} ) AND ( " . implode( ' OR ', $like_parts ) . ' )',
					array_merge( $keys, $like_args )
				),
				ARRAY_A
			);
			foreach ( (array) $gal_rows as $row ) {
				foreach ( $ids as $id ) {
					if ( self::id_list_contains( $row['meta_value'], $id ) ) {
						$out[ $id ][] = self::meta_role_label( (string) $row['meta_key'] ) . ' #' . absint( $row['post_id'] );
					}
				}
			}

			// ۳) متای دسته/برند/سواچ.
			$term_like = implode( ' OR ', array_fill( 0, count( $ids ), 'meta_value LIKE %s' ) );
			if ( empty( $wpdb->termmeta ) ) {
				$wpdb->termmeta = $wpdb->prefix . 'termmeta';
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$term_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_id, meta_value FROM {$wpdb->termmeta} WHERE " . $term_like,
					$like_args
				),
				ARRAY_A
			);
			foreach ( (array) $term_rows as $row ) {
				foreach ( self::numeric_ids_in( $row['meta_value'] ) as $tid ) {
					if ( in_array( $tid, $ids, true ) ) {
						$out[ $tid ][] = 'تصویر دسته/برند/سواچ ترم #' . absint( $row['term_id'] );
					}
				}
			}

			// ۳٫۵) توضیحات دسته/برند و متن دیدگاه‌ها (بنر دسته و عکس نظرات مشتریان).
			$desc_like = implode( ' OR ', array_fill( 0, count( $ids ), 'description LIKE %s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$desc_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_id, description FROM {$wpdb->term_taxonomy} WHERE " . $desc_like,
					$like_args
				),
				ARRAY_A
			);
			foreach ( (array) $desc_rows as $drow ) {
				foreach ( $ids as $id ) {
					if ( self::id_list_contains( $drow['description'], $id ) ) {
						$out[ $id ][] = 'توضیحات دسته/برند #' . absint( $drow['term_id'] );
					}
				}
			}

			$comment_like = implode( ' OR ', array_fill( 0, count( $ids ), 'comment_content LIKE %s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$comment_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT comment_ID, comment_content FROM {$wpdb->comments}
					 WHERE comment_approved <> 'spam' AND ( " . $comment_like . ' )
					 LIMIT 200',
					$like_args
				),
				ARRAY_A
			);
			foreach ( (array) $comment_rows as $crow ) {
				foreach ( $ids as $id ) {
					if ( self::id_list_contains( $crow['comment_content'], $id ) ) {
						$out[ $id ][] = 'متن دیدگاه #' . absint( $crow['comment_ID'] );
					}
				}
			}

			// ۴) تنظیمات سایت: فاوآیکون/لوگو/پلیس‌هولدر + theme_mods و ابزارک‌ها.
			$direct = self::direct_option_labels();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$opt_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$s_ph} )",
					array_keys( $direct )
				),
				ARRAY_A
			);
			foreach ( (array) $opt_rows as $row ) {
				$oid = (int) $row['option_value'];
				if ( in_array( $oid, $ids, true ) ) {
					$name          = (string) $row['option_name'];
					$out[ $oid ][] = isset( $direct[ $name ] ) ? $direct[ $name ] : ( 'تنظیمات: ' . $name );
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$mod_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options}
					 WHERE ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s )
					   AND ( " . implode( ' OR ', $like_parts ) . ' )',
					array_merge(
						array( 'theme_mods_%', 'widget_%', 'xts-%' ),
						$like_args
					)
				),
				ARRAY_A
			);
			foreach ( (array) $mod_rows as $row ) {
				foreach ( $ids as $id ) {
					if ( self::id_list_contains( $row['option_value'], $id ) ) {
						$out[ $id ][] = 'تنظیمات قالب/ابزارک (' . sanitize_key( (string) $row['option_name'] ) . ')';
					}
				}
			}

			foreach ( $out as $aid => $reasons ) {
				$out[ $aid ] = array_slice( array_values( array_unique( $reasons ) ), 0, self::MAX_REASONS );
			}

			return $out;
		}

		/**
		 * عنوان فارسی نقش یک کلید متا.
		 *
		 * @param string $meta_key کلید متا.
		 * @return string
		 */
		private static function meta_role_label( $meta_key ) {
			$map = array(
				'_product_image_gallery'            => 'گالری محصول',
				'_wc_additional_variation_images'   => 'گالری متغیر محصول',
				'_variation_image_gallery'          => 'گالری متغیر',
				'woodmart_wc_video_gallery'         => 'گالری ویدیو/تصویر قالب',
				'_yoast_wpseo_opengraph-image-id'   => 'تصویر اشتراک‌گذاری (سئو)',
				'_yoast_wpseo_twitter-image-id'     => 'تصویر توییتر (سئو)',
				'rank_math_facebook_image_id'       => 'تصویر فیسبوک (سئو)',
				'rank_math_twitter_image_id'        => 'تصویر توییتر (سئو)',
			);

			return isset( $map[ $meta_key ] ) ? $map[ $meta_key ] : ( 'متای تصویری ' . $meta_key );
		}

		/**
		 * جست‌وجوی شناسه‌ها در محتوای نوشته‌ها بر اساس نام واقعی فایل و `wp-image-ID`.
		 *
		 * @param int[] $ids شناسه‌های پیوست.
		 * @return array<int,string[]> شناسه => دلایل.
		 */
		private static function content_refs( array $ids ) {
			global $wpdb;

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			if ( empty( $ids ) ) {
				return array();
			}

			// حیاتی: این بررسی «به‌ازای هر تصویر» و با LIMIT 1 انجام می‌شود.
			// قبلاً یک کوئری مشترک با LIMIT 60 اجرا می‌شد؛ در فروشگاهی با صدها نوشتهٔ
			// هم‌الگو، ردیفِ واقعیِ همان عکس می‌توانست بیرون از آن ۶۰ ردیف بیفتد و
			// نتیجه‌اش «بدون استفاده» اعلام شدن عکسِ در حال استفاده بود.
			$out = array();

			foreach ( $ids as $aid ) {
				$needles = array(
					'wp-image-' . $aid,
					'"id":' . $aid,
					'"id":"' . $aid . '"',
					'"attachment_id":' . $aid,
					'"image_id":' . $aid,
					'"thumb_id":' . $aid,
					// الگوهای رایج قالب‌ها/افزونه‌ها که فقط با شناسه کار می‌کنند.
					'data-id="' . $aid . '"',
					"data-id='" . $aid . "'",
					'data-attachment-id="' . $aid . '"',
					'data-image-id="' . $aid . '"',
					'attachment-' . $aid,
					'"ids":"' . $aid . '"',
					'"ids":' . $aid,
					'"gallery_id":' . $aid,
				);
				foreach ( self::file_stems( $aid ) as $stem ) {
					$needles[] = '/' . $stem . '.';
					$needles[] = '/' . $stem . '-';
					$needles[] = '"' . $stem . '.';
				}

				$parts = array();
				$args  = array();
				foreach ( array_values( array_unique( $needles ) ) as $needle ) {
					if ( '' === $needle ) {
						continue;
					}
					$parts[] = '( post_content LIKE %s OR post_excerpt LIKE %s )';
					$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
					$args[]  = '%' . $wpdb->esc_like( $needle ) . '%';
				}

				if ( empty( $parts ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT ID, post_title, post_status FROM {$wpdb->posts}
						 WHERE post_type NOT IN ( 'attachment', 'revision' )
						   AND ( " . implode( ' OR ', $parts ) . ' )
						 LIMIT 1',
						$args
					),
					ARRAY_A
				);

				if ( empty( $row ) ) {
					continue;
				}

				$title = ! empty( $row['post_title'] ) ? (string) $row['post_title'] : '#' . absint( $row['ID'] );
				$label = 'محتوای نوشته «' . wp_html_excerpt( $title, 32, '…' ) . '»';

				if ( 'publish' !== (string) $row['post_status'] ) {
					$label .= ' (' . self::status_label( (string) $row['post_status'] ) . ')';
				}

				$out[ $aid ][] = $label;

				// چند مرجع نمایش داده شود (فقط برای شفافیت؛ تصمیم با اولین مرجع گرفته شده است).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$more = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT post_title FROM {$wpdb->posts}
						 WHERE post_type NOT IN ( 'attachment', 'revision' )
						   AND ID <> %d
						   AND ( " . implode( ' OR ', $parts ) . ' )
						 LIMIT 2',
						array_merge( array( absint( $row['ID'] ) ), $args )
					)
				);
				foreach ( (array) $more as $more_title ) {
					$out[ $aid ][] = 'محتوای نوشته «' . wp_html_excerpt( (string) $more_title, 32, '…' ) . '»';
				}
			}

			foreach ( $out as $aid => $reasons ) {
				$out[ $aid ] = array_slice( array_values( array_unique( $reasons ) ), 0, self::MAX_REASONS );
			}

			return $out;
		}

		/**
		 * عنوان فارسی وضعیت نوشته برای نمایش دلیل.
		 *
		 * @param string $status وضعیت نوشته.
		 * @return string
		 */
		private static function status_label( $status ) {
			$map = array(
				'publish' => 'منتشرشده',
				'draft'   => 'پیش‌نویس',
				'pending' => 'در انتظار بررسی',
				'private' => 'خصوصی',
				'future'  => 'زمان‌بندی‌شده',
				'trash'   => 'زباله‌دان',
				'inherit' => 'ارثی',
			);

			return isset( $map[ $status ] ) ? $map[ $status ] : $status;
		}

		/**
		 * آیا این مسیر فایل در محتوای سایت ذکر شده است؟ (محافظ فایل‌های یتیم)
		 *
		 * @param string $rel_path مسیر نسبی داخل uploads.
		 * @return string[] فهرست دلایل (خالی = بدون مرجع).
		 */
		public static function file_refs( $rel_path ) {
			global $wpdb;

			$rel = ltrim( wp_normalize_path( (string) $rel_path ), '/' );
			if ( '' === $rel ) {
				return array();
			}

			$noext = preg_replace( '/\.[a-z0-9]+$/i', '', $rel );
			$names = array( $rel );
			if ( is_string( $noext ) && '' !== $noext ) {
				$names[] = $noext;
				$names[] = basename( $noext );
			}

			$parts = array();
			$args  = array();
			foreach ( array_unique( $names ) as $name ) {
				if ( '' === $name ) {
					continue;
				}
				$parts[] = '( post_content LIKE %s OR post_excerpt LIKE %s )';
				$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
				$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
			}

			if ( empty( $parts ) ) {
				return array();
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts}
					 WHERE post_type NOT IN ( 'attachment', 'revision' )
					   AND post_status NOT IN ( 'auto-draft' )
					   AND ( " . implode( ' OR ', $parts ) . ' )
					 LIMIT 1',
					$args
				),
				ARRAY_A
			);

			if ( empty( $row ) ) {
				return array();
			}

			$title = ! empty( $row['post_title'] ) ? (string) $row['post_title'] : '#' . absint( $row['ID'] );

			return array( 'مسیر فایل در محتوای «' . wp_html_excerpt( $title, 32, '…' ) . '» ذکر شده است' );
		}

		/**
		 * بررسی زندهٔ یک پیوست.
		 *
		 * @param int  $aid       شناسهٔ پیوست.
		 * @param bool $with_text آیا محتوا هم بررسی شود؟
		 * @return array{used:bool,reasons:string[]}
		 */
		public static function verify_attachment( $aid, $with_text = true ) {
			$aid = absint( $aid );
			if ( $aid <= 0 ) {
				return array(
					'used'    => true,
					'reasons' => array( 'شناسهٔ پیوست نامعتبر است' ),
				);
			}

			$reasons = array();
			if ( in_array( $aid, TisaCase_Optimizer_Settings::pinned_ids(), true ) ) {
				$reasons[] = 'در لیست سفید «هرگز حذف نشود» پین شده است';
			}

			// همان قاعدهٔ سخت‌گیرانهٔ مسیر مدیریتی: بدون کاتالوگ کامل، هیچ حذفی مجاز نیست.
			$persisted = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			$coverage  = ( is_array( $persisted ) && isset( $persisted['ref_coverage'] ) && 'full' === $persisted['ref_coverage'] && isset( $persisted['usage_engine'] ) && (int) $persisted['usage_engine'] >= 5 )
				? 'full'
				: 'partial';
			if ( 'full' !== $coverage ) {
				$reasons[] = 'کاتالوگ اسکن کامل نیست (اسکن فوری یا قدیمی)؛ ابتدا اسکن کامل را اجرا کنید';
			}

			$live = self::live_refs( array( $aid ) );
			if ( ! empty( $live[ $aid ] ) ) {
				$reasons = array_merge( $reasons, $live[ $aid ] );
			}

			if ( $with_text ) {
				$text = self::content_refs( array( $aid ) );
				if ( ! empty( $text[ $aid ] ) ) {
					$reasons = array_merge( $reasons, $text[ $aid ] );
				}
			}

			$reasons = array_slice( array_values( array_unique( $reasons ) ), 0, self::MAX_REASONS );

			return array(
				'used'    => ! empty( $reasons ),
				'reasons' => $reasons,
			);
		}

		/**
		 * سن کاتالوگ بر حسب ساعت.
		 *
		 * @return float|null null = کاتالوگی نیست.
		 */
		public static function catalog_age_hours() {
			$raw = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			if ( ! is_array( $raw ) || empty( $raw['timestamp'] ) ) {
				return null;
			}

			return max( 0, ( microtime( true ) - (float) $raw['timestamp'] ) / HOUR_IN_SECONDS );
		}

		/**
		 * راستی‌آزمایی گروهی پیش از حذف/قرنطینه.
		 *
		 * خروجی شامل سه فهرست است: مجاز، مسدود (با دلیل فارسی) و «نیازمند اسکن تازه».
		 *
		 * @param int[]    $attachment_ids  شناسه‌های پیوست درخواستی.
		 * @param string[] $rel_paths       مسیرهای فایل یتیم درخواستی.
		 * @param bool     $allow_draft_only آیا حذف تصاویر «فقط استفاده‌شده در پیش‌نویس» مجاز است؟
		 * @param bool     $bulk           حالت دسته‌ای بزرگ: اگر کاتالوگ کامل و تازه باشد،
		 *                                 بازرسی محتوای تک‌تک تصاویر (که به‌ازای هر تصویر یک
		 *                                 اسکن جدول نوشته‌ها است) انجام نمی‌شود؛ چون همان
		 *                                 محتوا در اسکن کاملِ کاتالوگ برای همهٔ تصاویر دیده شده.
		 *                                 این تغییر بار سنگین را از سرور برمی‌دارد و امنیت
		 *                                 را حفظ می‌کند (بدون کاتالوگ کامل، بازرسی انجام می‌شود).
		 * @return array<string,mixed>
		 */
		public static function verify_batch( array $attachment_ids, array $rel_paths = array(), $allow_draft_only = false, $bulk = false ) {
			$enabled = (bool) TisaCase_Optimizer_Settings::get( 'verify_before_delete', 1 );

			$ids = array();
			foreach ( $attachment_ids as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$ids[ $id ] = true;
				}
			}
			$ids = array_keys( $ids );

			$paths = array();
			foreach ( $rel_paths as $path ) {
				$path = ltrim( wp_normalize_path( (string) $path ), '/' );
				if ( '' !== $path ) {
					$paths[ $path ] = true;
				}
			}
			$paths = array_keys( $paths );

			$result = array(
				'allow_ids'     => $ids,
				'blocked'       => array(),
				'allow_paths'   => $paths,
				'blocked_paths' => array(),
				'probed'        => 0,
				'catalog_age'   => self::catalog_age_hours(),
				'needs_rescan'  => false,
				'enabled'       => $enabled,
				'draft_allowed' => (bool) $allow_draft_only,
			);

			if ( ! $enabled || ( empty( $ids ) && empty( $paths ) ) ) {
				return $result;
			}

			$allow = array_fill_keys( $ids, true );

			// تصاویری که فقط در پیش‌نویس استفاده شده‌اند، تنها با تأیید صریح کاربر حذف می‌شوند.
			$draft_only_ids = array();
			if ( $allow_draft_only ) {
				$pre = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
				if ( is_array( $pre ) && ! empty( $pre['items'] ) ) {
					foreach ( $ids as $aid ) {
						if ( isset( $pre['items'][ $aid ]['usage_status'] ) && 'draft_only' === $pre['items'][ $aid ]['usage_status'] ) {
							$draft_only_ids[ $aid ] = true;
						}
					}
				}
			}

			// گام ۱: مراجع زندهٔ دیتابیس (سریع، دقیق و همیشه فعال).
			if ( ! empty( $ids ) ) {
				$live = self::live_refs( $ids );
				foreach ( $ids as $aid ) {
					if ( in_array( $aid, TisaCase_Optimizer_Settings::pinned_ids(), true ) ) {
						$result['blocked'][ $aid ] = array( 'در لیست سفید «هرگز حذف نشود» پین شده است' );
						unset( $allow[ $aid ] );
						continue;
					}
					if ( isset( $draft_only_ids[ $aid ] ) ) {
						continue;
					}
					if ( ! empty( $live[ $aid ] ) ) {
						$result['blocked'][ $aid ] = $live[ $aid ];
						unset( $allow[ $aid ] );
					}
				}
			}

			// گام ۲: مراجع ثبت‌شده در کاتالوگ (نتیجهٔ اسکن کامل لایه‌ای).
			// فقط کاتالوگ آمادهٔ روی دیسک خوانده می‌شود؛ هرگز اسکن سنگین از مسیر حذف اجرا نمی‌شود.
			$persisted     = TisaCase_Optimizer_Reference_Scanner::read_persisted_catalog();
			$catalog_items = ( is_array( $persisted ) && ! empty( $persisted['items'] ) && is_array( $persisted['items'] ) )
				? $persisted['items']
				: array();
			$catalog_ok    = ! empty( $catalog_items );
			$age_hours     = $result['catalog_age'];
			$fresh_h       = max( 1, (int) TisaCase_Optimizer_Settings::get( 'verify_fresh_hours', 72 ) );
			$is_fresh      = ( null !== $age_hours && $age_hours <= $fresh_h );

			// آیا کاتالوگ همهٔ لایه‌های مرجع‌یاب را دیده است؟
			// کاتالوگی که با دکمهٔ «نمایش فوری نتایج» (fast_finish) ساخته شود، لایهٔ محتوا/متا را
			// ندیده و «صفر مرجع» آن معنا ندارد. کاتالوگ نسخه‌های قدیمی هم همین حکم را دارد.
			$coverage = 'none';
			if ( $catalog_ok ) {
				$engine   = isset( $persisted['usage_engine'] ) ? (int) $persisted['usage_engine'] : 0;
				$coverage = ( isset( $persisted['ref_coverage'] ) && 'full' === $persisted['ref_coverage'] && $engine >= 5 )
					? 'full'
					: 'partial';
			}
			$result['coverage'] = $coverage;
			$result['fresh']    = $is_fresh;

			// سقف عمر قابل‌اتکا: کاتالوگ خیلی کهنه حتی با پوشش کامل هم حذف را مجاز نمی‌کند.
			$hard_stale_h = max( 24, (int) TisaCase_Optimizer_Settings::get( 'verify_max_age_hours', 168 ) );

			$coverage_block = '';
			if ( 'none' === $coverage ) {
				$coverage_block = 'هنوز اسکن کاملی انجام نشده است؛ برای حذف ایمن ابتدا «اسکن کامل تصاویر» را اجرا کنید';
				$result['needs_rescan'] = true;
			} elseif ( 'partial' === $coverage ) {
				$coverage_block = 'کاتالوگ فعلی با «نمایش فوری نتایج» ساخته شده و لایه‌های محتوا/متا بررسی نشده‌اند؛ ابتدا «اسکن کامل» را اجرا کنید';
				$result['needs_rescan'] = true;
			} elseif ( null !== $age_hours && $age_hours > $hard_stale_h ) {
				$coverage_block = sprintf( 'کاتالوگ اسکن از %d ساعت پیش به‌روزرسانی نشده است؛ یک‌بار اسکن کامل را تازه کنید', (int) round( $age_hours ) );
				$result['needs_rescan'] = true;
			}

			if ( '' !== $coverage_block ) {
				foreach ( array_keys( $allow ) as $aid ) {
					unset( $allow[ $aid ] );
					$result['blocked'][ $aid ] = array( $coverage_block );
				}
			}


			$need_probe = array();

			foreach ( array_keys( $allow ) as $aid ) {
				$item = isset( $catalog_items[ $aid ] ) ? $catalog_items[ $aid ] : null;
				if ( is_array( $item ) ) {
					$refs   = isset( $item['refs_count'] ) ? (int) $item['refs_count'] : 0;
					$status = isset( $item['usage_status'] ) ? (string) $item['usage_status'] : '';
					if ( $refs > 0 && ! ( $allow_draft_only && 'draft_only' === $status ) ) {
						$labels = array();
						if ( ! empty( $item['refs'] ) && is_array( $item['refs'] ) ) {
							foreach ( $item['refs'] as $ref ) {
								if ( ! empty( $ref['label'] ) ) {
									$labels[] = (string) $ref['label'];
								}
							}
						}
						$result['blocked'][ $aid ] = ! empty( $labels )
							? array_slice( $labels, 0, self::MAX_REASONS )
							: array( 'در نتیجهٔ اسکن، مرجع دارد' );
						unset( $allow[ $aid ] );
						continue;
					}
					// بازبینی محتوای زنده:
					//   • در حذف تک/کوچک: همیشه (حتی با کاتالوگ تازه) تا الگوهای قالبی
					//     مثل data-id/attachment-ID هم دیده شوند.
					//   • در حالت دسته‌ای بزرگ با کاتالوگ کامل و تازه: لازم نیست؛ چون
					//     اسکن کامل همان محتوا را برای همهٔ پیوست‌ها پیمایش کرده و
					//     انجام این کار برای ۱۵۰۰ عکس یعنی ۱۵۰۰ اسکن سنگین جدول.
					if ( $bulk && $is_fresh && 'full' === $coverage ) {
						continue;
					}
					$need_probe[] = $aid;
					continue;
				}

				// آیتم در کاتالوگ نیست: حتماً بازرسی محتوای زنده لازم است.
				$need_probe[] = $aid;
			}

			// گام ۳: بازرسی محتوای زنده فقط برای موارد مشکوک/کاتالوگ قدیمی (با سقف زمانی).
			if ( ! empty( $need_probe ) ) {
				$slice = array_slice( $need_probe, 0, self::MAX_CONTENT_PROBES );
				$text  = self::content_refs( $slice );
				$result['probed'] = count( $slice );

				foreach ( $slice as $aid ) {
					if ( ! empty( $text[ $aid ] ) ) {
						$result['blocked'][ $aid ] = $text[ $aid ];
						unset( $allow[ $aid ] );
					}
				}

				$rest = array_slice( $need_probe, self::MAX_CONTENT_PROBES );
				if ( ! empty( $rest ) ) {
					foreach ( $rest as $aid ) {
						unset( $allow[ $aid ] );
						$result['blocked'][ $aid ] = array( 'برای بازبینی محتوا، این موارد را جداگانه حذف کنید' );
					}
				}

				if ( 'full' !== $coverage ) {
					$result['needs_rescan'] = true;
				}
			}

			// گام ۴: محافظ فایل‌های یتیم؛ اگر مسیر در محتوا ذکر شده باشد حذف نمی‌شود.
			$allow_paths = array();
			foreach ( $paths as $path ) {
				$refs = self::file_refs( $path );
				if ( ! empty( $refs ) ) {
					$result['blocked_paths'][ $path ] = $refs;
					continue;
				}
				$allow_paths[] = $path;
			}

			$result['allow_ids']   = array_values( array_map( 'intval', array_keys( $allow ) ) );
			$result['allow_paths'] = $allow_paths;

			return $result;
		}
	}
}
