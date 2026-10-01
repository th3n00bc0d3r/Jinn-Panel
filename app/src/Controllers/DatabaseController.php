<?php
declare(strict_types=1);

/** cPanel > MySQL Databases: databases, users, privileges, Remote MySQL, phpMyAdmin (MysqlService). */
final class DatabaseController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $grants = [];
        $error = null;
        try {
            $grants = MysqlService::grants((int) $me['id']);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        View::render('cpanel/databases', [
            'title' => 'MySQL Databases',
            'dbs' => MysqlService::databases((int) $me['id']),
            'users' => MysqlService::users((int) $me['id']),
            'grants' => $grants,
            'remote' => MysqlService::remoteHosts((int) $me['id']),
            'privileges' => MysqlService::PRIVILEGES,
            'prefix' => $me['username'] . '_',
            'serverHost' => strtolower(Config::SERVER_HOSTNAME),
            'usage' => Quota::usage($me['id']),
            'pkg' => Quota::package($me['id']),
            'loadError' => $error,
        ], 'cpanel');
    }

    public static function store(): void
    {
        $me = self::post();
        self::run(function () use ($me) {
            $db = MysqlService::createDatabase($me, (string) ($_POST['name'] ?? ''));
            if (!empty($_POST['with_user'])) {
                $label = substr($db, strlen($me['username']) + 1);
                $user = MysqlService::createUser($me, $label, (string) ($_POST['password'] ?? ''));
                $id = (int) Database::app()->query('SELECT id FROM db_instances WHERE db_name = ' . Database::app()->quote($db))->fetchColumn();
                MysqlService::setGrant($me, $user, $id, MysqlService::PRIVILEGES);
                return "Database $db created, with user $user (all privileges).";
            }
            return "Database $db created. Add a user to it below.";
        });
    }

    public static function destroy(array $params): void
    {
        $me = self::post();
        self::run(fn() => 'Database ' . MysqlService::dropDatabase($me, (int) ($params['id'] ?? 0)) . ' deleted.');
    }

    public static function userStore(): void
    {
        $me = self::post();
        self::run(fn() => 'MySQL user ' . MysqlService::createUser($me, (string) ($_POST['name'] ?? ''), (string) ($_POST['password'] ?? '')) . ' created.');
    }

    public static function userAction(): void
    {
        $me = self::post();
        $user = (string) ($_POST['db_user'] ?? '');
        self::run(function () use ($me, $user) {
            switch ($_POST['op'] ?? '') {
                case 'password':
                    MysqlService::setPassword($me, $user, (string) ($_POST['password'] ?? ''));
                    return "Password changed for $user.";
                case 'delete':
                    MysqlService::deleteUser($me, $user);
                    return "MySQL user $user deleted.";
                case 'grant':
                    $privs = !empty($_POST['all']) ? MysqlService::PRIVILEGES : (array) ($_POST['privileges'] ?? []);
                    MysqlService::setGrant($me, $user, (int) ($_POST['db_id'] ?? 0), $privs);
                    return $privs ? "Privileges saved for $user." : "$user removed from the database.";
                case 'revoke':
                    MysqlService::setGrant($me, $user, (int) ($_POST['db_id'] ?? 0), []);
                    return "$user removed from the database.";
            }
            throw new InvalidArgumentException('Unknown action.');
        });
    }

    public static function remote(): void
    {
        $me = self::post();
        self::run(function () use ($me) {
            if (($_POST['op'] ?? '') === 'remove') {
                MysqlService::removeRemoteHost($me, (string) ($_POST['host'] ?? ''));
                return 'Remote host removed.';
            }
            $h = MysqlService::addRemoteHost($me, (string) ($_POST['host'] ?? ''));
            return "Remote access allowed from $h - the firewall opens port 3306 for it within a few seconds.";
        });
    }

    public static function phpmyadmin(): void
    {
        $me = self::post();
        try {
            header('Location: ' . MysqlService::phpMyAdminUrl($me));
            exit;
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            self::back();
        }
    }

    private static function post(): array
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        return Auth::user();
    }

    private static function run(callable $fn): never
    {
        try {
            Flash::ok((string) $fn());
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error($e instanceof InvalidArgumentException ? $e->getMessage() : 'The database server refused that: ' . preg_replace('/^SQLSTATE\[\w+\]: [^:]*: \d+ /', '', $e->getMessage()));
        }
        self::back();
    }

    private static function back(): never
    {
        header('Location: /cpanel/databases');
        exit;
    }
}
