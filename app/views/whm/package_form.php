<div class="max-w-lg">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <form method="post" action="/whm/packages" class="space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Package name</label>
            <input type="text" name="name" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Disk quota (MB)</label>
                <input type="number" name="disk_quota_mb" value="1024" min="1"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Bandwidth (MB/mo)</label>
                <input type="number" name="bandwidth_mb" value="10240" min="1"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Max domains</label>
                <input type="number" name="max_domains" value="1" min="1"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Max databases</label>
                <input type="number" name="max_databases" value="1" min="1"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Max email accounts</label>
                <input type="number" name="max_email_accounts" value="5" min="0"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Max FTP accounts</label>
                <input type="number" name="max_ftp_accounts" value="1" min="0"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>
        <div class="flex gap-3 pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Create package</button>
            <a href="/whm/packages" class="rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-5 py-2.5 transition-colors">Cancel</a>
        </div>
    </form>
</div>
</div>
