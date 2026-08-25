<?php
declare(strict_types=1);

/**
 * Generic schema-driven settings access for Stalwart. Stalwart has no fixed
 * REST API for its ~150 "x:"-prefixed system settings objects - the same
 * management UI you'd click through is built dynamically from a schema the
 * server serves at /api/schema. This talks that same protocol directly, so
 * any singleton settings object in that schema (cache sizes, thread pool,
 * spam thresholds, TLS reporting, ...) is editable through one code path
 * instead of hand-building 50 forms.
 */
final class StalwartAdminService
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

    /** Curated list of the settings objects worth surfacing in the WHM UI, grouped for the menu. */
    public const SETTINGS_GROUPS = [
        'Server' => ['x:SystemSettings', 'x:Cache', 'x:InMemoryStore', 'x:DnsResolver', 'x:TaskManager'],
        'Web & API' => ['x:Http', 'x:Jmap', 'x:WebDav', 'x:Sharing'],
        'Mail protocols' => ['x:Imap', 'x:Email', 'x:MtaInboundSession', 'x:MtaOutboundStrategy', 'x:MtaExtensions'],
        'Security & spam' => ['x:Authentication', 'x:Security', 'x:SpamSettings', 'x:SpamDnsblSettings', 'x:SenderAuth'],
        'Storage' => ['x:DataStore', 'x:BlobStore', 'x:SearchStore', 'x:DataRetention'],
        'Reporting' => ['x:ReportSettings', 'x:DmarcReportSettings', 'x:DkimReportSettings', 'x:TlsReportSettings'],
    ];

    private static ?string $accountId = null;
    private static ?array $schema = null;

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
        $primary = array_values($res['body']['primaryAccounts'] ?? [])[0] ?? null;
        if (!$primary) {
            throw new RuntimeException('Could not reach Stalwart JMAP session endpoint.');
        }
        return self::$accountId = $primary;
    }

    /** Full schema (objects/schemas/fields/forms/enums) - fetched once per request and cached. */
    public static function schema(): array
    {
        if (self::$schema !== null) {
            return self::$schema;
        }
        $res = Http::json('GET', Config::MAIL_API_BASE . '/api/schema', null, [], self::auth());
        // The endpoint 302s to a content-hashed URL; our tiny client doesn't
        // follow redirects, so do it manually once.
        if ($res['status'] === 302) {
            $ch = curl_init(Config::MAIL_API_BASE . '/api/schema');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERPWD => self::auth(),
                CURLOPT_ENCODING => '',
            ]);
            $raw = curl_exec($ch);
            curl_close($ch);
            $res['body'] = json_decode($raw, true);
        }
        if (!is_array($res['body'])) {
            throw new RuntimeException('Could not load Stalwart schema.');
        }
        return self::$schema = $res['body'];
    }

    public static function fieldsFor(string $objectName): array
    {
        $schemaName = self::schema()['schemas'][$objectName]['schemaName'] ?? $objectName;
        return self::schema()['fields'][$schemaName] ?? ['properties' => [], 'defaults' => []];
    }

    /** Current stored values for a singleton object. */
    public static function get(string $objectName): array
    {
        $res = self::call([[
            "$objectName/get", ['accountId' => self::accountId(), 'ids' => null], '0',
        ]]);
        return $res[0][1]['list'][0] ?? [];
    }

    /** @param array<string,mixed> $values */
    public static function set(string $objectName, array $values): array
    {
        $res = self::call([[
            "$objectName/set",
            ['accountId' => self::accountId(), 'update' => ['singleton' => $values]],
            '0',
        ]]);
        $result = $res[0][1] ?? [];
        if (isset($result['notUpdated']['singleton'])) {
            throw new RuntimeException(json_encode($result['notUpdated']['singleton']));
        }
        return $result['updated']['singleton'] ?? [];
    }

    private static function call(array $methodCalls): array
    {
        $res = Http::json('POST', Config::MAIL_API_BASE . '/jmap/', [
            'using' => self::USING,
            'methodCalls' => $methodCalls,
        ], [], self::auth());
        if ($res['status'] !== 200) {
            throw new RuntimeException('Stalwart JMAP call failed: ' . $res['raw']);
        }
        return $res['body']['methodResponses'] ?? [];
    }
}
