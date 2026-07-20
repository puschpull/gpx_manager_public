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

$page_title = 'Virtuální trasy';
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
        <i data-lucide="arrow-left" class="w-4 h-4" aria-hidden="true"></i> Zpět na seznam
    </a>
    <h1 class="font-[Manrope] text-3xl md:text-4xl font-extrabold tracking-tight text-forest-700 dark:text-sand-100 flex items-center gap-3">
        <i data-lucide="route" class="w-8 h-8 text-terracotta-500" aria-hidden="true"></i>
        Virtuální trasy
    </h1>
    <p class="mt-1 text-forest-700/70 dark:text-sand-100/70 text-sm">Trasy poskládané z GPS fotek (bez GPX záznamu) — chytré roztřídění nepřiřazených fotek do výletů</p>
</section>

<div class="mx-auto max-w-7xl px-4 sm:px-6 mt-6 pb-12 vt-wrap">

<?php if ($_isAdmin): ?>
<!-- ===== Generátor ===== -->
<div class="vt-card vt-gen">
    <h3 style="margin:0 0 10px; font-size:16px;">🛠 Vytvořit z nepřiřazených fotek</h3>
    <p style="font-size:13px; color:var(--text-muted); margin:0 0 12px;">
        Nepřiřazených fotek s GPS a časem: <strong><?= $candidateCount ?></strong>.
        Fotky se shluknou do výletů podle časové mezery i skoku v poloze.
        <em>Vzdálenost je přibližná (vzdušná čára mezi fotkami).</em>
    </p>
    <div style="margin-bottom:12px;">
        <label title="Když je mezi dvěma po sobě jdoucími fotkami delší pauza než tato hodnota, začne nový výlet. Vyšší = méně a větších tras (slévá); nižší = víc a menších tras (dělí i na přestávku).">
            Časová mezera (h) ⓘ: <input type="number" id="gapHours" value="4" min="0.5" step="0.5"
                title="Nová trasa při časové pauze delší než tolik hodin. Pro celodenní výšlapy 4–6 h.">
        </label>
        <label title="Když jsou dvě po sobě jdoucí fotky dál od sebe než tato vzdálenost, začne nový výlet — i při malém časovém odstupu. Vyšší = toleruje skoky (i ulítlou GPS fotku); nižší = líp dělí různá místa, ale ulítlá fotka může trasu falešně roztrhnout.">
            Skok v poloze (km) ⓘ: <input type="number" id="gapKm" value="5" min="0.5" step="0.5"
                title="Nová trasa při skoku v poloze větším než tolik km. Vyšší toleruje chybný GPS fix.">
        </label>
        <label title="Shluky s méně fotkami než tato hodnota se nezahrnou — virtuální trasa z nich nevznikne a fotky zůstanou nepřiřazené (nic se nesmaže). Vyšší = jen „pořádné“ trasy; nižší = i dvojice fotek se stane trasou.">
            Min. fotek na trasu ⓘ: <input type="number" id="minPhotos" value="3" min="2" step="1"
                title="Shluky s méně fotkami se přeskočí (zůstanou nepřiřazené).">
        </label>
    </div>
    <details style="margin-bottom:12px; font-size:12px; color:var(--text-muted);">
        <summary style="cursor:pointer; user-select:none;">ⓘ Jak volby fungují</summary>
        <div style="padding:8px 0 0 4px; line-height:1.6;">
            Fotky se seřadí podle času a projdou jedna po druhé. Nový výlet začne, když je vůči předchozí fotce
            <strong>časová mezera</strong> větší než zadané hodiny <em>nebo</em> <strong>skok v poloze</strong> větší než zadané km
            (stačí jedna z podmínek). Shluk se nakonec zahodí, pokud nemá aspoň <strong>min. fotek na trasu</strong>.
            <br>• Trhá to jeden výlet na víc kousků → zvyš časovou mezeru a/nebo skok v poloze.
            <br>• Slévá to dva různé výlety do jednoho → sniž je.
            <br>• Vznikají zbytečné mini-trasy → zvyš „min. fotek“.
            <br><em>Náhled nic nezapisuje — klidně si hodnoty vyzkoušej.</em>
        </div>
    </details>
    <button class="vt-btn" id="previewBtn">👁 Náhled (nic nezapíše)</button>
    <button class="vt-btn vt-btn-primary" id="createBtn" style="display:none;">✅ Vytvořit trasy</button>
    <div id="previewResult" style="margin-top:14px;"></div>
</div>
<?php endif; ?>

<!-- ===== Seznam virtuálních tras ===== -->
<div class="vt-card">
    <h3 style="margin:0 0 12px; font-size:16px;">📋 Seznam (<?= count($vtracks) ?>)</h3>
    <?php if (empty($vtracks)): ?>
        <p class="vt-empty">Zatím žádné virtuální trasy.<?= $_isAdmin ? ' Vytvoř je z nepřiřazených fotek výše.' : '' ?></p>
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
                    <img src="<?= h($vtThumbUrl) ?>" alt="náhled trasy" width="120" height="60"
                         style="width:120px; height:60px; object-fit:cover; border-radius:6px; border:1px solid var(--border-color);">
                </a>
                <?php endif; ?>
                <div>
                    <a class="name" href="virtual_track_detail.php?id=<?= (int)$vt['id'] ?>"><?= h($vt['name'] ?: ('Virtuální trasa #' . $vt['id'])) ?></a>
                    <div class="meta">
                        📅 <?= $vt['date_start'] ? h(date('j. n. Y', strtotime($vt['date_start']))) : '—' ?>
                        · 📸 <?= (int)$vt['photo_count'] ?> fotek
                        · 📏 ≈<?= h(number_format((float)$vt['distance_km'], 2, ',', ' ')) ?> km
                        <?php if ($vt['ascent'] !== null): ?> · ↑<?= (int)$vt['ascent'] ?> m<?php endif; ?>
                    </div>
                    <?php if (!empty($vt['note'])): ?>
                    <div class="note">📝 <?= nl2br(h($vt['note'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center;">
                <a class="vt-btn" href="virtual_track_detail.php?id=<?= (int)$vt['id'] ?>" style="padding:5px 10px; font-size:12px;">🗺 Detail</a>
                <?php if ($_isAdmin): ?>
                <button class="vt-del" data-id="<?= (int)$vt['id'] ?>" data-name="<?= h($vt['name']) ?>">🗑 Smazat</button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

<script>
const VT_CSRF = <?= json_encode(csrf_token()) ?>;

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
        box().innerHTML = '⏳ Počítám…';
        fetch('api/virtual_tracks/preview.php', { method: 'POST', body: paramsForm(lastParams) })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { box().innerHTML = '⚠️ ' + (d.error || 'Chyba'); return; }
                if (d.cluster_count === 0) {
                    box().innerHTML = `Z ${d.candidates} nepřiřazených fotek nevznikla žádná trasa (zkus menší „min. fotek" nebo větší mezery). Nezařazeno: ${d.rejected}.`;
                    $('createBtn').style.display = 'none';
                    return;
                }
                let html = `<p><strong>Návrh:</strong> ${d.cluster_count} výletů z ${d.candidates} fotek (nezařazeno ${d.rejected}). Název uprav ručně, nebo nech doplnit dle místa:</p>`;
                d.clusters.forEach((c, i) => {
                    html += `<div class="vt-preview-row">
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:3px;">${i+1}. 📸 ${c.photo_count} · 📏 ≈${c.distance_km} km${c.ascent!=null?` · ↑${c.ascent} m`:''}</div>
                        <input type="text" class="vt-name-input" data-idx="${i}" aria-label="Název trasy ${i+1}" style="width:100%;max-width:560px;padding:5px 8px;border:1px solid var(--border-color);border-radius:6px;background:var(--card-bg);color:var(--text-color);font-size:13px;">
                    </div>`;
                });
                html += `<div style="margin-top:10px;"><button class="vt-btn" id="suggestNamesBtn" type="button">✨ Navrhnout názvy dle míst</button></div>`;
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
        sb.disabled = true; sb.textContent = '⏳ Hledám místa…';
        fetch('api/virtual_tracks/suggest_names.php', { method: 'POST', body: paramsForm(lastParams) })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { alert('Návrh názvů selhal: ' + (d.error || '?')); return; }
                (d.names || []).forEach((nm, i) => {
                    const inp = box().querySelector(`.vt-name-input[data-idx="${i}"]`);
                    if (inp && nm) inp.value = nm;
                });
            })
            .catch(e => alert('Chyba: ' + e.message))
            .finally(() => { sb.disabled = false; sb.textContent = orig; });
    }

    $('createBtn').addEventListener('click', () => {
        if (!lastParams) return;
        if (!confirm('Vytvořit virtuální trasy podle náhledu? Fotky se přiřadí k těmto trasám.')) return;
        // sesbírej názvy z polí PŘED přepsáním obsahu boxu
        const fd = paramsForm(lastParams);
        box().querySelectorAll('.vt-name-input').forEach(inp => {
            fd.append('names[' + inp.dataset.idx + ']', inp.value);
        });
        box().innerHTML = '⏳ Vytvářím…';
        fetch('api/virtual_tracks/create.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) { box().innerHTML = '⚠️ ' + (d.error || 'Chyba'); return; }
                box().innerHTML = '✅ ' + d.message + ' Stránka se obnoví…';
                setTimeout(() => location.reload(), 1200);
            })
            .catch(e => { box().innerHTML = '⚠️ ' + e.message; });
    });

    document.querySelectorAll('.vt-del').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (!confirm(`Smazat virtuální trasu „${btn.dataset.name || ('#'+id)}"? Fotky se vrátí mezi nepřiřazené (nesmažou se).`)) return;
            const fd = new FormData();
            fd.append('_csrf_token', VT_CSRF);
            fd.append('id', id);
            fetch('api/virtual_tracks/delete.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) { const row = document.getElementById('vt-row-' + id); if (row) row.remove(); }
                    else alert('Chyba: ' + (d.error || '?'));
                })
                .catch(e => alert('Chyba: ' + e.message));
        });
    });
})();
<?php endif; ?>
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
