/**
 * GPX Manager — collections (collections.php, collection.php).
 *
 * List page (admin): create a collection.
 * Detail page: map of the tracks (GpxTrackSetMap), copy link;
 * admin: edit / delete, search and add tracks, remove, move up / down.
 * Writes go to api/collections/* with the CSRF token in X-CSRF-Token.
 */
(function () {
    "use strict";

    function post(url, data, csrf) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            if (Array.isArray(v)) v.forEach(function (x) { body.append(k + "[]", String(x)); });
            else body.append(k, String(v));
        });
        return fetch(url, {
            method: "POST", credentials: "same-origin",
            headers: { "X-CSRF-Token": csrf, "X-Requested-With": "XMLHttpRequest" },
            body: body
        }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    function formData(form) {
        return {
            name: form.elements.name.value.trim(),
            description: form.elements.description.value.trim(),
            is_public: form.elements.is_public.checked ? "1" : "0"
        };
    }

    function status(form, text) {
        var s = form.querySelector("[data-col-status]");
        if (s) s.textContent = text;
    }

    /* ---------- list page ---------- */
    function initList(D) {
        var form = document.getElementById("colNewForm");
        if (!form) return;
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var data = formData(form);
            if (!data.name) return;
            data.id = 0;
            status(form, D.i18n.saving);
            post("api/collections/save.php", data, D.csrf).then(function (d) {
                if (d && d.ok) location.href = "collection.php?id=" + encodeURIComponent(d.id);
                else status(form, (d && d.error) || D.i18n.error);
            });
        });
    }

    /* ---------- detail page ---------- */
    function initDetail(D) {
        if (window.GpxTrackSetMap && document.getElementById("colMap")) {
            window.GpxTrackSetMap.create("colMap", { linesUrl: D.linesUrl, apiKeys: D.apiKeys, i18n: D.i18n });
        }

        var copy = document.getElementById("colCopyLink");
        if (copy) {
            copy.addEventListener("click", function () {
                var url = location.origin + location.pathname + "?id=" + encodeURIComponent(D.id);
                var done = function () { copy.lastChild.textContent = " " + D.i18n.linkCopied; };
                if (navigator.clipboard) navigator.clipboard.writeText(url).then(done, function () { prompt("", url); });
                else prompt("", url);
            });
        }

        if (!D.isAdmin) return;

        var edit = document.getElementById("colEditForm");
        if (edit) {
            edit.addEventListener("submit", function (e) {
                e.preventDefault();
                var data = formData(edit);
                if (!data.name) return;
                data.id = D.id;
                status(edit, D.i18n.saving);
                post("api/collections/save.php", data, D.csrf).then(function (d) {
                    if (d && d.ok) location.reload();
                    else status(edit, (d && d.error) || D.i18n.error);
                });
            });
        }

        var del = document.getElementById("colDelete");
        if (del) {
            del.addEventListener("click", function () {
                if (!confirm(D.i18n.confirmDelete)) return;
                post("api/collections/delete.php", { id: D.id }, D.csrf).then(function (d) {
                    if (d && d.ok) location.href = "collections.php";
                    else alert((d && d.error) || D.i18n.error);
                });
            });
        }

        // Remove / reorder rows
        var table = document.getElementById("colTable");
        if (table) {
            table.addEventListener("click", function (e) {
                var btn = e.target.closest("button");
                if (!btn || btn.disabled) return;
                var row = btn.closest("tr[data-track-id]");
                if (!row) return;
                var tid = row.getAttribute("data-track-id");
                if (btn.hasAttribute("data-col-remove")) {
                    btn.disabled = true;
                    post("api/collections/tracks.php", { collection_id: D.id, action: "remove", track_ids: [tid] }, D.csrf)
                        .then(function (d) { if (d && d.ok) location.reload(); else { btn.disabled = false; alert(D.i18n.error); } });
                    return;
                }
                var dir = parseInt(btn.getAttribute("data-col-move"), 10);
                if (!dir) return;
                var sib = dir < 0 ? row.previousElementSibling : row.nextElementSibling;
                if (!sib) return;
                if (dir < 0) row.parentNode.insertBefore(row, sib);
                else row.parentNode.insertBefore(sib, row);
                var order = Array.prototype.map.call(table.querySelectorAll("tr[data-track-id]"), function (r) { return r.getAttribute("data-track-id"); });
                post("api/collections/tracks.php", { collection_id: D.id, action: "order", track_ids: order }, D.csrf)
                    .then(function (d) { if (d && d.ok) location.reload(); else alert(D.i18n.error); });
            });
        }

        // Search and add
        var input = document.getElementById("colSearch");
        var list = document.getElementById("colResults");
        var sform = document.getElementById("colSearchForm");
        if (!input || !list) return;
        var timer = null, seq = 0;

        function render(tracks) {
            list.textContent = "";
            if (!tracks.length) {
                var li = document.createElement("li");
                li.textContent = D.i18n.noResults;
                list.appendChild(li);
                return;
            }
            tracks.forEach(function (t) {
                var li = document.createElement("li");
                var a = document.createElement("a");
                a.href = "detail.php?id=" + encodeURIComponent(t.id);
                a.textContent = t.name + (t.km ? " · " + t.km.toLocaleString() + " km" : "");
                li.appendChild(a);
                if (t.member) {
                    var s = document.createElement("span");
                    s.className = "opacity-60 whitespace-nowrap";
                    s.textContent = "✓ " + D.i18n.added;
                    li.appendChild(s);
                } else {
                    var b = document.createElement("button");
                    b.type = "button";
                    b.className = "col-icon-btn whitespace-nowrap";
                    b.textContent = "+ " + D.i18n.add;
                    b.addEventListener("click", function () {
                        b.disabled = true;
                        post("api/collections/tracks.php", { collection_id: D.id, action: "add", track_ids: [t.id] }, D.csrf)
                            .then(function (d) {
                                if (d && d.ok) { b.replaceWith(document.createTextNode("✓ " + D.i18n.added)); showRefresh(); }
                                else { b.disabled = false; alert(D.i18n.error); }
                            });
                    });
                    li.appendChild(b);
                }
                list.appendChild(li);
            });
        }

        function search() {
            var q = input.value.trim();
            var my = ++seq;
            if (!q) { list.textContent = ""; return; }
            fetch("api/collections/search.php?collection_id=" + encodeURIComponent(D.id) + "&q=" + encodeURIComponent(q),
                  { credentials: "same-origin" })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (my === seq && d && d.ok) render(d.tracks); })
                .catch(function () {});
        }

        input.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(search, 300); });
        if (sform) sform.addEventListener("submit", function (e) { e.preventDefault(); search(); });
        // After adding, offer one button to refresh table and map (search results stay until then)
        function showRefresh() {
            if (document.getElementById("colRefresh")) return;
            var b = document.createElement("button");
            b.type = "button";
            b.id = "colRefresh";
            b.className = "btn-outdoor btn-outdoor-primary mt-3";
            b.textContent = D.i18n.refresh;
            b.addEventListener("click", function () { location.reload(); });
            list.parentNode.appendChild(b);
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        if (window.gpxCollection) initDetail(window.gpxCollection);
        else if (window.gpxCollections) initList(window.gpxCollections);
    });
})();
