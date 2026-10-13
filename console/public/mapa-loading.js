/* MAPA.DEV — decorative startup motion; native app readiness always wins. */
(() => {
    'use strict';
    const splash = document.getElementById('boot-loader');
    if (!splash?.classList.contains('mapa-boot') || splash.dataset.mapaLoadingInstalled) return;
    splash.dataset.mapaLoadingInstalled = 'true';

    let observer;
    let slowTimer;
    let failSafeTimer;
    let cleaned = false;
    const motion = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    function updateMotion() {
        splash.dataset.mapaReducedMotion = String(Boolean(motion?.matches));
    }

    function finish() {
        if (cleaned) return;
        cleaned = true;
        clearTimeout(slowTimer);
        clearTimeout(failSafeTimer);
        observer?.disconnect();
        motion?.removeEventListener?.('change', updateMotion);
        window.removeEventListener('error', finish);
        window.removeEventListener('unhandledrejection', finish);
        window.removeEventListener('pagehide', finish);
        // No fade delay, forced focus, body scroll lock or interception of forms.
        splash.remove();
    }

    function ready() {
        if (!splash.isConnected) return finish();
        // Ember's native removeBootLoader remains authoritative. These fallbacks
        // remove the splash as soon as a usable screen or a real error renders.
        const candidates = document.querySelectorAll(
            'form input:not([type="hidden"]), form button, main, [role="main"], '
            + '.console-content, [role="alert"]:not(:empty), .ember-notify:not(:empty)'
        );
        if (Array.from(candidates).some((element) => !splash.contains(element)
            && element.getClientRects().length > 0)) finish();
    }

    updateMotion();
    motion?.addEventListener?.('change', updateMotion);
    observer = new MutationObserver(ready);
    observer.observe(document.body, { childList: true, subtree: true });
    window.addEventListener('error', finish);
    window.addEventListener('unhandledrejection', finish);
    window.addEventListener('pagehide', finish);
    slowTimer = setTimeout(() => {
        const message = splash.querySelector('.mapa-boot-message');
        if (message) message.textContent = 'ASR Health is taking a little longer to open…';
    }, 6500);
    // A failed bootstrap must never leave a decorative overlay covering recovery.
    failSafeTimer = setTimeout(finish, 15000);
    ready();
})();
