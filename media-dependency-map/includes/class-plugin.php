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
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			require_once __DIR__ . '/cli/class-commands.php';
			\WP_CLI::add_command( 'mdm', new CLI\Commands() );
		}
		add_action( 'admin_init', array( $this, 'upgrade' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/** Upgrade on authorized administration requests, including already-active installs. */
	public function upgrade() {
		if ( ! current_user_can( 'activate_plugins' ) || Persistence\Schema::VERSION === get_option( 'mdm_schema_version' ) ) {
			return;
		}
		global $wpdb;
		try {
			Persistence\Schema::install( $wpdb );
		} catch ( \Throwable $error ) {
			add_action( 'admin_notices', array( $this, 'schema_notice' ) );
		}
	}

	/** Explain a failed migration without exposing database details. */
	public function schema_notice() {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Media Dependency Map could not initialize its database tables. Check database permissions and InnoDB support.', 'media-dependency-map' ) . '</p></div>';
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
