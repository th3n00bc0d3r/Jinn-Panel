<?php
/** @var string $feature a Features::LIST key */
[$label, $what] = Features::LIST[$feature] ?? [$feature, ''];
View::slot('header', 'none');
?>
<div class="card mx-auto mt-6 max-w-lg">
    <?= Ui::empty($label . ' isn\'t in your package', $what . ' Your hosting provider can add it to your package or turn it on for your account.', 'lock', '<a href="/cpanel" class="btn btn-secondary">' . icon('arrow-left') . 'Back to the dashboard</a>') ?>
</div>
