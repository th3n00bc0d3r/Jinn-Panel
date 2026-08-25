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
            throw new RuntimeException('Stalwart JMAP call failed with HTTP ' . $res['status'] . ': ' . $res['raw']);
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

    /**
     * Creates a mailbox (name@domain) with a password credential.
     * Returns the Stalwart account object id.
     */
    public static function createMailbox(string $mailDomainId, string $localPart, string $password): string
    {
        $responses = self::call([
            ['x:Account/set', [
                'accountId' => self::accountId(),
                'create' => ['a1' => [
                    '@type' => 'User',
                    'name' => $localPart,
                    'domainId' => $mailDomainId,
                    'credentials' => [
                        ['@type' => 'Password', 'secret' => $password],
                    ],
                ]],
            ], '0'],
        ]);
        $result = $responses[0][1] ?? [];
        if (isset($result['created']['a1']['id'])) {
            return $result['created']['a1']['id'];
        }
        $reason = json_encode($result['notCreated'] ?? $result);
        throw new RuntimeException("Could not create mailbox '$localPart': $reason");
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
