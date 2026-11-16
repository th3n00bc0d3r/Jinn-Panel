<?php
declare(strict_types=1);

/** WHM > Notifications: the emails the panel sends, and where from. Admin only. */
final class NotificationController
{
    public static function index(): void
    {
        Auth::requireRole(['admin']);
        View::render('whm/notifications', [
            'title' => 'Notifications',
            's' => NotificationService::settings(),
            'from' => Mailer::from(),
            'recipients' => NotificationService::adminRecipients(),
            'me' => Auth::user(),
        ], 'whm');
    }

    public static function save(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        try {
            NotificationService::saveSettings($_POST);
            Flash::ok('Notification settings saved.');
        } catch (InvalidArgumentException $e) {
            Flash::error($e->getMessage());
        }
        header('Location: /whm/notifications');
        exit;
    }

    public static function test(): void
    {
        Auth::requireRole(['admin']);
        Csrf::requireValid();
        $to = trim((string) ($_POST['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::error('Enter the address to send the test to.');
        } elseif (NotificationService::test($to)) {
            Flash::ok("Test email handed to the mail server for $to. If it doesn't arrive, check the spam folder and that " . Mailer::from() . ' is on a mail domain of this server.');
        } else {
            Flash::error('The mail server refused the test email. The From address must be on a mail domain hosted here; see the app log for details.');
        }
        header('Location: /whm/notifications');
        exit;
    }
}
