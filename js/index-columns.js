/**
 * GPX Manager – Column visibility toggler (index-legacy)
 *
 * Cookie: visible_cols = CSV of HIDDEN column keys, e.g. "id,filename,bounds"
 * CSS:    body.hide-col-{key} → .col-{key} { display:none }  (defined in index.css)
 *
 * The inline script in index_legacy_view.php handles the initial paint (reads
 * cookie and adds body classes before first render). This module handles all
 * subsequent interactions: checkboxes, show-all, hide-all, and AJAX reloads.
 */

(function () {
    const COOKIE = 'visible_cols';
    const MAX_AGE = 60 * 60 * 24 * 365; // 1 year
    // Scope cookie to this project's directory so different projects on the same
    // domain don't share column preferences (e.g. /redesign_01/ vs /public_new_robert/)
    const COOKIE_PATH = window.location.pathname.replace(/\/[^/]*$/, '/') || '/';

    function loadHidden() {
        const match = document.cookie.match(/(?:^|;\s*)visible_cols=([^;]*)/);
        if (!match) return [];
        return decodeURIComponent(match[1]).split(',').filter(Boolean);
    }

    function saveHidden(list) {
        document.cookie = COOKIE + '=' + encodeURIComponent(list.join(','))
            + '; path=' + COOKIE_PATH + '; max-age=' + MAX_AGE;
    }

    function applyHidden(hidden) {
        // Remove all existing hide-col-* body classes first
        Array.from(document.body.classList)
            .filter(c => c.startsWith('hide-col-'))
            .forEach(c => document.body.classList.remove(c));

        // Add class for each hidden column
        hidden.forEach(key => document.body.classList.add('hide-col-' + key));

        // Sync checkboxes
        document.querySelectorAll('.col-toggle').forEach(cb => {
            cb.checked = !hidden.includes(cb.dataset.col);
        });
    }

    function init() {
        let hidden = loadHidden();
        applyHidden(hidden);

        // Checkbox change
        document.addEventListener('change', function (e) {
            if (!e.target.classList.contains('col-toggle')) return;
            const key = e.target.dataset.col;
            if (!e.target.checked) {
                if (!hidden.includes(key)) hidden.push(key);
            } else {
                hidden = hidden.filter(k => k !== key);
            }
            saveHidden(hidden);
            applyHidden(hidden);
        });

        // Show all
        const btnShow = document.getElementById('col-show-all');
        if (btnShow) {
            btnShow.addEventListener('click', function () {
                hidden = [];
                saveHidden(hidden);
                applyHidden(hidden);
            });
        }

        // Hide all
        const btnHide = document.getElementById('col-hide-all');
        if (btnHide) {
            btnHide.addEventListener('click', function () {
                hidden = Array.from(document.querySelectorAll('.col-toggle'))
                    .map(cb => cb.dataset.col)
                    .filter(Boolean);
                saveHidden(hidden);
                applyHidden(hidden);
            });
        }

        // Re-apply after AJAX table reload
        document.addEventListener('gpx:table-updated', function () {
            applyHidden(hidden);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
