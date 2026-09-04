<?php
/**
 * Layout header (redesign — Outdoor / Forest theme)
 * Načítá Tailwind app.css, Google Fonts, dark mode init, sticky header s navigací.
 *
 * Usage: před výpisem obsahu stránky volej `require __DIR__ . '/includes/layout_header.php';`
 * Variables očekávané: $page_title (string, optional), $is_admin (bool, optional)
 */

if (!isset($_SESSION)) { @session_start(); }

$pageTitle = isset($page_title) ? $page_title : 'GPX Manager';
$isAdmin = isset($is_admin) ? $is_admin : (!empty($_SESSION['is_admin']));

// Detekce aktivní stránky pro nav highlighting
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');

// Bezpečný t() fallback (kdyby helpers.php nebyl loadnut)
if (!function_exists('t')) {
    function t($k, $default = null) { return $default ?? $k; }
}
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(function_exists('app_lang') ? app_lang() : 'cs') ?>" class="" x-data="{ dark: localStorage.getItem('gpx-theme') === 'dark' || (!localStorage.getItem('gpx-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" x-init="$watch('dark', v => { document.documentElement.classList.toggle('dark', v); localStorage.setItem('gpx-theme', v ? 'dark' : 'light'); }); document.documentElement.classList.toggle('dark', dark);">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — GPX Manager</title>

    <?php // Místo pro značky konkrétní stránky (Open Graph na detailu trasy).
          // Obsah si escapuje ten, kdo proměnnou plní. ?>
    <?= $page_head_extra ?? '' ?>

    <!-- Inline blok: nastav dark mode PŘED parsováním <body> — eliminuje FOUC (FE-6) -->
    <script>
        (function () {
            // Tailwind dark mode (gpx-theme klíč) — světlý/tmavý přepínač v hlavičce
            var t = localStorage.getItem('gpx-theme');
            if (t === 'dark' || (!t && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <!-- Google Fonts: Manrope (display), Inter (body), JetBrains Mono (numerické) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Manrope:wght@600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">

    <!-- Tailwind generated CSS -->
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">

    <!-- x-cloak: skryje Alpine elementy před inicializací (jinak jsou vidět při načtení stránky) -->
    <style>[x-cloak] { display: none !important; }</style>

    <!-- Nativní prvky (select a jeho rozbalený seznam, inputy, scrollbary) kreslí prohlížeč,
         ne Tailwind. Bez color-scheme je kreslí vždy světle — a text, který podědí barvu
         z tmavého motivu, na nich zmizí. Opakovaný zdroj „světlý text na světlém pozadí". -->
    <!-- Výjimka: uvnitř mapy má Leaflet vždy světlé pozadí (ovládání vrstev, bubliny),
         takže tam musí zůstat i světlá zaškrtávátka — jinak by se invertovala. -->
    <style>:root { color-scheme: light; } html.dark { color-scheme: dark; } .leaflet-container { color-scheme: light; }</style>

    <?php
    /* Výška map — JEDINÉ místo, kde se řídí velikost mapy v celé aplikaci.
       Nastavuje se v Administraci (app_config 'map_height'); stránky, kde je
       mapa hlavní obsah, mohou dostat celou výšku okna.

       Pravidlo je schválně tady a NEVRSTVENĚ: Tailwind utility jsou v @layer
       a nevrstvené pravidlo je porazí bez ohledu na pořadí (viz CLAUDE.md).
       Dřív se o výšku přetahovalo šest pravidel v pěti souborech a vyhrávalo
       jedno s !important, takže většina z nich byla mrtvá. */
    $_mapFull = in_array($currentScript, map_first_pages(), true)
                && get_app_config('map_pages_full', true);
    [$_mapH, $_mapMin] = map_height_css($_mapFull);
    ?>
    <style>
        :root { --gpx-map-h: <?= $_mapH ?>; --gpx-map-min: <?= $_mapMin ?>; }
        #map { height: var(--gpx-map-h); min-height: var(--gpx-map-min); }
        /* Na telefonu nesmí ani volba „vysoká" sníst celou obrazovku */
        @media (max-width: 640px) {
            #map { height: min(var(--gpx-map-h), 60vh); min-height: 280px; }
        }
    </style>

    <?php
    /* Vrstvy map: co je vypnuté a v jakém pořadí se má nabízet.
       Čte to js/lib/map-factory.js — jedno místo pro všech devět map. */
    $_layerCfg = [
        'off'   => array_values(array_map(
            static fn($k) => map_layer_defs()[$k]['label'],
            array_filter((array)get_app_config('map_layers_off', []),
                         static fn($k) => isset(map_layer_defs()[$k])))),
        'order' => [
            'base'    => array_map(static fn($k) => map_layer_defs()[$k]['label'], map_layer_order('base')),
            'overlay' => array_map(static fn($k) => map_layer_defs()[$k]['label'], map_layer_order('overlay')),
        ],
    ];
    ?>
    <script>window.gpxMapLayers = <?= js_safe_json($_layerCfg) ?>;</script>

    <!-- Admin/návštěvnický banner i hlavička jsou sticky na top:0. Banner má
         z-index 9999, hlavička 40 — po odrolování proto banner hlavičku překryl
         a z menu zbyl jen spodní proužek. Hlavička se proto lepí AŽ POD banner;
         jeho výšku (mění se zalomením na úzkých displejích) měří skript níže. -->
    <style>header.site-header { top: var(--gpx-banner-h, 0px); }

    /* ===== Bezpečné náhrady Tailwindího `hidden md:*` =====
       Tailwind v4 dává utility do @layer utilities. Podle specifikace CSS ale
       NEVRSTVENÉ pravidlo porazí jakékoli vrstvené — bez ohledu na pořadí a bez
       !important. Stačí tedy, aby si rozšíření prohlížeče vložilo do stránky
       obyčejné `.hidden{display:none}` (dělá to řada z nich kvůli vlastnímu UI),
       a každý náš prvek s `hidden md:flex` zmizí i na širokém displeji.
       Přesně to se stalo 27.7.2026 s horním menu.

       Proto: kde viditelnost závisí ČISTĚ na CSS, používej tyto třídy.
       Jsou nevrstvené a mají vlastní jmenný prostor, takže je nic nepřebije.
       (Tam, kde třídu `hidden` přidává a odebírá JavaScript, problém nenastává —
       po odebrání třídy se cizí pravidlo nemá čeho chytit.)
       Breakpointy odpovídají Tailwindu: sm = 40rem, md = 48rem. */
    /* ===== Horní menu: ikona nad drobným popiskem =====
       Devět položek vedle sebe s textem za ikonou potřebovalo 1122 px, ale
       hlavička jich nabízí jen ~947 — proto působilo natěsnaně. Ve svislém
       uspořádání šířku určuje popisek, ne ikona s textem, a menu se vejde
       do 752 px. Položka měří 58 px, hlavička má 64, takže se nezvyšuje.
       Rozměry vybrané v menu_demo.php (varianta F2). */
    .gpx-topnav {
        align-items: center;
        gap: 8px;
        margin-left: auto;      /* obě auto marginy = menu je na středu */
        margin-right: auto;     /* volné plochy mezi značkou a ovládáním */
    }
    .gpx-topnav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
        padding: 5px 6px;
        border-radius: 6px;
        line-height: 1.15;
        text-align: center;
        white-space: nowrap;
    }
    .gpx-topnav-item svg  { width: 30px; height: 30px; }
    .gpx-topnav-item span { font-size: 12px; }

    /* ===== Barvy hlavičky si řídíme sami =====
       Stránky, které vedle Tailwindu načítají i legacy css/style.css
       (Detailní tabulka, Trasy v okolí, Virtuální trasy, Fotky v okolí,
       Foto-heatmapa, Plánovač, Administrace a pár dalších), v něm mají
       nevrstvené `a { color: var(--accent-color) }`. Nevrstvené pravidlo
       porazí Tailwind utility v @layer bez ohledu na specificitu i pořadí,
       takže celá hlavička na nich vycházela modrá — značka, menu i ovládání.
       Barvy proto určujeme tady, taky nevrstveně. Pozadí (aktivní položka,
       hover) legacy CSS neřeší, ta zůstávají na Tailwindu. */
    .site-header a,
    .site-header button,
    #mobile-nav-drawer a       { color: var(--color-forest-700); }
    html.dark .site-header a,
    html.dark .site-header button,
    html.dark #mobile-nav-drawer a { color: var(--color-sand-100); }
    .site-header a:hover       { text-decoration: none; }   /* legacy podtrhává */
    .gpx-brand:hover           { color: var(--color-terracotta-500); }

    /* Totéž platí pro PÍSMO. Legacy stylopisy mají nevrstvené
       `body { font-family: Arial }` a to přebije Tailwindí font-[Inter] na
       body — položky menu ho pak dědí a vykreslují se v Arialu. Ten je při
       stejné velikosti užší (12px „Virtuální trasy" měří 73,1 px proti
       78,2 px v Interu), takže menu na těch stránkách vycházelo o ~28 px
       užší a opticky drobnější. Značka si Manrope drží sama, protože ho má
       vlastní třídou — přímé pravidlo porazí dědičnost bez ohledu na vrstvy. */
    .site-header,
    #mobile-nav-drawer         { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif; }
    .gpx-topnav-item           { opacity: .8; }
    .gpx-topnav-item:hover,
    .gpx-topnav-item[aria-current] { opacity: 1; }

    /* Admin menu v hlavičce — nevrstveně ze stejného důvodu jako menu výše.
       Geometrie je tady, ne v Tailwind utilitách: app.css je commitnutý build
       a nové utility (w-64, my-1, …) v něm nejsou. */
    .gpx-adminmenu-btn   { height: 36px; padding: 0 8px; display: inline-flex;
                           align-items: center; gap: 4px; border-radius: 6px;
                           background: none; border: 0; cursor: pointer; font-family: inherit; }
    .gpx-adminmenu-btn svg   { width: 20px; height: 20px; }
    .gpx-adminmenu-caret     { display: inline-flex; transition: transform .15s; }
    .gpx-adminmenu-btn .gpx-adminmenu-caret svg { width: 14px; height: 14px; opacity: .6; }
    .gpx-adminmenu-panel { position: absolute; right: 0; top: 100%; margin-top: 4px;
                           width: 260px; padding: 4px 0; z-index: 50; }
    /* Identita adminu je hlavička menu, ne poznámka pod čarou — proto vlastní
       podklad a proužek. Terracotta jen jako proužek: na text má na sand-100
       kontrast 2,9:1, což je pod hranicí WCAG AA. */
    .gpx-adminmenu-meta  { margin: 2px 4px 4px; padding: 8px 10px; border-radius: 6px;
                           font-size: 12.5px; line-height: 1.55; font-weight: 600;
                           color: var(--color-forest-700);
                           background: var(--color-sand-100);
                           border-left: 3px solid var(--color-terracotta-500); }
    .gpx-adminmenu-meta strong { font-weight: 800; }
    html.dark .gpx-adminmenu-meta { color: var(--color-sand-100);
                                    background: rgba(255, 255, 255, .07); }
    .gpx-adminmenu-sep   { margin: 4px 0; }
    .gpx-adminmenu-item  { display: flex; align-items: center; gap: 10px;
                           padding: 8px 12px; font-size: 14px; width: 100%;
                           text-align: left; background: none; border: 0;
                           cursor: pointer; font-family: inherit; }
    .gpx-adminmenu-item svg { width: 16px; height: 16px; flex: none; }
    .gpx-adminmenu-form  { margin: 0; padding: 0; }
    /* Dvě třídy porazí .site-header button (jedna třída + typ) výše. */
    .site-header .gpx-adminmenu-logout           { color: #b32d2d; }
    html.dark .site-header .gpx-adminmenu-logout { color: #ff9090; }

    /* Na mobilu je menu skryté, takže ovládání vpravo si musí doprava pomoct
       samo. Na desktopu to naopak musí být 0, jinak by třetí auto margin
       rozhodil symetrii mezer kolem menu. */
    .gpx-topnav-right { margin-left: auto; }
    @media (min-width: 48rem) { .gpx-topnav-right { margin-left: 0; } }

    .gpx-md-flex, .gpx-md-block, .gpx-sm-inline, .gpx-sm-inline-flex { display: none; }
    @media (min-width: 48rem) {
        .gpx-md-flex  { display: flex; }
        .gpx-md-block { display: block; }
    }
    @media (min-width: 40rem) {
        .gpx-sm-inline       { display: inline; }
        .gpx-sm-inline-flex  { display: inline-flex; }
    }</style>

    <!-- Alpine.js focus plugin (x-trap for focus management — A11Y-003) -->
    <!-- Must be loaded before Alpine core (defer preserves script order) -->
    <script defer src="https://unpkg.com/@alpinejs/focus@3.14.1/dist/cdn.min.js" integrity="sha384-bKXNU7o2Y3Uk/F2PB6U0bMyGZf6pLDnePM70U7sTE3cXUQ+JLgzrr/kwipEh0p23" crossorigin="anonymous"></script>

    <!-- Alpine.js (pro interaktivitu) -->
    <script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js" integrity="sha384-l8f0VcPi/M1iHPv8egOnY/15TDwqgbOR1anMIJWvU6nLRgZVLTLSaNqi/TOoT5Fh" crossorigin="anonymous"></script>

    <!-- Lucide icons -->
    <script defer src="https://cdn.jsdelivr.net/npm/lucide@0.469.0/dist/umd/lucide.min.js" integrity="sha384-hJnF5AwidE18GSWTAGHv3ByzzvfNZ1Tcx5y1UUV3WkauuMCEzBJBMSwSt/PUPXnM" crossorigin="anonymous"></script>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/logo-mountain.svg">

    <!-- CSRF token for AJAX requests -->
    <?php if (function_exists('csrf_token')): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <?php endif; ?>

    <!-- Shared i18n strings for JS modules (TASK-25) -->
    <?php
    $commonI18n = [
        '_lang'           => function_exists('app_lang') ? app_lang() : 'cs',
        'loading'         => t('loading', 'Načítání…'),
        'error'           => t('error', 'Chyba'),
        'save'            => t('save', 'Uložit'),
        'cancel'          => t('cancel', 'Zrušit'),
        'delete'          => t('delete', 'Smazat'),
        'confirm_delete'  => t('confirm_delete', 'Opravdu smazat?'),
        'speed'           => t('filter_badge_speed', 'Rychlost'),
        'gps_jumps'       => t('filter_badge_gps_jumps', 'GPS skoky'),
        'stationary'      => t('filter_badge_stationary', 'Stání'),
        'elevation'       => t('filter_badge_elevation', 'Výška'),
        'short_segments'  => t('filter_badge_short_seg', 'Kratké seg.'),
        'simplify'        => t('filter_badge_simplify', 'Zjednodušení'),
        'duration'        => t('param_duration', 'Doba (h:m:s)'),
        'distance'        => t('param_distance', 'Vzdálenost'),
    ];
    ?>
    <script>
    window.gpxI18n = <?= js_safe_json($commonI18n) ?>;
    </script>
</head>
<body class="font-[Inter] bg-sand-50 text-forest-900 dark:bg-forest-900 dark:text-sand-100 antialiased">

<style>
/* Administrátorská lišta přes celou šířku byla zrušena — její obsah je nyní
   v rozbalovacím menu v hlavičce (render_admin_menu()). Zůstává jen oranžová
   lišta návštěvnického náhledu: je to dočasný stav, který musí být vidět. */
.visitor-preview-banner{background:#e65c00;color:#fff;padding:6px 16px;font-size:13px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #cc4400;position:sticky;top:0;z-index:9999;gap:12px;font-family:inherit}
.visitor-preview-banner__exit{color:#fff;text-decoration:none;background:var(--color-forest-700);padding:4px 10px;border-radius:6px}
</style>
<?php if (function_exists('render_visitor_preview_banner')) render_visitor_preview_banner(); ?>

<script>
// Odsazení sticky hlavičky o výšku bannerů nad ní (viz --gpx-banner-h výše).
// Měří se za běhu, protože banner se na úzkém displeji zalomí do dvou řádků.
(function () {
    var banners = document.querySelectorAll('.visitor-preview-banner');
    if (!banners.length) { return; }

    function setBannerOffset() {
        var h = 0;
        for (var i = 0; i < banners.length; i++) {
            h += banners[i].getBoundingClientRect().height;
        }
        document.documentElement.style.setProperty('--gpx-banner-h', h + 'px');
    }
    setBannerOffset();

    // ResizeObserver, ne jen window.resize: banner se na úzkém displeji zalomí
    // do dvou řádků a vyroste, aniž by se okno muselo měnit (změna jazyka,
    // doběhnutí fontů, přepnutí na návštěvnický náhled). Se zastaralou výškou
    // by banner hlavičku zase překryl.
    if (window.ResizeObserver) {
        var ro = new ResizeObserver(setBannerOffset);
        for (var j = 0; j < banners.length; j++) { ro.observe(banners[j]); }
    }
    // window.resize necháváme i vedle ResizeObserveru jako pojistku — je to
    // jeden řádek a kryje případy, kdy se callbacky observeru nedoručí.
    window.addEventListener('resize', setBannerOffset);
    window.addEventListener('load', setBannerOffset);   // po doběhnutí fontů
})();
</script>

<style>.skip-link{position:absolute;left:-9999px;z-index:10000;padding:8px 16px;background:var(--color-forest-700);color:#fff;text-decoration:none;font-weight:600}.skip-link:focus{left:8px;top:8px;outline:2px solid #fff;outline-offset:2px}</style>
<a href="#main-content" class="skip-link"><?= htmlspecialchars(t('skip_to_content', 'Přeskočit na obsah')) ?></a>

<!-- Sticky header s blur backdrop -->
<header class="site-header sticky top-0 z-40 bg-sand-50/85 dark:bg-forest-900/85 backdrop-blur-md border-b border-sand-200 dark:border-forest-800">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 h-16 flex items-center gap-4">
        <!-- Brand -->
        <a href="index.php" class="gpx-brand flex items-center gap-2 transition-colors">
            <span class="w-8 h-8 inline-flex items-center justify-center text-forest-600 dark:text-forest-300">
                <!-- Mountain SVG inline pro fast paint -->
                <svg viewBox="0 0 32 32" fill="currentColor" class="w-7 h-7">
                    <path d="M2 26 L11 12 L17 20 L22 14 L30 26 Z" opacity="0.95"/>
                    <path d="M11 12 L14 16 L13 17 L10 13 Z" fill="white" opacity="0.6"/>
                    <path d="M22 14 L24 17 L23 18 L21 15 Z" fill="white" opacity="0.6"/>
                    <circle cx="24" cy="7" r="2" opacity="0.7"/>
                </svg>
            </span>
            <span class="font-[Manrope] font-bold text-lg tracking-tight">GPX Manager</span>
        </a>

        <!-- Desktop nav -->
        <!-- Vlastní třída místo Tailwindího `hidden md:flex` — viz komentář u stylů
             v hlavičce. `hidden` je natolik obecný název, že ho přebije kdejaké
             cizí pravidlo a menu pak zmizí i na širokém displeji. -->
        <nav class="gpx-md-flex gpx-topnav">
            <?php
            // Položky + výchozí pořadí definuje nav_menu_items() (app_constants.php),
            // pořadí si admin mění v Administraci (app_config 'nav_order').
            $_visPages = function_exists('get_app_config')
                ? (array)get_app_config('visible_pages', function_exists('all_pages') ? all_pages() : [])
                : [];
            $_navReg  = nav_menu_items();
            $navItems = [];
            foreach (nav_menu_order() as $_navKey) {
                [$_nHref, $_nIcon, $_nLabel, $_nVisKey] = $_navReg[$_navKey];
                // Podmíněné položky: návštěvník je vidí jen pokud je stránka viditelná
                if ($_nVisKey !== null && !$isAdmin && !in_array($_nVisKey, $_visPages, true)) {
                    continue;
                }
                $navItems[] = [$_nHref, $_nIcon, $_nLabel];
            }
            foreach ($navItems as [$href, $icon, $label]):
                $active = ($currentScript === $href);
            ?>
                <a href="<?= $href ?>"<?= $active ? ' aria-current="page"' : '' ?>
                   class="gpx-topnav-item transition-colors
                          <?= $active
                              ? 'bg-forest-100 dark:bg-forest-800 font-semibold'
                              : 'hover:bg-sand-100 dark:hover:bg-forest-800' ?>">
                    <i data-lucide="<?= $icon ?>" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="gpx-topnav-right flex items-center gap-2">
            <!-- Language switcher -->
            <?php
                $currentLang = function_exists('app_lang') ? app_lang() : 'cs';
                $allowedLangs = function_exists('available_langs') ? available_langs() : all_langs();
                $langFlags = [
                    'cs' => '🇨🇿', 'en' => '🇬🇧', 'de' => '🇩🇪', 'sk' => '🇸🇰',
                    'es' => '🇪🇸', 'fr' => '🇫🇷', 'pl' => '🇵🇱', 'it' => '🇮🇹',
                ];
                $langNames = [
                    'cs' => 'Čeština', 'en' => 'English', 'de' => 'Deutsch', 'sk' => 'Slovenčina',
                    'es' => 'Español', 'fr' => 'Français', 'pl' => 'Polski', 'it' => 'Italiano',
                ];
            ?>
            <!-- Language switcher — A11Y-004: aria-haspopup, aria-expanded, role=menu, role=menuitem, aria-current -->
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button @click="open = !open" type="button"
                        class="h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors text-sm"
                        aria-label="<?= htmlspecialchars(t('lang_switcher', 'Přepnout jazyk')) ?>"
                        aria-haspopup="menu"
                        :aria-expanded="open.toString()">
                    <span class="text-base leading-none" aria-hidden="true"><?= $langFlags[$currentLang] ?? '🌐' ?></span>
                    <span class="gpx-sm-inline uppercase font-medium tracking-wider text-xs"><?= htmlspecialchars($currentLang) ?></span>
                    <i data-lucide="chevron-down" class="w-3.5 h-3.5 opacity-60" x-bind:class="open && 'rotate-180'" style="transition: transform .15s" aria-hidden="true"></i>
                </button>
                <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                     role="menu"
                     class="absolute right-0 top-full mt-1 w-44 rounded-lg bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 shadow-hover py-1 z-50">
                    <?php foreach ($allowedLangs as $lang): ?>
                        <?php $isActive = $lang === $currentLang; ?>
                        <a href="?app_lang=<?= htmlspecialchars($lang) ?>"
                           onclick="document.cookie='app_lang=<?= htmlspecialchars($lang) ?>; path=/; max-age=31536000'"
                           role="menuitem"
                           <?= $isActive ? 'aria-current="true"' : '' ?>
                           class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors <?= $isActive
                               ? 'bg-forest-100 dark:bg-forest-700 text-forest-700 dark:text-sand-100 font-medium'
                               : 'text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-700' ?>">
                            <span class="text-base leading-none" aria-hidden="true"><?= $langFlags[$lang] ?? '🌐' ?></span>
                            <span class="flex-1"><?= htmlspecialchars($langNames[$lang] ?? strtoupper($lang)) ?></span>
                            <?php if ($isActive): ?>
                                <i data-lucide="check" class="w-3.5 h-3.5 text-terracotta-500" aria-hidden="true"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Theme toggle -->
            <button @click="dark = !dark" type="button"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors"
                    :aria-label="dark ? 'Light mode' : 'Dark mode'">
                <i data-lucide="sun" class="w-5 h-5" x-show="dark" x-cloak aria-hidden="true"></i>
                <i data-lucide="moon" class="w-5 h-5" x-show="!dark" x-cloak aria-hidden="true"></i>
            </button>

            <?php
            // Admin menu (identita, Administrace, náhled jako návštěvník, odhlášení).
            // Nahrazuje bývalou modrou lištu přes celou šířku stránky.
            if (function_exists('render_admin_menu')) render_admin_menu();
            ?>

            <!-- Mobile menu (hamburger) — A11Y-003 -->
            <button @click="$store.mobileNav.open = !$store.mobileNav.open" type="button"
                    id="mobile-nav-trigger"
                    aria-label="<?= htmlspecialchars(t('nav_menu', 'Menu')) ?>"
                    aria-controls="mobile-nav-drawer"
                    :aria-expanded="$store.mobileNav.open.toString()"
                    class="md:hidden w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800">
                <i data-lucide="menu" class="w-5 h-5" aria-hidden="true"></i>
            </button>
        </div>
    </div>
</header>

<!-- Mobile slide-over drawer — A11Y-003: role=dialog, aria-modal, focus trap via x-trap.noscroll -->
<div x-data x-show="$store.mobileNav.open" x-cloak class="md:hidden fixed inset-0 z-50" @keydown.escape.window="$store.mobileNav.open = false">
    <!-- Backdrop -->
    <div x-show="$store.mobileNav.open"
         x-transition:enter="transition-opacity ease-out duration-200"
         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-in duration-150"
         x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         @click="$store.mobileNav.open = false"
         class="absolute inset-0 bg-forest-900/60 backdrop-blur-sm"></div>

    <!-- Drawer panel -->
    <aside id="mobile-nav-drawer"
           x-show="$store.mobileNav.open"
           x-trap.noscroll="$store.mobileNav.open"
           role="dialog"
           aria-modal="true"
           aria-label="<?= htmlspecialchars(t('nav_menu', 'Navigační menu')) ?>"
           x-transition:enter="transition transform ease-out duration-250"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition transform ease-in duration-200"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="translate-x-full"
           class="absolute right-0 top-0 bottom-0 w-72 max-w-[85vw] bg-sand-50 dark:bg-forest-900 shadow-hover flex flex-col">
        <div class="h-16 px-4 flex items-center justify-between border-b border-sand-200 dark:border-forest-800 shrink-0">
            <span class="font-[Manrope] font-bold text-forest-700 dark:text-sand-100">GPX Manager</span>
            <button @click="$store.mobileNav.open = false"
                    aria-label="<?= htmlspecialchars(t('nav_close', 'Zavřít navigaci')) ?>"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800">
                <i data-lucide="x" class="w-5 h-5" aria-hidden="true"></i>
            </button>
        </div>
        <nav class="flex-1 overflow-y-auto py-2">
            <?php foreach ($navItems as [$href, $icon, $label]):
                $active = ($currentScript === $href);
            ?>
                <a href="<?= $href ?>"
                   class="flex items-center gap-3 px-4 py-3 transition-colors
                          <?= $active
                              ? 'bg-forest-100 text-forest-700 dark:bg-forest-800 dark:text-sand-100 border-l-4 border-terracotta-500'
                              : 'text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800' ?>">
                    <i data-lucide="<?= $icon ?>" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                    <span class="font-medium"><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
                <div class="my-2 mx-4 border-t border-sand-200 dark:border-forest-800"></div>
                <a href="admin.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="shield" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                    <span class="font-medium">Admin</span>
                </a>
                <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="settings" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                    <span class="font-medium"><?= htmlspecialchars(t('h1_settings')) ?></span>
                </a>
                <a href="import.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="upload" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                    <span class="font-medium"><?= htmlspecialchars(t('h1_import')) ?></span>
                </a>
                <?php // Na mobilu je admin menu v hlavičce skryté — náhled a odhlášení musí být tady. ?>
                <div class="my-2 mx-4 border-t border-sand-200 dark:border-forest-800"></div>
                <a href="index.php?visitor_preview=1" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="eye" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                    <span class="font-medium"><?= htmlspecialchars(t('preview_as_visitor', 'Náhled jako návštěvník')) ?></span>
                </a>
                <form method="post" action="login.php" class="m-0 p-0">
                    <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="w-full text-left flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                        <i data-lucide="log-out" class="w-5 h-5 shrink-0" aria-hidden="true"></i>
                        <span class="font-medium"><?= htmlspecialchars(t('logout', 'Odhlásit se')) ?></span>
                    </button>
                </form>
            <?php endif; ?>
        </nav>
    </aside>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('mobileNav', { open: false });
    });
</script>

<main id="main-content" class="min-h-[calc(100vh-4rem)]">
