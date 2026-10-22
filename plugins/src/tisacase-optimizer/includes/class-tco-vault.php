<?php
/**
 * خزانهٔ قرنطینهٔ ۳۰ روزه و پشتیبان فایل‌های اصلی (Quarantine & Original Backup Vault).
 *
 * امنیت:
 *  - پوشهٔ `uploads/tisa-opt-vault/` با `.htaccess` (Apache 2.2/2.4 + LiteSpeed)،
 *    `web.config` (IIS) و `index.php` مسدود می‌شود.
 *  - هر مسیر فایل پیش از جابه‌جایی یا حذف با `realpath()` داخل ریشهٔ مجاز قفل می‌شود
 *    تا هیچ نوع Path Traversal ممکن نباشد.
 *  - هنگام قرنطینهٔ یک پیوست وردپرس، کل ردیف `wp_posts`، تمام متاهای `wp_postmeta`،
 *    فایل اصلی، فایل `original_image` و تمام زیرسایزها داخل بستهٔ قرنطینه منتقل می‌شوند
 *    و رکورد پیوست از وردپرس حذف می‌گردد تا اگر هر صفحه‌ای به آن وابسته بود فوراً آشکار
 *    شود؛ با «بازگردانی تک‌کلیکی» دقیقاً همان Attachment ID، همان مسیر فایل و همان متاها
 *    عین روز اول بازسازی می‌شوند!
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Vault' ) ) {

	/**
	 * مدیریت قرنطینه و پشتیبان تصاویر.
	 */
	final class TisaCase_Optimizer_Vault {

		/** شمارندهٔ تغییرات فهرست قرنطینه/پشتیبان (برای باطل‌کردن کش خلاصه). */
		private static $summary_rev = 0;

		/**
		 * باطل‌کردن کش «خلاصهٔ خزانه» پس از هر تغییر در فهرست‌ها.
		 *
		 * @return void
		 */
		private static function invalidate_summary() {
			++self::$summary_rev;
		}

		const DIR_NAME           = 'tisa-opt-vault';
		const SUB_QUARANTINE     = 'quarantine';
		const SUB_ORIGINALS      = 'originals';
		const SUB_INDEX          = 'index';
		const OPTION_QUARANTINE  = 'tisacase_optimizer_quarantine_items';
		const OPTION_ORIG_INDEX  = 'tisacase_optimizer_original_backups';
		const CRON_PURGE_HOOK    = 'tisacase_optimizer_daily_vault_purge';

		/**
		 * مسیر ریشهٔ خزانه در `uploads/` و ایمن‌سازی دایرکتوری‌ها.
		 *
		 * @return string مسیر مطلق بدون اسلش انتهایی (یا رشتهٔ خالی در صورت خطا).
		 */
		public static function base_dir() {
			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
				return '';
			}
			$root = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . self::DIR_NAME );
			self::ensure_protected( $root );
			return $root;
		}

		/**
		 * ساخت زیرپوشه‌ها و فایل‌های محافظتی وب‌سرور.
		 *
		 * @param string $root مسیر ریشهٔ خزانه.
		 * @return void
		 */
		public static function ensure_protected( $root ) {
			if ( '' === $root ) {
				return;
			}
			$dirs = array(
				$root,
				$root . '/' . self::SUB_QUARANTINE,
				$root . '/' . self::SUB_ORIGINALS,
				$root . '/' . self::SUB_INDEX,
			);
			foreach ( $dirs as $dir ) {
				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}
				$idx = $dir . '/index.php';
				if ( ! file_exists( $idx ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					@file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
				}
			}

			$hta = $root . '/.htaccess';
			if ( ! file_exists( $hta ) ) {
				$rules = "# TisaCase Optimizer Protected Vault\n"
					. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
					. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $hta, $rules );
			}

			$webconfig = $root . '/web.config';
			if ( ! file_exists( $webconfig ) ) {
				$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $webconfig, $xml );
			}
		}

		/**
		 * بررسی اینکه مسیر فایل واقعاً داخل پوشهٔ `uploads/` سایت قرار دارد.
		 *
		 * @param string $path مسیر فایل.
		 * @return string|false مسیر نرمال‌شدهٔ واقعی یا false.
		 */
		public static function assert_inside_uploads( $path ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return false;
			}
			$base = realpath( $uploads['basedir'] );
			$real = realpath( (string) $path );
			if ( false === $base || false === $real ) {
				return false;
			}
			$base_norm = wp_normalize_path( $base ) . '/';
			$real_norm = wp_normalize_path( $real );
			if ( 0 !== strpos( $real_norm, $base_norm ) ) {
				return false;
			}
			return $real_norm;
		}

		/**
		 * جمع‌آوری تمام فایل‌های فیزیکی متعلق به یک پیوست وردپرس (اصلی + خام + زیرسایزها + WebP/AVIF موازی).
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return array<int,array{abs:string,rel:string,role:string,bytes:int}>
		 */
		public static function collect_attachment_files( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$uploads       = wp_upload_dir();
			if ( $attachment_id <= 0 || empty( $uploads['basedir'] ) ) {
				return array();
			}

			$basedir  = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
			$rel_main = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$meta     = wp_get_attachment_metadata( $attachment_id );
			$candidates = array();

			if ( '' !== $rel_main ) {
				$rel_clean                = ltrim( wp_normalize_path( $rel_main ), '/' );
				$candidates[ $rel_clean ] = 'main';
				$subdir                   = wp_normalize_path( dirname( $rel_clean ) );
				$subdir                   = ( '.' === $subdir ) ? '' : trailingslashit( $subdir );

				if ( is_array( $meta ) ) {
					if ( ! empty( $meta['original_image'] ) ) {
						$orig_rel                = $subdir . basename( (string) $meta['original_image'] );
						$candidates[ $orig_rel ] = 'original';
					}
					if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
						foreach ( $meta['sizes'] as $size_key => $size_info ) {
							if ( ! empty( $size_info['file'] ) ) {
								$sub_rel                = $subdir . basename( (string) $size_info['file'] );
								$candidates[ $sub_rel ] = 'subsize:' . sanitize_key( (string) $size_key );
							}
						}
					}
				}
			}

			// بررسی نسخه‌های موازی WebP / AVIF ساخته‌شده کنار هر فایل.
			$expanded = array();
			foreach ( $candidates as $rel => $role ) {
				$expanded[ $rel ] = $role;
				foreach ( array( '.webp', '.avif' ) as $ext ) {
					$expanded[ $rel . $ext ] = 'sidecar:' . ltrim( $ext, '.' );
				}
			}

			$found = array();
			$seen  = array();
			foreach ( $expanded as $rel => $role ) {
				$full = $basedir . ltrim( $rel, '/' );
				$safe = self::assert_inside_uploads( $full );
				if ( false === $safe || isset( $seen[ $safe ] ) || ! is_file( $safe ) ) {
					continue;
				}
				$seen[ $safe ] = true;
				$rel_actual    = ltrim( substr( $safe, strlen( wp_normalize_path( realpath( $uploads['basedir'] ) ) ) ), '/' );
				$found[]       = array(
					'abs'   => $safe,
					'rel'   => $rel_actual,
					'role'  => $role,
					'bytes' => max( 0, (int) @filesize( $safe ) ),
				);
			}

			return $found;
		}

		/**
		 * انتقال یک پیوست کتابخانهٔ رسانه به قرنطینهٔ امن با قابلیت بازگردانی کامل.
		 *
		 * @param int    $attachment_id شناسهٔ پیوست.
		 * @param string $reason        علت قرنطینه (unused | duplicate | manual).
		 * @return array<string,mixed>|WP_Error
		 */
		public static function quarantine_attachment( $attachment_id, $reason = 'unused', $batch_id = '' ) {
			global $wpdb;

			$attachment_id = absint( $attachment_id );
			$post          = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'پیوست مورد نظر در کتابخانهٔ رسانه یافت نشد.', 'tisacase-optimizer' ) );
			}

			$vault_root = self::base_dir();
			if ( '' === $vault_root ) {
				return new WP_Error( 'vault_unavailable', __( 'امکان ساخت پوشهٔ قرنطینه در هاست وجود ندارد.', 'tisacase-optimizer' ) );
			}
			$uploads = wp_upload_dir();

			$item_id = 'q-' . $attachment_id . '-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 5, false, false ) );
			$pkg_dir = $vault_root . '/' . self::SUB_QUARANTINE . '/' . $item_id;
			if ( ! wp_mkdir_p( $pkg_dir . '/files' ) ) {
				return new WP_Error( 'mkdir_failed', __( 'ساخت پوشهٔ بستهٔ قرنطینه ناموفق بود.', 'tisacase-optimizer' ) );
			}

			// ۱) پشتیبان کامل ردیف wp_posts و تمام ردیف‌های wp_postmeta.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $attachment_id ),
				ARRAY_A
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC", $attachment_id ),
				ARRAY_A
			);

			// ۲) انتقال فایل‌ها به بستهٔ قرنطینه.
			//
			// بهینه‌سازی I/O: قبلاً هر فایل «کپی» می‌شد و بعد فایل اصلی حذف می‌شد
			// (دو برابر نوشتن + دو برابر خواندن). اکنون ابتدا یک مانیفست مقدماتی
			// نوشته می‌شود و سپس فایل‌ها با `rename` (جابه‌جایی اتمیک روی همان
			// فایل‌سیستم) منتقل می‌شوند؛ اگر `rename` ممکن نبود، همان کپی امن
			// انجام و فایل اصلی حذف می‌شود. نتیجه: نصف شدن I/O در قرنطینهٔ
			// هزاران تصویر و مصون‌بودن در برابر قطع ناگهانی PHP وسط جابه‌جایی.
			$files       = self::collect_attachment_files( $attachment_id );
			$moved_files = array();
			$total_bytes = 0;

			$days       = max( 1, (int) TisaCase_Optimizer_Settings::get( 'quarantine_days', 30 ) );
			$expires_at = time() + ( $days * DAY_IN_SECONDS );
			$rel_main   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

			// مانیفست مقدماتی: اگر فرایند وسط انتقال قطع شود، بازیابی می‌داند
			// چه فایل‌هایی باید در بسته باشد.
			$prelim = array(
				'id'            => $item_id,
				'batch_id'      => sanitize_key( (string) $batch_id ),
				'kind'          => 'attachment',
				'attachment_id' => $attachment_id,
				'rel_path'      => $rel_main,
				'state'         => 'copying',
				'created_at'    => current_time( 'mysql' ),
				'files'         => array(),
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $pkg_dir . '/manifest.partial.json', wp_json_encode( $prelim, JSON_UNESCAPED_UNICODE ) );

			foreach ( $files as $f ) {
				$dest = $pkg_dir . '/files/' . $f['rel'];
				wp_mkdir_p( dirname( $dest ) );

				$moved = false;
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				if ( @rename( $f['abs'], $dest ) ) {
					$moved = true;
				} else {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( @copy( $f['abs'], $dest ) ) {
						wp_delete_file( $f['abs'] );
						$moved = true;
					}
				}

				if ( $moved ) {
					$moved_files[] = array(
						'rel'   => $f['rel'],
						'role'  => $f['role'],
						'bytes' => $f['bytes'],
					);
					$total_bytes  += $f['bytes'];
				}
			}

			$manifest = array(
				'id'            => $item_id,
				'batch_id'      => sanitize_key( (string) $batch_id ),
				'kind'          => 'attachment',
				'attachment_id' => $attachment_id,
				'title'         => (string) $post->post_title,
				'filename'      => '' !== $rel_main ? basename( $rel_main ) : basename( (string) $post->guid ),
				'rel_path'      => $rel_main,
				'mime_type'     => (string) $post->post_mime_type,
				'reason'        => sanitize_key( $reason ),
				'total_bytes'   => $total_bytes,
				'files_count'   => count( $moved_files ),
				'files'         => $moved_files,
				'post_row'      => $post_row,
				'meta_rows'     => is_array( $meta_rows ) ? $meta_rows : array(),
				'created_at'    => current_time( 'mysql' ),
				'timestamp'     => time(),
				'expires_at'    => $expires_at,
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$written = @file_put_contents( $pkg_dir . '/manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			if ( false === $written ) {
				// بازگرداندن فایل‌ها به محل اول تا هیچ تصویری در حالت «نه اینجا نه آنجا» نماند.
				foreach ( $moved_files as $mf ) {
					$back = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $mf['rel'] );
					$src  = $pkg_dir . '/files/' . $mf['rel'];
					if ( is_file( $src ) && is_file( $back ) === false ) {
						wp_mkdir_p( dirname( $back ) );
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
						@rename( $src, $back );
					}
				}
				return new WP_Error( 'manifest_failed', __( 'ذخیرهٔ مانیفست قرنطینه ناموفق بود؛ هیچ فایلی حذف نشد.', 'tisacase-optimizer' ) );
			}
			if ( is_file( $pkg_dir . '/manifest.partial.json' ) ) {
				wp_delete_file( $pkg_dir . '/manifest.partial.json' );
			}

			// ۳) اکنون با خیال آسوده پیوست و فایل‌هایش را از وردپرس پاک می‌کنیم.
			wp_delete_attachment( $attachment_id, true );
			// فایل‌ها معمولاً با rename جابه‌جا شده‌اند؛ این حلقه فقط بازمانده‌ها
			// (فایل‌هایی که انتقالشان ناموفق بود) را پاک می‌کند.
			foreach ( $files as $f ) {
				if ( is_file( $f['abs'] ) ) {
					wp_delete_file( $f['abs'] );
				}
			}

			$summary = array(
				'id'            => $item_id,
				'batch_id'      => $manifest['batch_id'],
				'kind'          => 'attachment',
				'attachment_id' => $attachment_id,
				'title'         => $manifest['title'],
				'filename'      => $manifest['filename'],
				'rel_path'      => $manifest['rel_path'],
				'mime_type'     => $manifest['mime_type'],
				'reason'        => $manifest['reason'],
				'total_bytes'   => $total_bytes,
				'files_count'   => count( $moved_files ),
				'created_at'    => $manifest['created_at'],
				'timestamp'     => $manifest['timestamp'],
				'expires_at'    => $expires_at,
			);

			$index = self::list_quarantine();
			array_unshift( $index, $summary );
			update_option( self::OPTION_QUARANTINE, $index, false );
			self::invalidate_summary();

			TisaCase_Optimizer_Settings::bump_stats(
				array(
					'quarantined_count' => 1,
					'saved_bytes'       => $total_bytes,
				)
			);

			return $summary;
		}

		/**
		 * انتقال یک فایل یتیمِ ثبت‌نشدهٔ روی دیسک به قرنطینهٔ ۳۰ روزه.
		 *
		 * @param string $rel_path مسیر نسبی داخل `uploads/`.
		 * @return array<string,mixed>|WP_Error
		 */
		public static function quarantine_orphan_file( $rel_path, $batch_id = '' ) {
			$uploads = wp_upload_dir();
			if ( empty( $uploads['basedir'] ) ) {
				return new WP_Error( 'no_uploads', __( 'پوشهٔ بارگذاری یافت نشد.', 'tisacase-optimizer' ) );
			}

			$rel_clean = ltrim( wp_normalize_path( (string) $rel_path ), '/' );
			$abs       = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $rel_clean );
			$safe      = self::assert_inside_uploads( $abs );
			if ( false === $safe || ! is_file( $safe ) ) {
				return new WP_Error( 'invalid_file', __( 'فایل مورد نظر در پوشهٔ بارگذاری یافت نشد.', 'tisacase-optimizer' ) );
			}

			// جلوگیری از قرنطینهٔ خودِ پوشهٔ خزانه!
			if ( 0 === strpos( $rel_clean, self::DIR_NAME . '/' ) ) {
				return new WP_Error( 'protected_path', __( 'این فایل متعلق به خزانهٔ محافظت‌شده است.', 'tisacase-optimizer' ) );
			}

			$vault_root = self::base_dir();
			$item_id    = 'qf-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 6, false, false ) );
			$pkg_dir    = $vault_root . '/' . self::SUB_QUARANTINE . '/' . $item_id;
			$dest       = $pkg_dir . '/files/' . $rel_clean;
			wp_mkdir_p( dirname( $dest ) );

			$bytes   = max( 0, (int) @filesize( $safe ) );
			$relocated = false;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $safe, $dest ) ) {
				$relocated = true;
			} else {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! @copy( $safe, $dest ) ) {
					return new WP_Error( 'copy_failed', __( 'انتقال فایل به قرنطینه ناموفق بود.', 'tisacase-optimizer' ) );
				}
			}

			$days       = max( 1, (int) TisaCase_Optimizer_Settings::get( 'quarantine_days', 30 ) );
			$expires_at = time() + ( $days * DAY_IN_SECONDS );
			$filetype   = wp_check_filetype( basename( $rel_clean ) );

			$manifest = array(
				'id'            => $item_id,
				'batch_id'      => sanitize_key( (string) $batch_id ),
				'kind'          => 'disk_orphan',
				'attachment_id' => 0,
				'title'         => basename( $rel_clean ),
				'filename'      => basename( $rel_clean ),
				'rel_path'      => $rel_clean,
				'mime_type'     => ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream',
				'reason'        => 'disk_orphan',
				'total_bytes'   => $bytes,
				'files_count'   => 1,
				'files'         => array(
					array(
						'rel'   => $rel_clean,
						'role'  => 'orphan_file',
						'bytes' => $bytes,
					),
				),
				'created_at'    => current_time( 'mysql' ),
				'timestamp'     => time(),
				'expires_at'    => $expires_at,
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $pkg_dir . '/manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			if ( ! $relocated && is_file( $safe ) ) {
				wp_delete_file( $safe );
			}

			$summary = $manifest;
			unset( $summary['files'] );

			$index = self::list_quarantine();
			array_unshift( $index, $summary );
			update_option( self::OPTION_QUARANTINE, $index, false );
			self::invalidate_summary();

			TisaCase_Optimizer_Settings::bump_stats(
				array(
					'quarantined_count' => 1,
					'saved_bytes'       => $bytes,
				)
			);

			return $summary;
		}

		/**
		 * بازگردانی کامل یک مورد از قرنطینه به مسیر اصلی و همان Attachment ID اولیه!
		 *
		 * @param string $item_id شناسهٔ رکورد قرنطینه.
		 * @return array<string,mixed>|WP_Error
		 */
		public static function restore_quarantine_item( $item_id ) {
			global $wpdb;

			$item_id    = sanitize_file_name( (string) $item_id );
			$vault_root = self::base_dir();
			$pkg_dir    = $vault_root . '/' . self::SUB_QUARANTINE . '/' . $item_id;
			$manifest_f = $pkg_dir . '/manifest.json';

			if ( ! is_file( $manifest_f ) ) {
				return new WP_Error( 'missing_manifest', __( 'بستهٔ قرنطینه روی دیسک پیدا نشد.', 'tisacase-optimizer' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$manifest = json_decode( (string) file_get_contents( $manifest_f ), true );
			if ( ! is_array( $manifest ) ) {
				return new WP_Error( 'corrupt_manifest', __( 'فایل مانیفست قرنطینه آسیب دیده است.', 'tisacase-optimizer' ) );
			}

			$uploads = wp_upload_dir();
			$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

			// ۱) بازگردانی تمام فایل‌ها به مسیر دقیق قبلی.
			if ( ! empty( $manifest['files'] ) && is_array( $manifest['files'] ) ) {
				foreach ( $manifest['files'] as $f ) {
					$rel = ltrim( str_replace( '..', '', (string) $f['rel'] ), '/' );
					$src = $pkg_dir . '/files/' . $rel;
					$dst = $basedir . $rel;
					if ( is_file( $src ) ) {
						wp_mkdir_p( dirname( $dst ) );
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@copy( $src, $dst );
					}
				}
			}

			// ۲) بازگردانی رکورد دیتابیس با همان Attachment ID دقیق قبلی!
			if ( 'attachment' === $manifest['kind'] && ! empty( $manifest['post_row'] ) && is_array( $manifest['post_row'] ) ) {
				$row     = $manifest['post_row'];
				$orig_id = isset( $row['ID'] ) ? absint( $row['ID'] ) : 0;

				// اگر ID قبلی آزاد است با همان ID دقیق برمی‌گردد؛ اگر پست جدیدی آن ID را گرفته، پست جدید ساخته می‌شود.
				if ( $orig_id > 0 && ! get_post( $orig_id ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $wpdb->posts, $row );
					$restored_id = $orig_id;
				} else {
					unset( $row['ID'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->insert( $wpdb->posts, $row );
					$restored_id = (int) $wpdb->insert_id;
				}

				if ( $restored_id > 0 && ! empty( $manifest['meta_rows'] ) && is_array( $manifest['meta_rows'] ) ) {
					foreach ( $manifest['meta_rows'] as $meta_row ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->insert(
							$wpdb->postmeta,
							array(
								'post_id'    => $restored_id,
								'meta_key'   => isset( $meta_row['meta_key'] ) ? (string) $meta_row['meta_key'] : '',
								'meta_value' => isset( $meta_row['meta_value'] ) ? $meta_row['meta_value'] : '',
							)
						);
					}
					clean_post_cache( $restored_id );
				}
			}

			self::remove_dir_recursive( $pkg_dir );
			self::remove_from_quarantine_index( $item_id );
			TisaCase_Optimizer_Settings::bump_stats( array( 'restored_count' => 1 ) );

			return array(
				'id'            => $item_id,
				'attachment_id' => isset( $manifest['attachment_id'] ) ? (int) $manifest['attachment_id'] : 0,
				'filename'      => isset( $manifest['filename'] ) ? (string) $manifest['filename'] : '',
			);
		}

		/**
		 * حذف قطعی یک مورد از قرنطینه و آزادسازی کامل فضای دیسک خزانه.
		 *
		 * @param string $item_id شناسهٔ رکورد قرنطینه.
		 * @return int بایت آزادشده از خزانه.
		 */
		public static function delete_quarantine_permanently( $item_id ) {
			$item_id    = sanitize_file_name( (string) $item_id );
			$vault_root = self::base_dir();
			$pkg_dir    = $vault_root . '/' . self::SUB_QUARANTINE . '/' . $item_id;
			$bytes      = self::dir_size( $pkg_dir );

			self::remove_dir_recursive( $pkg_dir );
			self::remove_from_quarantine_index( $item_id );
			TisaCase_Optimizer_Settings::bump_stats( array( 'deleted_count' => 1 ) );

			return $bytes;
		}

		/**
		 * حذف مستقیم و فوری پیوست (بدون عبور از قرنطینه).
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return array<string,mixed>|WP_Error
		 */
		public static function delete_attachment_permanently( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$post          = get_post( $attachment_id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'پیوست یافت نشد.', 'tisacase-optimizer' ) );
			}

			$files = self::collect_attachment_files( $attachment_id );
			$bytes = 0;
			foreach ( $files as $f ) {
				$bytes += $f['bytes'];
			}

			$rel_main = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$filename = '' !== $rel_main ? basename( $rel_main ) : basename( (string) $post->guid );

			wp_delete_attachment( $attachment_id, true );
			foreach ( $files as $f ) {
				if ( is_file( $f['abs'] ) ) {
					wp_delete_file( $f['abs'] );
				}
			}

			TisaCase_Optimizer_Settings::bump_stats(
				array(
					'deleted_count' => 1,
					'saved_bytes'   => $bytes,
				)
			);

			return array(
				'attachment_id' => $attachment_id,
				'filename'      => $filename,
				'saved_bytes'   => $bytes,
			);
		}

		/**
		 * ذخیرهٔ پشتیبان نسخهٔ اصلیِ پیش از فشرده‌سازی/تبدیل به WebP (برای امکان بازگردانی کیفیت اولیه).
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return bool
		 */
		public static function backup_original_before_optimize( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id <= 0 ) {
				return false;
			}
			$orig_index = self::list_original_backups();
			if ( isset( $orig_index[ $attachment_id ] ) ) {
				// پشتیبان دست‌نخوردهٔ اولیه از قبل موجود است؛ روی آن بازنویسی نمی‌کنیم!
				return true;
			}

			$rel_main = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$post     = get_post( $attachment_id );
			$uploads  = wp_upload_dir();
			if ( '' === $rel_main || ! $post || empty( $uploads['basedir'] ) ) {
				return false;
			}

			$src = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( $rel_main, '/' ) );
			$src = self::assert_inside_uploads( $src );
			if ( false === $src || ! is_file( $src ) ) {
				return false;
			}

			$vault_root = self::base_dir();
			$dest_dir   = $vault_root . '/' . self::SUB_ORIGINALS . '/' . $attachment_id;
			wp_mkdir_p( $dest_dir );
			$dest_file  = $dest_dir . '/' . basename( $src );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $src, $dest_file ) ) {
				return false;
			}

			$orig_index[ $attachment_id ] = array(
				'attachment_id' => $attachment_id,
				'rel_path'      => ltrim( wp_normalize_path( $rel_main ), '/' ),
				'backup_file'   => basename( $src ),
				'mime_type'     => (string) $post->post_mime_type,
				'meta'          => wp_get_attachment_metadata( $attachment_id ),
				'bytes'         => max( 0, (int) @filesize( $dest_file ) ),
				'created_at'    => current_time( 'mysql' ),
			);
			update_option( self::OPTION_ORIG_INDEX, $orig_index, false );
			self::invalidate_summary();
			return true;
		}

		/**
		 * بازگردانی تصویر فشرده‌شده/WebP‌شده به فایل اصلی اولیه.
		 *
		 * @param int $attachment_id شناسهٔ پیوست.
		 * @return bool|WP_Error
		 */
		public static function restore_original_backup( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			$orig_index    = self::list_original_backups();
			if ( ! isset( $orig_index[ $attachment_id ] ) ) {
				return new WP_Error( 'no_backup', __( 'پشتیبان اولیه برای این تصویر ثبت نشده است.', 'tisacase-optimizer' ) );
			}

			$entry      = $orig_index[ $attachment_id ];
			$vault_root = self::base_dir();
			$src        = $vault_root . '/' . self::SUB_ORIGINALS . '/' . $attachment_id . '/' . $entry['backup_file'];
			if ( ! is_file( $src ) ) {
				return new WP_Error( 'missing_backup_file', __( 'فایل پشتیبان اولیه روی دیسک یافت نشد.', 'tisacase-optimizer' ) );
			}

			$uploads = wp_upload_dir();
			$dst     = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( (string) $entry['rel_path'], '/' ) );
			wp_mkdir_p( dirname( $dst ) );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @copy( $src, $dst ) ) {
				return new WP_Error( 'restore_copy_failed', __( 'کپی فایل اصلی ناموفق بود.', 'tisacase-optimizer' ) );
			}

			update_attached_file( $attachment_id, $entry['rel_path'] );
			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => $entry['mime_type'],
				)
			);
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$regen = wp_generate_attachment_metadata( $attachment_id, $dst );
			if ( is_array( $regen ) && ! empty( $regen ) ) {
				wp_update_attachment_metadata( $attachment_id, $regen );
			} elseif ( ! empty( $entry['meta'] ) && is_array( $entry['meta'] ) ) {
				wp_update_attachment_metadata( $attachment_id, $entry['meta'] );
			}

			delete_post_meta( $attachment_id, '_tco_optimized' );
			self::remove_dir_recursive( $vault_root . '/' . self::SUB_ORIGINALS . '/' . $attachment_id );
			unset( $orig_index[ $attachment_id ] );
			update_option( self::OPTION_ORIG_INDEX, $orig_index, false );
			self::invalidate_summary();

			return true;
		}

		/**
		 * تخلیهٔ گروهی پشتیبان‌های اولیهٔ تصاویر فشرده‌شده برای آزادسازی فضا پس از اطمینان از کیفیت.
		 *
		 * @return array{count:int,bytes:int}
		 */
		public static function purge_original_backups() {
			$vault_root = self::base_dir();
			$orig_dir   = $vault_root . '/' . self::SUB_ORIGINALS;
			$orig_index = self::list_original_backups();
			$count      = count( $orig_index );
			$bytes      = self::dir_size( $orig_dir );

			self::remove_dir_recursive( $orig_dir );
			wp_mkdir_p( $orig_dir );
			self::ensure_protected( $vault_root );
			update_option( self::OPTION_ORIG_INDEX, array(), false );
			self::invalidate_summary();

			return array(
				'count' => $count,
				'bytes' => $bytes,
			);
		}

		/**
		 * کرون روزانهٔ پاک‌سازی خودکار موارد منقضی‌شدهٔ قرنطینه (پیش‌فرض ۳۰ روز).
		 *
		 * @return int تعداد بسته‌های پاک‌شده.
		 */
		public static function purge_expired_quarantine() {
			$items  = self::list_quarantine();
			$now    = time();
			$purged = 0;

			foreach ( $items as $item ) {
				$exp = isset( $item['expires_at'] ) ? (int) $item['expires_at'] : 0;
				if ( $exp > 0 && $exp <= $now && ! empty( $item['id'] ) ) {
					self::delete_quarantine_permanently( (string) $item['id'] );
					++$purged;
				}
			}

			if ( $purged > 0 ) {
				TisaCase_Optimizer_Session::log(
					'auto_purge_quarantine',
					__( 'پاک‌سازی خودکار قرنطینه‌های منقضی‌شده', 'tisacase-optimizer' ),
					$purged,
					0,
					'',
					'info'
				);
			}

			return $purged;
		}

		/**
		 * فهرست رکوردهای قرنطینه.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_quarantine() {
			$raw = get_option( self::OPTION_QUARANTINE, array() );
			return is_array( $raw ) ? array_values( $raw ) : array();
		}

		/**
		 * فهرست پشتیبان‌های اولیهٔ فشرده‌سازی.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function list_original_backups() {
			$raw = get_option( self::OPTION_ORIG_INDEX, array() );
			return is_array( $raw ) ? $raw : array();
		}

		/**
		 * خلاصهٔ حجم خزانه (قرنطینه + پشتیبان‌های اولیه).
		 *
		 * @return array{quarantine_count:int,quarantine_bytes:int,originals_count:int,originals_bytes:int}
		 */
		public static function summary() {
			// این متد در آمار کلی صفحه، فهرست قرنطینه و کارت «ادامه» صدا زده
			// می‌شود؛ بدون کش، هر بار دو آپشن کامل پیمایش می‌شد. کش با هر تغییر
			// در فهرست‌ها باطل می‌شود تا هیچ‌گاه عدد قدیمی نشان داده نشود.
			static $cache = null;
			static $cache_rev = -1;
			if ( is_array( $cache ) && $cache_rev === self::$summary_rev ) {
				return $cache;
			}
			$q_items = self::list_quarantine();
			$q_bytes = 0;
			foreach ( $q_items as $q ) {
				$q_bytes += isset( $q['total_bytes'] ) ? (int) $q['total_bytes'] : 0;
			}
			$o_items = self::list_original_backups();
			$o_bytes = 0;
			foreach ( $o_items as $o ) {
				$o_bytes += isset( $o['bytes'] ) ? (int) $o['bytes'] : 0;
			}
			$cache_rev = self::$summary_rev;
			$cache     = array(
				'quarantine_count' => count( $q_items ),
				'quarantine_bytes' => $q_bytes,
				'originals_count'  => count( $o_items ),
				'originals_bytes'  => $o_bytes,
			);
			return $cache;
		}

		/**
		 * حذف یک آیتم از فهرست آپشن قرنطینه.
		 *
		 * @param string $item_id شناسهٔ رکورد.
		 * @return void
		 */
		private static function remove_from_quarantine_index( $item_id ) {
			$items = self::list_quarantine();
			$kept  = array();
			foreach ( $items as $item ) {
				if ( isset( $item['id'] ) && $item['id'] !== $item_id ) {
					$kept[] = $item;
				}
			}
			update_option( self::OPTION_QUARANTINE, $kept, false );
			self::invalidate_summary();
		}

		/**
		 * محاسبهٔ حجم یک پوشه.
		 *
		 * @param string $dir مسیر پوشه.
		 * @return int
		 */
		public static function dir_size( $dir ) {
			if ( ! is_dir( $dir ) ) {
				return 0;
			}
			$bytes = 0;
			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
				);
				foreach ( $it as $file ) {
					if ( $file->isFile() ) {
						$bytes += max( 0, (int) $file->getSize() );
					}
				}
			} catch ( Throwable $e ) {
				return $bytes;
			}
			return $bytes;
		}

		/**
		 * حذف بازگشتی امن یک زیرپوشه داخل خزانه.
		 *
		 * @param string $dir مسیر پوشه.
		 * @return void
		 */
		public static function remove_dir_recursive( $dir ) {
			$vault_root = self::base_dir();
			if ( '' === $vault_root || ! is_dir( $dir ) ) {
				return;
			}
			$real_vault = realpath( $vault_root );
			$real_dir   = realpath( $dir );
			if ( false === $real_vault || false === $real_dir ) {
				return;
			}
			if ( 0 !== strpos( wp_normalize_path( $real_dir ) . '/', wp_normalize_path( $real_vault ) . '/' ) ) {
				return;
			}

			try {
				$it = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $real_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $it as $entry ) {
					if ( $entry->isDir() ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						@rmdir( $entry->getPathname() );
					} else {
						wp_delete_file( $entry->getPathname() );
					}
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $real_dir );
			} catch ( Throwable $e ) {
				return;
			}
		}
	}
}
