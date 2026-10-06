<?php
declare(strict_types=1);

/**
 * Administrace (nová verze) — HTML. Data: $adm z includes/admin_new_data.php.
 * Styly: css/admin-new.css (vlastní nevrstvené CSS, ne Tailwind utility —
 * app.css je commitnutý build a nové utility by v něm chyběly).
 * Chování (řazení, neuložené změny, zvýraznění sekce): js/admin-new.js.
 *
 * Pozor na strict_types: h() bere jen string, čísla se vypisují přímo nebo přes (string).
 */

$_saved = (string)($_GET['saved'] ?? '');
$_dep   = $adm['deploy'];
$_short = static fn(?string $sha): string => $sha ? substr($sha, 0, 7) : '—';

$_langNames = ['cs' => '🇨🇿 Čeština', 'en' => '🇬🇧 English', 'de' => '🇩🇪 Deutsch', 'sk' => '🇸🇰 Slovenčina',
               'es' => '🇪🇸 Español', 'fr' => '🇫🇷 Français', 'pl' => '🇵🇱 Polski', 'it' => '🇮🇹 Italiano'];

// Stránky pro návštěvníky: [ikona, popisek, poznámka]
$_nav = nav_menu_items();
$_navLabel = static fn(string $k, string $fallback): string => isset($_nav[$k]) ? (string)$_nav[$k][2] : $fallback;
$_pages = [
    'stats'          => ['bar-chart-2', $_navLabel('stats', 'Statistiky'), ''],
    'calendar'       => ['calendar',    $_navLabel('calendar', 'Kalendář'), ''],
    'heatmap'        => ['flame',       $_navLabel('heatmap', 'Heatmapa'), ''],
    'photo_heatmap'  => ['camera',      $_navLabel('photo_heatmap', 'Foto-heatmapa'), ''],
    'virtual_tracks' => ['route',       $_navLabel('virtual_tracks', 'Virtuální trasy'), ''],
    'map_search'     => ['scan-search', admin_new_label(t('tool_map_search', 'Hledat na mapě')), ''],
    'nearby'         => ['map-pin',     $_navLabel('nearby', 'Trasy v okolí'), ''],
    'photo_nearby'   => ['aperture',    $_navLabel('photo_nearby', 'Fotky v okolí'), ''],
    'photos'         => ['image',       $_navLabel('photos', 'Fotografie'), t('adm2_hint_photos', 'jen prohlížení')],
    'compare'        => ['git-compare', t('h1_compare', 'Porovnání tras'), ''],
    'filter'         => ['eraser',      admin_new_label(t('tool_gpx_cleaner', 'GPX Cleaner')), ''],
    'settings'       => ['sliders-horizontal', admin_new_label(t('tool_settings', 'Nastavení')), ''],
    'links'          => ['compass',     $_navLabel('links', 'Podobné weby'), ''],
    'planner'        => ['signpost',    $_navLabel('planner', 'Plánovač'), t('adm2_hint_planner', 'bez ukládání, čerpá kvótu mapového API')],
    'story'          => ['book-open',   t('adm2_page_story', 'Cestopis'), t('adm2_hint_story', 'jen zveřejněné texty, funkce musí být zapnutá')],
];

$_sections = [
    'prehled'     => ['layout-dashboard', t('adm2_sec_overview', 'Přehled')],
    'udrzba'      => ['wrench',           t('adm2_sec_maintenance', 'Údržba dat')],
    'navstevnici' => ['users',            t('adm2_sec_visitors', 'Návštěvníci')],
    'menu'        => ['menu',             t('admin_nav_order', 'Pořadí horního menu')],
    'mapy'        => ['map',              t('adm2_sec_maps', 'Mapy')],
    'funkce'      => ['toggle-right',     t('admin_features', 'Volitelné funkce')],
    'system'      => ['server',           t('adm2_sec_system', 'Systém')],
    'stranky'     => ['layout-grid',      t('adm2_sec_pages', 'Stránky aplikace')],
];

/** Dlaždice nástroje (odkaz). $badge > 0 = počet tras, kterých se týká. */
$_tool = static function (string $href, string $icon, string $title, string $desc, int $badge = 0): void { ?>
    <a class="an-tool" href="<?= h($href) ?>">
        <span class="an-tool-icon"><i data-lucide="<?= h($icon) ?>" aria-hidden="true"></i></span>
        <span class="an-tool-body">
            <span class="an-tool-title"><?= h($title) ?></span>
            <span class="an-tool-desc"><?= h($desc) ?></span>
        </span>
        <?php if ($badge > 0): ?><span class="an-badge an-badge-warn"><?= $badge ?></span><?php endif; ?>
    </a>
<?php };

/** Řádek se zapínačem (checkbox stylovaný jako přepínač). */
$_switch = static function (string $name, string $value, bool $on, string $label, string $note = '', string $icon = ''): void { ?>
    <label class="an-switch-row">
        <input type="checkbox" class="an-switch" name="<?= h($name) ?>" value="<?= h($value) ?>" <?= $on ? 'checked' : '' ?>>
        <?php if ($icon !== ''): ?><i data-lucide="<?= h($icon) ?>" class="an-row-icon" aria-hidden="true"></i><?php endif; ?>
        <span class="an-switch-text">
            <span><?= h($label) ?></span>
            <?php if ($note !== ''): ?><span class="an-note"><?= h($note) ?></span><?php endif; ?>
        </span>
    </label>
<?php };

/** Tlačítka pro posun položky v seznamu. */
$_moveBtns = static function (): void { ?>
    <span class="an-move">
        <button type="button" class="an-move-btn" data-move="up" aria-label="<?= h(t('move_up', 'Posunout nahoru')) ?>" title="<?= h(t('move_up', 'Posunout nahoru')) ?>"><i data-lucide="chevron-up" aria-hidden="true"></i></button>
        <button type="button" class="an-move-btn" data-move="down" aria-label="<?= h(t('move_down', 'Posunout dolů')) ?>" title="<?= h(t('move_down', 'Posunout dolů')) ?>"><i data-lucide="chevron-down" aria-hidden="true"></i></button>
    </span>
<?php };
?>
<link rel="stylesheet" href="<?= h(asset('css/admin-new.css')) ?>">

<div class="an-page">

<header class="an-head">
    <div>
        <a href="index.php" class="an-back"><i data-lucide="arrow-left" aria-hidden="true"></i><?= h(admin_new_label(t('back_to_list', 'Zpět na přehled tras'))) ?></a>
        <h1 class="an-title"><i data-lucide="shield" aria-hidden="true"></i><?= h(t('nav_admin', 'Administrace')) ?></h1>
    </div>
    <?php require_once __DIR__ . '/partials/admin_ui_switch.php'; render_admin_ui_switch('new'); ?>
</header>

<div class="an-layout">

<nav class="an-nav" aria-label="<?= h(t('adm2_sections', 'Sekce administrace')) ?>">
    <?php foreach ($_sections as $_id => [$_ic, $_lb]): ?>
        <a href="#<?= h($_id) ?>" data-section="<?= h($_id) ?>">
            <i data-lucide="<?= h($_ic) ?>" aria-hidden="true"></i><span><?= h($_lb) ?></span>
            <?php if ($_id === 'prehled' && $adm['todo']): ?><span class="an-badge an-badge-warn"><?= count($adm['todo']) ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<div class="an-main">

<!-- ===== PŘEHLED ===== -->
<section id="prehled" class="an-section">
    <h2 class="an-h2"><?= h(t('adm2_sec_overview', 'Přehled')) ?></h2>

    <div class="an-stats">
        <div class="an-stat"><span class="an-stat-value"><?= $adm['tracks'] ?></span><span class="an-stat-label"><?= h(t('info_total_tracks', 'Celkem tras')) ?></span></div>
        <div class="an-stat"><span class="an-stat-value"><?= $adm['cats'] ?></span><span class="an-stat-label"><?= h(t('info_categories', 'Kategorií')) ?></span></div>
        <div class="an-stat"><span class="an-stat-value"><?= $adm['favs'] ?></span><span class="an-stat-label"><?= h(t('info_favorites', 'Oblíbených')) ?></span></div>
        <div class="an-stat"><span class="an-stat-value"><?= h(number_format($adm['gpx_mb'], 1, ',', ' ')) ?> <small>MB</small></span><span class="an-stat-label"><?= h(t('info_gpx_files', 'GPX soubory')) ?></span></div>
    </div>

    <div class="an-card">
        <h3 class="an-h3"><i data-lucide="list-checks" aria-hidden="true"></i><?= h(t('adm2_attention', 'Vyžaduje pozornost')) ?></h3>
        <?php if (!$adm['todo']): ?>
            <p class="an-ok"><i data-lucide="check-circle" aria-hidden="true"></i><?= h(t('adm2_all_ok', 'Vše v pořádku — všechny trasy mají obtížnost, aktivitu, náhled i název místa.')) ?></p>
        <?php else: ?>
            <ul class="an-todo">
                <?php foreach ($adm['todo'] as $_t): ?>
                    <li>
                        <i data-lucide="<?= h($_t['icon']) ?>" class="an-row-icon" aria-hidden="true"></i>
                        <span class="an-todo-label">
                            <?= h($_t['label']) ?>
                            <?php if ($_t['icon'] === 'cloud-rain'): ?>
                                <span class="an-note">
                                    <?php foreach (array_slice($adm['radar'], 0, 5) as $_rp): ?>
                                        <a href="detail.php?id=<?= (int)$_rp['id'] ?>">#<?= (int)$_rp['id'] ?> <?= h(date('j. n.', (int)strtotime((string)$_rp['date_start']))) ?></a>
                                    <?php endforeach; ?>
                                </span>
                            <?php elseif ($_t['icon'] === 'image-off' && $adm['orphan_thumbs'] > 0): ?>
                                <span class="an-note"><?= h(str_replace('{n}', (string)$adm['orphan_thumbs'], t('adm2_orphan_thumbs', 'a {n} osiřelých náhledů bez trasy'))) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="an-todo-count"><?= (int)$_t['n'] ?></span>
                        <a class="an-btn an-btn-small" href="<?= h($_t['href']) ?>"><?= h($_t['action']) ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="an-card">
        <h3 class="an-h3"><i data-lucide="rocket" aria-hidden="true"></i><?= h(t('admin_deploy', 'Nasazení')) ?></h3>
        <?php if (empty($_dep['git'])): ?>
            <p class="an-muted"><?= h(t('deploy_no_git', 'Tato instalace neběží z gitu — stav nasazení nelze zjistit.')) ?></p>
        <?php else:
            $_same  = $_dep['head'] && $_dep['remote'] && $_dep['head'] === $_dep['remote'];
            $_info  = $_dep['info'] ?? null;
            $_ago   = $_dep['fetched_at'] ? (int)floor((time() - (int)$_dep['fetched_at']) / 60) : null;
            // Cron kontroluje GitHub každé 3 minuty; po 10 minutách ticha je něco špatně
            $_stale = APP_ENV !== 'local' && !empty($_dep['deploy_readable']) && $_ago !== null && $_ago > 10;
        ?>
            <dl class="an-dl">
                <dt><?= h(t('deploy_commit', 'Web běží na')) ?></dt>
                <dd><code><?= h($_short($_dep['head'])) ?></code>
                    <?php if (!empty($_info['subject'])): ?><?= h(mb_strimwidth((string)$_info['subject'], 0, 90, '…')) ?><?php endif; ?>
                    <?php if (!empty($_info['time'])): ?><span class="an-muted">(<?= h(date('j. n. Y H:i', (int)$_info['time'])) ?>)</span><?php endif; ?>
                </dd>
                <dt><?= h(t('deploy_github', 'GitHub')) ?></dt>
                <dd><code><?= h($_short($_dep['remote'])) ?></code>
                    <?php if ($_same): ?><span class="an-badge an-badge-ok"><?= h(t('deploy_uptodate', 'aktuální')) ?></span>
                    <?php elseif ($_dep['remote']): ?><span class="an-badge an-badge-warn"><?= h(t('deploy_behind', 'na GitHubu je jiná verze — nasazení čeká nebo selhalo, viz log níže')) ?></span><?php endif; ?>
                </dd>
                <?php if ($_ago !== null): ?>
                    <dt><?= h(t('deploy_last_check', 'poslední kontrola GitHubu')) ?></dt>
                    <dd class="<?= $_stale ? 'an-text-err' : '' ?>"><?= h(date('j. n. H:i', (int)$_dep['fetched_at'])) ?>
                        <?php if ($_stale): ?>— <?= h(t('deploy_stale', 'déle než 10 minut, nasazovací cron možná neběží')) ?><?php endif; ?></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>

        <details class="an-details">
            <summary><?= h(t('deploy_log_title', 'Poslední záznamy nasazení')) ?>
                <?php if (!empty($_dep['deploy_lines'])): ?><span class="an-badge"><?= count($_dep['deploy_lines']) ?></span><?php endif; ?></summary>
            <?php if (empty($_dep['deploy_readable'])): ?>
                <p class="an-muted"><?= h(t('deploy_log_na', 'Log nasazení není z webu čitelný.')) ?> <code><?= h((string)($_dep['deploy_log'] ?? '')) ?></code></p>
            <?php elseif (empty($_dep['deploy_lines'])): ?>
                <p class="an-muted"><?= h(t('deploy_log_empty', 'Log nasazení je prázdný.')) ?></p>
            <?php else: ?>
                <ul class="an-log">
                    <?php foreach (array_reverse($_dep['deploy_lines']) as $_l): ?>
                        <li><span class="an-dot an-dot-<?= h((string)$_l['state']) ?>"></span>
                            <span class="an-muted"><?= h((string)$_l['time']) ?></span>
                            <span><?= h(mb_strimwidth((string)$_l['text'], 0, 220, '…')) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </details>

        <details class="an-details">
            <summary><?= h(t('deploy_csp_title', 'Zablokovaný obsah (CSP)')) ?>
                <?php if (!empty($_dep['csp_count'])): ?><span class="an-badge an-badge-warn"><?= (int)$_dep['csp_count'] ?></span><?php endif; ?></summary>
            <?php if (empty($_dep['csp_lines'])): ?>
                <p class="an-muted"><?= h(t('deploy_csp_none', 'Žádná hlášení — prohlížeče nic neblokovaly.')) ?></p>
            <?php else: ?>
                <ul class="an-log">
                    <?php foreach (array_reverse($_dep['csp_lines']) as $_c): ?>
                        <li><span class="an-muted"><?= h((string)$_c['time']) ?></span>
                            <code><?= h((string)$_c['directive']) ?></code> <?= h((string)$_c['page']) ?>
                            <?php if ((string)$_c['sample'] !== ''): ?><span class="an-muted">— „<?= h(mb_strimwidth((string)$_c['sample'], 0, 60, '…')) ?>"</span><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="an-note"><?= h(t('deploy_csp_hint', 'Každé hlášení = něco, co prohlížeč nespustil nebo nenačetl. Záznamy označené TEST jsou zkušební.')) ?></p>
            <?php endif; ?>
        </details>
    </div>
</section>

<!-- ===== ÚDRŽBA DAT ===== -->
<section id="udrzba" class="an-section">
    <h2 class="an-h2"><?= h(t('adm2_sec_maintenance', 'Údržba dat')) ?></h2>

    <h3 class="an-h3 an-h3-plain"><?= h(t('adm2_import_export', 'Import a export')) ?></h3>
    <div class="an-tools">
        <?php
        $_tool('import.php', 'upload', admin_new_label(t('tool_import', 'Importovat trasy')), t('desc_import', 'Nahrát nové GPX soubory do databáze'));
        $_tool('photo_import.php', 'folder-input', admin_new_label(t('tool_photo_import', 'Lokální import fotek')), t('desc_photo_import', 'Skenuje adresář na PC, vybere a importuje fotky hromadně'));
        $_tool('export.php?' . http_build_query(['filter_submit' => 1]), 'file-spreadsheet', admin_new_label(t('tool_export_csv', 'Exportovat do CSV')), t('desc_export_csv', 'Stáhnout metadata všech tras jako CSV'));
        $_tool('zip_export.php?' . http_build_query(['filter_submit' => 1]), 'file-archive', admin_new_label(t('tool_export_zip', 'Exportovat GPX (ZIP)')), t('desc_export_zip', 'Stáhnout všechny GPX soubory jako ZIP archiv'));
        ?>
    </div>

    <h3 class="an-h3 an-h3-plain"><?= h(t('adm2_recalc', 'Hromadné přepočty')) ?></h3>
    <div class="an-tools">
        <?php
        $_tool('recalc_difficulty.php', 'gauge', admin_new_label(t('tool_recalc_diff', 'Přepočítat obtížnost')), t('desc_recalc_diff', 'Přepočte obtížnost (1–5) u všech tras'), $adm['no_difficulty']);
        $_tool('recalc_activity.php', 'footprints', admin_new_label(t('tool_recalc_act', 'Rozpoznat aktivity')), t('desc_recalc_act', 'Automaticky přiřadí typ aktivity'), $adm['no_activity']);
        $_tool('rebuild_thumbs.php', 'image', admin_new_label(t('tool_rebuild_thumbs', 'Přegenerovat náhledy')), t('desc_rebuild_thumbs', 'Znovu vytvoří náhledové obrázky map'), $adm['no_thumb']);
        $_tool('rebuild_places.php', 'map-pin', admin_new_label(t('tool_rebuild_places', 'Doplnit názvy míst')), t('desc_rebuild_places', 'Zjistí místo startu pro titulky tras pojmenovaných časovým razítkem'), $adm['no_place']);
        $_tool('index-legacy.php?radar=todo&filter_submit=1', 'cloud-rain', admin_new_label(t('tool_radar_pending', 'Radar k dotažení')), t('desc_radar_pending', 'Trasy z posledních 7 dní bez stažených radarových snímků'), count($adm['radar']));
        ?>
    </div>

    <h3 class="an-h3 an-h3-plain"><?= h(t('adm2_other_tools', 'Další nástroje')) ?></h3>
    <div class="an-tools">
        <?php
        $_tool('filter.php', 'eraser', admin_new_label(t('tool_gpx_cleaner', 'GPX Cleaner')), t('desc_gpx_cleaner', 'Vyčistit GPX soubor (spikey, drifty, zjednodušení)'));
        $_tool('settings.php', 'sliders-horizontal', admin_new_label(t('tool_settings', 'Nastavení')), t('desc_settings', 'Jednotky (km/míle), výchozí mapová vrstva, jazyk'));
        ?>
    </div>
</section>

<!-- ===== NASTAVENÍ WEBU (jeden formulář, jedno uložení) ===== -->
<form method="post" id="nastaveni" class="an-settings" data-admin-settings autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="save_access_config" value="1">
    <?php if ($_saved === 'access'): ?>
        <div class="an-flash an-flash-ok" role="status"><i data-lucide="check-circle" aria-hidden="true"></i><?= h(t('admin_access_saved', 'Konfigurace přístupu uložena')) ?></div>
    <?php elseif ($_saved === 'nobase'): ?>
        <div class="an-flash an-flash-err" role="alert"><i data-lucide="alert-triangle" aria-hidden="true"></i><?= h(t('admin_nobase_error', 'Neuloženo: aspoň jedna podkladová mapa musí zůstat zapnutá.')) ?></div>
    <?php endif; ?>

    <section id="navstevnici" class="an-section">
        <h2 class="an-h2"><?= h(t('adm2_sec_visitors', 'Návštěvníci')) ?></h2>
        <p class="an-lead"><?= h(t('admin_visible_pages_hint', 'Admin vidí vždy vše. Odškrtnuté stránky přesměrují návštěvníky na hlavní stránku.')) ?></p>
        <div class="an-grid-2">
            <div class="an-card">
                <h3 class="an-h3"><i data-lucide="languages" aria-hidden="true"></i><?= h(t('admin_available_langs', 'Dostupné jazyky')) ?></h3>
                <div class="an-switch-list">
                    <?php foreach ($_langNames as $_lc => $_ln) {
                        $_switch('allowed_langs[]', $_lc, in_array($_lc, $adm['cfg_langs'], true), $_ln);
                    } ?>
                </div>
            </div>
            <div class="an-card">
                <h3 class="an-h3"><i data-lucide="eye" aria-hidden="true"></i><?= h(t('admin_visible_pages', 'Viditelné stránky pro návštěvníky')) ?></h3>
                <div class="an-switch-list">
                    <?php foreach ($_pages as $_pk => [$_pi, $_pl, $_pn]) {
                        $_switch('allowed_pages[]', $_pk, in_array($_pk, $adm['cfg_pages'], true), $_pl, $_pn, $_pi);
                    } ?>
                </div>
            </div>
        </div>
    </section>

    <section id="menu" class="an-section">
        <h2 class="an-h2"><?= h(t('admin_nav_order', 'Pořadí horního menu')) ?></h2>
        <p class="an-lead"><?= h(t('admin_nav_order_hint', 'Šipkami změň pořadí položek v horním menu. Platí pro admina i návštěvníky.')) ?></p>
        <div class="an-card">
            <ol class="an-order" data-orderable>
                <?php foreach (nav_menu_order() as $_nk): ?>
                    <li>
                        <input type="hidden" name="nav_order[]" value="<?= h($_nk) ?>">
                        <i data-lucide="<?= h((string)$_nav[$_nk][1]) ?>" class="an-row-icon" aria-hidden="true"></i>
                        <span class="an-order-label"><?= h((string)$_nav[$_nk][2]) ?></span>
                        <?php $_moveBtns(); ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <section id="mapy" class="an-section">
        <h2 class="an-h2"><?= h(t('adm2_sec_maps', 'Mapy')) ?></h2>
        <div class="an-card">
            <h3 class="an-h3"><i data-lucide="ruler" aria-hidden="true"></i><?= h(t('admin_map_size', 'Velikost map')) ?></h3>
            <p class="an-note"><?= h(t('admin_map_size_hint', 'Platí pro všechny mapy v aplikaci — detail trasy, plánovač, okolí, porovnání i heatmapy.')) ?></p>
            <div class="an-map-size">
                <select name="map_height" class="an-input" aria-label="<?= h(t('admin_map_size', 'Velikost map')) ?>">
                    <?php foreach (map_height_options() as $_mk => $_ml): ?>
                        <option value="<?= h((string)$_mk) ?>" <?= $adm['map_height'] === $_mk ? 'selected' : '' ?>><?= h($_ml) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php $_switch('map_pages_full', '1', $adm['map_full'], t('admin_map_pages_full', 'Heatmapy a Hledání na mapě na celou výšku okna')); ?>
            </div>
        </div>

        <div class="an-card">
            <h3 class="an-h3"><i data-lucide="layers" aria-hidden="true"></i><?= h(t('admin_map_layers', 'Vrstvy map')) ?></h3>
            <p class="an-note"><?= h(t('admin_map_layers_hint', 'Odškrtnutá vrstva se v ovladači map vůbec nenabídne. Šipkami změň pořadí v seznamu. Platí pro všechny mapy v aplikaci.')) ?></p>
            <div class="an-grid-2">
                <?php $_defs = map_layer_defs();
                foreach (['base' => t('admin_map_layers_base', 'Podkladové mapy'), 'overlay' => t('admin_map_layers_overlay', 'Překryvné vrstvy')] as $_sec => $_secLabel): ?>
                    <div>
                        <h4 class="an-h4"><?= h($_secLabel) ?></h4>
                        <ol class="an-order" data-orderable>
                            <?php foreach (map_layer_order($_sec) as $_lk):
                                $_d = $_defs[$_lk];
                                $_missing = !empty($_d['needs']) && (!defined($_d['needs']) || constant($_d['needs']) === ''); ?>
                                <li class="<?= $_missing ? 'an-dim' : '' ?>">
                                    <input type="hidden" name="map_layers_order[<?= h($_sec) ?>][]" value="<?= h($_lk) ?>">
                                    <label class="an-switch-row">
                                        <input type="checkbox" class="an-switch" name="map_layers_on[]" value="<?= h($_lk) ?>" <?= map_layer_enabled($_lk) ? 'checked' : '' ?>>
                                        <span class="an-switch-text">
                                            <span><?= h(admin_new_label((string)$_d['label'])) ?></span>
                                            <?php if ($_missing): ?><span class="an-note"><?= h(t('mlayer_no_key', 'chybí API klíč, nezobrazí se')) ?></span><?php endif; ?>
                                        </span>
                                    </label>
                                    <?php $_moveBtns(); ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                <?php endforeach; ?>
            </div>
            <details class="an-details">
                <summary><?= h(t('admin_map_layers_ctx', 'Vrstvy vázané na konkrétní stránku')) ?></summary>
                <p class="an-note"><?= h(t('admin_map_layers_ctx_hint', 'Tyhle se odsud nevypínají — existují jen tam, kde mají smysl, a řídí se jinde.')) ?></p>
                <ul class="an-plain-list">
                    <?php foreach (map_context_layers() as $_cl => $_cw): ?>
                        <li><?= h(admin_new_label((string)$_cl)) ?> <span class="an-muted">— <?= h($_cw) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        </div>
    </section>

    <section id="funkce" class="an-section">
        <h2 class="an-h2"><?= h(t('admin_features', 'Volitelné funkce')) ?></h2>
        <p class="an-lead"><?= h(t('adm2_features_hint', 'Zapnutí a vypnutí volitelných funkcí. Cestopis je ve výchozím stavu vypnutý, ostatní zapnuté.')) ?></p>
        <div class="an-card">
            <div class="an-switch-list an-switch-list-2">
                <?php foreach (feature_flag_labels() as $_fk => $_fl) {
                    $_switch('features[]', (string)$_fk, feature_enabled((string)$_fk), admin_new_label($_fl));
                } ?>
            </div>
        </div>
    </section>

    <div class="an-savebar" data-savebar>
        <span class="an-savebar-text">
            <span data-state-clean><?= h(t('adm2_save_hint', 'Platí pro sekce Návštěvníci, Menu, Mapy a Funkce. Aspoň jeden jazyk a jedna podkladová mapa musí zůstat zapnuté.')) ?></span>
            <span data-state-dirty hidden><i data-lucide="circle-dot" aria-hidden="true"></i><?= h(t('adm2_unsaved', 'Máte neuložené změny')) ?></span>
        </span>
        <button type="button" class="an-btn an-btn-ghost" data-discard hidden><?= h(t('adm2_discard', 'Zahodit změny')) ?></button>
        <button type="submit" class="an-btn an-btn-primary"><i data-lucide="save" aria-hidden="true"></i><?= h(t('adm2_save', 'Uložit nastavení')) ?></button>
    </div>
</form>

<!-- ===== SYSTÉM ===== -->
<section id="system" class="an-section">
    <h2 class="an-h2"><?= h(t('adm2_sec_system', 'Systém')) ?></h2>
    <div class="an-grid-2 an-grid-wide">
        <form method="post" class="an-card" data-admin-uploads>
            <?= csrf_field() ?>
            <h3 class="an-h3"><i data-lucide="folder-cog" aria-hidden="true"></i><?= h(t('admin_uploads_config', 'Konfigurace cest k uploads/')) ?></h3>
            <?php if ($_saved === 'uploads'): ?>
                <div class="an-flash an-flash-ok" role="status"><i data-lucide="check-circle" aria-hidden="true"></i><?= h(t('admin_uploads_saved', 'Konfigurace cest uložena')) ?></div>
            <?php endif; ?>
            <p class="an-note"><?= h(t('admin_uploads_config_hint', 'Pokud chceš sdílet adresář uploads/ mezi více instancemi, nastav zde absolutní cestu. Ponech prázdné pro použití lokálního ./uploads/.')) ?></p>

            <label class="an-field">
                <span class="an-field-label"><?= h(t('adm2_fs_path', 'Cesta na disku')) ?> <span class="an-muted"><?= h(t('adm2_fs_path_hint', '(pro PHP — čtení GPX, fotek, náhledů)')) ?></span></span>
                <input type="text" name="uploads_fs_path" value="<?= h($adm['uploads_fs_cfg']) ?>" class="an-input an-mono"
                       placeholder="/var/www/html/uploads · C:/wamp64/www/gpx/uploads">
                <span class="an-note"><?= h(t('adm2_in_use', 'Aktuálně se používá:')) ?> <code><?= h($adm['uploads_fs']) ?></code>
                    <?php if (!$adm['uploads_exists']): ?><span class="an-badge an-badge-err"><?= h(t('adm2_dir_missing', 'neexistuje')) ?></span>
                    <?php elseif ($adm['uploads_write']): ?><span class="an-badge an-badge-ok"><?= h(t('adm2_dir_writable', 'zapisovatelná')) ?></span>
                    <?php else: ?><span class="an-badge an-badge-warn"><?= h(t('adm2_dir_readonly', 'jen pro čtení')) ?></span><?php endif; ?>
                </span>
            </label>

            <label class="an-field">
                <span class="an-field-label"><?= h(t('adm2_url_prefix', 'URL prefix')) ?> <span class="an-muted"><?= h(t('adm2_url_prefix_hint', '(pro prohlížeč — obrázky, odkazy ke stažení)')) ?></span></span>
                <input type="text" name="uploads_url" value="<?= h($adm['uploads_url_cfg']) ?>" class="an-input an-mono"
                       placeholder="https://example.com/gpx/uploads/">
                <span class="an-note"><?= h(t('adm2_in_use', 'Aktuálně se používá:')) ?> <code><?= h($adm['uploads_url']) ?></code></span>
            </label>

            <div class="an-form-foot">
                <button type="submit" name="save_uploads_config" value="1" class="an-btn an-btn-primary"><i data-lucide="save" aria-hidden="true"></i><?= h(t('adm2_save_paths', 'Uložit cesty')) ?></button>
                <span class="an-note"><?= h(t('adm2_paths_empty', 'Prázdné hodnoty = výchozí lokální ./uploads/')) ?></span>
            </div>
        </form>

        <div class="an-card">
            <h3 class="an-h3"><i data-lucide="info" aria-hidden="true"></i><?= h(t('adm2_server_info', 'Server')) ?></h3>
            <dl class="an-dl">
                <dt>PHP</dt><dd><?= h($adm['php']) ?></dd>
                <dt>MySQL</dt><dd><?= h($adm['db']) ?></dd>
                <dt><?= h(t('db_label', 'Databáze')) ?></dt><dd><?= h((string)DB_NAME) ?></dd>
                <dt><?= h(t('adm2_environment', 'Prostředí')) ?></dt><dd><?= h((string)APP_ENV) ?></dd>
                <dt><?= h(t('adm2_app_version', 'Verze aplikace')) ?></dt><dd>GPX Manager v1.0</dd>
            </dl>
            <div class="an-links">
                <a href="phpinfo.php" class="an-btn an-btn-ghost"><i data-lucide="file-code" aria-hidden="true"></i><?= h(admin_new_label(t('tool_php_info', 'PHP Info'))) ?></a>
                <a href="CHANGELOG.txt" target="_blank" rel="noopener" class="an-btn an-btn-ghost"><i data-lucide="history" aria-hidden="true"></i><?= h(admin_new_label(t('tool_changelog', 'Seznam změn'))) ?></a>
                <form method="post" action="login.php" data-confirm-text="<?= h(t('confirm_logout', 'Opravdu se chcete odhlásit?')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="an-btn an-btn-danger"><i data-lucide="log-out" aria-hidden="true"></i><?= h(t('logout', 'Odhlásit se')) ?></button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- ===== STRÁNKY APLIKACE ===== -->
<section id="stranky" class="an-section">
    <h2 class="an-h2"><?= h(t('adm2_sec_pages', 'Stránky aplikace')) ?></h2>
    <div class="an-tools">
        <?php
        $_tool('stats.php', 'bar-chart-2', admin_new_label(t('tool_stats', 'Statistiky')), t('desc_stats', 'Dashboard s přehledy, rekordy a grafy'));
        $_tool('calendar.php', 'calendar', admin_new_label(t('tool_calendar', 'Kalendář aktivity')), t('desc_calendar', 'Roční heatmapa aktivity'));
        $_tool('heatmap.php', 'flame', admin_new_label(t('tool_heatmap', 'Heatmapa')), t('desc_heatmap', 'Hustota průchodu tras na mapě'));
        $_tool('photo_heatmap.php', 'camera', admin_new_label(t('tool_photo_heatmap', 'Foto-heatmapa')), t('desc_photo_heatmap', 'Hustota fotografií na mapě, při přiblížení jednotlivé fotky'));
        $_tool('virtual_tracks.php', 'route', admin_new_label(t('tool_virtual_tracks', 'Virtuální trasy')), t('desc_virtual_tracks', 'Chytré roztřídění nepřiřazených fotek do tras'));
        $_tool('map_search.php', 'scan-search', admin_new_label(t('tool_map_search', 'Hledat na mapě')), t('desc_map_search', 'Nakreslit obdélník → najít trasy v oblasti'));
        $_tool('nearby.php', 'map-pin', admin_new_label(t('tool_nearby', 'Nejbližší trasy')), t('desc_nearby', 'Kliknutím na mapu najít trasy v okolí'));
        ?>
    </div>
</section>

</div><!-- /.an-main -->
</div><!-- /.an-layout -->
</div><!-- /.an-page -->

<script src="<?= h(asset('js/admin-new.js')) ?>" nonce="<?= csp_nonce() ?>" defer></script>
