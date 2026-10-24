<?php
/**
 * The screen that publishes the handbook on the site.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the handbook page and links it into the chosen menus.
 */
class PLP_Manual_Page {

	const SLUG = 'plp-manual';

	/**
	 * Hooks the page and its action.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_plp_create_manual', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Adds the submenu entry.
	 */
	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Kézikönyv', 'pl-player' ),
			__( 'Kézikönyv', 'pl-player' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez az oldalhoz.', 'pl-player' ) );
		}

		$page_id = PLP_Manual::page_id();
		$menus   = wp_get_nav_menus();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kézikönyv', 'pl-player' ); ?></h1>

			<?php self::notice(); ?>

			<p style="max-width:720px">
				<?php esc_html_e( 'A kézikönyv egy oldalként tehető közzé az oldaladon: minden shortcode, paraméter és beállítás egy helyen, mindig a telepített verzióhoz igazodva. A tartalmat a bővítmény adja, tehát nem avul el a frissítésekkel.', 'pl-player' ); ?>
			</p>

			<?php if ( $page_id ) : ?>

				<div class="notice notice-success inline" style="max-width:720px">
					<p>
						<strong><?php esc_html_e( 'Az oldal létezik.', 'pl-player' ); ?></strong><br />
						<a href="<?php echo esc_url( (string) get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( (string) get_permalink( $page_id ) ); ?>
						</a>
						&nbsp;·&nbsp;
						<a href="<?php echo esc_url( (string) get_edit_post_link( $page_id ) ); ?>">
							<?php esc_html_e( 'Szerkesztés', 'pl-player' ); ?>
						</a>
						&nbsp;·&nbsp;
						<?php
						$status = get_post_status( $page_id );
						echo esc_html(
							'private' === $status
								? __( 'Állapot: privát — csak bejelentkezve látható.', 'pl-player' )
								: __( 'Állapot: közzétéve — bárki látja.', 'pl-player' )
						);
						?>
					</p>
				</div>

			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="plp_create_manual" />
				<?php wp_nonce_field( 'plp_create_manual' ); ?>

				<?php if ( ! $page_id ) : ?>
					<h2><?php esc_html_e( 'Az oldal létrehozása', 'pl-player' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Láthatóság', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="radio" name="plp_status" value="private" checked="checked" />
									<?php esc_html_e( 'Privát — csak bejelentkezve látható', 'pl-player' ); ?>
								</label><br />
								<label>
									<input type="radio" name="plp_status" value="publish" />
									<?php esc_html_e( 'Közzétéve — bárki látja', 'pl-player' ); ?>
								</label>
								<p class="description" style="max-width:620px">
									<?php esc_html_e( 'A privát oldal a menüben is csak annak jelenik meg, aki jogosult elolvasni — a látogatók nem látják sem a menüpontot, sem az oldalt. Ez a kézikönyvnél általában a helyes választás.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Hozzáadás a menükhöz', 'pl-player' ); ?></h2>

				<?php if ( ! $menus ) : ?>
					<p class="description">
						<?php esc_html_e( 'Ezen az oldalon még nincs menü. Megjelenés → Menük alatt tudsz létrehozni egyet.', 'pl-player' ); ?>
					</p>
				<?php else : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Menük', 'pl-player' ); ?></th>
							<td>
								<?php foreach ( $menus as $menu ) : ?>
									<label style="display:block;margin-bottom:6px">
										<input type="checkbox" name="plp_menus[]" value="<?php echo esc_attr( (string) $menu->term_id ); ?>" />
										<?php echo esc_html( $menu->name ); ?>
										<span class="description">
											<?php
											printf(
												/* translators: %d: number of menu items. */
												esc_html__( '(%d elem)', 'pl-player' ),
												(int) $menu->count
											);
											?>
										</span>
									</label>
								<?php endforeach; ?>
								<p class="description" style="max-width:620px">
									<?php esc_html_e( 'A menüpont a lista végére kerül, „Kézikönyv" néven. Ha már benne van, nem kerül bele még egyszer. A sorrenden a Megjelenés → Menük alatt tudsz igazítani.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
					</table>
				<?php endif; ?>

				<?php
				submit_button(
					$page_id
						? __( 'Hozzáadás a kijelölt menükhöz', 'pl-player' )
						: __( 'Kézikönyv oldal létrehozása', 'pl-player' )
				);
				?>
			</form>

			<h2><?php esc_html_e( 'Kézzel is beilleszthető', 'pl-player' ); ?></h2>
			<p style="max-width:720px">
				<?php esc_html_e( 'Ha inkább magad készíted el az oldalt, csak írd be ezt bárhová — oldalba, bejegyzésbe, Divi szöveg- vagy kódmodulba:', 'pl-player' ); ?>
			</p>
			<p>
				<input type="text" class="large-text code" readonly="readonly"
					value="[<?php echo esc_attr( PLP_Manual::TAG ); ?>]"
					onclick="this.select()" style="max-width:320px" />
			</p>
		</div>
		<?php
	}

	/**
	 * Shows what the last run did.
	 */
	private static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$created = isset( $_GET['plp_created'] ) ? absint( $_GET['plp_created'] ) : 0;
		$linked  = isset( $_GET['plp_linked'] ) ? absint( $_GET['plp_linked'] ) : -1;
		$failed  = isset( $_GET['plp_failed'] ) ? sanitize_text_field( wp_unslash( $_GET['plp_failed'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $failed ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message. */
						__( 'Az oldal létrehozása nem sikerült: %s', 'pl-player' ),
						$failed
					)
				)
			);

			return;
		}

		if ( $created ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'A kézikönyv oldal elkészült.', 'pl-player' )
			);
		}

		if ( $linked >= 0 ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					$linked
						? sprintf(
							/* translators: %d: number of menus. */
							_n( '%d menübe került be.', '%d menübe került be.', $linked, 'pl-player' ),
							$linked
						)
						: __( 'Egyik kijelölt menü sem változott — vagy már benne volt, vagy nem jelöltél ki menüt.', 'pl-player' )
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Action
	 * ------------------------------------------------------------------ */

	/**
	 * Creates the page, then links it where asked.
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod ehhez a művelethez.', 'pl-player' ) );
		}

		check_admin_referer( 'plp_create_manual' );

		$existing = PLP_Manual::page_id();
		$status   = isset( $_POST['plp_status'] ) ? sanitize_key( wp_unslash( $_POST['plp_status'] ) ) : 'private';
		$page_id  = $existing ? $existing : PLP_Manual::create_page( $status );

		if ( is_wp_error( $page_id ) ) {
			self::back( array( 'plp_failed' => $page_id->get_error_message() ) );
		}

		$linked = 0;
		$menus  = isset( $_POST['plp_menus'] ) ? array_map( 'absint', (array) $_POST['plp_menus'] ) : array();

		foreach ( $menus as $menu_id ) {
			if ( PLP_Manual::add_to_menu( $menu_id, $page_id ) ) {
				$linked++;
			}
		}

		self::back(
			array(
				'plp_created' => $existing ? 0 : 1,
				'plp_linked'  => $linked,
			)
		);
	}

	/**
	 * Returns to the screen with a result.
	 *
	 * @param array $args Query arguments.
	 */
	private static function back( array $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'post_type' => PLP_Post_Types::TRACK,
						'page'      => self::SLUG,
					),
					$args
				),
				admin_url( 'edit.php' )
			)
		);

		exit;
	}
}
