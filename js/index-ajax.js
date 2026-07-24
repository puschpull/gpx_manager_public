if (window.GPX_DEBUG) console.log('🧩 index-ajax.js načten – AJAX filtr připraven');

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#filter-form');
    if (!form) {
        console.warn('⚠️ Formulář #filter-form nebyl nalezen');
        return;
    }

    // Endpoint je vždy stránka v rootu, ne soubor z includes/ (ten nemá bootstrap ani auth)
    const endpoint = form.dataset.endpoint || 'index-legacy.php';
    const tableContainer = document.querySelector('#table-container');

    // FE-9: Chart init lives exclusively in index-chart.js; no duplicate here.


    // === FE-5: AbortController resolves race conditions during debounce ===
    let debounceTimer;
    let currentController = null;

    function debounceAjax(callback, delay = 400) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(callback, delay);
    }

    function performAjaxFilter() {
        // Cancel any in-flight request before starting a new one
        if (currentController) currentController.abort();
        currentController = new AbortController();

        const formData = new FormData(form);
        const params = new URLSearchParams(formData);
        params.append('ajax', '1'); // řekni PHP, že chceme JSON

        const url = `${endpoint}?${params.toString()}`;

        fetch(url, { signal: currentController.signal })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then(data => {
                // === Aktualizace tabulky ===
                if (tableContainer && data.table_html) {
                    tableContainer.innerHTML = data.table_html;
                }
                // FE-9: chart updates handled by index-chart.js via gpx:chart:shown event
            })
            .catch(err => {
                if (err.name === 'AbortError') return; // intentional abort, not an error
                console.error('AJAX filter failed', err);
            });
    }

    // === Naslouchání změnám filtrů ===
    ['change', 'input'].forEach(evt => {
        form.addEventListener(evt, (e) => {
            if (e.target.type === 'submit') return; // ignoruj tlačítka
            debounceAjax(performAjaxFilter);
        });
    });
});
