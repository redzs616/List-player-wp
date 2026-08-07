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
	 * Listening depth
	 * ------------------------------------------------------------------ */

	/**
	 * How many slices a track is divided into for the retention curve.
	 *
	 * Twenty is enough shape to see where listeners drop off, and coarse enough that no
	 * single slice can be tied back to one person's session.
	 */
	const BUCKETS = 20;

	/**
	 * Records how much of a track was actually heard.
	 *
	 * The browser reports this, because only the browser knows. A forged report can
	 * inflate the totals, which is why the same per-minute limit applies as everywhere
	 * else, and why these numbers are presented as listening patterns rather than as
	 * anything to be paid out on.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $seconds Seconds actually played.
	 * @param int[] $buckets Slice indexes that were played.
	 * @return array{seconds:int}
	 */
	public static function record_progress( $post_id, $seconds, array $buckets ) {
		global $wpdb;

		$post_id = absint( $post_id );
		$seconds = max( 0, min( 6 * HOUR_IN_SECONDS, absint( $seconds ) ) );

		$clean = array();
		foreach ( $buckets as $bucket ) {
			$bucket = absint( $bucket );

			if ( $bucket < self::BUCKETS ) {
				$clean[ $bucket ] = true;
			}
		}

		if ( $seconds ) {
			self::bump( $post_id, '_pl_seconds', $seconds );
		}

		if ( $clean ) {
			$table  = plp_segments_table();
			$values = array();

			foreach ( array_keys( $clean ) as $bucket ) {
				$values[] = $wpdb->prepare( '(%d,%d,1)', $post_id, $bucket );
			}

			// One statement for the whole set. ON DUPLICATE KEY makes the increment
			// atomic, so simultaneous listeners cannot overwrite each other.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$wpdb->query(
				"INSERT INTO {$table} ( track_id, bucket, plays ) VALUES "
				. implode( ',', $values )
				. ' ON DUPLICATE KEY UPDATE plays = plays + 1'
			);
		}

		return array( 'seconds' => self::seconds( $post_id ) );
	}

	/**
	 * Total seconds listened for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function seconds( $post_id ) {
		return absint( get_post_meta( absint( $post_id ), '_pl_seconds', true ) );
	}

	/**
	 * The retention curve of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int[] One value per slice, always BUCKETS long.
	 */
	public static function curve( $post_id ) {
		global $wpdb;

		$table = plp_segments_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT bucket, plays FROM {$table} WHERE track_id = %d",
				absint( $post_id )
			),
			ARRAY_A
		);

		$curve = array_fill( 0, self::BUCKETS, 0 );

		foreach ( (array) $rows as $row ) {
			$bucket = absint( $row['bucket'] );

			if ( $bucket < self::BUCKETS ) {
				$curve[ $bucket ] = (int) $row['plays'];
			}
		}

		return $curve;
	}

	/* ---------------------------------------------------------------------
	 * Reporting
	 * ------------------------------------------------------------------ */

	/**
	 * The site's UTC offset in seconds.
	 *
	 * Events are stored in UTC, but a report has to break days where the listener's
	 * midnight is, not where Greenwich's is.
	 *
	 * @return int
	 */
	private static function offset() {
		return (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	}

	/**
	 * Start of a period, as a UTC datetime string.
	 *
	 * @param int $days Number of days back. 0 means the start of today.
	 * @return string
	 */
	private static function since( $days ) {
		$offset         = self::offset();
		$local_now      = time() + $offset;
		$local_midnight = $local_now - ( $local_now % DAY_IN_SECONDS );

		return gmdate( 'Y-m-d H:i:s', $local_midnight - ( absint( $days ) * DAY_IN_SECONDS ) - $offset );
	}

	/**
	 * Headline numbers for the report screen.
	 *
	 * @return array
	 */
	public static function totals() {
		global $wpdb;

		$table = plp_events_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$plays = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_pl_plays'
			)
		);

		$likes = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( CAST( meta_value AS UNSIGNED ) ) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_pl_likes'
			)
		);

		$today = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s",
				self::EVENT_PLAY,
				self::since( 0 )
			)
		);

		$week = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND created_at >= %s",
				self::EVENT_PLAY,
				self::since( 7 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		$tracks = 0;
		foreach ( PLP_Source::post_types() as $post_type ) {
			$counts  = wp_count_posts( $post_type );
			$tracks += isset( $counts->publish ) ? (int) $counts->publish : 0;
		}

		return array(
			'plays'  => $plays,
			'likes'  => $likes,
			'today'  => $today,
			'week'   => $week,
			'tracks' => $tracks,
		);
	}

	/**
	 * Plays per day for the last N days, oldest first.
	 *
	 * Days with no plays are filled in with zero, otherwise the chart would silently
	 * compress quiet stretches and read as busier than reality.
	 *
	 * @param int $days Days to cover.
	 * @return array List of ['date' => 'Y-m-d', 'plays' => int].
	 */
	public static function daily_plays( $days = 30 ) {
		global $wpdb;

		$days   = max( 1, min( 365, absint( $days ) ) );
		$table  = plp_events_table();
		$offset = self::offset();

		// Shifting inside SQL avoids depending on the server's timezone tables, which
		// are often not loaded on shared hosting.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE( created_at + INTERVAL %d SECOND ) AS day, COUNT(*) AS plays
				FROM {$table}
				WHERE event_type = %s AND created_at >= %s
				GROUP BY day
				ORDER BY day ASC",
				$offset,
				self::EVENT_PLAY,
				self::since( $days )
			),
			ARRAY_A
		);

		$found = array();
		foreach ( (array) $rows as $row ) {
			$found[ $row['day'] ] = (int) $row['plays'];
		}

		$series    = array();
		$local_now = time() + $offset;

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', $local_now - ( $i * DAY_IN_SECONDS ) );

			$series[] = array(
				'date'  => $date,
				'plays' => isset( $found[ $date ] ) ? $found[ $date ] : 0,
			);
		}

		return $series;
	}

	/**
	 * Best performing tracks.
	 *
	 * All-time comes from the aggregate counters, which also include anything counted
	 * before the event log existed. A bounded period has to come from the log.
	 *
	 * @param string $metric `plays` or `likes`.
	 * @param int    $limit  How many rows.
	 * @param int    $days   0 for all time.
	 * @return array List of ['id' => int, 'value' => int].
	 */
	public static function top_tracks( $metric = 'plays', $limit = 20, $days = 0 ) {
		global $wpdb;

		$limit  = max( 1, min( 100, absint( $limit ) ) );
		$metric = 'likes' === $metric ? 'likes' : 'plays';

		if ( ! $days ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id AS id, CAST( meta_value AS UNSIGNED ) AS value
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s AND CAST( meta_value AS UNSIGNED ) > 0
					ORDER BY value DESC
					LIMIT %d",
					'plays' === $metric ? '_pl_plays' : '_pl_likes',
					$limit
				),
				ARRAY_A
			);
		} else {
			$table = plp_events_table();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT track_id AS id, COUNT(*) AS value
					FROM {$table}
					WHERE event_type = %s AND created_at >= %s
					GROUP BY track_id
					ORDER BY value DESC
					LIMIT %d",
					'plays' === $metric ? self::EVENT_PLAY : self::EVENT_LIKE,
					self::since( $days ),
					$limit
				),
				ARRAY_A
			);
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row['id'],
				'value' => (int) $row['value'],
			);
		}

		return $out;
	}

	/**
	 * Total plays per category.
	 *
	 * Sums the aggregate counters across each term's posts. A track in several
	 * categories counts in each of them, which is the honest reading of "how much was
	 * this category listened to".
	 *
	 * @param int $limit Maximum rows.
	 * @return array List of ['name' => string, 'taxonomy' => string, 'plays' => int, 'tracks' => int].
	 */
	public static function category_plays( $limit = 20 ) {
		global $wpdb;

		$taxonomies = PLP_Source::all_taxonomies();

		if ( ! $taxonomies ) {
			return array();
		}

		$limit        = max( 1, min( 100, absint( $limit ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );
		$args         = array_merge( array( '_pl_plays' ), $taxonomies, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name AS name, tt.taxonomy AS taxonomy,
					SUM( CAST( pm.meta_value AS UNSIGNED ) ) AS plays,
					COUNT( DISTINCT tr.object_id ) AS tracks
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = tr.object_id AND pm.meta_key = %s
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_status = 'publish'
				WHERE tt.taxonomy IN ({$placeholders})
				GROUP BY tt.term_taxonomy_id, t.name, tt.taxonomy
				ORDER BY plays DESC, tracks DESC
				LIMIT %d",
				$args
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'name'     => (string) $row['name'],
				'taxonomy' => (string) $row['taxonomy'],
				'plays'    => (int) $row['plays'],
				'tracks'   => (int) $row['tracks'],
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
