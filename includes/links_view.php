<?php
/**
 * links_view.php — šablona rozcestníku na příbuzné weby.
 *
 * Bez strict_types: soubor generuje HTML a volá h() nad hodnotami, které
 * mohou být int (viz pravidlo v CLAUDE.md pro *_view.php).
 *
 * Obsah je v links_data.php — přidání odkazu se dělá tam, sem se nesahá.
 */
$page_title = t('h1_links', 'Podobné weby');
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/links.css') ?>">
<?php

$catalog = links_catalog();
$badges  = links_badge_labels();
$total   = array_sum(array_map(fn($c) => count($c['items']), $catalog));
?>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_to_list')) ?>
    </a>

    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="compass" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('h1_links', 'Podobné weby')) ?>
    </h1>

    <p class="mt-3 max-w-3xl text-forest-700/80 dark:text-sand-100/80">
        <?= htmlspecialchars(t('links_intro',
            'Nástroje, které se hodí vedle tohoto archivu — prohlížeče a editory GPX, '
          . 'plánovače tras, mapy se značením a pár dalších užitečností. '
          . 'Všechny odkazy vedou mimo tento web a otevřou se v novém okně.')) ?>
    </p>

    <p class="mt-2 text-sm text-forest-700/60 dark:text-sand-100/60">
        <?= htmlspecialchars(sprintf(t('links_count', 'Celkem %d odkazů v %d kategoriích.'),
            $total, count($catalog))) ?>
    </p>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-8 pb-12 space-y-10">
    <?php foreach ($catalog as $cat): ?>
        <section>
            <h2 class="font-[Manrope] text-xl font-bold text-forest-700 dark:text-sand-100 flex items-center gap-2 mb-4">
                <i data-lucide="<?= htmlspecialchars($cat['icon']) ?>" class="w-5 h-5 text-terracotta-500" aria-hidden="true"></i>
                <?= htmlspecialchars($cat['title']) ?>
            </h2>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($cat['items'] as $item): ?>
                    <?php
                        // Doména pod názvem — ať je poznat, kam odkaz vede, ještě před kliknutím
                        $host = preg_replace('#^www\.#', '', (string)parse_url($item['url'], PHP_URL_HOST));
                    ?>
                    <a href="<?= htmlspecialchars($item['url']) ?>"
                       target="_blank"
                       rel="noopener noreferrer external"
                       class="gpx-linkcard card-outdoor p-4 block transition-shadow">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-semibold text-forest-700 dark:text-sand-100">
                                <?= htmlspecialchars($item['name']) ?>
                            </span>
                            <i data-lucide="external-link" class="w-4 h-4 shrink-0 mt-0.5 opacity-50" aria-hidden="true"></i>
                        </div>

                        <div class="gpx-linkcard-host mt-0.5"><?= htmlspecialchars((string)$host) ?></div>

                        <p class="gpx-linkcard-desc mt-2"><?= htmlspecialchars($item['desc']) ?></p>

                        <?php if (!empty($item['badges'])): ?>
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                <?php foreach ($item['badges'] as $b): ?>
                                    <?php if (isset($badges[$b])): ?>
                                        <span class="gpx-linkcard-badge"><?= htmlspecialchars($badges[$b]) ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="text-sm text-forest-700/60 dark:text-sand-100/60 border-t border-sand-200 dark:border-forest-800 pt-6">
        <?= htmlspecialchars(t('links_disclaimer',
            'Odkazy jsou tipy, ne doporučení — s cizími weby nemá tento archiv nic společného '
          . 'a nemůže ručit za to, co na nich najdete ani jak nakládají s vašimi daty.')) ?>
    </p>
</div>

<?php require __DIR__ . '/layout_footer.php'; ?>
