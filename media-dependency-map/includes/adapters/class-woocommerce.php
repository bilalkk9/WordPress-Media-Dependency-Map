<?php
/**
 * WooCommerce product and category media through public read APIs.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Matching\Resolver;

defined( 'ABSPATH' ) || exit;

/** Includes variations without treating inherited fallback images as stored references. */
final class Woocommerce implements Adapter {
	/**
	 * Resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;
	/**
	 * Source context.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Compose an adapter.
	 *
	 * @param Resolver $resolver Resolver.
	 * @param string   $source Post or term context.
	 */
	public function __construct( Resolver $resolver, $source = 'post' ) {
		$this->resolver = $resolver;
		$this->source   = $source;
	}

	/**
	 * Check supported API availability.
	 *
	 * @return bool
	 */
	public static function available() {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.0', '>=' ) && version_compare( WC_VERSION, '12.0', '<' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Return adapter identity.
	 *
	 * @return string
	 */
	public function id() {
		return 'woocommerce-' . $this->source; }

	/**
	 * Extract a supported product or category.
	 *
	 * @param int $consumer_id Object ID.
	 * @throws \RuntimeException For unsupported APIs, permissions or oversized collections.
	 * @return Reference[]
	 */
	public function scan( $consumer_id ) {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'WooCommerce is unavailable.' ); }
		$values = array();
		if ( 'term' === $this->source ) {
			$term = get_term( $consumer_id );
			if ( ! $term || is_wp_error( $term ) || 'product_cat' !== $term->taxonomy ) {
				return array(); }
			if ( ! current_user_can( 'edit_term', $consumer_id ) ) {
				throw new \RuntimeException( 'Category is not authorized.' ); }
			$values['thumbnail_id'] = array( 'id', get_term_meta( $consumer_id, 'thumbnail_id', true ) );
		} else {
			if ( ! in_array( get_post_type( $consumer_id ), array( 'product', 'product_variation' ), true ) ) {
				return array(); }
			if ( ! current_user_can( 'edit_post', $consumer_id ) ) {
				throw new \RuntimeException( 'Product is not authorized.' ); }
			$product = call_user_func( 'wc_get_product', $consumer_id );
			if ( ! $product ) {
				throw new \RuntimeException( 'Product data is unavailable.' ); }
			$values['image_id'] = array( 'id', $product->get_image_id( 'edit' ) );
			$gallery            = $product->get_gallery_image_ids( 'edit' );
			$downloads          = $product->get_downloads( 'edit' );
			if ( count( $gallery ) + count( $downloads ) > 1000 ) {
				throw new \RuntimeException( 'Product media exceeds the budget.' ); }
			foreach ( $gallery as $index => $id ) {
				$values[ 'gallery/' . $index ] = array( 'id', $id ); }
			foreach ( $downloads as $key => $file ) {
				$values[ 'downloads/' . rawurlencode( $key ) . '/file' ] = array( 'url', $file->get_file() ); }
		}
		$refs = array();
		foreach ( $values as $path => $candidate ) {
			list( $kind, $value ) = $candidate;
			if ( ! is_scalar( $value ) || ! $value ) {
				continue; }
			if ( 'url' === $kind && null === Resolver::upload_path( (string) $value, wp_get_upload_dir()['baseurl'] ) ) {
				continue; }
			$id     = 'id' === $kind ? $this->resolver->id( $value ) : $this->resolver->url( (string) $value );
			$refs[] = new Reference( $this->id(), $this->source, (string) $consumer_id, $path, $kind, $id, null === $id ? 'unresolved' : ( 'id' === $kind ? 'exact' : 'strong' ), (string) $value );
		}
		return $refs;
	}
}
