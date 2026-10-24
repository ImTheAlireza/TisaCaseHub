<?php
/**
 * «اجرای عملیات روی خود هاست» — موتور کار بدون نیاز به باز ماندن مرورگر.
 *
 * چرا؟ کاربر گفت: «دکمهٔ شروع فشرده‌سازی را می‌زنم، عملیات وابسته به خود هاست
 * باشه نه مرورگر من؛ این‌طوری با قطعی نت یا خاموش شدن سیستم من قطع نمی‌شه.»
 *
 * معماری (دو لایه، هر دو سمت سرور):
 *   ۱) کرون وردپرس: یک رویداد دوره‌ای (۳۰ یا ۶۰ ثانیه) که هر بار یک «تیک»
 *      کوچک از موتور کار را جلو می‌برد. تا وقتی کار تمام نشده ادامه می‌دهد،
 *      حتی اگر هیچ مرورگری باز نباشد.
 *   ۲) تیک دستی از پنل: اگر کاربر صفحه را باز نگه دارد، همان درخواست‌های
 *      سبک پنل هم می‌توانند کار را جلو ببرند (سریع‌تر) — ولی هیچ‌کدام شرط
 *      بقیهٔ کار نیستند.
 *
 * قواعد ایمنی هاست:
 *   • هر تیک سقف زمانی (پیش‌فرض ۱۲ ثانیه، کران ۱۶) و سقف تعداد (پیش‌فرض ۶۰ مورد،
 *     کران ۲۵۰) دارد؛ به‌همراه set_time_limit(24) هیچ درخواستی به سقف ۲۸ ثانیه
 *     هاست نزدیک نمی‌شود.
 *   • حین اجرای کار، «زنجیرهٔ توربو» تیک بعدی را ~۳ ثانیه بعد ثبت می‌کند تا کار
 *     منتظر بازهٔ ۳۰/۶۰ ثانیه کرون نماند و تقریباً پیوسته پیش برود.
 *   • پیشرفت بعد از **هر آیتم** در سرور ذخیره می‌شود؛ اگر PHP وسط کار بمیرد،
 *     تیک بعدی از همان نقطه ادامه می‌دهد (هیچ موردی دوباره انجام نمی‌شود).
 *   • قفل سبک (option) از اجرای هم‌زمان دو تیک جلوگیری می‌کند؛ قفل رهاشده
 *     پس از ۱۲۰ ثانیه خودکار آزاد می‌شود تا کار هیچ‌وقت گیر نکند.
 *   • خروجی/خطای هر تیک کوتاه ثبت می‌شود تا در پنل دلیل توقف دیده شود.
 *
 * @package TisaCase_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * کلاس اجرای سمت هاست.
 */
class TisaCase_Optimizer_Server_Runner {

	/** قلاب کرون وردپرس. */
	const HOOK = 'tisacase_optimizer_server_runner';

	/** نام بازه‌های زمانی سفارشی. */
	const SCHEDULE_30 = 'tco_runner_30s';
	const SCHEDULE_60 = 'tco_runner_60s';

	/** قفل ضد اجرای هم‌زمان (option اتمیک). */
	const LOCK_OPTION = 'tisacase_optimizer_runner_lock';

	/** آخرین تیک (timestamp) برای نمایش «زنده است / خوابیده است». */
	const LAST_TICK_OPTION = 'tisacase_optimizer_runner_last_tick';

	/** نتیجهٔ آخرین کاری که روی هاست تمام شد (برای وقتی صفحه بسته بوده). */
	const LAST_RESULT_OPTION = 'tisacase_optimizer_runner_last_result';

	/** تعداد تیک‌های اجراشده (تشخیص «کرون کار نمی‌کند»). */
	const TICKS_OPTION = 'tisacase_optimizer_runner_ticks';

	/** عمر قفل به ثانیه؛ بعد از آن قفل رهاشده فرض می‌شود. */
	const LOCK_TTL = 120;

	/**
	 * ثبت قلاب‌ها. (در پیشخوان، کرون و اجرای CLI یکسان است.)
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );

		// اگر افزونه بعد از فعال‌سازی به‌روزرسانی شد، رویداد کرون هم‌گام می‌شود.
		add_action( 'init', array( __CLASS__, 'sync_schedule' ), 20 );
	}

	/**
	 * افزودن بازه‌های زمانی سفارشی کرون.
	 *
	 * @param array<string,array<string,mixed>> $schedules بازه‌ها.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedules( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::SCHEDULE_30 ] = array(
			'interval' => 30,
			'display'  => __( 'هر ۳۰ ثانیه (اجرای عملیات بهینه‌سازی روی هاست)', 'tisacase-optimizer' ),
		);
		$schedules[ self::SCHEDULE_60 ] = array(
			'interval' => 60,
			'display'  => __( 'هر دقیقه (اجرای عملیات بهینه‌سازی روی هاست)', 'tisacase-optimizer' ),
		);
		return $schedules;
	}

	/**
	 * آیا اجرای سمت هاست روشن است؟
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) TisaCase_Optimizer_Settings::get( 'server_runner', 1 );
	}

	/**
	 * بازهٔ تیک به ثانیه (۳۰ یا ۶۰).
	 *
	 * @return int
	 */
	public static function interval() {
		return 60 === (int) TisaCase_Optimizer_Settings::get( 'runner_interval', 30 ) ? 60 : 30;
	}

	/**
	 * نام بازهٔ کرون متناسب با تنظیم.
	 *
	 * @return string
	 */
	public static function schedule_name() {
		return 60 === self::interval() ? self::SCHEDULE_60 : self::SCHEDULE_30;
	}

	/**
	 * هم‌گام‌کردن رویداد کرون با تنظیمات (روشن/خاموش شدن خودکار).
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$next = wp_next_scheduled( self::HOOK );

		if ( ! self::enabled() ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		// بازهٔ اشتباه (کاربر از ۳۰ به ۶۰ تغییر داده) ⇒ رویداد تازه.
		$current = wp_get_schedule( self::HOOK );
		if ( $next && $current && $current !== self::schedule_name() ) {
			wp_clear_scheduled_hook( self::HOOK );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 5, self::schedule_name(), self::HOOK );
		}
	}

	/**
	 * خاموش‌کردن کامل (غیرفعال‌سازی افزونه).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * بیدارکردن فوری موتور پس از ثبت یک کار تازه.
	 *
	 * چرا loopback؟ کرون وردپرس با هر بازدید سایت اجرا می‌شود؛ اما کاربر
	 * می‌خواهد بعد از زدن دکمه، کار «همان حالا» شروع شود. یک درخواست
	 * غیرمسدودکننده به wp-cron.php همان کار را می‌کند و اگر هاست اجازه ندهد،
	 * تیک بعدی از راه کرون عادی یا از خود پنل انجام می‌شود (بن‌بست ندارد).
	 *
	 * @return void
	 */
	public static function wake() {
		if ( ! self::enabled() ) {
			return;
		}
		self::sync_schedule();
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * یک تیک: کمی از کار فعال را جلو می‌برد.
	 *
	 * @param string $expect_job_id اگر مقدار داشته باشد، فقط همان کار اجرا می‌شود.
	 * @return array<string,mixed> گزارش کوتاه تیک (برای لاگ و پاسخ AJAX).
	 */
	public static function tick( $expect_job_id = '' ) {
		$report = array(
			'ran'      => false,
			'reason'   => '',
			'job_id'   => '',
			'done'     => 0,
			'finished' => false,
		);

		if ( ! self::enabled() ) {
			$report['reason'] = 'disabled';
			return $report;
		}

		$job = TisaCase_Optimizer_Job::get();
		if ( ! $job || 'running' !== (string) $job['status'] ) {
			$report['reason'] = 'no_job';
			return $report;
		}
		if ( '' !== $expect_job_id && (string) $job['id'] !== (string) $expect_job_id ) {
			$report['reason'] = 'other_job';
			return $report;
		}
		// کاری که کاربر از پنل اجرا می‌کند (حالت خاموشِ اجرای روی هاست) نباید
		// هم‌زمان از کرون هم جلو برود؛ وگرنه یک آیتم دو بار پردازش می‌شود.
		if ( empty( $job['runner_started'] ) ) {
			$report['reason'] = 'panel_job';
			return $report;
		}

		if ( ! self::acquire_lock() ) {
			$report['reason'] = 'locked';
			$report['job_id'] = (string) $job['id'];
			return $report;
		}

		$report['ran']    = true;
		$report['job_id'] = (string) $job['id'];

		// اگر کاربر مرورگر را ببندد، PHP نباید وسط تیک کشته شود.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		// سقف مطلق اجرای این درخواست؛ همیشه کمتر از سقف ۲۸ ثانیه هاست و کمی
		// بزرگ‌تر از بودجهٔ پردازش تا ذخیرهٔ پایان تیک جا بگیرد.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 24 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		try {
			$budget = max( 1500, min( 16000, (int) TisaCase_Optimizer_Settings::get( 'runner_tick_ms', 12000 ) ) );
			$max    = max( 5, min( 250, (int) TisaCase_Optimizer_Settings::get( 'runner_tick_max', 60 ) ) );

			$before = (int) $job['done'];
			$job    = TisaCase_Optimizer_Job::advance_now( $job, $budget, $max );

			$report['done']     = max( 0, (int) $job['done'] - $before );
			$report['finished'] = 'running' !== (string) $job['status'];

			if ( $report['finished'] ) {
				// کار تمام‌شده در سرور می‌ماند تا پنل بتواند خلاصهٔ نهایی را
				// نشان دهد (کاربر ممکن است هنوز صفحه را باز نکرده باشد).
				self::remember_result( $job );
			}
		} catch ( Throwable $e ) {
			// خطای یک تیک نباید کار را نابود کند؛ وضعیت ذخیره‌شده می‌ماند.
			try {
				TisaCase_Optimizer_Job::mark_error( $job, $e->getMessage() );
			} catch ( Throwable $ignored ) {
				unset( $ignored );
			}
			$report['reason'] = 'error';
			$report['error']  = $e->getMessage();
		} finally {
			self::release_lock();
			update_option( self::LAST_TICK_OPTION, time(), false );
			update_option( self::TICKS_OPTION, (int) get_option( self::TICKS_OPTION, 0 ) + 1, false );
		}

		// زنجیرهٔ توربو: تا وقتی کار باقی است، تیک بعدی را به بازهٔ ۳۰/۶۰ ثانیه
		// کرون نسپار؛ یک رویداد تک‌بارهٔ چند ثانیه‌ای ثبت می‌شود تا پردازش
		// تقریباً پیوسته پیش برود (هر درخواست همچنان بودجه و قفل خودش را دارد).
		if ( $report['ran'] && ! $report['finished'] ) {
			self::chain_next_tick();
		}

		return $report;
	}

	/**
	 * ثبت رویداد تک‌بارهٔ نزدیک برای تیک بعدی (زنجیرهٔ توربو حین اجرای کار).
	 *
	 * ایمن: اگر همین حالا رویداد نزدیکی (≤۱۰ ثانیه) در صف باشد ثبت نمی‌کند تا
	 * زنجیره‌های موازی ساخته نشوند؛ قفل اتمیک هم اجرای هم‌زمان را غیرممکن می‌کند.
	 *
	 * @return void
	 */
	private static function chain_next_tick() {
		$next = wp_next_scheduled( self::HOOK );
		if ( $next && ( (int) $next - time() ) <= 10 ) {
			return;
		}
		wp_schedule_single_event( time() + 3, self::HOOK );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * گرفتن قفل سبک (اتمیک؛ ضد اجرای هم‌زمان دو تیک).
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$now = time();
		// add_option اتمیک است: فقط یک درخواست برنده می‌شود.
		if ( add_option( self::LOCK_OPTION, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held > 0 && ( $now - $held ) < self::LOCK_TTL ) {
			return false;
		}
		// قفل رهاشده (PHP وسط تیک مرده بود) ⇒ آزاد و دوباره بگیر.
		update_option( self::LOCK_OPTION, $now, false );
		return true;
	}

	/**
	 * آزادکردن قفل.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * ذخیرهٔ نتیجهٔ کار تمام‌شده تا اگر مرورگر بسته بود هم کاربر ببیند.
	 *
	 * @param array<string,mixed> $job کار.
	 * @return void
	 */
	public static function remember_result( array $job ) {
		try {
			update_option(
				self::LAST_RESULT_OPTION,
				array(
					'summary'   => TisaCase_Optimizer_Job::summary( $job ),
					'finished'  => time(),
					'by_runner' => true,
				),
				false
			);
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * نتیجهٔ آخرین کار سمت هاست (برای کارت «در نبود شما تمام شد»).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function last_result() {
		$raw = get_option( self::LAST_RESULT_OPTION, null );
		if ( ! is_array( $raw ) || empty( $raw['summary'] ) || ! is_array( $raw['summary'] ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * پاک‌کردن نتیجهٔ دیده‌شده (کاربر دکمهٔ «بستن» را زده).
	 *
	 * @return void
	 */
	public static function forget_result() {
		delete_option( self::LAST_RESULT_OPTION );
	}

	/**
	 * وضعیت کوتاه موتور برای رابط کاربری.
	 *
	 * @return array<string,mixed>
	 */
	public static function status() {
		$last    = (int) get_option( self::LAST_TICK_OPTION, 0 );
		$ticks   = (int) get_option( self::TICKS_OPTION, 0 );
		$next    = wp_next_scheduled( self::HOOK );
		$job     = TisaCase_Optimizer_Job::get();
		$ago     = $last > 0 ? max( 0, time() - $last ) : null;
		// اگر کرون هاست اجرا نشود (DISABLE_WP_CRON یا سایت بدون بازدید)،
		// آخرین تیک قدیمی می‌ماند؛ این حالت باید صادقانه به کاربر گفته شود.
		$sleeping = ! $next || ( null !== $ago && $ago > ( self::interval() * 4 + 60 ) );

		return array(
			'enabled'      => self::enabled(),
			'interval'     => self::interval(),
			'next_run'     => $next ? (int) $next : 0,
			'next_in'      => $next ? max( 0, (int) $next - time() ) : 0,
			'last_tick'    => $last,
			'last_tick_ago' => $ago,
			'ticks'        => $ticks,
			// «خوابیده» یعنی کرون هاست اجرا نمی‌شود؛ رابط کاربری صادقانه
			// می‌گوید کار در نخستین بازدید سایت ادامه می‌یابد و دکمهٔ
			// «همین حالا ادامه بده» می‌دهد.
			'sleeping'     => (bool) $sleeping,
			'job_running'  => (bool) ( $job && 'running' === (string) $job['status'] ),
			'tick_ms'      => max( 1500, min( 16000, (int) TisaCase_Optimizer_Settings::get( 'runner_tick_ms', 12000 ) ) ),
			'tick_max'     => max( 5, min( 250, (int) TisaCase_Optimizer_Settings::get( 'runner_tick_max', 60 ) ) ),
		);
	}
}
