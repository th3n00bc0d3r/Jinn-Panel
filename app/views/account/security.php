<?php
/** @var array<string,mixed> $me */
/** @var array{secret:string,uri:string,qr:?string}|null $setup */
/** @var string $base */
/** @var string $accent */
$card = 'bg-white rounded-xl border border-slate-200 shadow-sm';
$ring = $accent === 'sky' ? 'focus:ring-sky-500 focus:border-sky-500' : 'focus:ring-indigo-500 focus:border-indigo-500';
$input = "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 $ring";
$label = 'block text-xs font-medium text-slate-600 mb-1';
$button = $accent === 'sky' ? 'bg-sky-600 hover:bg-sky-500' : 'bg-indigo-600 hover:bg-indigo-500';
$on = !empty($me['totp_secret_enc']);
?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <div class="<?= $card ?> p-5">
        <h2 class="text-sm font-semibold text-slate-700">Change password</h2>
        <p class="text-xs text-slate-400 mt-0.5 mb-4">At least <?= Passwords::MIN_LENGTH ?> characters. Changing it signs out your other sessions.</p>
        <form method="post" action="<?= e($base) ?>/password" class="space-y-3">
            <?= Csrf::field() ?>
            <div><label class="<?= $label ?>">Current password</label><input type="password" name="current_password" required autocomplete="current-password" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">New password</label><input type="password" name="new_password" required minlength="<?= Passwords::MIN_LENGTH ?>" autocomplete="new-password" class="<?= $input ?>"></div>
            <div><label class="<?= $label ?>">New password again</label><input type="password" name="new_password_confirm" required minlength="<?= Passwords::MIN_LENGTH ?>" autocomplete="new-password" class="<?= $input ?>"></div>
            <button class="rounded-lg <?= $button ?> text-white text-sm font-medium px-4 py-2">Change password</button>
        </form>
    </div>

    <div class="<?= $card ?> p-5">
        <div class="flex items-center gap-2">
            <h2 class="text-sm font-semibold text-slate-700">Two-factor sign-in</h2>
            <span class="text-xs font-medium rounded-full px-2 py-0.5 <?= $on ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' ?>"><?= $on ? 'On' : 'Off' ?></span>
        </div>
        <p class="text-xs text-slate-400 mt-0.5 mb-4">A 6-digit code from an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password, Aegis...) at every sign-in, on top of your password.</p>

        <?php if ($on): ?>
        <form method="post" action="<?= e($base) ?>/2fa/disable" class="space-y-3" data-confirm="Turn off two-factor sign-in?">
            <?= Csrf::field() ?>
            <div><label class="<?= $label ?>">Your password, to turn it off</label><input type="password" name="current_password" required autocomplete="current-password" class="<?= $input ?>"></div>
            <button class="rounded-lg border border-red-200 text-red-700 hover:bg-red-50 text-sm font-medium px-4 py-2">Turn off two-factor</button>
        </form>
        <?php elseif ($setup !== null): ?>
        <ol class="text-sm text-slate-600 space-y-3 list-decimal list-inside">
            <li>Scan this code with your authenticator app<?= $setup['qr'] === null ? ' - or add the key below by hand' : '' ?>:
                <?php if ($setup['qr'] !== null): ?>
                <div class="mt-2 w-48 h-48 [&>svg]:w-full [&>svg]:h-full bg-white p-2 rounded-lg border border-slate-200"><?= $setup['qr'] ?></div>
                <?php endif; ?>
                <p class="mt-2 text-xs text-slate-500">Key (if you can't scan): <code class="font-mono text-slate-700 break-all select-all"><?= e(trim(chunk_split($setup['secret'], 4, ' '))) ?></code></p>
                <p class="text-xs"><a href="<?= e($setup['uri']) ?>" class="text-slate-500 underline">Open in an authenticator app on this device</a></p>
            </li>
            <li>Enter the code it shows:
                <form method="post" action="<?= e($base) ?>/2fa/confirm" class="mt-2 flex gap-2">
                    <?= Csrf::field() ?>
                    <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" class="<?= $input ?> max-w-[10rem] tracking-[0.3em] text-center">
                    <button class="rounded-lg <?= $button ?> text-white text-sm font-medium px-4 py-2">Turn on</button>
                </form>
            </li>
        </ol>
        <?php else: ?>
        <form method="post" action="<?= e($base) ?>/2fa/start">
            <?= Csrf::field() ?>
            <button class="rounded-lg <?= $button ?> text-white text-sm font-medium px-4 py-2">Set up two-factor sign-in</button>
        </form>
        <?php endif; ?>
    </div>
</div>
