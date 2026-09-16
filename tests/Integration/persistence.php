<?php
use Bilal\MediaDependencyMap\Domain\Reference;
use Bilal\MediaDependencyMap\Persistence\Schema;
use Bilal\MediaDependencyMap\Persistence\Reference_Repository;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Restricted to api-local.local.' );
}
global $wpdb;
$assert = static function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
Schema::install( $wpdb );
Schema::install( $wpdb );
$assert( Schema::VERSION === get_option( 'mdm_schema_version' ), 'Schema version missing.' );
$assert( ! isset( wp_load_alloptions()['mdm_schema_version'] ), 'Schema option autoloaded.' );
$repo = new Reference_Repository( $wpdb );
$run = $repo->start_run();
$attachment = wp_insert_attachment( array( 'post_title' => 'MDM persistence fixture', 'post_mime_type' => 'image/png' ) );
$key = 'fixture-' . wp_generate_uuid4();
$ref = new Reference( 'fixture', 'post', $key, 'image', 'id', $attachment, 'exact', (string) $attachment );
try {
	$repo->reconcile( $run, 'fixture', 'post', $key, array( $ref, $ref ) );
	$rows = $repo->for_attachment( $attachment );
	$assert( 1 === count( $rows ), 'Duplicate references were inserted.' );
	$first_seen = $rows[0]->first_seen_gmt;
	$repo->reconcile( $run, 'fixture', 'post', $key, array( $ref ) );
	$assert( $first_seen === $repo->for_attachment( $attachment )[0]->first_seen_gmt, 'First seen changed.' );
	// Force an INSERT failure after the old snapshot was deleted inside the transaction.
	$fail = static function ( $sql ) use ( $wpdb ) {
		if ( 0 === strpos( $sql, 'INSERT INTO `' . $wpdb->prefix . 'mdm_references`' ) ) {
			return 'INSERT INTO mdm_missing_fixture_table (id) VALUES (1)';
		}
		return $sql;
	};
	add_filter( 'query', $fail );
	$old_suppress = $wpdb->suppress_errors( true );
	$failed = false;
	try { $repo->reconcile( $run, 'fixture', 'post', $key, array( $ref ) ); }
	catch ( RuntimeException $error ) { $failed = true; }
	finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( $old_suppress ); }
	$assert( $failed && 1 === count( $repo->for_attachment( $attachment ) ), 'Rollback lost previous snapshot.' );
	Schema::install( $wpdb );
	$assert( 1 === count( $repo->for_attachment( $attachment ) ), 'Upgrade lost data.' );
	Schema::uninstall( $wpdb );
	$assert( 1 === count( $repo->for_attachment( $attachment ) ), 'Default uninstall removed data.' );
	$repo->reconcile( $run, 'fixture', 'post', $key, array() );
	$assert( array() === $repo->for_attachment( $attachment ), 'Stale references survived successful empty scan.' );
	$repo->finish_run( $run, true );
	$rejected = false;
	try { $repo->reconcile( $run, 'fixture', 'post', $key, array( $ref ) ); }
	catch ( RuntimeException $error ) { $rejected = true; }
	$assert( $rejected, 'Completed run accepted writes.' );
	// Verify opted-in removal against disposable tables in this same site's database.
	$isolated = clone $wpdb;
	$isolated->prefix = $wpdb->prefix . 'mdm_fixture_';
	$saved_settings = get_option( 'mdm_settings' );
	try {
		Schema::install( $isolated );
		update_option( 'mdm_settings', array( 'remove_data_on_uninstall' => true ), false );
		Schema::uninstall( $isolated );
		foreach ( Schema::TABLES as $suffix ) {
			$assert( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $isolated->prefix . 'mdm_' . $suffix ) ) ), 'Opt-in uninstall left a table.' );
		}
	} finally {
		update_option( 'mdm_settings', $saved_settings, false );
		Schema::install( $wpdb );
	}
	WP_CLI::success( 'Persistence: idempotent schema, uniqueness, timestamps, rollback, retention, reconciliation and run state passed.' );
} finally {
	$wpdb->delete( $wpdb->prefix . 'mdm_references', array( 'consumer_key' => $key ) );
	$wpdb->delete( $wpdb->prefix . 'mdm_scan_runs', array( 'id' => $run ) );
	wp_delete_attachment( $attachment, true );
}
