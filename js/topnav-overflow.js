/**
 * ===========================================================
 *  GPX Manager – Top menu overflow („Více ▾")
 *  Ukáže tolik položek horního menu, kolik se vejde do šířky
 *  hlavičky; zbytek schová do rozbalovacího „Více".
 *  Šířka menu se mění podle jazyka, počtu položek (admin vidí
 *  víc než návštěvník) a okna, proto se to počítá za běhu.
 *  Otevírání „Více" řeší Alpine komponenta dropdown, tady jen
 *  atribut hidden u položek v liště a v rozbalovacím menu.
 * ===========================================================
 */
(function () {
    "use strict";

    var nav = document.querySelector(".gpx-topnav");
    if (!nav) return;
    var bar   = nav.parentElement;
    var brand = bar.querySelector(".gpx-brand");
    var right = bar.querySelector(".gpx-topnav-right");
    var more  = nav.querySelector(".gpx-topnav-more");
    if (!brand || !right || !more) return;

    var items     = Array.prototype.slice.call(nav.querySelectorAll("[data-nav-idx]"));
    var menuItems = Array.prototype.slice.call(more.querySelectorAll("[data-nav-more]"));
    var moreBtn   = more.querySelector("button");
    var ACTIVE    = ["bg-forest-100", "dark:bg-forest-800", "font-semibold"];
    var activeIdx = -1;
    items.forEach(function (a, i) { if (a.hasAttribute("aria-current")) activeIdx = i; });

    function px(v) { return parseFloat(v) || 0; }

    function layout() {
        // Pod 48rem je menu skryté (hamburger) — nic nepočítat.
        if (getComputedStyle(nav).display === "none") return;

        // Změřit přirozené šířky: vše ukázat, i „Více".
        items.forEach(function (a) { a.hidden = false; });
        more.hidden = false;

        var barCs  = getComputedStyle(bar);
        var gap    = px(barCs.columnGap);
        var navGap = px(getComputedStyle(nav).columnGap);
        var avail  = bar.clientWidth - px(barCs.paddingLeft) - px(barCs.paddingRight)
                   - brand.getBoundingClientRect().width - right.getBoundingClientRect().width - 2 * gap;
        var widths = items.map(function (a) { return a.getBoundingClientRect().width; });
        var total  = widths.reduce(function (s, w) { return s + w; }, 0) + navGap * (items.length - 1);

        var hide = [];
        if (total > avail) {
            total += navGap + more.getBoundingClientRect().width;
            // Odebírat od konce; aktuální stránku nechat v liště, dokud to jde.
            for (var i = items.length - 1; i >= 0 && total > avail; i--) {
                if (i === activeIdx) continue;
                hide.push(i);
                total -= widths[i] + navGap;
            }
            if (total > avail && activeIdx >= 0) hide.push(activeIdx);
        }

        items.forEach(function (a, i) { a.hidden = hide.indexOf(i) !== -1; });
        menuItems.forEach(function (a, i) { a.hidden = hide.indexOf(i) === -1; });
        more.hidden = hide.length === 0;

        // Aktuální stránka schovaná ve „Více" → zvýraznit „Více".
        var activeHidden = activeIdx >= 0 && hide.indexOf(activeIdx) !== -1;
        ACTIVE.forEach(function (c) { moreBtn.classList.toggle(c, activeHidden); });
    }

    layout();
    if (window.ResizeObserver) {
        new ResizeObserver(layout).observe(bar);
    }
    window.addEventListener("resize", layout);
    // Šířky se změní po načtení písma a po startu Alpine (ovládání vpravo).
    window.addEventListener("load", layout);
    document.addEventListener("alpine:initialized", layout);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(layout);
})();
