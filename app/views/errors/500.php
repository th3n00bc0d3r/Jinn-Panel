<?php
View::slot('title', 'Something went wrong');
// A page that failed to load can simply be asked for again; a sent form can't.
// Always a path on this host ("//x" or "/\x" would lead elsewhere).
$again = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && is_string($_SERVER['REQUEST_URI'] ?? null)
    ? '/' . ltrim($_SERVER['REQUEST_URI'], '/\\')
    : null;
?>
<p class="error-code"><span class="sr-only">Error </span>500</p>
<h1 class="auth-title mt-6">Something went wrong</h1>
<p class="mt-3 text-[15px] leading-relaxed text-muted">The panel hit an error it could not handle, and logged it for the administrator. Try again in a moment. If it keeps happening, tell your hosting provider what you were doing.</p>
<div class="mt-8 flex flex-wrap gap-3">
    <?php if ($again !== null): ?>
    <a href="<?= e($again) ?>" class="btn btn-primary h-10"><?= icon('refresh') ?>Try again</a>
    <a href="/" class="btn btn-secondary h-10"><?= icon('home') ?>Go to the dashboard</a>
    <?php else: ?>
    <a href="/" class="btn btn-primary h-10"><?= icon('home') ?>Go to the dashboard</a>
    <?php endif; ?>
</div>
