/*
 * Backups page: the "Restore" dialogs (choose the parts, then restore).
 *
 *   (dialogs: button[data-dialog-open], [data-dialog-close], [data-dialog-focus] - handled in app.js)
 */
(function () {
    'use strict';
    var doc = document;

    // Restoring nothing isn't possible: the Restore button waits for a ticked part.
    Array.prototype.forEach.call(doc.querySelectorAll('dialog form'), function (form) {
        var parts = form.querySelectorAll('input[name="parts[]"]');
        var submit = form.querySelector('button[type="submit"]');
        if (!parts.length || !submit) return;
        var sync = function () {
            submit.disabled = !Array.prototype.some.call(parts, function (p) { return p.checked; });
        };
        Array.prototype.forEach.call(parts, function (p) { p.addEventListener('change', sync); });
        sync();
    });
})();
