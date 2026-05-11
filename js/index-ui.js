/**
 * ===========================================================
 *  GPX Manager – Index UI Logic
 *  Ovládací logika pro UI: sidebar, přehled, graf, scroll, filtry
 * ===========================================================
 */

console.log("🧩 index-ui.js načten");

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
        } else {
            indexLayout.classList.remove("sidebar-hidden");
            sidebarToggle.classList.add("active");
        }

        sidebarToggle.addEventListener("click", () => {
            const isHidden = indexLayout.classList.toggle("sidebar-hidden");
            sidebarToggle.classList.toggle("active", !isHidden);
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
            console.log("🔍 Odesílám filtr...");
        });
    }

    /* ===== Dynamická viditelnost sloupců ===== */
    const COOKIE_NAME = "visible_cols";

    function getCookie(name) {
        const m = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
        return m ? decodeURIComponent(m[2]) : "";
    }

    function setCookie(name, value) {
        document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=31536000`;
    }

    const checkboxes = document.querySelectorAll(".col-toggle");
    if (!checkboxes.length) return;

    const cookieVal = getCookie(COOKIE_NAME);
    let visibleCols = cookieVal ? cookieVal.split(",") : [];

    if (visibleCols.length === 0) {
        visibleCols = Array.from(checkboxes).map(cb => cb.dataset.col);
        setCookie(COOKIE_NAME, visibleCols.join(","));
    }

    checkboxes.forEach(cb => {
        const col = cb.dataset.col;
        if (visibleCols.includes(col)) {
            cb.checked = true;
            document.body.classList.remove("hide-col-" + col);
        } else {
            cb.checked = false;
            document.body.classList.add("hide-col-" + col);
        }
    });

    checkboxes.forEach(cb => {
        cb.addEventListener("change", () => {
            const col = cb.dataset.col;
            if (cb.checked) {
                document.body.classList.remove("hide-col-" + col);
                if (!visibleCols.includes(col)) visibleCols.push(col);
            } else {
                document.body.classList.add("hide-col-" + col);
                visibleCols = visibleCols.filter(c => c !== col);
            }
            setCookie(COOKIE_NAME, visibleCols.join(","));
        });
    });

    const btnShowAll = document.getElementById("col-show-all");
    const btnHideAll = document.getElementById("col-hide-all");

    if (btnShowAll) {
        btnShowAll.addEventListener("click", () => {
            checkboxes.forEach(cb => {
                cb.checked = true;
                document.body.classList.remove("hide-col-" + cb.dataset.col);
            });
            setCookie(COOKIE_NAME, Array.from(checkboxes).map(cb => cb.dataset.col).join(","));
        });
    }

    if (btnHideAll) {
        btnHideAll.addEventListener("click", () => {
            checkboxes.forEach(cb => {
                cb.checked = false;
                document.body.classList.add("hide-col-" + cb.dataset.col);
            });
            setCookie(COOKIE_NAME, "");
        });
    }
});

/* ===== Obnovení velikosti grafu po návratu do záložky ===== */
window.addEventListener("focus", () => {
    if (window.tracksChart && typeof window.tracksChart.resize === "function") {
        window.tracksChart.resize();
        window.tracksChart.update();
    }
});
