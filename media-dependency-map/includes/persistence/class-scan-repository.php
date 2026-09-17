<?php
/**
 * Scan state, leases, queues and generation publication.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Persistence;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This class owns uncached scan coordination tables.

/** Provides fenced transactions and bounded keyset queries. */
final class Scan_Repository {

	/**
	 * Site connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Construct the repository.
	 *
	 * @param \wpdb $db Site connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db; }

	/**
	 * Read the singleton control record.
	 *
	 * @return object
	 */
	public function control() {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = 1', $this->table( 'control' ) ) );
		$this->check( $row );
		return $row;
	}

	/**
	 * Acquire a two-minute worker lease, atomically reclaiming an expired lease.
	 *
	 * @return string|null Ownership token or null when another worker is active.
	 */
	public function acquire() {
		$token = wp_generate_uuid4();
		$this->check( $this->db->query( $this->db->prepare( 'INSERT IGNORE INTO %i (name,token,expires_gmt) VALUES (%s,%s,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND))', $this->table( 'locks' ), 'worker', $token ) ) );
		$this->check( $this->db->query( $this->db->prepare( 'UPDATE %i SET token = %s, expires_gmt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND) WHERE name = %s AND expires_gmt < UTC_TIMESTAMP()', $this->table( 'locks' ), $token, 'worker' ) ) );
		$owner = $this->db->get_var( $this->db->prepare( 'SELECT token FROM %i WHERE name = %s', $this->table( 'locks' ), 'worker' ) );
		return $owner === $token ? $token : null;
	}

	/**
	 * Release only this worker's lease.
	 *
	 * @param string $token Ownership token.
	 */
	public function release( $token ) {
		$this->check(
			$this->db->delete(
				$this->table( 'locks' ),
				array(
					'name'  => 'worker',
					'token' => $token,
				)
			)
		);
	}

	/**
	 * Fence mutations with a locked lease row, renewing it before committing.
	 *
	 * @throws \RuntimeException When ownership has expired or been lost.
	 * @throws \Throwable Rethrows failures after rollback.
	 * @param string   $token Ownership token.
	 * @param callable $callback Transaction body.
	 * @return mixed
	 */
	public function atomic( $token, callable $callback ) {
		$this->check( $this->db->query( 'START TRANSACTION' ) );
		try {
			$owner = $this->db->get_var( $this->db->prepare( 'SELECT token FROM %i WHERE name = %s AND expires_gmt >= UTC_TIMESTAMP() FOR UPDATE', $this->table( 'locks' ), 'worker' ) );
			if ( $owner !== $token ) {
				throw new \RuntimeException( 'Scan lease lost.' ); }
			$result = $callback();
			$this->check( $this->db->query( $this->db->prepare( 'UPDATE %i SET expires_gmt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 120 SECOND) WHERE name = %s AND token = %s', $this->table( 'locks' ), 'worker', $token ) ) );
			$this->check( $this->db->query( 'COMMIT' ) );
			return $result;
		} catch ( \Throwable $error ) {
			$this->db->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Start a scan inside a fenced transaction.
	 *
	 * @param int   $user_id Initiating user.
	 * @param array $adapters Adapter identities.
	 * @return int Run ID.
	 */
	public function start( $user_id, array $adapters ) {
		$control = $this->control();
		if ( $control->active_run ) {
			return (int) $control->active_run; }
		$max = $this->db->get_var( $this->db->prepare( 'SELECT COALESCE(MAX(ID),0) FROM %i', $this->db->posts ) );
		$this->check( $max );
		$state = array(
			'user_id'     => $user_id,
			'phase'       => 'paths',
			'adapter'     => 0,
			'adapters'    => $adapters,
			'cursor'      => 0,
			'max_id'      => (int) $max,
			'max_ids'     => array(
				'term'    => $this->ceiling( 'term' ),
				'user'    => $this->ceiling( 'user' ),
				'comment' => $this->ceiling( 'comment' ),
			),
			'sequence'    => (int) $control->revision,
			'processed'   => 0,
			'error_count' => 0,
			'errors'      => array(),
		);
		$this->check(
			$this->db->insert(
				$this->table( 'scan_runs' ),
				array(
					'type'        => 'full',
					'status'      => 'running',
					'started_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'state_json'  => wp_json_encode( $state ),
				)
			)
		);
		$id = (int) $this->db->insert_id;
		$this->check(
			$this->db->update(
				$this->table( 'control' ),
				array(
					'active_run' => $id,
					'last_run'   => $id,
					'owner_id'   => $user_id,
				),
				array( 'id' => 1 )
			)
		);
		return $id;
	}

	/**
	 * Fetch a run with decoded bounded state.
	 *
	 * @throws \RuntimeException For invalid state.
	 * @param int $id Run ID.
	 * @return object|null
	 */
	public function run( $id ) {
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table( 'scan_runs' ), $id ) );
		if ( $row ) {
			$row->state = json_decode( $row->state_json, true );
			if ( ! is_array( $row->state ) ) {
				throw new \RuntimeException( 'Invalid scan state.' ); }
		}
		return $row;
	}

	/**
	 * Save a checkpoint inside the same transaction as the snapshot.
	 *
	 * @throws \LengthException For oversized state.
	 * @param int   $id Run ID.
	 * @param array $state Checkpoint.
	 */
	public function checkpoint( $id, array $state ) {
		$json = wp_json_encode( $state );
		if ( false === $json || strlen( $json ) > 32000 ) {
			throw new \LengthException( 'Scan state exceeds its budget.' ); }
		$this->check(
			$this->db->update(
				$this->table( 'scan_runs' ),
				array( 'state_json' => $json ),
				array(
					'id'     => $id,
					'status' => 'running',
				)
			)
		);
	}

	/**
	 * Publish only successful generations, retaining the old index on failure.
	 *
	 * @param int   $id Run ID.
	 * @param array $state Final checkpoint.
	 */
	public function finish( $id, array $state ) {
		$success = 0 === $state['error_count'];
		$this->checkpoint( $id, $state );
		$this->check(
			$this->db->update(
				$this->table( 'scan_runs' ),
				array(
					'status'        => $success ? 'complete' : 'failed',
					'completed_gmt' => gmdate( 'Y-m-d H:i:s' ),
					'error_code'    => $success ? '' : 'partial_coverage',
				),
				array( 'id' => $id )
			)
		);
		$values = array( 'active_run' => 0 );
		if ( $success ) {
			$values['active_generation'] = $id;
			$this->check( $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE sequence <= %d', $this->table( 'queue' ), $state['sequence'] ) ) );
		} else {
			$this->check( $this->db->query( $this->db->prepare( "UPDATE %i SET status = 'failed' WHERE sequence <= %d", $this->table( 'queue' ), $state['sequence'] ) ) );
		}
		$this->check(
			$this->db->update(
				$this->table( 'control' ),
				$values,
				array(
					'id'         => 1,
					'active_run' => $id,
				)
			)
		);
	}

	/**
	 * Read one bounded keyset page of source IDs.
	 *
	 * @param string $source Source kind.
	 * @param int    $cursor Last ID.
	 * @param int    $max Maximum ID at start.
	 * @return int[]
	 */
	public function page( $source, $cursor, $max ) {
		if ( 'site' === $source ) {
			return 0 === $cursor ? array( get_current_blog_id() ) : array(); }
		$object = $this->object_table( $source );
		if ( $object ) {
			$ids = $this->db->get_col( $this->db->prepare( 'SELECT %i FROM %i WHERE %i > %d AND %i <= %d ORDER BY %i ASC LIMIT 20', $object[1], $object[0], $object[1], $cursor, $object[1], $max, $object[1] ) );
			$this->check( $ids );
			return array_map( 'intval', $ids );
		}
		$condition = 'attachment' === $source ? "post_type = 'attachment'" : "post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Condition comes exclusively from the two static strings above.
		$ids = $this->db->get_col( $this->db->prepare( "SELECT ID FROM %i WHERE ID > %d AND ID <= %d AND $condition ORDER BY ID ASC LIMIT 20", $this->db->posts, $cursor, $max ) );
		$this->check( $ids );
		return array_map( 'intval', $ids );
	}

	/**
	 * Resolve a closed allowlist of extra WordPress consumer tables.
	 *
	 * @param string $source Consumer source.
	 * @return array
	 */
	private function object_table( $source ) {
		$tables = array(
			'term'    => array( $this->db->terms, 'term_id' ),
			'user'    => array( $this->db->users, 'ID' ),
			'comment' => array( $this->db->comments, 'comment_ID' ),
		);
		return $tables[ $source ] ?? array();
	}

	/**
	 * Snapshot an independent cursor ceiling for each object type.
	 *
	 * @param string $source Consumer source.
	 * @return int
	 */
	private function ceiling( $source ) {
		$object = $this->object_table( $source );
		$max    = $this->db->get_var( $this->db->prepare( 'SELECT COALESCE(MAX(%i),0) FROM %i', $object[1], $object[0] ) );
		$this->check( $max );
		return (int) $max;
	}

	/**
	 * Populate registered attachment paths atomically within the worker checkpoint.
	 *
	 * @throws \LengthException For oversized attachment metadata.
	 * @param int $generation New generation.
	 * @param int $id Attachment ID.
	 */
	public function index_paths( $generation, $id ) {
		$repository = new Attachment_Repository( $this->db );
		$meta       = wp_get_attachment_metadata( $id );
		$paths      = is_array( $meta ) ? $repository->metadata_paths( $meta ) : array();
		$file       = get_post_meta( $id, '_wp_attached_file', true );
		if ( is_string( $file ) && '' !== $file ) {
			$paths[] = $file; }
		if ( count( $paths ) > 1000 ) {
			throw new \LengthException( 'Attachment paths exceed budget.' ); }
		$this->check(
			$this->db->delete(
				$this->table( 'paths' ),
				array(
					'generation'    => $generation,
					'attachment_id' => $id,
				)
			)
		);
		foreach ( array_unique( $paths ) as $path ) {
			$this->check(
				$this->db->insert(
					$this->table( 'paths' ),
					array(
						'generation'    => $generation,
						'attachment_id' => $id,
						'path_hash'     => hash( 'sha256', $path ),
					)
				)
			);
		}
	}

	/**
	 * Debounce a change into one persistent item, without parsing content.
	 *
	 * @param string $source post, site or full.
	 * @param int    $id Consumer ID.
	 */
	public function enqueue( $source, $id ) {
		if ( ! in_array( $source, array( 'post', 'site', 'term', 'user', 'comment', 'full' ), true ) ) {
			return; }
		$this->check( $this->db->query( $this->db->prepare( 'UPDATE %i SET revision = LAST_INSERT_ID(revision + 1) WHERE id = 1', $this->table( 'control' ) ) ) );
		$sequence = (int) $this->db->get_var( 'SELECT LAST_INSERT_ID()' );
		$this->check( $this->db->query( $this->db->prepare( "INSERT INTO %i (item_key,source,consumer_id,sequence,status) VALUES (%s,%s,%d,%d,'pending') ON DUPLICATE KEY UPDATE sequence = GREATEST(sequence,VALUES(sequence)), status = 'pending'", $this->table( 'queue' ), $source . ':' . $id, $source, $id, $sequence ) ) );
	}

	/**
	 * Read the next pending change; path/template changes take priority.
	 *
	 * @return object|null
	 */
	public function next_item() {
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM %i WHERE status = 'pending' ORDER BY (source = 'full') DESC, sequence ASC LIMIT 1", $this->table( 'queue' ) ) );
	}

	/**
	 * Finish only the exact queued version inspected by this worker.
	 *
	 * @param object $item Queued version.
	 * @param bool   $success Result.
	 */
	public function finish_item( $item, $success ) {
		$where = array(
			'item_key' => $item->item_key,
			'sequence' => $item->sequence,
		);
		$this->check( $success ? $this->db->delete( $this->table( 'queue' ), $where ) : $this->db->update( $this->table( 'queue' ), array( 'status' => 'failed' ), $where ) );
	}

	/**
	 * Pending or failed changes prevent an unreferenced claim.
	 *
	 * @return int
	 */
	public function queue_count() {
		$count = $this->db->get_var( $this->db->prepare( 'SELECT COUNT(*) FROM %i', $this->table( 'queue' ) ) );
		$this->check( $count );
		return (int) $count;
	}

	/**
	 * Construct a generation-scoped reference repository.
	 *
	 * @param int $generation Index generation.
	 * @return Reference_Repository
	 */
	public function references( $generation ) {
		return new Reference_Repository( $this->db, $generation ); }

	/**
	 * Remove abandoned generations in small pages, preserving diagnostic generation zero.
	 */
	public function cleanup() {
		$control = $this->control();
		foreach ( array( 'references', 'paths' ) as $suffix ) {
			$this->check( $this->db->query( $this->db->prepare( 'DELETE FROM %i WHERE generation > 0 AND generation <> %d AND generation <> %d LIMIT 500', $this->table( $suffix ), $control->active_generation, $control->active_run ) ) );
		}
	}

	/**
	 * Internal table names are always prepared as identifiers.
	 *
	 * @param string $suffix Internal suffix.
	 * @return string
	 */
	private function table( $suffix ) {
		return $this->db->prefix . 'mdm_' . $suffix; }

	/**
	 * Reject database failures without disclosing raw SQL.
	 *
	 * @throws \RuntimeException On failure.
	 * @param mixed $result Database result.
	 */
	private function check( $result ) {
		if ( false === $result || null === $result || $this->db->last_error ) {
			throw new \RuntimeException( 'Scan database operation failed.' ); }
	}
}
