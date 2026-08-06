<?php
/**
 * The statistics engine: counters, events and likes.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records plays and likes.
 *
 * Two layers on purpose. The aggregate counters live in post meta so listings can
 * sort on them cheaply; the event table keeps the history that meta cannot express,
 * which is what makes "plays this week" possible later on.
 */
class PLP_Stats {

	const EVENT_PLAY     = 'play';
	const EVENT_COMPLETE = 'complete';
	const EVENT_LIKE     = 'like';
	const EVENT_UNLIKE   = 'unlike';

	/**
	 * Hooks counter seeding.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'seed_counters_on_save' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Counter seeding
	 * ------------------------------------------------------------------ */

	/**
	 * Gives every newly saved playable post its two counters.
	 *
	 * Ordering a listing by `_pl_plays` silently drops any post that lacks the key, so
	 * the counters have to exist from the start — including on podcast episodes the
	 * plugin did not create.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function seed_counters_on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, PLP_Source::post_types(), true ) ) {
			return;
		}

		add_post_meta( $post_id, '_pl_plays', 0, true );
		add_post_meta( $post_id, '_pl_likes', 0, true );
	}

	/**
	 * Seeds the counters of every existing post of a type, in one pass.
	 *
	 * Run when a post type is switched on in the settings — looping through thousands
	 * of posts in PHP would be needlessly slow, so the database does the work.
	 *
	 * @param string $post_type Post type.
	 * @return int Rows created.
	 */
	public static function backfill_counters( $post_type ) {
		global $wpdb;

		$post_type = sanitize_key( $post_type );

		if ( ! post_type_exists( $post_type ) ) {
			return 0;
		}

		$created = 0;

		foreach ( array( '_pl_plays', '_pl_likes' ) as $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
					SELECT p.ID, %s, '0'
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
					WHERE p.post_type = %s
					  AND p.post_status = 'publish'
					  AND m.post_id IS NULL",
					$meta_key,
					$meta_key,
					$post_type
				)
			);

			$created += (int) $result;
		}

		// Direct SQL bypasses the object cache, so a persistent one has to be told.
		if ( $created && function_exists( 'wp_cache_flush_group' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( 'post_meta' );
		}

		return $created;
	}

	/* ---------------------------------------------------------------------
	 * Plays
	 * ------------------------------------------------------------------ */

	/**
	 * Records a play, unless this visitor already had one counted recently.
	 *
	 * The listening threshold itself is enforced in the browser — the server cannot
	 * observe how much audio actually came out of the speakers. What the server can
	 * do, and does here, is stop the same visitor from counting the same track over
	 * and over.
	 *
	 * @param int $post_id Post ID.
	 * @return array{counted:bool,plays:int}
	 */
	public static function record_play( $post_id ) {
		$post_id = absint( $post_id );

		if ( self::played_recently( $post_id ) ) {
			return array(
				'counted' => false,
				'plays'   => self::plays( $post_id ),
			);
		}

		self::log_event( $post_id, self::EVENT_PLAY );
		self::bump( $post_id, '_pl_plays', 1 );

		return array(
			'counted' => true,
			'plays'   => self::plays( $post_id ),
		);
	}

	/**
	 * Whether this visitor already had a play counted inside the cooldown window.
	 *
	 * Answered from the event table rather than a transient: it is the source of truth
	 * anyway, the query is covered by an index, and it keeps the options table from
	 * filling up with one row per visitor and track.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function played_recently( $post_id ) {
		global $wpdb;

		$settings = plp_get_settings();
		$hours    = max( 1, (int) $settings['play_cooldown_hours'] );
		$since    = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );

		$user_id = get_current_user_id();
		$hash    = PLP_Visitor::visitor_hash();
		$table   = plp_events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE track_id = %d
				  AND event_type = %s
				  AND created_at > %s
				  AND ( ( %d > 0 AND user_id = %d ) OR ( %d = 0 AND visitor_hash = %s ) )
				LIMIT 1",
				$post_id,
				self::EVENT_PLAY,
				$since,
				$user_id,
				$user_id,
				$user_id,
				$hash
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		return (bool) $found;
	}

	/**
	 * Records that a track was listened to the end.
	 *
	 * Never bumps the play counter — that already happened at the threshold. This is
	 * only here so completion rate becomes measurable later.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function record_complete( $post_id ) {
		self::log_event( absint( $post_id ), self::EVENT_COMPLETE );
	}

	/* ---------------------------------------------------------------------
	 * Likes
	 * ------------------------------------------------------------------ */

	/**
	 * Turns a like on or off for the current visitor.
	 *
	 * @param int $post_id Post ID.
	 * @return array{liked:bool,likes:int}
	 */
	public static function toggle_like( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		$table   = plp_likes_table();
		$user_id = get_current_user_id();
		$hash    = PLP_Visitor::visitor_hash();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE track_id = %d AND user_id = %d AND visitor_hash = %s",
				$post_id,
				$user_id,
				$hash
			)
		);

		if ( $existing ) {
			$wpdb->delete( $table, array( 'id' => (int) $existing ), array( '%d' ) );
			self::bump( $post_id, '_pl_likes', -1 );
			self::log_event( $post_id, self::EVENT_UNLIKE );

			return array(
				'liked' => false,
				'likes' => self::likes( $post_id ),
			);
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'track_id'     => $post_id,
				'user_id'      => $user_id,
				'visitor_hash' => $hash,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		// The unique index is the real guard here: if two requests race, the second
		// insert fails instead of double counting.
		if ( ! $inserted ) {
			return array(
				'liked' => true,
				'likes' => self::likes( $post_id ),
			);
		}

		self::bump( $post_id, '_pl_likes', 1 );
		self::log_event( $post_id, self::EVENT_LIKE );

		return array(
			'liked' => true,
			'likes' => self::likes( $post_id ),
		);
	}

	/**
	 * Whether the current visitor has liked a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_liked( $post_id ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$hash    = PLP_Visitor::visitor_hash_readonly();

		// A guest with no cookie yet cannot have liked anything.
		if ( ! $user_id && '' === $hash ) {
			return false;
		}

		$table = plp_likes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE track_id = %d AND user_id = %d AND visitor_hash = %s",
				absint( $post_id ),
				$user_id,
				$hash
			)
		);
	}

	/**
	 * Which of these posts the current visitor has liked.
	 *
	 * One query for the whole list instead of one per row.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int[]
	 */
	public static function liked_among( array $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );

		if ( ! $post_ids ) {
			return array();
		}

		$user_id = get_current_user_id();
		$hash    = PLP_Visitor::visitor_hash_readonly();

		// A guest with no cookie yet cannot have liked anything — skip the query.
		if ( ! $user_id && '' === $hash ) {
			return array();
		}

		$table        = plp_likes_table();
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$args = array_merge( $post_ids, array( $user_id, $hash ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT track_id FROM {$table}
				WHERE track_id IN ({$placeholders}) AND user_id = %d AND visitor_hash = %s",
				$args
			)
		);

		return array_map( 'absint', (array) $rows );
	}

	/* ---------------------------------------------------------------------
	 * Reading counters
	 * ------------------------------------------------------------------ */

	/**
	 * Play count of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function plays( $post_id ) {
		return absint( get_post_meta( absint( $post_id ), '_pl_plays', true ) );
	}

	/**
	 * Like count of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function likes( $post_id ) {
		return absint( get_post_meta( absint( $post_id ), '_pl_likes', true ) );
	}

	/**
	 * Counters plus the visitor's own like state for a batch of posts.
	 *
	 * This is what the front end asks for separately from the page HTML, so a page
	 * cache cannot freeze the numbers.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array
	 */
	public static function counters( array $post_ids ) {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		$liked    = self::liked_among( $post_ids );
		$out      = array();

		foreach ( $post_ids as $post_id ) {
			$out[ (string) $post_id ] = array(
				'plays' => self::plays( $post_id ),
				'likes' => self::likes( $post_id ),
				'liked' => in_array( $post_id, $liked, true ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------ */

	/**
	 * Moves a counter by a delta, atomically.
	 *
	 * A read-modify-write would quietly lose counts whenever two visitors hit the same
	 * track in the same moment, so the arithmetic happens inside the UPDATE.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Counter meta key.
	 * @param int    $delta    Amount to add.
	 */
	private static function bump( $post_id, $meta_key, $delta ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				SET meta_value = GREATEST( 0, CAST( meta_value AS SIGNED ) + %d )
				WHERE post_id = %d AND meta_key = %s",
				(int) $delta,
				absint( $post_id ),
				$meta_key
			)
		);

		// No rows touched usually means the counter does not exist yet. With
		// $unique = true this is also a no-op when another request just created it.
		if ( ! $affected ) {
			add_post_meta( $post_id, $meta_key, max( 0, (int) $delta ), true );
		}

		wp_cache_delete( absint( $post_id ), 'post_meta' );
	}

	/**
	 * Appends one row to the event log.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $event_type Event type.
	 */
	private static function log_event( $post_id, $event_type ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			plp_events_table(),
			array(
				'track_id'     => absint( $post_id ),
				'event_type'   => $event_type,
				'user_id'      => get_current_user_id(),
				'visitor_hash' => PLP_Visitor::visitor_hash(),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}
}
