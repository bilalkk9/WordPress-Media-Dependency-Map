<?php
/** Create two visible, disposable demo attachments on the designated test site. */
if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { throw new RuntimeException( 'Restricted to api-local.local.' ); }
$existing = get_option( 'mdm_demo_fixture_ids' );
if ( $existing ) { WP_CLI::line( wp_json_encode( $existing ) ); return; }
require_once ABSPATH . 'wp-admin/includes/image.php';
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS1cAAAAASUVORK5CYII=' );
$ids = array();
foreach ( array( 'Referenced demo image', 'Unreferenced demo image' ) as $title ) {
	$upload = wp_upload_bits( sanitize_title( $title ) . '.png', null, $png );
	if ( $upload['error'] ) { throw new RuntimeException( 'Fixture upload failed.' ); }
	$id = wp_insert_attachment( array( 'post_title' => 'MDM: ' . $title, 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $upload['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	$ids[] = $id;
}
$url = wp_get_attachment_url( $ids[0] );
$post = wp_insert_post( array( 'post_title' => 'MDM demo: media dependency test', 'post_status' => 'draft', 'post_content' => wp_slash( '<!-- wp:image {"id":' . $ids[0] . '} --><figure class="wp-block-image"><img src="' . esc_url( $url ) . '" class="wp-image-' . $ids[0] . '" alt="Dependency test image"></figure><!-- /wp:image -->' ) ) );
set_post_thumbnail( $post, $ids[0] );
$result = array( 'referenced_attachment' => $ids[0], 'unreferenced_attachment' => $ids[1], 'post' => $post );
update_option( 'mdm_demo_fixture_ids', $result, false );
WP_CLI::line( wp_json_encode( $result ) );
