/**
 * ===========================================================
 *  GPX Manager – Detail UI Module (final)
 *  Ovládací prvky stránky detailu (časový režim, export)
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("⚙️ detail-ui.js načten");

document.addEventListener("DOMContentLoaded", () => {
    /* ====== EXPORT PROFILU DO PNG ====== */
    const exportBtn = document.getElementById("exportBtn");
    if (exportBtn) {
        exportBtn.addEventListener("click", () => {
            const canvas = document.getElementById("elev");
            if (!canvas) return;

            const url = canvas.toDataURL("image/png");
            const a = document.createElement("a");

            const safeTitle = (window.gpxDetailData?.trackTitle || "profil")
                .replace(/[^\p{L}\p{N}\-_ ]/gu, "_");

            a.href = url;
            a.download = `profil_${safeTitle}.png`;
            a.click();

            if (window.GPX_DEBUG) console.log(`📤 Exportován výškový profil jako profil_${safeTitle}.png`);
        });
    }

    /* ====== PŘEPÍNAČ REŽIMU ČASU (UTC / LOKÁLNÍ) ====== */
    const timeModeSelect = document.getElementById("timeMode");
    if (timeModeSelect) {
        window.timeMode = timeModeSelect.value;

        timeModeSelect.addEventListener("change", () => {
            window.timeMode = timeModeSelect.value;
            if (window.GPX_DEBUG) console.log(`🕒 Režim času změněn na: ${window.timeMode}`);

            // Pokud je už graf načten, jen aktualizuj tooltipy
            if (window.chart && typeof window.chart.update === "function") {
                window.chart.update();
            }
        });
    }

    /* ====== Pomocná funkce pro formátování času ====== */
    window.fmtDateTime = function (iso, mode = "local") {
        if (!iso) return "";
        const d = new Date(iso);
        const get = (m, k) => m === "utc" ? d["getUTC" + k]() : d["get" + k]();
        const dd = String(get(mode, "Date")).padStart(2, "0");
        const mm = String(get(mode, "Month") + 1).padStart(2, "0");
        const yyyy = get(mode, "FullYear");
        const hh = String(get(mode, "Hours")).padStart(2, "0");
        const mi = String(get(mode, "Minutes")).padStart(2, "0");
        return `${dd}.${mm}.${yyyy} ${hh}:${mi}`;
    };
});
