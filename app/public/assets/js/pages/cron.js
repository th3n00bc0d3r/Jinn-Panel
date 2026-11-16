/*
 * Cron Jobs page:
 *  - the "Run" choice shows only the fields of that kind (PHP script or URL);
 *  - schedule shortcuts fill in the schedule field;
 *  - the "Output" dialogs of the last runs.
 *
 *   (dialogs: button[data-dialog-open], [data-dialog-close], [data-dialog-focus] - handled in app.js)
 */
(function () {
    'use strict';
    var doc = document;

    /* Kind: PHP script or URL */
    var kind = doc.querySelector('[data-cron-kind]');
    if (kind) {
        var syncKind = function () {
            Array.prototype.forEach.call(doc.querySelectorAll('[data-kind-group]'), function (g) {
                g.hidden = g.getAttribute('data-kind-group') !== kind.value;
            });
        };
        kind.addEventListener('change', syncKind);
        syncKind();
    }

    /* Schedule shortcuts */
    var presets = doc.querySelector('[data-cron-presets]');
    var schedule = doc.querySelector('[data-cron-schedule]');
    if (presets && schedule) {
        presets.hidden = false;
        presets.addEventListener('click', function (e) {
            var b = e.target.closest('[data-schedule]');
            if (!b) return;
            schedule.value = b.getAttribute('data-schedule');
            schedule.dispatchEvent(new Event('input', { bubbles: true }));
            schedule.focus();
        });
    }
})();
