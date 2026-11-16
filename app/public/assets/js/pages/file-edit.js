// File Manager text editor: line numbers, Tab/Shift+Tab indent, Ctrl/Cmd+S
// to save, and a warning before leaving with unsaved changes.
(function () {
    'use strict';
    var form = document.querySelector('[data-editor]');
    if (!form) return;
    var ta = form.querySelector('[data-editor-text]');
    var gutter = form.querySelector('[data-gutter]');
    var status = form.querySelector('[data-editor-status]');
    var saved = ta.value;
    var dirty = false;

    function lines() {
        if (!gutter) return;
        var n = ta.value.split('\n').length, out = [];
        for (var i = 1; i <= n; i++) out.push(i);
        gutter.textContent = out.join('\n');
        gutter.scrollTop = ta.scrollTop;
    }
    function markDirty() {
        var d = ta.value !== saved;
        if (d === dirty) return;
        dirty = d;
        if (status) status.textContent = dirty ? 'Unsaved changes' : 'No changes';
        document.title = (dirty ? '• ' : '') + document.title.replace(/^• /, '');
    }
    ta.addEventListener('input', function () { lines(); markDirty(); });
    ta.addEventListener('scroll', function () { if (gutter) gutter.scrollTop = ta.scrollTop; });

    ta.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.ctrlKey || e.metaKey || e.altKey) return;
        e.preventDefault();
        var start = ta.selectionStart, end = ta.selectionEnd, v = ta.value;
        var indent = /\n\t/.test('\n' + v) && !/\n {4}/.test('\n' + v) ? '\t' : '    ';
        var lineStart = v.lastIndexOf('\n', start - 1) + 1;
        if (start === end && !e.shiftKey) {
            ta.setRangeText(indent, start, end, 'end');
        } else {
            var block = v.slice(lineStart, end);
            var changed = e.shiftKey
                ? block.replace(/^(\t| {1,4})/gm, '')
                : block.replace(/^/gm, indent);
            ta.setRangeText(changed, lineStart, end, 'select');
        }
        lines();
        markDirty();
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (form.requestSubmit) form.requestSubmit(); else form.submit();
        }
    });

    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    lines();
    if (!form.querySelector('#fe-name')) ta.focus();
})();
