<?php
use Bilal\MediaDependencyMap\Adapters\Core;
use Bilal\MediaDependencyMap\Adapters\Site_Identity;
use Bilal\MediaDependencyMap\Index\Engine;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;
use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
global $wpdb;
$assert = static function( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } };
$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$db->set_prefix( $wpdb->prefix );
$db->prefix = $wpdb->prefix . 'mdm_engine_fixture_';
Schema::install( $db );
$store = new Scan_Repository( $db );
$factory = static function( $generation ) use ( $db ) {
	$resolver = new Resolver( new Attachment_Repository( $db, $generation ) );
	return array( 'core' => array( 'source' => 'post', 'scanner' => new Core( $resolver ) ), 'site-identity' => array( 'source' => 'site', 'scanner' => new Site_Identity( $resolver ) ) );
};
$engine = new Engine( $store, $factory );
$before_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$attachment = 0;
$post = 0;
$cron_before = wp_next_scheduled( 'mdm_process_queue' );
$drain = static function( Engine $engine, Scan_Repository $store ) {
	for ( $i = 0; $i < 500; ++$i ) {
		$engine->tick( 20 );
		if ( ! $store->control()->active_run && ! $store->next_item() ) { return; }
	}
	throw new RuntimeException( 'Scan failed to finish: ' . wp_json_encode( $store->run( $store->control()->active_run ) ) );
};
try {
	$lease = $store->acquire();
	$assert( is_string( $lease ) && null === $store->acquire(), 'Duplicate workers acquired the lease.' );
	$db->query( $db->prepare( 'UPDATE %i SET expires_gmt = %s', $db->prefix . 'mdm_locks', '2000-01-01 00:00:00' ) );
	$new_lease = $store->acquire();
	$rejected = false;
	try { $store->atomic( $lease, static function() {} ); } catch ( RuntimeException $error ) { $rejected = true; }
	$assert( $rejected && is_string( $new_lease ), 'Expired worker was not fenced out.' );
	$store->release( $new_lease );
	$attachment = wp_insert_attachment( array( 'post_title' => 'MDM engine fixture', 'post_mime_type' => 'image/png' ) );
	$path = '2099/engine-' . wp_generate_uuid4() . '.png';
	update_post_meta( $attachment, '_wp_attached_file', $path );
	$url = wp_get_upload_dir()['baseurl'] . '/' . $path;
	$post = wp_insert_post( array( 'post_title' => 'MDM engine post', 'post_status' => 'draft', 'post_content' => '<img src="' . $url . '">' ) );
	$run_id = $engine->start();
	$assert( $run_id === $engine->start(), 'A second full scan was created.' );
	$engine->tick( 1 );
	$assert( 0 === (int) $store->control()->active_generation, 'Partial generation became visible.' );
	$checkpoint = $store->run( $run_id )->state;
	$assert( $checkpoint['cursor'] > 0 || 'adapters' === $checkpoint['phase'], 'Checkpoint was not persisted.' );
	// A new engine instance resumes from the stored checkpoint.
	$engine = new Engine( $store, $factory );
	$drain( $engine, $store );
	$assert( 'complete' === $store->run( $run_id )->status, 'Full scan did not complete.' );
	$generation = (int) $store->control()->active_generation;
	$assert( $generation === $run_id, 'Successful generation was not published.' );
	$assert( 1 === count( $store->references( $generation )->for_attachment( $attachment ) ), 'Indexed media reference missing or duplicated.' );
	wp_update_post( array( 'ID' => $post, 'post_content' => 'Changed content' ) );
	$store->enqueue( 'post', $post );
	$store->enqueue( 'post', $post );
	$assert( 1 === $store->queue_count(), 'Repeated changes did not debounce.' );
	$drain( $engine, $store );
	$assert( 0 === count( $store->references( $generation )->for_attachment( $attachment ) ), 'Incremental scan left a stale reference.' );
	wp_update_post( array( 'ID' => $post, 'post_content' => '<img src="' . $url . '">' ) );
	$store->enqueue( 'post', $post );
	$item = $store->next_item();
	$store->enqueue( 'post', $post );
	$store->finish_item( $item, true );
	$assert( 1 === $store->queue_count(), 'An old worker erased a newer queued change.' );
	$drain( $engine, $store );
	$assert( 1 === count( $store->references( $generation )->for_attachment( $attachment ) ), 'Incremental scan failed to restore reference.' );
	// One failing adapter must not publish its partial generation or suppress other adapters.
	$bad_factory = static function( $generation ) use ( $factory ) {
		$adapters = $factory( $generation );
		$adapters['site-identity']['scanner'] = new class {
			public function scan( $id ) { throw new RuntimeException( 'Fixture failure' ); }
		};
		return $adapters;
	};
	$bad = new Engine( $store, $bad_factory );
	$bad_run = $bad->start();
	$drain( $bad, $store );
	$assert( 'failed' === $store->run( $bad_run )->status, 'Adapter failure was hidden.' );
	$assert( $generation === (int) $store->control()->active_generation, 'Failed rebuild replaced the last good generation.' );
	$assert( 1 === count( $store->references( $generation )->for_attachment( $attachment ) ), 'Failed rebuild lost prior references.' );
	wp_delete_post( $post, true );
	$store->enqueue( 'post', $post );
	$drain( $engine, $store );
	$assert( 0 === count( $store->references( $generation )->for_attachment( $attachment ) ), 'Deleted consumer retained references.' );
	WP_CLI::success( 'Scan engine: leases, fencing, resume, generation publication, debouncing, incremental changes, deletion and failed rebuild preservation passed.' );
} finally {
	if ( $post && get_post( $post ) ) { wp_delete_post( $post, true ); }
	if ( $attachment ) { wp_delete_attachment( $attachment, true ); }
	$settings = get_option( 'mdm_settings' );
	update_option( 'mdm_settings', array( 'remove_data_on_uninstall' => true ), false );
	Schema::uninstall( $db );
	update_option( 'mdm_settings', $settings, false );
	Schema::install( $wpdb );
	wp_set_current_user( $before_user );
	if ( ! $cron_before ) { wp_clear_scheduled_hook( 'mdm_process_queue' ); }
}
