<?php
declare(strict_types=1);

/** Runs one cron job and records its result (CronService::execute). */

require __DIR__ . '/../src/cli_bootstrap.php';

$id = (int) ($argv[1] ?? 0);
if ($id > 0) {
    CronService::execute($id);
}
