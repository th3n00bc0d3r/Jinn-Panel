<?php View::slot('title', 'Page not found'); ?>
<p class="error-code"><span class="sr-only">Error </span>404</p>
<h1 class="auth-title mt-6">Page not found</h1>
<p class="mt-3 text-[15px] leading-relaxed text-muted">There is nothing at this address. The page may have moved, or the link may have a typo in it.</p>
<div class="mt-8 flex flex-wrap gap-3">
    <a href="/" class="btn btn-primary h-10"><?= icon('home') ?>Go to the dashboard</a>
</div>
