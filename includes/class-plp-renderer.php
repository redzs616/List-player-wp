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
				'search'    => 'yes',
				'sort'      => 'yes',
				'accent'    => '',
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
			'show_search' => self::is_yes( $atts['search'] ),
			'show_sort'  => self::is_yes( $atts['sort'] ),
			'accent'     => sanitize_hex_color( (string) $atts['accent'] ),
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
		$query  = new WP_Query( PLP_Source::query_args( $config ) );

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
		<div class="plp plp--<?php echo esc_attr( $config['layout'] ); ?>" data-plp="<?php echo esc_attr( (string) wp_json_encode( $client_config ) ); ?>" <?php echo $style; // phpcs:ignore WordPress.Security.EscapeOutput ?>>

			<?php if ( 'hero' === $config['layout'] ) : ?>
				<?php self::render_hero( $tracks, $show_stats ); ?>
			<?php endif; ?>

			<?php if ( $config['show_nav'] ) : ?>
				<?php self::render_nav( $config ); ?>
			<?php endif; ?>

			<?php if ( $config['show_search'] || $config['show_sort'] ) : ?>
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
	private static function render_hero( array $tracks, $show_stats ) {
		$first = $tracks ? $tracks[0] : null;
		$cover = $first && $first['cover_large'] ? $first['cover_large'] : ( $first ? $first['cover'] : '' );
		?>
		<div class="plp-hero" data-plp-hero>
			<span class="plp-hero__backdrop" aria-hidden="true"
				style="<?php echo $cover ? 'background-image:url(' . esc_url( $cover ) . ')' : ''; ?>"></span>

			<div class="plp-hero__inner">
				<div class="plp-hero__cover">
					<?php if ( $cover ) : ?>
						<img src="<?php echo esc_url( $cover ); ?>" alt="" decoding="async" />
					<?php else : ?>
						<img src="" alt="" decoding="async" hidden />
						<span class="plp-hero__cover-empty" aria-hidden="true"></span>
					<?php endif; ?>
				</div>

				<div class="plp-hero__body">
					<p class="plp-hero__eyebrow"><?php esc_html_e( 'Kiválasztott szám', 'pl-player' ); ?></p>

					<p class="plp-hero__title">
						<?php echo esc_html( $first ? $first['title'] : __( 'Nincs lejátszható szám', 'pl-player' ) ); ?>
					</p>

					<p class="plp-hero__artist"><?php echo esc_html( $first ? $first['artist'] : '' ); ?></p>

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
				</div>
			</div>
		</div>
		<?php
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
					<span class="plp-track__cover-empty" aria-hidden="true"></span>
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
		$groups = array();

		foreach ( PLP_Source::all_taxonomies() as $taxonomy ) {
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);

			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			$groups[ $taxonomy ] = $terms;
		}

		if ( ! $groups ) {
			return;
		}
		?>
		<nav class="plp-nav" aria-label="<?php esc_attr_e( 'Kategóriák', 'pl-player' ); ?>">
			<button type="button" class="plp-nav__item is-active" data-term="0">
				<?php esc_html_e( 'Összes', 'pl-player' ); ?>
			</button>
			<?php
			foreach ( $groups as $terms ) {
				$by_parent = array();

				foreach ( $terms as $term ) {
					$by_parent[ (int) $term->parent ][] = $term;
				}

				self::render_nav_level( $by_parent, 0, $config['terms'] );
			}
			?>
		</nav>
		<?php
	}

	/**
	 * Prints one level of the category navigation.
	 *
	 * @param array $by_parent Terms grouped by parent.
	 * @param int   $parent    Parent term ID.
	 * @param array $active    Currently selected term IDs.
	 */
	private static function render_nav_level( array $by_parent, $parent, array $active ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}

		foreach ( $by_parent[ $parent ] as $term ) {
			$depth = $parent ? ' plp-nav__item--child' : '';

			printf(
				'<button type="button" class="plp-nav__item%1$s%2$s" data-term="%3$d">%4$s <span class="plp-nav__count">%5$s</span></button>',
				esc_attr( $depth ),
				in_array( (int) $term->term_id, $active, true ) ? ' is-active' : '',
				(int) $term->term_id,
				esc_html( $term->name ),
				esc_html( number_format_i18n( (int) $term->count ) )
			);

			self::render_nav_level( $by_parent, (int) $term->term_id, $active );
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
