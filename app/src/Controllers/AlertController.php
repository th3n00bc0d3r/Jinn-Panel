<?php
declare(strict_types=1);

/** WHM > Alerts: everything that needs an administrator's attention (AlertService). */
final class AlertController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        $health = SystemWorkerService::output('health.txt');
        $alerts = AlertService::collect();
        AlertService::remember($alerts);
        View::render('whm/alerts', [
            'title' => 'Alerts',
            'alerts' => $alerts,
            'healthLine' => $health !== null ? strtok($health, "\n") : null,
            'emails' => NotificationService::settings()['alerts'],
        ], 'whm');
    }
}
