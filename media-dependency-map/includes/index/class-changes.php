<?php
/**
 * Cheap, debounced invalidation hooks.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Index;

use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

defined( 'ABSPATH' ) || exit;

/** Queues changes without parsing content during writes. */
final class Changes {

	/**
	 * Storage.
	 *
	 * @var Scan_Repository
	 */
	private $store;

	/**
	 * Scheduler.
	 *
	 * @var Engine
	 */
	private $engine;

	/**
	 * Compose hooks.
	 *
	 * @param Scan_Repository $store Storage.
	 * @param Engine          $engine Scheduler.
	 */
	public function __construct( Scan_Repository $store, Engine $engine ) {
		$this->store  = $store;
		$this->engine = $engine; }

	/** Register supported change sources. */
	public function register() {
		add_action( 'save_post', array( $this, 'post' ), 20 );
		add_action( 'before_delete_post', array( $this, 'post' ) );
		add_action( 'deleted_post', array( $this, 'deleted' ), 20, 2 );
		add_action( 'added_post_meta', array( $this, 'meta' ), 20, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta' ), 20, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta' ), 20, 3 );
		add_action( 'updated_option', array( $this, 'option' ) );
		add_action( 'added_option', array( $this, 'option' ) );
		add_action( 'deleted_option', array( $this, 'option' ) );
		add_action( 'switch_theme', array( $this, 'theme' ) );
		add_action( 'elementor/editor/after_save', array( $this, 'post' ) );
		add_action( 'activated_plugin', array( $this, 'integration' ) );
		add_action( 'deactivated_plugin', array( $this, 'integration' ) );
		add_action( 'acf/save_post', array( $this, 'acf_saved' ), 30 );
		add_action( 'acf/update_field_group', array( $this, 'acf_schema' ), 30 );
		add_action( 'acf/delete_field_group', array( $this, 'acf_schema' ), 30 );
		foreach ( array( 'term', 'user', 'comment' ) as $source ) {
			foreach ( array( 'added', 'updated', 'deleted' ) as $event ) {
				add_action(
					$event . '_' . $source . '_meta',
					function ( $meta_id, $id ) use ( $source ) {
						if ( \Bilal\MediaDependencyMap\Adapters\Acf::available() ) {
							$this->queue( $source, $id );
						}
					},
					30,
					2
				);
			}
			add_action(
				'deleted_' . $source,
				function ( $id ) use ( $source ) {
					if ( \Bilal\MediaDependencyMap\Adapters\Acf::available() ) {
						$this->queue( $source, $id );
					}
				},
				30
			);
		}
	}

	/**
	 * Queue post changes; shared patterns and attachments invalidate global resolution.
	 *
	 * @param int $id Post ID.
	 */
	public function post( $id ) {
		$type = get_post_type( $id );
		if ( ! $type || 'revision' === $type || 'auto-draft' === get_post_status( $id ) ) {
			return; }
		if ( in_array( $type, array( 'acf-field', 'acf-field-group' ), true ) ) {
			$this->queue( 'full', 0 );
			return;
		}
		$this->queue( in_array( $type, array( 'attachment', 'wp_block' ), true ) ? 'full' : 'post', in_array( $type, array( 'attachment', 'wp_block' ), true ) ? 0 : $id );
	}

	/**
	 * Requeue after the deletion commits, closing the before-delete race.
	 *
	 * @param int      $id Removed ID.
	 * @param \WP_Post $post Removed object.
	 */
	public function deleted( $id, $post ) {
		if ( in_array( $post->post_type, array( 'attachment', 'wp_block', 'acf-field', 'acf-field-group' ), true ) ) {
			$this->queue( 'full', 0 );
		} elseif ( 'revision' !== $post->post_type ) {
			$this->queue( 'post', $id );
		}
	}

	/**
	 * Queue relevant metadata updates.
	 *
	 * @param int|array $meta_id Metadata identity.
	 * @param int       $post_id Post identity.
	 * @param string    $key Metadata key.
	 */
	public function meta( $meta_id, $post_id, $key ) {
		if ( \Bilal\MediaDependencyMap\Adapters\Acf::available() || in_array( $key, array( '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata', '_elementor_data', '_elementor_page_settings', '_elementor_edit_mode' ), true ) ) {
			$this->post( $post_id ); }
	}

	/**
	 * Watch allowlisted core settings only.
	 *
	 * @param string $name Option name.
	 */
	public function option( $name ) {
		if ( \Bilal\MediaDependencyMap\Adapters\Acf::available() && ( 0 === strpos( $name, 'options_' ) || 0 === strpos( $name, '_options_' ) ) ) {
			$this->queue( 'site', get_current_blog_id() );
		}
		if ( in_array( $name, array( 'site_icon', 'widget_media_image' ), true ) || 0 === strpos( $name, 'theme_mods_' ) ) {
			$this->queue( 'site', get_current_blog_id() ); }
		if ( in_array( $name, array( 'home', 'siteurl', 'upload_path', 'upload_url_path' ), true ) ) {
			$this->queue( 'full', 0 ); }
	}

	/** Refresh the current theme's identity settings. */
	public function theme() {
		$this->queue( 'site', get_current_blog_id() ); }

	/**
	 * Route supported ACF context identities after all field writes.
	 *
	 * @param int|string $context ACF object identity.
	 */
	public function acf_saved( $context ) {
		if ( is_numeric( $context ) ) {
			$this->post( (int) $context );
		} elseif ( in_array( $context, array( 'option', 'options' ), true ) ) {
			$this->queue( 'site', get_current_blog_id() );
		} elseif ( preg_match( '/^(term|user|comment)_(\d+)$/D', $context, $match ) ) {
			$this->queue( $match[1], (int) $match[2] );
		}
	}

	/** Field definitions can change interpretation across many consumers. */
	public function acf_schema() {
		$this->queue( 'full', 0 );
	}

	/**
	 * Rebuild after integration changes without starting work on our own activation.
	 *
	 * @param string $plugin Changed plugin basename.
	 */
	public function integration( $plugin ) {
		if ( 'media-dependency-map/media-dependency-map.php' !== $plugin ) {
			$this->queue( 'full', 0 );
		}
	}

	/**
	 * Persist and schedule a change without breaking the originating content save.
	 *
	 * @param string $source Source type.
	 * @param int    $id Consumer ID.
	 */
	private function queue( $source, $id ) {
		if ( Schema::VERSION !== get_option( 'mdm_schema_version' ) ) {
			return; }
		try {
			$this->store->enqueue( $source, $id );
			if ( $this->store->control()->owner_id ) {
				$this->engine->schedule(); }
		} catch ( \Throwable $error ) {
			update_option( 'mdm_worker_error', gmdate( 'Y-m-d H:i:s' ), false );
		}
	}
}
