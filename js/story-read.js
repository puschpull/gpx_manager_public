/**
 * Cestopis — čtení (story.php): fotky se otevírají ve stejné kartě
 * v prohlížeči js/lightbox.js (← → Esc, posun prstem, počítadlo).
 * Listuje se přes všechny fotky výletu v pořadí galerie; fotka z textu
 * článku (data-story-inline) otevře prohlížeč na svém místě v galerii.
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

    // Fotky v textu článku: otevřou se na svém místě v galerii, takže
    // šipkami jde listovat dál po celém výletě (a v seznamu nejsou dvakrát)
    document.querySelectorAll("a[data-story-inline]").forEach((a) => {
        const i = photos.findIndex((p) => p.full_url === a.getAttribute("href"));
        if (i < 0) return;
        a.addEventListener("click", (e) => {
            if (!window.gpxLightbox) return;
            e.preventDefault();
            window.gpxLightbox.open(photos, i, a);
        });
    });
})();
