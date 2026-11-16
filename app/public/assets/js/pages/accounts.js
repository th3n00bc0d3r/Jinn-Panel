// WHM > Accounts: select accounts, then change their package, suspend,
// unsuspend or switch a feature in one go. The confirm text says what will
// happen (app.js reads data-confirm when the form is sent).
(function () {
    'use strict';
    var form = document.querySelector('[data-bulk]');
    if (!form) return;
    var items = function () { return Array.prototype.slice.call(document.querySelectorAll('[data-bulk-item]')); };
    var checked = function () { return items().filter(function (i) { return i.checked && !i.closest('tr').hidden; }); };
    var all = document.querySelector('[data-bulk-all]');
    var op = form.querySelector('[data-bulk-op]');
    var count = form.querySelector('[data-bulk-count]');
    var apply = form.querySelector('[data-bulk-apply]');
    var groups = form.querySelectorAll('[data-bulk-for]');
    var label = function (sel) { return sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : ''; };

    function update() {
        var n = checked().length;
        count.textContent = n;
        groups.forEach(function (g) { g.hidden = g.getAttribute('data-bulk-for') !== op.value; });
        apply.disabled = n === 0 || op.value === '';
        if (all) {
            all.checked = n > 0 && n === items().length;
            all.indeterminate = n > 0 && n < items().length;
        }
        var who = n === 1 ? '1 account' : n + ' accounts';
        var text = {
            package: 'Move ' + who + ' to the package "' + label(form.elements.package_id) + '"? Their limits and features change right away.',
            suspend: 'Suspend ' + who + '? Their sites go offline, and mail logins, SFTP, MySQL and cron stop. Customers are emailed if suspension emails are on.',
            unsuspend: 'Unsuspend ' + who + '?',
            feature: 'Set "' + label(form.elements.feature) + '" to ' + label(form.elements.feature_value).toLowerCase() + ' for ' + who + '?'
        }[op.value] || 'Apply this to the selected accounts?';
        form.setAttribute('data-confirm', text);
        form.setAttribute('data-confirm-action', op.value === 'suspend' ? 'Suspend' : 'Apply');
        if (op.value === 'suspend') form.setAttribute('data-confirm-danger', ''); else form.removeAttribute('data-confirm-danger');
    }
    if (all) all.addEventListener('change', function () {
        items().forEach(function (i) { if (!i.closest('tr').hidden) i.checked = all.checked; });
        update();
    });
    items().forEach(function (i) { i.addEventListener('change', update); });
    form.addEventListener('change', update);
    update();
})();
