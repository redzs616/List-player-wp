<?php
/**
 * Bulk import: reading audio tags and turning attachments into tracks.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates tracks from audio attachments, filling in whatever the file already knows
 * about itself.
 */
class PLP_Importer {

	/**
	 * Upper bound on a single scan, so one click cannot build an unusable table.
	 */
	const MAX_SCAN = 200;

	/* ---------------------------------------------------------------------
	 * Scanning
	 * ------------------------------------------------------------------ */

	/**
	 * Reads the tags of a batch of audio attachments.
	 *
	 * @param array $attachment_ids Attachment IDs.
	 * @return array List of rows ready for the preview table.
	 */
	public static function scan( array $attachment_ids ) {
		$rows = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			if ( ! wp_attachment_is( 'audio', $attachment_id ) ) {
				continue;
			}

			$meta        = PLP_Meta::audio_meta_from_attachment( $attachment_id );
			$existing_id = self::find_existing_track( $attachment_id );

			$rows[] = array(
				'attachment_id'  => $attachment_id,
				'filename'       => $meta['filename'],
				'title'          => '' !== $meta['title'] ? $meta['title'] : self::title_from_filename( $meta['filename'] ),
				'artist'         => $meta['artist'],
				'album'          => $meta['album'],
				'year'           => $meta['year'],
				'duration'       => $meta['duration'],
				'duration_human' => $meta['duration_human'],
				'has_cover'      => self::has_extracted_cover( $attachment_id ),
				'existing_id'    => $existing_id,
				'existing_link'  => $existing_id ? (string) get_edit_post_link( $existing_id, 'raw' ) : '',
			);
		}

		return $rows;
	}

	/**
	 * Derives a readable title from a file name.
	 *
	 * Only used when the file carries no title tag at all.
	 *
	 * @param string $filename File name.
	 * @return string
	 */
	public static function title_from_filename( $filename ) {
		$name = preg_replace( '/\.[a-z0-9]{1,5}$/i', '', (string) $filename );
		$name = str_replace( array( '_', '-' ), ' ', (string) $name );

		// Drop a leading track number such as "01 ", "3. " or "07) ".
		$name = preg_replace( '/^\s*\d{1,3}\s*[.)]?\s+/', '', (string) $name );
		$name = preg_replace( '/\s+/', ' ', (string) $name );

		return trim( (string) $name );
	}

	/**
	 * Whether WordPress already pulled the embedded artwork out of this file.
	 *
	 * Only the cheap check runs here: a full getID3 parse per file would make
	 * scanning dozens of uploads painfully slow. Files that arrived outside the
	 * uploader may still yield artwork at import time.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function has_extracted_cover( $attachment_id ) {
		return (bool) get_post_thumbnail_id( absint( $attachment_id ) );
	}

	/**
	 * Finds a track that already points at this attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Track ID, or 0.
	 */
	public static function find_existing_track( $attachment_id ) {
		$existing = get_posts(
			array(
				'post_type'        => PLP_Post_Types::TRACK,
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => '_pl_attachment_id', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => absint( $attachment_id ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);

		return $existing ? (int) $existing[0] : 0;
	}

	/* ---------------------------------------------------------------------
	 * Importing
	 * ------------------------------------------------------------------ */

	/**
	 * Creates a single track from one row of the preview table.
	 *
	 * @param array $row Row data, already sanitised by the caller.
	 * @return array|WP_Error Result data on success.
	 */
	public static function create_track( array $row ) {
		$attachment_id = isset( $row['attachment_id'] ) ? absint( $row['attachment_id'] ) : 0;

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is( 'audio', $attachment_id ) ) {
			return new WP_Error( 'plp_invalid_attachment', __( 'Érvénytelen hangfájl.', 'pl-player' ) );
		}

		$existing_id = self::find_existing_track( $attachment_id );
		if ( $existing_id ) {
			return new WP_Error(
				'plp_duplicate',
				__( 'Ehhez a fájlhoz már tartozik zeneszám.', 'pl-player' ),
				array(
					'post_id'   => $existing_id,
					'edit_link' => (string) get_edit_post_link( $existing_id, 'raw' ),
				)
			);
		}

		$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
		if ( '' === $title ) {
			$title = self::title_from_filename( wp_basename( (string) get_attached_file( $attachment_id ) ) );
		}
		if ( '' === $title ) {
			$title = __( 'Névtelen szám', 'pl-player' );
		}

		$status = ( isset( $row['status'] ) && 'draft' === $row['status'] ) ? 'draft' : 'publish';

		$post_id = wp_insert_post(
			array(
				'post_type'   => PLP_Post_Types::TRACK,
				'post_title'  => $title,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$duration = isset( $row['duration'] ) ? absint( $row['duration'] ) : 0;
		if ( ! $duration ) {
			$duration = PLP_Meta::duration_from_attachment( $attachment_id );
		}

		update_post_meta( $post_id, '_pl_source_type', PLP_Meta::SOURCE_MEDIA );
		update_post_meta( $post_id, '_pl_attachment_id', $attachment_id );
		update_post_meta( $post_id, '_pl_external_url', '' );
		update_post_meta( $post_id, '_pl_artist', isset( $row['artist'] ) ? (string) $row['artist'] : '' );
		update_post_meta( $post_id, '_pl_album', isset( $row['album'] ) ? (string) $row['album'] : '' );
		update_post_meta( $post_id, '_pl_year', isset( $row['year'] ) ? $row['year'] : '' );
		update_post_meta( $post_id, '_pl_duration', $duration );

		self::assign_terms( $post_id, $row );

		$cover_id = self::import_cover( $attachment_id );
		if ( $cover_id ) {
			set_post_thumbnail( $post_id, $cover_id );
		}

		return array(
			'post_id'   => (int) $post_id,
			'title'     => $title,
			'cover_id'  => (int) $cover_id,
			'edit_link' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}

	/**
	 * Applies the chosen categories and tags to a freshly created track.
	 *
	 * @param int   $post_id Track ID.
	 * @param array $row     Row data.
	 */
	private static function assign_terms( $post_id, array $row ) {
		$category_ids = array();

		foreach ( (array) ( isset( $row['categories'] ) ? $row['categories'] : array() ) as $term_id ) {
			$term_id = absint( $term_id );
			if ( ! $term_id ) {
				continue;
			}

			// Only accept terms that really exist in our taxonomy.
			if ( get_term( $term_id, PLP_Post_Types::CATEGORY ) instanceof WP_Term ) {
				$category_ids[] = $term_id;
			}
		}

		if ( $category_ids ) {
			wp_set_object_terms( $post_id, $category_ids, PLP_Post_Types::CATEGORY, false );
		}

		$tags = isset( $row['tags'] ) ? trim( (string) $row['tags'] ) : '';
		if ( '' !== $tags ) {
			$tag_names = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
			if ( $tag_names ) {
				wp_set_object_terms( $post_id, $tag_names, PLP_Post_Types::TAG, false );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Cover art
	 * ------------------------------------------------------------------ */

	/**
	 * Returns an image attachment ID for the track's cover, creating one if needed.
	 *
	 * @param int $audio_attachment_id Audio attachment ID.
	 * @return int Image attachment ID, or 0 when the file carries no artwork.
	 */
	public static function import_cover( $audio_attachment_id ) {
		$audio_attachment_id = absint( $audio_attachment_id );

		// WordPress extracts embedded artwork at upload time, so the common case
		// needs no parsing and no new file at all.
		$thumbnail_id = (int) get_post_thumbnail_id( $audio_attachment_id );
		if ( $thumbnail_id ) {
			return $thumbnail_id;
		}

		// Fallback for files that arrived some other way — FTP, migration, or an
		// upload from before thumbnail support was active.
		$file = get_attached_file( $audio_attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$meta = wp_read_audio_metadata( $file );
		if ( ! is_array( $meta ) || empty( $meta['image']['data'] ) ) {
			return 0;
		}

		$data = $meta['image']['data'];
		$hash = md5( $data );

		// Reuse an already imported copy of the same artwork rather than filling the
		// uploads folder with identical album covers. Core tags covers the same way.
		$known = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => '_cover_hash', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);

		if ( $known ) {
			return (int) $known[0];
		}

		$mime = ! empty( $meta['image']['mime'] ) ? $meta['image']['mime'] : 'image/jpeg';

		switch ( $mime ) {
			case 'image/gif':
				$extension = 'gif';
				break;
			case 'image/png':
				$extension = 'png';
				break;
			case 'image/webp':
				$extension = 'webp';
				break;
			default:
				$mime      = 'image/jpeg';
				$extension = 'jpg';
		}

		$basename = sanitize_file_name( pathinfo( $file, PATHINFO_FILENAME ) . '-cover.' . $extension );
		$uploaded = wp_upload_bits( $basename, null, $data );

		if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) ) {
			return 0;
		}

		$cover_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( pathinfo( $basename, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploaded['file']
		);

		if ( is_wp_error( $cover_id ) || ! $cover_id ) {
			return 0;
		}

		add_post_meta( $cover_id, '_cover_hash', $hash );
		wp_update_attachment_metadata( $cover_id, wp_generate_attachment_metadata( $cover_id, $uploaded['file'] ) );

		return (int) $cover_id;
	}
}
