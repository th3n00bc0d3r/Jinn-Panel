<?php
declare(strict_types=1);

final class CpanelDashboardController
{
    public static function index(): void
    {
        Auth::requireRole(['user']);
        $me = Auth::user();
        $pkg = Quota::package($me['id']);
        $usage = Quota::usage($me['id']);

        View::render('cpanel/dashboard', [
            'title' => 'Dashboard',
            'pkg' => $pkg,
            'usage' => $usage,
        ], 'cpanel');
    }
}
