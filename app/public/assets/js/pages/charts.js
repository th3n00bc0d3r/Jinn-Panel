// Charts (src/Support/Chart.php) - the hover layer: a crosshair and one
// tooltip listing every series at the pointer (lines), or the hovered
// column's parts and total (columns). Arrow keys, Home and End do the same
// when the chart has focus. A chart is complete without this file; its
// values are also in its "Show as table" view.
//
// Range links (Chart::ranges) inside a [data-chart-region="name"] swap only
// that region: the page is fetched at the link's URL and the region with the
// same name replaces this one. Without JavaScript they are plain links.
// [data-live] regions refresh themselves every 30 seconds (see the end).
(function () {
    'use strict';

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    function init(root) {
        if (root.hasAttribute('data-chart-ready')) return;
        root.setAttribute('data-chart-ready', '');
        var data;
        try { data = JSON.parse(root.getAttribute('data-chart')); } catch (e) { return; }
        var plot = root.querySelector('[data-chart-plot]');
        if (!plot || !data.labels || !data.labels.length) return;
        var n = data.labels.length;
        var cols = data.kind === 'columns';
        var cross = null;
        if (!cols) {
            cross = el('div', 'chart-cross');
            cross.hidden = true;
            plot.appendChild(cross);
        }
        // Lines: a dot on each line where the crosshair is.
        var dots = cols ? [] : data.series.map(function (s) {
            var d = el('span', 'pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-surface ' + s.series);
            d.hidden = true;
            plot.appendChild(d);
            return d;
        });
        var tip = el('div', 'chart-tip');
        tip.hidden = true;
        tip.setAttribute('role', 'status');
        root.appendChild(tip);
        var current = -1;

        function row(name, value, key) {
            var r = el('div', 'chart-tip-row');
            var l = el('span', 'flex items-center gap-2 text-muted');
            if (key) l.appendChild(el('span', key));
            l.appendChild(document.createTextNode(name));
            r.appendChild(l);
            r.appendChild(el('strong', 'font-semibold tabular-nums text-fg', value));
            return r;
        }

        function show(i) {
            i = Math.max(0, Math.min(n - 1, i));
            current = i;
            tip.textContent = '';
            tip.appendChild(el('div', 'mb-1 text-[12px] text-subtle', data.labels[i]));
            // Stacks read top part first, as drawn; lines in series order.
            var list = cols ? data.series.slice().reverse() : data.series;
            list.forEach(function (s) {
                tip.appendChild(row(s.name, s.values[i], (cols ? 'chart-swatch ' : 'chart-key ') + s.series));
            });
            if (cols && data.totals) {
                var t = row('Total', data.totals[i], '');
                t.classList.add('mt-1', 'border-t', 'border-line', 'pt-1');
                tip.appendChild(t);
            }
            var pr = plot.getBoundingClientRect();
            var rr = root.getBoundingClientRect();
            var x = cols ? (i + 0.5) / n : (n > 1 ? i / (n - 1) : 0.5);
            var px = pr.left - rr.left + x * pr.width;
            if (cross) {
                cross.hidden = false;
                cross.style.left = (x * 100) + '%';
            }
            dots.forEach(function (d, k) {
                var y = data.series[k].y ? data.series[k].y[i] : null;
                d.hidden = y === null || y === undefined;
                d.style.left = (x * 100) + '%';
                d.style.top = y + '%';
            });
            root.querySelectorAll('.chart-col[data-active]').forEach(function (c) { c.removeAttribute('data-active'); });
            var col = cols ? root.querySelector('.chart-col[data-i="' + i + '"]') : null;
            if (col) col.setAttribute('data-active', '');
            tip.hidden = false;
            var w = tip.offsetWidth;
            var left = px + 12;
            if (left + w > rr.width) left = px - w - 12;
            tip.style.left = Math.max(0, left) + 'px';
            tip.style.top = '4px';
        }
        function hide() {
            tip.hidden = true;
            if (cross) cross.hidden = true;
            dots.forEach(function (d) { d.hidden = true; });
            root.querySelectorAll('.chart-col[data-active]').forEach(function (c) { c.removeAttribute('data-active'); });
            current = -1;
        }
        function indexAt(clientX) {
            var pr = plot.getBoundingClientRect();
            var f = (clientX - pr.left) / pr.width;
            return cols ? Math.floor(f * n) : Math.round(f * (n - 1));
        }
        root.addEventListener('pointermove', function (e) {
            var pr = plot.getBoundingClientRect();
            if (e.clientX < pr.left || e.clientX > pr.right) { hide(); return; }
            show(indexAt(e.clientX));
        });
        root.addEventListener('pointerleave', hide);
        root.addEventListener('blur', hide);
        root.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                e.preventDefault();
                var step = e.key === 'ArrowRight' ? 1 : -1;
                show(current < 0 ? (step > 0 ? 0 : n - 1) : current + step);
            } else if (e.key === 'Home') { e.preventDefault(); show(0); }
            else if (e.key === 'End') { e.preventDefault(); show(n - 1); }
            else if (e.key === 'Escape') { hide(); }
        });
    }

    function initAll(scope) {
        (scope || document).querySelectorAll('[data-chart]').forEach(init);
    }

    // Swap one region for the same region of another URL (ranges, live refresh).
    function swap(region, url, opts) {
        var name = region.getAttribute('data-chart-region') || region.getAttribute('data-live');
        var attr = region.hasAttribute('data-chart-region') ? 'data-chart-region' : 'data-live';
        region.setAttribute('aria-busy', 'true');
        return fetch(url, { headers: { 'X-JP-Poll': '1' }, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('[' + attr + '="' + name + '"]');
                if (!fresh) throw new Error('region missing');
                var active = document.activeElement;
                var keep = active && region.contains(active) && active.getAttribute('href');
                region.innerHTML = fresh.innerHTML;
                initAll(region);
                if (keep) {
                    var again = region.querySelector('[href="' + keep.replace(/"/g, '\\"') + '"]');
                    if (again) again.focus();
                }
                if (opts && opts.push) history.replaceState(null, '', url);
            })
            .finally(function () { region.removeAttribute('aria-busy'); });
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[data-chart-range]');
        if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        var region = a.closest('[data-chart-region]');
        if (!region) return;
        e.preventDefault();
        swap(region, a.href, { push: true }).catch(function () { location.href = a.href; });
    });

    // Dashboards: every [data-live="name"] region reloads from the same URL
    // every 30 seconds while the tab is visible and nobody is using it. The
    // request says X-JP-Live, so it doesn't keep an idle session signed in.
    var live = document.querySelectorAll('[data-live]');
    if (live.length) {
        var timer = setInterval(function () {
            if (document.hidden) return;
            var regions = document.querySelectorAll('[data-live]');
            var busy = Array.prototype.some.call(regions, function (r) {
                return r.contains(document.activeElement) || r.hasAttribute('aria-busy') || r.querySelector('.chart-tip:not([hidden]), details[open]');
            });
            if (busy) return;
            fetch(location.href, { headers: { 'X-JP-Poll': '1', 'X-JP-Live': '1' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) {
                    if (!r.ok || r.redirected) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    document.querySelectorAll('[data-live]').forEach(function (region) {
                        var fresh = doc.querySelector('[data-live="' + region.getAttribute('data-live') + '"]');
                        if (fresh && fresh.innerHTML !== region.innerHTML) {
                            region.innerHTML = fresh.innerHTML;
                            initAll(region);
                        }
                    });
                })
                .catch(function () { clearInterval(timer); }); // signed out or offline: stop
        }, 30000);
    }

    window.JPCharts = { init: initAll, swap: swap };
    initAll(document);
})();
