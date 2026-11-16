// Second sign-in step.
// - The 6-digit code as six boxes over the real input (input[data-otp],
//   name="code"), which becomes hidden and gets the digits. Typing moves on,
//   Backspace goes back, the arrow keys move, a pasted (or autofilled) code
//   fills every box, and the form sends itself once the sixth digit is in.
//   Without JavaScript the real input is shown as it is.
// - A switch between the authenticator code and a recovery code.
(function () {
    'use strict';
    var doc = document;
    var codeForm = doc.querySelector('[data-tf="code"]');
    var recForm = doc.querySelector('[data-tf="recovery"]');
    var sw = doc.querySelector('[data-tf-switch]');
    var real = doc.querySelector('input[data-otp]');
    var N = 6;
    var boxes = [];
    var digitsIn = []; // what each box holds, to put back when a letter is typed
    var busy = false;

    function focusBox(i) {
        var b = boxes[Math.max(0, Math.min(N - 1, i))];
        b.focus();
        b.select();
    }
    function firstEmpty() {
        for (var k = 0; k < N; k++) if (!digitsIn[k]) return k;
        return 0;
    }
    function set(i, digit) {
        boxes[i].value = digit;
        digitsIn[i] = digit;
        real.value = digitsIn.join('');
    }
    function maybeSend() {
        if (busy || !/^\d{6}$/.test(real.value) || codeForm.classList.contains('js-hide')) return;
        if (codeForm.requestSubmit) codeForm.requestSubmit(); else { busy = true; codeForm.submit(); }
    }
    // Several digits at once (paste, autofill): a whole code starts at the first box.
    function fill(from, digits) {
        if (digits.length >= N) from = 0;
        digits = digits.slice(0, N - from);
        for (var k = 0; k < digits.length; k++) set(from + k, digits[k]);
        focusBox(from + digits.length);
        maybeSend();
    }

    if (real && codeForm) {
        var label = doc.querySelector('label[for="' + real.id + '"]');
        var group = doc.createElement('div');
        group.className = 'otp';
        group.setAttribute('role', 'group');
        if (label) {
            if (!label.id) label.id = real.id + '-label';
            group.setAttribute('aria-labelledby', label.id);
        }
        var invalid = real.getAttribute('aria-invalid') === 'true';
        var start = real.value.replace(/\D/g, '').slice(0, N);
        for (var i = 0; i < N; i++) {
            var b = doc.createElement('input');
            b.type = 'text';
            b.id = real.id + '-' + (i + 1);
            b.className = 'otp-box';
            b.inputMode = 'numeric';
            b.setAttribute('autocomplete', i === 0 ? 'one-time-code' : 'off');
            b.setAttribute('pattern', '[0-9]');
            b.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of ' + N);
            b.required = real.required;
            b.value = start[i] || '';
            if (invalid) b.setAttribute('aria-invalid', 'true');
            group.appendChild(b);
            boxes.push(b);
            digitsIn.push(b.value);
        }
        real.insertAdjacentElement('afterend', group);
        real.type = 'hidden';
        real.removeAttribute('required');
        if (label) label.htmlFor = boxes[0].id; // clicking the label goes to the first box

        boxes.forEach(function (b, i) {
            b.addEventListener('focus', function () { b.select(); });
            b.addEventListener('input', function (e) {
                if (invalid) {
                    invalid = false;
                    boxes.forEach(function (x) { x.removeAttribute('aria-invalid'); });
                }
                // Typed: the new character (the box may already hold a digit). Otherwise (autofill, drop): the whole value.
                var typed = e.inputType === 'insertText';
                var digits = (typed ? (e.data || '') : b.value).replace(/\D/g, '');
                if (digits.length > 1) { fill(i, digits); return; }
                if (typed && digits === '') { set(i, digitsIn[i]); return; } // a letter: keep what was there
                set(i, digits);
                if (digits && i < N - 1) focusBox(i + 1);
                maybeSend();
            });
            b.addEventListener('paste', function (e) {
                var text = (e.clipboardData || window.clipboardData).getData('text') || '';
                e.preventDefault();
                var digits = text.replace(/\D/g, '');
                if (digits.length === 1) { set(i, digits); if (i < N - 1) focusBox(i + 1); maybeSend(); }
                else if (digits) fill(i, digits);
            });
            b.addEventListener('keydown', function (e) {
                if (e.altKey || e.ctrlKey || e.metaKey) return;
                if (e.key === 'Backspace' && b.value === '' && i > 0) {
                    e.preventDefault();
                    set(i - 1, '');
                    focusBox(i - 1);
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    e.preventDefault();
                    focusBox(i - 1);
                } else if (e.key === 'ArrowRight' && i < N - 1) {
                    e.preventDefault();
                    focusBox(i + 1);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    focusBox(0);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    focusBox(N - 1);
                }
            });
        });

        codeForm.addEventListener('submit', function (e) {
            if (busy) { e.preventDefault(); return; } // already on its way (auto-sent, then Enter)
            busy = true;
        });
        // Back to this page from the next one (back/forward cache): it can be sent again.
        window.addEventListener('pageshow', function () { busy = false; });

        if (!codeForm.classList.contains('js-hide')) focusBox(firstEmpty());
    }

    if (!codeForm || !recForm || !sw) return;
    var recInput = recForm.querySelector('input:not([type=hidden])');
    sw.addEventListener('click', function () {
        var toRecovery = !codeForm.classList.contains('js-hide');
        codeForm.classList.toggle('js-hide', toRecovery);
        recForm.classList.toggle('js-hide', !toRecovery);
        if (boxes.length) boxes.forEach(function (b) { b.required = !toRecovery; });
        else if (real) real.required = !toRecovery;
        if (recInput) recInput.required = toRecovery;
        sw.textContent = toRecovery ? 'Use the authenticator app' : 'Use a recovery code';
        if (toRecovery) { if (recInput) recInput.focus(); }
        else if (boxes.length) focusBox(firstEmpty());
        else if (real) real.focus();
    });
})();
