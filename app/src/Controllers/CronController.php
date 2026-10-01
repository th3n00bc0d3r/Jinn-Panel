<?php
declare(strict_types=1);

final class CronController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $d = Database::app()->prepare('SELECT id, domain_name FROM domains WHERE user_id = ? ORDER BY domain_name');
        $d->execute([$me['id']]);
        View::render('cpanel/cron', [
            'title' => 'Cron Jobs',
            'jobs' => CronService::forUser((int) $me['id']),
            'domains' => $d->fetchAll(),
        ], 'cpanel');
    }

    public static function store(): void
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        try {
            CronService::create(Auth::user(), $_POST);
            Flash::ok('Cron job added.');
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
        }
        self::back();
    }

    public static function toggle(array $params): void
    {
        $job = self::job($params);
        Database::app()->prepare('UPDATE cron_jobs SET enabled = 1 - enabled WHERE id = ?')->execute([$job['id']]);
        Flash::ok((int) $job['enabled'] === 1 ? 'Cron job paused.' : 'Cron job resumed.');
        self::back();
    }

    public static function runNow(array $params): void
    {
        $job = self::job($params);
        exec('setsid ' . escapeshellarg(PHP_BINARY === '' ? '/usr/bin/php' : PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../worker/cron-exec.php') . ' ' . (int) $job['id'] . ' > /dev/null 2>&1 &');
        Flash::ok('Started - reload in a moment to see the output.');
        self::back();
    }

    public static function destroy(array $params): void
    {
        $job = self::job($params);
        Database::app()->prepare('DELETE FROM cron_jobs WHERE id = ?')->execute([$job['id']]);
        Flash::ok('Cron job deleted.');
        self::back();
    }

    private static function job(array $params): array
    {
        Auth::requireRole(['user']);
        Csrf::requireValid();
        $s = Database::app()->prepare('SELECT * FROM cron_jobs WHERE id = ? AND user_id = ?');
        $s->execute([(int) ($params['id'] ?? 0), Auth::user()['id']]);
        $job = $s->fetch();
        if (!$job) {
            Flash::error('Cron job not found.');
            self::back();
        }
        return $job;
    }

    private static function back(): never
    {
        header('Location: /cpanel/cron');
        exit;
    }
}
