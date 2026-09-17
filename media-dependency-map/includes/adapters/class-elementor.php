<?php
/**
 * Read-only Elementor media controls, using the installed control schemas.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Matching\Resolver;

defined( 'ABSPATH' ) || exit;

/** Does not render widgets, evaluate dynamic tags or modify Elementor data. */
final class Elementor implements Adapter {

	/**
	 * Media resolver.
	 *
	 * @var Resolver
	 */
	private $resolver;
	/**
	 * Collected references.
	 *
	 * @var array
	 */
	private $refs = array();
	/**
	 * Consumer post.
	 *
	 * @var int
	 */
	private $post_id = 0;
	/**
	 * Traversed nodes.
	 *
	 * @var int
	 */
	private $nodes = 0;
	/**
	 * Consumer deadline.
	 *
	 * @var float
	 */
	private $deadline = 0;
	/**
	 * Registered responsive breakpoint names.
	 *
	 * @var string[]
	 */
	private $breakpoints = array();

	/**
	 * Construct an adapter.
	 *
	 * @param Resolver $resolver Media resolver.
	 */
	public function __construct( Resolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * Supported API range; every discovered reference remains read-only.
	 *
	 * @return bool
	 */
	public static function available() {
		return defined( 'ELEMENTOR_VERSION' ) && version_compare( ELEMENTOR_VERSION, '3.20', '>=' ) && version_compare( ELEMENTOR_VERSION, '5.0', '<' ) && is_callable( array( '\Elementor\Plugin', 'instance' ) );
	}

	/**
	 * Stable adapter identifier.
	 *
	 * @return string
	 */
	public function id() {
		return 'elementor';
	}

	/**
	 * Scan persisted document data and page controls without evaluating rendered output.
	 *
	 * @param int $consumer_id Document post ID.
	 * @throws \RuntimeException For incompatible, malformed or over-budget documents.
	 * @return Reference[]
	 */
	public function scan( $consumer_id ) {
		$this->refs     = array();
		$this->nodes    = 0;
		$this->post_id  = $consumer_id;
		$this->deadline = microtime( true ) + 6;
		if ( ! self::available() ) {
			throw new \RuntimeException( 'Elementor API is unavailable.' );
		}
		$raw      = get_post_meta( $consumer_id, '_elementor_data', true );
		$settings = get_post_meta( $consumer_id, '_elementor_page_settings', true );
		if ( '' === $raw && ! $settings ) {
			return array();
		}
		if ( ! is_string( $raw ) || strlen( $raw ) > 2097152 || strlen( (string) wp_json_encode( $settings ) ) > 2097152 ) {
			throw new \RuntimeException( 'Elementor document exceeds the input budget.' );
		}
		if ( '' !== $raw && ! is_array( json_decode( $raw, true, 64 ) ) ) {
			throw new \RuntimeException( 'Elementor document JSON is invalid.' );
		}
		$plugin            = call_user_func( array( '\Elementor\Plugin', 'instance' ) );
		$this->breakpoints = array_keys( $plugin->breakpoints->get_active_breakpoints() );
		$document          = $plugin->documents->get( $consumer_id );
		if ( ! $document || $plugin->editor->is_edit_mode() ) {
			throw new \RuntimeException( 'Persisted Elementor document is unavailable.' );
		}
		$data = $document->get_elements_data();
		if ( ! is_array( $data ) ) {
			throw new \RuntimeException( 'Elementor returned invalid document data.' );
		}
		if ( is_array( $settings ) ) {
			$this->controls( $settings, $document->get_controls(), 'document/settings', 0 );
		}
		$this->elements( $data, $plugin->elements_manager, 'elements', 0 );
		return $this->refs;
	}

	/**
	 * Walk nested containers while keeping element identity in the logical path.
	 *
	 * @param array  $data Element records.
	 * @param object $manager Installed element manager.
	 * @param string $path Logical path.
	 * @param int    $depth Nesting depth.
	 * @throws \RuntimeException For invalid or missing element schemas.
	 */
	private function elements( array $data, $manager, $path, $depth ) {
		foreach ( $data as $index => $element ) {
			$this->budget( $depth );
			if ( ! is_array( $element ) || ! isset( $element['id'], $element['elType'] ) || ! is_string( $element['id'] ) || ! is_string( $element['elType'] ) ) {
				throw new \RuntimeException( 'Invalid Elementor element.' );
			}
			$instance = $manager->create_element_instance( $element );
			if ( ! $instance ) {
				throw new \RuntimeException( 'Elementor element schema is unavailable.' );
			}
			$type = $element['widgetType'] ?? $element['elType'];
			if ( ! is_string( $type ) ) {
				throw new \RuntimeException( 'Invalid Elementor element type.' );
			}
			$location = $path . '/' . $index . ':' . rawurlencode( $element['id'] ) . ':' . rawurlencode( $type );
			$this->controls( (array) ( $element['settings'] ?? array() ), $instance->get_controls(), $location . '/settings', $depth + 1 );
			$this->elements( (array) ( $element['elements'] ?? array() ), $manager, $location . '/elements', $depth + 1 );
		}
	}

	/**
	 * Read only values whose registered controls declare a media-bearing shape.
	 *
	 * @param array  $values Persisted values, without dynamic evaluation.
	 * @param array  $controls Registered control definitions.
	 * @param string $path Logical path.
	 * @param int    $depth Nesting depth.
	 */
	private function controls( array $values, array $controls, $path, $depth ) {
		// Recent Elementor versions register one responsive schema and duplicate it only in the editor.
		foreach ( $controls as $key => $control ) {
			$name = $control['name'] ?? $key;
			if ( ! empty( $control['is_responsive'] ) && is_string( $name ) ) {
				foreach ( $this->breakpoints as $device ) {
					$responsive_name = $name . '_' . $device;
					if ( ! isset( $controls[ $responsive_name ] ) ) {
						$copy                         = $control;
						$copy['name']                 = $responsive_name;
						$controls[ $responsive_name ] = $copy;
					}
				}
			}
		}
		foreach ( $controls as $key => $control ) {
			$this->budget( $depth );
			$name = $control['name'] ?? $key;
			if ( ! is_string( $name ) || ( ! isset( $values[ $name ] ) && empty( $values['__dynamic__'][ $name ] ) ) ) {
				continue;
			}
			$type     = $control['type'] ?? '';
			$value    = $values[ $name ] ?? null;
			$location = $path . '/' . rawurlencode( $name );
			if ( ! empty( $values['__dynamic__'][ $name ] ) && in_array( $type, array( 'media', 'gallery', 'url', 'icons' ), true ) ) {
				$this->refs[] = new Reference( $this->id(), 'post', (string) $this->post_id, $location . '/dynamic', 'dynamic', null, 'unresolved', (string) wp_json_encode( $values['__dynamic__'][ $name ] ) );
				continue;
			}
			if ( 'media' === $type && is_array( $value ) ) {
				$this->media( $value, $location );
			} elseif ( 'gallery' === $type && is_array( $value ) ) {
				foreach ( $value as $index => $image ) {
					$this->budget( $depth );
					if ( is_array( $image ) ) {
						$this->media( $image, $location . '/' . $index );
					}
				}
			} elseif ( 'url' === $type && is_array( $value ) && isset( $value['url'] ) ) {
				$this->candidate( $value['url'], 'url', $location . '/url' );
			} elseif ( 'icons' === $type && is_array( $value ) && 'svg' === ( $value['library'] ?? '' ) && is_array( $value['value'] ?? null ) ) {
				$this->media( $value['value'], $location . '/value' );
			} elseif ( 'repeater' === $type && is_array( $value ) && isset( $control['fields'] ) && is_array( $control['fields'] ) ) {
				foreach ( $value as $index => $row ) {
					$this->budget( $depth );
					if ( is_array( $row ) ) {
						$this->controls( $row, $control['fields'], $location . '/' . $index, $depth + 1 );
					}
				}
			}
		}
	}

	/**
	 * Preserve ID and URL as separate stored occurrences, including conflicts.
	 *
	 * @param array  $value Media control value.
	 * @param string $path Logical path.
	 */
	private function media( array $value, $path ) {
		foreach ( array( 'id', 'url' ) as $kind ) {
			if ( isset( $value[ $kind ] ) ) {
				$this->candidate( $value[ $kind ], $kind, $path . '/' . $kind );
			}
		}
	}

	/**
	 * Resolve a schema-backed scalar without guessing arbitrary numeric settings.
	 *
	 * @param mixed  $value Stored candidate.
	 * @param string $kind Representation.
	 * @param string $path Logical location.
	 */
	private function candidate( $value, $kind, $path ) {
		if ( ! is_scalar( $value ) || ! $value ) {
			return;
		}
		if ( 'url' === $kind && null === Resolver::upload_path( (string) $value, wp_get_upload_dir()['baseurl'] ) ) {
			return;
		}
		$id           = 'id' === $kind ? $this->resolver->id( $value ) : $this->resolver->url( (string) $value );
		$this->refs[] = new Reference( $this->id(), 'post', (string) $this->post_id, $path, $kind, $id, null === $id ? 'unresolved' : ( 'id' === $kind ? 'exact' : 'strong' ), (string) $value );
	}

	/**
	 * Reject oversized documents rather than publishing a truncated snapshot.
	 *
	 * @param int $depth Current nesting depth.
	 * @throws \RuntimeException On a resource budget violation.
	 */
	private function budget( $depth ) {
		if ( ++$this->nodes > 50000 || $depth > 32 || count( $this->refs ) > 5000 || microtime( true ) > $this->deadline ) {
			throw new \RuntimeException( 'Elementor consumer exceeds the scan budget.' );
		}
	}
}
