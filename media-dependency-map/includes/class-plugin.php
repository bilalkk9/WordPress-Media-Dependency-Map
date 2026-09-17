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
		global $wpdb;
		$engine = self::engine();
		// phpcs:ignore WordPress.WP.CronInterval -- Watchdog processes short resumable batches only after an explicit scan.
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( 'mdm_process_queue', array( $engine, 'tick' ) );
		( new Index\Changes( new Persistence\Scan_Repository( $wpdb ), $engine ) )->register();
		if ( is_admin() ) {
			( new Admin\Controller( new Persistence\Browser_Repository( $wpdb ), $engine ) )->register(); }
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			require_once __DIR__ . '/cli/class-commands.php';
			\WP_CLI::add_command( 'mdm', new CLI\Commands() );
		}
		add_action( 'admin_init', array( $this, 'upgrade' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Compose core adapters outside the generic scan engine.
	 *
	 * @return Index\Engine
	 */
	public static function engine() {
		global $wpdb;
		return new Index\Engine(
			new Persistence\Scan_Repository( $wpdb ),
			static function ( $generation ) use ( $wpdb ) {
				$resolver = new Matching\Resolver( new Persistence\Attachment_Repository( $wpdb, $generation ) );
				$adapters = array(
					'core'          => array(
						'source'  => 'post',
						'scanner' => new Adapters\Core( $resolver ),
					),
					'site-identity' => array(
						'source'  => 'site',
						'scanner' => new Adapters\Site_Identity( $resolver ),
					),
				);
				if ( Adapters\Elementor::available() ) {
					$adapters['elementor'] = array(
						'source'  => 'post',
						'scanner' => new Adapters\Elementor( $resolver ),
					);
				}
				return $adapters;
			}
		);
	}

	/**
	 * Add a traffic-driven watchdog schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function schedules( $schedules ) {
		$schedules['mdm_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Media Dependency Map: every minute', 'media-dependency-map' ),
		);
		return $schedules;
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
		global $wpdb;
		( new Admin\Controller( new Persistence\Browser_Repository( $wpdb ), self::engine() ) )->render();
	}
}
