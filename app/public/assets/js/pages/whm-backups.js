/*
 * WHM > Backups: each backup's row opens a detail row below it (contents,
 * downloads, restore, log). The filter box (app.js) closes open ones so a
 * detail row never stays behind a row it hid.
 */
(function () {
    'use strict';

    function setOpen(btn, open) {
        var row = document.getElementById(btn.getAttribute('aria-controls'));
        if (!row) return;
        row.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        var chevron = btn.querySelector('svg');
        if (chevron) chevron.classList.toggle('rotate-180', open);
    }

    var toggles = Array.prototype.slice.call(document.querySelectorAll('[data-backup-toggle]'));
    toggles.forEach(function (btn) {
        btn.addEventListener('click', function () {
            setOpen(btn, btn.getAttribute('aria-expanded') !== 'true');
        });
    });

    var filter = document.querySelector('input[data-filter="#backups-table"]');
    if (filter) {
        filter.addEventListener('input', function () {
            toggles.forEach(function (btn) { setOpen(btn, false); });
        });
    }
})();
