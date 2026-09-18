<?php
use Bilal\MediaDependencyMap\Adapters\Woocommerce;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;
if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
if ( ! Woocommerce::available() ) { throw new RuntimeException( 'WooCommerce required.' ); }
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
global $wpdb;
$assert = static function( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } };
$image = wp_insert_attachment( array( 'post_title' => 'MDM Woo fixture', 'post_mime_type' => 'image/png' ) );
update_post_meta( $image, '_wp_attached_file', '2099/mdm-woo-' . wp_generate_uuid4() . '.png' );
$product = new WC_Product_Simple(); $product->set_name( 'MDM Woo fixture' ); $product->set_status( 'draft' ); $product->set_image_id( $image ); $product->set_gallery_image_ids( array( $image ) );
$download = new WC_Product_Download(); $download->set_id( wp_generate_uuid4() ); $download->set_name( 'Fixture' ); $download->set_file( wp_get_attachment_url( $image ) ); $product->set_downloads( array( $download ) ); $product->save();
$parent = new WC_Product_Variable(); $parent->set_name( 'MDM parent fixture' ); $parent->set_status( 'draft' ); $parent->set_image_id( $image ); $parent->save();
$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->save();
$term = wp_insert_term( 'MDM Woo ' . wp_generate_uuid4(), 'product_cat' )['term_id']; update_term_meta( $term, 'thumbnail_id', $image );
try {
 $scanner = new Woocommerce( new Resolver( new Attachment_Repository( $wpdb ) ) );
 $before = get_post_meta( $product->get_id() );
 $refs = $scanner->scan( $product->get_id() ); $assert( 3 === count( $refs ), 'Expected image, gallery and local download.' );
 foreach ( $refs as $ref ) { $assert( $image === $ref->to_array()['attachment_id'], 'Wrong attachment.' ); }
 $assert( $before === get_post_meta( $product->get_id() ), 'Scanner changed product metadata.' );
 $assert( 0 === count( $scanner->scan( $variation->get_id() ) ), 'Inherited image must not become a stored variation reference.' );
 $variation->set_image_id( $image ); $variation->save(); $assert( 1 === count( $scanner->scan( $variation->get_id() ) ), 'Explicit variation image missing.' );
 $assert( 1 === count( ( new Woocommerce( new Resolver( new Attachment_Repository( $wpdb ) ), 'term' ) )->scan( $term ) ), 'Category image missing.' );
 wp_set_current_user( 0 ); $denied = false; try { $scanner->scan( $product->get_id() ); } catch ( RuntimeException $e ) { $denied = true; } $assert( $denied, 'Unauthorized product scan allowed.' );
 echo "WooCommerce integration passed.\n";
} finally {
 wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
 $variation->delete( true ); $parent->delete( true ); $product->delete( true ); wp_delete_term( $term, 'product_cat' ); wp_delete_attachment( $image, true );
}
