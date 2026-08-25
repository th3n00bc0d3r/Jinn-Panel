<?php
/**
 * Generic form for any Stalwart singleton settings object, rendered
 * straight from its schema. Simple types get a real input; anything
 * structural (object/objectList/set/map) falls back to a JSON textarea -
 * still fully editable, just "advanced mode" for the less common fields.
 */
?>
<a href="/whm/server-config/mail" class="text-sm text-indigo-600 hover:text-indigo-700">&larr; Mail settings</a>

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 mt-3 max-w-3xl">
    <form method="post" action="/whm/server-config/mail/<?= e($object) ?>" class="space-y-5">
        <?= Csrf::field() ?>
        <?php foreach ($fields as $name => $def):
            if (($def['update'] ?? '') === 'serverSet') continue;
            $type = $def['type']['type'] ?? 'string';
            $current = $values[$name] ?? null;
        ?>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1"><?= e($name) ?></label>
            <?php if (!empty($def['description'])): ?>
            <p class="text-xs text-slate-400 mb-1.5"><?= e($def['description']) ?></p>
            <?php endif; ?>

            <?php if ($type === 'boolean'): ?>
                <select name="<?= e($name) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="1" <?= $current === true ? 'selected' : '' ?>>Enabled</option>
                    <option value="0" <?= $current === false ? 'selected' : '' ?>>Disabled</option>
                </select>

            <?php elseif ($type === 'enum' && isset($enums[$def['type']['enumName'] ?? ''])): ?>
                <select name="<?= e($name) ?>" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <?php foreach ($enums[$def['type']['enumName']] as $opt): ?>
                    <option value="<?= e($opt['name']) ?>" <?= $current === $opt['name'] ? 'selected' : '' ?>><?= e($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ($type === 'number'): ?>
                <input type="number" name="<?= e($name) ?>" value="<?= e((string) ($current ?? '')) ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <?php if (($def['type']['format'] ?? '') === 'size'): ?>
                <p class="text-xs text-slate-400 mt-1">Size in bytes.</p>
                <?php endif; ?>

            <?php elseif (in_array($type, ['string', 'enum'], true)): ?>
                <input type="text" name="<?= e($name) ?>" value="<?= e((string) ($current ?? '')) ?>"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">

            <?php else: ?>
                <textarea name="<?= e($name) ?>" rows="4"
                          class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500"
                          placeholder="Advanced field - raw JSON"><?= e($current !== null ? json_encode($current, JSON_PRETTY_PRINT) : '') ?></textarea>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (!$fields): ?>
        <p class="text-sm text-slate-400">This object has no client-editable fields.</p>
        <?php endif; ?>

        <div class="pt-2">
            <button type="submit" class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 transition-colors">Save settings</button>
        </div>
    </form>
</div>
