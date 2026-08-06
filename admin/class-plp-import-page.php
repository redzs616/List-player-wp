<?php
/**
 * The bulk import screen.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the import page and serves its two AJAX endpoints.
 */
class PLP_Import_Page {

	const SLUG = 'plp-import';

	/**
	 * Hook suffix of the submenu page, used to scope asset loading.
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
		add_action( 'wp_ajax_plp_import_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_plp_import_track', array( __CLASS__, 'ajax_import_track' ) );
	}

	/**
	 * Adds the page under the Lejátszó menu.
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Tömeges import', 'pl-player' ),
			__( 'Tömeges import', 'pl-player' ),
			'upload_files',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads the media picker and the page assets.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'plp-import',
			PLP_URL . 'admin/assets/css/import.css',
			array(),
			PLP_VERSION
		);

		wp_enqueue_script(
			'plp-import',
			PLP_URL . 'admin/assets/js/import.js',
			array( 'jquery' ),
			PLP_VERSION,
			true
		);

		wp_localize_script(
			'plp-import',
			'PLPImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'plp_import' ),
				'maxScan' => PLP_Importer::MAX_SCAN,
				'i18n'    => array(
					'selectTitle'     => __( 'Hangfájlok kiválasztása', 'pl-player' ),
					'selectButton'    => __( 'Beolvasom ezeket', 'pl-player' ),
					'scanning'        => __( 'Fájlok beolvasása…', 'pl-player' ),
					'pending'         => __( 'Vár', 'pl-player' ),
					'working'         => __( 'Létrehozás…', 'pl-player' ),
					'created'         => __( 'Létrehozva', 'pl-player' ),
					'alreadyImported' => __( 'Már importálva', 'pl-player' ),
					'failed'          => __( 'Hiba', 'pl-player' ),
					'networkError'    => __( 'A kérés nem jutott el a szerverig.', 'pl-player' ),
					'noSelection'     => __( 'Jelöld ki, melyik számokat importáljuk.', 'pl-player' ),
					'noAudio'         => __( 'A kiválasztott fájlok között nem volt hangfájl.', 'pl-player' ),
					/* translators: %d: number of files. */
					'tooMany'         => __( 'Egyszerre legfeljebb %d fájl olvasható be. A többit egy második körben importáld.', 'pl-player' ),
					/* translators: 1: created count, 2: skipped count, 3: failed count. */
					'summary'         => __( '%1$d szám létrehozva, %2$d kihagyva, %3$d hibás.', 'pl-player' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod az importáláshoz.', 'pl-player' ) );
		}
		?>
		<div class="wrap plp-import">
			<h1><?php esc_html_e( 'Zeneszámok tömeges importálása', 'pl-player' ); ?></h1>

			<p class="plp-import__intro">
				<?php esc_html_e( 'Jelöld ki a hangfájlokat a Médiatárból — vagy tölts fel újakat ugyanabban az ablakban. A cím, előadó, album, év és hossz a fájlok ID3 adataiból töltődik ki, a beágyazott borítókép pedig borítóként kerül a számhoz.', 'pl-player' ); ?>
			</p>

			<p>
				<button type="button" class="button button-primary button-hero" id="plp-pick-files">
					<?php esc_html_e( 'Hangfájlok kiválasztása', 'pl-player' ); ?>
				</button>
				<span class="spinner plp-spinner" id="plp-scan-spinner"></span>
			</p>

			<div class="notice notice-error plp-notice" id="plp-error" hidden>
				<p></p>
			</div>

			<div id="plp-import-form" hidden>

				<div class="plp-panel">
					<h2 class="plp-panel__title"><?php esc_html_e( 'Beállítások az összes importált számhoz', 'pl-player' ); ?></h2>

					<div class="plp-panel__grid">
						<div>
							<h3><?php esc_html_e( 'Kategóriák', 'pl-player' ); ?></h3>
							<?php self::render_category_checklist(); ?>
						</div>

						<div>
							<h3><?php esc_html_e( 'Címkék', 'pl-player' ); ?></h3>
							<p>
								<input type="text" class="regular-text" id="plp-import-tags"
									placeholder="<?php esc_attr_e( 'nyugodt, instrumentális', 'pl-player' ); ?>" />
							</p>
							<p class="description"><?php esc_html_e( 'Vesszővel elválasztva. A nem létező címkék létrejönnek.', 'pl-player' ); ?></p>

							<h3><?php esc_html_e( 'Állapot', 'pl-player' ); ?></h3>
							<p>
								<select id="plp-import-status">
									<option value="publish"><?php esc_html_e( 'Közzétett', 'pl-player' ); ?></option>
									<option value="draft"><?php esc_html_e( 'Vázlat', 'pl-player' ); ?></option>
								</select>
							</p>
							<p class="description"><?php esc_html_e( 'Vázlatként importálva átnézheted a számokat, mielőtt megjelennek a lejátszóban.', 'pl-player' ); ?></p>
						</div>
					</div>
				</div>

				<h2><?php esc_html_e( 'Importálandó számok', 'pl-player' ); ?></h2>

				<table class="wp-list-table widefat striped plp-import-table">
					<thead>
						<tr>
							<td class="check-column">
								<input type="checkbox" id="plp-toggle-all" checked
									title="<?php esc_attr_e( 'Mindet kijelöl', 'pl-player' ); ?>" />
							</td>
							<th scope="col"><?php esc_html_e( 'Fájl', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Cím', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Előadó', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Album', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Év', 'pl-player' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Hossz', 'pl-player' ); ?></th>
							<th scope="col" title="<?php esc_attr_e( 'A feltöltéskor kinyert borítókép. Az FTP-vel felmásolt fájloknál importáláskor derül ki.', 'pl-player' ); ?>">
								<?php esc_html_e( 'Borító', 'pl-player' ); ?>
							</th>
							<th scope="col"><?php esc_html_e( 'Állapot', 'pl-player' ); ?></th>
						</tr>
					</thead>
					<tbody id="plp-import-rows"></tbody>
				</table>

				<div class="plp-progress" id="plp-progress" hidden>
					<div class="plp-progress__bar"><span id="plp-progress-fill"></span></div>
					<p class="plp-progress__label" id="plp-progress-label"></p>
				</div>

				<p class="plp-actions">
					<button type="button" class="button button-primary button-large" id="plp-start-import">
						<?php esc_html_e( 'Import indítása', 'pl-player' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PLP_Post_Types::TRACK ) ); ?>">
						<?php esc_html_e( 'Zeneszámok listája', 'pl-player' ); ?>
					</a>
				</p>

			</div>
		</div>
		<?php
	}

	/**
	 * Renders the category picker as an indented checklist.
	 */
	private static function render_category_checklist() {
		$terms = get_terms(
			array(
				'taxonomy'   => PLP_Post_Types::CATEGORY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) || ! $terms ) {
			printf(
				'<p class="description">%s <a href="%s">%s</a></p>',
				esc_html__( 'Még nincs kategória.', 'pl-player' ),
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . PLP_Post_Types::CATEGORY . '&post_type=' . PLP_Post_Types::TRACK ) ),
				esc_html__( 'Létrehozok egyet', 'pl-player' )
			);
			return;
		}

		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		echo '<div class="plp-checklist-wrap">';
		self::render_term_level( $by_parent, 0 );
		echo '</div>';
	}

	/**
	 * Recursively prints one level of the category tree.
	 *
	 * @param array $by_parent Terms grouped by parent ID.
	 * @param int   $parent    Parent term ID.
	 */
	private static function render_term_level( array $by_parent, $parent ) {
		if ( empty( $by_parent[ $parent ] ) ) {
			return;
		}

		echo '<ul class="plp-checklist">';

		foreach ( $by_parent[ $parent ] as $term ) {
			printf(
				'<li><label><input type="checkbox" class="plp-category" value="%1$d" /> %2$s</label>',
				(int) $term->term_id,
				esc_html( $term->name )
			);

			self::render_term_level( $by_parent, (int) $term->term_id );

			echo '</li>';
		}

		echo '</ul>';
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Stops the request unless the user may both upload and create posts.
	 */
	private static function verify_request() {
		check_ajax_referer( 'plp_import', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pl-player' ) ), 403 );
		}
	}

	/**
	 * Returns the parsed tags of the selected attachments.
	 */
	public static function ajax_scan() {
		self::verify_request();

		$raw = isset( $_POST['attachment_ids'] ) ? (array) wp_unslash( $_POST['attachment_ids'] ) : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );

		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => __( 'Nem választottál ki hangfájlt.', 'pl-player' ) ), 400 );
		}

		$truncated = count( $ids ) > PLP_Importer::MAX_SCAN;
		$ids       = array_slice( $ids, 0, PLP_Importer::MAX_SCAN );

		wp_send_json_success(
			array(
				'rows'      => PLP_Importer::scan( $ids ),
				'truncated' => $truncated,
			)
		);
	}

	/**
	 * Creates one track from one submitted row.
	 */
	public static function ajax_import_track() {
		self::verify_request();

		$row = array(
			'attachment_id' => isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0,
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'artist'        => isset( $_POST['artist'] ) ? sanitize_text_field( wp_unslash( $_POST['artist'] ) ) : '',
			'album'         => isset( $_POST['album'] ) ? sanitize_text_field( wp_unslash( $_POST['album'] ) ) : '',
			'year'          => isset( $_POST['year'] ) ? PLP_Meta::sanitize_year( wp_unslash( $_POST['year'] ) ) : '',
			'duration'      => isset( $_POST['duration'] ) ? absint( $_POST['duration'] ) : 0,
			'status'        => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'publish',
			'tags'          => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
			'categories'    => isset( $_POST['categories'] )
				? array_map( 'absint', (array) wp_unslash( $_POST['categories'] ) )
				: array(),
		);

		$result = PLP_Importer::create_track( $row );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		wp_send_json_success( $result );
	}
}
