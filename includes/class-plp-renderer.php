<?php
/**
 * Front end markup.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the player: category navigation, the track list and the sticky bar.
 *
 * The first page is rendered on the server so the list is visible immediately and
 * readable by search engines. Everything after that — filtering, sorting, paging —
 * is handled by the script through the REST routes.
 */
class PLP_Renderer {

	/**
	 * Whether the sticky bar has been queued for the footer.
	 *
	 * One bar per page, however many players are embedded.
	 *
	 * @var bool
	 */
	private static $bar_queued = false;

	/**
	 * Hooks the footer bar.
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'maybe_render_bar' ), 20 );
	}

	/* ---------------------------------------------------------------------
	 * Configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Normalises shortcode attributes into a configuration array.
	 *
	 * @param array $atts Raw attributes.
	 * @return array
	 */
	public static function config( $atts ) {
		$atts = shortcode_atts(
			array(
				'category'  => '',
				'terms'     => '',
				'post_type' => '',
				'layout'    => 'list',
				'columns'   => 3,
				'limit'     => 20,
				'orderby'   => 'date',
				'order'     => 'desc',
				'nav'       => 'yes',
				'nav_limit' => 12,
				'nav_taxonomy' => '',
				'search'    => 'yes',
				'sort'      => 'yes',
				'accent'    => '',
				'theme'     => 'auto',
				'popup'     => 'yes',
				'playlist'  => '',
				'equalizer' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'playlist_player'
		);

		$terms = self::resolve_terms( (string) $atts['terms'], (string) $atts['category'] );

		$post_types = array();
		foreach ( array_map( 'sanitize_key', explode( ',', (string) $atts['post_type'] ) ) as $post_type ) {
			if ( $post_type ) {
				$post_types[] = $post_type;
			}
		}

		return array(
			'terms'      => $terms,
			'post_types' => $post_types,
			'layout'     => in_array( $atts['layout'], array( 'list', 'grid', 'hero' ), true ) ? $atts['layout'] : 'list',
			'columns'    => max( 1, min( 6, absint( $atts['columns'] ) ) ),
			'per_page'   => max( 1, min( 100, absint( $atts['limit'] ) ) ),
			'orderby'    => sanitize_key( (string) $atts['orderby'] ),
			'order'      => 'asc' === strtolower( (string) $atts['order'] ) ? 'asc' : 'desc',
			'show_nav'   => self::is_yes( $atts['nav'] ),
			// 0 means show every category at once.
			'nav_limit'  => max( 0, absint( $atts['nav_limit'] ) ),
			'nav_taxonomy' => sanitize_key( (string) $atts['nav_taxonomy'] ),
			'show_search' => self::is_yes( $atts['search'] ),
			'show_sort'  => self::is_yes( $atts['sort'] ),
			'accent'     => sanitize_hex_color( (string) $atts['accent'] ),
			'theme'      => in_array( $atts['theme'], array( 'auto', 'dark', 'light' ), true ) ? $atts['theme'] : 'auto',
			'show_popup' => self::is_yes( $atts['popup'] ),
			'playlist'   => PLP_Playlist::resolve( $atts['playlist'] ),
			// `always` overrides the visitor's reduced-motion preference. Worth having
			// as an explicit opt-in: an equalizer is the feature itself, not decorative
			// chrome, so some owners will want it regardless.
			'equalizer'  => 'always' === strtolower( (string) $atts['equalizer'] )
				? 'always'
				: ( self::is_yes( $atts['equalizer'] ) ? 'yes' : 'no' ),
		);
	}

	/**
	 * Accepts the usual ways of writing "yes".
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function is_yes( $value ) {
		return in_array( strtolower( (string) $value ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * Turns term ids or slugs from the shortcode into term ids.
	 *
	 * Slugs are friendlier to write by hand, so both are accepted.
	 *
	 * @param string $terms    Comma separated ids or slugs.
	 * @param string $category Comma separated ids or slugs.
	 * @return int[]
	 */
	private static function resolve_terms( $terms, $category ) {
		$raw        = array_filter( array_map( 'trim', explode( ',', $terms . ',' . $category ) ) );
		$taxonomies = PLP_Source::all_taxonomies();
		$ids        = array();

		foreach ( $raw as $item ) {
			if ( ctype_digit( $item ) ) {
				$ids[] = absint( $item );
				continue;
			}

			foreach ( $taxonomies as $taxonomy ) {
				$term = get_term_by( 'slug', sanitize_title( $item ), $taxonomy );

				if ( $term instanceof WP_Term ) {
					$ids[] = (int) $term->term_id;
					break;
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders one player instance.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$config = self::config( $atts );

		// A named playlist replaces the category and sorting logic with its own order.
		if ( $config['playlist'] ) {
			$config['include'] = PLP_Playlist::track_ids( $config['playlist'] );
		}

		$query = new WP_Query( PLP_Source::query_args( $config ) );

		$tracks = array();
		foreach ( $query->posts as $post ) {
			$data = PLP_Source::track_data( $post->ID );

			// A post with no resolvable audio is left out rather than shown as a row
			// that cannot play.
			if ( $data ) {
				$tracks[] = $data;
			}
		}

		self::$bar_queued = true;

		$settings   = plp_get_settings();
		$show_stats = ! empty( $settings['public_stats'] );

		$client_config = array(
			'terms'      => $config['terms'],
			'postTypes'  => $config['post_types'],
			'layout'     => $config['layout'],
			'perPage'    => $config['per_page'],
			'orderby'    => $config['orderby'],
			'order'      => $config['order'],
			'showStats'  => $show_stats,
			'page'       => 1,
			'totalPages' => (int) $query->max_num_pages,
		);

		$style = $config['accent'] ? 'style="--plp-accent:' . esc_attr( $config['accent'] ) . '"' : '';

		ob_start();
		?>
		<div class="plp plp--<?php echo esc_attr( $config['layout'] ); ?> plp--theme-<?php echo esc_attr( $config['theme'] ); ?>"
			data-plp="<?php echo esc_attr( (string) wp_json_encode( $client_config ) ); ?>" <?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>

			<?php if ( 'hero' === $config['layout'] ) : ?>
				<?php self::render_hero( $tracks, $show_stats, $config['equalizer'] ); ?>
			<?php endif; ?>

			<?php if ( $config['show_nav'] ) : ?>
				<?php self::render_nav( $config ); ?>
			<?php endif; ?>

			<?php // The pop-out button counts as a reason to render this row: it must not
				// disappear just because the search field and the sort control are off. ?>
			<?php if ( $config['show_search'] || $config['show_sort'] || $config['show_popup'] ) : ?>
				<div class="plp-toolbar">
					<?php if ( $config['show_search'] ) : ?>
						<div class="plp-search">
							<input type="search" class="plp-search__input"
								aria-label="<?php esc_attr_e( 'Keresés a számok között', 'pl-player' ); ?>"
								placeholder="<?php esc_attr_e( 'Keresés…', 'pl-player' ); ?>" />
						</div>
					<?php endif; ?>

					<?php if ( $config['show_sort'] ) : ?>
						<div class="plp-sort">
							<select class="plp-sort__select" aria-label="<?php esc_attr_e( 'Rendezés', 'pl-player' ); ?>">
								<option value="date"><?php esc_html_e( 'Legújabb', 'pl-player' ); ?></option>
								<?php if ( $show_stats ) : ?>
									<option value="plays"><?php esc_html_e( 'Legtöbbet hallgatott', 'pl-player' ); ?></option>
									<option value="likes"><?php esc_html_e( 'Legkedveltebb', 'pl-player' ); ?></option>
								<?php endif; ?>
								<option value="title"><?php esc_html_e( 'Cím szerint', 'pl-player' ); ?></option>
								<option value="random"><?php esc_html_e( 'Véletlen', 'pl-player' ); ?></option>
							</select>
						</div>
					<?php endif; ?>

					<?php if ( $config['show_popup'] ) : ?>
						<button type="button" class="plp-popout"
							data-plp-popup="<?php echo esc_url( PLP_Popup::url( array( 'terms' => implode( ',', $config['terms'] ) ) ) ); ?>"
							title="<?php esc_attr_e( 'Külön ablakban folytatja, így oldalváltásnál sem szakad meg', 'pl-player' ); ?>">
							<span class="plp-icon plp-icon--popout" aria-hidden="true"></span>
							<?php esc_html_e( 'Külön ablakban', 'pl-player' ); ?>
						</button>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="plp-status" role="status" aria-live="polite"></div>

			<ol class="plp-list plp-list--<?php echo esc_attr( $config['layout'] ); ?>"
				style="--plp-columns:<?php echo esc_attr( (string) $config['columns'] ); ?>">
				<?php
				foreach ( $tracks as $index => $track ) {
					self::render_item( $track, $index, $show_stats );
				}
				?>
			</ol>

			<?php // Both of these are always in the markup, just hidden: a later filter
				// or search can need them even when the first page did not. ?>
			<p class="plp-empty" <?php echo $tracks ? 'hidden' : ''; ?>>
				<?php esc_html_e( 'Ebben a kategóriában még nincs lejátszható szám.', 'pl-player' ); ?>
			</p>

			<p class="plp-more" <?php echo $query->max_num_pages > 1 ? '' : 'hidden'; ?>>
				<button type="button" class="plp-more__button">
					<?php esc_html_e( 'Több szám betöltése', 'pl-player' ); ?>
				</button>
			</p>

		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the featured panel used by the hero layout.
	 *
	 * The first track is printed into it on the server so the panel never appears
	 * empty for a moment before the script runs. From then on it follows whatever is
	 * selected.
	 *
	 * @param array $tracks     Tracks on the first page.
	 * @param bool  $show_stats Whether the numbers are public.
	 */
	private static function render_hero( array $tracks, $show_stats, $equalizer = 'yes' ) {
		$first = $tracks ? $tracks[0] : null;
		$cover = $first && $first['cover_large'] ? $first['cover_large'] : ( $first ? $first['cover'] : '' );
		$hue   = $first ? (int) $first['hue'] : 250;

		// The backdrop always carries the hue as a background colour; a cover image just
		// layers on top of it. That way a track without artwork still tints the panel
		// instead of leaving it flat.
		$backdrop = '--plp-hue:' . $hue . ';';

		if ( $cover ) {
			$backdrop .= 'background-image:url(' . esc_url( $cover ) . ');';
		}
		?>
		<div class="plp-hero" data-plp-hero>
			<span class="plp-hero__backdrop" aria-hidden="true" style="<?php echo esc_attr( $backdrop ); ?>"></span>

			<?php if ( 'no' !== $equalizer ) : ?>
				<?php // Driven by the actual audio through the Web Audio API. Hidden until
					// there is real signal to draw, so a silent canvas never sits there. ?>
				<canvas class="plp-hero__eq" data-plp-eq aria-hidden="true" hidden
					data-plp-eq-force="<?php echo 'always' === $equalizer ? '1' : '0'; ?>"></canvas>
			<?php endif; ?>

			<div class="plp-hero__inner">
				<div class="plp-hero__cover">
					<img src="<?php echo esc_url( $cover ); ?>" alt="" decoding="async" <?php echo $cover ? '' : 'hidden'; ?> />
					<span class="plp-hero__cover-empty" aria-hidden="true"
						style="--plp-hue:<?php echo esc_attr( (string) $hue ); ?>"
						<?php echo $cover ? 'hidden' : ''; ?>><?php echo esc_html( $first ? $first['initial'] : '' ); ?></span>
				</div>

				<div class="plp-hero__body">
					<p class="plp-hero__eyebrow"><?php esc_html_e( 'Kiválasztott szám', 'pl-player' ); ?></p>

					<p class="plp-hero__title">
						<?php echo esc_html( $first ? $first['title'] : __( 'Nincs lejátszható szám', 'pl-player' ) ); ?>
					</p>

					<p class="plp-hero__artist"><?php echo esc_html( $first ? $first['artist'] : '' ); ?></p>

					<p class="plp-hero__labels" data-plp-labels <?php echo ( $first && $first['labels'] ) ? '' : 'hidden'; ?>>
						<?php
						if ( $first ) {
							foreach ( $first['labels'] as $label ) {
								printf( '<span class="plp-tag">%s</span>', esc_html( $label ) );
							}
						}
						?>
					</p>

					<p class="plp-hero__about" data-plp-about <?php echo ( $first && '' !== $first['description'] ) ? '' : 'hidden'; ?>>
						<?php echo esc_html( $first ? $first['description'] : '' ); ?>
					</p>

					<div class="plp-hero__progress">
						<span class="plp-hero__time" data-plp-current>0:00</span>
						<input type="range" class="plp-hero__seek" data-plp-seek min="0" max="1000" value="0" step="1"
							aria-label="<?php esc_attr_e( 'Tekerés', 'pl-player' ); ?>" />
						<span class="plp-hero__time" data-plp-total>
							<?php echo esc_html( $first ? $first['duration_human'] : '0:00' ); ?>
						</span>
					</div>

					<div class="plp-hero__controls">
						<button type="button" class="plp-hero__button" data-plp-prev
							aria-label="<?php esc_attr_e( 'Előző szám', 'pl-player' ); ?>">
							<span class="plp-icon plp-icon--prev" aria-hidden="true"></span>
						</button>

						<button type="button" class="plp-hero__button plp-hero__button--main" data-plp-toggle
							aria-label="<?php esc_attr_e( 'Lejátszás', 'pl-player' ); ?>">
							<span class="plp-icon plp-icon--play" aria-hidden="true"></span>
						</button>

						<button type="button" class="plp-hero__button" data-plp-next
							aria-label="<?php esc_attr_e( 'Következő szám', 'pl-player' ); ?>">
							<span class="plp-icon plp-icon--next" aria-hidden="true"></span>
						</button>

						<span class="plp-hero__stats">
							<button type="button" class="plp-hero__like" data-plp-hero-like aria-pressed="false"
								aria-label="<?php esc_attr_e( 'Kedvelés', 'pl-player' ); ?>">
								<span class="plp-icon plp-icon--heart" aria-hidden="true"></span>
								<span data-plp-hero-likes></span>
							</button>

							<?php if ( $show_stats ) : ?>
								<span class="plp-hero__plays" title="<?php esc_attr_e( 'Lejátszások', 'pl-player' ); ?>">
									<span class="plp-icon plp-icon--headphones" aria-hidden="true"></span>
									<span data-plp-hero-plays></span>
								</span>
							<?php endif; ?>
						</span>
					</div>

					<?php if ( ! empty( plp_get_settings()['public_listening'] ) ) : ?>
						<div class="plp-depth" data-plp-depth hidden>
							<span class="plp-depth__label" data-plp-listened></span>
							<span class="plp-depth__curve" data-plp-curve aria-hidden="true"></span>
							<span class="plp-depth__hint"><?php esc_html_e( 'Hallgatottság a szám hossza mentén', 'pl-player' ); ?></span>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the [playlist_stats] block: public top lists and the traffic trend.
	 *
	 * Rendered on the server rather than fetched: a statistics page a few hours stale is
	 * no worse for being cached, and it saves every visitor a round trip.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'  => 10,
				'show'   => 'both',
				'trend'  => 'yes',
				'days'   => 30,
				'accent' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'playlist_stats'
		);

		$settings = plp_get_settings();

		if ( empty( $settings['public_stats'] ) ) {
			return '';
		}

		$limit  = max( 1, min( 50, absint( $atts['limit'] ) ) );
		$show   = in_array( $atts['show'], array( 'plays', 'likes', 'both' ), true ) ? $atts['show'] : 'both';
		$days   = max( 7, min( 90, absint( $atts['days'] ) ) );
		$accent = sanitize_hex_color( (string) $atts['accent'] );
		$style  = $accent ? 'style="--plp-accent:' . esc_attr( $accent ) . '"' : '';

		ob_start();
		?>
		<div class="plp plp-stats" <?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>

			<?php if ( self::is_yes( $atts['trend'] ) && ! empty( $settings['public_trend'] ) ) : ?>
				<?php self::render_trend( PLP_Stats::daily_plays( $days ), $days ); ?>
			<?php endif; ?>

			<div class="plp-stats__columns">
				<?php
				if ( 'likes' !== $show ) {
					self::render_stats_list(
						__( 'Legtöbbet hallgatott', 'pl-player' ),
						PLP_Stats::top_tracks( 'plays', $limit, 0 ),
						'plays'
					);
				}

				if ( 'plays' !== $show ) {
					self::render_stats_list(
						__( 'Legkedveltebb', 'pl-player' ),
						PLP_Stats::top_tracks( 'likes', $limit, 0 ),
						'likes'
					);
				}
				?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the public traffic trend as a bar chart.
	 *
	 * @param array $series Daily series.
	 * @param int   $days   Days covered.
	 */
	private static function render_trend( array $series, $days ) {
		$max = 0;
		foreach ( $series as $point ) {
			$max = max( $max, (int) $point['plays'] );
		}

		if ( ! $max ) {
			return;
		}
		?>
		<div class="plp-trend">
			<p class="plp-trend__title">
				<?php
				printf(
					/* translators: %d: number of days. */
					esc_html__( 'Lejátszások az elmúlt %d napban', 'pl-player' ),
					(int) $days
				);
				?>
			</p>

			<div class="plp-trend__bars">
				<?php
				foreach ( $series as $point ) {
					$value = (int) $point['plays'];
					$share = $value ? max( 3, (int) round( ( $value / $max ) * 100 ) ) : 0;

					printf(
						'<span class="plp-trend__bar" style="height:%1$d%%" title="%2$s"></span>',
						(int) $share,
						esc_attr(
							sprintf(
								/* translators: 1: date, 2: play count. */
								__( '%1$s — %2$s lejátszás', 'pl-player' ),
								$point['date'],
								number_format_i18n( $value )
							)
						)
					);
				}
				?>
			</div>

			<p class="plp-trend__scale">
				<span><?php echo esc_html( $series[0]['date'] ); ?></span>
				<span><?php echo esc_html( $series[ count( $series ) - 1 ]['date'] ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders one public top list.
	 *
	 * @param string $title  Heading.
	 * @param array  $rows   Rows from PLP_Stats::top_tracks().
	 * @param string $metric `plays` or `likes`.
	 */
	private static function render_stats_list( $title, array $rows, $metric ) {
		echo '<div class="plp-stats__block">';
		printf( '<h3 class="plp-stats__title">%s</h3>', esc_html( $title ) );

		if ( ! $rows ) {
			printf( '<p class="plp-empty">%s</p>', esc_html__( 'Még nincs adat.', 'pl-player' ) );
			echo '</div>';
			return;
		}

		echo '<ol class="plp-stats__list">';

		foreach ( $rows as $row ) {
			$track = PLP_Source::track_data( $row['id'] );

			if ( ! $track ) {
				continue;
			}
			?>
			<li class="plp-stats__row">
				<span class="plp-stats__cover" style="--plp-hue:<?php echo esc_attr( (string) $track['hue'] ); ?>">
					<?php if ( $track['cover'] ) : ?>
						<img src="<?php echo esc_url( $track['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
					<?php else : ?>
						<span aria-hidden="true"><?php echo esc_html( $track['initial'] ); ?></span>
					<?php endif; ?>
				</span>

				<span class="plp-stats__meta">
					<a class="plp-stats__name" href="<?php echo esc_url( $track['permalink'] ); ?>">
						<?php echo esc_html( $track['title'] ); ?>
					</a>
					<?php if ( $track['artist'] ) : ?>
						<span class="plp-stats__artist"><?php echo esc_html( $track['artist'] ); ?></span>
					<?php endif; ?>
				</span>

				<span class="plp-stats__value">
					<span class="plp-icon plp-icon--<?php echo 'likes' === $metric ? 'heart' : 'headphones'; ?>" aria-hidden="true"></span>
					<?php echo esc_html( number_format_i18n( (int) $row['value'] ) ); ?>
				</span>
			</li>
			<?php
		}

		echo '</ol></div>';
	}

	/**
	 * Renders one row of the list.
	 *
	 * The counters are printed empty on purpose — the script fills them from the REST
	 * route so a page cache cannot freeze the numbers into the HTML.
	 *
	 * @param array $track      Track payload.
	 * @param int   $index      Position in the list.
	 * @param bool  $show_stats Whether the numbers are public.
	 */
	public static function render_item( array $track, $index, $show_stats ) {
		?>
		<li class="plp-track"
			data-id="<?php echo esc_attr( (string) $track['id'] ); ?>"
			data-audio="<?php echo esc_url( $track['audio'] ); ?>"
			data-title="<?php echo esc_attr( $track['title'] ); ?>"
			data-artist="<?php echo esc_attr( $track['artist'] ); ?>"
			data-album="<?php echo esc_attr( $track['album'] ); ?>"
			data-cover="<?php echo esc_url( $track['cover_large'] ? $track['cover_large'] : $track['cover'] ); ?>"
			data-hue="<?php echo esc_attr( (string) $track['hue'] ); ?>"
			data-initial="<?php echo esc_attr( $track['initial'] ); ?>"
			data-labels="<?php echo esc_attr( implode( '|', $track['labels'] ) ); ?>"
			data-about="<?php echo esc_attr( $track['description'] ); ?>"
			data-duration="<?php echo esc_attr( (string) $track['duration'] ); ?>">

			<button type="button" class="plp-track__play" aria-label="<?php
				/* translators: %s: track title. */
				echo esc_attr( sprintf( __( '%s lejátszása', 'pl-player' ), $track['title'] ) );
			?>">
				<span class="plp-icon plp-icon--play" aria-hidden="true"></span>
			</button>

			<span class="plp-track__cover">
				<?php if ( $track['cover'] ) : ?>
					<img src="<?php echo esc_url( $track['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
				<?php else : ?>
					<span class="plp-track__cover-empty" aria-hidden="true"
						style="--plp-hue:<?php echo esc_attr( (string) $track['hue'] ); ?>"><?php echo esc_html( $track['initial'] ); ?></span>
				<?php endif; ?>
			</span>

			<span class="plp-track__meta">
				<span class="plp-track__title"><?php echo esc_html( $track['title'] ); ?></span>
				<?php if ( $track['artist'] ) : ?>
					<span class="plp-track__artist"><?php echo esc_html( $track['artist'] ); ?></span>
				<?php endif; ?>
			</span>

			<span class="plp-track__duration"><?php echo esc_html( $track['duration_human'] ); ?></span>

			<span class="plp-track__stats">
				<button type="button" class="plp-like" aria-pressed="false" aria-label="<?php esc_attr_e( 'Kedvelés', 'pl-player' ); ?>">
					<span class="plp-icon plp-icon--heart" aria-hidden="true"></span>
					<span class="plp-like__count"></span>
				</button>

				<?php if ( $show_stats ) : ?>
					<span class="plp-plays" title="<?php esc_attr_e( 'Lejátszások', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--headphones" aria-hidden="true"></span>
						<span class="plp-plays__count"></span>
					</span>
				<?php endif; ?>
			</span>
		</li>
		<?php
	}

	/**
	 * Renders the category navigation.
	 *
	 * Hierarchical taxonomies keep their nesting, which is what makes the categories
	 * feel like folders.
	 *
	 * @param array $config Player configuration.
	 */
	private static function render_nav( array $config ) {
		$taxonomies = PLP_Source::all_taxonomies();

		// A single named taxonomy keeps the navigation readable on sites where several
		// content types — each with its own categories — feed the same player.
		if ( $config['nav_taxonomy'] && in_array( $config['nav_taxonomy'], $taxonomies, true ) ) {
			$taxonomies = array( $config['nav_taxonomy'] );
		}

		$items = array();

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);

			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$by_parent = array();

			foreach ( $terms as $term ) {
				$by_parent[ (int) $term->parent ][] = $term;
			}

			self::flatten_terms( $by_parent, 0, 0, $items );
		}

		if ( ! $items ) {
			return;
		}

		$limit = $config['nav_limit'];
		$shown = 0;
		$extra = 0;
		?>
		<nav class="plp-nav" aria-label="<?php esc_attr_e( 'Kategóriák', 'pl-player' ); ?>">
			<button type="button" class="plp-nav__item is-active" data-term="0">
				<?php esc_html_e( 'Összes', 'pl-player' ); ?>
			</button>

			<?php
			foreach ( $items as $item ) {
				$term      = $item['term'];
				$is_active = in_array( (int) $term->term_id, $config['terms'], true );

				// An active category is always visible, however far down the list it
				// sits — hiding the current filter behind a "show more" would be absurd.
				$is_extra = $limit && $shown >= $limit && ! $is_active;

				if ( $is_extra ) {
					$extra++;
				} else {
					$shown++;
				}

				printf(
					'<button type="button" class="plp-nav__item%1$s%2$s%3$s" data-term="%4$d"%5$s>%6$s <span class="plp-nav__count">%7$s</span></button>',
					$item['depth'] ? ' plp-nav__item--child' : '',
					$is_active ? ' is-active' : '',
					$is_extra ? ' plp-nav__item--extra' : '',
					(int) $term->term_id,
					$is_extra ? ' hidden' : '',
					esc_html( $term->name ),
					esc_html( number_format_i18n( (int) $term->count ) )
				);
			}
			?>

			<?php if ( $extra ) : ?>
				<button type="button" class="plp-nav__more">
					<?php
					printf(
						/* translators: %s: number of hidden categories. */
						esc_html__( 'További %s kategória', 'pl-player' ),
						esc_html( number_format_i18n( $extra ) )
					);
					?>
				</button>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Flattens a term tree into an ordered list, keeping children after their parent.
	 *
	 * @param array $by_parent Terms grouped by parent.
	 * @param int   $parent    Parent term ID.
	 * @param int   $depth     Current depth.
	 * @param array $items     Accumulator, by reference.
	 */
	private static function flatten_terms( array $by_parent, $parent, $depth, array &$items ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent ] as $term ) {
			$items[] = array(
				'term'  => $term,
				'depth' => $depth,
			);

			self::flatten_terms( $by_parent, (int) $term->term_id, $depth + 1, $items );
		}
	}

	/* ---------------------------------------------------------------------
	 * Sticky bar
	 * ------------------------------------------------------------------ */

	/**
	 * Prints the sticky bar once, if a player was rendered on this page.
	 */
	public static function maybe_render_bar() {
		if ( ! self::$bar_queued ) {
			return;
		}
		?>
		<div class="plp-bar" id="plp-bar" hidden>
			<div class="plp-bar__inner">

				<span class="plp-bar__cover"><img src="" alt="" id="plp-bar-cover" /></span>

				<span class="plp-bar__meta">
					<span class="plp-bar__title" id="plp-bar-title"></span>
					<span class="plp-bar__artist" id="plp-bar-artist"></span>
				</span>

				<span class="plp-bar__controls">
					<button type="button" class="plp-bar__button" id="plp-prev" aria-label="<?php esc_attr_e( 'Előző szám', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--prev" aria-hidden="true"></span>
					</button>
					<button type="button" class="plp-bar__button plp-bar__button--main" id="plp-toggle" aria-label="<?php esc_attr_e( 'Lejátszás / megállítás', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--play" aria-hidden="true"></span>
					</button>
					<button type="button" class="plp-bar__button" id="plp-next" aria-label="<?php esc_attr_e( 'Következő szám', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--next" aria-hidden="true"></span>
					</button>
				</span>

				<span class="plp-bar__progress">
					<span class="plp-bar__time" id="plp-current">0:00</span>
					<input type="range" id="plp-seek" min="0" max="1000" value="0" step="1"
						aria-label="<?php esc_attr_e( 'Tekerés', 'pl-player' ); ?>" />
					<span class="plp-bar__time" id="plp-total">0:00</span>
				</span>

				<span class="plp-bar__extras">
					<button type="button" class="plp-bar__button" id="plp-shuffle" aria-pressed="false"
						aria-label="<?php esc_attr_e( 'Véletlen sorrend', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--shuffle" aria-hidden="true"></span>
					</button>
					<button type="button" class="plp-bar__button" id="plp-repeat" aria-pressed="false"
						aria-label="<?php esc_attr_e( 'Ismétlés', 'pl-player' ); ?>">
						<span class="plp-icon plp-icon--repeat" aria-hidden="true"></span>
					</button>
					<input type="range" id="plp-volume" class="plp-bar__volume" min="0" max="100" value="100"
						aria-label="<?php esc_attr_e( 'Hangerő', 'pl-player' ); ?>" />
				</span>

			</div>
		</div>
		<?php
	}
}
