<?php
declare(strict_types=1);

/**
 * Administrace (nová, výchozí verze) — POST handlery a data pro includes/admin_new_view.php.
 *
 * Ukládá do stejných klíčů app_config a přijímá stejné názvy polí formuláře
 * jako původní verze (admin_classic.php), takže mezi nimi jde přepínat.
 * Logika uložení je zatím v obou souborech zvlášť — při změně upravit obě.
 *
 * Očekává: $pdo (includes/db.php), přihlášeného admina (includes/auth.php).
 */

require_once __DIR__ . '/radar_helper.php';
require_once __DIR__ . '/deploy_status.php';

const ADMIN_NEW_URL = 'admin.php';

/* ===== POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Invalid CSRF token');
    }

    if (isset($_POST['save_access_config'])) {
        header('Location: ' . ADMIN_NEW_URL . '?saved=' . admin_new_save_settings($_POST) . '#nastaveni');
        exit;
    }

    if (isset($_POST['save_uploads_config'])) {
        set_app_config('uploads_fs_path', trim((string)($_POST['uploads_fs_path'] ?? '')));
        set_app_config('uploads_url',     trim((string)($_POST['uploads_url'] ?? '')));
        header('Location: ' . ADMIN_NEW_URL . '?saved=uploads#system');
        exit;
    }
}

/**
 * Uloží formulář „Nastavení webu" (návštěvníci, menu, mapy, funkce).
 * Vrací kód pro hlášku: 'access' = uloženo, 'nobase' = odmítnuto.
 */
function admin_new_save_settings(array $post): string
{
    // all_pages() nezná 'photos', 'planner' a 'story' — ty se návštěvníkům
    // zpřístupňují zvlášť (plánovač čerpá kvótu Mapy.com, cestopis je volitelná funkce).
    $pageKeys = array_merge(all_pages(), ['photos', 'planner', 'story']);

    $langs = array_values(array_intersect((array)($post['allowed_langs'] ?? []), all_langs()));
    $pages = array_values(array_intersect((array)($post['allowed_pages'] ?? []), $pageKeys));
    if (!$langs) {
        $langs = ['cs'];
    }

    // Pořadí menu — jen známé klíče, chybějící (nové stránky) na konec
    $navKeys  = array_keys(nav_menu_items());
    $navOrder = array_values(array_intersect((array)($post['nav_order'] ?? []), $navKeys));
    foreach ($navKeys as $k) {
        if (!in_array($k, $navOrder, true)) {
            $navOrder[] = $k;
        }
    }

    // Volitelné funkce — nezaškrtnuté = vypnuto
    $postedFlags = (array)($post['features'] ?? []);
    $flags = [];
    foreach (array_keys(feature_flag_labels()) as $fk) {
        $flags[$fk] = in_array($fk, $postedFlags, true);
    }

    // Vrstvy map. Bez jediné podkladové mapy by mapa byla prázdná plocha,
    // proto se v tom případě neuloží nic (stejně jako v původní verzi).
    $layerDefs = map_layer_defs();
    $onPosted  = (array)($post['map_layers_on'] ?? []);
    $orderPost = (array)($post['map_layers_order'] ?? []);

    $baseOn = array_filter(array_keys($layerDefs), static function ($k) use ($layerDefs, $onPosted) {
        return $layerDefs[$k]['section'] === 'base' && in_array($k, $onPosted, true);
    });
    if (!$baseOn) {
        return 'nobase';
    }

    $off   = array_values(array_diff(array_keys($layerDefs), $onPosted));
    $order = [];
    foreach (['base', 'overlay'] as $sec) {
        $known = array_keys(array_filter($layerDefs, static fn($d) => $d['section'] === $sec));
        $order[$sec] = array_values(array_intersect((array)($orderPost[$sec] ?? []), $known));
        foreach ($known as $k) {
            if (!in_array($k, $order[$sec], true)) {
                $order[$sec][] = $k;
            }
        }
    }

    $mh = (string)($post['map_height'] ?? 'mid');
    if (!array_key_exists($mh, map_height_options())) {
        $mh = 'mid';
    }

    set_app_config('map_layers_off',   $off);
    set_app_config('map_layers_order', $order);
    set_app_config('map_height',       $mh);
    set_app_config('map_pages_full',   !empty($post['map_pages_full']));
    set_app_config('allowed_langs',    $langs);
    set_app_config('visible_pages',    $pages);
    set_app_config('nav_order',        $navOrder);
    set_app_config('feature_flags',    $flags);

    return 'access';
}

/** Popisek bez úvodního emoji — nová verze má místo nich ikony. */
function admin_new_label(string $s): string
{
    // ℹ (U+2139) je pro regex písmeno, proto zvlášť
    return trim((string)preg_replace('/^(?:[^\p{L}\p{N}(]|\x{2139})+/u', '', $s));
}

/* ===== Počty pro přehled ===== */
$adm = [];
$adm['tracks'] = (int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
$adm['cats']   = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
$adm['favs']   = (int)$pdo->query('SELECT COUNT(*) FROM tracks WHERE is_favorite = 1')->fetchColumn();

$adm['no_difficulty'] = 0;
try {
    $adm['no_difficulty'] = (int)$pdo->query('SELECT COUNT(*) FROM tracks WHERE difficulty IS NULL')->fetchColumn();
} catch (Throwable $e) {
    error_log('admin_new difficulty query: ' . $e->getMessage());
}

// Trasy bez kategorie aktivity (stejná definice jako v původní administraci)
$_actCats = ['Pěšky', 'Turistika', 'Běh', 'Kolo', 'E-bike', 'Auto'];
$_st = $pdo->prepare('SELECT COUNT(DISTINCT tc.track_id) FROM track_categories tc
                      JOIN categories c ON c.id = tc.category_id
                      WHERE c.name IN (' . implode(',', array_fill(0, count($_actCats), '?')) . ')');
$_st->execute($_actCats);
$adm['no_activity'] = max(0, $adm['tracks'] - (int)$_st->fetchColumn());

// Trasy bez zjištěného místa pro titulek (sloupec přidala migrace 0019)
$adm['no_place'] = 0;
try {
    $adm['no_place'] = (int)$pdo->query('SELECT COUNT(*) FROM tracks WHERE place_name IS NULL')->fetchColumn();
} catch (Throwable $e) {
    // před migrací 0019 sloupec neexistuje
}

// Radar ČHMÚ drží archiv jen ~7 dní — hlídají se jen čerstvé trasy bez snímků
$adm['radar'] = [];
try {
    $_have = array_keys(radar_counts());
    $_list = $_have ? implode(',', array_map('intval', $_have)) : '0';
    $_st = $pdo->prepare("SELECT id, track_name, date_start FROM tracks
                          WHERE id NOT IN ($_list)
                            AND date_start IS NOT NULL AND date_start >= :since
                          ORDER BY date_start DESC");
    $_st->execute([':since' => date('Y-m-d H:i:s', time() - RADAR_ARCHIVE_D * 86400)]);
    $adm['radar'] = $_st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('admin_new radar pending: ' . $e->getMessage());
}

// Náhledy: trasy bez PNG a PNG bez trasy (osiřelé)
$adm['no_thumb'] = 0;
$adm['orphan_thumbs'] = 0;
$_thumbDir = uploads_fs('thumbs/');
if (is_dir($_thumbDir)) {
    $_expected = [];
    foreach ($pdo->query('SELECT filename FROM tracks')->fetchAll(PDO::FETCH_COLUMN) as $_fn) {
        $_name = pathinfo((string)$_fn, PATHINFO_FILENAME) . '.png';
        $_expected[$_name] = true;
        if (!is_file($_thumbDir . $_name)) {
            $adm['no_thumb']++;
        }
    }
    foreach (glob($_thumbDir . '*.png') ?: [] as $_png) {
        if (!isset($_expected[basename($_png)])) {
            $adm['orphan_thumbs']++;
        }
    }
} else {
    $adm['no_thumb'] = $adm['tracks'];
}

// Velikost GPX souborů
$_bytes = 0;
$_upDir = uploads_fs();
if (is_dir($_upDir)) {
    foreach (glob($_upDir . '*.gpx') ?: [] as $_f) {
        $_bytes += (int)filesize($_f);
    }
}
$adm['gpx_mb'] = round($_bytes / 1024 / 1024, 1);

$adm['php']    = PHP_VERSION;
$adm['db']     = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
$adm['deploy'] = deploy_status();

/* ===== Hodnoty formulářů ===== */
$adm['cfg_langs']   = available_langs();
$adm['cfg_pages']   = (array)get_app_config('visible_pages', all_pages());
$adm['map_height']  = (string)get_app_config('map_height', 'mid');
$adm['map_full']    = (bool)get_app_config('map_pages_full', true);

$adm['uploads_fs_cfg']  = (string)get_app_config('uploads_fs_path', '');
$adm['uploads_url_cfg'] = (string)get_app_config('uploads_url', '');
$adm['uploads_fs']      = uploads_fs('');
$adm['uploads_url']     = uploads_url('');
$adm['uploads_exists']  = is_dir($adm['uploads_fs']);
$adm['uploads_write']   = $adm['uploads_exists'] && is_writable($adm['uploads_fs']);

/* ===== Úkoly „vyžaduje pozornost" — jen nenulové ===== */
$adm['todo'] = array_values(array_filter([
    ['n' => $adm['no_difficulty'], 'icon' => 'gauge',     'label' => t('info_no_difficulty', 'Bez obtížnosti'),
     'href' => 'recalc_difficulty.php', 'action' => admin_new_label(t('tool_recalc_diff', 'Přepočítat obtížnost'))],
    ['n' => $adm['no_activity'],   'icon' => 'footprints', 'label' => t('info_no_activity', 'Bez aktivity'),
     'href' => 'recalc_activity.php',   'action' => admin_new_label(t('tool_recalc_act', 'Rozpoznat aktivity'))],
    ['n' => $adm['no_thumb'],      'icon' => 'image-off',  'label' => t('info_no_thumb', 'Bez náhledu'),
     'href' => 'rebuild_thumbs.php',    'action' => admin_new_label(t('tool_rebuild_thumbs', 'Přegenerovat náhledy'))],
    ['n' => $adm['no_place'],      'icon' => 'map-pin-off', 'label' => t('adm2_no_place', 'Bez názvu místa'),
     'href' => 'rebuild_places.php',    'action' => admin_new_label(t('tool_rebuild_places', 'Doplnit názvy míst'))],
    ['n' => count($adm['radar']),  'icon' => 'cloud-rain', 'label' => t('adm2_radar_missing', 'Bez radaru (posledních 7 dní)'),
     'href' => 'index-legacy.php?radar=todo&filter_submit=1', 'action' => t('adm2_show_tracks', 'Zobrazit trasy')],
], static fn($i) => $i['n'] > 0));
