/*
 * JinnPanel - the interaction layer shared by every page. No dependencies.
 * Page-specific code lives in assets/js/pages/<name>.js (View::script()).
 * Everything here hangs off data- attributes:
 *
 *   form[data-confirm="text"]       ask in a dialog before submitting
 *        [data-confirm-action]      label of the confirm button (default "Continue")
 *        [data-confirm-danger]      red confirm button
 *   select[data-autosubmit]         submit its form when changed
 *   [data-copy="text"]              copy to the clipboard
 *   button[data-generate-password="input-id"]
 *   [data-busy="ms"]                a job is running: re-check the page in the background, reload when it's done
 *   [data-countup]                  count a number up once
 *   input[data-filter="#table-id"]  filter the rows of a table as you type
 *   [data-toast]                    a message; [data-autohide] ones go away by themselves
 *   button[data-dialog-open="id"]   open that <dialog>; [data-dialog-close] closes it, [data-dialog-focus] gets focus
 *
 * window.JP: confirm(opts), prompt(opts), toast(message, type).
 */
(function () {
    'use strict';

    var doc = document;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function $(sel, root) { return (root || doc).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || doc).querySelectorAll(sel)); }

    /* ---------------------------------------------------------------- Sidebar */
    var sidebar = $('#sidebar');
    var backdrop = $('#sidebar-backdrop');
    var toggle = $('#sidebar-toggle');
    function setSidebar(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('-translate-x-full', !open);
        if (backdrop) backdrop.classList.toggle('hidden', !open);
        if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var first = $('a[aria-current], a', sidebar);
            if (first) first.focus();
        } else if (toggle && sidebar.contains(doc.activeElement)) {
            toggle.focus();
        }
    }
    if (toggle) toggle.addEventListener('click', function () { setSidebar(true); });
    $$('[data-sidebar-close]').forEach(function (el) { el.addEventListener('click', function () { setSidebar(false); }); });
    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sidebar && !sidebar.classList.contains('-translate-x-full') && window.innerWidth < 1024) setSidebar(false);
    });

    /* ------------------------------------------------------------------ Theme */
    var root = doc.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    function applyTheme(pref) {
        var dark = pref === 'dark' || (pref === 'system' && media && media.matches);
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.setAttribute('data-theme-pref', pref);
        $$('[data-theme-icon]').forEach(function (el) { el.hidden = el.getAttribute('data-theme-icon') !== pref; });
        $$('[data-theme-toggle]').forEach(function (b) {
            var label = 'Theme: ' + pref + ' (click to change)';
            b.setAttribute('aria-label', label);
            b.title = label;
        });
    }
    var themePref = root.getAttribute('data-theme-pref') || 'system';
    applyTheme(themePref);
    $$('[data-theme-toggle]').forEach(function (b) {
        b.addEventListener('click', function () {
            themePref = { system: 'light', light: 'dark', dark: 'system' }[themePref] || 'system';
            try { localStorage.setItem('jp-theme', themePref); } catch (e) { /* ignore */ }
            applyTheme(themePref);
        });
    });
    if (media && media.addEventListener) media.addEventListener('change', function () { if (themePref === 'system') applyTheme('system'); });

    /* ----------------------------------------------------------------- Toasts */
    var toasts = $('#toasts');
    function wireToast(el) {
        var close = $('[data-toast-close]', el);
        function dismiss() {
            el.style.transition = 'opacity .2s ease, transform .2s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(4px)';
            setTimeout(function () { el.remove(); }, reduced ? 0 : 200);
        }
        if (close) close.addEventListener('click', dismiss);
        if (el.hasAttribute('data-autohide')) {
            var timer = setTimeout(dismiss, 5000);
            el.addEventListener('mouseenter', function () { clearTimeout(timer); });
            el.addEventListener('mouseleave', function () { timer = setTimeout(dismiss, 2500); });
        }
    }
    $$('[data-toast]').forEach(wireToast);
    function toast(message, type) {
        if (!toasts) { if (type === 'error') window.alert(message); return; }
        var ok = type !== 'error';
        var el = doc.createElement('div');
        el.className = 'toast';
        el.setAttribute('role', ok ? 'status' : 'alert');
        el.setAttribute('data-toast', '');
        if (ok) el.setAttribute('data-autohide', '');
        el.innerHTML = (ok
            ? '<svg class="h-5 w-5 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>'
            : '<svg class="h-5 w-5 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>')
            + '<p class="min-w-0 flex-1 break-words"></p>'
            + '<button type="button" class="-m-1 rounded p-1 text-subtle hover:text-fg" data-toast-close aria-label="Dismiss"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>';
        $('p', el).textContent = message;
        toasts.appendChild(el);
        wireToast(el);
    }

    /* ---------------------------------------------------------------- Dialogs */
    var dlg = $('#jp-dialog');
    function openDialog(opts, withInput) {
        return new Promise(function (resolve) {
            if (!dlg || typeof dlg.showModal !== 'function') {
                resolve(withInput ? window.prompt((opts.title || '') + '\n' + (opts.text || ''), opts.value || '') : window.confirm((opts.title ? opts.title + '\n\n' : '') + (opts.text || '')));
                return;
            }
            var input = $('#jp-dialog-value', dlg);
            var ok = $('[data-dialog-ok]', dlg);
            $('#jp-dialog-title', dlg).textContent = opts.title || 'Are you sure?';
            var text = $('[data-dialog-text]', dlg);
            text.textContent = opts.text || '';
            text.hidden = !opts.text;
            $('[data-dialog-input]', dlg).hidden = !withInput;
            $('[data-dialog-label]', dlg).textContent = opts.label || '';
            input.value = withInput ? (opts.value || '') : '';
            input.required = !!withInput;
            ok.textContent = opts.action || (withInput ? 'OK' : 'Continue');
            ok.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-primary');
            dlg.returnValue = '';
            dlg.showModal();
            if (withInput) { input.focus(); input.select(); } else { ok.focus(); }
            dlg.addEventListener('close', function onClose() {
                dlg.removeEventListener('close', onClose);
                var yes = dlg.returnValue === 'ok';
                resolve(withInput ? (yes ? input.value : null) : yes);
            });
        });
    }
    // Enter in the text field submits the dialog as OK.
    if (dlg) {
        var dlgInput = $('#jp-dialog-value', dlg);
        dlgInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); dlg.close('ok'); }
        });
    }

    // Forms with data-confirm ask first. The submitting button is kept, so
    // its name/value still reach the server.
    doc.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
        if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
        e.preventDefault();
        e.stopImmediatePropagation();
        var submitter = e.submitter || null;
        openDialog({
            title: form.getAttribute('data-confirm-title') || 'Are you sure?',
            text: form.getAttribute('data-confirm'),
            action: form.getAttribute('data-confirm-action') || 'Continue',
            danger: form.hasAttribute('data-confirm-danger')
        }).then(function (yes) {
            if (!yes) return;
            form.dataset.confirmed = '1';
            if (form.requestSubmit) form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            else form.submit();
        });
    }, true);

    // No double submits: a POST form's buttons wait once it's on its way.
    doc.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post' || form.target) return;
        setTimeout(function () {
            $$('button[type="submit"], button:not([type])', form).forEach(function (b) { b.disabled = true; b.setAttribute('aria-busy', 'true'); });
        }, 0);
    });
    window.addEventListener('pageshow', function () {
        $$('button[aria-busy="true"]').forEach(function (b) { b.disabled = false; b.removeAttribute('aria-busy'); });
    });

    /* ------------------------------------------------------- Page dialogs */
    // button[data-dialog-open="<dialog id>"] opens it, [data-dialog-close]
    // closes the dialog it is in, [data-dialog-focus] gets the focus.
    doc.addEventListener('click', function (e) {
        var opener = e.target.closest && e.target.closest('[data-dialog-open]');
        if (opener) {
            var d = doc.getElementById(opener.getAttribute('data-dialog-open'));
            if (!d || typeof d.showModal !== 'function' || d.open) return;
            d.showModal();
            var first = $('[data-dialog-focus]', d);
            if (first) first.focus();
            return;
        }
        var closer = e.target.closest && e.target.closest('[data-dialog-close]');
        if (closer && closer.closest('dialog')) closer.closest('dialog').close();
    });

    /* ------------------------------------------------------------ Autosubmit */
    doc.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.hasAttribute && el.hasAttribute('data-autosubmit') && el.form) {
            if (el.form.requestSubmit) el.form.requestSubmit(); else el.form.submit();
        }
    });

    /* ------------------------------------------------------------------ Copy */
    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        return new Promise(function (resolve, reject) {
            var ta = doc.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            doc.body.appendChild(ta);
            ta.select();
            try { doc.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
            ta.remove();
        });
    }
    doc.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-copy]');
        if (!btn) return;
        e.preventDefault();
        copyText(btn.getAttribute('data-copy')).then(function () { toast('Copied to the clipboard.'); }, function () { toast('Copying failed - select the text and copy it by hand.', 'error'); });
    });

    /* ----------------------------------------------------- Password generator */
    function strongPassword(len) {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_.!@#%+=';
        var out = '';
        var buf = new Uint32Array(len);
        window.crypto.getRandomValues(buf);
        for (var i = 0; i < len; i++) out += chars[buf[i] % chars.length];
        return out;
    }
    doc.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-generate-password]');
        if (!btn) return;
        var input = doc.getElementById(btn.getAttribute('data-generate-password'));
        if (!input) return;
        var pw = strongPassword(20);
        input.type = 'text';
        input.value = pw;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        // A "confirm password" field in the same form gets it too.
        var confirm = input.form && $$('input[type="password"]', input.form).filter(function (i) { return /confirm|again/i.test(i.name); })[0];
        if (confirm) confirm.value = pw;
        input.select();
        copyText(pw).then(function () { toast('Password generated and copied. Keep it somewhere safe.'); }, function () { toast('Password generated - copy it before you leave the page.'); });
    });

    /* ---------------------------------------------------- Background job polling */
    var busy = $('[data-busy]');
    if (busy) {
        var every = Math.max(2000, parseInt(busy.getAttribute('data-busy'), 10) || 5000);
        var failures = 0;
        var tick = function () {
            fetch(location.href, { credentials: 'same-origin', headers: { 'X-JP-Poll': '1' }, cache: 'no-store' })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
                .then(function (html) {
                    failures = 0;
                    if (html.indexOf('data-busy=') === -1) location.reload();
                    else setTimeout(tick, every);
                })
                .catch(function () { failures++; setTimeout(tick, Math.min(60000, every * (failures + 1))); });
        };
        setTimeout(tick, every);
    }

    /* -------------------------------------------------------------- Count-up */
    $$('[data-countup]').forEach(function (el) {
        var target = parseFloat(el.textContent.replace(/[^0-9.]/g, ''));
        if (reduced || !isFinite(target) || target <= 0) return;
        var start = null, dur = 700, decimals = (el.textContent.split('.')[1] || '').length;
        function step(ts) {
            if (start === null) start = ts;
            var p = Math.min(1, (ts - start) / dur), eased = 1 - Math.pow(1 - p, 3);
            el.textContent = (target * eased).toFixed(decimals);
            if (p < 1) requestAnimationFrame(step); else el.textContent = target.toFixed(decimals);
        }
        requestAnimationFrame(step);
    });

    /* ---------------------------------------------------------- Table filter */
    $$('input[data-filter]').forEach(function (input) {
        var table = $(input.getAttribute('data-filter'));
        if (!table) return;
        var rows = $$('tbody tr', table).filter(function (r) { return !r.hasAttribute('data-filter-skip'); });
        var none = $('[data-filter-none]', table);
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            var shown = 0;
            rows.forEach(function (r) {
                var hit = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
                r.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown !== 0;
        });
    });

    /* ----------------------------------------------------------------- Menus */
    doc.addEventListener('click', function (e) {
        $$('details[data-menu][open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
    });
    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        $$('details[data-menu][open]').forEach(function (d) { d.removeAttribute('open'); var s = $('summary', d); if (s) s.focus(); });
    });

    /* ----------------------------------------------------- Command palette */
    var pal = $('#jp-palette');
    var palData = $('#jp-palette-data');
    if (pal && palData && typeof pal.showModal === 'function') {
        var entries = [];
        try { entries = JSON.parse(palData.textContent); } catch (err) { entries = []; }
        var input = $('[data-palette-input]', pal);
        var list = $('[data-palette-list]', pal);
        var empty = $('[data-palette-empty]', pal);
        var active = 0, shown = [];
        // Accounts and domains come from the server (GET /search?q=) as you type.
        var searchUrl = pal.getAttribute('data-search');
        var remote = [], remoteFor = '', searchTimer = null, searchSeq = 0;
        var search = function () {
            var q = input.value.trim();
            clearTimeout(searchTimer);
            if (!searchUrl || q.length < 2) { remote = []; remoteFor = ''; return; }
            searchTimer = setTimeout(function () {
                var seq = ++searchSeq;
                fetch(searchUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                    .then(function (data) {
                        if (seq !== searchSeq) return; // an older answer
                        remote = Array.isArray(data.results) ? data.results : [];
                        remoteFor = q;
                        render();
                    })
                    .catch(function () {});
            }, 180);
        };

        var render = function () {
            var q = input.value.trim().toLowerCase();
            shown = entries.filter(function (it) {
                if (!q) return true;
                var hay = (it.label + ' ' + it.group).toLowerCase();
                return q.split(/\s+/).every(function (w) { return hay.indexOf(w) !== -1; });
            });
            if (q && remoteFor.toLowerCase() === q) shown = shown.concat(remote);
            active = Math.min(active, Math.max(0, shown.length - 1));
            list.innerHTML = '';
            shown.forEach(function (it, i) {
                var li = doc.createElement('li');
                li.setAttribute('role', 'option');
                li.id = 'jp-pal-' + i;
                li.setAttribute('aria-selected', i === active ? 'true' : 'false');
                li.className = 'flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm ' + (i === active ? 'bg-accent-soft text-accent-text' : 'text-fg');
                var a = doc.createElement('span'); a.className = 'min-w-0 truncate'; a.textContent = it.label;
                var g = doc.createElement('span'); g.className = 'max-w-[55%] shrink-0 truncate text-xs text-subtle'; g.textContent = it.hint ? it.hint : it.group;
                li.appendChild(a); li.appendChild(g);
                li.addEventListener('mousemove', function () { if (active !== i) { active = i; render(); } });
                li.addEventListener('click', function () { go(it); });
                list.appendChild(li);
            });
            input.setAttribute('aria-activedescendant', shown.length ? 'jp-pal-' + active : '');
            empty.classList.toggle('hidden', shown.length !== 0);
            var cur = $('#jp-pal-' + active, list);
            if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
        };
        var go = function (it) { pal.close(); location.href = it.href; };
        var open = function () { input.value = ''; active = 0; remote = []; remoteFor = ''; render(); pal.showModal(); input.focus(); };
        input.addEventListener('input', function () { active = 0; render(); search(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, shown.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter' && shown[active]) { e.preventDefault(); go(shown[active]); }
        });
        pal.addEventListener('click', function (e) { if (e.target === pal) pal.close(); });
        $$('[data-palette-open]').forEach(function (b) { b.addEventListener('click', open); });
        doc.addEventListener('keydown', function (e) {
            var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((doc.activeElement || {}).tagName || '') || (doc.activeElement && doc.activeElement.isContentEditable);
            if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); pal.open ? pal.close() : open(); }
            else if (e.key === '/' && !typing && !pal.open) { e.preventDefault(); open(); }
        });
    }

    window.JP = {
        confirm: function (opts) { return openDialog(opts || {}, false); },
        prompt: function (opts) { return openDialog(opts || {}, true); },
        toast: toast
    };
})();
