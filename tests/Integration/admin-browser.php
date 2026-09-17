<?php
use Bilal\MediaDependencyMap\Admin\Controller;
use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Index\Engine;
use Bilal\MediaDependencyMap\Persistence\Browser_Repository;
use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
global $wpdb;
$assert = static function( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$db->set_prefix( $wpdb->prefix );
$db->prefix = $wpdb->prefix . 'mdm_browser_fixture_';
Schema::install( $db );
$store = new Scan_Repository( $db );
$browser = new Browser_Repository( $db );
$engine = new Engine( $store, static function( $generation ) { return array(); } );
$controller = new Controller( $browser, $engine );
$before_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$attachment = wp_insert_attachment( array( 'post_title' => 'MDM <script>alert(1)</script> export fixture', 'post_mime_type' => 'image/png' ) );
$post = wp_insert_post( array( 'post_title' => 'MDM browser consumer', 'post_status' => 'draft' ) );
$before_get = $_GET;
try {
	$assert( ! $browser->status()['current'], 'Unscanned index appeared current.' );
	$assert( 'Not fully scanned' === Controller::usage( 0, $browser->status() ), 'Unscanned media was called unreferenced.' );
	$refs = $store->references( 999 );
	$run = $refs->start_run();
	$refs->reconcile( $run, 'core', 'post', (string) $post, array( new Reference( 'core', 'post', (string) $post, 'html/0/src', 'url', $attachment, 'strong', 'not retained' ) ) );
	$refs->finish_run( $run, true );
	$db->update( $db->prefix . 'mdm_control', array( 'active_generation' => 999, 'last_run' => $run ), array( 'id' => 1 ) );
	$rows = $browser->attachments( array( 'search' => (string) $attachment, 'usage' => 'used', 'sort' => 'malicious; SQL', 'order' => 'malicious' ) );
	$assert( count( $rows ) === 1 && 1 === (int) $rows[0]->usage_count, 'Attachment filters/counts failed.' );
	$assert( 1 === $browser->counts( array( $attachment ) )[$attachment], 'Batch usage counts failed.' );
	$assert( 1 === count( $browser->references( $attachment, 0, 'strong', 'core' ) ), 'Reference filters failed.' );
	$assert( 0 === count( $browser->references( $attachment, 0, 'exact' ) ), 'Confidence filter was ignored.' );
	$assert( Controller::can_scan( wp_create_nonce( 'mdm_scan' ) ), 'Authorized scan was rejected.' );
	$assert( ! Controller::can_scan( 'invalid' ), 'Invalid nonce accepted.' );
	wp_set_current_user( 0 );
	$assert( ! Controller::allowed() && ! Controller::can_scan( wp_create_nonce( 'mdm_scan' ) ), 'Guest obtained access with a nonce.' );
	wp_set_current_user( $admins[0] );
	$_GET = array( 'search' => (string) $attachment );
	ob_start(); $controller->render(); $html = ob_get_clean();
	$assert( false !== strpos( $html, 'Start full scan' ) && false !== strpos( $html, '1 known reference' ), 'Browser controls or results missing.' );
	$assert( false === strpos( $html, '<script>alert(1)</script>' ), 'Attachment title was not escaped.' );
	$_GET = array( 'attachment_id' => (string) $attachment );
	ob_start(); $controller->render(); $detail = ob_get_clean();
	$assert( false !== strpos( $detail, 'html/0/src' ) && false !== strpos( $detail, 'MDM browser consumer' ), 'Dependency detail missing.' );
	$store->enqueue( 'post', $post );
	$assert( ! $browser->status()['current'], 'Queued changes did not mark index stale.' );
	WP_CLI::success( 'Admin browser: filters, sorting allowlists, batched counts, confidence, escaping, permissions, nonces and freshness passed.' );
} finally {
	$_GET = $before_get;
	wp_delete_post( $post, true );
	wp_delete_attachment( $attachment, true );
	$settings = get_option( 'mdm_settings' );
	update_option( 'mdm_settings', array( 'remove_data_on_uninstall' => true ), false );
	Schema::uninstall( $db );
	update_option( 'mdm_settings', $settings, false );
	Schema::install( $wpdb );
	wp_set_current_user( $before_user );
}
