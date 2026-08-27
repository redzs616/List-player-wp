# Lejátszási Lista Player

![Verzió](https://img.shields.io/badge/verzió-1.11.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![Licenc](https://img.shields.io/badge/licenc-GPL--2.0%2B-green)

WordPress bővítmény kategóriákba rendezett zenelejátszóhoz, nyilvános lejátszás- és
kedvelés-statisztikával. A meglévő podcast epizódokat is be tudja vonni anélkül,
hogy bármit át kellene költöztetni.

## Mit tud

**Zenetár mappákba rendezve** — a kategóriák hierarchikusak, tehát tetszőleges
mélységben egymásba ágyazhatók (Elektronikus → House → Deep House). Egy szám több
kategóriába is tartozhat, és szabad címkézés is van mellette.

**Kétféle hangforrás számonként** — a fájl lehet a WordPress Médiatárban, vagy egy
külső CDN-en (Bunny.net, S3). A kettő szabadon keverhető, és később átköltöztethető
egyik módról a másikra.

**ID3 automatikus kitöltés** — a WordPress core-ban lévő getID3 alapján a feltöltött
MP3-akból kiolvassa a címet, előadót, albumot, évet és hosszt, a beágyazott
borítóképet pedig borítóként állítja be. Csak az üres mezőket írja, egy kézi
javítást soha nem ír vissza.

**Tömeges import** — több fájl egyszerre, táblázatos előnézettel, amiben mentés előtt
javíthatók az adatok. Kategória, címke és állapot egy lépésben az egész kötegre.
Fájlonként dolgozik folyamatjelzővel, így nagy köteg sem fut időtúllépésbe.

**Statisztika, ami nem hazudik** — a lejátszásszám nem a Play gomb megnyomásától
növekszik, hanem a ténylegesen lehallgatott időtől, látogatónként beállítható türelmi
idővel. Egy látogató egy számot csak egyszer kedvelhet, ezt adatbázis szintű `UNIQUE`
index garantálja.

**Beágyazható lejátszó** — háromféle elrendezés, ragadós lejátszósáv, kategória-
navigáció, keresés, rendezés. Telefonon a zárolt képernyőn is látszik a cím és a
borító, és működnek a rendszer- meg a Bluetooth-gombok.

## Követelmények

| | |
|---|---|
| WordPress | 6.0 vagy újabb |
| PHP | 7.4 vagy újabb |
| Egyéb | nincs külső könyvtár, nincs jQuery a frontenden |

## Telepítés

1. Töltsd le a legutóbbi kiadás `pl-player.zip` fájlját a
   [Releases](../../releases) oldalról
2. **Bővítmények → Új hozzáadása → Bővítmény feltöltése**
3. Aktiválás. Ekkor létrejön a `wp_pl_events` és a `wp_pl_likes` tábla.
4. Az admin menüben megjelenik a **Lejátszó** menüpont

Frissítésnél ugyanez a folyamat: nem kell kikapcsolni és nem kell törölni semmit. A
WordPress felajánlja a *„Jelenlegi felülírása a feltöltöttel"* gombot. A zenék,
kategóriák, beállítások és a statisztika az adatbázisban vannak, tehát érintetlenek.

## Minden shortcode egy helyen

A bővítmény **két** shortcode-ot ad. Bárhová beírhatók, ahol a WordPress feldolgozza
őket: oldal, bejegyzés, Divi Szöveg vagy Kód modul, widget.

| Shortcode | Mit tesz |
|---|---|
| `[playlist_player]` | A lejátszó: listázza és lejátssza a zenét |
| `[playlist_index]` | A lejátszási listák oldala: felsorolja őket, és rákattintva megnyitja |
| `[playlist_stats]` | Nyilvános toplisták és forgalmi grafikon, önálló statisztika oldalhoz |

### Kész példák, másolásra

```
// Kiemelt panel a saját kategóriafával, arany kiemeléssel
[playlist_player layout="hero" accent="#f0a12e" nav_limit="10" equalizer="always"]

// Egy műfaj kártyás rácsban, navigáció nélkül
[playlist_player layout="grid" columns="4" category="deep-house" nav="no"]

// A tíz legtöbbet hallgatott, tömör listában
[playlist_player orderby="plays" limit="10" nav="no" search="no"]

// Egy kézzel összeválogatott lista a saját sorrendjében
[playlist_player playlist="nyari-mix" layout="hero"]

// Csak a podcast epizódok
[playlist_player post_type="podcast" layout="hero"]

// Toplista oldal grafikonnal
[playlist_stats limit="10" show="both" trend="yes" days="30" accent="#f0a12e"]

// Toplista grafikon nélkül, csak a legkedveltebbek
[playlist_stats show="likes" limit="20" trend="no"]
```

Az összes paraméter táblázatosan lentebb következik. Van rajtuk kívül egy harmadik
bejárat is: a **Divi modul**, ami ugyanezt a lejátszót adja, csak kattintással
állítva — lásd a *Divi modul* fejezetet.

## Használat

Illeszd be a shortcode-ot bármelyik oldalba, bejegyzésbe, vagy page builder szöveg-
illetve kód modulba:

```
[playlist_player layout="hero" accent="#f0a12e"]
```

### Shortcode paraméterek

| Paraméter | Alapérték | Leírás |
|---|---|---|
| `layout` | `list` | `hero` (kiemelt panel + sorszámozott lista), `list` (tömör lista), `grid` (kártyás rács) |
| `playlist` | — | Egy saját lejátszási lista azonosítója vagy slugja. A lista sorrendje érvényesül, a kategória és a rendezés nem számít. |
| `equalizer` | `yes` | Élő ekvalizér a kiemelt panel hátterében, a tényleges hangból. `yes` — csökkentett mozgást kérő látogatónál nem indul. `always` — akkor is indul. `no` — soha. |
| `theme` | `auto` | `auto`, `dark` vagy `light` |
| `popup` | `yes` | A „Külön ablakban" gomb |
| `category` | — | Kategória azonosító vagy slug, vesszővel több is |
| `terms` | — | Ugyanaz, mint a `category`; címkékre is működik |
| `post_type` | — | Csak egy tartalomtípusra szűkít |
| `columns` | `3` | Rács oszlopszám, 1–6 |
| `limit` | `20` | Számok oldalanként |
| `orderby` | `date` | `date`, `plays`, `likes`, `title`, `random`, `menu_order` |
| `order` | `desc` | `asc` vagy `desc` |
| `nav` | `yes` | Kategória-navigáció megjelenítése |
| `nav_limit` | `12` | Ennyi kategória látszik, a többi „További N kategória" mögé kerül. `0` = mind |
| `nav_taxonomy` | — | Csak egy taxonómia kategóriáit mutatja, pl. `pl_category` |
| `search` | `yes` | Keresőmező megjelenítése |
| `sort` | `yes` | Rendezés választó megjelenítése |
| `accent` | — | Kiemelő szín hexa kóddal, pl. `#f0a12e` |

### Nyilvános statisztika oldal

Külön shortcode egy „Legnépszerűbb mixek" oldalhoz:

```
[playlist_stats limit="10" show="both" trend="yes" days="30"]
```

| Paraméter | Alapérték | Leírás |
|---|---|---|
| `limit` | `10` | Hány szám szerepeljen egy listában |
| `show` | `both` | `plays`, `likes` vagy `both` |
| `trend` | `yes` | Forgalmi grafikon megjelenítése |
| `days` | `30` | A grafikon időszaka, 7–90 nap |
| `accent` | — | Kiemelő szín |

A blokk a szerveren renderelődik, tehát page cache mellett néhány óráig állhat
elavult adaton. Egy statisztika oldalnál ez elfogadható csere azért, hogy minden
látogatónak ne kelljen külön kérést indítani.

### Billentyűzet

`Space` lejátszás/megállítás · `←` `→` tekerés 5 másodperccel · `M` némítás

### Kedvelés és megosztás

Minden soron ott van a **kedvelés** és a **megosztás** gomb, a hossz mellett; a kiemelt
panelen pedig nagyban, feliratozva.

A megosztás a **böngésző saját megosztó felületét** hívja (`navigator.share`), tehát
telefonon rögtön ott van a Messenger, WhatsApp és minden, amit a látogató használ —
harmadik féltől származó gombok és követőszkriptek nélkül. Asztali böngészőkben ez
általában nincs meg, ott a link a **vágólapra** kerül, és a lejátszó visszajelzi.

Amit megoszt, az a szám **saját oldala** (`/zene/...`), nem az, ahol épp a lejátszó áll.

## Saját lejátszási listák

A kategória azt mondja meg, **mi** egy szám. A lejátszási lista azt, hogy **mi követ
mit** — ez két különböző dolog, ezért külön tartalomtípus, nem újabb taxonómia.

**Lejátszó → Lejátszási listák → Új lista.** A szerkesztőben jobb oldalt keresel, a
találatra kattintva bekerül; bal oldalt a sorrend, a sorokat a pontozott fogantyúnál
fogva húzhatod a helyükre. A listák táblázatában ott a kész shortcode.

```
[playlist_player playlist="nyari-mix"]
```

Ilyenkor a lista **saját sorrendje** érvényesül, a `category` és az `orderby` nem
számít. Ha egy szám közben törlődik vagy elveszti a hangfájlját, pirossal jelenik meg
a listában — nem csendben tűnik el, hogy lásd, hol van lyuk.

A Divi modulban legördülőből választható, a benne lévő számok darabszámával.

### Listák készítése hallgatás közben

Ha **be vagy jelentkezve** szerkesztői joggal, minden soron megjelenik egy **+** gomb.
Rákattintva egy panel nyílik, ahol a számot beteheted egy meglévő listába, vagy
**helyben létrehozhatsz újat** — csak írd be a nevét. Ami már benne van egy listában, az
kiszürkül, tehát nem tudod kétszer betenni.

A gomb és a panel **nem is kerül bele az oldalba** kijelentkezett látogatónál. Egy
nyilvános felület, ami bárkinek engedi bejegyzést létrehozni az oldalon, nyitott kapu
lenne — ezért nem kapcsoló kérdése, hanem az sem generálódik le.

### A listák oldala

```
[playlist_index columns="3" layout="hero"]
```

Ez felsorolja a lejátszási listákat borítóval, a számok darabszámával és a teljes
hosszal. Egy listára kattintva **ugyanaz az oldal** megnyitja azt a listát a
lejátszóban, fölötte egy „← Vissza a listákhoz" linkkel.

| Paraméter | Alapérték | Leírás |
|---|---|---|
| `lists` | — | **Csak ezek a listák**, slug vagy ID, vesszővel. A megadott sorrend érvényesül. Üresen hagyva minden közzétett lista megjelenik. |
| `exclude` | — | Ezeket hagyd ki, slug vagy ID, vesszővel |
| `orderby` | `title` | `title` (ábécé), `date` (legújabb elöl), `tracks` (legtöbb számot tartalmazó elöl) |
| `order` | — | `asc` vagy `desc`, ha a fentiek természetes irányával szemben kell |
| `columns` | `3` | Oszlopszám a listák rácsában, 1–6 |
| `layout` | `hero` | Milyen elrendezésben nyíljon meg a választott lista |
| `accent` | — | Kiemelő szín |
| `limit` | `100` | Legfeljebb ennyi lista jelenik meg |

Példák:

```
// Minden lista, ábécé sorrendben
[playlist_index]

// Csak ez a négy, pontosan ebben a sorrendben
[playlist_index lists="nyari-mix,retro-szett,chill,ejszakai"]

// Minden lista a legújabbtól, egy kivételével
[playlist_index orderby="date" exclude="teszt-lista"]

// A legnagyobb listák elöl, két oszlopban
[playlist_index orderby="tracks" columns="2"]
```

A slug az, ami a lista URL-jében szerepel — a **Lejátszó → Lejátszási listák** táblázatban
megtalálod. ID is használható, ha egyszerűbb. Ha egy megnevezett lista nem található
(elírás, vagy nincs közzétéve), a blokk ezt kiírja, nem pedig azt, hogy nincs listád.

A választott lista **valódi URL-t kap** (`?plp_list=slug`), nem helyben cserélődik. Így
működik a vissza gomb, egy lista linkje megosztható, és minden állapot külön
gyorsítótárazódik ahelyett, hogy minden látogató újra lekérné ugyanazt.

## Időbélyeg-jelölők

Egy 90 perces mixnél a jelölők adják a tracklistát. A látogatónál a szám **címe
gombbá válik** egy kis nyíllal: rákattint, lenyílik alatta a fejezetlista, és
bármelyik pontra kattintva odaugrik. Jelölő nélküli szám címe sima szöveg marad. A
kiemelt panel csúszkáján a jelölők apró vonalkákként is látszanak.

Kétféleképpen vihetők fel.

### Hallgatás közben, a lejátszóból

Ha be vagy jelentkezve, és **szerkesztheted azt a felvételt**, a kiemelt panelen
megjelenik egy **„Jelölő ide"** gomb. Hallgatás közben oda teszed a jelölőt, ahol
épp jár a zene — mentés gomb nélkül, azonnal.

Alatta ott a jelölők listája:

- az **időre kattintva** odaugrik a lejátszás
- a **névbe írhatsz**; a mezőből kilépve vagy Enterre mentődik, nem betűnként
- az **X** törli

A jelölősáv és a szám alatti fejezetlista rögtön követi, újratöltés nélkül.

Látogató ebből semmit nem lát, és nem is tud írni: a mentés **poszt szintű**
jogosultságot ellenőriz, nem csak azt, hogy valaki be van-e jelentkezve.

> A gomb megjelenéséről nem a PHP dönt, hanem egy REST kérdés a betöltés után
> (`GET /me`). Page cache mellett a szerveroldali eldöntés az egyik látogató
> válaszát égetné bele a következő oldalába — vagy megmutatná a gombot annak,
> akinek nem jár, vagy elrejtené attól, akinek jár.
>
> Ennek egy korlátja van: ha a gyorsítótár egy bejelentkezett szerkesztőnek is
> vendég oldalt ad, a gomb nem jelenik meg. A WordPress REST cookie-alapú
> azonosításához nonce kell, az pedig nem lehet gyorsítótárazott HTML-ben. A
> LiteSpeed Cache alapból nem gyorsítótáraz bejelentkezett felhasználónak, tehát a
> gyakorlatban ez nem szokott előfordulni.

### Az admin felületen

A szám vagy a podcast epizód szerkesztőjében, a szövegtörzs alatt: **„Időbélyegek a
felvételen"**. Itt egy nagyobb sávon dolgozol:

- a **sávra kattintva** kerül oda jelölő
- a meglévőket **megfoghatod és elhúzhatod**
- a táblázatban átírható az **idő** (`1:23`, `12:30`, `1:23:45`) és a **név**
- a **„Jelölő a mostani pozíciónál"** gomb oda tesz egyet, ahol a lejátszás áll

Itt a jelölők a **poszt mentésekor** kerülnek be, tehát mentened kell.

Egy másodpercre csak egy jelölő eshet, és számonként legfeljebb 100 lehet. A
névtelen jelölők „1. rész", „2. rész" néven jelennek meg — ez **nem** kerül bele az
adatbázisba, csak megjelenítéskor generálódik, így új jelölő beszúrásakor a
számozás magától újrarendeződik.

## Elemzés és generált borítók

**Lejátszó → Elemzés.** A böngésző végigmegy a számokon, és **magából a hangból**
megmér három dolgot. Számonként 10–15 másodperc, folyamatjelzővel.

| Mit mér | Hogyan |
|---|---|
| **BPM** | A basszussáv energiaburkolójának autokorrelációja. Elektronikus zenén megbízható. |
| **Energia** | A spektrum átlagos amplitúdója — mennyire nyomós a felvétel |
| **Fényesség** | Spektrális súlypont — tompa vagy csillogó |

Ezekből lesznek a címkék a lejátszóban: *„138 BPM · kemény · sötét"*. A `_pl_bpm`,
`_pl_energy` és `_pl_bright` mezőkben tárolódnak.

A mérés úgy zajlik, hogy a lejátszó a felvétel közepére ugrik (40%-nál, de legfeljebb
két percnél), **kétszeres sebességgel, hangtalanul** lejátszik egy szakaszt, és
mintavételezi a spektrumot. A hangtalanság egy nulla erősítésű csomóponttal készül a
mérési pont *után*, tehát a jel teljes, csak nem hallható. A kétszeres sebesség miatt
a mért tempó kétszerese a valósnak — ez vissza van osztva.

### Amit ez nem tud

**Nem ismeri fel a mixben szóló számokat**, és nem ad tracklistát. Ahhoz
hangfelismerő szolgáltatás kellene (pl. ACRCloud), ami használatarányos díjú, és a
hangot el kell küldeni hozzá. Az ingyenes AcoustID erre nem jó: egész számokat
azonosít, és a fingerprintje érzékeny az időnyújtásra, ezért egy beatmatchelt mixen
gyenge a találati aránya.

**Nem ad műfaj-címkét.** Egy tanított modell rátenné, hogy „techno", és elég gyakran
tévedne ahhoz, hogy félrevezető legyen. Ami itt van, az mérés — nem tipp.

### Generált borítók

Ugyanabból a mérésből **borító is készül** azoknak, amiknek nincs. A kép a felvétel
saját spektrumából áll össze: vízszintesen az idő, függőlegesen a frekvencia — egy
kis spektrogram a megmért szakaszról, alatta a cím. Minden mix más képet kap, mégis
egy sorozatba illenek.

Valódi borítóképként (featured image) kerül be a médiatárba, `_pl_generated_cover`
jelöléssel, tehát később megkülönböztethető attól, amit kézzel választottál.

**Miért generálás és nem letöltés:** idegen képeket az internetről letölteni és a
saját oldalon közzétenni szerzői jogi jogsértés. A MusicBrainz Cover Art Archive
legális, de csak kiadott albumokhoz van benne kép — egy DJ mix nincs benne. A
generálás jogilag tiszta, és koherensebb is.

## Élő ekvalizér

A kiemelt panel hátterében a sávok a **tényleges hangból** számolódnak a Web Audio
API-val, nem előre gyártott animáció. A szín a sáv magasságából jön, ahogy egy
keverőpult LED-létráján: zöld a kényelmes tartomány, sárga a hangosabb, piros a csúcs.
Egy halk sáv végig zöld marad, ugyanaz a sáv hangos résznél átmegy sárgába és
belenyúl a pirosba.

```css
.plp {
    --plp-eq-low:  #35d07f;
    --plp-eq-mid:  #f5c542;
    --plp-eq-high: #e8453c;
}
```

**Két dolog, ami miatt nem indul el:**

Ha a látogató **csökkentett mozgást** kér (Windowson: Kisegítő lehetőségek → Vizuális
effektusok → Animációs effektusok kikapcsolva), az `equalizer="yes"` tiszteletben
tartja, és nem animál. Az `equalizer="always"` felülírja ezt.

Ha a hangfájl **más domainről jön CORS fejlécek nélkül**, az elemző csendet lát, és a
sávok nem jelennek meg — a hang viszont szól. A bővítmény szándékosan nem kényszeríti
a böngészőt CORS módba, mert az a lejátszást törné el olyan szervereken, amik nem
küldenek ilyen fejlécet. Egy díszítésért nem érdemes feláldozni a hangot.

## Popup lejátszó

Egy sima oldalváltás megszünteti a JavaScript környezetet, és vele az audio elemet is.
Ez a böngésző működése, az oldalon belülről nem kerülhető meg. Ezért van a keresősor
jobb szélén egy **„Külön ablakban"** gomb: megnyílik egy kis ablak, és **ott
folytatja** ugyanazt a számot ugyanattól a másodperctől, az oldali lejátszó pedig
megáll. Onnantól szabadon járkálhatsz az oldalon.

Telefonon a gomb **nincs ott**, mert a mobilböngészők a felugró ablakot vagy blokkolják,
vagy új fülként nyitják — ami ugyanúgy megszakítja a hangot. Ott a lejátszó megjegyzi a
pozíciót, és a következő oldalon egy koppintással folytatja.

Ha a látogató kilép a böngészőből, a hang a **háttérben tovább szól**, és a zárolt
képernyőn látszik a cím meg a borító (MediaSession).

## Kinézet

Minden állítható érték CSS változó, tehát a bővítmény fájljait nem kell szerkeszteni.
Divi-ben a **Téma beállítások → Egyéni CSS** mezőbe, vagy a modul **Egyéni CSS**
fülére.

| Változó | Alap | Mit szabályoz |
|---|---|---|
| `--plp-accent` | `#4a9eff` | Lejátszás gomb, aktív kategória, tekerő sáv |
| `--plp-surface` | áttetsző | Sorok és panelek háttere |
| `--plp-surface-hover` | áttetsző | Sor háttere hover állapotban |
| `--plp-border` | áttetsző | Keretek és elválasztók |
| `--plp-radius` | `10px` | Sarkok lekerekítése |
| `--plp-gap` | `12px` | Elemek közti térköz |
| `--plp-eq-low` / `-mid` / `-high` | zöld / sárga / piros | Az ekvalizér színsávjai |

A `theme="auto"` (alapértelmezés) átveszi az oldal szövegszínét és áttetsző felületeket
használ, tehát világos és sötét szakaszon is működik. A `dark` és `light` fixen
beállítja. A nagy kiemelt panel és az alsó lejátszósáv szándékosan mindig sötét
üveghatású — világos oldalon is így néz ki szándékosnak.

Aminek nincs borítóképe, az kap egy **saját színt a bejegyzés azonosítójából** és a cím
kezdőbetűjét. Ugyanaz a szám mindig ugyanazt a színt kapja, tehát a lista nem egyforma
szürke négyzetek sora lesz.

## Milyen tartalmat játszik

Alapból a bővítmény saját **Zeneszámok** tartalomtípusát. A **Lejátszó →
Beállítások** képernyőn viszont bármelyik másik nyilvános tartalomtípus bevonható —
tipikusan a már meglévő podcast epizódok.

A hangfájlt ilyenkor a bővítmény magától megkeresi, ebben a sorrendben:

1. A bővítmény saját mezői, ha már ki vannak töltve
2. A core `enclosure` meta — ide teszik a podcast bővítmények a fájlt
3. `[audio]` shortcode a bejegyzés tartalmában
4. Bármilyen a bejegyzéshez csatolt hangfájl

Amikor egy bejegyzést először elér, az eredményt **beírja ugyanabba a
mezőstruktúrába**, amit a Zeneszámok használnak, és ha van hozzá médiatár-fájl, az
ID3 adatokat is kiolvassa. Onnantól a bejegyzés pontosan úgy viselkedik, mint egy
Zeneszám. Nincs duplikálás, nincs migráció, a meglévő RSS és SEO linkek érintetlenek
maradnak.

## Hogyan számol

**Lejátszás.** Akkor számít, ha a látogató tényleges lehallgatott egy küszöböt — alapból
15 másodpercet vagy a hossz 30%-át, amelyik hamarabb teljesül. A küszöböt a böngésző
méri, mert a szerver nem látja, mennyi hang jött ki a hangszóróból. Amit a szerver
érvényesít: ugyanaz a látogató ugyanazt a számot a türelmi időn belül (alapból 6 óra)
csak egyszer számolhatja, és percenkénti kérés-limit is van. Így a küszöb a véletlen
átlépkedést szűri ki, a türelmi idő a szándékos felnyomást.

**Kedvelés.** Bejelentkezett felhasználót a saját azonosítója különböztet meg,
vendéget egy véletlen értékű, első feles süti. A `wp_pl_likes` táblán lévő `UNIQUE`
index miatt egy azonosító egy számhoz csak egy sort tud létrehozni — versenyhelyzetben
a második beszúrás elbukik, nem duplán számol.

**Két réteg.** Az összesített számlálók post metában vannak, hogy a listák gyorsan
tudjanak rájuk rendezni. Mellettük a `wp_pl_events` tábla minden eseményt naplóz —
ebből jönnek majd az időbeli kimutatások, amit meta önmagában nem tud.

**Cache-kompatibilitás.** A számlálók nem sülnek bele a HTML-be, hanem külön kéréssel
töltődnek. Így page cache mellett sem fagynak be.

## Adatkezelés

- A vendég-azonosító süti (`pl_vid`) **csak akkor jön létre, amikor valaki tényleg
  kedvel valamit**, nem oldalbetöltéskor. Véletlen érték, személyes adatot nem
  tartalmaz.
- **Nyers IP-cím nem kerül az adatbázisba.** Csak egy rövid életű, sózott hash a
  kérés-limithez, ami a limit ablakának lejártával eltűnik.
- A hallgatási görbe és a hallgatott idő **összesített**: egyetlen tárolt érték sem
  köthető látogatóhoz. A görbe 20 szeletes felbontása szándékosan durva — ennél
  finomabb bontásból már egy-egy munkamenet is kirajzolódhatna.
- Egy **napi ütemezett feladat** (éjjel 3-kor, a site időzónájában) az egy évnél
  régebbi eseményekből kitörli a látogató-azonosítót, két évnél régebben pedig magukat
  a sorokat is, kötegelve. A kimutatások nem változnak, a tábla nem nő a végtelenbe.
- A bővítmény **törlésekor alapértelmezésben minden adat megmarad.** A zenék, a
  kategóriák és a statisztika csak akkor törlődik, ha a Beállításokban ezt kifejezetten
  bekapcsolták. Ilyenkor a három tábla, a bővítmény mezői minden érintett bejegyzésen,
  az ütemezett feladat és a beállítások is eltűnnek.

**A hallgatási mélység új mérés**, ezért érdemes egy sorral megemlíteni az adatkezelési
tájékoztatóban.

## REST API

Névtér: `plplayer/v1`

| Metódus | Végpont | Leírás |
|---|---|---|
| `GET` | `/tracks` | Lista. Paraméterek: `terms`, `playlist`, `post_type`, `search`, `orderby`, `order`, `page`, `per_page` |
| `GET` | `/categories` | Kategóriafa minden bevont taxonómiából |
| `GET` | `/counters?ids=1,2,3` | Számlálók és a látogató kedvelés-állapota |
| `GET` | `/me?ids=1,2,3` | Mit tehet a kérő: be van-e jelentkezve, és a felsoroltak közül melyiket szerkesztheti |
| `POST` | `/tracks/{id}/play` | Lejátszás rögzítése |
| `POST` | `/tracks/{id}/like` | Kedvelés be- és kikapcsolása |
| `POST` | `/tracks/{id}/progress` | Hallgatott másodpercek és a lehallgatott szeletek |
| `POST` | `/tracks/{id}/markers` | A jelölők teljes listájának felülírása. **Nonce + `edit_post` az adott poszton** |
| `GET` | `/tracks/{id}/stats` | Egy szám nyilvános adatai, hallgatási görbével |
| `GET` | `/stats/public` | Nyilvános top listák és forgalmi trend |
| `GET`, `POST` | `/playlists` | Listák, illetve új lista. **Nonce + jogosultság** |
| `POST` | `/playlists/{id}/add` | Szám hozzáadása listához. **Nonce + jogosultság** |

A **statisztikai** író végpontok (`play`, `like`, `progress`) szándékosan nem kérnek
nonce-ot. Page cache mellett a HTML — és a benne kinyomtatott nonce — közös minden
látogatónál és elavul, tehát pont azoknak törné el a kedvelést, akiket védeni
hivatott. Helyette same-origin ellenőrzés és kérés-limit van; a legrosszabb, amit egy
hamisított kérés elérhet, egy kedvelés.

A **tartalmat író** végpontok — `markers`, `playlists` — ellenben nonce-ot **és**
jogosultságot kérnek, a jelölőknél poszt szinten (`edit_post`), nem csak
„be van jelentkezve" szinten. Itt nincs cache-dilemma: aki ír, az bejelentkezett, és
neki a nonce rendelkezésre áll.

A `/me` bárkinek válaszol, mert a válasz vendégnek csupa hamis. Épp ez teszi
biztonságossá gyorsítótárazott frontenden megkérdezni, hogy megjelenjenek-e a
szerkesztő kezelőelemek.

Ha a webszerver blokkolja a `/wp-json/` útvonalat (LiteSpeed és mod_security mellett
gyakori), a frontend magától átvált a `?rest_route=` formára, és a váltást megjegyzi a
munkamenetre.

## Automatikus frissítés

A bővítmény a saját GitHub tárolójából figyeli az új kiadásokat, és a Bővítmények
listán ugyanúgy jelzi őket, mint bármelyik más bővítmény.

**Egyszeri beállítás:** Lejátszó → Beállítások → **GitHub tároló** mezőbe
`felhasznalonev/pl-player` formában, majd mentés.

**Új verzió kiadása:**

1. A `pl-player.php`-ban emeld a `Version:` értéket
2. **Releases → Draft a new release**
3. Tag a verziószám, pl. `v0.5.0` (a `v` elhagyható)
4. Csatolj hozzá egy `pl-player.zip` fájlt — ez a biztosabb út, mert abban a mappanév
   pontosan `pl-player`
5. Publish release

Csatolmány nélkül a bővítmény a GitHub automatikus forrásarchívumát használja, és
menet közben átnevezi a mappát a helyesre.

**Privát tároló** esetén a token a `wp-config.php`-ba kerül, nem az admin felületre:

```php
define( 'PLP_GITHUB_TOKEN', 'ghp_...' );
```

Az adatbázisban tárolt token egy adatbázis-szivárgással együtt kerülne illetéktelen
kézbe — ezért nincs rá beállítási mező.

## Repo felépítése

A tároló gyökere egyben a bővítmény gyökere, tehát a `pl-player.php` a legfelső
szinten van:

```
pl-player.php              belépési pont, verzió, betöltés
uninstall.php              takarítás törléskor (alapból nem töröl adatot)
includes/
  functions.php               segédfüggvények, beállítások
  class-plp-activator.php     adatbázis séma, verziókövetés
  class-plp-post-types.php    poszttípusok és taxonómiák
  class-plp-meta.php          zeneszám mezők, adatlap, ID3
  class-plp-playlist.php      lejátszási listák, track-választó
  class-plp-importer.php      tömeges import, borító-import
  class-plp-source.php        hangforrás felismerés, lekérdezés-építés
  class-plp-visitor.php       látogató-azonosítás, kérés-limit
  class-plp-stats.php         számlálók, események, hallgatási mélység
  class-plp-rest.php          REST végpontok
  class-plp-renderer.php      frontend HTML
  class-plp-shortcode.php     [playlist_player] és [playlist_stats]
  class-plp-popup.php         önálló popup lejátszó oldal
  class-plp-cron.php          napi naplótömörítés
  class-plp-updater.php       GitHub frissítés-figyelő
  class-plp-divi.php          Divi regisztráció és legördülők
  class-plp-divi-module.php   a Divi modul maga
admin/
  class-plp-admin.php         zeneszám lista és adatlap
  class-plp-import-page.php   tömeges import képernyő
  class-plp-settings-page.php beállítások
  class-plp-stats-page.php    statisztika riport és CSV
public/                       frontend CSS és JS
```

## Admin felületek

Minden a bal oldali **Lejátszó** menü alatt:

| Menüpont | Mire jó |
|---|---|
| Összes szám | A zenetár borítóval, előadóval, hosszal, hangfájl állapottal, számlálókkal. Rendezhető oszlopok. Piros jelzés, ha egy számhoz nincs hangfájl. |
| Lejátszási listák | Kézzel összeválogatott listák, fogd-és-vidd sorrenddel |
| Kategóriák · Címkék | Hierarchikus kategóriák és szabad címkézés |
| Tömeges import | Több MP3 egyszerre, ID3 előnézettel, duplikátum-védelemmel |
| Duplikátumok | Mi szerepel többször, három bizonyossági szinten, tömeges lomtárazással |
| Elemzés | BPM, energia, fényesség a hangból; borítógenerálás |
| Statisztika | Összesítő kártyák, napi grafikon, top listák, kategóriabontás, CSV |
| Beállítások | Tartalomtípusok, nyilvános adatok, lejátszás-küszöb, GitHub frissítés |

## Duplikátumok

**Lejátszó → Duplikátumok.** Három bizonyossági szinten csoportosít:

| Szint | Mit jelent |
|---|---|
| **Ugyanaz a hangfájl** | Biztos: két bejegyzés egy felvételre mutat. A tipikus eset, amikor egy podcast epizód és egy zeneszám ugyanabból az MP3-ból készült. |
| **Ugyanaz a cím, más fájl** | Nagyon valószínű, de lehet két különböző szett is egy néven — érdemes ránézni. |
| **Azonos hossz, más cím és fájl** | Csak jelzés. Csak hosszú felvételeknél számol vele, mert rövid klipeknél a másodpercre egyezés gyakran véletlen. |

Minden csoportban megjelöli, melyik példányt javasolja megtartani: a legtöbb
lejátszással rendelkezőt, holtverseny esetén a régebbit — arra mutat valószínűbben
egy meglévő link.

### Tömeges lomtárazás

Jelölőnégyzet minden törölhető soron, és **„Kijelöltek lomtárba"** a táblázatok fölött
és alatt. A **„Csak a biztos egyezések kijelölése"** gomb kizárólag az *Ugyanaz a
hangfájl* csoportokat jelöli ki — a másik két szint találgatás, azt szándékosan nem
nyúlja meg egy kattintás.

Amiért ez biztonságos:

- **A megtartásra javasolt példány nem is kap jelölőnégyzetet**, tehát egy csoport
  elvileg sem üríthető ki teljesen.
- **Lomtár, nem törlés.** A WordPress saját lomtárába kerül, tehát visszaállítható,
  amíg nem ürítesz lomtárat.
- A szerver a beküldött azonosítókat **kérésnek tekinti, nem utasításnak**: újra
  felépíti a jelentést, és csak azt teszi lomtárba, amit az *abban a pillanatban* is
  fölös példányként jelöl, és amire van `delete_post` jogosultságod. Egy régóta nyitva
  hagyott fül vagy egy kézzel átírt kérés így nem ér el tetszőleges bejegyzést.
- A megerősítő kérdés megmondja a darabszámot, és külön kiemeli, ha **nem a bővítmény
  saját zeneszáma** van közte.

### Törlés nélkül is megoldható

Ha a duplikátumok többsége abból fakad, hogy ugyanaz a felvétel egyszer podcast
epizódként, egyszer zeneszámként létezik, a legkisebb kockázatú megoldás **nem** a
törlés: a Beállításokban vedd ki a pipát az egyik tartalomtípusnál. A lejátszó
onnantól csak az egyiket listázza, mindkét bejegyzés megmarad, a podcast RSS és a
meglévő linkek sértetlenek — és a lejátszásszám sem veszik el. A jelentés magától
figyelmeztet, ha ez a helyzet áll fenn.

## Horgok fejlesztőknek

| Név | Típus | Mire jó |
|---|---|---|
| `plp_track_data` | filter | Egy szám frontend adatainak módosítása |
| `plp_player_config` | filter | A frontend script konfigurációja |
| `plp_client_ip` | filter | IP felismerés proxy vagy Cloudflare mögött |
| `plp_allow_origin` | filter | Idegen origin engedélyezése az író végpontokon |
| `plp_github_repo` | filter | A frissítéshez használt tároló felülírása |

## Fejlesztési állapot

Az eredetileg megtervezett funkciók mind elkészültek: zenetár hierarchikus
kategóriákkal · saját lejátszási listák · tömeges import ID3-mal · statisztika motor és
REST API · frontend lejátszó három elrendezésben · kiemelt panelos dizájn · nyilvános
statisztika és hallgatási görbe · popup lejátszó · élő ekvalizér · Divi modul · napi
naplótömörítés · GitHub frissítés.

**Amit nem tudtam éles környezetben tesztelni**, mert nincs hozzá gépem: a **Divi
modul** működése. Minden más darabot méréssel vagy futtatással ellenőriztem.

**Tudatos kihagyások:**

- Nincs fordítási fájl. A bővítmény minden szövege eleve magyar, tehát a `.po` csak
  akkor kellene, ha angolul is kellene.
- A `[playlist_stats]` blokk a szerveren renderelődik, tehát page cache mellett
  néhány óráig állhat elavult adaton. Egy statisztika oldalnál ez jó csere azért, hogy
  ne minden látogató indítson külön kérést.
- A hallgatási mérés kliensoldali, tehát elvben hamisítható. Aggregált, nyilvános
  megjelenítéshez ez elfogadható; pénzben elszámolt jogdíjhoz nem lenne elég.

## Licenc

GPL-2.0-or-later
