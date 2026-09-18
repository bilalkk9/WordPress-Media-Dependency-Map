<?php
/**
 * Explicit opt-in removal of this site's plugin settings.
 *
 * @package Bilal\MediaDependencyMap
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

( static function () {
	$media_dependency_map_settings = get_option( 'mdm_settings', array() );
	if ( empty( $media_dependency_map_settings['remove_data_on_uninstall'] ) ) {
		return;
	}

	require_once __DIR__ . '/includes/class-lifecycle.php';
	require_once __DIR__ . '/includes/load.php';
	global $wpdb;
	\Bilal\MediaDependencyMap\Persistence\Schema::uninstall( $wpdb );
	$media_dependency_map_role = get_role( 'administrator' );
	if ( $media_dependency_map_role ) {
		foreach ( \Bilal\MediaDependencyMap\Lifecycle::CAPABILITIES as $media_dependency_map_capability ) {
			$media_dependency_map_role->remove_cap( $media_dependency_map_capability );
		}
	}
	delete_option( 'mdm_settings' );
} )();
