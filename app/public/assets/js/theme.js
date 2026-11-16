// Loaded in <head>, before the page paints, so it never flashes the wrong
// theme. "system" follows the OS; app.js has the toggle.
(function () {
    'use strict';
    var pref = 'system';
    try { pref = localStorage.getItem('jp-theme') || 'system'; } catch (e) { /* storage blocked */ }
    var dark = pref === 'dark' || (pref !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme-pref', pref);
    document.documentElement.classList.add('js'); // .js-hide: hidden only when scripts run
})();
