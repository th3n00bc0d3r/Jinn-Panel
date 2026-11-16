// cPanel > File Manager: selection toolbar, dialogs for move/copy/zip/rename,
// upload by button or drag and drop, new folder. Without JavaScript the
// plain upload and new-folder forms under the table do the same.
(function () {
    'use strict';
    var form = document.getElementById('fm');
    if (!form) return;
    var JP = window.JP;
    var here = form.getAttribute('data-here') || '';
    var site = form.getAttribute('data-site') || '';
    var uploadForm = document.querySelector('[data-upload]');
    var uploadInput = document.getElementById('fm-upload-input');
    var mkdirForm = document.querySelector('[data-mkdir-form]');

    // The fallback forms aren't needed with JavaScript.
    if (uploadForm) uploadForm.hidden = true;
    if (mkdirForm) mkdirForm.hidden = true;

    function items() { return Array.prototype.slice.call(form.querySelectorAll('[data-item]')); }
    function checked() { return items().filter(function (i) { return i.checked; }); }
    var selBar = form.querySelector('[data-selection]');
    var noSel = form.querySelector('[data-no-selection]');
    var folderActions = form.querySelector('[data-folder-actions]');
    var all = form.querySelector('[data-all]');

    function update() {
        var n = checked().length;
        form.querySelector('[data-count]').textContent = n;
        selBar.hidden = n === 0;
        noSel.hidden = n !== 0;
        folderActions.hidden = n !== 0;
        if (all) {
            all.checked = n > 0 && n === items().length;
            all.indeterminate = n > 0 && n < items().length;
        }
    }
    if (all) all.addEventListener('change', function () { items().forEach(function (i) { i.checked = all.checked; }); update(); });
    items().forEach(function (i) { i.addEventListener('change', update); });

    function submit(op, dest) {
        form.elements.op.value = op;
        form.elements.dest.value = dest || '';
        form.submit();
    }
    function names() { return checked().map(function (i) { return i.value; }); }
    function what(n) { return n === 1 ? '"' + names()[0] + '"' : n + ' items'; }

    form.querySelectorAll('[data-op]').forEach(function (b) {
        b.addEventListener('click', function () {
            var n = checked().length, op = b.getAttribute('data-op');
            if (!n) return;
            if (op === 'download') {
                var q = '?domain_id=' + encodeURIComponent(form.getAttribute('data-domain-id')) + '&path=' + encodeURIComponent(here);
                names().forEach(function (v) { q += '&names[]=' + encodeURIComponent(v); });
                location.href = '/cpanel/files/download' + q;
            } else if (op === 'delete') {
                JP.confirm({ title: 'Delete ' + what(n) + '?', text: 'Folders are deleted with everything inside. This can\'t be undone.', action: 'Delete', danger: true })
                    .then(function (yes) { if (yes) submit('delete'); });
            } else if (op === 'move' || op === 'copy') {
                JP.prompt({ title: (op === 'move' ? 'Move ' : 'Copy ') + what(n), label: 'To folder (inside ' + site + ')', value: here, action: op === 'move' ? 'Move' : 'Copy' })
                    .then(function (t) { if (t !== null) submit(op, t); });
            } else if (op === 'compress') {
                JP.prompt({ title: 'Zip ' + what(n), label: 'Name of the .zip to create in this folder', value: n === 1 ? names()[0].replace(/\/$/, '') + '.zip' : 'archive.zip', action: 'Create zip' })
                    .then(function (z) { if (z) submit('compress', z); });
            } else if (op === 'chmod') {
                var box = form.querySelector('[data-chmod]');
                box.classList.toggle('hidden');
                box.classList.toggle('flex');
            }
        });
    });
    form.querySelector('[data-apply-chmod]').addEventListener('click', function () { submit('chmod'); });

    form.querySelectorAll('[data-row-op]').forEach(function (b) {
        b.addEventListener('click', function () {
            var name = b.getAttribute('data-name');
            items().forEach(function (i) { i.checked = i.value === name; });
            update();
            if (b.getAttribute('data-row-op') === 'extract') {
                JP.confirm({ title: 'Extract ' + name + '?', text: 'Into this folder. Files with the same names are overwritten.', action: 'Extract' })
                    .then(function (yes) { if (yes) submit('extract'); else { items().forEach(function (i) { i.checked = false; }); update(); } });
            } else {
                JP.prompt({ title: 'Rename', label: 'New name for ' + name, value: name, action: 'Rename' })
                    .then(function (nn) {
                        if (nn && nn !== name) submit('rename', nn);
                        else { items().forEach(function (i) { i.checked = false; }); update(); }
                    });
            }
        });
    });

    // New folder: ask for the name, then the plain form does the rest.
    var mk = form.querySelector('[data-mkdir]');
    if (mk && mkdirForm) {
        mk.addEventListener('click', function () {
            JP.prompt({ title: 'New folder', label: 'Folder name', value: '', action: 'Create' }).then(function (name) {
                if (!name) return;
                mkdirForm.elements.name.value = name;
                mkdirForm.submit();
            });
        });
    }

    // Upload: the Upload button opens the file picker; choosing files sends them.
    function sendFiles() {
        if (!uploadInput.files || !uploadInput.files.length) return;
        JP.toast('Uploading ' + uploadInput.files.length + ' file' + (uploadInput.files.length === 1 ? '' : 's') + '…');
        uploadForm.submit();
    }
    if (uploadInput && uploadForm) {
        uploadInput.addEventListener('change', sendFiles);
        var zone = form.querySelector('[data-dropzone]');
        var depth = 0;
        form.addEventListener('dragenter', function (e) {
            if (!e.dataTransfer || Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') === -1) return;
            depth++;
            zone.classList.add('ring-2', 'ring-accent', 'ring-inset', 'bg-accent-soft');
        });
        form.addEventListener('dragleave', function () {
            if (--depth <= 0) { depth = 0; zone.classList.remove('ring-2', 'ring-accent', 'ring-inset', 'bg-accent-soft'); }
        });
        form.addEventListener('dragover', function (e) { e.preventDefault(); });
        form.addEventListener('drop', function (e) {
            e.preventDefault();
            depth = 0;
            zone.classList.remove('ring-2', 'ring-accent', 'ring-inset', 'bg-accent-soft');
            if (!e.dataTransfer || !e.dataTransfer.files.length) return;
            try { uploadInput.files = e.dataTransfer.files; } catch (err) { JP.toast('Your browser can\'t upload by dropping - use the Upload button.', 'error'); return; }
            sendFiles();
        });
    }

    update();
})();
