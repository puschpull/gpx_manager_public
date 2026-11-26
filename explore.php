<?php
declare(strict_types=1);

/**
 * Explored squares — the map split into zoom-14 tiles (about 1.5 × 2.4 km here),
 * coloured where a track went through. Shows the number of squares, the largest
 * cluster (squares surrounded on all four sides) and the largest square.
 * Data: api/explore/tiles.php, computation: includes/explore_helper.php.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/year_data.php';   // year_list()
check_page_access('explore');

$years = year_list($pdo);
$page_title = t('ex_title', 'Prozkoumané čtverce');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="<?= asset('css/extra-utilities.css') ?>">
<style>
    #exMap { height: 68vh; min-height: 420px; border-radius: 12px; z-index: 0; }
    .ex-ctrl { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; font-size: 14px; }
    .ex-ctrl select { padding: 6px 10px; border-radius: 8px; border: 1px solid rgba(63,107,87,.3); background: transparent; color: inherit; }
    html.dark .ex-ctrl select option { background: #243028; }
    .ex-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 13px; }
    .ex-sw { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: -2px; margin-right: 5px; }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="grid-3x3" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= h(t('ex_title', 'Prozkoumané čtverce')) ?>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm"><?= h(t('ex_subtitle')) ?></p>
</section>

<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php foreach ([
            ['exTotal',   'grid-3x3',   t('ex_kpi_total', 'prozkoumaných čtverců')],
            ['exCluster', 'shapes',     t('ex_kpi_cluster', 'největší cluster')],
            ['exSquare',  'square',     t('ex_kpi_square', 'největší čtverec')],
            ['exNew',     'sparkles',   t('ex_kpi_new', 'nových ve zvoleném roce')],
        ] as [$id, $icon, $lbl]): ?>
            <div class="card-outdoor p-4">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                    <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5" aria-hidden="true"></i>
                </div>
                <div id="<?= $id ?>" class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100">…</div>
                <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= h($lbl) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 mb-12">
    <div class="card-outdoor p-4 mb-3">
        <div class="ex-ctrl">
            <label><?= h(t('th_activity')) ?>:
                <select id="exAct">
                    <option value="all"><?= h(t('ex_act_all', 'všechny aktivity')) ?></option>
                    <option value="foot"><?= h(t('ex_act_foot', 'jen pěší')) ?></option>
                </select>
            </label>
            <label><?= h(t('ex_year', 'Zvýraznit nové v roce')) ?>:
                <select id="exYear">
                    <option value="">—</option>
                    <?php foreach ($years as $yy): ?>
                        <option value="<?= $yy ?>"><?= $yy ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <span id="exStatus" class="text-sm opacity-70" role="status" aria-live="polite"></span>
        </div>
        <div class="ex-legend mt-3">
            <span><span class="ex-sw" style="background:#7b2cbf88"></span><?= h(t('ex_leg_tile', 'prozkoumaný čtverec')) ?></span>
            <span><span class="ex-sw" style="background:#1f5fbf88"></span><?= h(t('ex_leg_cluster', 'největší cluster (obklopený ze všech stran)')) ?></span>
            <span><span class="ex-sw" style="background:#e8590c88"></span><?= h(t('ex_leg_new', 'nový ve zvoleném roce')) ?></span>
            <span><span class="ex-sw" style="border:2px dashed #e8590c"></span><?= h(t('ex_leg_square', 'největší čtverec')) ?></span>
        </div>
    </div>
    <div class="card-outdoor p-2">
        <div id="exMap" role="region" aria-label="<?= h(t('ex_title', 'Prozkoumané čtverce')) ?>"></div>
    </div>
    <p class="mt-3 text-xs text-forest-700/60 dark:text-sand-100/60"><?= h(t('ex_help')) ?></p>
</section>

<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script defer src="<?= asset('js/lib/map-factory.js') ?>"></script>
<script defer src="<?= asset('js/explore-page.js') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
window.gpxExploreData = <?= js_safe_json([
    'apiKeys' => ['tf' => TF_API_KEY, 'mapycom' => MAPYCOM_API_KEY, 'mapillary' => MAPILLARY_TOKEN],
    'i18n'    => [
        'grid'       => t('ex_grid', '▦ Mřížka čtverců'),
        'loading'    => t('ex_loading', 'Počítám čtverce…'),
        'error'      => t('ex_error', 'Čtverce se nepodařilo načíst.'),
        'incomplete' => t('ex_incomplete', 'Ještě nejsou spočítané všechny trasy — obnovte stránku.'),
        'tileTip'    => t('ex_tile_tip', 'Projito {n}× · poprvé {y}'),
    ],
]) ?>;
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
