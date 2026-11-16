/*
 * cPanel > Domains > a domain > Routes.
 *
 *   form[data-routes-editor]  the rules editor: leaving the page (another tab, a
 *                             link, the reload when a change finishes applying)
 *                             asks first while it holds edits that weren't saved.
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-routes-editor]');
    if (!form) return;
    var fields = Array.prototype.slice.call(form.querySelectorAll('textarea'));
    var submitting = false;

    function dirty() {
        return fields.some(function (f) { return f.value !== f.defaultValue; });
    }

    form.addEventListener('submit', function () { submitting = true; });
    window.addEventListener('pageshow', function () { submitting = false; });
    window.addEventListener('beforeunload', function (e) {
        if (submitting || !dirty()) return;
        e.preventDefault();
        e.returnValue = '';
    });
})();
