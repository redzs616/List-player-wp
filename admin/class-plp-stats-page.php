<?php
/**
 * The statistics report screen.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the report and serves the CSV export.
 */
class PLP_Stats_Page {

	const SLUG = 'plp-stats';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Periods offered by the selector, in days.
	 */
	const PERIODS = array( 7, 30, 90 );

	/**
	 * Hooks the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_plp_export_stats', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * Adds the submenu entry.
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Statisztika', 'pl-player' ),
			__( 'Statisztika', 'pl-player' ),
			'edit_others_posts',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads the report stylesheet.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style(
			'plp-stats',
			PLP_URL . 'admin/assets/css/stats.css',
			array(),
			PLP_VERSION
		);
	}

	/**
	 * The period currently selected, in days.
	 *
	 * @return int
	 */
	private static function period() {
		$days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification

		return in_array( $days, self::PERIODS, true ) ? $days : 30;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the report.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod a statisztika megtekintéséhez.', 'pl-player' ) );
		}

		$days   = self::period();
		$totals = PLP_Stats::totals();
		$series = PLP_Stats::daily_plays( $days );
		?>
		<div class="wrap plp-report">
			<h1><?php esc_html_e( 'Lejátszó statisztika', 'pl-player' ); ?></h1>

			<?php self::render_cards( $totals ); ?>

			<h2><?php esc_html_e( 'Lejátszások napi bontásban', 'pl-player' ); ?></h2>

			<p class="plp-report__periods">
				<?php
				foreach ( self::PERIODS as $option ) {
					printf(
						'<a class="button %1$s" href="%2$s">%3$s</a> ',
						$option === $days ? 'button-primary' : '',
						esc_url( self::page_url( array( 'days' => $option ) ) ),
						esc_html(
							sprintf(
								/* translators: %d: number of days. */
								_n( 'Utolsó %d nap', 'Utolsó %d nap', $option, 'pl-player' ),
								$option
							)
						)
					);
				}
				?>
			</p>

			<?php self::render_chart( $series ); ?>

			<div class="plp-report__columns">
				<?php
				self::render_top_table(
					__( 'Legtöbbet hallgatott', 'pl-player' ),
					PLP_Stats::top_tracks( 'plays', 20, 0 ),
					__( 'lejátszás', 'pl-player' )
				);

				self::render_top_table(
					__( 'Legkedveltebb', 'pl-player' ),
					PLP_Stats::top_tracks( 'likes', 20, 0 ),
					__( 'kedvelés', 'pl-player' )
				);
				?>
			</div>

			<h2>
				<?php
				printf(
					/* translators: %d: number of days. */
					esc_html__( 'A periódus nyertesei (utolsó %d nap)', 'pl-player' ),
					(int) $days
				);
				?>
			</h2>
			<?php
			self::render_top_table(
				'',
				PLP_Stats::top_tracks( 'plays', 10, $days ),
				__( 'lejátszás', 'pl-player' )
			);
			?>

			<h2><?php esc_html_e( 'Kategóriák', 'pl-player' ); ?></h2>
			<?php self::render_categories(); ?>

			<h2><?php esc_html_e( 'Export', 'pl-player' ); ?></h2>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( self::export_url() ); ?>">
					<?php esc_html_e( 'Letöltés CSV-ben', 'pl-player' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'Minden lejátszható szám egy sorban: cím, előadó, hossz, kategóriák, lejátszás- és kedvelésszám. Excelben és Google Táblázatokban is megnyitható.', 'pl-player' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Renders the summary cards.
	 *
	 * @param array $totals Totals.
	 */
	private static function render_cards( array $totals ) {
		$cards = array(
			array( __( 'Összes lejátszás', 'pl-player' ), number_format_i18n( (int) $totals['plays'] ) ),
			array( __( 'Összes kedvelés', 'pl-player' ), number_format_i18n( (int) $totals['likes'] ) ),
			// Not a count but a duration, so it gets the readable form rather than a
			// raw number of seconds nobody can picture.
			array( __( 'Összes hallgatott idő', 'pl-player' ), plp_format_listening_time( (int) $totals['seconds'] ) ),
			array( __( 'Ma', 'pl-player' ), number_format_i18n( (int) $totals['today'] ) ),
			array( __( 'Utolsó 7 nap', 'pl-player' ), number_format_i18n( (int) $totals['week'] ) ),
			array( __( 'Lejátszható szám', 'pl-player' ), number_format_i18n( (int) $totals['tracks'] ) ),
		);

		echo '<div class="plp-cards">';

		foreach ( $cards as $card ) {
			printf(
				'<div class="plp-card"><span class="plp-card__label">%1$s</span><span class="plp-card__value">%2$s</span></div>',
				esc_html( $card[0] ),
				esc_html( '' === (string) $card[1] ? '—' : $card[1] )
			);
		}

		echo '</div>';
	}

	/**
	 * Draws the daily plays chart.
	 *
	 * Hand-drawn SVG rather than a charting library: it is one bar per day, and pulling
	 * in a script for that would cost more than it is worth.
	 *
	 * @param array $series Daily series.
	 */
	private static function render_chart( array $series ) {
		$count = count( $series );

		if ( ! $count ) {
			printf( '<p class="plp-report__empty">%s</p>', esc_html__( 'Még nincs adat.', 'pl-player' ) );
			return;
		}

		$max = 0;
		foreach ( $series as $point ) {
			$max = max( $max, (int) $point['plays'] );
		}

		if ( ! $max ) {
			printf(
				'<p class="plp-report__empty">%s</p>',
				esc_html__( 'Ebben az időszakban még nem volt lejátszás. A számok akkor jelennek meg, amikor a látogatók elérik a beállított minimum hallgatási időt.', 'pl-player' )
			);
			return;
		}

		$width  = 720;
		$height = 200;
		$pad    = 24;
		$plot   = $height - $pad;
		$step   = $width / $count;
		$bar    = max( 1, min( 22, $step - 2 ) );
		?>
		<div class="plp-chart">
			<svg viewBox="0 0 <?php echo esc_attr( (string) $width ); ?> <?php echo esc_attr( (string) $height ); ?>"
				preserveAspectRatio="none" role="img"
				aria-label="<?php esc_attr_e( 'Napi lejátszások grafikonja', 'pl-player' ); ?>">

				<line x1="0" y1="<?php echo esc_attr( (string) $plot ); ?>" x2="<?php echo esc_attr( (string) $width ); ?>"
					y2="<?php echo esc_attr( (string) $plot ); ?>" class="plp-chart__axis" />

				<?php
				foreach ( $series as $index => $point ) {
					$value = (int) $point['plays'];
					$h     = $value ? max( 2, (int) round( ( $value / $max ) * ( $plot - 6 ) ) ) : 0;
					$x     = ( $index * $step ) + ( ( $step - $bar ) / 2 );

					if ( ! $h ) {
						continue;
					}

					printf(
						'<rect x="%1$s" y="%2$s" width="%3$s" height="%4$s" rx="2" class="plp-chart__bar"><title>%5$s</title></rect>',
						esc_attr( (string) round( $x, 2 ) ),
						esc_attr( (string) ( $plot - $h ) ),
						esc_attr( (string) round( $bar, 2 ) ),
						esc_attr( (string) $h ),
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
			</svg>

			<div class="plp-chart__labels">
				<span><?php echo esc_html( $series[0]['date'] ); ?></span>
				<span class="plp-chart__peak">
					<?php
					printf(
						/* translators: %s: highest daily play count. */
						esc_html__( 'csúcs: %s', 'pl-player' ),
						esc_html( number_format_i18n( $max ) )
					);
					?>
				</span>
				<span><?php echo esc_html( $series[ $count - 1 ]['date'] ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a top list.
	 *
	 * @param string $title Optional heading.
	 * @param array  $rows  Rows from PLP_Stats::top_tracks().
	 * @param string $unit  Word for the value column.
	 */
	private static function render_top_table( $title, array $rows, $unit ) {
		echo '<div class="plp-report__block">';

		if ( $title ) {
			printf( '<h3>%s</h3>', esc_html( $title ) );
		}

		if ( ! $rows ) {
			printf( '<p class="plp-report__empty">%s</p>', esc_html__( 'Még nincs adat.', 'pl-player' ) );
			echo '</div>';
			return;
		}

		echo '<table class="wp-list-table widefat striped plp-report__table"><tbody>';

		$position = 0;

		foreach ( $rows as $row ) {
			$position++;
			$title_text = get_the_title( $row['id'] );
			$edit_link  = get_edit_post_link( $row['id'] );

			printf(
				'<tr><td class="plp-report__rank">%1$d</td><td>%2$s</td><td class="plp-report__value">%3$s <span>%4$s</span></td></tr>',
				(int) $position,
				$edit_link
					? '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $title_text ) . '</a>'
					: esc_html( $title_text ),
				esc_html( number_format_i18n( (int) $row['value'] ) ),
				esc_html( $unit )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Renders the category breakdown.
	 */
	private static function render_categories() {
		$rows = PLP_Stats::category_plays( 25 );

		if ( ! $rows ) {
			printf( '<p class="plp-report__empty">%s</p>', esc_html__( 'Még nincs adat.', 'pl-player' ) );
			return;
		}

		$max = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['plays'] );
		}

		echo '<table class="wp-list-table widefat striped plp-report__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Kategória', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Számok', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Lejátszás', 'pl-player' ) );
		printf( '<th></th>' );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$share = $max ? max( 1, (int) round( ( $row['plays'] / $max ) * 100 ) ) : 0;

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $row['name'] ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['tracks'] ) ) );
			printf( '<td>%s</td>', esc_html( number_format_i18n( $row['plays'] ) ) );
			printf(
				'<td class="plp-report__bar-cell"><span class="plp-report__bar" style="width:%d%%"></span></td>',
				(int) $share
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Egy szám több kategóriában is szerepelhet, ilyenkor mindegyikben számít. A lejátszásszám az összesített számlálókból jön, tehát a teljes időszakra vonatkozik.', 'pl-player' )
		);
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * URL of this page with extra arguments.
	 *
	 * @param array $args Extra query arguments.
	 * @return string
	 */
	private static function page_url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => PLP_Post_Types::TRACK,
					'page'      => self::SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Nonce-protected export URL.
	 *
	 * @return string
	 */
	private static function export_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'plp_export_stats', admin_url( 'admin-post.php' ) ),
			'plp_export_stats'
		);
	}

	/* ---------------------------------------------------------------------
	 * CSV export
	 * ------------------------------------------------------------------ */

	/**
	 * Streams every playable track as CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod az exporthoz.', 'pl-player' ) );
		}

		check_admin_referer( 'plp_export_stats' );

		$filename = 'lejatszo-statisztika-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// Excel needs the byte order mark to read UTF-8 accents correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Azonosító', 'pl-player' ),
				__( 'Cím', 'pl-player' ),
				__( 'Előadó', 'pl-player' ),
				__( 'Album', 'pl-player' ),
				__( 'Hossz (mp)', 'pl-player' ),
				__( 'Tartalomtípus', 'pl-player' ),
				__( 'Kategóriák', 'pl-player' ),
				__( 'Lejátszás', 'pl-player' ),
				__( 'Kedvelés', 'pl-player' ),
				__( 'Hallgatott idő (mp)', 'pl-player' ),
				__( 'Közzétéve', 'pl-player' ),
			),
			';'
		);

		$paged = 1;

		// Paged rather than one big query: a few thousand tracks would otherwise load
		// every post object into memory at once.
		do {
			$query = new WP_Query(
				array(
					'post_type'              => PLP_Source::post_types(),
					'post_status'            => 'publish',
					'posts_per_page'         => 200,
					'paged'                  => $paged,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => true,
				)
			);

			foreach ( $query->posts as $post ) {
				$categories = array();

				foreach ( PLP_Source::all_taxonomies() as $taxonomy ) {
					$terms = get_the_terms( $post, $taxonomy );

					if ( is_wp_error( $terms ) || ! $terms ) {
						continue;
					}

					foreach ( $terms as $term ) {
						$categories[] = $term->name;
					}
				}

				fputcsv(
					$out,
					array(
						$post->ID,
						get_the_title( $post ),
						(string) get_post_meta( $post->ID, '_pl_artist', true ),
						(string) get_post_meta( $post->ID, '_pl_album', true ),
						absint( get_post_meta( $post->ID, '_pl_duration', true ) ),
						$post->post_type,
						implode( ', ', $categories ),
						PLP_Stats::plays( $post->ID ),
						PLP_Stats::likes( $post->ID ),
						PLP_Stats::seconds( $post->ID ),
						get_the_date( 'Y-m-d', $post ),
					),
					';'
				);
			}

			$paged++;
		} while ( $paged <= (int) $query->max_num_pages );

		fclose( $out );
		exit;
	}
}
