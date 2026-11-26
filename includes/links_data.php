<?php
declare(strict_types=1);

/**
 * links_data.php — obsah rozcestníku na příbuzné weby.
 *
 * Popisky jsou tady česky (zdroj). Překlady do ostatních jazyků jsou
 * v includes/links_desc/<jazyk>.php podle názvu odkazu (od 26. 11. 2026).
 * Okolí stránky — nadpisy, kategorie a odznaky — jde přes t().
 *
 * Přidání odkazu = položka v poli níže + popis ve všech includes/links_desc/*.php
 * (jinak test LinksDescTest selže a v daném jazyce zůstane popis česky).
 *
 * Odznaky (badge): 'cz' česky | 'free' zdarma | 'reg' vyžaduje registraci
 *                  | 'noreg' bez registrace | 'app' i mobilní aplikace
 *                  | 'local' zpracuje soubor v prohlížeči, nikam ho neposílá
 *
 * Poznámka k soukromí: schválně se nenačítají ikonky (favicony) z cizích
 * domén. Vypadalo by to hezky, ale každé zobrazení stránky by ohlásilo
 * návštěvu dvaceti cizím serverům, což by šlo proti tomu, proč web
 * nechceme ve vyhledávačích.
 */

/**
 * @return array<int, array{title:string, items:array<int, array{name:string, url:string, desc:string, badges:array<int,string>}>}>
 */
function links_catalog(): array
{
    $catalog = links_catalog_cs();
    $lang = function_exists('app_lang') ? app_lang() : 'cs';
    $file = __DIR__ . '/links_desc/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
    if ($lang === 'cs' || !is_file($file)) {
        return $catalog;
    }
    // Translated descriptions, keyed by the link name; a missing one stays Czech
    $desc = (array) require $file;
    foreach ($catalog as &$cat) {
        foreach ($cat['items'] as &$item) {
            if (isset($desc[$item['name']]) && is_string($desc[$item['name']])) {
                $item['desc'] = $desc[$item['name']];
            }
        }
        unset($item);
    }
    unset($cat);
    return $catalog;
}

/**
 * The catalogue with Czech descriptions (the source; other languages are in
 * includes/links_desc/<lang>.php, keyed by 'name' — tests/unit/LinksDescTest.php
 * checks that every language has exactly the same names).
 *
 * @return array<int, array{title:string, icon:string, items:array<int, array{name:string, url:string, desc:string, badges:array<int,string>}>}>
 */
function links_catalog_cs(): array
{
    return [
        [
            'title' => t('links_cat_viewers', 'Prohlížeče a editory GPX'),
            'icon'  => 'map',
            'items' => [
                [
                    'name' => 'gpx.studio',
                    'url'  => 'https://gpx.studio/cs',
                    'desc' => 'Asi nejlepší online editor GPX. Umí posouvat body, spojovat '
                            . 'a dělit trasy, přepočítat výšky i dorouteovat chybějící úsek. Česky.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'GPX mapy',
                    'url'  => 'https://gpxmapy.cz/',
                    'desc' => 'Český prohlížeč a editor. Bez reklam, bez cookies, bez registrace — '
                            . 'nahraješ soubor a hned vidíš trasu i výškový profil.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'GPS Visualizer',
                    'url'  => 'https://www.gpsvisualizer.com/map_input',
                    'desc' => 'Veterán mezi nástroji a pořád jeden z nejmocnějších. Převádí mezi '
                            . 'formáty, kreslí profily, umí dávkové zpracování. Vzhled z roku 2005, '
                            . 'ale funguje na všechno.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'MyGPSFiles',
                    'url'  => 'https://www.mygpsfiles.com/app/',
                    'desc' => 'Přehledné zobrazení více tras najednou s porovnáním rychlosti '
                            . 'a převýšení. Dobré, když chceš vidět dvě varianty vedle sebe.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'ViewGPX',
                    'url'  => 'https://www.viewgpx.com/',
                    'desc' => 'Prohlížeč se sdílením — vygeneruje odkaz na trasu, který můžeš '
                            . 'poslat dál. Hodí se, když nechceš posílat samotný soubor.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'Garmin GPX viewer (msimons.nl)',
                    'url'  => 'https://www.msimons.nl/tools/gpx_viewer/index.php',
                    'desc' => 'Jednoduchý prohlížeč zaměřený na soubory z Garminu. Bez cavyků, '
                            . 'rychle ukáže, co v souboru je.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'GlandNav GPX Viewer',
                    'url'  => 'https://glandnav.com/tools/gpx-viewer',
                    'desc' => 'Další rychlý prohlížeč v prohlížeči — nahraj a koukej. '
                            . 'Užitečné jako druhý názor, když se ti něco nezdá.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'GPXSee',
                    'url'  => 'https://www.gpxsee.org/',
                    'desc' => 'Rychlý otevřený prohlížeč do počítače (Windows, Mac, Linux). Kromě GPX '
                            . 'otevře i FIT a TCX, ukáže výšku, rychlost i tep a umí víc tras naráz.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'Mappr GPX Viewer',
                    'url'  => 'https://www.mappr.co/gpx-viewer/',
                    'desc' => 'Zpracuje soubor přímo v prohlížeči — nikam se nenahrává. '
                            . 'Ukáže trasu, výškový profil, vzdálenost a převýšení.',
                    'badges' => ['free', 'noreg', 'local'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_planning', 'Plánování tras'),
            'icon'  => 'signpost',
            'items' => [
                [
                    'name' => 'Mapy.com',
                    'url'  => 'https://mapy.com/',
                    'desc' => 'Bývalé Mapy.cz. Pro české a slovenské hory nejlepší podklad, '
                            . 'turistické značení včetně. Umí import i export GPX.',
                    'badges' => ['cz', 'free', 'app'],
                ],
                [
                    'name' => 'BRouter web',
                    'url'  => 'https://brouter.de/brouter-web/',
                    'desc' => 'Routovací nástroj pro náročné — dá se u něj nastavit, čemu se '
                            . 'trasa má vyhýbat a co naopak preferovat. Oblíbený u cyklistů.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'openrouteservice',
                    'url'  => 'https://routing.heigit.org/ors/',
                    'desc' => 'Plánovač nad OpenStreetMap s profily pro pěší, kolo i vozík. '
                            . 'Umí izochrony — kam se dostaneš za daný čas.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Komoot',
                    'url'  => 'https://www.komoot.com/cs-cz',
                    'desc' => 'Plánovač s doporučeními od komunity a odhadem času podle '
                            . 'kondice. Zdarma jen jeden region, jinak předplatné (od roku 2025, '
                            . 'kdy Komoot koupila firma Bending Spoons).',
                    'badges' => ['cz', 'reg', 'app'],
                ],
                [
                    'name' => 'IDOS',
                    'url'  => 'https://idos.cz/',
                    'desc' => 'Jízdní řády vlaků a autobusů. Když trasa nezačíná u domu, '
                            . 'je tohle druhá polovina plánování.',
                    'badges' => ['cz', 'free', 'app'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_maps', 'Mapy a značené trasy'),
            'icon'  => 'layers',
            'items' => [
                [
                    'name' => 'Waymarked Trails — Hiking',
                    'url'  => 'https://hiking.waymarkedtrails.org/',
                    'desc' => 'Mapa všech značených turistických tras z OpenStreetMap. '
                            . 'Tytéž vrstvy používá i tenhle web v mapách.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'OpenTopoMap',
                    'url'  => 'https://opentopomap.org/',
                    'desc' => 'Topografická mapa s vrstevnicemi a stínovaným reliéfem. '
                            . 'Dobrá, když chceš vidět terén, ne jen cesty.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'CyclOSM',
                    'url'  => 'https://www.cyclosm.org/',
                    'desc' => 'Mapový styl pro kolo — zdůrazňuje povrch cest, stoupání '
                            . 'a cyklotrasy.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Cykloatlas',
                    'url'  => 'https://www.cykloserver.cz/cykloatlas/',
                    'desc' => 'Český cykloatlas s cyklotrasami a jejich značením. '
                            . 'Pro plánování na kole doma nejpodrobnější.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Klub českých turistů',
                    'url'  => 'https://kct.cz/',
                    'desc' => 'Ti, kdo to značení v terénu opravdu udržují — přes 135 let. '
                            . 'Popis systému značek, mapová edice, síť turistických chat.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Trailforks',
                    'url'  => 'https://www.trailforks.com/',
                    'desc' => 'Největší databáze stezek na světě, s obtížností a stavem povrchu. '
                            . 'Těžiště má u horských kol, ale hodí se i pěšímu.',
                    'badges' => ['free', 'app'],
                ],
                [
                    'name' => 'OpenStreetMap',
                    'url'  => 'https://www.openstreetmap.org/',
                    'desc' => 'Pramen, ze kterého čerpá skoro všechno ostatní na této stránce '
                            . '— tenhle web nevyjímaje. Když někde chybí cesta, opravit ji jde tady.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'Waymarked Trails — Cycling',
                    'url'  => 'https://cycling.waymarkedtrails.org/',
                    'desc' => 'Sesterská mapa k turistické: značené cyklotrasy z OpenStreetMap, '
                            . 'od dálkových po místní.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Waymarked Trails — MTB',
                    'url'  => 'https://mtb.waymarkedtrails.org/',
                    'desc' => 'Totéž pro singletracky a horská kola, s vyznačenou obtížností.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Staré mapy (Geolab)',
                    'url'  => 'https://geolab.cz/stare-mapy',
                    'desc' => 'Historické mapy českých zemí od vojenských mapování po císařské '
                            . 'otisky, s porovnáním s dneškem. Kudy vedla cesta před sto lety.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'Geoportál ČÚZK',
                    'url'  => 'https://geoportal.cuzk.gov.cz/',
                    'desc' => 'Státní zdroj leteckých snímků, katastru a reliéfu — odtud bere '
                            . 'své vrstvy i tenhle web. Archivní ortofota ukážou, jak místo vypadalo dřív.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'Freemap Slovakia',
                    'url'  => 'https://www.freemap.sk/',
                    'desc' => 'Slovenská outdoorová mapa s plánováním a exportem GPX. '
                            . 'Pro Malou i Velkou Fatru lepší podklad než obecné mapy.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Mapillary',
                    'url'  => 'https://www.mapillary.com/',
                    'desc' => 'Fotky z úrovně cesty, nasnímané dobrovolníky. Užitečné k ověření, '
                            . 'jestli je „cesta" na mapě opravdu cesta. Vrstvu z Mapillary má '
                            . 'i tenhle web přímo v mapách.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'uMap',
                    'url'  => 'https://umap.openstreetmap.fr/',
                    'desc' => 'Vlastní mapa na pár kliknutí — přidáš body, čáry, vlastní popisky '
                            . 'a sdílíš odkazem. Hodí se na mapu k jedné konkrétní akci.',
                    'badges' => ['free', 'noreg'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_device', 'Do přístroje a offline'),
            'icon'  => 'download',
            'items' => [
                [
                    'name' => 'OpenTopoMap pro Garmin',
                    'url'  => 'https://garmin.opentopomap.org/',
                    'desc' => 'Hotové topografické mapy pro Garmin, generované z OpenStreetMap. '
                            . 'Zdarma a pravidelně aktualizované — do navigace se nahrají jako soubor.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'BBBike extracts',
                    'url'  => 'https://extract.bbbike.org/',
                    'desc' => 'Vyřízneš si z OpenStreetMap libovolný kus světa a stáhneš ho '
                            . 'v požadovaném formátu, včetně garminského. Hodí se na cesty do zahraničí.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Garmin BaseCamp',
                    'url'  => 'https://www.garmin.com/cs-CZ/software/basecamp/',
                    'desc' => 'Program do počítače pro správu tras a bodů v Garminu. Už se '
                            . 'nevyvíjí a na nových Macích nemusí běžet, ve Windows je pro přenos '
                            . 'dat do přístroje pořád nejjistější cesta.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Locus Map',
                    'url'  => 'https://www.locusmap.app/',
                    'desc' => 'Česká outdoorová navigace do mobilu s offline mapami a záznamem '
                            . 'trasy. Umí importovat i exportovat GPX.',
                    'badges' => ['cz', 'app'],
                ],
                [
                    'name' => 'OsmAnd',
                    'url'  => 'https://osmand.net/',
                    'desc' => 'Otevřená navigace s offline mapami z OpenStreetMap. '
                            . 'Dobrá záloha pro případ, že v terénu není signál.',
                    'badges' => ['free', 'app'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_stats', 'Statistiky a průzkum území'),
            'icon'  => 'trending-up',
            'items' => [
                [
                    'name' => 'StatsHunters',
                    'url'  => 'https://www.statshunters.com/',
                    'desc' => 'Rozdělí mapu na čtverce a ukáže, které jsi už projel nebo prošel. '
                            . 'Nenápadně návyková hra, která tě začne posílat do neznámých míst.',
                    'badges' => ['free', 'reg'],
                ],
                [
                    'name' => 'Wandrer.earth',
                    'url'  => 'https://wandrer.earth/',
                    'desc' => 'Počítá, kolik procent cest ve tvém okolí jsi už prošel. '
                            . 'Jiný pohled na totéž — místo čtverců sleduje konkrétní cesty.',
                    'badges' => ['free', 'reg'],
                ],
                [
                    'name' => 'VeloViewer',
                    'url'  => 'https://veloviewer.com/',
                    'desc' => 'Podrobné rozbory aktivit ze Stravy, žebříčky a mapy pokrytí. '
                            . 'Původně pro cyklisty, ale zvládne i chůzi.',
                    'badges' => ['reg'],
                ],
                [
                    'name' => 'Squadrats',
                    'url'  => 'https://squadrats.com/',
                    'desc' => 'Čtverce jako u StatsHunters, jen s rychlejší mapou a doplňkem '
                            . 'pro plánování tras přes neprozkoumaná místa. Dvě velikosti čtverců.',
                    'badges' => ['free', 'reg'],
                ],
                [
                    'name' => 'CityStrides',
                    'url'  => 'https://citystrides.com/',
                    'desc' => 'Počítá prošlé ulice ve městech a obcích. Podobné jako Wandrer, '
                            . 'jen s důrazem na „mám tu obec celou“.',
                    'badges' => ['free', 'reg'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_replay', 'Animace a 3D přelety'),
            'icon'  => 'play-circle',
            'items' => [
                [
                    'name' => 'GPX Replay',
                    'url'  => 'https://gpx.pelmers.com/',
                    'desc' => 'Udělá z trasy animovanou mapu, kterou si pustíš nebo vyexportuješ '
                            . 'do videa. Nemá serverovou část — soubor tvůj počítač neopustí.',
                    'badges' => ['free', 'noreg', 'local'],
                ],
                [
                    'name' => 'Trackalyzer',
                    'url'  => 'https://trackalyzer.trackthisout.com/',
                    'desc' => 'Rozbor trasy a 3D přelet nad terénem, umí i trasy ze Stravy.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'Mapdirector',
                    'url'  => 'https://www.mapdirector.com/',
                    'desc' => 'Filmové přelety nad trasou pro sdílení. Pozor, kde končí verze '
                            . 'zdarma — z webu to není zřejmé.',
                    'badges' => [],
                ],
                [
                    'name' => 'Relive',
                    'url'  => 'https://www.relive.com/',
                    'desc' => 'Nejznámější 3D video z výletu — přelet nad trasou i s fotkami. '
                            . 'Základ zdarma, delší a kvalitnější videa za předplatné.',
                    'badges' => ['reg', 'app'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_weather', 'Počasí a podmínky'),
            'icon'  => 'cloud-sun',
            'items' => [
                [
                    'name' => 'Windy',
                    'url'  => 'https://www.windy.com/',
                    'desc' => 'Nejlepší vizualizace počasí na webu — vrstvy pro vítr, srážky, '
                            . 'oblačnost i mlhu. K plánování výšlapu praktičtější než běžná předpověď.',
                    'badges' => ['cz', 'free', 'app'],
                ],
                [
                    'name' => 'Horská služba ČR',
                    'url'  => 'https://www.horskasluzba.cz/',
                    'desc' => 'Aktuální podmínky na horách, výstrahy a lavinová situace. '
                            . 'Než vyrazíš do vyšších poloh, stojí za dvě minuty.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'bergfex',
                    'url'  => 'https://www.bergfex.cz/',
                    'desc' => 'Počasí, webkamery a túry, těžiště v Alpách a v Rakousku. '
                            . 'Webkamery se hodí ke zjištění, jak to na místě opravdu vypadá.',
                    'badges' => ['cz', 'free', 'app'],
                ],
                [
                    'name' => 'ČHMÚ',
                    'url'  => 'https://www.chmi.cz/',
                    'desc' => 'Oficiální předpověď, radar a výstrahy Českého hydrometeorologického '
                            . 'ústavu. Když je potřeba vědět, jestli varování opravdu platí i pro tvůj kraj.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_community', 'Deníky a komunita'),
            'icon'  => 'users',
            'items' => [
                [
                    'name' => 'Strava',
                    'url'  => 'https://www.strava.com/',
                    'desc' => 'Nejrozšířenější deník aktivit. Zajímavá je hlavně globální '
                            . 'heatmapa — ukáže, kudy lidé opravdu chodí, i tam, kde mapa cestu nemá.',
                    'badges' => ['cz', 'reg', 'app'],
                ],
                [
                    'name' => 'Turistika.cz',
                    'url'  => 'https://www.turistika.cz/',
                    'desc' => 'Český portál s tipy na výlety, popisy tras a fotkami od lidí. '
                            . 'Dobrý zdroj nápadů, kam příště.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Wikiloc',
                    'url'  => 'https://www.wikiloc.com/',
                    'desc' => 'Obrovská sbírka tras od lidí z celého světa, ke stažení jako GPX. '
                            . 'Když jedeš někam, kde jsi nikdy nebyl, tady najdeš, kudy chodí ostatní.',
                    'badges' => ['cz', 'free', 'app'],
                ],
                [
                    'name' => 'AllTrails',
                    'url'  => 'https://www.alltrails.com/',
                    'desc' => 'Největší světová databáze tras s hodnocením a recenzemi od lidí. '
                            . 'GPX jde stáhnout po registraci.',
                    'badges' => ['free', 'app'],
                ],
                [
                    'name' => 'Hikr',
                    'url'  => 'https://www.hikr.org/',
                    'desc' => 'Podrobné túrové zprávy s fotkami, hlavně z Alp. Když chceš vědět, '
                            . 'jak je na tom konkrétní hřeben teď, ne před deseti lety.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'Treking.cz',
                    'url'  => 'https://www.treking.cz/',
                    'desc' => 'Outdoorový magazín s články o horách, popisy hřebenovek '
                            . 'a praktickými tipy. Čtení na večer před výletem.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Hrady.cz',
                    'url'  => 'https://www.hrady.cz/',
                    'desc' => 'Databáze hradů, zámků a tvrzí s otevírací dobou a polohou. '
                            . 'Praktické, když se má výšlap o něco opřít.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Geocaching',
                    'url'  => 'https://www.geocaching.com/',
                    'desc' => 'Hledání schránek podle souřadnic. Umí z obyčejné procházky '
                            . 'udělat výpravu a zavede tě na místa, kam bys jinak nezašel.',
                    'badges' => ['cz', 'reg', 'app'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_trips', 'Na výlet v Česku'),
            'icon'  => 'flag',
            'items' => [
                [
                    'name' => 'eStudánky',
                    'url'  => 'https://www.estudanky.eu/',
                    'desc' => 'Národní registr pramenů a studánek s mapou a fotkami. '
                            . 'Kde se dá cestou napít — a jestli voda ještě teče.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'Turistické známky',
                    'url'  => 'https://turisticke-znamky.cz/',
                    'desc' => 'Dřevěné známky z výletních míst a deník, kam si je zapisuješ. '
                            . 'Mapa míst se známkou je i dobrý seznam tipů na výlet.',
                    'badges' => ['cz', 'free'],
                ],
                [
                    'name' => 'Rozhledny',
                    'url'  => 'https://rozhledny.webzdarma.cz/',
                    'desc' => 'Dlouholetá databáze českých rozhleden s fotkami a otevírací dobou. '
                            . 'Vzhled starší, obsah poctivý.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
                [
                    'name' => 'Kudy z nudy',
                    'url'  => 'https://www.kudyznudy.cz/',
                    'desc' => 'Tipy na výlety a akce po celém Česku od agentury CzechTourism. '
                            . 'Hodí se, když chceš výšlap spojit s něčím k vidění.',
                    'badges' => ['cz', 'free', 'noreg'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_peaks', 'Hory a panorama'),
            'icon'  => 'mountain',
            'items' => [
                [
                    'name' => 'PeakFinder',
                    'url'  => 'https://www.peakfinder.com/',
                    'desc' => 'Pojmenuje kopce na obzoru — zadáš místo a on nakreslí panorama '
                            . 's názvy vrcholů. Skvělé k dohledání, co je na fotce z výšlapu.',
                    'badges' => ['free', 'app'],
                ],
                [
                    'name' => 'PeakVisor',
                    'url'  => 'https://peakvisor.com/',
                    'desc' => 'Totéž ve 3D, navíc s profily pohoří a přehledem tras. '
                            . 'Hezký doplněk k fotkám z hřebenů.',
                    'badges' => ['free', 'app'],
                ],
            ],
        ],
        [
            'title' => t('links_cat_tools', 'Převody a nástroje'),
            'icon'  => 'wrench',
            'items' => [
                [
                    'name' => 'GPSBabel',
                    'url'  => 'https://www.gpsbabel.org/',
                    'desc' => 'Program do počítače, který převede kdeco na cokoli — desítky '
                            . 'formátů, filtry, komunikace s přístroji. Když si online nástroj neporadí.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'MyGeodata Converter',
                    'url'  => 'https://mygeodata.cloud/',
                    'desc' => 'Převody mezi mapovými formáty rovnou v prohlížeči — GPX, KML, '
                            . 'shapefile a další. Zdarma do určitého objemu dat.',
                    'badges' => ['free'],
                ],
            ],
        ],
    ];
}

/**
 * Popisky odznaků. Krátké, ať se vejdou vedle názvu.
 *
 * @return array<string, string>
 */
function links_badge_labels(): array
{
    return [
        'cz'    => t('links_badge_cz',    'česky'),
        'free'  => t('links_badge_free',  'zdarma'),
        'noreg' => t('links_badge_noreg', 'bez registrace'),
        'reg'   => t('links_badge_reg',   'registrace'),
        'app'   => t('links_badge_app',   'i mobilní aplikace'),
        'local' => t('links_badge_local', 'soubor se nikam nenahrává'),
    ];
}
