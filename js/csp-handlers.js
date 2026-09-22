/**
 * ===========================================================
 *  GPX Manager – obsluhy událostí bez inline atributů
 *
 *  CSP s nonce (bez 'unsafe-inline') NESPOUŠTÍ obsluhy zapsané přímo
 *  v HTML (onclick="…", onerror="…", onsubmit="…"). Místo nich mají prvky
 *  data-* atributy a tady je jeden posluchač na celý dokument (delegace),
 *  takže funguje i pro prvky, které vzniknou později z JavaScriptu.
 *
 *    <form data-confirm-text="Opravdu?">       potvrzení před odesláním
 *    <img data-img-fallback="hide|dim|dim-clear"> co udělat, když se obrázek nenačte
 *    <button data-toggle-target="id" data-toggle-class="cls">  přepnout třídu jinde
 *    <a data-lang="cs">                         uložit volbu jazyka do cookie
 *    <select data-href-template="…__VALUE__…">  po změně přejít na adresu
 *
 *  Načítá se v <head> BEZ defer: posluchač chyb obrázků musí existovat dřív,
 *  než se začnou načítat obrázky na stránce.
 *
 *  NOVÝ KÓD: žádné on*="…" v šablonách ani v innerHTML — použij data-*
 *  atribut odsud, nebo addEventListener ve vlastním skriptu.
 * ===========================================================
 */
(function () {
    "use strict";

    /* ---------- Nenačtený obrázek ---------- */
    function applyFallback(img) {
        var mode = img.getAttribute("data-img-fallback");
        if (mode === "hide") {
            img.style.display = "none";
        } else if (mode === "dim") {
            img.style.opacity = ".3";
        } else if (mode === "dim-clear") {
            img.removeAttribute("src");      // přestat zobrazovat rozbitou ikonu
            img.style.opacity = ".3";
        }
    }
    // Událost error neprobublává — zachytit ve fázi capture
    document.addEventListener("error", function (e) {
        var t = e.target;
        if (t && t.tagName === "IMG" && t.hasAttribute("data-img-fallback")) applyFallback(t);
    }, true);
    // Obrázky, které selhaly dřív, než se posluchač stihl zaregistrovat
    // (z mezipaměti prohlížeče se mohou „načíst" okamžitě)
    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll("img[data-img-fallback]").forEach(function (img) {
            if (img.complete && img.naturalWidth === 0 && img.getAttribute("src")) applyFallback(img);
        });
    });

    /* ---------- Potvrzení před odesláním formuláře ---------- */
    document.addEventListener("submit", function (e) {
        var f = e.target;
        if (!f || !f.hasAttribute || !f.hasAttribute("data-confirm-text")) return;
        if (!window.confirm(f.getAttribute("data-confirm-text"))) e.preventDefault();
    }, true);

    /* ---------- Kliknutí ---------- */
    document.addEventListener("click", function (e) {
        var el = e.target && e.target.closest ? e.target.closest("[data-toggle-target],[data-lang]") : null;
        if (!el) return;

        if (el.hasAttribute("data-toggle-target")) {
            var target = document.getElementById(el.getAttribute("data-toggle-target"));
            if (target) target.classList.toggle(el.getAttribute("data-toggle-class") || "open");
        }
        if (el.hasAttribute("data-lang")) {
            // Odkaz dál normálně pokračuje na ?app_lang=…; cookie jen pro jistotu,
            // server ji nastaví sám jen pokud ještě neodeslal hlavičky.
            document.cookie = "app_lang=" + encodeURIComponent(el.getAttribute("data-lang"))
                + "; path=/; max-age=31536000; SameSite=Lax";
        }
    });

    /* ---------- Výběr, který přesměruje ---------- */
    document.addEventListener("change", function (e) {
        var el = e.target;
        if (!el || !el.hasAttribute || !el.hasAttribute("data-href-template")) return;
        window.location.href = el.getAttribute("data-href-template")
            .replace("__VALUE__", encodeURIComponent(el.value));
    });
})();
