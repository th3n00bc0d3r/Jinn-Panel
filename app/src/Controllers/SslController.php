<?php
declare(strict_types=1);

/**
 * WHM > SSL/TLS (administrators): every hosted domain's certificates and
 * their expiry, AutoSSL on demand, and each domain's SSL mode.
 */
final class SslController
{
    private const MODES = ['auto', 'letsencrypt', 'self_signed'];

    public static function index(): void
    {
        Auth::requireRole(['admin']);
        $rows = SslService::overview();
        $counts = ['ok' => 0, 'expiring' => 0, 'problem' => 0, 'self_signed' => 0];
        foreach ($rows as $r) {
            $counts[$r['state'] === 'pinned' ? 'self_signed' : $r['state']]++;
        }
        $show = (string) ($_GET['show'] ?? '');
        if ($show === 'attention') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => in_array($r['state'], ['problem', 'expiring', 'self_signed'], true)));
        }
        View::render('whm/ssl', [
            'title' => 'SSL/TLS',
            'rows' => $rows,
            'counts' => $counts,
            'show' => $show,
        ], 'whm');
    }

    /** Run AutoSSL for every domain now (the same pass also runs daily). */
    public static function autossl(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $log = SslService::autoSslAll();
        Flash::ok($log === []
            ? 'AutoSSL: every certificate is in order, nothing to do.'
            : 'AutoSSL: ' . implode('. ', $log) . '. New certificates normally arrive within a minute or two; reload this page to see them.');
        header('Location: /whm/ssl');
        exit;
    }

    /** One domain: change its SSL mode, or (mode unchanged) ask Caddy to issue again. */
    public static function update(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $s = Database::app()->prepare('SELECT * FROM domains WHERE id = ?');
        $s->execute([(int) ($params['id'] ?? 0)]);
        $d = $s->fetch();
        if (!$d) {
            Flash::error('That domain no longer exists.');
            header('Location: /whm/ssl');
            exit;
        }
        Audit::target((int) $d['user_id']);
        $name = (string) $d['domain_name'];
        $requested = (string) ($_POST['ssl_mode'] ?? 'auto');
        if (!in_array($requested, self::MODES, true)) {
            $requested = 'auto';
        }
        $mode = SslService::resolveMode($requested, $name);
        $pinned = SslService::pinned($requested) ? 1 : 0;
        try {
            VhostService::create($name, (string) ($d['php_version'] ?: 'default'), $mode);
            Database::app()->prepare('UPDATE domains SET ssl_mode = ?, ssl_pinned = ? WHERE id = ?')->execute([$mode, $pinned, $d['id']]);
        } catch (Throwable $e) {
            error_log("ssl update $name: " . $e->getMessage());
            Flash::error("Could not update $name: " . $e->getMessage());
            header('Location: /whm/ssl');
            exit;
        }
        $label = $mode === 'letsencrypt' ? "Let's Encrypt" : ($pinned ? 'self-signed only' : 'self-signed');
        $note = $mode === 'letsencrypt' && !SslService::resolvesHere($name)
            ? " Its DNS doesn't point here (" . Config::SERVER_IP . "), so Let's Encrypt can't issue until it does."
            : ($mode === 'letsencrypt' ? ' The certificate normally arrives within a minute or two.' : '');
        Flash::ok("SSL for $name: $label.$note");
        header('Location: /whm/ssl#ssl-' . (int) $d['id']);
        exit;
    }
}
