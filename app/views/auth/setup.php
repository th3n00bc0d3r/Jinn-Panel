<div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-8 shadow-xl">
    <h1 class="text-xl font-semibold text-white mb-1">Welcome to JinnPanel</h1>
    <p class="text-sm text-slate-400 mb-6">One-time setup. Create the administrator account for this server to go live.</p>

    <div class="grid grid-cols-2 gap-2 mb-6">
        <?php foreach ($server['services'] as $name => $up): ?>
        <div class="flex items-center gap-2 rounded-lg bg-slate-800/60 border border-slate-700 px-3 py-2">
            <span class="h-1.5 w-1.5 rounded-full <?= $up ? 'bg-emerald-500' : 'bg-red-500' ?>"></span>
            <span class="text-xs text-slate-300"><?= e($name) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (in_array(false, $server['services'], true)): ?>
    <div class="mb-6 rounded-lg bg-amber-950/60 border border-amber-800 text-amber-300 text-xs px-3 py-2">
        One or more services aren't responding yet. You can still finish setup, but check <code>systemctl status</code> for
        anything marked red before relying on it.
    </div>
    <?php endif; ?>

    <?php if ($errors): ?>
    <div class="mb-4 rounded-lg bg-red-950/60 border border-red-800 text-red-300 text-sm px-3 py-2">
        <ul class="list-disc list-inside space-y-0.5">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/setup" class="space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">Setup token</label>
            <input type="text" name="setup_token" required autocomplete="off" spellcheck="false"
                   class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm font-mono text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                   placeholder="printed at the end of install.sh">
            <p class="mt-1 text-[11px] text-slate-500">Also saved on the server in <code>/root/.jinnpanel/setup_token</code>.</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1.5">Admin username</label>
                <input type="text" name="username" required value="<?= e($old['username'] ?? '') ?>"
                       class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1.5">Full name (optional)</label>
                <input type="text" name="full_name" value="<?= e($old['full_name'] ?? '') ?>"
                       class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">Admin email</label>
            <input type="email" name="email" required value="<?= e($old['email'] ?? '') ?>"
                   class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1.5">Password</label>
                <input type="password" name="password" required minlength="10"
                       class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-400 mb-1.5">Confirm password</label>
                <input type="password" name="password_confirm" required minlength="10"
                       class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-sm text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>
        <p class="text-xs text-slate-500">At least 10 characters. This account has full control of every domain, mailbox, and server setting.</p>

        <button type="submit"
                class="w-full rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 hover:from-indigo-400 hover:to-violet-500 text-white font-medium text-sm py-2.5 transition-colors shadow-lg shadow-indigo-950/50">
            Create admin account &amp; finish setup
        </button>
    </form>

    <p class="mt-6 text-xs text-slate-500">
        Server: <span class="text-slate-300 font-mono"><?= e($server['hostname']) ?></span>
        &middot; <span class="text-slate-300 font-mono"><?= e($server['ip']) ?></span>
    </p>
</div>
