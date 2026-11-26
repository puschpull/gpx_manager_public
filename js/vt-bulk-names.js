/**
 * GPX Manager — virtual tracks: bulk names (virtual_tracks.php, admin only).
 *
 * "Suggest" asks api/virtual_tracks/suggest_name.php for every track (one by
 * one — each call geocodes places on Mapy.com) and fills the "new name" field,
 * ticking rows where the suggestion differs. Nothing is saved until the admin
 * presses "Save ticked" — then api/virtual_tracks/rename.php for each ticked row.
 */
(function () {
    "use strict";

    var script = document.getElementById("vtBulkScript");
    var CSRF = script ? script.getAttribute("data-csrf") : "";
    var I = {};
    try { I = JSON.parse(script.getAttribute("data-i18n") || "{}"); } catch (e) { I = {}; }
    function T(k, p) {
        var s = I[k] || k;
        if (p) Object.keys(p).forEach(function (x) { s = s.split("{" + x + "}").join(p[x]); });
        return s;
    }

    document.addEventListener("DOMContentLoaded", function () {
        var box = document.getElementById("vtBulk");
        if (!box || !CSRF) return;
        var status = document.getElementById("vtBulkStatus");
        var rows = function () { return Array.prototype.slice.call(box.querySelectorAll("tr[data-vt-id]")); };
        var busy = false;

        // Editing a name ticks its row
        box.addEventListener("input", function (e) {
            if (!e.target.classList.contains("vt-bulk-name")) return;
            var tr = e.target.closest("tr");
            tr.querySelector(".vt-bulk-pick").checked = e.target.value.trim() !== tr.querySelector(".vt-bulk-cur").textContent.trim();
        });
        document.getElementById("vtBulkAll").addEventListener("change", function () {
            var on = this.checked;
            rows().forEach(function (tr) { tr.querySelector(".vt-bulk-pick").checked = on; });
        });

        function seq(list, fn, label) {
            var i = 0, failed = 0;
            function next() {
                if (i >= list.length) return Promise.resolve(failed);
                var tr = list[i++];
                status.textContent = T(label, { i: i, n: list.length });
                return fn(tr).then(function (ok) { if (!ok) failed++; }, function () { failed++; }).then(next);
            }
            return next();
        }

        document.getElementById("vtBulkSuggest").addEventListener("click", function () {
            if (busy) return;
            busy = true;
            seq(rows(), function (tr) {
                var id = tr.getAttribute("data-vt-id");
                return fetch("api/virtual_tracks/suggest_name.php?id=" + encodeURIComponent(id), { credentials: "same-origin" })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok || !d.name) return false;
                        var inp = tr.querySelector(".vt-bulk-name");
                        inp.value = d.name;
                        tr.querySelector(".vt-bulk-pick").checked = d.name.trim() !== tr.querySelector(".vt-bulk-cur").textContent.trim();
                        return true;
                    });
            }, "suggesting").then(function (failed) {
                busy = false;
                status.textContent = failed ? T("failed", { n: failed }) : T("done");
            });
        });

        document.getElementById("vtBulkSave").addEventListener("click", function () {
            if (busy) return;
            var picked = rows().filter(function (tr) {
                return tr.querySelector(".vt-bulk-pick").checked && tr.querySelector(".vt-bulk-name").value.trim() !== "";
            });
            if (!picked.length) { status.textContent = T("none"); return; }
            busy = true;
            seq(picked, function (tr) {
                var body = new URLSearchParams();
                body.append("id", tr.getAttribute("data-vt-id"));
                body.append("name", tr.querySelector(".vt-bulk-name").value.trim());
                return fetch("api/virtual_tracks/rename.php", {
                    method: "POST", credentials: "same-origin",
                    headers: { "X-CSRF-Token": CSRF, "X-Requested-With": "XMLHttpRequest" },
                    body: body
                }).then(function (r) { return r.json(); }).then(function (d) { return !!(d && d.ok); });
            }, "saving").then(function (failed) {
                busy = false;
                if (failed) status.textContent = T("failed", { n: failed });
                else location.reload();
            });
        });
    });
})();
