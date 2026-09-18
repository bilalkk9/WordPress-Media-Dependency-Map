<?php
/**
 * Validated reference value.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Domain;

defined( 'ABSPATH' ) || exit;

/** Describes one logical reference without retaining its raw value. */
final class Reference {

	/**
	 * Validated fields.
	 *
	 * @var array<string, mixed>
	 */
	private $fields;

	/**
	 * Create a reference with explicit writer eligibility.
	 *
	 * @throws \InvalidArgumentException For invalid reference fields.
	 * @param string   $adapter Adapter identifier.
	 * @param string   $consumer_type WordPress object type.
	 * @param string   $consumer_key Stable object identity.
	 * @param string   $path Logical location.
	 * @param string   $kind Representation type.
	 * @param int|null $attachment_id Resolved attachment or null.
	 * @param string   $confidence Exact, strong, heuristic or unresolved.
	 * @param string   $raw_value Value to fingerprint; never retained.
	 * @param bool     $replaceable Whether a verified writer supports this location.
	 */
	public function __construct( $adapter, $consumer_type, $consumer_key, $path, $kind, $attachment_id, $confidence, $raw_value, $replaceable = false ) {
		foreach ( array( $adapter, $consumer_type, $kind ) as $identifier ) {
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,63}$/D', $identifier ) ) {
				throw new \InvalidArgumentException( 'Invalid reference identifier.' );
			}
		}
		if ( '' === $consumer_key || strlen( $consumer_key ) > 191 || strlen( $path ) > 2048 ) {
			throw new \InvalidArgumentException( 'Invalid reference location.' );
		}
		if ( ! in_array( $confidence, array( 'exact', 'strong', 'heuristic', 'unresolved' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid confidence.' );
		}
		if ( ( null !== $attachment_id && $attachment_id < 1 ) || ( in_array( $confidence, array( 'exact', 'strong' ), true ) && null === $attachment_id ) ) {
			throw new \InvalidArgumentException( 'Confirmed references require an attachment.' );
		}
		if ( 'unresolved' === $confidence && null !== $attachment_id ) {
			throw new \InvalidArgumentException( 'Unresolved references cannot identify an attachment.' );
		}
		$identity     = array( $adapter, $consumer_type, $consumer_key, $path, $kind, $attachment_id );
		$this->fields = array(
			'reference_key'  => hash( 'sha256', (string) wp_json_encode( $identity ) ),
			'adapter_id'     => $adapter,
			'consumer_type'  => $consumer_type,
			'consumer_key'   => $consumer_key,
			'data_path'      => $path,
			'reference_kind' => $kind,
			'attachment_id'  => $attachment_id,
			'confidence'     => $confidence,
			'value_hash'     => hash( 'sha256', $raw_value ),
			'replaceability' => $replaceable && 'exact' === $confidence ? 'replaceable' : 'read-only',
		);
	}

	/**
	 * Return a detached copy for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return $this->fields;
	}

	/**
	 * Describe an indirect, read-only occurrence through a synced pattern.
	 *
	 * @throws \LengthException If the logical path exceeds the storage budget.
	 * @param int    $post_id Referencing post.
	 * @param string $prefix Pattern path.
	 * @return self
	 */
	public function through_pattern( $post_id, $prefix ) {
		$copy                           = clone $this;
		$copy->fields['replaceability'] = 'read-only';
		$copy->fields['consumer_key']   = (string) $post_id;
		$copy->fields['data_path']      = $prefix . '/' . $this->fields['data_path'];
		if ( strlen( $copy->fields['data_path'] ) > 2048 ) {
			throw new \LengthException( 'Pattern path exceeds the reference budget.' );
		}
		$copy->fields['reference_key'] = hash( 'sha256', (string) wp_json_encode( array( $copy->fields['adapter_id'], 'post', (string) $post_id, $copy->fields['data_path'], $copy->fields['reference_kind'], $copy->fields['attachment_id'] ) ) );
		return $copy;
	}
}
