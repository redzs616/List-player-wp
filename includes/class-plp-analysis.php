<?php
/**
 * Audio analysis: tempo, energy, brightness, and generated cover art.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores what was measured from the audio, and turns it into readable labels.
 *
 * The measuring itself happens in the browser, because that is the only place with a
 * working audio decoder on shared hosting — PHP has none, and no binary can be
 * installed. This class receives the numbers, sanity-checks them, and keeps them.
 *
 * These are measurements, not genre guesses. A trained classifier would put a label
 * like "techno" on a file and be wrong often enough to be misleading; tempo and energy
 * are simply true, and read just as usefully.
 */
class PLP_Analysis {

	const META_BPM      = '_pl_bpm';
	const META_ENERGY   = '_pl_energy';
	const META_BRIGHT   = '_pl_bright';
	const META_ANALYZED = '_pl_analyzed';

	/**
	 * Hooks meta registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Registers the measured fields on every playable post type.
	 */
	public static function register_meta() {
		$fields = array(
			self::META_BPM      => 'integer',
			self::META_ENERGY   => 'integer',
			self::META_BRIGHT   => 'integer',
			self::META_ANALYZED => 'integer',
		);

		foreach ( PLP_Source::post_types() as $post_type ) {
			foreach ( $fields as $key => $type ) {
				register_post_meta(
					$post_type,
					$key,
					array(
						'type'              => $type,
						'single'            => true,
						'sanitize_callback' => 'absint',
						'show_in_rest'      => false,
						'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * The measured values of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{bpm:int,energy:int,bright:int,analyzed:int}
	 */
	public static function get( $post_id ) {
		$post_id = absint( $post_id );

		return array(
			'bpm'      => absint( get_post_meta( $post_id, self::META_BPM, true ) ),
			'energy'   => absint( get_post_meta( $post_id, self::META_ENERGY, true ) ),
			'bright'   => absint( get_post_meta( $post_id, self::META_BRIGHT, true ) ),
			'analyzed' => absint( get_post_meta( $post_id, self::META_ANALYZED, true ) ),
		);
	}

	/**
	 * Human labels derived from the measurements.
	 *
	 * Deliberately coarse. A number like "63 energia" means nothing to a visitor, and
	 * pretending to a precision the method does not have would be worse than a band.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public static function labels( $post_id ) {
		$data   = self::get( $post_id );
		$labels = array();

		if ( ! $data['analyzed'] ) {
			return $labels;
		}

		if ( $data['bpm'] ) {
			$labels[] = sprintf(
				/* translators: %d: beats per minute. */
				__( '%d BPM', 'pl-player' ),
				$data['bpm']
			);
		}

		if ( $data['energy'] ) {
			if ( $data['energy'] >= 66 ) {
				$labels[] = __( 'kemény', 'pl-player' );
			} elseif ( $data['energy'] >= 36 ) {
				$labels[] = __( 'közepes', 'pl-player' );
			} else {
				$labels[] = __( 'lágy', 'pl-player' );
			}
		}

		if ( $data['bright'] ) {
			if ( $data['bright'] >= 62 ) {
				$labels[] = __( 'fényes', 'pl-player' );
			} elseif ( $data['bright'] <= 34 ) {
				$labels[] = __( 'sötét', 'pl-player' );
			}
		}

		return $labels;
	}

	/**
	 * A one-line summary usable as the start of a description.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function summary( $post_id ) {
		$parts    = array();
		$duration = plp_format_duration( get_post_meta( absint( $post_id ), '_pl_duration', true ) );

		if ( $duration ) {
			$parts[] = $duration;
		}

		$parts = array_merge( $parts, self::labels( $post_id ) );

		return implode( ' · ', $parts );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Stores a measurement.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $values  Measured values.
	 * @return array The stored values.
	 */
	public static function save( $post_id, array $values ) {
		$post_id = absint( $post_id );

		// Tempo outside this range is almost certainly a detection error rather than a
		// real recording, and a wrong number is worse than none.
		$bpm = absint( isset( $values['bpm'] ) ? $values['bpm'] : 0 );
		if ( $bpm < 50 || $bpm > 220 ) {
			$bpm = 0;
		}

		$energy = min( 100, absint( isset( $values['energy'] ) ? $values['energy'] : 0 ) );
		$bright = min( 100, absint( isset( $values['bright'] ) ? $values['bright'] : 0 ) );

		update_post_meta( $post_id, self::META_BPM, $bpm );
		update_post_meta( $post_id, self::META_ENERGY, $energy );
		update_post_meta( $post_id, self::META_BRIGHT, $bright );
		update_post_meta( $post_id, self::META_ANALYZED, time() );

		return self::get( $post_id );
	}

	/* ---------------------------------------------------------------------
	 * Generated cover art
	 * ------------------------------------------------------------------ */

	/**
	 * Saves a cover image drawn in the browser as the post's featured image.
	 *
	 * Drawn from the recording's own spectrum, so every mix gets a distinct image that
	 * still belongs to one visual series. Generating beats fetching: pulling artwork off
	 * the web and republishing it would be someone else's copyright.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $data_url  A `data:image/jpeg;base64,...` string from a canvas.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function save_cover( $post_id, $data_url ) {
		$post_id = absint( $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'plp_forbidden', __( 'Nincs jogosultság.', 'pl-player' ) );
		}

		if ( ! preg_match( '#^data:image/(jpeg|png);base64,#', (string) $data_url, $match ) ) {
			return new WP_Error( 'plp_bad_image', __( 'Érvénytelen képadat.', 'pl-player' ) );
		}

		$mime      = 'image/' . $match[1];
		$extension = 'jpeg' === $match[1] ? 'jpg' : 'png';
		$encoded   = substr( (string) $data_url, strlen( $match[0] ) );
		$binary    = base64_decode( $encoded, true );

		if ( false === $binary || strlen( $binary ) < 1024 ) {
			return new WP_Error( 'plp_bad_image', __( 'A kép nem dekódolható.', 'pl-player' ) );
		}

		// Sanity ceiling: a 1000x1000 cover is well under this, so anything larger is a
		// sign something went wrong rather than a legitimate image.
		if ( strlen( $binary ) > 4 * MB_IN_BYTES ) {
			return new WP_Error( 'plp_bad_image', __( 'A kép túl nagy.', 'pl-player' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$slug     = sanitize_title( get_the_title( $post_id ) );
		$basename = sanitize_file_name( ( $slug ? $slug : 'borito' ) . '-' . $post_id . '-borito.' . $extension );
		$uploaded = wp_upload_bits( $basename, null, $binary );

		if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			return new WP_Error( 'plp_upload_failed', __( 'A kép mentése nem sikerült.', 'pl-player' ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => get_the_title( $post_id ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploaded['file'],
			$post_id
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new WP_Error( 'plp_attach_failed', __( 'A kép nem került a médiatárba.', 'pl-player' ) );
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] )
		);

		// Marks it as ours, so a later pass can tell a generated cover from one the
		// owner chose deliberately.
		update_post_meta( $attachment_id, '_pl_generated_cover', 1 );

		set_post_thumbnail( $post_id, $attachment_id );

		return (int) $attachment_id;
	}
}
