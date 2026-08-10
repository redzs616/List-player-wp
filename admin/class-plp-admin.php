<?php
/**
 * Admin screens for the track post type.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Track list columns, edit screen assets and the ID3 lookup endpoint.
 */
class PLP_Admin {

	/**
	 * Hooks the admin pieces.
	 */
	public static function init() {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'force_classic_editor' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		add_filter( 'manage_' . PLP_Post_Types::TRACK . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . PLP_Post_Types::TRACK . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PLP_Post_Types::TRACK . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_sorting' ) );

		add_action( 'wp_ajax_plp_audio_meta', array( __CLASS__, 'ajax_audio_meta' ) );
	}

	/**
	 * Keeps the classic editor for tracks.
	 *
	 * A track is a data record rather than a document — the details panel is the
	 * main interface here, and the classic screen gives it a predictable place to
	 * live. REST stays enabled for the post type either way.
	 *
	 * @param bool   $use       Whether to use the block editor.
	 * @param string $post_type Post type being edited.
	 * @return bool
	 */
	public static function force_classic_editor( $use, $post_type ) {
		return PLP_Post_Types::TRACK === $post_type ? false : $use;
	}

	/**
	 * Loads the media picker and the edit screen script.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		// The marker editor appears on every playable post type — podcast episodes get
		// chapters just as much as tracks do.
		if ( in_array( $screen->post_type, PLP_Source::post_types(), true ) ) {
			wp_enqueue_style( 'plp-admin', PLP_URL . 'admin/assets/css/admin.css', array(), PLP_VERSION );
			wp_enqueue_script( 'plp-markers', PLP_URL . 'admin/assets/js/markers.js', array(), PLP_VERSION, true );

			wp_localize_script(
				'plp-markers',
				'PLPMarkers',
				array(
					'max'  => PLP_Markers::MAX,
					'i18n' => array(
						'jump'             => __( 'Odaugrás', 'pl-player' ),
						'remove'           => __( 'Törlés', 'pl-player' ),
						'labelPlaceholder' => __( 'Mi szól itt?', 'pl-player' ),
						'confirmClear'     => __( 'Biztosan törlöd az összes jelölőt erről a felvételről?', 'pl-player' ),
						/* translators: %d: maximum number of markers. */
						'tooMany'          => sprintf( __( 'Egy felvételen legfeljebb %d jelölő lehet.', 'pl-player' ), PLP_Markers::MAX ),
					),
				)
			);
		}

		if ( PLP_Post_Types::TRACK !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'plp-admin',
			PLP_URL . 'admin/assets/css/admin.css',
			array(),
			PLP_VERSION
		);

		wp_enqueue_script(
			'plp-admin',
			PLP_URL . 'admin/assets/js/admin.js',
			array( 'jquery' ),
			PLP_VERSION,
			true
		);

		wp_localize_script(
			'plp-admin',
			'PLPAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'plp_admin' ),
				'i18n'    => array(
					'selectTitle'  => __( 'Hangfájl kiválasztása', 'pl-player' ),
					'selectButton' => __( 'Használom ezt a fájlt', 'pl-player' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Track list columns
	 * ------------------------------------------------------------------ */

	/**
	 * Builds the track list columns, keeping the date last.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['plp_cover'] = __( 'Borító', 'pl-player' );
				$new[ $key ]      = $label;
				$new['plp_artist']   = __( 'Előadó', 'pl-player' );
				$new['plp_duration'] = __( 'Hossz', 'pl-player' );
				$new['plp_source']   = __( 'Hangfájl', 'pl-player' );
				continue;
			}

			$new[ $key ] = $label;
		}

		$new['plp_plays'] = __( 'Lejátszás', 'pl-player' );
		$new['plp_likes'] = __( 'Like', 'pl-player' );

		if ( isset( $new['date'] ) ) {
			$date = $new['date'];
			unset( $new['date'] );
			$new['date'] = $date;
		}

		return $new;
	}

	/**
	 * Renders a single custom column cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Track ID.
	 */
	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'plp_cover':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 48, 48 ), array( 'class' => 'plp-col-cover' ) );
				} else {
					echo '<span class="plp-col-cover plp-col-cover--empty dashicons dashicons-format-audio" aria-hidden="true"></span>';
				}
				break;

			case 'plp_artist':
				$artist = (string) get_post_meta( $post_id, '_pl_artist', true );
				echo $artist ? esc_html( $artist ) : '<span class="plp-muted">—</span>';
				break;

			case 'plp_duration':
				$duration = plp_format_duration( get_post_meta( $post_id, '_pl_duration', true ) );
				echo $duration ? esc_html( $duration ) : '<span class="plp-muted">—</span>';
				break;

			case 'plp_source':
				self::render_source_column( $post_id );
				break;

			case 'plp_plays':
				echo esc_html( number_format_i18n( absint( get_post_meta( $post_id, '_pl_plays', true ) ) ) );
				break;

			case 'plp_likes':
				echo esc_html( number_format_i18n( absint( get_post_meta( $post_id, '_pl_likes', true ) ) ) );
				break;
		}
	}

	/**
	 * Shows where the audio comes from, or warns when there is none.
	 *
	 * @param int $post_id Track ID.
	 */
	private static function render_source_column( $post_id ) {
		if ( ! plp_get_track_audio_url( $post_id ) ) {
			printf(
				'<span class="plp-badge plp-badge--warning">%s</span>',
				esc_html__( 'Nincs hangfájl', 'pl-player' )
			);
			return;
		}

		$is_external = PLP_Meta::SOURCE_EXTERNAL === get_post_meta( $post_id, '_pl_source_type', true );

		printf(
			'<span class="plp-badge">%s</span>',
			$is_external
				? esc_html__( 'Külső URL', 'pl-player' )
				: esc_html__( 'Médiatár', 'pl-player' )
		);
	}

	/**
	 * Marks the artist and counter columns as sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['plp_artist']   = 'plp_artist';
		$columns['plp_duration'] = 'plp_duration';
		$columns['plp_plays']    = 'plp_plays';
		$columns['plp_likes']    = 'plp_likes';

		return $columns;
	}

	/**
	 * Translates a sortable column click into a meta ordering.
	 *
	 * Safe to order by meta_key here because saving a track always writes all of
	 * these keys, so no row gets dropped from the result set.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function apply_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( PLP_Post_Types::TRACK !== $query->get( 'post_type' ) ) {
			return;
		}

		$map = array(
			'plp_artist'   => array( '_pl_artist', 'meta_value' ),
			'plp_duration' => array( '_pl_duration', 'meta_value_num' ),
			'plp_plays'    => array( '_pl_plays', 'meta_value_num' ),
			'plp_likes'    => array( '_pl_likes', 'meta_value_num' ),
		);

		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) || ! isset( $map[ $orderby ] ) ) {
			return;
		}

		$query->set( 'meta_key', $map[ $orderby ][0] );
		$query->set( 'orderby', $map[ $orderby ][1] );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Returns the ID3 data WordPress parsed out of a chosen audio attachment.
	 */
	public static function ajax_audio_meta() {
		check_ajax_referer( 'plp_admin', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pl-player' ) ), 403 );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen hangfájl.', 'pl-player' ) ), 400 );
		}

		wp_send_json_success( PLP_Meta::audio_meta_from_attachment( $attachment_id ) );
	}
}
