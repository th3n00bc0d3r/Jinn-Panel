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

    // Numbers count up when the page opens (<span data-countup>42</span>).
    var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.querySelectorAll('[data-countup]').forEach(function (el) {
        var target = parseFloat(el.textContent.replace(/[^0-9.]/g, ''));
        if (still || !isFinite(target) || target <= 0) return;
        var start = null, dur = 900, decimals = (el.textContent.split('.')[1] || '').length;
        function step(ts) {
            if (start === null) start = ts;
            var p = Math.min(1, (ts - start) / dur), eased = 1 - Math.pow(1 - p, 3);
            el.textContent = (target * eased).toFixed(decimals);
            if (p < 1) requestAnimationFrame(step); else el.textContent = (target).toFixed(decimals);
        }
        requestAnimationFrame(step);
    });

    // Auto-dismiss flash messages after a while
    document.querySelectorAll('[data-flash]').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 300);
        }, 6000);
    });
})();
