/* photos.js — extracted from photos.php (TASK-18)
 * All mutating fetch calls point to api/photos/*.php
 * CSRF token is read from the <meta name="csrf-token"> tag set by layout_header.php
 */

/* CSRF token read once from meta tag — sent as header on all mutating requests */
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

/* ===================== Záložky ===================== */
document.querySelectorAll('.photos-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + tab.dataset.tab)?.classList.add('active');
        // Clear bulk selection when switching tabs
        clearBulkSelection();
    });
});

/* ===================== Aktivní záložka z URL hash ===================== */
(function () {
    const hash = location.hash.replace('#', '');
    if (!hash) return;
    const tab = document.querySelector(`.photos-tab[data-tab="${hash}"]`);
    if (!tab) return;
    document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
    tab.classList.add('active');
    document.getElementById('tab-' + hash)?.classList.add('active');
})();

/* ===================== Přímý odkaz na trasu (?track_id=X) ===================== */
(function () {
    const params  = new URLSearchParams(location.search);
    const trackId = params.get('track_id');
    if (!trackId) return;

    // Přepnout na záložku Správa
    document.querySelectorAll('.photos-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.photos-tab-content').forEach(c => c.classList.remove('active'));
    const galleryTab = document.querySelector('.photos-tab[data-tab="gallery"]');
    if (galleryTab) galleryTab.classList.add('active');
    document.getElementById('tab-gallery')?.classList.add('active');

    // Scroll na sekci trasy a zvýraznit ji
    const section = document.getElementById('track-section-' + trackId);
    if (section) {
        setTimeout(() => {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            section.style.outline = '2px solid var(--accent-color)';
            section.style.borderRadius = '8px';
            setTimeout(() => { section.style.outline = ''; }, 2500);
        }, 100);
    }
})();

/* ===================== Upload ===================== */
const dropzone  = document.getElementById('photoDropzone');
const fileInput = document.getElementById('photoFileInput');
const progress  = document.getElementById('uploadProgress');

if (dropzone && fileInput && progress) {
    dropzone.addEventListener('click', () => fileInput.click());
    dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', e => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        if (e.dataTransfer.files.length) uploadFiles(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) uploadFiles(fileInput.files);
    });
}

// Dynamické dávkování: nová dávka začne, jakmile by aktuální překročila
// MAX_BYTES nebo MAX_FILES. Hodnoty jsou bezpečné i pro hosting s přísnými
// PHP limity (post_max_size=8M, max_file_uploads=20).
const UPLOAD_MAX_FILES = 8;
const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;   // ~5 MB / dávka

async function uploadFiles(files) {
    const fileArr = Array.from(files);
    const total   = fileArr.length;
    const hasZip  = fileArr.some(f => f.name.toLowerCase().endsWith('.zip'));

    // Rozdělení do dávek podle velikosti i počtu souborů.
    const batches = [];
    let curBatch  = [];
    let curBytes  = 0;
    for (const f of fileArr) {
        const fSize = f.size || 0;
        const wouldOverflow = curBatch.length > 0 &&
            (curBatch.length >= UPLOAD_MAX_FILES || curBytes + fSize > UPLOAD_MAX_BYTES);
        if (wouldOverflow) {
            batches.push(curBatch);
            curBatch = [];
            curBytes = 0;
        }
        curBatch.push(f);
        curBytes += fSize;
    }
    if (curBatch.length > 0) batches.push(curBatch);

    // Progress bar UI
    const statusEl = document.createElement('div');
    statusEl.style.cssText = 'font-size:13px; color:var(--text-muted); margin-bottom:8px;';
    const barWrap = document.createElement('div');
    barWrap.style.cssText = 'background:var(--border-color,#e0e0e0); border-radius:4px; height:8px; margin-bottom:10px; overflow:hidden;';
    const barFill = document.createElement('div');
    barFill.style.cssText = 'height:100%; background:var(--accent-color,#2d4a3e); width:0%; transition:width .3s;';
    barWrap.appendChild(barFill);
    progress.innerHTML = '';
    progress.appendChild(statusEl);
    progress.appendChild(barWrap);

    let doneFiles = 0;
    let totalOk   = 0;
    const results = [];

    const updateProgress = (batchIdx) => {
        const pct = total > 0 ? Math.round((doneFiles / total) * 100) : 0;
        barFill.style.width = pct + '%';
        if (hasZip) {
            statusEl.textContent = `⏳ Nahrávám ZIP dávku ${batchIdx + 1} / ${batches.length}…`;
        } else {
            statusEl.textContent = `⏳ Dávka ${batchIdx + 1} / ${batches.length} · ${doneFiles} / ${total} fotek (${pct}%)`;
        }
    };

    for (let bi = 0; bi < batches.length; bi++) {
        updateProgress(bi);
        const batch = batches[bi];
        const fd = new FormData();
        for (const f of batch) fd.append('photos[]', f);

        try {
            const resp = await fetch('api/photos/upload.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: fd
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            for (const r of (data.results || [])) {
                results.push(r);
                if (r.ok) totalOk++;
            }
            doneFiles += batch.length;
        } catch (err) {
            const errDiv = document.createElement('div');
            errDiv.style.cssText = 'color:#c62828; font-size:13px;';
            errDiv.textContent = `Chyba v dávce ${bi + 1}: ${err.message}`;
            progress.appendChild(errDiv);
            doneFiles += batch.length;
        }
    }

    // Finální výpis výsledků
    barFill.style.width = '100%';
    statusEl.textContent = `✅ Hotovo — zpracováno ${total} ${total === 1 ? 'soubor' : 'souborů'}`;

    for (const r of results) {
        const div = document.createElement('div');
        div.className = 'upload-result-row';
        if (r.ok) {
            const sizeInfo = (r.orig_size && r.stored_size)
                ? ` <span style="color:var(--text-muted); font-size:11px;">${fmtSize(r.orig_size)} → ${fmtSize(r.stored_size)}</span>`
                : '';
            div.innerHTML = `
                <img src="${r.thumb_url}" onerror="this.style.opacity='.3'">
                <div class="res-info">
                    <span class="badge-ok">✓</span> ${escHtml(r.file)}${sizeInfo}
                    <br><span class="badge-gps">${r.has_gps ? '📍 GPS nalezena' : '⚠ Bez GPS — nezobrazí se na mapě'}</span>
                    ${r.track_name ? `<div class="res-track">🗺 Přiřazeno: ${escHtml(r.track_name)}</div>` : '<div class="res-track">⚠ Nepřiřazeno k trase</div>'}
                </div>`;
        } else if (r.duplicate) {
            div.innerHTML = `
                <img src="${r.thumb_url}" onerror="this.style.opacity='.3'">
                <div class="res-info">
                    <span style="color:#e65c00; font-weight:700;">⚠ Duplikát</span> ${escHtml(r.file)}
                    <br><span class="badge-gps" style="color:#e65c00;">${escHtml(r.msg)}</span>
                    ${r.track_name ? `<div class="res-track">🗺 ${escHtml(r.track_name)}</div>` : ''}
                </div>`;
        } else {
            div.innerHTML = `<div class="res-info"><span class="badge-err">✗</span> ${escHtml(r.file)}: ${escHtml(r.msg)}</div>`;
        }
        progress.appendChild(div);
    }

    if (totalOk > 0) {
        const note = document.createElement('div');
        note.style.cssText = 'margin-top:10px; font-size:13px; color:var(--text-muted);';
        note.textContent = `✅ Nahráno ${totalOk} fotek. Stránka se za chvíli obnoví…`;
        progress.appendChild(note);
        setTimeout(() => location.reload(), 2500);
    }
}

// Delegát na sdílené lib (js/lib/format-utils.js)
function escHtml(s) { return window.GpxFmt.escHtml(s); }

function fmtSize(bytes) {
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
    if (bytes >= 1024)    return Math.round(bytes / 1024) + ' KB';
    return bytes + ' B';
}

/* ===================== Přiřazení k trase ===================== */
let currentAssignPhotoId = null;
const assignModal  = document.getElementById('assignModal');
const assignSelect = document.getElementById('assignTrackSelect');

document.querySelectorAll('.btn-assign').forEach(btn => {
    btn.addEventListener('click', () => {
        currentAssignPhotoId = parseInt(btn.dataset.id);
        assignSelect.value = btn.dataset.track || '';
        assignModal.classList.add('open');
    });
});

document.getElementById('assignCancelBtn')?.addEventListener('click', () => {
    assignModal.classList.remove('open');
});

// assignSaveBtn handler is defined below (handles both single + bulk)

/* ===================== Viditelnost fotky ===================== */

// Helpers — aktualizuje UI tlačítka + karty bez AJAX
function applyVisibleUI(btn, visible) {
    const card = btn.closest('.photo-card');
    btn.dataset.visible = visible ? '1' : '0';
    btn.textContent     = visible ? '👁' : '🙈';
    btn.title           = visible ? 'Zobrazená na trase — kliknutím skrýt' : 'Skrytá — kliknutím zobrazit na trase';
    btn.classList.toggle('btn-hidden', !visible);
    if (card) card.classList.toggle('photo-is-hidden', !visible);
}

let _lastVisibleBtn   = null;  // kotva pro shift+click
let _lastVisibleState = null;  // stav nastavený posledním (ne-shift) klikem

document.querySelectorAll('.btn-visible').forEach(btn => {
    btn.addEventListener('click', async (e) => {
        const allBtns = Array.from(document.querySelectorAll('.btn-visible'));

        if (e.shiftKey && _lastVisibleBtn && _lastVisibleBtn !== btn && _lastVisibleState !== null) {
            // ── Range operace ──
            const idxA = allBtns.indexOf(_lastVisibleBtn);
            const idxB = allBtns.indexOf(btn);
            if (idxA === -1 || idxB === -1) return;
            const [from, to] = idxA < idxB ? [idxA, idxB] : [idxB, idxA];
            const rangeState = _lastVisibleState;
            const rangeBtns  = allBtns.slice(from, to + 1);
            const rangeIds   = rangeBtns.map(b => b.dataset.id);

            // Okamžitě aktualizuj UI
            rangeBtns.forEach(b => applyVisibleUI(b, rangeState));

            // Jeden hromadný AJAX
            const fd = new FormData();
            fd.append('visible', rangeState ? '1' : '0');
            rangeIds.forEach(id => fd.append('photo_ids[]', id));
            const resp = await fetch('api/photos/visibility.php?action=bulk_visible', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: fd
            });
            const data = await resp.json();
            if (!data.ok) {
                // Rollback UI při chybě
                rangeBtns.forEach(b => applyVisibleUI(b, !rangeState));
            }
            return;
        }

        // ── Normální single klik ──
        const fd = new FormData();
        fd.append('photo_id', btn.dataset.id);
        const resp = await fetch('api/photos/visibility.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        });
        const data = await resp.json();
        if (!data.ok) return;
        const visible = data.visible === 1;
        applyVisibleUI(btn, visible);
        _lastVisibleBtn   = btn;
        _lastVisibleState = visible;
    });
});

/* ===================== Smazání ===================== */
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', async () => {
        const name = btn.dataset.name;
        if (!confirm(`Smazat fotku "${name}"? Tuto akci nelze vrátit.`)) return;
        const fd = new FormData();
        fd.append('photo_id', btn.dataset.id);
        const resp = await fetch('api/photos/delete.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        });
        const data = await resp.json();
        if (data.ok) btn.closest('.photo-card').remove();
    });
});

/* ===================== Caption inline edit (B2) ===================== */
document.addEventListener('click', e => {
    const display = e.target.closest('.photo-caption-display');
    if (!display) return;
    const wrap  = display.closest('.photo-caption-wrap');
    const edit  = wrap.querySelector('.photo-caption-edit');
    const input = wrap.querySelector('.caption-input');
    display.style.display = 'none';
    edit.style.display    = 'block';
    input.focus();
    input.select();
});

document.addEventListener('keydown', async e => {
    const input = e.target.closest('.caption-input');
    if (!input) return;
    if (e.key === 'Escape') {
        cancelCaptionEdit(input);
        return;
    }
    if (e.key === 'Enter') {
        await saveCaptionEdit(input);
    }
});

document.addEventListener('focusout', async e => {
    const input = e.target.closest('.caption-input');
    if (!input) return;
    // Small delay so click events fire first
    setTimeout(() => saveCaptionEdit(input), 120);
});

async function saveCaptionEdit(input) {
    const wrap    = input.closest('.photo-caption-wrap');
    if (!wrap || wrap.dataset.saving) return;
    wrap.dataset.saving = '1';
    const photoId = parseInt(wrap.dataset.id);
    const caption = input.value.trim();

    const fd = new FormData();
    fd.append('photo_id', photoId);
    fd.append('caption', caption);

    try {
        await fetch('api/photos/caption.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        });
    } catch (_) {}

    const display = wrap.querySelector('.photo-caption-display');
    const edit    = wrap.querySelector('.photo-caption-edit');
    display.innerHTML = caption
        ? escHtml(caption)
        : '<span class="caption-empty">+ popisek</span>';
    display.style.display = '';
    edit.style.display    = 'none';
    delete wrap.dataset.saving;
}

function cancelCaptionEdit(input) {
    const wrap    = input.closest('.photo-caption-wrap');
    const display = wrap.querySelector('.photo-caption-display');
    const edit    = wrap.querySelector('.photo-caption-edit');
    display.style.display = '';
    edit.style.display    = 'none';
    delete wrap.dataset.saving;
}

/* ===================== Lightbox — galerie ===================== */
function initGridLightbox(grid) {
    const imgs = Array.from(grid.querySelectorAll('img[data-full-url]'));
    imgs.forEach((img, i) => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', (e) => {
            const photos = imgs.map(el => ({
                full_url: el.dataset.fullUrl,
                alt:      el.alt,
                taken_at: el.dataset.takenAt || ''
            }));
            // A11Y-012: pass trigger so focus returns after close
            window.gpxLightbox?.open(photos, i, e.currentTarget);
        });
    });
}

// Init for all existing grids
document.querySelectorAll('.photo-grid').forEach(initGridLightbox);

/* ===================== Throttled lazy loader ===================== */
(function () {
    const MAX_CONCURRENT = 4;
    let loading = 0;
    const queue = [];

    function loadNext() {
        while (loading < MAX_CONCURRENT && queue.length) {
            const img = queue.shift();
            if (!img.dataset.src) continue;
            loading++;
            const src = img.dataset.src;
            const tmp = new Image();
            tmp.onload = tmp.onerror = () => {
                if (tmp.naturalWidth) img.src = src;
                else img.style.opacity = '.3';
                delete img.dataset.src;
                loading--;
                loadNext();
            };
            tmp.src = src;
        }
    }

    function enqueue(img) {
        if (!img.dataset.src || img._lazyQueued) return;
        img._lazyQueued = true;
        queue.push(img);
        loadNext();
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(e => { if (e.isIntersecting) enqueue(e.target); });
    }, { rootMargin: '200px' });

    function observeAll() {
        document.querySelectorAll('img.lazy-thumb[data-src]').forEach(img => {
            observer.observe(img);
        });
    }

    // observe on load and whenever a <details> is opened (new thumbnails become relevant)
    observeAll();
    document.addEventListener('toggle', e => {
        if (e.target.classList?.contains('timeline-month')) observeAll();
    }, true);
})();

/* ===================== Lightbox — timeline ===================== */
document.querySelectorAll('[data-timeline-day]').forEach(dayEl => {
    const imgs = Array.from(dayEl.querySelectorAll('img[data-full-url]'));
    imgs.forEach((img, i) => {
        img.addEventListener('click', (e) => {
            const photos = imgs.map(el => ({
                full_url: el.dataset.fullUrl,
                alt:      el.alt,
                taken_at: el.dataset.takenAt || ''
            }));
            // A11Y-012: pass trigger so focus returns after close
            window.gpxLightbox?.open(photos, i, e.currentTarget);
        });
    });
});

/* ===================== Hromadné operace (Bulk) ===================== */
const bulkBar       = document.getElementById('bulkActionBar');
const bulkCountEl   = document.getElementById('bulkCount');
const bulkAssignBtn = document.getElementById('bulkAssignBtn');
const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
const bulkSelAllBtn = document.getElementById('bulkSelectAllBtn');
const bulkClearBtn  = document.getElementById('bulkClearBtn');

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.photo-select-cb:checked'))
                .map(cb => parseInt(cb.dataset.id));
}

function updateBulkBar() {
    const ids = getSelectedIds();
    bulkCountEl.textContent = ids.length + ' vybráno';
    if (ids.length > 0) {
        bulkBar.classList.add('visible');
    } else {
        bulkBar.classList.remove('visible');
    }
    // Mark/unmark cards
    document.querySelectorAll('.photo-card').forEach(card => {
        const cb = card.querySelector('.photo-select-cb');
        if (cb) card.classList.toggle('selected', cb.checked);
    });
}

function clearBulkSelection() {
    document.querySelectorAll('.photo-select-cb').forEach(cb => cb.checked = false);
    updateBulkBar();
}

/* ── Shift+click range selection na checkboxech ── */
let _lastCb = null;  // kotva pro shift+click

document.addEventListener('click', e => {
    const cb = e.target;
    if (!cb.classList || !cb.classList.contains('photo-select-cb')) return;

    if (e.shiftKey && _lastCb && _lastCb !== cb) {
        const allCbs = Array.from(document.querySelectorAll('.photo-select-cb'));
        const idxA   = allCbs.indexOf(_lastCb);
        const idxB   = allCbs.indexOf(cb);
        if (idxA !== -1 && idxB !== -1) {
            const [from, to] = idxA < idxB ? [idxA, idxB] : [idxB, idxA];
            const target = cb.checked;
            for (let i = from; i <= to; i++) allCbs[i].checked = target;
        }
    }

    _lastCb = cb;
    updateBulkBar();
});

document.addEventListener('change', e => {
    if (e.target.classList.contains('photo-select-cb')) updateBulkBar();
});

// Select all visible in active tab
if (bulkSelAllBtn) {
    bulkSelAllBtn.addEventListener('click', () => {
        const activeContent = document.querySelector('.photos-tab-content.active');
        if (!activeContent) return;
        activeContent.querySelectorAll('.photo-select-cb').forEach(cb => cb.checked = true);
        updateBulkBar();
    });
}

if (bulkClearBtn) {
    bulkClearBtn.addEventListener('click', clearBulkSelection);
}

// Select/clear per track section
document.addEventListener('click', e => {
    const selAll  = e.target.closest('.btn-track-sel-all');
    const selNone = e.target.closest('.btn-track-sel-none');
    if (!selAll && !selNone) return;
    const sectionId = (selAll || selNone).dataset.section;
    const section   = document.getElementById(sectionId);
    if (!section) return;
    section.querySelectorAll('.photo-select-cb').forEach(cb => {
        cb.checked = !!selAll;
    });
    updateBulkBar();
});

// Bulk delete
if (bulkDeleteBtn) {
    bulkDeleteBtn.addEventListener('click', async () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        if (!confirm(`Smazat ${ids.length} vybraných fotek? Tuto akci nelze vrátit.`)) return;

        const fd = new FormData();
        ids.forEach(id => fd.append('photo_ids[]', id));

        try {
            const resp = await fetch('api/photos/bulk.php?action=bulk_delete', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrfToken },
                body: fd
            });
            const data = await resp.json();
            if (data.ok) {
                ids.forEach(id => {
                    document.querySelectorAll(`.photo-card[data-id="${id}"]`).forEach(c => c.remove());
                });
                clearBulkSelection();
            }
        } catch (err) {
            alert('Chyba při mazání: ' + err.message);
        }
    });
}

// Bulk assign — opens modal, assigns after save
let _bulkAssigning = false;
if (bulkAssignBtn) {
    bulkAssignBtn.addEventListener('click', () => {
        const ids = getSelectedIds();
        if (!ids.length) return;
        _bulkAssigning = true;
        currentAssignPhotoId = null; // signal that this is bulk
        assignSelect.value = '';
        assignModal.classList.add('open');
    });
}

// Override assignSaveBtn to handle bulk
document.getElementById('assignSaveBtn')?.addEventListener('click', async () => {
    if (_bulkAssigning) {
        _bulkAssigning = false;
        const ids = getSelectedIds();
        if (!ids.length) { assignModal.classList.remove('open'); return; }

        const fd = new FormData();
        fd.append('track_id', assignSelect.value);
        ids.forEach(id => fd.append('photo_ids[]', id));

        const resp = await fetch('api/photos/bulk.php?action=bulk_assign', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body: fd
        });
        const data = await resp.json();
        if (data.ok) {
            assignModal.classList.remove('open');
            clearBulkSelection();
            location.reload();
        }
        return;
    }
    // Single assign (original logic)
    if (!currentAssignPhotoId) return;
    const fd = new FormData();
    fd.append('photo_id', currentAssignPhotoId);
    fd.append('track_id', assignSelect.value);
    const resp = await fetch('api/photos/assign.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: fd
    });
    const data = await resp.json();
    if (data.ok) { assignModal.classList.remove('open'); location.reload(); }
});
