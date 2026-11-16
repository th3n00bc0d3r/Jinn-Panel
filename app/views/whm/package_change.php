<?php
/**
 * Before a move to a smaller package (one account, several, or a package
 * made smaller): what the accounts already have beyond the new limits.
 */
/** @var string $intro */
/** @var list<array{account: array<string,mixed>, items: list<array{what:string, used:string, limit:string, effect:string}>}> $rows */
/** @var string $action where the confirmed form goes */
/** @var array<string,mixed> $hidden the original form's fields */
/** @var string $confirm the button */
/** @var string $back */
View::breadcrumbs(str_starts_with($back, '/whm/packages') ? [['Packages', '/whm/packages'], ['Check the new limits']] : [['Accounts', '/whm/accounts'], ['Check the new limits']]);
View::slot('desc', e($intro));
// The original form's fields again (arrays as name[key]).
$fields = function (array $data, string $prefix = '') use (&$fields): string {
    $html = '';
    foreach ($data as $k => $v) {
        $name = $prefix === '' ? (string) $k : $prefix . '[' . $k . ']';
        $html .= is_array($v) ? $fields($v, $name) : '<input type="hidden" name="' . e($name) . '" value="' . e((string) $v) . '">';
    }
    return $html;
};
?>
<div class="stack max-w-4xl">
    <?php foreach ($rows as $r): $a = $r['account']; ?>
    <section class="panel" aria-labelledby="acc-<?= (int) $a['id'] ?>">
        <div class="card-header">
            <div>
                <h2 id="acc-<?= (int) $a['id'] ?>" class="card-title"><a href="/whm/accounts/<?= (int) $a['id'] ?>" class="hover:text-accent-text"><?= e($a['username']) ?></a></h2>
                <p class="card-desc"><?= e(Ui::plural(count($r['items']), 'thing', 'things')) ?> over the new limits</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">What</th><th scope="col" class="text-right">Has now</th><th scope="col" class="text-right">New limit</th><th scope="col" class="hidden md:table-cell">What happens</th></tr></thead>
                <tbody>
                    <?php foreach ($r['items'] as $i): ?>
                    <tr>
                        <td class="font-medium text-fg"><?= e($i['what']) ?><p class="mt-0.5 text-[12.5px] font-normal text-muted md:hidden"><?= e($i['effect']) ?></p></td>
                        <td class="text-right"><?= e($i['used']) ?></td>
                        <td class="text-right font-medium text-warning-text"><?= e($i['limit']) ?></td>
                        <td class="hidden text-[13px] text-muted md:table-cell"><?= e($i['effect']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endforeach; ?>

    <form method="post" action="<?= e($action) ?>" class="flex flex-wrap items-center justify-end gap-3">
        <?= Csrf::field() ?>
        <?= $fields($hidden) ?>
        <input type="hidden" name="confirm" value="1">
        <a href="<?= e($back) ?>" class="btn btn-secondary">Go back</a>
        <button type="submit" class="btn btn-primary"><?= icon('check') ?><?= e($confirm) ?></button>
    </form>
</div>
