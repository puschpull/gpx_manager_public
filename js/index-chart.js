/**
 * GPX Manager – Index Chart Logic (lazy init)
 */
console.log("📈 index-chart.js načten");

(function () {
    let chart; // instance Chart.js bude uložená tady

    // vytvoří graf (pokud už není) nebo ho jen přepočítá
    function ensureChart() {
        const canvas = document.getElementById("tracksChart");
        const metricSelector = document.getElementById("metricSelector");
        const data = window.gpxChartData || {};

        if (!canvas || !metricSelector) return;
        if (!data.labels || !Array.isArray(data.labels) || data.labels.length === 0) return;

        // pokud graf už existuje → jen resize + update
        if (chart && typeof chart.resize === "function") {
            chart.resize();
            chart.update();
            return;
        }

        // první vytvoření grafu (už je viditelný, protože voláme po odhalení sekce)
        const labels = data.labels;
        const dataDistance = data.distance || [];
        const dataCount = data.count || [];
        const dataAscent = data.ascent || [];
        const dataAvgSpeed = data.avg_speed || [];

        const styles = getComputedStyle(document.body);
        const textColor = styles.getPropertyValue("--text-color").trim() || "#222";
        const accentColor = styles.getPropertyValue("--accent-color").trim() || "#4da3ff";

        // zvolená metrika z localStorage
        const savedMetric = localStorage.getItem("lastMetric") || "distance";
        metricSelector.value = savedMetric;

        function metricInfo(metric) {
            switch (metric) {
                case "count":     return { label: "Počet tras (ks)",        data: dataCount,    type: "bar" };
                case "ascent":    return { label: "Stoupání (m)",           data: dataAscent,   type: "bar" };
                case "avg_speed": return { label: "Prům. rychlost (km/h)", data: dataAvgSpeed, type: "line" };
                default:          return { label: "Vzdálenost (km)",        data: dataDistance,  type: "bar" };
            }
        }

        function applyChartType(ds, type) {
            if (type === "line") {
                ds.borderColor = accentColor;
                ds.backgroundColor = accentColor + "33";
                ds.fill = true;
                ds.tension = 0.3;
                ds.pointRadius = 3;
                ds.borderWidth = 2;
                ds.borderRadius = 0;
                ds.barPercentage = undefined;
                ds.categoryPercentage = undefined;
            } else {
                ds.borderColor = undefined;
                ds.backgroundColor = accentColor;
                ds.fill = false;
                ds.tension = 0;
                ds.pointRadius = 0;
                ds.borderWidth = 0;
                ds.borderRadius = 4;
                ds.barPercentage = 0.6;
                ds.categoryPercentage = 0.8;
            }
        }

        const info = metricInfo(savedMetric);
        const initDs = {
            label: info.label,
            data: info.data
        };
        applyChartType(initDs, info.type);

        chart = new Chart(canvas.getContext("2d"), {
            type: info.type,
            data: {
                labels,
                datasets: [initDs]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        title: { display: true, text: "Měsíc", color: textColor },
                        ticks: { color: textColor }
                    },
                    y: {
                        title: { display: true, text: info.label, color: textColor },
                        beginAtZero: true,
                        ticks: { color: textColor }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y.toLocaleString("cs-CZ")}`
                        }
                    }
                }
            }
        });

        // změna metriky
        metricSelector.addEventListener("change", () => {
            const selected = metricSelector.value;
            localStorage.setItem("lastMetric", selected);
            const mi = metricInfo(selected);
            chart.config.type = mi.type;
            chart.data.datasets[0].label = mi.label;
            chart.data.datasets[0].data = mi.data;
            chart.options.scales.y.title.text = mi.label;
            applyChartType(chart.data.datasets[0], mi.type);
            chart.update();
        });
    }

    // 1) pokud je sekce při načtení už vidět (uložený stav), zkus rovnou inicializovat
    document.addEventListener("DOMContentLoaded", () => {
        const chartSection = document.getElementById("chartSection");
        if (chartSection && chartSection.style.display === "block") {
            // počkej 1 tick, ať se DOM domaluje
            setTimeout(ensureChart, 0);
        }
    });

    // 2) hlavní cesta – jakmile UI oznámí, že se sekce právě zobrazila, inicializuj/resize
    document.addEventListener("gpx:chart:shown", () => {
        // drobná prodleva pomůže po přepnutí display:none→block
        setTimeout(ensureChart, 50);
    });

    // 3) pojistka – po návratu do záložky přepočítej
    window.addEventListener("focus", () => {
        if (chart && typeof chart.resize === "function") {
            chart.resize();
            chart.update();
        }
    });
})();
