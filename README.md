# Lejátszási Lista Player

![Verzió](https://img.shields.io/badge/verzió-1.1.0-blue)
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
| `equalizer` | `yes` | Élő ekvalizér a kiemelt panel hátterében, a tényleges hangból |
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
- A bővítmény **törlésekor alapértelmezésben minden adat megmarad.** A zenék, a
  kategóriák és a statisztika csak akkor törlődik, ha a Beállításokban ezt kifejezetten
  bekapcsolták.

## REST API

Névtér: `plplayer/v1`

| Metódus | Végpont | Leírás |
|---|---|---|
| `GET` | `/tracks` | Lista. Paraméterek: `terms`, `post_type`, `search`, `orderby`, `order`, `page`, `per_page` |
| `GET` | `/categories` | Kategóriafa minden bevont taxonómiából |
| `GET` | `/counters?ids=1,2,3` | Számlálók és a látogató kedvelés-állapota |
| `POST` | `/tracks/{id}/play` | Lejátszás rögzítése |
| `POST` | `/tracks/{id}/like` | Kedvelés be- és kikapcsolása |
| `POST` | `/tracks/{id}/progress` | Hallgatott másodpercek és a lehallgatott szeletek |
| `GET` | `/tracks/{id}/stats` | Egy szám nyilvános adatai, hallgatási görbével |
| `GET` | `/stats/public` | Nyilvános top listák és forgalmi trend |

Az író végpontok szándékosan nem kérnek nonce-ot. Page cache mellett a HTML — és a
benne kinyomtatott nonce — közös minden látogatónál és elavul, tehát pont azoknak
törné el a kedvelést, akiket védeni hivatott. Helyette same-origin ellenőrzés és
kérés-limit van; a legrosszabb, amit egy hamisított kérés elérhet, egy kedvelés.

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
  functions.php            segédfüggvények, beállítások
  class-plp-activator.php  adatbázis séma, verziókövetés
  class-plp-post-types.php poszttípus és taxonómiák
  class-plp-meta.php       zeneszám mezők, adatlap, ID3
  class-plp-importer.php   tömeges import, borító-import
  class-plp-source.php     hangforrás felismerés, lekérdezés-építés
  class-plp-visitor.php    látogató-azonosítás, kérés-limit
  class-plp-stats.php      számlálók, események, kedvelések
  class-plp-rest.php       REST végpontok
  class-plp-renderer.php   frontend HTML
  class-plp-shortcode.php  [playlist_player]
  class-plp-updater.php    GitHub frissítés-figyelő
admin/                     admin képernyők és eszközeik
public/                    frontend CSS és JS
```

## Horgok fejlesztőknek

| Név | Típus | Mire jó |
|---|---|---|
| `plp_track_data` | filter | Egy szám frontend adatainak módosítása |
| `plp_player_config` | filter | A frontend script konfigurációja |
| `plp_client_ip` | filter | IP felismerés proxy vagy Cloudflare mögött |
| `plp_allow_origin` | filter | Idegen origin engedélyezése az író végpontokon |
| `plp_github_repo` | filter | A frissítéshez használt tároló felülírása |

## Fejlesztési állapot

Elkészült: alapok · tömeges import · statisztika motor és REST API · frontend
lejátszó · kiemelt panelos dizájn · GitHub frissítés

Hátra van: saját Divi modul, hogy shortcode helyett kattintva legyen állítható ·
admin statisztika képernyő grafikonokkal és CSV exporttal

## Licenc

GPL-2.0-or-later
