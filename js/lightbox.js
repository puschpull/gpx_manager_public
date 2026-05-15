/**
 * GPX Manager — PhotoLightbox
 * Fullscreen overlay viewer — keyboard (← → Esc), touch swipe, counter
 * A11Y: focus trap, return-focus, role=dialog, aria-modal, aria-label (A11Y-012, A11Y-013)
 */
class PhotoLightbox {
    constructor() {
        this._photos      = [];
        this._index       = 0;
        this._overlay     = null;
        this._img         = null;
        this._counter     = null;
        this._caption     = null;
        this._touchStartX = 0;
        this._triggerEl   = null; // A11Y-012: element that opened the lightbox
        this._build();
        this._bindKeys();
    }

    _build() {
        const o = document.createElement('div');
        o.className = 'lightbox-overlay';
        o.setAttribute('role', 'dialog');
        o.setAttribute('aria-modal', 'true');
        // aria-label is set dynamically in _render()
        o.innerHTML = `
            <button class="lightbox-close" title="Zavřít (ESC)" aria-label="Zavřít">✕</button>
            <span class="lightbox-counter" aria-live="polite" aria-atomic="true"></span>
            <button class="lightbox-nav lightbox-prev" title="Předchozí (←)" aria-label="Předchozí fotografie">&#8249;</button>
            <div class="lightbox-content">
                <img class="lightbox-img" src="" alt="">
                <div class="lightbox-caption"></div>
            </div>
            <button class="lightbox-nav lightbox-next" title="Další (→)" aria-label="Další fotografie">&#8250;</button>
        `;
        document.body.appendChild(o);

        this._overlay = o;
        this._img     = o.querySelector('.lightbox-img');
        this._counter = o.querySelector('.lightbox-counter');
        this._caption = o.querySelector('.lightbox-caption');

        o.querySelector('.lightbox-close').addEventListener('click', () => this.close());
        o.querySelector('.lightbox-prev').addEventListener('click', (e) => { e.stopPropagation(); this.prev(); });
        o.querySelector('.lightbox-next').addEventListener('click', (e) => { e.stopPropagation(); this.next(); });

        // Click on backdrop → close
        o.addEventListener('click', (e) => { if (e.target === o) this.close(); });

        // Touch swipe
        o.addEventListener('touchstart', (e) => {
            this._touchStartX = e.touches[0].clientX;
        }, { passive: true });
        o.addEventListener('touchend', (e) => {
            const dx = e.changedTouches[0].clientX - this._touchStartX;
            if (Math.abs(dx) > 50) { dx < 0 ? this.next() : this.prev(); }
        });
    }

    /**
     * A11Y-012: focus trap — cycles Tab/Shift+Tab within the overlay.
     * @param {KeyboardEvent} e
     */
    _trapFocus(e) {
        const focusable = Array.from(
            this._overlay.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )
        ).filter(el => el.offsetParent !== null); // visible only
        if (!focusable.length) return;

        const first = focusable[0];
        const last  = focusable[focusable.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    _bindKeys() {
        document.addEventListener('keydown', (e) => {
            if (!this._overlay || !this._overlay.classList.contains('open')) return;
            if (e.key === 'ArrowLeft')  { e.preventDefault(); this.prev(); }
            if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
            if (e.key === 'Escape')     { e.preventDefault(); this.close(); }
            if (e.key === 'Tab')        { this._trapFocus(e); }
        });
    }

    /**
     * Open lightbox
     * @param {Array}   photos      - [{full_url, alt, caption, filename, taken_at}]
     * @param {number}  index       - starting index
     * @param {Element} [trigger]   - A11Y-012: DOM element that triggered open (for return-focus)
     */
    open(photos, index, trigger) {
        this._photos    = photos;
        this._index     = Math.max(0, Math.min(index, photos.length - 1));
        this._triggerEl = trigger || document.activeElement || null;
        this._render();
        this._overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        // A11Y-012: move focus to close button after open
        const closeBtn = this._overlay.querySelector('.lightbox-close');
        if (closeBtn) { closeBtn.focus(); }
    }

    _render() {
        const p     = this._photos[this._index];
        const total = this._photos.length;

        // A11Y-013: alt text — prefer caption, then alt/filename, then generic fallback
        const photoNumber = this._index + 1;
        const captionText = p.caption || p.alt || p.filename || ('Fotografie ' + photoNumber);
        const altText     = captionText;

        // Show spinner while loading
        this._img.style.opacity = '0';
        this._img.onload = () => { this._img.style.opacity = '1'; };
        this._img.src = p.full_url || p.src || '';
        this._img.alt = altText;

        // Counter
        this._counter.textContent = total > 1 ? `${photoNumber} / ${total}` : '';

        // Caption: taken_at → caption → alt
        const cap = p.taken_at
            ? p.taken_at.substring(0, 16).replace('T', ' ')
            : (p.caption || p.alt || '');
        this._caption.textContent = cap;
        this._caption.style.display = cap ? 'block' : 'none';

        // A11Y-012: update dialog aria-label to describe current photo
        const dialogLabel = total > 1
            ? `Fotografie ${photoNumber} z ${total} — ${captionText}`
            : captionText;
        this._overlay.setAttribute('aria-label', dialogLabel);

        // Show/hide nav
        const prevBtn = this._overlay.querySelector('.lightbox-prev');
        const nextBtn = this._overlay.querySelector('.lightbox-next');
        const show    = total > 1 ? 'flex' : 'none';
        prevBtn.style.display = show;
        nextBtn.style.display = show;
    }

    prev() {
        if (this._photos.length < 2) return;
        this._index = (this._index - 1 + this._photos.length) % this._photos.length;
        this._render();
    }

    next() {
        if (this._photos.length < 2) return;
        this._index = (this._index + 1) % this._photos.length;
        this._render();
    }

    close() {
        this._overlay.classList.remove('open');
        document.body.style.overflow = '';
        // A11Y-012: return focus to the element that opened the lightbox
        if (this._triggerEl && typeof this._triggerEl.focus === 'function') {
            this._triggerEl.focus();
        }
        this._triggerEl = null;
        // Clear src to stop loading
        setTimeout(() => { if (!this._overlay.classList.contains('open')) this._img.src = ''; }, 300);
    }
}

// Auto-init — runs after DOM is parsed (script is loaded with defer)
(function () {
    function init() {
        if (!window.gpxLightbox) {
            window.gpxLightbox = new PhotoLightbox();
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
