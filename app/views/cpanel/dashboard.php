<?php include __DIR__ . '/../partials/quota_bars.php'; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-4">
    <a href="/cpanel/domains" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('globe', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">Domains</p>
        <p class="text-xs text-slate-400 mt-0.5">Add and manage websites</p>
    </a>
    <a href="/cpanel/databases" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('database', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">MySQL Databases</p>
        <p class="text-xs text-slate-400 mt-0.5">Create databases &amp; users</p>
    </a>
    <a href="/cpanel/email" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('mail', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">Email Accounts</p>
        <p class="text-xs text-slate-400 mt-0.5">Mailboxes on your domains</p>
    </a>
    <a href="/cpanel/ftp" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('folder-up', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">FTP / SFTP</p>
        <p class="text-xs text-slate-400 mt-0.5">File transfer accounts</p>
    </a>
    <a href="/cpanel/files" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('folder', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">File Manager</p>
        <p class="text-xs text-slate-400 mt-0.5">Upload, edit, zip and unzip files</p>
    </a>
    <a href="/cpanel/dns" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('server', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">DNS Zones</p>
        <p class="text-xs text-slate-400 mt-0.5">Records for your domains</p>
    </a>
    <a href="/cpanel/cron" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('clock', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">Cron Jobs</p>
        <p class="text-xs text-slate-400 mt-0.5">Scheduled scripts and URLs</p>
    </a>
    <a href="/cpanel/cache" class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 hover:border-sky-300 hover:shadow transition-all">
        <?= icon('bolt', 'h-6 w-6 text-sky-500 mb-3') ?>
        <p class="font-medium text-slate-800">Cache</p>
        <p class="text-xs text-slate-400 mt-0.5">Page cache and object cache</p>
    </a>
</div>
