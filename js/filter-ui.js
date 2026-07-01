/**
 * ===========================================================
 *  GPX Track Cleaner – UI Orchestration Module
 *  Propojeni ovladacich prvku, presety, export
 * ===========================================================
 */

// filter-ui.js
if (window.GPX_DEBUG) { console.log("filter-ui.js nacten"); }

// Shared i18n lookup — reads from window.gpxI18n injected by layout_header.php.
// Falls back to the supplied default string if the key is absent.
function _t(key, fallback) {
    return (window.gpxI18n && window.gpxI18n[key]) || fallback || key;
}

(function () {
    "use strict";

    let originalPoints = null;
    let originalXmlText = null;
    let lastResult = null;
    let filterTimeout = null;

    /* ===== Inicializace ===== */

    document.addEventListener("DOMContentLoaded", () => {
        FilterMap.init();
        bindSliders();
        bindActions();
        loadPresets();

        const gpxUrl = window.gpxFilterData?.gpxUrl;
        if (gpxUrl) {
            loadGpxFromUrl(gpxUrl);
        } else {
            setupDropzone();
        }

        // Obnovit uložený mód mapy
        initMapMode();
    });

    /* ===== Nacteni GPX ===== */

    function loadGpxFromUrl(url) {
        fetch(url)
            .then(r => {
                if (!r.ok) throw new Error("HTTP " + r.status);
                return r.text();
            })
            .then(xmlText => {
                originalXmlText = xmlText;
                originalPoints = GpxFilter.parseGpx(xmlText);
                FilterMap.resetZoom();
                if (window.GPX_DEBUG) { console.log(`GPX nacten: ${originalPoints.filter(p => p !== null).length} bodu`); }
                runFilters();
            })
            .catch(err => {
                if (window.GPX_DEBUG) { console.error("Chyba pri nacitani GPX:", err); }
                showWarning("Nepodařilo se nacist GPX soubor: " + err.message);
            });
    }

    function setupDropzone() {
        const dz = document.getElementById("dropzone");
        const fi = document.getElementById("fileInput");
        if (!dz || !fi) return;

        dz.addEventListener("click", () => fi.click());
        dz.addEventListener("dragover", e => { e.preventDefault(); dz.classList.add("dragover"); });
        dz.addEventListener("dragleave", () => dz.classList.remove("dragover"));
        dz.addEventListener("drop", e => {
            e.preventDefault();
            dz.classList.remove("dragover");
            if (e.dataTransfer.files.length > 0) loadFile(e.dataTransfer.files[0]);
        });
        fi.addEventListener("change", () => { if (fi.files.length > 0) loadFile(fi.files[0]); });
    }

    function loadFile(file) {
        if (!file.name.toLowerCase().endsWith(".gpx")) {
            showWarning("Prosim vyberte soubor s priponou .gpx");
            return;
        }
        const reader = new FileReader();
        reader.onload = e => {
            originalXmlText = e.target.result;
            originalPoints = GpxFilter.parseGpx(originalXmlText);
            FilterMap.resetZoom();
            // Nastavime nazev souboru pro export
            window.gpxFilterData = window.gpxFilterData || {};
            window.gpxFilterData.fileName = file.name;
            if (window.GPX_DEBUG) { console.log(`GPX nacten z souboru: ${originalPoints.filter(p => p !== null).length} bodu`); }
            runFilters();
        };
        reader.readAsText(file);
    }

    /* ===== Parametry z UI ===== */

    function getParams() {
        return {
            speed_enabled: el("f_speed_enabled")?.checked ?? true,
            max_speed_kmh: num("f_max_speed", 12),
            speed_window: num("f_speed_window", 3),

            spike_enabled: el("f_spike_enabled")?.checked ?? true,
            spike_distance_m: num("f_spike_distance", 100),
            spike_time_s: num("f_spike_time", 10),

            stationary_enabled: el("f_stationary_enabled")?.checked ?? true,
            stationary_radius_m: num("f_stationary_radius", 15),
            stationary_min_time_s: num("f_stationary_time", 300),

            elevation_enabled: el("f_elevation_enabled")?.checked ?? true,
            elevation_spike_m: num("f_elevation_spike", 30),

            segment_enabled: el("f_segment_enabled")?.checked ?? true,
            min_segment_distance_m: num("f_min_segment_dist", 200),
            min_segment_time_s: num("f_min_segment_time", 120),

            simplify_enabled: el("f_simplify_enabled")?.checked ?? false,
            simplify_tolerance_m: num("f_simplify_tolerance", 5)
        };
    }

    function setParams(params) {
        const mapping = {
            speed_enabled: "f_speed_enabled",
            max_speed_kmh: "f_max_speed",
            speed_window: "f_speed_window",
            spike_enabled: "f_spike_enabled",
            spike_distance_m: "f_spike_distance",
            spike_time_s: "f_spike_time",
            stationary_enabled: "f_stationary_enabled",
            stationary_radius_m: "f_stationary_radius",
            stationary_min_time_s: "f_stationary_time",
            elevation_enabled: "f_elevation_enabled",
            elevation_spike_m: "f_elevation_spike",
            segment_enabled: "f_segment_enabled",
            min_segment_distance_m: "f_min_segment_dist",
            min_segment_time_s: "f_min_segment_time",
            simplify_enabled: "f_simplify_enabled",
            simplify_tolerance_m: "f_simplify_tolerance"
        };

        for (const [param, elId] of Object.entries(mapping)) {
            if (params[param] === undefined) continue;
            const elem = el(elId);
            if (!elem) continue;
            if (elem.type === "checkbox") {
                elem.checked = !!params[param];
            } else {
                elem.value = params[param];
                // Update output
                const out = document.getElementById(elId + "_val");
                if (out) out.textContent = params[param];
            }
        }
    }

    /* ===== Filtrovani ===== */

    function runFilters() {
        if (!originalPoints) return;

        const params = getParams();
        const t0 = performance.now();
        lastResult = GpxFilter.applyAll(originalPoints, params);
        const dt = performance.now() - t0;
        if (window.GPX_DEBUG) { console.log(`Filtry aplikovany za ${dt.toFixed(1)} ms`); }

        // Vizualizace
        FilterMap.update(originalPoints, lastResult.filtered, lastResult.removedByFilter);
        FilterElevation.update(originalPoints, lastResult.filtered);

        // Statistiky
        updateStats(lastResult.origStats, lastResult.filteredStats);
        updateRemovalBadges(lastResult.removedByFilter);

        // Varovani
        if (lastResult.warnings.length > 0) {
            showWarning(lastResult.warnings.join("<br>"));
        } else {
            hideWarning();
        }

        // Povolit export
        const btnExport = el("btnExport");
        const btnSave = el("btnSaveCatalog");
        const btnApplyTrack = el("btnApplyTrack");
        if (btnExport) btnExport.disabled = false;
        if (btnSave) btnSave.disabled = false;
        if (btnApplyTrack) btnApplyTrack.disabled = false;
    }

    function debouncedRun() {
        clearTimeout(filterTimeout);
        filterTimeout = setTimeout(runFilters, 150);
    }

    /* ===== Statistiky ===== */

    function updateStats(orig, filt) {
        setText("statPointsOrig", orig.pointCount);
        setText("statPointsNew", filt.pointCount);
        setDiff("statPointsDiff", orig.pointCount, filt.pointCount, "");

        setText("statDistOrig", orig.distanceKm.toFixed(2) + " km");
        setText("statDistNew", filt.distanceKm.toFixed(2) + " km");
        setDiff("statDistDiff", orig.distanceKm, filt.distanceKm, " km");

        setText("statAscentOrig", Math.round(orig.ascentM) + " m");
        setText("statAscentNew", Math.round(filt.ascentM) + " m");
        setDiff("statAscentDiff", orig.ascentM, filt.ascentM, " m");

        const origDur = formatDuration(orig.durationS);
        const filtDur = formatDuration(filt.durationS);
        setText("statDurationOrig", origDur);
        setText("statDurationNew", filtDur);
        if (orig.durationS > 0) {
            const pct = ((filt.durationS - orig.durationS) / orig.durationS * 100).toFixed(1);
            const diff = el("statDurationDiff");
            if (diff) {
                diff.textContent = `(${pct > 0 ? '+' : ''}${pct}%)`;
                diff.className = "stat-diff" + (pct < 0 ? " negative" : pct > 0 ? " positive" : "");
            }
        }
    }

    function setDiff(id, origVal, newVal, unit) {
        const diff = el(id);
        if (!diff) return;
        if (origVal === 0) { diff.textContent = ""; return; }
        const pct = ((newVal - origVal) / origVal * 100).toFixed(1);
        diff.textContent = `(${pct > 0 ? '+' : ''}${pct}%)`;
        diff.className = "stat-diff" + (pct < 0 ? " negative" : pct > 0 ? " positive" : "");
    }

    function updateRemovalBadges(removedByFilter) {
        const container = el("removalBadges");
        if (!container) return;

        const labels = {
            speed:      _t('speed',         'Rychlost'),
            spike:      _t('gps_jumps',     'GPS skoky'),
            stationary: _t('stationary',    'Stání'),
            elevation:  _t('elevation',     'Výška'),
            segment:    _t('short_segments','Kratké seg.'),
            simplify:   _t('simplify',      'Zjednodušení'),
        };

        container.innerHTML = "";
        for (const [key, arr] of Object.entries(removedByFilter)) {
            const badge = document.createElement("span");
            badge.className = `removal-badge ${key}`;
            badge.innerHTML = `<span class="dot"></span>${labels[key]}: <strong>${arr.length}</strong>`;
            container.appendChild(badge);
        }
    }

    /* ===== Vazba slideru ===== */

    function bindSliders() {
        const sliders = [
            "f_max_speed", "f_speed_window",
            "f_spike_distance", "f_spike_time",
            "f_stationary_radius", "f_stationary_time",
            "f_elevation_spike",
            "f_min_segment_dist", "f_min_segment_time",
            "f_simplify_tolerance"
        ];

        for (const id of sliders) {
            const slider = el(id);
            const output = document.getElementById(id + "_val");
            if (!slider) continue;
            slider.addEventListener("input", () => {
                if (output) output.textContent = slider.value;
                debouncedRun();
            });
        }

        // Checkboxy enable/disable
        const checks = [
            "f_speed_enabled", "f_spike_enabled", "f_stationary_enabled",
            "f_elevation_enabled", "f_segment_enabled", "f_simplify_enabled"
        ];
        for (const id of checks) {
            const cb = el(id);
            if (cb) cb.addEventListener("change", debouncedRun);
        }
    }

    /* ===== Akce ===== */

    function bindActions() {
        // Aplikovat
        const btnApply = el("btnApply");
        if (btnApply) btnApply.addEventListener("click", runFilters);

        // Resetovat
        const btnReset = el("btnReset");
        if (btnReset) btnReset.addEventListener("click", () => {
            setParams(GpxFilter.PRESETS.hiking.settings);
            runFilters();
        });

        // Export GPX
        const btnExport = el("btnExport");
        if (btnExport) btnExport.addEventListener("click", exportGpx);

        // Ulozit do katalogu
        const btnSave = el("btnSaveCatalog");
        if (btnSave) btnSave.addEventListener("click", saveToCatalog);

        // Aplikovat na tuto trasu (in-place)
        const btnApplyTrack = el("btnApplyTrack");
        if (btnApplyTrack) btnApplyTrack.addEventListener("click", applyToTrack);

        // Presety
        const btnLoad = el("presetLoad");
        const btnSavePreset = el("presetSave");
        const btnDelete = el("presetDelete");
        if (btnLoad) btnLoad.addEventListener("click", loadSelectedPreset);
        if (btnSavePreset) btnSavePreset.addEventListener("click", savePreset);
        if (btnDelete) btnDelete.addEventListener("click", deletePreset);
    }

    /* ===== Export ===== */

    function exportGpx() {
        if (!originalXmlText || !lastResult) return;

        const gpxContent = GpxFilter.exportGpx(originalXmlText, lastResult.filtered);
        const blob = new Blob([gpxContent], { type: "application/gpx+xml" });
        const url = URL.createObjectURL(blob);

        const baseName = (window.gpxFilterData?.fileName || "track.gpx").replace(/\.gpx$/i, "");
        const a = document.createElement("a");
        a.href = url;
        a.download = baseName + "_cleaned.gpx";
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function saveToCatalog() {
        if (!originalXmlText || !lastResult) return;

        const btn = el("btnSaveCatalog");
        const origLabel = btn ? btn.textContent : "";
        if (btn) { btn.disabled = true; btn.textContent = "⏳ Ukládám…"; }

        const gpxContent = GpxFilter.exportGpx(originalXmlText, lastResult.filtered);
        const fileName = window.gpxFilterData?.fileName || "track.gpx";
        const csrfToken = window.gpxFilterData?.csrfToken || "";

        const form = new FormData();
        form.append("_csrf_token", csrfToken);
        form.append("gpx_content", gpxContent);
        form.append("original_name", fileName);

        fetch("filter.php?ajax=save_cleaned", {
            method: "POST",
            body: form
        })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (btn) { btn.textContent = "✅ Uloženo! Přesměrovávám…"; }
                    setTimeout(() => { window.location.href = "detail.php?id=" + data.id; }, 800);
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = origLabel; }
                    showWarning("Chyba při ukládání: " + (data.error || "neznámá chyba"));
                }
            })
            .catch(err => {
                if (btn) { btn.disabled = false; btn.textContent = origLabel; }
                showWarning("Chyba při ukládání: " + err.message);
            });
    }

    // Aplikace vyčištěné verze PŘÍMO na aktuální trasu (in-place, stejné id).
    function applyToTrack() {
        if (!originalXmlText || !lastResult) return;
        const trackId = window.gpxFilterData?.trackId;
        if (!trackId) return;
        if (!confirm("Přepsat přímo tuto trasu #" + trackId + " vyčištěnou verzí?\n\n"
            + "Fotky i kategorie zůstanou, statistiky se přepočítají. "
            + "Originál GPX se zazálohuje (.bak).")) return;

        const btn = el("btnApplyTrack");
        const origLabel = btn ? btn.textContent : "";
        if (btn) { btn.disabled = true; btn.textContent = "⏳ Aplikuji…"; }

        const gpxContent = GpxFilter.exportGpx(originalXmlText, lastResult.filtered);
        const csrfToken = window.gpxFilterData?.csrfToken || "";

        const form = new FormData();
        form.append("_csrf_token", csrfToken);
        form.append("track_id", trackId);
        form.append("gpx_content", gpxContent);

        fetch("filter.php?ajax=apply_cleaned", { method: "POST", body: form })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    if (btn) { btn.textContent = "✅ Hotovo! Přesměrovávám…"; }
                    setTimeout(() => { window.location.href = "detail.php?id=" + data.id; }, 800);
                } else {
                    if (btn) { btn.disabled = false; btn.textContent = origLabel; }
                    showWarning("Chyba při aplikaci: " + (data.error || "neznámá chyba"));
                }
            })
            .catch(err => {
                if (btn) { btn.disabled = false; btn.textContent = origLabel; }
                showWarning("Chyba při aplikaci: " + err.message);
            });
    }

    /* ===== Presety ===== */

    let allPresets = []; // { id, name, settings, builtin }

    function loadPresets() {
        allPresets = [];

        // Vestaveene presety
        for (const [key, preset] of Object.entries(GpxFilter.PRESETS)) {
            allPresets.push({
                id: "builtin_" + key,
                name: preset.name,
                settings: preset.settings,
                builtin: true
            });
        }

        // Z localStorage
        try {
            const saved = JSON.parse(localStorage.getItem("gpx_filter_presets") || "[]");
            for (const p of saved) {
                allPresets.push({ id: "local_" + p.name, name: p.name, settings: p.settings, builtin: false });
            }
        } catch (e) { /* ignore */ }

        // Z DB (async)
        fetch("filter.php?ajax=presets")
            .then(r => r.json())
            .then(dbPresets => {
                for (const p of dbPresets) {
                    const settings = typeof p.settings === "string" ? JSON.parse(p.settings) : p.settings;
                    // Nekopiruj duplicity s localStorage
                    const exists = allPresets.some(x => !x.builtin && x.name === p.name);
                    if (!exists) {
                        allPresets.push({ id: "db_" + p.id, dbId: p.id, name: p.name, settings, builtin: false });
                    }
                }
                populatePresetSelect();
            })
            .catch(() => populatePresetSelect());
    }

    function populatePresetSelect() {
        const select = el("presetSelect");
        if (!select) return;
        select.innerHTML = '<option value="">-- Vyberte preset --</option>';
        for (const p of allPresets) {
            const opt = document.createElement("option");
            opt.value = p.id;
            opt.textContent = p.name + (p.builtin ? " *" : "");
            select.appendChild(opt);
        }
    }

    function loadSelectedPreset() {
        const select = el("presetSelect");
        if (!select || !select.value) return;
        const preset = allPresets.find(p => p.id === select.value);
        if (!preset) return;
        setParams(preset.settings);
        runFilters();
    }

    /**
     * A11Y-026: Open accessible <dialog> for preset name instead of browser prompt().
     * Resolves with trimmed name string or null if cancelled.
     * @returns {Promise<string|null>}
     */
    function _promptPresetName() {
        return new Promise((resolve) => {
            const dialog    = document.getElementById("preset-name-dialog");
            const input     = document.getElementById("preset-name-input");
            const errorEl   = document.getElementById("preset-name-error");
            const cancelBtn = document.getElementById("preset-dialog-cancel");

            if (!dialog || !input) {
                // Fallback if dialog element is missing
                const name = window.prompt("Název presetu:");
                resolve(name && name.trim() ? name.trim() : null);
                return;
            }

            // Reset state
            input.value = "";
            if (errorEl) { errorEl.hidden = true; errorEl.textContent = ""; }

            dialog.showModal();
            input.focus();

            let _cancelled = false;

            function cleanup() {
                dialog.removeEventListener("close", onClose);
                cancelBtn.removeEventListener("click", onCancel);
            }

            function onClose() {
                cleanup();
                if (_cancelled) { resolve(null); return; }
                const trimmed = input.value.trim();
                resolve(trimmed || null);
            }

            function onCancel() {
                _cancelled = true;
                // Clear value so onClose resolves null
                input.value = "";
                dialog.close();
            }

            dialog.addEventListener("close", onClose);
            cancelBtn.addEventListener("click", onCancel);

            // Validate on submit (form method=dialog handles close, but validate first)
            const form = document.getElementById("preset-name-form");
            if (form) {
                form.onsubmit = (e) => {
                    const trimmed = input.value.trim();
                    if (!trimmed) {
                        e.preventDefault();
                        if (errorEl) {
                            errorEl.textContent = "Název presetu nesmí být prázdný.";
                            errorEl.hidden = false;
                        }
                        input.focus();
                    }
                };
            }
        });
    }

    function savePreset() {
        _promptPresetName().then((name) => {
            if (!name) return;

            const settings   = getParams();
            const csrfToken  = window.gpxFilterData?.csrfToken || "";

            // localStorage
            try {
                const saved = JSON.parse(localStorage.getItem("gpx_filter_presets") || "[]");
                const idx = saved.findIndex(p => p.name === name);
                if (idx >= 0) saved[idx].settings = settings;
                else saved.push({ name, settings });
                localStorage.setItem("gpx_filter_presets", JSON.stringify(saved));
            } catch (e) { /* ignore */ }

            // DB
            const form = new FormData();
            form.append("_csrf_token", csrfToken);
            form.append("name", name);
            form.append("settings", JSON.stringify(settings));

            fetch("filter.php?ajax=preset_save", { method: "POST", body: form })
                .then(r => r.json())
                .then(() => loadPresets())
                .catch(err => { if (window.GPX_DEBUG) { console.error("Chyba pri ukladani presetu:", err); } });
        });
    }

    function deletePreset() {
        const select = el("presetSelect");
        if (!select || !select.value) return;
        const preset = allPresets.find(p => p.id === select.value);
        if (!preset || preset.builtin) {
            showWarning("Vestaveene presety nelze smazat.");
            return;
        }
        if (!confirm(`Smazat preset "${preset.name}"?`)) return;

        // localStorage
        try {
            const saved = JSON.parse(localStorage.getItem("gpx_filter_presets") || "[]");
            const filtered = saved.filter(p => p.name !== preset.name);
            localStorage.setItem("gpx_filter_presets", JSON.stringify(filtered));
        } catch (e) { /* ignore */ }

        // DB
        if (preset.dbId) {
            const csrfToken = window.gpxFilterData?.csrfToken || "";
            const form = new FormData();
            form.append("_csrf_token", csrfToken);
            form.append("id", preset.dbId);
            fetch("filter.php?ajax=preset_delete", { method: "POST", body: form })
                .then(() => loadPresets())
                .catch(err => { if (window.GPX_DEBUG) { console.error("Chyba pri mazani presetu:", err); } });
        } else {
            loadPresets();
        }
    }

    /* ===== Pomocne funkce ===== */

    function el(id) { return document.getElementById(id); }
    function num(id, def) { return parseFloat(el(id)?.value) || def; }
    function setText(id, text) { const e = el(id); if (e) e.textContent = text; }

    // Delegát na sdílené lib (js/lib/format-utils.js)
    function formatDuration(seconds) { return window.GpxFmt.formatDuration(seconds); }

    function showWarning(msg) {
        const w = el("filterWarnings");
        if (w) { w.innerHTML = msg; w.style.display = "block"; }
    }

    function hideWarning() {
        const w = el("filterWarnings");
        if (w) w.style.display = "none";
    }

    /* ===== Přepínání módu mapy ===== */

    function applyMapModeUI(expanded) {
        const btn = el("btnMapMode");
        if (expanded) {
            document.body.classList.add("filter-mode-expanded");
            if (btn) btn.textContent = "↙ Normální pohled";
        } else {
            document.body.classList.remove("filter-mode-expanded");
            if (btn) btn.textContent = "↗ Velká mapa + panel";
        }
        // Leaflet musí přepočítat rozměry kontejneru po CSS přechodu
        setTimeout(() => {
            const lmap = FilterMap.getMap();
            if (lmap) lmap.invalidateSize();
        }, 50);
    }

    function initMapMode() {
        const expanded = localStorage.getItem("filter_map_expanded") === "1";
        applyMapModeUI(expanded);

        const btn = el("btnMapMode");
        if (btn) btn.addEventListener("click", () => {
            const next = !document.body.classList.contains("filter-mode-expanded");
            localStorage.setItem("filter_map_expanded", next ? "1" : "0");
            applyMapModeUI(next);
        });
    }

})();
