<?php
/**
 * REST API routes.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the player's data and accepts play and like events.
 */
class PLP_Rest {

	const NAMESPACE_V1 = 'plplayer/v1';

	/**
	 * Hooks route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers every route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/tracks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_tracks' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'terms'     => array(
						'description' => __( 'Kategória vagy címke azonosítók, vesszővel.', 'pl-player' ),
						'type'        => 'string',
						'default'     => '',
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'search'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'orderby'   => array(
						'type'    => 'string',
						'enum'    => array( 'date', 'title', 'plays', 'likes', 'random', 'menu_order' ),
						'default' => 'date',
					),
					'order'     => array(
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
						'default' => 'desc',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/categories',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_categories' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/counters',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_counters' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ids' => array(
						'description' => __( 'Poszt azonosítók, vesszővel.', 'pl-player' ),
						'type'        => 'string',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/tracks/(?P<id>\d+)/play',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_play' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'completed' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/tracks/(?P<id>\d+)/like',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_like' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------ */

	/**
	 * Returns a page of playable posts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_tracks( WP_REST_Request $request ) {
		$post_types = PLP_Source::post_types();
		$requested  = sanitize_key( (string) $request->get_param( 'post_type' ) );

		if ( $requested && in_array( $requested, $post_types, true ) ) {
			$post_types = array( $requested );
		}

		$args = PLP_Source::query_args(
			array(
				'post_types' => $post_types,
				'terms'      => array_map( 'absint', explode( ',', (string) $request->get_param( 'terms' ) ) ),
				'search'     => (string) $request->get_param( 'search' ),
				'orderby'    => (string) $request->get_param( 'orderby' ),
				'order'      => (string) $request->get_param( 'order' ),
				'per_page'   => (int) $request->get_param( 'per_page' ),
				'page'       => (int) $request->get_param( 'page' ),
			)
		);

		$query  = new WP_Query( $args );
		$tracks = array();

		foreach ( $query->posts as $post ) {
			$data = PLP_Source::track_data( $post->ID );

			// Posts with no resolvable audio are simply left out rather than shown as
			// a row that cannot play.
			if ( $data ) {
				$tracks[] = $data;
			}
		}

		$response = rest_ensure_response(
			array(
				'tracks' => $tracks,
				'total'  => (int) $query->found_posts,
				'pages'  => (int) $query->max_num_pages,
				'page'   => (int) $request->get_param( 'page' ),
			)
		);

		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * Returns the category tree of every supported taxonomy.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_categories() {
		$out = array();

		foreach ( PLP_Source::all_taxonomies() as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );

			if ( ! $object ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$items = array();

			foreach ( $terms as $term ) {
				$items[] = array(
					'id'     => (int) $term->term_id,
					'name'   => $term->name,
					'slug'   => $term->slug,
					'parent' => (int) $term->parent,
					'count'  => (int) $term->count,
				);
			}

			$out[] = array(
				'taxonomy' => $taxonomy,
				'label'    => $object->labels->name,
				'terms'    => $items,
			);
		}

		return rest_ensure_response( array( 'groups' => $out ) );
	}

	/**
	 * Returns counters and the visitor's like state for a batch of posts.
	 *
	 * Kept separate from the track payload so a page cache cannot freeze the numbers:
	 * the HTML can be cached while these values stay live.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_counters( WP_REST_Request $request ) {
		$ids = array_values(
			array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) )
		);

		$ids = array_slice( array_unique( $ids ), 0, 100 );

		$response = rest_ensure_response( array( 'counters' => PLP_Stats::counters( $ids ) ) );

		// Never cache: these numbers and the like state are per visitor.
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Records a play.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_play( WP_REST_Request $request ) {
		$guard = self::guard_write( 'play', 60, MINUTE_IN_SECONDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$post_id = absint( $request['id'] );

		if ( ! PLP_Source::is_playable( $post_id ) ) {
			return new WP_Error(
				'plp_not_playable',
				__( 'Ez a szám nem játszható le.', 'pl-player' ),
				array( 'status' => 404 )
			);
		}

		if ( $request->get_param( 'completed' ) ) {
			PLP_Stats::record_complete( $post_id );
		}

		$result = PLP_Stats::record_play( $post_id );

		return self::no_store( rest_ensure_response( $result ) );
	}

	/**
	 * Toggles a like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_like( WP_REST_Request $request ) {
		$guard = self::guard_write( 'like', 30, MINUTE_IN_SECONDS );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$settings = plp_get_settings();

		if ( empty( $settings['guest_likes'] ) && ! is_user_logged_in() ) {
			return new WP_Error(
				'plp_login_required',
				__( 'A kedveléshez be kell jelentkezned.', 'pl-player' ),
				array( 'status' => 401 )
			);
		}

		$post_id = absint( $request['id'] );

		if ( ! PLP_Source::is_playable( $post_id ) ) {
			return new WP_Error(
				'plp_not_playable',
				__( 'Ez a szám nem kedvelhető.', 'pl-player' ),
				array( 'status' => 404 )
			);
		}

		return self::no_store( rest_ensure_response( PLP_Stats::toggle_like( $post_id ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------ */

	/**
	 * Same-origin and rate limit checks for the write routes.
	 *
	 * These endpoints deliberately do not demand a nonce. On a page-cached site the
	 * HTML — and any nonce printed into it — is shared between visitors and goes stale,
	 * so a nonce would break the feature for exactly the visitors it is meant to
	 * protect. Origin checking plus rate limiting is the right trade here: the worst a
	 * forged request can achieve is a like on a track.
	 *
	 * @param string $bucket Rate limit bucket.
	 * @param int    $max    Requests per window.
	 * @param int    $window Window in seconds.
	 * @return true|WP_Error
	 */
	private static function guard_write( $bucket, $max, $window ) {
		if ( ! self::is_same_origin() ) {
			return new WP_Error(
				'plp_bad_origin',
				__( 'Érvénytelen kérés.', 'pl-player' ),
				array( 'status' => 403 )
			);
		}

		if ( PLP_Visitor::is_rate_limited( $bucket, $max, $window ) ) {
			return new WP_Error(
				'plp_rate_limited',
				__( 'Túl sok kérés érkezett. Próbáld újra kicsit később.', 'pl-player' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Whether the request came from this site.
	 *
	 * @return bool
	 */
	private static function is_same_origin() {
		$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$origin    = get_http_origin();

		if ( $origin ) {
			$origin_host = (string) wp_parse_url( $origin, PHP_URL_HOST );

			if ( $origin_host && 0 === strcasecmp( $origin_host, $site_host ) ) {
				return true;
			}

			/**
			 * Filters whether a foreign origin may post events.
			 *
			 * @param bool   $allowed Default false.
			 * @param string $origin  Origin header.
			 */
			return (bool) apply_filters( 'plp_allow_origin', false, $origin );
		}

		// wp_get_referer() only returns same-host referers, so a present but foreign
		// referer fails here as it should.
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return (bool) wp_get_referer();
		}

		// Neither header sent — some browsers omit both on same-origin requests. The
		// rate limiter still applies.
		return true;
	}

	/**
	 * Marks a response as never cacheable.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( $response ) {
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}
}
