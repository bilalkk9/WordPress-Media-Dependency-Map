<?php
/**
 * Administrator dependency browser and authenticated scan actions.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Admin;

use Bilal\MediaDependencyMap\Index\Engine;
use Bilal\MediaDependencyMap\Persistence\Browser_Repository;
use Bilal\MediaDependencyMap\Persistence\Schema;

defined( 'ABSPATH' ) || exit;

/** Uses core admin forms, tables, nonces and capabilities. */
final class Controller {

	/**
	 * Read model.
	 *
	 * @var Browser_Repository
	 */
	private $browser;

	/**
	 * Scan engine.
	 *
	 * @var Engine
	 */
	private $engine;

	/**
	 * Per-page usage counts.
	 *
	 * @var array|null
	 */
	private $counts;

	/**
	 * Per-request freshness summary.
	 *
	 * @var array|null
	 */
	private $status;

	/**
	 * Compose the controller.
	 *
	 * @param Browser_Repository $browser Read model.
	 * @param Engine             $engine Scan worker.
	 */
	public function __construct( Browser_Repository $browser, Engine $engine ) {
		$this->browser = $browser;
		$this->engine  = $engine; }

	/** Register authenticated actions and Media Library integrations. */
	public function register() {
		add_action( 'admin_post_mdm_scan', array( $this, 'scan_action' ) );
		add_action( 'wp_ajax_mdm_batch', array( $this, 'batch_action' ) );
		add_action( 'admin_post_mdm_export', array( $this, 'export_action' ) );
		add_filter( 'manage_media_columns', array( $this, 'columns' ) );
		add_action( 'manage_media_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( 'add_meta_boxes_attachment', array( $this, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Current preview is restricted to site administrators to protect global index summaries.
	 *
	 * @return bool
	 */
	public static function allowed() {
		return current_user_can( 'mdm_view_dependencies' ) && current_user_can( 'manage_options' ); }

	/**
	 * Validate scan permissions and nonce consistently for browser actions.
	 *
	 * @param string $nonce Submitted nonce.
	 * @return bool
	 */
	public static function can_scan( $nonce ) {
		return self::allowed() && current_user_can( 'mdm_run_scans' ) && (bool) wp_verify_nonce( $nonce, 'mdm_scan' ); }

	/** Render the attachment browser or a dependency detail page. */
	public function render() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You cannot view media dependencies.', 'media-dependency-map' ), '', array( 'response' => 403 ) ); }
		if ( Schema::VERSION !== get_option( 'mdm_schema_version' ) ) {
			wp_die( esc_html__( 'The dependency index schema is not ready. Reactivate the plugin or check database permissions.', 'media-dependency-map' ) ); }
		try {
			$status        = $this->browser->status();
			$filters       = $this->filters();
			$page_number   = max( 1, min( 50000, absint( $this->input( 'paged' ) ) ) );
			$attachment_id = absint( $this->input( 'attachment_id' ) );
			$unresolved    = 'unresolved' === $this->input( 'view' );
			if ( $attachment_id && ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) ) {
				wp_die( esc_html__( 'Attachment is unavailable.', 'media-dependency-map' ) ); }
			$detail   = $attachment_id || $unresolved;
			$rows     = $detail ? $this->browser->references( $attachment_id, ( $page_number - 1 ) * 50, $filters['confidence'], $filters['adapter'] ) : $this->browser->attachments( $filters, ( $page_number - 1 ) * 20 );
			$limit    = $detail ? 50 : 20;
			$has_next = count( $rows ) > $limit;
			$rows     = array_slice( $rows, 0, $limit );
			$view     = require __DIR__ . '/../views/browser.php';
			$view( $status, $filters, $page_number, $attachment_id, $unresolved, $detail, $rows, $has_next );
		} catch ( \Throwable $error ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Media Dependency Map', 'media-dependency-map' ) . '</h1><p>' . esc_html__( 'The index could not be read. Check the scan status or database permissions.', 'media-dependency-map' ) . '</p></div>';
		}
	}

	/** Start or manually continue a scan through a nonce-protected POST. */
	public function scan_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capabilities are verified together below.
		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! self::can_scan( $nonce ) ) {
			wp_die( esc_html__( 'Scan request was not authorized.', 'media-dependency-map' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'mdm_scan' );
		$mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
		try {
			if ( 'resume' === $mode ) {
				$this->engine->tick();
			} elseif ( in_array( $mode, array( 'start', 'rebuild' ), true ) ) {
				if ( 'rebuild' === $mode && empty( $_POST['confirm_rebuild'] ) ) {
					wp_die( esc_html__( 'Confirm the index rebuild first.', 'media-dependency-map' ) ); }
				$this->engine->start();
			}
			wp_safe_redirect( self::url() );
		} catch ( \Throwable $error ) {
			wp_safe_redirect( add_query_arg( 'mdm_notice', 'busy', self::url() ) );
		}
		exit;
	}

	/** Drive one bounded batch from the open browser; Cron can also continue it. */
	public function batch_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Explicit shared nonce and capability checks follow.
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! self::can_scan( $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'Scan request was not authorized.', 'media-dependency-map' ) ), 403 ); }
		$this->engine->tick();
		try {
			wp_send_json_success( $this->browser->status() );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Scan status is unavailable.', 'media-dependency-map' ) ), 500 ); }
	}

	/** Export filtered attachment results, with an explicit maximum rather than silent truncation. */
	public function export_action() {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You cannot export dependencies.', 'media-dependency-map' ), '', array( 'response' => 403 ) ); }
		check_admin_referer( 'mdm_export' );
		$rows = $this->browser->attachments( $this->filters(), 0, 10001 );
		if ( count( $rows ) > 10000 ) {
			wp_die( esc_html__( 'Narrow the filters to 10,000 attachments or fewer before exporting.', 'media-dependency-map' ) ); }
		$status = $this->browser->status();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="media-dependencies.csv"' );
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			return; }
		fputcsv( $output, array( 'Attachment ID', 'Title', 'MIME type', 'Known references', 'Dependency index current', 'Last seen (UTC)' ), ',', '"', '' );
		foreach ( $rows as $row ) {
			fputcsv( $output, array( (int) $row->ID, Csv::cell( $row->post_title ), Csv::cell( $row->post_mime_type ), (int) $row->usage_count, $status['current'] ? 'yes' : 'no', $row->last_seen ?? '' ), ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- This is the CSV HTTP response stream.
		fclose( $output );
		exit;
	}

	/**
	 * Normalize read-only query filters.
	 *
	 * @return array
	 */
	private function filters() {
		$filters = array();
		foreach ( array( 'search', 'mime', 'usage', 'sort', 'order', 'after', 'before', 'confidence', 'adapter' ) as $key ) {
			$filters[ $key ] = $this->input( $key ); }
		if ( preg_match( '~^https?://~i', $filters['search'] ) ) {
			$filters['search'] = wp_basename( (string) wp_parse_url( $filters['search'], PHP_URL_PATH ) ); }
		return $filters;
	}

	/**
	 * Read sanitized non-mutating query text.
	 *
	 * @param string $key Query key.
	 * @return string
	 */
	private function input( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- GET parameters only filter authorized read-only views.
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Admin detail URL.
	 *
	 * @param array $args Additional query values.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'media-dependency-map' ), $args ), admin_url( 'upload.php' ) ); }

	/**
	 * Localized usage with explicit coverage.
	 *
	 * @param int   $count Known occurrences.
	 * @param array $status Freshness.
	 * @return string
	 */
	public static function usage( $count, array $status ) {
		if ( $count ) {
			/* translators: %s: number of stored reference occurrences. */
			return sprintf( _n( '%s known reference', '%s known references', $count, 'media-dependency-map' ), number_format_i18n( $count ) );
		}
		return $status['current'] ? __( 'No known references', 'media-dependency-map' ) : __( 'Not fully scanned', 'media-dependency-map' );
	}

	/**
	 * Add a list-view usage column for authorized administrators.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function columns( $columns ) {
		if ( self::allowed() ) {
			$columns['mdm_usage'] = __( 'Usage', 'media-dependency-map' );
		} return $columns; }

	/**
	 * Render counts from one query for the visible attachment page.
	 *
	 * @param string $column Column key.
	 * @param int    $id Attachment ID.
	 */
	public function column( $column, $id ) {
		if ( 'mdm_usage' !== $column || ! self::allowed() ) {
			return; }
		try {
			if ( null === $this->counts ) {
				global $wp_query;
				$ids          = array_map(
					static function ( $post ) {
						return (int) $post->ID;
					},
					$wp_query->posts
				);
				$this->counts = array_replace( array_fill_keys( array_slice( $ids, 0, 500 ), 0 ), $this->browser->counts( $ids ) );
				$this->status = $this->browser->status();
			}
			$label = isset( $this->counts[ $id ] ) ? self::usage( $this->counts[ $id ], $this->status ) : __( 'View references', 'media-dependency-map' );
			echo '<a href="' . esc_url( self::url( array( 'attachment_id' => $id ) ) ) . '">' . esc_html( $label ) . '</a>';
		} catch ( \Throwable $error ) {
			esc_html_e( 'Not fully scanned', 'media-dependency-map' ); }
	}

	/** Register an attachment edit-screen panel. */
	public function meta_box() {
		if ( self::allowed() ) {
			add_meta_box( 'mdm_dependencies', __( 'Media dependencies', 'media-dependency-map' ), array( $this, 'panel' ), 'attachment', 'normal' ); } }

	/**
	 * Render a bounded attachment summary.
	 *
	 * @param \WP_Post $post Attachment object.
	 */
	public function panel( $post ) {
		if ( ! self::allowed() || ! current_user_can( 'read_post', $post->ID ) ) {
			return; }
		try {
			$counts = $this->browser->counts( array( $post->ID ) );
			echo '<p>' . esc_html( self::usage( $counts[ $post->ID ] ?? 0, $this->browser->status() ) ) . '</p>';
			echo '<p><a href="' . esc_url( self::url( array( 'attachment_id' => $post->ID ) ) ) . '">' . esc_html__( 'Inspect dependencies and coverage', 'media-dependency-map' ) . '</a></p>';
		} catch ( \Throwable $error ) {
			esc_html_e( 'Index unavailable.', 'media-dependency-map' ); }
	}

	/**
	 * Load a small progress driver only on this plugin's page.
	 *
	 * @param string $hook Admin screen hook.
	 */
	public function assets( $hook ) {
		if ( 'media_page_media-dependency-map' !== $hook || ! self::allowed() ) {
			return; }
		wp_enqueue_script( 'mdm-admin', plugins_url( 'assets/admin.js', dirname( __DIR__, 2 ) . '/media-dependency-map.php' ), array(), '0.6.0', true );
		wp_localize_script(
			'mdm-admin',
			'mdmAdmin',
			array(
				'nonce'    => wp_create_nonce( 'mdm_scan' ),
				'url'      => admin_url( 'admin-ajax.php' ),
				'error'    => __( 'Automatic progress paused. Use Resume scan to continue.', 'media-dependency-map' ),
				'progress' => __( 'Processing scan batchesâ€¦', 'media-dependency-map' ),
			)
		);
	}
}
