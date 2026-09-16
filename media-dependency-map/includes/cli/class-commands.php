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

defined( 'ABSPATH' ) || exit;

/** Bounded read-only inspections; no whole-site scan is implied. */
final class Commands {

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
		if ( ! current_user_can( 'mdm_view_dependencies' ) ) {
			\WP_CLI::error( 'Select an authorized user with --user.' );
			return;
		}
		\WP_CLI::line(
			(string) wp_json_encode(
				array(
					'schema_ready'          => Schema::VERSION === get_option( 'mdm_schema_version' ),
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
