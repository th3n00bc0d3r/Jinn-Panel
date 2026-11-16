/*
 * WHM > cPanel Migration > Migration #n (step 3): live progress. Polls
 * GET /whm/migrations/{id}/status every 3 s while the migration runs and
 * updates the badges, bars, steps, errors and the runner log in place;
 * reloads the page when an account or the whole migration finishes (to
 * show the report). Settings come from <script type="application/json"
 * id="migration-status"> (only present while the migration isn't finished).
 */
(function () {
    'use strict';

    var cfgEl = document.getElementById('migration-status');
    if (!cfgEl) return;
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
    var url = cfg.url;
    var badges = cfg.badges || {};
    var logEl = document.querySelector('[data-log]');
    var done = ['completed', 'completed_with_errors', 'failed', 'cancelled'];

    function setBadge(el, key) {
        if (!el) return;
        if (badges[key]) el.innerHTML = badges[key];
        else el.textContent = key;
    }

    // Ui::progress(): a .progress track (role=progressbar) holding a .progress-bar.
    function setBar(wrap, pct) {
        if (!wrap) return;
        pct = Math.max(0, Math.min(100, pct));
        var track = wrap.querySelector('[role="progressbar"]');
        var bar = wrap.querySelector('.progress-bar');
        if (bar) bar.style.width = pct + '%';
        if (track) track.setAttribute('aria-valuenow', String(Math.round(pct)));
    }

    function tick() {
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                var reload = d.finished;
                setBadge(document.querySelector('[data-migration-badge]'), d.stalled ? 'stalled' : d.status);
                if (d.status !== 'queued') {
                    var q = document.querySelector('[data-queued-note]');
                    if (q) q.remove();
                }
                var sum = 0, finished = 0;
                d.items.forEach(function (it) {
                    var isDone = done.indexOf(it.status) !== -1;
                    sum += isDone ? 100 : Math.max(0, Math.min(100, it.progress));
                    if (it.status === 'completed' || it.status === 'completed_with_errors') finished++;
                    var card = document.querySelector('[data-item="' + it.id + '"]');
                    if (!card) return;
                    if (card.dataset.itemStatus !== it.status && isDone) reload = true;
                    setBadge(card.querySelector('[data-item-badge]'), it.status);
                    setBar(card.querySelector('[data-progress]'), it.progress);
                    card.querySelector('[data-step]').textContent = it.step;
                    var err = card.querySelector('[data-error]');
                    err.textContent = it.error || '';
                    err.hidden = !it.error;
                });
                if (d.items.length) {
                    setBar(document.querySelector('[data-overall]'), sum / d.items.length);
                    var count = document.querySelector('[data-overall-done]');
                    if (count) count.textContent = finished;
                }
                if (logEl && d.log) {
                    var atBottom = logEl.scrollTop + logEl.clientHeight >= logEl.scrollHeight - 20;
                    logEl.textContent = d.log;
                    if (atBottom) logEl.scrollTop = logEl.scrollHeight;
                }
                if (reload) { window.location.reload(); return; }
                setTimeout(tick, 3000);
            })
            .catch(function () { setTimeout(tick, 6000); });
    }

    if (logEl) logEl.scrollTop = logEl.scrollHeight;
    setTimeout(tick, 2000);
})();
