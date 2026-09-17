<?php
/**
 * Core WordPress media extraction.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Matching\Resolver;

defined( 'ABSPATH' ) || exit;

/** Extracts schema-owned IDs and known upload URLs from a single post. */
final class Core implements Adapter {

	/**
	 * Candidate resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;

	/**
	 * URL results scoped to one consumer inspection.
	 *
	 * @var array<string, int|null>
	 */
	private $url_cache = array();

	/**
	 * Ancestor pattern IDs used to reject cycles.
	 *
	 * @var int[]
	 */
	private $ancestors = array();

	/**
	 * Construct the scanner.
	 *
	 * @param Resolver $resolver Attachment resolver.
	 */
	public function __construct( Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Adapter identity.
	 *
	 * @return string
	 */
	public function id() {
		return 'core';
	}

	/**
	 * Scan a post without rendering dynamic blocks or shortcodes.
	 *
	 * @throws \RuntimeException For missing or over-budget consumers.
	 * @param int $consumer_id Post ID.
	 * @return Reference[]
	 */
	public function scan( $consumer_id ) {
		if ( in_array( $consumer_id, $this->ancestors, true ) || count( $this->ancestors ) > 8 ) {
			throw new \RuntimeException( 'Cyclic or deeply nested synced pattern.' );
		}
		$this->url_cache = array();
		$post            = get_post( $consumer_id );
		if ( ! $post || 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			throw new \RuntimeException( 'Consumer is not eligible for scanning.' );
		}
		if ( strlen( $post->post_content ) > 2097152 ) {
			throw new \RuntimeException( 'Consumer content exceeds the extraction budget.' );
		}
		$refs = array();
		$this->candidate( $refs, $consumer_id, 'featured_image', 'id', get_post_thumbnail_id( $consumer_id ) );
		$this->blocks( parse_blocks( $post->post_content ), $consumer_id, 'blocks', $refs, 0 );
		$this->html( $post->post_content, $consumer_id, $refs );
		$pattern = get_shortcode_regex( array( 'gallery', 'playlist', 'audio', 'video', 'caption' ) );
		if ( preg_match_all( '/' . $pattern . '/s', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $index => $match ) {
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue;
				}
				$atts = shortcode_parse_atts( $match[3] );
				$path = 'shortcodes/' . $index . '/' . $match[2];
				if ( 'caption' === $match[2] && isset( $atts['id'] ) && preg_match( '/^attachment_([1-9][0-9]*)$/D', $atts['id'], $caption ) ) {
					$this->candidate( $refs, $consumer_id, $path . '/id', 'id', $caption[1] );
				}
				if ( in_array( $match[2], array( 'gallery', 'playlist' ), true ) && empty( $atts['ids'] ) && empty( $atts['include'] ) ) {
					$parent = isset( $atts['id'] ) ? absint( $atts['id'] ) : $consumer_id;
					// phpcs:ignore WordPress.WP.PostsPerPage -- Bounded ID-only lookup with an overflow sentinel.
					$children = get_posts(
						array(
							'post_type'      => 'attachment',
							'post_status'    => 'inherit',
							'post_parent'    => $parent,
							'post_mime_type' => 'gallery' === $match[2] ? 'image' : ( ( $atts['type'] ?? 'audio' ) === 'video' ? 'video' : 'audio' ),
							'exclude'        => isset( $atts['exclude'] ) ? wp_parse_id_list( $atts['exclude'] ) : array(),
							// phpcs:ignore WordPress.WP.PostsPerPage -- ID-only overflow sentinel for a bounded consumer.
							'posts_per_page' => 5001,
							'fields'         => 'ids',
						)
					);
					if ( count( $children ) > 5000 ) {
						throw new \RuntimeException( 'Implicit gallery exceeds the reference budget.' );
					}
					foreach ( $children as $child ) {
						$this->candidate( $refs, $consumer_id, $path . '/children/' . $child, 'id', $child );
					}
				}
				foreach ( array( 'ids', 'include' ) as $key ) {
					if ( in_array( $match[2], array( 'gallery', 'playlist' ), true ) && isset( $atts[ $key ] ) ) {
						foreach ( explode( ',', $atts[ $key ] ) as $position => $id ) {
							$this->candidate( $refs, $consumer_id, $path . '/' . $key . '/' . $position, 'id', trim( $id ) );
						}
					}
				}
				if ( in_array( $match[2], array( 'audio', 'video' ), true ) ) {
					foreach ( array( 'src', 'mp3', 'mp4', 'm4a', 'ogg', 'ogv', 'webm', 'wav', 'wma', 'wmv', 'flv', 'poster' ) as $key ) {
						if ( isset( $atts[ $key ] ) ) {
							$this->candidate( $refs, $consumer_id, $path . '/' . $key, 'url', $atts[ $key ] );
						}
					}
				}
			}
		}
		return $refs;
	}

	/**
	 * Walk known blocks recursively with a depth limit.
	 *
	 * @throws \RuntimeException For over-nested content.
	 * @param array       $blocks Parsed blocks.
	 * @param int         $post_id Post ID.
	 * @param string      $path Tree path.
	 * @param Reference[] $refs Output.
	 * @param int         $depth Nesting depth.
	 */
	private function blocks( array $blocks, $post_id, $path, array &$refs, $depth ) {
		if ( $depth > 64 ) {
			throw new \RuntimeException( 'Block nesting exceeds the extraction budget.' );
		}
		$schemas = array(
			'core/image'      => array( 'id' ),
			'core/gallery'    => array( 'ids' ),
			'core/cover'      => array( 'id' ),
			'core/media-text' => array( 'mediaId' ),
			'core/file'       => array( 'id' ),
			'core/audio'      => array( 'id' ),
			'core/video'      => array( 'id' ),
		);
		foreach ( $blocks as $index => $block ) {
			$location = $path . '/' . $index;
			if ( 'core/block' === $block['blockName'] && ! empty( $block['attrs']['ref'] ) ) {
				$pattern_id = absint( $block['attrs']['ref'] );
				if ( 'wp_block' !== get_post_type( $pattern_id ) || ! current_user_can( 'read_post', $pattern_id ) ) {
					throw new \RuntimeException( 'Synced pattern is unavailable to the scan user.' );
				}
				$scanner            = new self( $this->resolver );
				$scanner->ancestors = array_merge( $this->ancestors, array( $post_id ) );
				foreach ( $scanner->scan( $pattern_id ) as $reference ) {
					$refs[] = $reference->through_pattern( $post_id, $location . '/synced/' . $pattern_id );
					if ( count( $refs ) > 5000 ) {
						throw new \RuntimeException( 'Synced patterns exceed the reference budget.' );
					}
				}
			}
			foreach ( $schemas[ $block['blockName'] ] ?? array() as $attribute ) {
				$value = $block['attrs'][ $attribute ] ?? null;
				if ( is_array( $value ) ) {
					foreach ( $value as $position => $id ) {
						$this->candidate( $refs, $post_id, $location . '/' . $attribute . '/' . $position, 'id', $id );
					}
				} else {
					$this->candidate( $refs, $post_id, $location . '/' . $attribute, 'id', $value );
				}
			}
			$this->attribute_urls( $block['attrs'], $post_id, $location . '/attrs', $refs, 0 );
			$this->blocks( $block['innerBlocks'], $post_id, $location . '/innerBlocks', $refs, $depth + 1 );
		}
	}

	/**
	 * Find local upload URLs in attributes; arbitrary numeric attributes are ignored.
	 *
	 * @throws \RuntimeException For overly nested attributes.
	 * @param array       $attributes Attributes.
	 * @param int         $post_id Post ID.
	 * @param string      $path Path.
	 * @param Reference[] $refs Output.
	 * @param int         $depth Nesting depth.
	 */
	private function attribute_urls( array $attributes, $post_id, $path, array &$refs, $depth ) {
		if ( $depth > 32 ) {
			throw new \RuntimeException( 'Attribute nesting exceeds the extraction budget.' );
		}
		$uploads = wp_get_upload_dir();
		foreach ( $attributes as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->attribute_urls( $value, $post_id, $path . '/' . $key, $refs, $depth + 1 );
			} elseif ( is_string( $value ) && null !== Resolver::upload_path( $value, $uploads['baseurl'] ) ) {
				$this->candidate( $refs, $post_id, $path . '/' . $key, 'url', $value );
			}
		}
	}

	/**
	 * Parse HTML attributes without executing content.
	 *
	 * @param string      $content HTML source.
	 * @param int         $post_id Consumer ID.
	 * @param Reference[] $refs Output.
	 */
	private function html( $content, $post_id, array &$refs ) {
		$html  = new \WP_HTML_Tag_Processor( $content );
		$index = 0;
		while ( $html->next_tag() ) {
			$path = 'html/' . ( $index++ );
			$tag  = $html->get_tag();
			foreach ( array( 'src', 'href', 'poster' ) as $attribute ) {
				$value = $html->get_attribute( $attribute );
				if ( is_string( $value ) && ( 'href' !== $attribute || 'A' === $tag ) ) {
					$this->candidate( $refs, $post_id, $path . '/' . $attribute, 'url', $value );
				}
			}
			$srcset = $html->get_attribute( 'srcset' );
			if ( is_string( $srcset ) && in_array( $tag, array( 'IMG', 'SOURCE' ), true ) ) {
				foreach ( preg_split( '/\s*,\s*/', $srcset ) as $position => $source ) {
					$url = preg_split( '/\s+/', trim( $source ) )[0];
					$this->candidate( $refs, $post_id, $path . '/srcset/' . $position, 'url', $url );
				}
			}
			$classes = $html->get_attribute( 'class' );
			if ( 'IMG' === $tag && is_string( $classes ) && preg_match_all( '/(?:^|\s)wp-image-([1-9][0-9]*)(?=\s|$)/', $classes, $matches ) ) {
				foreach ( $matches[1] as $position => $id ) {
					$this->candidate( $refs, $post_id, $path . '/class/' . $position, 'id', $id );
				}
			}
			$style = $html->get_attribute( 'style' );
			if ( is_string( $style ) && preg_match_all( '/url\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i', $style, $matches ) ) {
				foreach ( $matches[1] as $position => $url ) {
					$this->candidate( $refs, $post_id, $path . '/style/' . $position, 'url', trim( $url ) );
				}
			}
		}
	}

	/**
	 * Validate one candidate and retain only its fingerprint.
	 *
	 * @throws \RuntimeException For over-budget consumers.
	 * @param Reference[] $refs Output.
	 * @param int         $post_id Consumer ID.
	 * @param string      $path Logical path.
	 * @param string      $kind Candidate kind.
	 * @param mixed       $value Candidate value.
	 */
	private function candidate( array &$refs, $post_id, $path, $kind, $value ) {
		if ( ! is_scalar( $value ) || '' === (string) $value || '0' === (string) $value ) {
			return;
		}
		if ( 'url' === $kind ) {
			$uploads = wp_get_upload_dir();
			if ( null === Resolver::upload_path( (string) $value, $uploads['baseurl'] ) ) {
				return;
			}
		}
		if ( 'url' === $kind && ! array_key_exists( (string) $value, $this->url_cache ) ) {
			if ( count( $this->url_cache ) >= 256 ) {
				throw new \RuntimeException( 'Consumer exceeds the unique URL budget.' );
			}
			$this->url_cache[ (string) $value ] = $this->resolver->url( (string) $value );
		}
		$id         = 'id' === $kind ? $this->resolver->id( $value ) : $this->url_cache[ (string) $value ];
		$confidence = null === $id ? 'unresolved' : ( 'id' === $kind ? 'exact' : 'strong' );
		$refs[]     = new Reference( $this->id(), 'post', (string) $post_id, $path, $kind, $id, $confidence, (string) $value );
		if ( count( $refs ) > 5000 ) {
			throw new \RuntimeException( 'Consumer exceeds the reference budget.' );
		}
	}
}
