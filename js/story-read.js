/**
 * Cestopis — čtení (story.php): fotky se otevírají ve stejné kartě
 * v prohlížeči js/lightbox.js (← → Esc, posun prstem, počítadlo).
 * Listuje se přes všechny fotky výletu v pořadí na stránce.
 * Bez JavaScriptu zůstává obyčejný odkaz na velkou fotku.
 */
(function () {
    "use strict";

    const links = Array.from(document.querySelectorAll("a[data-story-photo]"));
    if (!links.length) return;

    const photos = links.map((a) => ({
        full_url: a.getAttribute("href"),
        caption: a.getAttribute("data-caption") || "",
    }));

    links.forEach((a, i) => {
        a.addEventListener("click", (e) => {
            if (!window.gpxLightbox) return;   // lightbox se nenačetl → obyčejný odkaz
            e.preventDefault();
            window.gpxLightbox.open(photos, i, a);
        });
    });
})();
