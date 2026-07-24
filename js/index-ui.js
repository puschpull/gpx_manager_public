/**
 * ===========================================================
 *  GPX Manager – Index UI Logic
 *  Ovládací logika pro UI: sidebar, přehled, graf, scroll, filtry
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("🧩 index-ui.js načten");

document.addEventListener("DOMContentLoaded", () => {

    /* ===== Sidebar toggle ===== */
    const sidebarToggle = document.getElementById("sidebarToggle");
    const indexLayout   = document.getElementById("indexLayout");

    if (sidebarToggle && indexLayout) {
        // Načtení stavu z localStorage
        const sidebarVisible = localStorage.getItem("sidebarVisible") !== "false";

        if (!sidebarVisible) {
            indexLayout.classList.add("sidebar-hidden");
            sidebarToggle.classList.remove("active");
            sidebarToggle.setAttribute("aria-expanded", "false");
        } else {
            indexLayout.classList.remove("sidebar-hidden");
            sidebarToggle.classList.add("active");
            sidebarToggle.setAttribute("aria-expanded", "true");
        }

        sidebarToggle.addEventListener("click", () => {
            const isHidden = indexLayout.classList.toggle("sidebar-hidden");
            sidebarToggle.classList.toggle("active", !isHidden);
            sidebarToggle.setAttribute("aria-expanded", (!isHidden).toString());
            localStorage.setItem("sidebarVisible", (!isHidden).toString());
        });
    }

    /* ===== Přepínání zobrazení grafu ===== */
    const btn = document.getElementById("toggleChartBtn");
    const chartSection = document.getElementById("chartSection");

    if (btn && chartSection) {
        const savedState = localStorage.getItem("showChart") === "true";
        chartSection.style.display = savedState ? "block" : "none";
        btn.textContent = savedState ? "📊 Skrýt přehled" : "📊 Zobrazit přehled";

        if (savedState) {
            setTimeout(() => {
                document.dispatchEvent(new Event("gpx:chart:shown"));
            }, 0);
        }

        btn.addEventListener("click", () => {
            const visible = chartSection.style.display === "block";
            chartSection.style.display = visible ? "none" : "block";
            localStorage.setItem("showChart", (!visible).toString());
            btn.textContent = visible ? "📊 Zobrazit přehled" : "📊 Skrýt přehled";

            if (!visible) {
                setTimeout(() => {
                    document.dispatchEvent(new Event("gpx:chart:shown"));
                }, 0);
            }
        });
    }

    /* ===== Synchronizace horizontálního scrollu tabulky ===== */
    const bottomScroll = document.querySelector(".table-responsive");
    const topScroll    = document.querySelector(".table-scroll-top");

    if (bottomScroll && topScroll) {
        const inner = document.createElement("div");
        inner.style.width = bottomScroll.scrollWidth + "px";
        topScroll.appendChild(inner);

        let scrollTicking = false;
        topScroll.addEventListener("scroll", () => {
            if (!scrollTicking) {
                requestAnimationFrame(() => {
                    bottomScroll.scrollLeft = topScroll.scrollLeft;
                    scrollTicking = false;
                });
                scrollTicking = true;
            }
        });
        bottomScroll.addEventListener("scroll", () => {
            if (!scrollTicking) {
                requestAnimationFrame(() => {
                    topScroll.scrollLeft = bottomScroll.scrollLeft;
                    scrollTicking = false;
                });
                scrollTicking = true;
            }
        });

        let resizeTimer;
        window.addEventListener("resize", () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                inner.style.width = bottomScroll.scrollWidth + "px";
                topScroll.style.display = bottomScroll.scrollWidth <= bottomScroll.clientWidth + 1
                    ? "none" : "block";
            }, 150);
        });
    }

    /* ===== Logika filtrovacího formuláře ===== */
    const filterForm = document.querySelector("form.filters");
    if (filterForm) {
        filterForm.addEventListener("submit", () => {
            if (window.GPX_DEBUG) console.log("🔍 Odesílám filtr...");
        });
    }

    // FE-4: column-visibility logic removed — authoritative implementation is in js/index-columns.js
});


// FE-17: window.tracksChart reference removed — chart resize is handled by index-chart.js
