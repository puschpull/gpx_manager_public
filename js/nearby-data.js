/**
 * ===========================================================
 *  GPX Manager – Nearby Tracks Data Module
 *  Barevná paleta a vykreslování tabulky výsledků
 * ===========================================================
 */

const NEARBY_COLORS = [
    '#e6194b', // červená
    '#3cb44b', // zelená
    '#4363d8', // modrá
    '#f58231', // oranžová
    '#911eb4', // fialová
    '#42d4f4', // azurová
    '#f032e6', // magenta
    '#bfef45', // limetková
    '#469990', // petrolejová
    '#9a6324', // hnědá
    '#800000', // vínová
    '#808000', // olivová
    '#000075', // námořnická modrá
    '#ffe119', // žlutá
    '#e377c2', // růžová
    '#2f4f4f', // tmavě šedozelená
    '#00a86b', // nefritová
    '#ff7f50', // korálová
    '#7b68ee', // břidlicově modrá
    '#a9a9a9', // šedá
];

/* Delegáty na sdílené lib (js/lib/format-utils.js) */
function formatDuration(seconds) { return window.GpxFmt.formatDuration(seconds); }
function formatDate(dateStr)     { return window.GpxFmt.formatDate(dateStr); }

/**
 * Vykreslí tabulku nalezených nejbližších tras
 */
function renderNearbyTable(tracks) {
    const tbody = document.getElementById('nearby-tbody');
    const wrap  = document.getElementById('nearby-results');
    if (!tbody || !wrap) return;

    tbody.innerHTML = '';
    const dlLabel = window.gpxNearbyData?.i18n?.downloadGpx || 'Stáhnout GPX';

    if (!tracks || tracks.length === 0) {
        wrap.style.display = 'none';
        return;
    }

    tracks.forEach((t, i) => {
        const tr = document.createElement('tr');
        tr.title = 'Klikněte pro zobrazení detailu trasy';
        tr.addEventListener('click', () => {
            window.location.href = `detail.php?id=${t.id}`;
        });

        tr.innerHTML = `
            <td>${i + 1}</td>
            <td><span class="nearby-color-swatch" style="background:${NEARBY_COLORS[i]}"></span></td>
            <td><a href="detail.php?id=${t.id}">${escapeHtml(t.track_name || t.filename)}</a></td>
            <td>${t.distance_from_point}</td>
            <td>${t.distance_km}</td>
            <td>${t.ascent}</td>
            <td>${t.descent}</td>
            <td>${formatDate(t.date_start)}</td>
            <td>${formatDuration(t.duration)}</td>
            <td><a class="nearby-gpx-link" href="${escapeHtml(t.gpx_url)}" download title="${escapeHtml(dlLabel)}">⬇ GPX</a></td>
        `;
        // Stažení nemá zároveň otevřít detail trasy (klik na řádek)
        tr.querySelector('.nearby-gpx-link').addEventListener('click', e => e.stopPropagation());
        tbody.appendChild(tr);
    });

    // Všechny nalezené trasy jako ZIP (pořadí = od nejbližší)
    const zipLink = document.getElementById('nearby-zip');
    if (zipLink) {
        zipLink.href = 'nearby.php?export=zip&ids=' + tracks.map(t => t.id).join(',');
    }

    wrap.style.display = 'block';
}

/* Delegát na sdílené lib (js/lib/format-utils.js) */
function escapeHtml(str) { return window.GpxFmt.escHtml(str); }
