/**
 * ===========================================
 *   GPX Manager – Column visibility toggler
 *   Ukládá do cookie a mění viditelnost sloupců
 * ===========================================
 */

console.log("🧩 index-columns.js načten");

document.addEventListener("DOMContentLoaded", () => {

    // přečte cookie
    function loadHiddenCols() {
        const match = document.cookie.match(/hiddenCols=([^;]+)/);
        if (!match) return [];
        try {
            return JSON.parse(decodeURIComponent(match[1]));
        } catch {
            return [];
        }
    }

    // uloží cookie
    function saveHiddenCols(list) {
        document.cookie =
            "hiddenCols=" + encodeURIComponent(JSON.stringify(list)) +
            "; path=/; max-age=" + (60 * 60 * 24 * 365);
    }

    let hidden = loadHiddenCols();

    // použije viditelnost na TH a TD
    function applyVisibility() {
        hidden.forEach(key => {
            document.querySelectorAll(`.col-${key}`).forEach(el => {
                el.classList.add("col-hidden");
            });
        });

        // u těch, které nemají být skryté
        document.querySelectorAll("[class*='col-']").forEach(el => {
            const cls = Array.from(el.classList).find(c => c.startsWith("col-"));
            if (!cls) return;
            const colKey = cls.replace("col-", "");
            if (!hidden.includes(colKey)) {
                el.classList.remove("col-hidden");
            }
        });

        // checkboxy
        document.querySelectorAll(".col-toggle").forEach(ch => {
            const key = ch.dataset.col;
            ch.checked = !hidden.includes(key);
        });
    }

    // ————— INIT —————
    applyVisibility();

    // ————— PŘI ZMĚNĚ CHECKBOXU —————
    document.querySelectorAll(".col-toggle").forEach(ch => {
        ch.addEventListener("change", () => {
            const key = ch.dataset.col;

            if (!ch.checked) {
                if (!hidden.includes(key)) hidden.push(key);
            } else {
                hidden = hidden.filter(k => k !== key);
            }

            saveHiddenCols(hidden);
            applyVisibility();
        });
    });

});
