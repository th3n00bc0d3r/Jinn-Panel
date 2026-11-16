/*
 * MySQL Databases page:
 *  - "Also create a user" shows (and requires) the password field;
 *  - "All privileges" hides the single privileges, and choosing a user and a
 *    database shows what that user may do there now (#db-grants);
 *  - the per-user "Change password" dialogs;
 *  - #add-database (Ctrl-K "Create a database") focuses the name field.
 *
 *   (dialogs: button[data-dialog-open], [data-dialog-close], [data-dialog-focus] - handled in app.js)
 */
(function () {
    'use strict';
    var doc = document;

    /* Create database: optional user with the same name */
    var withUser = doc.querySelector('[data-with-user]');
    var pwBox = doc.querySelector('[data-with-user-pw]');
    if (withUser && pwBox) {
        var pw = pwBox.querySelector('input');
        var syncUser = function () {
            pwBox.hidden = !withUser.checked;
            if (pw) pw.required = withUser.checked;
        };
        withUser.addEventListener('change', syncUser);
        syncUser();
    }

    /* Access form */
    var form = doc.querySelector('[data-grant-form]');
    if (form) {
        var all = form.querySelector('[data-all-privs]');
        var box = form.querySelector('[data-privs]');
        var userSel = form.querySelector('[data-grant-user]');
        var dbSel = form.querySelector('[data-grant-db]');
        var now = form.querySelector('[data-grant-now]');
        var data = { grants: {}, all: 0 };
        try { data = JSON.parse(doc.getElementById('db-grants').textContent); } catch (err) { /* keep defaults */ }
        var boxes = Array.prototype.slice.call(box.querySelectorAll('input[type="checkbox"]'));

        var syncAll = function () {
            box.hidden = all.checked;
            boxes.forEach(function (i) {
                i.disabled = all.checked;
                if (all.checked) i.checked = true;
            });
        };
        var current = function () {
            var u = data.grants[userSel.value] || {};
            return u[dbSel.value] || null;
        };
        var load = function () {
            var privs = current();
            if (!privs) {
                now.textContent = userSel.value + ' has no access to this database yet.';
                all.checked = true;
            } else if (privs.length >= data.all) {
                now.textContent = userSel.value + ' has all privileges on this database now.';
                all.checked = true;
            } else {
                now.textContent = userSel.value + ' has ' + privs.length + ' of ' + data.all + ' privileges on this database now.';
                all.checked = false;
                boxes.forEach(function (i) { i.checked = privs.indexOf(i.value) !== -1; });
            }
            now.hidden = false;
            syncAll();
        };
        all.addEventListener('change', syncAll);
        userSel.addEventListener('change', load);
        dbSel.addEventListener('change', load);
        load();
    }

    function focusTarget() {
        if (location.hash !== '#add-database') return;
        var input = doc.querySelector('#add-database input[name="name"]');
        if (input) input.focus({ preventScroll: true });
    }
    window.addEventListener('hashchange', focusTarget);
    focusTarget();
})();
