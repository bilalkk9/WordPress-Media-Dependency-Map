<?php
/**
 * Allowlisted site identity extraction.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Matching\Resolver;

defined( 'ABSPATH' ) || exit;

/** Does not enumerate arbitrary options or theme settings. */
final class Site_Identity implements Adapter {

	/**
	 * Resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * Construct the adapter.
	 *
	 * @param Resolver $resolver Media resolver.
	 */
	public function __construct( Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Stable identity.
	 *
	 * @return string
	 */
	public function id() {
		return 'site-identity';
	}

	/**
	 * Read recognized site settings only.
	 *
	 * @throws \RuntimeException When stored widgets exceed the inspection budget.
	 * @param int $consumer_id Unused; settings belong to the current site.
	 * @return Reference[]
	 */
	public function scan( $consumer_id ) {
		$values  = array(
			'site_icon'        => array( 'id', get_option( 'site_icon' ) ),
			'custom_logo'      => array( 'id', get_theme_mod( 'custom_logo' ) ),
			'header_image'     => array( 'url', get_theme_mod( 'header_image' ) ),
			'background_image' => array( 'url', get_theme_mod( 'background_image' ) ),
		);
		$widgets = get_option( 'widget_media_image', array() );
		if ( is_array( $widgets ) ) {
			if ( count( $widgets ) > 500 ) {
				throw new \RuntimeException( 'Image widgets exceed the inspection budget.' );
			}
			foreach ( $widgets as $number => $widget ) {
				if ( ! is_numeric( $number ) || ! is_array( $widget ) ) {
					continue;
				}
				foreach ( array(
					'attachment_id' => 'id',
					'url'           => 'url',
					'link_url'      => 'url',
				) as $field => $kind ) {
					if ( isset( $widget[ $field ] ) ) {
						$values[ 'widget_media_image/' . $number . '/' . $field ] = array( $kind, $widget[ $field ] );
					}
				}
			}
		}
		$refs = array();
		foreach ( $values as $path => $candidate ) {
			list( $kind, $value ) = $candidate;
			if ( ! is_scalar( $value ) || ! $value ) {
				continue;
			}
			if ( 'url' === $kind && null === Resolver::upload_path( (string) $value, wp_get_upload_dir()['baseurl'] ) ) {
				continue;
			}
			$id     = 'id' === $kind ? $this->resolver->id( $value ) : $this->resolver->url( (string) $value );
			$refs[] = new Reference( $this->id(), 'site', (string) get_current_blog_id(), $path, $kind, $id, null === $id ? 'unresolved' : ( 'id' === $kind ? 'exact' : 'strong' ), (string) $value );
		}
		return $refs;
	}
}
