<?php
/**
 * Timestamp markers — a hand-made chapter list for long recordings.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores named points in time on a recording.
 *
 * For a ninety-minute mix this is the tracklist, made by the person who knows what is
 * in it. That is why it exists as an editing feature rather than as something derived:
 * audio fingerprinting would cost money and still miss beatmatched transitions, while
 * someone who mixed the set can mark it in a few minutes and be right.
 */
class PLP_Markers {

	const META = '_pl_markers';

	/** Hard ceiling, so one track cannot carry an unbounded payload. */
	const MAX = 100;

	/** Longest label kept. */
	const LABEL_LENGTH = 120;

	/**
	 * Hooks the editor and meta registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Registers the marker store on every playable post type.
	 */
	public static function register_meta() {
		foreach ( PLP_Source::post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::META,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	/**
	 * The markers of a post, ordered by time.
	 *
	 * @param int $post_id Post ID.
	 * @return array List of ['t' => int, 'l' => string].
	 */
	public static function get( $post_id ) {
		$raw = (string) get_post_meta( absint( $post_id ), self::META, true );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? self::clean( $decoded ) : array();
	}

	/**
	 * Validates, de-duplicates and sorts a marker list.
	 *
	 * @param array $markers Raw markers.
	 * @return array
	 */
	public static function clean( array $markers ) {
		$clean = array();
		$seen  = array();

		foreach ( $markers as $marker ) {
			if ( ! is_array( $marker ) ) {
				continue;
			}

			$time = isset( $marker['t'] ) ? absint( $marker['t'] ) : -1;

			if ( $time < 0 ) {
				continue;
			}

			// Two markers on the same second would stack into an unclickable pile.
			if ( isset( $seen[ $time ] ) ) {
				continue;
			}

			$label = isset( $marker['l'] ) ? sanitize_text_field( (string) $marker['l'] ) : '';

			if ( function_exists( 'mb_substr' ) ) {
				$label = mb_substr( $label, 0, self::LABEL_LENGTH );
			} else {
				$label = substr( $label, 0, self::LABEL_LENGTH );
			}

			$seen[ $time ] = true;

			$clean[] = array(
				't' => $time,
				'l' => $label,
			);
		}

		usort(
			$clean,
			static function ( $a, $b ) {
				return $a['t'] <=> $b['t'];
			}
		);

		return array_slice( $clean, 0, self::MAX );
	}

	/**
	 * Markers shaped for the front end, with a readable time on each.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function for_display( $post_id ) {
		$out = array();

		foreach ( self::get( $post_id ) as $index => $marker ) {
			$out[] = array(
				't'     => $marker['t'],
				// The stored name, carried verbatim alongside the display one. The editor
				// needs to tell "unnamed" from the generated "3. rész", or saving would
				// freeze that numbering into the data and break it on the next reorder.
				'l'     => $marker['l'],
				'label' => '' !== $marker['l']
					? $marker['l']
					: sprintf(
						/* translators: %d: marker number. */
						__( '%d. rész', 'pl-player' ),
						$index + 1
					),
				'time'  => plp_format_duration( $marker['t'] ),
			);
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Editor
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the marker editor to every playable post type.
	 */
	public static function add_meta_box() {
		foreach ( PLP_Source::post_types() as $post_type ) {
			add_meta_box(
				'plp_markers',
				__( 'Időbélyegek a felvételen', 'pl-player' ),
				array( __CLASS__, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Renders the editor.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_meta_box( $post ) {
		$markers  = self::get( $post->ID );
		$audio    = PLP_Source::audio_url( $post->ID );
		$duration = absint( get_post_meta( $post->ID, '_pl_duration', true ) );

		wp_nonce_field( 'plp_save_markers_' . $post->ID, 'plp_markers_nonce' );
		?>
		<div class="plp-marks"
			data-plp-marks
			data-audio="<?php echo esc_url( $audio ); ?>"
			data-duration="<?php echo esc_attr( (string) $duration ); ?>">

			<input type="hidden" id="plp_markers" name="plp_markers"
				value="<?php echo esc_attr( (string) wp_json_encode( $markers ) ); ?>" />

			<?php if ( '' === $audio ) : ?>
				<p class="plp-marks__empty">
					<?php esc_html_e( 'Ehhez a bejegyzéshez nincs hangfájl, ezért nincs mit megjelölni. Add meg a hangforrást feljebb, mentsd el, és utána térj vissza ide.', 'pl-player' ); ?>
				</p>
			<?php else : ?>

				<p class="description">
					<?php esc_html_e( 'Kattints a sávra, ahol jelölőt szeretnél. A meglévő jelölőket megfoghatod és elhúzhatod. A látogató a lejátszóban a szám nevére kattintva nyithatja le őket, és odaugorhat.', 'pl-player' ); ?>
				</p>

				<audio class="plp-marks__audio" controls preload="metadata" src="<?php echo esc_url( $audio ); ?>"></audio>

				<div class="plp-marks__bar" data-plp-marks-bar tabindex="0"
					role="group" aria-label="<?php esc_attr_e( 'Idősáv jelölőkkel', 'pl-player' ); ?>">
					<span class="plp-marks__played" data-plp-marks-played></span>
				</div>

				<p class="plp-marks__times">
					<span data-plp-marks-current>0:00</span>
					<span data-plp-marks-total><?php echo esc_html( plp_format_duration( $duration ) ); ?></span>
				</p>

				<p class="plp-marks__actions">
					<button type="button" class="button" data-plp-marks-add>
						<?php esc_html_e( 'Jelölő a mostani pozíciónál', 'pl-player' ); ?>
					</button>
					<button type="button" class="button-link plp-marks__clear" data-plp-marks-clear>
						<?php esc_html_e( 'Összes törlése', 'pl-player' ); ?>
					</button>
				</p>

				<table class="plp-marks__table" data-plp-marks-table>
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Idő', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Megnevezés', 'pl-player' ); ?></th>
							<th scope="col"></th>
						</tr>
					</thead>
					<tbody data-plp-marks-rows></tbody>
				</table>

				<p class="plp-marks__none" data-plp-marks-none>
					<?php esc_html_e( 'Még nincs jelölő ezen a felvételen.', 'pl-player' ); ?>
				</p>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Saves the marker list.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, PLP_Source::post_types(), true ) ) {
			return;
		}

		$nonce = isset( $_POST['plp_markers_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['plp_markers_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'plp_save_markers_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// JSON, so wp_unslash but no text sanitising here — each field is sanitised
		// individually in clean().
		$raw     = isset( $_POST['plp_markers'] ) ? wp_unslash( $_POST['plp_markers'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$decoded = json_decode( (string) $raw, true );
		$markers = is_array( $decoded ) ? self::clean( $decoded ) : array();

		if ( ! $markers ) {
			delete_post_meta( $post_id, self::META );

			return;
		}

		update_post_meta( $post_id, self::META, (string) wp_json_encode( $markers ) );
	}
}
