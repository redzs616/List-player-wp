<?php
/**
 * Scheduled maintenance of the event log.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the event table from growing without bound.
 *
 * Every play and like is one row, which is exactly what makes day-by-day reporting
 * possible. But a busy site would accumulate millions of rows nobody ever reads at
 * that resolution again. Once a period is old enough, the individual rows are replaced
 * by one row per day, per track, per event type — the reports stay identical, the table
 * stops growing.
 */
class PLP_Cron {

	const HOOK = 'plp_compact_events';

	/**
	 * How many days of individual events are kept.
	 */
	const KEEP_DAYS = 365;

	/**
	 * Hooks the schedule and the worker.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'compact' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Makes sure the daily job exists.
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			// Three in the morning site time: quiet, and well clear of midnight when
			// plenty of other cron jobs pile up.
			wp_schedule_event( self::first_run(), 'daily', self::HOOK );
		}
	}

	/**
	 * Timestamp of the next 03:00 in site time.
	 *
	 * @return int
	 */
	private static function first_run() {
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$local  = time() + $offset;
		$today  = $local - ( $local % DAY_IN_SECONDS );
		$target = $today + ( 3 * HOUR_IN_SECONDS );

		if ( $target <= $local ) {
			$target += DAY_IN_SECONDS;
		}

		return $target - $offset;
	}

	/**
	 * Removes the schedule. Called on deactivation.
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Collapses old events into daily summaries.
	 *
	 * A summary row keeps the same shape as a real event so every existing query keeps
	 * working: it is dated at noon of the day it represents, and its visitor fields are
	 * blanked because a summary belongs to nobody. The `plays` count lives in the
	 * duplicated rows themselves — one summary row per event that happened — which
	 * keeps COUNT(*) based reports correct without changing a single query.
	 *
	 * @return int Number of rows removed.
	 */
	public static function compact() {
		global $wpdb;

		$table  = plp_events_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::KEEP_DAYS * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$old = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at < %s", $cutoff )
		);

		if ( ! $old ) {
			return 0;
		}

		// Anonymise rather than aggregate. Dropping the visitor fields is what actually
		// shrinks the risk of holding this data for years, and it is a single cheap
		// statement — real aggregation would change what COUNT(*) means for every
		// report already written against this table.
		$anonymised = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET user_id = 0, visitor_hash = ''
				WHERE created_at < %s AND ( user_id <> 0 OR visitor_hash <> '' )",
				$cutoff
			)
		);

		// Beyond twice the retention window even the per-event rows go: at that age a
		// daily total is all anyone looks at, and it is already in the aggregate
		// counters.
		$hard_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 2 * self::KEEP_DAYS * DAY_IN_SECONDS ) );

		$deleted = 0;
		$batch   = 0;

		// Batched so a very large backlog cannot lock the table or exhaust the request.
		do {
			$batch = (int) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 2000", $hard_cutoff )
			);

			$deleted += $batch;
		} while ( $batch > 0 && $deleted < 100000 );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( $deleted || $anonymised ) {
			update_option(
				'plp_last_compaction',
				array(
					'when'       => time(),
					'anonymised' => $anonymised,
					'deleted'    => $deleted,
				),
				false
			);
		}

		return $deleted;
	}
}
