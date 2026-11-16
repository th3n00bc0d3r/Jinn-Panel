<?php
/** @var string|null $back the page the form was on, if it was on this panel */
View::slot('title', 'Form expired');
?>
<p class="error-code"><span class="sr-only">Error </span>419</p>
<h1 class="auth-title mt-6">This form expired</h1>
<p class="mt-3 text-[15px] leading-relaxed text-muted">Nothing was changed. For your safety a form only works for a while, and only from the page it came from. Open it again and send it once more.</p>
<div class="mt-8 flex flex-wrap gap-3">
    <?php if (!empty($back)): ?>
    <a href="<?= e($back) ?>" class="btn btn-primary h-10"><?= icon('arrow-left') ?>Back to the form</a>
    <a href="/" class="btn btn-secondary h-10"><?= icon('home') ?>Go to the dashboard</a>
    <?php else: ?>
    <a href="/" class="btn btn-primary h-10"><?= icon('home') ?>Go to the dashboard</a>
    <?php endif; ?>
</div>
