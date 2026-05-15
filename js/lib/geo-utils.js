/**
 * GPX Manager — shared geo utilities
 * Exposes: window.GpxGeo.{ toRad, haversine, subsampleArrays }
 */
window.GpxGeo = (function () {
    "use strict";

    function toRad(d) { return d * Math.PI / 180; }

    function haversine(lat1, lon1, lat2, lon2) {
        var R = 6371000;
        var dLat = toRad(lat2 - lat1);
        var dLon = toRad(lon2 - lon1);
        var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    /**
     * Subsample parallel arrays to at most maxPoints entries,
     * always keeping the last element.
     * @param {number[]} distKm
     * @param {number[]} elevM
     * @param {number[]} latArr
     * @param {number[]} lonArr
     * @param {Array}    timesArr  — pass [] when unused
     * @param {number}   maxPoints — default 2000
     * @returns {{ distKm, elevM, latArr, lonArr, timesArr }}
     */
    function subsampleArrays(distKm, elevM, latArr, lonArr, timesArr, maxPoints) {
        maxPoints = maxPoints || 2000;
        var n = distKm.length;
        if (n <= maxPoints) return { distKm: distKm, elevM: elevM, latArr: latArr, lonArr: lonArr, timesArr: timesArr };
        var step = Math.ceil(n / maxPoints);
        var d2 = [], e2 = [], la2 = [], lo2 = [], t2 = [];
        for (var i = 0; i < n; i += step) {
            d2.push(distKm[i]);
            e2.push(elevM[i]);
            la2.push(latArr[i]);
            lo2.push(lonArr[i]);
            if (timesArr && timesArr.length) t2.push(timesArr[i]);
        }
        var last = n - 1;
        if (d2[d2.length - 1] !== distKm[last]) {
            d2.push(distKm[last]);
            e2.push(elevM[last]);
            la2.push(latArr[last]);
            lo2.push(lonArr[last]);
            if (timesArr && timesArr.length) t2.push(timesArr[last]);
        }
        return { distKm: d2, elevM: e2, latArr: la2, lonArr: lo2, timesArr: t2 };
    }

    return { toRad: toRad, haversine: haversine, subsampleArrays: subsampleArrays };
})();
