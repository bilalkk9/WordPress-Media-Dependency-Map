<?php
/**
 * Diagnostic commands for the core scanner milestone.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\CLI;

use Bilal\MediaDependencyMap\Adapters\Core;
use Bilal\MediaDependencyMap\Adapters\Site_Identity;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;
use Bilal\MediaDependencyMap\Persistence\Browser_Repository;
use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Plugin;

defined( 'ABSPATH' ) || exit;

/** Bounded read-only inspections; no whole-site scan is implied. */
final class Commands {

	/**
	 * Run or resume a full scan using bounded batches.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Start a new full scan (or continue the active scan).
	 * [--resume]
	 * : Resume saved work without starting another scan.
	 * [--batch]
	 * : Process only one batch and return.
	 *
	 * @param string[] $args Positional arguments.
	 * @param array    $assoc_args Named arguments.
	 */
	public function scan( $args, $assoc_args ) {
		if ( ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Select an authorized administrator with --user.' );
			return; }
		if ( ! isset( $assoc_args['all'] ) && ! isset( $assoc_args['resume'] ) ) {
			\WP_CLI::error( 'Use --all or --resume.' );
			return; }
		global $wpdb;
		$engine = Plugin::engine();
		$store  = new Scan_Repository( $wpdb );
		try {
			if ( isset( $assoc_args['all'] ) ) {
				$engine->start(); }
			$control = $store->control();
			if ( ! $control->active_run && ! $control->active_generation ) {
				\WP_CLI::error( 'No scan to resume. Start with --all.' );
				return;
			}
			$deadline = microtime( true ) + 300;
			do {
				$engine->tick();
				$control = $store->control();
				if ( get_option( 'mdm_worker_error' ) ) {
					\WP_CLI::error( 'Worker interrupted; the checkpoint is preserved. Correct the cause and resume.' );
					return; }
				if ( isset( $assoc_args['batch'] ) ) {
					break; }
				if ( microtime( true ) >= $deadline ) {
					\WP_CLI::warning( 'CLI time budget reached. Resume saved work with --resume.' );
					return;
				}
				usleep( 100000 );
			} while ( $control->active_run || $store->next_item() );
			$last = $store->run( (int) $control->last_run );
			if ( $last && 'failed' === $last->status ) {
				\WP_CLI::error( 'Scan completed with failures; the previous index was preserved.' );
				return; }
			if ( $store->queue_count() && ! $control->active_run && ! $store->next_item() ) {
				\WP_CLI::error( 'Some queued changes failed. Start a full scan to retry.' );
				return; }
			\WP_CLI::success( $control->active_run || $store->queue_count() ? 'Batch saved. Resume to continue.' : 'Core index is up to date for the supported sources.' );
		} catch ( \Throwable $error ) {
			\WP_CLI::error( 'Scan could not proceed. Check permissions, schema and whether another worker is active.' ); }
	}

	/**
	 * Rebuild the index while preserving the current published generation until success.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Confirm the rebuild without a prompt.
	 *
	 * @param string[] $args Positional arguments.
	 * @param array    $assoc_args Named arguments.
	 */
	public function rebuild( $args, $assoc_args ) {
		if ( ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Select an authorized administrator with --user.' );
			return; }
		\WP_CLI::confirm( 'Build a new core index?', $assoc_args );
		$this->scan( array(), array( 'all' => true ) );
	}

	/**
	 * Read one page of published references as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>
	 * : Attachment ID.
	 * [--page=<page>]
	 * : Results page, 50 records per page.
	 *
	 * @param string[] $args Positional arguments.
	 * @param array    $assoc_args Named arguments.
	 */
	public function references( $args, $assoc_args ) {
		$id = absint( $args[0] ?? 0 );
		if ( ! current_user_can( 'mdm_view_dependencies' ) || ! current_user_can( 'manage_options' ) || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
			\WP_CLI::error( 'Select an authorized administrator and a valid attachment.' );
			return; }
		global $wpdb;
		$page = max( 1, absint( $assoc_args['page'] ?? 1 ) );
		$rows = ( new Browser_Repository( $wpdb ) )->references( $id, ( $page - 1 ) * 50 );
		\WP_CLI::line(
			(string) wp_json_encode(
				array(
					'page'       => $page,
					'has_next'   => count( $rows ) > 50,
					'references' => array_slice( $rows, 0, 50 ),
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Inspect core media references in one post without changing the index.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : Post, page or template ID to inspect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mdm inspect 123 --user=admin
	 *
	 * @param string[] $args Positional arguments.
	 */
	public function inspect( $args ) {
		$id = isset( $args[0] ) && ctype_digit( $args[0] ) ? (int) $args[0] : 0;
		if ( ! $id || ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'mdm_view_dependencies' ) || ! current_user_can( 'edit_post', $id ) ) {
			\WP_CLI::error( 'Select an authorized user with --user and an editable post ID.' );
			return;
		}
		global $wpdb;
		try {
			$scanner = new Core( new Resolver( new Attachment_Repository( $wpdb ) ) );
			$rows    = array();
			foreach ( $scanner->scan( $id ) as $reference ) {
				$row = $reference->to_array();
				if ( null === $row['attachment_id'] || current_user_can( 'read_post', $row['attachment_id'] ) ) {
					$rows[] = $row;
				}
			}
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'coverage'   => 'core-post-only',
						'indexed'    => false,
						'references' => $rows,
					),
					JSON_PRETTY_PRINT
				)
			);
		} catch ( \Throwable $error ) {
			\WP_CLI::error( 'Core inspection failed or exceeded its budget. The index was not changed.' );
		}
	}

	/** Inspect the current site's allowlisted identity settings as JSON. */
	public function identity() {
		if ( ! current_user_can( 'mdm_run_scans' ) || ! current_user_can( 'mdm_view_dependencies' ) || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Select an authorized administrator with --user.' );
			return;
		}
		global $wpdb;
		try {
			$scanner = new Site_Identity( new Resolver( new Attachment_Repository( $wpdb ) ) );
			$rows    = array();
			foreach ( $scanner->scan( 0 ) as $reference ) {
				$rows[] = $reference->to_array();
			}
			\WP_CLI::line(
				(string) wp_json_encode(
					array(
						'coverage'   => 'site-identity-only',
						'indexed'    => false,
						'references' => $rows,
					),
					JSON_PRETTY_PRINT
				)
			);
		} catch ( \Throwable $error ) {
			\WP_CLI::error( 'Site identity inspection failed. The index was not changed.' );
		}
	}

	/** Report schema and scanner availability without claiming site-wide coverage. */
	public function status() {
		if ( ! current_user_can( 'mdm_view_dependencies' ) || ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Select an authorized user with --user.' );
			return;
		}
		global $wpdb;
		if ( Schema::VERSION === get_option( 'mdm_schema_version' ) ) {
			\WP_CLI::line( (string) wp_json_encode( ( new Browser_Repository( $wpdb ) )->status(), JSON_PRETTY_PRINT ) );
			return;
		}
		\WP_CLI::line(
			(string) wp_json_encode(
				array(
					'schema_ready'          => false,
					'coverage'              => 'not-fully-scanned',
					'inspection_adapters'   => array( 'core', 'site-identity' ),
					'full_scan_available'   => false,
					'replacement_available' => false,
				),
				JSON_PRETTY_PRINT
			)
		);
	}
}
