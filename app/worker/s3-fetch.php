<?php
declare(strict_types=1);

/**
 * Fetches cPanel backups from S3 into the migration import folder
 * (S3FetchService::run). Started by hostpanel-worker.php as the transient
 * unit jinnpanel-s3-fetch-<id>, as frankenphp:webusers.
 *
 *   php s3-fetch.php <fetch-id>
 */

require __DIR__ . '/../src/cli_bootstrap.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Usage: php s3-fetch.php <fetch-id>\n");
    exit(2);
}
exit(S3FetchService::run($id));
