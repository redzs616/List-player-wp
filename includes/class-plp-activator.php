<?php
/**
 * Activation, database schema and upgrade routines.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and maintains the plugin's own tables.
 */
class PLP_Activator {

	const DB_VERSION_OPTION = 'plp_db_version';

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::add_default_settings();

		// `init` has already fired by the time an activation hook runs, so the post
		// type has to be registered by hand before the rewrite rules are rebuilt.
		PLP_Post_Types::register();
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation. Data is left untouched.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Re-runs the schema when the plugin was updated by file copy rather than
	 * through a fresh activation.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) === PLP_DB_VERSION ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Creates the event log and like state tables.
	 *
	 * The aggregate counters live in post meta so the track list can sort on them;
	 * these tables carry the per-event history that meta cannot express.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = plp_events_table();
		$likes   = plp_likes_table();

		// Note: dbDelta is whitespace sensitive — the two spaces after PRIMARY KEY
		// and the comma-separated key columns without spaces are both required.
		$schema = array();

		$schema[] = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			track_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visitor_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY track_created (track_id,created_at),
			KEY type_created (event_type,created_at)
		) {$charset};";

		$schema[] = "CREATE TABLE {$likes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			track_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			visitor_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_like (track_id,user_id,visitor_hash),
			KEY track (track_id)
		) {$charset};";

		foreach ( $schema as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::DB_VERSION_OPTION, PLP_DB_VERSION );
	}

	/**
	 * Writes the default settings without overwriting an existing configuration.
	 */
	private static function add_default_settings() {
		if ( false === get_option( 'plp_settings' ) ) {
			add_option( 'plp_settings', plp_get_settings() );
		}
	}
}
