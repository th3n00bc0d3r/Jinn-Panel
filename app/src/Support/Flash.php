<?php
declare(strict_types=1);

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
        // Every panel action reports its outcome here, so this is also where
        // it lands in the audit trail (WHM > Activity Log), named after the
        // route: POST /cpanel/domains/12/delete -> cpanel.domains.delete.
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && PHP_SAPI !== 'cli') {
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $parts = array_filter(explode('/', $path), fn($p) => $p !== '' && !ctype_digit($p) && strlen($p) < 40);
            $action = implode('.', $parts) ?: 'post';
            $target = Audit::currentTarget() ?? (Auth::role() === 'user' ? Auth::id() : null);
            Audit::log($type === 'error' ? "$action.failed" : $action, $message, $target);
        }
    }

    public static function ok(string $message): void
    {
        self::set('success', $message);
    }

    public static function error(string $message): void
    {
        self::set('error', $message);
        self::rememberInput();
    }

    /**
     * Keeps what was typed into a form that failed, so the page it
     * redirects back to can show it again (Ui::old). Never secrets.
     */
    private static function rememberInput(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || PHP_SAPI === 'cli') {
            return;
        }
        $keep = [];
        foreach ($_POST as $k => $v) {
            if (is_string($v) && strlen($v) <= 4096 && !preg_match('/pass|secret|token|csrf|code|key|private/i', (string) $k)) {
                $keep[(string) $k] = $v;
            }
        }
        $_SESSION['old'] = $keep;
    }

    /** @return array<int, array{type:string,message:string}> */
    public static function pull(): array
    {
        // Background checks of a page (data-busy polling) must not eat messages.
        if (($_SERVER['HTTP_X_JP_POLL'] ?? '') !== '') {
            return [];
        }
        $items = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $items;
    }
}
