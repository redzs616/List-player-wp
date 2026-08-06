<?php
/**
 * Update checking against GitHub releases.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes the plugin update like any other, from a GitHub repository.
 *
 * WordPress normally asks wordpress.org whether a plugin has a new version. This
 * class answers that question itself, using the repository's latest release, so the
 * plugins list shows the usual "update available" notice and the usual update button.
 */
class PLP_Updater {

	const TRANSIENT = 'plp_github_release';

	/**
	 * How long a successful lookup is trusted.
	 *
	 * GitHub allows 60 unauthenticated API calls an hour per address, and every admin
	 * page load would otherwise spend one.
	 */
	const CACHE_TTL = 21600;

	/**
	 * How long a failed lookup is remembered, so a broken repo name cannot hammer the
	 * API on every request.
	 */
	const FAILURE_TTL = 900;

	/**
	 * Hooks the update machinery, but only when a repository is configured.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_manual_check' ) );

		if ( ! self::repo() ) {
			return;
		}

		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( __CLASS__, 'authorize_request' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'clear_cache' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * The configured repository as `owner/name`.
	 *
	 * @return string Empty when unset or malformed.
	 */
	public static function repo() {
		$settings = plp_get_settings();
		$repo     = isset( $settings['github_repo'] ) ? trim( (string) $settings['github_repo'] ) : '';

		/**
		 * Filters the GitHub repository used for updates.
		 *
		 * @param string $repo Repository as owner/name.
		 */
		$repo = (string) apply_filters( 'plp_github_repo', $repo );

		return preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * The access token for a private repository.
	 *
	 * Read only from a constant, never from the database: a token in the options table
	 * is one SQL injection away from being someone else's.
	 *
	 * @return string
	 */
	private static function token() {
		return defined( 'PLP_GITHUB_TOKEN' ) ? (string) constant( 'PLP_GITHUB_TOKEN' ) : '';
	}

	/**
	 * This plugin's entry as WordPress refers to it, e.g. `pl-player/pl-player.php`.
	 *
	 * @return string
	 */
	private static function plugin_file() {
		return plugin_basename( PLP_FILE );
	}

	/**
	 * The plugin's folder name.
	 *
	 * @return string
	 */
	private static function slug() {
		return dirname( self::plugin_file() );
	}

	/* ---------------------------------------------------------------------
	 * Talking to GitHub
	 * ------------------------------------------------------------------ */

	/**
	 * The latest release, from cache when possible.
	 *
	 * @return array Empty array when unavailable.
	 */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$token   = self::token();
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'pl-player/' . PLP_VERSION . '; ' . home_url( '/' ),
		);

		if ( $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 12,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::TRANSIENT, array(), self::FAILURE_TTL );

			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::TRANSIENT, array(), self::FAILURE_TTL );

			return array();
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'zip'       => self::pick_zip( $body ),
			'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
			'checked'   => time(),
		);

		set_site_transient( self::TRANSIENT, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * Chooses which file to download for an update.
	 *
	 * An uploaded .zip asset is preferred: it contains the plugin folder exactly as it
	 * should end up on disk. GitHub's automatic source archive is the fallback, and its
	 * folder name gets corrected in fix_source_folder().
	 *
	 * @param array $body Release payload.
	 * @return string
	 */
	private static function pick_zip( array $body ) {
		$private = '' !== self::token();

		foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $asset ) {
			$name = isset( $asset['name'] ) ? strtolower( (string) $asset['name'] ) : '';

			if ( '.zip' !== substr( $name, -4 ) ) {
				continue;
			}

			// A private repository's assets are only reachable through the API URL with
			// an octet-stream Accept header; the browser URL would return HTML.
			if ( $private && ! empty( $asset['url'] ) ) {
				return (string) $asset['url'];
			}

			if ( ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return isset( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
	}

	/* ---------------------------------------------------------------------
	 * WordPress integration
	 * ------------------------------------------------------------------ */

	/**
	 * Adds this plugin to the list of things with an available update.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::release();

		if ( empty( $release['version'] ) || empty( $release['zip'] ) ) {
			return $transient;
		}

		$file = self::plugin_file();

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		if ( version_compare( $release['version'], PLP_VERSION, '>' ) ) {
			$transient->response[ $file ] = self::item( $release, $release['version'] );
			unset( $transient->no_update[ $file ] );

			return $transient;
		}

		// Nothing newer. Listing it under no_update is what makes the "enable
		// auto-updates" link appear instead of nothing at all.
		unset( $transient->response[ $file ] );
		$transient->no_update[ $file ] = self::item( $release, PLP_VERSION );

		return $transient;
	}

	/**
	 * Builds the update descriptor WordPress expects.
	 *
	 * @param array  $release Release data.
	 * @param string $version Version to report.
	 * @return object
	 */
	private static function item( array $release, $version ) {
		return (object) array(
			'id'            => 'github.com/' . self::repo(),
			'slug'          => self::slug(),
			'plugin'        => self::plugin_file(),
			'new_version'   => $version,
			'url'           => $release['url'],
			'package'       => $release['zip'],
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'compatibility' => new stdClass(),
		);
	}

	/**
	 * Fills the "View details" panel.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action Requested action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$release = self::release();

		if ( empty( $release['version'] ) ) {
			return $result;
		}

		return (object) array(
			'name'          => __( 'Lejátszási Lista Player', 'pl-player' ),
			'slug'          => self::slug(),
			'version'       => $release['version'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'homepage'      => $release['url'],
			'download_link' => $release['zip'],
			'sections'      => array(
				'description' => wpautop(
					esc_html__( 'Kategóriákba rendezett zenelejátszó, nyilvános lejátszás- és like-statisztikával.', 'pl-player' )
				),
				'changelog'   => $release['changelog']
					? wpautop( esc_html( $release['changelog'] ) )
					: wpautop( esc_html__( 'Ehhez a kiadáshoz nem tartozik leírás.', 'pl-player' ) ),
			),
		);
	}

	/**
	 * Renames the extracted folder when it does not match the installed one.
	 *
	 * GitHub's automatic source archive unpacks to something like
	 * `owner-repo-9f3a1c/`. Left alone, WordPress would install the plugin under that
	 * name, so the old copy would stay active and the new one would look like a second,
	 * separate plugin.
	 *
	 * @param string      $source        Extracted directory.
	 * @param string      $remote_source Downloaded archive.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $extra         Hook extras.
	 * @return string|WP_Error
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		unset( $remote_source, $upgrader );

		if ( empty( $extra['plugin'] ) || self::plugin_file() !== $extra['plugin'] ) {
			return $source;
		}

		$desired = self::slug();

		if ( basename( untrailingslashit( $source ) ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $desired;

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return trailingslashit( $target );
		}

		return $source;
	}

	/**
	 * Adds authentication to requests aimed at a private repository.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function authorize_request( $args, $url ) {
		$token = self::token();

		if ( ! $token || ! is_string( $url ) ) {
			return $args;
		}

		$repo = self::repo();

		if ( false === strpos( $url, 'api.github.com/repos/' . $repo ) ) {
			return $args;
		}

		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}

		$args['headers']['Authorization'] = 'Bearer ' . $token;

		// Asset downloads need this, otherwise the API returns JSON metadata instead of
		// the file itself.
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Drops the cached release after an update runs.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $extra    Hook extras.
	 */
	public static function clear_cache( $upgrader, $extra ) {
		unset( $upgrader );

		if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
			delete_site_transient( self::TRANSIENT );
		}
	}

	/* ---------------------------------------------------------------------
	 * Manual check
	 * ------------------------------------------------------------------ */

	/**
	 * Handles the "check now" link on the settings screen.
	 */
	public static function handle_manual_check() {
		if ( ! isset( $_GET['plp_check_update'] ) ) {
			return;
		}

		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'plp_check_update' );

		delete_site_transient( self::TRANSIENT );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => PLP_Post_Types::TRACK,
					'page'         => PLP_Settings_Page::SLUG,
					'plp_checked'  => '1',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
