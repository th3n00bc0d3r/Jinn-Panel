/*
 * DNS record forms (cPanel > DNS Zones, WHM > DNS Zones > a zone).
 *
 *   form[data-dns-form]            the Priority field ([data-dns-priority]) shows only
 *                                  for types that use it, and the Value field's
 *                                  ([data-dns-value]) placeholder and hint follow the
 *                                  type picked in [data-dns-type]. The help per type is
 *                                  in <script type="application/json" id="dns-type-help">:
 *                                  { "MX": { "placeholder", "hint", "priority": true }, ... }
 *   button[data-dns-edit="row-id"] opens or closes a record's edit row (<tr id="row-id">);
 *                                  [data-dns-cancel] or Escape closes it and undoes the edits.
 *   a[href="#add-record"]          also puts the cursor in the add form.
 *
 * Without JavaScript every field shows and the edit rows stay closed.
 */
(function () {
    'use strict';

    var doc = document;
    var help = {};
    var data = doc.getElementById('dns-type-help');
    if (data) {
        try { help = JSON.parse(data.textContent) || {}; } catch (err) { help = {}; }
    }

    /* ------------------------------------------------ Fields per record type */
    function wire(form) {
        var type = form.querySelector('[data-dns-type]');
        if (!type) return;
        var prio = form.querySelector('[data-dns-priority]');
        var value = form.querySelector('[data-dns-value]');
        var hint = value ? doc.getElementById(value.id + '-hint') : null;
        var fallback = { placeholder: value ? value.placeholder : '', hint: hint ? hint.textContent : '' };

        function update() {
            var h = help[type.value] || null;
            if (prio) {
                // An unknown type keeps the field: better one too many than a missing one.
                (prio.closest('.field') || prio).hidden = !!h && !h.priority;
            }
            if (value) value.placeholder = h && h.placeholder ? h.placeholder : fallback.placeholder;
            if (hint) hint.textContent = h && h.hint ? h.hint : fallback.hint;
        }
        type.addEventListener('change', update);
        form.addEventListener('reset', function () { setTimeout(update, 0); });
        update();
    }
    Array.prototype.forEach.call(doc.querySelectorAll('form[data-dns-form]'), wire);

    /* ------------------------------------------------------- Inline editing */
    function buttonFor(row) {
        return doc.querySelector('[data-dns-edit="' + row.id + '"]');
    }

    function open(row) {
        var btn = buttonFor(row);
        row.hidden = false;
        if (btn) btn.setAttribute('aria-expanded', 'true');
        var first = row.querySelector('[data-dns-value]') || row.querySelector('input:not([type="hidden"]), select');
        if (first) first.focus();
    }

    function close(row, focusButton) {
        var form = row.querySelector('form');
        var btn = buttonFor(row);
        if (form) form.reset();
        row.hidden = true;
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
            if (focusButton) btn.focus();
        }
    }

    doc.addEventListener('click', function (e) {
        if (!e.target.closest) return;
        var btn = e.target.closest('[data-dns-edit]');
        if (btn) {
            var row = doc.getElementById(btn.getAttribute('data-dns-edit'));
            if (row) {
                if (row.hidden) open(row); else close(row, false);
            }
            return;
        }
        var cancel = e.target.closest('[data-dns-cancel]');
        if (cancel) {
            var editRow = cancel.closest('[data-dns-edit-row]');
            if (editRow) close(editRow, true);
            return;
        }
        var add = e.target.closest('a[href="#add-record"]');
        if (add) {
            var field = doc.querySelector('#add-record select, #add-record input:not([type="hidden"])');
            if (field) setTimeout(function () { field.focus({ preventScroll: true }); }, 0);
        }
    });

    doc.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !e.target.closest) return;
        var row = e.target.closest('[data-dns-edit-row]');
        if (row && !row.hidden) {
            e.preventDefault();
            close(row, true);
        }
    });
})();
