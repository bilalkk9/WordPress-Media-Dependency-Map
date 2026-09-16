<?php
/**
 * Site-scoped lifecycle.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap;

defined( 'ABSPATH' ) || exit;

/** Manages activation without starting scans. */
final class Lifecycle {

	/** Capabilities reserved for the dependency workflow. */
	const CAPABILITIES = array(
		'mdm_view_dependencies',
		'mdm_run_scans',
		'mdm_replace_references',
		'mdm_manage_settings',
	);

	/**
	 * Initialize only the current site.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Media Dependency Map separately on each site.', 'media-dependency-map' ) );
		}
		global $wpdb;
		Persistence\Schema::install( $wpdb );
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( self::CAPABILITIES as $capability ) {
				$role->add_cap( $capability );
			}
		}
		add_option( 'mdm_settings', array( 'remove_data_on_uninstall' => false ), '', false );
	}

	/** Stop scheduled work, retaining settings and future index data. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mdm_process_queue' );
	}
}
