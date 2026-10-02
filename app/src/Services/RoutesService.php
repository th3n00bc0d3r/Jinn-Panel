<?php
declare(strict_types=1);

/**
 * cPanel > Domains > domain > Routes: the site's .htaccess files, the Caddy
 * rules translated from them (HtaccessTranslator), and the rules in use.
 *
 * Saving never writes the rule files from the web process: the root worker
 * (routes_apply) re-validates them, writes them, re-renders the site and
 * runs `frankenphp validate` - reloading only on success, restoring the
 * previous files otherwise. The result is the worker's last log line.
 *
 * With routes_sync on, the worker also follows the .htaccess files: when they
 * change it translates them again and applies the result - unless something
 * can't be translated exactly, then the rules in use stay and the domain is
 * marked for review. Editing the rules by hand turns the sync off.
 */
final class RoutesService
{
    private const MAX_FILES = 200;
    private const MAX_DEPTH = 8;
    private const SKIP_DIRS = ['node_modules', 'vendor', '.git', 'cache', 'tmp'];

    /** @return array<string,string> relative folder ('' = docroot) => .htaccess contents */
    public static function htaccessFiles(string $docroot): array
    {
        $out = [];
        $walk = function (string $dir, string $rel, int $depth) use (&$walk, &$out): void {
            if ($depth > self::MAX_DEPTH || count($out) >= self::MAX_FILES || is_link($dir)) {
                return;
            }
            $file = "$dir/.htaccess";
            if (is_file($file) && !is_link($file) && filesize($file) < 262144) {
                $out[$rel] = (string) file_get_contents($file);
            }
            foreach (scandir($dir) ?: [] as $e) {
                if ($e === '.' || $e === '..' || in_array($e, self::SKIP_DIRS, true) || $e[0] === '.') {
                    continue;
                }
                if (is_dir("$dir/$e") && !is_link("$dir/$e")) {
                    $walk("$dir/$e", $rel === '' ? $e : "$rel/$e", $depth + 1);
                }
            }
        };
        if (is_dir($docroot)) {
            $walk(rtrim($docroot, '/'), '', 0);
        }
        ksort($out);
        return $out;
    }

    /** @return array{files:array<string,string>, generated:array, current:array{route:?string,site:?string}} */
    public static function overview(array $domain): array
    {
        $name = (string) $domain['domain_name'];
        $docroot = VhostService::effectiveDocroot($name);
        // Read as the account (its files aren't the panel's to read).
        $files = (array) PoolClient::call($name, 'htaccess', ['docroot' => $docroot])['files'];
        $route = VhostService::rulesFile($name);
        $site = VhostService::siteRulesFile($name);
        return [
            'files' => $files,
            'generated' => $files ? HtaccessTranslator::translate($files, $docroot) : ['route' => '', 'site' => '', 'notes' => [], 'needs_review' => false],
            'current' => [
                'route' => is_file($route) ? (string) file_get_contents($route) : null,
                'site' => is_file($site) ? (string) file_get_contents($site) : null,
            ],
        ];
    }

    /**
     * Checks $route/$site and queues them for the root worker. Empty route
     * = the default front controller (no rule file).
     *
     * @return list<string> validation errors (nothing queued if any)
     */
    public static function queueSave(array $domain, string $route, string $site, bool $needsReview = false): array
    {
        $name = (string) $domain['domain_name'];
        $docroot = VhostService::effectiveDocroot($name);
        $route = self::norm($route);
        $site = self::norm($site);
        $errors = [];
        if ($route !== '') {
            foreach (HtaccessTranslator::validate($route, $docroot, 'route') as $e) {
                $errors[] = "Rules: $e";
            }
        }
        if ($site !== '') {
            foreach (HtaccessTranslator::validate($site, $docroot, 'site') as $e) {
                $errors[] = "Site rules: $e";
            }
        }
        if (strlen($route) > 200000 || strlen($site) > 50000) {
            $errors[] = 'The rules are too long.';
        }
        if ($errors) {
            return $errors;
        }
        Database::app()->prepare('UPDATE domains SET routes_review = ? WHERE id = ?')->execute([$needsReview ? 1 : 0, $domain['id']]);
        SystemWorkerService::enqueue(self::label($name), [
            'type' => 'routes_apply',
            'domain' => $name,
            'docroot' => $docroot,
            'route' => $route === '' ? null : $route . "\n",
            'site' => $site === '' ? null : $site . "\n",
        ]);
        return [];
    }

    /**
     * What the .htaccess sync does with a domain (worker: routesSync). Pure.
     *
     * @param bool|null $sync routes_sync (null = not decided yet)
     * @param array{route:?string,site:?string} $current the rules in use
     * @param array{route:string,site:string,needs_review:bool} $generated the translated .htaccess
     * @return string 'skip' (.htaccess unchanged since the last look), 'enable' / 'disable'
     *                (first look: on only if the rules in use already are the translation),
     *                'review' (changed, but not translatable exactly: keep the rules in use),
     *                'same' (changed, translates to the rules in use), 'apply'
     */
    public static function syncPlan(?bool $sync, ?string $lastHash, string $hash, array $current, array $generated): string
    {
        $same = self::norm((string) ($current['route'] ?? '')) === self::norm($generated['route'])
            && self::norm((string) ($current['site'] ?? '')) === self::norm($generated['site']);
        if ($sync === null) {
            return $same ? 'enable' : 'disable';
        }
        if ($hash === $lastHash) {
            return 'skip';
        }
        if ($same) {
            return 'same';
        }
        return $generated['needs_review'] ? 'review' : 'apply';
    }

    /** Identity of a set of .htaccess files (and the folder they're translated for). */
    public static function filesHash(array $files, string $docroot): string
    {
        ksort($files);
        return hash('sha256', $docroot . "\0" . json_encode($files));
    }

    /** Rules as stored: trimmed, Unix line ends ('' = none). */
    public static function norm(string $rules): string
    {
        return str_replace("\r\n", "\n", trim($rules));
    }

    /**
     * Turns the .htaccess sync on (the worker applies the translation right
     * away, whatever is in use) or off (the rules in use stay as they are).
     */
    public static function setSync(array $domain, bool $on): void
    {
        Database::app()->prepare('UPDATE domains SET routes_sync = ?, routes_sync_hash = NULL, routes_sync_note = NULL WHERE id = ?')
            ->execute([$on ? 1 : 0, $domain['id']]);
        if ($on) {
            SystemWorkerService::enqueue(self::label((string) $domain['domain_name']), ['type' => 'routes_sync', 'domain' => (string) $domain['domain_name']]);
        }
    }

    public static function label(string $domain): string
    {
        return 'routes-' . $domain;
    }

    /** "OK" / "FAILED: ..." from the worker's last run for this domain, or null. */
    public static function lastResult(string $domain): ?string
    {
        return SystemWorkerService::lastLog(self::label($domain));
    }

    public static function pending(string $domain): bool
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', self::label($domain));
        return SystemWorkerService::pending("$safe-");
    }
}
