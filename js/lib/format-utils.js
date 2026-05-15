/**
 * GPX Manager — shared formatting utilities
 * Exposes: window.GpxFmt.{ formatDuration, escHtml, formatDate }
 */
window.GpxFmt = (function () {
    "use strict";

    /**
     * Format seconds as H:MM:SS. Returns '–' for zero/negative.
     * @param {number} seconds
     * @returns {string}
     */
    function formatDuration(seconds) {
        if (!seconds || seconds <= 0) return "–";
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = Math.floor(seconds % 60);
        return h + ":" + String(m).padStart(2, "0") + ":" + String(s).padStart(2, "0");
    }

    /**
     * Escape user-supplied strings before inserting into Leaflet tooltip/popup HTML.
     * Escapes: & < > " '
     * @param {*} s
     * @returns {string}
     */
    function escHtml(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (m) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m];
        });
    }

    /**
     * Format a date string as DD.MM.YYYY (cs-CZ locale).
     * Returns '–' for falsy input.
     * @param {string} dateStr
     * @returns {string}
     */
    function formatDate(dateStr) {
        if (!dateStr) return "–";
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString("cs-CZ");
    }

    return { formatDuration: formatDuration, escHtml: escHtml, formatDate: formatDate };
})();
