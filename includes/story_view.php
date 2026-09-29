<?php
declare(strict_types=1);
/**
 * story_view.php — šablona čtení cestopisu. Proměnné připraví story.php.
 * Čísla do h() přetypovaná (strict_types).
 */

$page_title = t('story_title', 'Cestopis') . ' — ' . $_storyTitle;
require __DIR__ . '/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= asset('css/story.css') ?>">

<article class="mx-auto max-w-4xl px-4 sm:px-6 pt-6 pb-12 story-read">
    <p class="story-back">
        <a href="detail.php?id=<?= (string)$trackId ?>">← <?= h(t('story_back_detail', 'Zpět na detail trasy')) ?></a>
        <?php if ($_isAdmin): ?>
            · <a href="story_admin.php?id=<?= (string)$trackId ?>"><?= h(t('story_btn_admin', 'Správa cestopisu')) ?></a>
        <?php endif; ?>
    </p>

    <?php if ($story === null): ?>
        <h1 class="story-h1">📖 <?= h(t('story_title', 'Cestopis')) ?> <span><?= h($_storyTitle) ?></span></h1>
        <p class="story-note" role="status"><?= h(t('story_not_found', 'K této trase zatím není zveřejněný cestopis.')) ?></p>
    <?php else: ?>

        <?php if ($_storyPreview): ?>
            <p class="story-note story-note-warn" role="status">
                <?= h(str_replace('{id}', (string)$story['id'], t('story_preview_banner', 'Náhled verze #{id} — není zveřejněná, návštěvníci ji nevidí.'))) ?>
            </p>
        <?php endif; ?>

        <?php
        // Fotka v článku: odkaz na velkou fotku, prohlížeč ji najde v galerii dole
        $_fig = static function (array $it, string $cls): string {
            $p = $it['photo'];
            return '<figure class="story-fig ' . $cls . '">'
                . '<a href="' . h(photo_full_url($p->filename)) . '" data-story-inline>'
                . '<img src="' . h(photo_full_url($p->filename)) . '" loading="lazy" data-img-fallback="dim" alt="' . h($it['caption']) . '">'
                . '</a><figcaption>' . h($it['caption']) . '</figcaption></figure>';
        };
        ?>
        <header class="story-read-head story-mag-head">
            <?php if ($_storyLayout['hero'] !== null): ?>
                <?= $_fig($_storyLayout['hero'], 'story-fig-hero') ?>
            <?php endif; ?>
            <h1 class="story-h1"><?= h($_storyTitle) ?></h1>
            <p class="story-muted">
                <?php if (!empty($track['date_start'])): ?>
                    <?= h(date('j. n. Y', (int)strtotime((string)$track['date_start']))) ?>
                <?php endif; ?>
                <?php if (!empty($track['activity_type'])): ?>
                    · <?= h(activity_type_label((string)$track['activity_type'])) ?>
                <?php endif; ?>
            </p>
        </header>

        <div class="story-text story-read-text story-mag">
            <?php $_side = 0; foreach ($_storyParas as $_i => $_p): ?>
                <p<?= $_i === 0 ? ' class="story-mag-lead"' : '' ?>><?= nl2br(h($_p)) ?></p>
                <?php if (isset($_storyLayout['after'][$_i])):
                    $_it = $_storyLayout['after'][$_i];
                    // Střídavě vpravo / vlevo; fotka na výšku je užší
                    $_cls = ($_side++ % 2 === 0 ? 'story-fig-right' : 'story-fig-left')
                          . ($_it['orient'] === 'portrait' ? ' story-fig-portrait' : ''); ?>
                    <?= $_fig($_it, $_cls) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <dl class="story-numbers">
            <?php
            $_nums = [
                'story_num_distance' => ['Vzdálenost', $track['distance_km'] !== null ? fmtDist((float)$track['distance_km']) : null],
                'story_num_ascent'   => ['Stoupání', $track['ascent'] !== null ? fmtElev((float)$track['ascent']) : null],
                'story_num_descent'  => ['Klesání', $track['descent'] !== null ? fmtElev((float)$track['descent']) : null],
                'story_num_duration' => ['Celkový čas', $track['duration'] !== null ? formatSecondsToHMS((int)$track['duration']) : null],
                'story_num_moving'   => ['V pohybu', $track['moving_time'] !== null ? formatSecondsToHMS((int)$track['moving_time']) : null],
                'story_num_max'      => ['Nejvyšší bod', $track['elevation_max'] !== null ? fmtElev((float)$track['elevation_max']) : null],
            ];
            foreach ($_nums as $_k => [$_label, $_val]):
                if ($_val === null) continue; ?>
                <div><dt><?= h(t($_k, $_label)) ?></dt><dd><?= h($_val) ?></dd></div>
            <?php endforeach; ?>
        </dl>

        <?php if ($_storyStops !== [] || $_storyOther !== []): ?>
            <hr class="story-rule">
            <h2 class="story-h2"><?= h(t('story_all_photos', 'Všechny fotky z výletu')) ?></h2>
            <?php foreach ($_storyStops as $_i => $_s): if ($_s['photos'] === []) continue;
                $_cap = ($_i + 1) . '. ' . ($_s['misto'] ?? t('story_stop', 'Zastávka')); ?>
                <section class="story-stop">
                    <h3>
                        <?= (string)($_i + 1) ?>. <?= h($_s['misto'] ?? t('story_stop', 'Zastávka')) ?>
                        <span class="story-muted"><?= h($_s['od']) ?><?= $_s['do'] !== $_s['od'] ? '–' . h($_s['do']) : '' ?></span>
                    </h3>
                    <?php if ($_s['okoli'] !== []): ?>
                        <p class="story-near">
                            <?= h(t('story_near', 'Nedaleko')) ?>:
                            <?= implode(', ', array_map(static fn($p) => h((string)($p['nazev'] ?? '')), $_s['okoli'])) ?>
                        </p>
                    <?php endif; ?>
                    <div class="story-photos">
                        <?php foreach ($_s['photos'] as $_ph): ?>
                            <a href="<?= h(photo_full_url($_ph->filename)) ?>" data-story-photo data-caption="<?= h($_cap . ' · ' . $_ph->takenAt->format('H:i')) ?>">
                                <img src="<?= h(photo_thumb_url($_ph->filename)) ?>" loading="lazy" data-img-fallback="dim"
                                     alt="<?= h(t('story_photo_alt', 'Fotografie') . ' ' . $_ph->takenAt->format('H:i')) ?>">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <?php if ($_storyOther !== []): ?>
                <section class="story-stop">
                    <h3><?= h(t('story_more_photos', 'Další fotky')) ?></h3>
                    <?php $_cap = t('story_more_photos', 'Další fotky'); ?>
                    <div class="story-photos">
                        <?php foreach ($_storyOther as $_ph): ?>
                            <a href="<?= h(photo_full_url($_ph->filename)) ?>" data-story-photo data-caption="<?= h($_cap . ' · ' . $_ph->takenAt->format('H:i')) ?>">
                                <img src="<?= h(photo_thumb_url($_ph->filename)) ?>" loading="lazy" data-img-fallback="dim"
                                     alt="<?= h(t('story_photo_alt', 'Fotografie') . ' ' . $_ph->takenAt->format('H:i')) ?>">
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <footer class="story-read-foot">
            <p><?= h(t('story_ai_note', 'Text napsal jazykový model (Claude od Anthropic) z dat záznamu trasy, názvů míst, bodů z mapy a fotek. Může obsahovat nepřesnosti.')) ?></p>
            <?php if (!empty($story['with_map']) || !empty($story['facts']['zastavky'])): ?>
                <p><?= h(t('story_osm_note', 'Názvy míst a body z mapy © přispěvatelé OpenStreetMap.')) ?></p>
            <?php endif; ?>
            <?php if (app_lang() !== 'cs'): ?>
                <p><?= h(t('story_lang_note', 'Text cestopisu je v češtině.')) ?></p>
            <?php endif; ?>
        </footer>
    <?php endif; ?>
</article>

<script src="<?= asset('js/lightbox.js') ?>"></script>
<script src="<?= asset('js/story-read.js') ?>"></script>

</div><?php require __DIR__ . '/layout_footer.php'; ?>
