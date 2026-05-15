/**
 * lang-switcher.js — Přepínač jazyka pro GPX Manager
 * Nastaví cookie app_lang a přenačte stránku
 */
(function () {
    'use strict';

    const LANG_TO_FLAG = { cs:'cz', en:'gb', de:'de', sk:'sk', es:'es', fr:'fr', pl:'pl', it:'it' };

    function getCurrentLang() {
        const m = document.cookie.match(/(?:^|;\s*)app_lang=([^;]+)/);
        return m ? m[1] : 'cs';
    }

    function setLang(lang) {
        const d = new Date();
        d.setFullYear(d.getFullYear() + 1);
        document.cookie = 'app_lang=' + lang + '; expires=' + d.toUTCString() + '; path=/';
        location.reload();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sel  = document.getElementById('lang-selector');
        var flag = document.getElementById('lang-flag');
        if (!sel) return;

        var cur = getCurrentLang();
        sel.value = cur;
        // Po přenačtení stránky PHP již vykreslí správný obrázek,
        // ale kdyby byl flag přítomen dřív (bez reload), aktualizujeme src
        if (flag && flag.tagName === 'IMG') {
            var code = LANG_TO_FLAG[cur] || 'cz';
            flag.src = 'lang/flags/' + code + '.png';
        }

        sel.addEventListener('change', function () {
            setLang(this.value);
        });
    });
})();
