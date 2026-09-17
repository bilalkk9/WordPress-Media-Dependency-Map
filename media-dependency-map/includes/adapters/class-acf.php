<?php
/**
 * Field-definition-aware ACF inspection.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Adapters;

use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Matching\Resolver;

defined( 'ABSPATH' ) || exit;

/** Reads unformatted ACF values without interpreting arbitrary metadata as media. */
final class Acf implements Adapter {

	/**
	 * Media resolver.
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
	 * Collected references.
	 *
	 * @var array
	 */
	private $refs = array();
	/**
	 * Consumer identity.
	 *
	 * @var int
	 */
	private $consumer = 0;
	/**
	 * Traversal steps.
	 *
	 * @var int
	 */
	private $steps = 0;
	/**
	 * Traversal deadline.
	 *
	 * @var float
	 */
	private $deadline = 0;

	/**
	 * Construct a context-specific adapter.
	 *
	 * @param Resolver $resolver Media resolver.
	 * @param string   $source Context type.
	 * @throws \InvalidArgumentException For an unsupported context.
	 */
	public function __construct( Resolver $resolver, $source = 'post' ) {
		if ( ! in_array( $source, array( 'post', 'term', 'user', 'comment', 'site' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported ACF context.' );
		}
		$this->resolver = $resolver;
		$this->source   = $source;
	}

	/**
	 * Accept only the supported ACF API family.
	 *
	 * @return bool
	 */
	public static function available() {
		return defined( 'ACF_VERSION' ) && version_compare( ACF_VERSION, '6.0', '>=' ) && version_compare( ACF_VERSION, '7.0', '<' ) && function_exists( 'get_field_objects' ) && function_exists( 'acf_get_field_group' );
	}

	/**
	 * Stable adapter identity per context.
	 *
	 * @return string
	 */
	public function id() {
		return 'acf-' . $this->source;
	}

	/**
	 * Retrieve saved fields using ACF's definitions and unformatted value API.
	 *
	 * @param int $consumer_id WordPress object identity, or site ID for default options.
	 * @throws \RuntimeException For incompatible APIs or unsupported complex field types.
	 * @return Reference[]
	 */
	public function scan( $consumer_id ) {
		if ( ! self::available() ) {
			throw new \RuntimeException( 'ACF API unavailable.' );
		}
		$context = 'post' === $this->source ? $consumer_id : ( 'site' === $this->source ? 'options' : $this->source . '_' . $consumer_id );
		$fields  = call_user_func( 'get_field_objects', $context, false, false );
		$caps    = array(
			'post'    => 'edit_post',
			'term'    => 'edit_term',
			'user'    => 'edit_user',
			'comment' => 'edit_comment',
			'site'    => 'manage_options',
		);
		if ( $fields && ! current_user_can( $caps[ $this->source ], $consumer_id ) ) {
			throw new \RuntimeException( 'ACF consumer is not authorized.' );
		}
		$loaded = array();
		$start  = microtime( true );
		foreach ( is_array( $fields ) ? $fields : array() as $field ) {
			if ( count( $loaded ) > 1000 || microtime( true ) - $start > 6 ) {
				throw new \RuntimeException( 'ACF field loading exceeds the budget.' );
			}
			if ( ! in_array( $field['type'], array( 'image', 'file', 'gallery', 'group', 'repeater', 'flexible_content', 'clone' ), true ) ) {
				continue;
			}
			$group = call_user_func( 'acf_get_field_group', $field['parent'] );
			if ( ! $group || empty( $group['active'] ) ) {
				continue;
			}
			if ( ! call_user_func( 'acf_get_field_type', $field['type'] ) ) {
				throw new \RuntimeException( 'ACF field type requires an unavailable extension.' );
			}
			$field['value'] = call_user_func( 'get_field', $field['key'], $context, false );
			$loaded[]       = $field;
		}
		return $this->inspect_fields( $loaded, $consumer_id );
	}

	/**
	 * Traverse a loaded schema/value snapshot; also supports distributable complex-field fixtures.
	 *
	 * @param array $fields Field definitions carrying unformatted values.
	 * @param int   $consumer_id Owning object.
	 * @throws \RuntimeException For oversized input.
	 * @return Reference[]
	 */
	public function inspect_fields( array $fields, $consumer_id ) {
		$this->refs     = array();
		$this->steps    = 0;
		$this->consumer = $consumer_id;
		$this->deadline = microtime( true ) + 6;
		$json           = wp_json_encode( $fields );
		if ( false === $json || strlen( $json ) > 2097152 ) {
			throw new \RuntimeException( 'ACF snapshot exceeds the input budget.' );
		}
		foreach ( $fields as $field ) {
			$this->field( $field, $field['value'] ?? null, 'fields', 0 );
		}
		$this->budget( 0 );
		return $this->refs;
	}

	/**
	 * Follow declared media and nested field types only.
	 *
	 * @param array  $field Definition.
	 * @param mixed  $value Unformatted value.
	 * @param string $path Parent path.
	 * @param int    $depth Current depth.
	 * @throws \RuntimeException For malformed complex structures or missing layouts.
	 */
	private function field( array $field, $value, $path, $depth ) {
		$this->budget( $depth );
		if ( ! isset( $field['key'], $field['name'], $field['type'] ) ) {
			throw new \RuntimeException( 'ACF field definition is incomplete.' );
		}
		$path .= '/' . rawurlencode( $field['key'] ) . ':' . rawurlencode( $field['name'] );
		$type  = $field['type'];
		if ( null === $value || false === $value || '' === $value || array() === $value ) {
			return;
		}
		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			$this->media( $value, $path );
		} elseif ( 'gallery' === $type ) {
			if ( ! is_array( $value ) ) {
				throw new \RuntimeException( 'Invalid ACF gallery value.' );
			}
			foreach ( $value as $index => $image ) {
				$this->budget( $depth );
				$this->media( $image, $path . '/' . $index );
			}
		} elseif ( in_array( $type, array( 'group', 'clone', 'repeater', 'flexible_content' ), true ) ) {
			if ( ! is_array( $value ) ) {
				throw new \RuntimeException( 'ACF complex field did not expose a structured value.' );
			}
			$rows = in_array( $type, array( 'group', 'clone' ), true ) ? array( $value ) : $value;
			foreach ( $rows as $index => $row ) {
				$this->budget( $depth );
				if ( ! is_array( $row ) ) {
					throw new \RuntimeException( 'Invalid ACF row.' );
				}
				$children = $field['sub_fields'] ?? array();
				$row_path = $path . '/' . $index;
				if ( 'flexible_content' === $type ) {
					$layout = $row['acf_fc_layout'] ?? '';
					$found  = false;
					foreach ( $field['layouts'] ?? array() as $definition ) {
						if ( $layout === $definition['name'] ) {
							$children  = $definition['sub_fields'] ?? array();
							$row_path .= '/' . rawurlencode( $layout );
							$found     = true;
							break;
						}
					}
					if ( ! $found ) {
						throw new \RuntimeException( 'ACF layout definition is unavailable.' );
					}
				}
				if ( 'clone' === $type && ! $children ) {
					throw new \RuntimeException( 'ACF clone definitions were not expanded.' );
				}
				foreach ( $children as $child ) {
					$this->field( $child, $row[ $child['key'] ] ?? $row[ $child['name'] ] ?? null, $row_path, $depth + 1 );
				}
			}
		}
	}

	/**
	 * Resolve a declared media value in ID, URL or array form.
	 *
	 * @param mixed  $value Media value.
	 * @param string $path Logical path.
	 */
	private function media( $value, $path ) {
		if ( is_array( $value ) ) {
			foreach ( array( 'ID', 'id', 'url' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					$this->media( $value[ $key ], $path . '/' . $key );
				}
			}
			return;
		}
		if ( ! is_scalar( $value ) || ! $value ) {
			return;
		}
		$kind = is_numeric( $value ) ? 'id' : 'url';
		if ( 'url' === $kind && null === Resolver::upload_path( (string) $value, wp_get_upload_dir()['baseurl'] ) ) {
			return;
		}
		$id           = 'id' === $kind ? $this->resolver->id( $value ) : $this->resolver->url( (string) $value );
		$this->refs[] = new Reference( $this->id(), $this->source, (string) $this->consumer, $path, $kind, $id, null === $id ? 'unresolved' : ( 'id' === $kind ? 'exact' : 'strong' ), (string) $value );
	}

	/**
	 * Enforce bounded traversal without truncating successful results.
	 *
	 * @param int $depth Current depth.
	 * @throws \RuntimeException On budget exhaustion.
	 */
	private function budget( $depth ) {
		if ( ++$this->steps > 10000 || $depth > 32 || count( $this->refs ) > 5000 || microtime( true ) > $this->deadline ) {
			throw new \RuntimeException( 'ACF traversal exceeds the scan budget.' );
		}
	}
}
