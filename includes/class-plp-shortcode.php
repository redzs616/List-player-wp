<?php
/**
 * The [playlist_player] shortcode and its assets.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shortcode and loads the front end assets only where it is used.
 */
class PLP_Shortcode {

	const TAG       = 'playlist_player';
	const TAG_STATS = 'playlist_stats';

	/**
	 * Hooks the shortcodes.
	 */
	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_shortcode( self::TAG_STATS, array( __CLASS__, 'render_stats' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registers, but does not load, the front end assets.
	 */
	public static function register_assets() {
		wp_register_style(
			'plp-player',
			PLP_URL . 'public/css/player.css',
			array(),
			PLP_VERSION
		);

		wp_register_script(
			'plp-player',
			PLP_URL . 'public/js/player.js',
			array(),
			PLP_VERSION,
			true
		);

		$settings = plp_get_settings();

		/**
		 * Filters the configuration handed to the front end script.
		 *
		 * Useful for forcing the alternative route form on servers that block
		 * /wp-json/ outright: set `rest` to the same value as `restFallback`.
		 *
		 * @param array $config Script configuration.
		 */
		$config = apply_filters(
			'plp_player_config',
			array(
				'rest'             => esc_url_raw( rest_url( PLP_Rest::NAMESPACE_V1 ) ),
				// Some web servers refuse the pretty /wp-json/ path before WordPress
				// ever runs. This form always works, and the script switches to it
				// automatically when the first request comes back blocked.
				'restFallback'     => esc_url_raw(
					add_query_arg( 'rest_route', '/' . PLP_Rest::NAMESPACE_V1, home_url( '/' ) )
				),
				// Only logged-in visitors get a nonce, and only they need one: it is
				// what lets the REST API recognise their cookie. Printing one for
				// guests would bake a stale value into page-cached HTML.
				'nonce'            => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'thresholdSeconds' => (int) $settings['play_threshold_seconds'],
				'thresholdPercent' => (int) $settings['play_threshold_percent'],
				'showStats'        => ! empty( $settings['public_stats'] ),
				'i18n'            => array(
					'play'        => __( 'Lejátszás', 'pl-player' ),
					'pause'       => __( 'Megállítás', 'pl-player' ),
					'like'        => __( 'Kedvelés', 'pl-player' ),
					'unlike'      => __( 'Kedvelés visszavonása', 'pl-player' ),
					'loading'     => __( 'Betöltés…', 'pl-player' ),
					'empty'       => __( 'Nincs találat.', 'pl-player' ),
					'error'       => __( 'Nem sikerült betölteni a számokat.', 'pl-player' ),
					'loginNeeded' => __( 'A kedveléshez be kell jelentkezned.', 'pl-player' ),
					'nowPlaying'  => __( 'Most játszik:', 'pl-player' ),
					'popupBlocked' => __( 'A böngésző letiltotta a felugró ablakot. Engedélyezd az oldalnak, és próbáld újra.', 'pl-player' ),
					'share'        => __( 'Megosztás', 'pl-player' ),
					'linkCopied'   => __( 'A link a vágólapra került.', 'pl-player' ),
				),
			)
		);

		wp_localize_script( 'plp-player', 'PLPlayer', $config );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		wp_enqueue_style( 'plp-player' );
		wp_enqueue_script( 'plp-player' );

		return PLP_Renderer::render( $atts );
	}

	/**
	 * Renders the public statistics block.
	 *
	 * Only the stylesheet is needed — the lists and the trend chart are static markup.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_stats( $atts ) {
		wp_enqueue_style( 'plp-player' );

		return PLP_Renderer::render_stats( $atts );
	}
}
