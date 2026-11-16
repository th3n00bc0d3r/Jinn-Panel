<?php
/**
 * Error pages (404, 419, 500): the brand and the server's name above one
 * short message. Rendered when something failed, so it uses nothing that
 * can fail in turn (no session, no database). A view can set the tab title
 * with View::slot('title', 'plain text') when the caller passes none.
 */
$pageTitle = $title ?? View::slotGet('title');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= $pageTitle !== '' ? e($pageTitle) . ' · ' : '' ?><?= e(Config::APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/theme.js"></script>
</head>
<body class="flex min-h-screen flex-col bg-surface">
<header class="flex items-center gap-2.5 px-6 py-8 sm:px-10">
    <svg class="h-7 w-7 shrink-0" viewBox="0 0 140 140" aria-hidden="true"><defs><linearGradient id="jp-mark" x1="0" y1="0" x2="140" y2="140" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs><rect width="140" height="140" rx="32" fill="url(#jp-mark)"/><path d="M86 35v57c0 17.5-11 28.5-29.5 28.5-12 0-20.8-5-26.3-14.2l12-11c3.3 5.5 7.7 8.8 13.7 8.8 8.2 0 12.6-4.9 12.6-16.4V35H86Z" fill="#fff"/><rect x="96" y="31" width="13" height="13" rx="3" fill="#fff" fill-opacity=".55"/></svg>
    <span class="text-[14px] font-semibold text-fg"><?= e(Config::APP_NAME) ?></span>
    <span class="min-w-0 truncate font-mono text-[13px] text-subtle"><?= e(Config::SERVER_HOSTNAME) ?></span>
</header>
<main id="main" class="flex flex-1 items-center justify-center px-6 pb-24 pt-6 sm:px-10">
    <div class="w-full max-w-[30rem]"><?= $content ?></div>
</main>
</body>
</html>
