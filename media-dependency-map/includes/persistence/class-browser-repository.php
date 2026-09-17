<?php
/**
 * Bounded read models for administrator dependency browsing.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Persistence;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads custom index tables; caller enforces site-administrator access.

/** Queries the published generation only. */
final class Browser_Repository {

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Construct the read model.
	 *
	 * @param \wpdb $db Site connection.
	 */
	public function __construct( \wpdb $db ) {
		$this->db = $db; }

	/**
	 * Summarize freshness without confusing old or partial data with current coverage.
	 *
	 * @return array
	 */
	public function status() {
		$store   = new Scan_Repository( $this->db );
		$control = $store->control();
		$run     = $control->last_run ? $store->run( (int) $control->last_run ) : null;
		$queued  = $store->queue_count();
		return array(
			'generation'   => (int) $control->active_generation,
			'active_run'   => (int) $control->active_run,
			'queued'       => $queued,
			'run'          => $run,
			'worker_error' => (bool) get_option( 'mdm_worker_error' ),
			'current'      => $control->active_generation && ! $control->active_run && ! $queued && $run && 'complete' === $run->status && ! get_option( 'mdm_worker_error' ),
		);
	}

	/**
	 * Read one attachment page and one lookahead record.
	 *
	 * @param array $filters Validated UI filters.
	 * @param int   $offset Pagination offset.
	 * @param int   $limit Maximum returned rows.
	 * @return object[]
	 */
	public function attachments( array $filters = array(), $offset = 0, $limit = 21 ) {
		$generation = (int) ( new Scan_Repository( $this->db ) )->control()->active_generation;
		$parameters = array( $this->db->posts, $this->db->prefix . 'mdm_references', $generation );
		$where      = "p.post_type = 'attachment'";
		$search     = $filters['search'] ?? '';
		if ( '' !== $search ) {
			$like       = '%' . $this->db->esc_like( $search ) . '%';
			$where     .= ' AND (p.post_title LIKE %s OR p.ID = %d OR p.post_mime_type LIKE %s OR EXISTS (SELECT 1 FROM %i m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE %s) OR EXISTS (SELECT 1 FROM %i rr JOIN %i owner ON owner.ID = CAST(rr.consumer_key AS UNSIGNED) WHERE rr.generation = %d AND rr.attachment_id = p.ID AND rr.consumer_type = %s AND owner.post_title LIKE %s))';
			$parameters = array_merge( $parameters, array( $like, absint( $search ), $like, $this->db->postmeta, '_wp_attached_file', $like, $this->db->prefix . 'mdm_references', $this->db->posts, $generation, 'post', $like ) );
		}
		if ( ! empty( $filters['mime'] ) && in_array( $filters['mime'], array( 'image', 'audio', 'video', 'application' ), true ) ) {
			$where       .= ' AND p.post_mime_type LIKE %s';
			$parameters[] = $filters['mime'] . '/%';
		}
		foreach ( array(
			'after'  => '>=',
			'before' => '<=',
		) as $key => $operator ) {
			if ( ! empty( $filters[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $filters[ $key ] ) ) {
				$where       .= " AND p.post_date $operator %s";
				$parameters[] = $filters[ $key ] . ( 'after' === $key ? ' 00:00:00' : ' 23:59:59' );
			}
		}
		$having = '';
		if ( 'used' === ( $filters['usage'] ?? '' ) ) {
			$having = ' HAVING COUNT(r.id) > 0'; }
		if ( 'none' === ( $filters['usage'] ?? '' ) ) {
			$having = ' HAVING COUNT(r.id) = 0'; }
		$sorts        = array(
			'date'    => 'p.post_date',
			'title'   => 'p.post_title',
			'usage'   => 'usage_count',
			'scanned' => 'last_seen',
		);
		$sort         = $sorts[ $filters['sort'] ?? 'date' ] ?? 'p.post_date';
		$order        = 'asc' === ( $filters['order'] ?? '' ) ? 'ASC' : 'DESC';
		$parameters[] = max( 1, min( 10001, $limit ) );
		$parameters[] = max( 0, min( 1000000, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE contains placeholders only; sort/order/having are closed allowlists.
		$sql  = $this->db->prepare( "SELECT p.ID, p.post_title, p.post_mime_type, p.post_date, COUNT(r.id) AS usage_count, MAX(r.last_seen_gmt) AS last_seen FROM %i p LEFT JOIN %i r ON r.attachment_id = p.ID AND r.generation = %d WHERE $where GROUP BY p.ID, p.post_title, p.post_mime_type, p.post_date $having ORDER BY $sort $order, p.ID $order LIMIT %d OFFSET %d", $parameters );
		$rows = $this->db->get_results( $sql );
		$this->check();
		return $rows;
	}

	/**
	 * Read a dependency page, or unresolved dependencies when attachment ID is zero.
	 *
	 * @param int    $attachment_id Attachment or zero for unresolved.
	 * @param int    $offset Offset.
	 * @param string $confidence Optional confidence filter.
	 * @param string $adapter Optional adapter filter.
	 * @return object[]
	 */
	public function references( $attachment_id, $offset = 0, $confidence = '', $adapter = '' ) {
		$generation = (int) ( new Scan_Repository( $this->db ) )->control()->active_generation;
		$parameters = array( $this->db->prefix . 'mdm_references', $generation );
		$where      = 'attachment_id IS NULL';
		if ( $attachment_id ) {
			$where        = 'attachment_id = %d';
			$parameters[] = $attachment_id; }
		if ( in_array( $confidence, array( 'exact', 'strong', 'heuristic', 'unresolved' ), true ) ) {
			$where       .= ' AND confidence = %s';
			$parameters[] = $confidence; }
		if ( in_array( $adapter, array( 'core', 'site-identity' ), true ) ) {
			$where       .= ' AND adapter_id = %s';
			$parameters[] = $adapter; }
		$parameters[] = max( 0, min( 1000000, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE is built only from fixed predicates with prepared values.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM %i WHERE generation = %d AND $where ORDER BY id ASC LIMIT 51 OFFSET %d", $parameters ) );
		$this->check();
		return $rows;
	}

	/**
	 * Fetch usage counts for a single Media Library page in one query.
	 *
	 * @param int[] $ids Visible attachment IDs.
	 * @return array<int, int>
	 */
	public function counts( array $ids ) {
		$ids = array_slice( array_map( 'intval', $ids ), 0, 500 );
		if ( ! $ids ) {
			return array(); }
		$generation   = (int) ( new Scan_Repository( $this->db ) )->control()->active_generation;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list contains only %d tokens.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT attachment_id, COUNT(*) AS count FROM %i WHERE generation = %d AND attachment_id IN ($placeholders) GROUP BY attachment_id", array_merge( array( $this->db->prefix . 'mdm_references', $generation ), $ids ) ) );
		$this->check();
		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row->attachment_id ] = (int) $row->count; }
		return $counts;
	}

	/**
	 * Fail closed on read failures.
	 *
	 * @throws \RuntimeException If a query failed.
	 */
	private function check() {
		if ( $this->db->last_error ) {
			throw new \RuntimeException( 'Index lookup failed.' ); } }
}
