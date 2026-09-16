<?php
/**
 * Application composition and initial admin screen.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap;

defined( 'ABSPATH' ) || exit;

/** Registers the development preview. */
final class Plugin {

	/** Register WordPress hooks. */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/** Add the screen beneath Media. */
	public function add_menu() {
		add_media_page(
			__( 'Media Dependency Map', 'media-dependency-map' ),
			__( 'Dependency Map', 'media-dependency-map' ),
			'mdm_view_dependencies',
			'media-dependency-map',
			array( $this, 'render' )
		);
	}

	/** Render the protected preview screen. */
	public function render() {
		if ( ! current_user_can( 'mdm_view_dependencies' ) ) {
			wp_die( esc_html__( 'You cannot view media dependencies.', 'media-dependency-map' ) );
		}
		require __DIR__ . '/views/dependency-map.php';
	}
}
