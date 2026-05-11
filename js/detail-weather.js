/**
 * GPX Manager Redesign — Weather widget pro detail trasy
 * Zdroj: Open-Meteo Archive API (zdarma, bez API klíče)
 * Model: era5_seamless (~10 km rozlišení, ERA5-Land + ERA5)
 *
 * POZNÁMKA: Data pocházejí z klimatického modelu ERA5, nikoliv z meteorologické
 * stanice. Přesnost je přibližná — odchylky ±3–5 °C a chybná oblačnost jsou
 * běžné, zejména v členitém terénu nebo při lokálních jevech.
 */
(function () {
    'use strict';

    // ── WMO kódy počasí → emoji + popis ──────────────────────────────────────
    const WMO = {
        0:  { cs: 'Jasno',                      en: 'Clear sky',              icon: '☀️' },
        1:  { cs: 'Převážně jasno',              en: 'Mainly clear',           icon: '🌤️' },
        2:  { cs: 'Polojasno',                   en: 'Partly cloudy',          icon: '⛅' },
        3:  { cs: 'Zataženo',                    en: 'Overcast',               icon: '☁️' },
        45: { cs: 'Mlha',                        en: 'Fog',                    icon: '🌫️' },
        48: { cs: 'Jinovatková mlha',            en: 'Rime fog',               icon: '🌫️' },
        51: { cs: 'Slabé mrholení',              en: 'Light drizzle',          icon: '🌦️' },
        53: { cs: 'Mrholení',                    en: 'Drizzle',                icon: '🌦️' },
        55: { cs: 'Silné mrholení',              en: 'Heavy drizzle',          icon: '🌧️' },
        61: { cs: 'Slabý déšť',                  en: 'Slight rain',            icon: '🌧️' },
        63: { cs: 'Déšť',                        en: 'Moderate rain',          icon: '🌧️' },
        65: { cs: 'Silný déšť',                  en: 'Heavy rain',             icon: '🌧️' },
        71: { cs: 'Slabé sněžení',               en: 'Slight snow',            icon: '❄️' },
        73: { cs: 'Sněžení',                     en: 'Moderate snow',          icon: '❄️' },
        75: { cs: 'Silné sněžení',               en: 'Heavy snow',             icon: '❄️' },
        77: { cs: 'Sněhové krupky',              en: 'Snow grains',            icon: '🌨️' },
        80: { cs: 'Slabé přeháňky',              en: 'Slight showers',         icon: '🌦️' },
        81: { cs: 'Přeháňky',                    en: 'Rain showers',           icon: '🌦️' },
        82: { cs: 'Silné přeháňky',              en: 'Heavy showers',          icon: '⛈️' },
        85: { cs: 'Sněhové přeháňky',            en: 'Snow showers',           icon: '🌨️' },
        86: { cs: 'Silné sněh. přeháňky',        en: 'Heavy snow showers',     icon: '🌨️' },
        95: { cs: 'Bouřka',                      en: 'Thunderstorm',           icon: '⛈️' },
        96: { cs: 'Bouřka s krupobitím',         en: 'Thunderstorm + hail',    icon: '⛈️' },
        99: { cs: 'Silná bouřka s krupobitím',   en: 'Heavy thunderstorm + hail', icon: '⛈️' },
    };

    function wmoInfo(code) {
        const c = parseInt(code, 10);
        if (isNaN(c)) return { cs: '–', en: '–', icon: '❓' };
        if (WMO[c]) return WMO[c];
        for (let i = c - 1; i >= 0; i--) {
            if (WMO[i]) return WMO[i];
        }
        return { cs: '–', en: '–', icon: '❓' };
    }

    // ── Detekce jazyka ────────────────────────────────────────────────────────
    function getLang() {
        try {
            const c = document.cookie.split('; ').find(r => r.startsWith('app_lang='));
            return c ? c.split('=')[1] : 'cs';
        } catch (_) { return 'cs'; }
    }

    // ── Parsování MySQL datetime → JS Date (bezpečné) ────────────────────────
    // MySQL: "2026-04-12 10:30:00" — mezera místo T, JS to různě interpretuje
    function parseMysqlDate(str) {
        if (!str) return null;
        // Normalizuj mezeru na T a ořež sekundy
        const iso = str.replace(' ', 'T').substring(0, 16); // "2026-04-12T10:30"
        const d = new Date(iso);
        return isNaN(d.getTime()) ? null : d;
    }

    // Stejné parsování pro Open-Meteo časy (jsou ve formátu "2026-04-12T10:00")
    function parseApiTime(str) {
        if (!str) return null;
        const d = new Date(str.length === 16 ? str : str.substring(0, 16));
        return isNaN(d.getTime()) ? null : d;
    }

    // ── Formátování hodnot ────────────────────────────────────────────────────
    function fmt(val, decimals = 1, suffix = '') {
        if (val === null || val === undefined) return '–';
        return Number(val).toFixed(decimals).replace('.', ',') + (suffix ? ' ' + suffix : '');
    }

    // ── DOM helper ────────────────────────────────────────────────────────────
    function el(id) { return document.getElementById(id); }

    // ── Render dat do widgetu ─────────────────────────────────────────────────
    function renderWeather(data, lang, startDate) {
        const wrap = el('weather-widget');
        if (!wrap) return;

        const hourly = data.hourly;
        const times  = hourly.time; // ["2026-04-12T00:00", ...]

        // Najdi index nejbližší hodiny ke startu trasy
        let idx = 0;
        if (startDate) {
            let best = Infinity;
            times.forEach((t, i) => {
                const apiTime = parseApiTime(t);
                if (!apiTime) return;
                const diff = Math.abs(apiTime - startDate);
                if (diff < best) { best = diff; idx = i; }
            });
        }

        const code   = parseInt(hourly.weather_code[idx], 10);
        const temp   = hourly.temperature_2m[idx];
        const precip = hourly.precipitation[idx];
        const wind   = hourly.wind_speed_10m[idx];
        const cloud  = hourly.cloud_cover[idx];
        const wmo    = wmoInfo(code);

        const label   = lang === 'cs' ? wmo.cs : wmo.en;
        const timeStr = times[idx] ? times[idx].substring(11, 16) : '';

        wrap.innerHTML = `
            <div class="flex items-center gap-2 mb-3">
                <span class="text-2xl leading-none" title="${label}">${wmo.icon}</span>
                <div>
                    <div class="font-semibold text-forest-700 dark:text-sand-100 leading-tight">${label}</div>
                    <div class="text-xs text-forest-700/60 dark:text-sand-100/60">${timeStr ? timeStr + ' h' : ''}</div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm">
                <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 shrink-0 text-forest-700/50 dark:text-sand-100/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>
                    <span class="stat-num font-semibold text-forest-700 dark:text-sand-100">${fmt(temp, 1)}</span>
                    <span class="text-forest-700/60 dark:text-sand-100/60">°C</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 shrink-0 text-forest-700/50 dark:text-sand-100/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 16.2A4.5 4.5 0 0 0 17.5 8h-1.8A7 7 0 1 0 4 14.9"/><polyline points="16 16 12 20 8 16"/></svg>
                    <span class="stat-num font-semibold text-forest-700 dark:text-sand-100">${fmt(precip, 1)}</span>
                    <span class="text-forest-700/60 dark:text-sand-100/60">mm</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 shrink-0 text-forest-700/50 dark:text-sand-100/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.7 7.7a2.5 2.5 0 1 1 1.8 4.3H2"/><path d="M9.6 4.6A2 2 0 1 1 11 8H2"/><path d="M12.6 19.4A2 2 0 1 0 14 16H2"/></svg>
                    <span class="stat-num font-semibold text-forest-700 dark:text-sand-100">${fmt(wind, 0)}</span>
                    <span class="text-forest-700/60 dark:text-sand-100/60">km/h</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5 shrink-0 text-forest-700/50 dark:text-sand-100/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                    <span class="stat-num font-semibold text-forest-700 dark:text-sand-100">${cloud !== null && cloud !== undefined ? Math.round(cloud) : '–'}</span>
                    <span class="text-forest-700/60 dark:text-sand-100/60">%</span>
                </div>
            </div>`;

        el('weather-section')?.classList.remove('hidden');
    }

    // ── Hlavní funkce ─────────────────────────────────────────────────────────
    async function loadWeather() {
        const d = window.gpxDetailData;
        if (!d || !d.dateStart || !d.weatherLat || !d.weatherLon) return;

        const startDate = parseMysqlDate(d.dateStart);
        if (!startDate) return;

        const dateStr = d.dateStart.substring(0, 10); // "2026-04-12"

        // Archive vs. forecast podle data
        // ERA5 má latenci ~5–7 dní → práh nastaven na 7 dní jako bezpečná rezerva
        // Trasy mladší 7 dní jdou na Forecast API (má aktuální + recent data)
        const todayMs  = Date.now();
        const trackMs  = startDate.getTime();
        const isHistory = (todayMs - trackMs) > 7 * 24 * 3600 * 1000;

        const baseUrl = isHistory
            ? 'https://archive-api.open-meteo.com/v1/archive'
            : 'https://api.open-meteo.com/v1/forecast';

        // era5_seamless = ERA5-Land (~10 km) + ERA5 fallback; lepší přesnost než plain ERA5
        const modelParam = isHistory ? '&models=era5_seamless' : '';

        const url = `${baseUrl}?latitude=${d.weatherLat}&longitude=${d.weatherLon}` +
            `&start_date=${dateStr}&end_date=${dateStr}` +
            `&hourly=temperature_2m,precipitation,wind_speed_10m,cloud_cover,weather_code` +
            `&timezone=Europe%2FPrague${modelParam}`;

        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (!data.hourly?.time?.length) throw new Error('no hourly data');
            renderWeather(data, getLang(), startDate);
        } catch (e) {
            console.warn('[weather]', e.message);
            // Při chybě widget tiše skryjeme
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadWeather);
    } else {
        loadWeather();
    }
})();
