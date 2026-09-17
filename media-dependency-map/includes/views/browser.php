<?php
/**
 * Native WordPress dependency browser.
 *
 * @package Bilal\MediaDependencyMap
 */

use Bilal\MediaDependencyMap\Admin\Controller;

defined( 'ABSPATH' ) || exit;
return static function ( array $status, array $filters, $page_number, $attachment_id, $unresolved, $detail, array $rows, $has_next ) {
	$run        = $status['run'];
	$pagination = array_merge(
		$filters,
		array(
			'attachment_id' => $attachment_id,
			'view'          => $unresolved ? 'unresolved' : '',
		)
	);
	?>
<div class="wrap">
	<h1><?php esc_html_e( 'Media Dependency Map', 'media-dependency-map' ); ?></h1>
	<p><?php esc_html_e( 'Review known media references and scan coverage before changing an attachment.', 'media-dependency-map' ); ?></p>
	<h2><?php esc_html_e( 'Scan status', 'media-dependency-map' ); ?></h2>
	<p id="mdm-progress" role="status" aria-live="polite" data-active="<?php echo $status['active_run'] ? '1' : '0'; ?>">
		<?php
		if ( $status['active_run'] ) {
			/* translators: %s: completed consumer count. */
			printf( esc_html__( 'Scan running. %s consumers processed.', 'media-dependency-map' ), esc_html( number_format_i18n( $run->state['processed'] ?? 0 ) ) );
		} elseif ( $status['current'] ) {
			esc_html_e( 'Supported-source scan complete. Coverage limitations still apply.', 'media-dependency-map' );
		} else {
			esc_html_e( 'Not fully scanned. Results may be missing or out of date.', 'media-dependency-map' );
		}
		?>
	</p>
	<?php if ( $status['queued'] ) : ?>
		<p><?php /* translators: %s: number of pending or failed consumer changes. */ printf( esc_html__( '%s changes are pending or require a successful rescan.', 'media-dependency-map' ), esc_html( number_format_i18n( $status['queued'] ) ) ); ?></p>
	<?php endif; ?>
	<?php if ( $run && 'failed' === $run->status ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The last scan had failures. The previous successful index was preserved. Start a new full scan after correcting the affected consumers.', 'media-dependency-map' ); ?></p></div>
		<details><summary><?php esc_html_e( 'Scan error sample', 'media-dependency-map' ); ?></summary><ul>
		<?php foreach ( $run->state['errors'] ?? array() as $error ) : ?>
			<li><?php echo esc_html( $error['adapter'] . ' — ' . $error['consumer_id'] . ' — ' . $error['code'] ); ?></li>
		<?php endforeach; ?>
		</ul></details>
	<?php endif; ?>
	<?php if ( $status['worker_error'] ) : ?>
		<p><?php esc_html_e( 'A worker was interrupted. Resume the scan; persistent errors may indicate a database or resource limit.', 'media-dependency-map' ); ?></p>
	<?php endif; ?>
	<?php if ( current_user_can( 'mdm_run_scans' ) ) : ?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="mdm_scan">
			<?php wp_nonce_field( 'mdm_scan' ); ?>
			<p><button class="button button-primary" name="mode" value="start"><?php esc_html_e( 'Start full scan', 'media-dependency-map' ); ?></button>
			<button class="button" name="mode" value="resume"><?php esc_html_e( 'Resume scan', 'media-dependency-map' ); ?></button></p>
			<details><summary><?php esc_html_e( 'Rebuild index', 'media-dependency-map' ); ?></summary>
			<p><label><input type="checkbox" name="confirm_rebuild" value="1"> <?php esc_html_e( 'Build a fresh dependency index. The last successful index remains visible until the new scan succeeds.', 'media-dependency-map' ); ?></label></p>
			<button class="button" name="mode" value="rebuild"><?php esc_html_e( 'Rebuild index', 'media-dependency-map' ); ?></button></details>
		</form>
	<?php endif; ?>
	<p><?php esc_html_e( 'Scanning continues through WordPress Cron while the site receives traffic. With Cron disabled, use Resume scan or WP-CLI.', 'media-dependency-map' ); ?></p>
	<details><summary><?php esc_html_e( 'Coverage and limitations', 'media-dependency-map' ); ?></summary>
		<ul>
			<li><?php esc_html_e( 'Core posts: featured images, recognized Gutenberg media attributes, synced patterns, HTML media URLs, and supported media shortcodes.', 'media-dependency-map' ); ?></li>
			<li><?php esc_html_e( 'Site identity: icon, current theme logo, header/background images, and stored core image widgets.', 'media-dependency-map' ); ?></li>
			<li><?php echo \Bilal\MediaDependencyMap\Adapters\Elementor::available() ? esc_html__( 'Elementor: registered media, gallery, SVG icon, URL and nested repeater controls in saved documents. Dynamic values remain unresolved; rendered output and template inclusion are not evaluated.', 'media-dependency-map' ) : esc_html__( 'Elementor: unavailable or outside the supported API range (3.20 through 4.x). Its storage is not scanned.', 'media-dependency-map' ); ?></li>
			<li><?php esc_html_e( 'ACF, WooCommerce, Bricks and WPBakery-specific storage: not scanned in this preview.', 'media-dependency-map' ); ?></li>
			<li><?php esc_html_e( 'All references are read-only. No known references does not guarantee that a file is unused.', 'media-dependency-map' ); ?></li>
		</ul>
	</details>
	<hr>
	<p><a href="<?php echo esc_url( Controller::url() ); ?>"><?php esc_html_e( 'Browse attachments', 'media-dependency-map' ); ?></a> | <a href="<?php echo esc_url( Controller::url( array( 'view' => 'unresolved' ) ) ); ?>"><?php esc_html_e( 'Unresolved references', 'media-dependency-map' ); ?></a></p>
	<?php if ( $detail ) : ?>
		<h2><?php echo $attachment_id ? esc_html( get_the_title( $attachment_id ) ) : esc_html__( 'Unresolved references', 'media-dependency-map' ); ?></h2>
		<p><?php esc_html_e( 'Counts represent stored occurrences, not distinct pages. A block and its HTML may contain separate references to the same file.', 'media-dependency-map' ); ?></p>
		<form method="get">
			<input type="hidden" name="page" value="media-dependency-map"><input type="hidden" name="attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>"><input type="hidden" name="view" value="<?php echo $unresolved ? 'unresolved' : ''; ?>">
			<label for="mdm-confidence"><?php esc_html_e( 'Confidence', 'media-dependency-map' ); ?></label>
			<select id="mdm-confidence" name="confidence">
			<?php
			foreach ( array(
				''           => __( 'All confidence levels', 'media-dependency-map' ),
				'exact'      => __( 'Exact ID', 'media-dependency-map' ),
				'strong'     => __( 'Strong URL match', 'media-dependency-map' ),
				'unresolved' => __( 'Unresolved', 'media-dependency-map' ),
			) as $value => $label ) :
				?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['confidence'], $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?></select>
			<label for="mdm-adapter"><?php esc_html_e( 'Source', 'media-dependency-map' ); ?></label>
			<select id="mdm-adapter" name="adapter">
			<?php
			foreach ( array(
				''              => __( 'All sources', 'media-dependency-map' ),
				'core'          => __( 'Core posts', 'media-dependency-map' ),
				'site-identity' => __( 'Site identity', 'media-dependency-map' ),
				'elementor'     => __( 'Elementor', 'media-dependency-map' ),
			) as $value => $label ) :
				?>
											<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['adapter'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
			<button class="button"><?php esc_html_e( 'Filter', 'media-dependency-map' ); ?></button>
		</form>
		<table class="widefat striped"><caption class="screen-reader-text"><?php esc_html_e( 'Known dependency occurrences', 'media-dependency-map' ); ?></caption>
			<thead><tr><th scope="col"><?php esc_html_e( 'Consumer', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Source and location', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Confidence', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Last seen', 'media-dependency-map' ); ?></th></tr></thead>
			<tbody><?php foreach ( $rows as $row ) : ?>
			<tr><td><?php if ( 'post' === $row->consumer_type && current_user_can( 'read_post', (int) $row->consumer_key ) ) : ?>
				<?php echo esc_html( get_the_title( (int) $row->consumer_key ) ); ?> <small>#<?php echo esc_html( $row->consumer_key ); ?></small>
				<?php
				if ( current_user_can( 'edit_post', (int) $row->consumer_key ) ) :
					?>
					<br><a href="<?php echo esc_url( get_edit_post_link( (int) $row->consumer_key ) ); ?>"><?php esc_html_e( 'Edit consumer', 'media-dependency-map' ); ?></a><?php endif; ?>
				<?php
			elseif ( 'site' === $row->consumer_type ) :
				?>
				<?php esc_html_e( 'Site settings', 'media-dependency-map' ); ?>
				<?php
else :
	?>
	<?php esc_html_e( 'Unavailable consumer', 'media-dependency-map' ); ?><?php endif; ?></td>
			<td><?php echo esc_html( $row->adapter_id ); ?><br><code style="overflow-wrap:anywhere"><?php echo esc_html( $row->data_path ); ?></code></td>
			<td><?php echo esc_html( $row->confidence ); ?><br><?php esc_html_e( 'Read-only', 'media-dependency-map' ); ?></td>
			<td><?php echo esc_html( get_date_from_gmt( $row->last_seen_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php
			if ( ! $rows ) :
				?>
				<tr><td colspan="4"><?php esc_html_e( 'No matching references in the published dependency index.', 'media-dependency-map' ); ?></td></tr><?php endif; ?></tbody>
		</table>
	<?php else : ?>
		<h2><?php esc_html_e( 'Media Library dependencies', 'media-dependency-map' ); ?></h2>
		<form method="get"><input type="hidden" name="page" value="media-dependency-map">
		<p><label for="mdm-search"><?php esc_html_e( 'Search title, filename, ID, MIME type, consumer title or URL', 'media-dependency-map' ); ?></label><br><input class="regular-text" id="mdm-search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>"></p>
		<p><label for="mdm-usage"><?php esc_html_e( 'Usage', 'media-dependency-map' ); ?></label> <select id="mdm-usage" name="usage">
		<?php
		foreach ( array(
			''     => __( 'All media', 'media-dependency-map' ),
			'used' => __( 'Has known references', 'media-dependency-map' ),
			'none' => __( 'No indexed references', 'media-dependency-map' ),
		) as $value => $label ) :
			?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['usage'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
		<label for="mdm-mime"><?php esc_html_e( 'Media type', 'media-dependency-map' ); ?></label> <select id="mdm-mime" name="mime">
		<?php
		foreach ( array(
			''            => __( 'All types', 'media-dependency-map' ),
			'image'       => __( 'Images', 'media-dependency-map' ),
			'audio'       => __( 'Audio', 'media-dependency-map' ),
			'video'       => __( 'Video', 'media-dependency-map' ),
			'application' => __( 'Documents', 'media-dependency-map' ),
		) as $value => $label ) :
			?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['mime'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></p>
		<p><label for="mdm-after"><?php esc_html_e( 'Uploaded from', 'media-dependency-map' ); ?></label> <input type="date" id="mdm-after" name="after" value="<?php echo esc_attr( $filters['after'] ); ?>"> <label for="mdm-before"><?php esc_html_e( 'Uploaded through', 'media-dependency-map' ); ?></label> <input type="date" id="mdm-before" name="before" value="<?php echo esc_attr( $filters['before'] ); ?>"></p>
		<p><label for="mdm-sort"><?php esc_html_e( 'Sort by', 'media-dependency-map' ); ?></label> <select id="mdm-sort" name="sort">
		<?php
		foreach ( array(
			'date'    => __( 'Upload date', 'media-dependency-map' ),
			'title'   => __( 'Title', 'media-dependency-map' ),
			'usage'   => __( 'Usage count', 'media-dependency-map' ),
			'scanned' => __( 'Last reference scan', 'media-dependency-map' ),
		) as $value => $label ) :
			?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['sort'], $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
		<label for="mdm-order"><?php esc_html_e( 'Order', 'media-dependency-map' ); ?></label> <select id="mdm-order" name="order"><option value="desc" <?php selected( $filters['order'], 'desc' ); ?>><?php esc_html_e( 'Descending', 'media-dependency-map' ); ?></option><option value="asc" <?php selected( $filters['order'], 'asc' ); ?>><?php esc_html_e( 'Ascending', 'media-dependency-map' ); ?></option></select>
		<button class="button"><?php esc_html_e( 'Apply filters', 'media-dependency-map' ); ?></button></p></form>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_merge( $filters, array( 'action' => 'mdm_export' ) ), admin_url( 'admin-post.php' ) ), 'mdm_export' ) ); ?>"><?php esc_html_e( 'Export filtered CSV', 'media-dependency-map' ); ?></a></p>
		<table class="widefat striped"><caption class="screen-reader-text"><?php esc_html_e( 'Attachments and known references', 'media-dependency-map' ); ?></caption><thead><tr><th scope="col"><?php esc_html_e( 'Attachment', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Known usage', 'media-dependency-map' ); ?></th><th scope="col"><?php esc_html_e( 'Uploaded', 'media-dependency-map' ); ?></th></tr></thead>
		<tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr><td><a href="<?php echo esc_url( Controller::url( array( 'attachment_id' => $row->ID ) ) ); ?>"><?php echo esc_html( '' !== $row->post_title ? $row->post_title : (string) $row->ID ); ?></a><br><small>#<?php echo esc_html( $row->ID ); ?></small></td><td><?php echo esc_html( $row->post_mime_type ); ?></td><td><?php echo esc_html( Controller::usage( (int) $row->usage_count, $status ) ); ?></td><td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->post_date ) ); ?></td></tr><?php endforeach; ?>
			<?php
			if ( ! $rows ) :
				?>
	<tr><td colspan="4"><?php esc_html_e( 'No attachments match these filters.', 'media-dependency-map' ); ?></td></tr><?php endif; ?></tbody></table>
	<?php endif; ?>
	<nav aria-label="<?php esc_attr_e( 'Dependency results pagination', 'media-dependency-map' ); ?>"><p>
	<?php
	if ( $page_number > 1 ) :
		?>
		<a class="button" href="<?php echo esc_url( Controller::url( array_merge( $pagination, array( 'paged' => $page_number - 1 ) ) ) ); ?>"><?php esc_html_e( 'Previous page', 'media-dependency-map' ); ?></a><?php endif; ?>
	<?php /* translators: %s: current results page. */ printf( esc_html__( 'Page %s', 'media-dependency-map' ), esc_html( number_format_i18n( $page_number ) ) ); ?>
	<?php
	if ( $has_next ) :
		?>
		<a class="button" href="<?php echo esc_url( Controller::url( array_merge( $pagination, array( 'paged' => $page_number + 1 ) ) ) ); ?>"><?php esc_html_e( 'Next page', 'media-dependency-map' ); ?></a><?php endif; ?>
	</p></nav>
</div>
	<?php
};
