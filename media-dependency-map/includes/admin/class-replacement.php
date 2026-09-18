<?php
/**
 * Replacement previews and reports.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Admin;

use Bilal\MediaDependencyMap\Replacement\Service;
defined( 'ABSPATH' ) || exit;
/** Native forms require confirmation before mutation. */
final class Replacement {
	/** Register hooks. */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mdm_replace', array( $this, 'action' ) ); }
	/** Add the screen. */
	public function menu() {
		add_media_page( __( 'Media replacement', 'media-dependency-map' ), __( 'Media replacement', 'media-dependency-map' ), 'mdm_replace_references', 'mdm-replacement', array( $this, 'render' ) ); }
	/** Handle nonce-protected forms. */
	public function action() {
		check_admin_referer( 'mdm_replace' );
		global $wpdb;
		$service = new Service( $wpdb );
		try {
			Service::authorize();
			$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
			$id   = isset( $_POST['operation'] ) ? absint( $_POST['operation'] ) : 0;
			if ( 'preview' === $mode ) {
				$id = $service->preview( isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0, isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0 );
			} elseif ( in_array( $mode, array( 'apply', 'rollback' ), true ) && ! empty( $_POST['confirm'] ) ) {
				$service->execute( $id, 'rollback' === $mode );
			} else {
				wp_die( esc_html__( 'Confirm the action first.', 'media-dependency-map' ) ); }
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => 'mdm-replacement',
						'operation' => $id,
					),
					admin_url( 'upload.php' )
				)
			);
			exit;
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Cannot proceed. Check permissions and image IDs, finish the scan, or create a fresh preview. A worker may be busy. Existing reports remain available.', 'media-dependency-map' ), '', array( 'back_link' => true ) ); }
	}
	/** Render the preview and operation history. */
	public function render() {
		global $wpdb;
		try {
			Service::authorize();
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Not authorized.', 'media-dependency-map' ) ); }
	 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report selector.
		$id = isset( $_GET['operation'] ) ? absint( $_GET['operation'] ) : 0;
		echo '<div class="wrap"><h1>' . esc_html__( 'Media replacement', 'media-dependency-map' ) . '</h1><p>' . esc_html__( 'Preview before applying. Only core featured images on non-product posts are writable. Other references remain read-only. Files are never deleted. Each request processes ten changes; repeat until no eligible items remain. Previews expire after 30 minutes.', 'media-dependency-map' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mdm_replace">';
		wp_nonce_field( 'mdm_replace' );
		echo '<p><label>' . esc_html__( 'Source attachment ID', 'media-dependency-map' ) . ' <input type="number" min="1" name="source" required></label> <label>' . esc_html__( 'Target image ID', 'media-dependency-map' ) . ' <input type="number" min="1" name="target" required></label></p><button class="button" name="mode" value="preview">' . esc_html__( 'Create dry run', 'media-dependency-map' ) . '</button></form>';
		if ( $id ) {
			try {
				list( $operation, $items ) = ( new Service( $wpdb ) )->report( $id );
				echo '<h2>' . esc_html__( 'Operation report', 'media-dependency-map' ) . ' #' . esc_html( (string) $id ) . '</h2><p>' . esc_html( $operation->source_id . ' -> ' . $operation->target_id . ' | ' . $operation->created_gmt . ' UTC' ) . '</p>';
				echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Consumer / path', 'media-dependency-map' ) . '</th><th>' . esc_html__( 'Status', 'media-dependency-map' ) . '</th></tr></thead><tbody>';
				foreach ( $items as $item ) {
					echo '<tr><td>' . esc_html( $item->adapter_id . ' / ' . $item->consumer_type . ':' . $item->consumer_key . ' / ' . $item->data_path ) . '</td><td>' . esc_html( $item->status . ( $item->error_code ? ' - ' . $item->error_code : '' ) ) . '</td></tr>'; }
				echo '</tbody></table><p>' . esc_html__( 'Conflicts leave that item unchanged. Rollback restores only values still matching this operation. Rescan to refresh the index after changes.', 'media-dependency-map' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mdm_replace"><input type="hidden" name="operation" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( 'mdm_replace' );
				echo '<p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I confirm the changes shown in this report.', 'media-dependency-map' ) . '</label></p><button class="button button-primary" name="mode" value="apply">' . esc_html__( 'Apply next batch', 'media-dependency-map' ) . '</button> <button class="button" name="mode" value="rollback">' . esc_html__( 'Roll back next batch', 'media-dependency-map' ) . '</button></form>';
			} catch ( \Throwable $error ) {
				echo '<p>' . esc_html__( 'Operation unavailable.', 'media-dependency-map' ) . '</p>'; }
		}
	 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded journal history.
		$history = $wpdb->get_results( $wpdb->prepare( 'SELECT id,created_gmt,status FROM %i ORDER BY id DESC LIMIT 20', $wpdb->prefix . 'mdm_operations' ) );
		echo '<h2>' . esc_html__( 'Recent operations', 'media-dependency-map' ) . '</h2><ul>';
		foreach ( $history as $entry ) {
			echo '<li><a href="' . esc_url(
				add_query_arg(
					array(
						'page'      => 'mdm-replacement',
						'operation' => $entry->id,
					),
					admin_url( 'upload.php' )
				)
			) . '">' . esc_html( '#' . $entry->id . ' - ' . $entry->created_gmt . ' - ' . $entry->status ) . '</a></li>'; }
		echo '</ul></div>';
	}
}
