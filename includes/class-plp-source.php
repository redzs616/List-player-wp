<?php
/**
 * Audio source resolution across post types.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out how to play any supported post, whatever put the audio there.
 *
 * Tracks created by this plugin carry their file in plugin meta. Podcast episodes
 * and similar posts made by other tools carry it somewhere else entirely — in an
 * `enclosure` meta field, in an `[audio]` shortcode, or simply as an attachment.
 * This class hides that difference from the rest of the plugin, and the first time
 * it resolves a post it writes the answer into the same meta a track uses. No
 * migration, no duplicated content: posts get adopted as they are played.
 */
class PLP_Source {

	/**
	 * Post types the player is allowed to include.
	 *
	 * The plugin's own track type is always in the list — it cannot be switched off.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$settings = plp_get_settings();
		$types    = array_map( 'sanitize_key', (array) $settings['post_types'] );

		if ( ! in_array( PLP_Post_Types::TRACK, $types, true ) ) {
			$types[] = PLP_Post_Types::TRACK;
		}

		return array_values( array_unique( array_filter( $types, 'post_type_exists' ) ) );
	}

	/**
	 * Taxonomies that can be used to filter the player, grouped by post type.
	 *
	 * @return array<string,string[]>
	 */
	public static function taxonomies() {
		$map = array();

		foreach ( self::post_types() as $post_type ) {
			$taxonomies = get_object_taxonomies( $post_type, 'objects' );

			foreach ( $taxonomies as $taxonomy ) {
				if ( $taxonomy->public && $taxonomy->show_ui ) {
					$map[ $post_type ][] = $taxonomy->name;
				}
			}
		}

		return $map;
	}

	/**
	 * A flat list of every filterable taxonomy.
	 *
	 * @return string[]
	 */
	public static function all_taxonomies() {
		$flat = array();

		foreach ( self::taxonomies() as $taxonomies ) {
			$flat = array_merge( $flat, $taxonomies );
		}

		return array_values( array_unique( $flat ) );
	}

	/**
	 * Whether a post may be played and counted.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_playable( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}

		return '' !== self::audio_url( $post->ID );
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/**
	 * The playable URL of a post, adopting the post into plugin meta on first use.
	 *
	 * @param int $post_id Post ID.
	 * @return string Empty string when no audio could be found.
	 */
	public static function audio_url( $post_id ) {
		$post_id = absint( $post_id );

		self::ensure_meta( $post_id );

		$external = (string) get_post_meta( $post_id, '_pl_external_url', true );
		if ( '' !== $external ) {
			return $external;
		}

		$attachment_id = absint( get_post_meta( $post_id, '_pl_attachment_id', true ) );
		if ( $attachment_id ) {
			return (string) wp_get_attachment_url( $attachment_id );
		}

		return '';
	}

	/**
	 * Fills in the plugin's meta for a post that was not created as a track.
	 *
	 * Runs once per post: the presence of `_pl_source_type` marks it as adopted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function ensure_meta( $post_id ) {
		$post_id = absint( $post_id );

		if ( '' !== (string) get_post_meta( $post_id, '_pl_source_type', true ) ) {
			return;
		}

		$resolved = self::resolve( $post_id );

		if ( '' === $resolved['url'] ) {
			return;
		}

		update_post_meta( $post_id, '_pl_source_type', $resolved['source'] );
		update_post_meta( $post_id, '_pl_attachment_id', $resolved['attachment_id'] );
		update_post_meta(
			$post_id,
			'_pl_external_url',
			PLP_Meta::SOURCE_EXTERNAL === $resolved['source'] ? $resolved['url'] : ''
		);

		if ( $resolved['attachment_id'] ) {
			self::backfill_id3( $post_id, $resolved['attachment_id'] );
		}

		add_post_meta( $post_id, '_pl_plays', 0, true );
		add_post_meta( $post_id, '_pl_likes', 0, true );
	}

	/**
	 * Copies whatever the file knows about itself into empty fields only.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Audio attachment ID.
	 */
	private static function backfill_id3( $post_id, $attachment_id ) {
		$id3 = PLP_Meta::audio_meta_from_attachment( $attachment_id );

		$map = array(
			'_pl_artist'   => 'artist',
			'_pl_album'    => 'album',
			'_pl_duration' => 'duration',
		);

		foreach ( $map as $meta_key => $id3_key ) {
			if ( '' !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
				continue;
			}

			if ( $id3[ $id3_key ] ) {
				update_post_meta( $post_id, $meta_key, $id3[ $id3_key ] );
			}
		}
	}

	/**
	 * Looks for the audio of a post in every place it could plausibly be.
	 *
	 * @param int $post_id Post ID.
	 * @return array{url:string,attachment_id:int,source:string}
	 */
	private static function resolve( $post_id ) {
		$empty = array(
			'url'           => '',
			'attachment_id' => 0,
			'source'        => '',
		);

		// 1. Podcast plugins put the file in the core `enclosure` meta, one value per
		//    line with the URL first.
		$enclosure = (string) get_post_meta( $post_id, 'enclosure', true );
		if ( '' !== $enclosure ) {
			$first = trim( (string) strtok( $enclosure, "\n" ) );

			if ( $first && wp_http_validate_url( $first ) ) {
				return self::describe( $first );
			}
		}

		// 2. An [audio] shortcode in the content — how the podcast episodes on this
		//    site carry their file.
		$content = (string) get_post_field( 'post_content', $post_id );
		if ( '' !== $content && has_shortcode( $content, 'audio' ) ) {
			$url = self::url_from_shortcode( $content );

			if ( '' !== $url ) {
				return self::describe( $url );
			}
		}

		// 3. Any audio file attached to the post.
		$attached = get_attached_media( 'audio', $post_id );
		if ( $attached ) {
			$first = reset( $attached );
			$url   = (string) wp_get_attachment_url( $first->ID );

			if ( '' !== $url ) {
				return array(
					'url'           => $url,
					'attachment_id' => (int) $first->ID,
					'source'        => PLP_Meta::SOURCE_MEDIA,
				);
			}
		}

		return $empty;
	}

	/**
	 * Pulls the first usable file URL out of an [audio] shortcode.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function url_from_shortcode( $content ) {
		if ( ! preg_match_all( '/\[audio\b([^\]]*)\]/i', $content, $matches ) ) {
			return '';
		}

		$keys = array( 'src', 'mp3', 'm4a', 'ogg', 'wav', 'wma', 'flac' );

		foreach ( $matches[1] as $attribute_string ) {
			$attributes = shortcode_parse_atts( $attribute_string );

			if ( ! is_array( $attributes ) ) {
				continue;
			}

			foreach ( $keys as $key ) {
				if ( empty( $attributes[ $key ] ) ) {
					continue;
				}

				$url = esc_url_raw( trim( (string) $attributes[ $key ] ), array( 'http', 'https' ) );

				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}

	/**
	 * Decides whether a URL points at something in the media library.
	 *
	 * A local attachment is worth knowing about: it gives us ID3 tags and survives a
	 * later domain change.
	 *
	 * @param string $url File URL.
	 * @return array{url:string,attachment_id:int,source:string}
	 */
	private static function describe( $url ) {
		$attachment_id = (int) attachment_url_to_postid( $url );

		return array(
			'url'           => $url,
			'attachment_id' => $attachment_id,
			'source'        => $attachment_id ? PLP_Meta::SOURCE_MEDIA : PLP_Meta::SOURCE_EXTERNAL,
		);
	}

	/* ---------------------------------------------------------------------
	 * Querying
	 * ------------------------------------------------------------------ */

	/**
	 * Builds WP_Query arguments for a player listing.
	 *
	 * Shared by the shortcode and the REST route so a filter never behaves one way on
	 * first paint and another way after the visitor changes a dropdown.
	 *
	 * @param array $config Listing configuration.
	 * @return array
	 */
	public static function query_args( array $config ) {
		$config = array_merge(
			array(
				'post_types' => array(),
				'terms'      => array(),
				'search'     => '',
				'orderby'    => 'date',
				'order'      => 'desc',
				'per_page'   => 20,
				'page'       => 1,
			),
			$config
		);

		$post_types = array_values( array_intersect( (array) $config['post_types'], self::post_types() ) );

		if ( ! $post_types ) {
			$post_types = self::post_types();
		}

		$args = array(
			'post_type'           => $post_types,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, min( 100, (int) $config['per_page'] ) ),
			'paged'               => max( 1, (int) $config['page'] ),
			'ignore_sticky_posts' => true,
		);

		// An explicit set of tracks — a hand-built playlist. The chosen order is the
		// point, so it overrides sorting entirely.
		if ( isset( $config['include'] ) ) {
			$include = array_values( array_filter( array_map( 'absint', (array) $config['include'] ) ) );

			// WP_Query treats an empty post__in as "no filter" and would return the whole
			// library, so an empty playlist has to be spelled out as "match nothing".
			$args['post__in']       = $include ? $include : array( 0 );
			$args['orderby']        = 'post__in';
			$args['posts_per_page'] = $include ? count( $include ) : 1;

			unset( $args['paged'] );

			$search = sanitize_text_field( (string) $config['search'] );
			if ( '' !== $search ) {
				$args['s'] = $search;
			}

			return $args;
		}

		$search = sanitize_text_field( (string) $config['search'] );
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$tax_query = self::tax_query( (array) $config['terms'] );
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		return array_merge( $args, self::ordering( (string) $config['orderby'], (string) $config['order'] ) );
	}

	/**
	 * Builds a tax_query spanning every allowed taxonomy.
	 *
	 * The player mixes post types, so a term id alone does not say which taxonomy it
	 * came from — each one is looked up and grouped.
	 *
	 * @param array $term_ids Term IDs.
	 * @return array
	 */
	public static function tax_query( array $term_ids ) {
		$term_ids = array_values( array_filter( array_map( 'absint', $term_ids ) ) );

		if ( ! $term_ids ) {
			return array();
		}

		$allowed  = self::all_taxonomies();
		$by_group = array();

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );

			if ( ! $term instanceof WP_Term || ! in_array( $term->taxonomy, $allowed, true ) ) {
				continue;
			}

			$by_group[ $term->taxonomy ][] = (int) $term->term_id;
		}

		if ( ! $by_group ) {
			return array();
		}

		$tax_query = array( 'relation' => 'OR' );

		foreach ( $by_group as $taxonomy => $ids ) {
			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'term_id',
				'terms'            => $ids,
				'include_children' => true,
			);
		}

		return $tax_query;
	}

	/**
	 * Translates a sort choice into query arguments.
	 *
	 * @param string $orderby Sort key.
	 * @param string $order   Direction.
	 * @return array
	 */
	public static function ordering( $orderby, $order ) {
		$order = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		switch ( $orderby ) {
			case 'plays':
			case 'likes':
				// Safe to order by meta_key: every supported post gets its counters
				// seeded, so nothing silently drops out of the result set.
				return array(
					'meta_key' => 'plays' === $orderby ? '_pl_plays' : '_pl_likes', // phpcs:ignore WordPress.DB.SlowDBQuery
					'orderby'  => 'meta_value_num',
					'order'    => $order,
				);

			case 'title':
				// Always alphabetical: a title list that starts at Z only ever looks
				// like a bug.
				return array(
					'orderby' => 'title',
					'order'   => 'ASC',
				);

			case 'random':
				return array( 'orderby' => 'rand' );

			case 'menu_order':
				return array(
					'orderby' => array(
						'menu_order' => 'ASC',
						'date'       => 'DESC',
					),
				);

			default:
				return array(
					'orderby' => 'date',
					'order'   => $order,
				);
		}
	}

	/* ---------------------------------------------------------------------
	 * Payload
	 * ------------------------------------------------------------------ */

	/**
	 * The player's view of one post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Null when the post cannot be played.
	 */
	public static function track_data( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post ) {
			return null;
		}

		$url = self::audio_url( $post->ID );

		if ( '' === $url ) {
			return null;
		}

		$duration     = absint( get_post_meta( $post->ID, '_pl_duration', true ) );
		$show_stats   = (bool) plp_get_settings()['public_stats'];
		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );

		$data = array(
			'id'             => (int) $post->ID,
			'post_type'      => $post->post_type,
			'title'          => get_the_title( $post ),
			'permalink'      => (string) get_permalink( $post ),
			'artist'         => (string) get_post_meta( $post->ID, '_pl_artist', true ),
			'album'          => (string) get_post_meta( $post->ID, '_pl_album', true ),
			'year'           => (string) get_post_meta( $post->ID, '_pl_year', true ),
			'duration'       => $duration,
			'duration_human' => plp_format_duration( $duration ),
			'audio'          => $url,
			'cover'          => $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '',
			'cover_large'    => $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'large' ) : '',
			'hue'            => self::cover_hue( $post->ID ),
			'initial'        => self::cover_initial( get_the_title( $post ) ),
			// Measured, not guessed — see PLP_Analysis.
			'labels'         => PLP_Analysis::labels( $post->ID ),
			'description'    => self::excerpt( $post ),
			'markers'        => PLP_Markers::for_display( $post->ID ),
			'categories'     => self::term_list( $post ),
		);

		if ( $show_stats ) {
			$data['plays'] = absint( get_post_meta( $post->ID, '_pl_plays', true ) );
			$data['likes'] = absint( get_post_meta( $post->ID, '_pl_likes', true ) );
		}

		/**
		 * Filters the player payload of a single post.
		 *
		 * @param array   $data Payload.
		 * @param WP_Post $post Post object.
		 */
		return apply_filters( 'plp_track_data', $data, $post );
	}

	/**
	 * A stable colour for a post that has no artwork.
	 *
	 * Derived from the ID so every coverless track still looks like its own thing
	 * instead of all of them sharing one flat square.
	 *
	 * @param int $post_id Post ID.
	 * @return int Hue between 0 and 359.
	 */
	public static function cover_hue( $post_id ) {
		return (int) ( ( absint( $post_id ) * 47 ) % 360 );
	}

	/**
	 * The first character of a title, for the placeholder cover.
	 *
	 * @param string $title Track title.
	 * @return string
	 */
	public static function cover_initial( $title ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );

		if ( '' === $title ) {
			return '';
		}

		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( mb_substr( $title, 0, 1 ) )
			: strtoupper( substr( $title, 0, 1 ) );
	}

	/**
	 * A short, safe description for the player.
	 *
	 * Shortcodes have to go before the tags do. A podcast episode's content is often
	 * nothing but `[audio mp3="…"]`, and stripping only HTML left that markup sitting in
	 * the panel as visible text.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private static function excerpt( $post ) {
		$text = (string) $post->post_content;

		// An explicit excerpt is the author's own summary, so it wins.
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			$text = (string) $post->post_excerpt;
		}

		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );

		// Anything left in square brackets is a shortcode this install no longer
		// registers; it is markup either way, not prose.
		$text = preg_replace( '/\[[^\]]*\]/', '', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		return wp_trim_words( $text, 42, '…' );
	}

	/**
	 * Flattens the post's terms from every filterable taxonomy.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function term_list( $post ) {
		$list       = array();
		$taxonomies = self::taxonomies();

		foreach ( (array) ( isset( $taxonomies[ $post->post_type ] ) ? $taxonomies[ $post->post_type ] : array() ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );

			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			foreach ( $terms as $term ) {
				$list[] = array(
					'id'       => (int) $term->term_id,
					'taxonomy' => $term->taxonomy,
					'name'     => $term->name,
					'slug'     => $term->slug,
				);
			}
		}

		return $list;
	}
}
