<?php
/**
 * WP-CLI commands.
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Bulk-clean draft products and manage backups.
 */
class BDC_CLI {

	/**
	 * Deletes matching products, always taking a backup first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses. Default: draft.
	 *
	 * [--older-than=<days>]
	 * : Only products not modified for N days.
	 *
	 * [--mode=<mode>]
	 * : trash | delete | delete_media. Default: trash.
	 *
	 * [--images=<images>]
	 * : any | with | without. Default: any.
	 *
	 * [--limit=<number>]
	 * : Maximum products to process. Default: 1000.
	 *
	 * [--batch=<number>]
	 * : Products per batch. Default: 20.
	 *
	 * [--dry-run]
	 * : Report only, change nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bdc clean --dry-run
	 *     wp bdc clean --older-than=180 --mode=delete_media --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function clean( $args, $assoc_args ) {
		$mode  = BDC_Deleter::sanitize_mode( isset( $assoc_args['mode'] ) ? $assoc_args['mode'] : 'trash' );
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 1000;
		$batch = isset( $assoc_args['batch'] ) ? max( 1, min( 100, (int) $assoc_args['batch'] ) ) : 20;
		$dry   = isset( $assoc_args['dry-run'] );

		$filters = BDC_Query::sanitize_filters(
			array(
				'status'     => isset( $assoc_args['status'] ) ? explode( ',', (string) $assoc_args['status'] ) : array( 'draft' ),
				'older_than' => isset( $assoc_args['older-than'] ) ? (int) $assoc_args['older-than'] : 0,
				'images'     => isset( $assoc_args['images'] ) ? (string) $assoc_args['images'] : 'any',
			)
		);

		$total = BDC_Query::count( $filters );
		WP_CLI::log( sprintf( 'Matched %d product(s). Mode: %s.', $total, $mode ) );

		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to do.' );
			return;
		}

		$process_total = min( $total, $limit );

		$plugin  = bdc();
		$deleter = $plugin->deleter();
		$backup  = $plugin->backup();

		if ( ! $deleter || ! $backup ) {
			WP_CLI::error( 'WooCommerce is not active.' );
			return;
		}

		if ( $dry ) {
			$preview_ids = BDC_Query::get_ids_after( $filters, min( 500, $process_total ), 0 );
			$preview = $deleter->preview( $preview_ids, $mode );
			WP_CLI::log( sprintf( 'Products: %d', $preview['products'] ) );
			WP_CLI::log( sprintf( 'Variations: %d', $preview['variations'] ) );
			WP_CLI::log( sprintf( 'Images removable: %d', $preview['images'] ) );
			WP_CLI::log( sprintf( 'Images preserved (shared): %d', $preview['shared'] ) );
			WP_CLI::log( sprintf( 'Disk to free: %s', $preview['size'] ) );
			WP_CLI::success( 'Dry run complete. Nothing was changed.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Process %d product(s) in mode "%s"?', $process_total, $mode ), $assoc_args );

		$run_id = '';

		if ( 'trash' !== $mode ) {
			$run_id = $backup->start(
				array(
					'mode'          => $mode,
					'delete_images' => ( 'delete_media' === $mode ),
					'filters'       => $filters,
				)
			);

			if ( is_wp_error( $run_id ) ) {
				WP_CLI::error( $run_id->get_error_message() );
				return;
			}

			WP_CLI::log( sprintf( 'Backup run: %s (kept %d days)', $run_id, BDC_Backup::retention_days() ) );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Cleaning', $process_total );
		$totals   = array(
			'ok'      => 0,
			'fail'    => 0,
			'skipped' => 0,
			'bytes'   => 0,
		);

		$after_id  = 0;
		$processed = 0;
		while ( $processed < $process_total ) {
			$want  = min( $batch, $process_total - $processed );
			$chunk = BDC_Query::get_ids_after( $filters, $want, $after_id );
			if ( empty( $chunk ) ) {
				break;
			}
			$after_id = (int) end( $chunk );
			$result   = $deleter->delete_batch( $chunk, $mode, 'trash' !== $mode );

			$totals['ok']      += $result['ok'];
			$totals['fail']    += $result['fail'];
			$totals['skipped'] += $result['skipped'];
			$totals['bytes']   += $result['bytes'];
			$processed        += count( $chunk );

			foreach ( $chunk as $unused ) {
				$progress->tick();
			}
		}

		$progress->finish();

		if ( '' !== $run_id ) {
			$backup->finish( 'completed' );
		}

		WP_CLI::success(
			sprintf(
				'Done. OK: %d, skipped: %d, failed: %d, freed: %s',
				$totals['ok'],
				$totals['skipped'],
				$totals['fail'],
				size_format( $totals['bytes'], 2 )
			)
		);
	}

	/**
	 * Lists available backups.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bdc backups
	 *
	 * @return void
	 */
	public function backups() {
		$runs = BDC_Backup::list_runs();

		if ( empty( $runs ) ) {
			WP_CLI::log( 'No backups.' );
			return;
		}

		$rows = array();
		foreach ( $runs as $run ) {
			$stats  = isset( $run['stats'] ) ? (array) $run['stats'] : array();
			$rows[] = array(
				'run_id'    => $run['run_id'],
				'created'   => gmdate( 'Y-m-d H:i', (int) $run['created_ts'] ),
				'products'  => isset( $stats['products'] ) ? (int) $stats['products'] : 0,
				'size'      => size_format( (int) $run['size_on_disk'], 2 ),
				'days_left' => (int) $run['days_left'],
				'restored'  => ! empty( $run['restored'] ) ? 'yes' : 'no',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'run_id', 'created', 'products', 'size', 'days_left', 'restored' ) );
	}

	/**
	 * Restores a backup run.
	 *
	 * ## OPTIONS
	 *
	 * <run_id>
	 * : The backup run id.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bdc restore bdc-20260907-101500-a1b2c3d4 --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$run_id = isset( $args[0] ) ? (string) $args[0] : '';

		if ( ! BDC_Backup::is_valid_run_id( $run_id ) ) {
			WP_CLI::error( 'Invalid run id.' );
			return;
		}

		$total = BDC_Backup::count_records( $run_id );

		if ( 0 === $total ) {
			WP_CLI::error( 'Backup is empty or missing.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Restore %d product(s) from %s?', $total, $run_id ), $assoc_args );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Restoring', $total );
		$offset   = 0;
		$summary  = array(
			'restored' => 0,
			'skipped'  => 0,
			'failed'   => 0,
		);

		while ( $offset < $total ) {
			$batch = BDC_Backup::restore_batch( $run_id, $offset, 5 );

			if ( 0 === $batch['done'] ) {
				break;
			}

			$summary['restored'] += $batch['restored'];
			$summary['skipped']  += $batch['skipped'];
			$summary['failed']   += $batch['failed'];

			for ( $i = 0; $i < $batch['done']; $i++ ) {
				$progress->tick();
			}

			$offset += $batch['done'];

			if ( $batch['eof'] ) {
				break;
			}
		}

		$progress->finish();
		BDC_Backup::mark_restored( $run_id );

		WP_CLI::success(
			sprintf(
				'Restored: %d, skipped: %d, failed: %d',
				$summary['restored'],
				$summary['skipped'],
				$summary['failed']
			)
		);
	}

	/**
	 * Purges backups older than the retention window.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bdc purge
	 *
	 * @return void
	 */
	public function purge() {
		$purged = BDC_Backup::purge_expired();
		WP_CLI::success( sprintf( 'Purged %d expired backup run(s).', $purged ) );
	}
}
