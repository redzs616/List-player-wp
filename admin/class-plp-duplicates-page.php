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
		add_action( 'admin_post_plp_trash_duplicates', array( __CLASS__, 'bulk_trash' ) );
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

		wp_enqueue_script(
			'plp-duplicates',
			PLP_URL . 'admin/assets/js/duplicates.js',
			array(),
			PLP_VERSION,
			true
		);

		wp_localize_script(
			'plp-duplicates',
			'PLPDupes',
			array(
				'nothingPicked' => __( 'Nem jelöltél ki egyetlen bejegyzést sem.', 'pl-player' ),
				/* translators: %d: number of posts. */
				'confirm'       => __( 'Ez %d bejegyzést tesz a lomtárba. A lomtárból visszaállíthatók, amíg nem ürítesz lomtárat. Folytatod?', 'pl-player' ),
				/* translators: 1: number of posts, 2: number of posts that are not plugin tracks. */
				'confirmMixed'  => __( 'Ez %1$d bejegyzést tesz a lomtárba, és közülük %2$d NEM a bővítmény saját zeneszáma, hanem meglévő tartalom — például podcast epizód, aminek saját linkje és RSS bejegyzése van. A lomtárból visszaállíthatók. Folytatod?', 'pl-player' ),
				/* translators: %d: number of selected posts. */
				'selected'      => __( 'Kijelölve: %d', 'pl-player' ),
			)
		);
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

			<?php self::render_result_notice(); ?>

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

			<?php self::render_foreign_warning( $report ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-plp-dupes-form>
				<input type="hidden" name="action" value="plp_trash_duplicates" />
				<?php wp_nonce_field( 'plp_trash_duplicates' ); ?>

				<?php self::render_actions(); ?>

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

				<?php self::render_actions(); ?>
			</form>

			<?php self::render_advice(); ?>
		</div>
		<?php
	}

	/**
	 * Warns when the removable copies include content the player did not create.
	 *
	 * This is the mistake worth preventing rather than merely undoing: a podcast episode
	 * has a permalink people may have shared and an entry in a feed readers have already
	 * fetched. For that pairing there is a fix that removes nothing at all.
	 *
	 * @param array $report Report data.
	 */
	private static function render_foreign_warning( array $report ) {
		$foreign = array();

		foreach ( $report['groups'] as $group ) {
			$keep = PLP_Duplicates::suggest_keep( $group['items'] );

			foreach ( $group['items'] as $item ) {
				if ( (int) $item['id'] !== $keep && PLP_Post_Types::TRACK !== $item['type'] ) {
					$foreign[ $item['type'] ] = true;
				}
			}
		}

		if ( ! $foreign ) {
			return;
		}

		$names = array();

		foreach ( array_keys( $foreign ) as $type ) {
			$object  = get_post_type_object( $type );
			$names[] = $object ? $object->labels->name : $type;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Mielőtt tömegesen törölnél:', 'pl-player' ); ?></strong>
				<?php
				printf(
					/* translators: %s: list of post type names. */
					esc_html__( 'a törölhetőként megjelölt példányok között nem csak a bővítmény saját zeneszámai vannak, hanem meglévő tartalom is (%s). Ezeknek saját linkjük és RSS bejegyzésük van, amit a hallgatók már megkaphattak.', 'pl-player' ),
					esc_html( implode( ', ', $names ) )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Ha a duplikátumok többsége abból fakad, hogy ugyanaz a felvétel egyszer podcast epizódként, egyszer zeneszámként létezik, akkor a Beállításokban vedd ki a pipát az egyik tartalomtípusnál. A lejátszó onnantól csak az egyiket listázza, és semmit nem kell törölni.', 'pl-player' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The bulk action bar.
	 *
	 * Rendered above and below the tables, because the list can run long and nobody
	 * should have to scroll back to the top to act on what they just ticked.
	 */
	private static function render_actions() {
		?>
		<div class="plp-dupes__actions">
			<button type="submit" class="button button-primary" data-plp-dupes-submit>
				<?php esc_html_e( 'Kijelöltek lomtárba', 'pl-player' ); ?>
			</button>

			<button type="button" class="button" data-plp-dupes-pick="file">
				<?php esc_html_e( 'Csak a biztos egyezések kijelölése', 'pl-player' ); ?>
			</button>

			<button type="button" class="button" data-plp-dupes-pick="none">
				<?php esc_html_e( 'Kijelölés törlése', 'pl-player' ); ?>
			</button>

			<span class="plp-dupes__count" data-plp-dupes-count aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * Reports what the last bulk action actually did.
	 */
	private static function render_result_notice() {
		$trashed = isset( $_GET['plp_trashed'] ) ? absint( $_GET['plp_trashed'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $trashed < 0 ) {
			return;
		}

		$skipped = isset( $_GET['plp_skipped'] ) ? absint( $_GET['plp_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p>%3$s</div>',
			$trashed ? 'success' : 'warning',
			esc_html(
				sprintf(
					/* translators: %s: number of posts. */
					_n( '%s bejegyzés a lomtárba került.', '%s bejegyzés a lomtárba került.', $trashed, 'pl-player' ),
					number_format_i18n( $trashed )
				)
			),
			$skipped
				? '<p>' . esc_html(
					sprintf(
						/* translators: %s: number of posts. */
						__( '%s bejegyzést kihagytam: mire a művelet lefutott, már nem szerepeltek a jelentésben törölhetőként, vagy nincs rájuk törlési jogosultságod.', 'pl-player' ),
						number_format_i18n( $skipped )
					)
				) . '</p>'
				: ''
		);
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

		echo '<table class="wp-list-table widefat striped plp-dupes__table" data-plp-dupes-group="' . esc_attr( $group['kind'] ) . '"><thead><tr>';
		printf(
			'<td class="check-column"><input type="checkbox" data-plp-dupes-all aria-label="%s" /></td>',
			esc_attr__( 'A csoport összes törölhető példányának kijelölése', 'pl-player' )
		);
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

			// The kept copy gets no checkbox at all. Making it unpickable in the markup
			// is worth more than a validation message: a group can never be emptied by
			// a stray "select all".
			echo '<th scope="row" class="check-column">';

			if ( ! $is_keep && current_user_can( 'delete_post', $item['id'] ) ) {
				printf(
					'<input type="checkbox" name="plp_trash[]" value="%1$d" data-plp-dupes-item%2$s aria-label="%3$s" />',
					(int) $item['id'],
					PLP_Post_Types::TRACK === $item['type'] ? '' : ' data-plp-dupes-foreign="1"',
					esc_attr(
						sprintf(
							/* translators: %s: post title. */
							__( '„%s" lomtárba tétele', 'pl-player' ),
							$item['title']
						)
					)
				);
			}

			echo '</th>';

			printf(
				'<td><a href="%1$s"><strong>%2$s</strong></a>%3$s<br /><span class="plp-dupes__file">%4$s</span></td>',
				esc_url( (string) get_edit_post_link( $item['id'] ) ),
				esc_html( $item['title'] ),
				$is_keep ? ' <span class="plp-dupes__badge">' . esc_html__( 'ezt javaslom megtartani', 'pl-player' ) . '</span>' : '',
				esc_html( $item['file'] )
			);

			printf(
				'<td>%1$s%2$s</td>',
				esc_html( $type ? $type->labels->singular_name : $item['type'] ),
				// Worth flagging on the row itself: a podcast episode is content that
				// existed before the player and has its own permalink and RSS entry.
				// Trashing it is a bigger step than trashing a track the plugin made.
				PLP_Post_Types::TRACK === $item['type']
					? ''
					: '<br /><span class="plp-dupes__foreign">' . esc_html__( 'meglévő tartalom', 'pl-player' ) . '</span>'
			);
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
	 * Bulk trash
	 * ------------------------------------------------------------------ */

	/**
	 * Moves the selected surplus copies to the trash.
	 *
	 * The posted IDs are treated as a request, never as an instruction. The report is
	 * rebuilt here and only a post that it *currently* marks as a surplus copy may go:
	 * a form left open in a tab while the library changed, or a hand-edited request,
	 * must not be able to reach an arbitrary post. Trash rather than delete, so the way
	 * back is a single click in WordPress's own screen.
	 */
	public static function bulk_trash() {
		if ( ! current_user_can( 'delete_others_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod bejegyzések törléséhez.', 'pl-player' ) );
		}

		check_admin_referer( 'plp_trash_duplicates' );

		$requested = isset( $_POST['plp_trash'] ) ? array_map( 'absint', (array) $_POST['plp_trash'] ) : array();
		$requested = array_values( array_unique( array_filter( $requested ) ) );

		$trashed = 0;
		$skipped = 0;

		if ( $requested ) {
			$deletable = self::deletable_now();

			// Grouped, so the last survivor of a group can be protected even if the
			// request somehow asked for all of them.
			$by_group = array();

			foreach ( $requested as $id ) {
				if ( ! isset( $deletable[ $id ] ) || ! current_user_can( 'delete_post', $id ) ) {
					$skipped++;

					continue;
				}

				$by_group[ $deletable[ $id ]['group'] ][] = $id;
			}

			foreach ( $by_group as $group => $ids ) {
				$survivors = $deletable[ $ids[0] ]['size'] - count( $ids );

				// Cannot happen through the form — the kept copy has no checkbox — but a
				// group must never be wiped out entirely, so it is enforced here too.
				while ( $survivors < 1 && $ids ) {
					array_pop( $ids );
					$skipped++;
					$survivors++;
				}

				foreach ( $ids as $id ) {
					if ( wp_trash_post( $id ) ) {
						$trashed++;
					} else {
						$skipped++;
					}
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => PLP_Post_Types::TRACK,
					'page'         => self::SLUG,
					'plp_trashed'  => $trashed,
					'plp_skipped'  => $skipped,
				),
				admin_url( 'edit.php' )
			)
		);

		exit;
	}

	/**
	 * The surplus copies the report marks as removable right now.
	 *
	 * @return array<int, array{group:int, size:int}> Keyed by post ID.
	 */
	private static function deletable_now() {
		$deletable = array();

		foreach ( PLP_Duplicates::report()['groups'] as $index => $group ) {
			$keep = PLP_Duplicates::suggest_keep( $group['items'] );
			$size = count( $group['items'] );

			foreach ( $group['items'] as $item ) {
				if ( (int) $item['id'] === $keep ) {
					continue;
				}

				$deletable[ (int) $item['id'] ] = array(
					'group' => $index,
					'size'  => $size,
				);
			}
		}

		return $deletable;
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
