<?php
/**
 * Homepage redesign — Outdoor edition.
 * Pro detailní tabulkový pohled (legacy) viz index-legacy.php.
 *
 * Variables ze index_data.php:
 *   $tracks (array) — current page records
 *   $stats  (array) — totals (total_tracks, total_km, total_ascent, total_descent, avg_speed)
 *   $page, $per_page, $total_pages, $total_rows — pagination
 *   $q, $fav_only, ...  — active filter values from GET
 *   $available_themes, $theme, $allowedLangs — theming
 */

if (!isset($_SESSION)) { @session_start(); }
$is_admin = !empty($_SESSION['is_admin']);

$page_title = t('h1_my_tracks');

$activeQ        = $q ?? '';
$activeFav      = ($fav_only ?? '') === '1';
$activeActivity = trim($_GET['activity'] ?? '');
$activeYear     = trim($_GET['year'] ?? '');
$thisYearStart  = date('Y') . '-01-01';

require __DIR__ . '/layout_header.php';

/** Build query string preserving currently active filter except given key.
 *  Pokud je v overrides nějaký aktivní filter, přidá se filter_submit=1 (vyžadováno
 *  index_data.php / buildFilterSQL pro aplikaci filtru). */
function gpx_chip_url($overrides = []) {
    $merged = array_merge($_GET, $overrides);
    // Vyřaď prázdné a defaults
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== null && $v !== []);
    // Pokud zůstává nějaký filter — přidej filter_submit=1
    $hasFilter = false;
    foreach (['q','fav_only','activity','year','date_from','date_to','color','cat','diff_min','diff_max','dist_min','dist_max'] as $k) {
        if (!empty($merged[$k])) { $hasFilter = true; break; }
    }
    if ($hasFilter) {
        $merged['filter_submit'] = '1';
    } else {
        unset($merged['filter_submit']);
    }
    unset($merged['page']); // chip click reset stránku
    return 'index.php' . (empty($merged) ? '' : '?' . http_build_query($merged));
}
?>

<!-- HERO -->
<section class="relative overflow-hidden">
    <div class="absolute inset-0 opacity-[0.06] pointer-events-none topo-bg" aria-hidden="true"></div>
    <div class="absolute inset-0 bg-gradient-to-br from-sand-50 via-sand-50 to-forest-50 dark:from-forest-900 dark:via-forest-900 dark:to-forest-800 pointer-events-none" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-4 sm:px-6 py-10 md:py-14">
        <div class="grid md:grid-cols-[1.4fr_1fr] gap-8 items-center">
            <div class="anim-fade-up anim-fade-up-1">
                <p class="font-[Manrope] text-sm uppercase tracking-widest text-terracotta-500 mb-3">
                    <?= htmlspecialchars(t('hero_kicker')) ?>
                </p>
                <h1 class="font-[Manrope] text-4xl md:text-5xl font-extrabold tracking-tight leading-tight text-forest-700 dark:text-sand-100">
                    <?= htmlspecialchars(t('hero_title_a')) ?><br>
                    <span class="text-terracotta-500"><?= htmlspecialchars(t('hero_title_b')) ?></span>
                </h1>

                <!-- Stat counters -->
                <div class="mt-7 grid grid-cols-3 gap-3 max-w-lg"
                     x-data="{
                        tracks: 0, km: 0, asc: 0,
                        target: { tracks: <?= (int)($stats['total_tracks'] ?? 0) ?>, km: <?= (int)round($stats['total_km'] ?? 0) ?>, asc: <?= (int)round($stats['total_ascent'] ?? 0) ?> },
                        animate() {
                          const start = performance.now(), dur = 1100;
                          const ease = t => 1 - Math.pow(1 - t, 3);
                          const tick = (now) => {
                            const t = Math.min(1, (now - start) / dur);
                            const e = ease(t);
                            this.tracks = Math.round(this.target.tracks * e);
                            this.km = Math.round(this.target.km * e);
                            this.asc = Math.round(this.target.asc * e);
                            if (t < 1) requestAnimationFrame(tick);
                          };
                          requestAnimationFrame(tick);
                        }
                     }" x-init="animate()">
                    <div class="card-outdoor p-4 text-center">
                        <div class="stat-num text-2xl md:text-3xl font-semibold text-forest-700 dark:text-sand-100" x-text="tracks.toLocaleString('cs')">0</div>
                        <div class="text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mt-1"><?= htmlspecialchars(t('stat_tracks')) ?></div>
                    </div>
                    <div class="card-outdoor p-4 text-center">
                        <div class="stat-num text-2xl md:text-3xl font-semibold text-terracotta-500" x-text="km.toLocaleString('cs')">0</div>
                        <div class="text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mt-1">km</div>
                    </div>
                    <div class="card-outdoor p-4 text-center">
                        <div class="stat-num text-2xl md:text-3xl font-semibold text-forest-600" x-text="asc.toLocaleString('cs')">0</div>
                        <div class="text-xs uppercase tracking-wider text-forest-700/60 dark:text-sand-100/60 mt-1">m ↑</div>
                    </div>
                </div>

                <!-- Search -->
                <form action="index.php" method="get" class="mt-6 flex gap-2 max-w-lg">
                    <input type="hidden" name="filter_submit" value="1">
                    <div class="flex-1 relative">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-forest-700/50 dark:text-sand-100/50"></i>
                        <input type="search" name="q" value="<?= htmlspecialchars($activeQ) ?>"
                               placeholder="<?= htmlspecialchars(t('search_placeholder')) ?>"
                               class="w-full pl-9 pr-3 py-2.5 rounded-md bg-white dark:bg-forest-800 border border-sand-200 dark:border-forest-700 focus:border-forest-500 focus:outline-none focus:ring-2 focus:ring-forest-500/30 transition">
                    </div>
                    <button type="submit" class="btn-outdoor btn-outdoor-primary">
                        <i data-lucide="search" class="w-4 h-4"></i>
                        <?= htmlspecialchars(t('btn_search')) ?>
                    </button>
                </form>
            </div>

            <!-- Hero illustration -->
            <div class="hidden md:block">
                <div class="relative aspect-[5/4] max-w-md mx-auto">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-forest-600 to-forest-800 shadow-hover overflow-hidden">
                        <svg viewBox="0 0 400 320" class="w-full h-full" preserveAspectRatio="xMidYMid slice">
                            <circle cx="80" cy="50" r="1.5" fill="white" opacity="0.6"/>
                            <circle cx="140" cy="35" r="1" fill="white" opacity="0.5"/>
                            <circle cx="220" cy="60" r="1.2" fill="white" opacity="0.7"/>
                            <circle cx="310" cy="45" r="1" fill="white" opacity="0.4"/>
                            <circle cx="320" cy="80" r="20" fill="#F5F1EA" opacity="0.85"/>
                            <circle cx="328" cy="76" r="18" fill="#3F6B57" opacity="0.85"/>
                            <path d="M0 220 L80 140 L140 180 L210 110 L280 170 L360 130 L400 160 L400 320 L0 320 Z" fill="#1A2620" opacity="0.7"/>
                            <path d="M0 250 L60 190 L130 230 L200 170 L260 220 L320 180 L400 230 L400 320 L0 320 Z" fill="#243028"/>
                            <path d="M200 170 L210 180 L195 190 L185 182 Z" fill="#F5F1EA" opacity="0.8"/>
                            <path d="M60 190 L70 200 L55 208 L48 200 Z" fill="#F5F1EA" opacity="0.7"/>
                            <g fill="#1A2620">
                                <path d="M40 295 L50 255 L60 295 Z"/>
                                <path d="M90 300 L100 265 L110 300 Z"/>
                                <path d="M340 300 L350 260 L360 300 Z"/>
                            </g>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- QUICK FILTERS -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-2 anim-fade-up anim-fade-up-3">
    <div class="flex flex-wrap items-center gap-2">
        <a href="<?= gpx_chip_url(['fav_only'=>'','activity'=>'','year'=>'','date_from'=>'','date_to'=>'']) ?>" class="chip-filter" <?= (!$activeFav && !$activeActivity && !$activeYear && !$activeQ) ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="list" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_all')) ?>
        </a>
        <a href="<?= gpx_chip_url(['fav_only'=>$activeFav?'':'1']) ?>" class="chip-filter" <?= $activeFav ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="star" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_fav')) ?>
        </a>
        <a href="<?= gpx_chip_url(['date_from'=>$activeYear?'':$thisYearStart, 'year'=>$activeYear?'':date('Y')]) ?>" class="chip-filter" <?= $activeYear ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="calendar-days" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_thisyear')) ?>
        </a>
        <a href="<?= gpx_chip_url(['activity'=>$activeActivity==='Pěšky'?'':'Pěšky']) ?>" class="chip-filter" <?= $activeActivity==='Pěšky' ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="footprints" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_walk')) ?>
        </a>
        <a href="<?= gpx_chip_url(['activity'=>$activeActivity==='Turistika'?'':'Turistika']) ?>" class="chip-filter" <?= $activeActivity==='Turistika' ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="mountain" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_hiking')) ?>
        </a>
        <a href="<?= gpx_chip_url(['activity'=>$activeActivity==='Auto'?'':'Auto']) ?>" class="chip-filter" <?= $activeActivity==='Auto' ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="car" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_auto')) ?>
        </a>
        <a href="<?= gpx_chip_url(['activity'=>$activeActivity==='Kolo'?'':'Kolo']) ?>" class="chip-filter" <?= $activeActivity==='Kolo' ? 'aria-pressed="true"' : '' ?>>
            <i data-lucide="bike" class="w-3.5 h-3.5"></i> <?= htmlspecialchars(t('chip_bike')) ?>
        </a>

        <span class="ml-auto flex items-center gap-2 text-sm">
            <a href="index-legacy.php<?= !empty($_SERVER['QUERY_STRING']) ? '?'.$_SERVER['QUERY_STRING'] : '' ?>"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-forest-700/80 dark:text-sand-100/70 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors"
               title="<?= htmlspecialchars(t('legacy_table_hint')) ?>">
                <i data-lucide="table-2" class="w-4 h-4"></i>
                <?= htmlspecialchars(t('legacy_table')) ?>
            </a>
            <?php if ($is_admin): ?>
                <a href="import.php" class="btn-outdoor btn-outdoor-primary !py-1.5">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    <?= htmlspecialchars(t('btn_import', 'Importovat')) ?>
                </a>
            <?php endif; ?>
        </span>
    </div>
</section>

<!-- TRACKS GRID -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-8">
    <?php
    $resultLabel = $total_rows ?? count($tracks);
    if ($activeQ || $activeFav || $activeActivity || $activeYear): ?>
        <div class="mb-4 text-sm text-forest-700/70 dark:text-sand-100/70">
            <?= htmlspecialchars(sprintf(t('result_count'), $resultLabel)) ?>
            <?php if ($activeQ): ?>
                <span class="ml-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-terracotta-50 text-terracotta-600 text-xs">
                    „<?= htmlspecialchars($activeQ) ?>"
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($tracks)): ?>
        <div class="card-outdoor p-12 text-center">
            <i data-lucide="map-off" class="w-14 h-14 mx-auto mb-4 text-forest-400"></i>
            <h3 class="font-[Manrope] text-xl font-semibold text-forest-700 dark:text-sand-100"><?= htmlspecialchars(t('empty_title')) ?></h3>
            <p class="mt-2 text-forest-700/70 dark:text-sand-100/70"><?= htmlspecialchars(t('empty_desc')) ?></p>
            <?php if ($is_admin): ?>
                <a href="import.php" class="btn-outdoor btn-outdoor-primary mt-6 inline-flex">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    <?= htmlspecialchars(t('btn_import', 'Importovat trasu')) ?>
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($tracks as $i => $tr): ?>
                <?php
                    $tid = (int)$tr['id'];
                    $name = $tr['track_name'] ?: $tr['filename'];
                    $dist = number_format((float)$tr['distance_km'], 1, ',', ' ');
                    $asc  = number_format((int)$tr['ascent'], 0, ',', ' ');
                    $desc = number_format((int)$tr['descent'], 0, ',', ' ');
                    $date = !empty($tr['date_start']) ? date('d.m.Y', strtotime($tr['date_start'])) : '';
                    $isFav = !empty($tr['is_favorite']);
                    $diff  = $tr['difficulty'] ?? '';
                    $act   = $tr['activity_type'] ?? '';
                    $color = $tr['color'] ?? '';
                ?>
                <?php
                    // Mapový thumbnail — pokud existuje uploads/thumbs/{name}.png, ukaž ho.
                    $thumbBase = preg_replace('/\.(gpx|GPX)$/', '', $tr['filename']);
                    $thumbName = $thumbBase . '.png';
                    $thumbPath = thumb_url($thumbName);
                    $hasThumb = is_file(uploads_fs('thumbs/' . $thumbName));
                ?>
                <article class="card-outdoor overflow-hidden group relative" style="animation: fadeInUp .45s var(--ease-out-soft) <?= ($i % 9) * 50 ?>ms backwards">
                    <!-- Mapový thumbnail -->
                    <a href="<?= h('detail.php?' . http_build_query(array_merge($_GET, ['id' => $tid, 'from' => 'index']))) ?>" class="block aspect-[16/9] relative overflow-hidden bg-gradient-to-br from-forest-400 to-forest-700">
                        <?php if ($hasThumb): ?>
                            <img src="<?= htmlspecialchars($thumbPath) ?>" alt="<?= h($name) ?>" loading="lazy"
                                 class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="absolute inset-0 topo-bg opacity-25"></div>
                            <?php if ($color): ?>
                                <div class="absolute inset-0" style="background: linear-gradient(135deg, <?= htmlspecialchars($color) ?> 0%, transparent 70%); opacity: 0.45;"></div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Badges top-right -->
                        <div class="absolute top-2 right-2 flex flex-wrap gap-1 justify-end max-w-[70%]">
                            <?php if ($diff !== '' && $diff !== null): ?>
                                <span class="px-2 py-0.5 rounded-full bg-white/90 text-forest-700 text-[11px] font-medium backdrop-blur"><?= htmlspecialchars($diff) ?></span>
                            <?php endif; ?>
                            <?php if ($act): ?>
                                <span class="px-2 py-0.5 rounded-full bg-terracotta-500 text-white text-[11px] font-medium"><?= htmlspecialchars($act) ?></span>
                            <?php endif; ?>
                        </div>

                        <!-- Decorative route icon -->
                        <i data-lucide="route" class="absolute bottom-3 left-3 w-6 h-6 text-white/70 group-hover:text-white transition-colors"></i>

                        <!-- Distance label -->
                        <div class="absolute bottom-3 right-3 px-2.5 py-1 rounded-md bg-forest-900/70 text-white text-xs backdrop-blur">
                            <span class="stat-num"><?= $dist ?></span> km
                        </div>
                    </a>

                    <!-- Favorite toggle -->
                    <button type="button"
                            data-track-id="<?= $tid ?>"
                            class="js-fav absolute top-2 left-2 w-9 h-9 inline-flex items-center justify-center rounded-full bg-white/90 hover:bg-white transition-colors shadow-soft <?= $isFav ? 'text-terracotta-500' : 'text-forest-700/60 hover:text-terracotta-500' ?>"
                            aria-label="<?= htmlspecialchars(t('btn_toggle_favorite')) ?>"
                            aria-pressed="<?= $isFav ? 'true' : 'false' ?>">
                        <i data-lucide="star" class="w-5 h-5" <?= $isFav ? 'fill="currentColor"' : '' ?>></i>
                    </button>

                    <div class="p-4">
                        <h3 class="font-[Manrope] font-semibold text-forest-700 dark:text-sand-100 group-hover:text-terracotta-500 transition-colors line-clamp-1">
                            <a href="<?= h('detail.php?' . http_build_query(array_merge($_GET, ['id' => $tid, 'from' => 'index']))) ?>"><?= htmlspecialchars($name) ?></a>
                        </h3>
                        <div class="mt-2 flex items-center gap-3 text-sm text-forest-700/70 dark:text-sand-100/70 flex-wrap">
                            <span class="flex items-center gap-1" title="<?= htmlspecialchars(t('th_ascent', 'Stoupání')) ?>">
                                <i data-lucide="trending-up" class="w-3.5 h-3.5"></i>
                                <span class="stat-num"><?= $asc ?></span> m
                            </span>
                            <span class="flex items-center gap-1" title="<?= htmlspecialchars(t('th_descent', 'Klesání')) ?>">
                                <i data-lucide="trending-down" class="w-3.5 h-3.5"></i>
                                <span class="stat-num"><?= $desc ?></span> m
                            </span>
                            <?php if ($date): ?>
                                <span class="ml-auto text-xs opacity-70"><?= $date ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-8 flex items-center justify-center gap-1.5 text-sm" aria-label="<?= htmlspecialchars(t('paging')) ?>">
            <?php
                $pgUrl = function ($p) {
                    $q = array_merge($_GET, ['page' => $p]);
                    // Pokud existuje aktivní filter, ujisti se, že filter_submit zůstane
                    $hasF = false;
                    foreach (['q','fav_only','activity','year','date_from','date_to','color','cat','diff_min','diff_max'] as $k) {
                        if (!empty($q[$k])) { $hasF = true; break; }
                    }
                    if ($hasF) $q['filter_submit'] = '1';
                    return 'index.php?' . http_build_query($q);
                };
                $prev = max(1, $page - 1);
                $next = min($total_pages, $page + 1);
            ?>
            <a href="<?= $pgUrl($prev) ?>" class="w-9 h-9 inline-flex items-center justify-center rounded-md border border-sand-200 dark:border-forest-700 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors <?= $page == 1 ? 'opacity-40 pointer-events-none' : '' ?>">
                <i data-lucide="chevron-left" class="w-4 h-4"></i>
            </a>
            <?php
            // Show pages near current
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);
            if ($start > 1) { echo '<a href="'.$pgUrl(1).'" class="w-9 h-9 inline-flex items-center justify-center rounded-md hover:bg-sand-100 dark:hover:bg-forest-800">1</a>'; if ($start > 2) echo '<span class="px-1 text-forest-700/40">…</span>'; }
            for ($p = $start; $p <= $end; $p++):
                $active = $p == $page;
            ?>
                <a href="<?= $pgUrl($p) ?>" class="min-w-9 h-9 px-3 inline-flex items-center justify-center rounded-md transition-colors <?= $active ? 'bg-forest-700 text-white' : 'hover:bg-sand-100 dark:hover:bg-forest-800' ?>"><?= $p ?></a>
            <?php endfor;
            if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="px-1 text-forest-700/40">…</span>'; echo '<a href="'.$pgUrl($total_pages).'" class="w-9 h-9 inline-flex items-center justify-center rounded-md hover:bg-sand-100 dark:hover:bg-forest-800">'.$total_pages.'</a>'; }
            ?>
            <a href="<?= $pgUrl($next) ?>" class="w-9 h-9 inline-flex items-center justify-center rounded-md border border-sand-200 dark:border-forest-700 hover:bg-sand-100 dark:hover:bg-forest-800 transition-colors <?= $page == $total_pages ? 'opacity-40 pointer-events-none' : '' ?>">
                <i data-lucide="chevron-right" class="w-4 h-4"></i>
            </a>

            <span class="ml-3 text-forest-700/60 dark:text-sand-100/60 text-xs">
                <?= htmlspecialchars(sprintf(t('paging_of'), $page, $total_pages)) ?>
            </span>
        </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

<!-- INSIGHTS BANNER -->
<section class="mx-auto max-w-7xl px-4 sm:px-6 mt-12 mb-8">
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="card-outdoor p-5">
            <div class="flex items-center gap-2 text-sm text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="route" class="w-4 h-4"></i> <?= htmlspecialchars(t('insight_total_tracks')) ?>
            </div>
            <div class="mt-2 stat-num text-3xl font-semibold text-forest-700 dark:text-sand-100"><?= number_format((int)($stats['total_tracks'] ?? 0), 0, ',', ' ') ?></div>
        </div>
        <div class="card-outdoor p-5">
            <div class="flex items-center gap-2 text-sm text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="ruler" class="w-4 h-4"></i> <?= htmlspecialchars(t('insight_total_km')) ?>
            </div>
            <div class="mt-2 stat-num text-3xl font-semibold text-terracotta-500"><?= number_format((float)($stats['total_km'] ?? 0), 0, ',', ' ') ?> km</div>
        </div>
        <div class="card-outdoor p-5">
            <div class="flex items-center gap-2 text-sm text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="mountain" class="w-4 h-4"></i> <?= htmlspecialchars(t('insight_total_ascent')) ?>
            </div>
            <div class="mt-2 stat-num text-3xl font-semibold text-forest-600"><?= number_format((int)($stats['total_ascent'] ?? 0), 0, ',', ' ') ?> m</div>
        </div>
        <div class="card-outdoor p-5">
            <div class="flex items-center gap-2 text-sm text-forest-700/60 dark:text-sand-100/60">
                <i data-lucide="gauge" class="w-4 h-4"></i> <?= htmlspecialchars(t('insight_avg_speed')) ?>
            </div>
            <div class="mt-2 stat-num text-3xl font-semibold text-forest-600"><?= number_format((float)($stats['avg_speed'] ?? 0), 1, ',', ' ') ?> km/h</div>
        </div>
    </div>
</section>

<style>
@keyframes fadeInUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
.line-clamp-1 { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<script>
// Favorite toggle (volá api_toggle_favorite.php)
document.addEventListener('click', async function (ev) {
    const btn = ev.target.closest('.js-fav');
    if (!btn) return;
    ev.preventDefault();
    ev.stopPropagation();
    const id = btn.dataset.trackId;
    const wasFav = btn.getAttribute('aria-pressed') === 'true';
    // Optimistic UI update
    btn.setAttribute('aria-pressed', wasFav ? 'false' : 'true');
    btn.classList.toggle('text-terracotta-500');
    btn.classList.toggle('text-forest-700/60');
    const icon = btn.querySelector('[data-lucide="star"], svg');
    if (icon) {
        if (!wasFav) icon.setAttribute('fill', 'currentColor');
        else icon.removeAttribute('fill');
    }
    try {
        const fd = new FormData();
        fd.append('id', id);
        const r = await fetch('api_toggle_favorite.php', { method: 'POST', body: fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
    } catch (e) {
        // Revert on error
        btn.setAttribute('aria-pressed', wasFav ? 'true' : 'false');
        btn.classList.toggle('text-terracotta-500');
        btn.classList.toggle('text-forest-700/60');
        console.error('Favorite toggle failed:', e);
    }
});
</script>

<?php require __DIR__ . '/layout_footer.php'; ?>
