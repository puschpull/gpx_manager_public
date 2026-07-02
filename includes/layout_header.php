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
    <link rel="stylesheet" href="assets/css/app.css">

    <!-- x-cloak: skryje Alpine elementy před inicializací (jinak jsou vidět při načtení stránky) -->
    <style>[x-cloak] { display: none !important; }</style>

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
.admin-banner{background:#003366;color:#fff;padding:6px 16px;font-size:13px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #002244;position:sticky;top:0;z-index:9999;gap:12px;font-family:inherit}
.admin-banner__muted{opacity:.8}
.admin-banner__actions{display:flex;gap:8px;align-items:center}
.admin-banner__actions a,.admin-banner__actions button{color:#fff;text-decoration:none;background:#e65c00;padding:4px 10px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-family:inherit}
.admin-banner__logout-form{display:inline;margin:0;padding:0}
.admin-banner__logout-form button{background:#cc3333}
.admin-banner__actions a:focus-visible,.admin-banner__actions button:focus-visible{outline:2px solid #fff;outline-offset:2px}
.visitor-preview-banner{background:#e65c00;color:#fff;padding:6px 16px;font-size:13px;display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid #cc4400;position:sticky;top:0;z-index:9999;gap:12px;font-family:inherit}
.visitor-preview-banner__exit{color:#fff;text-decoration:none;background:#003366;padding:4px 10px;border-radius:6px}
</style>
<?php if (!isset($show_admin_banner) || $show_admin_banner): ?>
<?php if (function_exists('render_admin_banner')) render_admin_banner(); ?>
<?php endif; ?>
<?php if (function_exists('render_visitor_preview_banner')) render_visitor_preview_banner(); ?>

<style>.skip-link{position:absolute;left:-9999px;z-index:10000;padding:8px 16px;background:#003366;color:#fff;text-decoration:none;font-weight:600}.skip-link:focus{left:8px;top:8px;outline:2px solid #fff;outline-offset:2px}</style>
<a href="#main-content" class="skip-link"><?= htmlspecialchars(t('skip_to_content', 'Přeskočit na obsah')) ?></a>

<!-- Sticky header s blur backdrop -->
<header class="sticky top-0 z-40 bg-sand-50/85 dark:bg-forest-900/85 backdrop-blur-md border-b border-sand-200 dark:border-forest-800">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 h-16 flex items-center gap-4">
        <!-- Brand -->
        <a href="index.php" class="flex items-center gap-2 text-forest-700 dark:text-sand-100 hover:text-terracotta-500 transition-colors">
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
        <nav class="hidden md:flex items-center gap-1 ml-4 text-sm">
            <?php
            $navItems = [
                ['nearby.php',   'map-pin',     t('h1_nearby',    'Nejbližší trasy')],
                ['photos.php',   'image',        t('nav_photos',   'Fotografie')],
                ['stats.php',    'bar-chart-2',  t('nav_stats',    'Statistiky')],
                ['calendar.php', 'calendar',     t('nav_calendar', 'Kalendář')],
                ['heatmap.php',  'flame',        t('nav_heatmap',  'Heatmapa')],
            ];
            // Foto-heatmapa — v menu jen pokud je viditelná (admin vždy, návštěvník dle konfigurace)
            $_visPages = function_exists('get_app_config')
                ? (array)get_app_config('visible_pages', function_exists('all_pages') ? all_pages() : [])
                : [];
            if ($isAdmin || in_array('photo_heatmap', $_visPages, true)) {
                $navItems[] = ['photo_heatmap.php', 'camera', t('nav_photo_heatmap', 'Foto-heatmapa')];
            }
            if ($isAdmin || in_array('virtual_tracks', $_visPages, true)) {
                $navItems[] = ['virtual_tracks.php', 'route', t('nav_virtual_tracks', 'Virtuální trasy')];
            }
            foreach ($navItems as [$href, $icon, $label]):
                $active = ($currentScript === $href);
            ?>
                <a href="<?= $href ?>"
                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors
                          <?= $active
                              ? 'bg-forest-100 text-forest-700 dark:bg-forest-800 dark:text-sand-100'
                              : 'text-forest-700/80 dark:text-sand-100/70 hover:bg-sand-100 dark:hover:bg-forest-800' ?>">
                    <i data-lucide="<?= $icon ?>" class="w-4 h-4" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ml-auto flex items-center gap-2">
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
                    <span class="hidden sm:inline uppercase font-medium tracking-wider text-xs"><?= htmlspecialchars($currentLang) ?></span>
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

            <?php if ($isAdmin): ?>
                <a href="admin.php" class="hidden sm:inline-flex w-9 h-9 items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors" aria-label="Admin">
                    <i data-lucide="settings" class="w-5 h-5" aria-hidden="true"></i>
                </a>
            <?php endif; ?>

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
