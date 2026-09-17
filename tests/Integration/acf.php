<?php
use Bilal\MediaDependencyMap\Adapters\Acf;
use Bilal\MediaDependencyMap\Index\Engine;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;
use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
if ( ! Acf::available() ) { throw new RuntimeException( 'Install official ACF 6.x for this fixture.' ); }
$assert = static function( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
global $wpdb;
$db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$db->set_prefix( $wpdb->prefix );
$db->prefix = $wpdb->prefix . 'mdm_acf_fixture_';
Schema::install( $db );
$store = new Scan_Repository( $db );
$prior_user = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$group = 'group_mdm_acf_fixture';
acf_add_local_field_group( array( 'key' => $group, 'title' => 'MDM fixture', 'active' => true, 'fields' => array(
 array( 'key' => 'field_mdm_image', 'name' => 'mdm_fixture_image', 'type' => 'image', 'return_format' => 'url' ),
 array( 'key' => 'field_mdm_file', 'name' => 'mdm_fixture_file', 'type' => 'file', 'return_format' => 'array' ),
 array( 'key' => 'field_mdm_number', 'name' => 'mdm_fixture_number', 'type' => 'number' ),
 array( 'key' => 'field_mdm_group', 'name' => 'mdm_fixture_group', 'type' => 'group', 'sub_fields' => array( array( 'key' => 'field_mdm_child', 'name' => 'image', 'type' => 'image' ) ) ),
), 'location' => array() ) );
$image = wp_insert_attachment( array( 'post_title' => 'MDM ACF fixture', 'post_mime_type' => 'image/png' ) );
$path = '2099/mdm-acf-' . wp_generate_uuid4() . '.png';
update_post_meta( $image, '_wp_attached_file', $path );
$url = wp_get_upload_dir()['baseurl'] . '/' . $path;
$post = wp_insert_post( array( 'post_title' => 'MDM ACF consumer', 'post_status' => 'draft' ) );
$term_result = wp_insert_term( 'MDM ACF ' . wp_generate_uuid4(), 'category' );
$term = $term_result['term_id'];
$user = wp_insert_user( array( 'user_login' => 'mdm_' . substr( wp_generate_uuid4(), 0, 8 ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$comment = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_content' => 'MDM ACF fixture', 'comment_approved' => 1 ) );
$contexts = array( 'post' => $post, 'term' => $term, 'user' => $user, 'comment' => $comment, 'site' => get_current_blog_id() );
$factory = static function( $generation ) use ( $db ) {
 $adapters = array();
 foreach ( array( 'post', 'term', 'user', 'comment', 'site' ) as $source ) {
  $adapters['acf-' . $source] = array( 'source' => $source, 'scanner' => new Acf( new Resolver( new Attachment_Repository( $db, $generation ) ), $source ) );
 }
 return $adapters;
};
$engine = new Engine( $store, $factory );
$drain = static function() use ( $engine, $store ) {
 for ( $i = 0; $i < 100; ++$i ) { $engine->tick( 20 ); if ( ! $store->control()->active_run && ! $store->next_item() ) { return; } }
 throw new RuntimeException( 'ACF engine did not finish.' );
};
try {
 foreach ( $contexts as $source => $id ) {
  $context = 'post' === $source ? $id : ( 'site' === $source ? 'options' : $source . '_' . $id );
  update_field( 'field_mdm_image', $image, $context );
  update_field( 'field_mdm_number', $image, $context );
  $scanner = new Acf( new Resolver( new Attachment_Repository( $wpdb ) ), $source );
  $refs = $scanner->scan( $id );
  $assert( 1 === count( $refs ), 'Media/numeric distinction failed for ' . $source );
  $row = $refs[0]->to_array();
  $assert( 'exact' === $row['confidence'] && $image === $row['attachment_id'] && $source === $row['consumer_type'], 'Wrong context or return-format resolution.' );
 }
 update_field( 'field_mdm_file', $image, $post );
 update_field( 'field_mdm_group', array( 'field_mdm_child' => $image ), $post );
 update_post_meta( $post, 'mdm_orphaned', $image );
 update_post_meta( $post, '_mdm_orphaned', 'field_missing_definition' );
 $scanner = new Acf( new Resolver( new Attachment_Repository( $wpdb ) ) );
 $before = get_post_meta( $post );
 $assert( 3 === count( $scanner->scan( $post ) ), 'Image/file/group coverage failed or orphan metadata was guessed.' );
 $assert( $before === get_post_meta( $post ), 'ACF scan changed stored values.' );
 $child = array( 'key' => 'field_nested', 'name' => 'nested', 'type' => 'image' );
 $snapshots = array(
  array( 'key' => 'field_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => array( $child ), 'value' => array( array( 'field_nested' => $image ) ) ),
  array( 'key' => 'field_flex', 'name' => 'flex', 'type' => 'flexible_content', 'layouts' => array( array( 'name' => 'hero', 'sub_fields' => array( $child ) ) ), 'value' => array( array( 'acf_fc_layout' => 'hero', 'field_nested' => $image ) ) ),
  array( 'key' => 'field_clone', 'name' => 'clone', 'type' => 'clone', 'sub_fields' => array( $child ), 'value' => array( 'field_nested' => $image ) ),
  array( 'key' => 'field_gallery', 'name' => 'gallery', 'type' => 'gallery', 'value' => array( $image, $url ) ),
  array( 'key' => 'field_array', 'name' => 'array', 'type' => 'image', 'value' => array( 'ID' => $image, 'url' => $url ) ),
 );
 $assert( 7 === count( $scanner->inspect_fields( $snapshots, $post ) ), 'Complex-field schema/value fixtures failed.' );
 $snapshots[1]['value'][0]['acf_fc_layout'] = 'missing';
 $failed = false; try { $scanner->inspect_fields( $snapshots, $post ); } catch ( RuntimeException $e ) { $failed = true; }
 $assert( $failed, 'Missing layout was silently ignored.' );
 wp_set_current_user( $user );
 $failed = false; try { $scanner->scan( $post ); } catch ( RuntimeException $e ) { $failed = true; }
 $assert( $failed, 'Unauthorized user could inspect another consumer.' );
 wp_set_current_user( $admins[0] );
 $engine->start(); $drain();
 $run = $store->run( (int) $store->control()->last_run );
 $assert( 'complete' === $run->status && isset( $run->state['max_ids']['term'], $run->state['max_ids']['user'], $run->state['max_ids']['comment'] ), 'Independent context cursors failed.' );
 $generation = (int) $store->control()->active_generation;
 $rows = $db->get_results( $db->prepare( 'SELECT * FROM %i WHERE generation = %d AND attachment_id = %d', $db->prefix . 'mdm_references', $generation, $image ) );
 $assert( 7 === count( $rows ), 'Full generation did not publish all five ACF contexts.' );
 delete_field( 'field_mdm_image', 'user_' . $user );
 $store->enqueue( 'user', $user ); $drain();
 $assert( 0 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE generation = %d AND consumer_type = %s AND consumer_key = %s', $db->prefix . 'mdm_references', $generation, 'user', (string) $user ) ), 'Incremental user field deletion left a stale reference.' );
 wp_delete_term( $term, 'category' );
 $store->enqueue( 'term', $term ); $drain();
 $assert( 0 === (int) $db->get_var( $db->prepare( 'SELECT COUNT(*) FROM %i WHERE generation = %d AND consumer_type = %s AND consumer_key = %s', $db->prefix . 'mdm_references', $generation, 'term', (string) $term ) ), 'Deleted term references remained.' );
 WP_CLI::success( 'ACF: real image/file/group values, five contexts, return formats, orphan/numeric exclusion, permissions, schema fixtures, independent cursors, incremental changes and deletion passed.' );
} finally {
 foreach ( array( 'field_mdm_image', 'field_mdm_number' ) as $key ) { delete_field( $key, 'options' ); }
 wp_delete_comment( $comment, true ); wp_delete_term( $term, 'category' );
 require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user );
 wp_delete_post( $post, true ); wp_delete_attachment( $image, true );
 acf_remove_local_field_group( $group );
 $settings = get_option( 'mdm_settings' ); update_option( 'mdm_settings', array( 'remove_data_on_uninstall' => true ), false ); Schema::uninstall( $db ); update_option( 'mdm_settings', $settings, false ); Schema::install( $wpdb );
 wp_set_current_user( $prior_user );
}
