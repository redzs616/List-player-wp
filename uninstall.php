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

// Tracks, including their meta and term relationships.
$plp_track_ids = get_posts(
	array(
		'post_type'        => 'pl_track',
		'post_status'      => 'any',
		'numberposts'      => -1,
		'fields'           => 'ids',
		'suppress_filters' => true,
	)
);

foreach ( $plp_track_ids as $plp_track_id ) {
	wp_delete_post( $plp_track_id, true );
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

// Options.
delete_option( 'plp_settings' );
delete_option( 'plp_db_version' );
