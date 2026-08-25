<?php
declare(strict_types=1);

final class DatabaseController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $stmt = Database::app()->prepare('SELECT * FROM db_instances WHERE user_id = ? ORDER BY created_at DESC');
        $stmt->execute([$me['id']]);
        View::render('cpanel/databases', [
            'title' => 'MySQL Databases',
            'dbs' => $stmt->fetchAll(),
            'usage' => Quota::usage($me['id']),
            'pkg' => Quota::package($me['id']),
        ], 'cpanel');
    }

    public static function store(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();

        if (!Quota::withinLimit($me['id'], 'databases')) {
            Flash::error('You have reached your package\'s database limit.');
            header('Location: /cpanel/databases');
            exit;
        }

        $label = strtolower(trim((string) ($_POST['name'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        if (!preg_match('/^[a-z][a-z0-9_]{2,30}$/', $label)) {
            Flash::error('Database name must be 3-31 characters: letters, numbers, underscore.');
            header('Location: /cpanel/databases');
            exit;
        }
        if (strlen($password) < 8) {
            Flash::error('Database password must be at least 8 characters.');
            header('Location: /cpanel/databases');
            exit;
        }

        // Prefix with the account username to avoid collisions across tenants,
        // mirroring how cPanel namespaces user_dbname / user_dbuser.
        $dbName = substr($me['username'] . '_' . $label, 0, 63);
        $dbUser = substr($me['username'] . '_' . $label, 0, 31);

        try {
            ProvisioningService::createDatabase($dbName, $dbUser, $password);
        } catch (Throwable $e) {
            error_log($e->getMessage());
            Flash::error('Could not create the database. It may already exist.');
            header('Location: /cpanel/databases');
            exit;
        }

        $stmt = Database::app()->prepare('INSERT INTO db_instances (user_id, db_name, db_user) VALUES (?, ?, ?)');
        $stmt->execute([$me['id'], $dbName, $dbUser]);

        Flash::ok("Database \"$dbName\" created with user \"$dbUser\".");
        header('Location: /cpanel/databases');
        exit;
    }

    public static function destroy(array $params): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $me = Auth::user();
        $id = (int) $params['id'];

        $stmt = Database::app()->prepare('SELECT * FROM db_instances WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $me['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            Flash::error('Database not found.');
            header('Location: /cpanel/databases');
            exit;
        }

        try { ProvisioningService::dropDatabase($row['db_name']); } catch (Throwable $e) { error_log($e->getMessage()); }
        try { ProvisioningService::dropDbUser($row['db_user']); } catch (Throwable $e) { error_log($e->getMessage()); }

        $del = Database::app()->prepare('DELETE FROM db_instances WHERE id = ?');
        $del->execute([$id]);

        Flash::ok("Database \"{$row['db_name']}\" deleted.");
        header('Location: /cpanel/databases');
        exit;
    }
}
