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

        View::render('cpanel/email', [
            'title' => 'Email Accounts',
            'accounts' => $stmt->fetchAll(),
            'domains' => $domainsStmt->fetchAll(),
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
        if (strlen($password) < 8) {
            Flash::error('Mailbox password must be at least 8 characters.');
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
            }
            $mailAccountId = MailService::createMailbox($mailDomainId, $localPart, $password);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the mailbox on the mail server: ' . $e->getMessage());
            header('Location: /cpanel/email');
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO email_accounts (user_id, domain_id, local_part, mail_account_id) VALUES (?, ?, ?, ?)');
        $stmt->execute([$me['id'], $domain['id'], $localPart, $mailAccountId]);

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

        if ($row['mail_account_id']) {
            try { MailService::deleteMailbox($row['mail_account_id']); } catch (Throwable $e) { error_log($e->getMessage()); }
        }

        $del = Database::app()->prepare('DELETE FROM email_accounts WHERE id = ?');
        $del->execute([$id]);

        Flash::ok('Mailbox deleted.');
        header('Location: /cpanel/email');
        exit;
    }
}
