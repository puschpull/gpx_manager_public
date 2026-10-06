/**
 * Administrace — nová (výchozí) verze (admin.php).
 *  - řazení položek šipkami (menu, vrstvy map),
 *  - lišta „Máte neuložené změny" + varování při odchodu ze stránky,
 *  - zvýraznění aktuální sekce v navigaci.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-admin-settings]');

    /* ----- řazení ----- */
    function refreshMoveButtons(list) {
        var items = list.children;
        for (var i = 0; i < items.length; i++) {
            var up = items[i].querySelector('[data-move="up"]');
            var down = items[i].querySelector('[data-move="down"]');
            if (up) up.disabled = i === 0;
            if (down) down.disabled = i === items.length - 1;
        }
    }

    document.querySelectorAll('[data-orderable]').forEach(function (list) {
        refreshMoveButtons(list);
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-move]');
            if (!btn || btn.disabled) return;
            var li = btn.closest('li');
            if (btn.getAttribute('data-move') === 'up' && li.previousElementSibling) {
                list.insertBefore(li, li.previousElementSibling);
            } else if (btn.getAttribute('data-move') === 'down' && li.nextElementSibling) {
                list.insertBefore(li.nextElementSibling, li);
            }
            refreshMoveButtons(list);
            li.classList.add('is-moved');
            // Fokus zůstane na stejném tlačítku, pokud ještě jde použít; jinak na protějším
            var target = btn.disabled ? li.querySelector('[data-move]:not(:disabled)') : btn;
            if (target) target.focus();
            updateDirty();
        });
    });

    /* ----- neuložené změny ----- */
    function snapshot() {
        if (!form) return '';
        var parts = [];
        new FormData(form).forEach(function (v, k) {
            if (k !== '_csrf_token') parts.push(k + '=' + v);
        });
        return parts.join('&');
    }

    var initial = snapshot();
    var dirty = false;
    var bar = document.querySelector('[data-savebar]');

    function updateDirty() {
        if (!form || !bar) return;
        dirty = snapshot() !== initial;
        bar.classList.toggle('is-dirty', dirty);
        bar.querySelector('[data-state-clean]').hidden = dirty;
        bar.querySelector('[data-state-dirty]').hidden = !dirty;
        bar.querySelector('[data-discard]').hidden = !dirty;
    }

    if (form && bar) {
        form.addEventListener('change', updateDirty);
        form.addEventListener('submit', function () { dirty = false; });
        bar.querySelector('[data-discard]').addEventListener('click', function () {
            dirty = false;
            window.location.reload();
        });
        window.addEventListener('beforeunload', function (e) {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = '';
        });
    }

    /* ----- aktuální sekce v navigaci ----- */
    var links = document.querySelectorAll('.an-nav a[data-section]');
    if (!links.length || !('IntersectionObserver' in window)) return;

    var byId = {};
    links.forEach(function (a) { byId[a.getAttribute('data-section')] = a; });

    function activate(id) {
        links.forEach(function (a) {
            var on = a === byId[id];
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'location'); else a.removeAttribute('aria-current');
        });
        // Na mobilu je navigace vodorovná — aktivní položku posunout do zorného pole
        var active = byId[id];
        var nav = active && active.parentElement;
        if (nav && nav.scrollWidth > nav.clientWidth) {
            nav.scrollLeft = active.offsetLeft - (nav.clientWidth - active.offsetWidth) / 2;
        }
    }

    var visible = {};
    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { visible[en.target.id] = en.isIntersecting; });
        // První viditelná sekce v pořadí dokumentu
        for (var id in byId) {
            if (visible[id]) { activate(id); return; }
        }
    }, { rootMargin: '-30% 0px -60% 0px' });

    Object.keys(byId).forEach(function (id) {
        var el = document.getElementById(id);
        if (el) observer.observe(el);
    });
    activate(window.location.hash.slice(1) in byId ? window.location.hash.slice(1) : 'prehled');
})();
