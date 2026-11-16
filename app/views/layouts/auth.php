<?php
/**
 * Signed-out pages: sign in, two-factor, forgot and reset password, first-run
 * setup. On wide screens a brand panel with the server's name sits beside the
 * form; on phones only the form, under a small header.
 * Views hand it (View::slot):
 *   title       tab title (plain text) when the controller passes none
 *   wide        any value: a wider form column (setup)
 *   brand_lead  the line above the server name (default "You are signing in to")
 *   brand_note  the line at the foot of the panel, and under the form on phones
 * brand_lead and brand_note are raw HTML: escape what goes in them.
 */
$host = Config::SERVER_HOSTNAME;
// The name breaks after a dot when it has to, with the dots in the light violet.
$hostHtml = implode('<span class="auth-dot">.</span><wbr>', array_map(fn(string $part): string => e($part), explode('.', $host)));
$wide = View::slotGet('wide') !== '';
$pageTitle = $title ?? (View::slotGet('title') !== '' ? View::slotGet('title') : 'Sign in');
$lead = View::slotGet('brand_lead') !== '' ? View::slotGet('brand_lead') : e('You are signing in to');
$note = View::slotGet('brand_note') !== '' ? View::slotGet('brand_note') : e('Trouble signing in? Your hosting provider can reset your password.');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($pageTitle) ?> · <?= e(Config::APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/theme.js"></script>
</head>
<body class="min-h-screen bg-surface">
<div class="grid min-h-screen <?= $wide ? 'lg:grid-cols-[minmax(0,0.85fr)_minmax(0,1.15fr)]' : 'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' ?>">
    <aside class="auth-brand relative hidden flex-col justify-between overflow-hidden px-12 py-10 lg:flex" aria-label="This server">
        <div class="flex items-center gap-2.5">
            <svg class="h-8 w-8" viewBox="0 0 140 140" aria-hidden="true"><rect width="140" height="140" rx="32" fill="#fff" fill-opacity=".12"/><path d="M86 35v57c0 17.5-11 28.5-29.5 28.5-12 0-20.8-5-26.3-14.2l12-11c3.3 5.5 7.7 8.8 13.7 8.8 8.2 0 12.6-4.9 12.6-16.4V35H86Z" fill="#fff"/><rect x="96" y="31" width="13" height="13" rx="3" fill="#a996ff"/></svg>
            <span class="text-[15px] font-semibold"><?= e(Config::APP_NAME) ?></span>
        </div>
        <div>
            <p class="text-[13px] text-white/60"><?= $lead ?></p>
            <p class="auth-host mt-3" style="--host-chars: <?= max(8, strlen($host)) ?>"><?= $hostHtml ?></p>
            <p class="mt-6 max-w-md text-[15px] leading-relaxed text-white/70">The control panel for the websites, mailboxes and databases hosted on this server.</p>
        </div>
        <p class="text-[13px] text-white/50"><?= $note ?></p>
    </aside>

    <main id="main" class="flex min-w-0 flex-col px-6 py-8 sm:px-10">
        <div class="flex items-center gap-2.5 lg:hidden">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 140 140" aria-hidden="true"><defs><linearGradient id="jp-mark" x1="0" y1="0" x2="140" y2="140" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs><rect width="140" height="140" rx="32" fill="url(#jp-mark)"/><path d="M86 35v57c0 17.5-11 28.5-29.5 28.5-12 0-20.8-5-26.3-14.2l12-11c3.3 5.5 7.7 8.8 13.7 8.8 8.2 0 12.6-4.9 12.6-16.4V35H86Z" fill="#fff"/><rect x="96" y="31" width="13" height="13" rx="3" fill="#fff" fill-opacity=".55"/></svg>
            <span class="min-w-0 truncate font-mono text-[13px] text-muted"><span class="sr-only"><?= e(Config::APP_NAME) ?> on </span><?= e($host) ?></span>
        </div>
        <div class="my-auto w-full self-center py-10 <?= $wide ? 'max-w-xl' : 'max-w-[22rem]' ?>">
            <?php foreach (Flash::pull() as $f): ?>
            <div class="mb-6"><?= Ui::alert($f['message'], $f['type'] === 'error' ? 'danger' : 'success') ?></div>
            <?php endforeach; ?>
            <?= $content ?>
        </div>
        <p class="text-center text-[12px] text-subtle lg:hidden"><?= $note ?></p>
    </main>
</div>
<div id="toasts" class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:left-auto sm:right-6 sm:w-96"></div>
<script src="/assets/js/app.js" defer></script>
<?php foreach (View::scripts() as $s): ?>
<script src="/assets/js/pages/<?= e($s) ?>.js" defer></script>
<?php endforeach; ?>
</body>
</html>
