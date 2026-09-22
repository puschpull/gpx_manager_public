/**
 * ===========================================================
 *  GPX Manager – Alpine.js komponenty
 *
 *  Aplikace používá CSP build Alpine (@alpinejs/csp), který nepotřebuje
 *  'unsafe-eval'. Ten ale neumí vyhodnotit kód napsaný přímo v HTML
 *  atributu (přiřazení, negaci, podmínku, volání s parametry) — v šablonách
 *  smí být jen název vlastnosti nebo metody. Veškerá logika je proto tady.
 *
 *  Pravidlo pro šablony:  @click="toggle"   ne  @click="open = !open"
 *                         x-show="light"    ne  x-show="!dark"
 *  Vlastnost použitá ve výrazu nesmí být undefined (CSP build pak hází chybu).
 *
 *  Musí se načíst PŘED Alpine (defer zachovává pořadí skriptů).
 * ===========================================================
 */

document.addEventListener("alpine:init", () => {

    /* ---------- Tmavý režim ---------- */
    function storedTheme() {
        try { return localStorage.getItem("gpx-theme"); } catch (e) { return null; }
    }
    function prefersDark() {
        const t = storedTheme();
        if (t) return t === "dark";
        return !!(window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches);
    }
    function applyTheme(dark) {
        document.documentElement.classList.toggle("dark", dark);
    }

    /**
     * Kořenová komponenta na <html>: tmavý režim a mobilní menu.
     * Mobilní menu bylo dřív v Alpine.store — v kořenové komponentě je
     * dostupné ze všech potomků stejně, jen bez výrazů „$store.x = !…".
     */
    Alpine.data("gpxApp", () => ({
        dark: prefersDark(),
        navOpen: false,

        init() {
            applyTheme(this.dark);
            this.$watch("dark", (v) => {
                applyTheme(v);
                try { localStorage.setItem("gpx-theme", v ? "dark" : "light"); } catch (e) { /* privátní režim */ }
            });
        },

        toggleDark() { this.dark = !this.dark; },
        get light()     { return !this.dark; },
        get darkLabel() { return this.dark ? "Light mode" : "Dark mode"; },

        toggleNav() { this.navOpen = !this.navOpen; },
        closeNav()  { this.navOpen = false; },
        get navExpanded() { return this.navOpen ? "true" : "false"; }
    }));

    /* ---------- Rozbalovací menu (jazyk, menu admina) ---------- */
    Alpine.data("dropdown", () => ({
        open: false,
        toggle() { this.open = !this.open; },
        close()  { this.open = false; },
        get expanded()   { return this.open ? "true" : "false"; },
        get caretClass() { return this.open ? "rotate-180" : ""; },
        get caretStyle() { return this.open ? "transform:rotate(180deg)" : ""; }
    }));

    /* ---------- Počítadlo na úvodní stránce ----------
       Cílové hodnoty přicházejí z PHP v data-* atributech (dřív byly vepsané
       přímo do x-data, což CSP build nepřečte). */
    Alpine.data("statCounter", () => ({
        tracks: 0, km: 0, asc: 0,

        init() {
            const d = this.$el.dataset;
            const target = {
                tracks: parseInt(d.tracks, 10) || 0,
                km:     parseInt(d.km, 10) || 0,
                asc:    parseInt(d.asc, 10) || 0
            };
            const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
            if (reduce) { Object.assign(this, target); return; }

            const start = performance.now(), dur = 1100;
            const ease = (t) => 1 - Math.pow(1 - t, 3);
            const tick = (now) => {
                const t = Math.min(1, (now - start) / dur);
                const e = ease(t);
                this.tracks = Math.round(target.tracks * e);
                this.km     = Math.round(target.km * e);
                this.asc    = Math.round(target.asc * e);
                if (t < 1) requestAnimationFrame(tick);
            };
            requestAnimationFrame(tick);
        },

        get tracksText() { return this.tracks.toLocaleString("cs"); },
        get kmText()     { return this.km.toLocaleString("cs"); },
        get ascText()    { return this.asc.toLocaleString("cs"); }
    }));
});
