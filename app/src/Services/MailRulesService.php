<?php
declare(strict_types=1);

/**
 * Forwarders, autoresponders and each domain's default address (catch-all),
 * the way cPanel offers them, on top of Stalwart:
 *
 *  - a forwarder for an address that is NOT a mailbox is a Stalwart mailing
 *    list (mail is only passed on);
 *  - a forwarder for a mailbox, and a mailbox's autoresponder, live in one
 *    Sieve script the panel writes into that mailbox ("redirect :copy" keeps
 *    the mailbox's own copy, like cPanel). One script, because Stalwart runs
 *    a single active script per mailbox - its own vacation script would be
 *    switched off by a forwarding script and vice versa;
 *  - the default address is the mail domain's catchAllAddress.
 *
 * The panel DB (email_forwarders, email_autoresponders, domains.catch_all)
 * is the source of truth; applyMailbox() re-renders a mailbox's script.
 */
final class MailRulesService
{
    public const SCRIPT_NAME = 'jinnpanel';
    private const MAX_DESTINATIONS = 10;
    private const LOCAL_RE = '/^[a-z0-9][a-z0-9._+-]{0,63}$/';

    // ------------------------------------------------------------------
    // Forwarders
    // ------------------------------------------------------------------

    /** @return list<string> normalised, validated destination addresses */
    public static function parseDestinations(string $raw, string $self): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $a) {
            if ($a === '') {
                continue;
            }
            if (!filter_var($a, FILTER_VALIDATE_EMAIL) || preg_match('/["\\\\\r\n]/', $a)) {
                throw new InvalidArgumentException("\"$a\" is not a valid email address.");
            }
            if ($a === $self) {
                throw new InvalidArgumentException("$self can't forward to itself.");
            }
            $out[$a] = true;
        }
        if (!$out) {
            throw new InvalidArgumentException('Enter at least one address to forward to.');
        }
        if (count($out) > self::MAX_DESTINATIONS) {
            throw new InvalidArgumentException('At most ' . self::MAX_DESTINATIONS . ' destinations per forwarder.');
        }
        return array_keys($out);
    }

    /**
     * Adds destinations to the forwarder for local@domain (creating it).
     *
     * @param array<string,mixed> $domain the customer's domains row
     */
    public static function addForwarder(int $userId, array $domain, string $local, string $rawDestinations): void
    {
        $local = strtolower(trim($local));
        if (!preg_match(self::LOCAL_RE, $local)) {
            throw new InvalidArgumentException('Enter a valid address (the part before @).');
        }
        $address = "$local@{$domain['domain_name']}";
        $dest = self::parseDestinations($rawDestinations, $address);
        $mailDomainId = self::mailDomain($domain);
        $pdo = Database::app();

        $f = $pdo->prepare('SELECT * FROM email_forwarders WHERE domain_id = ? AND local_part = ?');
        $f->execute([$domain['id'], $local]);
        $existing = $f->fetch() ?: null;
        $all = array_values(array_unique(array_merge($existing ? explode(',', (string) $existing['destinations']) : [], $dest)));
        if (count($all) > self::MAX_DESTINATIONS) {
            throw new InvalidArgumentException('At most ' . self::MAX_DESTINATIONS . ' destinations per forwarder.');
        }

        $mailbox = self::mailboxRow((int) $domain['id'], $local);
        if ($mailbox !== null) {
            // A mailbox keeps its copy: Sieve redirect inside it.
            if ($existing) {
                $pdo->prepare('UPDATE email_forwarders SET destinations = ? WHERE id = ?')->execute([implode(',', $all), $existing['id']]);
            } else {
                $pdo->prepare('INSERT INTO email_forwarders (user_id, domain_id, local_part, destinations, mail_list_id) VALUES (?, ?, ?, ?, NULL)')
                    ->execute([$userId, $domain['id'], $local, implode(',', $all)]);
            }
            self::applyMailbox($mailbox);
            return;
        }
        if ($existing && $existing['mail_list_id']) {
            MailService::updateList((string) $existing['mail_list_id'], $all);
            $pdo->prepare('UPDATE email_forwarders SET destinations = ? WHERE id = ?')->execute([implode(',', $all), $existing['id']]);
            return;
        }
        $listId = MailService::createList($mailDomainId, $local, $all);
        $pdo->prepare('INSERT INTO email_forwarders (user_id, domain_id, local_part, destinations, mail_list_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $domain['id'], $local, implode(',', $all), $listId]);
    }

    /** Removes one destination ($destination given) or the whole forwarder. */
    public static function removeForwarder(int $userId, int $forwarderId, ?string $destination = null): void
    {
        $pdo = Database::app();
        $f = $pdo->prepare('SELECT f.*, d.domain_name FROM email_forwarders f JOIN domains d ON d.id = f.domain_id WHERE f.id = ? AND f.user_id = ?');
        $f->execute([$forwarderId, $userId]);
        $row = $f->fetch() ?: throw new InvalidArgumentException('Forwarder not found.');
        $left = $destination === null ? [] : array_values(array_diff(explode(',', (string) $row['destinations']), [strtolower($destination)]));

        if ($left) {
            $pdo->prepare('UPDATE email_forwarders SET destinations = ? WHERE id = ?')->execute([implode(',', $left), $row['id']]);
            if ($row['mail_list_id']) {
                MailService::updateList((string) $row['mail_list_id'], $left);
            }
        } else {
            $pdo->prepare('DELETE FROM email_forwarders WHERE id = ?')->execute([$row['id']]);
            if ($row['mail_list_id']) {
                MailService::deleteList((string) $row['mail_list_id']);
            }
        }
        if (!$row['mail_list_id'] && ($mailbox = self::mailboxRow((int) $row['domain_id'], (string) $row['local_part'])) !== null) {
            self::applyMailbox($mailbox);
        }
    }

    /**
     * Before a mailbox local@domain is created: a list-based forwarder of
     * that name would clash with it, so turn it into a Sieve forwarder
     * (applied once the mailbox exists - see afterMailboxCreated()).
     */
    public static function beforeMailboxCreated(int $domainId, string $local): void
    {
        $f = Database::app()->prepare('SELECT * FROM email_forwarders WHERE domain_id = ? AND local_part = ? AND mail_list_id IS NOT NULL');
        $f->execute([$domainId, $local]);
        if ($row = $f->fetch()) {
            MailService::deleteList((string) $row['mail_list_id']);
            Database::app()->prepare('UPDATE email_forwarders SET mail_list_id = NULL WHERE id = ?')->execute([$row['id']]);
        }
    }

    public static function afterMailboxCreated(int $domainId, string $local): void
    {
        if (($mailbox = self::mailboxRow($domainId, $local)) !== null) {
            self::applyMailbox($mailbox);
        }
    }

    /** A mailbox is going away: its forwarders become plain (list) forwarders again; the autoresponder goes. */
    public static function beforeMailboxDeleted(array $mailboxRow): void
    {
        $pdo = Database::app();
        $f = $pdo->prepare('SELECT f.*, d.mail_domain_id FROM email_forwarders f JOIN domains d ON d.id = f.domain_id WHERE f.domain_id = ? AND f.local_part = ?');
        $f->execute([$mailboxRow['domain_id'], $mailboxRow['local_part']]);
        $row = $f->fetch();
        if ($row && $row['mail_domain_id']) {
            // Stalwart frees the name only once the account is gone, so the
            // list is created by afterMailboxDeleted().
            $pdo->prepare("UPDATE email_forwarders SET mail_list_id = 'pending' WHERE id = ?")->execute([$row['id']]);
        }
    }

    public static function afterMailboxDeleted(int $domainId, string $local): void
    {
        $pdo = Database::app();
        $f = $pdo->prepare("SELECT f.*, d.mail_domain_id FROM email_forwarders f JOIN domains d ON d.id = f.domain_id WHERE f.domain_id = ? AND f.local_part = ? AND f.mail_list_id = 'pending'");
        $f->execute([$domainId, $local]);
        if ($row = $f->fetch()) {
            try {
                $id = MailService::createList((string) $row['mail_domain_id'], $local, explode(',', (string) $row['destinations']));
                $pdo->prepare('UPDATE email_forwarders SET mail_list_id = ? WHERE id = ?')->execute([$id, $row['id']]);
            } catch (Throwable $e) {
                $pdo->prepare('DELETE FROM email_forwarders WHERE id = ?')->execute([$row['id']]);
                error_log("forwarder $local: " . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------
    // Autoresponders
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $in subject, body, starts_on, ends_on, interval_days */
    public static function saveAutoresponder(int $userId, int $emailAccountId, array $in): void
    {
        $mailbox = self::ownedMailbox($userId, $emailAccountId);
        $subject = trim((string) ($in['subject'] ?? ''));
        $body = str_replace("\r\n", "\n", trim((string) ($in['body'] ?? '')));
        if ($subject === '' || mb_strlen($subject) > 200 || preg_match('/[\x00-\x1f]/', $subject)) {
            throw new InvalidArgumentException('Enter a subject (up to 200 characters, one line).');
        }
        if ($body === '' || strlen($body) > 10000) {
            throw new InvalidArgumentException('Enter a message (up to 10,000 characters).');
        }
        $start = self::date($in['starts_on'] ?? '');
        $end = self::date($in['ends_on'] ?? '');
        if ($start && $end && $end < $start) {
            throw new InvalidArgumentException('The end date is before the start date.');
        }
        $interval = max(1, min(30, (int) ($in['interval_days'] ?? 1)));
        Database::app()->prepare(
            'INSERT INTO email_autoresponders (user_id, email_account_id, subject, body, starts_on, ends_on, interval_days) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body = VALUES(body), starts_on = VALUES(starts_on), ends_on = VALUES(ends_on), interval_days = VALUES(interval_days)'
        )->execute([$userId, $emailAccountId, $subject, $body, $start, $end, $interval]);
        self::applyMailbox($mailbox);
    }

    public static function deleteAutoresponder(int $userId, int $emailAccountId): void
    {
        $mailbox = self::ownedMailbox($userId, $emailAccountId);
        Database::app()->prepare('DELETE FROM email_autoresponders WHERE email_account_id = ?')->execute([$emailAccountId]);
        self::applyMailbox($mailbox);
    }

    // ------------------------------------------------------------------
    // Default address (catch-all)
    // ------------------------------------------------------------------

    /**
     * $target: '' (reject unknown recipients) or a local part of one of the
     * domain's mailboxes or forwarders.
     *
     * @param array<string,mixed> $domain
     */
    public static function setDefaultAddress(array $domain, string $target): void
    {
        $target = strtolower(trim($target));
        $address = null;
        if ($target !== '') {
            $isBox = self::mailboxRow((int) $domain['id'], $target) !== null;
            $f = Database::app()->prepare('SELECT COUNT(*) FROM email_forwarders WHERE domain_id = ? AND local_part = ?');
            $f->execute([$domain['id'], $target]);
            if (!$isBox && (int) $f->fetchColumn() === 0) {
                throw new InvalidArgumentException('Choose one of the domain\'s mailboxes or forwarders.');
            }
            $address = "$target@{$domain['domain_name']}";
        }
        MailService::setCatchAll(self::mailDomain($domain), $address);
        Database::app()->prepare('UPDATE domains SET catch_all = ? WHERE id = ?')->execute([$address, $domain['id']]);
    }

    // ------------------------------------------------------------------
    // The mailbox's Sieve script
    // ------------------------------------------------------------------

    /** Re-renders and installs (or removes) the panel's Sieve script for a mailbox. */
    public static function applyMailbox(array $mailbox): void
    {
        $pdo = Database::app();
        $d = $pdo->prepare('SELECT domain_name FROM domains WHERE id = ?');
        $d->execute([$mailbox['domain_id']]);
        $address = $mailbox['local_part'] . '@' . $d->fetchColumn();

        $f = $pdo->prepare('SELECT destinations FROM email_forwarders WHERE domain_id = ? AND local_part = ? AND mail_list_id IS NULL');
        $f->execute([$mailbox['domain_id'], $mailbox['local_part']]);
        $forward = array_filter(explode(',', (string) ($f->fetchColumn() ?: '')));

        $a = $pdo->prepare('SELECT * FROM email_autoresponders WHERE email_account_id = ?');
        $a->execute([$mailbox['id']]);
        $auto = $a->fetch() ?: null;

        MailService::setSieveScript($address, self::SCRIPT_NAME, self::renderScript($address, $forward, $auto));
    }

    /** @param list<string> $forward */
    public static function renderScript(string $address, array $forward, ?array $auto): ?string
    {
        if (!$forward && !$auto) {
            return null;
        }
        $req = [];
        $body = [];
        if ($auto) {
            $req = array_merge($req, ['vacation']);
            $vacation = sprintf('vacation :days %d :subject %s :addresses [%s] %s;',
                (int) $auto['interval_days'], self::str((string) $auto['subject']), self::str($address), self::str((string) $auto['body']));
            $tests = [];
            if (!empty($auto['starts_on'])) {
                $tests[] = 'currentdate :value "ge" "date" ' . self::str((string) $auto['starts_on']);
            }
            if (!empty($auto['ends_on'])) {
                $tests[] = 'currentdate :value "le" "date" ' . self::str((string) $auto['ends_on']);
            }
            if ($tests) {
                $req = array_merge($req, ['date', 'relational']);
                $body[] = 'if allof(' . implode(', ', $tests) . ") {\n    $vacation\n}";
            } else {
                $body[] = $vacation;
            }
        }
        if ($forward) {
            $req[] = 'copy';
            foreach ($forward as $to) {
                $body[] = 'redirect :copy ' . self::str($to) . ';';
            }
        }
        $quoted = implode(', ', array_map([self::class, 'str'], array_values(array_unique($req))));
        return "# Managed by JinnPanel (cPanel > Email: forwarders and autoresponder) - edits here are overwritten.\n"
            . "require [$quoted];\n\n" . implode("\n", $body) . "\n";
    }

    private static function str(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private static function date(mixed $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            throw new InvalidArgumentException('Dates must look like 2026-10-31.');
        }
        return $raw;
    }

    private static function mailDomain(array $domain): string
    {
        if (!empty($domain['mail_domain_id'])) {
            return (string) $domain['mail_domain_id'];
        }
        $id = MailService::ensureDomain((string) $domain['domain_name']);
        Database::app()->prepare('UPDATE domains SET mail_domain_id = ? WHERE id = ?')->execute([$id, $domain['id']]);
        MailDnsService::syncAfterMailDomain((int) $domain['id']);
        return $id;
    }

    private static function mailboxRow(int $domainId, string $local): ?array
    {
        $s = Database::app()->prepare('SELECT * FROM email_accounts WHERE domain_id = ? AND local_part = ?');
        $s->execute([$domainId, $local]);
        return $s->fetch() ?: null;
    }

    private static function ownedMailbox(int $userId, int $emailAccountId): array
    {
        $s = Database::app()->prepare('SELECT * FROM email_accounts WHERE id = ? AND user_id = ?');
        $s->execute([$emailAccountId, $userId]);
        return $s->fetch() ?: throw new InvalidArgumentException('Mailbox not found.');
    }
}
