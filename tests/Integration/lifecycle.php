<?php
/** Run with wp eval-file on the designated disposable site only. */
if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'This integration check is restricted to api-local.local.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$plugin = 'media-dependency-map/media-dependency-map.php';
$before = get_option( 'mdm_settings' );
if ( ! is_plugin_active( $plugin ) ) {
	throw new RuntimeException( 'Activate the plugin before testing.' );
}
deactivate_plugins( $plugin );
if ( is_plugin_active( $plugin ) || get_option( 'mdm_settings' ) !== $before ) {
	throw new RuntimeException( 'Deactivation did not preserve settings.' );
}
$result = activate_plugin( $plugin );
if ( is_wp_error( $result ) || ! is_plugin_active( $plugin ) ) {
	throw new RuntimeException( 'Reactivation failed.' );
}
foreach ( \Bilal\MediaDependencyMap\Lifecycle::CAPABILITIES as $capability ) {
	if ( ! get_role( 'administrator' )->has_cap( $capability ) || get_role( 'subscriber' )->has_cap( $capability ) ) {
		throw new RuntimeException( 'Unexpected capability assignment.' );
	}
}
if ( wp_next_scheduled( 'mdm_process_queue' ) ) {
	throw new RuntimeException( 'Activation unexpectedly scheduled work.' );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
ob_start();
( new \Bilal\MediaDependencyMap\Plugin() )->render();
$html = ob_get_clean();
if ( false === strpos( $html, 'Media Dependency Map' ) || false === strpos( $html, 'Coverage and limitations' ) ) {
	throw new RuntimeException( 'Coverage status missing.' );
}
if ( isset( wp_load_alloptions()['mdm_settings'] ) ) {
	throw new RuntimeException( 'Settings should not be autoloaded.' );
}
WP_CLI::success( 'Lifecycle, data retention, capabilities, no automatic scan, admin rendering and non-autoloaded settings passed.' );
