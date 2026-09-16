<?php
/**
 * Explicit opt-in removal of this site's plugin settings.
 *
 * @package Bilal\MediaDependencyMap
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mdm_settings = get_option( 'mdm_settings', array() );
if ( empty( $mdm_settings['remove_data_on_uninstall'] ) ) {
	return;
}

require_once __DIR__ . '/includes/class-lifecycle.php';
$mdm_role = get_role( 'administrator' );
if ( $mdm_role ) {
	foreach ( \Bilal\MediaDependencyMap\Lifecycle::CAPABILITIES as $mdm_capability ) {
		$mdm_role->remove_cap( $mdm_capability );
	}
}
delete_option( 'mdm_settings' );
