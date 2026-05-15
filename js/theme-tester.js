/*
===========================================================
  GPX Manager – Theme Tester Panel (synchronizovaný)
  (Pro vývojáře a ladění vzhledů, nezasahuje do PHP logiky)

  GUARD: Tento soubor smí být includován POUZE takto (FE-15):
    <?php if (!empty($_SESSION['is_admin']) && defined('APP_ENV') && APP_ENV === 'local'): ?>
        <script src="js/theme-tester.js" defer></script>
    <?php endif; ?>

  JS-level guard níže slouží jako druhá pojistka pro případ,
  že se soubor ocitne na stránce bez PHP gate.
===========================================================
*/

// JS-level guard: spustí se pouze pokud stránka obsahuje admin indikátor
// a hostname je lokální (localhost / .local / 127.x / ::1)
(function () {
    var host = location.hostname;
    var isLocal = (host === 'localhost' || host === '127.0.0.1' || host === '::1' ||
                   host.indexOf('.local') !== -1 || host.indexOf('.test') !== -1);
    var isAdmin = (document.querySelector('meta[name="x-admin"]') !== null ||
                   document.documentElement.hasAttribute('data-admin'));
    if (!isLocal || !isAdmin) {
        // Nejedná se o lokální admin session — tester se nenačte
        if (typeof window !== 'undefined' && window.GPX_DEBUG) {
            console.info('[theme-tester] guard: not local+admin, skipping');
        }
        return;
    }
    initThemeTester();
})();

function initThemeTester() {

document.addEventListener("DOMContentLoaded", () => {
  const themes = ["classic", "dark", "blue", "green", "minimal"];

  // Získání aktivního tématu z PHP (atribut <html data-theme="...">)
  const fromServer = document.documentElement.getAttribute("data-theme");
  const match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
  const fromCookie = match ? decodeURIComponent(match[1]) : null;
  const currentTheme = fromServer || fromCookie || "classic";

  console.log("🎨 Theme tester aktivní – aktuální téma:", currentTheme);

  // === Vytvoření plovoucího panelu ===
  const panel = document.createElement("div");
  panel.id = "theme-tester";
  panel.innerHTML = `
    <button id="theme-toggle">🎨</button>
    <div id="theme-options" class="hidden">
      ${themes.map(t => `
        <button class="theme-btn" data-theme="${t}">${t.charAt(0).toUpperCase() + t.slice(1)}</button>
      `).join("")}
    </div>
  `;
  document.body.appendChild(panel);

  // === Stylování panelu (vloží se dynamicky) ===
  const style = document.createElement("style");
  style.textContent = `
    #theme-tester {
      position: fixed;
      bottom: 12px;
      right: 12px;
      z-index: 9999;
      font-family: sans-serif;
    }

    #theme-toggle {
      background: var(--accent-color);
      color: #fff;
      border: none;
      border-radius: 50%;
      width: 44px;
      height: 44px;
      font-size: 20px;
      cursor: pointer;
      box-shadow: 0 2px 6px rgba(0,0,0,0.2);
      transition: transform 0.2s;
    }

    #theme-toggle:hover {
      transform: scale(1.1);
    }

    #theme-options {
      position: absolute;
      bottom: 54px;
      right: 0;
      display: flex;
      flex-direction: column;
      gap: 4px;
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 6px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    #theme-options.hidden {
      display: none;
    }

    .theme-btn {
      padding: 4px 8px;
      border: 1px solid var(--border-color);
      border-radius: 4px;
      background: var(--card-bg);
      color: var(--text-color);
      font-size: 12px;
      cursor: pointer;
      text-transform: capitalize;
      text-align: left;
    }

    .theme-btn:hover {
      background: var(--accent-color);
      color: #fff;
    }

    .theme-btn.active {
      border-color: var(--accent-color);
      font-weight: bold;
    }
  `;
  document.head.appendChild(style);

  // === Logika ovládání ===
  const toggleBtn = document.getElementById("theme-toggle");
  const options = document.getElementById("theme-options");

  toggleBtn.addEventListener("click", () => {
    options.classList.toggle("hidden");
  });

  // === Zvýraznění aktuálního ===
  document.querySelectorAll(".theme-btn").forEach(btn => {
    const theme = btn.getAttribute("data-theme");
    if (theme === currentTheme) btn.classList.add("active");

    btn.addEventListener("click", e => {
      const newTheme = e.target.getAttribute("data-theme");

      // Pouze vizuální přepnutí – nemění cookie, jen přepojí CSS dočasně
      const cssHref = `css/theme-${newTheme}.css`;
      let linkEl = document.getElementById("theme-style");

      if (!linkEl) {
        linkEl = document.createElement("link");
        linkEl.id = "theme-style";
        linkEl.rel = "stylesheet";
        document.head.appendChild(linkEl);
      }

      linkEl.href = cssHref;

      console.log("🧪 Tester: přepnuto na", newTheme);

      document.querySelectorAll(".theme-btn").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");

      // Skryje panel po výběru
      options.classList.add("hidden");
    });
  });
}); // end DOMContentLoaded

} // end initThemeTester
