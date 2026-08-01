/**
 * ===========================================================
 *  GPX Manager – Barometrická kontrola výšky start/cíl
 * ===========================================================
 *
 * Na okruhu (start i cíl na stejném místě) se nadmořská výška na začátku
 * a na konci často liší o desítky metrů. Nejde o chybu měření vzdálenosti,
 * ale o to, že barometrický výškoměr počítá výšku z tlaku vzduchu — a ten
 * se během výšlapu mění s počasím. Zhruba 1 hPa ≈ 8–9 m zdánlivého převýšení.
 *
 * Modul vezme čas a výšku prvního a posledního bodu trasy, dotáhne k nim
 * průběh tlaku z Open-Meteo (stejný zdroj jako widget počasí, model ERA5)
 * a spočítá, kolik z toho rozdílu jde na vrub tlaku a co zbývá nevysvětleno.
 *
 * Vyhodnocuje se jen tam, kde to dává smysl:
 *   – trasa musí být okruh (start a cíl blíž než LOOP_MAX_M),
 *     jinak je rozdíl výšky skutečný a není co vysvětlovat,
 *   – rozdíl výšky musí být aspoň MIN_DIFF_M,
 *   – výšlap musí trvat aspoň MIN_DURATION_MS.
 * Když některá podmínka neplatí, modul mlčí a NEODESÍLÁ žádný dotaz na API.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POZOR — OVĚŘENO 31.7.2026, VÝSLEDEK JE NEGATIVNÍ. Neber výstup tohoto
 * modulu jako spolehlivou diagnózu a nestav na něm další funkce.
 *
 * Na 70 okruzích z GPSMAP 64sx (tedy z novějšího přístroje, kde by data
 * měla být nejčistší) vyšlo:
 *   – korelace mezi tlakem předpovězeným posunem a skutečným rozdílem
 *     výšky  r = +0,02  (u rozdílů pod 12 m dokonce −0,21). Při n = 70 je
 *     mez významnosti kolem 0,24, takže ani slabý vztah tam není.
 *   – po odečtení vlivu tlaku směrodatná odchylka NEKLESNE, ale STOUPNE:
 *     22,2 → 24,5 m (u rozdílů pod 12 m 7,6 → 14,4 m). Kdyby byl tlak
 *     příčinou, musela by klesnout. Korekce tedy přidává šum.
 *   – medián tlakového posunu vychází 9,0 m, zatímco medián skutečného
 *     rozdílu u tohoto přístroje je 6,5 m — „oprava" je větší než jev.
 *
 * Nejpravděpodobnější příčina (úvaha, ne měření): GPSMAP 64sx si barometr
 * průběžně kalibruje podle GPS, takže tlakový drift vyrovná uvnitř sebe
 * a ven jde jen zbytkový šum, nezávislý na počasí.
 *
 * Funkce zůstává v kódu jako doplňková informace a dá se vypnout příznakem
 * `baro_note` v Administraci. Ověřeno, že vypnutá neposílá do stránky ani
 * skript, ani kontejner, ani své texty, a nedělá žádný dotaz na API.
 * Čísla a postup měření: CHANGELOG.txt, záznam z 31.7.2026.
 * ─────────────────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    const LOOP_MAX_M      = 250;              // start↔cíl blíž ⇒ okruh
    // Práh 8 m není odhad: model ERA5 má u tlaku nejistotu kolem 0,5 hPa, což
    // samo o sobě dělá ±4 m. Vysvětlovat menší rozdíl by předstíralo přesnost,
    // kterou vstupní data nemají. Na 630 trasách autora vychází 8 m tak, že se
    // blok objeví u 59 % z nich (při 4 m by to bylo 71 %).
    const MIN_DIFF_M      = 8;
    const MIN_DURATION_MS = 20 * 60 * 1000;   // pod 20 min se tlak nestihne změnit
    const FULL_REST_M     = 5;                // zbytek pod tímto ⇒ "vysvětleno"
    const DEVICE_HINT_M   = 30;               // nad tímto zbytkem už jde nejspíš o přístroj

    const R_AIR = 287.05;    // měrná plynová konstanta suchého vzduchu [J/(kg·K)]
    const G     = 9.80665;   // tíhové zrychlení [m/s²]

    // ── i18n ─────────────────────────────────────────────────────────────────
    // Texty chodí v gpxDetailData.baroI18n (stejný vzor jako planI18n/replayI18n),
    // ne v globálním gpxI18n — na ostatních stránkách by jen zabíraly místo.
    function _t(key, fallback) {
        const d = window.gpxDetailData && window.gpxDetailData.baroI18n;
        return (d && d[key]) || fallback || key;
    }

    function tpl(str, vars) {
        return String(str).replace(/\{(\w+)\}/g, (m, k) =>
            Object.prototype.hasOwnProperty.call(vars, k) ? vars[k] : m);
    }

    // ── Formátování ──────────────────────────────────────────────────────────
    function fmt(v, dec) {
        if (v === null || v === undefined || !isFinite(v)) return '–';
        return Number(v).toFixed(dec).replace('.', ',');
    }

    // Se znaménkem — u rozdílů je směr to podstatné.
    function signed(v, dec) {
        if (v === null || v === undefined || !isFinite(v)) return '–';
        const sign = v > 0 ? '+' : (v < 0 ? '−' : '');
        return sign + fmt(Math.abs(v), dec);
    }

    // ── První a poslední <trkpt> ─────────────────────────────────────────────
    // Záměrně bez parsování celého souboru: detail-elevation.js už jednou
    // DOMParserem projel celé XML a u trasy o deseti tisících bodech není
    // důvod to dělat podruhé. Stačí vyříznout dva elementy a parsovat je.
    function readPoint(parser, fragment) {
        const doc = parser.parseFromString(fragment, 'application/xml');
        const pt  = doc.getElementsByTagName('trkpt')[0];
        if (!pt) return null;

        const eleEl  = pt.getElementsByTagName('ele')[0];
        const timeEl = pt.getElementsByTagName('time')[0];
        if (!eleEl || !timeEl) return null;

        const lat = parseFloat(pt.getAttribute('lat'));
        const lon = parseFloat(pt.getAttribute('lon'));
        const ele = parseFloat(eleEl.textContent);
        const t   = new Date(timeEl.textContent);

        if (!isFinite(lat) || !isFinite(lon) || !isFinite(ele) || isNaN(t.getTime())) return null;
        return { lat: lat, lon: lon, ele: ele, t: t };
    }

    function endpoints(xmlText) {
        const i1 = xmlText.indexOf('<trkpt');
        const i2 = xmlText.lastIndexOf('<trkpt');
        if (i1 < 0 || i2 <= i1) return null;               // méně než dva body

        const e1 = xmlText.indexOf('</trkpt>', i1);
        const e2 = xmlText.indexOf('</trkpt>', i2);
        if (e1 < 0 || e2 < 0) return null;                 // samouzavírací <trkpt/> — nemá ele ani time

        const parser = new DOMParser();
        const a = readPoint(parser, xmlText.slice(i1, e1 + 8));
        const b = readPoint(parser, xmlText.slice(i2, e2 + 8));
        return (a && b) ? { a: a, b: b } : null;
    }

    // ── Data o tlaku ─────────────────────────────────────────────────────────
    // timezone=UTC záměrně: časy v GPX jsou v UTC, takže se tím vyhneme
    // přepočtům i letnímu času.
    async function fetchSeries(lat, lon, tStart, tEnd) {
        const ageDays   = (Date.now() - tStart.getTime()) / 86400000;
        const isHistory = ageDays > 7;    // ERA5 má latenci ~5–7 dní

        const base = isHistory
            ? 'https://archive-api.open-meteo.com/v1/archive'
            : 'https://api.open-meteo.com/v1/forecast';

        const url = base +
            '?latitude='   + lat.toFixed(4) +
            '&longitude='  + lon.toFixed(4) +
            '&start_date=' + tStart.toISOString().substring(0, 10) +
            '&end_date='   + tEnd.toISOString().substring(0, 10) +
            '&hourly=pressure_msl,surface_pressure,temperature_2m' +
            '&timezone=UTC' + (isHistory ? '&models=era5_seamless' : '');

        const res = await fetch(url);
        if (!res.ok) throw new Error('HTTP ' + res.status);

        const json = await res.json();
        if (!json.hourly || !json.hourly.time || !json.hourly.time.length) {
            throw new Error('no hourly data');
        }
        return json.hourly;
    }

    // Hodnota v konkrétním čase — lineárně mezi sousedními hodinami.
    function valueAt(hourly, key, when) {
        const times = hourly.time;
        const arr   = hourly[key];
        if (!arr || !arr.length) return null;

        const target = when.getTime();
        let lo = -1;
        for (let i = 0; i < times.length; i++) {
            if (Date.parse(times[i] + 'Z') <= target) lo = i; else break;
        }
        if (lo < 0) return num(arr[0]);
        if (lo >= times.length - 1) return num(arr[arr.length - 1]);

        const t0 = Date.parse(times[lo] + 'Z');
        const t1 = Date.parse(times[lo + 1] + 'Z');
        const v0 = num(arr[lo]);
        const v1 = num(arr[lo + 1]);
        if (v0 === null || v1 === null || t1 === t0) return v0;

        return v0 + (v1 - v0) * ((target - t0) / (t1 - t0));
    }

    function num(v) {
        return (v === null || v === undefined || !isFinite(v)) ? null : Number(v);
    }

    // ── Výpočet ──────────────────────────────────────────────────────────────
    // Kolik metrů odpovídá jednomu hektopascalu: k = R·T / (g·p).
    // Nedrží se konstanty 8,4 m/hPa — ta platí u hladiny moře při 15 °C;
    // v tisíci metrech a v mrazu vychází přes 9 m/hPa, tedy o ~9 % jinak.
    function metresPerHpa(tempC, pressureHpa) {
        return (R_AIR * (tempC + 273.15)) / (G * pressureHpa * 100) * 100;
    }

    function analyse(a, b, hourly) {
        // Tendenci bere z tlaku přepočteného na hladinu moře — ten se mění
        // synopticky, kdežto surface_pressure v modelu závisí i na tom, jak
        // vysoko leží povrch v dané buňce sítě.
        const p0 = valueAt(hourly, 'pressure_msl', a.t);
        const p1 = valueAt(hourly, 'pressure_msl', b.t);
        if (p0 === null || p1 === null) return null;

        const t0 = valueAt(hourly, 'temperature_2m', a.t);
        const t1 = valueAt(hourly, 'temperature_2m', b.t);
        const ps = valueAt(hourly, 'surface_pressure', a.t);

        const tempMean = (t0 !== null && t1 !== null) ? (t0 + t1) / 2 : (t0 !== null ? t0 : 10);
        const pAmbient = (ps !== null) ? ps : (p0 + p1) / 2;

        const dP    = p1 - p0;                          // hPa, kladné = tlak stoupl
        const k     = metresPerHpa(tempMean, pAmbient); // m na 1 hPa
        const drift = -dP * k;                          // klesající tlak ⇒ výškoměr ukáže víc
        const dEle  = b.ele - a.ele;

        return { p0: p0, p1: p1, dP: dP, k: k, drift: drift, dEle: dEle, rest: dEle - drift };
    }

    function verdictKey(r) {
        if (r.drift * r.dEle <= 0)              return 'baro_verdict_none';
        if (Math.abs(r.rest) <= FULL_REST_M)    return 'baro_verdict_full';
        if (Math.abs(r.rest) < Math.abs(r.dEle) / 2) return 'baro_verdict_most';
        return 'baro_verdict_part';
    }

    // ── Výstup ───────────────────────────────────────────────────────────────
    function render(host, a, b, gap, r) {
        // Měřeno na vzorku 45 tras autora: tlak vysvětlí rozdíl úplně nebo z větší
        // části jen v 11 % případů, medián nevysvětleného zbytku je 34 m. To sedí
        // na náběh barometru po zapnutí přístroje (u starého přístroje naměřeno
        // 31,7 m). Proto se u velkých zbytků nevede jako hlavní závěr tlak, ale
        // přístroj — jinak by blok tvrdil „tlak to nevysvětluje" a mlčel o tom,
        // co to tedy vysvětluje.
        const deviceDominates = Math.abs(r.rest) > DEVICE_HINT_M;

        const verdict = deviceDominates
            ? tpl(_t('baro_verdict_device',
                'Samotný tlak by dal {drift} m, takže {rest} m zůstává nevysvětlených — nejspíš výškoměr, který se po zapnutí přístroje teprve ustaloval.'), {
                drift: signed(r.drift, 0),
                rest:  signed(r.rest, 0)
              })
            : _t(verdictKey(r), 'Změna tlaku vysvětluje část rozdílu.');

        const line1 = tpl(_t('baro_loop',
            'Trasa je okruh (start a cíl {gap} m od sebe), ale výška se liší o {diff} m: {e0} → {e1} m.'), {
            gap:  fmt(gap, 0),
            diff: signed(r.dEle, 0),
            e0:   fmt(a.ele, 0),
            e1:   fmt(b.ele, 0)
        });

        const line2 = tpl(_t('baro_pressure',
            'Tlak vzduchu se za tu dobu změnil o {dp} hPa, což barometrický výškoměr posune o {drift} m ({k} m na hPa).'), {
            dp:    signed(r.dP, 1),
            drift: signed(r.drift, 0),
            k:     fmt(r.k, 1)
        });

        // Zbytek se vypisuje jen tam, kde ho závěr sám neuvádí.
        const line3 = deviceDominates ? '' : tpl(_t('baro_rest', 'Nevysvětlený zbytek: {rest} m.'), {
            rest: signed(r.rest, 0)
        }) + ' ';

        // Zavřené <details>: v souhrnu je vidět jen o kolik metrů jde, ať se dá
        // poznat, jestli to stojí za rozkliknutí. Nativní prvek, ne vlastní JS —
        // sám umí klávesnici i čtečky.
        const summaryDiff = tpl(_t('baro_summary_diff', 'rozdíl {diff} m'), {
            diff: signed(r.dEle, 0)
        });

        host.innerHTML =
            '<details class="gpx-baro">' +
                '<summary>' +
                    '<span aria-hidden="true">⛰</span>' +
                    '<span>' + escapeHtml(_t('baro_title', 'Výška na startu a v cíli')) + '</span>' +
                    '<span class="gpx-baro-diff">' + escapeHtml('— ' + summaryDiff) + '</span>' +
                    '<span class="gpx-baro-toggle">' +
                        '<span class="gpx-baro-more">' + escapeHtml(_t('baro_show', 'Ukázat detaily')) + '</span>' +
                        '<span class="gpx-baro-less">' + escapeHtml(_t('baro_hide', 'Skrýt detaily')) + '</span>' +
                    '</span>' +
                '</summary>' +
                '<div class="gpx-baro-body">' +
                    '<div>' + escapeHtml(line1) + '</div>' +
                    '<div>' + escapeHtml(line2) + '</div>' +
                    '<div class="gpx-baro-verdict">' + escapeHtml(verdict) + '</div>' +
                    '<div class="gpx-baro-foot">' +
                        escapeHtml(line3) +
                        escapeHtml(_t('baro_disclaimer',
                            'Platí pro přístroje s barometrickým výškoměrem. Údaje o tlaku pocházejí z modelu ERA5, ne z měření na trase.')) +
                    '</div>' +
                '</div>' +
            '</details>';

        host.classList.remove('hidden');
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    // ── Hlavní běh ───────────────────────────────────────────────────────────
    async function run(xmlText) {
        const host = document.getElementById('baro-note');
        if (!host) return;

        const ep = endpoints(xmlText);
        if (!ep) return;
        const a = ep.a, b = ep.b;

        if (b.t - a.t < MIN_DURATION_MS) return;
        if (!window.GpxGeo || typeof window.GpxGeo.haversine !== 'function') return;

        // Není-li to okruh, je rozdíl výšky skutečný a vysvětlovat ho nemá smysl.
        const gap = window.GpxGeo.haversine(a.lat, a.lon, b.lat, b.lon);
        if (!isFinite(gap) || gap > LOOP_MAX_M) return;
        if (Math.abs(b.ele - a.ele) < MIN_DIFF_M) return;

        let hourly;
        try {
            hourly = await fetchSeries(a.lat, a.lon, a.t, b.t);
        } catch (e) {
            if (window.GPX_DEBUG) console.warn('[baro]', e.message);
            return;   // tiše — je to doplňková informace, ne obsah stránky
        }

        const r = analyse(a, b, hourly);
        if (!r) return;

        render(host, a, b, gap, r);
    }

    document.addEventListener('gpxDataReady', e => {
        if (e.detail && e.detail.xmlText) run(e.detail.xmlText);
    });
})();
