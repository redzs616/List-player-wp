<?php
/**
 * Settings screen.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets the site owner choose which content the player covers and how strictly plays
 * are counted.
 */
class PLP_Settings_Page {

	const SLUG   = 'plp-settings';
	const GROUP  = 'plp_settings_group';
	const OPTION = 'plp_settings';

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
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Adds the submenu entry.
	 */
	public static function add_page() {
		self::$hook = (string) add_submenu_page(
			'edit.php?post_type=' . PLP_Post_Types::TRACK,
			__( 'Lejátszó beállításai', 'pl-player' ),
			__( 'Beállítások', 'pl-player' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers the option.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => plp_get_settings(),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	/**
	 * Validates the submitted settings and seeds counters for newly enabled types.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = plp_get_settings();
		$input   = is_array( $input ) ? $input : array();

		$post_types = array();
		foreach ( (array) ( isset( $input['post_types'] ) ? $input['post_types'] : array() ) as $post_type ) {
			$post_type = sanitize_key( $post_type );

			if ( $post_type && post_type_exists( $post_type ) ) {
				$post_types[] = $post_type;
			}
		}

		// The plugin's own track type is never optional.
		if ( ! in_array( PLP_Post_Types::TRACK, $post_types, true ) ) {
			$post_types[] = PLP_Post_Types::TRACK;
		}

		$post_types = array_values( array_unique( $post_types ) );

		$clean = array(
			'post_types'               => $post_types,
			'public_stats'             => empty( $input['public_stats'] ) ? 0 : 1,
			'public_listening'         => empty( $input['public_listening'] ) ? 0 : 1,
			'public_trend'             => empty( $input['public_trend'] ) ? 0 : 1,
			'guest_likes'              => empty( $input['guest_likes'] ) ? 0 : 1,
			'play_threshold_seconds'   => min( 120, max( 1, absint( isset( $input['play_threshold_seconds'] ) ? $input['play_threshold_seconds'] : 15 ) ) ),
			'play_threshold_percent'   => min( 100, max( 1, absint( isset( $input['play_threshold_percent'] ) ? $input['play_threshold_percent'] : 30 ) ) ),
			'play_cooldown_hours'      => min( 168, max( 1, absint( isset( $input['play_cooldown_hours'] ) ? $input['play_cooldown_hours'] : 6 ) ) ),
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
			'github_repo'              => self::sanitize_repo( isset( $input['github_repo'] ) ? $input['github_repo'] : '' ),
		);

		// A changed repository invalidates whatever release was cached for the old one.
		if ( $clean['github_repo'] !== ( isset( $current['github_repo'] ) ? $current['github_repo'] : '' ) ) {
			delete_site_transient( PLP_Updater::TRANSIENT );
			delete_site_transient( 'update_plugins' );
		}

		// Types that were just switched on need their counters before anything can be
		// sorted by play count.
		$newly_enabled = array_diff( $clean['post_types'], (array) $current['post_types'] );

		foreach ( $newly_enabled as $post_type ) {
			PLP_Stats::backfill_counters( $post_type );
		}

		return $clean;
	}

	/**
	 * Accepts a GitHub repository written as `owner/name`.
	 *
	 * A pasted repository URL is trimmed down rather than rejected — that is what most
	 * people have on their clipboard.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function sanitize_repo( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '#github\.com/([A-Za-z0-9._-]+/[A-Za-z0-9._-]+)#', $value, $matches ) ) {
			$value = $matches[1];
		}

		$value = preg_replace( '#\.git$#', '', $value );
		$value = trim( (string) $value, '/' );

		return preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $value ) ? $value : '';
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nincs jogosultságod a beállítások módosításához.', 'pl-player' ) );
		}

		$settings = plp_get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lejátszó beállításai', 'pl-player' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Milyen tartalmat játsszon a lejátszó?', 'pl-player' ); ?></h2>
				<p class="description" style="max-width:760px">
					<?php esc_html_e( 'Jelöld ki azokat a tartalomtípusokat, amiket a lejátszó listázhat. A hangfájlt a plugin magától megkeresi: hozzárendelt médiatár-fájlból, [audio] shortcode-ból, vagy podcast enclosure adatból. Nem kell semmit átköltöztetni.', 'pl-player' ); ?>
				</p>

				<?php self::render_post_types( $settings ); ?>

				<h2><?php esc_html_e( 'Statisztika és kedvelés', 'pl-player' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Nyilvános számok', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[public_stats]" value="1"
										<?php checked( 1, (int) $settings['public_stats'] ); ?> />
									<?php esc_html_e( 'A látogatók is látják a lejátszásszámot és a like-okat', 'pl-player' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Kikapcsolva csak az adminban látszanak a számok, a lejátszó akkor is számol.', 'pl-player' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hallgatási adatok', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[public_listening]" value="1"
										<?php checked( 1, (int) $settings['public_listening'] ); ?> />
									<?php esc_html_e( 'Összes hallgatott idő és hallgatási görbe a látogatóknak is', 'pl-player' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'A lejátszó jelenti, mennyit hallgattak egy számból, és a szám 20 szeletéből melyik ment le. Ebből lesz a visszatartási görbe: hol esnek ki a hallgatók. Csak összesített adat, látogatóhoz nem köthető.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Forgalmi trend', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[public_trend]" value="1"
										<?php checked( 1, (int) $settings['public_trend'] ); ?> />
									<?php esc_html_e( 'A napi lejátszás-grafikon a látogatóknak is látszik', 'pl-player' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Ez megmutatja a látogatóknak, mennyi forgalma van az oldalnak. Ha ezt nem szeretnéd kitenni, kapcsold ki — a top listák ettől függetlenül működnek.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Vendég kedvelés', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[guest_likes]" value="1"
										<?php checked( 1, (int) $settings['guest_likes'] ); ?> />
									<?php esc_html_e( 'Bejelentkezés nélkül is lehet kedvelni', 'pl-player' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'A vendégeket egy véletlen azonosítójú süti különbözteti meg egymástól. Személyes adatot nem tárol, és IP-cím sem kerül az adatbázisba.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Mikor számít egy lejátszás?', 'pl-player' ); ?></h2>
				<p class="description" style="max-width:760px">
					<?php esc_html_e( 'A Play gomb megnyomása önmagában nem számol. Enélkül egy F5-nyomkodással bárki felvihetné a saját számának a lejátszottságát.', 'pl-player' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="plp-threshold-seconds"><?php esc_html_e( 'Minimum hallgatás', 'pl-player' ); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" id="plp-threshold-seconds" min="1" max="120"
									name="<?php echo esc_attr( self::OPTION ); ?>[play_threshold_seconds]"
									value="<?php echo esc_attr( (string) $settings['play_threshold_seconds'] ); ?>" />
								<?php esc_html_e( 'másodperc', 'pl-player' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="plp-threshold-percent"><?php esc_html_e( 'vagy a hossz', 'pl-player' ); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" id="plp-threshold-percent" min="1" max="100"
									name="<?php echo esc_attr( self::OPTION ); ?>[play_threshold_percent]"
									value="<?php echo esc_attr( (string) $settings['play_threshold_percent'] ); ?>" />
								<?php esc_html_e( 'százaléka — amelyik hamarabb teljesül', 'pl-player' ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="plp-cooldown"><?php esc_html_e( 'Újraszámolás', 'pl-player' ); ?></label>
							</th>
							<td>
								<input type="number" class="small-text" id="plp-cooldown" min="1" max="168"
									name="<?php echo esc_attr( self::OPTION ); ?>[play_cooldown_hours]"
									value="<?php echo esc_attr( (string) $settings['play_cooldown_hours'] ); ?>" />
								<?php esc_html_e( 'óra múlva számol újra ugyanattól a látogatótól', 'pl-player' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Automatikus frissítés', 'pl-player' ); ?></h2>
				<?php self::render_updates( $settings ); ?>

				<h2><?php esc_html_e( 'Adatkezelés', 'pl-player' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Törléskor', 'pl-player' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[delete_data_on_uninstall]" value="1"
										<?php checked( 1, (int) $settings['delete_data_on_uninstall'] ); ?> />
									<?php esc_html_e( 'A bővítmény törlésekor a zeneszámok és a statisztika is törlődjön', 'pl-player' ); ?>
								</label>
								<p class="description">
									<strong><?php esc_html_e( 'Alapértelmezésben kikapcsolva.', 'pl-player' ); ?></strong>
									<?php esc_html_e( 'Bekapcsolva egy véletlen bővítmény-törlés a teljes zenetárat és a lejátszástörténetet is elviszi.', 'pl-player' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the update settings and the current state of the release check.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_updates( array $settings ) {
		$repo    = isset( $settings['github_repo'] ) ? (string) $settings['github_repo'] : '';
		$release = $repo ? PLP_Updater::release() : array();

		$check_url = wp_nonce_url(
			add_query_arg(
				array(
					'post_type'        => PLP_Post_Types::TRACK,
					'page'             => self::SLUG,
					'plp_check_update' => '1',
				),
				admin_url( 'edit.php' )
			),
			'plp_check_update'
		);
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Jelenlegi verzió', 'pl-player' ); ?></th>
					<td><code><?php echo esc_html( PLP_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">
						<label for="plp-github-repo"><?php esc_html_e( 'GitHub tároló', 'pl-player' ); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text code" id="plp-github-repo"
							name="<?php echo esc_attr( self::OPTION ); ?>[github_repo]"
							value="<?php echo esc_attr( $repo ); ?>"
							placeholder="felhasznalonev/pl-player" />
						<p class="description">
							<?php esc_html_e( 'Írd be „felhasználónév/tároló" formában, vagy másold be a tároló teljes linkjét. Ha ki van töltve, a WordPress a Bővítmények listán jelzi, ha új kiadás jelent meg.', 'pl-player' ); ?>
						</p>
					</td>
				</tr>

				<?php if ( $repo ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Legutóbbi kiadás', 'pl-player' ); ?></th>
						<td>
							<?php if ( ! empty( $release['version'] ) ) : ?>
								<code><?php echo esc_html( $release['version'] ); ?></code>
								<?php if ( version_compare( $release['version'], PLP_VERSION, '>' ) ) : ?>
									<strong style="color:#996800">
										— <?php esc_html_e( 'frissítés elérhető', 'pl-player' ); ?>
									</strong>
								<?php else : ?>
									— <?php esc_html_e( 'naprakész', 'pl-player' ); ?>
								<?php endif; ?>
							<?php else : ?>
								<span style="color:#b32d2e">
									<?php esc_html_e( 'Nem sikerült lekérdezni. Ellenőrizd a tároló nevét, és hogy van-e benne közzétett kiadás (release).', 'pl-player' ); ?>
								</span>
							<?php endif; ?>

							<p>
								<?php
								$has_update = ! empty( $release['version'] )
									&& version_compare( $release['version'], PLP_VERSION, '>' );

								if ( $has_update && current_user_can( 'update_plugins' ) ) {
									// Core's own upgrade route, so the update runs through the
									// normal WordPress machinery with its rollback and checks.
									$plugin_file = plugin_basename( PLP_FILE );
									$update_url  = wp_nonce_url(
										self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $plugin_file ) ),
										'upgrade-plugin_' . $plugin_file
									);

									printf(
										'<a class="button button-primary" href="%1$s">%2$s</a> ',
										esc_url( $update_url ),
										esc_html(
											sprintf(
												/* translators: %s: new version number. */
												__( 'Frissítés most a %s verzióra', 'pl-player' ),
												$release['version']
											)
										)
									);
								}
								?>

								<a class="button" href="<?php echo esc_url( $check_url ); ?>">
									<?php esc_html_e( 'Keresés most', 'pl-player' ); ?>
								</a>
							</p>

							<p class="description">
								<?php esc_html_e( 'A WordPress 6 óránként magától ellenőriz. A „Keresés most" csak ellenőriz, nem telepít. Ha azt szeretnéd, hogy magától fel is tegye, a Bővítmények listán kapcsold be nála az automatikus frissítést.', 'pl-player' ); ?>
							</p>
						</td>
					</tr>
				<?php endif; ?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Privát tároló', 'pl-player' ); ?></th>
					<td>
						<p class="description" style="max-width:760px">
							<?php esc_html_e( 'Publikus tárolóhoz nem kell semmi más. Privát tárolónál egy hozzáférési tokent a wp-config.php-ba kell felvenni:', 'pl-player' ); ?>
						</p>
						<p><code>define( 'PLP_GITHUB_TOKEN', 'ghp_...' );</code></p>
						<p class="description" style="max-width:760px">
							<?php esc_html_e( 'A token szándékosan nem ezen a felületen állítható: az adatbázisban tárolt token egy adatbázis-szivárgással együtt kerülne illetéktelen kézbe.', 'pl-player' ); ?>
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the post type checkboxes with a hint about each one's audio source.
	 *
	 * @param array $settings Current settings.
	 */
	private static function render_post_types( array $settings ) {
		$enabled = (array) $settings['post_types'];
		$types   = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'objects'
		);

		echo '<table class="widefat striped" style="max-width:900px;margin-bottom:20px">';
		echo '<thead><tr>';
		printf( '<th style="width:40px"></th><th>%s</th>', esc_html__( 'Tartalomtípus', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Azonosító', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Talált hangfájl', 'pl-player' ) );
		echo '</tr></thead><tbody>';

		foreach ( $types as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$is_track = PLP_Post_Types::TRACK === $type->name;
			$found    = self::count_playable( $type->name );

			echo '<tr>';
			printf(
				'<td><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s %4$s /></td>',
				esc_attr( self::OPTION ),
				esc_attr( $type->name ),
				checked( true, $is_track || in_array( $type->name, $enabled, true ), false ),
				$is_track ? 'disabled' : ''
			);

			printf( '<td><strong>%s</strong>', esc_html( $type->labels->name ) );
			if ( $is_track ) {
				printf(
					' <em>(%s)</em><input type="hidden" name="%s[post_types][]" value="%s" />',
					esc_html__( 'mindig bekapcsolva', 'pl-player' ),
					esc_attr( self::OPTION ),
					esc_attr( $type->name )
				);
			}
			echo '</td>';

			printf( '<td><code>%s</code></td>', esc_html( $type->name ) );

			if ( $found['total'] ) {
				printf(
					'<td>%s</td>',
					esc_html(
						sprintf(
							/* translators: 1: posts with audio, 2: all posts. */
							__( '%1$d / %2$d bejegyzésben', 'pl-player' ),
							$found['playable'],
							$found['total']
						)
					)
				);
			} else {
				printf( '<td><span style="color:#a7aaad">%s</span></td>', esc_html__( 'nincs bejegyzés', 'pl-player' ) );
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Samples a post type to show how many of its posts actually have audio.
	 *
	 * Only the newest few are inspected — this is a hint for the admin, not a report,
	 * and resolving every post on a large site would make the page crawl.
	 *
	 * @param string $post_type Post type.
	 * @return array{total:int,playable:int}
	 */
	private static function count_playable( $post_type ) {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 10,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
			)
		);

		$playable = 0;

		foreach ( $query->posts as $post_id ) {
			if ( '' !== PLP_Source::audio_url( $post_id ) ) {
				$playable++;
			}
		}

		return array(
			'total'    => (int) $query->found_posts,
			'playable' => $playable,
		);
	}
}
