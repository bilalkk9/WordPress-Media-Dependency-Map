<?php
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/schema-stub.php';
require dirname( __DIR__ ) . '/media-dependency-map/includes/domain/class-reference.php';
require dirname( __DIR__ ) . '/media-dependency-map/includes/matching/class-resolver.php';
require dirname( __DIR__ ) . '/media-dependency-map/includes/class-lifecycle.php';

$GLOBALS['mdm_test_options'] = array();
$GLOBALS['mdm_test_role'] = new class {
	public $capabilities = array();
	public function add_cap( $capability ) { $this->capabilities[ $capability ] = true; }
};
function get_role( $name ) { return $GLOBALS['mdm_test_role']; }
function add_option( $name, $value, $deprecated = '', $autoload = true ) {
	if ( ! isset( $GLOBALS['mdm_test_options'][ $name ] ) ) {
		$GLOBALS['mdm_test_options'][ $name ] = array( $value, $autoload );
	}
}
function wp_clear_scheduled_hook( $hook ) { $GLOBALS['mdm_cleared_hook'] = $hook; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
