<?php
/**
 * AJAX endpoints: batched delete, dry run, batched restore, backup management.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BDC_Ajax
 */
class BDC_Ajax {

	const NONCE = 'bdc_ajax';
	const LOCK  = 'bdc_operation_lock';

	/**
	 * Backup engine.
	 *
	 * @var BDC_Backup
	 */
	private $backup;

	/**
	 * Deleter.
	 *
	 * @var BDC_Deleter
	 */
	private $deleter;

	/**
	 * Constructor. Registers routes only; the heavy engines are built on demand.
	 */
	public function __construct() {
		$actions = array(
			'bdc_collect_ids',
			'bdc_start_run',
			'bdc_process_batch',
			'bdc_finish_run',
			'bdc_preview',
			'bdc_restore_start',
			'bdc_restore_batch',
			'bdc_delete_backup',
		);

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'route' ) );
		}
	}

	/**
	 * Backup engine, built on first use.
	 *
	 * @return BDC_Backup
	 */
	private function backup() {
		if ( null === $this->backup ) {
			$this->backup = bdc()->backup();
		}

		return $this->backup;
	}

	/**
	 * Deleter, built on first use.
	 *
	 * @return BDC_Deleter
	 */
	private function deleter() {
		if ( null === $this->deleter ) {
			$this->deleter = bdc()->deleter();
		}

		return $this->deleter;
	}

	/**
	 * Number of products processed per delete batch.
	 *
	 * Shared by the server loop and the browser so the two can never drift.
	 *
	 * @return int
	 */
	public static function batch_size() {
		/**
		 * Filters how many products are deleted per AJAX batch.
		 *
		 * The old default of 10 meant 52 separate admin-ajax round trips for
		 * 512 products. Each of those re-bootstraps WordPress, every active
		 * plugin and the theme — on a typical shared host that boot costs far
		 * more than the deletion itself, so the run was dominated by overhead
		 * rather than work. 40 is a safer starting point: the per-batch time
		 * guard in BDC_Deleter still closes any batch that runs long, so a
		 * slow host degrades into more batches instead of a timeout.
		 *
		 * @param int $size Batch size.
		 */
		$size = (int) apply_filters( 'bdc_batch_size', 40 );

		return max( 1, min( self::MAX_BATCH, $size ) );
	}

	/**
	 * Upper bound for a single batch.
	 *
	 * The time and memory guards inside BDC_Deleter are the real safety net;
	 * this cap only stops an absurd filter value from building a huge payload.
	 */
	const MAX_BATCH = 200;

	/**
	 * Suggests the next batch size from how long the previous one took.
	 *
	 * The browser sends back the wall time of the batch it just received and
	 * this grows or shrinks the next slice to keep each request near a target
	 * duration. A fast host converges upward to MAX_BATCH and finishes 512
	 * products in a handful of requests; a slow host shrinks and keeps every
	 * request comfortably short of the PHP time limit.
	 *
	 * @param int   $current  Batch size that was just used.
	 * @param float $duration Seconds the server spent on it.
	 * @return int Suggested next batch size.
	 */
	public static function next_batch_size( $current, $duration ) {
		$current = max( 1, (int) $current );
		$duration = (float) $duration;

		/**
		 * Filters the wall-clock time each batch should aim for, in seconds.
		 *
		 * @param float $target Target seconds per batch.
		 */
		$target = (float) apply_filters( 'bdc_batch_target_seconds', 6.0 );
		$target = max( 1.0, $target );

		// No usable measurement yet — leave the size alone.
		if ( $duration <= 0.0 ) {
			return $current;
		}

		$scaled = (int) round( $current * ( $target / $duration ) );

		// Never move more than 2x in one step, so a single slow batch (a
		// product with hundreds of images, say) cannot cause wild swings.
		$scaled = min( $scaled, $current * 2 );
		$scaled = max( $scaled, (int) ceil( $current / 2 ) );

		return max( 1, min( self::MAX_BATCH, $scaled ) );
	}

	/**
	 * Required capability.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to use the cleaner.
		 *
		 * @param string $capability Capability slug.
		 */
		return (string) apply_filters( 'bdc_required_capability', 'manage_options' );
	}

	/**
	 * Acquires the mutating-operation lock.
	 *
	 * Prevents two administrators (or a double-clicked button) from running
	 * overlapping delete/restore passes over the same rows.
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock() {
		$owner = get_transient( self::LOCK );

		if ( $owner && (int) $owner !== get_current_user_id() ) {
			return false;
		}

		// Short TTL: if a request dies mid-flight the lock frees itself.
		set_transient( self::LOCK, get_current_user_id(), 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Releases the mutating-operation lock.
	 *
	 * @return void
	 */
	private function release_lock() {
		delete_transient( self::LOCK );
	}

	/**
	 * Shared guard, then dispatch.
	 *
	 * @return void
	 */
	public function route() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'دسترسی غیرمجاز.', 'bulk-product-cleaner' ) ),
				403
			);
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		// Serialise anything that writes.
		$mutating = array( 'bdc_process_batch', 'bdc_restore_batch', 'bdc_delete_backup' );

		if ( in_array( $action, $mutating, true ) && ! $this->acquire_lock() ) {
			wp_send_json_error(
				array(
					'message' => __( 'یک عملیات دیگر توسط کاربر دیگری در حال اجراست. لطفاً صبر کنید.', 'bulk-product-cleaner' ),
					'locked'  => true,
				),
				409
			);
		}

		// Never let a runaway batch hold a PHP worker hostage.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		ignore_user_abort( true );

		switch ( $action ) {
			case 'bdc_collect_ids':
				$this->collect_ids();
				break;
			case 'bdc_start_run':
				$this->start_run();
				break;
			case 'bdc_process_batch':
				$this->process_batch();
				break;
			case 'bdc_finish_run':
				$this->finish_run();
				break;
			case 'bdc_preview':
				$this->preview();
				break;
			case 'bdc_restore_start':
				$this->restore_start();
				break;
			case 'bdc_restore_batch':
				$this->restore_batch();
				break;
			case 'bdc_delete_backup':
				$this->delete_backup();
				break;
			default:
				wp_send_json_error( array( 'message' => __( 'عملیات نامعتبر.', 'bulk-product-cleaner' ) ), 400 );
		}
	}

	/**
	 * Reads and sanitizes the filter payload.
	 *
	 * @return array
	 */
	private function read_filters() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$raw = isset( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array();

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}

		return BDC_Query::sanitize_filters( is_array( $raw ) ? $raw : array() );
	}

	/**
	 * Reads an explicit list of product IDs.
	 *
	 * @return int[]
	 */
	private function read_ids() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : explode( ',', $raw );
		}

		$ids = array_map( 'absint', (array) $raw );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Resolves every matching product ID for "select all matching".
	 *
	 * @return void
	 */
	private function collect_ids() {
		$filters = $this->read_filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$after_id = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;

		$chunk = 2000;
		$ids   = BDC_Query::get_ids_after( $filters, $chunk, $after_id );
		$last_id = empty( $ids ) ? $after_id : (int) end( $ids );

		wp_send_json_success(
			array(
				'ids'      => $ids,
				'after_id' => $last_id,
				'done'     => count( $ids ) < $chunk,
			)
		);
	}

	/**
	 * Dry-run preview.
	 *
	 * @return void
	 */
	private function preview() {
		$ids = $this->read_ids();

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'هیچ محصولی انتخاب نشده است.', 'bulk-product-cleaner' ) ), 400 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'trash';

		// Preview a bounded slice to keep the response snappy.
		$limit = (int) apply_filters( 'bdc_preview_limit', 200 );
		$limit = max( 1, min( 1000, $limit ) );

		$preview = $this->deleter()->preview( array_slice( $ids, 0, $limit ), $mode );

		$preview['total_selected'] = count( $ids );
		$preview['limit']          = $limit;
		$preview['truncated']      = count( $ids ) > $limit;

		wp_send_json_success( $preview );
	}

	/**
	 * Opens a backup run.
	 *
	 * @return void
	 */
	private function start_run() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'trash';
		$mode = BDC_Deleter::sanitize_mode( $mode );

		// Trash mode is reversible via WordPress itself; no snapshot needed.
		if ( 'trash' === $mode ) {
			wp_send_json_success(
				array(
					'run_id'  => '',
					'backup'  => false,
					'message' => __( 'حالت زباله‌دان — بازیابی از طریق خود وردپرس انجام می‌شود.', 'bulk-product-cleaner' ),
				)
			);
		}

		$run_id = $this->backup()->start(
			array(
				'mode'          => $mode,
				'delete_images' => ( 'delete_media' === $mode ),
				'filters'       => $this->read_filters(),
			)
		);

		if ( is_wp_error( $run_id ) ) {
			wp_send_json_error( array( 'message' => $run_id->get_error_message() ), 500 );
		}

		wp_send_json_success(
			array(
				'run_id'    => $run_id,
				'backup'    => true,
				'retention' => BDC_Backup::retention_days(),
				'message'   => sprintf(
					/* translators: 1: run id, 2: retention days. */
					__( 'پشتیبان «%1$s» ساخته شد و تا %2$d روز نگه‌داری می‌شود.', 'bulk-product-cleaner' ),
					$run_id,
					BDC_Backup::retention_days()
				),
			)
		);
	}

	/**
	 * Processes one deletion batch.
	 *
	 * @return void
	 */
	private function process_batch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'trash';
		$mode = BDC_Deleter::sanitize_mode( $mode );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		$ids = $this->read_ids();

		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'دسته خالی است.', 'bulk-product-cleaner' ) ), 400 );
		}

		// The browser proposes a size (it adapts to how fast this host has
		// actually been); we clamp it so a tampered request cannot ask for an
		// unbounded slice. Falls back to the default when absent.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$want = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 0;
		$size = $want > 0 ? max( 1, min( self::MAX_BATCH, $want ) ) : self::batch_size();

		$ids = array_slice( $ids, 0, $size );

		$batch_started = microtime( true );
		$do_backup     = false;

		if ( 'trash' !== $mode ) {
			if ( ! BDC_Backup::is_valid_run_id( $run_id ) || ! $this->backup()->resume( $run_id ) ) {
				wp_send_json_error(
					array( 'message' => __( 'نشست پشتیبان معتبر نیست — حذف متوقف شد.', 'bulk-product-cleaner' ) ),
					400
				);
			}
			$do_backup = true;
		}

		$result = $this->deleter()->delete_batch( $ids, $mode, $do_backup );

		if ( $do_backup ) {
			$this->backup()->finish( 'running' );
		}

		$this->release_lock();

		// Report how long this took plus the size that would keep the next
		// request near the target duration, so the loop self-tunes.
		$elapsed = microtime( true ) - $batch_started;

		$result['duration']   = round( $elapsed, 3 );
		$result['batch_used'] = count( $ids );
		$result['next_batch'] = self::next_batch_size( count( $ids ), $elapsed );

		wp_send_json_success( $result );
	}

	/**
	 * Closes a run.
	 *
	 * @return void
	 */
	private function finish_run() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'completed';

		if ( ! BDC_Backup::is_valid_run_id( $run_id ) ) {
			wp_send_json_success( array( 'message' => __( 'پایان یافت.', 'bulk-product-cleaner' ) ) );
		}

		$this->backup()->resume( $run_id );
		$manifest = $this->backup()->finish( $status );

		wp_send_json_success(
			array(
				'manifest' => $manifest,
				'message'  => __( 'عملیات به پایان رسید و پشتیبان بسته شد.', 'bulk-product-cleaner' ),
			)
		);
	}

	/**
	 * Prepares a restore job.
	 *
	 * @return void
	 */
	private function restore_start() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		if ( ! BDC_Backup::is_valid_run_id( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'شناسه پشتیبان نامعتبر است.', 'bulk-product-cleaner' ) ), 400 );
		}

		$manifest = BDC_Backup::load_manifest( $run_id );

		if ( empty( $manifest ) ) {
			wp_send_json_error( array( 'message' => __( 'پشتیبان یافت نشد.', 'bulk-product-cleaner' ) ), 404 );
		}

		$total = BDC_Backup::count_records( $run_id );

		wp_send_json_success(
			array(
				'run_id' => $run_id,
				'total'  => $total,
			)
		);
	}

	/**
	 * Restores one batch.
	 *
	 * @return void
	 */
	private function restore_batch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		if ( ! BDC_Backup::is_valid_run_id( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'شناسه پشتیبان نامعتبر است.', 'bulk-product-cleaner' ) ), 400 );
		}

		// Restoring re-creates posts and copies files back, so it is heavier
		// per item than deleting — but it suffered the same round-trip tax.
		// Same treatment: a higher floor plus client-proposed sizing.
		$limit = (int) apply_filters( 'bdc_restore_batch_size', 20 );
		$limit = max( 1, min( self::MAX_BATCH, $limit ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$want = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 0;
		if ( $want > 0 ) {
			$limit = max( 1, min( self::MAX_BATCH, $want ) );
		}

		$batch_started = microtime( true );

		$result           = BDC_Backup::restore_batch( $run_id, $offset, $limit );
		$result['offset'] = $offset + $result['done'];

		if ( $result['eof'] ) {
			BDC_Backup::mark_restored( $run_id );
		}

		$this->release_lock();

		$elapsed = microtime( true ) - $batch_started;

		$result['duration']   = round( $elapsed, 3 );
		$result['next_batch'] = self::next_batch_size( max( 1, (int) $result['done'] ), $elapsed );

		wp_send_json_success( $result );
	}

	/**
	 * Deletes a backup run.
	 *
	 * @return void
	 */
	private function delete_backup() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in route().
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';

		if ( ! BDC_Backup::is_valid_run_id( $run_id ) ) {
			wp_send_json_error( array( 'message' => __( 'شناسه پشتیبان نامعتبر است.', 'bulk-product-cleaner' ) ), 400 );
		}

		$deleted = BDC_Backup::delete_run( $run_id );

		$this->release_lock();

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'حذف پشتیبان ناموفق بود.', 'bulk-product-cleaner' ) ), 500 );
		}

		wp_send_json_success( array( 'message' => __( 'پشتیبان حذف شد.', 'bulk-product-cleaner' ) ) );
	}
}
