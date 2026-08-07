<?php
/**
 * The standalone popup player.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves a minimal player page meant to live in its own browser window.
 *
 * A normal page navigation destroys the JavaScript context and with it the audio
 * element — there is no way around that from inside the page. A separate window is
 * the only way the sound genuinely survives moving around the site, which is why the
 * popup exists rather than some clever trick on the main page.
 */
class PLP_Popup {

	const QUERY_ARG = 'plp_popup';

	/**
	 * Hooks the popup route.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * URL of the popup player.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$args = array_filter(
			array_merge( array( self::QUERY_ARG => '1' ), $args ),
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Outputs the popup page and stops, when the flag is present.
	 *
	 * Read straight from the query string rather than through a registered query var:
	 * this route has nothing to do with rewrite rules and should work even if they are
	 * stale.
	 */
	public static function maybe_render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET[ self::QUERY_ARG ] ) ) {
			return;
		}

		$track = isset( $_GET['track'] ) ? absint( $_GET['track'] ) : 0;
		$at    = isset( $_GET['t'] ) ? absint( $_GET['t'] ) : 0;
		$terms = isset( $_GET['terms'] ) ? sanitize_text_field( wp_unslash( $_GET['terms'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		nocache_headers();

		// The assets are normally registered on wp_enqueue_scripts, which has not run at
		// this point in the request.
		if ( ! wp_style_is( 'plp-player', 'registered' ) ) {
			PLP_Shortcode::register_assets();
		}

		wp_enqueue_style( 'plp-player' );
		wp_enqueue_script( 'plp-player' );

		$player = PLP_Renderer::render(
			array(
				'terms'   => $terms,
				'layout'  => 'list',
				'limit'   => 50,
				'nav'     => 'no',
				'search'  => 'no',
				'sort'    => 'no',
				'popup'   => 'no',
				'theme'   => 'dark',
			)
		);

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( get_bloginfo( 'name' ) . ' — ' . __( 'Lejátszó', 'pl-player' ) ); ?></title>
	<?php wp_print_styles(); ?>
	<style>
		body.plp-popup {
			margin: 0;
			padding: 14px 14px 0;
			background: #12141a;
			color: #eef1f5;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
		}

		body.plp-popup .plp-bar {
			box-shadow: none;
		}
	</style>
</head>
<body class="plp-popup">
	<?php
	echo $player; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	wp_print_footer_scripts();
	?>

	<script>
		( function () {
			var id = <?php echo (int) $track; ?>;
			var at = <?php echo (int) $at; ?>;

			if ( ! id ) {
				return;
			}

			// The window was opened by a click, so it carries a user gesture and is
			// allowed to start playing.
			var start = function () {
				if ( window.PLPlayerAPI && window.PLPlayerAPI.play( id, at ) ) {
					return;
				}

				window.setTimeout( start, 120 );
			};

			start();
		}() );
	</script>
</body>
</html>
		<?php
		exit;
	}
}
