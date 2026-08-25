(function () {
    'use strict';

    // Mobile sidebar toggle
    var sidebar = document.getElementById('sidebar');
    var toggle = document.getElementById('sidebar-toggle');
    var backdrop = document.getElementById('sidebar-backdrop');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('-translate-x-full');
        if (backdrop) backdrop.classList.remove('hidden');
    }
    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('-translate-x-full');
        if (backdrop) backdrop.classList.add('hidden');
    }
    if (toggle) toggle.addEventListener('click', openSidebar);
    if (backdrop) backdrop.addEventListener('click', closeSidebar);

    // Confirm-before-submit for destructive actions
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form && form.hasAttribute('data-confirm')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        }
    }, true);

    // Auto-dismiss flash messages after a while
    document.querySelectorAll('[data-flash]').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 6000);
    });
})();
