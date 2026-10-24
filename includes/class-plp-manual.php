<?php
/**
 * The handbook, as a page you can publish on the site.
 *
 * The same reference that lives in README.md, rendered where the person configuring
 * the player actually is. Keeping it as a shortcode rather than a static HTML file
 * means the shortcode list cannot drift from the plugin that ships it.
 *
 * @package PL_Player
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the handbook and can create the page that holds it.
 */
class PLP_Manual {

	const TAG    = 'playlist_manual';
	const OPTION = 'plp_manual_page';

	/**
	 * Hooks the shortcode and its stylesheet.
	 */
	public static function init() {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registers the handbook stylesheet without loading it.
	 */
	public static function register_assets() {
		wp_register_style(
			'plp-manual',
			PLP_URL . 'public/css/manual.css',
			array(),
			PLP_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * The page
	 * ------------------------------------------------------------------ */

	/**
	 * The ID of the handbook page, if one has been created.
	 *
	 * @return int
	 */
	public static function page_id() {
		$id   = absint( get_option( self::OPTION, 0 ) );
		$post = $id ? get_post( $id ) : null;

		// A page that was deleted by hand should not leave the option pointing at a
		// hole — the admin screen offers to create it again instead.
		if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * Creates the handbook page if it does not exist yet.
	 *
	 * Private by default: the handbook is for whoever runs the site, not for its
	 * visitors, and WordPress already knows how to keep a private page out of public
	 * listings while still showing it in menus to those who may read it.
	 *
	 * @param string $status Post status, `private` or `publish`.
	 * @return int|WP_Error Page ID, or an error.
	 */
	public static function create_page( $status = 'private' ) {
		$existing = self::page_id();

		if ( $existing ) {
			return $existing;
		}

		$status = in_array( $status, array( 'private', 'publish' ), true ) ? $status : 'private';

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => $status,
				'post_title'   => __( 'Kézikönyv', 'pl-player' ),
				'post_name'    => 'kezikonyv',
				'post_content' => '[' . self::TAG . ']',
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_option( self::OPTION, (int) $id, false );

		return (int) $id;
	}

	/**
	 * Adds the handbook page to a navigation menu, unless it is already there.
	 *
	 * @param int $menu_id Menu term ID.
	 * @param int $page_id Page ID.
	 * @return bool Whether something was added.
	 */
	public static function add_to_menu( $menu_id, $page_id ) {
		$menu_id = absint( $menu_id );
		$page_id = absint( $page_id );

		if ( ! $menu_id || ! $page_id ) {
			return false;
		}

		foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $item ) {
			// Already linked — adding a second copy would be the more annoying bug.
			if ( 'post_type' === $item->type && (int) $item->object_id === $page_id ) {
				return false;
			}
		}

		$added = wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-object-id' => $page_id,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => __( 'Kézikönyv', 'pl-player' ),
				'menu-item-status'    => 'publish',
			)
		);

		return ! is_wp_error( $added ) && $added;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Renders the handbook.
	 *
	 * @return string
	 */
	public static function render() {
		wp_enqueue_style( 'plp-manual' );

		ob_start();
		?>
		<div class="plpm">
			<?php
			self::intro();
			self::shortcode_table();
			self::examples();
			self::player_params();
			self::playlists();
			self::stats_section();
			self::popup_section();
			self::markers_section();
			self::sharing_section();
			self::equalizer_section();
			self::duplicates_section();
			self::updates_section();
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------
	 * Small helpers, so each section reads as content rather than markup.
	 * --------------------------------------------------------------- */

	/**
	 * Section heading with an anchor.
	 *
	 * @param string $id    Anchor.
	 * @param string $title Heading.
	 */
	private static function heading( $id, $title ) {
		printf(
			'<h2 class="plpm__h2" id="%1$s">%2$s</h2>',
			esc_attr( $id ),
			esc_html( $title )
		);
	}

	/**
	 * A block of shortcode examples.
	 *
	 * @param array $lines Lines; a line starting with // is a comment, '' is a blank.
	 */
	private static function code( array $lines ) {
		echo '<pre class="plpm__code"><code>';

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				echo "\n";
				continue;
			}

			if ( 0 === strpos( $line, '//' ) ) {
				printf( '<span class="plpm__comment">%s</span>' . "\n", esc_html( $line ) );
				continue;
			}

			echo self::colour_shortcode( $line ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '</code></pre>';
	}

	/**
	 * Highlights a shortcode line: tag, attribute names, quoted values.
	 *
	 * Escaping happens first and the markup is added afterwards, so nothing from the
	 * input can become a tag.
	 *
	 * @param string $line Raw line.
	 * @return string
	 */
	private static function colour_shortcode( $line ) {
		$safe = esc_html( $line );

		$safe = (string) preg_replace(
			'/(\[)([a-z_]+)/',
			'$1<span class="plpm__tag">$2</span>',
			$safe
		);

		$safe = (string) preg_replace(
			'/([a-z_]+)(=)(&quot;[^&]*?&quot;)/',
			'<span class="plpm__attr">$1</span>$2<span class="plpm__value">$3</span>',
			$safe
		);

		return $safe;
	}

	/**
	 * A callout box.
	 *
	 * @param string $label Small heading.
	 * @param string $body  Paragraph. Already-escaped markup is not expected.
	 */
	private static function note( $label, $body ) {
		printf(
			'<div class="plpm__note"><span class="plpm__note-label">%1$s</span><p>%2$s</p></div>',
			esc_html( $label ),
			esc_html( $body )
		);
	}

	/**
	 * A parameter table.
	 *
	 * @param array $rows Each row: [name, default, description].
	 */
	private static function params( array $rows ) {
		echo '<div class="plpm__scroll"><table class="plpm__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Paraméter', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Alap', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Mit tesz', 'pl-player' ) );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><code class="plpm__attr">%1$s</code></td><td><code class="plpm__muted">%2$s</code></td><td>%3$s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $row[1] ),
				esc_html( $row[2] )
			);
		}

		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------
	 * Sections
	 * --------------------------------------------------------------- */

	private static function intro() {
		?>
		<header class="plpm__head">
			<p class="plpm__eyebrow"><?php esc_html_e( 'WordPress bővítmény', 'pl-player' ); ?></p>
			<h1 class="plpm__h1"><?php esc_html_e( 'Lejátszási Lista Player — kézikönyv', 'pl-player' ); ?></h1>
			<p class="plpm__lead">
				<?php
				printf(
					/* translators: %s: plugin version. */
					esc_html__( 'Minden shortcode, paraméter és beállítás egy helyen. Telepített verzió: %s.', 'pl-player' ),
					esc_html( PLP_VERSION )
				);
				?>
			</p>
		</header>
		<?php
	}

	private static function shortcode_table() {
		self::heading( 'shortcodeok', __( 'A három shortcode', 'pl-player' ) );

		echo '<div class="plpm__scroll"><table class="plpm__table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Shortcode', 'pl-player' ) );
		printf( '<th>%s</th>', esc_html__( 'Mit tesz', 'pl-player' ) );
		echo '</tr></thead><tbody>';

		$rows = array(
			array( '[playlist_player]', __( 'A lejátszó: listázza és lejátssza a zenét. Ez a fő eszköz.', 'pl-player' ) ),
			array( '[playlist_index]', __( 'A lejátszási listák oldala: felsorolja őket, és rákattintva megnyitja.', 'pl-player' ) ),
			array( '[playlist_stats]', __( 'Nyilvános toplisták és forgalmi grafikon, önálló statisztika oldalhoz.', 'pl-player' ) ),
		);

		foreach ( $rows as $row ) {
			printf(
				'<tr><td><code class="plpm__tag">%1$s</code></td><td>%2$s</td></tr>',
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}

		echo '</tbody></table></div>';

		self::note(
			__( 'HA NYERS SZÖVEGKÉNT JELENIK MEG', 'pl-player' ),
			__( 'Akkor a bővítmény nincs aktiválva, vagy egy régebbi verzió van fent, amiben az a shortcode még nem létezett. A Bővítmények listán a verziószám megmondja.', 'pl-player' )
		);
	}

	private static function examples() {
		self::heading( 'peldak', __( 'Kész példák, másolásra', 'pl-player' ) );

		self::code(
			array(
				'// Kiemelt panel, arany kiemeléssel, ambiens ekvalizérrel',
				'[playlist_player layout="hero" accent="#f0a12e" nav_limit="10" equalizer="always"]',
				'',
				'// Egy műfaj kártyás rácsban, navigáció nélkül',
				'[playlist_player layout="grid" columns="4" category="deep-house" nav="no"]',
				'',
				'// A tíz legtöbbet hallgatott, tömör listában',
				'[playlist_player orderby="plays" limit="10" nav="no" search="no"]',
				'',
				'// Egy kézzel összeválogatott lista a saját sorrendjében',
				'[playlist_player playlist="nyari-mix" layout="hero"]',
				'',
				'// Csak a podcast epizódok',
				'[playlist_player post_type="podcast" layout="hero"]',
				'',
				'// Válogatott lejátszási listák, a megadott sorrendben',
				'[playlist_index lists="nyari-mix,retro-szett,chill"]',
				'',
				'// Toplista oldal grafikonnal',
				'[playlist_stats limit="10" show="both" trend="yes" days="30" accent="#f0a12e"]',
				'',
				'// Csak a legkedveltebbek, grafikon nélkül',
				'[playlist_stats show="likes" limit="20" trend="no"]',
			)
		);
	}

	private static function player_params() {
		self::heading( 'parameterek', __( 'A lejátszó paraméterei', 'pl-player' ) );

		self::params(
			array(
				array( 'layout', 'list', __( 'hero — kiemelt panel és sorszámozott lista. list — tömör lista. grid — kártyás rács.', 'pl-player' ) ),
				array( 'playlist', '—', __( 'Egy saját lejátszási lista slugja vagy azonosítója. A lista sorrendje érvényesül.', 'pl-player' ) ),
				array( 'category', '—', __( 'Kategória slug vagy azonosító, vesszővel több is.', 'pl-player' ) ),
				array( 'post_type', '—', __( 'Csak egy tartalomtípusra szűkít, például podcast.', 'pl-player' ) ),
				array( 'limit', '20', __( 'Számok oldalanként.', 'pl-player' ) ),
				array( 'orderby', 'date', __( 'date, plays, likes, title, random, menu_order.', 'pl-player' ) ),
				array( 'order', 'desc', __( 'asc vagy desc.', 'pl-player' ) ),
				array( 'columns', '3', __( 'Rács oszlopszám, 1 és 6 között.', 'pl-player' ) ),
				array( 'nav', 'yes', __( 'Kategória-navigáció. Lista módban a lista neve áll a helyén.', 'pl-player' ) ),
				array( 'nav_limit', '12', __( 'Ennyi kategória látszik, a többi a „További N kategória" mögé kerül. 0 = mind.', 'pl-player' ) ),
				array( 'search', 'yes', __( 'Keresőmező.', 'pl-player' ) ),
				array( 'sort', 'yes', __( 'Rendezés választó. Lista módban magától eltűnik.', 'pl-player' ) ),
				array( 'popup', 'yes', __( 'A „Külön ablakban" gomb.', 'pl-player' ) ),
				array( 'equalizer', 'yes', __( 'yes — ambiens vizualizáció a képernyő alján. always — csökkentett mozgás mellett is. hero — a régi, panelen belüli változat. no — soha.', 'pl-player' ) ),
				array( 'theme', 'auto', __( 'auto, dark vagy light.', 'pl-player' ) ),
				array( 'accent', '—', __( 'Kiemelő szín hexa kóddal, például #f0a12e.', 'pl-player' ) ),
			)
		);
	}

	private static function playlists() {
		self::heading( 'listak', __( 'Saját lejátszási listák', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php
			echo wp_kses_post(
				__( 'A kategória azt mondja meg, <strong>mi</strong> egy szám. A lejátszási lista azt, hogy <strong>mi követ mit</strong>. Ez két különböző dolog — ezért külön menüpont, nem újabb kategória.', 'pl-player' )
			);
			?>
		</p>

		<ol class="plpm__steps">
			<li><?php echo wp_kses_post( __( '<strong>Lejátszó → Lejátszási listák → Új lista.</strong> Adj neki nevet, és ha akarsz, borítót.', 'pl-player' ) ); ?></li>
			<li><?php echo wp_kses_post( __( '<strong>Jobb oldalt keresel</strong> cím vagy előadó szerint. A találatra kattintva bekerül a listába.', 'pl-player' ) ); ?></li>
			<li><?php echo wp_kses_post( __( '<strong>Bal oldalt a sorrend.</strong> A sorokat a pontozott fogantyúnál fogva húzhatod a helyükre — ebben a sorrendben fognak szólni.', 'pl-player' ) ); ?></li>
			<li><?php echo wp_kses_post( __( '<strong>Közzététel.</strong> A listák táblázatában megjelenik a kész shortcode.', 'pl-player' ) ); ?></li>
		</ol>

		<?php
		self::code( array( '[playlist_player playlist="nyari-mix" layout="hero"]' ) );

		self::heading( 'listak-oldala', __( 'A listák oldala', 'pl-player' ) );

		self::code(
			array(
				'// Minden lista, ábécé sorrendben',
				'[playlist_index]',
				'',
				'// Csak ez a három, pontosan ebben a sorrendben',
				'[playlist_index lists="nyari-mix,retro-szett,chill"]',
				'',
				'// Minden lista a legújabbtól, egy kivételével',
				'[playlist_index orderby="date" exclude="teszt-lista"]',
			)
		);

		self::params(
			array(
				array( 'lists', '—', __( 'Csak ezek a listák, slug vagy azonosító szerint. A megadott sorrend érvényesül.', 'pl-player' ) ),
				array( 'exclude', '—', __( 'Ezeket hagyja ki.', 'pl-player' ) ),
				array( 'orderby', 'title', __( 'title — ábécé. date — legújabb elöl. tracks — legtöbb számot tartalmazó elöl.', 'pl-player' ) ),
				array( 'columns', '3', __( 'Oszlopszám a listák rácsában.', 'pl-player' ) ),
				array( 'layout', 'hero', __( 'Milyen elrendezésben nyíljon meg a választott lista.', 'pl-player' ) ),
			)
		);

		self::note(
			__( 'HA EGY SZÁM KIESIK', 'pl-player' ),
			__( 'Ha egy lista tagja közben törlődik vagy elveszíti a hangfájlját, a szerkesztőben pirossal jelenik meg — nem csendben tűnik el. Így látod, hol van lyuk a listában.', 'pl-player' )
		);
	}

	private static function stats_section() {
		self::heading( 'statisztika', __( 'A statisztika shortcode', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'Ez külön oldalra való — egy „Legnépszerűbb mixek" vagy „Toplista" oldalhoz. Nem játszik zenét, csak listákat és grafikont mutat.', 'pl-player' ); ?>
		</p>
		<?php

		self::code( array( '[playlist_stats limit="10" show="both" trend="yes" days="30"]' ) );

		self::params(
			array(
				array( 'limit', '10', __( 'Hány szám szerepeljen egy listában.', 'pl-player' ) ),
				array( 'show', 'both', __( 'plays — csak a hallgatottsági lista. likes — csak a kedveltek. both — mindkettő.', 'pl-player' ) ),
				array( 'trend', 'yes', __( 'Forgalmi grafikon a lista fölött.', 'pl-player' ) ),
				array( 'days', '30', __( 'A grafikon időszaka, 7 és 90 nap között.', 'pl-player' ) ),
				array( 'accent', '—', __( 'Kiemelő szín.', 'pl-player' ) ),
			)
		);

		self::note(
			__( 'EGY TUDATOS CSERE', 'pl-player' ),
			__( 'Ez a blokk a szerveren renderelődik, nem külön kéréssel. Page cache mellett tehát néhány óráig állhat elavult adaton. Egy statisztika oldalnál ez jó csere azért, hogy ne minden látogató indítson külön kérést.', 'pl-player' )
		);
	}

	private static function popup_section() {
		self::heading( 'popup', __( 'Popup lejátszó', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'Egy sima oldalváltás megszünteti a JavaScript környezetet, és vele az audio elemet is. Ez a böngésző működése, nem lehet megkerülni az oldalon belül. Ezért van a lejátszóban egy „Külön ablakban" gomb.', 'pl-player' ); ?>
		</p>
		<ul class="plpm__list">
			<li><?php echo wp_kses_post( __( 'Megnyílik egy kis ablak, és <strong>ott folytatja</strong>, ahol az oldalon abbahagytad — ugyanaz a szám, ugyanaz a pozíció.', 'pl-player' ) ); ?></li>
			<li><?php esc_html_e( 'Az oldali lejátszó megáll, hogy ne szóljon egymáson a kettő.', 'pl-player' ); ?></li>
			<li><?php esc_html_e( 'Az ablak megmarad, amíg te be nem zárod, tehát szabadon járkálhatsz az oldalon.', 'pl-player' ); ?></li>
		</ul>
		<p class="plpm__p">
			<?php echo wp_kses_post( __( '<strong>Mobilon ez nem járható út</strong> — a telefonos böngészők a felugró ablakokat vagy blokkolják, vagy új fülként nyitják meg. Ott a másik megoldás dolgozik: a lejátszó megjegyzi, hol tartottál, és a következő oldalon egy koppintással folytatja. Autoplay nélkül, mert azt a böngésző nem engedi.', 'pl-player' ) ); ?>
		</p>
		<p class="plpm__p">
			<?php echo wp_kses_post( __( 'Ha a látogató kilép a böngészőből, a hang <strong>a háttérben tovább szól</strong>, és a zárolt képernyőn is látszik a cím meg a borító — ez a MediaSession része, és a Bluetooth-gombok is működnek vele.', 'pl-player' ) ); ?>
		</p>
		<?php
		self::code( array( '[playlist_player layout="hero" popup="no"]' ) );
	}

	private static function markers_section() {
		self::heading( 'jelolok', __( 'Időbélyeg-jelölők', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'Egy 90 perces mixnél a jelölők adják a tracklistát. A látogatónál a szám címe gombbá válik: rákattint, lenyílik a fejezetlista, és bármelyik pontra kattintva odaugrik. A kiemelt panel csúszkáján vonalkákként is látszanak, alatta pedig kattintható fejezetcsíkként.', 'pl-player' ); ?>
		</p>
		<h3 class="plpm__h3"><?php esc_html_e( 'Hallgatás közben, a lejátszóból', 'pl-player' ); ?></h3>
		<p class="plpm__p">
			<?php echo wp_kses_post( __( 'Ha be vagy jelentkezve és szerkesztheted a felvételt, a panelen megjelenik a <strong>„Jelölő ide"</strong> gomb. Hallgatás közben oda teszed, ahol épp jár a zene — mentés gomb nélkül, azonnal. Alatta a jelölők listája: az időre kattintva odaugrik, a névbe írhatsz, az X törli.', 'pl-player' ) ); ?>
		</p>
		<h3 class="plpm__h3"><?php esc_html_e( 'Az admin felületen', 'pl-player' ); ?></h3>
		<p class="plpm__p">
			<?php echo wp_kses_post( __( 'A szám szerkesztőjében, a szövegtörzs alatt: <strong>„Időbélyegek a felvételen"</strong>. A sávra kattintva kerül oda jelölő, a meglévőket elhúzhatod, és a táblázatban átírható az idő meg a név. Itt mentéskor kerülnek be.', 'pl-player' ) ); ?>
		</p>
		<?php
		self::note(
			__( 'A SZÁMOZÁSRÓL', 'pl-player' ),
			__( 'A névtelen jelölők „1. rész", „2. rész" néven jelennek meg. Ez nem kerül bele az adatbázisba, csak megjelenítéskor generálódik — így új jelölő beszúrásakor a számozás magától újrarendeződik.', 'pl-player' )
		);
	}

	private static function sharing_section() {
		self::heading( 'megosztas', __( 'Kedvelés és megosztás', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'Minden soron ott a kedvelés és a megosztás gomb, a kiemelt panelen pedig nagyban, feliratozva. A megosztás a böngésző saját megosztó felületét hívja, tehát telefonon rögtön ott a Messenger, a WhatsApp és minden más — harmadik féltől származó gombok és követőszkriptek nélkül. Asztali gépen a link a vágólapra kerül.', 'pl-player' ); ?>
		</p>
		<p class="plpm__p">
			<?php echo wp_kses_post( __( 'Amit megoszt, az <strong>a lejátszó oldala azzal a számmal elindítva</strong> — nem a szám külön oldala. Aki megkapja a linket, pontosan oda érkezik, ahol te hallgatod: ugyanabba a listába, ugyanabba a keresésbe.', 'pl-player' ) ); ?>
		</p>
		<?php
		self::code(
			array(
				'/zenelejatszo/?plp_track=684086',
				'/zenelejatszo/?plp_list=nyari-mix&plp_track=303',
			)
		);
	}

	private static function equalizer_section() {
		self::heading( 'ekvalizer', __( 'Az ambiens ekvalizér', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'A vizualizáció a képernyő aljáról emelkedik fel, a tartalom mögött, enyhe perspektívában forgó gyűrűként. A sávokat a tényleges hangból számolja a Web Audio API — nem előre gyártott animáció. A színét a szóló szám adja, ugyanaz, ami a panel hátterét is.', 'pl-player' ); ?>
		</p>
		<?php
		self::params(
			array(
				array( 'equalizer="yes"', __( 'alap', 'pl-player' ), __( 'Ambiens vizualizáció a képernyő alján.', 'pl-player' ) ),
				array( 'equalizer="always"', '—', __( 'Akkor is fut, ha a látogató csökkentett mozgást kért.', 'pl-player' ) ),
				array( 'equalizer="hero"', '—', __( 'A régi, panelen belüli kompakt változat.', 'pl-player' ) ),
				array( 'equalizer="no"', '—', __( 'Nincs vizualizáció.', 'pl-player' ) ),
			)
		);

		self::note(
			__( 'HA NEM JELENIK MEG', 'pl-player' ),
			__( 'A hangelemzéshez a böngészőnek hozzá kell férnie a fájl tartalmához. Ha az más domainről jön CORS fejlécek nélkül, az elemző csendet lát — ilyenkor a sávok nem jelennek meg, a hang viszont szól. Ez tudatos döntés: a lejátszást soha nem áldozzuk fel egy díszítésért.', 'pl-player' )
		);
	}

	private static function duplicates_section() {
		self::heading( 'duplikatumok', __( 'Duplikátumok', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'Lejátszó → Duplikátumok. Három bizonyossági szinten csoportosít: ugyanaz a hangfájl (biztos), ugyanaz a cím más fájlon (valószínű), azonos hossz más címmel (csak jelzés). Jelölőnégyzetekkel tömegesen lomtárba tehetők, és egy gombbal kijelölhetők a biztos egyezések.', 'pl-player' ); ?>
		</p>
		<?php
		self::note(
			__( 'TÖRLÉS NÉLKÜL IS MEGOLDHATÓ', 'pl-player' ),
			__( 'Ha a duplikátumok többsége abból fakad, hogy ugyanaz a felvétel egyszer podcast epizódként, egyszer zeneszámként létezik, a legkisebb kockázatú megoldás nem a törlés: a Beállításokban vedd ki a pipát az egyik tartalomtípusnál. A lejátszó onnantól csak az egyiket listázza, és semmi nem vész el.', 'pl-player' )
		);
	}

	private static function updates_section() {
		self::heading( 'frissites', __( 'Frissítés', 'pl-player' ) );
		?>
		<p class="plpm__p">
			<?php esc_html_e( 'A bővítmény a saját GitHub tárolójából figyeli az új kiadásokat, és a Bővítmények listán ugyanúgy jelzi őket, mint bármelyik más bővítmény. A zenék, kategóriák, listák, jelölők és a statisztika az adatbázisban vannak, nem a bővítmény fájljaiban — frissítéskor tehát érintetlenek.', 'pl-player' ); ?>
		</p>
		<p class="plpm__p plpm__muted">
			<?php esc_html_e( 'Frissítés után érdemes egy erős újratöltés (Ctrl+F5), hogy a régi JavaScript ne maradjon a böngésző gyorsítótárában.', 'pl-player' ); ?>
		</p>
		<?php
	}
}
