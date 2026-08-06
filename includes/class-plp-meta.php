<?php
/**
 * Track meta fields: registration, edit screen and saving.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles everything that describes a single track.
 */
class PLP_Meta {

	const SOURCE_MEDIA    = 'media';
	const SOURCE_EXTERNAL = 'external';

	/**
	 * Hooks the meta box and the save handler.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . PLP_Post_Types::TRACK, array( __CLASS__, 'ensure_counters' ), 5 );
		add_action( 'save_post_' . PLP_Post_Types::TRACK, array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Makes sure both counters exist on every track.
	 *
	 * Runs independently of the details panel on purpose: quick edit and the bulk
	 * importer never submit that form, and a track without these keys would silently
	 * vanish from the admin list whenever it is sorted by plays or likes.
	 *
	 * @param int $post_id Track ID.
	 */
	public static function ensure_counters( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		add_post_meta( $post_id, '_pl_plays', 0, true );
		add_post_meta( $post_id, '_pl_likes', 0, true );
	}

	/**
	 * The meta keys a track carries, with the sanitiser each one runs through.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'_pl_artist'        => array( 'string', 'sanitize_text_field' ),
			'_pl_album'         => array( 'string', 'sanitize_text_field' ),
			'_pl_year'          => array( 'integer', array( __CLASS__, 'sanitize_year' ) ),
			'_pl_duration'      => array( 'integer', 'absint' ),
			'_pl_source_type'   => array( 'string', array( __CLASS__, 'sanitize_source_type' ) ),
			'_pl_attachment_id' => array( 'integer', 'absint' ),
			'_pl_external_url'  => array( 'string', array( __CLASS__, 'sanitize_audio_url' ) ),
			'_pl_plays'         => array( 'integer', 'absint' ),
			'_pl_likes'         => array( 'integer', 'absint' ),
		);
	}

	/**
	 * Registers the meta keys so their sanitisers run on every write, wherever the
	 * write comes from.
	 */
	public static function register_meta() {
		foreach ( self::fields() as $key => $definition ) {
			list( $type, $sanitize ) = $definition;

			register_post_meta(
				PLP_Post_Types::TRACK,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'sanitize_callback' => $sanitize,
					// Deliberately kept out of the core meta endpoint: the public
					// payload is shaped by the plugin's own /plplayer/v1 routes, so
					// there is no reason to expose raw internal keys.
					'show_in_rest'      => false,
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Sanitisers
	 * ------------------------------------------------------------------ */

	/**
	 * Restricts the source type to the two known values.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_source_type( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		return self::SOURCE_EXTERNAL === $value ? self::SOURCE_EXTERNAL : self::SOURCE_MEDIA;
	}

	/**
	 * Accepts a plausible release year, or nothing.
	 *
	 * @param mixed $value Raw value.
	 * @return string|int
	 */
	public static function sanitize_year( $value ) {
		$year = absint( $value );

		if ( $year < 1900 || $year > ( (int) gmdate( 'Y' ) + 1 ) ) {
			return '';
		}

		return $year;
	}

	/**
	 * Allows only http(s) URLs as an external audio source.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_audio_url( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}

		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	/* ---------------------------------------------------------------------
	 * ID3 / attachment metadata
	 * ------------------------------------------------------------------ */

	/**
	 * Reads what WordPress already parsed out of an uploaded audio file.
	 *
	 * WordPress ships getID3 and runs it at upload time, so the tags are sitting in
	 * the attachment metadata — no extra library needed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public static function audio_meta_from_attachment( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		$meta          = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$duration = isset( $meta['length'] ) ? absint( $meta['length'] ) : 0;

		return array(
			'title'          => isset( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : '',
			'artist'         => isset( $meta['artist'] ) ? sanitize_text_field( $meta['artist'] ) : '',
			'album'          => isset( $meta['album'] ) ? sanitize_text_field( $meta['album'] ) : '',
			'year'           => isset( $meta['year'] ) ? self::sanitize_year( $meta['year'] ) : '',
			'duration'       => $duration,
			'duration_human' => plp_format_duration( $duration ),
			'filename'       => wp_basename( get_attached_file( $attachment_id ) ),
			'url'            => (string) wp_get_attachment_url( $attachment_id ),
		);
	}

	/**
	 * Duration of an attachment in seconds, or 0 when unknown.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public static function duration_from_attachment( $attachment_id ) {
		$meta = wp_get_attachment_metadata( absint( $attachment_id ) );

		return ( is_array( $meta ) && ! empty( $meta['length'] ) ) ? absint( $meta['length'] ) : 0;
	}

	/* ---------------------------------------------------------------------
	 * Edit screen
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the details panel to the track edit screen.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'plp_track_details',
			__( 'Zeneszám adatai', 'pl-player' ),
			array( __CLASS__, 'render_meta_box' ),
			PLP_Post_Types::TRACK,
			'normal',
			'high'
		);
	}

	/**
	 * Renders the details panel.
	 *
	 * @param WP_Post $post Current track.
	 */
	public static function render_meta_box( $post ) {
		$source_type   = self::sanitize_source_type( get_post_meta( $post->ID, '_pl_source_type', true ) );
		$attachment_id = absint( get_post_meta( $post->ID, '_pl_attachment_id', true ) );
		$external_url  = (string) get_post_meta( $post->ID, '_pl_external_url', true );
		$artist        = (string) get_post_meta( $post->ID, '_pl_artist', true );
		$album         = (string) get_post_meta( $post->ID, '_pl_album', true );
		$year          = (string) get_post_meta( $post->ID, '_pl_year', true );
		$duration      = absint( get_post_meta( $post->ID, '_pl_duration', true ) );
		$plays         = absint( get_post_meta( $post->ID, '_pl_plays', true ) );
		$likes         = absint( get_post_meta( $post->ID, '_pl_likes', true ) );

		$attachment_url  = $attachment_id ? (string) wp_get_attachment_url( $attachment_id ) : '';
		$attachment_name = $attachment_id ? wp_basename( (string) get_attached_file( $attachment_id ) ) : '';

		wp_nonce_field( 'plp_save_meta_' . $post->ID, 'plp_meta_nonce' );
		?>
		<div class="plp-meta">

			<p class="plp-meta__legend">
				<strong><?php esc_html_e( 'Hangforrás', 'pl-player' ); ?></strong>
			</p>

			<p class="plp-source-toggle">
				<label>
					<input type="radio" name="plp_source_type" value="<?php echo esc_attr( self::SOURCE_MEDIA ); ?>"
						<?php checked( self::SOURCE_MEDIA, $source_type ); ?> />
					<?php esc_html_e( 'Médiatár', 'pl-player' ); ?>
				</label>
				<label>
					<input type="radio" name="plp_source_type" value="<?php echo esc_attr( self::SOURCE_EXTERNAL ); ?>"
						<?php checked( self::SOURCE_EXTERNAL, $source_type ); ?> />
					<?php esc_html_e( 'Külső URL (CDN)', 'pl-player' ); ?>
				</label>
			</p>

			<div class="plp-source plp-source--media" <?php echo self::SOURCE_MEDIA === $source_type ? '' : 'hidden'; ?>>
				<input type="hidden" id="plp_attachment_id" name="plp_attachment_id" value="<?php echo esc_attr( (string) $attachment_id ); ?>" />

				<p>
					<button type="button" class="button" id="plp-select-audio">
						<?php esc_html_e( 'Hangfájl kiválasztása', 'pl-player' ); ?>
					</button>
					<button type="button" class="button-link plp-remove" id="plp-remove-audio"
						<?php echo $attachment_id ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Eltávolítás', 'pl-player' ); ?>
					</button>
				</p>

				<div id="plp-audio-preview" <?php echo $attachment_id ? '' : 'hidden'; ?>>
					<p class="plp-audio-name">
						<span class="dashicons dashicons-format-audio"></span>
						<span id="plp-audio-name"><?php echo esc_html( $attachment_name ); ?></span>
					</p>
					<audio id="plp-audio-player" controls preload="none" src="<?php echo esc_url( $attachment_url ); ?>"></audio>
				</div>

				<p class="description">
					<?php esc_html_e( 'Kiválasztás után az előadó, album, év és hossz automatikusan kitöltődik a fájl ID3 adataiból, ha üresek.', 'pl-player' ); ?>
				</p>
			</div>

			<div class="plp-source plp-source--external" <?php echo self::SOURCE_EXTERNAL === $source_type ? '' : 'hidden'; ?>>
				<p>
					<label for="plp_external_url" class="screen-reader-text">
						<?php esc_html_e( 'Külső hangfájl URL', 'pl-player' ); ?>
					</label>
					<input type="url" class="large-text code" id="plp_external_url" name="plp_external_url"
						value="<?php echo esc_attr( $external_url ); ?>" placeholder="https://cdn.pelda.hu/zene/track.mp3" />
				</p>
				<p class="description">
					<?php esc_html_e( 'Közvetlen hangfájl URL-je (pl. Bunny.net, S3). A hosszt ilyenkor kézzel érdemes megadni, vagy a lejátszó olvassa be az első lejátszásnál.', 'pl-player' ); ?>
				</p>
			</div>

			<hr />

			<table class="form-table plp-meta__table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="plp_artist"><?php esc_html_e( 'Előadó', 'pl-player' ); ?></label></th>
						<td><input type="text" class="regular-text" id="plp_artist" name="plp_artist" value="<?php echo esc_attr( $artist ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="plp_album"><?php esc_html_e( 'Album', 'pl-player' ); ?></label></th>
						<td><input type="text" class="regular-text" id="plp_album" name="plp_album" value="<?php echo esc_attr( $album ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="plp_year"><?php esc_html_e( 'Év', 'pl-player' ); ?></label></th>
						<td><input type="number" class="small-text" id="plp_year" name="plp_year" min="1900" max="<?php echo esc_attr( (string) ( (int) gmdate( 'Y' ) + 1 ) ); ?>" value="<?php echo esc_attr( $year ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="plp_duration"><?php esc_html_e( 'Hossz', 'pl-player' ); ?></label></th>
						<td>
							<input type="number" class="small-text" id="plp_duration" name="plp_duration" min="0" step="1" value="<?php echo esc_attr( (string) $duration ); ?>" />
							<?php esc_html_e( 'másodperc', 'pl-player' ); ?>
							<span class="plp-duration-hint" id="plp-duration-hint"><?php echo esc_html( $duration ? '= ' . plp_format_duration( $duration ) : '' ); ?></span>
						</td>
					</tr>
				</tbody>
			</table>

			<hr />

			<p class="plp-meta__legend">
				<strong><?php esc_html_e( 'Statisztika', 'pl-player' ); ?></strong>
			</p>
			<p class="plp-stats">
				<span class="plp-stat">
					<span class="dashicons dashicons-controls-play"></span>
					<?php
					printf(
						/* translators: %s: play count. */
						esc_html__( '%s lejátszás', 'pl-player' ),
						'<strong>' . esc_html( number_format_i18n( $plays ) ) . '</strong>'
					);
					?>
				</span>
				<span class="plp-stat">
					<span class="dashicons dashicons-heart"></span>
					<?php
					printf(
						/* translators: %s: like count. */
						esc_html__( '%s like', 'pl-player' ),
						'<strong>' . esc_html( number_format_i18n( $likes ) ) . '</strong>'
					);
					?>
				</span>
			</p>
			<p class="description">
				<?php esc_html_e( 'A számlálók automatikusan frissülnek. A részletes, időbeli kimutatás a Statisztika menüpontban lesz elérhető.', 'pl-player' ); ?>
			</p>

		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Persists the details panel.
	 *
	 * @param int     $post_id Track ID.
	 * @param WP_Post $post    Track object.
	 */
	public static function save( $post_id, $post ) {
		unset( $post );

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['plp_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['plp_meta_nonce'] ) ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'plp_save_meta_' . $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$source_type = isset( $_POST['plp_source_type'] )
			? self::sanitize_source_type( wp_unslash( $_POST['plp_source_type'] ) )
			: self::SOURCE_MEDIA;

		$attachment_id = isset( $_POST['plp_attachment_id'] ) ? absint( $_POST['plp_attachment_id'] ) : 0;

		// Guard against an ID that points at something that is not an attachment.
		if ( $attachment_id && 'attachment' !== get_post_type( $attachment_id ) ) {
			$attachment_id = 0;
		}

		update_post_meta( $post_id, '_pl_source_type', $source_type );
		update_post_meta( $post_id, '_pl_attachment_id', $attachment_id );

		$external_url = isset( $_POST['plp_external_url'] ) ? wp_unslash( $_POST['plp_external_url'] ) : '';
		update_post_meta( $post_id, '_pl_external_url', self::sanitize_audio_url( $external_url ) );

		$artist = isset( $_POST['plp_artist'] ) ? wp_unslash( $_POST['plp_artist'] ) : '';
		update_post_meta( $post_id, '_pl_artist', sanitize_text_field( $artist ) );

		$album = isset( $_POST['plp_album'] ) ? wp_unslash( $_POST['plp_album'] ) : '';
		update_post_meta( $post_id, '_pl_album', sanitize_text_field( $album ) );

		$year = isset( $_POST['plp_year'] ) ? wp_unslash( $_POST['plp_year'] ) : '';
		update_post_meta( $post_id, '_pl_year', self::sanitize_year( $year ) );

		$duration = isset( $_POST['plp_duration'] ) ? absint( $_POST['plp_duration'] ) : 0;
		if ( ! $duration && self::SOURCE_MEDIA === $source_type && $attachment_id ) {
			$duration = self::duration_from_attachment( $attachment_id );
		}
		update_post_meta( $post_id, '_pl_duration', $duration );
	}
}
