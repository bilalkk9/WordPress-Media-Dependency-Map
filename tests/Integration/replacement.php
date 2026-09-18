<?php
use Bilal\MediaDependencyMap\Replacement\Service;
use Bilal\MediaDependencyMap\Plugin;
use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
global $wpdb;
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$images = array(); for ( $i = 0; $i < 3; ++$i ) { $images[] = wp_insert_attachment( array( 'post_title' => 'MDM replacement fixture', 'post_mime_type' => 'image/png' ) ); }
foreach ( $images as $image ) { update_post_meta( $image, '_wp_attached_file', '2099/mdm-replace-' . $image . '.png' ); }
$post = wp_insert_post( array( 'post_title' => 'MDM replacement fixture', 'post_status' => 'draft' ) ); update_post_meta( $post, '_thumbnail_id', $images[0] );
$original_settings = get_option( 'mdm_settings', array() );
$service = new Service( $wpdb ); $operations = array();
$scan = static function() use ( $wpdb ) {
 $engine = Plugin::engine(); $store = new Scan_Repository( $wpdb );
 if ( $store->control()->active_run ) { for ( $i = 0; $i < 100 && $store->control()->active_run; ++$i ) { $engine->tick(); } }
 $engine->start(); for ( $i = 0; $i < 200; ++$i ) { $engine->tick(); if ( ! $store->control()->active_run && ! $store->next_item() ) { return; } } throw new RuntimeException( 'Scan did not finish.' );
};
try {
 $scan();
 $guard = new \Bilal\MediaDependencyMap\Admin\Protection();
 update_option( 'mdm_settings', array( 'prevent_used_deletion' => false ) );
 $assert( null === $guard->prevent( null, get_post( $images[0] ) ), 'Default deletion behavior changed.' );
 update_option( 'mdm_settings', array( 'prevent_used_deletion' => true ) );
 $assert( false === $guard->prevent( null, get_post( $images[0] ) ), 'Confirmed usage not protected.' );
 $assert( null === $guard->prevent( null, get_post( $images[2] ) ), 'Unreferenced image blocked.' );
 update_option( 'mdm_settings', $original_settings );
 $id = $service->preview( $images[0], $images[1] ); $operations[] = $id;
 $assert( (int) get_post_thumbnail_id( $post ) === $images[0], 'Dry run changed content.' );
 $service->execute( $id ); $assert( (int) get_post_thumbnail_id( $post ) === $images[1], 'Apply failed.' );
 $assert( 'applied' === $service->report( $id )[1][0]->status, 'Missing journal outcome.' );
 $service->execute( $id ); $assert( (int) get_post_thumbnail_id( $post ) === $images[1], 'Repeated apply changed content.' );
 update_post_meta( $post, '_thumbnail_id', $images[2] ); $service->execute( $id, true );
 $assert( (int) get_post_thumbnail_id( $post ) === $images[2] && 'rollback-blocked' === $service->report( $id )[1][0]->status, 'Rollback overwrote a later edit.' );
 update_post_meta( $post, '_thumbnail_id', $images[1] ); $service->execute( $id, true );
 $assert( (int) get_post_thumbnail_id( $post ) === $images[0] && 'rolled-back' === $service->report( $id )[1][0]->status, 'Rollback did not restore source.' );
 $scan(); $id = $service->preview( $images[0], $images[1] ); $operations[] = $id;
 update_post_meta( $post, '_thumbnail_id', $images[2] ); $service->execute( $id );
 $assert( (int) get_post_thumbnail_id( $post ) === $images[2] && 'conflict' === $service->report( $id )[1][0]->status, 'Apply overwrote a later edit.' );
 $wpdb->update( $wpdb->prefix . 'mdm_operations', array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 400 * 86400 ) ), array( 'id' => $id ) );
 $guard->purge();
 $assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE operation_id = %d', $wpdb->prefix . 'mdm_operation_items', $id ) ), 'Expired journal items retained.' );
 wp_set_current_user( 0 ); $denied = false; try { $service->execute( $id ); } catch ( RuntimeException $e ) { $denied = true; } $assert( $denied, 'Unauthorized mutation allowed.' );
 echo "Replacement integration passed: dry run, apply, idempotency, conflict preservation, rollback and permissions.\n";
} finally {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
 update_option( 'mdm_settings', $original_settings );
 foreach ( $operations as $id ) { $wpdb->delete( $wpdb->prefix . 'mdm_operation_items', array( 'operation_id' => $id ) ); $wpdb->delete( $wpdb->prefix . 'mdm_operations', array( 'id' => $id ) ); }
 wp_delete_post( $post, true ); foreach ( $images as $image ) { wp_delete_attachment( $image, true ); }
}
