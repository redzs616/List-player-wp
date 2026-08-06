<?php
/**
 * Visitor identity and rate limiting.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Identifies a visitor well enough to stop double likes, without storing personal
 * data.
 *
 * Guests are recognised by a random first-party cookie. The raw IP address is used
 * only to build a short-lived rate limiting key and never reaches the database.
 */
class PLP_Visitor {

	const COOKIE = 'pl_vid';

	/**
	 * Cached visitor id for this request.
	 *
	 * @var string|null
	 */
	private static $vid = null;

	/**
	 * Returns the visitor's random id, issuing the cookie when it is missing.
	 *
	 * @return string 32 hex characters.
	 */
	public static function vid() {
		if ( null !== self::$vid ) {
			return self::$vid;
		}

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';

		if ( preg_match( '/^[a-f0-9]{32}$/', $raw ) ) {
			self::$vid = $raw;

			return self::$vid;
		}

		self::$vid = md5( wp_generate_uuid4() . '|' . wp_rand() );
		self::send_cookie( self::$vid );

		return self::$vid;
	}

	/**
	 * Sends the visitor cookie.
	 *
	 * Holds no personal data — a random value whose only job is to keep one visitor
	 * from liking the same track twice.
	 *
	 * @param string $vid Visitor id.
	 */
	private static function send_cookie( $vid ) {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$vid,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// Make it readable within this same request too.
		$_COOKIE[ self::COOKIE ] = $vid;
	}

	/**
	 * The guest identity as stored in the like table.
	 *
	 * Empty for logged-in visitors: they are identified by their user id, which keeps
	 * the unique index meaningful across devices.
	 *
	 * @return string
	 */
	public static function visitor_hash() {
		if ( get_current_user_id() ) {
			return '';
		}

		return hash( 'sha256', self::vid() . '|' . wp_salt( 'plp_visitor' ) );
	}

	/**
	 * The guest identity, without issuing a cookie when there is none yet.
	 *
	 * Used on read requests: someone who has never interacted has liked nothing, so
	 * there is no reason to set a cookie just to answer a question about them. The
	 * cookie appears the first time a visitor actually likes something.
	 *
	 * @return string Empty when the visitor carries no cookie.
	 */
	public static function visitor_hash_readonly() {
		if ( get_current_user_id() ) {
			return '';
		}

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';

		if ( ! preg_match( '/^[a-f0-9]{32}$/', $raw ) ) {
			return '';
		}

		return hash( 'sha256', $raw . '|' . wp_salt( 'plp_visitor' ) );
	}

	/**
	 * A single string identifying whoever is making this request.
	 *
	 * @return string
	 */
	public static function identity_key() {
		$user_id = get_current_user_id();

		return $user_id ? 'u' . $user_id : 'v' . self::visitor_hash();
	}

	/* ---------------------------------------------------------------------
	 * Rate limiting
	 * ------------------------------------------------------------------ */

	/**
	 * The client IP address.
	 *
	 * Proxy headers are not trusted by default — anyone can send an arbitrary
	 * X-Forwarded-For. Sites behind Cloudflare or a load balancer can opt in with the
	 * `plp_client_ip` filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';

		/**
		 * Filters the detected client IP.
		 *
		 * @param string $ip Address taken from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'plp_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Builds the transient key for a rate limit bucket.
	 *
	 * Only a truncated hash is kept, and only for the length of the window.
	 *
	 * @param string $bucket Bucket name.
	 * @return string
	 */
	private static function rate_key( $bucket ) {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 200 )
			: '';

		$hash = hash( 'sha256', self::client_ip() . '|' . $agent . '|' . wp_salt( 'plp_rate' ) );

		return 'plp_rl_' . sanitize_key( $bucket ) . '_' . substr( $hash, 0, 32 );
	}

	/**
	 * Counts one request against a bucket.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Requests allowed inside the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the caller is over the limit and should be refused.
	 */
	public static function is_rate_limited( $bucket, $max, $window ) {
		$key  = self::rate_key( $bucket );
		$hits = (int) get_transient( $key );

		if ( $hits >= $max ) {
			return true;
		}

		set_transient( $key, $hits + 1, $window );

		return false;
	}
}
