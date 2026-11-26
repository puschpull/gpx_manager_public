<?php
declare(strict_types=1);

/**
 * Collections — named lists of tracks across categories ("Lužické hory 2026").
 * Visitors see only public collections. Admin creates them here; tracks are
 * added on the collection page or from the track detail.
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/collections_helper.php';
check_page_access('collections');

$_isAdmin    = !empty($_SESSION['is_admin']);
$collections = collections_list($pdo, $_isAdmin);

$page_title = t('col_title', 'Kolekce tras');
require __DIR__ . '/includes/layout_header.php';

$fmtRange = static function (?string $a, ?string $b): string {
    if (!$a) {
        return '';
    }
    $ya = substr($a, 0, 4);
    $yb = $b ? substr($b, 0, 4) : $ya;
    return $ya === $yb ? $ya : $ya . '–' . $yb;
};
?>
<style>
    .col-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
    .col-card { display: flex; flex-direction: column; overflow: hidden; text-decoration: none; color: inherit; transition: transform .15s; }
    .col-card:hover { transform: translateY(-2px); }
    @media (prefers-reduced-motion: reduce) { .col-card, .col-card:hover { transition: none; transform: none; } }
    .col-cover { aspect-ratio: 2 / 1; background: rgba(63,107,87,.10); display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .col-cover img { width: 100%; height: 100%; object-fit: cover; }
    .col-badge { display: inline-block; font-size: 11px; padding: 1px 8px; border-radius: 999px; background: rgba(168,68,58,.12); color: #a8443a; margin-left: 6px; vertical-align: 2px; }
    html.dark .col-badge { color: #f0a39a; background: rgba(240,163,154,.12); }
    .col-desc { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .col-form input[type=text], .col-form textarea { width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid rgba(63,107,87,.3); background: transparent; color: inherit; }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="library" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= h(t('col_title', 'Kolekce tras')) ?>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm"><?= h(t('col_subtitle')) ?></p>
    <?php if ($_isAdmin): ?>
        <p class="mt-2 text-forest-700/70 dark:text-sand-100/70 text-xs max-w-3xl"><?= h(t('col_how')) ?></p>
    <?php endif; ?>
</section>

<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 mb-12">
    <?php if ($_isAdmin): ?>
    <details class="card-outdoor p-4 mb-6" <?= $collections ? '' : 'open' ?>>
        <summary class="cursor-pointer font-semibold"><?= h(t('col_new', '+ Nová kolekce')) ?></summary>
        <form class="col-form mt-4 grid gap-3" id="colNewForm">
            <label class="text-sm"><?= h(t('col_name', 'Název')) ?>
                <input type="text" name="name" maxlength="160" required>
            </label>
            <label class="text-sm"><?= h(t('col_desc', 'Popis (nepovinný)')) ?>
                <textarea name="description" rows="3" maxlength="10000"></textarea>
            </label>
            <label class="text-sm flex items-center gap-2"><input type="checkbox" name="is_public" value="1"> <?= h(t('col_public', 'Veřejná — uvidí ji i návštěvníci')) ?></label>
            <div><button type="submit" class="btn-outdoor btn-outdoor-primary"><?= h(t('col_create', 'Vytvořit kolekci')) ?></button>
                <span class="text-sm ml-2" role="status" aria-live="polite" data-col-status></span></div>
        </form>
    </details>
    <?php endif; ?>

    <?php if (!$collections): ?>
        <div class="card-outdoor p-6 text-forest-700/70 dark:text-sand-100/70"><?= h(t('col_empty', 'Zatím tu není žádná kolekce.')) ?>
            <?php if ($_isAdmin): ?> <?= h(t('col_empty_admin', 'Založte první a přidejte do ní trasy.')) ?><?php endif; ?>
        </div>
    <?php else: ?>
    <div class="col-grid">
        <?php foreach ($collections as $c): ?>
            <a class="card-outdoor col-card" href="collection.php?id=<?= (int)$c['id'] ?>">
                <div class="col-cover">
                    <?php if ($c['cover']): ?>
                        <img src="<?= h((string)$c['cover']) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i data-lucide="library" class="w-10 h-10 opacity-40" aria-hidden="true"></i>
                    <?php endif; ?>
                </div>
                <div class="p-4">
                    <div class="font-[Manrope] font-bold text-lg text-forest-700 dark:text-sand-100">
                        <?= h((string)$c['name']) ?>
                        <?php if ($_isAdmin && (int)$c['is_public'] !== 1): ?><span class="col-badge"><?= h(t('col_private', 'neveřejná')) ?></span><?php endif; ?>
                    </div>
                    <div class="text-sm text-forest-700/70 dark:text-sand-100/70 mt-1 stat-num">
                        <?= h(str_replace('{n}', (string)(int)$c['track_count'], t('col_n_tracks', '{n} tras'))) ?>
                        · <?= h(fmtDist((float)$c['km'], 0)) ?>
                        <?php $rng = $fmtRange($c['first_date'], $c['last_date']); if ($rng !== ''): ?> · <?= h($rng) ?><?php endif; ?>
                    </div>
                    <?php if (trim((string)$c['description']) !== ''): ?>
                        <p class="col-desc text-sm mt-2 opacity-80"><?= h((string)$c['description']) ?></p>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($_isAdmin): ?>
<script defer src="<?= asset('js/collections-page.js') ?>"></script>
<script nonce="<?= csp_nonce() ?>">
window.gpxCollections = <?= js_safe_json([
    'csrf' => csrf_token(),
    'i18n' => [
        'saving' => t('col_saving', 'Ukládám…'),
        'error'  => t('col_error', 'Nepodařilo se uložit.'),
    ],
]) ?>;
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
