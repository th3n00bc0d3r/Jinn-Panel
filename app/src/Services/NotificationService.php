<?php
declare(strict_types=1);

/**
 * The emails the panel sends: welcome, password reset, alerts, a test.
 * Which ones are on, the From address and where alerts go are set in
 * WHM > Notifications (panel_settings notify_*).
 */
final class NotificationService
{
    /** @return array{from:string, from_name:string, admin_email:string, welcome:bool, alerts:bool} */
    public static function settings(): array
    {
        return [
            'from' => Settings::get('notify_from'),
            'from_name' => Settings::get('notify_from_name'),
            'admin_email' => Settings::get('notify_admin_email'),
            'welcome' => Settings::get('notify_welcome', '1') === '1',
            'alerts' => Settings::get('notify_alerts', '1') === '1',
            'quota' => Settings::get('notify_quota', '1') === '1',
            'suspension' => Settings::get('notify_suspension', '1') === '1',
        ];
    }

    /** @param array<string,mixed> $in */
    public static function saveSettings(array $in): void
    {
        $from = trim((string) ($in['from'] ?? ''));
        if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('The From address isn\'t a valid email address.');
        }
        $admin = trim((string) ($in['admin_email'] ?? ''));
        foreach (array_filter(array_map('trim', explode(',', $admin))) as $a) {
            if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("\"$a\" isn't a valid email address.");
            }
        }
        $name = trim(preg_replace('/[\r\n"<>]/', '', (string) ($in['from_name'] ?? '')) ?? '');
        Settings::set('notify_from', $from);
        Settings::set('notify_from_name', mb_substr($name, 0, 80));
        Settings::set('notify_admin_email', $admin);
        Settings::set('notify_welcome', !empty($in['welcome']) ? '1' : '0');
        Settings::set('notify_alerts', !empty($in['alerts']) ? '1' : '0');
        Settings::set('notify_quota', !empty($in['quota']) ? '1' : '0');
        Settings::set('notify_suspension', !empty($in['suspension']) ? '1' : '0');
    }

    /** Where alerts go: the configured addresses, else every active admin's email. @return list<string> */
    public static function adminRecipients(): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', Settings::get('notify_admin_email'))), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if ($list) {
            return $list;
        }
        return array_values(array_filter(
            Database::app()->query("SELECT email FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN),
            fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)
        ));
    }

    /**
     * Welcome email for a new account: where to sign in, the username, and
     * a link to set their own password (the password is never mailed).
     *
     * @param array<string,mixed> $user
     */
    /**
     * The welcome email. A hosting account signs in at its own domain
     * (https://<domain>/jpanel), never the server's address; only an account
     * without a domain yet (and resellers/admins) gets the panel address.
     * $password is the one just set (create form); without it the email has
     * a one-time link to choose one instead.
     */
    public static function welcome(array $user, ?string $password = null): bool
    {
        $role = (string) $user['role'];
        $domain = $role === 'user' ? self::primaryDomain((int) $user['id']) : null;
        $login = $domain !== null ? "https://$domain/jpanel" : 'https://' . Auth::panelHost() . '/login';
        $what = $role === 'user'
            ? 'Your hosting account is ready. From the control panel you can add domains, mailboxes, databases and SFTP logins, and manage files, DNS and backups.'
            : 'Your ' . ($role === 'reseller' ? 'reseller' : 'administrator') . ' login for the hosting server is ready.';
        $text = "Hello" . (!empty($user['full_name']) ? ' ' . $user['full_name'] : '') . ",\n\n"
            . "$what\n\n"
            . "Control panel: $login\nUsername: {$user['username']}\n";
        if ($password !== null && $password !== '') {
            $text .= "Password: $password\n\n"
                . "Please change this password after you first sign in (Account > Password), and keep it private.\n\n";
        } else {
            $token = PasswordResetService::create((int) $user['id'], 'welcome');
            // On the account's own panel (<domain>:2083, where /jpanel leads), not the server's.
            $link = $domain !== null ? "https://$domain:2083/login/reset?token=$token" : PasswordResetService::url($token);
            $text .= "\nUse the password you were given, or choose your own with this link (valid for 3 days, works once):\n$link\n\n";
        }
        $text .= "Once signed in, you can turn on two-factor sign-in under Login security.";
        return Mailer::send((string) $user['email'], 'Your ' . Config::APP_NAME . ' account', $text);
    }

    /** The account's first domain (the one it was created with), or null. */
    private static function primaryDomain(int $userId): ?string
    {
        $s = Database::app()->prepare("SELECT domain_name FROM domains WHERE user_id = ? AND status = 'active' ORDER BY id LIMIT 1");
        $s->execute([$userId]);
        $d = $s->fetchColumn();
        return is_string($d) && $d !== '' ? $d : null;
    }

    /** @param array<string,mixed> $user */
    public static function passwordReset(array $user, string $ip): bool
    {
        $link = PasswordResetService::url(PasswordResetService::create((int) $user['id'], 'reset'));
        $text = "Someone (hopefully you) asked to reset the password of the " . Config::APP_NAME . " account \"{$user['username']}\" from the address $ip.\n\n"
            . "Choose a new password with this link. It works once, for the next hour:\n$link\n\n"
            . "If it wasn't you, ignore this email - your password stays as it is.";
        return Mailer::send((string) $user['email'], 'Reset your ' . Config::APP_NAME . ' password', $text);
    }

    /**
     * @param list<array{level:string,title:string,detail:string,href:?string}> $alerts
     */
    public static function alerts(array $alerts): int
    {
        $lines = [];
        foreach ($alerts as $a) {
            $lines[] = '- [' . strtoupper($a['level']) . '] ' . $a['title'] . ($a['detail'] !== '' ? "\n  " . $a['detail'] : '');
        }
        $text = 'The panel on ' . Config::SERVER_HOSTNAME . ' found ' . count($alerts) . ' new problem' . (count($alerts) === 1 ? '' : 's') . ":\n\n"
            . implode("\n", $lines) . "\n\nAll current alerts: https://" . Auth::panelHost() . "/whm/alerts\n\n"
            . 'You get one email per new problem; turn these off in WHM > Notifications.';
        $sent = 0;
        foreach (self::adminRecipients() as $to) {
            $sent += Mailer::send($to, '[' . Config::SERVER_HOSTNAME . '] ' . (count($alerts) === 1 ? $alerts[0]['title'] : count($alerts) . ' new server alerts'), $text) ? 1 : 0;
        }
        return $sent;
    }

    /**
     * Tells a hosting account it was suspended or is back (WHM >
     * Notifications: "suspension"). $reason is the admin's note, if any.
     *
     * @param array<string,mixed> $user
     */
    public static function suspension(array $user, bool $suspended, string $reason = ''): bool
    {
        if ($user['role'] !== 'user' || Settings::get('notify_suspension', '1') !== '1') {
            return false;
        }
        $reason = trim(mb_substr($reason, 0, 1000));
        $text = $suspended
            ? "Your hosting account \"{$user['username']}\" has been suspended.\n\n"
                . ($reason !== '' ? "Reason: $reason\n\n" : '')
                . "While it is suspended its websites show a \"temporarily unavailable\" page, and mail logins, SFTP, databases and cron jobs are switched off. Incoming mail is still accepted and kept.\n\n"
                . 'Contact your hosting provider to have it turned back on.'
            : "Your hosting account \"{$user['username']}\" is active again: its websites, mail logins, SFTP, databases and cron jobs are back on.\n\n"
                . 'Sign in at: https://' . Auth::panelHost() . '/login';
        $sent = Mailer::send((string) $user['email'], $suspended ? 'Your hosting account is suspended' : 'Your hosting account is active again', $text);
        Audit::log($suspended ? 'notify.suspended' : 'notify.unsuspended', $sent ? 'emailed ' . $user['email'] : 'email failed', (int) $user['id']);
        return $sent;
    }

    /**
     * Disk or bandwidth warning to a hosting account ($level 90 = nearly
     * full, 100 = over). Sent once per level by UsageNoticeService.
     *
     * @param array<string,mixed> $user
     */
    public static function quota(array $user, string $what, int $level, int $used, int $limit): bool
    {
        $amount = fmt_bytes($used) . ' of ' . fmt_bytes($limit);
        if ($what === 'disk') {
            $subject = $level >= 100 ? 'Your hosting account is out of disk space' : 'Your hosting account is nearly out of disk space';
            $text = $level >= 100
                ? "Your hosting account \"{$user['username']}\" is over its disk space ($amount).\n\nUntil it's back under, uploads, new databases, mailboxes and domains are refused. Delete files, old backups, databases or mail you no longer need - or ask your hosting provider for a bigger package."
                : "Your hosting account \"{$user['username']}\" has used " . (int) round($used / max(1, $limit) * 100) . "% of its disk space ($amount).\n\nWhen it's full, uploads, new databases, mailboxes and domains are refused. Now is a good time to clean up, or to ask for a bigger package.";
        } else {
            $subject = $level >= 100 ? 'Your websites are paused: monthly bandwidth used up' : 'Your hosting account is nearly out of bandwidth this month';
            $text = $level >= 100
                ? "Your hosting account \"{$user['username']}\" has used all of this month's bandwidth ($amount), so its websites are paused until the 1st of next month.\n\nAsk your hosting provider for a bigger package to bring them back sooner."
                : "Your hosting account \"{$user['username']}\" has used " . (int) round($used / max(1, $limit) * 100) . "% of this month's bandwidth ($amount).\n\nIf it runs out, its websites are paused until the 1st of next month.";
        }
        $text .= "\n\nSee your usage: https://" . Auth::panelHost() . '/cpanel';
        return Mailer::send((string) $user['email'], $subject, $text);
    }

    public static function test(string $to): bool
    {
        return Mailer::send($to, 'Test email from ' . Config::APP_NAME, "This is a test from the hosting panel on " . Config::SERVER_HOSTNAME . ".\n\nIf you can read it, the panel can send welcome emails, password resets and alerts.\n\nSent from: " . Mailer::from());
    }
}
