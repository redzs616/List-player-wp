<?php
/**
 * Finds recordings that appear in the player more than once.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects duplicates across every post type the player covers.
 *
 * The usual cause is two systems describing the same recording: a track created by the
 * bulk importer and a podcast episode pointing at the same MP3. Neither is wrong on its
 * own; together they make the same mix show up twice.
 *
 * This class only reports. Deciding which copy to keep is a judgement about content —
 * which post has the better title, the categories, the accumulated plays — and that is
 * not a decision code should make silently.
 */
class PLP_Duplicates {

	/**
	 * Confidence tiers, strongest first.
	 */
	const SAME_FILE     = 'file';
	const SAME_TITLE    = 'title';
	const SAME_DURATION = 'duration';

	/**
	 * Collects every playable post with the facts needed to compare them.
	 *
	 * @return array
	 */
	private static function collect() {
		$query = new WP_Query(
			array(
				'post_type'              => PLP_Source::post_types(),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 1000,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'date',
				'order'                  => 'ASC',
			)
		);

		if ( ! $query->posts ) {
			return array();
		}

		// One trip for every meta value instead of one per field per post.
		update_postmeta_cache( wp_list_pluck( $query->posts, 'ID' ) );

		$items = array();

		foreach ( $query->posts as $post ) {
			$url = PLP_Source::audio_url( $post->ID );

			// A post with no audio cannot be a duplicate of anything playable, and it is
			// already flagged in the track list.
			if ( '' === $url ) {
				continue;
			}

			$attachment_id = absint( get_post_meta( $post->ID, '_pl_attachment_id', true ) );

			$items[] = array
			(
				'id'        => (int) $post->ID,
				'title'     => get_the_title( $post ),
				'type'      => $post->post_type,
				'status'    => $post->post_status,
				'date'      => get_the_date( 'Y-m-d', $post ),
				'plays'     => PLP_Stats::plays( $post->ID ),
				'likes'     => PLP_Stats::likes( $post->ID ),
				'duration'  => absint( get_post_meta( $post->ID, '_pl_duration', true ) ),
				'file'      => wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ),
				'url'       => $url,
				// The attachment id is the reliable identity; the URL is the fallback for
				// files that live on an external host.
				'file_key'  => $attachment_id ? 'att:' . $attachment_id : 'url:' . strtolower( $url ),
				'title_key' => self::normalise( get_the_title( $post ) ),
			);
		}

		return $items;
	}

	/**
	 * Reduces a title to something comparable.
	 *
	 * Accents, case, punctuation and repeated spaces all differ between two hand-entered
	 * copies of the same name without meaning anything.
	 *
	 * @param string $title Raw title.
	 * @return string
	 */
	private static function normalise( $title ) {
		$title = remove_accents( (string) $title );
		$title = strtolower( $title );
		$title = preg_replace( '/[^a-z0-9]+/', ' ', $title );

		return trim( (string) preg_replace( '/\s+/', ' ', (string) $title ) );
	}

	/**
	 * Builds the duplicate report.
	 *
	 * @return array{groups:array,checked:int,extra:int}
	 */
	public static function report() {
		$items = self::collect();

		if ( ! $items ) {
			return array(
				'groups'  => array(),
				'checked' => 0,
				'extra'   => 0,
			);
		}

		$groups  = array();
		$claimed = array();

		// Tier 1 — the same file. Not a guess: two posts point at one recording.
		foreach ( self::bucket( $items, 'file_key' ) as $bucket ) {
			$groups[] = array(
				'kind'  => self::SAME_FILE,
				'items' => $bucket,
			);

			foreach ( $bucket as $item ) {
				$claimed[ $item['id'] ] = true;
			}
		}

		// Tier 2 — the same name on different files. Usually the same recording uploaded
		// twice, but it could also be two genuinely different sets with one name.
		foreach ( self::bucket( $items, 'title_key' ) as $bucket ) {
			$bucket = self::without( $bucket, $claimed );

			if ( count( $bucket ) < 2 ) {
				continue;
			}

			$groups[] = array(
				'kind'  => self::SAME_TITLE,
				'items' => $bucket,
			);

			foreach ( $bucket as $item ) {
				$claimed[ $item['id'] ] = true;
			}
		}

		// Tier 3 — different names and files, identical length. Weak on short clips, so
		// only long recordings are considered: two 90-minute mixes matching to the second
		// by coincidence is unlikely.
		foreach ( self::bucket( self::long_only( $items ), 'duration' ) as $bucket ) {
			$bucket = self::without( $bucket, $claimed );

			if ( count( $bucket ) < 2 ) {
				continue;
			}

			$groups[] = array(
				'kind'  => self::SAME_DURATION,
				'items' => $bucket,
			);

			foreach ( $bucket as $item ) {
				$claimed[ $item['id'] ] = true;
			}
		}

		$extra = 0;

		foreach ( $groups as $group ) {
			// Every group could shrink to a single kept copy.
			$extra += count( $group['items'] ) - 1;
		}

		return array(
			'groups'  => $groups,
			'checked' => count( $items ),
			'extra'   => $extra,
		);
	}

	/**
	 * Groups items by a key, keeping only the keys that occur more than once.
	 *
	 * @param array  $items Items.
	 * @param string $key   Field to group on.
	 * @return array
	 */
	private static function bucket( array $items, $key ) {
		$buckets = array();

		foreach ( $items as $item ) {
			$value = isset( $item[ $key ] ) ? (string) $item[ $key ] : '';

			if ( '' === $value || '0' === $value ) {
				continue;
			}

			$buckets[ $value ][] = $item;
		}

		return array_values(
			array_filter(
				$buckets,
				static function ( $bucket ) {
					return count( $bucket ) > 1;
				}
			)
		);
	}

	/**
	 * Drops items already reported in a stronger tier.
	 *
	 * @param array $bucket  Items.
	 * @param array $claimed Map of post IDs already reported.
	 * @return array
	 */
	private static function without( array $bucket, array $claimed ) {
		return array_values(
			array_filter(
				$bucket,
				static function ( $item ) use ( $claimed ) {
					return empty( $claimed[ $item['id'] ] );
				}
			)
		);
	}

	/**
	 * Only recordings long enough for a duration match to mean something.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function long_only( array $items ) {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return $item['duration'] >= 300;
				}
			)
		);
	}

	/**
	 * Suggests which copy to keep within a group.
	 *
	 * The one with the most plays, because that is where the accumulated history sits;
	 * ties go to the older post, which is likelier to be the one already linked to.
	 *
	 * @param array $items Group items.
	 * @return int Post ID.
	 */
	public static function suggest_keep( array $items ) {
		$best = null;

		foreach ( $items as $item ) {
			if ( null === $best ) {
				$best = $item;
				continue;
			}

			if ( $item['plays'] > $best['plays'] ) {
				$best = $item;
				continue;
			}

			if ( $item['plays'] === $best['plays'] && $item['id'] < $best['id'] ) {
				$best = $item;
			}
		}

		return $best ? (int) $best['id'] : 0;
	}

	/**
	 * Human name of a tier.
	 *
	 * @param string $kind Tier constant.
	 * @return string
	 */
	public static function kind_label( $kind ) {
		switch ( $kind ) {
			case self::SAME_FILE:
				return __( 'Ugyanaz a hangfájl', 'pl-player' );
			case self::SAME_TITLE:
				return __( 'Ugyanaz a cím, más fájl', 'pl-player' );
			default:
				return __( 'Azonos hossz, más cím és fájl', 'pl-player' );
		}
	}

	/**
	 * How much to trust a tier.
	 *
	 * @param string $kind Tier constant.
	 * @return string
	 */
	public static function kind_note( $kind ) {
		switch ( $kind ) {
			case self::SAME_FILE:
				return __( 'Biztos duplikátum: két bejegyzés ugyanarra a felvételre mutat. Ez a tipikus eset, amikor egy podcast epizód és egy zeneszám ugyanabból az MP3-ból készült.', 'pl-player' );
			case self::SAME_TITLE:
				return __( 'Nagyon valószínű: ugyanaz a név két külön fájlon. Általában ugyanaz a felvétel kétszer feltöltve — de lehet két különböző szett is egy néven, ezért érdemes ránézni.', 'pl-player' );
			default:
				return __( 'Csak jelzés: a hossz másodpercre egyezik, a cím és a fájl viszont más. Hosszú felvételeknél ez ritkán véletlen, de itt a legnagyobb a téves találat esélye.', 'pl-player' );
		}
	}
}
