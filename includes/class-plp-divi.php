<?php
/**
 * Divi Builder integration.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers a native Divi module so the player can be configured by clicking rather
 * than by typing shortcode attributes.
 *
 * The shortcode stays available and unchanged — this is an alternative front door to
 * the same renderer, not a replacement.
 */
class PLP_Divi {

	/**
	 * Hooks module registration.
	 *
	 * Divi fires `et_builder_ready` once its own classes exist, which is the only safe
	 * moment to extend ET_Builder_Module.
	 */
	public static function init() {
		add_action( 'et_builder_ready', array( __CLASS__, 'register_module' ) );
	}

	/**
	 * Defines and instantiates the module.
	 */
	public static function register_module() {
		if ( ! class_exists( 'ET_Builder_Module' ) || class_exists( 'PLP_Divi_Player_Module' ) ) {
			return;
		}

		require_once PLP_PATH . 'includes/class-plp-divi-module.php';

		new PLP_Divi_Player_Module();
	}

	/**
	 * Category options for the module's dropdown.
	 *
	 * Terms from every taxonomy the player covers, indented to keep the hierarchy
	 * readable in a flat select.
	 *
	 * @return array
	 */
	public static function category_options() {
		$options = array( '' => esc_html__( 'Összes kategória', 'pl-player' ) );

		foreach ( PLP_Source::all_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$by_parent = array();
			foreach ( $terms as $term ) {
				$by_parent[ (int) $term->parent ][] = $term;
			}

			self::flatten_options( $by_parent, 0, 0, $options );
		}

		return $options;
	}

	/**
	 * Adds one level of terms to the options list.
	 *
	 * @param array $by_parent Terms grouped by parent.
	 * @param int   $parent    Parent term ID.
	 * @param int   $depth     Current depth.
	 * @param array $options   Accumulator, by reference.
	 */
	private static function flatten_options( array $by_parent, $parent, $depth, array &$options ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent ] as $term ) {
			$prefix = $depth ? str_repeat( '— ', $depth ) : '';

			$options[ (string) $term->term_id ] = esc_html( $prefix . $term->name );

			self::flatten_options( $by_parent, (int) $term->term_id, $depth + 1, $options );
		}
	}

	/**
	 * Playlist options for the module's dropdown.
	 *
	 * @return array
	 */
	public static function playlist_options() {
		$options = array( '' => esc_html__( 'Nincs — kategória szerint', 'pl-player' ) );

		$playlists = get_posts(
			array(
				'post_type'        => PLP_Post_Types::PLAYLIST,
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		foreach ( $playlists as $playlist ) {
			$count = count( PLP_Playlist::track_ids( $playlist->ID ) );

			$options[ (string) $playlist->ID ] = esc_html(
				sprintf(
					/* translators: 1: playlist name, 2: number of tracks. */
					__( '%1$s (%2$d szám)', 'pl-player' ),
					$playlist->post_title,
					$count
				)
			);
		}

		return $options;
	}

	/**
	 * Post type options for the module's dropdown.
	 *
	 * @return array
	 */
	public static function post_type_options() {
		$options = array( '' => esc_html__( 'Minden bevont típus', 'pl-player' ) );

		foreach ( PLP_Source::post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( $object ) {
				$options[ $post_type ] = esc_html( $object->labels->name );
			}
		}

		return $options;
	}
}
