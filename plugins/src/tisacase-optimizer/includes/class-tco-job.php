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
		 * چند ثانیه بی‌خبری از یک کار یعنی «رها شده»؟
		 *
		 * چرا؟ در هر تیک موفق، وضعیت کار ذخیره می‌شود؛ پس اگر یک کار
		 * «در جریان» چند دقیقه هیچ به‌روزرسانی نداشته باشد، یعنی مرورگر بسته
		 * شده یا درخواست هرگز ادامه پیدا نکرده. پیش از ۱٫۲٫۱ چنین کاری
		 * برای همیشه همهٔ عملیات‌ها را با خطای «یک عملیات نیمه‌تمام در جریان
		 * است» قفل می‌کرد و کاربر هیچ راهی جز پیدا کردن کارت «ادامه» نداشت.
		 */
		const STALE_AFTER = 180;

		/** چقدر بی‌خبری یعنی رها شده؟ (قابل تغییر با فیلتر) */
		public static function stale_after() {
			$seconds = (int) apply_filters( 'tisacase_optimizer_job_stale_after', self::STALE_AFTER );
			return max( 60, min( 3600, $seconds ) );
		}

		/**
		 * چند ثانیه از آخرین پیشرفت این کار گذشته است؟
		 *
		 * @param array<string,mixed> $job کار.
		 * @return int
		 */
		public static function age_sec( array $job ) {
			$last = isset( $job['updated'] ) ? (int) $job['updated'] : ( isset( $job['started_at'] ) ? (int) $job['started_at'] : 0 );
			return max( 0, time() - $last );
		}

		/**
		 * آیا این کار «در جریان» است ولی واقعاً کسی دنبالش نیست؟
		 *
		 * @param array<string,mixed> $job کار.
		 * @return bool
		 */
		public static function is_stale( array $job ) {
			if ( 'running' !== ( isset( $job['status'] ) ? (string) $job['status'] : '' ) ) {
				return false;
			}
			return self::age_sec( $job ) >= self::stale_after();
		}

		/**
		 * بستن کار رهاشده (اگر وجود داشته باشد) و آزادکردن مسیر عملیات تازه.
		 *
		 * حسابداری کار قبلی حفظ می‌شود (همان مقدار انجام‌شده ثبت و در تاریخچه
		 * می‌آید) و سپس کار فعال پاک می‌شود تا هیچ خطای «در جریان است» برای
		 * همیشه باقی نماند.
		 *
		 * @return array<string,mixed>|null خلاصهٔ کارِ رهاشده یا null.
		 */
		public static function reclaim_stale() {
			$job = self::get();
			if ( ! is_array( $job ) || ! self::is_stale( $job ) ) {
				return null;
			}

			$age    = self::age_sec( $job );
			$before = self::summary( $job );
			$job    = self::cancel( $job );
			self::clear();

			try {
				TisaCase_Optimizer_Session::log(
					'job_reaped',
					__( 'بسته‌شدن کار رهاشدهٔ قبلی (بدون ادامه) و آزادکردن صف', 'tisacase-optimizer' ),
					(int) $before['done'],
					(int) $before['saved_bytes'],
					sprintf(
						/* translators: 1: operation label, 2: idle minutes. */
						__( 'عملیات «%1$s» %2$d دقیقه بی‌خبر بود؛ رها شد.', 'tisacase-optimizer' ),
						$before['op_label'],
						(int) ceil( $age / 60 )
					)
				);
			} catch ( Throwable $e ) {
				unset( $e );
			}

			return array(
				'op'          => (string) $before['op'],
				'op_label'    => (string) $before['op_label'],
				'done'        => (int) $before['done'],
				'total'       => (int) $before['total'],
				'age_sec'     => (int) $age,
				'idle_minutes' => (int) ceil( $age / 60 ),
			);
		}

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
		public static function start_remove( array $ids, array $paths, $mode = 'quarantine', $allow_draft = false, $reason = 'unused' ) {
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
				// علت حذف برای دسته‌بندی بسته‌های قرنطینه (در «پشتیبان و بازگردانی»
				// کاربر می‌تواند همهٔ موارد یک علت را یک‌جا برگرداند).
				'reason'        => sanitize_key( (string) $reason ),
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
		 * شروع کار «فشرده‌سازی / جایگزینی» با صف در سرور.
		 *
		 * چرا؟ پیش از ۱٫۱٫۰ این عملیات با حلقهٔ سمت مرورگر (بستهٔ ۸تایی) اجرا می‌شد:
		 * هر تصویر با ۱۴ زیرسایز ۲ تا ۵ ثانیه طول می‌کشد، پس یک بستهٔ ۸تایی می‌توانست
		 * ۱۶ تا ۴۰ ثانیه یک درخواست را بگیرد و با Fatal/504 همه‌چیز نیمه‌کاره بماند،
		 * بدون این‌که معلوم شود کدام تصویر عوض شده و کدام نه.
		 *
		 * @param int[]                $ids       شناسه‌های پیوست.
		 * @param array<string,mixed>  $overrides بازنویسی تنظیمات (مثلاً replace_mode).
		 * @return array<string,mixed>
		 */
		public static function start_optimize( array $ids, array $overrides = array() ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

			$job = self::new_job_array( 'optimize', $ids, array() );
			$job['phase']     = 'run';
			$job['overrides'] = is_array( $overrides ) ? $overrides : array();
			$job['mode']      = ! empty( $overrides['replace_mode'] ) ? 'replace' : 'sidecar';

			self::reap_orphans();
			self::save( $job );
			return $job;
		}

		/**
		 * شروع کار «بازگردانی انبوه از قرنطینه» با صف در سرور.
		 *
		 * @param string[] $item_ids شناسهٔ بسته‌های قرنطینه.
		 * @return array<string,mixed>
		 */
		public static function start_restore( array $item_ids ) {
			$ids = array();
			foreach ( $item_ids as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id ) {
					$ids[ $id ] = true;
				}
			}
			$ids = array_keys( $ids );

			$job = self::new_job_array( 'restore', array(), array() );
			$job['phase']     = 'run';
			$job['queue_qids'] = $ids;
			$job['total_ids']  = count( $ids );
			$job['mode']       = 'restore';

			self::reap_orphans();
			self::save( $job );
			return $job;
		}

		/**
		 * ساخت ساختار پایهٔ یک کار (برای همهٔ عملیات‌ها یکسان).
		 *
		 * @param string   $op    remove | optimize | restore.
		 * @param int[]    $ids   شناسه‌های پیوست.
		 * @param string[] $paths مسیرهای فایل یتیم.
		 * @return array<string,mixed>
		 */
		private static function new_job_array( $op, array $ids, array $paths ) {
			return array(
				'id'            => self::new_id(),
				'schema'        => self::SCHEMA,
				'op'            => (string) $op,
				'mode'          => (string) $op,
				'phase'         => 'verify',
				'batch_id'      => 'run' . gmdate( 'YmdHis' ) . strtolower( wp_generate_password( 4, false, false ) ),
				'queue_ids'     => array_values( $ids ),
				'queue_paths'   => array_values( $paths ),
				'queue_qids'    => array(),
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
				'allow_draft'   => false,
				'overrides'     => array(),
				'created'       => time(),
				'updated'       => time(),
				'started_at'    => time(),
				'last_error'    => '',
				'current'       => 0,
				'status'        => 'running',
			);
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

			// فاز ۱: راستی‌آزمایی ایمنی — فقط برای «حذف» معنا دارد.
			// (فشرده‌سازی و بازگردانی چیزی را نابود نمی‌کنند، پس بازرسی لازم نیست.)
			if ( 'verify' === $job['phase'] ) {
				if ( 'remove' !== $job['op'] ) {
					$job['phase'] = 'run';
				} else {
					$job = self::verify_step( $job, $started, $budget_s );
				}
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

				// پایان صف بر اساس نوع عملیات:
				//   • حذف: صف تأییدشده خالی شد.
				//   • فشرده‌سازی/بازگردانی: صف اصلی خالی شد (یا آیتمی برای پردازش نماند).
				$queue_left = 'remove' === $job['op']
					? ( ! empty( $job['safe_ids'] ) || ! empty( $job['safe_paths'] ) )
					: ( ! empty( $job['queue_ids'] ) || ! empty( $job['queue_qids'] ) );

				if ( ! $queue_left ) {
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
			// سقف ۵۰: با ۱۰۰+ شناسه در یک درخواست، بازرسی روی فروشگاه بزرگ از
			// سقف ۲۸ ثانیهٔ هاست رد می‌شد و کار با «Timeout» قطع می‌شد.
			$chunk_size = max( 5, min( 50, (int) TisaCase_Optimizer_Settings::get( 'verify_chunk', 20 ) ) );

			// سقف زمانی مخصوص خودِ بازرسی: حتی اگر بودجهٔ تیک بزرگ باشد، بازرسی
			// بیشتر از این طول نمی‌کشد و بقیه در تیک‌های بعد ادامه پیدا می‌کند.
			$verify_cap = max( 1.0, (float) apply_filters( 'tisacase_optimizer_verify_step_cap', 2.5 ) );
			$until      = min( $started + $budget_s, $started + $verify_cap );

			while ( microtime( true ) < $until ) {
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

				$verify = TisaCase_Optimizer_Usage::verify_batch(
					$chunk,
					$pchunk,
					! empty( $job['allow_draft'] ),
					true,
					$until
				);

				$job['coverage']     = isset( $verify['coverage'] ) ? (string) $verify['coverage'] : $job['coverage'];
				$job['needs_rescan'] = ! empty( $verify['needs_rescan'] );

				// مواردی که در سقف زمانی این نوبت جا نشدند، بی‌هیچ خطایی به ابتدای
				// صف برمی‌گردند تا در تیک بعد (چند ثانیه بعد) بررسی شوند.
				$deferred = array();
				if ( ! empty( $verify['deferred_ids'] ) ) {
					$deferred = array_values( array_filter( array_map( 'absint', (array) $verify['deferred_ids'] ) ) );
				}
				$deferred_path_chunks = array_values( array_filter( array_map( 'strval', (array) ( $verify['deferred_paths'] ?? array() ) ) ) );
				$deferred_lookup      = array_fill_keys( $deferred, true );

				$done_in_chunk = 0;
				foreach ( $chunk as $aid ) {
					$aid = absint( $aid );
					if ( isset( $deferred_lookup[ $aid ] ) ) {
						continue;
					}
					if ( isset( $verify['blocked'][ $aid ] ) ) {
						$job['blocked'][ $aid ] = (array) $verify['blocked'][ $aid ];
						++$done_in_chunk;
						continue;
					}
					$job['safe_ids'][] = $aid;
					++$done_in_chunk;
				}

				$deferred_path_lookup = array_fill_keys( $deferred_path_chunks, true );
				foreach ( $pchunk as $path ) {
					if ( isset( $deferred_path_lookup[ (string) $path ] ) ) {
						continue;
					}
					if ( isset( $verify['blocked_paths'][ $path ] ) ) {
						$job['blocked_paths'][ $path ] = (array) $verify['blocked_paths'][ $path ];
						++$done_in_chunk;
						continue;
					}
					$job['safe_paths'][] = (string) $path;
					++$done_in_chunk;
				}

				if ( ! empty( $deferred ) ) {
					// برگشت به ابتدای صف (بدون تکرار/گم‌شدن).
					$job['queue_ids'] = array_values( array_merge( $deferred, (array) $job['queue_ids'] ) );
				}
				if ( ! empty( $deferred_path_chunks ) ) {
					$job['queue_paths'] = array_values( array_merge( $deferred_path_chunks, (array) $job['queue_paths'] ) );
				}

				$job['verified'] += $done_in_chunk;

				// ذخیرهٔ فوری: اگر همین‌جا تایم‌اوت شود، پیشرفت از دست نمی‌رود.
				self::save( $job );

				if ( empty( $chunk ) && empty( $pchunk ) ) {
					break;
				}

				// اگر همهٔ این بسته به تیک بعد موکول شد، همین‌جا متوقف می‌شویم تا
				// درخواست طولانی نشود (پیشرفت ذخیره شده و کاربر عدد را می‌بیند).
				if ( ( ! empty( $deferred ) || ! empty( $deferred_path_chunks ) ) && 0 === $done_in_chunk ) {
					break;
				}
			}

			if ( empty( $job['queue_ids'] ) && empty( $job['queue_paths'] ) ) {
				$job['phase'] = 'run';
			}

			return $job;
		}

		/**
		 * علت حذفِ ثبت‌شده در کار (برای برچسب قرنطینه).
		 *
		 * @param array<string,mixed> $job کار.
		 * @return string
		 */
		private static function remove_reason( array $job ) {
			$allowed = array( 'unused', 'draft_only', 'missing_file', 'manual_unused' );
			$reason  = isset( $job['reason'] ) ? sanitize_key( (string) $job['reason'] ) : '';
			if ( in_array( $reason, $allowed, true ) ) {
				return $reason;
			}
			return ! empty( $job['allow_draft'] ) ? 'draft_only' : 'unused';
		}

		/**
		 * پردازش یک آیتم از صف تأییدشده.
		 *
		 * @param array<string,mixed> $job کار (با ارجاع).
		 * @return bool آیا آیتمی پردازش شد؟
		 */
		private static function run_one( array &$job ) {
			$op = isset( $job['op'] ) ? (string) $job['op'] : 'remove';

			if ( 'optimize' === $op ) {
				return self::run_one_optimize( $job );
			}
			if ( 'restore' === $op ) {
				return self::run_one_restore( $job );
			}

			$mode = (string) $job['mode'];

			if ( ! empty( $job['safe_ids'] ) ) {
				$aid = absint( array_shift( $job['safe_ids'] ) );
				$job['current'] = $aid;

				if ( $aid <= 0 ) {
					return true;
				}

				$res = 'permanent' === $mode
					? TisaCase_Optimizer_Vault::delete_attachment_permanently( $aid )
					: TisaCase_Optimizer_Vault::quarantine_attachment( $aid, self::remove_reason( $job ), (string) $job['batch_id'] );

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
		 * یک آیتم «فشرده‌سازی / جایگزینی».
		 *
		 * @param array<string,mixed> $job کار (با ارجاع).
		 * @return bool
		 */
		private static function run_one_optimize( array &$job ) {
			if ( empty( $job['queue_ids'] ) ) {
				return false;
			}

			$aid            = absint( array_shift( $job['queue_ids'] ) );
			$job['current'] = $aid;

			if ( $aid <= 0 ) {
				return true;
			}

			$overrides = isset( $job['overrides'] ) && is_array( $job['overrides'] ) ? $job['overrides'] : array();
			$res       = TisaCase_Optimizer_Image_Processor::optimize_attachment( $aid, $overrides );

			if ( is_wp_error( $res ) ) {
				$job['failed'][] = array(
					'id'     => $aid,
					'reason' => $res->get_error_message(),
				);
			} else {
				$job['done_ids'][]  = $aid;
				$job['saved_bytes'] += isset( $res['saved_bytes'] ) ? max( 0, (int) $res['saved_bytes'] ) : 0;
				$job['done']        += 1;
				$job['optimized'][] = array(
					'id'    => $aid,
					'mode'  => isset( $res['mode'] ) ? (string) $res['mode'] : '',
					'mime'  => isset( $res['nextgen'] ) ? (string) $res['nextgen'] : '',
					'saved' => isset( $res['saved_bytes'] ) ? (int) $res['saved_bytes'] : 0,
				);
			}

			$job['current'] = 0;
			return true;
		}

		/**
		 * یک آیتم «بازگردانی از قرنطینه».
		 *
		 * @param array<string,mixed> $job کار (با ارجاع).
		 * @return bool
		 */
		private static function run_one_restore( array &$job ) {
			if ( empty( $job['queue_qids'] ) ) {
				return false;
			}

			$qid            = (string) array_shift( $job['queue_qids'] );
			$job['current'] = 0;

			$res = TisaCase_Optimizer_Vault::restore_quarantine_item( $qid );

			if ( is_wp_error( $res ) ) {
				$job['failed'][] = array(
					'id'     => 0,
					'path'   => $qid,
					'reason' => $res->get_error_message(),
				);
				return true;
			}

			$aid = 0;
			if ( is_int( $res ) ) {
				$aid = $res;
			} elseif ( is_array( $res ) && ! empty( $res['attachment_id'] ) ) {
				$aid = absint( $res['attachment_id'] );
			}
			if ( $aid > 0 ) {
				$job['done_ids'][] = $aid;
			}

			++$job['done'];
			return true;
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

			$op = isset( $job['op'] ) ? (string) $job['op'] : 'remove';

			// به‌روزرسانی کاتالوگ — یک‌بار در پایان کار (نه در هر بسته).
			if ( 'remove' === $op ) {
				if ( ! empty( $job['done_ids'] ) || ! empty( $job['done_paths'] ) ) {
					TisaCase_Optimizer_Media_Inspector::remove_from_cached_catalog( $job['done_ids'], $job['done_paths'], true );
					TisaCase_Optimizer_Media_Inspector::flush_pending_removals();
				}
			} elseif ( ! empty( $job['done_ids'] ) && TisaCase_Optimizer_Media_Inspector::has_ready_catalog() ) {
				// پس از فشرده‌سازی یا بازگردانی، ردیف‌های همان تصاویر تازه‌سازی می‌شوند.
				TisaCase_Optimizer_Media_Inspector::refresh_items_in_cached_catalog( array_map( 'absint', (array) $job['done_ids'] ) );
			}

			// پاک‌سازی کش/CDN فقط زمانی که فایل‌ها واقعاً تغییر کرده‌اند (فشرده‌سازی و
			// بازگردانی) تا نسخهٔ قدیمی در کش به کاربر نشان داده نشود.
			if ( 'remove' !== $op && (int) $job['done'] > 0 ) {
				try {
					$purged = TisaCase_Optimizer_Cache_Purger::purge( array_map( 'absint', (array) $job['done_ids'] ) );
					if ( ! empty( $purged ) ) {
						$job['cache_purged'] = $purged;
					}
				} catch ( Throwable $e ) {
					unset( $e );
				}
			}

			// فهرست سبک نسخه‌های موازی فقط برای همان تصاویر اصلاح می‌شود (نه بازسازی کل).
			if ( ! empty( $job['done_ids'] ) ) {
				try {
					TisaCase_Optimizer_Sidecar_Index::refresh_for_ids( array_map( 'absint', (array) $job['done_ids'] ) );
				} catch ( Throwable $e ) {
					unset( $e );
				}
			}

			$count_done = (int) $job['done'];
			$blocked_n  = count( (array) $job['blocked'] ) + count( (array) $job['blocked_paths'] );
			$failed_n   = count( (array) $job['failed'] );

			if ( $count_done > 0 ) {
				$mode = (string) $job['mode'];
				if ( 'optimize' === $op ) {
					$message = 'replace' === $mode
						? __( 'فشرده‌سازی و جایگزینی فایل اصل با نسخهٔ WebP (با پشتیبان در خزانه)', 'tisacase-optimizer' )
						: __( 'فشرده‌سازی تصاویر و ساخت نسخهٔ موازی WebP', 'tisacase-optimizer' );
				} elseif ( 'restore' === $op ) {
					$message = __( 'بازگردانی تصاویر از قرنطینه به مسیر و ID اصلی', 'tisacase-optimizer' );
				} else {
					$message = 'permanent' === $mode
						? __( 'حذف قطعی تصاویر انتخاب‌شده از هاست و دیتابیس', 'tisacase-optimizer' )
						: __( 'انتقال ایمن تصاویر به قرنطینهٔ ۳۰ روزه (قابل بازگردانی)', 'tisacase-optimizer' );
				}

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

				$log_type = 'remove' === $op
					? ( 'permanent' === $mode ? 'delete_permanent' : 'move_quarantine' )
					: ( 'optimize' === $op ? ( 'replace' === $mode ? 'optimize_replace' : 'optimize' ) : 'restore_quarantine' );

				TisaCase_Optimizer_Session::log(
					$log_type,
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
			$job['queue_qids']  = array();
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
			$op    = isset( $job['op'] ) ? (string) $job['op'] : 'remove';
			$total = (int) $job['total_ids'] + (int) $job['total_paths'];

			if ( 'remove' === $op ) {
				$remaining = count( (array) $job['queue_ids'] ) + count( (array) $job['queue_paths'] )
					+ count( (array) $job['safe_ids'] ) + count( (array) $job['safe_paths'] );
			} elseif ( 'restore' === $op ) {
				$remaining = count( (array) $job['queue_qids'] );
			} else {
				$remaining = count( (array) $job['queue_ids'] );
			}

			$failed = array();
			foreach ( array_slice( (array) $job['failed'], 0, 20 ) as $row ) {
				$failed[] = $row;
			}

			$elapsed = max( 0, time() - (int) $job['started_at'] );
			$idle    = self::age_sec( $job );
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
			$failed_n  = count( (array) $job['failed'] );

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
			$op_label = 'optimize' === $op
				? __( 'فشرده‌سازی', 'tisacase-optimizer' )
				: ( 'restore' === $op ? __( 'بازگردانی', 'tisacase-optimizer' ) : __( 'حذف', 'tisacase-optimizer' ) );

			if ( 'verify' === (string) $job['phase'] ) {
				$hint = sprintf(
					/* translators: 1: verified, 2: total. */
					__( 'در حال بازرسی ایمنی پیش از حذف: %1$d از %2$d مورد بررسی شد.', 'tisacase-optimizer' ),
					(int) $job['verified'],
					$total
				);
			} elseif ( 'done' === (string) $job['status'] && 0 === (int) $job['done'] && $failed_n > 0 && 'remove' !== $op ) {
				/* translators: %s: operation label. */
				$hint = sprintf( __( 'هیچ موردی با موفقیت %s نشد؛ متن خطای اولین مورد را در گزارش ببینید.', 'tisacase-optimizer' ), $op_label );
			} elseif ( 'done' === (string) $job['status'] && 0 === (int) $job['done'] && $blocked_n > 0 ) {
				$hint = ! empty( $job['needs_rescan'] ) || 'full' !== (string) $job['coverage']
					? __( 'برای حذف ایمن، ابتدا «اسکن کامل تصاویر» را اجرا کنید؛ بازرسی ایمنی بدون کاتالوگ کامل اجازهٔ حذف نمی‌دهد.', 'tisacase-optimizer' )
					: __( 'این موردها هنوز در سایت استفاده می‌شوند (مثلاً تصویر شاخص، گالری یا متن محصول)؛ عمداً حذف نشدند.', 'tisacase-optimizer' );
			}

			return array(
				'id'            => (string) $job['id'],
				'op'            => $op,
				'op_label'      => $op_label,
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
				'idle_sec'      => $idle,
				'stale'         => self::is_stale( $job ),
				'stale_after'   => self::stale_after(),
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
