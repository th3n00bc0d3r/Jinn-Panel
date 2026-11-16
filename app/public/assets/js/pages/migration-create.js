/*
 * WHM > cPanel Migration > New migration (step 1).
 *  - #files / #s3 in the URL pick that source card (the cards switch the
 *    panels with CSS alone; this only preselects one and keeps the URL in step).
 *  - The port and username placeholders, and the secret's label, follow the
 *    kind of login and the sign-in method.
 */
(function () {
    'use strict';

    var preset = document.getElementById('src-' + location.hash.slice(1));
    if (preset && preset.type === 'radio' && preset.name === 'source') preset.checked = true;
    Array.prototype.forEach.call(document.querySelectorAll('input[name="source"]'), function (r) {
        r.addEventListener('change', function () {
            if (r.checked && window.history.replaceState) {
                window.history.replaceState(null, '', r.value === 'live' ? location.pathname + location.search : '#' + r.value);
            }
        });
    });

    var form = document.getElementById('migration-connect');
    if (!form) return;
    var port = form.querySelector('[data-port]');
    var user = form.querySelector('[data-user]');
    var auth = form.querySelector('[data-auth-type]');
    var secretLabel = form.querySelector('label[for="mig-secret"]');
    function sync() {
        var t = (form.querySelector('input[name=source_type]:checked') || {}).value || 'cpanel';
        if (port) port.placeholder = t === 'cpanel' ? '2083' : '2087';
        if (user) user.placeholder = t === 'root' ? 'root' : (t === 'reseller' ? 'WHM reseller username' : 'cPanel username');
        if (secretLabel && auth) secretLabel.textContent = auth.value === 'password' ? 'Password' : 'API token';
    }
    form.addEventListener('change', sync);
    sync();
})();
