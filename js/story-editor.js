/**
 * Cestopis — vlastní text a ruční úprava verzí (story_admin.php).
 * „Upravit“ u verze načte její text a prameny do editoru; uložení vždy
 * vytvoří NOVOU verzi (koncept), původní zůstává. Klik na náhled fotky
 * vloží značku „[foto N/k]“ na samostatný řádek u kurzoru.
 * Konfigurace: window.GPX_STORY (trackId, csrfToken), window.GPX_STORY_ED.
 */
(function () {
    "use strict";

    const cfg = window.GPX_STORY || {};
    const ed = window.GPX_STORY_ED || {};
    const T = ed.i18n || {};
    const form = document.getElementById("story-editor");
    if (!form) return;

    const text = form.elements.text;
    const sources = form.elements.sources;
    const status = document.getElementById("story-ed-status");
    const title = document.getElementById("story-ed-title");
    const cancel = document.getElementById("story-ed-cancel");
    const fill = (tpl, map) => String(tpl).replace(/\{(\w+)\}/g, (m, k) => (k in map ? map[k] : m));

    function setMode(baseId) {
        form.elements.base_id.value = baseId ? String(baseId) : "";
        title.textContent = baseId ? fill(T.titleEdit, { id: baseId }) : T.titleNew;
        cancel.hidden = !baseId;
    }

    // ---- Upravit verzi → editor
    document.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-story-edit]");
        if (!btn) return;
        const id = btn.getAttribute("data-story-edit");
        const v = (ed.versions || {})[id];
        if (!v) return;
        if (text.value.trim() !== "" && !window.confirm(T.discard)) return;
        text.value = v.text;
        sources.value = v.sources;
        setMode(id);
        status.textContent = "";
        form.scrollIntoView({ behavior: "smooth", block: "start" });
        text.focus();
    });

    cancel.addEventListener("click", () => {
        if (text.value.trim() !== "" && !window.confirm(T.discard)) return;
        text.value = "";
        sources.value = "";
        setMode(null);
    });

    // ---- Klik na fotku → značka u kurzoru (vždy jako samostatný odstavec)
    form.addEventListener("click", (e) => {
        const btn = e.target.closest("[data-story-mark]");
        if (!btn) return;
        const mark = btn.getAttribute("data-story-mark");
        const pos = text.selectionStart ?? text.value.length;
        const before = text.value.slice(0, pos).replace(/\s+$/, "");
        const after = text.value.slice(pos).replace(/^\s+/, "");
        const ins = (before ? "\n\n" : "") + mark + (after ? "\n\n" : "");
        text.value = before + ins + after;
        const caret = before.length + ins.length;
        text.focus();
        text.setSelectionRange(caret, caret);
    });

    // ---- Uložit jako novou verzi
    form.addEventListener("submit", (e) => {
        e.preventDefault();
        if (text.value.trim() === "") return;
        const fd = new FormData();
        fd.append("track_id", cfg.trackId);
        fd.append("text", text.value);
        fd.append("sources", sources.value);
        fd.append("base_id", form.elements.base_id.value);
        fd.append("_csrf_token", cfg.csrfToken || "");
        status.textContent = T.saving;
        form.querySelector("[type=submit]").disabled = true;
        fetch("api/story/save.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then((r) => r.json().catch(() => ({ ok: false, error: "HTTP " + r.status })))
            .catch((err) => ({ ok: false, error: String(err) }))
            .then((res) => {
                form.querySelector("[type=submit]").disabled = false;
                if (!res.ok) {
                    status.textContent = T.error + ": " + (res.error || "");
                    return;
                }
                status.textContent = fill(T.saved, { id: res.id });
                window.location.reload();
            });
    });
})();
