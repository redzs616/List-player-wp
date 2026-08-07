=== Lejátszási Lista Player ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Kategóriákba rendezett zenelejátszó WordPress oldalra, nyilvános lejátszás- és
like-statisztikával.

== Telepítés ==

1. A `pl-player` mappát töltsd fel a `/wp-content/plugins/` alá, vagy a
   `pl-player.zip`-et telepítsd a Bővítmények → Új hozzáadása → Bővítmény
   feltöltése menüponton.
2. Aktiváld a bővítményt. Aktiválás közben létrejön a két adatbázistábla
   (`wp_pl_events`, `wp_pl_likes`).
3. Az admin menüben megjelenik a **Lejátszó** menüpont.

== Frissítés ==

Nem kell kikapcsolni és nem kell törölni semmit:

1. Bővítmények → Új hozzáadása → **Bővítmény feltöltése**
2. Válaszd ki az új `pl-player.zip`-et → Telepítés most
3. A WordPress jelzi, hogy a bővítmény már telepítve van, és felajánlja a
   **„Jelenlegi felülírása a feltöltöttel"** gombot — ezt válaszd
4. Kész, aktív marad

A zenék, kategóriák, beállítások és a statisztika **nem érintett** — azok az
adatbázisban vannak, nem a bővítmény fájljaiban.

Ellenőrzés: a Bővítmények listán a **verziószámnak** meg kell egyeznie a
feltöltött ZIP verziójával.

== Automatikus frissítés GitHubról ==

Beállítás után a WordPress a Bővítmények listán ugyanúgy jelzi az új verziót,
mint bármelyik más bővítménynél.

Egyszeri beállítás:

1. Hozz létre egy GitHub tárolót (publikus a legegyszerűbb), pl. `pl-player`
2. Töltsd fel bele a bővítmény fájljait
3. Lejátszó → Beállítások → **GitHub tároló** mezőbe írd be
   `felhasznalonev/pl-player` formában, majd mentsd

Minden új verzió kiadása:

1. A `pl-player.php`-ban emeld a `Version:` értéket
2. GitHubon **Releases → Draft a new release**
3. Tag: a verziószám, pl. `v0.4.1` (a `v` elhagyható)
4. Csatolj hozzá egy `pl-player.zip` fájlt (Attach binaries) — ez a
   megbízhatóbb út, mert a ZIP-ben a mappanév pontosan `pl-player`
5. Publish release

A WordPress 6 óránként ellenőriz. Azonnali ellenőrzéshez: Beállítások →
**Keresés most**.

Privát tároló esetén a `wp-config.php`-ba kell egy token:

    define( 'PLP_GITHUB_TOKEN', 'ghp_...' );

A token szándékosan nem az admin felületen állítható be — az adatbázisban
tárolt token egy adatbázis-szivárgással együtt kerülne illetéktelen kézbe.

== Fejlesztési állapot ==

Elkészült (9. fázis — Divi modul):

* Saját Divi modul: a Divi Builder modullistájában megjelenik a
  **Lejátszási lista player**, shortcode írása nélkül
* Kattintással állítható: kategória (legördülőben a valós kategóriafa,
  behúzott alkategóriákkal), tartalomtípus, sorrend, elemszám, elrendezés,
  oszlopszám, kategória-navigáció és a látszó kategóriák száma, keresőmező,
  rendezés választó
* A kiemelő szín a Tervezés fülön, Divi natív színválasztójával
* Az oszlopszám csak rács nézetnél, a kategória-limit csak bekapcsolt
  navigációnál jelenik meg
* A shortcode változatlanul működik — a modul ugyanazt a renderelőt hívja,
  csak más bejárat hozzá

Elkészült (8. fázis — nyilvános statisztika és hallgatási mélység):

* Hallgatási mélység mérése: a lejátszó jelenti a tényleges hallgatott
  másodperceket, és hogy a szám 20 szeletéből melyik ment le. Tekerésnél az
  átugrott részek nem számítanak.
* Az adat `navigator.sendBeacon`-nal megy, tehát nem blokkolja az oldalt, és
  akkor is megérkezik, ha a látogató bezárja a fület
* Új tábla: `wp_pl_segments` (adatbázis séma 2-es verzió). Frissítéskor magától
  létrejön, nem kell újraaktiválni.
* A kiemelt panelen megjelenik az összes hallgatott idő és a visszatartási
  görbe — látszik, hol esnek ki a hallgatók
* Új `[playlist_stats]` shortcode: nyilvános top listák és forgalmi grafikon,
  egy önálló „Legnépszerűbb mixek" oldalhoz
* Beállításokban külön kapcsoló a hallgatási adatokra és a forgalmi trendre,
  ha valamelyiket nem szeretnéd kitenni
* A Beállításokban megjelent egy igazi **Frissítés most** gomb, ami el is
  végzi a frissítést, nem csak ellenőriz

Fontos: a mérés kliensoldali, tehát elvben hamisítható. Aggregált, nyilvános
megjelenítéshez ez elfogadható, a percenkénti kérés-limit ugyanúgy véd. Pénzben
elszámolt jogdíjhoz nem lenne elég.

Adatkezelés: a hallgatási mélység új mérés, érdemes egy sorral megemlíteni az
adatkezelési tájékoztatóban. Minden tárolt érték összesített, látogatóhoz nem
köthető.

Elkészült (7. fázis — statisztika képernyő):

* **Lejátszó → Statisztika** képernyő
* Összesítő kártyák: összes lejátszás, összes kedvelés, ma, utolsó 7 nap,
  lejátszható számok
* Napi lejátszás-grafikon 7 / 30 / 90 napos váltóval. Az idősor a site
  időzónájában vág napot, nem UTC-ben — különben minden nap eltolódna.
* Top 20 legtöbbet hallgatott és top 20 legkedveltebb, szerkesztő linkkel
* A periódus nyertesei: az adott időszakban legtöbbet hallgatott 10 szám
  (ez az esemény naplóból jön, nem az összesített számlálóból)
* Kategóriánkénti bontás arányjelző sávval
* CSV export minden lejátszható számról, Excel-kompatibilis kódolással

Mobil javítások (0.6.1 és 0.6.2), valós méréssel ellenőrizve 375x813-on:

* A ragadós sáv 163 pixel magas telefonon, és eddig alá esett a lista vége.
  Most az oldal helyet tart neki, elfordításnál újraszámolva.
* iPhone-on a sáv a home indikátor fölé kerül (safe-area)
* A kategória-sáv vízszintesen csúszó szalag lett: 278 pixelről 36-ra
* A kiemelt panel 575 pixelről 466-ra
* A kereső és a rendezés egy sorba került, további 40 pixel
* 480 pixel alatt szűkebb sorok; a lejátszásszám a sorokból a panelre kerül
* Javítva: a lejátszósáv magától felugrott a következő oldalon. A kiemelt
  panel betöltéskor kiválaszt egy számot, és ez úgy mentődött el, mintha a
  látogató hallgatta volna. Mostantól csak tényleges lejátszás után ment.


Elkészült (6. fázis — borító nélküli számok és sok kategória kezelése):

* Helyettesítő borító a kép nélküli számokhoz: a bejegyzés azonosítójából
  származó saját szín és a cím kezdőbetűje, tehát minden szám külön arcot kap
  egyforma szürke négyzetek helyett
* A kiemelt panel háttere borító nélkül is felveszi ezt a színt, nem marad lapos
* `nav_limit` paraméter: alapból 12 kategória látszik, a többi egy „További N
  kategória" gomb mögé kerül. Az épp kiválasztott kategória mindig látható.
* `nav_taxonomy` paraméter: a navigáció egyetlen taxonómiára szűkíthető, ha
  több tartalomtípus külön kategóriarendszere futna össze egy sávban

Elkészült (5. fázis — kiemelt panelos dizájn):

* Új `layout="hero"` elrendezés: nagy kiemelt panel a kiválasztott számmal —
  nagy borító, cím, előadó, tekerő sáv, nagy lejátszás gomb, kedvelés és
  lejátszásszám
* A panel háttere magának a borítónak az elmosott, sötétített változata, így
  minden számnál más hangulatot kap
* A panel alatt a lista sorszámozott, tömör formában
* A panel mindig sötét üveghatású, tehát világos és sötét oldalon is
  szándékosnak látszik
* Mobilon a panel egy hasábbá rendeződik, középre igazítva
* GitHub alapú automatikus frissítés (lásd fentebb)

Elkészült (4. fázis — a látható lejátszó):

* `[playlist_player]` shortcode — Divi Szöveg vagy Kód modulba beírva működik
* Lista és rács nézet, kategória-navigáció a hierarchia megtartásával
* Ragadós lejátszósáv az oldal alján: borító, cím, tekerés, hangerő,
  előző/következő, véletlen sorrend, ismétlés
* Like gomb és lejátszásszám minden számnál
* Keresés, rendezés (legújabb, legtöbbet hallgatott, legkedveltebb, cím,
  véletlen), további számok betöltése
* MediaSession: telefonon a zárolt képernyőn is látszik a cím és a borító,
  működnek a rendszer- és Bluetooth-gombok
* Lapváltás után a lejátszó ott folytatja, ahol abbahagytad
* Billentyűzet: Space, balra/jobbra nyíl, M
* A `/wp-json/` útvonalat blokkoló szervereken automatikusan átvált a
  `?rest_route=` formára

Shortcode paraméterek:

    [playlist_player
        category="house,techno"   kategória azonosító vagy slug
        post_type="pl_track"      csak egy tartalomtípus
        layout="hero|list|grid"   kiemelt panel, lista vagy rács
        columns="3"               rács oszlopszám (1-6)
        limit="20"                számok oldalanként
        orderby="date|plays|likes|title|random|menu_order"
        order="desc|asc"
        nav="yes|no"              kategória-navigáció
        nav_limit="12"            ennyi kategória látszik, 0 = mind
        nav_taxonomy="pl_category"  csak egy taxonómia kategóriái
        search="yes|no"           keresőmező
        sort="yes|no"             rendezés választó
        accent="#f0a12e"          kiemelő szín ]

Elkészült (3. fázis — statisztika motor és REST API):

* **Lejátszó → Beállítások** képernyő: itt jelölöd ki, mely tartalomtípusok
  kerüljenek a lejátszóba (pl. a meglévő Podcastok is)
* Automatikus hangfájl-felismerés más tartalomtípusoknál is: hozzárendelt
  médiatár-fájl, `[audio]` shortcode, podcast enclosure, vagy csatolt hangfájl
  — átköltöztetés nélkül
* Lejátszás- és like-számlálás versenyhelyzet-biztos, atomi növeléssel
* Esemény napló (`wp_pl_events`) az időbeli kimutatásokhoz
* Vendég-azonosítás véletlen sütivel; nyers IP-cím nem kerül adatbázisba
* Egy látogató egy számot csak egyszer kedvelhet — adatbázis szintű
  UNIQUE index garantálja
* Lejátszás csak ténylegesen lehallgatott idő után számol, látogatónként
  beállítható türelmi idővel
* REST API: `/wp-json/plplayer/v1/tracks`, `/categories`, `/counters`,
  `/tracks/{id}/play`, `/tracks/{id}/like`
* A számlálók külön végponton töltődnek, így page cache mellett sem
  fagynak be

Elkészült (2. fázis — tömeges import):

* **Lejátszó → Tömeges import** képernyő
* Több hangfájl kijelölése a Médiatárból, vagy feltöltés ugyanabban az ablakban
* Táblázatos előnézet a beolvasott ID3 adatokkal, mentés előtt szerkeszthetően
* Kategóriák, címkék és állapot (közzétett / vázlat) egy lépésben az egész
  kötegre
* Beágyazott borítókép importálása borítóként, azonos borítóknál a már
  feltöltött kép újrahasznosításával
* Duplikátum-védelem: egy hangfájlhoz csak egy zeneszám tartozhat
* Fájlonkénti importálás folyamatjelzővel, így nagy köteg sem futhat
  időtúllépésbe

Elkészült (1. fázis — alapok):

* Bővítmény váz, aktiválás, adatbázis séma verziókezeléssel
* `pl_track` poszttípus (Zeneszámok)
* `pl_category` hierarchikus kategória-taxonómia (mappa a mappában)
* `pl_tag` szabad címke-taxonómia
* Zeneszám adatlap: hangforrás (Médiatár vagy külső CDN URL), előadó, album,
  év, hossz
* ID3 automatikus kitöltés a Médiatárból választott fájlból (előadó, album,
  év, hossz, cím) — csak az üres mezőkbe ír
* Zeneszámok listája: borító, előadó, hossz, hangfájl állapota, lejátszás- és
  like-szám, rendezhető oszlopokkal

Következő fázisok: saját Divi modul (hogy shortcode helyett kattintva
állítható legyen), admin statisztika képernyő grafikonokkal és CSV exporttal.

== Adatkezelés ==

A bővítmény törlésekor alapértelmezésben **minden adat megmarad**. A zenék, a
kategóriák és a statisztika csak akkor törlődik, ha a `plp_settings` beállításban
a `delete_data_on_uninstall` értéke be van kapcsolva.
