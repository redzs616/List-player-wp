<?php
/**
 * The batch analysis screen.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives the browser-side analyser over a queue of tracks.
 *
 * The work happens in the visitor's — here, the admin's — browser, one track at a
 * time, because that is where a working audio decoder lives. This screen just supplies
 * the queue and stores the answers.
 */
class PLP_Analyze_Page {

	const SLUG = 'plp-analyze';

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hooks the page and its endpoints.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_plp_analyze_queue', array( __CLASS__, 'ajax_queue' ) );
		add_action( 'wp_ajax_plp_analyze_save', array( __CLASS__, 'ajax_save' ) );
	}

	/**
	 * Adds the submenu entry.
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Elemzés és borítók', 'pl-player' ),
			__( 'Elemzés', 'pl-player' ),
			'upload_files',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads the analyser.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'plp-analyze', PLP_URL . 'admin/assets/css/analyze.css', array(), PLP_VERSION );
		wp_enqueue_script( 'plp-analyze', PLP_URL . 'admin/assets/js/analyze.js', array( 'jquery' ), PLP_VERSION, true );

		wp_localize_script(
			'plp-analyze',
			'PLPAnalyze',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'plp_analyze' ),
				'i18n'    => array(
					'loading'  => __( 'Sor betöltése…', 'pl-player' ),
					'working'  => __( 'Elemzés…', 'pl-player' ),
					'cover'    => __( 'Borító készítése…', 'pl-player' ),
					'saving'   => __( 'Mentés…', 'pl-player' ),
					'done'     => __( 'Kész', 'pl-player' ),
					'failed'   => __( 'Nem sikerült', 'pl-player' ),
					'noAudio'  => __( 'A hangot nem lehetett megnyitni', 'pl-player' ),
					'finished' => __( 'A köteg végére értünk.', 'pl-player' ),
					'stopped'  => __( 'Megállítva.', 'pl-player' ),
					'empty'    => __( 'Nincs elemzésre váró szám.', 'pl-player' ),
					/* translators: 1: done count, 2: total. */
					'progress' => __( '%1$d / %2$d', 'pl-player' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod az elemzéshez.', 'pl-player' ) );
		}
		?>
		<div class="wrap plp-analyze">
			<h1><?php esc_html_e( 'Elemzés és borítók', 'pl-player' ); ?></h1>

			<p class="plp-analyze__intro">
				<?php esc_html_e( 'A böngésző végigfut a számokon, és magából a hangból megmér három dolgot: a tempót, az energiát és a fényességet. Ebből lesznek a címkék a lejátszóban. Ha kéred, ugyanabból a mérésből borítót is készít azoknak, amiknek nincs.', 'pl-player' ); ?>
			</p>

			<div class="plp-panel">
				<p>
					<label>
						<input type="checkbox" id="plp-make-covers" checked />
						<?php esc_html_e( 'Borító készítése, ha nincs neki', 'pl-player' ); ?>
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" id="plp-redo" />
						<?php esc_html_e( 'A már megmért számokat is mérje újra', 'pl-player' ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e( 'Számonként 10–15 másodperc. Hagyd nyitva ezt a lapot, amíg fut — a mérés itt történik, nem a szerveren. Bezárás vagy a Megállítás után a már elkészült számok megmaradnak.', 'pl-player' ); ?>
				</p>
			</div>

			<p class="plp-analyze__actions">
				<button type="button" class="button button-primary button-large" id="plp-analyze-start">
					<?php esc_html_e( 'Elemzés indítása', 'pl-player' ); ?>
				</button>
				<button type="button" class="button" id="plp-analyze-stop" disabled>
					<?php esc_html_e( 'Megállítás', 'pl-player' ); ?>
				</button>
				<span class="spinner plp-analyze__spinner" id="plp-analyze-spinner"></span>
			</p>

			<div class="plp-progress" id="plp-analyze-progress" hidden>
				<div class="plp-progress__bar"><span id="plp-analyze-fill"></span></div>
				<p class="plp-progress__label" id="plp-analyze-label"></p>
			</div>

			<div class="notice notice-error plp-notice" id="plp-analyze-error" hidden><p></p></div>

			<table class="wp-list-table widefat striped plp-analyze-table" id="plp-analyze-table" hidden>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Szám', 'pl-player' ); ?></th>
						<th scope="col"><?php esc_html_e( 'BPM', 'pl-player' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Energia', 'pl-player' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Fényesség', 'pl-player' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Borító', 'pl-player' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Állapot', 'pl-player' ); ?></th>
					</tr>
				</thead>
				<tbody id="plp-analyze-rows"></tbody>
			</table>

			<div class="plp-panel plp-analyze__note">
				<p>
					<strong><?php esc_html_e( 'Amit ez nem tud:', 'pl-player' ); ?></strong>
					<?php esc_html_e( 'nem ismeri fel, mely számok szólnak egy mixben, és nem ad műfaj-címkét. A tracklistához hangfelismerő szolgáltatás kellene, a műfaj-osztályozás pedig egy DJ mixen annyit tévedne, hogy félrevezető lenne. Amit itt látsz, az mérés — nem tipp.', 'pl-player' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Refuses the request unless the caller may do this.
	 */
	private static function verify() {
		check_ajax_referer( 'plp_analyze', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nincs jogosultság.', 'pl-player' ) ), 403 );
		}
	}

	/**
	 * Returns the tracks waiting to be measured.
	 */
	public static function ajax_queue() {
		self::verify();

		$redo = isset( $_POST['redo'] ) && '1' === $_POST['redo'];

		$args = array(
			'post_type'           => PLP_Source::post_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => 400,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
		);

		if ( ! $redo ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'     => PLP_Analysis::META_ANALYZED,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );
		$queue = array();

		foreach ( $query->posts as $post ) {
			$url = PLP_Source::audio_url( $post->ID );

			// Nothing to measure without a file; these are already flagged in the track
			// list, so silently skipping them here is right.
			if ( '' === $url ) {
				continue;
			}

			$queue[] = array(
				'id'       => (int) $post->ID,
				'title'    => get_the_title( $post ),
				'audio'    => $url,
				'hue'      => PLP_Source::cover_hue( $post->ID ),
				'initial'  => PLP_Source::cover_initial( get_the_title( $post ) ),
				'hasCover' => (bool) get_post_thumbnail_id( $post->ID ),
				'duration' => absint( get_post_meta( $post->ID, '_pl_duration', true ) ),
			);
		}

		wp_send_json_success( array( 'queue' => $queue ) );
	}

	/**
	 * Stores one measurement, and the generated cover when one was sent.
	 */
	public static function ajax_save() {
		self::verify();

		$post_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Érvénytelen szám.', 'pl-player' ) ), 400 );
		}

		$stored = PLP_Analysis::save(
			$post_id,
			array(
				'bpm'    => isset( $_POST['bpm'] ) ? absint( $_POST['bpm'] ) : 0,
				'energy' => isset( $_POST['energy'] ) ? absint( $_POST['energy'] ) : 0,
				'bright' => isset( $_POST['bright'] ) ? absint( $_POST['bright'] ) : 0,
			)
		);

		$cover = null;

		if ( ! empty( $_POST['cover'] ) ) {
			// Not sanitised as text on purpose: this is base64 image data, and
			// sanitize_text_field would corrupt it. It is validated by pattern and by
			// length inside save_cover().
			$result = PLP_Analysis::save_cover( $post_id, wp_unslash( $_POST['cover'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( is_wp_error( $result ) ) {
				$cover = array( 'error' => $result->get_error_message() );
			} else {
				$cover = array(
					'id'  => $result,
					'url' => (string) wp_get_attachment_image_url( $result, 'thumbnail' ),
				);
			}
		}

		wp_send_json_success(
			array(
				'id'     => $post_id,
				'values' => $stored,
				'labels' => PLP_Analysis::labels( $post_id ),
				'cover'  => $cover,
			)
		);
	}
}
