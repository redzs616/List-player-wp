<?php
/**
 * The Divi Builder module.
 *
 * Loaded only from PLP_Divi::register_module(), after Divi has declared itself ready —
 * ET_Builder_Module does not exist before that.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Puts the player into Divi's module list with clickable settings.
 */
class PLP_Divi_Player_Module extends ET_Builder_Module {

	/**
	 * Module slug.
	 *
	 * @var string
	 */
	public $slug = 'et_pb_plp_player';

	/**
	 * Visual Builder support. Divi renders this module through its PHP output.
	 *
	 * @var string
	 */
	public $vb_support = 'on';

	/**
	 * Sets the module's names.
	 */
	public function init() {
		$this->name   = esc_html__( 'Lejátszási lista player', 'pl-player' );
		$this->plural = esc_html__( 'Lejátszási lista playerek', 'pl-player' );
	}

	/**
	 * Groups the settings into named panels.
	 *
	 * @return array
	 */
	public function get_settings_modal_toggles() {
		return array(
			'general'  => array(
				'toggles' => array(
					'plp_content' => esc_html__( 'Mit játsszon', 'pl-player' ),
					'plp_display' => esc_html__( 'Megjelenítés', 'pl-player' ),
				),
			),
			'advanced' => array(
				'toggles' => array(
					'plp_colors' => esc_html__( 'Lejátszó színe', 'pl-player' ),
				),
			),
		);
	}

	/**
	 * The module's settings fields.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			'plp_playlist'  => array(
				'label'           => esc_html__( 'Lejátszási lista', 'pl-player' ),
				'type'            => 'select',
				'option_category' => 'basic_option',
				'options'         => PLP_Divi::playlist_options(),
				'default'         => '',
				'description'     => esc_html__( 'Ha választasz egyet, a saját sorrendje érvényesül, és a kategória meg a sorrend beállítás nem számít.', 'pl-player' ),
				'toggle_slug'     => 'plp_content',
			),
			'plp_category'  => array(
				'label'            => esc_html__( 'Kategória', 'pl-player' ),
				'type'             => 'select',
				'option_category'  => 'basic_option',
				'options'          => PLP_Divi::category_options(),
				'default'          => '',
				'description'      => esc_html__( 'Az alkategóriák tartalma is beleszámít.', 'pl-player' ),
				'toggle_slug'      => 'plp_content',
				'computed_affects' => array(),
			),
			'plp_post_type' => array(
				'label'           => esc_html__( 'Tartalomtípus', 'pl-player' ),
				'type'            => 'select',
				'option_category' => 'basic_option',
				'options'         => PLP_Divi::post_type_options(),
				'default'         => '',
				'toggle_slug'     => 'plp_content',
			),
			'plp_orderby'   => array(
				'label'           => esc_html__( 'Sorrend', 'pl-player' ),
				'type'            => 'select',
				'option_category' => 'configuration',
				'options'         => array(
					'date'       => esc_html__( 'Legújabb elöl', 'pl-player' ),
					'plays'      => esc_html__( 'Legtöbbet hallgatott', 'pl-player' ),
					'likes'      => esc_html__( 'Legkedveltebb', 'pl-player' ),
					'title'      => esc_html__( 'Cím szerint', 'pl-player' ),
					'random'     => esc_html__( 'Véletlen', 'pl-player' ),
					'menu_order' => esc_html__( 'Kézi sorrend', 'pl-player' ),
				),
				'default'         => 'date',
				'toggle_slug'     => 'plp_content',
			),
			'plp_limit'     => array(
				'label'           => esc_html__( 'Számok oldalanként', 'pl-player' ),
				'type'            => 'text',
				'option_category' => 'configuration',
				'default'         => '20',
				'toggle_slug'     => 'plp_content',
			),
			'plp_layout'    => array(
				'label'           => esc_html__( 'Elrendezés', 'pl-player' ),
				'type'            => 'select',
				'option_category' => 'configuration',
				'options'         => array(
					'hero' => esc_html__( 'Kiemelt panel + lista', 'pl-player' ),
					'list' => esc_html__( 'Tömör lista', 'pl-player' ),
					'grid' => esc_html__( 'Kártyás rács', 'pl-player' ),
				),
				'default'         => 'hero',
				'toggle_slug'     => 'plp_display',
			),
			'plp_columns'   => array(
				'label'           => esc_html__( 'Oszlopok (rács nézetnél)', 'pl-player' ),
				'type'            => 'select',
				'option_category' => 'configuration',
				'options'         => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'default'         => '3',
				'show_if'         => array( 'plp_layout' => 'grid' ),
				'toggle_slug'     => 'plp_display',
			),
			'plp_nav'       => array(
				'label'           => esc_html__( 'Kategória-navigáció', 'pl-player' ),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => array(
					'on'  => esc_html__( 'Igen', 'pl-player' ),
					'off' => esc_html__( 'Nem', 'pl-player' ),
				),
				'default'         => 'on',
				'toggle_slug'     => 'plp_display',
			),
			'plp_nav_limit' => array(
				'label'           => esc_html__( 'Ennyi kategória látszik', 'pl-player' ),
				'type'            => 'text',
				'option_category' => 'configuration',
				'default'         => '12',
				'description'     => esc_html__( 'A többi egy „További N kategória" gomb mögé kerül. 0 esetén mind látszik.', 'pl-player' ),
				'show_if'         => array( 'plp_nav' => 'on' ),
				'toggle_slug'     => 'plp_display',
			),
			'plp_search'    => array(
				'label'           => esc_html__( 'Keresőmező', 'pl-player' ),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => array(
					'on'  => esc_html__( 'Igen', 'pl-player' ),
					'off' => esc_html__( 'Nem', 'pl-player' ),
				),
				'default'         => 'on',
				'toggle_slug'     => 'plp_display',
			),
			'plp_sort'      => array(
				'label'           => esc_html__( 'Rendezés választó', 'pl-player' ),
				'type'            => 'yes_no_button',
				'option_category' => 'configuration',
				'options'         => array(
					'on'  => esc_html__( 'Igen', 'pl-player' ),
					'off' => esc_html__( 'Nem', 'pl-player' ),
				),
				'default'         => 'on',
				'toggle_slug'     => 'plp_display',
			),
			'plp_accent'    => array(
				'label'           => esc_html__( 'Kiemelő szín', 'pl-player' ),
				'type'            => 'color-alpha',
				'option_category' => 'configuration',
				'default'         => '',
				'description'     => esc_html__( 'A lejátszás gomb, az aktív kategória és a görbék színe.', 'pl-player' ),
				'tab_slug'        => 'advanced',
				'toggle_slug'     => 'plp_colors',
			),
		);
	}

	/**
	 * Outputs the player.
	 *
	 * @param array  $unprocessed_props Raw props.
	 * @param string $content           Inner content.
	 * @param string $render_slug       Slug being rendered.
	 * @return string
	 */
	public function render( $unprocessed_props, $content, $render_slug ) {
		unset( $unprocessed_props, $content, $render_slug );

		// In the Visual Builder the module renders over AJAX, where
		// wp_enqueue_scripts may not have run yet, so the handles may not exist.
		if ( ! wp_style_is( 'plp-player', 'registered' ) ) {
			PLP_Shortcode::register_assets();
		}

		wp_enqueue_style( 'plp-player' );
		wp_enqueue_script( 'plp-player' );

		$props = $this->props;

		$value = function ( $key, $fallback = '' ) use ( $props ) {
			return isset( $props[ $key ] ) && '' !== $props[ $key ] ? $props[ $key ] : $fallback;
		};

		$toggle = function ( $key, $fallback = 'on' ) use ( $props ) {
			$raw = isset( $props[ $key ] ) && '' !== $props[ $key ] ? $props[ $key ] : $fallback;

			return 'on' === $raw ? 'yes' : 'no';
		};

		return PLP_Renderer::render(
			array(
				'playlist'     => (string) $value( 'plp_playlist' ),
				'terms'        => (string) $value( 'plp_category' ),
				'post_type'    => (string) $value( 'plp_post_type' ),
				'orderby'      => (string) $value( 'plp_orderby', 'date' ),
				'limit'        => (string) $value( 'plp_limit', '20' ),
				'layout'       => (string) $value( 'plp_layout', 'hero' ),
				'columns'      => (string) $value( 'plp_columns', '3' ),
				'nav'          => $toggle( 'plp_nav' ),
				'nav_limit'    => (string) $value( 'plp_nav_limit', '12' ),
				'search'       => $toggle( 'plp_search' ),
				'sort'         => $toggle( 'plp_sort' ),
				'accent'       => (string) $value( 'plp_accent' ),
			)
		);
	}
}
