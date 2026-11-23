<?php
/** @var array<string,mixed> $me */
/** @var list<array<string,mixed>> $tokens */
/** @var array{name:string, token:string, username:string}|null $newToken shown once, right after it's created */
/** @var string $endpoint */
/** @var list<string> $functions */
View::slot('desc', e('Tokens for the provisioning API: billing systems and order forms create, suspend and remove hosting accounts with them. A token acts with your rights.'));
$isAdmin = ($me['role'] ?? '') === 'admin';
?>
<?php if ($newToken): ?>
<section class="card mb-6">
    <?= Ui::cardHeader('Your new token: "' . $newToken['name'] . '"', 'Copy it now. Only a hash of it is kept, so it can\'t be shown again.') ?>
    <div class="card-body space-y-4">
        <p class="text-lg"><?= Ui::copyable($newToken['token']) ?></p>
        <dl class="dl">
            <div><dt>WHM-style header</dt><dd class="break-all"><?= Ui::copyable('Authorization: whm ' . $newToken['username'] . ':' . $newToken['token']) ?></dd></div>
            <div><dt>Or</dt><dd class="break-all"><?= Ui::copyable('Authorization: Bearer ' . $newToken['token']) ?></dd></div>
        </dl>
    </div>
</section>
<?php endif; ?>

<div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
    <section class="card xl:col-span-2">
        <?= Ui::cardHeader('Tokens', $isAdmin ? 'Every administrator\'s and reseller\'s tokens.' : 'Your tokens.') ?>
        <?php if (!$tokens): ?>
        <?= Ui::empty('No API tokens yet', 'Create one for each site or tool that provisions accounts, so you can revoke them one at a time.', 'key') ?>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table table-tight">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <?php if ($isAdmin): ?><th scope="col" class="hidden md:table-cell">Owner</th><?php endif; ?>
                        <th scope="col" class="hidden md:table-cell">Starts with</th>
                        <th scope="col" class="hidden lg:table-cell">Allowed from</th>
                        <th scope="col">Last used</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tokens as $t): ?>
                    <tr>
                        <td class="font-medium text-fg"><?= e($t['name']) ?><span class="block text-xs text-muted">created <?= e(substr((string) $t['created_at'], 0, 16)) ?></span></td>
                        <?php if ($isAdmin): ?><td class="hidden md:table-cell"><?= e($t['owner_username']) ?></td><?php endif; ?>
                        <td class="hidden md:table-cell"><code><?= e($t['token_hint']) ?>…</code></td>
                        <td class="hidden lg:table-cell text-muted"><?= $t['allowed_ips'] ? e(str_replace(',', ', ', (string) $t['allowed_ips'])) : 'anywhere' ?></td>
                        <td class="whitespace-nowrap text-muted"><?= $t['last_used_at'] ? e(substr((string) $t['last_used_at'], 0, 16)) . '<span class="block text-xs">' . e((string) $t['last_used_ip']) . '</span>' : 'never' ?></td>
                        <td class="text-right"><?= Ui::deleteForm('/whm/api-tokens/' . (int) $t['id'] . '/delete', 'Delete the token "' . $t['name'] . '"? Anything using it stops working.', 'Delete token') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <div class="stack">
        <form method="post" action="/whm/api-tokens" class="card">
            <?= Csrf::field() ?>
            <?= Ui::cardHeader('Create a token') ?>
            <div class="card-body space-y-5">
                <?= Ui::field('name', 'Name', ['required' => true, 'attrs' => ['maxlength' => 64], 'placeholder' => 'e.g. Order form on example.com']) ?>
                <?= Ui::field('allowed_ips', 'Only from these addresses', ['optional' => true, 'placeholder' => '203.0.113.7, 2001:db8::7', 'hint' => 'Comma-separated. Empty: any address. Limit it when you know where the calls come from.']) ?>
            </div>
            <div class="card-footer">
                <button type="submit" class="btn btn-primary"><?= icon('key') ?>Create token</button>
            </div>
        </form>

        <section class="card">
            <?= Ui::cardHeader('Using the API', 'WHM API 1 compatible: WHM clients work unchanged.') ?>
            <dl class="dl card-body">
                <div><dt>Endpoint</dt><dd class="break-all"><?= Ui::copyable($endpoint . '<function>') ?></dd></div>
                <div><dt>Functions</dt><dd><?= e(implode(', ', $functions)) ?></dd></div>
                <div><dt>Example</dt><dd class="break-all"><code><?= e('curl -H "Authorization: whm ' . $me['username'] . ':TOKEN" "' . $endpoint . 'listpkgs?api.version=1"') ?></code></dd></div>
            </dl>
        </section>
    </div>
</div>
