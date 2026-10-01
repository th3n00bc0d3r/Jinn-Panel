<?php
declare(strict_types=1);

/**
 * "Server Config" area of WHM - admin-only, direct control over the
 * underlying services (Stalwart, SFTPGo, PHP, MariaDB) plus one-click
 * performance tuning. Not scoped to resellers: this is server-wide
 * infrastructure, not a hosting account's own settings.
 */
final class ServerConfigController
{
    // ---- Mail (Stalwart) - generic schema-driven settings ----

    public static function mailIndex(): void
    {
        Auth::requireRole(['admin']);
        View::render('whm/server_config/mail', [
            'title' => 'Mail Server Settings',
            'groups' => StalwartAdminService::SETTINGS_GROUPS,
        ], 'whm');
    }

    public static function mailEdit(array $params): void
    {
        Auth::requireRole(['admin']);
        $object = self::validObjectName($params['object']);

        try {
            $fields = StalwartAdminService::fieldsFor($object);
            $values = StalwartAdminService::get($object);
        } catch (Throwable $e) {
            Flash::error('Could not load settings: ' . $e->getMessage());
            header('Location: /whm/server-config/mail');
            exit;
        }

        View::render('whm/server_config/mail_edit', [
            'title' => $object,
            'object' => $object,
            'fields' => $fields['properties'] ?? [],
            'values' => $values,
            'enums' => StalwartAdminService::schema()['enums'] ?? [],
        ], 'whm');
    }

    public static function mailUpdate(array $params): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $object = self::validObjectName($params['object']);

        $fields = StalwartAdminService::fieldsFor($object)['properties'] ?? [];
        $update = self::collectFormValues($fields, $_POST);

        try {
            StalwartAdminService::set($object, $update);
            Flash::ok("$object updated - takes effect immediately, no restart needed.");
        } catch (Throwable $e) {
            Flash::error('Update failed: ' . $e->getMessage());
        }
        header("Location: /whm/server-config/mail/$object");
        exit;
    }

    // ---- SFTPGo ----

    public static function sftpIndex(): void
    {
        Auth::requireRole(['admin']);
        $config = json_decode((string) @file_get_contents('/etc/sftpgo/sftpgo.json'), true) ?: [];
        View::render('whm/server_config/sftp', [
            'title' => 'SFTP Server Settings',
            'common' => $config['common'] ?? [],
            'lastLog' => SystemWorkerService::lastLog('tune-sftpgo'),
        ], 'whm');
    }

    public static function sftpUpdate(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();

        $settings = [
            'common.max_total_connections' => (int) $_POST['max_total_connections'],
            'common.max_per_host_connections' => (int) $_POST['max_per_host_connections'],
            'common.idle_timeout' => (int) $_POST['idle_timeout'],
        ];
        SystemWorkerService::enqueue('sftp-settings', ['type' => 'set_sftpgo_json', 'settings' => $settings]);
        SystemWorkerService::enqueue('sftp-settings-restart', ['type' => 'restart', 'service' => 'sftpgo']);

        Flash::ok('SFTP settings queued - applied and service restarted within a few seconds.');
        header('Location: /whm/server-config/sftp');
        exit;
    }

    // ---- PHP extensions ----

    public static function phpExtensions(): void
    {
        Auth::requireRole(['admin']);
        $list = PhpExtensionService::list();
        $last = PhpExtensionService::lastResult();
        if ($list['updated'] === null || $list['updated'] < time() - 86400) {
            PhpExtensionService::refresh();
        }
        View::render('whm/server_config/php_extensions', [
            'title' => 'PHP Extensions',
            'list' => $list,
            'last' => $last,
            'pending' => (bool) glob(__DIR__ . '/../../storage/config-queue/php-ext*.json'),
        ], 'whm');
    }

    public static function phpExtensionChange(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            if (($_POST['op'] ?? '') === 'refresh') {
                PhpExtensionService::refresh();
                Flash::ok('Refreshing the package list...');
            } else {
                $ext = (string) ($_POST['ext'] ?? '');
                PhpExtensionService::change($ext, ($_POST['op'] ?? '') === 'install');
                Flash::ok("Queued: php-zts-$ext - the web server restarts when it's done (a second or two).");
            }
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/server-config/php-extensions');
        exit;
    }

    // ---- PHP / OPcache ----

    public static function phpIndex(): void
    {
        Auth::requireRole(['admin']);
        View::render('whm/server_config/php', [
            'title' => 'PHP Settings',
            'current' => self::currentPhpSettings(),
            'lastLog' => SystemWorkerService::lastLog('php-settings'),
        ], 'whm');
    }

    public static function phpUpdate(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();

        $ini = [
            'memory_limit' => preg_replace('/[^0-9]/', '', $_POST['memory_limit'] ?? '128') . 'M',
            'max_execution_time' => (int) $_POST['max_execution_time'],
            'upload_max_filesize' => preg_replace('/[^0-9]/', '', $_POST['upload_max_filesize'] ?? '2') . 'M',
            'post_max_size' => preg_replace('/[^0-9]/', '', $_POST['post_max_size'] ?? '8') . 'M',
        ];
        $opcache = [
            'opcache.memory_consumption' => (int) $_POST['opcache_memory'],
            'opcache.max_accelerated_files' => (int) $_POST['opcache_max_files'],
            'opcache.jit' => !empty($_POST['jit_enabled']) ? 'tracing' : 'off',
            'opcache.jit_buffer_size' => !empty($_POST['jit_enabled']) ? '64M' : '0',
        ];

        SystemWorkerService::enqueue('php-settings', ['type' => 'set_ini', 'file' => '/etc/php-zts/php.ini', 'settings' => $ini]);
        SystemWorkerService::enqueue('php-settings-opcache', ['type' => 'set_ini', 'file' => '/etc/php-zts/conf.d/opcache.ini', 'settings' => $opcache]);
        SystemWorkerService::enqueue('php-settings-restart', ['type' => 'restart', 'service' => 'frankenphp']);

        Flash::ok('PHP settings queued - the web server will restart within a few seconds to apply them.');
        header('Location: /whm/server-config/php');
        exit;
    }

    // ---- MariaDB ----

    public static function databaseIndex(): void
    {
        Auth::requireRole(['admin']);
        View::render('whm/server_config/database', [
            'title' => 'Database Settings',
            'current' => self::currentMycnfSettings(),
            'lastLog' => SystemWorkerService::lastLog('db-settings'),
        ], 'whm');
    }

    public static function databaseUpdate(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();

        $settings = [
            'innodb_buffer_pool_size' => preg_replace('/[^0-9]/', '', $_POST['innodb_buffer_pool_size'] ?? '256') . 'M',
            'max_connections' => (int) $_POST['max_connections'],
            'thread_cache_size' => (int) $_POST['thread_cache_size'],
            'table_open_cache' => (int) $_POST['table_open_cache'],
        ];
        SystemWorkerService::enqueue('db-settings', ['type' => 'set_mycnf', 'settings' => $settings]);
        SystemWorkerService::enqueue('db-settings-restart', ['type' => 'restart', 'service' => 'mariadb']);

        Flash::ok('Database settings queued - MariaDB will restart within a few seconds to apply them (brief interruption for all sites/mailboxes using it).');
        header('Location: /whm/server-config/database');
        exit;
    }

    // ---- Server Tweaks / auto-tuning ----

    public static function tuningIndex(): void
    {
        Auth::requireRole(['admin']);
        $profile = $_GET['preview'] ?? 'extreme';
        if (!in_array($profile, TuningService::PROFILES, true)) {
            $profile = 'extreme';
        }
        View::render('whm/server_config/tuning', [
            'title' => 'Server Tweaks',
            'hardware' => TuningService::hardware(),
            'preview' => $profile,
            'computed' => TuningService::compute($profile),
        ], 'whm');
    }

    public static function tuningApply(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $profile = (string) ($_POST['profile'] ?? 'extreme');

        try {
            TuningService::apply($profile);
            Flash::ok(ucfirst($profile) . ' tuning applied across MariaDB, PHP, Stalwart and SFTPGo. Services affected will restart within a few seconds.');
        } catch (Throwable $e) {
            Flash::error('Tuning failed: ' . $e->getMessage());
        }
        header('Location: /whm/server-config/tuning');
        exit;
    }

    // ---- helpers ----

    private static function validObjectName(string $object): string
    {
        $allowed = array_merge(...array_values(StalwartAdminService::SETTINGS_GROUPS));
        if (!in_array($object, $allowed, true)) {
            http_response_code(404);
            exit('Unknown settings object.');
        }
        return $object;
    }

    /**
     * Builds a JMAP update payload from a schema's field list + $_POST,
     * coercing simple types. A field left blank is OMITTED entirely (not
     * touched) unless the schema marks it nullable, in which case blank
     * means "clear it" - otherwise a blank optional-looking field (e.g. one
     * this Stalwart instance never had set) would get force-set to null and
     * trip required-field validation on unrelated fields in the same form.
     */
    private static function collectFormValues(array $fields, array $post): array
    {
        $update = [];
        foreach ($fields as $name => $def) {
            if (($def['update'] ?? '') === 'serverSet' || !array_key_exists($name, $post)) {
                continue;
            }
            $type = $def['type']['type'] ?? 'string';
            $nullable = (bool) ($def['type']['nullable'] ?? false);
            $raw = $post[$name];

            if ($raw === '' && $type !== 'boolean') {
                if ($nullable) {
                    $update[$name] = null;
                }
                continue; // not nullable and left blank: leave it untouched
            }

            $update[$name] = match ($type) {
                'boolean' => $raw === '1' || $raw === 'true',
                'number' => is_numeric($raw) ? (str_contains((string) $raw, '.') ? (float) $raw : (int) $raw) : null,
                'string', 'enum' => $raw,
                default => json_decode((string) $raw, true), // object/objectList/set/map edited as raw JSON
            };
        }
        return $update;
    }

    private static function currentPhpSettings(): array
    {
        $out = [];
        foreach (@file('/etc/php-zts/php.ini') ?: [] as $line) {
            if (preg_match('/^(memory_limit|max_execution_time|upload_max_filesize|post_max_size)\s*=\s*(.+)$/', trim($line), $m)) {
                $out[$m[1]] = trim($m[2]);
            }
        }
        foreach (@file('/etc/php-zts/conf.d/opcache.ini') ?: [] as $line) {
            if (preg_match('/^;?\s*(opcache\.(memory_consumption|max_accelerated_files|jit|jit_buffer_size))\s*=\s*(.+)$/', trim($line), $m)) {
                $out[$m[1]] = trim($m[3]);
            }
        }
        return $out;
    }

    private static function currentMycnfSettings(): array
    {
        $out = [];
        $file = '/etc/my.cnf.d/99-hostpanel-tuning.cnf';
        foreach (is_file($file) ? file($file) : [] as $line) {
            if (preg_match('/^([a-z_]+)\s*=\s*(.+)$/', trim($line), $m)) {
                $out[$m[1]] = trim($m[2]);
            }
        }
        return $out;
    }
}
