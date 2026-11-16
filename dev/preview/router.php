<?php
declare(strict_types=1);

/**
 * Browse every panel page on your own machine:
 *
 *   ./dev/preview/serve.sh            (builds the CSS, then serves on :8787)
 *
 * or by hand:
 *
 *   php -S 127.0.0.1:8787 -t app/public dev/preview/router.php
 *
 * Pages open at their real URLs (/cpanel/domains, /whm/accounts...) with
 * the data from dev/preview/fixtures/. Forms don't do anything; a POST
 * shows what would have been sent. ?as=admin|reseller|user switches role,
 * ?variant=<name> shows another state of the page (empty lists, errors).
 */

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($uri !== '/' && is_file(__DIR__ . '/../../app/public' . $uri)) {
    return false; // static asset
}

require __DIR__ . '/bootstrap.php';
// The panel's own policy (app/src/bootstrap.php), so an inline script or
// on*= handler fails here exactly as it would on the server.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
session_name(Config::SESSION_NAME);
session_start();

$fixtures = preview_fixtures();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $fields = $_POST;
    unset($fields['csrf_token']);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/theme.js"></script>'
        . '<body class="p-8"><div class="card mx-auto max-w-2xl p-6"><h1 class="text-lg font-semibold">Preview: nothing was changed</h1>'
        . '<p class="mt-1 text-sm text-muted">On a real server this form would POST to <code>' . e($uri) . '</code> with:</p>'
        . '<pre class="log mt-4">' . e(json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>'
        . '<p class="mt-4"><a class="link" href="javascript:history.back()">Back</a> · <a class="link" href="/__preview">All pages</a></p></div>';
    return true;
}

// Ctrl-K search (SearchController) with sample accounts and domains.
if ($uri === '/search') {
    $q = strtolower(trim((string) ($_GET['q'] ?? '')));
    $all = [
        ['label' => 'acme', 'hint' => 'Hosting account · Acme Ltd', 'href' => '/whm/accounts/10', 'group' => 'Accounts'],
        ['label' => 'bakery', 'hint' => 'Hosting account, suspended · The Bakery', 'href' => '/whm/accounts/10', 'group' => 'Accounts'],
        ['label' => 'resell', 'hint' => 'Reseller · North Web Reselling', 'href' => '/whm/accounts/10', 'group' => 'Accounts'],
        ['label' => 'acme.test', 'hint' => 'Site of acme', 'href' => '/whm/accounts/10', 'group' => 'Domains'],
        ['label' => 'shop.acme.test', 'hint' => 'Site of acme', 'href' => '/whm/accounts/10', 'group' => 'Domains'],
        ['label' => 'bakery.test', 'hint' => 'Site of bakery', 'href' => '/whm/accounts/10', 'group' => 'Domains'],
    ];
    header('Content-Type: application/json');
    echo json_encode(['results' => strlen($q) < 2 ? [] : array_values(array_filter($all, fn($r) => str_contains($r['label'] . ' ' . strtolower($r['hint']), $q)))]);
    return true;
}

// A page at phone width (headless Chrome can't make a window under 500px).
if ($uri === '/__frame') {
    $w = max(320, min(1600, (int) ($_GET['w'] ?? 390)));
    $src = (string) ($_GET['url'] ?? '/');
    if (!str_starts_with($src, '/')) {
        $src = '/';
    }
    echo '<!doctype html><meta charset="utf-8"><body style="margin:0;background:#888">'
        . '<iframe src="' . e($src) . '" style="border:0;width:' . $w . 'px;height:100vh;display:block;background:#fff"></iframe>';
    return true;
}

// Index of every page and variant.
if ($uri === '/__preview' || ($uri === '/' && !isset($_GET['view']))) {
    $groups = [];
    foreach ($fixtures as $view => $fx) {
        $groups[explode('/', $view)[0]][$view] = $fx;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Preview · JinnPanel</title>'
        . '<link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/theme.js"></script><body class="px-4 py-10">'
        . '<div class="page max-w-4xl"><h1 class="page-title">JinnPanel preview</h1><p class="page-desc">Every view, rendered with sample data. ' . count($fixtures) . ' pages.</p><div class="mt-8 grid gap-6 sm:grid-cols-2">';
    foreach ($groups as $g => $views) {
        echo '<section class="card"><h2 class="card-header card-title">' . e($g) . '</h2><ul class="divide-y divide-line">';
        foreach ($views as $view => $fx) {
            $href = '/?view=' . rawurlencode($view);
            echo '<li class="flex flex-wrap items-center gap-2 px-5 py-2.5 text-sm"><a class="link" href="' . e($href) . '">' . e($view) . '</a>';
            foreach (array_keys($fx['variants'] ?? []) as $v) {
                echo '<a class="badge badge-neutral" href="' . e($href . '&variant=' . rawurlencode((string) $v)) . '">' . e((string) $v) . '</a>';
            }
            echo '</li>';
        }
        echo '</ul></section>';
    }
    echo '</div></div></body></html>';
    return true;
}

// A page: by ?view=, or by the URL a fixture says it lives at.
$view = $_GET['view'] ?? null;
if ($view === null) {
    foreach ($fixtures as $name => $fx) {
        if (($fx['path'] ?? null) === $uri) {
            $view = $name;
            break;
        }
    }
}
if ($view === null || !isset($fixtures[$view])) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/app.css"><body class="p-8">'
        . '<p>No preview fixture for <code>' . e($uri) . '</code>. <a class="link" href="/__preview">All pages</a></p>';
    return true;
}

header('Content-Type: text/html; charset=utf-8');
try {
    $html = preview_render($view, $fixtures[$view], $_GET['variant'] ?? null, $_GET['as'] ?? null);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><body style="font:14px ui-monospace,monospace;padding:2rem"><h1>' . e(get_class($e)) . '</h1><pre>'
        . e($e->getMessage() . "\n\n" . $e->getTraceAsString()) . '</pre>';
    return true;
}
echo $html;
return true;
