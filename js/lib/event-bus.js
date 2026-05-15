/**
 * GPX Manager — shared event bus
 * Usage: window.GpxBus.dispatchEvent(new CustomEvent('myEvent', { detail: ... }))
 *        window.GpxBus.addEventListener('myEvent', handler)
 */
window.GpxBus = new EventTarget();
