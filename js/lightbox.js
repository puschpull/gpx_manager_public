/**
 * GPX Manager — PhotoLightbox
 * Fullscreen overlay viewer — keyboard (← → Esc), touch swipe, counter
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
        this._build();
        this._bindKeys();
    }

    _build() {
        const o = document.createElement('div');
        o.className = 'lightbox-overlay';
        o.setAttribute('role', 'dialog');
        o.setAttribute('aria-modal', 'true');
        o.innerHTML = `
            <button class="lightbox-close" title="Zavřít (ESC)" aria-label="Zavřít">✕</button>
            <span class="lightbox-counter" aria-live="polite"></span>
            <button class="lightbox-nav lightbox-prev" title="Předchozí (←)" aria-label="Předchozí">&#8249;</button>
            <div class="lightbox-content">
                <img class="lightbox-img" src="" alt="">
                <div class="lightbox-caption"></div>
            </div>
            <button class="lightbox-nav lightbox-next" title="Další (→)" aria-label="Další">&#8250;</button>
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

    _bindKeys() {
        document.addEventListener('keydown', (e) => {
            if (!this._overlay || !this._overlay.classList.contains('open')) return;
            if (e.key === 'ArrowLeft')  { e.preventDefault(); this.prev(); }
            if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
            if (e.key === 'Escape')     { e.preventDefault(); this.close(); }
        });
    }

    /**
     * Open lightbox
     * @param {Array} photos - [{full_url, alt, taken_at}]
     * @param {number} index - starting index
     */
    open(photos, index) {
        this._photos = photos;
        this._index  = Math.max(0, Math.min(index, photos.length - 1));
        this._render();
        this._overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    _render() {
        const p     = this._photos[this._index];
        const total = this._photos.length;

        // Show spinner while loading
        this._img.style.opacity = '0';
        this._img.onload = () => { this._img.style.opacity = '1'; };
        this._img.src = p.full_url || p.src || '';
        this._img.alt = p.alt || '';

        // Counter
        this._counter.textContent = total > 1 ? `${this._index + 1} / ${total}` : '';

        // Caption: taken_at
        const cap = p.taken_at ? p.taken_at.substring(0, 16).replace('T', ' ') : (p.alt || '');
        this._caption.textContent = cap;
        this._caption.style.display = cap ? 'block' : 'none';

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
