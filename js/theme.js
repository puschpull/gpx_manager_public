/**
 * ===========================================================
 *  GPX Manager – Theme Switcher (v2)
 *  Přepínání vzhledů s perzistencí v localStorage + fallback do cookie
 *  Autor: ChatGPT + [Tvé jméno]
 * ===========================================================
 */

console.log("🎨 theme.js v2 načten");

document.addEventListener("DOMContentLoaded", function () {
  const THEME_KEY = "theme";
  const DEFAULT_THEME = "classic";
  const THEME_PATH = "css/theme-";
  const LINK_ID = "theme-style";

  /* ===== Pomocné funkce ===== */

  // Získá téma z localStorage nebo cookie
  function getSavedTheme() {
    let theme = localStorage.getItem(THEME_KEY);
    if (!theme) {
      const match = document.cookie.match(new RegExp("(^| )" + THEME_KEY + "=([^;]+)"));
      theme = match ? match[2] : null;
    }
    return theme || DEFAULT_THEME;
  }

  // Uloží téma do localStorage i cookie (pro jistotu)
  function saveTheme(theme) {
    localStorage.setItem(THEME_KEY, theme);
    const d = new Date();
    d.setFullYear(d.getFullYear() + 1);
    document.cookie = `${THEME_KEY}=${theme}; expires=${d.toUTCString()}; path=/`;
  }

  // Aplikuje CSS soubor podle zvoleného tématu
  function applyTheme(theme) {
    let link = document.getElementById(LINK_ID);
    if (!link) {
      link = document.createElement("link");
      link.rel = "stylesheet";
      link.id = LINK_ID;
      document.head.appendChild(link);
    }

    const newHref = `${THEME_PATH}${theme}.css`;
    if (link.href.endsWith(newHref)) return; // už načteno

    link.href = newHref;
    document.documentElement.setAttribute("data-theme", theme);
	// Odeber jen theme-* třídy, ale zachovej hide-col-xxx!
	const toRemove = [];
	document.body.classList.forEach(cls => {
		if (cls.startsWith("theme-")) toRemove.push(cls);
	});
	document.body.classList.remove(...toRemove);

	// Přidej aktuální tému
	document.body.classList.add(`theme-${theme}`);

    console.log(`✅ Aplikováno téma: ${theme}`);
  }

  /* ===== Inicializace ===== */

  const currentTheme = getSavedTheme();
  applyTheme(currentTheme);

  /* ===== Přepínač v <select> ===== */
  const selector = document.querySelector("select[name='theme'], #theme-selector");
  if (selector) {
    selector.value = currentTheme;
    selector.addEventListener("change", function () {
      const newTheme = this.value;
      saveTheme(newTheme);
      applyTheme(newTheme);
    });
  }

  /* ===== Alternativní přepínače (tlačítka data-theme) ===== */
  document.querySelectorAll("[data-theme]").forEach((btn) => {
    btn.addEventListener("click", function () {
      const newTheme = this.getAttribute("data-theme");
      saveTheme(newTheme);
      applyTheme(newTheme);
      if (selector) selector.value = newTheme;
    });
  });

  /* ===== Auto-sync při návratu na stránku ===== */
  window.addEventListener("focus", () => {
    const themeNow = getSavedTheme();
    if (document.documentElement.getAttribute("data-theme") !== themeNow) {
      applyTheme(themeNow);
      if (selector) selector.value = themeNow;
    }
  });
});
