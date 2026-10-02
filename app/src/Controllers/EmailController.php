<?php
declare(strict_types=1);

final class EmailController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $pdo = Database::app();

        $stmt = $pdo->prepare(
            'SELECT e.*, d.domain_name FROM email_accounts e JOIN domains d ON d.id = e.domain_id
             WHERE e.user_id = ? ORDER BY e.created_at DESC'
        );
        $stmt->execute([$me['id']]);

        $domainsStmt = $pdo->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain_name');
        $domainsStmt->execute([$me['id']]);

        $fwd = $pdo->prepare('SELECT f.*, d.domain_name FROM email_forwarders f JOIN domains d ON d.id = f.domain_id WHERE f.user_id = ? ORDER BY d.domain_name, f.local_part');
        $fwd->execute([$me['id']]);
        $auto = $pdo->prepare('SELECT * FROM email_autoresponders WHERE user_id = ?');
        $auto->execute([$me['id']]);

        View::render('cpanel/email', [
            'title' => 'Email Accounts',
            'accounts' => $accounts = $stmt->fetchAll(),
            'domains' => $domainRows = $domainsStmt->fetchAll(),
            'forwarders' => $fwd->fetchAll(),
            'autoresponders' => array_column($auto->fetchAll(), null, 'email_account_id'),
            'mailHost' => strtolower(Config::SERVER_HOSTNAME),
            'webmail' => self::webmailHosts($accounts, $domainRows),
            'usage' => Quota::usage($me['id']),
            'pkg' => Quota::package($me['id']),
        ], 'cpanel');
    }

    public static function store(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();

        if (!Quota::withinLimit($me['id'], 'email_accounts')) {
            Flash::error('You have reached your package\'s email account limit.');
            header('Location: /cpanel/email');
            exit;
        }

        $localPart = strtolower(trim((string) ($_POST['local_part'] ?? '')));
        $domainId = (int) ($_POST['domain_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart)) {
            Flash::error('Enter a valid mailbox name.');
            header('Location: /cpanel/email');
            exit;
        }
        if (($problem = Passwords::problem($password, $localPart)) !== null) {
            Flash::error('Mailbox: ' . $problem);
            header('Location: /cpanel/email');
            exit;
        }

        $pdo = Database::app();
        $dStmt = $pdo->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $dStmt->execute([$domainId, $me['id']]);
        $domain = $dStmt->fetch();
        if (!$domain) {
            Flash::error('Select one of your domains.');
            header('Location: /cpanel/email');
            exit;
        }

        try {
            $mailDomainId = $domain['mail_domain_id'];
            if (!$mailDomainId) {
                $mailDomainId = MailService::ensureDomain($domain['domain_name']);
                $upd = $pdo->prepare('UPDATE domains SET mail_domain_id = ? WHERE id = ?');
                $upd->execute([$mailDomainId, $domain['id']]);
                MailDnsService::syncAfterMailDomain((int) $domain['id']);
            }
            MailRulesService::beforeMailboxCreated((int) $domain['id'], $localPart);
            $mailAccountId = MailService::createMailbox($mailDomainId, $localPart, $password);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the mailbox on the mail server: ' . $e->getMessage());
            header('Location: /cpanel/email');
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO email_accounts (user_id, domain_id, local_part, mail_account_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$me['id'], $domain['id'], $localPart, $mailAccountId]);
        try {
            MailRulesService::afterMailboxCreated((int) $domain['id'], $localPart); // a forwarder of that name moves into it
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }

        Flash::ok("Mailbox \"$localPart@{$domain['domain_name']}\" created.");
        header('Location: /cpanel/email');
        exit;
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM email_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            Flash::error('Mailbox not found.');
            header('Location: /cpanel/email');
            exit;
        }

        MailRulesService::beforeMailboxDeleted($row);
        if ($row['mail_account_id']) {
            try { MailService::deleteMailbox($row['mail_account_id']); } catch (Throwable $e) { error_log($e->getMessage()); }
        }

        $del = Database::app()->prepare('DELETE FROM email_accounts WHERE id = ?');
        $del->execute([$id]);
        MailRulesService::afterMailboxDeleted((int) $row['domain_id'], (string) $row['local_part']);

        Flash::ok('Mailbox deleted.');
        header('Location: /cpanel/email');
        exit;
    }

    public static function password(array $params): void
    {
        [$me, $row] = self::mailbox($params);
        $password = (string) ($_POST['password'] ?? '');
        if (($problem = Passwords::problem($password, (string) $row['local_part'])) !== null) {
            self::back('Mailbox: ' . $problem);
        }
        try {
            MailService::setPassword((string) $row['mail_account_id'], $password);
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok('Password changed.');
        self::back();
    }

    public static function forwarderStore(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $domain = self::domain($me, (int) ($_POST['domain_id'] ?? 0));
        try {
            MailRulesService::addForwarder((int) $me['id'], $domain, (string) ($_POST['local_part'] ?? ''), (string) ($_POST['destinations'] ?? ''));
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok('Forwarder saved.');
        self::back();
    }

    public static function forwarderDestroy(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $dest = trim((string) ($_POST['destination'] ?? ''));
        try {
            MailRulesService::removeForwarder((int) $me['id'], (int) ($params['id'] ?? 0), $dest !== '' ? $dest : null);
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok('Forwarder updated.');
        self::back();
    }

    public static function autoresponderSave(array $params): void
    {
        [$me, $row] = self::mailbox($params);
        try {
            MailRulesService::saveAutoresponder((int) $me['id'], (int) $row['id'], $_POST);
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok('Autoresponder saved.');
        self::back();
    }

    public static function autoresponderDestroy(array $params): void
    {
        [$me, $row] = self::mailbox($params);
        try {
            MailRulesService::deleteAutoresponder((int) $me['id'], (int) $row['id']);
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok('Autoresponder turned off.');
        self::back();
    }

    public static function defaultAddress(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $domain = self::domain($me, (int) ($_POST['domain_id'] ?? 0));
        try {
            MailRulesService::setDefaultAddress($domain, (string) ($_POST['target'] ?? ''));
        } catch (Throwable $e) {
            self::back($e->getMessage());
        }
        Flash::ok("Default address for {$domain['domain_name']} saved.");
        self::back();
    }

    /** mail.<domain> of the customer's domains with mailboxes, where webmail is served (it resolves here). */
    private static function webmailHosts(array $accounts, array $domains): array
    {
        $out = [];
        $hosted = array_column($domains, 'domain_name');
        foreach (array_unique(array_column($accounts, 'domain_name')) as $d) {
            if (!in_array("mail.$d", $hosted, true) && SslService::resolvesHere("mail.$d")) {
                $out["mail.$d"] = true;
            }
        }
        return $out;
    }

    /** @return array{0:array,1:array} the user and their mailbox from {id} (POST + CSRF checked) */
    private static function mailbox(array $params): array
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM email_accounts WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($params['id'] ?? 0), $me['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            self::back('Mailbox not found.');
        }
        return [$me, $row];
    }

    private static function domain(array $me, int $domainId): array
    {
        $stmt = Database::app()->prepare('SELECT * FROM domains WHERE id = ? AND user_id = ?');
        $stmt->execute([$domainId, $me['id']]);
        return $stmt->fetch() ?: self::back('Select one of your domains.');
    }

    private static function back(?string $error = null): never
    {
        if ($error !== null) {
            Flash::error($error);
        }
        header('Location: /cpanel/email');
        exit;
    }
}
