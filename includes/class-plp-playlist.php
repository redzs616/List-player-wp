<?php
/**
 * Hand-assembled playlists.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores and edits the ordered track list of a playlist.
 *
 * The order is the whole point, so the IDs are kept as one ordered string rather than
 * as repeated meta rows — meta has no reliable order of its own.
 */
class PLP_Playlist {

	const META = '_pl_tracks';

	/**
	 * Hooks the editor and the search endpoint.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . PLP_Post_Types::PLAYLIST, array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_plp_search_tracks', array( __CLASS__, 'ajax_search' ) );

		add_filter( 'manage_' . PLP_Post_Types::PLAYLIST . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . PLP_Post_Types::PLAYLIST . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/**
	 * The ordered track IDs of a playlist.
	 *
	 * @param int $playlist_id Playlist ID.
	 * @return int[]
	 */
	public static function track_ids( $playlist_id ) {
		$raw = (string) get_post_meta( absint( $playlist_id ), self::META, true );

		if ( '' === $raw ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * Resolves a playlist reference — ID or slug — to a playlist ID.
	 *
	 * @param string|int $reference ID or slug.
	 * @return int Zero when not found.
	 */
	public static function resolve( $reference ) {
		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return 0;
		}

		if ( ctype_digit( $reference ) ) {
			$post = get_post( absint( $reference ) );

			return ( $post && PLP_Post_Types::PLAYLIST === $post->post_type ) ? (int) $post->ID : 0;
		}

		$post = get_page_by_path( sanitize_title( $reference ), OBJECT, PLP_Post_Types::PLAYLIST );

		return $post ? (int) $post->ID : 0;
	}

	/**
	 * Every published playlist, with the facts an index page needs.
	 *
	 * @param int $limit Maximum playlists.
	 * @return array
	 */
	public static function all( $limit = 100 ) {
		$playlists = get_posts(
			array
			(
				'post_type'        => PLP_Post_Types::PLAYLIST,
				'post_status'      => 'publish',
				'numberposts'      => max( 1, min( 200, absint( $limit ) ) ),
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$out = array();

		foreach ( $playlists as $playlist ) {
			$ids     = self::track_ids( $playlist->ID );
			$seconds = 0;

			foreach ( $ids as $track_id ) {
				$seconds += absint( get_post_meta( $track_id, '_pl_duration', true ) );
			}

			$thumbnail = (int) get_post_thumbnail_id( $playlist->ID );

			$out[] = array(
				'id'       => (int) $playlist->ID,
				'title'    => get_the_title( $playlist ),
				'slug'     => $playlist->post_name,
				'count'    => count( $ids ),
				'seconds'  => $seconds,
				'duration' => plp_format_listening_time( $seconds ),
				'cover'    => $thumbnail ? (string) wp_get_attachment_image_url( $thumbnail, 'medium' ) : '',
				'hue'      => PLP_Source::cover_hue( $playlist->ID ),
				'initial'  => PLP_Source::cover_initial( get_the_title( $playlist ) ),
				'excerpt'  => wp_trim_words( wp_strip_all_tags( (string) $playlist->post_content ), 22, '…' ),
			);
		}

		return $out;
	}

	/**
	 * Playlists the current user may edit, for the "add to playlist" control.
	 *
	 * @return array List of ['id' => int, 'title' => string, 'count' => int, 'has' => bool].
	 */
	public static function editable_for( $track_id = 0 ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array();
		}

		$playlists = get_posts(
			array(
				'post_type'        => PLP_Post_Types::PLAYLIST,
				'post_status'      => array( 'publish', 'draft', 'private' ),
				'numberposts'      => 100,
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$track_id = absint( $track_id );
		$out      = array();

		foreach ( $playlists as $playlist ) {
			if ( ! current_user_can( 'edit_post', $playlist->ID ) ) {
				continue;
			}

			$ids = self::track_ids( $playlist->ID );

			$out[] = array(
				'id'    => (int) $playlist->ID,
				'title' => get_the_title( $playlist ),
				'count' => count( $ids ),
				'has'   => $track_id && in_array( $track_id, $ids, true ),
			);
		}

		return $out;
	}

	/**
	 * Appends a track to a playlist.
	 *
	 * @param int $playlist_id Playlist ID.
	 * @param int $track_id    Track ID.
	 * @return array|WP_Error
	 */
	public static function add_track( $playlist_id, $track_id ) {
		$playlist_id = absint( $playlist_id );
		$track_id    = absint( $track_id );

		$playlist = get_post( $playlist_id );

		if ( ! $playlist || PLP_Post_Types::PLAYLIST !== $playlist->post_type ) {
			return new WP_Error( 'plp_no_playlist', __( 'Nincs ilyen lejátszási lista.', 'pl-player' ) );
		}

		if ( ! current_user_can( 'edit_post', $playlist_id ) ) {
			return new WP_Error( 'plp_forbidden', __( 'Nincs jogosultságod ehhez a listához.', 'pl-player' ) );
		}

		if ( ! PLP_Source::is_playable( $track_id ) ) {
			return new WP_Error( 'plp_not_playable', __( 'Ez a szám nem játszható le.', 'pl-player' ) );
		}

		$ids = self::track_ids( $playlist_id );

		// Already there: report success rather than an error, because the end state the
		// caller asked for is the end state they got.
		if ( in_array( $track_id, $ids, true ) ) {
			return array(
				'id'    => $playlist_id,
				'count' => count( $ids ),
				'added' => false,
			);
		}

		$ids[] = $track_id;

		update_post_meta( $playlist_id, self::META, implode( ',', $ids ) );

		return array(
			'id'    => $playlist_id,
			'count' => count( $ids ),
			'added' => true,
		);
	}

	/**
	 * Creates a playlist, optionally with a first track in it.
	 *
	 * @param string $title    Playlist name.
	 * @param int    $track_id Optional first track.
	 * @return array|WP_Error
	 */
	public static function create( $title, $track_id = 0 ) {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return new WP_Error( 'plp_forbidden', __( 'Nincs jogosultságod listát létrehozni.', 'pl-player' ) );
		}

		$title = sanitize_text_field( (string) $title );
		$title = trim( $title );

		if ( '' === $title ) {
			return new WP_Error( 'plp_no_title', __( 'Adj nevet a listának.', 'pl-player' ) );
		}

		$playlist_id = wp_insert_post(
			array(
				'post_type'   => PLP_Post_Types::PLAYLIST,
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $playlist_id ) ) {
			return $playlist_id;
		}

		$count    = 0;
		$track_id = absint( $track_id );

		if ( $track_id && PLP_Source::is_playable( $track_id ) ) {
			update_post_meta( $playlist_id, self::META, (string) $track_id );
			$count = 1;
		}

		return array(
			'id'    => (int) $playlist_id,
			'title' => $title,
			'count' => $count,
			'added' => $count > 0,
		);
	}

	/* ---------------------------------------------------------------------
	 * Editor
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the track picker to the playlist edit screen.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'plp_playlist_tracks',
			__( 'A lista tartalma', 'pl-player' ),
			array( __CLASS__, 'render_meta_box' ),
			PLP_Post_Types::PLAYLIST,
			'normal',
			'high'
		);
	}

	/**
	 * Loads the picker's assets on the playlist screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || PLP_Post_Types::PLAYLIST !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'plp-playlist', PLP_URL . 'admin/assets/css/playlist.css', array(), PLP_VERSION );
		wp_enqueue_script(
			'plp-playlist',
			PLP_URL . 'admin/assets/js/playlist.js',
			array( 'jquery', 'jquery-ui-sortable' ),
			PLP_VERSION,
			true
		);

		wp_localize_script(
			'plp-playlist',
			'PLPList',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'plp_playlist' ),
				'i18n'    => array(
					'searching' => __( 'Keresés…', 'pl-player' ),
					'noResults' => __( 'Nincs találat.', 'pl-player' ),
					'added'     => __( 'Már a listában van', 'pl-player' ),
					'remove'    => __( 'Eltávolítás', 'pl-player' ),
					'empty'     => __( 'Még nincs szám a listában. Keress rá valamire jobb oldalt, és kattints rá a hozzáadáshoz.', 'pl-player' ),
					/* translators: %d: number of tracks. */
					'count'     => __( '%d szám a listában', 'pl-player' ),
				),
			)
		);
	}

	/**
	 * Renders the picker.
	 *
	 * @param WP_Post $post Playlist being edited.
	 */
	public static function render_meta_box( $post ) {
		$ids = self::track_ids( $post->ID );

		wp_nonce_field( 'plp_save_playlist_' . $post->ID, 'plp_playlist_nonce' );
		?>
		<div class="plp-list-editor">
			<input type="hidden" id="plp_tracks" name="plp_tracks" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />

			<div class="plp-list-editor__grid">

				<div class="plp-list-editor__col">
					<p class="plp-list-editor__head">
						<strong><?php esc_html_e( 'A lista sorrendje', 'pl-player' ); ?></strong>
						<span class="plp-list-editor__count" id="plp-list-count"></span>
					</p>

					<ol class="plp-chosen" id="plp-chosen">
						<?php foreach ( $ids as $id ) : ?>
							<?php self::render_row( $id ); ?>
						<?php endforeach; ?>
					</ol>

					<p class="plp-list-editor__empty" id="plp-list-empty" <?php echo $ids ? 'hidden' : ''; ?>>
						<?php esc_html_e( 'Még nincs szám a listában. Keress rá valamire jobb oldalt, és kattints rá a hozzáadáshoz.', 'pl-player' ); ?>
					</p>

					<p class="description">
						<?php esc_html_e( 'A sorokat fogd meg és húzd a helyükre — ebben a sorrendben fognak szólni.', 'pl-player' ); ?>
					</p>
				</div>

				<div class="plp-list-editor__col">
					<p class="plp-list-editor__head">
						<strong><?php esc_html_e( 'Számok hozzáadása', 'pl-player' ); ?></strong>
					</p>

					<p>
						<input type="search" class="widefat" id="plp-list-search"
							placeholder="<?php esc_attr_e( 'Cím vagy előadó…', 'pl-player' ); ?>"
							autocomplete="off" />
					</p>

					<ul class="plp-results" id="plp-results"></ul>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Renders one chosen row. Also used as the template for rows added by script.
	 *
	 * @param int $id Track ID.
	 */
	public static function render_row( $id ) {
		$data = PLP_Source::track_data( $id );

		if ( ! $data ) {
			// The track was deleted or lost its file; show it so the gap is visible
			// rather than silently dropping it from the list.
			printf(
				'<li class="plp-chosen__row plp-chosen__row--missing" data-id="%1$d">
					<span class="plp-chosen__handle"></span>
					<span class="plp-chosen__meta"><span class="plp-chosen__title">%2$s</span></span>
					<button type="button" class="plp-chosen__remove button-link">%3$s</button>
				</li>',
				(int) $id,
				esc_html__( 'Hiányzó vagy nem lejátszható szám', 'pl-player' ),
				esc_html__( 'Eltávolítás', 'pl-player' )
			);

			return;
		}
		?>
		<li class="plp-chosen__row" data-id="<?php echo esc_attr( (string) $data['id'] ); ?>">
			<span class="plp-chosen__handle" aria-hidden="true"></span>

			<span class="plp-chosen__cover" style="--plp-hue:<?php echo esc_attr( (string) $data['hue'] ); ?>">
				<?php if ( $data['cover'] ) : ?>
					<img src="<?php echo esc_url( $data['cover'] ); ?>" alt="" />
				<?php else : ?>
					<span aria-hidden="true"><?php echo esc_html( $data['initial'] ); ?></span>
				<?php endif; ?>
			</span>

			<span class="plp-chosen__meta">
				<span class="plp-chosen__title"><?php echo esc_html( $data['title'] ); ?></span>
				<?php if ( $data['artist'] ) : ?>
					<span class="plp-chosen__artist"><?php echo esc_html( $data['artist'] ); ?></span>
				<?php endif; ?>
			</span>

			<span class="plp-chosen__duration"><?php echo esc_html( $data['duration_human'] ); ?></span>

			<button type="button" class="plp-chosen__remove button-link">
				<?php esc_html_e( 'Eltávolítás', 'pl-player' ); ?>
			</button>
		</li>
		<?php
	}

	/**
	 * Saves the chosen order.
	 *
	 * @param int $post_id Playlist ID.
	 */
	public static function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$nonce = isset( $_POST['plp_playlist_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['plp_playlist_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'plp_save_playlist_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$raw = isset( $_POST['plp_tracks'] ) ? sanitize_text_field( wp_unslash( $_POST['plp_tracks'] ) ) : '';
		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );

		update_post_meta( $post_id, self::META, implode( ',', $ids ) );
	}

	/* ---------------------------------------------------------------------
	 * Search
	 * ------------------------------------------------------------------ */

	/**
	 * Returns tracks matching a search term.
	 */
	public static function ajax_search() {
		check_ajax_referer( 'plp_playlist', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pl-player' ) ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		$args = array(
			'post_type'           => PLP_Source::post_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => 20,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		if ( '' !== $term ) {
			$args['s'] = $term;
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		$query = new WP_Query( $args );
		$rows  = array();

		foreach ( $query->posts as $post ) {
			$data = PLP_Source::track_data( $post->ID );

			if ( ! $data ) {
				continue;
			}

			$rows[] = array(
				'id'       => $data['id'],
				'title'    => $data['title'],
				'artist'   => $data['artist'],
				'duration' => $data['duration_human'],
				'cover'    => $data['cover'],
				'hue'      => $data['hue'],
				'initial'  => $data['initial'],
			);
		}

		wp_send_json_success( array( 'tracks' => $rows ) );
	}

	/* ---------------------------------------------------------------------
	 * List table
	 * ------------------------------------------------------------------ */

	/**
	 * Adds a track count column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['plp_count']     = __( 'Számok', 'pl-player' );
				$new['plp_shortcode'] = __( 'Beillesztés', 'pl-player' );
			}
		}

		return $new;
	}

	/**
	 * Renders the custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Playlist ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'plp_count' === $column ) {
			echo esc_html( number_format_i18n( count( self::track_ids( $post_id ) ) ) );

			return;
		}

		if ( 'plp_shortcode' === $column ) {
			$post = get_post( $post_id );

			printf(
				'<code>[playlist_player playlist="%s"]</code>',
				esc_html( $post ? $post->post_name : (string) $post_id )
			);
		}
	}
}
