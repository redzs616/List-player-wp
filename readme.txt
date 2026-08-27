=== Lejátszási Lista Player ===
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.10.1
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

A ZIP-et **így** kell elkészíteni:

    git archive --format=zip --prefix=pl-player/ -o pl-player.zip v1.2.3

FONTOS: a Windows PowerShell `Compress-Archive` parancsa **nem használható**.
Az visszaperjelet ír útvonal-elválasztónak (`pl-player\pl-player.php`), a ZIP
szabvány viszont perjelet ír elő. Linux szerveren a PHP az ilyen bejegyzést nem
könyvtárnak érti, hanem egyetlen furcsa nevű fájlnak — a frissítés ilyenkor
törli a régi könyvtárat, kicsomagol valami használhatatlant, és a bővítmény
eltűnik. Ellenőrzés a csomagoláskor:

    unzip -l pl-player.zip | grep -c '\\'

Ha ez nem nullát ad, a csomag hibás, ne add ki.

A WordPress 6 óránként ellenőriz. Azonnali ellenőrzéshez: Beállítások →
**Keresés most**.

Privát tároló esetén a `wp-config.php`-ba kell egy token:

    define( 'PLP_GITHUB_TOKEN', 'ghp_...' );

A token szándékosan nem az admin felületen állítható be — az adatbázisban
tárolt token egy adatbázis-szivárgással együtt kerülne illetéktelen kézbe.

== Fejlesztési állapot ==

Javítva (1.10.1 — a csúszkák megfoghatósága):

Mindhárom csúszkának **maga a vékony vonal volt a fogható területe**: a kiemelt
panelé 5 képpont, az alsó sávé és a hangerőé 4 képpont magas. Ujjal ezt eltalálni
gyakorlatilag lehetetlen volt. Ez elnézés volt, nem szándék — hiányzott a
`::-webkit-slider-runnable-track` szabály, így a látható vonal maga az input volt.

* A vékony vonal átkerült a track pszeudo-elemre, tehát **a látvány változatlan**,
  de a fogható sáv magas lett: kiemelt panel 5 → **28 képpont**, alsó sáv és
  hangerő 4 → **26 képpont**.
* **Érintőképernyőn 44, illetve 40 képpont** — a 44 pont az ajánlott minimum
  érintőcélpont. Ez nem képernyőméret szerint dől el, hanem beviteli mód szerint
  (`pointer: coarse`), tehát egy fekvő tablet is megkapja.
* A gomb 15 → 16, érintésnél 20 képpontra nőtt.
* **A csúszka húzása többé nem görgeti az oldalt** telefonon (`touch-action`).
  Eddig egy vízszintes húzás könnyen függőleges görgetésbe csúszott át.
* A hangerő 80 → **110 képpont széles**, tehát egy adott hangerő beállítása
  kevesebb pontosságot kíván.
* A fejezetjelölők pontosan a vonal közepére kerültek. A csúszka sorbeli
  elhelyezése miatt volt egy pár képpontos elcsúszás.
* Billentyűzettel fókuszálva mostantól látszik a fókuszkeret.

Elkészült (1.10.0 — válogatott listák egy oldalon, és a hozzáadás panel):

* A `[playlist_index]` új **`lists`** paramétere: felsorolod, mely listákat
  akarod kitenni, slug vagy ID alapján, vesszővel — és a megadott sorrend
  érvényesül, nem az ábécé. Üresen hagyva továbbra is mindet kiteszi.
  Például: `[playlist_index lists="nyari-mix,retro-szett,chill"]`
* Új **`exclude`**: mindet kiteszi, kivéve a felsoroltakat.
* Új **`orderby`**: `title` (ábécé), `date` (legújabb elöl) vagy `tracks`
  (legtöbb számot tartalmazó elöl). Mindegyiknek megvan a maga természetes
  iránya, az `order` csak akkor kell, ha szembe akarsz menni vele.
* Ha egy megnevezett lista nem található (elírás, vagy nincs közzétéve), a
  blokk ezt írja ki — nem azt, hogy „még nincs lejátszási lista". Az előbbi
  üzenet a Beállítások felé küldött volna, holott a shortcode-ban van a hiba.

Javítva: a „hozzáadás lejátszási listához" panel eddig nyitva maradt a mentés
után, és **az előző számhoz kötve**. Így ha közben másik zenére léptél, a
következő kattintás az előzőt tette egy újabb listába. Mostantól:

* mentés után a panel **becsukódik**, és a lista alatti állapotsorban
  megjelenik, hogy melyik szám melyik listába került — tehát látszik a
  visszajelzés, csak nem egy útban lévő panelen
* ugyanez új lista létrehozásánál is
* ha másik számra váltasz, a panel akkor is becsukódik, bármitől is nyílt ki

Elkészült (1.9.0 — duplikátumok tömeges lomtárazása):

* **Jelölőnégyzetek a duplikátum jelentésben**, és egy „Kijelöltek lomtárba"
  gomb a táblázatok fölött és alatt is — hosszú listánál ne kelljen
  visszagörgetni.
* **„Csak a biztos egyezések kijelölése"** egy kattintással: ez kizárólag az
  „Ugyanaz a hangfájl" csoportokat jelöli ki. A másik két szint találgatás,
  azokat szándékosan nem nyúlja meg.
* Csoportonként fejlécben egy kijelölő négyzet, ami követi a saját sorait
  (részleges kijelölésnél félig behúzott állapot).
* **A megtartásra javasolt példány nem is kap jelölőnégyzetet.** Így egy
  csoportot elvileg sem lehet teljesen kiürteni.
* **Lomtár, nem törlés.** A WordPress saját lomtárába kerül, tehát egy
  kattintással visszaállítható, amíg nem ürítesz lomtárat.
* A megerősítő kérdés megmondja, mennyi bejegyzésről van szó, és külön
  kiemeli, ha közte **nem a bővítmény saját zeneszáma** van — például podcast
  epizód, aminek saját linkje és RSS bejegyzése van.
* A sorokon és a táblázatok fölött is jelezzük, ha meglévő tartalom kerülne
  lomtárba, és ott ajánljuk a törlés nélküli megoldást: a Beállításokban vedd
  ki a pipát az egyik tartalomtípusnál.
* A művelet után kiírja, hány bejegyzés került lomtárba, és hányat hagyott ki.

A szerver a beküldött azonosítókat **kérésnek tekinti, nem utasításnak**: újra
felépíti a jelentést, és csak azt teszi lomtárba, amit az *abban a pillanatban*
is fölös példányként jelöl, és amire van törlési jogosultságod. Így egy régóta
nyitva hagyott fül vagy egy kézzel átírt kérés nem tud tetszőleges bejegyzéshez
hozzáférni.

Elkészült (1.8.0 — jelölők hallgatás közben, a lejátszóból):

* **„Jelölő ide" gomb a kiemelt panelen.** Hallgatás közben odarakod a
  jelölőt, ahol épp jár a zene — nem kell az admin felületre menni. A jelölő
  azonnal mentődik, nem kell külön mentést nyomni.
* Alatta a jelölők listája: a **időre kattintva odaugrik**, a névbe írhatsz,
  az X törli. A név a mezőből kilépve vagy Enterre mentődik, nem
  betűnként.
* A jelölősáv és a szám alatti fejezetlista rögtön követi a változást,
  újratöltés nélkül.
* Csak annak látszik, aki **szerkesztheti azt a felvételt**. Látogató nem
  látja, és nem is tud írni: a mentés poszt szintű jogosultságot ellenőriz,
  nem csak bejelentkezést.
* A gomb megjelenítéséről nem a PHP dönt, hanem egy REST kérdés a betöltés
  után. Ez azért fontos, mert page cache mellett a szerveroldali eldöntés az
  egyik látogató válaszát égeti bele a következő oldalába — vagy megmutatná a
  gombot annak, akinek nem jár, vagy elrejtené attól, akinek jár.

Ismert korlát: ha a gyorsítótár egy bejelentkezett szerkesztőnek is vendég
oldalt szolgál ki, a gomb nem jelenik meg. A WordPress REST cookie-alapú
azonosításához nonce kell, az pedig nem lehet gyorsítótárazott HTML-ben. A
LiteSpeed Cache alapból nem gyorsítótáraz bejelentkezett felhasználónak, tehát
ez a gyakorlatban nem szokott előfordulni.

Javítva (1.7.2 — kirakott lejátszási listák):

* **A lista neve látszik ott, ahol eddig a kategóriák.** Ha egy oldalra
  `[playlist_player playlist="..."]`-t teszel, felül eddig a teljes
  kategória-navigáció jelent meg „Összes" gombbal. Egy lista nem szűrő, tehát
  most a lista neve és a számok darabszáma áll ott. Az `nav="no"` továbbra is
  eltünteti ezt a sort is.
* **A lista eddig szétesett az első lekérés után.** A lista azonosítója nem
  utazott a REST kérésekkel, így egy keresés, egy kategóriakattintás vagy a
  „Továbbiak" gomb csendben az egész zenetárra váltott. Innentől minden
  lekérés a listán belül marad.
* A rendezés választó listamódban eltűnik. A lista saját sorrendje felülírja a
  rendezést, tehát egy olyan legördülő volt kitéve, ami semmit nem tett.
* Ha a megnevezett lista nem létezik (elírás, vázlat, kukázott), a lejátszó
  üres marad. Eddig ilyenkor **az egész zenetárat** kilistázta.
* Vázlat állapotú lista tartalmát kívülről nem lehet kiolvasni. A lista
  azonosítója most nyilvános REST paraméter is, ezért a feloldás jogosultságot
  ellenőriz.

Javítva (1.7.1 — a kiemelt panel play gombja):

* **A kiemelt panel nagy play gombja nem indította el a zenét.** A fejezetek
  lenyitásához felvett `toggle` nevű változó a JavaScript változó-felhúzása
  miatt elárnyékolta az ugyanígy hívott `toggle()` függvényt, így a gomb
  `TypeError`-ral elhalt, még mielőtt bármi történt volna. A változó új nevet
  kapott, és a fájlban minden más ilyen névütközést is átvizsgáltunk.
* Az ekvalizér többé nem tudja elvenni a hangot. A hangelemző eddig akkor is
  bekötötte magát, ha az `AudioContext` nem futott — ilyenkor a hang egy halott
  gráfba folyt, és nem volt visszaút. Innentől a bekötés csak futó
  hangkörnyezetben történik meg, egyébként az ekvalizér kimarad, a zene szól.
* A hangelemző gráf kizárólag a lejátszás **előtt** épül fel. Eddig a
  `play` eseményből is megkísérelte — egy már szóló elemre kapcsolódva a
  Chrome csendet ad vissza.
* Ha a lejátszás mégis elhasal, azt a lejátszó kiírja. Eddig a hibát csendben
  elnyelte, ezért látszott úgy, hogy a kattintásra semmi nem történik.
* A kiemelt panel által előre kiválasztott szám indításakor megjelenik az alsó
  ragadós sáv is. Eddig ez az egy útvonal kihagyta.
* A számok sora tördelhető lett, így a fejezetlista a sor alá kerül, nem
  szorul be a cím és a gombok közé.

Elkészült (1.1.0 — saját lejátszási listák és élő ekvalizér):

* Új **Lejátszási listák** tartalomtípus a Lejátszó menü alatt. Adsz neki egy
  nevet, és kézzel összeválogatod, mely számok legyenek benne, milyen
  sorrendben.
* A szerkesztőben kereső a jobb oldalon, a kiválasztott lista a bal oldalon
  fogd-és-vidd sorrendezéssel. A sorok mutatják a borítót, előadót és hosszt.
* A listák oszlopában ott a kész shortcode, egy kattintással kimásolható
* Új `playlist` paraméter: `[playlist_player playlist="nyari-mix"]`. A lista
  saját sorrendje érvényesül, a kategória és a rendezés nem számít.
* A Divi modulban is választható legördülőből, a számok darabszámával
* Törölt vagy nem lejátszható szám a listában pirossal jelenik meg, nem
  csendben tűnik el

* **Élő ekvalizér** a kiemelt panel hátterében: a Web Audio API-val a tényleges
  hangból számolja a sávokat, nem előre gyártott animáció. Lejátszáskor
  beúszik, megállításkor kiúszik.
* Az ekvalizér a kiemelő színt veszi fel, és a `equalizer="no"` paraméterrel
  kikapcsolható
* Csökkentett mozgást kérő beállítás mellett nem animál

Fontos az ekvalizérről: a hangelemzéshez a böngészőnek hozzá kell férnie a
hangfájl tartalmához. Ha a fájl más domainről jön CORS fejlécek nélkül, az
elemző csendet lát — ilyenkor a sávok egyszerűen nem jelennek meg, a hang
viszont szól. Ez tudatos döntés: a lejátszást soha nem áldozzuk fel egy
díszítésért.

Javítva: a popup gomb eddig a keresősor belsejében volt, így `search="no"
sort="no"` mellett eltűnt vele. Most akkor is megjelenik.

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
