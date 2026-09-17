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
		$this->queue( in_array( $type, array( 'attachment', 'wp_block' ), true ) ? 'full' : 'post', in_array( $type, array( 'attachment', 'wp_block' ), true ) ? 0 : $id );
	}

	/**
	 * Requeue after the deletion commits, closing the before-delete race.
	 *
	 * @param int      $id Removed ID.
	 * @param \WP_Post $post Removed object.
	 */
	public function deleted( $id, $post ) {
		if ( in_array( $post->post_type, array( 'attachment', 'wp_block' ), true ) ) {
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
		if ( in_array( $key, array( '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata' ), true ) ) {
			$this->post( $post_id ); }
	}

	/**
	 * Watch allowlisted core settings only.
	 *
	 * @param string $name Option name.
	 */
	public function option( $name ) {
		if ( in_array( $name, array( 'site_icon', 'widget_media_image' ), true ) || 0 === strpos( $name, 'theme_mods_' ) ) {
			$this->queue( 'site', get_current_blog_id() ); }
		if ( in_array( $name, array( 'home', 'siteurl', 'upload_path', 'upload_url_path' ), true ) ) {
			$this->queue( 'full', 0 ); }
	}

	/** Refresh the current theme's identity settings. */
	public function theme() {
		$this->queue( 'site', get_current_blog_id() ); }

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
