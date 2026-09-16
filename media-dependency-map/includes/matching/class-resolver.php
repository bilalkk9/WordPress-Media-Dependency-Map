<?php
/**
 * Conservative local attachment resolution.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Matching;

use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;

defined( 'ABSPATH' ) || exit;

/** Maps only known upload URLs and schema-owned IDs. */
final class Resolver {

	/**
	 * Attachment lookup repository.
	 *
	 * @var Attachment_Repository
	 */
	private $attachments;

	/**
	 * Construct the resolver.
	 *
	 * @param Attachment_Repository $attachments Storage reader.
	 */
	public function __construct( Attachment_Repository $attachments ) {
		$this->attachments = $attachments;
	}

	/**
	 * Resolve a value with an explicit attachment-ID schema.
	 *
	 * @param mixed $value Candidate.
	 * @return int|null
	 */
	public function id( $value ) {
		if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
			return null;
		}
		$id = (int) $value;
		return 'attachment' === get_post_type( $id ) ? $id : null;
	}

	/**
	 * Map a URL to a unique attachment, including registered image sizes.
	 *
	 * @param string $url Candidate URL.
	 * @return int|null
	 */
	public function url( $url ) {
		$uploads = wp_get_upload_dir();
		$path    = self::upload_path( $url, $uploads['baseurl'] );
		return null === $path ? null : $this->attachments->resolve_path( $path );
	}

	/**
	 * Normalize within the current uploads origin; never infer CDN ownership.
	 *
	 * @param string $url Candidate URL.
	 * @param string $base Uploads base URL.
	 * @return string|null Upload-relative path, or null outside the known origin.
	 */
	public static function upload_path( $url, $base ) {
		$url        = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$base_parts = wp_parse_url( $base );
		if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) ) {
			return null;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = ( $base_parts['scheme'] ?? 'https' ) . '://' . $base_parts['host'] . ( isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '' ) . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'], $parts['path'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( strtolower( $parts['host'] ) !== strtolower( $base_parts['host'] ) || ( $parts['port'] ?? null ) !== ( $base_parts['port'] ?? null ) ) {
			return null;
		}
		$path = rawurldecode( $parts['path'] );
		if ( preg_match( '/[\x00-\x1F\\\\]/', $path ) ) {
			return null;
		}
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$segments[] = $segment;
			}
		}
		$path   = '/' . implode( '/', $segments );
		$prefix = rtrim( rawurldecode( $base_parts['path'] ?? '' ), '/' ) . '/';
		if ( 0 !== strpos( $path, $prefix ) || strlen( $path ) <= strlen( $prefix ) ) {
			return null;
		}
		return substr( $path, strlen( $prefix ) );
	}
}
