<?php
/**
 * photos_view.php — HTML template for the photos page.
 *
 * Expected variables (provided by photos.php via extract()):
 *   $totalCount, $withGps, $withTrack, $unassigned
 *   $GALLERY_PER_PAGE_OPTIONS, $galleryPerPage, $galleryPage, $totalGalleryPages
 *   $currentPageGroups, $pagePhotos, $groupedForDisplay
 *   $unassignedPhotos, $tracksForSelect
 *   $timeline, $timelinePhotos, $monthNamesCz, $currentYear
 *
 * All echo output is escaped via h() or explicit (int) casts.
 * No DB queries here.
 */

$_isAdmin = !empty($_SESSION['is_admin']);
?>

<!-- Photos page styling — outdoor tokenizovaný (přebíjí potřebné proměnné) -->
<link rel="stylesheet" href="css/photos-outdoor.css">
<!-- Lightbox z původního systému -->
<script src="js/lib/format-utils.js" defer></script>
<script src="js/lightbox.js" defer></script>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i>
        <?= htmlspecialchars(t('back_short', 'Zpět na přehled')) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="image" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('photos_title', 'Fotografie tras')) ?>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">
        <?= htmlspecialchars(t('photos_subtitle', 'Galerie, časová osa a správa fotografií z výletů')) ?>
    </p>
</section>

<?php if (!empty($filterTrack)): ?>
<!-- Banner: fotky jedné trasy -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-4">
    <div class="card-outdoor p-4 flex flex-wrap items-center justify-between gap-3 border-l-4 border-l-terracotta-500">
        <div class="text-sm">
            <div class="text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <?= htmlspecialchars(t('photos_filter_label', 'Filtr')) ?>
            </div>
            <div class="mt-0.5 text-base font-semibold text-forest-700 dark:text-sand-100">
                🗺 <?= htmlspecialchars(t('photos_filter_track', 'Fotky trasy')) ?>:
                <a href="detail.php?id=<?= (int)$filterTrack['id'] ?>" class="hover:text-terracotta-500">
                    <?= h($filterTrack['track_name'] ?: $filterTrack['filename']) ?>
                </a>
            </div>
        </div>
        <a href="photos.php" class="btn-outdoor btn-outdoor-ghost shrink-0">
            ← <?= htmlspecialchars(t('photos_filter_clear', 'Zobrazit všechny fotky')) ?>
        </a>
    </div>
</section>
<?php endif; ?>

<!-- Statistiky -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="image" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('photos_stat_total', 'Celkem')) ?>
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format($totalCount, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars(t('photos_unit', 'fotek')) ?></div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="map-pin" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('photos_stat_gps', 'S GPS')) ?>
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format($withGps, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars(t('photos_stat_gps_sub', 'polohou')) ?></div>
        </div>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="route" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('photos_stat_assigned', 'Přiřazeno')) ?>
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-600"><?= number_format($withTrack, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars(t('photos_stat_assigned_sub', 'k trase')) ?></div>
        </div>
        <?php if ($unassigned > 0): ?>
        <div class="card-outdoor p-4 border-l-4 border-l-terracotta-500">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-terracotta-500">
                <i data-lucide="alert-triangle" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('photos_stat_warning', 'Pozor')) ?>
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-terracotta-500"><?= number_format($unassigned, 0, ',', ' ') ?></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars(t('photos_stat_unassigned', 'nepřiřazených')) ?></div>
        </div>
        <?php else: ?>
        <div class="card-outdoor p-4">
            <div class="flex items-center gap-1.5 text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="check-circle" class="w-3.5 h-3.5" aria-hidden="true"></i> <?= htmlspecialchars(t('photos_stat_done', 'Pořízeno')) ?>
            </div>
            <div class="mt-1.5 stat-num text-2xl font-semibold text-forest-700 dark:text-sand-100">100<span class="text-sm">%</span></div>
            <div class="text-xs text-forest-700/60 dark:text-sand-100/60 mt-0.5"><?= htmlspecialchars(t('photos_stat_done_sub', 'přiřazeno')) ?></div>
        </div>
        <?php endif; ?>
    </div>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-8">

<!-- Záložky -->
<?php
$activeTab = $_GET['tab'] ?? (($_isAdmin && empty($filterTrack)) ? 'upload' : 'gallery');
if (!in_array($activeTab, ['upload','gallery','unassigned','timeline'])) {
    $activeTab = ($_isAdmin && empty($filterTrack)) ? 'upload' : 'gallery';
}
if (!$_isAdmin && $activeTab === 'upload') {
    $activeTab = 'gallery';
}
// V režimu „fotky jedné trasy" nemají záložky Nahrát / Nepřiřazené smysl.
if (!empty($filterTrack) && in_array($activeTab, ['upload','unassigned'])) {
    $activeTab = 'gallery';
}
?>
<div class="photos-tabs">
    <?php if ($_isAdmin): ?>
    <?php if (empty($filterTrack)): ?>
    <button class="photos-tab <?= $activeTab === 'upload'     ? 'active' : '' ?>" data-tab="upload">⬆ <?= htmlspecialchars(t('photos_tab_upload', 'Nahrát fotky')) ?></button>
    <?php endif; ?>
    <button class="photos-tab <?= $activeTab === 'gallery'    ? 'active' : '' ?>" data-tab="gallery">🖼 <?= htmlspecialchars(t('photos_tab_manage', 'Správa')) ?> (<?= $totalCount ?>)</button>
    <?php if (empty($filterTrack) && $unassigned > 0): ?>
    <button class="photos-tab <?= $activeTab === 'unassigned' ? 'active' : '' ?>" data-tab="unassigned">⚠ <?= htmlspecialchars(t('photos_tab_unassigned', 'Nepřiřazené')) ?> (<?= $unassigned ?>)</button>
    <?php endif; ?>
    <?php else: ?>
    <button class="photos-tab <?= $activeTab === 'gallery'    ? 'active' : '' ?>" data-tab="gallery">🖼 <?= htmlspecialchars(t('photos_tab_gallery', 'Fotografie')) ?> (<?= $totalCount ?>)</button>
    <?php endif; ?>
    <?php if (!empty($timelinePhotos)): ?>
    <button class="photos-tab <?= $activeTab === 'timeline'   ? 'active' : '' ?>" data-tab="timeline">📅 <?= htmlspecialchars(t('photos_tab_timeline', 'Časová osa')) ?> (<?= count($timelinePhotos) ?>)</button>
    <?php endif; ?>
</div>

<!-- Záložka: Upload (pouze admin) -->
<div class="photos-tab-content <?= $activeTab === 'upload' ? 'active' : '' ?>" id="tab-upload" <?= !$_isAdmin ? 'style="display:none!important;"' : '' ?>>

    <div class="photo-dropzone" id="photoDropzone">
        <div class="drop-icon">📷</div>
        <div><?= htmlspecialchars(t('photos_dropzone_text', 'Přetáhněte fotky sem nebo')) ?> <strong><?= htmlspecialchars(t('photos_dropzone_click', 'klikněte pro výběr')) ?></strong></div>
        <div class="drop-formats"><?= htmlspecialchars(t('photos_dropzone_formats', 'JPEG · PNG · WebP · ZIP (Google Takeout) · Nahrávání po dávkách (max. 100 fotek)')) ?></div>
        <input type="file" id="photoFileInput" accept="image/jpeg,image/png,image/webp,.zip,application/zip" multiple aria-label="<?= htmlspecialchars(t('photos_file_label', 'Vybrat fotky k importu')) ?>">
    </div>

    <div id="uploadProgress"></div>

</div>

<!-- Záložka: Správa / Galerie -->
<div class="photos-tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>" id="tab-gallery">
<?php if ($totalCount === 0): ?>
    <p style="color:var(--text-muted); font-size:14px;"><?= htmlspecialchars(t('photos_empty', 'Zatím žádné fotky. Nahrajte je v záložce ⬆ Nahrát fotky.')) ?></p>
<?php else: ?>
    <?php
    $pgUrl = fn(int $p) => '?' . http_build_query(array_merge($_GET, ['page' => $p, 'per_page' => $galleryPerPage, 'tab' => 'gallery']));
    ?>
    <?php if (empty($filterTrack)): ?>
    <div class="gallery-pager">
        <?php if ($galleryPage > 1): ?>
            <a class="btn" href="<?= h($pgUrl(1)) ?>">« <?= htmlspecialchars(t('photos_pager_first', 'První')) ?></a>
            <a class="btn" href="<?= h($pgUrl($galleryPage - 1)) ?>">← <?= htmlspecialchars(t('photos_pager_prev', 'Předchozí')) ?></a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">« <?= htmlspecialchars(t('photos_pager_first', 'První')) ?></span>
            <span class="btn" style="opacity:.4; cursor:default;">← <?= htmlspecialchars(t('photos_pager_prev', 'Předchozí')) ?></span>
        <?php endif; ?>
        <span class="pager-info"><?= htmlspecialchars(t('photos_pager_page', 'Strana')) ?> <strong><?= $galleryPage ?></strong> / <?= $totalGalleryPages ?></span>
        <?php if ($galleryPage < $totalGalleryPages): ?>
            <a class="btn" href="<?= h($pgUrl($galleryPage + 1)) ?>"><?= htmlspecialchars(t('photos_pager_next', 'Další')) ?> →</a>
            <a class="btn" href="<?= h($pgUrl($totalGalleryPages)) ?>"><?= htmlspecialchars(t('photos_pager_last', 'Poslední')) ?> »</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;"><?= htmlspecialchars(t('photos_pager_next', 'Další')) ?> →</span>
            <span class="btn" style="opacity:.4; cursor:default;"><?= htmlspecialchars(t('photos_pager_last', 'Poslední')) ?> »</span>
        <?php endif; ?>
        <form method="get" style="display:inline-flex; align-items:center; gap:4px; margin:0;">
            <?php foreach (array_merge($_GET, ['tab' => 'gallery', 'per_page' => $galleryPerPage]) as $k => $v): ?>
                <?php if ($k !== 'page'): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <input type="number" name="page" min="1" max="<?= $totalGalleryPages ?>"
                   value="<?= $galleryPage ?>"
                   style="width:54px; font-size:12px; padding:3px 6px; text-align:center;"
                   title="<?= htmlspecialchars(t('photos_pager_goto', 'Skočit na stranu')) ?>">
            <button type="submit" class="btn" style="font-size:12px; padding:3px 8px;"><?= htmlspecialchars(t('photos_pager_go', 'Jít')) ?></button>
        </form>
        <select class="select" style="font-size:12px; padding:4px 8px;"
                onchange="location.href='<?= h('?' . http_build_query(array_merge($_GET, ['page' => 1, 'per_page' => '__PP__', 'tab' => 'gallery']))) ?>'.replace('__PP__', this.value)">
            <?php foreach ($GALLERY_PER_PAGE_OPTIONS as $pp): ?>
            <option value="<?= $pp ?>" <?= $pp === $galleryPerPage ? 'selected' : '' ?>><?= $pp ?> <?= htmlspecialchars(t('photos_per_page', 'fotek / strana')) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php foreach ($groupedForDisplay as $grp): ?>
    <div class="track-section" id="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">
        <div class="track-section-header">
            <?php if ($grp['track_id']): ?>
                🗺 <a href="detail.php?id=<?= (int)$grp['track_id'] ?>"><?= h($grp['track_name'] ?? '—') ?></a>
                <span class="track-date"><?= $grp['date_start'] ? substr($grp['date_start'], 0, 10) : '' ?></span>
                <span style="margin-left:auto; font-size:12px; color:var(--text-muted);"><?= count($grp['photos']) ?> <?= htmlspecialchars(t('photos_unit', 'fotek')) ?></span>
            <?php else: ?>
                ⚠ <?= htmlspecialchars(t('photos_unassigned_header', 'Nepřiřazené fotky')) ?>
                <span style="margin-left:auto; font-size:12px; color:var(--text-muted);"><?= count($grp['photos']) ?> <?= htmlspecialchars(t('photos_unit', 'fotek')) ?></span>
            <?php endif; ?>
            <?php if ($_isAdmin): ?>
                <button class="btn btn-track-sel-all" style="font-size:11px; padding:2px 8px; margin-left:8px;"
                        data-section="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">☑ <?= htmlspecialchars(t('photos_sel_all', 'Vybrat')) ?></button>
                <button class="btn btn-track-sel-none" style="font-size:11px; padding:2px 8px;"
                        data-section="track-section-<?= (int)($grp['track_id'] ?? 0) ?>">✕ <?= htmlspecialchars(t('photos_sel_none', 'Zrušit')) ?></button>
            <?php endif; ?>
        </div>
        <div class="track-section-body">
            <div class="photo-grid">
                <?php foreach ($grp['photos'] as $p): ?>
                <div class="photo-card<?= empty($p['visible']) ? ' photo-is-hidden' : '' ?>" data-id="<?= (int)$p['id'] ?>">
                    <?php if ($_isAdmin): ?>
                    <div class="photo-select-wrap">
                        <input type="checkbox" class="photo-select-cb" data-id="<?= (int)$p['id'] ?>">
                    </div>
                    <?php endif; ?>
                    <?php if (!$p['lat']): ?>
                        <div class="photo-no-gps"><?= htmlspecialchars(t('photos_no_gps', 'Bez GPS')) ?></div>
                    <?php endif; ?>
                    <img src="<?= h(photo_thumb_url($p['filename'])) ?>"
                         data-full-url="<?= h(photo_full_url($p['filename'])) ?>"
                         data-taken-at="<?= h($p['taken_at'] ?? '') ?>"
                         alt="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                         loading="lazy"
                         onerror="this.style.opacity='.3'">
                    <div class="photo-meta">
                        <?php if ($p['taken_at']): ?>
                            📅 <?= substr($p['taken_at'], 0, 16) ?>
                        <?php endif; ?>
                        <?php if ($p['lat']): ?>
                            <br>📍 <?= round((float)$p['lat'], 4) ?>, <?= round((float)$p['lon'], 4) ?>
                        <?php endif; ?>
                        <?php if (!empty($p['img_direction'])): ?>
                            <br>🧭 <?= round((float)$p['img_direction']) ?>°
                        <?php endif; ?>
                    </div>
                    <?php if ($_isAdmin): ?>
                    <div class="photo-caption-wrap" data-id="<?= (int)$p['id'] ?>">
                        <div class="photo-caption-display" title="<?= htmlspecialchars(t('photos_caption_edit_hint', 'Kliknutím upravit popisek')) ?>">
                            <?= $p['caption'] ? h($p['caption']) : '<span class="caption-empty">+ ' . htmlspecialchars(t('photos_caption_placeholder_short', 'popisek')) . '</span>' ?>
                        </div>
                        <div class="photo-caption-edit" style="display:none;">
                            <input type="text" class="caption-input" maxlength="200"
                                   placeholder="<?= htmlspecialchars(t('photos_caption_placeholder', 'Krátký popisek fotky…')) ?>"
                                   value="<?= h($p['caption'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="photo-actions">
                        <button class="btn btn-visible<?= empty($p['visible']) ? ' btn-hidden' : '' ?>"
                                data-id="<?= (int)$p['id'] ?>"
                                data-visible="<?= empty($p['visible']) ? '0' : '1' ?>"
                                title="<?= empty($p['visible']) ? htmlspecialchars(t('photos_action_show', 'Skrytá — kliknutím zobrazit na trase')) : htmlspecialchars(t('photos_action_hide', 'Zobrazená na trase — kliknutím skrýt')) ?>"
                        ><?= empty($p['visible']) ? '🙈' : '👁' ?></button>
                        <button class="btn btn-assign"
                                data-id="<?= (int)$p['id'] ?>"
                                data-track="<?= (int)($p['track_id'] ?? 0) ?>"
                                title="<?= htmlspecialchars(t('photos_action_assign', 'Přiřadit k trase')) ?>">✏</button>
                        <button class="btn btn-delete"
                                data-id="<?= (int)$p['id'] ?>"
                                data-name="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                                title="<?= htmlspecialchars(t('photos_action_delete', 'Smazat fotku')) ?>">🗑</button>
                    </div>
                    <?php elseif ($p['caption']): ?>
                    <div class="photo-caption-wrap">
                        <div class="photo-caption-display" style="cursor:default;">
                            <?= h($p['caption']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Spodní stránkovací lišta -->
    <?php if ($totalGalleryPages > 1): ?>
    <div class="gallery-pager" style="margin-top:12px;">
        <?php if ($galleryPage > 1): ?>
            <a class="btn" href="<?= h($pgUrl(1)) ?>">« První</a>
            <a class="btn" href="<?= h($pgUrl($galleryPage - 1)) ?>">← Předchozí</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">« První</span>
            <span class="btn" style="opacity:.4; cursor:default;">← Předchozí</span>
        <?php endif; ?>
        <span class="pager-info">Strana <strong><?= $galleryPage ?></strong> / <?= $totalGalleryPages ?></span>
        <?php if ($galleryPage < $totalGalleryPages): ?>
            <a class="btn" href="<?= h($pgUrl($galleryPage + 1)) ?>">Další →</a>
            <a class="btn" href="<?= h($pgUrl($totalGalleryPages)) ?>">Poslední »</a>
        <?php else: ?>
            <span class="btn" style="opacity:.4; cursor:default;">Další →</span>
            <span class="btn" style="opacity:.4; cursor:default;">Poslední »</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<!-- Záložka: Nepřiřazené (jen pokud existují) -->
<?php if ($unassigned > 0): ?>
<div class="photos-tab-content" id="tab-unassigned">
    <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">
        Tyto fotky nebylo možné automaticky přiřadit k trase (chybí GPS, čas nesedí nebo žádná trasa neexistuje v daném okně).
        Přiřadit je můžete ručně tlačítkem ✏.
        <?php if ($unassigned > 500): ?><strong>Zobrazeno prvních 500.</strong><?php endif; ?>
    </p>
    <div class="photo-grid">
        <?php foreach ($unassignedPhotos as $p): ?>
        <div class="photo-card" data-id="<?= (int)$p['id'] ?>">
            <div class="photo-select-wrap">
                <input type="checkbox" class="photo-select-cb" data-id="<?= (int)$p['id'] ?>">
            </div>
            <?php if (!$p['lat']): ?>
                <div class="photo-no-gps">Bez GPS</div>
            <?php endif; ?>
            <img src="<?= h(photo_thumb_url($p['filename'])) ?>"
                 data-full-url="<?= h(photo_full_url($p['filename'])) ?>"
                 data-taken-at="<?= h($p['taken_at'] ?? '') ?>"
                 alt="<?= h($p['orig_name'] ?? $p['filename']) ?>"
                 loading="lazy"
                 onerror="this.style.opacity='.3'">
            <div class="photo-meta">
                <?= h($p['orig_name'] ?? $p['filename']) ?><br>
                <?php if ($p['taken_at']): ?>📅 <?= substr($p['taken_at'], 0, 16) ?><?php endif; ?>
            </div>
            <div class="photo-actions">
                <button class="btn btn-assign"
                        data-id="<?= (int)$p['id'] ?>"
                        data-track="0"
                        title="Přiřadit k trase">✏ Přiřadit</button>
                <button class="btn btn-delete"
                        data-id="<?= (int)$p['id'] ?>"
                        data-name="<?= h($p['orig_name'] ?? $p['filename']) ?>">🗑</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Záložka: Časová osa -->
<?php if (!empty($timelinePhotos)): ?>
<div class="photos-tab-content" id="tab-timeline">
    <?php if (empty($timeline)): ?>
        <p style="color:var(--text-muted); font-size:14px;"><?= htmlspecialchars(t('photos_timeline_empty', 'Žádné fotky s datem pořízení.')) ?></p>
    <?php else: ?>
        <?php foreach ($timeline as $month => $days): ?>
            <?php
            $monthNum   = substr($month, 5, 2);
            $year       = substr($month, 0, 4);
            $monthName  = ($monthNamesCz[$monthNum] ?? $month) . ' ' . $year;
            $monthCount = array_sum(array_map('count', $days));
            $isOpenDefault = ($year === $currentYear);
            ?>
            <details class="timeline-month" <?= $isOpenDefault ? 'open' : '' ?>>
                <summary>
                    📅 <?= h($monthName) ?>
                    <span class="timeline-month-count"><?= $monthCount ?> fotek</span>
                </summary>
                <div class="timeline-days">
                    <?php foreach ($days as $day => $dayPhotos): ?>
                        <?php
                        $dayTs    = strtotime($day);
                        $dayLabel = date('j. n. Y', $dayTs);
                        ?>
                        <div class="timeline-day">
                            <div class="timeline-day-label">📆 <?= h($dayLabel) ?></div>
                            <div class="timeline-thumbs" data-timeline-day="<?= h($day) ?>">
                                <?php foreach ($dayPhotos as $tp): ?>
                                <img class="timeline-thumb lazy-thumb"
                                     src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs="
                                     data-src="<?= h(photo_thumb_url($tp['filename'])) ?>"
                                     data-full-url="<?= h(photo_full_url($tp['filename'])) ?>"
                                     data-taken-at="<?= h($tp['taken_at'] ?? '') ?>"
                                     alt="<?= h($tp['orig_name'] ?? $tp['filename']) ?>"
                                     onerror="this.src='';this.style.opacity='.3'"
                                     title="<?= h(substr($tp['taken_at'] ?? '', 0, 16)) . ($tp['track_name'] ? ' · ' . h($tp['track_name']) : '') ?>">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Bulk action bar (sticky bottom, pouze admin) -->
<div class="bulk-bar" id="bulkActionBar" <?= !$_isAdmin ? 'style="display:none!important;"' : '' ?>>
    <span class="bulk-count" id="bulkCount">0 <?= htmlspecialchars(t('photos_bulk_selected', 'vybráno')) ?></span>
    <button class="btn" id="bulkAssignBtn">✏ <?= htmlspecialchars(t('photos_action_assign', 'Přiřadit k trase')) ?></button>
    <button class="btn btn-bulk-delete" id="bulkDeleteBtn">🗑 <?= htmlspecialchars(t('photos_bulk_delete', 'Smazat vybrané')) ?></button>
    <span class="bulk-sep"></span>
    <button class="btn" id="bulkSelectAllBtn">☑ <?= htmlspecialchars(t('photos_bulk_select_all', 'Vybrat vše')) ?></button>
    <button class="btn" id="bulkClearBtn">✕ <?= htmlspecialchars(t('photos_bulk_clear', 'Zrušit výběr')) ?></button>
</div>

<!-- Modal: přiřazení k trase -->
<div class="modal-overlay" id="assignModal">
    <div class="modal-box">
        <h3>✏ <?= htmlspecialchars(t('photos_modal_assign_title', 'Přiřadit fotku k trase')) ?></h3>
        <select id="assignTrackSelect" class="select">
            <option value="">— <?= htmlspecialchars(t('photos_modal_unassigned', 'Nepřiřazená')) ?> —</option>
            <?php foreach ($tracksForSelect as $tr): ?>
            <option value="<?= (int)$tr['id'] ?>">
                <?= h($tr['track_name'] ?: $tr['filename']) ?>
                <?= $tr['date_start'] ? ' (' . substr($tr['date_start'], 0, 10) . ')' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <div class="modal-actions">
            <button class="btn" id="assignCancelBtn"><?= htmlspecialchars(t('cancel', 'Zrušit')) ?></button>
            <button class="btn btn-primary" id="assignSaveBtn"><?= htmlspecialchars(t('save', 'Uložit')) ?></button>
        </div>
    </div>
</div>

<script src="js/photos.js" defer></script>

</div><!-- /content wrapper -->
