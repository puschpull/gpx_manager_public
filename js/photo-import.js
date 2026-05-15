/* photo-import.js — extracted from photo_import.php (TASK-19) */
/* CSRF token is read from <meta name="csrf-token"> set by layout_header.php  */
(function () {
'use strict';

/* ── CSRF token ─────────────────────────────────────── */
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/* ── Constants ──────────────────────────────────────── */
const EXIF_BATCH  = 25;   // photos per exif_batch request
const IMP_BATCH   = 8;    // photos per do_import request
const RECENT_KEY  = 'gpx_photo_import_dirs';
const RECENT_MAX  = 6;

/* ── State ──────────────────────────────────────────── */
let allFiles     = [];
let selected     = new Set();
let activeFilter = 'all';

/* ── DOM refs ───────────────────────────────────────── */
const dirInput       = document.getElementById('dirInput');
const btnScan        = document.getElementById('btnScan');
const recentEl       = document.getElementById('recentDirs');
const galleryEl      = document.getElementById('impGallery');
const gallerySection = document.getElementById('gallerySection');
const loadingEl      = document.getElementById('impLoading');
const impStats       = document.getElementById('impStats');
const selCountEl     = document.getElementById('selCount');
const selHintEl      = document.getElementById('selHint');
const btnImport      = document.getElementById('btnImport');
const impResults     = document.getElementById('impResults');
const progressWrap   = document.getElementById('barProgress');
const progressBar    = document.getElementById('impProgressBar');
const progressText   = document.getElementById('impProgressText');

/* ── Recent paths ───────────────────────────────────── */
function loadRecent() {
    try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
    catch(e) { return []; }
}
function saveRecent(dir) {
    let arr = loadRecent().filter(d => d !== dir);
    arr.unshift(dir);
    localStorage.setItem(RECENT_KEY, JSON.stringify(arr.slice(0, RECENT_MAX)));
    renderRecent();
}
function renderRecent() {
    const dirs = loadRecent();
    if (!dirs.length) { recentEl.innerHTML = ''; return; }
    recentEl.innerHTML = '<span style="color:var(--text-muted)">Naposledy:</span> ' +
        dirs.map(d =>
            `<a class="imp-recent-chip" href="#" onclick="event.preventDefault();useDir(${JSON.stringify(d)})">${escHtml(d)}</a>`
        ).join('');
}
window.useDir = function(d) { dirInput.value = d; startScan(); };
renderRecent();

/* ── Scan ───────────────────────────────────────────── */
btnScan.addEventListener('click', startScan);
dirInput.addEventListener('keydown', e => { if (e.key === 'Enter') startScan(); });

async function startScan() {
    const dir = dirInput.value.trim();
    if (!dir) { dirInput.focus(); return; }

    allFiles = [];
    selected.clear();
    gallerySection.style.display = 'none';
    impResults.innerHTML = '';
    loadingEl.style.display = '';
    btnScan.disabled = true;
    progressWrap.style.display = 'none';
    progressBar.style.width = '0%';
    selHintEl.textContent = '— skenuju…';

    try {
        const resp = await fetch('api/photo_import/scan.php?dir=' + encodeURIComponent(dir), {
            headers: { 'X-CSRF-Token': getCsrfToken() }
        });
        const data = await resp.json();

        if (!data.ok) {
            loadingEl.innerHTML =
                `<div class="imp-loading" style="color:#c62828;">&#9888; ${escHtml(data.msg ?? data.error)}</div>`;
            btnScan.disabled = false;
            return;
        }

        if (!data.files.length) {
            loadingEl.innerHTML =
                `<div class="imp-loading">Zadny fotky (JPEG/PNG/WebP) nenalezeny v adresari.</div>`;
            btnScan.disabled = false;
            return;
        }

        allFiles = data.files.map(f => ({
            path:       f.path,
            name:       f.name,
            size:       f.size,
            mtime:      f.mtime,
            exifLoaded: false,
            hasGps:     null,
            isDup:      null,
            takenAt:    null,
            trackName:  null,
        }));

        saveRecent(dir);
        loadingEl.style.display = 'none';
        gallerySection.style.display = '';
        renderGallery();
        updateStats();
        btnScan.disabled = false;
        selHintEl.textContent = '— nacitam EXIF…';

        // Load EXIF asynchronously in batches
        loadExifBatches();

    } catch(err) {
        loadingEl.innerHTML =
            `<div class="imp-loading" style="color:#c62828;">Chyba: ${escHtml(String(err))}</div>`;
        btnScan.disabled = false;
    }
}

/* ── Gallery ────────────────────────────────────────── */
function renderGallery() {
    galleryEl.innerHTML = '';
    allFiles.forEach((f, idx) => galleryEl.appendChild(createCard(f, idx)));
    applyFilter();
}

function thumbUrl(path) {
    const bytes = new TextEncoder().encode(path);
    let bin = '';
    bytes.forEach(b => bin += String.fromCharCode(b));
    return 'api/photo_import/thumb.php?p=' + encodeURIComponent(btoa(bin));
}

// Thumbnail queue — max 4 concurrent requests (Apache protection)
const THUMB_CONCURRENCY = 4;
let thumbActive = 0;
const thumbQueue = [];

function thumbNext() {
    while (thumbActive < THUMB_CONCURRENCY && thumbQueue.length > 0) {
        const img = thumbQueue.shift();
        if (!img.dataset.src) continue;
        thumbActive++;
        const src = img.dataset.src;
        delete img.dataset.src;
        const loader = new Image();
        loader.onload  = () => { img.src = src; thumbActive--; thumbNext(); };
        loader.onerror = () => { img.dispatchEvent(new Event('error')); thumbActive--; thumbNext(); };
        loader.src = src;
    }
}

// IntersectionObserver lazy loads thumbnails
const thumbObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        const img = entry.target;
        if (img.dataset.src) thumbQueue.push(img);
        thumbObserver.unobserve(img);
    });
    thumbNext();
}, { rootMargin: '300px' });

function observeThumb(img) {
    if (img.dataset.src) thumbObserver.observe(img);
}

function createCard(f, idx) {
    const card = document.createElement('div');
    card.className = 'imp-card';
    card.dataset.idx = String(idx);

    card.innerHTML = `
        <div class="imp-card-img-wrap">
            <div class="imp-card-check">&#9744;</div>
            <div class="imp-card-badge">DUP</div>
            <img class="imp-card-thumb" data-src="${thumbUrl(f.path)}" alt="">
        </div>
        <div class="imp-card-info">
            <div class="imp-card-name" title="${escHtml(f.name)}">${escHtml(f.name)}</div>
            <div class="imp-card-meta imp-card-meta-${idx}">
                ${fmtDate(f.mtime * 1000)} &nbsp; ${fmtSize(f.size)}
            </div>
            <div class="imp-card-meta imp-card-gps-${idx}" style="margin-top:1px;"></div>
            <div class="imp-card-track imp-card-track-${idx}"></div>
        </div>`;

    // Lazy load + fallback if thumbnail fails
    const img = card.querySelector('.imp-card-thumb');
    img.addEventListener('error', () => {
        img.style.display = 'none';
        const wrap = card.querySelector('.imp-card-img-wrap');
        const ph = document.createElement('div');
        ph.className = 'imp-card-placeholder';
        ph.textContent = '🖼';
        wrap.appendChild(ph);
    });
    observeThumb(img);

    card.addEventListener('click', () => toggleSelect(idx));
    return card;
}

function toggleSelect(idx) {
    const f = allFiles[idx];
    if (f.isDup) return;
    if (selected.has(idx)) selected.delete(idx);
    else selected.add(idx);
    refreshCardUI(idx);
    updateStats();
}

function refreshCardUI(idx) {
    const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
    if (!card) return;
    const f   = allFiles[idx];
    const sel = selected.has(idx);

    card.classList.toggle('selected', sel);
    card.classList.toggle('is-dup', !!f.isDup);

    const checkEl = card.querySelector('.imp-card-check');
    if (checkEl) checkEl.textContent = sel ? '✓' : (f.isDup ? '✗' : '☐');

    const badgeEl = card.querySelector('.imp-card-badge');
    if (badgeEl) badgeEl.style.display = f.isDup ? '' : 'none';

    if (f.exifLoaded) {
        const gpsEl = card.querySelector('.imp-card-gps-' + idx);
        if (gpsEl) {
            gpsEl.innerHTML = f.hasGps
                ? '<span class="gps-yes">&#128994; GPS</span>'
                : '<span class="gps-no">&#9898; bez GPS</span>';
        }
        if (f.takenAt) {
            const metaEl = card.querySelector('.imp-card-meta-' + idx);
            if (metaEl) metaEl.textContent = f.takenAt.slice(0, 16).replace('T', ' ') + ' · ' + fmtSize(f.size);
        }
        const trackEl = card.querySelector('.imp-card-track-' + idx);
        if (trackEl) trackEl.textContent = f.trackName ? '→ ' + f.trackName : '';
    }
}

function applyFilter() {
    allFiles.forEach((f, idx) => {
        const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
        if (!card) return;
        let show = true;
        switch (activeFilter) {
            case 'new':     show = !f.isDup; break;
            case 'gps':     show = f.hasGps === true; break;
            case 'gps_new': show = f.hasGps === true && !f.isDup; break;
            case 'track':   show = !!f.trackName; break;
        }
        card.classList.toggle('hidden', !show);
    });
}

/* ── EXIF batch loading ─────────────────────────────── */
async function loadExifBatches() {
    const total = allFiles.length;
    for (let i = 0; i < total; i += EXIF_BATCH) {
        const slice = allFiles.slice(i, i + EXIF_BATCH);
        const paths = slice.map(f => f.path);

        try {
            const form = new FormData();
            paths.forEach(p => form.append('paths[]', p));

            const resp = await fetch('api/photo_import/exif.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCsrfToken() },
                body: form
            });
            const data = await resp.json();

            if (data.ok && Array.isArray(data.results)) {
                data.results.forEach(r => {
                    const idx = allFiles.findIndex(f => f.path === r.path);
                    if (idx < 0) return;
                    allFiles[idx].exifLoaded = true;
                    allFiles[idx].hasGps     = r.has_gps;
                    allFiles[idx].isDup      = r.is_dup;
                    allFiles[idx].takenAt    = r.taken_at;
                    allFiles[idx].trackName  = r.track_name;
                    if (r.is_dup) selected.delete(idx);
                    refreshCardUI(idx);
                });
                updateStats();
                applyFilter();
            }
        } catch(err) {
            if (window.GPX_DEBUG) console.warn('exif_batch error:', err);
        }
    }
    selHintEl.textContent = '';
}

/* ── Statistics ─────────────────────────────────────── */
function updateStats() {
    const total   = allFiles.length;
    const loaded  = allFiles.filter(f => f.exifLoaded).length;
    const withGps = allFiles.filter(f => f.hasGps).length;
    const fresh   = allFiles.filter(f => f.exifLoaded && !f.isDup).length;
    const dups    = allFiles.filter(f => f.isDup).length;

    impStats.innerHTML =
        `Nalezeno: <strong>${total}</strong> &nbsp;&middot;&nbsp; ` +
        `S GPS: <strong>${withGps}</strong> &nbsp;&middot;&nbsp; ` +
        `Novych: <strong>${fresh}</strong> &nbsp;&middot;&nbsp; ` +
        `Duplikatu: <strong>${dups}</strong>` +
        (loaded < total ? ` &nbsp;&middot;&nbsp; <em>EXIF: ${loaded}/${total}</em>` : '');

    const n = selected.size;
    selCountEl.textContent = `Vybrano: ${n}`;
    btnImport.disabled = n === 0;
    btnImport.textContent = n > 0 ? `Importovat vybrane (${n})` : 'Importovat vybrane';
}

/* ── Filters ────────────────────────────────────────── */
document.querySelectorAll('.imp-filter-btn[data-filter]').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.imp-filter-btn[data-filter]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeFilter = btn.dataset.filter;
        applyFilter();
    });
});

document.getElementById('btnSelectAll').addEventListener('click', () => {
    allFiles.forEach((f, idx) => {
        const card = galleryEl.querySelector(`[data-idx="${idx}"]`);
        if (card && !card.classList.contains('hidden') && !f.isDup) {
            selected.add(idx);
            refreshCardUI(idx);
        }
    });
    updateStats();
});

document.getElementById('btnDeselectAll').addEventListener('click', () => {
    selected.clear();
    allFiles.forEach((_, idx) => refreshCardUI(idx));
    updateStats();
});

/* ── Import ─────────────────────────────────────────── */
btnImport.addEventListener('click', startImport);

async function startImport() {
    const indices = [...selected];
    if (!indices.length) return;

    btnImport.disabled = true;
    btnScan.disabled   = true;
    impResults.innerHTML = '';
    progressWrap.style.display = '';
    progressBar.style.width    = '0%';
    progressBar.style.background = 'var(--accent-color)';

    const paths = indices.map(i => allFiles[i].path);
    const total = paths.length;
    let done = 0, okCount = 0, dupCount = 0, errCount = 0;

    const resultHeader = document.createElement('h3');
    resultHeader.style.cssText = 'font-size:15px;margin:0 0 8px;';
    resultHeader.textContent = 'Vysledky importu';
    impResults.appendChild(resultHeader);

    for (let i = 0; i < total; i += IMP_BATCH) {
        const batchPaths = paths.slice(i, i + IMP_BATCH);
        try {
            const form = new FormData();
            batchPaths.forEach(p => form.append('paths[]', p));

            const resp = await fetch('api/photo_import/import.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': getCsrfToken() },
                body: form
            });
            const data = await resp.json();

            if (data.ok && Array.isArray(data.results)) {
                data.results.forEach(r => {
                    done++;
                    if (r.ok) okCount++;
                    else if (r.duplicate) dupCount++;
                    else errCount++;
                    impResults.appendChild(buildResultRow(r));
                });
            }
        } catch(err) {
            done += batchPaths.length;
            errCount += batchPaths.length;
            if (window.GPX_DEBUG) console.error('do_import error:', err);
        }

        const pct = Math.round((done / total) * 100);
        progressBar.style.width = pct + '%';
        progressText.textContent = `${done} / ${total} zpracovano…`;
    }

    progressText.textContent =
        `Hotovo: ✅ importovano ${okCount}` +
        (dupCount ? ` · ⏭ duplikatu ${dupCount}` : '') +
        (errCount ? ` · ❌ chyb ${errCount}` : '');
    progressBar.style.background = errCount > 0 ? '#e53935' : '#2e7d32';

    if (okCount > 0) {
        const link = document.createElement('p');
        link.style.cssText = 'margin:10px 0 16px;';
        link.innerHTML = `<a href="photos.php" style="color:var(--accent-color);font-weight:600;font-size:14px;">→ Zobrazit ${okCount} novych fotek v galerii</a>`;
        impResults.insertBefore(link, resultHeader.nextSibling);
    }

    // Reset selection and reload EXIF (marks imported as dup)
    selected.clear();
    allFiles.forEach(f => { f.exifLoaded = false; f.isDup = null; f.hasGps = null; });
    allFiles.forEach((_, idx) => refreshCardUI(idx));
    updateStats();
    loadExifBatches();

    btnScan.disabled = false;
}

function buildResultRow(r) {
    const row = document.createElement('div');
    row.className = 'imp-result-row';

    let badge = '', sub = '';
    if (r.ok) {
        badge = '<span class="res-ok">✅ OK</span>';
        sub   = r.track_name ? `Prirazeno: ${escHtml(r.track_name)}` : '(bez prirazene trasy)';
        if (r.orig_size && r.stored_size && r.orig_size > r.stored_size) {
            const pct = Math.round((1 - r.stored_size / r.orig_size) * 100);
            sub += ` · zmenšeno o ${pct}%`;
        }
    } else if (r.duplicate) {
        badge = '<span class="res-dup">⏭ Duplikat</span>';
        sub   = r.track_name ? `Jiz prirazeno: ${escHtml(r.track_name)}` : 'Jiz v databazi';
    } else {
        badge = '<span class="res-err">❌ Chyba</span>';
        sub   = escHtml(r.msg || '');
    }

    const imgPart = r.thumb_url
        ? `<img src="${escHtml(r.thumb_url)}" alt="">`
        : `<div class="imp-result-icon">🖼</div>`;

    row.innerHTML = `
        ${imgPart}
        <div class="imp-result-info">
            <div>${badge} <strong>${escHtml(r.file || '')}</strong></div>
            <div class="imp-result-sub">${sub}</div>
        </div>`;
    return row;
}

/* ── Utility ────────────────────────────────────────── */
// Delegát na sdílené lib (js/lib/format-utils.js)
function escHtml(s) { return window.GpxFmt.escHtml(s); }
function fmtDate(ms) {
    const d = new Date(ms);
    return d.getFullYear() + '-'
        + String(d.getMonth()+1).padStart(2,'0') + '-'
        + String(d.getDate()).padStart(2,'0');
}
function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1024*1024) return (b/1024).toFixed(0) + ' KB';
    return (b/1024/1024).toFixed(1) + ' MB';
}

})();
