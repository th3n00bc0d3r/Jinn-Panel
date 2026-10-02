<div class="max-w-xl">
<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6">
    <?php if (!empty($errors)): ?>
    <div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3">
        <ul class="list-disc list-inside space-y-0.5">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="/whm/accounts" class="space-y-4">
        <?= Csrf::field() ?>

        <?php if ($canCreateReseller): ?>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Account type</label>
            <select name="role" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="user">Hosting account (cPanel end user)</option>
                <option value="reseller">Reseller (gets their own WHM)</option>
            </select>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Username</label>
                <?php /* Hosting accounts: Usernames::problem(); resellers: Usernames::loginProblem(). */ ?>
                <input type="text" name="username" required pattern="<?= !empty($canCreateReseller) ? '[A-Za-z][A-Za-z0-9_]{2,31}' : '[A-Za-z][A-Za-z0-9]{2,15}' ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <p class="mt-1 text-xs text-slate-400">3-16 letters or digits, starting with a letter<?= !empty($canCreateReseller) ? ' (resellers: up to 32, underscores allowed)' : '' ?>.</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1.5">Full name</label>
                <input type="text" name="full_name"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Email</label>
            <input type="email" name="email" required
                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Password</label>
            <input type="password" name="password" required minlength="<?= Passwords::MIN_LENGTH ?>"
                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <p class="mt-1 text-xs text-slate-400">At least <?= Passwords::MIN_LENGTH ?> characters, not a common password.</p>
        </div>

        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1.5">Hosting package</label>
            <select name="package_id" class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">— None (resellers only; hosting accounts need one) —</option>
                <?php foreach ($packages as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> &middot; <?= (int) $p['disk_quota_mb'] ?>MB disk / <?= (int) $p['max_domains'] ?> domains</option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Create account</button>
            <a href="/whm/accounts" class="rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-medium px-5 py-2.5 transition-colors">Cancel</a>
        </div>
    </form>
</div>
</div>
