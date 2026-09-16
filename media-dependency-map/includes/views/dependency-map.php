<?php
/**
 * Development preview view.
 *
 * @package Bilal\MediaDependencyMap
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Media Dependency Map', 'media-dependency-map' ); ?></h1>
	<p><?php esc_html_e( 'Development preview — reference storage and core inspection are available.', 'media-dependency-map' ); ?></p>
	<h2><?php esc_html_e( 'Scan coverage', 'media-dependency-map' ); ?></h2>
	<p><?php esc_html_e( 'Not fully scanned. Whole-site scanning and the dependency browser are under development.', 'media-dependency-map' ); ?></p>
	<p><?php esc_html_e( 'Core inspection covers featured images, recognized Gutenberg media attributes, HTML media URLs, explicit gallery and playlist IDs, and site identity settings.', 'media-dependency-map' ); ?></p>
	<p><?php esc_html_e( 'Inspection is currently available through WP-CLI. It reads one consumer at a time and does not populate the index.', 'media-dependency-map' ); ?></p>
	<p><?php esc_html_e( 'Elementor, ACF, WooCommerce, Bricks and WPBakery-specific storage is not covered in this preview.', 'media-dependency-map' ); ?></p>
	<p><?php esc_html_e( 'This preview does not replace references or prevent media deletion.', 'media-dependency-map' ); ?></p>
</div>
