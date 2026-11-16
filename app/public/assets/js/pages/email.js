/*
 * Email Accounts page: the per-mailbox "Manage" dialogs (password and
 * automatic reply), and focusing the "Create a mailbox" form when the page
 * is opened at #add-mailbox (Ctrl-K "Create a mailbox", the header button).
 *
 *   (dialogs: button[data-dialog-open], [data-dialog-close], [data-dialog-focus] - handled in app.js)
 */
(function () {
    'use strict';

    function focusTarget() {
        if (location.hash !== '#add-mailbox') return;
        var input = document.querySelector('#add-mailbox input[name="local_part"]');
        if (input) input.focus({ preventScroll: true });
    }
    window.addEventListener('hashchange', focusTarget);
    focusTarget();
})();
