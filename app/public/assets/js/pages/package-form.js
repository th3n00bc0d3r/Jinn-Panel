// Package form: an "Unlimited" box switches its number field off.
(function () {
    'use strict';
    document.querySelectorAll('[data-unlimited]').forEach(function (box) {
        var input = document.querySelector('[data-limit="' + box.getAttribute('data-unlimited') + '"]');
        if (!input) return;
        var kept = input.value;
        function sync() {
            if (box.checked) {
                if (input.value !== '') kept = input.value;
                input.value = '';
                input.placeholder = 'Unlimited';
            } else {
                input.value = input.value || kept || input.getAttribute('min') || '0';
                input.placeholder = '';
            }
            input.disabled = box.checked;
        }
        box.addEventListener('change', sync);
        sync();
    });
})();
