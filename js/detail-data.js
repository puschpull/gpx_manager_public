/**
 * ===========================================================
 *  GPX Manager – Detail Data Module
 *  Přenos dat z PHP → JS + základní konstanty a barvy
 * ===========================================================
 */

if (window.GPX_DEBUG) console.log("📄 detail-data.js načten");



// ===== Mapování barev Garmin → HEX =====
window.gpxColorMap = {
    Black:'#000', Blue:'#00f', Cyan:'#0ff', DarkBlue:'#00008B', DarkCyan:'#008B8B',
    DarkGray:'#A9A9A9', DarkGreen:'#006400', DarkMagenta:'#8B008B', DarkRed:'#8B0000',
    DarkOrange:'#FF8C00', Gray:'#808080', Green:'#008000', LightGray:'#D3D3D3',
    Magenta:'#FF00FF', Orange:'#FFA500', Purple:'#800080', Red:'#FF0000',
    White:'#FFFFFF', Yellow:'#FFFF00'
};

// Výběr barvy pro aktuální trasu
window.strokeColor =
    window.gpxColorMap[gpxDetailData.garminColor] ||
    gpxDetailData.garminColor ||
    'blue';
