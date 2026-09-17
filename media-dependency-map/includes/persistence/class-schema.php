<?php
/**
 * Site-local schema and explicit uninstall.
 *
 * @package Bilal\MediaDependencyMap
 */

namespace Bilal\MediaDependencyMap\Persistence;

defined( 'ABSPATH' ) || exit;

/** Owns the plugin's table allowlist and schema version. */
final class Schema {

	const VERSION = '2';
	const TABLES  = array( 'references', 'scan_runs', 'operations', 'operation_items', 'control', 'queue', 'locks', 'paths' );

	/**
	 * Create or upgrade tables idempotently; only publish the version on success.
	 *
	 * @throws \RuntimeException If schema changes fail.
	 * @param \wpdb $db Site database.
	 * @return void
	 */
	public static function install( \wpdb $db ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$prefix  = $db->prefix . 'mdm_';
		$charset = $db->get_charset_collate();
		$schemas = array(
			"CREATE TABLE {$prefix}references (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				generation bigint(20) unsigned NOT NULL DEFAULT 0,
				reference_key char(64) NOT NULL,
				attachment_id bigint(20) unsigned DEFAULT NULL,
				adapter_id varchar(64) NOT NULL,
				adapter_version varchar(32) NOT NULL DEFAULT '1',
				consumer_type varchar(64) NOT NULL,
				consumer_key varchar(191) NOT NULL,
				data_path text NOT NULL,
				reference_kind varchar(64) NOT NULL,
				confidence varchar(16) NOT NULL,
				replaceability varchar(16) NOT NULL DEFAULT 'read-only',
				value_hash char(64) NOT NULL,
				scan_run_id bigint(20) unsigned NOT NULL,
				first_seen_gmt datetime NOT NULL,
				last_seen_gmt datetime NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'current',
				PRIMARY KEY  (id),
				UNIQUE KEY reference_generation (generation,reference_key),
				KEY attachment_generation (generation,attachment_id,status),
				KEY consumer (adapter_id,consumer_type,consumer_key),
				KEY scan_adapter (scan_run_id,adapter_id,status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}control (
				id bigint(20) unsigned NOT NULL,
				active_generation bigint(20) unsigned NOT NULL DEFAULT 0,
				active_run bigint(20) unsigned NOT NULL DEFAULT 0,
				last_run bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				revision bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}locks (
				name varchar(32) NOT NULL,
				token char(36) NOT NULL,
				expires_gmt datetime NOT NULL,
				PRIMARY KEY  (name)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}queue (
				item_key varchar(64) NOT NULL,
				source varchar(16) NOT NULL,
				consumer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				sequence bigint(20) unsigned NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				PRIMARY KEY  (item_key),
				KEY sequence_status (status,sequence)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}paths (
				generation bigint(20) unsigned NOT NULL,
				path_hash char(64) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (generation,path_hash,attachment_id),
				KEY attachment (generation,attachment_id)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}scan_runs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(16) NOT NULL,
				status varchar(16) NOT NULL DEFAULT 'running',
				started_gmt datetime NOT NULL,
				completed_gmt datetime DEFAULT NULL,
				state_json text NOT NULL,
				error_code varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY status (status)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}operations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				source_id bigint(20) unsigned NOT NULL,
				target_id bigint(20) unsigned NOT NULL,
				status varchar(16) NOT NULL,
				created_gmt datetime NOT NULL,
				completed_gmt datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status_created (status,created_gmt)
			) ENGINE=InnoDB $charset;",
			"CREATE TABLE {$prefix}operation_items (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				operation_id bigint(20) unsigned NOT NULL,
				adapter_id varchar(64) NOT NULL,
				consumer_type varchar(64) NOT NULL,
				consumer_key varchar(191) NOT NULL,
				data_path text NOT NULL,
				before_hash char(64) NOT NULL,
				after_hash char(64) NOT NULL,
				before_state longtext NOT NULL,
				status varchar(16) NOT NULL,
				error_code varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY operation_status (operation_id,status)
			) ENGINE=InnoDB $charset;",
		);
		foreach ( $schemas as $sql ) {
			dbDelta( $sql );
			if ( $db->last_error ) {
				throw new \RuntimeException( 'Media Dependency Map schema installation failed.' );
			}
		}
		foreach ( self::TABLES as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema verification requires uncached metadata.
			$table = $db->get_row( $db->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $prefix . $suffix ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL metadata uses Engine.
			if ( ! $table || 'InnoDB' !== $table->Engine ) {
				throw new \RuntimeException( 'Media Dependency Map requires transactional InnoDB tables.' );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Remove the superseded uniqueness constraint after installing its replacement.
		$legacy = $db->get_results( $db->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $prefix . 'references', 'reference_key' ) );
		if ( $legacy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Allow generation-scoped reference keys after migration.
			if ( false === $db->query( $db->prepare( 'ALTER TABLE %i DROP INDEX reference_key', $prefix . 'references' ) ) ) {
				throw new \RuntimeException( 'Reference generation migration failed.' );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Singleton control row is idempotent.
		if ( false === $db->query( $db->prepare( 'INSERT IGNORE INTO %i (id) VALUES (1)', $prefix . 'control' ) ) ) {
			throw new \RuntimeException( 'Scan control initialization failed.' );
		}
		update_option( 'mdm_schema_version', self::VERSION, false );
	}

	/**
	 * Remove only this plugin's current-site tables after opt-in.
	 *
	 * @throws \RuntimeException If schema changes fail.
	 * @param \wpdb $db Site database.
	 */
	public static function uninstall( \wpdb $db ) {
		$settings = get_option( 'mdm_settings', array() );
		if ( empty( $settings['remove_data_on_uninstall'] ) ) {
			return;
		}
		foreach ( self::TABLES as $suffix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Explicitly requested uninstall of allowlisted tables.
			$result = $db->query( $db->prepare( 'DROP TABLE IF EXISTS %i', $db->prefix . 'mdm_' . $suffix ) );
			if ( false === $result ) {
				throw new \RuntimeException( 'Media Dependency Map table removal failed.' );
			}
		}
		delete_option( 'mdm_schema_version' );
	}
}
