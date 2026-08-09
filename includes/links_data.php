<?php
declare(strict_types=1);

/**
 * links_data.php — obsah rozcestníku na příbuzné weby.
 *
 * Popisky jsou ZÁMĚRNĚ česky a mimo t(): je jich přes dvacet a překládat je
 * do osmi jazyků by bylo nepoměrné k užitku, protože část odkazovaných
 * nástrojů je stejně jen česká. Přeložené je okolí stránky — nadpisy,
 * kategorie a odznaky.
 *
 * Přidání odkazu = jeden řádek v poli. Nic dalšího se nemusí měnit.
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
                    'url'  => 'https://www.viewgpx.com/dashboard',
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
                    'url'  => 'https://maps.openrouteservice.org/',
                    'desc' => 'Plánovač nad OpenStreetMap s profily pro pěší, kolo i vozík. '
                            . 'Umí izochrony — kam se dostaneš za daný čas.',
                    'badges' => ['free', 'noreg'],
                ],
                [
                    'name' => 'Komoot',
                    'url'  => 'https://www.komoot.com/cs-cz',
                    'desc' => 'Plánovač s doporučeními od komunity a odhadem času podle '
                            . 'kondice. Základ zdarma, offline mapy za peníze.',
                    'badges' => ['cz', 'reg', 'app'],
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
                    'desc' => 'Rozbor trasy a 3D přelet nad terénem. Nástupce zrušené Doaramy, '
                            . 'umí i trasy ze Stravy.',
                    'badges' => ['free'],
                ],
                [
                    'name' => 'Mapdirector',
                    'url'  => 'https://www.mapdirector.com/',
                    'desc' => 'Filmové přelety nad trasou pro sdílení. Pozor, kde končí verze '
                            . 'zdarma — z webu to není zřejmé.',
                    'badges' => [],
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
