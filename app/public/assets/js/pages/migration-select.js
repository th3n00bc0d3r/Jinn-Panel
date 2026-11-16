/*
 * WHM > cPanel Migration > Choose accounts (step 2): the selected count and
 * size, "select all" (the rows the filter shows), the Start button only with
 * at least one account, and "Stored email" only with email accounts.
 */
(function () {
    'use strict';

    var form = document.getElementById('migration-select');
    if (!form) return;
    var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-account]:not(:disabled)'));
    var all = form.querySelector('[data-select-all]');
    var countEl = form.querySelector('[data-selected-count]');
    var sizeEl = form.querySelector('[data-selected-size]');
    var start = form.querySelector('[data-start]');
    var mailAcc = form.querySelector('[data-email-accounts]');
    var mailData = form.querySelector('[data-email-data]');

    function fmt(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : mb + ' MB'; }
    function shown(b) { var tr = b.closest('tr'); return !tr || !tr.hidden; }

    function sync() {
        var n = 0, mb = 0;
        boxes.forEach(function (b) { if (b.checked) { n++; mb += parseInt(b.dataset.mb || '0', 10); } });
        if (countEl) countEl.textContent = n;
        if (sizeEl) sizeEl.textContent = mb > 0 ? ' · ' + fmt(mb) : '';
        if (start) start.disabled = n === 0;
        if (all) {
            var visible = boxes.filter(shown);
            var on = visible.filter(function (b) { return b.checked; }).length;
            all.checked = on > 0 && on === visible.length;
            all.indeterminate = on > 0 && on < visible.length;
        }
        if (mailAcc && mailData) mailData.disabled = !mailAcc.checked;
    }

    if (all) {
        all.addEventListener('change', function () {
            boxes.filter(shown).forEach(function (b) { b.checked = all.checked; });
            sync();
        });
    }
    form.addEventListener('change', sync);
    var filter = form.querySelector('input[data-filter]');
    if (filter) filter.addEventListener('input', sync);
    sync();
})();
