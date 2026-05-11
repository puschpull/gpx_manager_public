console.log('🧩 index-ajax.js načten – AJAX filtr připraven');

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('#filter-form');
    if (!form) {
        console.warn('⚠️ Formulář #filter-form nebyl nalezen');
        return;
    }

    const endpoint = form.dataset.endpoint || '../includes/index_data.php';
    const tableContainer = document.querySelector('#table-container');
    // const chartCanvas = document.querySelector('#chart');
    const chartCanvas = document.querySelector('#tracksChart');


    console.log('✅ AJAX připraven pro endpoint:', endpoint);

    // === Pomocná funkce pro aktualizaci grafu ===
    function updateChart(chartData) {
        if (!chartCanvas) {
            console.warn('⚠️ Canvas #tracksChart nebyl nalezen');
            return;
        }

        // --- Globální odstranění všech grafů na tomto canvasu ---
        try {
            const chartInstance = Chart.getChart(chartCanvas);
            if (chartInstance) {
                chartInstance.destroy();
                console.log('🧹 Starý graf (globálně) odstraněn');
            }
        } catch (err) {
            console.warn('⚠️ Nepodařilo se odstranit starý graf globálně:', err);
        }

        // --- Přetvoření dat ---
        const labels = chartData.map(item => item.month);
        const values = chartData.map(item => parseFloat(item.total_distance));

        const dataset = {
            labels,
            datasets: [{
                label: 'Celková vzdálenost (km)',
                data: values,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0,123,255,0.3)',
                borderWidth: 2,
                tension: 0.25,
                fill: true
            }]
        };

        const options = {
            responsive: true,
            animation: {
                duration: 500,
                easing: 'easeInOutQuart'
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Km' }
                },
                x: {
                    title: { display: true, text: 'Měsíc' }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.formattedValue} km`
                    }
                }
            }
        };

        // --- Vytvoření nového grafu ---
        try {
            const ctx = chartCanvas.getContext('2d');
            window.myChart = new Chart(ctx, {
                type: 'line',
                data: dataset,
                options
            });
            console.log('📈 Nový graf vytvořen');
        } catch (err) {
            console.error('❌ Chyba při vytváření grafu:', err);
        }
    }



    // === Debounce (aby se AJAX nespouštěl 10× při psaní) ===
    let debounceTimer;
    function debounceAjax(callback, delay = 400) {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(callback, delay);
    }

    // === Naslouchání změnám filtrů ===
    ['change', 'input'].forEach(evt => {
        form.addEventListener(evt, (e) => {
            if (e.target.type === 'submit') return; // ignoruj tlačítka

            debounceAjax(() => {
                const formData = new FormData(form);
                const params = new URLSearchParams(formData);
                params.append('ajax', '1'); // řekni PHP, že chceme JSON

                const url = `${endpoint}?${params.toString()}`;
                console.log(`📡 [${evt}] Odesílám AJAX: ${url}`);

                fetch(url)
                    .then(res => {
                        if (!res.ok) throw new Error(`HTTP ${res.status}`);
                        return res.json();
                    })
                    .then(data => {
                        console.log('📨 Odpověď AJAX:', data);

                        // === Aktualizace tabulky ===
                        if (tableContainer && data.table_html) {
                            tableContainer.innerHTML = data.table_html;
                            console.log('✅ Tabulka aktualizována');
                        }

                        // === Aktualizace grafu ===
                        if (data.chart_data) {
                            updateChart(data.chart_data);
                        }
                    })
                    .catch(err => {
                        console.error('❌ Chyba při načítání dat:', err);
                    });
            });
        });
    });
});
