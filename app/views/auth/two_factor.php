<div class="bg-slate-900/70 border border-slate-800 rounded-2xl p-8 shadow-xl">
    <h1 class="text-xl font-semibold text-white mb-1">Two-factor sign-in</h1>
    <p class="text-sm text-slate-400 mb-6">Enter the 6-digit code from your authenticator app.</p>

    <?php if (!empty($error)): ?>
    <div class="mb-4 rounded-lg bg-red-950/60 border border-red-800 text-red-300 text-sm px-3 py-2">
        <?= e($error) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="/login/2fa" class="space-y-4">
        <?= Csrf::field() ?>
        <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">Authentication code</label>
            <input type="text" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                   class="w-full rounded-lg bg-slate-800/80 border border-slate-700 px-3 py-2.5 text-lg tracking-[0.4em] text-center text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <button type="submit"
                class="w-full rounded-lg bg-gradient-to-br from-indigo-500 to-violet-600 hover:from-indigo-400 hover:to-violet-500 text-white font-medium text-sm py-2.5 transition-colors shadow-lg shadow-indigo-950/50">
            Verify
        </button>
        <p class="text-center text-xs"><a href="/login" class="text-slate-500 hover:text-slate-300">Start over</a></p>
    </form>
</div>
