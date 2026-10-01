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
