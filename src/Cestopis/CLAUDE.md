# Cestopis — text výletu psaný jazykovým modelem

Volitelná funkce GPX Manageru. Vezme jednu trasu, poskládá z ní a z fotek
uzavřený seznam ověřených faktů a nechá jazykový model napsat souvislý text.
Každá verze se uloží do `track_stories`; návštěvník vidí jen zveřejněnou.

Kód: `src/Cestopis/` (namespace `GpxManager\Cestopis`). Příkazová řádka pro
vývoj a zkoušky: `tools/cestopis/bin/cestopis.php`. Vznikl jako samostatný
nástroj od Coworku (9/2026), do aplikace převeden 26. 9. 2026.

> Ve veřejném repozitáři příkazová řádka a testy (`tools/cestopis/`) nejsou — obsahují
> data konkrétního výletu. Zmínky o nich níže platí pro autorův repozitář.

---

## Architektura

Třídy se načítají přes `includes/story_classes.php` — aplikace musí běžet
i bez Composeru (FTP instalace), PSR-4 autoload proto nestačí. Načítat jen
tam, kde se Cestopis používá, ne v `bootstrap.php`.

**`StoryGenerator` je jediný vstup** pro web i příkazovou řádku: `prepare()`
(zastávky, názvy míst, body z mapy — zdarma), `estimate()` (odhad ceny),
`generate()` (placené). Hlášení jdou přes `Log` — na webu neexistuje `STDERR`.

```
track_photos ─┐
              ├─ StopDetector ── zastávky ─┐
tracks ───────┘                            ├─ FactSheet ── Narrator ── track_stories
                             Geocoder ─────┤                (model)
                             PoiFinder ────┘  (mapa OSM)       ▲
                             PhotoPicker ───────────────────────┘  (jen když se zvolí fotky)
```

| Soubor | Role |
|---|---|
| `StoryGenerator.php` | řízení: řádek „running" → fakta → model → done / error, odhad ceny |
| `Repository.php` | všechny SQL dotazy, zámek proti dvojímu spuštění, zveřejnění, útrata za měsíc |
| `Photo.php` | jedna fotka + příznak `coordsReliable` |
| `Geo.php` | haversine, medián bodů |
| `Stop.php` | zastávka (shluk fotek) |
| `StopDetector.php` | shlukování a detekce ustřelených souřadnic |
| `FactSheet.php` | **uzavřený seznam faktů pro model** |
| `Geocoder.php` | Nominatim + cache |
| `PoiFinder.php` | pojmenované body z OpenStreetMap (Overpass) do 150 m od zastávek + cache |
| `PhotoPicker.php` | výběr fotek (none / rest / all), zmenšení na 800 px pro model |
| `Narrator.php` | volání API, ceník modelů (`MODELS`), spotřeba (`lastUsage`) |
| `Renderer.php` | HTML stránka se všemi verzemi a přepínačem (zatím pro příkazovou řádku) |
| `Log.php` | hlášení: CLI → stderr, web → error_log |

## Databáze

```
čte  (nemění)   tracks · track_photos · categories · track_categories
píše            track_stories · story_geocode_cache · story_poi_cache
```

Migrace `migrations/0020_track_stories.sql` (+ `install.sql`). Klíč varianty
v `track_stories`: `style` (factual / narrative / literary), `model`, `with_map`,
`photo_mode` (none / rest / all). Stav: `running` → `done` | `error`; smazaná
verze = `deleted`.

**Útrata se nikdy nemaže.** Neúspěšné volání (odmítnutí, uříznutí) se zapíše
i se spotřebou — zaplatilo se. Smazání verze vymaže text, ale řádek s cenou
zůstane, jinak by šel měsíční strop obejít. `Repository::monthSpend()`.

**Proti placení dvakrát:** řádek `running` vzniká před voláním API pod
`GET_LOCK` na trasu; druhé spuštění pro tutéž trasu se odmítne. Spadlý
proces (running déle než 15 min) se při dalším čtení označí jako chyba.

---

## Co ukázala reálná data

Tohle je nejdůležitější část. Vše níže bylo ověřeno na trase **574**
(Blíževedly, 27. 4. 2025, 14,7 km, 123 fotek) — ta trasa je testovací
fixture a v databázi pořád je.

**1. Fotky chodí v salvách.** Tři snímky během dvaceti sekund na jednom místě
nejsou tři události. Bez shlukování vznikne 123 „zastávek". Po shlukování
jich je 22, z toho 8 delších zastavení.

**2. GPS z telefonu ustřeluje.** V datech je dvojice snímků vzdálená 600 metrů
se čtyřsekundovým odstupem — 540 km/h. Takových nemožných souřadnic je
**9 ze 123**. Detektor je najde a označí `coordsReliable = false`; fotka
zůstane, jen se jí nevěří poloha. Rozhodování, který ze dvou snímků je vadný,
dělá medián okna ±3 snímky — naivní pravidlo „vadný je ten, kde skok začíná"
označí i legitimní bod na začátku delšího přesunu.

**3. Dlouhá pauza se rozpadala na dvě zastávky.** Kdyby o dělení rozhodoval
jen čas, 27minutová pauza mezi 13:31 a 13:58 by se rozpadla — a přitom je to
nejzajímavější událost výletu. Proto se povolený odstup řídí vzdáleností:
do 70 m se toleruje mezera až 40 minut, jinak 6 minut.

**4. Dva zdroje čísel si protiřečí, a je to v pořádku.** GPS přístroj hlásí
`stopped_time` = 47 minut, součet zastávek z časů fotek dá 86 minut. Přístroj
měří podle rychlosti, fotky podle toho, kdy se cvakalo. `FactSheet` na to
model v sekci `pozor_na` výslovně upozorňuje.

---

## Pravidla, která se nesmí porušit

**Výšky z fotek se modelu nedávají. Nikdy.** Telefon je měří barometrem.
U trasy 574 dávají rozsah **359–699 m**, zatímco GPSMAP 64sx hlásí
**301–627 m** — 72 metrů rozdílu nahoře. Uvnitř trasy skáčou o 175 metrů
během sedmdesáti sekund (14:17 → 14:18). Kdyby je model dostal, psal by
o stoupání, které se nestalo, a znělo by to věrohodně. Do `FactSheet`
nepatří ani jako „orientační údaj".

**`FactSheet` je uzavřený seznam, ne podklad k doplňování.** Model dostane
jen to, co v něm je, a systémový prompt mu zakazuje cokoliv dodat — počasí,
výhledy, náladu, obsah fotek. Sloupec `caption` je v datech prázdný, takže
bez `--s-fotkami` o tom, co na snímku je, nevíme nic; s ním model vidí jen
vybrané fotky (seznam je v `prilozene_fotky`). Systémový prompt se podle toho
mění (`Narrator::systemPrompt`). Když se do faktů přidává nové pole,
musí být ověřitelné z databáze, ne odvozené odhadem.

**Fotky do API jen na výslovný přepínač `--s-fotkami`**, u každého spuštění znovu.
Odcházejí k poskytovateli modelu. Souhlas majitele (26. 9. 2026) je **jen pro trasu 574**
(lidé na fotkách nejsou) — u jiné trasy se nejdřív zeptat. Model smí popsat jen to,
co na fotce opravdu je, a jen u její zastávky; nepojmenovává z fotek místa, rostliny
ani zvířata (jména jen z fakt). Každá fotka má v požadavku štítek „Zastávka N (od–do)".

**Body z mapy (`v_okoli`) znamenají „nedaleko", ne „navštívili jsme".** Jen objekty
se jménem, jen vyjmenované druhy (vyhlídka, vrchol, skála, jeskyně, pramen, zřícenina…),
jen k nejbližší zastávce a do 150 m. Overpass je zdarma s přísnými limity: jeden dotaz
na obdélník kolem celé trasy (dotaz `around` přes všechny zastávky končil 504), cache
30 dní, záložní server. Selhání se necachuje a vypíše se jako POZOR.

**Nic se nezveřejňuje samo.** Nová verze je koncept (`is_published = 0`).
Návštěvník ji uvidí až po ručním zveřejnění; veřejná je nejvýš jedna verze
na trasu (`Repository::publish()` ostatní stáhne).

**Ukládá se i to, co model dostal** (`facts_json`). Když text něco tvrdí,
musí jít dohledat, odkud to měl. Tohle pole neodstraňovat.

**Nominatim:** nejvýš jeden dotaz za sekundu a povinný kontakt v User-Agent.
Obojí je ošetřené, výsledky jdou do `story_geocode_cache`. Limit neobcházet.
Do cache jde jen skutečná odpověď služby; výpadek sítě / 429 / 5xx se neukládá
(jinak by zastávka zůstala bez názvu natrvalo).

**Volání modelu (`Narrator`):** text se skládá ze VŠECH bloků typu `text` —
novější modely vrací jako první blok přemýšlení, `content[0]` by byl prázdný.
Před čtením textu se kontroluje `stop_reason`: `max_tokens` (uříznuto),
`refusal` (odmítnuto) i prázdný text skončí chybou a **text se neuloží** —
spotřeba ano (`Narrator::lastUsage` se nastaví hned po odpovědi API).
429 / 5xx / 529 / výpadek sítě se opakuje (max. 4 pokusy, respektuje retry-after).
U `claude-opus-5` je zapnutý server-side fallback (`fallbacks: "default"`).
Ověřeno 25. 9. 2026 proti falešnému API (6 scénářů), bez placených volání.

**Argumenty CLI se čtou ručně, ne přes `getopt()`.** `getopt` přestane číst u prvního
argumentu, který není přepínač — `574 --jen-fakta` by přepínač tiše ignoroval a místo
výpisu faktů zavolal placené API (tak to bylo v původní verzi; odhaleno 25. 9. 2026,
naštěstí bez nastaveného klíče). Neznámý přepínač nebo styl = chyba hned na začátku.

**HTTPS na Windows (WAMP):** PHP tam nemá systémové úložiště certifikátů → Nominatim
i API končí „SSL certificate problem". Řeší parametr `caBundle` (příkazová řádka:
`ca_bundle` v `tools/cestopis/config.php`, web: `CURL_CA_BUNDLE` aplikace). Selhání geokódování se vypíše jako POZOR, není tiché.

**Chybějící údaje trasy zůstávají `null`.** Prázdný `date_start` / `date_end`
nesmí projít do `new DateTimeImmutable('')` (= „teď"), chybějící délka nesmí být
„0 h 00 min". Model by dostal vymyšlená data.

---

## Příkazová řádka (vývoj, zkoušky)

```bash
cd tools/cestopis
cp config.example.php config.php              # přístup k DB a API klíč (gitignored)

php bin/cestopis.php 574 --odhad              # zdarma: odhad ceny + útrata za měsíc
php bin/cestopis.php 574 --jen-fakta          # zdarma: co přesně dostane model
php bin/cestopis.php 574 --jen-html           # zdarma: stránka out/cestopis-574.html ze všech verzí
php bin/cestopis.php 574 --styl=literarni     # PLACENÉ: nová verze (mapa je zapnutá vždy)
php bin/cestopis.php 574 --s-fotkami=delsi    # fotky jen z delších zastavení (23 místo 37)
php bin/cestopis.php 574 --s-fotkami          # fotky ze všech zastávek (37)
php bin/cestopis.php 574 --model=sonnet       # levnější model
php bin/cestopis.php 574 --bez-mapy --bez-geokodovani
```

Styly: `vecny` / `vypraveci` / `literarni` (v databázi factual / narrative / literary).
Stránka z `--jen-html` má přepínač verzí a volbu „vše vedle sebe" (čisté CSS).

Ceník (9/2026, USD za 1M tokenů vstup/výstup) je v `Narrator::MODELS`:
Opus 5 $5/$25, Sonnet 5 $2/$10. Skutečná cena na trase 574 (Opus 5):

| Verze | Varianta | Cena | Čas |
|---|---|---|---|
| #1 | věcný, bez mapy, bez fotek | $0.05 | 13 s |
| #2 | literární + mapa + 37 fotek | $0.34 | 1 min 54 s |
| #3 | literární + mapa, bez fotek | ~$0.07 (6 485 vstup / 1 483 výstup) | 22 s |

Většinu ceny s fotkami netvoří fotky samy (~$0.09), ale přemýšlení modelu, když je
páruje se zastávkami. Vzorec v `StoryGenerator::estimate()` je nastavený podle těchto
čísel; jakmile varianta jednou proběhne, odhad bere průměr její skutečné spotřeby.

**Zjištění z verze #3:** bez fotek si model doplňoval terén z čísel („13 minut na 270 m
vzdušnou čarou — tedy pořádné stoupání", „zpátky dolů", „nespěchalo se"). Pravidlo 4
v promptu to od 26. 9. 2026 výslovně zakazuje.
Cestopis je pro jednotlivé trasy, ne hromadné generování.

## Testy

```bash
php tools/cestopis/tests/run.php      # detekce zastávek na reálných datech trasy 574
php tools/cestopis/tests/facts.php    # co přesně dostane model
```

Běží **bez databáze i bez API klíče** — data jsou v `tests/track-574.txt`.
Při změně `StopDetector` nebo `FactSheet` je spustit vždycky; čísla v tomto
dokumentu jsou jejich očekávaný výstup.

## Bezpečnost

`tools/cestopis/config.php` obsahuje API klíč, je v `.gitignore` a **nesmí se
commitnout**. Složka leží pod webovým kořenem, proto je v `tools/cestopis/`
`.htaccess` s `Require all denied`. Nemazat ani neoslabovat.

**Fotky do API jen se souhlasem majitele trasy** (viz výše) — trvalý souhlas je
jen pro trasu 574.

---

## Stav převodu do aplikace (plán 26. 9. 2026)

- [x] Fáze 1: kód v `src/Cestopis`, migrace 0020, `StoryGenerator`, CLI nad stejným kódem;
      verze #1–#3 převedeny; ověřeno falešným API (úspěch, odmítnutí se spotřebou,
      zámek, zveřejnění, smazání bez ztráty útraty)
- [x] Fáze 2: `story_admin.php?id=` (jen admin, odkaz v detailu se zapnutou funkcí),
      `api/story/*` (estimate, generate, status, publish, delete, cap), `includes/story_helper.php`,
      `css/story.css`, `js/story-admin.js`, 54 klíčů × 8 jazyků, `.env.example`.
      Funkce `story` je ve výchozím stavu VYPNUTÁ (`feature_flags_default_off()`).
      Generování: `generate.php` založí verzi, odpoví a práci dokončí po odeslání
      odpovědi (`story_respond_then_run`: FPM `fastcgi_finish_request`, Apache
      Content-Length + Connection: close; session se zavře, jinak by status čekal).
      Strop: když by útrata + odhad překročily strop → `needs_confirm` → dotaz Ano/Ne.
      Zkoušky bez placení: app_config `story_test_endpoint` = `http://127.0.0.1:PORT/`
      (falešné API; platí JEN pro APP_ENV=local). Ověřeno 26. 9. v prohlížeči: odmítnutí
      se zapíše s cenou, úspěch, dotaz na strop, zveřejnění (jen jedna), vedle sebe,
      smazání bez ztráty útraty, odpověď do 1,5 s i při 8 s běhu API.
- [x] Fáze 3: `story.php?id=` (čtení, nová karta) + `includes/story_view.php`; admin
      náhled libovolné hotové verze `?v=<id>`. Nic nepočítá ani nevolá ven — zastávky,
      místa a body z mapy bere z uloženého `facts_json`, fotky přiřadí podle času.
      Přístup návštěvníka: funkce `story` zapnutá + stránka `story` ve visible_pages
      (Administrace → Konfigurace přístupu, jako Plánovač není v `all_pages()` →
      ve výchozím stavu skrytá) + jen zveřejněná verze. Odkaz v detailu:
      `includes/story_link.php` (lehký dotaz bez tříd; chybějící tabulka = žádný odkaz).
      Patička: upozornění, že text psal model, © OpenStreetMap, „text je česky“.
      Ověřeno 26. 9. curl (admin / návštěvník se stránkou skrytou i povolenou,
      ?v= u návštěvníka ignorováno) a snímky v prohlížeči.
- [ ] Fáze 4: changelog, manuál, instalace (vlastní API klíč), merge do main,
      ikona „Nasadit GPX" (migrace), klíč do `.env` na serveru, public repo
