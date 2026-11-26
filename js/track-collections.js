/**
 * GPX Manager — track detail: add the track to a collection / remove it
 * (includes/partials/track_collections.php, admin only).
 */
(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        var box = document.getElementById("trackCollections");
        if (!box) return;
        var tid = box.getAttribute("data-track-id");
        var csrf = box.getAttribute("data-csrf");
        if (!csrf) return;

        function send(cid, action) {
            var body = new URLSearchParams();
            body.append("collection_id", cid);
            body.append("action", action);
            body.append("track_ids[]", tid);
            return fetch("api/collections/tracks.php", {
                method: "POST", credentials: "same-origin",
                headers: { "X-CSRF-Token": csrf, "X-Requested-With": "XMLHttpRequest" },
                body: body
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d && d.ok) location.reload();
                else alert((d && d.error) || "Error");
            });
        }

        var sel = document.getElementById("tcAdd");
        if (sel) sel.addEventListener("change", function () { if (sel.value) { sel.disabled = true; send(sel.value, "add"); } });

        box.addEventListener("click", function (e) {
            var b = e.target.closest("[data-tc-remove]");
            if (!b) return;
            b.disabled = true;
            send(b.getAttribute("data-tc-remove"), "remove");
        });
    });
})();
