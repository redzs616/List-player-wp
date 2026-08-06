<?php
/**
 * Post type and taxonomy registration.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the track post type and its two taxonomies.
 */
class PLP_Post_Types {

	const TRACK    = 'pl_track';
	const CATEGORY = 'pl_category';
	const TAG      = 'pl_tag';

	/**
	 * Hooks registration onto `init`.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registers everything. Also called directly on activation.
	 */
	public static function register() {
		self::register_track();
		self::register_category();
		self::register_tag();
	}

	/**
	 * The track post type.
	 */
	private static function register_track() {
		$labels = array(
			'name'                  => __( 'Zeneszámok', 'pl-player' ),
			'singular_name'         => __( 'Zeneszám', 'pl-player' ),
			'menu_name'             => __( 'Lejátszó', 'pl-player' ),
			'all_items'             => __( 'Összes szám', 'pl-player' ),
			'add_new'               => __( 'Új szám', 'pl-player' ),
			'add_new_item'          => __( 'Új zeneszám', 'pl-player' ),
			'edit_item'             => __( 'Zeneszám szerkesztése', 'pl-player' ),
			'new_item'              => __( 'Új zeneszám', 'pl-player' ),
			'view_item'             => __( 'Zeneszám megtekintése', 'pl-player' ),
			'view_items'            => __( 'Zeneszámok megtekintése', 'pl-player' ),
			'search_items'          => __( 'Zeneszámok keresése', 'pl-player' ),
			'not_found'             => __( 'Nem található zeneszám.', 'pl-player' ),
			'not_found_in_trash'    => __( 'Nincs zeneszám a lomtárban.', 'pl-player' ),
			'featured_image'        => __( 'Borítókép', 'pl-player' ),
			'set_featured_image'    => __( 'Borítókép beállítása', 'pl-player' ),
			'remove_featured_image' => __( 'Borítókép eltávolítása', 'pl-player' ),
			'use_featured_image'    => __( 'Legyen ez a borítókép', 'pl-player' ),
			'item_published'        => __( 'Zeneszám közzétéve.', 'pl-player' ),
			'item_updated'          => __( 'Zeneszám frissítve.', 'pl-player' ),
		);

		register_post_type(
			self::TRACK,
			array(
				'labels'       => $labels,
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-format-audio',
				'menu_position' => 25,
				'hierarchical' => false,
				// `page-attributes` gives us menu_order, which the manual playlist
				// ordering in a later phase builds on.
				'supports'     => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'taxonomies'   => array( self::CATEGORY, self::TAG ),
				'has_archive'  => false,
				'rewrite'      => array(
					'slug'       => 'zene',
					'with_front' => false,
				),
				'show_in_rest' => true,
				'rest_base'    => 'pl-tracks',
			)
		);
	}

	/**
	 * Hierarchical category taxonomy — this is what gives the folder-in-folder feel.
	 */
	private static function register_category() {
		$labels = array(
			'name'              => __( 'Kategóriák', 'pl-player' ),
			'singular_name'     => __( 'Kategória', 'pl-player' ),
			'menu_name'         => __( 'Kategóriák', 'pl-player' ),
			'all_items'         => __( 'Összes kategória', 'pl-player' ),
			'parent_item'       => __( 'Szülő kategória', 'pl-player' ),
			'parent_item_colon' => __( 'Szülő kategória:', 'pl-player' ),
			'edit_item'         => __( 'Kategória szerkesztése', 'pl-player' ),
			'update_item'       => __( 'Kategória frissítése', 'pl-player' ),
			'add_new_item'      => __( 'Új kategória', 'pl-player' ),
			'new_item_name'     => __( 'Új kategória neve', 'pl-player' ),
			'search_items'      => __( 'Kategóriák keresése', 'pl-player' ),
			'not_found'         => __( 'Nem található kategória.', 'pl-player' ),
			'back_to_items'     => __( '← Vissza a kategóriákhoz', 'pl-player' ),
		);

		register_taxonomy(
			self::CATEGORY,
			array( self::TRACK ),
			array(
				'labels'            => $labels,
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rest_base'         => 'pl-categories',
				'rewrite'           => array(
					'slug'         => 'zene-kategoria',
					'with_front'   => false,
					'hierarchical' => true,
				),
			)
		);
	}

	/**
	 * Flat tag taxonomy for free-form labelling (mood, tempo, year).
	 */
	private static function register_tag() {
		$labels = array(
			'name'          => __( 'Címkék', 'pl-player' ),
			'singular_name' => __( 'Címke', 'pl-player' ),
			'menu_name'     => __( 'Címkék', 'pl-player' ),
			'all_items'     => __( 'Összes címke', 'pl-player' ),
			'edit_item'     => __( 'Címke szerkesztése', 'pl-player' ),
			'update_item'   => __( 'Címke frissítése', 'pl-player' ),
			'add_new_item'  => __( 'Új címke', 'pl-player' ),
			'new_item_name' => __( 'Új címke neve', 'pl-player' ),
			'search_items'  => __( 'Címkék keresése', 'pl-player' ),
			'not_found'     => __( 'Nem található címke.', 'pl-player' ),
		);

		register_taxonomy(
			self::TAG,
			array( self::TRACK ),
			array(
				'labels'            => $labels,
				'public'            => true,
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => false,
				'show_in_rest'      => true,
				'rest_base'         => 'pl-tags',
				'rewrite'           => array(
					'slug'       => 'zene-cimke',
					'with_front' => false,
				),
			)
		);
	}
}
