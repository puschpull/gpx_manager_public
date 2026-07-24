if (window.GPX_DEBUG) console.log('📱 mobile-init.js spuštěn');

function mobileInit() {
    const sidebar = document.getElementById('indexSidebar');
    const toggle  = document.getElementById('sidebarToggle');

    if (!sidebar || !toggle) return;
    if (!window.matchMedia('(max-width: 900px)').matches) return;

    // ---- Inicializace sidebar inline styly ----
    sidebar.style.display                = 'none';
    sidebar.style.position               = 'fixed';
    sidebar.style.top                    = '0';
    sidebar.style.left                   = '0';
    sidebar.style.right                  = '0';
    sidebar.style.bottom                 = '0';
    sidebar.style.zIndex                 = '1002';
    sidebar.style.width                  = '100%';
    sidebar.style.height                 = '100%';
    sidebar.style.overflowY              = 'scroll';
    sidebar.style.webkitOverflowScrolling = 'touch';
    sidebar.style.boxSizing              = 'border-box';
    sidebar.style.padding                = '60px 14px 80px';
    sidebar.style.pointerEvents          = 'none';

    // ---- Backdrop ----
    const bd = document.createElement('div');
    bd.id = 'mobileOverlayBackdrop';
    bd.className = 'mobile-overlay-backdrop';
    bd.style.cssText = 'display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:1001; background:rgba(0,0,0,0.55);';
    document.body.appendChild(bd);

    // ---- Tlačítko Zavřít ----
    const closeBtn = document.createElement('button');
    closeBtn.className = 'mobile-filter-close';
    closeBtn.type = 'button';
    closeBtn.textContent = '✕ Zavřít filtry';
    closeBtn.style.cssText = 'display:none; position:fixed; bottom:12px; right:12px; z-index:1003; padding:10px 18px; font-size:15px; min-height:44px; background:var(--accent-color); color:#fff; border:none; border-radius:8px; cursor:pointer;';
    document.body.appendChild(closeBtn);

    // ---- Stav ----
    let isOpen = false;

    // ---- Otevřít ----
    function open() {
        isOpen = true;
        const bgColor = getComputedStyle(document.documentElement)
            .getPropertyValue('--bg-color').trim() || '#fff';
        sidebar.style.background   = bgColor;
        sidebar.style.opacity      = '0';
        sidebar.style.transform    = 'translateY(30px)';
        sidebar.style.display      = 'block';
        sidebar.style.pointerEvents = 'auto';
        bd.style.display           = 'block';
        closeBtn.style.display     = 'flex';
        toggle.setAttribute('aria-expanded', 'true');
        // Reflow → spustí transition
        sidebar.getBoundingClientRect();
        sidebar.style.transition   = 'opacity 0.22s ease, transform 0.22s ease';
        sidebar.style.opacity      = '1';
        sidebar.style.transform    = 'translateY(0)';
    }

    // ---- Zavřít ----
    function close() {
        isOpen = false;
        sidebar.style.transition   = 'opacity 0.22s ease, transform 0.22s ease';
        sidebar.style.opacity      = '0';
        sidebar.style.transform    = 'translateY(30px)';
        bd.style.display           = 'none';
        closeBtn.style.display     = 'none';
        toggle.setAttribute('aria-expanded', 'false');
        setTimeout(() => {
            sidebar.style.display      = 'none';
            sidebar.style.pointerEvents = 'none';
        }, 230);
    }

    // ---- Event listeners ----
    toggle.addEventListener('click', function () {
        isOpen ? close() : open();
    });

    bd.addEventListener('click', close);
    closeBtn.addEventListener('click', close);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) close();
    });

    const filterForm = document.getElementById('filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', close);
    }

    window.matchMedia('(max-width: 900px)').addEventListener('change', function (e) {
        if (!e.matches) close();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mobileInit);
} else {
    mobileInit();
}