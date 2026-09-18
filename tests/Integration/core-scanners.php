<?php
use Bilal\MediaDependencyMap\Adapters\Core;
use Bilal\MediaDependencyMap\Adapters\Site_Identity;
use Bilal\MediaDependencyMap\Matching\Resolver;
use Bilal\MediaDependencyMap\Persistence\Attachment_Repository;

if ( 'api-local.local' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'Restricted to api-local.local.' );
}
global $wpdb;
$assert = static function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$ids = array();
$post_id = 0;
$pattern_id = 0;
$user_before = get_current_user_id();
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admins[0] );
$icon_before = get_option( 'site_icon', null );
$logo_before = get_theme_mod( 'custom_logo', null );
$widgets_before = get_option( 'widget_media_image', null );
try {
	$path = '2099/mdm-' . wp_generate_uuid4() . '.png';
	$image = wp_insert_attachment( array( 'post_title' => 'MDM core fixture', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ) );
	$ids[] = $image;
	update_post_meta( $image, '_wp_attached_file', $path );
	$derivative = str_replace( '.png', '-150x150.png', wp_basename( $path ) );
	wp_update_attachment_metadata( $image, array( 'file' => $path, 'width' => 600, 'height' => 600, 'sizes' => array( 'thumbnail' => array( 'file' => $derivative, 'width' => 150, 'height' => 150, 'mime-type' => 'image/png' ) ) ) );
	$resolver = new Resolver( new Attachment_Repository( $wpdb ) );
	$base = wp_get_upload_dir()['baseurl'];
	$url = $base . '/' . $path;
	$thumb = $base . '/2099/' . $derivative;
	$assert( $image === $resolver->url( $url ), 'Original URL failed.' );
	$assert( $image === $resolver->url( $thumb ), 'Registered derivative failed.' );
	$assert( null === $resolver->url( str_replace( '.png', '-999x999.png', $url ) ), 'Guessed an unregistered derivative.' );
	$assert( null === $resolver->url( str_replace( wp_parse_url( $base, PHP_URL_HOST ), 'foreign.invalid', $url ) ), 'Foreign URL resolved.' );
	$content = '<!-- wp:group --><div><!-- wp:image {"id":' . $image . '} --><figure><img class="wp-image-' . $image . '" src="' . $url . '" srcset="' . $thumb . ' 150w, ' . $url . ' 600w"></figure><!-- /wp:image --></div><!-- /wp:group -->';
	$content .= '[gallery ids="' . $image . '"]<a href="' . $url . '">Download</a><video poster="' . $thumb . '"></video><div style="background:url(' . $url . ')"></div>';
	foreach ( array( 'cover', 'file', 'audio', 'video' ) as $block ) { $content .= '<!-- wp:' . $block . ' {"id":' . $image . '} /-->'; }
	$content .= '<!-- wp:media-text {"mediaId":' . $image . '} /--><!-- wp:gallery {"ids":[' . $image . ']} /-->';
	$content .= '<!-- wp:example/unknown {"count":' . $image . ',"url":"' . $url . '"} /-->';
	$post_id = wp_insert_post( array( 'post_title' => 'MDM parser fixture', 'post_status' => 'draft', 'post_content' => wp_slash( $content ) ) );
	update_post_meta( $post_id, '_thumbnail_id', $image );
	$refs = ( new Core( $resolver ) )->scan( $post_id );
	$rows = array_map( static function( $ref ) { return $ref->to_array(); }, $refs );
	$paths = array_column( $rows, 'data_path' );
	$assert( in_array( 'featured_image', $paths, true ), 'Featured image missing.' );
	$assert( in_array( 'blocks/0/innerBlocks/0/id', $paths, true ), 'Nested image block missing.' );
	$assert( count( array_filter( $rows, static function( $row ) { return 'exact' === $row['confidence']; } ) ) >= 9, 'Core block or shortcode IDs missing.' );
	$assert( count( array_filter( $rows, static function( $row ) { return 'strong' === $row['confidence']; } ) ) >= 7, 'HTML or URL attributes missing.' );
	$assert( ! preg_grep( '/count$/', $paths ), 'Unknown numeric attribute became an attachment.' );
	foreach ( $rows as $row ) { $assert( ( 'featured_image' === $row['data_path'] ? 'replaceable' : 'read-only' ) === $row['replaceability'], 'Incorrect writer eligibility.' ); }
	update_option( 'site_icon', $image );
	set_theme_mod( 'custom_logo', $image );
	update_option( 'widget_media_image', array( 2 => array( 'attachment_id' => $image, 'url' => $url, 'width' => $image ), '_multiwidget' => 1 ) );
	$site_refs = ( new Site_Identity( $resolver ) )->scan( 0 );
	$assert( count( $site_refs ) >= 4, 'Site identity or image widget references missing.' );
	$widget_paths = array_map( static function( $ref ) { return $ref->to_array()['data_path']; }, $site_refs );
	$assert( ! preg_grep( '/width$/', $widget_paths ), 'Widget dimensions became attachment IDs.' );
	// A missing local URL is represented as unresolved, not silently discarded.
	wp_update_post( array( 'ID' => $post_id, 'post_content' => '<img src="' . $url . '-missing">' ) );
	$missing = ( new Core( $resolver ) )->scan( $post_id );
	$assert( count( array_filter( $missing, static function( $ref ) { return 'unresolved' === $ref->to_array()['confidence']; } ) ) === 1, 'Missing URL was not unresolved.' );
	// A repeated URL uses one lookup within a consumer, preserving each logical occurrence.
	wp_update_post( array( 'ID' => $post_id, 'post_content' => str_repeat( '<img src="' . $url . '">', 100 ) ) );
	$repeated = ( new Core( $resolver ) )->scan( $post_id );
	$assert( 101 === count( $repeated ), 'Repeated occurrences or featured image were lost.' );
	$pattern_id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'MDM pattern fixture', 'post_content' => '<img src="' . $url . '">' ) );
	wp_update_post( array( 'ID' => $image, 'post_parent' => $post_id ) );
	wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( '<!-- wp:block {"ref":' . $pattern_id . '} /-->[gallery][caption id="attachment_' . $image . '"]Image[/caption]' ) ) );
	$extended = ( new Core( $resolver ) )->scan( $post_id );
	$extended_paths = array_map( static function( $ref ) { return $ref->to_array()['data_path']; }, $extended );
	$assert( (bool) preg_grep( '/synced\//', $extended_paths ), 'Synced pattern media missing.' );
	$assert( (bool) preg_grep( '/children\//', $extended_paths ), 'Implicit gallery missing.' );
	$assert( (bool) preg_grep( '/caption\/id$/', $extended_paths ), 'Caption ID missing.' );
	wp_update_post( array( 'ID' => $pattern_id, 'post_content' => wp_slash( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' ) ) );
	$cycle = false;
	try { ( new Core( $resolver ) )->scan( $post_id ); } catch ( RuntimeException $error ) { $cycle = true; }
	$assert( $cycle, 'Pattern cycle was not rejected.' );
	// Two attachments with identical upload paths must never be resolved as unique.
	$duplicate = wp_insert_attachment( array( 'post_title' => 'MDM ambiguous fixture', 'post_mime_type' => 'image/png' ) );
	$ids[] = $duplicate;
	update_post_meta( $duplicate, '_wp_attached_file', $path );
	$assert( null === $resolver->url( $url ), 'Ambiguous path became confirmed.' );
	WP_CLI::success( 'Core scanners: URLs, registered sizes, ambiguity, nested blocks, HTML, shortcodes, identity and read-only safeguards passed.' );
} finally {
	wp_set_current_user( $user_before );
	if ( $pattern_id ) { wp_delete_post( $pattern_id, true ); }
	if ( null === $widgets_before ) { delete_option( 'widget_media_image' ); } else { update_option( 'widget_media_image', $widgets_before ); }
	if ( null === $icon_before ) { delete_option( 'site_icon' ); } else { update_option( 'site_icon', $icon_before ); }
	if ( null === $logo_before ) { remove_theme_mod( 'custom_logo' ); } else { set_theme_mod( 'custom_logo', $logo_before ); }
	if ( $post_id ) { wp_delete_post( $post_id, true ); }
	foreach ( $ids as $id ) { wp_delete_attachment( $id, true ); }
}
