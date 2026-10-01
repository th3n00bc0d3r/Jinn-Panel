<?php
declare(strict_types=1);

/**
 * Keeps mail DNS (DKIM/SPF/DMARC/MTA-STS/SRV/autoconfig), the mail
 * autoconfig/MTA-STS site and Stalwart's TLS certificate in sync - see
 * MailDnsService. Run as frankenphp; a daily systemd timer
 * (jinnpanel-mail-dns.timer) and install.sh run it.
 *
 *   php mail-dns-sync.php
 */

require __DIR__ . '/../src/cli_bootstrap.php';

// One sync at a time (the timer, install.sh and a manual run can overlap).
$lock = fopen(__DIR__ . '/../storage/logs/mail-dns-sync.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "Could not take the mail DNS sync lock.\n");
    exit(1);
}

$failed = false;
foreach (MailDnsService::syncAll() as $line) {
    echo $line, "\n";
    $failed = $failed || str_contains($line, 'FAILED');
}
exit($failed ? 1 : 0);
