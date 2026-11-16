/*
 * Exposed files (a domain's tab): "select all", and the move button names
 * how many are selected (and waits until one is).
 */
(function () {
    'use strict';
    var form = document.querySelector('[data-exposed]');
    if (!form) return;
    var all = form.querySelector('[data-exposed-all]');
    var items = Array.prototype.slice.call(form.querySelectorAll('[data-exposed-item]'));
    var submit = form.querySelector('[data-exposed-submit]');
    var label = form.querySelector('[data-exposed-label]');

    function sync() {
        var n = items.filter(function (i) { return i.checked; }).length;
        submit.disabled = n === 0;
        label.textContent = n === 0 ? 'Move selected to private' : 'Move ' + n + ' to private';
        if (all) {
            all.checked = n === items.length && n > 0;
            all.indeterminate = n > 0 && n < items.length;
        }
    }
    items.forEach(function (i) { i.addEventListener('change', sync); });
    if (all) {
        all.hidden = false;
        all.addEventListener('change', function () {
            items.forEach(function (i) { i.checked = all.checked; });
            sync();
        });
    }
    sync();
})();
