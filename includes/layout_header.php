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
<html lang="cs" class="" x-data="{ dark: localStorage.getItem('gpx-theme') === 'dark' || (!localStorage.getItem('gpx-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches) }" x-init="$watch('dark', v => { document.documentElement.classList.toggle('dark', v); localStorage.setItem('gpx-theme', v ? 'dark' : 'light'); }); document.documentElement.classList.toggle('dark', dark);">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — GPX Manager</title>

    <!-- Inline blok: nastav .dark před stylem aby nedošlo k FOUC -->
    <script>
        (function () {
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

    <!-- Alpine.js (pro interaktivitu) -->
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>

    <!-- Lucide ikony -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/logo-mountain.svg">
</head>
<body class="font-[Inter] bg-sand-50 text-forest-900 dark:bg-forest-900 dark:text-sand-100 antialiased">

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
                ['nearby.php',   'map-pin',  t('h1_nearby', 'Nejbližší trasy')],
                ['photos.php',   'image',    t('Fotografie', 'Fotografie')],
                ['stats.php',    'bar-chart-2', t('Statistiky', 'Statistiky')],
                ['calendar.php', 'calendar', t('Kalendář', 'Kalendář')],
                ['heatmap.php',  'flame',    t('Heatmapa', 'Heatmapa')],
            ];
            foreach ($navItems as [$href, $icon, $label]):
                $active = ($currentScript === $href);
            ?>
                <a href="<?= $href ?>"
                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-md transition-colors
                          <?= $active
                              ? 'bg-forest-100 text-forest-700 dark:bg-forest-800 dark:text-sand-100'
                              : 'text-forest-700/80 dark:text-sand-100/70 hover:bg-sand-100 dark:hover:bg-forest-800' ?>">
                    <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i>
                    <span><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="ml-auto flex items-center gap-2">
            <!-- Language switcher -->
            <?php
                $currentLang = function_exists('app_lang') ? app_lang() : 'cs';
                $allowedLangs = function_exists('get_app_config')
                    ? get_app_config('allowed_langs', ['cs','en','de','sk','es','fr','pl','it'])
                    : ['cs','en','de','sk','es','fr','pl','it'];
                $langFlags = [
                    'cs' => '🇨🇿', 'en' => '🇬🇧', 'de' => '🇩🇪', 'sk' => '🇸🇰',
                    'es' => '🇪🇸', 'fr' => '🇫🇷', 'pl' => '🇵🇱', 'it' => '🇮🇹',
                ];
                $langNames = [
                    'cs' => 'Čeština', 'en' => 'English', 'de' => 'Deutsch', 'sk' => 'Slovenčina',
                    'es' => 'Español', 'fr' => 'Français', 'pl' => 'Polski', 'it' => 'Italiano',
                ];
            ?>
            <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                <button @click="open = !open" type="button"
                        class="h-9 px-2.5 inline-flex items-center gap-1.5 rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors text-sm"
                        aria-label="Jazyk">
                    <span class="text-base leading-none"><?= $langFlags[$currentLang] ?? '🌐' ?></span>
                    <span class="hidden sm:inline uppercase font-medium tracking-wider text-xs"><?= htmlspecialchars($currentLang) ?></span>
                    <i data-lucide="chevron-down" class="w-3.5 h-3.5 opacity-60" x-bind:class="open && 'rotate-180'" style="transition: transform .15s"></i>
                </button>
                <div x-show="open" x-cloak x-transition.opacity.duration.150ms
                     class="absolute right-0 top-full mt-1 w-44 rounded-lg bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 shadow-hover py-1 z-50">
                    <?php foreach ($allowedLangs as $lang): ?>
                        <?php $isActive = $lang === $currentLang; ?>
                        <a href="?app_lang=<?= htmlspecialchars($lang) ?>"
                           onclick="document.cookie='app_lang=<?= htmlspecialchars($lang) ?>; path=/; max-age=31536000'"
                           class="flex items-center gap-2.5 px-3 py-2 text-sm transition-colors <?= $isActive
                               ? 'bg-forest-100 dark:bg-forest-700 text-forest-700 dark:text-sand-100 font-medium'
                               : 'text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-700' ?>">
                            <span class="text-base leading-none"><?= $langFlags[$lang] ?? '🌐' ?></span>
                            <span class="flex-1"><?= htmlspecialchars($langNames[$lang] ?? strtoupper($lang)) ?></span>
                            <?php if ($isActive): ?>
                                <i data-lucide="check" class="w-3.5 h-3.5 text-terracotta-500"></i>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Theme toggle -->
            <button @click="dark = !dark" type="button"
                    class="w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors"
                    :aria-label="dark ? 'Light mode' : 'Dark mode'">
                <i data-lucide="sun" class="w-5 h-5" x-show="dark" x-cloak></i>
                <i data-lucide="moon" class="w-5 h-5" x-show="!dark" x-cloak></i>
            </button>

            <?php if ($isAdmin): ?>
                <a href="admin.php" class="hidden sm:inline-flex w-9 h-9 items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors" title="Admin">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                </a>
            <?php endif; ?>

            <!-- Mobile menu (hamburger) -->
            <button @click="$store.mobileNav.open = !$store.mobileNav.open" type="button"
                    class="md:hidden w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800">
                <i data-lucide="menu" class="w-5 h-5"></i>
            </button>
        </div>
    </div>
</header>

<!-- Mobile slide-over drawer -->
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
    <aside x-show="$store.mobileNav.open"
           x-transition:enter="transition transform ease-out duration-250"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition transform ease-in duration-200"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="translate-x-full"
           class="absolute right-0 top-0 bottom-0 w-72 max-w-[85vw] bg-sand-50 dark:bg-forest-900 shadow-hover flex flex-col">
        <div class="h-16 px-4 flex items-center justify-between border-b border-sand-200 dark:border-forest-800 shrink-0">
            <span class="font-[Manrope] font-bold text-forest-700 dark:text-sand-100">GPX Manager</span>
            <button @click="$store.mobileNav.open = false" class="w-9 h-9 inline-flex items-center justify-center rounded-md text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800">
                <i data-lucide="x" class="w-5 h-5"></i>
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
                    <i data-lucide="<?= $icon ?>" class="w-5 h-5 shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars($label) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if ($isAdmin): ?>
                <div class="my-2 mx-4 border-t border-sand-200 dark:border-forest-800"></div>
                <a href="admin.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="shield" class="w-5 h-5 shrink-0"></i>
                    <span class="font-medium">Admin</span>
                </a>
                <a href="settings.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="settings" class="w-5 h-5 shrink-0"></i>
                    <span class="font-medium"><?= htmlspecialchars(t('h1_settings')) ?></span>
                </a>
                <a href="import.php" class="flex items-center gap-3 px-4 py-3 text-forest-700 dark:text-sand-100 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors">
                    <i data-lucide="upload" class="w-5 h-5 shrink-0"></i>
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

<main class="min-h-[calc(100vh-4rem)]">
