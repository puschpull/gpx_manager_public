/**
 * Chart.js outdoor theme — sdílená paleta pro stats/calendar/index grafy
 * Zpřístupní hodnoty z CSS proměnných definovaných v assets/css/input.css
 * (forest greens + terracotta + earth tones).
 */
(function () {
    function readVar(name, fallback) {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    }
    const isDark = () => document.documentElement.classList.contains('dark');

    window.OutdoorChartTheme = {
        forest700:     readVar('--color-forest-700', '#2d4a3e'),
        forest600:     readVar('--color-forest-600', '#2e7d32'),
        forest500:     readVar('--color-forest-500', '#3f6b57'),
        forest300:     readVar('--color-forest-300', '#84ad94'),
        terracotta500: readVar('--color-terracotta-500', '#c97b3f'),
        terracotta300: readVar('--color-terracotta-300', '#e89455'),
        bark700:       readVar('--color-bark-700', '#5c4a3a'),
        sand200:       readVar('--color-sand-200', '#e8e0d2'),
        // Kontextové tóny
        get text()      { return isDark() ? '#e8e0d2' : '#2d4a3e'; },
        get textMuted() { return isDark() ? 'rgba(245,241,234,0.6)' : 'rgba(45,74,62,0.65)'; },
        get gridSoft()  { return isDark() ? 'rgba(245,241,234,0.08)' : 'rgba(45,74,62,0.08)'; },
        get cardBg()    { return isDark() ? '#243028' : '#ffffff'; }
    };

    if (window.Chart) {
        Chart.defaults.font.family = '"Inter", system-ui, sans-serif';
        Chart.defaults.font.size = 11;
        Chart.defaults.color = window.OutdoorChartTheme.text;
    }
})();
