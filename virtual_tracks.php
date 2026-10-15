<?php
/**
 * Virtuální trasy — seznam + generátor (chytré roztřídění nepřiřazených fotek).
 */
require_once __DIR__ . '/includes/public_access.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/virtual_track_helper.php';
check_page_access('virtual_tracks');

$allowedLangs = available_langs();

$_isAdmin = !empty($_SESSION['is_admin']);

// Seznam virtuálních tras + počet kandidátů (nepřiřazené fotky s GPS+časem)
$vtracks = $pdo->query("
    SELECT id, name, note, date_start, date_end, photo_count, distance_km, ascent, descent,
           centroid_lat, centroid_lon
    FROM virtual_tracks
    ORDER BY date_start DESC, id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$candidateCount = (int)$pdo->query("
    SELECT COUNT(*) FROM track_photos
    WHERE track_id IS NULL AND virtual_track_id IS NULL
      AND lat IS NOT NULL AND lon IS NOT NULL AND taken_at IS NOT NULL
")->fetchColumn();

$page_title = t('nav_virtual_tracks', 'Virtuální trasy');
require __DIR__ . '/includes/layout_header.php';
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<style>
    .vt-wrap { max-width: 1100px; }
    .vt-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; margin-bottom: 14px; }
    .vt-gen label { font-size: 13px; color: var(--text-muted); display: inline-flex; align-items: center; gap: 6px; margin-right: 16px; }
    .vt-gen input[type="number"] { width: 70px; padding: 4px 6px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--card-bg); color: var(--text-color); }
    .vt-list-item { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; margin-bottom: 8px; background: var(--card-bg); }
    .vt-list-item .meta { font-size: 12px; color: var(--text-muted); }
    .vt-list-item .note { font-size: 13px; color: var(--text-color); opacity: 0.9; margin-top: 4px; line-height: 1.4; max-width: 640px;
        display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; line-clamp: 2; overflow: hidden; }
    .vt-list-item a.name { font-weight: 600; color: var(--text-color); text-decoration: none; }
    .vt-list-item a.name:hover { color: var(--accent-color); }
    .vt-preview-row { padding: 6px 0; border-bottom: 1px dashed var(--border-color); font-size: 13px; }
    .vt-empty { color: var(--text-muted); font-size: 14px; }
    .vt-btn { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color); background: var(--bg-button); color: var(--text-color); cursor: pointer; font-size: 14px; }
    .vt-btn-primary { background: var(--accent-color); color: #fff; border-color: var(--accent-color); }
    .vt-del { background: #e53935; color: #fff; border: none; border-radius: 6px; padding: 5px 10px; cursor: pointer; font-size: 12px; }
</style>

<section class="mx-auto max-w-7xl px-4 sm:px-6 pt-6">
    <a href="index.php" class="inline-flex items-center gap-1.5 text-sm text-forest-700/70 dark:text-sand-100/70 hover:text-terracotta-500 transition-colors mb-3">
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i> <?= htmlspecialchars(preg_replace('/^←\s*/u', '', t('back_to_list'))) ?>
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="route" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        <?= htmlspecialchars(t('nav_virtual_tracks', 'Virtuální trasy')) ?>
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm"><?= htmlspecialchars(t('vt_subtitle')) ?></p>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12 vt-wrap">

<?php if ($_isAdmin): ?>
<!-- ===== Generátor ===== -->
<div class="vt-card vt-gen">
    <h3 style="margin:0 0 10px; font-size:16px;"><?= htmlspecialchars(t('vt_gen_title')) ?></h3>
    <p style="font-size:13px; color:var(--text-muted); margin:0 0 12px;">
        <?= str_replace('{n}', '<strong>' . (int)$candidateCount . '</strong>', htmlspecialchars(t('vt_gen_candidates'))) ?>
        <?= htmlspecialchars(t('vt_gen_cluster_info')) ?>
        <em><?= htmlspecialchars(t('vt_gen_approx')) ?></em>
    </p>
    <div style="margin-bottom:12px;">
        <label title="<?= htmlspecialchars(t('vt_gap_hours_tip')) ?>">
            <?= htmlspecialchars(t('vt_gap_hours_label')) ?> ⓘ: <input type="number" id="gapHours" value="4" min="0.5" step="0.5"
                title="<?= htmlspecialchars(t('vt_gap_hours_input_tip')) ?>">
        </label>
        <label title="<?= htmlspecialchars(t('vt_gap_km_tip')) ?>">
            <?= htmlspecialchars(t('vt_gap_km_label')) ?> ⓘ: <input type="number" id="gapKm" value="5" min="0.5" step="0.5"
                title="<?= htmlspecialchars(t('vt_gap_km_input_tip')) ?>">
        </label>
        <label title="<?= htmlspecialchars(t('vt_min_photos_tip')) ?>">
            <?= htmlspecialchars(t('vt_min_photos_label')) ?> ⓘ: <input type="number" id="minPhotos" value="3" min="2" step="1"
                title="<?= htmlspecialchars(t('vt_min_photos_input_tip')) ?>">
        </label>
    </div>
    <details style="margin-bottom:12px; font-size:12px; color:var(--text-muted);">
        <summary style="cursor:pointer; user-select:none;"><?= htmlspecialchars(t('vt_help_summary')) ?></summary>
        <div style="padding:8px 0 0 4px; line-height:1.6;">
            <?= htmlspecialchars(t('vt_help_1')) ?>
            <br><?= htmlspecialchars(t('vt_help_2')) ?>
            <br><?= htmlspecialchars(t('vt_help_3')) ?>
            <br><?= htmlspecialchars(t('vt_help_4')) ?>
            <br><em><?= htmlspecialchars(t('vt_help_5')) ?></em>
        </div>
    </details>
    <button class="vt-btn" id="previewBtn"><?= htmlspecialchars(t('vt_btn_preview')) ?></button>
    <button class="vt-btn vt-btn-primary" id="createBtn" style="display:none;"><?= htmlspecialchars(t('vt_btn_create')) ?></button>
    <div id="previewResult" style="margin-top:14px;"></div>
</div>
<?php endif; ?>

<!-- ===== Seznam virtuálních tras ===== -->
<div class="vt-card">
    <h3 style="margin:0 0 12px; font-size:16px;"><?= htmlspecialchars(str_replace('{n}', (string)count($vtracks), t('vt_list_title'))) ?></h3>
    <?php if (empty($vtracks)): ?>
        <p class="vt-empty"><?= htmlspecialchars(t('vt_empty')) ?><?= $_isAdmin ? ' ' . htmlspecialchars(t('vt_empty_admin')) : '' ?></p>
    <?php else: ?>
        <?php foreach ($vtracks as $vt): ?>
        <?php
        // Náhled mapy — lazy generování chybějícího (jen admin, jednorázově)
        $vtThumb = vt_thumb_path((int)$vt['id']);
        if (!is_file($vtThumb) && $_isAdmin) { @vt_generate_thumb($pdo, (int)$vt['id']); }
        $vtThumbUrl = is_file($vtThumb) ? (uploads_url('vt_thumbs/' . (int)$vt['id'] . '.png') . '?t=' . filemtime($vtThumb)) : null;
        ?>
        <div class="vt-list-item" id="vt-row-<?= (int)$vt['id'] ?>">
            <div style="display:flex; align-items:center; gap:12px;">
                <?php if ($vtThumbUrl): ?>
                <a href="virtual_track_detail.php?id=<?= (int)$vt['id'] ?>" style="flex-shrink:0;">
                    <img src="<?= h($vtThumbUrl) ?>" alt="<?= htmlspecialchars(t('vt_thumb_alt')) ?>" width="120" height="60"
                         style="width:120px; height:60px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                </a>
                <?php endif; ?>
                <div>
                    <a class="name" href="virtual_track_detail.php?id=<?= (int)$vt['id'] ?>"><?= h($vt['name'] ?: str_replace('{id}', (string)$vt['id'], t('vt_default_name'))) ?></a>
                    <div class="meta">
                        📅 <?= $vt['date_start'] ? h(date('j. n. Y', strtotime($vt['date_start']))) : '—' ?>
                        · 📸 <?= h(str_replace('{n}', (string)(int)$vt['photo_count'], t('photos_count'))) ?>
                        · 📏 ≈<?= h(number_format((float)$vt['distance_km'], 2, ',', ' ')) ?> km
                        <?php if ($vt['ascent'] !== null): ?> · ↑<?= (int)$vt['ascent'] ?> m<?php endif; ?>
                    </div>
                    <?php if (!empty($vt['note'])): ?>
                    <div class="note">📝 <?= nl2br(h($vt['note'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a class="vt-btn" href="virtual_track_detail.php?id=<?= (int)$vt['id'] ?>" style="padding:5px 10px; font-size:12px;"><?= htmlspecialchars(t('vt_btn_detail')) ?></a>
                <?php if ($_isAdmin): ?>
                <button class="vt-del" data-id="<?= (int)$vt['id'] ?>" data-name="<?= h($vt['name']) ?>"><?= htmlspecialchars(t('vt_btn_delete')) ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<script nonce="<?= csp_nonce() ?>">
const VT_CSRF = <?= json_encode(csrf_token()) ?>;
const I18N = <?= js_safe_json(array_combine($_vtJs = ['vt_js_computing', 'vt_js_error', 'vt_js_no_cluster', 'vt_js_proposal_label', 'vt_js_proposal', 'vt_js_name_aria', 'vt_js_suggest_btn', 'vt_js_finding_places', 'vt_js_suggest_failed', 'err_prefix', 'vt_js_confirm_create', 'vt_js_creating', 'vt_js_reloading', 'vt_js_confirm_delete'], array_map('t', $_vtJs))) ?>;
const T = (k, p) => { let s = I18N[k] ?? k; if (p) for (const x in p) s = s.split('{' + x + '}').join(p[x]); return s; };

<?php if ($_isAdmin): ?>
(function () {
    const $ = id => document.getElementById(id);
    const box = () => $('previewResult');
    let lastParams = null;   // parametry posledního náhledu — drží konzistenci preview → návrh → vytvoření

    function readParams() {
        return { gap_hours: $('gapHours').value, gap_km: $('gapKm').value, min_photos: $('minPhotos').value };
    }
    function paramsForm(p) {
        const fd = new FormData();
        fd.append('_csrf_token', VT_CSRF);
        fd.append('gap_hours', p.gap_hours);
        fd.append('gap_km', p.gap_km);
        fd.append('min_photos', p.min_photos);
        return fd;
    }

    $('previewBtn').addEventListener('click', () => {
        lastParams = readParams();
        box().innerHTML = T('vt_js_computing');
        fetch('api/virtual_tracks/preview.php', { method: 'POST', body: paramsForm(lastParams) })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { box().innerHTML = '⚠️ ' + (d.error || T('vt_js_error')); return; }
                if (d.cluster_count === 0) {
                    box().textContent = T('vt_js_no_cluster', { candidates: d.candidates, rejected: d.rejected });
                    $('createBtn').style.display = 'none';
                    return;
                }
                let html = `<p><strong>${T('vt_js_proposal_label')}</strong> ${T('vt_js_proposal', { clusters: d.cluster_count, candidates: d.candidates, rejected: d.rejected })}</p>`;
                d.clusters.forEach((c, i) => {
                    html += `<div class="vt-preview-row">
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:3px;">${i+1}. 📸 ${c.photo_count} · 📏 ≈${c.distance_km} km${c.ascent!=null?` · ↑${c.ascent} m`:''}</div>
                        <input type="text" class="vt-name-input" data-idx="${i}" aria-label="${T('vt_js_name_aria', { n: i + 1 })}" style="width:100%;max-width:560px;padding:5px 8px;border:1px solid var(--border-color);border-radius:6px;background:var(--card-bg);color:var(--text-color);font-size:13px;">
                    </div>`;
                });
                html += `<div style="margin-top:10px;"><button class="vt-btn" id="suggestNamesBtn" type="button">${T('vt_js_suggest_btn')}</button></div>`;
                box().innerHTML = html;
                // předvyplň datum-only názvy přes .value (bez HTML escapování)
                d.clusters.forEach((c, i) => {
                    const inp = box().querySelector(`.vt-name-input[data-idx="${i}"]`);
                    if (inp) inp.value = c.name;
                });
                const sb = $('suggestNamesBtn');
                if (sb) sb.addEventListener('click', suggestNames);
                $('createBtn').style.display = '';
            })
            .catch(e => { box().innerHTML = '⚠️ ' + e.message; });
    });

    function suggestNames() {
        const sb = $('suggestNamesBtn');
        if (!sb || !lastParams) return;
        const orig = sb.textContent;
        sb.disabled = true; sb.textContent = T('vt_js_finding_places');
        fetch('api/virtual_tracks/suggest_names.php', { method: 'POST', body: paramsForm(lastParams) })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { alert(T('vt_js_suggest_failed') + (d.error || '?')); return; }
                (d.names || []).forEach((nm, i) => {
                    const inp = box().querySelector(`.vt-name-input[data-idx="${i}"]`);
                    if (inp && nm) inp.value = nm;
                });
            })
            .catch(e => alert(T('err_prefix') + e.message))
            .finally(() => { sb.disabled = false; sb.textContent = orig; });
    }

    $('createBtn').addEventListener('click', () => {
        if (!lastParams) return;
        if (!confirm(T('vt_js_confirm_create'))) return;
        // sesbírej názvy z polí PŘED přepsáním obsahu boxu
        const fd = paramsForm(lastParams);
        box().querySelectorAll('.vt-name-input').forEach(inp => {
            fd.append('names[' + inp.dataset.idx + ']', inp.value);
        });
        box().innerHTML = T('vt_js_creating');
        fetch('api/virtual_tracks/create.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { box().innerHTML = '⚠️ ' + (d.error || T('vt_js_error')); return; }
                box().textContent = '✅ ' + d.message + ' ' + T('vt_js_reloading');
                setTimeout(() => location.reload(), 1200);
            })
            .catch(e => { box().innerHTML = '⚠️ ' + e.message; });
    });

    document.querySelectorAll('.vt-del').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!confirm(T('vt_js_confirm_delete', { name: btn.dataset.name || ('#' + id) }))) return;
            const fd = new FormData();
            fd.append('_csrf_token', VT_CSRF);
            fd.append('id', id);
            fetch('api/virtual_tracks/delete.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) { const row = document.getElementById('vt-row-' + id); if (row) row.remove(); }
                    else alert(T('err_prefix') + (d.error || '?'));
                })
                .catch(e => alert(T('err_prefix') + e.message));
        });
    });
})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
