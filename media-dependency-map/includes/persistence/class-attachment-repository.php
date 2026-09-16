<?php
/**
 * Bounded upload path lookup.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Persistence;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Batch-local attachment lookup against core metadata.

/** Validates generated filenames against registered attachment metadata. */
final class Attachment_Repository {

	/**
	 * Database reader.
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
		$this->db = $db;
	}

	/**
	 * Resolve exact paths and metadata-registered derivatives without guessing suffixes.
	 *
	 * @throws \RuntimeException On database failure or an exhausted candidate budget.
	 * @param string $path Upload-relative path.
	 * @return int|null Unique attachment, null when absent or ambiguous.
	 */
	public function resolve_path( $path ) {
		$ids = $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i m ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND BINARY m.meta_value = %s LIMIT 2', $this->db->posts, $this->db->postmeta, 'attachment', '_wp_attached_file', $path ) );
		$this->check_error();
		// Search only a bounded candidate set; each filename is validated below.
		$candidates = $this->db->get_col( $this->db->prepare( 'SELECT DISTINCT p.ID FROM %i p INNER JOIN %i m ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value LIKE %s LIMIT 51', $this->db->posts, $this->db->postmeta, 'attachment', '_wp_attachment_metadata', '%' . $this->db->esc_like( wp_basename( $path ) ) . '%' ) );
		$this->check_error();
		if ( count( $candidates ) > 50 ) {
			throw new \RuntimeException( 'Attachment metadata lookup exceeded its budget or failed.' );
		}
		foreach ( $candidates as $candidate ) {
			$meta = wp_get_attachment_metadata( (int) $candidate );
			if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
				continue;
			}
			$paths = $this->metadata_paths( $meta );
			if ( in_array( $path, $paths, true ) ) {
				$ids[] = $candidate;
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		return 1 === count( $ids ) ? $ids[0] : null;
	}

	/**
	 * Validate metadata structure, including third-party filtered values.
	 *
	 * @param array $meta Attachment metadata.
	 * @return string[]
	 */
	private function metadata_paths( array $meta ) {
		if ( empty( $meta['file'] ) || ! is_string( $meta['file'] ) ) {
			return array();
		}
		$directory = dirname( $meta['file'] );
		$directory = '.' === $directory ? '' : $directory . '/';
		$paths     = array( $meta['file'] );
		foreach ( $meta['sizes'] ?? array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
				$paths[] = $directory . $size['file'];
			}
		}
		if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
			$paths[] = $directory . $meta['original_image'];
		}
		return $paths;
	}

	/**
	 * Check the most recent database operation.
	 *
	 * @throws \RuntimeException On database errors.
	 */
	private function check_error() {
		if ( $this->db->last_error ) {
			throw new \RuntimeException( 'Attachment lookup failed.' );
		}
	}
}
