<?php
/**
 * Deletion protection, retention and privacy controls.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Admin;

use Bilal\MediaDependencyMap\Persistence\Scan_Repository;
defined( 'ABSPATH' ) || exit;
/** Defaults to warnings; blocking is an explicit administrator setting. */
final class Protection {
	/** Register site-local hooks. */
	public function register() {
		add_filter( 'pre_delete_attachment', array( $this, 'prevent' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_init', array( $this, 'privacy' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mdm_settings', array( $this, 'save' ) );
		add_action( 'mdm_process_queue', array( $this, 'purge' ) );
	}
	/**
	 * Count confirmed references in the last published index.
	 *
	 * @param int $id Attachment ID.
	 * @return int
	 */
	public static function count( $id ) {
		global $wpdb;
	 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Current published generation only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i r INNER JOIN %i c ON c.id = 1 AND r.generation = c.active_generation WHERE r.attachment_id = %d AND r.confidence IN ('exact','strong')", $wpdb->prefix . 'mdm_references', $wpdb->prefix . 'mdm_control', $id ) );
	}
	/**
	 * Respect earlier filters and block only confirmed indexed usage when opted in.
	 *
	 * @param mixed    $delete Earlier result.
	 * @param \WP_Post $post Attachment.
	 * @return mixed
	 */
	public function prevent( $delete, $post ) {
		$settings = get_option( 'mdm_settings', array() );
		return null === $delete && ! empty( $settings['prevent_used_deletion'] ) && self::count( $post->ID ) ? false : $delete;
	}
	/** Warn on the attachment edit screen before permanent deletion. */
	public function notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'attachment' !== $screen->id || ! Controller::allowed() ) {
			return; }
	 // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen warning.
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( self::count( $id ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This attachment has confirmed references in the last published dependency index. Review its dependencies and refresh the scan before deleting it.', 'media-dependency-map' ) . '</p></div>'; }
	}
	/** Add the settings screen. */
	public function menu() {
		add_media_page( __( 'Dependency settings', 'media-dependency-map' ), __( 'Dependency settings', 'media-dependency-map' ), 'mdm_manage_settings', 'mdm-settings', array( $this, 'render' ) ); }
	/** Save validated settings. */
	public function save() {
		check_admin_referer( 'mdm_settings' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'mdm_manage_settings' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'media-dependency-map' ) ); }
		update_option(
			'mdm_settings',
			array(
				'prevent_used_deletion'    => ! empty( $_POST['prevent'] ),
				'remove_data_on_uninstall' => ! empty( $_POST['remove'] ),
				'journal_days'             => max( 1, min( 365, isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30 ) ),
			),
			false
		);
		wp_safe_redirect( add_query_arg( 'page', 'mdm-settings', admin_url( 'upload.php' ) ) );
		exit;
	}
	/** Render native setting controls. */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'mdm_manage_settings' ) ) {
			wp_die( esc_html__( 'Not authorized.', 'media-dependency-map' ) ); }
		$settings = get_option( 'mdm_settings', array() );
		echo '<div class="wrap"><h1>' . esc_html__( 'Dependency settings', 'media-dependency-map' ) . '</h1><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mdm_settings">';
		wp_nonce_field( 'mdm_settings' );
		echo '<p><label><input type="checkbox" name="prevent" value="1" ' . checked( ! empty( $settings['prevent_used_deletion'] ), true, false ) . '> ' . esc_html__( 'Prevent permanent deletion of attachments with confirmed indexed references.', 'media-dependency-map' ) . '</label></p><p>' . esc_html__( 'Default: warn only. Blocking uses the last published index; rescan after removing usage. Unsupported sources may be missing, so an empty index is not proof that deletion is safe.', 'media-dependency-map' ) . '</p><p><label>' . esc_html__( 'Keep operation journals for days (1-365)', 'media-dependency-map' ) . ' <input type="number" name="days" min="1" max="365" value="' . esc_attr( (string) ( $settings['journal_days'] ?? 30 ) ) . '"></label></p><p>' . esc_html__( 'Expired journals are removed by the scan watchdog. Rollback becomes unavailable after removal. Journals store IDs, paths, hashes and statuses; no copied page content or personal field values.', 'media-dependency-map' ) . '</p><p><label><input type="checkbox" name="remove" value="1" ' . checked( ! empty( $settings['remove_data_on_uninstall'] ), true, false ) . '> ' . esc_html__( 'Remove plugin tables and settings on uninstall. This permanently removes rollback history.', 'media-dependency-map' ) . '</label></p>';
		submit_button();
		echo '</form></div>';
	}
	/** Suggest accurate privacy policy text. */
	public function privacy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Media Dependency Map', '<p>' . esc_html__( 'Media Dependency Map stores local indexes of attachment and consumer IDs, field paths, fingerprints and scan timestamps. User and comment field references can identify their associated WordPress records. Replacement journals record the administrator ID and changed attachment IDs. No telemetry or external service is used. Administrators control retention and uninstall cleanup.', 'media-dependency-map' ) . '</p>' ); }
	}
	/** Remove at most twenty expired journals per watchdog invocation. */
	public function purge() {
		global $wpdb;
		$store = new Scan_Repository( $wpdb );
		$token = $store->acquire();
		if ( ! $token ) {
			return; }
		try {
			$store->atomic(
				$token,
				static function () use ( $wpdb ) {
					$settings = get_option( 'mdm_settings', array() );
					$days     = max( 1, min( 365, (int) ( $settings['journal_days'] ?? 30 ) ) );
			  // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded retention cleanup under the shared lease.
					$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE created_gmt < %s ORDER BY id LIMIT 20', $wpdb->prefix . 'mdm_operations', gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ) ) );
					foreach ( $ids as $id ) {
				 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only expired operation items.
						if ( false === $wpdb->delete( $wpdb->prefix . 'mdm_operation_items', array( 'operation_id' => $id ) ) ) {
							throw new \RuntimeException( 'Journal cleanup failed.' ); }
				 // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Only expired operation headers.
						if ( false === $wpdb->delete( $wpdb->prefix . 'mdm_operations', array( 'id' => $id ) ) ) {
							throw new \RuntimeException( 'Journal cleanup failed.' ); }
					}
				}
			);
		} catch ( \Throwable $error ) {
			return;
		} finally {
			$store->release( $token ); }
	}
}
