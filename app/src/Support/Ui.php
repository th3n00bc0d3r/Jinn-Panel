<?php
declare(strict_types=1);

/**
 * Shared UI components for the views. Each method returns escaped HTML.
 * The simple pieces are plain CSS classes (card, btn, badge... in
 * public/assets/css/input.css); these helpers build the ones with more
 * markup or accessibility wiring - a label tied to its input, an error
 * announced with the field, an icon button with a name.
 *
 * Tailwind scans this file, so class names here must be literal too.
 */
final class Ui
{
    private static int $seq = 0;

    /** A unique id for tying a label, hint or error to its control. */
    public static function id(string $prefix = 'f'): string
    {
        return $prefix . '-' . (++self::$seq);
    }

    /**
     * HTML attributes from an array: true -> bare attribute, false/null ->
     * left out, everything else escaped.
     *
     * @param array<string, scalar|null> $attrs
     */
    public static function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            $out .= $v === true ? ' ' . e((string) $k) : ' ' . e((string) $k) . '="' . e((string) $v) . '"';
        }
        return $out;
    }

    /**
     * A labelled input. Options:
     *  type, value, placeholder, hint, error, required, optional (adds "(optional)"),
     *  mono, addon (fixed text before the input), suffix (after it, e.g. "MB"), class (extra input classes),
     *  wrap (extra wrapper classes), label_hidden, generate (password generator button),
     *  attrs (any other input attributes: min, pattern, autocomplete...).
     * Without a value, a value from a failed submit is put back (old()),
     * except for passwords.
     *
     * @param array<string,mixed> $o
     */
    public static function field(string $name, string $label, array $o = []): string
    {
        $id = (string) ($o['id'] ?? self::id());
        $type = (string) ($o['type'] ?? 'text');
        $value = $o['value'] ?? null;
        if ($value === null && $type !== 'password') {
            $value = self::old($name);
        }
        $error = $o['error'] ?? null;
        $described = [];
        $hintId = $id . '-hint';
        $errId = $id . '-err';
        if (!empty($o['hint'])) {
            $described[] = $hintId;
        }
        if ($error) {
            $described[] = $errId;
        }
        $class = 'input' . (!empty($o['mono']) ? ' font-mono' : '') . (!empty($o['class']) ? ' ' . $o['class'] : '');
        $attrs = [
            'type' => $type,
            'name' => $name,
            'id' => $id,
            'value' => $value,
            'placeholder' => $o['placeholder'] ?? null,
            'required' => !empty($o['required']),
            'aria-invalid' => $error ? 'true' : null,
            'aria-describedby' => $described ? implode(' ', $described) : null,
            'class' => $class,
        ] + ($o['attrs'] ?? []);

        $input = '<input' . self::attrs($attrs) . '>';
        if (!empty($o['addon'])) {
            $input = '<div class="input-group"><span class="input-addon">' . e((string) $o['addon']) . '</span>' . $input . '</div>';
        } elseif (!empty($o['suffix'])) {
            $input = '<div class="input-group input-group-suffix">' . $input . '<span class="input-addon">' . e((string) $o['suffix']) . '</span></div>';
        }
        if (!empty($o['generate'])) {
            $input = '<div class="flex gap-2">' . $input
                . '<button type="button" class="btn btn-secondary shrink-0" data-generate-password="' . e($id) . '" title="Generate a strong password">'
                . icon('key') . '<span class="sr-only sm:not-sr-only">Generate</span></button></div>';
        }
        return self::wrap($id, $label, $input, $o, $hintId, $errId);
    }

    /**
     * A labelled password input with a show/hide button and a caps lock
     * warning (both need pages/auth.js: View::script('auth')). Options:
     *  id, required, autocomplete (default current-password), autofocus, minlength,
     *  hint, strength (a strength meter, for a new password; "too short" is under
     *  minlength, default Passwords::MIN_LENGTH), generate (password generator
     *  button), link ([href, text] beside the label, e.g. "Forgot it?"), attrs.
     * Without JavaScript it is a plain password input with its label and hint.
     *
     * @param array<string,mixed> $o
     */
    public static function password(string $name, string $label, array $o = []): string
    {
        $id = (string) ($o['id'] ?? self::id('pw'));
        $hintId = $id . '-hint';
        $strength = !empty($o['strength']);
        $attrs = [
            'type' => 'password',
            'name' => $name,
            'id' => $id,
            'required' => !empty($o['required']),
            'minlength' => isset($o['minlength']) ? (string) $o['minlength'] : null,
            'autocomplete' => (string) ($o['autocomplete'] ?? 'current-password'),
            'autofocus' => !empty($o['autofocus']),
            'aria-describedby' => !empty($o['hint']) ? $hintId : null,
            'class' => 'input pr-11',
            'data-password' => true,
            'data-strength-min' => $strength ? (string) ($o['minlength'] ?? Passwords::MIN_LENGTH) : null,
        ] + ($o['attrs'] ?? []);

        if (!empty($o['link'])) {
            [$href, $text] = $o['link'];
            $labelHtml = '<div class="mb-1.5 flex items-baseline justify-between gap-3"><label for="' . e($id) . '" class="text-[13px] font-medium text-fg">' . e($label) . '</label>'
                . '<a href="' . e((string) $href) . '" class="link text-[13px]">' . e((string) $text) . '</a></div>';
        } else {
            $labelHtml = '<label for="' . e($id) . '" class="label">' . e($label) . '</label>';
        }

        // Hidden until auth.js runs: without it the button would do nothing.
        $reveal = '<button type="button" class="pw-reveal" aria-label="Show password" aria-pressed="false" aria-controls="' . e($id) . '" data-reveal hidden>'
            . '<span data-reveal-icon="show">' . icon('eye') . '</span><span data-reveal-icon="hide" hidden>' . icon('eye-off') . '</span></button>';
        $control = '<div class="relative min-w-0 flex-1"><input' . self::attrs($attrs) . '>' . $reveal . '</div>';
        if (!empty($o['generate'])) {
            $control = '<div class="flex gap-2">' . $control
                . '<button type="button" class="btn btn-secondary h-10 shrink-0" data-generate-password="' . e($id) . '" title="Generate a strong password">'
                . icon('key') . '<span class="sr-only sm:not-sr-only">Generate</span></button></div>';
        }

        // The live region stays in place; only its message shows and hides, so it is announced.
        $caps = '<div role="status"><p class="field-error flex items-center gap-1.5" hidden data-caps>' . icon('alert', 'h-3.5 w-3.5 shrink-0') . 'Caps lock is on</p></div>';
        $meter = $strength
            ? '<div class="pw-meter" data-strength hidden><div class="pw-meter-bars" aria-hidden="true"><span></span><span></span><span></span><span></span></div>'
                . '<p class="pw-meter-label" aria-live="polite" aria-atomic="true"><span class="sr-only">Password strength: </span><span data-strength-label></span></p></div>'
            : '';
        $hint = !empty($o['hint']) ? '<p id="' . e($hintId) . '" class="hint">' . e((string) $o['hint']) . '</p>' : '';

        return '<div class="field">' . $labelHtml . $control . $caps . $meter . $hint . '</div>';
    }

    /**
     * A labelled select. $options: value => label, or value => [label, disabled].
     *
     * @param array<string|int, string|array{0:string,1?:bool}> $options
     * @param array<string,mixed> $o
     */
    public static function select(string $name, string $label, array $options, ?string $selected = null, array $o = []): string
    {
        $id = (string) ($o['id'] ?? self::id());
        $selected ??= self::old($name);
        $hintId = $id . '-hint';
        $errId = $id . '-err';
        $error = $o['error'] ?? null;
        $described = array_filter([!empty($o['hint']) ? $hintId : null, $error ? $errId : null]);
        $attrs = [
            'name' => $name,
            'id' => $id,
            'required' => !empty($o['required']),
            'aria-invalid' => $error ? 'true' : null,
            'aria-describedby' => $described ? implode(' ', $described) : null,
            'class' => 'select' . (!empty($o['class']) ? ' ' . $o['class'] : ''),
        ] + ($o['attrs'] ?? []);
        $html = '<select' . self::attrs($attrs) . '>';
        foreach ($options as $value => $opt) {
            [$text, $disabled] = is_array($opt) ? [$opt[0], $opt[1] ?? false] : [$opt, false];
            $html .= '<option' . self::attrs([
                'value' => (string) $value,
                'selected' => $selected !== null && (string) $value === (string) $selected,
                'disabled' => $disabled,
            ]) . '>' . e($text) . '</option>';
        }
        $html .= '</select>';
        return self::wrap($id, $label, $html, $o, $hintId, $errId);
    }

    /** @param array<string,mixed> $o */
    public static function textarea(string $name, string $label, ?string $value = null, array $o = []): string
    {
        $id = (string) ($o['id'] ?? self::id());
        $value ??= self::old($name);
        $hintId = $id . '-hint';
        $errId = $id . '-err';
        $attrs = [
            'name' => $name,
            'id' => $id,
            'rows' => $o['rows'] ?? 4,
            'placeholder' => $o['placeholder'] ?? null,
            'required' => !empty($o['required']),
            'aria-describedby' => !empty($o['hint']) ? $hintId : null,
            'class' => 'textarea' . (!empty($o['mono']) ? ' font-mono text-xs' : '') . (!empty($o['class']) ? ' ' . $o['class'] : ''),
        ] + ($o['attrs'] ?? []);
        return self::wrap($id, $label, '<textarea' . self::attrs($attrs) . '>' . e($value) . '</textarea>', $o, $hintId, $errId);
    }

    /** A checkbox with its label to the right. @param array<string,mixed> $o */
    public static function checkbox(string $name, string $label, bool $checked = false, array $o = []): string
    {
        $id = (string) ($o['id'] ?? self::id());
        $attrs = [
            'type' => 'checkbox',
            'name' => $name,
            'id' => $id,
            'value' => $o['value'] ?? '1',
            'checked' => $checked,
        ] + ($o['attrs'] ?? []);
        $hint = !empty($o['hint']) ? '<span class="block text-xs text-muted">' . e((string) $o['hint']) . '</span>' : '';
        return '<label class="check' . (!empty($o['wrap']) ? ' ' . e((string) $o['wrap']) : '') . '" for="' . e($id) . '"><input' . self::attrs($attrs) . '><span>' . e($label) . $hint . '</span></label>';
    }

    /** @param array<string,mixed> $o */
    private static function wrap(string $id, string $label, string $control, array $o, string $hintId, string $errId): string
    {
        $labelHtml = '<label for="' . e($id) . '" class="' . (!empty($o['label_hidden']) ? 'sr-only' : 'label') . '">' . e($label)
            . (!empty($o['optional']) ? ' <span class="label-optional">(optional)</span>' : '') . '</label>';
        $hint = !empty($o['hint']) ? '<p id="' . e($hintId) . '" class="hint">' . e((string) $o['hint']) . '</p>' : '';
        $err = !empty($o['error']) ? '<p id="' . e($errId) . '" class="field-error">' . e((string) $o['error']) . '</p>' : '';
        return '<div class="field' . (!empty($o['wrap']) ? ' ' . e((string) $o['wrap']) : '') . '">' . $labelHtml . $control . $err . $hint . '</div>';
    }

    /** The value from a form that failed validation (Flash::error remembers it), or null. */
    public static function old(string $name): ?string
    {
        $v = $_SESSION['old'][$name] ?? null;
        return is_scalar($v) ? (string) $v : null;
    }

    /**
     * Page title row: heading, optional description and actions (raw HTML).
     */
    public static function pageHeader(string $title, string $desc = '', string $actions = ''): string
    {
        return '<div class="page-header"><div class="min-w-0"><h1 class="page-title">' . e($title) . '</h1>'
            . ($desc !== '' ? '<p class="page-desc">' . e($desc) . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>' : '') . '</div>';
    }

    /** Card header with title, optional description and actions (raw HTML); $id names the heading (aria-labelledby). */
    public static function cardHeader(string $title, string $desc = '', string $actions = '', string $id = ''): string
    {
        return '<div class="card-header"><div class="min-w-0"><h2 class="card-title"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>' . e($title) . '</h2>'
            . ($desc !== '' ? '<p class="card-desc">' . e($desc) . '</p>' : '') . '</div>'
            . ($actions !== '' ? '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>' : '') . '</div>';
    }

    /** tone: neutral, accent, success, warning, danger, info */
    public static function badge(string $text, string $tone = 'neutral', bool $dot = false): string
    {
        $class = match ($tone) {
            'accent' => 'badge badge-accent',
            'success' => 'badge badge-success',
            'warning' => 'badge badge-warning',
            'danger' => 'badge badge-danger',
            'info' => 'badge badge-info',
            default => 'badge badge-neutral',
        };
        return '<span class="' . $class . '">' . ($dot ? '<span class="dot" aria-hidden="true"></span>' : '') . e($text) . '</span>';
    }

    /** A badge for the usual status words. */
    public static function status(string $status, ?string $label = null): string
    {
        $tone = match (strtolower($status)) {
            'active', 'ok', 'done', 'completed', 'success', 'enabled', 'on', 'installed', 'running', 'provisioned', 'valid' => 'success',
            'suspended', 'pending', 'warning', 'queued', 'waiting', 'review', 'expiring', 'partial' => 'warning',
            'failed', 'error', 'inactive', 'disabled', 'off', 'expired', 'cancelled', 'canceled', 'no_dns' => 'danger',
            'working', 'in_progress', 'importing', 'installing', 'removing', 'restoring', 'backing_up', 'transferring', 'restoring_files', 'info' => 'info',
            default => 'neutral',
        };
        return self::badge($label ?? ucfirst(str_replace('_', ' ', $status)), $tone, true);
    }

    /** tone for a usage percentage: accent below 90%, warning to 100%, danger at/over. */
    public static function usageTone(float $pct, bool $over = false): string
    {
        return $over || $pct >= 100 ? 'danger' : ($pct >= 90 ? 'warning' : 'accent');
    }

    /** A progress bar. $label names it for screen readers. */
    public static function progress(float $pct, string $label, string $tone = 'accent'): string
    {
        $pct = max(0, min(100, $pct));
        $bar = match ($tone) {
            'success' => 'progress-bar progress-bar-success',
            'warning' => 'progress-bar progress-bar-warning',
            'danger' => 'progress-bar progress-bar-danger',
            default => 'progress-bar',
        };
        return '<div class="progress" role="progressbar" aria-label="' . e($label) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) round($pct) . '">'
            . '<div class="' . $bar . '" style="width: ' . round($pct, 1) . '%"></div></div>';
    }

    /** A usage meter: label, "used / limit" on the right, the bar, an optional note. */
    public static function meter(string $label, string $value, float $pct, string $tone = 'accent', string $note = ''): string
    {
        return '<div><div class="mb-1.5 flex items-baseline justify-between gap-3 text-sm"><span class="text-muted">' . e($label) . '</span>'
            . '<span class="font-medium tabular-nums text-fg">' . e($value) . '</span></div>'
            . self::progress($pct, $label, $tone)
            . ($note !== '' ? '<p class="mt-1.5 text-xs ' . ($tone === 'danger' ? 'text-danger-text' : 'text-muted') . '">' . e($note) . '</p>' : '')
            . '</div>';
    }

    /** Empty state for a list or card. $action is raw HTML (a button or link). */
    public static function empty(string $title, string $text = '', string $icon = 'folder', string $action = ''): string
    {
        return '<div class="empty"><div class="empty-icon">' . icon($icon) . '</div><p class="empty-title">' . e($title) . '</p>'
            . ($text !== '' ? '<p class="empty-text">' . e($text) . '</p>' : '')
            . ($action !== '' ? '<div class="mt-4">' . $action . '</div>' : '') . '</div>';
    }

    /** Empty state inside a table body. */
    public static function emptyRow(int $cols, string $title, string $text = '', string $icon = 'folder', string $action = ''): string
    {
        return '<tr class="hover:bg-transparent"><td colspan="' . $cols . '">' . self::empty($title, $text, $icon, $action) . '</td></tr>';
    }

    /** A dashboard number: label, value (counts up), optional icon, hint and link. */
    public static function stat(string $label, string|int $value, string $icon = '', string $hint = '', string $href = ''): string
    {
        $inner = ($icon !== '' ? '<div class="stat-icon">' . icon($icon) . '</div>' : '')
            . '<div class="min-w-0"><p class="stat-label">' . e($label) . '</p>'
            . '<p class="stat-value tabular-nums"' . (is_int($value) ? ' data-countup' : '') . '>' . e((string) $value) . '</p>'
            . ($hint !== '' ? '<p class="mt-1 text-xs text-muted">' . e($hint) . '</p>' : '') . '</div>';
        return $href !== ''
            ? '<a href="' . e($href) . '" class="stat card-link">' . $inner . '</a>'
            : '<div class="stat">' . $inner . '</div>';
    }

    /**
     * An icon-only button with an accessible name. Options: type (submit),
     * danger (red on hover), attrs.
     *
     * @param array<string,mixed> $o
     */
    public static function iconButton(string $icon, string $label, array $o = []): string
    {
        $attrs = [
            'type' => $o['type'] ?? 'submit',
            'class' => 'btn btn-icon' . (!empty($o['danger']) ? ' btn-danger-ghost' : ''),
            'title' => $label,
            'aria-label' => $label,
        ] + ($o['attrs'] ?? []);
        return '<button' . self::attrs($attrs) . '>' . icon($icon, 'h-4 w-4') . '</button>';
    }

    /** A link styled as an icon button. */
    public static function iconLink(string $href, string $icon, string $label, bool $external = false): string
    {
        return '<a href="' . e($href) . '" class="btn btn-icon" title="' . e($label) . '" aria-label="' . e($label) . '"'
            . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . icon($icon, 'h-4 w-4') . '</a>';
    }

    /**
     * A POST form holding one icon button, confirmed in a dialog first.
     * $hidden: extra hidden fields.
     *
     * @param array<string,string|int> $hidden
     */
    public static function actionForm(string $action, string $icon, string $label, string $confirm = '', array $hidden = [], bool $danger = false): string
    {
        $html = '<form method="post" action="' . e($action) . '"'
            . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '" data-confirm-action="' . e($label) . '"' . ($danger ? ' data-confirm-danger' : '') : '')
            . '>' . Csrf::field();
        foreach ($hidden as $k => $v) {
            $html .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '" hidden>';
        }
        return $html . self::iconButton($icon, $label, ['danger' => $danger]) . '</form>';
    }

    /** The usual "delete this" icon form. */
    public static function deleteForm(string $action, string $confirm, string $label = 'Delete', array $hidden = []): string
    {
        return self::actionForm($action, 'trash', $label, $confirm, $hidden, true);
    }

    /** A small button that copies $text to the clipboard. */
    public static function copy(string $text, string $label = 'Copy'): string
    {
        return '<button type="button" class="btn btn-icon -my-1 p-1" data-copy="' . e($text) . '" title="' . e($label) . '" aria-label="' . e($label) . '">'
            . icon('copy', 'h-3.5 w-3.5') . '</button>';
    }

    /** A value in monospace with a copy button next to it. */
    public static function copyable(string $text): string
    {
        return '<span class="inline-flex max-w-full items-center gap-1"><code class="truncate">' . e($text) . '</code>' . self::copy($text) . '</span>';
    }

    /** Inline alert. tone: info, success, warning, danger. $body is raw HTML when $html is true. */
    public static function alert(string $body, string $tone = 'info', bool $html = false): string
    {
        [$class, $ico] = match ($tone) {
            'success' => ['alert alert-success', 'check-circle'],
            'warning' => ['alert alert-warning', 'alert'],
            'danger' => ['alert alert-danger', 'x-circle'],
            default => ['alert alert-info', 'info'],
        };
        return '<div class="' . $class . '" role="' . ($tone === 'danger' ? 'alert' : 'status') . '">' . icon($ico) . '<div class="min-w-0">' . ($html ? $body : e($body)) . '</div></div>';
    }

    /**
     * Marks a "job is running" banner: the page checks itself in the
     * background and reloads once the banner is gone (job finished).
     */
    public static function busy(string $message, int $everyMs = 5000): string
    {
        return '<div class="alert alert-info" role="status" data-busy="' . $everyMs . '">'
            . '<svg class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>'
            . '<div class="min-w-0">' . e($message) . ' <span class="text-xs opacity-80">This page updates by itself.</span></div></div>';
    }

    /**
     * Tabs as links. $tabs: [href, label, icon?]. The one matching $current
     * gets aria-current.
     *
     * @param list<array{0:string,1:string,2?:string}> $tabs
     */
    public static function tabs(array $tabs, string $current, string $label = 'Sections'): string
    {
        $html = '<nav class="tabs" aria-label="' . e($label) . '">';
        foreach ($tabs as $t) {
            $html .= '<a href="' . e($t[0]) . '" class="tab"' . ($t[0] === $current ? ' aria-current="page"' : '') . '>'
                . (!empty($t[2]) ? icon($t[2], 'h-4 w-4') : '') . e($t[1]) . '</a>';
        }
        return $html . '</nav>';
    }

    /** "1 domain" / "3 domains". */
    public static function plural(int $n, string $one, ?string $many = null): string
    {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }
}
