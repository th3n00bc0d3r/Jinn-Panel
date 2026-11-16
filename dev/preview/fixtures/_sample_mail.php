<?php
declare(strict_types=1);

/** Sample mail rows (Email Accounts page). Files starting with _ are not fixtures. */

require_once __DIR__ . '/_sample.php';

/** The account's domains as EmailController reads them (SELECT * FROM domains). */
function sample_mail_domains(): array
{
    return array_map(fn(array $d) => $d + [
        'mail_domain_id' => 'd' . $d['id'],
        'status' => 'active',
        'catch_all' => $d['id'] === 1 ? 'info@acme.test' : null,
        'static_cache_ttl' => 300,
        'browser_cache' => 1,
    ], sample_domains());
}

/** email_accounts rows joined with domain_name. */
function sample_mailboxes(int $extra = 0): array
{
    $rows = [
        ['id' => 1, 'domain_id' => 1, 'local_part' => 'info', 'domain_name' => 'acme.test', 'created_at' => '2026-10-02 10:05:00'],
        ['id' => 2, 'domain_id' => 1, 'local_part' => 'jane', 'domain_name' => 'acme.test', 'created_at' => '2026-10-03 09:12:00'],
        ['id' => 3, 'domain_id' => 1, 'local_part' => 'accounts.payable', 'domain_name' => 'acme.test', 'created_at' => '2026-10-20 16:40:00'],
        ['id' => 4, 'domain_id' => 2, 'local_part' => 'orders', 'domain_name' => 'shop.acme.test', 'created_at' => '2026-11-12 08:00:00'],
    ];
    $names = ['sales', 'support', 'hr', 'press', 'billing', 'noreply', 'dev', 'ops'];
    for ($i = 0; $i < $extra; $i++) {
        $rows[] = ['id' => 10 + $i, 'domain_id' => 2, 'local_part' => $names[$i % count($names)] . ($i >= count($names) ? $i : ''), 'domain_name' => 'shop.acme.test', 'created_at' => '2026-11-01 12:00:00'];
    }
    return array_map(fn(array $r) => $r + ['user_id' => 10, 'mail_account_id' => 'a' . $r['id']], $rows);
}

/** email_forwarders rows joined with domain_name. */
function sample_forwarders(): array
{
    $base = ['user_id' => 10, 'mail_list_id' => null, 'created_at' => '2026-10-05 10:00:00'];
    return [
        $base + ['id' => 1, 'domain_id' => 1, 'local_part' => 'jane', 'domain_name' => 'acme.test', 'destinations' => 'jane.doe@gmail.example'],
        $base + ['id' => 2, 'domain_id' => 1, 'local_part' => 'sales', 'domain_name' => 'acme.test', 'destinations' => 'jane@acme.test,bob@partner.example,team-sales-archive@acme-group.example', 'mail_list_id' => 'l2'],
        $base + ['id' => 3, 'domain_id' => 2, 'local_part' => 'returns', 'domain_name' => 'shop.acme.test', 'destinations' => 'orders@shop.acme.test', 'mail_list_id' => 'l3'],
    ];
}

/** email_autoresponders rows keyed by email_account_id. */
function sample_autoresponders(): array
{
    return [
        2 => ['id' => 1, 'user_id' => 10, 'email_account_id' => 2, 'subject' => 'Out of office', 'body' => "Hi,\n\nI'm away until the 24th and will reply when I'm back. For anything urgent, write to info@acme.test.\n\nJane", 'starts_on' => null, 'ends_on' => date('Y-m-d', strtotime('+10 days')), 'interval_days' => 3, 'created_at' => '2026-11-10 09:00:00'],
        4 => ['id' => 2, 'user_id' => 10, 'email_account_id' => 4, 'subject' => 'Holiday closure', 'body' => 'The shop is closed for the holidays; orders ship from 2 January.', 'starts_on' => date('Y-m-d', strtotime('+30 days')), 'ends_on' => null, 'interval_days' => 7, 'created_at' => '2026-11-12 09:00:00'],
    ];
}
