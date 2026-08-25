<?php
/**
 * Shared app shell for both WHM and cPanel. Expects:
 * $navItems  array<int, array{href:string,label:string,icon:string}>
 * $badge     string  e.g. "WHM" / "cPanel"
 * $accent    string  tailwind color name, e.g. "indigo" / "sky"
 * $content   string  rendered page HTML
 * $title     string  page title
 */
$me = Auth::user();
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? '') ?> &mdash; <?= e(Config::APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">

<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside id="sidebar" class="fixed z-30 inset-y-0 left-0 w-64 -translate-x-full lg:translate-x-0 transition-transform duration-200 bg-slate-950 text-slate-300 flex flex-col">
        <div class="h-16 flex items-center gap-2 px-5 border-b border-slate-800/80 shrink-0">
            <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-<?= $accent ?>-500 to-<?= $accent ?>-700 flex items-center justify-center font-bold text-white text-sm">J</div>
            <span class="font-semibold text-white tracking-tight"><?= e(Config::APP_NAME) ?></span>
            <span class="ml-auto text-[10px] uppercase tracking-wider font-semibold text-<?= $accent ?>-400 bg-<?= $accent ?>-950 border border-<?= $accent ?>-800 rounded px-1.5 py-0.5"><?= e($badge) ?></span>
        </div>
        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            <?php foreach ($navItems as $item): ?>
                <?php if (isset($item['section'])): ?>
                <p class="px-3 pt-4 pb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600"><?= e($item['section']) ?></p>
                <?php continue; endif; ?>
                <?php $active = $path === $item['href'] || str_starts_with($path, $item['href'] . '/'); ?>
            <a href="<?= e($item['href']) ?>"
               class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors <?= $active ? "bg-{$accent}-600 text-white" : 'text-slate-400 hover:bg-slate-900 hover:text-white' ?>">
                <?= icon($item['icon'], 'h-5 w-5 shrink-0') ?>
                <span><?= e($item['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="border-t border-slate-800/80 p-3">
            <div class="flex items-center gap-3 rounded-lg px-2 py-2">
                <div class="h-8 w-8 rounded-full bg-slate-800 flex items-center justify-center text-xs font-semibold text-slate-300 uppercase shrink-0">
                    <?= e(substr($me['username'] ?? '?', 0, 2)) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-white truncate"><?= e($me['username'] ?? '') ?></p>
                    <p class="text-xs text-slate-500 truncate capitalize"><?= e($me['role'] ?? '') ?></p>
                </div>
                <a href="/logout" title="Sign out" class="text-slate-500 hover:text-white p-1.5 rounded-md hover:bg-slate-900">
                    <?= icon('logout', 'h-5 w-5') ?>
                </a>
            </div>
        </div>
    </aside>
    <div id="sidebar-backdrop" class="fixed inset-0 bg-black/50 z-20 hidden lg:hidden"></div>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0 lg:pl-64">
        <header class="h-16 shrink-0 bg-white border-b border-slate-200 flex items-center gap-4 px-4 sm:px-6 sticky top-0 z-10">
            <button id="sidebar-toggle" class="lg:hidden text-slate-500 hover:text-slate-800">
                <?= icon('menu', 'h-6 w-6') ?>
            </button>
            <h1 class="text-lg font-semibold text-slate-800"><?= e($title ?? '') ?></h1>
            <div class="ml-auto flex items-center gap-2 text-sm text-slate-400">
                <?= icon('server', 'h-4 w-4') ?>
                <span><?= e(Config::SERVER_HOSTNAME) ?></span>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <div class="mx-auto max-w-6xl space-y-4">
                <?php foreach (Flash::pull() as $f): ?>
                <div data-flash class="rounded-lg border px-4 py-3 text-sm flex items-start gap-2 <?= $f['type'] === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-emerald-50 border-emerald-200 text-emerald-800' ?>">
                    <?= icon($f['type'] === 'error' ? 'alert' : 'check', 'h-5 w-5 mt-0.5 shrink-0') ?>
                    <span><?= e($f['message']) ?></span>
                </div>
                <?php endforeach; ?>

                <?= $content ?>
            </div>
        </main>
    </div>
</div>

<script src="/assets/js/app.js"></script>
</body>
</html>
