<?php
/**
 * Transactional reference persistence.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Persistence;

use Bilal\MediaDependencyMap\Domain\Reference;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This repository owns uncached custom index tables.

/** Reconciles complete consumer snapshots atomically. */
final class Reference_Repository {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Create a repository.
	 *
	 * @param \wpdb $db Site connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Create a bounded consumer scan record.
	 *
	 * @return int Run ID.
	 */
	public function start_run() {
		$result = $this->db->insert(
			$this->db->prefix . 'mdm_scan_runs',
			array(
				'type'        => 'consumer',
				'status'      => 'running',
				'started_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'state_json'  => '{}',
			)
		);
		$this->check( $result );
		return (int) $this->db->insert_id;
	}

	/**
	 * Persist a successful consumer snapshot; failure retains its previous snapshot.
	 *
	 * @throws \RuntimeException If persistence fails.
	 * @throws \InvalidArgumentException For mismatched consumers.
	 * @throws \LengthException When the consumer exceeds its budget.
	 * @throws \Throwable Rethrows the original failure after rollback.
	 * @param int         $run_id Active run.
	 * @param string      $adapter Adapter identity.
	 * @param string      $type Consumer type.
	 * @param string      $key Consumer identity.
	 * @param Reference[] $references Complete extracted references.
	 */
	public function reconcile( $run_id, $adapter, $type, $key, array $references ) {
		if ( count( $references ) > 5000 ) {
			throw new \LengthException( 'Consumer exceeds the reference budget.' );
		}
		foreach ( $references as $reference ) {
			$fields = $reference->to_array();
			if ( $adapter !== $fields['adapter_id'] || $type !== $fields['consumer_type'] || $key !== $fields['consumer_key'] ) {
				throw new \InvalidArgumentException( 'Reference belongs to another consumer.' );
			}
		}
		$table = $this->db->prefix . 'mdm_references';
		$runs  = $this->db->prefix . 'mdm_scan_runs';
		$this->check( $this->db->query( 'START TRANSACTION' ) );
		try {
			// Lock the run to reject writes to failed/completed scans.
			$status = $this->db->get_var( $this->db->prepare( 'SELECT status FROM %i WHERE id = %d FOR UPDATE', $runs, $run_id ) );
			if ( 'running' !== $status ) {
				throw new \RuntimeException( 'Scan is not running.' );
			}
			$existing = $this->db->get_results( $this->db->prepare( 'SELECT reference_key, first_seen_gmt FROM %i WHERE adapter_id = %s AND consumer_type = %s AND consumer_key = %s FOR UPDATE', $table, $adapter, $type, $key ), 'OBJECT_K' );
			if ( $this->db->last_error ) {
				throw new \RuntimeException( 'Reference lookup failed.' );
			}
			$this->check(
				$this->db->delete(
					$table,
					array(
						'adapter_id'    => $adapter,
						'consumer_type' => $type,
						'consumer_key'  => $key,
					)
				)
			);
			$unique = array();
			foreach ( $references as $reference ) {
				$row                             = $reference->to_array();
				$unique[ $row['reference_key'] ] = $row;
			}
			foreach ( $unique as $identity => $row ) {
				$row['scan_run_id']    = $run_id;
				$row['first_seen_gmt'] = isset( $existing[ $identity ] ) ? $existing[ $identity ]->first_seen_gmt : gmdate( 'Y-m-d H:i:s' );
				$row['last_seen_gmt']  = gmdate( 'Y-m-d H:i:s' );
				$this->check( $this->db->insert( $table, $row ) );
			}
			$this->check( $this->db->query( 'COMMIT' ) );
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Close a run. Diagnostics are codes, never raw database errors.
	 *
	 * @param int  $run_id Run ID.
	 * @param bool $success Whether all requested consumers succeeded.
	 */
	public function finish_run( $run_id, $success ) {
		$this->check(
			$this->db->update(
				$this->db->prefix . 'mdm_scan_runs',
				array(
					'status'        => $success ? 'complete' : 'failed',
					'completed_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'error_code'    => $success ? '' : 'consumer_scan_failed',
				),
				array(
					'id'     => $run_id,
					'status' => 'running',
				)
			)
		);
	}

	/**
	 * Retrieve one attachment's bounded index page. Caller owns authorization.
	 *
	 * @throws \RuntimeException If lookup fails.
	 * @param int $attachment_id Attachment ID.
	 * @param int $after_id Keyset cursor.
	 * @return array<object>
	 */
	public function for_attachment( $attachment_id, $after_id = 0 ) {
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE attachment_id = %d AND id > %d ORDER BY id ASC LIMIT 100', $this->db->prefix . 'mdm_references', $attachment_id, $after_id ) );
		if ( $this->db->last_error ) {
			throw new \RuntimeException( 'Reference lookup failed.' );
		}
		return $rows;
	}

	/**
	 * Fail closed on database errors.
	 *
	 * @throws \RuntimeException If the database reports failure.
	 * @param int|bool $result Database result.
	 */
	private function check( $result ) {
		if ( false === $result ) {
			throw new \RuntimeException( 'Media Dependency Map database write failed.' );
		}
	}
}
