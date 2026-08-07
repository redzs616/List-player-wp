<?php
/**
 * Shared helpers.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Formats a duration in seconds as m:ss, or h:mm:ss past the hour mark.
 *
 * @param int $seconds Duration in seconds.
 * @return string Empty string when there is nothing to show.
 */
function plp_format_duration( $seconds ) {
	$seconds = absint( $seconds );
	if ( ! $seconds ) {
		return '';
	}

	$hours   = (int) floor( $seconds / 3600 );
	$minutes = (int) floor( ( $seconds % 3600 ) / 60 );
	$secs    = $seconds % 60;

	if ( $hours ) {
		return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
	}

	return sprintf( '%d:%02d', $minutes, $secs );
}

/**
 * Returns the playable URL of a track, whichever source it was set up with.
 *
 * @param int $post_id Track ID.
 * @return string Empty string when the track has no usable audio yet.
 */
function plp_get_track_audio_url( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id ) {
		return '';
	}

	if ( PLP_Meta::SOURCE_EXTERNAL === get_post_meta( $post_id, '_pl_source_type', true ) ) {
		$url = get_post_meta( $post_id, '_pl_external_url', true );
		return $url ? $url : '';
	}

	$attachment_id = absint( get_post_meta( $post_id, '_pl_attachment_id', true ) );
	if ( ! $attachment_id ) {
		return '';
	}

	$url = wp_get_attachment_url( $attachment_id );
	return $url ? $url : '';
}

/**
 * Plugin settings with defaults filled in.
 *
 * @return array
 */
function plp_get_settings() {
	$defaults = array(
		// Which post types the player covers. Podcast episodes and similar content can
		// be added here without moving anything — see PLP_Source.
		'post_types'               => array( PLP_Post_Types::TRACK ),
		'public_stats'             => 1,
		// Total listening time and the retention curve. Needs the browser to report
		// progress, so it is a separate switch from the plain counters.
		'public_listening'         => 1,
		// Public traffic trend. Off-limits for some sites, since it reveals how busy
		// the site actually is.
		'public_trend'             => 1,
		'guest_likes'              => 1,
		'play_threshold_seconds'   => 15,
		'play_threshold_percent'   => 30,
		'play_cooldown_hours'      => 6,
		'delete_data_on_uninstall' => 0,
		// GitHub repository as `owner/name`, used for update checks. A token for a
		// private repository goes in wp-config.php as PLP_GITHUB_TOKEN, never here.
		'github_repo'              => '',
	);

	$saved = get_option( 'plp_settings', array() );

	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Event log table name.
 *
 * @return string
 */
function plp_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'pl_events';
}

/**
 * Like state table name.
 *
 * @return string
 */
function plp_likes_table() {
	global $wpdb;
	return $wpdb->prefix . 'pl_likes';
}

/**
 * Listening depth table name.
 *
 * @return string
 */
function plp_segments_table() {
	global $wpdb;
	return $wpdb->prefix . 'pl_segments';
}

/**
 * Formats a duration as a rounded, human phrase: "14 óra 20 perc".
 *
 * Used for public totals, where the exact second is noise.
 *
 * @param int $seconds Total seconds.
 * @return string
 */
function plp_format_listening_time( $seconds ) {
	$seconds = absint( $seconds );

	if ( $seconds < 60 ) {
		/* translators: %s: number of seconds. */
		return sprintf( _n( '%s másodperc', '%s másodperc', $seconds, 'pl-player' ), number_format_i18n( $seconds ) );
	}

	$hours   = (int) floor( $seconds / 3600 );
	$minutes = (int) floor( ( $seconds % 3600 ) / 60 );

	if ( ! $hours ) {
		/* translators: %s: number of minutes. */
		return sprintf( _n( '%s perc', '%s perc', $minutes, 'pl-player' ), number_format_i18n( $minutes ) );
	}

	if ( ! $minutes ) {
		/* translators: %s: number of hours. */
		return sprintf( _n( '%s óra', '%s óra', $hours, 'pl-player' ), number_format_i18n( $hours ) );
	}

	return sprintf(
		/* translators: 1: hours, 2: minutes. */
		__( '%1$s óra %2$s perc', 'pl-player' ),
		number_format_i18n( $hours ),
		number_format_i18n( $minutes )
	);
}
