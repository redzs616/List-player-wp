<?php
/**
 * The duplicate report screen.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows what appears in the player more than once, and lets the owner act on it.
 *
 * Nothing is deleted automatically. Each row gets WordPress's own trash link, so a
 * mistake is one "Undo" away — and choosing which copy to keep stays a human decision.
 */
class PLP_Duplicates_Page {

	const SLUG = 'plp-duplicates';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_plp_export_duplicates', array( __CLASS__, 'export_csv' ) );
	}

	/**
	 * Adds the submenu entry.
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Duplikátumok', 'pl-player' ),
			__( 'Duplikátumok', 'pl-player' ),
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

		wp_enqueue_style( 'plp-duplicates', PLP_URL . 'admin/assets/css/duplicates.css', array(), PLP_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the report.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod a jelentés megtekintéséhez.', 'pl-player' ) );
		}

		$report = PLP_Duplicates::report();
		?>
		<div class="wrap plp-dupes">
			<h1><?php esc_html_e( 'Duplikátumok', 'pl-player' ); ?></h1>

			<p class="plp-dupes__intro">
				<?php esc_html_e( 'Ez a jelentés azt keresi, mely felvételek szerepelnek a lejátszóban többször. A leggyakoribb ok, hogy ugyanaz az MP3 két bejegyzésben is szerepel — egy podcast epizódban és egy zeneszámban. Semmit nem töröl: te döntöd el, melyik példány maradjon.', 'pl-player' ); ?>
			</p>

			<?php self::render_summary( $report ); ?>

			<?php if ( ! $report['groups'] ) : ?>
				<div class="notice notice-success"><p>
					<?php esc_html_e( 'Nem találtam duplikátumot. Minden felvétel egyszer szerepel.', 'pl-player' ); ?>
				</p></div>
				<?php self::render_advice(); ?>
				</div>
				<?php
				return;
			endif;
			?>

			<p>
				<a class="button" href="<?php echo esc_url( self::export_url() ); ?>">
					<?php esc_html_e( 'Jelentés letöltése CSV-ben', 'pl-player' ); ?>
				</a>
			</p>

			<?php
			$previous = '';

			foreach ( $report['groups'] as $group ) {
				if ( $group['kind'] !== $previous ) {
					printf( '<h2>%s</h2>', esc_html( PLP_Duplicates::kind_label( $group['kind'] ) ) );
					printf( '<p class="plp-dupes__note">%s</p>', esc_html( PLP_Duplicates::kind_note( $group['kind'] ) ) );
					$previous = $group['kind'];
				}

				self::render_group( $group );
			}
			?>

			<?php self::render_advice(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the headline counts.
	 *
	 * @param array $report Report data.
	 */
	private static function render_summary( array $report ) {
		$cards = array(
			array( __( 'Átvizsgált felvétel', 'pl-player' ), $report['checked'] ),
			array( __( 'Ütköző csoport', 'pl-player' ), count( $report['groups'] ) ),
			array( __( 'Törölhető bejegyzés', 'pl-player' ), $report['extra'] ),
		);

		echo '<div class="plp-cards">';

		foreach ( $cards as $card ) {
			printf(
				'<div class="plp-card"><span class="plp-card__label">%1$s</span><span class="plp-card__value">%2$s</span></div>',
				esc_html( $card[0] ),
				esc_html( number_format_i18n( (int) $card[1] ) )
			);
		}

		echo '</div>';
	}

	/**
	 * Renders one duplicate group.
	 *
	 * @param array $group Group data.
	 */
	private static function render_group( array $group ) {
		$keep = PLP_Duplicates::suggest_keep( $group['items'] );

		echo '<table class="wp-list-table widefat striped plp-dupes__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Bejegyzés', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Típus', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Dátum', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Hossz', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Lejátszás', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Kedvelés', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Művelet', 'pl-player' ) );
		echo '</tr></thead><tbody>';

		foreach ( $group['items'] as $item ) {
			$is_keep = ( $item['id'] === $keep );
			$type    = get_post_type_object( $item['type'] );
			$trash   = get_delete_post_link( $item['id'] );

			printf( '<tr class="%s">', $is_keep ? 'plp-dupes__keep' : '' );

			printf(
				'<td><a href="%1$s"><strong>%2$s</strong></a>%3$s<br /><span class="plp-dupes__file">%4$s</span></td>',
				esc_url( (string) get_edit_post_link( $item['id'] ) ),
				esc_html( $item['title'] ),
				$is_keep ? ' <span class="plp-dupes__badge">' . esc_html__( 'ezt javaslom megtartani', 'pl-player' ) . '</span>' : '',
				esc_html( $item['file'] )
			);

			printf( '<td>%s</td>', esc_html( $type ? $type->labels->singular_name : $item['type'] ) );
			printf( '<td>%s</td>', esc_html( $item['date'] ) );
			printf( '<td>%s</td>', esc_html( plp_format_duration( $item['duration'] ) ) );
			printf( '<td class="plp-num">%s</td>', esc_html( number_format_i18n( $item['plays'] ) ) );
			printf( '<td class="plp-num">%s</td>', esc_html( number_format_i18n( $item['likes'] ) ) );

			echo '<td>';

			if ( $is_keep ) {
				printf( '<span class="plp-dupes__muted">%s</span>', esc_html__( 'marad', 'pl-player' ) );
			} elseif ( $trash ) {
				printf(
					'<a class="plp-dupes__trash" href="%s">%s</a>',
					esc_url( $trash ),
					esc_html__( 'Lomtárba', 'pl-player' )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * The advice block: deleting is not the only way out.
	 */
	private static function render_advice() {
		?>
		<div class="plp-dupes__advice">
			<h2><?php esc_html_e( 'Törlés nélkül is megoldható', 'pl-player' ); ?></h2>
			<p>
				<?php esc_html_e( 'Ha a duplikátumok nagy része abból fakad, hogy ugyanaz a felvétel egyszer podcast epizódként, egyszer zeneszámként létezik, akkor a legkisebb kockázatú megoldás nem a törlés: a Beállításokban vedd ki a pipát az egyik tartalomtípusnál.', 'pl-player' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'A lejátszó onnantól csak az egyiket listázza, viszont mindkét bejegyzés megmarad — a podcast RSS-ed és a meglévő linkek sértetlenek. A törlés visszafordíthatatlanabb, és a hozzá tartozó lejátszásszám is elveszik vele.', 'pl-player' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'post_type' => PLP_Post_Types::TRACK, 'page' => PLP_Settings_Page::SLUG ), admin_url( 'edit.php' ) ) ); ?>">
					<?php esc_html_e( 'Beállítások megnyitása', 'pl-player' ); ?>
				</a>
			</p>
			<p class="description">
				<?php esc_html_e( 'A „Lomtárba" gomb a WordPress saját lomtárát használja, tehát a törlés visszavonható, amíg nem ürítesz lomtárat.', 'pl-player' ); ?>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce-protected export URL.
	 *
	 * @return string
	 */
	private static function export_url() {
		return wp_nonce_url(
			add_query_arg( 'action', 'plp_export_duplicates', admin_url( 'admin-post.php' ) ),
			'plp_export_duplicates'
		);
	}

	/**
	 * Streams the report as CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod az exporthoz.', 'pl-player' ) );
		}

		check_admin_referer( 'plp_export_duplicates' );

		$report = PLP_Duplicates::report();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="duplikatumok-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );

		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Csoport', 'pl-player' ),
				__( 'Egyezés alapja', 'pl-player' ),
				__( 'Javaslat', 'pl-player' ),
				__( 'Azonosító', 'pl-player' ),
				__( 'Cím', 'pl-player' ),
				__( 'Típus', 'pl-player' ),
				__( 'Állapot', 'pl-player' ),
				__( 'Dátum', 'pl-player' ),
				__( 'Hossz (mp)', 'pl-player' ),
				__( 'Lejátszás', 'pl-player' ),
				__( 'Kedvelés', 'pl-player' ),
				__( 'Fájl', 'pl-player' ),
			),
			';'
		);

		$number = 0;

		foreach ( $report['groups'] as $group ) {
			$number++;
			$keep = PLP_Duplicates::suggest_keep( $group['items'] );

			foreach ( $group['items'] as $item ) {
				fputcsv(
					$out,
					array(
						$number,
						PLP_Duplicates::kind_label( $group['kind'] ),
						$item['id'] === $keep ? __( 'megtartani', 'pl-player' ) : __( 'törölhető', 'pl-player' ),
						$item['id'],
						$item['title'],
						$item['type'],
						$item['status'],
						$item['date'],
						$item['duration'],
						$item['plays'],
						$item['likes'],
						$item['file'],
					),
					';'
				);
			}
		}

		fclose( $out );
		exit;
	}
}
