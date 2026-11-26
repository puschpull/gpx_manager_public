<?php
declare(strict_types=1);

/**
 * Collection detail — map of all its tracks, totals and the track list.
 * GET ?id=<collection id>. Visitors: only public collections.
 * Admin: edit name/description/visibility, add tracks (search), remove, reorder.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/collections_helper.php';
require_once __DIR__ . '/includes/track_title.php';
check_page_access('collections');

$_isAdmin = !empty($_SESSION['is_admin']);
$id  = (int)($_GET['id'] ?? 0);
$col = $id > 0 ? collection_get($pdo, $id, $_isAdmin) : null;
if ($col === null) {
    header('Location: collections.php');
    exit;
}
$tracks = collection_tracks($pdo, $id);

$sumKm = 0.0;
$sumAsc = 0.0;
$sumDur = 0;
$dates = [];
foreach ($tracks as $tr) {
    $sumKm  += (float)$tr['distance_km'];
    $sumAsc += (float)$tr['ascent'];
    $sumDur += (int)$tr['duration'];
    if ($tr['date_start']) {
        $dates[] = substr((string)$tr['date_start'], 0, 10);
    }
}
sort($dates);
$period = $dates ? (date('j. n. Y', (int)strtotime($dates[0])) . ($dates[0] !== end($dates) ? ' – ' . date('j. n. Y', (int)strtotime((string)end($dates))) : '')) : '—';

$title = static function (array $r) use ($pdo): string {
    $r['place_name'] = $r['place_name'] ?? '';
    return track_display_title($pdo, $r);
};

$page_title = (string)$col['name'];
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<link rel="stylesheet" href="<?= asset('css/extra-utilities.css') ?>">
<style>
    .col-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
    #colMap { height: 440px; border-radius: 12px; z-index: 0; }
    .col-badge { display: inline-block; font-size: 12px; padding: 2px 10px; border-radius: 999px; background: rgba(168,68,58,.12); color: #a8443a; vertical-align: middle; }
    html.dark .col-badge { color: #f0a39a; background: rgba(240,163,154,.12); }
    .col-form input[type=text], .col-form input[type=search], .col-form textarea { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid rgba(63,107,87,.3); background: transparent; color: inherit; }
    .col-tbl td, .col-tbl th { padding: 8px 12px; }
    .col-tbl a { color: inherit; text-decoration: none; }
    .col-tbl a:hover { color: #c97b3f; }
    .col-icon-btn { padding: 3px 7px; border-radius: 6px; border: 1px solid rgba(63,107,87,.25); background: transparent; color: inherit; cursor: pointer; font-size: 13px; line-height: 1; }
    .col-icon-btn:hover { border-color: #c97b3f; color: #c97b3f; }
    .col-icon-btn[disabled] { opacity: .3; cursor: default; }
    .col-results li { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 5px 0; border-bottom: 1px dashed rgba(0,0,0,.08); }
    html.dark .col-results li { border-color: rgba(255,255,255,.08); }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="collections.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i> <?= h(t('col_title', 'Kolekce tras')) ?>
    </a>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
            <i data-lucide="library" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
            <?= h((string)$col['name']) ?>
            <?php if ($_isAdmin && (int)$col['is_public'] !== 1): ?><span class="col-badge"><?= h(t('col_private', 'neveřejná')) ?></span><?php endif; ?>
        </h1>
        <?php if ((int)$col['is_public'] === 1): ?>
            <button type="button" class="btn-outdoor" id="colCopyLink"><i data-lucide="link" class="w-4 h-4" aria-hidden="true"></i> <?= h(t('col_copy_link', 'Zkopírovat odkaz')) ?></button>
        <?php endif; ?>
    </div>
    <?php if (trim((string)$col['description']) !== ''): ?>
        <p class="mt-2 max-w-3xl text-forest-700/80 dark:text-sand-100/80"><?= nl2br(h((string)$col['description'])) ?></p>
    <?php endif; ?>
</section>

<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="col-kpis">
        <?php foreach ([
            ['route',    (string)count($tracks),                       t('yr_kpi_tracks', 'výletů')],
            ['ruler',    fmtDist($sumKm, 0),                           t('yr_kpi_km', 'celkem')],
            ['mountain', fmtElev($sumAsc),                             t('yr_kpi_ascent', 'nastoupáno')],
            ['clock',    number_format($sumDur / 3600, 0, ',', ' '),   t('yr_kpi_hours', 'hodin na cestě')],
            ['calendar', $period,                                      t('stat_period', 'období')],
        ] as [$icon, $val, $lbl]): ?>
            <div class="card-outdoor p-4">
                <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                    <i data-lucide="<?= $icon ?>" class="w-3.5 h-3.5" aria-hidden="true"></i>
                </div>
                <div class="mt-1.5 stat-num <?= $icon === 'calendar' ? 'text-base' : 'text-2xl' ?> font-semibold text-forest-700 dark:text-sand-100"><?= h($val) ?></div>
                <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= h($lbl) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($tracks): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="card-outdoor p-2"><div id="colMap" role="region" aria-label="<?= h(t('col_map', 'Trasy kolekce na mapě')) ?>"></div></div>
</section>
<?php endif; ?>

<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="card-outdoor overflow-x-auto">
        <?php if (!$tracks): ?>
            <p class="p-5 text-forest-700/70 dark:text-sand-100/70"><?= h(t('col_no_tracks', 'Kolekce zatím neobsahuje žádné trasy.')) ?></p>
        <?php else: ?>
        <table class="w-full text-sm col-tbl" id="colTable">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 border-b border-sand-200 dark:border-forest-700">
                    <th scope="col">#</th>
                    <th scope="col"><?= h(t('col_th_track', 'Trasa')) ?></th>
                    <th scope="col"><?= h(t('th_activity')) ?></th>
                    <th scope="col" class="text-right"><?= h(t('th_distance')) ?></th>
                    <th scope="col" class="text-right"><?= h(t('th_ascent')) ?></th>
                    <?php if ($_isAdmin): ?><th scope="col" class="text-right"><span class="sr-only"><?= h(t('col_th_actions', 'Úpravy')) ?></span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-sand-100 dark:divide-forest-700">
                <?php foreach ($tracks as $i => $tr): ?>
                    <tr data-track-id="<?= (int)$tr['id'] ?>">
                        <td class="stat-num opacity-60"><?= $i + 1 ?></td>
                        <td><a href="detail.php?id=<?= (int)$tr['id'] ?>"><?= (int)$tr['is_favorite'] === 1 ? '★ ' : '' ?><?= h($title($tr)) ?></a></td>
                        <td><?= activityBadge((string)($tr['activity_type'] ?? '')) ?></td>
                        <td class="text-right stat-num whitespace-nowrap"><?= h(fmtDist((float)$tr['distance_km'])) ?></td>
                        <td class="text-right stat-num whitespace-nowrap"><?= h(fmtElev((float)$tr['ascent'])) ?></td>
                        <?php if ($_isAdmin): ?>
                        <td class="text-right whitespace-nowrap">
                            <button type="button" class="col-icon-btn" data-col-move="-1" title="<?= h(t('col_up', 'Posunout výš')) ?>" aria-label="<?= h(t('col_up', 'Posunout výš')) ?>"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
                            <button type="button" class="col-icon-btn" data-col-move="1" title="<?= h(t('col_down', 'Posunout níž')) ?>" aria-label="<?= h(t('col_down', 'Posunout níž')) ?>"<?= $i === count($tracks) - 1 ? ' disabled' : '' ?>>↓</button>
                            <button type="button" class="col-icon-btn" data-col-remove title="<?= h(t('col_remove', 'Odebrat z kolekce')) ?>" aria-label="<?= h(t('col_remove', 'Odebrat z kolekce')) ?>">✕</button>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</section>

<?php if ($_isAdmin): ?>
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid lg:grid-cols-2 gap-4">
        <div class="card-outdoor p-5">
            <h2 class="font-semibold mb-3"><?= h(t('col_add_title', 'Přidat trasy')) ?></h2>
            <form class="col-form" id="colSearchForm" role="search">
                <label class="sr-only" for="colSearch"><?= h(t('col_add_title', 'Přidat trasy')) ?></label>
                <input type="search" id="colSearch" placeholder="<?= h(t('search_placeholder')) ?>" title="<?= h(t('search_syntax_hint')) ?>">
            </form>
            <ul class="col-results text-sm mt-3" id="colResults" aria-live="polite"></ul>
        </div>
        <div class="card-outdoor p-5">
            <h2 class="font-semibold mb-3"><?= h(t('col_edit', 'Upravit kolekci')) ?></h2>
            <form class="col-form grid gap-3" id="colEditForm">
                <label class="text-sm"><?= h(t('col_name', 'Název')) ?>
                    <input type="text" name="name" maxlength="160" required value="<?= h((string)$col['name']) ?>">
                </label>
                <label class="text-sm"><?= h(t('col_desc', 'Popis (nepovinný)')) ?>
                    <textarea name="description" rows="3" maxlength="10000"><?= h((string)($col['description'] ?? '')) ?></textarea>
                </label>
                <label class="text-sm flex items-center gap-2"><input type="checkbox" name="is_public" value="1"<?= (int)$col['is_public'] === 1 ? ' checked' : '' ?>> <?= h(t('col_public', 'Veřejná — uvidí ji i návštěvníci')) ?></label>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="btn-outdoor btn-outdoor-primary"><?= h(t('col_save', 'Uložit')) ?></button>
                    <button type="button" class="btn-outdoor" id="colDelete"><?= h(t('col_delete', 'Smazat kolekci')) ?></button>
                    <span class="text-sm" role="status" aria-live="polite" data-col-status></span>
                </div>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="mb-12"></div>

<?php if ($tracks): ?>
<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script defer src="<?= asset('js/lib/map-factory.js') ?>"></script>
<script defer src="<?= asset('js/track-set-map.js') ?>"></script>
<?php endif; ?>
<script defer src="<?= asset('js/collections-page.js') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
window.gpxCollection = <?= js_safe_json([
    'id'       => $id,
    'isAdmin'  => $_isAdmin,
    'csrf'     => $_isAdmin ? csrf_token() : '',
    'linesUrl' => 'api/tracks/lines_set.php?collection=' . $id,
    'apiKeys'  => ['tf' => TF_API_KEY, 'mapycom' => MAPYCOM_API_KEY, 'mapillary' => MAPILLARY_TOKEN],
    'i18n'     => [
        'trackDetail'   => t('mp_track_detail', 'Detail trasy'),
        'saving'        => t('col_saving', 'Ukládám…'),
        'saved'         => t('col_saved', 'Uloženo.'),
        'error'         => t('col_error', 'Nepodařilo se uložit.'),
        'add'           => t('col_add', 'Přidat'),
        'added'         => t('col_added', 'v kolekci'),
        'noResults'     => t('col_no_results', 'Nic nenalezeno.'),
        'confirmDelete' => t('col_confirm_delete', 'Smazat kolekci? Trasy zůstanou, zmizí jen tento seznam.'),
        'linkCopied'    => t('mp_link_copied', 'Odkaz zkopírován'),
        'refresh'       => t('col_refresh', 'Zobrazit přidané trasy'),
    ],
]) ?>;
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
