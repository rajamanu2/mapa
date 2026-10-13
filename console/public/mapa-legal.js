/* Copyright (c) 2026 MAPA.DEV. SPDX-License-Identifier: AGPL-3.0-or-later */
(() => {
    'use strict';
    if (document.documentElement.dataset.mapaLegalInstalled) return;
    document.documentElement.dataset.mapaLegalInstalled = 'true';
    let dialog;
    let opener;

    function openLegal(trigger) {
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'mapa-legal-dialog';
            dialog.setAttribute('aria-labelledby', 'mapa-legal-title');
            dialog.innerHTML = `
                <header class="mapa-legal-header">
                    <h2 id="mapa-legal-title">Legal information</h2>
                    <button type="button" class="mapa-legal-close" aria-label="Close legal window">×</button>
                </header>
                <div class="mapa-legal-content">
                    <div class="mapa-legal-identity">
                        <span class="mapa-legal-logo" aria-hidden="true">MAPA</span>
                        <div><h3>MAPA</h3><p>Ambulance operations &amp; connected response</p></div>
                    </div>
                    <section class="mapa-legal-product">
                        <h3>Copyright © 2026 MAPA.DEV</h3>
                        <p>Original MAPA interface, branding and integration code.</p>
                        <p>Open-source components retain their original copyrights and licences.</p>
                    </section>
                    <section>
                        <h3>Privacy &amp; service information</h3>
                        <div class="mapa-legal-links">
                            <a href="/privacy/">Privacy policy</a>
                            <a href="/terms/">Terms and conditions</a>
                            <a href="/data-rights/">Data rights &amp; GDPR</a>
                        </div>
                    </section>
                    <section>
                        <h3>Licence &amp; warranty</h3>
                        <p>This open-source edition is provided under the GNU Affero General Public License, version 3 or later. You may copy, modify and redistribute it under that licence.</p>
                        <p>The software is provided without warranty, including any implied warranty of merchantability or fitness for a particular purpose.</p>
                        <div class="mapa-legal-links">
                            <a href="/mapa-license.txt" target="_blank" rel="noopener noreferrer">Read the licence</a>
                            <a href="/mapa-notices.html" target="_blank" rel="noopener noreferrer">Software notices &amp; source licence</a>
                        </div>
                    </section>
                </div>
                <footer class="mapa-legal-footer"><button type="button" class="mapa-legal-done">Done</button></footer>`;
            document.body.append(dialog);
            dialog.querySelector('.mapa-legal-close').addEventListener('click', () => dialog.close());
            dialog.querySelector('.mapa-legal-done').addEventListener('click', () => dialog.close());
            dialog.addEventListener('close', () => {
                if (opener?.isConnected) opener.focus();
            });
        }
        if (dialog.open) return;
        opener = trigger || document.activeElement;
        dialog.querySelector('.mapa-legal-content').scrollTop = 0;
        dialog.showModal();
    }

    // This selector belongs to MAPA's footer, not an upstream DOM implementation.
    // Capture also supports the already-built console while new builds use the event.
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('.mapa-attribution button') : null;
        if (!button) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        openLegal(button);
    }, true);
    window.addEventListener('mapa:open-legal', (event) => {
        event.preventDefault();
        openLegal(document.activeElement);
    });
})();
