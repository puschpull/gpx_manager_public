/**
 * Cestopis — správa verzí (story_admin.php).
 * Odhad ceny při změně volby, generování s potvrzením (a se stropem),
 * průběh na pozadí, zveřejnění / smazání verzí, měsíční strop.
 * Konfigurace a texty: window.GPX_STORY (story_admin_view.php).
 */
(function () {
    "use strict";

    const cfg = window.GPX_STORY || {};
    const T = cfg.i18n || {};
    const form = document.getElementById("story-form");
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const usd = (v) => (v === null || v === undefined) ? "?" : "$" + Number(v).toFixed(2);
    const fill = (tpl, map) => String(tpl).replace(/\{(\w+)\}/g, (m, k) => (k in map ? map[k] : m));

    function post(url, data) {
        const fd = new FormData();
        Object.keys(data).forEach((k) => fd.append(k, data[k]));
        fd.append("_csrf_token", cfg.csrfToken || "");
        return fetch(url, { method: "POST", body: fd, credentials: "same-origin" })
            .then((r) => r.json().catch(() => ({ ok: false, error: "HTTP " + r.status })))
            .catch((err) => ({ ok: false, error: String(err) }));
    }

    function variant() {
        return {
            track_id: cfg.trackId,
            style: form.elements.style.value,
            model: form.elements.model.value,
            with_map: form.elements.with_map.checked ? "1" : "",
            photo_mode: form.elements.photo_mode.value,
        };
    }

    // ---- útrata / strop
    function showSpending(spent, cap) {
        if (spent !== undefined) $("story-spent").textContent = usd(spent);
        if (cap !== undefined) $("story-cap-show").textContent = usd(cap);
        const s = parseFloat(String($("story-spent").textContent).replace("$", "")) || 0;
        const c = parseFloat(String($("story-cap-show").textContent).replace("$", "")) || 0;
        $("story-meter-bar").style.setProperty("--story-fill", Math.min(100, c > 0 ? (s / c) * 100 : 100) + "%");
    }

    // ---- odhad ceny
    let lastEstimate = null;
    let estTimer = null;
    function refreshEstimate() {
        $("story-photo-warning").hidden = form.elements.photo_mode.value === "none";
        clearTimeout(estTimer);
        estTimer = setTimeout(() => {
            $("story-est").textContent = "…";
            post("api/story/estimate.php", variant()).then((res) => {
                if (!res.ok) {
                    $("story-est").textContent = "?";
                    $("story-est-basis").textContent = res.error || "";
                    return;
                }
                lastEstimate = res.usd;
                $("story-est").textContent = "~" + usd(res.usd);
                const parts = [];
                if (res.photos > 0) parts.push(res.photos + " " + T.photosShort);
                parts.push(res.from_history > 0 ? T.estHistory : T.estFormula);
                $("story-est-basis").textContent = "(" + parts.join(", ") + ")";
                showSpending(res.month_spent, res.cap);
            });
        }, 250);
    }
    form.addEventListener("change", refreshEstimate);
    refreshEstimate();

    // ---- průběh generování
    function startPolling(id) {
        const btn = $("story-generate");
        btn.disabled = true;
        $("story-progress").hidden = false;
        const t0 = Date.now();
        const tick = () => {
            const s = Math.round((Date.now() - t0) / 1000);
            $("story-progress-text").textContent =
                T.running + " " + Math.floor(s / 60) + ":" + String(s % 60).padStart(2, "0") + " — " + T.runningNote;
        };
        tick();
        const timer = setInterval(tick, 1000);

        const poll = () => {
            post("api/story/status.php", { id: id }).then((res) => {
                if (res.ok && res.status === "running") {
                    setTimeout(poll, 3000);
                    return;
                }
                clearInterval(timer);
                if (res.ok && res.status === "error") {
                    alert(T.failed + ": " + (res.error || ""));
                } else if (!res.ok) {
                    alert(T.error + ": " + (res.error || ""));
                }
                location.reload();
            });
        };
        setTimeout(poll, 3000);
    }

    // ---- generování
    function generate(extra) {
        const data = Object.assign(variant(), extra || {});
        $("story-generate").disabled = true;
        post("api/story/generate.php", data).then((res) => {
            if (res.ok && res.id) {
                startPolling(res.id);
                return;
            }
            $("story-generate").disabled = !cfg.canGenerate;
            if (res.needs_confirm) {
                const msg = fill(T.confirmOverCap, { spent: usd(res.spent), cap: usd(res.cap), usd: usd(res.estimate) });
                if (confirm(msg)) generate({ confirm_over_cap: "1" });
                return;
            }
            alert(T.error + ": " + (res.error || ""));
        });
    }

    form.addEventListener("submit", (e) => {
        e.preventDefault();
        if (!cfg.canGenerate) return;
        if (confirm(fill(T.confirmGenerate, { usd: "~" + usd(lastEstimate) }))) generate();
    });

    if (cfg.runningId) startPolling(cfg.runningId);

    // ---- zveřejnit / stáhnout / smazat
    document.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-story-action]");
        if (!btn) return;
        const action = btn.getAttribute("data-story-action");
        const id = btn.getAttribute("data-id");
        let req;
        if (action === "delete") {
            if (!confirm(T.confirmDelete)) return;
            req = post("api/story/delete.php", { id: id });
        } else {
            req = post("api/story/publish.php", { id: id, publish: action === "publish" ? "1" : "" });
        }
        btn.disabled = true;
        req.then((res) => {
            if (!res.ok) {
                alert(T.error + ": " + (res.error || ""));
                btn.disabled = false;
                return;
            }
            location.reload();
        });
    });

    // ---- vedle sebe
    const side = $("story-side");
    if (side) {
        side.addEventListener("click", () => {
            const on = $("story-list").classList.toggle("story-side");
            side.setAttribute("aria-pressed", on ? "true" : "false");
            if (on) document.querySelectorAll(".story-text-wrap").forEach((d) => { d.open = true; });
        });
    }

    // ---- strop
    const capForm = $("story-cap-form");
    capForm.addEventListener("submit", (e) => {
        e.preventDefault();
        post("api/story/cap.php", { cap: capForm.elements.cap.value }).then((res) => {
            if (!res.ok) {
                alert(T.error + ": " + (res.error || ""));
                return;
            }
            showSpending(undefined, res.cap);
            refreshEstimate();
        });
    });
})();
