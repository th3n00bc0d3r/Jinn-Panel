<?php
declare(strict_types=1);

/**
 * Renders every view with its fixture and every variant, and fails on:
 *  - any PHP error, warning or notice, or an exception;
 *  - a view without a fixture (every view must render here);
 *  - accessibility basics: form controls without a label, buttons and
 *    links without a name, images without alt.
 *
 *   php dev/preview/render-all.php           # check everything
 *   php dev/preview/render-all.php cpanel/   # only views starting with that
 *   php dev/preview/render-all.php --out=DIR # also write the HTML (screenshots)
 */

require __DIR__ . '/bootstrap.php';

set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$filter = '';
$outDir = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } else {
        $filter = $arg;
    }
}

$fixtures = preview_fixtures();
$failures = [];
$warnings = [];
$count = 0;

// Every view file needs a fixture (layouts, partials and components are rendered through the pages).
$views = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PREVIEW_APP . '/views', FilesystemIterator::SKIP_DOTS));
foreach ($views as $f) {
    $rel = substr($f->getPathname(), strlen(PREVIEW_APP . '/views/'), -4);
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if ($f->getExtension() !== 'php' || preg_match('#^(layouts|partials|components)/#', $rel) || str_starts_with(basename($rel), '_')) {
        continue;
    }
    if ($filter !== '' && !str_starts_with($rel, $filter)) {
        continue;
    }
    if (!isset($fixtures[$rel])) {
        $failures[] = "$rel: no fixture (add dev/preview/fixtures/$rel.php)";
    }
}

foreach ($fixtures as $view => $fx) {
    if ($filter !== '' && !str_starts_with($view, $filter)) {
        continue;
    }
    foreach (array_merge([null], array_keys($fx['variants'] ?? [])) as $variant) {
        $label = $view . ($variant !== null ? " [$variant]" : '');
        try {
            $html = preview_render($view, $fx, $variant === null ? null : (string) $variant);
        } catch (Throwable $e) {
            $failures[] = "$label: " . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            continue;
        }
        $count++;
        foreach (a11y_problems($html) as $p) {
            $warnings[] = "$label: $p";
        }
        if ($outDir !== null) {
            if (!is_dir($outDir)) {
                mkdir($outDir, 0777, true);
            }
            file_put_contents($outDir . '/' . str_replace('/', '__', $view) . ($variant !== null ? "--$variant" : '') . '.html', $html);
        }
    }
}

/** @return list<string> */
function a11y_problems(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $problems = [];

    $labelled = [];
    foreach ($xp->query('//label[@for]') as $l) {
        $labelled[$l->getAttribute('for')] = true;
    }
    foreach ($xp->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea') as $el) {
        /** @var DOMElement $el */
        $id = $el->getAttribute('id');
        $ok = ($id !== '' && isset($labelled[$id]))
            || $el->getAttribute('aria-label') !== ''
            || $el->getAttribute('aria-labelledby') !== ''
            || $el->getAttribute('title') !== ''
            || $xp->query('ancestor::label', $el)->length > 0;
        if (!$ok) {
            $problems[] = '<' . $el->nodeName . ' name="' . $el->getAttribute('name') . '"> has no label';
        }
    }
    foreach ($xp->query('//button | //a[@href]') as $el) {
        /** @var DOMElement $el */
        $name = trim($el->textContent) !== '' || $el->getAttribute('aria-label') !== '' || $el->getAttribute('title') !== '';
        if (!$name) {
            $problems[] = '<' . $el->nodeName . (($h = $el->getAttribute('href')) !== '' ? ' href="' . $h . '"' : '') . '> has no accessible name';
        }
    }
    foreach ($xp->query('//img[not(@alt)]') as $el) {
        $problems[] = '<img> without alt';
    }
    return array_values(array_unique($problems));
}

foreach ($warnings as $w) {
    fwrite(STDERR, "  a11y  $w\n");
}
foreach ($failures as $f) {
    fwrite(STDERR, "  FAIL  $f\n");
}
$bad = count($failures) + count($warnings);
echo ($bad ? 'FAILED' : 'OK') . ": $count page renders, " . count($failures) . ' failures, ' . count($warnings) . " accessibility problems\n";
exit($bad ? 1 : 0);
