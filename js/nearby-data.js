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
];

/**
 * Formátuje sekundy na HH:MM:SS
 */
function formatDuration(seconds) {
    if (!seconds || seconds <= 0) return '–';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/**
 * Formátuje datum (YYYY-MM-DD HH:MM → DD.MM.YYYY)
 */
function formatDate(dateStr) {
    if (!dateStr) return '–';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('cs-CZ');
}

/**
 * Vykreslí tabulku nalezených nejbližších tras
 */
function renderNearbyTable(tracks) {
    const tbody = document.getElementById('nearby-tbody');
    const wrap  = document.getElementById('nearby-results');
    if (!tbody || !wrap) return;

    tbody.innerHTML = '';

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
        `;
        tbody.appendChild(tr);
    });

    wrap.style.display = 'block';
}

/**
 * Escapuje HTML znaky
 */
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
