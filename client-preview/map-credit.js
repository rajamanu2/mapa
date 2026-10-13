// Keep credit visible on native map views, including the legacy map renderer.
(() => {
  const creditMap = () => {
    for (const map of document.querySelectorAll('.leaflet-container')) {
      if (!map.querySelector('img[src^="https://tile.openstreetmap.org/"]')) continue;
      const fallback = map.querySelector('[data-mapa-map-credit]');
      // Prefer Leaflet's credited control; only legacy renderers need a fallback.
      if (map.querySelector('.leaflet-control-attribution a[href="https://www.openstreetmap.org/copyright"]')) {
        if (fallback) fallback.remove();
        continue;
      }
      if (fallback) continue;
      const credit = document.createElement('a');
      credit.dataset.mapaMapCredit = 'true';
      credit.href = 'https://www.openstreetmap.org/copyright';
      credit.target = '_blank';
      credit.rel = 'noopener noreferrer';
      credit.textContent = '© OpenStreetMap contributors';
      credit.style.cssText = 'position:absolute;bottom:12px;left:10px;z-index:700;padding:4px 7px;background:rgba(255,255,255,.95);color:#16404b;border-radius:4px;font:12px/1.4 sans-serif;';
      map.appendChild(credit);
    }
  };
  new MutationObserver(creditMap).observe(document.body, { childList: true, subtree: true });
  creditMap();
})();
