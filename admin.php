<?php
/**
 * Admin panel — přehled nástrojů pro správu aplikace
 */
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

/* ===== CSRF guard for all POST handlers ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die('Invalid CSRF token');
}

/* ===== POST: uložení konfigurace přístupu návštěvníků ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_access_config'])) {
    // all_pages() does not include 'photos' — photos is a special extra page for visitors
    $allPagesWithPhotos = array_merge(all_pages(), ['photos']);

    $langs  = array_values(array_intersect($_POST['allowed_langs']  ?? [], all_langs()));
    $pages  = array_values(array_intersect($_POST['allowed_pages']  ?? [], $allPagesWithPhotos));

    if (empty($langs))  $langs  = ['cs'];

    // Pořadí horního menu — jen známé klíče, chybějící (nové stránky) na konec
    $navKeys  = array_keys(nav_menu_items());
    $navOrder = array_values(array_intersect((array)($_POST['nav_order'] ?? []), $navKeys));
    foreach ($navKeys as $k) {
        if (!in_array($k, $navOrder, true)) $navOrder[] = $k;
    }

    set_app_config('allowed_langs',  $langs);
    set_app_config('visible_pages',  $pages);
    set_app_config('nav_order',      $navOrder);

    header('Location: admin.php?saved=access');
    exit;
}

/* ===== POST: uložení konfigurace cest k uploads ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_uploads_config'])) {
    $fs  = trim((string)($_POST['uploads_fs_path'] ?? ''));
    $url = trim((string)($_POST['uploads_url'] ?? ''));
    set_app_config('uploads_fs_path', $fs);
    set_app_config('uploads_url',     $url);
    header('Location: admin.php?saved=uploads');
    exit;
}

$allowedLangs = available_langs();

/* ===== Rychlé statistiky ===== */
$totalTracks = (int)$pdo->query("SELECT COUNT(*) FROM tracks")->fetchColumn();
$totalCats   = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalFavs   = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE is_favorite = 1")->fetchColumn();

$withDifficulty = 0;
$withoutDifficulty = 0;
try {
    $withDifficulty    = (int)$pdo->query("SELECT COUNT(*) FROM tracks WHERE difficulty IS NOT NULL")->fetchColumn();
    $withoutDifficulty = $totalTracks - $withDifficulty;
} catch (Throwable $e) { error_log("admin.php difficulty query: " . $e->getMessage()); }

// Trasy bez kategorie aktivity
$activityCats = ['Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto'];
$actPlaceholders = implode(',', array_fill(0, count($activityCats), '?'));
$actStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT tc.track_id)
    FROM track_categories tc
    JOIN categories c ON c.id = tc.category_id
    WHERE c.name IN ($actPlaceholders)
");
$actStmt->execute($activityCats);
$withActivity = (int)$actStmt->fetchColumn();
$withoutActivity = $totalTracks - $withActivity;

// Náhledy — počítáme trasy, kterým reálně chybí PNG náhled.
// (Dříve: $totalTracks - počet .png ve složce → mohlo jít do záporu kvůli
//  osiřelým náhledům po smazaných trasách.)
$thumbDir = uploads_fs('thumbs/');
$withoutThumbs = 0;
$orphanThumbs  = 0;
if (is_dir($thumbDir)) {
    $trackFilenames = $pdo->query("SELECT filename FROM tracks")->fetchAll(PDO::FETCH_COLUMN);
    $expectedThumbs = [];
    foreach ($trackFilenames as $fn) {
        $thumbName = pathinfo((string)$fn, PATHINFO_FILENAME) . '.png';
        $expectedThumbs[$thumbName] = true;
        if (!is_file($thumbDir . $thumbName)) {
            $withoutThumbs++;
        }
    }
    // Osiřelé náhledy: PNG ve složce, kterým neodpovídá žádná trasa.
    foreach (glob($thumbDir . '*.png') as $png) {
        if (!isset($expectedThumbs[basename($png)])) {
            $orphanThumbs++;
        }
    }
} else {
    $withoutThumbs = $totalTracks;
}

// Velikost uploads
$uploadsSize = 0;
$uploadDir = uploads_fs();
if (is_dir($uploadDir)) {
    foreach (glob($uploadDir . '*.gpx') as $f) {
        $uploadsSize += filesize($f);
    }
}
$uploadsSizeMB = round($uploadsSize / 1024 / 1024, 1);

// PHP a DB info
$phpVersion = PHP_VERSION;
$dbVersion = $pdo->query("SELECT VERSION()")->fetchColumn();
?>

<?php
$page_title = t('h1_admin');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="css/style.css">
    <style>
        .admin-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        .admin-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px;
        }
        .admin-card h3 {
            margin: 0 0 12px;
            font-size: 16px;
            color: var(--text-color);
            border-bottom: 2px solid var(--accent-color);
            padding-bottom: 6px;
        }
        .admin-card .tool-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .admin-card .tool-list li {
            margin-bottom: 8px;
        }
        .admin-card .tool-list a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--bg-button);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-color);
            text-decoration: none;
            font-size: 14px;
            transition: background 0.2s, color 0.2s;
        }
        .admin-card .tool-list a:hover {
            background: var(--accent-color);
            color: #fff;
        }
        .admin-card .tool-desc {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 10px;
            margin: 20px 0;
        }
        .info-item {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
        }
        .info-item .info-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--accent-color);
            margin: 4px 0;
        }
        .info-item .info-label {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
        }
        .info-item.warn .info-value {
            color: #f39c12;
        }
        .info-item.ok .info-value {
            color: #4caf50;
        }
    </style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="shield" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_admin')) ?>
    </h1>
    <?php if (isset($_GET['saved']) && $_GET['saved'] === 'access'): ?>
        <div class="mt-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-forest-100 dark:bg-forest-700 text-forest-700 dark:text-sand-100 text-sm">
            <i data-lucide="check-circle" class="w-4 h-4" aria-hidden="true"></i> <?= htmlspecialchars(t('admin_access_saved', 'Konfigurace přístupu uložena')) ?>
        </div>
    <?php endif; ?>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12">


<!-- ===== PŘEHLED STAVU ===== -->
<div class="info-grid">
    <div class="info-item">
        <div class="info-value"><?= $totalTracks ?></div>
        <div class="info-label"><?= t('info_total_tracks') ?></div>
    </div>
    <div class="info-item">
        <div class="info-value"><?= $totalCats ?></div>
        <div class="info-label"><?= t('info_categories') ?></div>
    </div>
    <div class="info-item">
        <div class="info-value"><?= $totalFavs ?></div>
        <div class="info-label"><?= t('info_favorites') ?></div>
    </div>
    <div class="info-item <?= $withoutDifficulty > 0 ? 'warn' : 'ok' ?>">
        <div class="info-value"><?= $withoutDifficulty ?></div>
        <div class="info-label"><?= t('info_no_difficulty') ?></div>
    </div>
    <div class="info-item <?= $withoutActivity > 0 ? 'warn' : 'ok' ?>">
        <div class="info-value"><?= $withoutActivity ?></div>
        <div class="info-label"><?= t('info_no_activity') ?></div>
    </div>
    <div class="info-item <?= $withoutThumbs > 0 ? 'warn' : 'ok' ?>">
        <div class="info-value"><?= $withoutThumbs ?></div>
        <div class="info-label"><?= t('info_no_thumb') ?></div>
    </div>
    <div class="info-item">
        <div class="info-value"><?= $uploadsSizeMB ?> MB</div>
        <div class="info-label"><?= t('info_gpx_files') ?></div>
    </div>
    <div class="info-item">
        <div class="info-value" style="font-size:14px;">PHP <?= h($phpVersion) ?></div>
        <div class="info-label"><?= t('info_php_version') ?></div>
    </div>
</div>

<!-- ===== NÁSTROJE ===== -->
<div class="admin-grid">

    <!-- Data -->
    <div class="admin-card">
        <h3><?= t('admin_data') ?></h3>
        <ul class="tool-list">
            <li>
                <a href="import.php">
                    <span><?= t('tool_import') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_import') ?></div>
            </li>
            <li>
                <a href="photo_import.php">
                    <span><?= t('tool_photo_import', '📥 Lokální import fotek') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_photo_import', 'Skenuje adresář na PC, vybere a importuje fotky hromadně (bez uploadu přes prohlížeč)') ?></div>
            </li>
            <li>
                <a href="export.php?<?= http_build_query(['filter_submit' => 1]) ?>">
                    <span><?= t('tool_export_csv') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_export_csv') ?></div>
            </li>
            <li>
                <a href="zip_export.php?<?= http_build_query(['filter_submit' => 1]) ?>">
                    <span><?= t('tool_export_zip') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_export_zip') ?></div>
            </li>
        </ul>
    </div>

    <!-- Přepočty -->
    <div class="admin-card">
        <h3><?= t('admin_recalc') ?></h3>
        <ul class="tool-list">
            <li>
                <a href="recalc_difficulty.php">
                    <span><?= t('tool_recalc_diff') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_recalc_diff') ?> (<?= $totalTracks ?>)<?= $withoutDifficulty > 0 ? " — {$withoutDifficulty} ×" : '' ?></div>
            </li>
            <li>
                <a href="recalc_activity.php">
                    <span><?= t('tool_recalc_act') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_recalc_act') ?><?= $withoutActivity > 0 ? " — {$withoutActivity} ×" : '' ?></div>
            </li>
            <li>
                <a href="rebuild_thumbs.php">
                    <span><?= t('tool_rebuild_thumbs') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_rebuild_thumbs') ?><?= $withoutThumbs > 0 ? " — {$withoutThumbs} ×" : '' ?><?= $orphanThumbs > 0 ? " · {$orphanThumbs} osiřelých náhledů" : '' ?></div>
            </li>
        </ul>
    </div>

    <!-- Vizualizace -->
    <div class="admin-card">
        <h3><?= t('admin_maps') ?></h3>
        <ul class="tool-list">
            <li>
                <a href="stats.php">
                    <span><?= t('tool_stats') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_stats') ?></div>
            </li>
            <li>
                <a href="calendar.php">
                    <span><?= t('tool_calendar') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_calendar') ?></div>
            </li>
            <li>
                <a href="heatmap.php">
                    <span><?= t('tool_heatmap') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_heatmap') ?></div>
            </li>
            <li>
                <a href="photo_heatmap.php">
                    <span><?= t('tool_photo_heatmap', '📸 Foto-heatmapa') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_photo_heatmap', 'Hustota fotografií na mapě, při přiblížení jednotlivé fotky') ?></div>
            </li>
            <li>
                <a href="virtual_tracks.php">
                    <span><?= t('tool_virtual_tracks', '🧭 Virtuální trasy') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_virtual_tracks', 'Chytré roztřídění nepřiřazených fotek do tras (z GPS bodů fotek)') ?></div>
            </li>
            <li>
                <a href="map_search.php">
                    <span><?= t('tool_map_search') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_map_search') ?></div>
            </li>
            <li>
                <a href="nearby.php">
                    <span><?= t('tool_nearby') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_nearby') ?></div>
            </li>
        </ul>
    </div>

    <!-- Nástroje -->
    <div class="admin-card">
        <h3><?= t('admin_tools') ?></h3>
        <ul class="tool-list">
            <li>
                <a href="settings.php">
                    <span><?= t('tool_settings') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_settings') ?></div>
            </li>
            <li>
                <a href="filter.php">
                    <span><?= t('tool_gpx_cleaner') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_gpx_cleaner') ?></div>
            </li>
            <li>
                <a href="phpinfo.php">
                    <span><?= t('tool_php_info') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_php_info') ?></div>
            </li>
            <li>
                <a href="CHANGELOG.txt" target="_blank">
                    <span><?= t('tool_changelog') ?></span>
                </a>
                <div class="tool-desc"><?= t('desc_changelog') ?></div>
            </li>
            <li>
                <form method="post" action="login.php"
                      data-confirm-text="<?= htmlspecialchars(t('confirm_logout'), ENT_QUOTES, 'UTF-8') ?>"
                      onsubmit="return confirm(this.dataset.confirmText);"
                      style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="tool-link-button">
                        <span><?= t('tool_logout') ?></span>
                    </button>
                </form>
                <div class="tool-desc"><?= t('desc_logout') ?></div>
            </li>
        </ul>
    </div>

</div>

<!-- ===== KONFIGURACE PŘÍSTUPU NÁVŠTĚVNÍKŮ ===== -->
<?php
$cfgLangs  = available_langs();
$cfgPages  = get_app_config('visible_pages', all_pages());

$langFlagsAll = ['cs'=>'🇨🇿 Čeština','en'=>'🇬🇧 English','de'=>'🇩🇪 Deutsch',
    'sk'=>'🇸🇰 Slovenčina','es'=>'🇪🇸 Español','fr'=>'🇫🇷 Français',
    'pl'=>'🇵🇱 Polski','it'=>'🇮🇹 Italiano'];
$pageLabels = ['stats'=>'📊 Statistiky','calendar'=>'📅 Kalendář',
    'heatmap'=>'🔥 Heatmapa','photo_heatmap'=>'📸 Foto-heatmapa','virtual_tracks'=>'🧭 Virtuální trasy','map_search'=>'🗺️ Hledat na mapě',
    'nearby'=>'📍 Nejbližší trasy','photo_nearby'=>'📷 Fotografie v okolí','filter'=>'🧹 GPX Cleaner',
    'compare'=>'⚖️ Porovnat trasy','settings'=>'🔧 Nastavení',
    'photos'=>'📸 Fotografie (jen prohlížení)'];
?>
<div style="margin:24px 0;">
    <h2 style="font-size:17px;margin-bottom:12px;color:var(--text-color);">🔐 <?= htmlspecialchars(t('admin_access_config', 'Konfigurace přístupu pro návštěvníky')) ?></h2>
    <form method="POST" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;">
        <?= csrf_field() ?>

        <div class="admin-card">
            <h3>🌍 <?= htmlspecialchars(t('admin_available_langs', 'Dostupné jazyky')) ?></h3>
            <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($langFlagsAll as $lc => $label): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="allowed_langs[]" value="<?= $lc ?>"
                        <?= in_array($lc, $cfgLangs) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="admin-card">
            <h3>📄 <?= htmlspecialchars(t('admin_visible_pages', 'Viditelné stránky pro návštěvníky')) ?></h3>
            <p style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
                <?= htmlspecialchars(t('admin_visible_pages_hint', 'Admin vidí vždy vše. Odškrtnuté stránky přesměrují návštěvníky na hlavní stránku.')) ?>
            </p>
            <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($pageLabels as $key => $label): ?>
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" name="allowed_pages[]" value="<?= $key ?>"
                        <?= in_array($key, $cfgPages) ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </label>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="admin-card">
            <h3>☰ <?= htmlspecialchars(t('admin_nav_order', 'Pořadí horního menu')) ?></h3>
            <p style="font-size:11px;color:var(--text-muted);margin-bottom:10px;">
                <?= htmlspecialchars(t('admin_nav_order_hint', 'Šipkami změň pořadí položek v horním menu. Platí pro admina i návštěvníky.')) ?>
            </p>
            <ul id="navOrderList" style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px;">
            <?php $_navReg = nav_menu_items(); foreach (nav_menu_order() as $_nk): ?>
                <li style="display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 8px;border:1px solid var(--border-color);border-radius:6px;background:var(--bg-secondary,var(--card-bg));">
                    <input type="hidden" name="nav_order[]" value="<?= htmlspecialchars($_nk) ?>">
                    <span style="flex:1;"><?= htmlspecialchars($_navReg[$_nk][2]) ?></span>
                    <button type="button" class="nav-order-up" style="padding:2px 8px;cursor:pointer;"
                            aria-label="<?= htmlspecialchars(t('move_up', 'Posunout nahoru')) ?>"
                            title="<?= htmlspecialchars(t('move_up', 'Posunout nahoru')) ?>">▲</button>
                    <button type="button" class="nav-order-down" style="padding:2px 8px;cursor:pointer;"
                            aria-label="<?= htmlspecialchars(t('move_down', 'Posunout dolů')) ?>"
                            title="<?= htmlspecialchars(t('move_down', 'Posunout dolů')) ?>">▼</button>
                </li>
            <?php endforeach; ?>
            </ul>
            <script>
            (function () {
                const list = document.getElementById('navOrderList');
                if (!list) return;
                list.addEventListener('click', function (e) {
                    const btn = e.target.closest('button');
                    if (!btn) return;
                    const li = btn.closest('li');
                    if (btn.classList.contains('nav-order-up') && li.previousElementSibling) {
                        li.parentNode.insertBefore(li, li.previousElementSibling);
                        btn.focus();
                    } else if (btn.classList.contains('nav-order-down') && li.nextElementSibling) {
                        li.parentNode.insertBefore(li.nextElementSibling, li);
                        btn.focus();
                    }
                });
            })();
            </script>
        </div>

        <div style="grid-column:1/-1;">
            <button type="submit" name="save_access_config" class="btn" style="font-size:14px;padding:10px 24px;">
                💾 <?= htmlspecialchars(t('admin_save_access', 'Uložit nastavení přístupu')) ?>
            </button>
            <p style="font-size:11px;color:var(--text-muted);margin-top:6px;">
                <?= htmlspecialchars(t('admin_save_access_hint', 'Minimálně 1 téma a 1 jazyk musí zůstat aktivní (fallback na Classic / Čeština).')) ?>
            </p>
        </div>

    </form>
</div>

<!-- ===== KONFIGURACE CEST K UPLOADS ===== -->
<?php
    $cfgUploadsFs  = (string)get_app_config('uploads_fs_path', '');
    $cfgUploadsUrl = (string)get_app_config('uploads_url', '');
    $effectiveFs   = uploads_fs('');
    $effectiveUrl  = uploads_url('');
    $fsExists      = is_dir($effectiveFs);
    $fsWritable    = $fsExists && is_writable($effectiveFs);
    $sampleThumb   = uploads_fs('thumbs/');
    $thumbExists   = is_dir($sampleThumb);
?>
<div class="card-outdoor p-5 mt-6">
    <h3 class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 mb-1 flex items-center gap-2">
        <i data-lucide="folder-cog" class="w-5 h-5 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('admin_uploads_config', 'Konfigurace cest k uploads/')) ?>
    </h3>
    <p class="text-sm text-forest-700/65 dark:text-sand-100/65 mb-4">
        <?= htmlspecialchars(t('admin_uploads_config_hint', 'Pokud chceš sdílet adresář uploads/ mezi více instancemi, nastav zde absolutní cestu. Ponech prázdné pro použití lokálního ./uploads/.')) ?>
    </p>
    <?php if (isset($_GET['saved']) && $_GET['saved'] === 'uploads'): ?>
        <div class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-md bg-forest-100 dark:bg-forest-700 text-forest-700 dark:text-sand-100 text-sm">
            <i data-lucide="check-circle" class="w-4 h-4" aria-hidden="true"></i> <?= htmlspecialchars(t('admin_uploads_saved', 'Konfigurace cest uložena')) ?>
        </div>
    <?php endif; ?>

    <form method="post" class="space-y-4">
        <?= csrf_field() ?>
        <label class="block">
            <span class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1">
                Filesystem path <span class="lowercase tracking-normal opacity-70">(pro PHP — čtení GPX, fotek, thumbnailů)</span>
            </span>
            <input type="text" name="uploads_fs_path" value="<?= h($cfgUploadsFs) ?>"
                   placeholder="např. /var/www/html/uploads nebo C:/wamp64/www/gpx/uploads"
                   class="w-full px-3 py-2 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 font-mono text-sm">
            <span class="block mt-1 text-xs text-forest-700/55 dark:text-sand-100/55">
                Aktuálně se používá: <code class="text-forest-700 dark:text-sand-100"><?= h($effectiveFs) ?></code>
                <?php if ($fsExists): ?>
                    <span class="text-forest-600 ml-1">✓ existuje</span>
                    <?= $fsWritable ? '<span class="text-forest-600">+ zapisovatelná</span>' : '<span class="text-terracotta-500">(jen pro čtení)</span>' ?>
                <?php else: ?>
                    <span class="text-terracotta-500 ml-1">✗ neexistuje</span>
                <?php endif; ?>
            </span>
        </label>

        <label class="block">
            <span class="block text-xs uppercase tracking-wider text-forest-700/65 dark:text-sand-100/65 mb-1">
                URL prefix <span class="lowercase tracking-normal opacity-70">(pro browser — img src, odkazy ke stažení)</span>
            </span>
            <input type="text" name="uploads_url" value="<?= h($cfgUploadsUrl) ?>"
                   placeholder="např. https://yourdomain.com/gpx/uploads/"
                   class="w-full px-3 py-2 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 font-mono text-sm">
            <span class="block mt-1 text-xs text-forest-700/55 dark:text-sand-100/55">
                Aktuálně se používá: <code class="text-forest-700 dark:text-sand-100"><?= h($effectiveUrl) ?></code>
            </span>
        </label>

        <div class="pt-2 border-t border-sand-200 dark:border-forest-700 flex items-center gap-3 flex-wrap">
            <button type="submit" name="save_uploads_config" class="btn-outdoor btn-outdoor-primary">
                <i data-lucide="save" class="w-4 h-4" aria-hidden="true"></i> Uložit cesty
            </button>
            <span class="text-xs text-forest-700/60 dark:text-sand-100/60">
                Tip: prázdné hodnoty = výchozí lokální <code>./uploads/</code>
            </span>
        </div>
    </form>
</div>

<div style="margin:20px 0; font-size:12px; color:var(--text-muted);">
    MySQL: <?= h($dbVersion) ?> | <?= t('db_label') ?>: <?= DB_NAME ?> | <?= t('server_label') ?>: <?= APP_ENV ?> | GPX Manager v1.0
</div>

</div><?php require __DIR__ . '/includes/layout_footer.php'; ?>