<?php
declare(strict_types=1);

/**
 * Talks to Stalwart Mail Server's JMAP admin API to provision real mail
 * domains and mailboxes. Stalwart has no separate REST/CLI for this -
 * everything goes through JMAP "x:"-prefixed system object methods.
 */
final class MailService
{
    private const USING = [
        'urn:ietf:params:jmap:core',
        'urn:stalwart:jmap',
        'urn:ietf:params:jmap:blob',
        'urn:ietf:params:jmap:mail',
        'urn:ietf:params:jmap:calendars',
        'urn:ietf:params:jmap:contacts',
        'urn:ietf:params:jmap:principals',
        'urn:ietf:params:jmap:sieve',
        'urn:ietf:params:jmap:vacationresponse',
    ];

    private static ?string $accountId = null;

    private static function auth(): string
    {
        return Config::MAIL_ADMIN_USER . ':' . Config::MAIL_ADMIN_PASS;
    }

    private static function accountId(): string
    {
        if (self::$accountId !== null) {
            return self::$accountId;
        }
        $res = Http::json('GET', Config::MAIL_API_BASE . '/jmap/session', null, [], self::auth());
        if ($res['status'] !== 200 || !isset($res['body']['primaryAccounts'])) {
            throw new RuntimeException('Could not reach Stalwart JMAP session endpoint.');
        }
        $primary = array_values($res['body']['primaryAccounts'])[0] ?? null;
        if (!$primary) {
            throw new RuntimeException('No primary Stalwart account found.');
        }
        return self::$accountId = $primary;
    }

    /** @return array<int, array{0:string,1:array,2:string}> methodResponses */
    private static function call(array $methodCalls): array
    {
        $res = Http::json('POST', Config::MAIL_API_BASE . '/jmap/', [
            'using' => self::USING,
            'methodCalls' => $methodCalls,
        ], [], self::auth());

        if ($res['status'] !== 200) {
            // Not the raw body: Stalwart echoes the request back, passwords included.
            $type = is_array($res['body'] ?? null) ? (string) ($res['body']['type'] ?? '') : '';
            throw new RuntimeException('Stalwart JMAP call failed with HTTP ' . $res['status'] . ($type !== '' ? " ($type)" : '') . '.');
        }
        return $res['body']['methodResponses'] ?? [];
    }

    /**
     * Creates the mail domain if it doesn't already exist and returns its
     * Stalwart object id.
     */
    public static function ensureDomain(string $domainName): string
    {
        $responses = self::call([
            ['x:Domain/set', [
                'accountId' => self::accountId(),
                'create' => ['d1' => ['name' => $domainName, 'isEnabled' => true]],
            ], '0'],
        ]);
        $result = $responses[0][1] ?? [];

        if (isset($result['created']['d1']['id'])) {
            return $result['created']['d1']['id'];
        }

        // Domain probably already exists - look it up.
        $queryResponses = self::call([
            ['x:Domain/query', [
                'accountId' => self::accountId(),
                'filter' => ['name' => $domainName],
            ], '0'],
        ]);
        $ids = $queryResponses[0][1]['ids'] ?? [];
        if (!empty($ids)) {
            return $ids[0];
        }

        $reason = json_encode($result['notCreated'] ?? $result);
        throw new RuntimeException("Could not create or find mail domain '$domainName': $reason");
    }

    // ------------------------------------------------------------------
    // Forwarders (mailing lists), catch-all, listeners
    // ------------------------------------------------------------------

    /**
     * Removes a mail domain with its mailboxes, lists and DKIM keys. Stalwart
     * deletes accounts in the background, so the domain itself may still be
     * "linked" right after; then it's queued and retryPendingDomainDeletes()
     * (daily sync) finishes the job. Returns whether the domain is gone.
     */
    public static function deleteDomain(string $mailDomainId): bool
    {
        $acc = self::accountId();
        $filter = ['domainId' => $mailDomainId];
        $r = self::call([
            ['x:Account/query', ['accountId' => $acc, 'filter' => $filter], 'a'],
            ['x:Account/set', ['accountId' => $acc, '#destroy' => ['resultOf' => 'a', 'name' => 'x:Account/query', 'path' => '/ids']], 'b'],
            ['x:MailingList/query', ['accountId' => $acc, 'filter' => $filter], 'c'],
            ['x:MailingList/set', ['accountId' => $acc, '#destroy' => ['resultOf' => 'c', 'name' => 'x:MailingList/query', 'path' => '/ids']], 'd'],
            ['x:DkimSignature/query', ['accountId' => $acc, 'filter' => $filter], 'e'],
            ['x:DkimSignature/set', ['accountId' => $acc, '#destroy' => ['resultOf' => 'e', 'name' => 'x:DkimSignature/query', 'path' => '/ids']], 'f'],
            ['x:Domain/set', ['accountId' => $acc, 'destroy' => [$mailDomainId]], 'g'],
        ]);
        $gone = in_array($mailDomainId, (array) ($r[6][1]['destroyed'] ?? []), true)
            || (($r[6][1]['notDestroyed'][$mailDomainId]['type'] ?? '') === 'notFound');
        $pending = json_decode((string) self::pendingSetting(), true) ?: [];
        $pending = array_values(array_diff($pending, [$mailDomainId]));
        if (!$gone) {
            $pending[] = $mailDomainId;
        }
        DnsService::putSetting('mail_domains_pending_delete', json_encode($pending));
        return $gone;
    }

    /** @return list<string> log lines */
    public static function retryPendingDomainDeletes(): array
    {
        $out = [];
        foreach (json_decode((string) self::pendingSetting(), true) ?: [] as $id) {
            $out[] = "mail domain $id: " . (self::deleteDomain((string) $id) ? 'deleted' : 'still linked, retrying tomorrow');
        }
        return $out;
    }

    private static function pendingSetting(): ?string
    {
        $s = Database::app()->prepare("SELECT setting_value FROM panel_settings WHERE setting_key = 'mail_domains_pending_delete'");
        $s->execute();
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /** A forwarder for an address that isn't a mailbox: a Stalwart mailing list. Returns its id. */
    public static function createList(string $mailDomainId, string $localPart, array $recipients): string
    {
        $r = self::call([['x:MailingList/set', ['accountId' => self::accountId(), 'create' => ['l' => [
            'name' => $localPart, 'domainId' => $mailDomainId, 'recipients' => (object) array_fill_keys($recipients, true),
        ]]], '0']])[0][1];
        $id = $r['created']['l']['id'] ?? null;
        if ($id === null) {
            throw new RuntimeException("Could not create the forwarder: " . self::reason($r['notCreated']['l'] ?? $r));
        }
        return (string) $id;
    }

    public static function updateList(string $listId, array $recipients): void
    {
        $r = self::call([['x:MailingList/set', ['accountId' => self::accountId(),
            'update' => (object) [$listId => ['recipients' => (object) array_fill_keys($recipients, true)]]], '0']])[0][1];
        if (!array_key_exists($listId, (array) ($r['updated'] ?? []))) {
            throw new RuntimeException('Could not update the forwarder: ' . self::reason($r['notUpdated'][$listId] ?? $r));
        }
    }

    public static function deleteList(string $listId): void
    {
        self::call([['x:MailingList/set', ['accountId' => self::accountId(), 'destroy' => [$listId]], '0']]);
    }

    /** Where mail to unknown addresses of the domain goes; null = rejected. */
    public static function setCatchAll(string $mailDomainId, ?string $address): void
    {
        $r = self::call([['x:Domain/set', ['accountId' => self::accountId(),
            'update' => (object) [$mailDomainId => ['catchAllAddress' => $address]]], '0']])[0][1];
        if (!array_key_exists($mailDomainId, (array) ($r['updated'] ?? []))) {
            throw new RuntimeException('Could not set the default address: ' . self::reason($r['notUpdated'][$mailDomainId] ?? $r));
        }
    }

    /**
     * Server-wide mail policy the panel relies on (idempotent; daily sync):
     *  - loopback may relay without a login, but only for senders on this
     *    server's mail domains - that's PHP mail() (msmtp -> 127.0.0.1:25);
     *  - such mail is DKIM-signed for the sender domain, like authenticated mail;
     *  - outbound delivery prefers IPv6 (both families have matching rDNS);
     *  - MTA-STS policy: enforce.
     *
     * @return list<string> what changed
     */
    public static function ensureMailPolicy(): array
    {
        $loop = "remote_ip == '127.0.0.1' || remote_ip == '::1'";
        $want = [
            'x:MtaStageRcpt' => ['allowRelaying' => ['match' => (object) ['0' => ['if' => "($loop) && is_local_domain(sender_domain)", 'then' => 'true']], 'else' => '!is_empty(authenticated_as)']],
            'x:SenderAuth' => ['dkimSignDomain' => ['match' => (object) ['0' => ['if' => "is_local_domain(sender_domain) && (!is_empty(authenticated_as) || $loop)", 'then' => 'sender_domain']], 'else' => 'false']],
            'x:MtaSts' => ['mode' => 'enforce'],
        ];
        $changed = [];
        foreach ($want as $object => $props) {
            $current = self::call([["$object/get", ['accountId' => self::accountId(), 'ids' => ['singleton'], 'properties' => array_keys($props)], '0']])[0][1]['list'][0] ?? [];
            $patch = [];
            foreach ($props as $k => $v) {
                if (json_encode($current[$k] ?? null) !== json_encode(json_decode((string) json_encode($v), true))) {
                    $patch[$k] = $v;
                }
            }
            if ($patch) {
                $r = self::call([["$object/set", ['accountId' => self::accountId(), 'update' => ['singleton' => $patch]], '0']])[0][1];
                if (!array_key_exists('singleton', (array) ($r['updated'] ?? []))) {
                    throw new RuntimeException("$object: " . self::reason($r['notUpdated']['singleton'] ?? $r));
                }
                $changed[] = "$object " . implode(',', array_keys($patch));
            }
        }
        // MX delivery route: IPv6 first.
        foreach (self::call([['x:MtaRoute/get', ['accountId' => self::accountId(), 'ids' => null], '0']])[0][1]['list'] ?? [] as $route) {
            if (($route['@type'] ?? '') === 'Mx' && ($route['ipLookupStrategy'] ?? '') !== 'v6ThenV4') {
                self::call([['x:MtaRoute/set', ['accountId' => self::accountId(), 'update' => (object) [$route['id'] => ['ipLookupStrategy' => 'v6ThenV4']]], '0']]);
                $changed[] = 'MX route v6ThenV4';
            }
        }
        if ($changed) {
            self::call([['x:Action/set', ['accountId' => self::accountId(), 'create' => ['r' => ['@type' => 'ReloadSettings']]], '0']]);
        }
        return $changed;
    }

    /**
     * Port 587 (SMTP submission with STARTTLS): what most mail apps try
     * first. Stalwart's default setup only has 465. Idempotent.
     */
    public static function ensureSubmissionListener(): string
    {
        $list = self::call([['x:NetworkListener/get', ['accountId' => self::accountId(), 'ids' => null, 'properties' => ['bind']], '0']])[0][1]['list'] ?? [];
        foreach ($list as $l) {
            foreach (array_keys((array) ($l['bind'] ?? [])) as $b) {
                if (str_ends_with((string) $b, ':587')) {
                    return 'submission on 587: present';
                }
            }
        }
        $r = self::call([['x:NetworkListener/set', ['accountId' => self::accountId(), 'create' => ['l' => [
            'name' => 'submission', 'bind' => ['[::]:587' => true], 'protocol' => 'smtp', 'tlsImplicit' => false,
        ]]], '0']])[0][1];
        if (!isset($r['created']['l'])) {
            throw new RuntimeException('Could not add the 587 listener: ' . self::reason($r['notCreated']['l'] ?? $r));
        }
        return 'submission on 587: added';
    }

    // ------------------------------------------------------------------
    // Acting as a mailbox (master-user login: "<address>%<admin>")
    // ------------------------------------------------------------------

    /** @return array{0:string,1:callable} the mailbox's JMAP account id, and a call(methodCalls, using) function */
    public static function asMailbox(string $address): array
    {
        $auth = $address . '%' . Config::MAIL_ADMIN_USER . ':' . Config::MAIL_ADMIN_PASS;
        $session = Http::json('GET', Config::MAIL_API_BASE . '/jmap/session', null, [], $auth);
        $acc = $session['body']['primaryAccounts']['urn:ietf:params:jmap:mail'] ?? null;
        if ($session['status'] !== 200 || !is_string($acc)) {
            throw new RuntimeException("Could not open the mailbox $address on the mail server.");
        }
        $call = function (array $methodCalls, array $using) use ($auth): array {
            $res = Http::json('POST', Config::MAIL_API_BASE . '/jmap/', ['using' => $using, 'methodCalls' => $methodCalls], [], $auth);
            if ($res['status'] !== 200) {
                throw new RuntimeException('Mail server request failed (HTTP ' . $res['status'] . ').');
            }
            return $res['body']['methodResponses'] ?? [];
        };
        return [$acc, $call];
    }

    /**
     * Makes $script the mailbox's active Sieve script named $name (or
     * removes that script when $script is null). Stalwart runs one active
     * script per mailbox, so the panel keeps forwarding and autoreplies in
     * this single script.
     */
    public static function setSieveScript(string $address, string $name, ?string $script): void
    {
        [$acc, $call] = self::asMailbox($address);
        $using = ['urn:ietf:params:jmap:core', 'urn:ietf:params:jmap:sieve'];
        $existing = null;
        foreach ($call([['SieveScript/get', ['accountId' => $acc, 'properties' => ['name', 'isActive']], '0']], $using)[0][1]['list'] ?? [] as $s) {
            if (($s['name'] ?? '') === $name) {
                $existing = (string) $s['id'];
            }
        }
        if ($script === null) {
            if ($existing !== null) {
                $call([['SieveScript/set', ['accountId' => $acc, 'onSuccessDeactivateScript' => true, 'destroy' => [$existing]], '0']], $using);
            }
            return;
        }
        $auth = $address . '%' . Config::MAIL_ADMIN_USER . ':' . Config::MAIL_ADMIN_PASS;
        $ch = curl_init(Config::MAIL_API_BASE . '/jmap/upload/' . rawurlencode($acc) . '/');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => $auth,
            CURLOPT_HTTPHEADER => ['Content-Type: application/sieve'], CURLOPT_POSTFIELDS => $script, CURLOPT_TIMEOUT => 30]);
        $blob = json_decode((string) curl_exec($ch), true)['blobId'] ?? null;
        if (!is_string($blob)) {
            throw new RuntimeException('Could not upload the mail filter.');
        }
        $set = $existing !== null
            ? ['update' => (object) [$existing => ['blobId' => $blob]], 'onSuccessActivateScript' => $existing]
            : ['create' => ['s' => ['name' => $name, 'blobId' => $blob]], 'onSuccessActivateScript' => '#s'];
        $r = $call([['SieveScript/set', ['accountId' => $acc] + $set, '0']], $using)[0][1] ?? [];
        $err = $r['notCreated']['s'] ?? ($existing !== null ? ($r['notUpdated'][$existing] ?? null) : null);
        if ($err !== null || ($r['type'] ?? '') !== '' && !isset($r['accountId'])) {
            throw new RuntimeException('The mail server rejected the mail filter: ' . self::reason((array) ($err ?? $r)));
        }
    }

    /** The DNS records Stalwart wants for a mail domain, as a zone-file snippet (DKIM, SPF, DMARC, SRV, ...). */
    public static function dnsZoneFile(string $mailDomainId): string
    {
        $responses = self::call([
            ['x:Domain/get', ['accountId' => self::accountId(), 'ids' => [$mailDomainId], 'properties' => ['dnsZoneFile']], '0'],
        ]);
        $zone = $responses[0][1]['list'][0]['dnsZoneFile'] ?? null;
        if (!is_string($zone)) {
            throw new RuntimeException("Mail domain $mailDomainId not found on the mail server.");
        }
        return $zone;
    }

    /**
     * Makes $pem/$key Stalwart's certificate for $host (and its default for
     * clients without SNI), unless it already has that exact one. Older
     * certificates for the same name are removed.
     */
    public static function installCertificate(string $host, string $pem, string $key, int $validTo): string
    {
        $list = self::call([['x:Certificate/get', ['accountId' => self::accountId(), 'ids' => null,
            'properties' => ['subjectAlternativeNames', 'notValidAfter']], '0']])[0][1]['list'] ?? [];
        $old = [];
        foreach ($list as $c) {
            if (!array_key_exists($host, (array) ($c['subjectAlternativeNames'] ?? []))) {
                continue;
            }
            if (strtotime((string) $c['notValidAfter']) === $validTo) {
                return 'unchanged (valid until ' . gmdate('Y-m-d', $validTo) . ')';
            }
            $old[] = (string) $c['id'];
        }
        $created = self::call([['x:Certificate/set', ['accountId' => self::accountId(), 'create' => ['c1' => [
            'certificate' => ['@type' => 'Text', 'value' => $pem],
            'privateKey' => ['@type' => 'Text', 'secret' => $key],
        ]]], '0']])[0][1];
        $id = $created['created']['c1']['id'] ?? null;
        if ($id === null) {
            throw new RuntimeException('Mail server refused the certificate: ' . self::reason($created['notCreated']['c1'] ?? $created));
        }
        self::call([
            ['x:SystemSettings/set', ['accountId' => self::accountId(), 'update' => ['singleton' => ['defaultCertificateId' => $id]]], '0'],
            ['x:Certificate/set', ['accountId' => self::accountId(), 'destroy' => $old], '1'],
            ['x:Action/set', ['accountId' => self::accountId(), 'create' => ['r' => ['@type' => 'ReloadTlsCertificates']]], '2'],
        ]);
        return 'installed (valid until ' . gmdate('Y-m-d', $validTo) . ')';
    }

    /**
     * Creates a mailbox (name@domain) with a password and returns the
     * Stalwart account object id.
     *
     * The password may be plaintext or an existing crypt()-style hash
     * ($6$/$5$/$1$/bcrypt) - Stalwart recognises and verifies hashed secrets
     * natively, which is how a cPanel migration keeps a mailbox's password
     * working without the panel ever knowing it. Plaintext passwords must
     * pass Stalwart's strength check.
     */
    public static function createMailbox(string $mailDomainId, string $localPart, string $password): string
    {
        // Stalwart (0.16) rejects `credentials` in an Account create; the
        // account has to be created bare and its password set by a patch.
        $responses = self::call([
            ['x:Account/set', [
                'accountId' => self::accountId(),
                'create' => ['a1' => [
                    '@type' => 'User',
                    'name' => $localPart,
                    'domainId' => $mailDomainId,
                ]],
            ], '0'],
        ]);
        $result = $responses[0][1] ?? [];
        $id = $result['created']['a1']['id'] ?? null;
        if ($id === null) {
            throw new RuntimeException("Could not create mailbox '$localPart': " . self::reason($result['notCreated']['a1'] ?? $result));
        }
        try {
            self::setPassword($id, $password);
        } catch (Throwable $e) {
            // Don't leave a mailbox nobody can log in to behind.
            try { self::deleteMailbox($id); } catch (Throwable) {}
            throw $e;
        }
        return $id;
    }

    /**
     * Replaces a mailbox's password (plaintext or crypt()-style hash).
     * Stalwart allows only one password credential per account, and patching
     * index 0 replaces it.
     */
    public static function setPassword(string $mailAccountId, string $password): void
    {
        $responses = self::call([
            ['x:Account/set', [
                'accountId' => self::accountId(),
                // An object even when the id looks numeric (PHP would make "0" a list).
                'update' => (object) [$mailAccountId => [
                    'credentials/0' => ['@type' => 'Password', 'secret' => $password],
                ]],
            ], '0'],
        ]);
        $result = $responses[0][1] ?? [];
        if (!array_key_exists($mailAccountId, (array) ($result['updated'] ?? []))) {
            throw new RuntimeException('Could not set the mailbox password: ' . self::reason($result['notUpdated'][$mailAccountId] ?? $result));
        }
    }

    /**
     * Runs $fn with the mailbox exempt from Stalwart's per-account upload
     * quota (50 MB / 1000 files per hour by default) and request rate
     * limit, which a mail import hits within seconds; the account's own
     * permissions are restored afterwards, whatever happens.
     */
    public static function withImportLimitsLifted(string $mailAccountId, callable $fn): mixed
    {
        $get = self::call([['x:Account/get', ['accountId' => self::accountId(), 'ids' => [$mailAccountId], 'properties' => ['permissions']], '0']]);
        $original = $get[0][1]['list'][0]['permissions'] ?? null;
        if (!is_array($original)) {
            throw new RuntimeException('Could not read the mailbox permissions.');
        }
        $lifted = ['@type' => 'Merge', 'enabledPermissions' => ['unlimitedUploads' => true, 'unlimitedRequests' => true], 'disabledPermissions' => []];
        if (($original['@type'] ?? '') === 'Merge') {
            $lifted['enabledPermissions'] += (array) ($original['enabledPermissions'] ?? []);
            $lifted['disabledPermissions'] = $original['disabledPermissions'] ?? [];
        }
        self::setPermissions($mailAccountId, $lifted);
        try {
            return $fn();
        } finally {
            self::setPermissions($mailAccountId, $original);
        }
    }

    /** Bytes the mailboxes use (Stalwart's usedDiskQuota), summed. @param list<string> $ids */
    public static function usedBytes(array $ids): int
    {
        $total = 0;
        foreach (array_chunk($ids, 100) as $chunk) {
            $r = self::call([['x:Account/get', ['accountId' => self::accountId(), 'ids' => $chunk, 'properties' => ['usedDiskQuota']], '0']]);
            foreach ((array) ($r[0][1]['list'] ?? []) as $acct) {
                $total += (int) ($acct['usedDiskQuota'] ?? 0);
            }
        }
        return $total;
    }

    /**
     * Suspension: a mailbox that may not log in (IMAP, POP3, SMTP
     * submission, webmail) - mail to it is still accepted and kept. Only
     * the "authenticate" permission is touched; anything else the mailbox
     * has (e.g. lifted import limits) stays.
     */
    public static function setLoginAllowed(string $mailAccountId, bool $allowed): void
    {
        $get = self::call([['x:Account/get', ['accountId' => self::accountId(), 'ids' => [$mailAccountId], 'properties' => ['permissions']], '0']]);
        $perm = $get[0][1]['list'][0]['permissions'] ?? null;
        if (!is_array($perm)) {
            throw new RuntimeException('Could not read the mailbox permissions.');
        }
        $type = (string) ($perm['@type'] ?? 'Inherit');
        $enabled = (array) ($perm['enabledPermissions'] ?? []);
        $disabled = (array) ($perm['disabledPermissions'] ?? []);
        if ($allowed) {
            unset($disabled['authenticate']);
        } else {
            $disabled['authenticate'] = true;
        }
        if ($type === 'Replace') {
            if ($allowed) {
                $enabled['authenticate'] = true;
            } else {
                unset($enabled['authenticate']);
            }
            self::setPermissions($mailAccountId, ['@type' => 'Replace', 'enabledPermissions' => $enabled, 'disabledPermissions' => $disabled]);
            return;
        }
        self::setPermissions($mailAccountId, $enabled === [] && $disabled === []
            ? ['@type' => 'Inherit']
            : ['@type' => 'Merge', 'enabledPermissions' => $enabled, 'disabledPermissions' => $disabled]);
    }

    private static function setPermissions(string $mailAccountId, array $permissions): void
    {
        // Permission sets are maps; Stalwart returns an empty one as [] but
        // only accepts {} back.
        foreach (['enabledPermissions', 'disabledPermissions'] as $k) {
            if (isset($permissions[$k]) && $permissions[$k] === []) {
                $permissions[$k] = new stdClass();
            }
        }
        $responses = self::call([
            ['x:Account/set', [
                'accountId' => self::accountId(),
                'update' => (object) [$mailAccountId => ['permissions' => $permissions]],
            ], '0'],
        ]);
        $result = $responses[0][1] ?? [];
        if (!array_key_exists($mailAccountId, (array) ($result['updated'] ?? []))) {
            throw new RuntimeException('Could not change the mailbox permissions: ' . self::reason($result['notUpdated'][$mailAccountId] ?? $result));
        }
    }

    /** Stalwart's SetError description (e.g. "Password is too weak...") when it gave one. */
    private static function reason(array $error): string
    {
        return $error['description'] ?? json_encode($error);
    }

    public static function deleteMailbox(string $mailAccountId): void
    {
        self::call([
            ['x:Account/set', [
                'accountId' => self::accountId(),
                'destroy' => [$mailAccountId],
            ], '0'],
        ]);
    }
}
