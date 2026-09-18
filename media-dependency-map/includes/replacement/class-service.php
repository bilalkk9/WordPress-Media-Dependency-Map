<?php
/**
 * Journaled, optimistic featured-image replacement.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Replacement;

use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Browser_Repository;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Transactional journal and uncached concurrency checks.

/** Restricts writes to independently verifiable core thumbnail metadata. */
final class Service {
	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;
	/**
	 * Coordination repository.
	 *
	 * @var Scan_Repository
	 */
	private $store;
	/**
	 * Compose the service.
	 *
	 * @param \wpdb $db Database.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->store = new Scan_Repository( $db ); }
	/**
	 * Require replacement privileges.
	 *
	 * @throws \RuntimeException When unauthorized.
	 */
	public static function authorize() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'mdm_replace_references' ) ) {
			throw new \RuntimeException( 'Replacement is not authorized.' ); }
	}
	/**
	 * Restrict supported mutation locations.
	 *
	 * @param object $row Indexed reference.
	 * @return bool
	 */
	public static function supported( $row ) {
		return 'core' === $row->adapter_id && 'post' === $row->consumer_type && 'featured_image' === $row->data_path && 'exact' === $row->confidence && ! in_array( get_post_type( (int) $row->consumer_key ), array( 'product', 'product_variation', 'attachment', 'revision' ), true );
	}
	/**
	 * Check both attachments and reject incompatible targets.
	 *
	 * @param int $source Source.
	 * @param int $target Target.
	 * @throws \RuntimeException For invalid attachments.
	 */
	private function attachments( $source, $target ) {
		self::authorize();
		if ( $source === $target || ! wp_attachment_is_image( $source ) || ! wp_attachment_is_image( $target ) || ! current_user_can( 'edit_post', $source ) || ! current_user_can( 'edit_post', $target ) ) {
			throw new \RuntimeException( 'Choose two different image attachments you can edit.' ); }
	}
	/**
	 * Persist a bounded dry run without changing consumers.
	 *
	 * @param int $source Source attachment.
	 * @param int $target Target attachment.
	 * @throws \RuntimeException For stale coverage or excessive references.
	 * @return int Operation ID.
	 */
	public function preview( $source, $target ) {
		$this->attachments( $source, $target );
		$token = $this->store->acquire();
		if ( ! $token ) {
			throw new \RuntimeException( 'A worker is busy. Try again.' ); }
		try {
			return $this->store->atomic(
				$token,
				function () use ( $source, $target ) {
					if ( ! ( new Browser_Repository( $this->db ) )->status()['current'] ) {
						throw new \RuntimeException( 'Complete a full scan and pending changes before previewing replacement.' ); }
					$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE generation = %d AND attachment_id = %d LIMIT 201', $this->db->prefix . 'mdm_references', $this->store->control()->active_generation, $source ) );
					if ( $this->db->last_error || count( $rows ) > 200 ) {
						throw new \RuntimeException( 'Preview requires at most 200 indexed occurrences.' ); }
					$this->check(
						$this->db->insert(
							$this->db->prefix . 'mdm_operations',
							array(
								'user_id'     => get_current_user_id(),
								'source_id'   => $source,
								'target_id'   => $target,
								'status'      => 'preview',
								'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
							)
						)
					);
					$operation = (int) $this->db->insert_id;
					foreach ( $rows as $row ) {
						$eligible = self::supported( $row ) && current_user_can( 'edit_post', (int) $row->consumer_key );
						$this->check(
							$this->db->insert(
								$this->db->prefix . 'mdm_operation_items',
								array(
									'operation_id'  => $operation,
									'adapter_id'    => $row->adapter_id,
									'consumer_type' => $row->consumer_type,
									'consumer_key'  => $row->consumer_key,
									'data_path'     => $row->data_path,
									'before_hash'   => hash( 'sha256', (string) $source ),
									'after_hash'    => hash( 'sha256', (string) $target ),
									'before_state'  => '',
									'status'        => $eligible ? 'planned' : 'read-only',
									'error_code'    => $eligible ? '' : 'unsupported_writer',
								)
							)
						);
					}
					return $operation;
				}
			);
		} finally {
			$this->store->release( $token ); }
	}
	/**
	 * Read an operation and its bounded items.
	 *
	 * @param int $id Operation ID.
	 * @throws \RuntimeException For missing operations.
	 * @return array
	 */
	public function report( $id ) {
		self::authorize();
		$operation = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d', $this->db->prefix . 'mdm_operations', $id ) );
		if ( ! $operation ) {
			throw new \RuntimeException( 'Operation is unavailable or expired.' ); }
		$items = $this->db->get_results( $this->db->prepare( 'SELECT * FROM %i WHERE operation_id = %d ORDER BY id LIMIT 201', $this->db->prefix . 'mdm_operation_items', $id ) );
		return array( $operation, $items );
	}
	/**
	 * Apply or roll back up to ten journaled items per request.
	 *
	 * @param int  $id Operation ID.
	 * @param bool $rollback Whether to restore source references.
	 * @throws \RuntimeException For invalid operations or unavailable locks.
	 */
	public function execute( $id, $rollback = false ) {
		list( $operation, $items ) = $this->report( $id );
		$this->attachments( (int) $operation->source_id, (int) $operation->target_id );
		if ( ! $rollback && strtotime( $operation->created_gmt . ' UTC' ) < time() - 1800 ) {
			throw new \RuntimeException( 'Preview expired. Create a new preview; applied items can still be rolled back.' ); }
		foreach ( array( $this->db->posts, $this->db->postmeta ) as $table_name ) {
			$table_status = $this->db->get_row( $this->db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table_name ) );
            // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL metadata.
			if ( ! $table_status || 'InnoDB' !== $table_status->Engine ) {
				throw new \RuntimeException( 'Replacement requires transactional WordPress tables.' ); }
		}
		$token = $this->store->acquire();
		if ( ! $token ) {
			throw new \RuntimeException( 'A worker is busy. Try again.' ); }
		try {
			$processed = 0;
			foreach ( $items as $item ) {
				if ( ++$processed > 200 ) {
					break; }
				if ( ! in_array( $item->status, $rollback ? array( 'applied', 'rollback-blocked' ) : array( 'planned' ), true ) ) {
					continue; }
				if ( ! isset( $budget ) ) {
					$budget = 0; }
				if ( ++$budget > 10 ) {
					break; }
				try {
					$this->store->atomic(
						$token,
						function () use ( $item, $operation, $rollback ) {
							$fresh = $this->db->get_row( $this->db->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', $this->db->prefix . 'mdm_operation_items', $item->id ) );
							if ( ! $fresh || ! in_array( $fresh->status, $rollback ? array( 'applied', 'rollback-blocked' ) : array( 'planned' ), true ) ) {
								return; }
							$this->db->get_results( $this->db->prepare( 'SELECT ID FROM %i WHERE ID IN (%d, %d) ORDER BY ID FOR UPDATE', $this->db->posts, $operation->source_id, $operation->target_id ) );
							clean_post_cache( (int) $operation->source_id );
							clean_post_cache( (int) $operation->target_id );
							$this->attachments( (int) $operation->source_id, (int) $operation->target_id );
							$post_id = (int) $item->consumer_key;
							$post    = $this->db->get_row( $this->db->prepare( 'SELECT ID, post_type FROM %i WHERE ID = %d FOR UPDATE', $this->db->posts, $post_id ) );
							if ( ! $post || in_array( $post->post_type, array( 'product', 'product_variation', 'attachment', 'revision' ), true ) || ! current_user_can( 'edit_post', $post_id ) || 'core' !== $item->adapter_id || 'featured_image' !== $item->data_path ) {
								throw new \RuntimeException( 'conflict' ); }
							$values   = $this->db->get_col( $this->db->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s FOR UPDATE', $this->db->postmeta, $post_id, '_thumbnail_id' ) );
							$expected = $rollback ? $item->after_hash : $item->before_hash;
							if ( 1 !== count( $values ) || ! hash_equals( $expected, hash( 'sha256', $values[0] ) ) ) {
								throw new \RuntimeException( 'conflict' ); }
							$value = (int) ( $rollback ? $operation->source_id : $operation->target_id );
							wp_cache_delete( $post_id, 'post_meta' );
							update_post_meta( $post_id, '_thumbnail_id', $value, $values[0] );
							$actual = $this->db->get_col( $this->db->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s', $this->db->postmeta, $post_id, '_thumbnail_id' ) );
							if ( array( (string) $value ) !== $actual ) {
								throw new \RuntimeException( 'verification_failed' ); }
							$this->check(
								$this->db->update(
									$this->db->prefix . 'mdm_operation_items',
									array(
										'status'     => $rollback ? 'rolled-back' : 'applied',
										'error_code' => '',
									),
									array( 'id' => $item->id )
								)
							);
						}
					);
				} catch ( \Throwable $error ) {
					$this->check(
						$this->db->update(
							$this->db->prefix . 'mdm_operation_items',
							array(
								'status'     => $rollback ? 'rollback-blocked' : 'conflict',
								'error_code' => 'changed_or_unavailable',
							),
							array( 'id' => $item->id )
						)
					);
				} finally {
					clean_post_cache( (int) $item->consumer_key ); }
			}
			$this->check(
				$this->db->update(
					$this->db->prefix . 'mdm_operations',
					array(
						'status'        => $rollback ? 'rollback' : 'processed',
						'completed_gmt' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => $id )
				)
			);
		} finally {
			$this->store->release( $token ); }
	}
	/**
	 * Validate database writes.
	 *
	 * @param mixed $result Database result.
	 * @throws \RuntimeException For persistence failures.
	 */
	private function check( $result ) {
		if ( false === $result || $this->db->last_error ) {
			throw new \RuntimeException( 'Journal storage failed.' ); } }
}
