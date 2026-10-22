<?php
/**
 * موتور اجرای کارهای سنگین و «قابل ادامه» (Resumable Job Engine).
 *
 * چرا این فایل ساخته شد؟
 * در نسخهٔ ۱.۰٫۵ حذف ۱۵۰۰ تصویر با یک حلقهٔ ساده در مرورگر اجرا می‌شد:
 *   • سرور در هر درخواست فقط تا سقف زمانی مشخص (۳٫۵ ثانیه) کار می‌کرد و
 *     بقیهٔ شناسه‌های همان بستهٔ ۲۵تایی را «بی‌صدا» رها می‌کرد؛ مرورگر هم
 *     آن‌ها را از صف خودش حذف کرده بود ⇒ بخشی از تصاویر هرگز حذف نمی‌شد و
 *     کاربر نمی‌فهمید چقدر انجام شده و چرا وسط کار قطع شد.
 *   • هر بسته، کاتالوگ کامل JSON را می‌خواند و دوباره می‌نوشت و آمار کلی را
 *     از نو حساب می‌کرد ⇒ بار سنگین دیسک/دیتابیس روی هر ۲۵ تصویر.
 *
 * این موتور وضعیت کار را در دیتابیس (آپشن غیر-autoload) نگه می‌دارد و:
 *   ۱. **هیچ شناسه‌ای گم نمی‌شود** — صف در سرور است، نه در مرورگر.
 *   ۲. هر تیک فقط به اندازهٔ بودجهٔ زمانی تعیین‌شده کار می‌کند (پیش‌فرض ۴ ثانیه)،
 *      سپس برمی‌گردد تا PHP-FPM نفس بکشد (بار کم روی هاست‌های اشتراکی).
 *   ۳. بین تصاویر یک «فاصلهٔ تنفس» (throttle) می‌گذارد تا IOPS هاست اشباع نشود.
 *   ۴. بعد از هر تصویر وضعیت را ذخیره می‌کند ⇒ حتی اگر PHP با خطای مرگبار یا
 *      تایم‌اوت قطع شود، دقیقاً از همان‌جا ادامه می‌دهد.
 *   ۵. تعداد انجام‌شده، باقی‌مانده، حجم آزادشده، موارد مسدودشده و آخرین خطا را
 *      همیشه دقیق گزارش می‌دهد.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'TisaCase_Optimizer_Job' ) ) {

	/**
	 * مدیریت کار جاری (فقط یک کار فعال در هر لحظه).
	 */
	final class TisaCase_Optimizer_Job {

		/** نام آپشن ذخیرهٔ کار فعال (غیر-autoload تا روی هر ریکوئست بارگذاری نشود). */
		const OPTION = 'tisacase_optimizer_active_job';

		/** نسخهٔ ساختار داده (برای مهاجرت بی‌خطر). */
		const SCHEMA = 1;

		/** عملیات‌های پشتیبانی‌شده. */
		const OPS = array( 'remove', 'restore', 'optimize' );

		/**
		 * خواندن کار فعال.
		 *
		 * @return array<string,mixed>|null
		 */
		public static function get() {
			$job = get_option( self::OPTION, null );
			if ( ! is_array( $job ) || empty( $job['id'] ) || ! isset( $job['phase'] ) ) {
				return null;
			}
			return $job;
		}

		/**
		 * ذخیرهٔ کار (بدون autoload).
		 *
		 * @param array<string,mixed> $job کار.
		 * @return void
		 */
		public static function save( array $job ) {
			$job['updated'] = time();
			update_option( self::OPTION, $job, false );
		}

		/**
		 * پاک‌کردن کار فعال (پس از پایان یا رهاکردن).
		 *
		 * @return void
		 */
		public static function clear() {
			delete_option( self::OPTION );
		}

		/**
		 * ساخت شناسهٔ یکتا برای کار.
		 *
		 * @return string
		 */
		private static function new_id() {
			return 'job' . gmdate( 'YmdHis' ) . strtolower( wp_generate_password( 5, false, false ) );
		}

		/**
		 * شروع یک کار حذف/قرنطینه.
		 *
		 * @param int[]    $ids       شناسه‌های پیوست.
		 * @param string[] $paths     مسیر فایل‌های یتیم.
		 * @param string   $mode      quarantine | permanent.
		 * @param bool     $allow_draft حذف تصاویر «فقط پیش‌نویس».
		 * @return array<string,mixed> کار تازه‌ساخته‌شده.
		 */
		public static function start_remove( array $ids, array $paths, $mode = 'quarantine', $allow_draft = false ) {
			$mode = in_array( $mode, array( 'quarantine', 'permanent' ), true ) ? $mode : 'quarantine';

			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			$paths = array();
			foreach ( $paths as $raw_path ) {
				$clean = ltrim( wp_normalize_path( (string) $raw_path ), '/' );
				if ( '' !== $clean ) {
					$paths[ $clean ] = true;
				}
			}
			$paths = array_keys( $paths );

			$job = array(
				'id'            => self::new_id(),
				'schema'        => self::SCHEMA,
				'op'            => 'remove',
				'mode'          => $mode,
				'phase'         => 'verify',
				'batch_id'      => 'run' . gmdate( 'YmdHis' ) . strtolower( wp_generate_password( 4, false, false ) ),
				// صف تأییدنشده (باید راستی‌آزمایی شود).
				'queue_ids'     => $ids,
				'queue_paths'   => $paths,
				// صف تأییدشده (آمادهٔ اجرا).
				'safe_ids'      => array(),
				'safe_paths'    => array(),
				'total_ids'     => count( $ids ),
				'total_paths'   => count( $paths ),
				'verified'      => 0,
				'done'          => 0,
				'done_ids'      => array(),
				'done_paths'    => array(),
				'saved_bytes'   => 0,
				'blocked'       => array(),
				'blocked_paths' => array(),
				'failed'        => array(),
				'coverage'      => '',
				'needs_rescan'  => false,
				'allow_draft'   => (bool) $allow_draft,
				'created'       => time(),
				'updated'       => time(),
				'started_at'    => time(),
				'last_error'    => '',
				'current'       => 0,
				'status'        => 'running',
			);

			// اگر کار نیمه‌کاره‌ای از قبل مانده، اول تعیین تکلیف شود.
			self::reap_orphans();

			self::save( $job );
			return $job;
		}

		/**
		 * اگر کار قبلی رهاشده مانده، تغییرات کاتالوگ آن را نهایی کن تا داده‌ها گم نشوند.
		 *
		 * @return void
		 */
		private static function reap_orphans() {
			$previous = self::get();
			if ( is_array( $previous ) ) {
				TisaCase_Optimizer_Media_Inspector::flush_pending_removals();
			}
		}

		/**
		 * اجرای گام بعدی کار در محدودهٔ بودجهٔ زمانی.
		 *
		 * @param array<string,mixed> $job     کار جاری.
		 * @param int                 $budget_ms سقف زمان این تیک (میلی‌ثانیه).
		 * @param int                 $max_items حداکثر تعداد آیتم در این تیک.
		 * @return array<string,mixed> کار به‌روزشده.
		 */
		public static function advance( array $job, $budget_ms = 0, $max_items = 0 ) {
			$started = microtime( true );

			// کارهای ساخته‌شده با نسخه‌های قبلی ممکن است کلیدهای تازه را نداشته
			// باشند؛ پیش‌فرض‌ها این‌جا پر می‌شوند تا ارتقاء، کار نیمه‌تمام را نکشد.
			$job += array(
				'queue_ids'     => array(),
				'queue_paths'   => array(),
				'safe_ids'      => array(),
				'safe_paths'    => array(),
				'done_ids'      => array(),
				'done_paths'    => array(),
				'blocked'       => array(),
				'blocked_paths' => array(),
				'failed'        => array(),
				'done'          => 0,
				'verified'      => 0,
				'saved_bytes'   => 0,
				'last_error'    => '',
				'coverage'      => '',
				'needs_rescan'  => false,
				'status'        => 'running',
				'phase'         => 'verify',
				'started_at'    => time(),
			);

			if ( $budget_ms <= 0 ) {
				$budget_ms = max( 1500, min( 15000, (int) TisaCase_Optimizer_Settings::get( 'delete_tick_ms', 4000 ) ) );
			}
			if ( $max_items <= 0 ) {
				$max_items = max( 5, min( 200, (int) TisaCase_Optimizer_Settings::get( 'delete_tick_max', 40 ) ) );
			}
			$throttle_ms = max( 0, min( 250, (int) TisaCase_Optimizer_Settings::get( 'delete_throttle_ms', 15 ) ) );

			$budget_s = $budget_ms / 1000;
			$processed = 0;

			// فاز ۱: راستی‌آزمایی (فقط یک‌بار برای هر شناسه، مقاوم به قطع).
			if ( 'verify' === $job['phase'] ) {
				$job = self::verify_step( $job, $started, $budget_s );
			}

			// فاز ۲: اجرا.
			if ( 'run' === $job['phase'] ) {
				while (
					$processed < $max_items &&
					( microtime( true ) - $started ) < $budget_s
				) {
					$did = self::run_one( $job );
					if ( ! $did ) {
						// صف خالی شد.
						break;
					}
					++$processed;
					// ذخیرهٔ وضعیت بعد از هر آیتم ⇒ مقاوم در برابر قطع ناگهانی PHP.
					self::save( $job );
					if ( $throttle_ms > 0 && ( microtime( true ) - $started ) < $budget_s ) {
						usleep( $throttle_ms * 1000 );
					}
				}

				if ( empty( $job['safe_ids'] ) && empty( $job['safe_paths'] ) ) {
					$job['phase'] = 'finish';
				}
			}

			// فاز ۳: نهایی‌سازی (کاتالوگ، تاریخچه، آمار) — یک‌بار و کوتاه.
			if ( 'finish' === $job['phase'] ) {
				$job = self::finish( $job );
			}

			self::save( $job );
			return $job;
		}

		/**
		 * یک گام راستی‌آزمایی دسته‌ای.
		 *
		 * @param array<string,mixed> $job       کار.
		 * @param float               $started   زمان شروع تیک.
		 * @param float               $budget_s  بودجه (ثانیه).
		 * @return array<string,mixed>
		 */
		private static function verify_step( array $job, $started, $budget_s ) {
			// اندازهٔ هر گام بازرسی: کوچک بماند تا هیچ کوئری‌ای غول‌آسا نشود و
			// پیشرفت بعد از هر گام در سرور ذخیره شود (کاربر عدد را زنده می‌بیند).
			$chunk_size = max( 10, min( 200, (int) TisaCase_Optimizer_Settings::get( 'verify_chunk', 100 ) ) );

			while ( ( microtime( true ) - $started ) < $budget_s ) {
				$ids_left   = array_values( (array) $job['queue_ids'] );
				$paths_left = array_values( (array) $job['queue_paths'] );

				if ( empty( $ids_left ) && empty( $paths_left ) ) {
					$job['phase'] = 'run';
					self::save( $job );
					return $job;
				}

				// فقط یک بستهٔ کوچک از صف را برمی‌داریم؛ باقی برای تیک‌های بعد.
				$chunk   = array_splice( $job['queue_ids'], 0, $chunk_size );
				$pchunk  = empty( $chunk ) ? array_splice( $job['queue_paths'], 0, $chunk_size ) : array();

				$verify = TisaCase_Optimizer_Usage::verify_batch( $chunk, $pchunk, ! empty( $job['allow_draft'] ), true );

				$job['coverage']     = isset( $verify['coverage'] ) ? (string) $verify['coverage'] : $job['coverage'];
				$job['needs_rescan'] = ! empty( $verify['needs_rescan'] );

				foreach ( $chunk as $aid ) {
					$aid = absint( $aid );
					if ( isset( $verify['blocked'][ $aid ] ) ) {
						$job['blocked'][ $aid ] = (array) $verify['blocked'][ $aid ];
						continue;
					}
					$job['safe_ids'][] = $aid;
				}

				foreach ( $pchunk as $path ) {
					if ( isset( $verify['blocked_paths'][ $path ] ) ) {
						$job['blocked_paths'][ $path ] = (array) $verify['blocked_paths'][ $path ];
						continue;
					}
					$job['safe_paths'][] = (string) $path;
				}

				$job['verified'] += count( $chunk ) + count( $pchunk );

				// ذخیرهٔ فوری: اگر همین‌جا تایم‌اوت شود، پیشرفت از دست نمی‌رود.
				self::save( $job );

				if ( empty( $chunk ) && empty( $pchunk ) ) {
					break;
				}
			}

			if ( empty( $job['queue_ids'] ) && empty( $job['queue_paths'] ) ) {
				$job['phase'] = 'run';
			}

			return $job;
		}

		/**
		 * پردازش یک آیتم از صف تأییدشده.
		 *
		 * @param array<string,mixed> $job کار (با ارجاع).
		 * @return bool آیا آیتمی پردازش شد؟
		 */
		private static function run_one( array &$job ) {
			$mode = (string) $job['mode'];

			if ( ! empty( $job['safe_ids'] ) ) {
				$aid = absint( array_shift( $job['safe_ids'] ) );
				$job['current'] = $aid;

				if ( $aid <= 0 ) {
					return true;
				}

				$res = 'permanent' === $mode
					? TisaCase_Optimizer_Vault::delete_attachment_permanently( $aid )
					: TisaCase_Optimizer_Vault::quarantine_attachment( $aid, 'unused', (string) $job['batch_id'] );

				if ( is_wp_error( $res ) ) {
					$job['failed'][] = array(
						'id'     => $aid,
						'reason' => $res->get_error_message(),
					);
				} else {
					$job['done_ids'][]  = $aid;
					$job['saved_bytes'] += isset( $res['saved_bytes'] ) ? (int) $res['saved_bytes'] : (int) $res['total_bytes'];
					$job['done']        += 1;
				}

				$job['current'] = 0;
				return true;
			}

			if ( ! empty( $job['safe_paths'] ) ) {
				$path = (string) array_shift( $job['safe_paths'] );
				$job['current'] = 0;

				if ( 'permanent' === $mode ) {
					$uploads = wp_upload_dir();
					$safe    = TisaCase_Optimizer_Vault::assert_inside_uploads( trailingslashit( $uploads['basedir'] ) . ltrim( $path, '/' ) );
					if ( false !== $safe && is_file( $safe ) ) {
						$bytes = max( 0, (int) @filesize( $safe ) );
						wp_delete_file( $safe );
						$job['saved_bytes'] += $bytes;
						$job['done']        += 1;
						$job['done_paths'][] = $path;
						TisaCase_Optimizer_Settings::bump_stats(
							array(
								'deleted_count' => 1,
								'saved_bytes'   => $bytes,
							)
						);
					}
					return true;
				}

				$res = TisaCase_Optimizer_Vault::quarantine_orphan_file( $path, (string) $job['batch_id'] );
				if ( is_wp_error( $res ) ) {
					$job['failed'][] = array(
						'id'     => 0,
						'path'   => $path,
						'reason' => $res->get_error_message(),
					);
				} else {
					$job['saved_bytes'] += (int) $res['total_bytes'];
					$job['done']        += 1;
					$job['done_paths'][] = $path;
				}

				return true;
			}

			return false;
		}

		/**
		 * نهایی‌سازی: نوشتن یک‌بارهٔ کاتالوگ، ثبت تاریخچه و آماده‌سازی گزارش.
		 *
		 * @param array<string,mixed> $job کار.
		 * @return array<string,mixed>
		 */
		private static function finish( array $job ) {
			if ( 'done' === $job['status'] ) {
				return $job;
			}

			// یک‌بار نوشتن کاتالوگ به‌جای یک‌بار در هر ۲۵ تصویر (صرفه‌جویی بزرگ I/O).
			if ( ! empty( $job['done_ids'] ) || ! empty( $job['done_paths'] ) ) {
				TisaCase_Optimizer_Media_Inspector::remove_from_cached_catalog( $job['done_ids'], $job['done_paths'], true );
				TisaCase_Optimizer_Media_Inspector::flush_pending_removals();
			}

			$count_done = (int) $job['done'];
			$blocked_n  = count( (array) $job['blocked'] ) + count( (array) $job['blocked_paths'] );
			$failed_n   = count( (array) $job['failed'] );

			if ( $count_done > 0 ) {
				$mode    = (string) $job['mode'];
				$message = 'permanent' === $mode
					? __( 'حذف قطعی تصاویر انتخاب‌شده از هاست و دیتابیس', 'tisacase-optimizer' )
					: __( 'انتقال ایمن تصاویر به قرنطینهٔ ۳۰ روزه (قابل بازگردانی)', 'tisacase-optimizer' );

				$notes = array();
				if ( $blocked_n > 0 ) {
					/* translators: %d: skipped items. */
					$notes[] = sprintf( __( '%d مورد به‌دلیل استفادهٔ واقعی حذف نشد', 'tisacase-optimizer' ), $blocked_n );
				}
				if ( $failed_n > 0 ) {
					/* translators: %d: failed items. */
					$notes[] = sprintf( __( '%d مورد با خطای فایل مواجه شد', 'tisacase-optimizer' ), $failed_n );
				}
				if ( ! empty( $notes ) ) {
					$message .= ' — ' . implode( '، ', $notes );
				}

				TisaCase_Optimizer_Session::log(
					'permanent' === $mode ? 'delete_permanent' : 'move_quarantine',
					$message,
					$count_done,
					(int) $job['saved_bytes'],
					'',
					'success',
					(string) $job['batch_id']
				);
			}

			// اگر هیچ موردی حذف نشد، دلیل واقعی باید روی کار ثبت شود؛ وگرنه
			// کاربر «۰ از ۱۴۸۰» می‌بیند بدون آن‌که بداند چرا (باگ گزارش‌شده).
			if ( 0 === (int) $job['done'] && empty( $job['last_error'] ) ) {
				if ( $blocked_n > 0 ) {
					$top = '';
					foreach ( (array) $job['blocked'] as $reasons ) {
						if ( ! empty( $reasons ) ) {
							$top = (string) $reasons[0];
							break;
						}
					}
					$job['last_error'] = sprintf(
						/* translators: 1: blocked count, 2: first reason. */
						__( 'هیچ تصویری حذف نشد: هر %1$d مورد در بازرسی ایمنی رد شد (نمونه دلیل: %2$s)', 'tisacase-optimizer' ),
						$blocked_n,
						'' !== $top ? $top : __( 'هنوز در سایت استفاده می‌شود', 'tisacase-optimizer' )
					);
				} elseif ( $failed_n > 0 ) {
					$first = (array) reset( $job['failed'] );
					$job['last_error'] = sprintf(
						/* translators: 1: failed count, 2: first error. */
						__( 'هیچ تصویری حذف نشد: هر %1$d مورد با خطای فایل مواجه شد (نمونه: %2$s)', 'tisacase-optimizer' ),
						$failed_n,
						isset( $first['reason'] ) ? (string) $first['reason'] : __( 'خطای نامشخص فایل', 'tisacase-optimizer' )
					);
				}
			}

			$job['status']       = 'done';
			$job['phase']        = 'finish';
			$job['finished_at']  = time();
			$job['elapsed_sec']  = max( 1, time() - (int) $job['started_at'] );

			return $job;
		}

		/**
		 * لغو کار: صف پاک می‌شود ولی آنچه انجام شده ثبت و کاتالوگ نهایی می‌شود.
		 *
		 * @param array<string,mixed> $job کار.
		 * @return array<string,mixed>
		 */
		public static function cancel( array $job ) {
			$job['queue_ids']   = array();
			$job['queue_paths'] = array();
			$job['safe_ids']    = array();
			$job['safe_paths']  = array();
			$job['status']      = 'cancelled';
			$job['phase']       = 'finish';
			$job['cancelled_at'] = time();
			$job = self::finish( $job );
			$job['status'] = 'cancelled';
			self::save( $job );
			return $job;
		}

		/**
		 * خلاصهٔ کار برای رابط کاربری (سبک و بدون آرایه‌های سنگین).
		 *
		 * @param array<string,mixed> $job کار.
		 * @return array<string,mixed>
		 */
		public static function summary( array $job ) {
			$total     = (int) $job['total_ids'] + (int) $job['total_paths'];
			$remaining = count( (array) $job['queue_ids'] ) + count( (array) $job['queue_paths'] )
				+ count( (array) $job['safe_ids'] ) + count( (array) $job['safe_paths'] );

			$failed = array();
			foreach ( array_slice( (array) $job['failed'], 0, 20 ) as $row ) {
				$failed[] = $row;
			}

			$elapsed = max( 0, time() - (int) $job['started_at'] );
			$rate    = $elapsed > 0 ? ( (int) $job['done'] / $elapsed ) : 0;
			$eta     = ( $rate > 0 && $remaining > 0 ) ? (int) ceil( $remaining / $rate ) : 0;

			$blocked_sample = array();
			$i = 0;
			foreach ( (array) $job['blocked'] as $bid => $reasons ) {
				if ( $i >= 30 ) {
					break;
				}
				$blocked_sample[ (string) $bid ] = array_slice( (array) $reasons, 0, 2 );
				++$i;
			}

			$blocked_n = count( (array) $job['blocked'] ) + count( (array) $job['blocked_paths'] );

			// تجمیع دلایل مسدودشدن (برای نمایش مستقیم در کارت «ادامه»).
			$reason_counts = array();
			foreach ( (array) $job['blocked'] as $reasons ) {
				$first = is_array( $reasons ) && ! empty( $reasons ) ? (string) reset( $reasons ) : '';
				if ( '' === $first ) {
					$first = __( 'دلیل نامشخص', 'tisacase-optimizer' );
				}
				if ( ! isset( $reason_counts[ $first ] ) ) {
					$reason_counts[ $first ] = 0;
				}
				++$reason_counts[ $first ];
			}
			arsort( $reason_counts );
			$block_reasons = array();
			$rc_i          = 0;
			foreach ( $reason_counts as $reason => $count ) {
				if ( $rc_i >= 4 ) {
					break;
				}
				$block_reasons[] = array(
					'reason' => (string) $reason,
					'count'  => (int) $count,
				);
				++$rc_i;
			}

			// راهنمای عملی: دقیقاً بگوییم چرا کاری انجام نشد و حالا چه باید کرد.
			$hint = '';
			if ( 'verify' === (string) $job['phase'] ) {
				$hint = sprintf(
					/* translators: 1: verified, 2: total. */
					__( 'در حال بازرسی ایمنی پیش از حذف: %1$d از %2$d مورد بررسی شد.', 'tisacase-optimizer' ),
					(int) $job['verified'],
					$total
				);
			} elseif ( 'done' === (string) $job['status'] && 0 === (int) $job['done'] && $blocked_n > 0 ) {
				$hint = ! empty( $job['needs_rescan'] ) || 'full' !== (string) $job['coverage']
					? __( 'برای حذف ایمن، ابتدا «اسکن کامل تصاویر» را اجرا کنید؛ بازرسی ایمنی بدون کاتالوگ کامل اجازهٔ حذف نمی‌دهد.', 'tisacase-optimizer' )
					: __( 'این موردها هنوز در سایت استفاده می‌شوند (مثلاً تصویر شاخص، گالری یا متن محصول)؛ عمداً حذف نشدند.', 'tisacase-optimizer' );
			}

			return array(
				'id'            => (string) $job['id'],
				'op'            => (string) $job['op'],
				'mode'          => (string) $job['mode'],
				'phase'         => (string) $job['phase'],
				'status'        => (string) $job['status'],
				'batch_id'      => (string) $job['batch_id'],
				'total'         => $total,
				'done'          => (int) $job['done'],
				'remaining'     => max( 0, $remaining ),
				'verified'      => (int) $job['verified'],
				'blocked_count' => count( (array) $job['blocked'] ) + count( (array) $job['blocked_paths'] ),
				'blocked'       => $blocked_sample,
				'blocked_paths' => array_slice( (array) $job['blocked_paths'], 0, 20, true ),
				'failed_count'  => count( (array) $job['failed'] ),
				'failed'        => $failed,
				'saved_bytes'   => (int) $job['saved_bytes'],
				'elapsed_sec'   => $elapsed,
				'eta_sec'       => $eta,
				'coverage'      => (string) $job['coverage'],
				'needs_rescan'  => ! empty( $job['needs_rescan'] ),
				'last_error'    => (string) $job['last_error'],
				'hint'          => $hint,
				'block_reasons' => $block_reasons,
				'finished'      => in_array( (string) $job['status'], array( 'done', 'cancelled' ), true ),
			);
		}

		/**
		 * ثبت آخرین خطا روی کار (برای نمایش در کارت ادامه).
		 *
		 * @param array<string,mixed> $job     کار.
		 * @param string              $message پیام.
		 * @return array<string,mixed>
		 */
		public static function mark_error( array $job, $message ) {
			$job['last_error'] = mb_substr( (string) $message, 0, 300 );
			self::save( $job );
			return $job;
		}
	}
}
