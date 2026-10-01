<?php
declare(strict_types=1);

/**
 * Imports cPanel zone files (<zone>.db, e.g. the dnszones/ folder of cPanel
 * backups) into the panel's DNS zones of the same name, then publishes each
 * zone once. Filtering rules: CpanelZoneImporter. Safe to re-run: records
 * that already exist are skipped.
 *
 *   php dns-import-cpanel.php <file.db|directory> [...]
 *
 * Run as frankenphp. Zones that don't exist in the panel are skipped.
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$files = [];
foreach (array_slice($argv, 1) as $arg) {
    if (is_dir($arg)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($arg, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.db')) {
                $files[] = $f->getPathname();
            }
        }
    } elseif (is_file($arg)) {
        $files[] = $arg;
    }
}
if (!$files) {
    fwrite(STDERR, "Usage: php dns-import-cpanel.php <file.db|directory> [...]\n");
    exit(2);
}
sort($files);

$domains = Database::app()->query('SELECT domain_name FROM domains')->fetchAll(PDO::FETCH_COLUMN);
$failed = 0;
foreach ($files as $file) {
    $zoneName = strtolower(substr(basename($file), 0, -3));
    $zone = DnsService::findZoneByName($zoneName);
    if ($zone === null) {
        echo "--      $zoneName: no such zone in the panel, skipped\n";
        continue;
    }
    $rep = CpanelZoneImporter::import((int) $zone['id'], (string) file_get_contents($file), $domains);
    DnsService::publish((int) $zone['id']);
    printf("%-7s %s: %d added, %d skipped, %d failed%s\n", $rep['failed'] ? 'WARN' : 'ok', $zoneName,
        count($rep['added']), count($rep['skipped']), count($rep['failed']), $rep['external_mail'] ? ' (external mail kept)' : '');
    foreach ($rep['added'] as $a) {
        echo "          + $a\n";
    }
    foreach ($rep['failed'] as $f) {
        echo "          ! $f\n";
        $failed++;
    }
}
exit($failed > 0 ? 1 : 0);
