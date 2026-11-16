// Password fields on the sign-in pages (Ui::password): a show/hide button,
// a warning while caps lock is on, and a strength meter for a new password.
// Without this script they are plain password inputs.
(function () {
    'use strict';
    var doc = document;

    /* ---------------------------------------------------------- Show / hide */
    function setShown(btn, input, shown) {
        input.type = shown ? 'text' : 'password';
        btn.setAttribute('aria-pressed', shown ? 'true' : 'false');
        btn.setAttribute('aria-label', shown ? 'Hide password' : 'Show password');
        btn.querySelector('[data-reveal-icon="show"]').hidden = shown;
        btn.querySelector('[data-reveal-icon="hide"]').hidden = !shown;
    }
    doc.querySelectorAll('[data-reveal]').forEach(function (btn) {
        var input = doc.getElementById(btn.getAttribute('aria-controls'));
        if (!input) return;
        btn.hidden = false;
        btn.addEventListener('click', function () {
            var start = input.selectionStart;
            var end = input.selectionEnd;
            setShown(btn, input, input.type === 'password');
            input.focus();
            try { input.setSelectionRange(start, end); } catch (e) { /* not a text input */ }
        });
        // The password generator (app.js) shows what it made: keep the button in step.
        input.addEventListener('input', function () {
            if ((input.type === 'text') !== (btn.getAttribute('aria-pressed') === 'true')) setShown(btn, input, input.type === 'text');
        });
        // Hide it again before sending, so the browser offers to save it as a password.
        if (input.form) input.form.addEventListener('submit', function () { if (input.type === 'text') setShown(btn, input, false); });
    });

    /* ------------------------------------------------------------- Caps lock */
    doc.querySelectorAll('[data-password]').forEach(function (input) {
        var field = input.closest('.field');
        var warn = field && field.querySelector('[data-caps]');
        if (!warn) return;
        function check(e) {
            if (!e.getModifierState) return;
            var on = e.getModifierState('CapsLock');
            if (warn.hidden === on) warn.hidden = !on; // only on a change, so it is announced once
        }
        input.addEventListener('keydown', check);
        input.addEventListener('keyup', check);
        input.addEventListener('blur', function () { warn.hidden = true; });
    });

    /* -------------------------------------------------------------- Strength */
    // 1 too short (under the server's minimum), 2 weak, 3 fair, 4 strong: from
    // the length and the kinds of characters used. A guide only: the server
    // decides what it accepts (Passwords::problem).
    var LABELS = ['', 'Too short', 'Weak', 'Fair', 'Strong'];
    function byteLength(s) {
        // The server counts bytes (strlen), so "too short" must too.
        return window.TextEncoder ? new TextEncoder().encode(s).length : unescape(encodeURIComponent(s)).length;
    }
    function rate(value, min) {
        if (value === '') return 0;
        if (byteLength(value) < min) return 1;
        var chars = Array.from(value);
        var unique = new Set(chars).size;
        if (unique < 4) return 2; // "aaaaaaaaaa1": the server refuses these as too easy to guess
        var pool = (/[a-z]/.test(value) ? 26 : 0) + (/[A-Z]/.test(value) ? 26 : 0)
            + (/[0-9]/.test(value) ? 10 : 0) + (/[^a-zA-Z0-9]/.test(value) ? 33 : 0);
        var bits = chars.length * Math.log2(Math.max(pool, 2));
        var level = bits >= 80 ? 4 : bits >= 56 ? 3 : 2;
        if (level === 4 && unique < chars.length / 2) level = 3; // mostly repeats
        return level;
    }
    doc.querySelectorAll('[data-strength-min]').forEach(function (input) {
        var meter = input.closest('.field').querySelector('[data-strength]');
        if (!meter) return;
        var label = meter.querySelector('[data-strength-label]');
        var min = parseInt(input.getAttribute('data-strength-min'), 10) || 10;
        var level = -1;
        function update() {
            var now = rate(input.value, min);
            if (now === level) return;
            level = now;
            meter.setAttribute('data-level', String(now));
            label.textContent = LABELS[now];
        }
        meter.hidden = false;
        input.addEventListener('input', update);
        update();
    });
})();
