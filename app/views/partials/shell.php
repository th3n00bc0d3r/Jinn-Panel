<?php
/**
 * Shared app shell for both the server side (WHM) and the hosting account
 * side (cPanel). Expects:
 * $nav           list<array{label:?string, items:list<array{href:string,label:string,icon:string}>}>
 * $quickActions  list<array{href:string,label:string,icon:string}>  extra entries for the Ctrl-K menu
 * $roleLabel     string  shown under the user name
 * $bell          string  optional top-bar alerts button (HTML)
 * $securityHref  string  the login security page
 * $content       string  rendered page HTML
 * $title         string  page title
 * Views add to it with View::slot('actions'|'desc', ...), View::breadcrumbs(), View::script().
 */
$me = Auth::user();
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$quickActions ??= [];

// The current section: exact match, or the longest nav link this page sits under.
$activeHref = null;
$activeGroup = null;
foreach ($nav as $group) {
    foreach ($group['items'] as $item) {
        $h = $item['href'];
        $hit = $path === $h || (substr_count($h, '/') > 1 && str_starts_with($path, $h . '/'));
        if ($hit && ($activeHref === null || strlen($h) > strlen($activeHref))) {
            $activeHref = $h;
            $activeGroup = $group['label'];
        }
    }
}

$crumbs = View::crumbs();
if ($crumbs === null) {
    $crumbs = [];
    if ($activeGroup !== null) {
        $crumbs[] = [$activeGroup];
    }
    $crumbs[] = [$title ?? ''];
}
// The trail shows above the title only when it leads somewhere (a parent page link).
$trail = count(array_filter(array_slice($crumbs, 0, -1), fn($c) => !empty($c[1]))) > 0;

// Ctrl-K menu: every page in the sidebar plus the quick actions.
$palette = [];
foreach ($nav as $group) {
    foreach ($group['items'] as $item) {
        $palette[] = ['label' => $item['label'], 'href' => $item['href'], 'group' => $group['label'] ?? 'Pages'];
    }
}
foreach ($quickActions as $qa) {
    $palette[] = ['label' => $qa['label'], 'href' => $qa['href'], 'group' => 'Actions'];
}
$palette[] = ['label' => 'Login security', 'href' => $securityHref, 'group' => 'Account'];

$initials = strtoupper(substr((string) ($me['username'] ?? '?'), 0, 2));
$flashes = Flash::pull();
$impersonator = $_SESSION['impersonator'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= e($title ?? '') ?> · <?= e(Config::APP_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/theme.js"></script>
</head>
<body class="min-h-screen">
<a href="#main" class="skip-link">Skip to content</a>

<!-- Sidebar: on the canvas, the active page a raised tab -->
<aside id="sidebar" class="fixed inset-y-0 left-0 z-40 flex w-60 -translate-x-full flex-col bg-canvas max-lg:border-r max-lg:border-line lg:translate-x-0" aria-label="Main">
    <div class="flex h-16 shrink-0 items-center gap-2.5 px-5">
        <a href="<?= e($nav[0]['items'][0]['href'] ?? '/') ?>" class="flex min-w-0 items-center gap-2.5 rounded-lg">
            <svg class="h-7 w-7 shrink-0" viewBox="0 0 140 140" aria-hidden="true"><defs><linearGradient id="jp-mark" x1="0" y1="0" x2="140" y2="140" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#7c3aed"/></linearGradient></defs><rect width="140" height="140" rx="32" fill="url(#jp-mark)"/><path d="M86 35v57c0 17.5-11 28.5-29.5 28.5-12 0-20.8-5-26.3-14.2l12-11c3.3 5.5 7.7 8.8 13.7 8.8 8.2 0 12.6-4.9 12.6-16.4V35H86Z" fill="#fff"/><rect x="96" y="31" width="13" height="13" rx="3" fill="#fff" fill-opacity=".55"/></svg>
            <span class="min-w-0 leading-tight">
                <span class="block text-[14px] font-semibold text-fg"><?= e(Config::APP_NAME) ?></span>
                <span class="block truncate font-mono text-[11.5px] text-subtle"><?= e(Config::SERVER_HOSTNAME) ?></span>
            </span>
        </a>
        <button type="button" class="btn btn-icon ml-auto lg:hidden" data-sidebar-close aria-label="Close menu"><?= icon('close', 'h-5 w-5') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 pb-6">
        <?php foreach ($nav as $group): ?>
            <?php if (!empty($group['label'])): ?><p class="nav-section"><?= e($group['label']) ?></p><?php else: ?><div class="pt-1"></div><?php endif; ?>
            <ul class="space-y-0.5">
            <?php foreach ($group['items'] as $item): ?>
                <li><a href="<?= e($item['href']) ?>" class="nav-link"<?= $item['href'] === $activeHref ? ' aria-current="page"' : '' ?>><?= icon($item['icon']) ?><span><?= e($item['label']) ?></span></a></li>
            <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
</aside>
<div id="sidebar-backdrop" class="fixed inset-0 z-30 hidden bg-black/40 lg:hidden" data-sidebar-close></div>

<div class="flex min-h-screen min-w-0 flex-col lg:pl-60">
    <header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 bg-canvas/90 px-4 backdrop-blur sm:px-6 lg:pl-2 lg:pr-8">
        <button type="button" class="btn btn-icon -ml-1 lg:hidden" id="sidebar-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 'h-5 w-5') ?></button>

        <button type="button" class="flex h-9 min-w-0 max-w-md flex-1 items-center gap-2 rounded-[9px] border border-line bg-surface px-3 text-left text-[13.5px] text-subtle transition-colors hover:border-line-strong" data-palette-open>
            <?= icon('search', 'h-4 w-4 shrink-0') ?><span class="truncate"><?= ($me['role'] ?? '') === 'user' ? 'Find a domain or page' : 'Find an account, domain or page' ?></span><kbd class="kbd ml-auto hidden sm:inline-flex">Ctrl K</kbd>
        </button>

        <div class="ml-auto flex items-center gap-1">
        <?= $bell ?? '' ?>
        <button type="button" class="btn btn-icon" data-theme-toggle aria-label="Theme" title="Theme">
            <span data-theme-icon="light"><?= icon('sun', 'h-5 w-5') ?></span>
            <span data-theme-icon="dark" hidden><?= icon('moon', 'h-5 w-5') ?></span>
            <span data-theme-icon="system" hidden><?= icon('monitor', 'h-5 w-5') ?></span>
        </button>

        <details class="menu" data-menu>
            <summary class="ml-1 flex cursor-pointer items-center gap-2 rounded-[9px] p-1 pr-2 hover:bg-surface-3" aria-label="Account menu">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent text-xs font-semibold text-accent-fg"><?= e($initials) ?></span>
                <span class="hidden text-left leading-tight md:block"><span class="block text-[13px] font-medium text-fg"><?= e($me['username'] ?? '') ?></span><span class="block text-[11.5px] text-subtle"><?= e($roleLabel) ?></span></span>
            </summary>
            <div class="menu-panel">
                <div class="px-2.5 pb-2 pt-1.5">
                    <p class="truncate text-sm font-medium text-fg"><?= e($me['username'] ?? '') ?></p>
                    <p class="truncate text-xs text-muted"><?= e($roleLabel) ?><?= !empty($me['email']) ? ' · ' . e($me['email']) : '' ?></p>
                </div>
                <div class="menu-sep"></div>
                <a href="<?= e($securityHref) ?>" class="menu-item"><?= icon('shield') ?>Login security</a>
                <div class="menu-sep"></div>
                <form method="post" action="/logout">
                    <?= Csrf::field() ?>
                    <button type="submit" class="menu-item"><?= icon('logout') ?>Sign out</button>
                </form>
            </div>
        </details>
        </div>
    </header>

    <?php if ($impersonator): ?>
    <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-2 border-b border-warning/30 bg-warning-soft px-4 py-2 text-sm text-warning-text">
        <span><?= icon('eye', 'mr-1 inline h-4 w-4 align-[-3px]') ?>You are viewing <strong><?= e($me['username'] ?? '') ?></strong>'s panel as <strong><?= e((string) ($impersonator['username'] ?? '')) ?></strong>. Changes you make are real.</span>
        <form method="post" action="/impersonate/stop">
            <?= Csrf::field() ?>
            <button class="btn btn-secondary btn-sm"><?= icon('arrow-left') ?>Back to <?= e((string) ($impersonator['username'] ?? 'admin')) ?></button>
        </form>
    </div>
    <?php endif; ?>

    <main id="main" class="flex-1 px-4 pb-12 pt-2 sm:px-6 lg:pl-2 lg:pr-8" tabindex="-1">
        <div class="page">
            <?php $actions = View::slotGet('actions'); $desc = View::slotGet('desc'); ?>
            <?php if ($trail): ?>
            <nav aria-label="Breadcrumb" class="mb-1.5">
                <ol class="flex min-w-0 flex-wrap items-center gap-1.5 text-[13px]">
                    <?php foreach ($crumbs as $i => $c): $last = $i === count($crumbs) - 1; ?>
                    <li class="flex min-w-0 items-center gap-1.5">
                        <?php if ($i > 0): ?><span class="text-subtle" aria-hidden="true"><?= icon('chevron-right', 'h-3.5 w-3.5') ?></span><?php endif; ?>
                        <?php if (!$last && !empty($c[1])): ?>
                        <a href="<?= e($c[1]) ?>" class="truncate text-muted hover:text-fg"><?= e($c[0]) ?></a>
                        <?php else: ?>
                        <span class="truncate text-subtle"<?= $last ? ' aria-current="page"' : '' ?>><?= e($c[0]) ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php endif; ?>
            <?php if (View::slotGet('header') !== 'none'): ?>
            <div class="page-header">
                <div class="min-w-0">
                    <h1 class="page-title"><?= e($title ?? '') ?></h1>
                    <?php if ($desc !== ''): ?><p class="page-desc"><?= $desc ?></p><?php endif; ?>
                </div>
                <?php if ($actions !== ''): ?><div class="flex flex-wrap items-center gap-2"><?= $actions ?></div><?php endif; ?>
            </div>
            <?php endif; ?>

            <?= $content ?>
        </div>
    </main>
</div>

<div id="toasts" class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:left-auto sm:right-6 sm:w-96">
    <?php foreach ($flashes as $f): $err = $f['type'] === 'error'; ?>
    <div class="toast" role="<?= $err ? 'alert' : 'status' ?>" data-toast<?= $err ? '' : ' data-autohide' ?>>
        <?= icon($err ? 'x-circle' : 'check-circle', $err ? 'text-danger' : 'text-success') ?>
        <p class="min-w-0 flex-1 break-words"><?= e($f['message']) ?></p>
        <button type="button" class="-m-1 rounded p-1 text-subtle hover:text-fg" data-toast-close aria-label="Dismiss"><?= icon('close', 'h-4 w-4') ?></button>
    </div>
    <?php endforeach; ?>
</div>

<dialog id="jp-dialog" class="dialog" aria-labelledby="jp-dialog-title">
    <form method="dialog">
        <div class="dialog-body">
            <h2 id="jp-dialog-title" class="dialog-title"></h2>
            <p class="dialog-text" data-dialog-text></p>
            <div class="mt-4" data-dialog-input hidden>
                <label for="jp-dialog-value" class="label" data-dialog-label></label>
                <input id="jp-dialog-value" class="input font-mono" autocomplete="off" spellcheck="false">
            </div>
        </div>
        <div class="dialog-footer">
            <button value="cancel" class="btn btn-secondary">Cancel</button>
            <button value="ok" class="btn btn-primary" data-dialog-ok>OK</button>
        </div>
    </form>
</dialog>

<dialog id="jp-palette" class="dialog max-w-lg self-start sm:mt-[12vh]" aria-label="Go to" data-search="/search">
    <div class="flex items-center gap-3 border-b border-line px-4">
        <?= icon('search', 'h-4 w-4 text-subtle') ?>
        <input type="text" class="w-full bg-transparent py-3.5 text-sm text-fg placeholder:text-subtle focus:outline-none" placeholder="<?= ($me['role'] ?? '') === 'user' ? 'Go to a page or domain…' : 'Go to a page, account or domain…' ?>" aria-label="Search pages, accounts and domains" data-palette-input autocomplete="off" role="combobox" aria-expanded="true" aria-controls="jp-palette-list">
        <kbd class="kbd">Esc</kbd>
    </div>
    <ul id="jp-palette-list" class="max-h-80 overflow-y-auto p-2" role="listbox" data-palette-list></ul>
    <p class="hidden px-4 py-8 text-center text-sm text-muted" data-palette-empty>Nothing matches.</p>
</dialog>

<script type="application/json" id="jp-palette-data"><?= json_encode($palette, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/js/app.js" defer></script>
<?php foreach (View::scripts() as $s): ?>
<script src="/assets/js/pages/<?= e($s) ?>.js" defer></script>
<?php endforeach; ?>
</body>
</html>
