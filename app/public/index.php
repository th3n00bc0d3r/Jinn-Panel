<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$router = new Router();

$router->get('/', function () {
    header('Location: ' . (Auth::check() ? '/whm' : '/login'));
    exit;
});

$router->get('/setup', ['SetupController', 'index']);
$router->post('/setup', ['SetupController', 'store']);

$router->get('/login', ['AuthController', 'showLogin']);
$router->post('/login', ['AuthController', 'login']);
$router->get('/logout', ['AuthController', 'logout']);

// WHM (admin + reseller)
$router->get('/whm', ['WhmDashboardController', 'index']);
$router->get('/whm/accounts', ['AccountController', 'index']);
$router->get('/whm/accounts/create', ['AccountController', 'create']);
$router->post('/whm/accounts', ['AccountController', 'store']);
$router->post('/whm/accounts/{id}/suspend', ['AccountController', 'suspend']);
$router->post('/whm/accounts/{id}/unsuspend', ['AccountController', 'unsuspend']);
$router->post('/whm/accounts/{id}/delete', ['AccountController', 'destroy']);
$router->get('/whm/packages', ['PackageController', 'index']);
$router->get('/whm/packages/create', ['PackageController', 'create']);
$router->post('/whm/packages', ['PackageController', 'store']);
$router->post('/whm/packages/{id}/delete', ['PackageController', 'destroy']);

// cPanel/WHM migrations (admin + reseller). /create must stay above /{id}.
$router->get('/whm/migrations', ['MigrationController', 'index']);
$router->get('/whm/migrations/create', ['MigrationController', 'create']);
$router->post('/whm/migrations/connect', ['MigrationController', 'connect']);
$router->get('/whm/migrations/{id}', ['MigrationController', 'show']);
$router->get('/whm/migrations/{id}/select', ['MigrationController', 'select']);
$router->get('/whm/migrations/{id}/status', ['MigrationController', 'status']);
$router->post('/whm/migrations/{id}/start', ['MigrationController', 'start']);
$router->post('/whm/migrations/{id}/cancel', ['MigrationController', 'cancel']);
$router->post('/whm/migrations/{id}/retry', ['MigrationController', 'retry']);
$router->post('/whm/migrations/{id}/restore-mail', ['MigrationController', 'restoreMail']);
$router->post('/whm/migrations/{id}/delete', ['MigrationController', 'destroy']);
$router->post('/whm/migrations/{id}/discard-secret', ['MigrationController', 'discardSecret']);

// Server Config (admin only)
$router->get('/whm/server-config/mail', ['ServerConfigController', 'mailIndex']);
$router->get('/whm/server-config/mail/{object}', ['ServerConfigController', 'mailEdit']);
$router->post('/whm/server-config/mail/{object}', ['ServerConfigController', 'mailUpdate']);
$router->get('/whm/server-config/sftp', ['ServerConfigController', 'sftpIndex']);
$router->post('/whm/server-config/sftp', ['ServerConfigController', 'sftpUpdate']);
$router->get('/whm/server-config/php', ['ServerConfigController', 'phpIndex']);
$router->post('/whm/server-config/php', ['ServerConfigController', 'phpUpdate']);
$router->get('/whm/server-config/database', ['ServerConfigController', 'databaseIndex']);
$router->post('/whm/server-config/database', ['ServerConfigController', 'databaseUpdate']);
$router->get('/whm/server-config/tuning', ['ServerConfigController', 'tuningIndex']);
$router->post('/whm/server-config/tuning', ['ServerConfigController', 'tuningApply']);
$router->get('/whm/server-config/php-versions', ['PhpVersionController', 'index']);
$router->post('/whm/server-config/php-versions', ['PhpVersionController', 'install']);
$router->post('/whm/server-config/php-versions/{version}/remove', ['PhpVersionController', 'remove']);
$router->post('/whm/logs/pull', ['WhmDashboardController', 'pullLog']);

// DNS zones (admin only). Static paths before /{id}.
$router->get('/whm/dns', ['WhmDnsController', 'index']);
$router->post('/whm/dns/zones', ['WhmDnsController', 'storeZone']);
$router->post('/whm/dns/server-zone', ['WhmDnsController', 'ensureServerZone']);
$router->post('/whm/dns/nameservers', ['WhmDnsController', 'saveNameservers']);
$router->get('/whm/dns/{id}', ['WhmDnsController', 'show']);
$router->post('/whm/dns/{id}/records', ['WhmDnsController', 'addRecord']);
$router->post('/whm/dns/{id}/records/{record}/delete', ['WhmDnsController', 'deleteRecord']);
$router->post('/whm/dns/{id}/republish', ['WhmDnsController', 'republish']);
$router->post('/whm/dns/{id}/delete', ['WhmDnsController', 'destroyZone']);

// cPanel (end user)
$router->get('/cpanel', ['CpanelDashboardController', 'index']);
$router->get('/cpanel/domains', ['DomainController', 'index']);
$router->post('/cpanel/domains', ['DomainController', 'store']);
$router->post('/cpanel/domains/{id}/delete', ['DomainController', 'destroy']);
$router->post('/cpanel/domains/{id}/settings', ['DomainController', 'updateSettings']);
$router->get('/cpanel/databases', ['DatabaseController', 'index']);
$router->post('/cpanel/databases', ['DatabaseController', 'store']);
$router->post('/cpanel/databases/{id}/delete', ['DatabaseController', 'destroy']);
$router->get('/cpanel/email', ['EmailController', 'index']);
$router->post('/cpanel/email', ['EmailController', 'store']);
$router->post('/cpanel/email/{id}/delete', ['EmailController', 'destroy']);
$router->get('/cpanel/ftp', ['FtpController', 'index']);
$router->post('/cpanel/ftp', ['FtpController', 'store']);
$router->post('/cpanel/ftp/{id}/delete', ['FtpController', 'destroy']);
$router->get('/cpanel/dns', ['DnsController', 'index']);
$router->post('/cpanel/dns/{id}/reprovision', ['DnsController', 'reprovision']);
$router->get('/cpanel/files', ['FileManagerController', 'index']);
$router->post('/cpanel/files/upload', ['FileManagerController', 'upload']);
$router->post('/cpanel/files/mkdir', ['FileManagerController', 'mkdir']);
$router->post('/cpanel/files/delete', ['FileManagerController', 'delete']);
$router->get('/cpanel/files/download', ['FileManagerController', 'download']);

try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    if ($path !== '/setup' && !SetupController::isComplete()) {
        header('Location: /setup');
        exit;
    }
    $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
} catch (Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    View::render('errors/500', [], 'blank');
}
