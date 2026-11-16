/*
 * WHM > Mail Settings > <object>: the JSON fields of a schema-generated
 * Stalwart form are checked before it's sent. The server decodes them with
 * json_decode(), and text that isn't JSON would be saved as "no value".
 */
(function () {
    'use strict';

    var form = document.querySelector('form[data-json-fields]');
    if (!form) return;

    function check(ta) {
        var v = ta.value.trim();
        if (v === '') return null;
        try { JSON.parse(v); return null; } catch (err) { return err.message || 'not valid JSON'; }
    }

    form.addEventListener('submit', function (e) {
        var first = null, firstError = '';
        Array.prototype.forEach.call(form.querySelectorAll('textarea[data-json]'), function (ta) {
            var err = check(ta);
            if (err) {
                ta.setAttribute('aria-invalid', 'true');
                if (!first) { first = ta; firstError = err; }
            } else {
                ta.removeAttribute('aria-invalid');
            }
        });
        if (!first) return;
        e.preventDefault();
        first.focus();
        var label = form.querySelector('label[for="' + first.id + '"]');
        var name = label ? label.textContent.trim() : first.name;
        var msg = '"' + name + '" isn\'t valid JSON (' + firstError + '). Fix it and save again.';
        if (window.JP) window.JP.toast(msg, 'error'); else window.alert(msg);
    });

    form.addEventListener('input', function (e) {
        var ta = e.target;
        if (ta && ta.matches && ta.matches('textarea[data-json][aria-invalid]') && !check(ta)) ta.removeAttribute('aria-invalid');
    });
})();
