<?php
/**
 * Uninstall routine.
 *
 * Keeping the data is the deliberate default — deleting a plugin by accident must
 * not take the music library and the whole play history with it. Removal only
 * happens when the setting was explicitly turned on beforehand.
 *
 * @package PL_Player
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$plp_settings = get_option( 'plp_settings', array() );

if ( empty( $plp_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Tracks and playlists, including their meta and term relationships.
$plp_post_ids = get_posts(
	array(
		'post_type'        => array( 'pl_track', 'pl_playlist' ),
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

foreach ( $plp_post_ids as $plp_post_id ) {
	wp_delete_post( $plp_post_id, true );
}

// Counters and listening data can also sit on posts of other types the player was
// pointed at — podcast episodes, for instance — which are not ours to delete.
foreach ( array( '_pl_plays', '_pl_likes', '_pl_seconds', '_pl_source_type', '_pl_attachment_id', '_pl_external_url', '_pl_artist', '_pl_album', '_pl_year', '_pl_duration' ) as $plp_meta_key ) {
	delete_post_meta_by_key( $plp_meta_key );
}

// Terms of both taxonomies.
foreach ( array( 'pl_category', 'pl_tag' ) as $plp_taxonomy ) {
	$plp_terms = get_terms(
		array(
			'taxonomy'   => $plp_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	if ( is_wp_error( $plp_terms ) ) {
		continue;
	}

	foreach ( $plp_terms as $plp_term_id ) {
		wp_delete_term( $plp_term_id, $plp_taxonomy );
	}
}

// Plugin tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pl_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pl_likes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pl_segments" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Scheduled maintenance.
$plp_cron = wp_next_scheduled( 'plp_compact_events' );
if ( $plp_cron ) {
	wp_unschedule_event( $plp_cron, 'plp_compact_events' );
}

// Options and cached lookups.
delete_option( 'plp_settings' );
delete_option( 'plp_db_version' );
delete_option( 'plp_last_compaction' );
delete_site_transient( 'plp_github_release' );
