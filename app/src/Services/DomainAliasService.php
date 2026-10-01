<?php
declare(strict_types=1);

/**
 * Domain aliases - cPanel's "parked domains": another name that serves the
 * same site (its own DNS zone pointing here, and its own certificate, as
 * extra addresses of the domain's Caddy site block).
 */
final class DomainAliasService
{
    private const DOMAIN_RE = '/^(?=.{1,190}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/';

    /** @return list<string> */
    public static function forDomain(string $domain): array
    {
        try {
            $s = Database::app()->prepare('SELECT a.alias_name FROM domain_aliases a JOIN domains d ON d.id = a.domain_id WHERE d.domain_name = ? ORDER BY a.alias_name');
            $s->execute([$domain]);
            return array_values(array_filter($s->fetchAll(PDO::FETCH_COLUMN), fn($a) => preg_match(self::DOMAIN_RE, (string) $a)));
        } catch (Throwable) {
            return []; // table not there yet (before install.sh applied the schema)
        }
    }

    /** Whether $name is already a hosted domain or an alias. */
    public static function nameTaken(string $name): bool
    {
        $pdo = Database::app();
        $a = $pdo->prepare('SELECT (SELECT COUNT(*) FROM domains WHERE domain_name = ?) + (SELECT COUNT(*) FROM domain_aliases WHERE alias_name = ?)');
        $a->execute([$name, $name]);
        return (int) $a->fetchColumn() > 0;
    }

    public static function add(array $domain, string $alias): void
    {
        $alias = strtolower(rtrim(trim($alias), '.'));
        if (str_starts_with($alias, 'www.')) {
            $alias = substr($alias, 4); // www.<alias> is served with it
        }
        if (!preg_match(self::DOMAIN_RE, $alias)) {
            throw new InvalidArgumentException('Enter a valid domain name, e.g. example.net');
        }
        if (self::nameTaken($alias) || $alias === strtolower(Config::SERVER_HOSTNAME) || $alias === 'panel.' . strtolower(Config::SERVER_HOSTNAME)) {
            throw new InvalidArgumentException("$alias is already in use on this server.");
        }
        Database::app()->prepare('INSERT INTO domain_aliases (domain_id, alias_name) VALUES (?, ?)')->execute([$domain['id'], $alias]);
        try {
            VhostService::create((string) $domain['domain_name'], (string) $domain['php_version'], (string) $domain['ssl_mode'], true, false);
        } catch (Throwable $e) {
            Database::app()->prepare('DELETE FROM domain_aliases WHERE alias_name = ?')->execute([$alias]);
            throw $e;
        }
        try {
            DnsService::createZone($alias);
        } catch (Throwable $e) {
            error_log("alias $alias zone: " . $e->getMessage());
        }
    }

    public static function remove(array $domain, string $alias): void
    {
        $s = Database::app()->prepare('DELETE FROM domain_aliases WHERE domain_id = ? AND alias_name = ?');
        $s->execute([$domain['id'], $alias]);
        if ($s->rowCount() === 0) {
            throw new InvalidArgumentException('Alias not found.');
        }
        VhostService::create((string) $domain['domain_name'], (string) $domain['php_version'], (string) $domain['ssl_mode'], true, false);
        try {
            DnsService::removeZone($alias);
        } catch (Throwable $e) {
            error_log("alias $alias zone: " . $e->getMessage());
        }
    }

    /** All aliases of a domain that's being removed. */
    public static function removeAll(array $domain): void
    {
        foreach (self::forDomain((string) $domain['domain_name']) as $alias) {
            try {
                DnsService::removeZone($alias);
            } catch (Throwable $e) {
                error_log("alias $alias zone: " . $e->getMessage());
            }
        }
        Database::app()->prepare('DELETE FROM domain_aliases WHERE domain_id = ?')->execute([$domain['id']]);
    }
}
