<?php
declare(strict_types=1);

/**
 * Translates a site's .htaccess files (Apache) into the two Caddy fragments
 * VhostService imports for a domain, and validates fragments a customer
 * edited by hand. Pure PHP: no file system access, no shell, no state.
 *
 *  - route: `/var/lib/frankenphp/site-rules/<domain>.caddy`, imported INSIDE
 *    the site's `route { }` (after the dotfile guard). Directives run in
 *    order there, so Apache's rule order can be kept. It always ends in
 *    php_server - no front-controller fallback unless a rule asks for one,
 *    exactly as Apache serves a site whose .htaccess has none.
 *  - site: `<domain>.site.caddy`, imported at site level: unconditional
 *    `header`s and `handle_errors` (ErrorDocument). Empty when not needed.
 *
 * How Apache's semantics are modelled:
 *
 *  - Access control (Require, Deny, <Files> deny) runs before mod_rewrite
 *    on the requested path, and again on the path a rewrite produces
 *    (Apache's internal redirect), so the denies are emitted first and are
 *    re-checked in the tail.
 *  - RewriteRules run in order, RewriteConds belong to the next rule, [L]
 *    and [END] stop. Each rule becomes a named matcher plus rewrite/redir/
 *    error; rules that must stop the rest of the list ([L] rewrites that
 *    aren't last) become mutually exclusive `handle` blocks. A run of [L]
 *    rewrites at the end of a list relies on Caddy's rewrite grouping
 *    (only the first matching `rewrite` in a block applies).
 *  - Per-directory rules see the path relative to their directory
 *    (`^login$` in the root = `^/login$`). A subfolder .htaccess with
 *    rewrite directives replaces the parent's rules below it (Apache's
 *    default without RewriteOptions Inherit); its own block is evaluated
 *    after the parent's, so a parent rewrite INTO the subfolder runs the
 *    subfolder's rules like Apache's second pass would.
 *  - Apache re-runs .htaccess rules after an [L] internal rewrite; Caddy
 *    doesn't. That only matters when the rewritten path matches another
 *    rule; for literal targets this is checked and flagged.
 *  - Redirect/RedirectMatch (mod_alias) run after mod_rewrite's redirects,
 *    on the original and the rewritten path.
 *
 * Anything that can't be translated faithfully is left out and reported
 * with status 'unsupported' (needs_review = true). Access-control
 * constructs that can't be translated (password protection, host-based
 * rules) fail closed: their folder answers 403 until someone reviews it.
 */
final class HtaccessTranslator
{
    /** Modules whose <IfModule> content is evaluated (present on cPanel and meaningful here). */
    private const EVALUATED_MODULES = ['rewrite', 'headers', 'alias', 'dir', 'mime', 'authz_core', 'authz_host', 'access_compat', 'core'];

    /** Variables whose value doesn't change when Apache re-runs the rules after an internal rewrite. */
    private const INVARIANT_VARS = ['THE_REQUEST', 'HTTP_HOST', 'REQUEST_METHOD', 'HTTPS', 'REMOTE_ADDR', 'SERVER_PORT', 'REQUEST_SCHEME', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'HTTP_COOKIE', 'HTTP_ACCEPT'];

    /** Matchers a customer may use in the rules files. */
    private const ALLOWED_MATCHERS = ['path', 'path_regexp', 'file', 'not', 'host', 'method', 'header', 'header_regexp', 'query', 'vars', 'vars_regexp', 'expression', 'remote_ip', 'client_ip', 'protocol'];

    /** Directives a customer may use inside the route file (and inside handle/route blocks). */
    private const ROUTE_DIRECTIVES = ['rewrite', 'redir', 'respond', 'error', 'header', 'handle', 'route', 'try_files', 'php_server', 'file_server', 'request_header', 'encode', 'vars', 'map'];

    /** Directives allowed at the top of the site file. */
    private const SITE_DIRECTIVES = ['header', 'request_header', 'handle_errors', 'encode', 'vars', 'map'];

    /** Directives allowed inside handle_errors. */
    private const ERROR_DIRECTIVES = ['rewrite', 'redir', 'respond', 'error', 'header', 'handle', 'route', 'try_files', 'php_server', 'file_server', 'request_header', 'vars', 'map'];

    private const PHP_SERVER_SUBDIRECTIVES = ['try_files', 'index', 'split'];
    private const FILE_SERVER_SUBDIRECTIVES = ['index', 'status', 'hide', 'precompressed', 'pass_thru', 'disable_canonical_uris'];

    /** Matcher names the surrounding VhostService route block already defines. */
    private const RESERVED_MATCHERS = ['@hidden'];

    /** @var array<string, mixed> translation state, reset by translate() */
    private static array $st = [];

    // =====================================================================
    // Public API
    // =====================================================================

    /**
     * @param array<string, string> $files  path relative to the docroot ('' = the
     *                                      docroot itself, 'admin' = admin/.htaccess)
     *                                      => that .htaccess file's contents
     * @param string $docroot               the site's document root on this server
     * @return array{route: string, site: string, notes: list<array{file:string,line:int,directive:string,status:string,message:string}>, needs_review: bool}
     */
    public static function translate(array $files, string $docroot): array
    {
        self::$st = [
            'docroot' => rtrim($docroot, '/'),
            'notes' => [],
            'review' => false,
            'scopes' => [],     // dir => engine/base/rules/hasRewrite/file
            'denies' => [],     // access decisions
            'access' => [],     // pending access-control groups (evaluated after the walk)
            'headers' => [],
            'errors' => [],
            'aliases' => [],
            'n' => 0,           // matcher name counter
        ];

        $dirs = [];
        foreach ($files as $path => $text) {
            $dir = trim(str_replace('\\', '/', (string)$path), '/');
            if ($dir === '.') {
                $dir = '';
            }
            if ($dir !== '' && (!preg_match('#^[A-Za-z0-9._~@+-]+(/[A-Za-z0-9._~@+-]+)*$#', $dir) || preg_match('#(^|/)\.\.?(/|$)#', $dir))) {
                self::note((string)$path, 0, '(file)', 'unsupported', 'Folder name contains characters the translator does not handle; this .htaccess was skipped.');
                continue;
            }
            $dirs[$dir] = (string)$text;
        }
        // Parents before children, so inherited state (RewriteEngine) is known.
        uksort($dirs, static fn ($a, $b) => [substr_count($a, '/') + ($a === '' ? 0 : 1), $a] <=> [substr_count($b, '/') + ($b === '' ? 0 : 1), $b]);

        foreach ($dirs as $dir => $text) {
            self::$st['curFile'] = $dir;
            self::$st['pendingConds'] = [];
            $nodes = self::parseHtaccess($text, $dir);
            self::walk($nodes, ['dir' => $dir, 'files' => null, 'methods' => null]);
            foreach (self::$st['pendingConds'] as $c) {
                self::note($dir, $c['line'], $c['raw'], 'ignored', 'RewriteCond without a following RewriteRule has no effect.');
            }
        }
        self::resolveAccess();

        $route = self::buildRoute();
        $site = self::buildSite();

        $notes = self::$st['notes'];
        usort($notes, static fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
        $review = self::$st['review'];
        self::$st = [];

        return ['route' => $route, 'site' => $site, 'notes' => $notes, 'needs_review' => $review];
    }

    /**
     * Checks a rules file before it is installed. $kind is 'route' (imported
     * inside the site's route block, must end in php_server) or 'site'
     * (imported at site level: header, handle_errors, ...).
     *
     * @return list<string> errors; empty means the file may be installed
     */
    public static function validate(string $caddy, string $docroot, string $kind = 'route'): array
    {
        $errors = [];
        if ($kind !== 'route' && $kind !== 'site') {
            return ['Unknown rules file kind.'];
        }
        if (strlen($caddy) > 512 * 1024) {
            return ['The rules file is too large.'];
        }
        if (str_contains($caddy, "\0")) {
            return ['The rules file contains a NUL byte.'];
        }
        if (preg_match('/\{\$/', $caddy)) {
            return ['Environment variables ({$...}) are not allowed.'];
        }
        $tokens = self::caddyTokens($caddy, $errors);
        if ($errors) {
            return $errors;
        }
        foreach ($tokens as $t) {
            if (preg_match('/\{(env|file)\./i', $t['text'])) {
                $errors[] = "line {$t['line']}: {env.*} and {file.*} placeholders are not allowed";
            }
        }
        if ($errors) {
            return $errors;
        }
        $tree = self::caddyTree($tokens, $errors);
        if ($errors) {
            return $errors;
        }
        $docroot = rtrim($docroot, '/');
        self::checkBlock($tree, $kind === 'route' ? 'route' : 'site', $docroot, $errors);
        if ($kind === 'route' && !$errors && !self::endsInPhpServer($tree)) {
            $errors[] = 'The route rules must end with php_server (directly, or as the last directive of a final handle/route block without a matcher).';
        }
        return $errors;
    }

    // =====================================================================
    // .htaccess parsing
    // =====================================================================

    /**
     * Splits a .htaccess file into a tree of directives and <Sections>.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseHtaccess(string $text, string $file): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }
        $physical = explode("\n", $text);
        $lines = [];
        $inCpanel = false;
        $count = count($physical);
        for ($i = 0; $i < $count; $i++) {
            $lineNo = $i + 1;
            $line = $physical[$i];
            $trim = trim($line);
            if ($inCpanel) {
                if (preg_match('/^#\s*END\s+cPanel-generated php ini directives/i', $trim)) {
                    $inCpanel = false;
                }
                continue;
            }
            if (preg_match('/^#\s*BEGIN\s+cPanel-generated php ini directives/i', $trim)) {
                $inCpanel = true;
                self::note($file, $lineNo, '# BEGIN cPanel-generated php ini directives', 'ignored', 'cPanel MultiPHP INI settings are not applied by Caddy; set PHP options in the panel instead.');
                continue;
            }
            if ($trim === '' || $trim[0] === '#') {
                continue;
            }
            // Line continuation: a backslash as the very last character.
            while (str_ends_with($line, '\\') && $i + 1 < $count) {
                $line = substr($line, 0, -1) . ' ' . ltrim($physical[++$i]);
            }
            $lines[] = [$lineNo, trim($line)];
        }
        $pos = 0;
        return self::parseNodes($lines, $pos, null, $file);
    }

    /**
     * @param list<array{0:int,1:string}> $lines
     * @return list<array<string, mixed>>
     */
    private static function parseNodes(array $lines, int &$pos, ?array $open, string $file): array
    {
        $nodes = [];
        while ($pos < count($lines)) {
            [$lineNo, $text] = $lines[$pos++];
            if (preg_match('#^</\s*([A-Za-z]+)\s*>$#', $text, $m)) {
                if ($open !== null && strcasecmp($m[1], $open['name']) === 0) {
                    return $nodes;
                }
                self::note($file, $lineNo, $text, 'unsupported', 'Closing tag without a matching opening section (Apache answers 500 for this file).');
                continue;
            }
            if (preg_match('#^<\s*([A-Za-z]+)(\s+[^>]*)?>$#', $text, $m)) {
                $section = ['t' => 's', 'name' => $m[1], 'args' => trim($m[2] ?? ''), 'line' => $lineNo, 'raw' => $text];
                $section['children'] = self::parseNodes($lines, $pos, $section, $file);
                $nodes[] = $section;
                continue;
            }
            if ($text[0] === '<') {
                self::note($file, $lineNo, $text, 'unsupported', 'Malformed section tag (Apache answers 500 for this file).');
                continue;
            }
            $parts = preg_split('/\s+/', $text, 2);
            $nodes[] = ['t' => 'd', 'name' => $parts[0], 'args' => $parts[1] ?? '', 'line' => $lineNo, 'raw' => $text];
        }
        if ($open !== null) {
            self::note($file, $open['line'], $open['raw'], 'unsupported', 'Section is never closed (Apache answers 500 for this file).');
        }
        return $nodes;
    }

    /** Apache's ap_getword_conf(): whitespace-separated words, "double" or 'single' quotes. */
    private static function words(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $q = $s[$i];
            if ($q === '"' || $q === "'") {
                $i++;
                $w = '';
                while ($i < $len && $s[$i] !== $q) {
                    if ($s[$i] === '\\' && $i + 1 < $len && ($s[$i + 1] === $q || $s[$i + 1] === '\\')) {
                        $i++;
                    }
                    $w .= $s[$i++];
                }
                $i++;
                $out[] = $w;
                continue;
            }
            $w = '';
            while ($i < $len && !ctype_space($s[$i])) {
                $w .= $s[$i++];
            }
            $out[] = $w;
        }
        return $out;
    }

    /**
     * mod_rewrite's own argument parser: double quotes group, a backslash
     * before whitespace or a quote keeps it in the word (backslash included).
     */
    private static function rewriteWords(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ctype_space($s[$i])) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            $quoted = $s[$i] === '"';
            if ($quoted) {
                $i++;
            }
            $w = '';
            while ($i < $len) {
                $c = $s[$i];
                if ($c === '\\' && $i + 1 < $len && (ctype_space($s[$i + 1]) || $s[$i + 1] === '"')) {
                    $w .= $c . $s[$i + 1];
                    $i += 2;
                    continue;
                }
                if ((!$quoted && ctype_space($c)) || ($quoted && $c === '"')) {
                    break;
                }
                $w .= $c;
                $i++;
            }
            if ($quoted) {
                $i++;
            }
            $out[] = $w;
        }
        return $out;
    }

    // =====================================================================
    // Walking the tree
    // =====================================================================

    /** @param array{dir:string, files:?array, methods:?array} $ctx */
    private static function walk(array $nodes, array $ctx): void
    {
        foreach ($nodes as $node) {
            if ($node['t'] === 's') {
                self::section($node, $ctx);
            } else {
                self::directive($node, $ctx);
            }
        }
    }

    private static function section(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        $name = strtolower($node['name']);
        $args = self::words($node['args']);
        switch ($name) {
            case 'ifmodule':
                $mod = $args[0] ?? '';
                $negated = str_starts_with($mod, '!');
                $norm = strtolower(ltrim($mod, '!'));
                $norm = preg_replace(['/^mod_/', '/\.c$/', '/_module$/'], '', $norm);
                if ($negated) {
                    self::note($file, $node['line'], $node['raw'], 'ignored', "Inactive: the module is loaded on cPanel, so Apache skips this block.");
                    return;
                }
                if ($norm === 'litespeed') {
                    self::note($file, $node['line'], $node['raw'], 'ignored', 'LiteSpeed-only settings (cache) do not apply to Caddy.');
                    return;
                }
                if (!in_array($norm, self::EVALUATED_MODULES, true)) {
                    $what = match ($norm) {
                        'deflate', 'gzip', 'brotli' => 'Compression is done by the site\'s `encode` directive.',
                        'expires' => 'Expiry headers are not translated; set Cache-Control with Header instead.',
                        'php', 'php5', 'php7', 'php8', 'lsapi', 'suphp', 'fcgid', 'php_fpm' => 'PHP settings are configured in the panel, not in .htaccess.',
                        default => 'Settings for this Apache module have no Caddy equivalent here.',
                    };
                    self::note($file, $node['line'], $node['raw'], 'ignored', $what);
                    return;
                }
                self::walk($node['children'], $ctx);
                return;

            case 'files':
            case 'filesmatch':
                if ($ctx['files'] !== null) {
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'Nested <Files> sections are not supported.');
                    self::failClosed($ctx, $node);
                    return;
                }
                $isRegex = $name === 'filesmatch';
                $pat = $args[0] ?? '';
                if ($name === 'files' && $pat === '~') {
                    $isRegex = true;
                    $pat = $args[1] ?? '';
                }
                if ($pat === '') {
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'Missing file pattern.');
                    return;
                }
                $err = null;
                $re = $isRegex ? self::re2($pat, $err) : self::globToRe($pat);
                if ($re === null || !self::pcreOk($pat, false) && $isRegex) {
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'File pattern can\'t be used by Caddy: ' . ($err ?? 'invalid regex') . '.');
                    self::failClosed($ctx, $node);
                    return;
                }
                if (self::tok($re) === null) {
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'File pattern can\'t be written as a Caddy token.');
                    self::failClosed($ctx, $node);
                    return;
                }
                $ctx['files'] = ['re' => $re, 'desc' => $node['raw']];
                self::walk($node['children'], $ctx);
                return;

            case 'limit':
            case 'limitexcept':
                $methods = array_values(array_filter(array_map('strtoupper', $args), static fn ($m) => preg_match('/^[A-Z]+$/', $m)));
                if (!$methods || $ctx['methods'] !== null) {
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unsupported method section.');
                    return;
                }
                foreach ($node['children'] as $child) {
                    $cn = strtolower($child['name']);
                    if ($child['t'] !== 'd' || !in_array($cn, ['require', 'order', 'allow', 'deny', 'satisfy', 'authtype', 'authname', 'authuserfile', 'authgroupfile', 'authbasicprovider'], true)) {
                        self::note($file, $node['line'], $node['raw'], 'unsupported', 'Only access-control directives are supported inside <' . $node['name'] . '>.');
                        return;
                    }
                }
                $ctx['methods'] = ['in' => $name === 'limit', 'list' => $methods];
                self::walk($node['children'], $ctx);
                return;

            case 'requireall':
            case 'requireany':
            case 'requirenone':
                $requires = [];
                foreach ($node['children'] as $child) {
                    if ($child['t'] === 'd' && strtolower($child['name']) === 'require') {
                        $requires[] = strtolower(trim(preg_replace('/\s+/', ' ', $child['args'])));
                    } else {
                        $requires[] = '?';
                    }
                }
                $group = &self::accessGroup($ctx, $node['line']);
                if ($name !== 'requirenone' && $requires && count(array_unique($requires)) === 1 && in_array($requires[0], ['all denied', 'all granted'], true)) {
                    $group['require'][] = $requires[0] === 'all denied' ? 'denied' : 'granted';
                    self::note($file, $node['line'], $node['raw'], 'translated', $requires[0] === 'all denied' ? 'Access denied (403).' : 'Access granted (no rule needed).');
                } else {
                    $group['require'][] = 'complex';
                    self::note($file, $node['line'], $node['raw'], 'unsupported', 'Combined Require rules are not translated; the folder answers 403 until reviewed.');
                }
                unset($group);
                return;

            case 'directory':
            case 'directorymatch':
            case 'location':
            case 'locationmatch':
            case 'virtualhost':
                self::note($file, $node['line'], $node['raw'], 'unsupported', "<{$node['name']}> is not allowed in .htaccess (Apache answers 500 for this file).");
                return;

            default:
                self::note($file, $node['line'], $node['raw'], 'unsupported', "<{$node['name']}> sections are not translated.");
                return;
        }
    }

    private static function directive(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        $name = strtolower($node['name']);
        $raw = $node['raw'];
        $line = $node['line'];
        $inFiles = $ctx['files'] !== null || $ctx['methods'] !== null;

        if ($ctx['methods'] !== null && !in_array($name, ['require', 'order', 'allow', 'deny', 'satisfy', 'authtype', 'authname', 'authuserfile', 'authgroupfile', 'authbasicprovider'], true)) {
            self::note($file, $line, $raw, 'unsupported', 'Not supported inside a method section.');
            return;
        }
        if ($inFiles && str_starts_with($name, 'rewrite')) {
            self::note($file, $line, $raw, 'unsupported', 'mod_rewrite inside <Files> is not supported.');
            return;
        }

        switch ($name) {
            case 'rewriteengine':
                $scope = &self::scope($ctx['dir']);
                $scope['hasRewrite'] = true;
                $scope['engine'] = strtolower(trim($node['args'])) === 'on';
                unset($scope);
                self::note($file, $line, $raw, 'translated', 'Rewrite engine ' . (strtolower(trim($node['args'])) === 'on' ? 'on.' : 'off: rules in this folder are not applied.'));
                return;

            case 'rewritebase':
                $base = trim($node['args']);
                if (!preg_match('#^/[A-Za-z0-9._~@+/-]*$#', $base)) {
                    self::note($file, $line, $raw, 'unsupported', 'RewriteBase must be a plain URL path.');
                    return;
                }
                $scope = &self::scope($ctx['dir']);
                $scope['hasRewrite'] = true;
                $scope['base'] = str_ends_with($base, '/') ? $base : $base . '/';
                unset($scope);
                self::note($file, $line, $raw, 'translated', 'Relative rule targets are resolved against ' . $base . '.');
                return;

            case 'rewritecond':
                $scope = &self::scope($ctx['dir']);
                $scope['hasRewrite'] = true;
                unset($scope);
                $w = self::rewriteWords($node['args']);
                if (count($w) < 2 || count($w) > 3 || (isset($w[2]) && !preg_match('/^\[[^\]]*\]$/', $w[2]))) {
                    self::note($file, $line, $raw, 'unsupported', 'Unexpected RewriteCond arguments (Apache answers 500 for this file).');
                    self::$st['pendingConds'][] = ['line' => $line, 'raw' => $raw, 'bad' => true];
                    return;
                }
                self::$st['pendingConds'][] = ['line' => $line, 'raw' => $raw, 'test' => $w[0], 'pattern' => $w[1], 'flags' => self::flags($w[2] ?? '')];
                return;

            case 'rewriterule':
                $scope = &self::scope($ctx['dir']);
                $scope['hasRewrite'] = true;
                $w = self::rewriteWords($node['args']);
                $conds = self::$st['pendingConds'];
                self::$st['pendingConds'] = [];
                if (count($w) < 2 || count($w) > 3 || (isset($w[2]) && !preg_match('/^\[[^\]]*\]$/', $w[2]))) {
                    self::note($file, $line, $raw, 'unsupported', 'Unexpected RewriteRule arguments (Apache answers 500 for this file).');
                    $scope['rules'][] = ['bad' => true, 'line' => $line, 'raw' => $raw, 'conds' => $conds, 'file' => $file];
                    unset($scope);
                    return;
                }
                $scope['rules'][] = ['line' => $line, 'raw' => $raw, 'pattern' => $w[0], 'target' => $w[1], 'flags' => self::flags($w[2] ?? ''), 'conds' => $conds, 'file' => $file];
                unset($scope);
                return;

            case 'rewriteoptions':
            case 'rewritemap':
            case 'rewritelog':
            case 'rewriteloglevel':
                $scope = &self::scope($ctx['dir']);
                $scope['hasRewrite'] = true;
                unset($scope);
                self::note($file, $line, $raw, 'unsupported', "{$node['name']} is not translated.");
                return;

            case 'header':
                self::header($node, $ctx);
                return;

            case 'forcetype':
                $type = trim($node['args'], " \t\"'");
                if (!preg_match('#^[A-Za-z0-9.+-]+/[A-Za-z0-9.+-]+(;\s*charset=[A-Za-z0-9_-]+)?$#', $type)) {
                    self::note($file, $line, $raw, 'unsupported', 'Unexpected media type.');
                    return;
                }
                self::$st['headers'][] = ['dir' => $ctx['dir'], 'files' => $ctx['files'], 'op' => 'set', 'always' => false, 'name' => 'Content-Type', 'value' => $type, 'line' => $line, 'file' => $file];
                self::note($file, $line, $raw, 'translated', 'Content-Type header set on these files.');
                return;

            case 'errordocument':
                self::errorDocument($node, $ctx);
                return;

            case 'redirect':
            case 'redirectpermanent':
            case 'redirecttemp':
            case 'redirectmatch':
                self::alias($node, $ctx);
                return;

            case 'require':
            case 'order':
            case 'allow':
            case 'deny':
            case 'satisfy':
                self::access($node, $ctx);
                return;

            case 'authtype':
            case 'authname':
            case 'authuserfile':
            case 'authgroupfile':
            case 'authbasicprovider':
            case 'authdigestprovider':
            case 'authdigestdomain':
                $group = &self::accessGroup($ctx, $line);
                $group['auth'] = true;
                unset($group);
                self::note($file, $line, $raw, 'unsupported', 'Password protection is not translated; the folder answers 403 until it is set up again.');
                return;

            case 'options':
                self::options($node);
                return;

            case 'directoryindex':
                $names = self::words($node['args']);
                if ($names && $names[0] === 'index.php') {
                    self::note($file, $line, $raw, 'translated', 'index.php is the directory index (php_server default; index.html is used when a folder has no index.php).');
                } else {
                    self::note($file, $line, $raw, 'unsupported', 'Only index.php as the first directory index is supported; Caddy serves index.php, then index.html.');
                }
                return;

            case 'php_value':
            case 'php_flag':
            case 'php_admin_value':
            case 'php_admin_flag':
                $w = self::words($node['args']);
                if (strtolower($w[0] ?? '') === 'engine' && in_array(strtolower($w[1] ?? ''), ['off', '0', 'false'], true)) {
                    self::phpOff($node, $ctx);
                    return;
                }
                self::note($file, $line, $raw, 'ignored', 'PHP settings are configured in the panel (php.ini), not in .htaccess.');
                return;

            case 'addhandler':
            case 'addtype':
                $w = self::words($node['args']);
                $handler = strtolower($w[0] ?? '');
                $exts = array_map(static fn ($e) => strtolower(ltrim($e, '.')), array_slice($w, 1));
                if (str_contains($handler, 'php')) {
                    if (array_diff($exts, ['php']) === []) {
                        self::note($file, $line, $raw, 'ignored', 'PHP version selection is done in the panel.');
                    } else {
                        self::note($file, $line, $raw, 'unsupported', 'Running other file extensions as PHP is not supported (these files would be served as plain files).');
                    }
                    return;
                }
                self::note($file, $line, $raw, 'ignored', $name === 'addtype' ? 'Caddy picks media types itself.' : 'Handlers have no Caddy equivalent here.');
                return;

            case 'removehandler':
            case 'sethandler':
            case 'removetype':
                $w = array_map('strtolower', self::words($node['args']));
                if (($name === 'removehandler' && in_array('.php', $w, true)) || ($name === 'sethandler' && in_array($w[0] ?? '', ['none', 'default-handler'], true))) {
                    self::phpOff($node, $ctx);
                    return;
                }
                self::note($file, $line, $raw, 'unsupported', "{$node['name']} is not translated.");
                return;

            case 'adddefaultcharset':
            case 'addcharset':
            case 'defaultlanguage':
            case 'addlanguage':
            case 'addencoding':
            case 'expiresactive':
            case 'expiresbytype':
            case 'expiresdefault':
            case 'addoutputfilterbytype':
            case 'addoutputfilter':
            case 'setoutputfilter':
            case 'removeoutputfilter':
            case 'deflatecompressionlevel':
            case 'fileetag':
            case 'serversignature':
            case 'indexignore':
            case 'indexoptions':
            case 'headername':
            case 'readmename':
            case 'cachelookup':
            case 'limitrequestbody':
            case 'contentdigest':
            case 'secfilterengine':
            case 'secfilterscanpost':
            case 'secruleengine':
                self::note($file, $line, $raw, 'ignored', match ($name) {
                    'adddefaultcharset', 'addcharset' => 'Caddy already sends UTF-8 text with a charset.',
                    'expiresactive', 'expiresbytype', 'expiresdefault' => 'mod_expires is not translated; set Cache-Control with Header instead.',
                    'addoutputfilterbytype', 'addoutputfilter', 'setoutputfilter', 'removeoutputfilter', 'deflatecompressionlevel' => 'Compression is done by the site\'s `encode` directive.',
                    'indexignore', 'indexoptions', 'headername', 'readmename' => 'Caddy never lists directories.',
                    'cachelookup' => 'LiteSpeed cache does not apply to Caddy.',
                    'limitrequestbody' => 'Request size limits are set server-wide.',
                    default => 'No effect on Caddy.',
                });
                return;

            default:
                self::note($file, $line, $raw, 'unsupported', "{$node['name']} is not translated.");
                return;
        }
    }

    private static function options(array $node): void
    {
        $file = self::$st['curFile'];
        $bad = [];
        foreach (self::words($node['args']) as $opt) {
            $o = strtolower($opt);
            $plain = ltrim($o, '+-');
            $sign = $o[0] === '-' ? '-' : '+';
            if ($o === 'none') {
                continue;
            }
            if (in_array($plain, ['followsymlinks', 'symlinksifownermatch'], true)
                || ($sign === '-' && in_array($plain, ['indexes', 'multiviews', 'execcgi', 'includes', 'includesnoexec'], true))) {
                continue;
            }
            $bad[] = $opt;
        }
        if ($bad) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Not available on Caddy: ' . implode(' ', $bad) . ' (directory listings, content negotiation, CGI and SSI are not supported).');
        } else {
            self::note($file, $node['line'], $node['raw'], 'ignored', 'Caddy never lists directories or negotiates file names (folders without an index answer 404 instead of 403).');
        }
    }

    // ---------------------------------------------------------------------
    // Headers, ErrorDocument, Redirect*, access control
    // ---------------------------------------------------------------------

    private static function header(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        $w = self::words($node['args']);
        $always = false;
        if (in_array(strtolower($w[0] ?? ''), ['always', 'onsuccess'], true)) {
            $always = strtolower(array_shift($w)) === 'always';
        }
        $op = strtolower($w[0] ?? '');
        $hname = $w[1] ?? '';
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $hname)) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unexpected header name.');
            return;
        }
        $value = null;
        $extra = [];
        if ($op === 'unset') {
            $extra = array_slice($w, 2);
        } elseif (in_array($op, ['set', 'append', 'add', 'setifempty'], true)) {
            if (!isset($w[2])) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Header value missing.');
                return;
            }
            $value = $w[2];
            $extra = array_slice($w, 3);
        } else {
            self::note($file, $node['line'], $node['raw'], 'unsupported', "Header {$op} is not translated.");
            return;
        }
        if ($extra) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Conditional headers (env=, expr=) are not translated.');
            return;
        }
        if ($value !== null) {
            if (preg_match('/%(?!%)/', $value)) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Header values with Apache format specifiers (%{...}e, %t, ...) are not translated.');
                return;
            }
            $value = str_replace('%%', '%', $value);
            if (strpbrk($value, "{}\n") !== false || self::tok($value) === null) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Header values containing braces are not translated (Caddy would read them as placeholders).');
                return;
            }
        }
        self::$st['headers'][] = ['dir' => $ctx['dir'], 'files' => $ctx['files'], 'op' => $op, 'always' => $always, 'name' => $hname, 'value' => $value, 'line' => $node['line'], 'file' => $file];
        self::note($file, $node['line'], $node['raw'], 'translated', $ctx['files'] === null && $ctx['dir'] === ''
            ? 'Site-wide header (site rules).'
            : 'Header on matching requests (route rules).');
    }

    private static function errorDocument(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        if ($ctx['files'] !== null) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'ErrorDocument inside <Files> is not translated.');
            return;
        }
        $parts = preg_split('/\s+/', trim($node['args']), 2);
        $code = (int)($parts[0] ?? 0);
        $rest = trim($parts[1] ?? '');
        if ($code < 400 || $code > 599 || $rest === '') {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'ErrorDocument needs a 4xx/5xx status and a target.');
            return;
        }
        if (strtolower($rest) === 'default') {
            self::note($file, $node['line'], $node['raw'], 'translated', 'Default error page (nothing to do).');
            return;
        }
        if ($rest[0] === '"') {
            $text = substr($rest, 1);
            $text = str_ends_with($text, '"') ? substr($text, 0, -1) : $text;
            $action = ['type' => 'text', 'value' => str_replace('\\"', '"', $text)];
        } elseif (preg_match('#^https?://[^\s{}"`]+$#i', $rest)) {
            $action = ['type' => 'url', 'value' => $rest];
        } elseif (preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@/%-]*(\?[A-Za-z0-9._~!$&\'()*+,;=:@/?%-]*)?$#', $rest)) {
            $action = ['type' => 'local', 'value' => $rest];
        } elseif (!str_contains($rest, '/')) {
            $action = ['type' => 'text', 'value' => $rest];
        } else {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unexpected ErrorDocument target.');
            return;
        }
        if ($action['type'] === 'text' && strpbrk($action['value'], '{}`') !== false) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Error message text with braces or backticks is not translated.');
            return;
        }
        self::$st['errors'][] = ['code' => $code, 'dir' => $ctx['dir'], 'action' => $action, 'line' => $node['line'], 'file' => $file];
        $isPhp = $action['type'] === 'local' && preg_match('#\.php($|\?)#', $action['value']);
        self::note($file, $node['line'], $node['raw'], 'translated', $isPhp
            ? "Review: Caddy runs {$action['value']} for {$code} errors, but answers 200 unless the script sets http_response_code({$code}) itself (Apache keeps {$code})."
            : "Custom {$code} page (site rules).", (bool)$isPhp);
    }

    private static function alias(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        $name = strtolower($node['name']);
        if ($ctx['files'] !== null) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Redirects inside <Files> are not translated.');
            return;
        }
        $w = self::words($node['args']);
        $status = match ($name) {
            'redirectpermanent' => 301,
            'redirecttemp' => 302,
            default => 302,
        };
        if (in_array($name, ['redirect', 'redirectmatch'], true) && isset($w[0]) && !str_starts_with($w[0], '/') && ($name === 'redirect' || count($w) > 2 || preg_match('/^(\d{3}|permanent|temp|seeother|gone)$/i', $w[0]))) {
            $s = strtolower(array_shift($w));
            $status = match ($s) {
                'permanent' => 301,
                'temp' => 302,
                'seeother' => 303,
                'gone' => 410,
                default => ctype_digit($s) ? (int)$s : 0,
            };
        }
        $from = $w[0] ?? '';
        $to = $w[1] ?? null;
        $isRedirect = $status >= 300 && $status < 400;
        if ($status < 300 || $status > 599 || $from === '' || ($isRedirect && $to === null) || (!$isRedirect && $to !== null) || count($w) > 2) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unexpected redirect arguments.');
            return;
        }
        $dirPath = $ctx['dir'] === '' ? '/' : '/' . $ctx['dir'] . '/';
        if ($name === 'redirectmatch') {
            $err = null;
            $re = self::re2($from, $err);
            if ($re === null || !self::pcreOk($from, false)) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Pattern can\'t be used by Caddy: ' . ($err ?? 'invalid regex') . '.');
                return;
            }
            $possible = false;
            foreach (self::literalPrefixes($from) ?? [''] as $literal) {
                if (str_starts_with($literal, $dirPath) || str_starts_with($dirPath, $literal)) {
                    $possible = true;
                }
            }
            if ($ctx['dir'] !== '' && !$possible) {
                self::note($file, $node['line'], $node['raw'], 'translated', "Review: never matches on Apache - RedirectMatch sees the full URL path, and this pattern can't match below {$dirPath}. Nothing emitted; if it was meant relative to this folder, write ^{$dirPath}... instead.", true);
                return;
            }
            $groups = self::groupCount($re);
        } else {
            if (!preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@/%-]*$#', $from)) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unexpected Redirect path.');
                return;
            }
            if ($ctx['dir'] !== '' && !str_starts_with($from . '/', $dirPath) && !str_starts_with($from, $dirPath)) {
                self::note($file, $node['line'], $node['raw'], 'translated', "Review: never matches on Apache - this .htaccess only sees requests below {$dirPath}. Nothing emitted.", true);
                return;
            }
            $q = preg_quote($from, '#');
            $re = str_ends_with($from, '/') ? '^' . $q . '(.*)$' : '^' . $q . '(/.*)?$';
            $groups = 1;
        }
        if ($to !== null) {
            if (!preg_match('#^(https?://[^\s/{}"`]+)?/?[^\s{}"`]*$#i', $to) || (!str_starts_with($to, '/') && !preg_match('#^https?://#i', $to))) {
                self::note($file, $node['line'], $node['raw'], 'unsupported', 'Unexpected redirect target.');
                return;
            }
            if ($name === 'redirectmatch' && preg_match('/\$(\d)/', $to, $m) && (int)$m[1] > $groups) {
                $to = preg_replace_callback('/\$(\d)/', static fn ($mm) => (int)$mm[1] > $groups ? '' : $mm[0], $to);
            }
        }
        if ($name !== 'redirectmatch' && $to !== null) {
            $to .= '$1';   // Redirect keeps the rest of the path: /old/x -> <target>/x
        }
        if (self::tok($re) === null || ($to !== null && self::tok($to) === null)) {
            self::note($file, $node['line'], $node['raw'], 'unsupported', 'Pattern or target can\'t be written as a Caddy token.');
            return;
        }
        self::$st['aliases'][] = ['dir' => $ctx['dir'], 're' => $re, 'to' => $to, 'status' => $status, 'prefix' => $name !== 'redirectmatch', 'line' => $node['line'], 'file' => $file];
        self::note($file, $node['line'], $node['raw'], 'translated', $isRedirect ? "Redirect ({$status})." : "Answers {$status}.");
    }

    private static function access(array $node, array $ctx): void
    {
        $file = self::$st['curFile'];
        $name = strtolower($node['name']);
        $args = strtolower(trim(preg_replace('/\s+/', ' ', $node['args'])));
        $group = &self::accessGroup($ctx, $node['line']);
        $ok = true;
        $msg = '';
        switch ($name) {
            case 'require':
                if ($args === 'all denied') {
                    $group['require'][] = 'denied';
                    $msg = 'Access denied (403).';
                } elseif ($args === 'all granted') {
                    $group['require'][] = 'granted';
                    $msg = 'Access granted (no rule needed).';
                } elseif (preg_match('/^ip ((?:[0-9a-f.:\/]+ ?)+)$/', $args, $m) && ($ips = self::ipList($m[1])) !== null) {
                    $group['require'][] = 'ip';
                    $group['ips'] = array_merge($group['ips'], $ips);
                    $msg = 'Only these addresses may access (403 for others).';
                } else {
                    $group['require'][] = 'complex';
                    $ok = false;
                    $msg = 'This Require rule is not translated; the folder answers 403 until reviewed.';
                }
                break;
            case 'order':
                $group['order'] = str_replace(' ', '', $args);
                $msg = 'Evaluated with the Allow/Deny lines.';
                break;
            case 'allow':
            case 'deny':
                if (!preg_match('/^from (.+)$/', $args, $m)) {
                    $ok = false;
                    $group['require'][] = 'complex';
                    $msg = 'Unexpected arguments; the folder answers 403 until reviewed.';
                    break;
                }
                if ($m[1] === 'all') {
                    $group[$name . 'All'] = true;
                } elseif (($ips = self::ipList($m[1])) !== null) {
                    $group[$name . 'Ips'] = array_merge($group[$name . 'Ips'], $ips);
                } else {
                    $ok = false;
                    $group['require'][] = 'complex';
                    $msg = 'Host-name based access rules are not translated; the folder answers 403 until reviewed.';
                    break;
                }
                $msg = $name === 'deny' ? 'Access rule (see the Order/Allow/Deny result).' : 'Access rule (see the Order/Allow/Deny result).';
                break;
            case 'satisfy':
                if ($args !== 'all') {
                    $ok = false;
                    $group['require'][] = 'complex';
                    $msg = 'Satisfy Any is not translated; the folder answers 403 until reviewed.';
                } else {
                    $msg = 'Default (nothing to do).';
                }
                break;
        }
        unset($group);
        self::note($file, $node['line'], $node['raw'], $ok ? 'translated' : 'unsupported', $msg);
    }

    /** Disables PHP below a folder (php_flag engine off, RemoveHandler .php, SetHandler none). */
    private static function phpOff(array $node, array $ctx): void
    {
        self::$st['denies'][] = ['dir' => $ctx['dir'], 'files' => ['re' => '\.(?i:php\d?|phtml|phar)$', 'desc' => ''], 'methods' => null, 'kind' => 'deny', 'ips' => [], 'line' => $node['line'], 'file' => self::$st['curFile'], 'own' => false];
        self::note(self::$st['curFile'], $node['line'], $node['raw'], 'translated', 'Review: PHP is disabled here; Apache served .php files as plain text, Caddy answers 403 for them instead.', true);
    }

    /** @return array<string, mixed> reference to the access group of this context */
    private static function &accessGroup(array $ctx, int $line): array
    {
        $key = $ctx['dir'] . "\0" . ($ctx['files']['re'] ?? '') . "\0" . json_encode($ctx['methods']);
        if (!isset(self::$st['access'][$key])) {
            self::$st['access'][$key] = ['dir' => $ctx['dir'], 'files' => $ctx['files'], 'methods' => $ctx['methods'], 'require' => [], 'ips' => [],
                'order' => null, 'allowAll' => false, 'denyAll' => false, 'allowIps' => [], 'denyIps' => [], 'auth' => false, 'line' => $line, 'file' => self::$st['curFile']];
        }
        return self::$st['access'][$key];
    }

    private static function failClosed(array $ctx, array $node): void
    {
        $group = &self::accessGroup($ctx, $node['line']);
        $group['require'][] = 'complex';
        unset($group);
    }

    /** Turns the collected access-control groups into deny entries. */
    private static function resolveAccess(): void
    {
        foreach (self::$st['access'] as $g) {
            $kind = null;   // null = allowed, 'deny', 'ipallow', 'ipdeny'
            $ips = [];
            $complex = $g['auth'] || in_array('complex', $g['require'], true);
            // mod_authz_core (Require)
            $req = null;
            if (!$complex && $g['require']) {
                $set = array_values(array_unique($g['require']));
                if ($set === ['denied']) {
                    $req = ['deny', []];
                } elseif ($set === ['granted']) {
                    $req = [null, []];
                } elseif ($set === ['ip']) {
                    $req = ['ipallow', $g['ips']];
                } else {
                    $complex = true;
                }
            }
            // mod_access_compat (Order/Allow/Deny)
            $old = null;
            $hasOld = $g['order'] !== null || $g['allowAll'] || $g['denyAll'] || $g['allowIps'] || $g['denyIps'];
            if (!$complex && $hasOld) {
                $order = $g['order'] ?? 'deny,allow';
                if ($order === 'deny,allow') {
                    if ($g['denyAll']) {
                        $old = $g['allowAll'] ? [null, []] : ($g['allowIps'] ? ['ipallow', $g['allowIps']] : ['deny', []]);
                    } else {
                        $old = $g['denyIps'] && !$g['allowAll'] && !$g['allowIps'] ? ['ipdeny', $g['denyIps']] : [null, []];
                        if ($g['denyIps'] && $g['allowIps']) {
                            $complex = true;
                        }
                    }
                } elseif ($order === 'allow,deny') {
                    if ($g['denyAll']) {
                        $old = ['deny', []];
                    } elseif ($g['allowAll']) {
                        $old = $g['denyIps'] ? ['ipdeny', $g['denyIps']] : [null, []];
                    } elseif ($g['allowIps']) {
                        $old = $g['denyIps'] ? null : ['ipallow', $g['allowIps']];
                        if ($old === null) {
                            $complex = true;
                        }
                    } else {
                        $old = ['deny', []];   // nothing allowed
                    }
                } else {
                    $complex = true;
                }
            }
            if (!$complex) {
                if ($req !== null && $old !== null && $req != $old) {
                    // Both styles in one place and they disagree: Apache 2.4's
                    // result depends on module order; don't guess.
                    $complex = true;
                } else {
                    [$kind, $ips] = $req ?? $old ?? [null, []];
                }
            }
            if ($complex) {
                $kind = 'deny';
                $ips = [];
                self::$st['review'] = true;
            }
            self::$st['denies'][] = ['dir' => $g['dir'], 'files' => $g['files'], 'methods' => $g['methods'], 'kind' => $kind, 'ips' => $ips, 'line' => $g['line'], 'file' => $g['file'], 'own' => true];
        }
    }

    // =====================================================================
    // RewriteRule compilation
    // =====================================================================

    /** Parses "[L,QSA,R=301]" into [name => value]. */
    private static function flags(string $s): array
    {
        $s = trim($s);
        if ($s === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', substr($s, 1, -1)) as $f) {
            $f = trim($f);
            if ($f === '') {
                continue;
            }
            $eq = strpos($f, '=');
            $k = strtoupper($eq === false ? $f : substr($f, 0, $eq));
            $v = $eq === false ? '' : substr($f, $eq + 1);
            $k = match ($k) {
                'LAST' => 'L', 'QSAPPEND' => 'QSA', 'QSDISCARD' => 'QSD', 'REDIRECT' => 'R', 'FORBIDDEN' => 'F',
                'GONE' => 'G', 'NOCASE' => 'NC', 'NOESCAPE' => 'NE', 'NOSUBREQ' => 'NS', 'PASSTHROUGH' => 'PT',
                'ENV' => 'E', 'COOKIE' => 'CO', 'TYPE' => 'T', 'HANDLER' => 'H', 'SKIP' => 'S', 'NEXT' => 'N',
                'CHAIN' => 'C', 'PROXY' => 'P', 'DISCARDPATH' => 'DPI', 'ORNEXT' => 'OR', 'NOVARY' => 'NV',
                default => $k,
            };
            if (isset($out[$k]) && $k === 'E') {
                $out['E'] .= ',' . $v;
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * Compiles one RewriteRule into one or more variants (more than one when
     * [OR] conditions have to be expanded).
     *
     * @return list<array<string, mixed>>|null null = unsupported (noted), [] = no effect (noted)
     */
    private static function compileRule(array $rule, string $dir, ?string $base, bool $nonLBefore): ?array
    {
        $file = $rule['file'];
        $raw = $rule['raw'];
        $fail = static function (string $msg) use ($file, $rule, $raw): ?array {
            self::note($file, $rule['line'], $raw, 'unsupported', $msg);
            return null;
        };
        $flags = $rule['flags'];
        $known = ['L', 'END', 'QSA', 'QSD', 'R', 'F', 'G', 'NC', 'NE', 'NS', 'PT', 'NV', 'DPI', 'E'];
        foreach (array_keys($flags) as $f) {
            if (!in_array($f, $known, true)) {
                return $fail("Flag [{$f}] is not supported.");
            }
        }
        // Authorization / XSRF header passing (WordPress, Laravel): FrankenPHP
        // passes every request header to PHP already.
        if (isset($flags['E'])) {
            $passOnly = $rule['target'] === '-' && array_diff(array_keys($flags), ['E', 'NC', 'NS', 'NV']) === [];
            foreach (explode(',', $flags['E']) as $assign) {
                if (!preg_match('/^HTTP_([A-Z0-9_]+):%\{HTTP:([A-Za-z0-9-]+)\}$/i', $assign, $m) || strtoupper(str_replace('-', '_', $m[2])) !== strtoupper($m[1])) {
                    $passOnly = false;
                }
            }
            if (!$passOnly) {
                return $fail('Setting environment variables ([E=...]) is not translated.');
            }
            self::note($file, $rule['line'], $raw, 'ignored', 'Passes a request header to PHP; FrankenPHP already does that.');
            foreach ($rule['conds'] as $c) {
                self::note($file, $c['line'], $c['raw'], 'ignored', 'Condition of an ignored rule.');
            }
            return [];
        }
        foreach ($rule['conds'] as $c) {
            if (!empty($c['bad'])) {
                return $fail('A condition of this rule could not be read.');
            }
        }
        if (isset($flags['QSA'], $flags['QSD'])) {
            return $fail('[QSA] together with [QSD] is not supported.');
        }

        // --- pattern
        $pattern = $rule['pattern'];
        $negate = false;
        if (str_starts_with($pattern, '!')) {
            $negate = true;
            $pattern = substr($pattern, 1);
        }
        $nc = isset($flags['NC']);
        if (!self::pcreOk($pattern, $nc)) {
            return $fail('Invalid regular expression.');
        }
        $err = null;
        $re = self::re2($pattern, $err);
        if ($re === null) {
            return $fail("Pattern can't be used by Caddy: {$err}.");
        }
        $groups = self::groupCount($re);

        // --- action
        $target = $rule['target'];
        $isR = isset($flags['R']);
        $code = 0;
        if ($isR) {
            $rv = strtolower($flags['R']);
            $code = match ($rv) { '' => 302, 'permanent' => 301, 'temp' => 302, 'seeother' => 303, default => ctype_digit($rv) ? (int)$rv : -1 };
            if ($code < 300 || $code > 599) {
                return $fail('Unexpected redirect status.');
            }
        }
        $kind = null;
        if (isset($flags['F'])) {
            $kind = 'error';
            $code = 403;
        } elseif (isset($flags['G'])) {
            $kind = 'error';
            $code = 410;
        } elseif ($isR && $code >= 400) {
            $kind = 'error';
        } elseif ($target === '-') {
            if ($isR) {
                return $fail('A redirect needs a target.');
            }
            $kind = isset($flags['L']) || isset($flags['END']) ? 'pass' : 'noop';
        } elseif ($isR || preg_match('#^https?://#i', $target)) {
            $kind = 'redir';
            if (!$isR) {
                $code = 302;
            }
        } else {
            $kind = 'rewrite';
        }
        $terminal = $kind !== 'rewrite' || isset($flags['L']) || isset($flags['END']);
        if ($kind === 'noop') {
            self::note($file, $rule['line'], $raw, 'ignored', 'Rule without a target or effect.');
            foreach ($rule['conds'] as $c) {
                self::note($file, $c['line'], $c['raw'], 'ignored', 'Condition of a rule without effect.');
            }
            return [];
        }
        $refsTarget = in_array($kind, ['rewrite', 'redir'], true) ? $target : '';
        $usesDollar = (bool)preg_match('/(?<!\\\\)\$\d/', $refsTarget);
        $usesPercent = (bool)preg_match('/(?<!\\\\)%\d/', $refsTarget);
        $dollarZero = (bool)preg_match('/(?<!\\\\)\$0/', $refsTarget);
        if ($negate && $usesDollar) {
            return $fail('A negated pattern has no captures to use.');
        }
        if ($dollarZero) {
            return $fail('$0 is not supported.');
        }

        $prefix = $dir === '' ? '/' : '/' . $dir . '/';
        // CodeIgniter: RewriteCond $1 ... on a rule that captures the whole path.
        $wholeCapture = in_array($pattern, ['^(.*)$', '^(.+)$', '(.*)', '^(.*)', '(.+)', '^(.+)'], true);

        // --- conditions
        $condItems = [];   // list of groups (OR-chains), each a list of alternatives
        $chain = [];
        foreach ($rule['conds'] as $c) {
            $item = self::compileCond($c, $prefix, $wholeCapture, $usesPercent);
            if ($item === null) {
                $why = self::$st['condErr'];
                foreach ($rule['conds'] as $c2) {
                    self::note($file, $c2['line'], $c2['raw'], 'unsupported', $c2['line'] === $c['line'] ? "Not supported: {$why}." : 'Part of the unsupported rule on line ' . $rule['line'] . '.');
                }
                return $fail("Condition on line {$c['line']} is not supported: {$why}.");
            }
            if ($nonLBefore && in_array(self::condVar($c['test']), ['REQUEST_URI'], true)) {
                self::$st['review'] = true;
                self::note($file, $c['line'], $c['raw'], 'translated', 'Review: an earlier rule without [L] rewrote the path; Apache still compares REQUEST_URI with the original path here.', true);
            }
            $chain[] = $item;
            if (!isset($c['flags']['OR'])) {
                $condItems[] = $chain;
                $chain = [];
            }
        }
        if ($chain) {
            $condItems[] = $chain;   // trailing [OR] on the last condition: Apache treats it as AND
        }

        // Simplify each OR group.
        $groupsOut = [];
        foreach ($condItems as $alts) {
            $alts = array_values(array_filter($alts, static fn ($a) => $a['m'] !== 'never'));
            if (!$alts) {
                self::note($file, $rule['line'], $raw, 'translated', 'Never applies on Caddy (it only matches on Apache\'s internal second pass, which Caddy does not do). Nothing emitted.');
                foreach ($rule['conds'] as $c) {
                    self::note($file, $c['line'], $c['raw'], 'translated', 'Condition of a rule that never applies.');
                }
                return [];
            }
            foreach ($alts as $a) {
                if ($a['m'] === 'always') {
                    continue 2;
                }
            }
            if (count($alts) === 1) {
                $groupsOut[] = [$alts[0]];
                continue;
            }
            $merged = self::mergeOr($alts, $usesPercent);
            if ($merged !== null) {
                $groupsOut[] = [$merged];
                continue;
            }
            if (!$terminal) {
                return $fail('[OR] conditions on a rule without [L] are not supported.');
            }
            $groupsOut[] = $alts;   // expanded into variants below
        }
        $variants = [[]];
        foreach ($groupsOut as $alts) {
            $next = [];
            foreach ($variants as $v) {
                foreach ($alts as $a) {
                    $next[] = array_merge($v, [$a]);
                }
            }
            $variants = $next;
            if (count($variants) > 16) {
                return $fail('Too many [OR] combinations.');
            }
        }

        $out = [];
        foreach ($variants as $items) {
            $name = 'r' . (++self::$st['n']);
            $lines = [];
            if ($negate) {
                $prx = self::perDir($re, $prefix, false, $err);
                if ($prx === null) {
                    return $fail((string)$err);
                }
                $lines[] = ['key' => null, 'text' => 'not path_regexp ' . self::tok(($nc ? '(?i)' : '') . ($prx === '' ? '^' . self::quoteRe($prefix) : $prx))];
            } else {
                $prx = self::perDir($re, $prefix, $usesDollar, $err);
                if ($prx === null) {
                    return $fail((string)$err);
                }
                if ($prx !== '') {
                    $tk = self::tok(($nc ? '(?i)' : '') . $prx);
                    if ($tk === null) {
                        return $fail('Pattern can\'t be written as a Caddy token.');
                    }
                    $lines[] = ['key' => 'path_regexp', 'text' => "path_regexp {$name} {$tk}"];
                }
            }
            // %N refers to the last condition with a (positive) regex.
            $cap = null;
            $k = 0;
            foreach ($items as $it) {
                $k++;
                if (!empty($it['captures'])) {
                    $cap = ['name' => "{$name}c{$k}", 'groups' => $it['groups'], 'offset' => $it['offset'] ?? 0, 're' => $it['re'], 'subject' => $it['subject'] ?? ''];
                }
            }
            $k = 0;
            foreach ($items as $it) {
                $k++;
                $item = self::renderItem($it, "{$name}c{$k}");
                if ($item === null) {
                    return $fail('A condition pattern can\'t be written as a Caddy token.');
                }
                $lines[] = $item;
            }
            // --- target
            $action = null;
            if ($kind === 'rewrite' || $kind === 'redir') {
                $t = self::buildTarget($target, $flags, $kind, $name, $groups, $re, $cap, $base ?? $prefix, $err);
                if ($t === null) {
                    return $fail((string)$err);
                }
                $action = $kind === 'rewrite' ? ['rewrite', $t['uri']] : ['redir', $t['uri'] . ' ' . $code];
                $caveat = $t['caveat'];
            } elseif ($kind === 'error') {
                $action = ['error', (string)$code];
                $caveat = '';
            } else {
                $action = ['pass', ''];
                $caveat = '';
            }
            $out[] = ['name' => $name, 'lines' => self::assembleSet($lines), 'kind' => $kind, 'terminal' => $terminal, 'action' => $action, 'caveat' => $caveat, 'end' => isset($flags['END'])];
        }
        return $out;
    }

    /**
     * Compiles a RewriteCond into a matcher item:
     * ['m' => matcher|'always'|'never', 'neg' => bool, ...].
     */
    private static function compileCond(array $c, string $prefix, bool $wholeCapture, bool $percentUsed): ?array
    {
        self::$st['condErr'] = '';
        $test = $c['test'];
        $pat = $c['pattern'];
        $nc = isset($c['flags']['NC']);
        $fail = static function (string $msg): ?array {
            self::$st['condErr'] = $msg;
            return null;
        };
        foreach (array_keys($c['flags']) as $f) {
            if (!in_array($f, ['NC', 'OR', 'NV'], true)) {
                return $fail("flag [{$f}]");
            }
        }
        $neg = false;
        if (str_starts_with($pat, '!')) {
            $neg = true;
            $pat = substr($pat, 1);
        }

        // --- file tests
        if (preg_match('/^-([fdslFUxeh])$/', $pat, $fm)) {
            $op = $fm[1];
            $p = self::fileTestPath($test);
            if ($p === null) {
                return $fail('file test on ' . $test);
            }
            if ($op !== 'f' && $op !== 'd') {
                return $fail("-{$op} file test");
            }
            $path = $op === 'd' ? rtrim($p, '/') . '/' : $p;
            return ['m' => 'file', 'neg' => $neg, 'paths' => [$path]];
        }
        if (preg_match('/^-(lt|le|eq|ge|gt|ne)/', $pat) || preg_match('/^[<>]/', $pat)) {
            return $fail('numeric/lexical comparison');
        }

        // --- string equality "=value"
        $isEq = str_starts_with($pat, '=');
        if ($isEq) {
            $val = substr($pat, 1);
            if ($val === '""') {
                $val = '';
            }
            $re = '^' . preg_quote($val, '#') . '$';
            $pcre = $re;
        } else {
            if (!self::pcreOk($pat, $nc)) {
                return $fail('invalid regex');
            }
            $err = null;
            $re = self::re2($pat, $err);
            if ($re === null) {
                return $fail((string)$err);
            }
            $pcre = $pat;
        }
        $captures = !$neg && !$isEq;   // only a positive regex cond sets %N

        // --- CodeIgniter: RewriteCond $1 with the rule capturing the whole path
        if ($test === '$1') {
            if (!$wholeCapture) {
                return $fail('$N in a condition (only supported when the rule captures the whole path)');
            }
            $err = null;
            $prx = self::perDir($re, $prefix, false, $err);
            if ($prx === null) {
                return $fail((string)$err);
            }
            return ['m' => 'vars_regexp', 'neg' => $neg, 'subject' => '{path}', 're' => ($nc ? '(?i)' : '') . ($prx === '' ? '^' : $prx), 'captures' => false, 'groups' => 0];
        }

        $var = self::condVar($test);
        if ($var === null) {
            return $fail('test string ' . $test . ' (only a single %{VARIABLE})');
        }
        $ci = $nc ? '(?i)' : '';
        $groups = self::groupCount($re);
        switch (true) {
            case $var === 'ENV:REDIRECT_STATUS':
                // Empty on the first pass - the only pass Caddy has.
                $matchesEmpty = preg_match(self::pcreDelim($pcre, $nc), '') === 1;
                return ['m' => ($matchesEmpty xor $neg) ? 'always' : 'never'];

            case $var === 'HTTPS':
                $on = preg_match(self::pcreDelim($pcre, $nc), 'on') === 1;
                $off = preg_match(self::pcreDelim($pcre, $nc), 'off') === 1;
                if ($on === $off) {
                    return ['m' => ($on xor $neg) ? 'always' : 'never'];
                }
                if ($captures && $percentUsed && $groups > 0) {
                    return $fail('captures from %{HTTPS}');
                }
                return ['m' => 'protocol', 'neg' => ($off xor $neg), 'args' => ['https'], 'captures' => false, 'groups' => 0];

            case $var === 'REQUEST_METHOD' && ($isEq || preg_match('/^\^\(?([A-Z]+(?:\|[A-Z]+)*)\)?\$$/', $pat, $mm)) && !$nc:
                $methods = $isEq ? [substr($pat, 1)] : explode('|', $mm[1]);
                if (!$isEq && $captures && $percentUsed) {
                    break;  // keep it a regex so %N works
                }
                foreach ($methods as $m) {
                    if (!preg_match('/^[A-Z]+$/', $m)) {
                        return $fail('unexpected method');
                    }
                }
                return ['m' => 'method', 'neg' => $neg, 'args' => $methods, 'captures' => false, 'groups' => 0];

            case $var === 'THE_REQUEST':
                $methods = null;
                $offset = 0;
                $err = null;
                $tr = self::theRequest($pat, $methods, $offset, $err);
                if ($tr === null) {
                    return $fail("this THE_REQUEST pattern ({$err})");
                }
                $err = null;
                $tre = self::re2($tr, $err);
                if ($tre === null) {
                    return $fail((string)$err);
                }
                if ($methods !== null && $neg) {
                    return $fail('negated THE_REQUEST with a method');
                }
                return ['m' => 'vars_regexp', 'neg' => $neg, 'subject' => '{http.request.orig_uri}', 're' => $ci . $tre, 'captures' => $captures, 'groups' => self::groupCount($tre) + $offset, 'offset' => $offset,
                    'methods' => $methods];
        }

        $subject = match (true) {
            $var === 'QUERY_STRING' => ['vars', '{query}'],
            $var === 'REQUEST_URI' => ['vars', '{path}'],
            $var === 'REQUEST_METHOD' => ['vars', '{method}'],
            $var === 'REMOTE_ADDR' => ['vars', '{remote_host}'],
            $var === 'SERVER_PORT' => ['vars', '{http.request.port}'],
            $var === 'REQUEST_SCHEME' => ['vars', '{scheme}'],
            $var === 'HTTP_HOST' => ['header', 'Host'],
            $var === 'HTTP_USER_AGENT' => ['header', 'User-Agent'],
            $var === 'HTTP_REFERER' => ['header', 'Referer'],
            $var === 'HTTP_COOKIE' => ['header', 'Cookie'],
            $var === 'HTTP_ACCEPT' => ['header', 'Accept'],
            str_starts_with($var, 'HTTP:') => ['header', substr($var, 5)],
            default => null,
        };
        if ($subject === null) {
            return $fail("%{{$var}}");
        }
        if ($subject[0] === 'header') {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $subject[1])) {
                return $fail('header name');
            }
            if ($subject[1] !== 'Host' && preg_match(self::pcreDelim($pcre, $nc), '') === 1) {
                return $fail("a pattern that matches an empty {$subject[1]} header (Caddy can't tell a missing header from an empty one)");
            }
            return ['m' => 'header_regexp', 'neg' => $neg, 'field' => $subject[1], 're' => $ci . $re, 'captures' => $captures, 'groups' => $groups];
        }
        return ['m' => 'vars_regexp', 'neg' => $neg, 'subject' => $subject[1], 're' => $ci . $re, 'captures' => $captures, 'groups' => $groups];
    }

    /** "%{REQUEST_URI}" -> "REQUEST_URI", "%{HTTP:X-Foo}" -> "HTTP:X-Foo", else null. */
    private static function condVar(string $test): ?string
    {
        if (preg_match('/^%\{([A-Za-z_]+(?::[A-Za-z0-9_-]+)?)\}$/', $test, $m)) {
            $v = $m[1];
            if (str_contains($v, ':')) {
                [$a, $b] = explode(':', $v, 2);
                return strtoupper($a) . ':' . (strtoupper($a) === 'ENV' ? strtoupper($b) : $b);
            }
            return strtoupper($v);
        }
        return null;
    }

    /** Test string of a file test -> Caddy file matcher path (relative to the site root). */
    private static function fileTestPath(string $test): ?string
    {
        $test = preg_replace('/\\\\(.)/', '$1', $test);
        if (preg_match('/^%\{(REQUEST_FILENAME|SCRIPT_FILENAME)\}(.*)$/', $test, $m)) {
            $suffix = $m[2];
            return preg_match('#^[A-Za-z0-9._/-]*$#', $suffix) && !str_contains($suffix, '..') ? '{path}' . $suffix : null;
        }
        if (preg_match('#^%\{DOCUMENT_ROOT\}(/?)%\{(REQUEST_URI|SCRIPT_NAME)\}(.*)$#', $test, $m)) {
            return preg_match('#^[A-Za-z0-9._/-]*$#', $m[3]) && !str_contains($m[3], '..') ? '{path}' . $m[3] : null;
        }
        if (preg_match('#^%\{DOCUMENT_ROOT\}(/[A-Za-z0-9._/-]+)$#', $test, $m)) {
            return str_contains($m[1], '..') ? null : $m[1];
        }
        $docroot = self::$st['docroot'] ?? '';
        if ($docroot !== '' && str_starts_with($test, $docroot . '/') && preg_match('#^[A-Za-z0-9._/-]+$#', $test) && !str_contains($test, '..')) {
            return substr($test, strlen($docroot));
        }
        return null;
    }

    /** Tries to fold an [OR] group into one matcher. */
    private static function mergeOr(array $alts, bool $percentUsed): ?array
    {
        $m = $alts[0]['m'];
        foreach ($alts as $a) {
            if ($a['m'] !== $m || $a['neg'] || (!empty($a['methods']))) {
                return null;
            }
        }
        if ($m === 'file') {
            return ['m' => 'file', 'neg' => false, 'paths' => array_merge(...array_map(static fn ($a) => $a['paths'], $alts))];
        }
        if ($m === 'method') {
            return ['m' => 'method', 'neg' => false, 'args' => array_values(array_unique(array_merge(...array_map(static fn ($a) => $a['args'], $alts)))), 'captures' => false, 'groups' => 0];
        }
        if ($percentUsed) {
            return null;
        }
        if ($m === 'vars_regexp' || $m === 'header_regexp') {
            $key = $m === 'vars_regexp' ? 'subject' : 'field';
            foreach ($alts as $a) {
                if ($a[$key] !== $alts[0][$key]) {
                    return null;
                }
            }
            $res = array_map(static fn ($a) => '(?:' . $a['re'] . ')', $alts);
            return array_merge($alts[0], ['re' => implode('|', $res), 'captures' => false, 'groups' => 0]);
        }
        return null;
    }

    /** One matcher item -> ['key' => collision key|null, 'text' => line], or null if a pattern can't be written. */
    private static function renderItem(array $it, string $capName): ?array
    {
        $not = $it['neg'] ? 'not ' : '';
        switch ($it['m']) {
            case 'file':
                $paths = array_map([self::class, 'tok'], $it['paths']);
                if (in_array(null, $paths, true)) {
                    return null;
                }
                return ['key' => $it['neg'] ? null : 'file', 'text' => $not . 'file ' . implode(' ', $paths), 'file' => $it['paths'], 'neg' => $it['neg']];
            case 'method':
                return ['key' => $it['neg'] ? null : 'method', 'text' => $not . 'method ' . implode(' ', $it['args'])];
            case 'protocol':
                return ['key' => $it['neg'] ? null : 'protocol', 'text' => $not . 'protocol https'];
            case 'vars_regexp':
            case 'header_regexp':
                $re = self::tok($it['re']);
                if ($re === null) {
                    return null;
                }
                $name = !$it['neg'] && !empty($it['captures']) ? $capName . ' ' : '';
                if ($it['m'] === 'header_regexp') {
                    return ['key' => $it['neg'] ? null : 'header_regexp ' . strtolower($it['field']), 'text' => $not . 'header_regexp ' . $name . $it['field'] . ' ' . $re];
                }
                $line = ['key' => $it['neg'] ? null : 'vars_regexp ' . $it['subject'], 'text' => $not . 'vars_regexp ' . $name . $it['subject'] . ' ' . $re];
                if (!empty($it['methods'])) {
                    $line['extra'] = ['key' => 'method', 'text' => 'method ' . implode(' ', $it['methods'])];
                }
                return $line;
        }
        throw new LogicException('unknown matcher item');
    }

    /**
     * Puts matcher lines into one Caddy matcher set: negative file tests are
     * merged (not file a b = neither exists), and a second positive matcher of
     * the same kind is nested as `not { not ... }` (Caddy would otherwise OR
     * or overwrite it).
     *
     * @return list<string>
     */
    private static function assembleSet(array $lines): array
    {
        $expanded = [];
        foreach ($lines as $l) {
            $expanded[] = $l;
            if (isset($l['extra'])) {
                $expanded[] = $l['extra'];
            }
        }
        $out = [];
        $seen = [];
        $negFiles = [];
        $negFileAt = null;
        foreach ($expanded as $l) {
            if (isset($l['file']) && $l['neg']) {
                $negFiles = array_merge($negFiles, $l['file']);
                if ($negFileAt === null) {
                    $negFileAt = count($out);
                    $out[] = '';
                }
                continue;
            }
            if ($l['key'] === null) {
                $out[] = $l['text'];
                continue;
            }
            if (isset($seen[$l['key']])) {
                $out[] = ['not {', 'not ' . $l['text'], '}'];
                continue;
            }
            $seen[$l['key']] = true;
            $out[] = $l['text'];
        }
        if ($negFileAt !== null) {
            $out[$negFileAt] = 'not file ' . implode(' ', array_map([self::class, 'tok'], array_values(array_unique($negFiles))));
        }
        $flat = [];
        foreach ($out as $o) {
            if (is_array($o)) {
                $flat[] = $o[0];
                $flat[] = "\t" . $o[1];
                $flat[] = $o[2];
            } else {
                $flat[] = $o;
            }
        }
        return $flat;
    }

    /**
     * Builds the Caddy rewrite/redir target from a RewriteRule substitution.
     *
     * @return array{uri:string, caveat:string, literal:?string}|null
     */
    private static function buildTarget(string $target, array $flags, string $kind, string $name, int $groups, string $re, ?array $cap, string $base, ?string &$err): ?array
    {
        $caveat = '';
        $isRedir = $kind === 'redir';
        if ($isRedir && !isset($flags['R'])) {
            $caveat = 'Review: a full URL without [R] redirects with 302; Apache would rewrite internally instead if it points at this same site.';
        }
        if (isset($flags['R']) && !isset($flags['L']) && !isset($flags['END'])) {
            $caveat = trim($caveat . ' Review: [R] without [L] - Apache keeps applying the following rules to the redirect target; Caddy redirects right away.');
        }
        // Split the query at the first unescaped '?'.
        $qpos = null;
        for ($i = 0, $n = strlen($target); $i < $n; $i++) {
            if ($target[$i] === '\\') {
                $i++;
                continue;
            }
            if ($target[$i] === '?') {
                $qpos = $i;
                break;
            }
        }
        $pathT = $qpos === null ? $target : substr($target, 0, $qpos);
        $queryT = $qpos === null ? null : substr($target, $qpos + 1);

        // Redirect idiom: https://%{HTTP_HOST}%{REQUEST_URI} keeps the raw (escaped) URI and query.
        $uriIdiom = $isRedir && $queryT === null && !isset($flags['QSD']) && str_ends_with($pathT, '%{REQUEST_URI}');

        $expand = function (string $s, bool $inQuery) use ($name, $groups, $cap, &$err, &$caveat): ?string {
            $out = '';
            $n = strlen($s);
            for ($i = 0; $i < $n; $i++) {
                $c = $s[$i];
                if ($c === '\\' && $i + 1 < $n) {
                    $out .= $s[++$i];
                    continue;
                }
                if ($c === '$' && $i + 1 < $n && ctype_digit($s[$i + 1])) {
                    $d = (int)$s[++$i];
                    $out .= $d <= $groups ? '{re.' . $name . '.' . $d . '}' : '';
                    if ($inQuery) {
                        $caveat = 'Caddy URL-encodes captured values placed in the query string ($_GET is the same; the raw QUERY_STRING is encoded).';
                    }
                    continue;
                }
                if ($c === '%' && $i + 1 < $n && ctype_digit($s[$i + 1])) {
                    $d = (int)$s[++$i];
                    if ($cap === null) {
                        $err = '%' . $d . ' used, but no condition captures anything';
                        return null;
                    }
                    if ($d === 0) {
                        $err = '%0 is not supported';
                        return null;
                    }
                    if ($d <= $cap['offset']) {
                        $out .= '{method}';   // the method group of a THE_REQUEST pattern
                    } elseif ($d <= $cap['groups']) {
                        $out .= '{re.' . $cap['name'] . '.' . ($d - $cap['offset']) . '}';
                    }
                    if ($inQuery) {
                        $caveat = 'Caddy URL-encodes captured values placed in the query string ($_GET is the same; the raw QUERY_STRING is encoded).';
                    }
                    continue;
                }
                if ($c === '%' && $i + 1 < $n && $s[$i + 1] === '{') {
                    $end = strpos($s, '}', $i);
                    if ($end === false) {
                        $err = 'unterminated %{...}';
                        return null;
                    }
                    $var = strtoupper(substr($s, $i + 2, $end - $i - 2));
                    $rep = match (true) {
                        $var === 'HTTP_HOST' => '{hostport}',
                        $var === 'SERVER_NAME' => '{host}',
                        $var === 'REQUEST_URI' => '{path}',
                        $var === 'QUERY_STRING' => '{query}',
                        $var === 'REQUEST_SCHEME' => '{scheme}',
                        $var === 'SERVER_PORT' => '{http.request.port}',
                        $var === 'REMOTE_ADDR' => '{remote_host}',
                        default => null,
                    };
                    if ($rep === null) {
                        $err = "%{{$var}} in the target is not supported";
                        return null;
                    }
                    $out .= $rep;
                    $i = $end;
                    continue;
                }
                if ($c === '$' && $i + 1 < $n && $s[$i + 1] === '{') {
                    $err = 'RewriteMap lookups are not supported';
                    return null;
                }
                if ($c === '{' || $c === '}' || $c === '`' || $c === '"' || ctype_space($c)) {
                    $err = 'the target contains characters Caddy would misread';
                    return null;
                }
                $out .= $c;
            }
            return $out;
        };

        if ($uriIdiom) {
            $p = $expand(substr($pathT, 0, -strlen('%{REQUEST_URI}')), false);
            if ($p === null) {
                return null;
            }
            $uri = $p . '{uri}';
            $tk = self::tok($uri);
            if ($tk === null || !preg_match('#^(/|https?://|\{)#i', $uri)) {
                $err = 'unexpected redirect target';
                return null;
            }
            return ['uri' => $tk, 'caveat' => $caveat, 'literal' => null];
        }

        $p = $expand($pathT, false);
        if ($p === null) {
            return null;
        }
        $q = $queryT === null ? null : $expand($queryT, true);
        if ($queryT !== null && $q === null) {
            return null;
        }

        // Relative or absolute?
        $external = (bool)preg_match('#^https?://#i', $p);
        if (!$external) {
            if ($p === '' && $pathT === '') {
                $err = 'empty target';
                return null;
            }
            $absolute = str_starts_with($p, '/');
            if (preg_match('/^([$%])(\d)/', $pathT, $lm)) {
                // The target starts with a capture: Apache decides after
                // expanding it whether the result is rooted, so work out
                // whether the captured text starts with a slash.
                $where = null;
                if ($lm[1] === '$') {
                    $where = self::groupContext($re, (int)$lm[2]);
                    $where = $where === 'start' ? 'afterslash' : $where;   // the folder-relative path has no leading slash
                } elseif ($cap !== null && (int)$lm[2] > $cap['offset']) {
                    $where = self::groupContext($cap['re'], (int)$lm[2] - $cap['offset']);
                    if ($where === 'start') {
                        $where = in_array($cap['subject'], ['{path}', '{http.request.orig_uri}'], true) ? 'slash' : null;
                    }
                }
                if ($where === null) {
                    $err = 'can\'t tell whether the captured text starts with a slash';
                    return null;
                }
                $absolute = $where === 'slash';
            }
            if (!$absolute) {
                $p = $base . $p;
            }
        }
        if (!$isRedir && $external) {
            $err = 'internal rewrite to a full URL';
            return null;
        }
        if ($isRedir && !isset($flags['NE'])) {
            $p = str_replace('#', '%23', $p);
        }
        // Query string handling.
        $qsa = isset($flags['QSA']);
        $qsd = isset($flags['QSD']);
        if ($isRedir) {
            if ($q === null) {
                $uri = $p . ($qsd ? '' : '{?query}');
            } elseif ($q === '') {
                $uri = $p . ($qsa ? '{?query}' : '');
            } else {
                $uri = $p . '?' . $q . ($qsa ? '&{query}' : '');
                if ($qsa) {
                    $caveat = trim($caveat . ' The redirect ends with "&" when the request had no query string.');
                }
            }
        } else {
            if ($q === null) {
                $uri = $p . ($qsd ? '?' : '');
            } elseif ($q === '') {
                $uri = $p . '?' . ($qsa ? '{query}' : '');
            } else {
                $uri = $p . '?' . $q . ($qsa ? '&{query}' : '');
            }
        }
        if (!preg_match('#^(/|https?://|\{)#i', $uri)) {
            $err = 'the target does not resolve to a path';
            return null;
        }
        $tk = self::tok($uri);
        if ($tk === null) {
            $err = 'the target can\'t be written as a Caddy token';
            return null;
        }
        $literal = (!$isRedir && !str_contains($uri, '{')) ? $uri : null;
        return ['uri' => $tk, 'caveat' => $caveat, 'literal' => $literal];
    }

    // =====================================================================
    // Output
    // =====================================================================

    private static function buildRoute(): string
    {
        $out = ['# Generated by JinnPanel from .htaccess - see the review notes in cPanel > Domains > Routes.'];
        $hasRewrite = false;

        // Compile every scope's rules.
        $scopes = self::$st['scopes'];
        ksort($scopes);
        uksort($scopes, static fn ($a, $b) => [$a === '' ? 0 : substr_count($a, '/') + 1, $a] <=> [$b === '' ? 0 : substr_count($b, '/') + 1, $b]);
        $compiled = [];
        $engine = [];
        foreach ($scopes as $dir => $scope) {
            if (!$scope['hasRewrite']) {
                continue;
            }
            // RewriteEngine is inherited from the closest parent that sets it.
            $on = $scope['engine'];
            $p = $dir;
            while ($on === null && $p !== '') {
                $p = str_contains($p, '/') ? substr($p, 0, strrpos($p, '/')) : '';
                $on = $scopes[$p]['engine'] ?? null;
            }
            $engine[$dir] = (bool)$on;
            $list = [];
            $nonL = false;
            foreach ($scope['rules'] as $rule) {
                if (!empty($rule['bad'])) {
                    continue;
                }
                if (!$on) {
                    self::note($rule['file'], $rule['line'], $rule['raw'], 'ignored', 'RewriteEngine is not On here; Apache ignores this rule.');
                    continue;
                }
                $variants = self::compileRule($rule, $dir, $scope['base'], $nonL);
                if ($variants === null) {
                    // Rules after an unsupported one still run in order; the
                    // unsupported one is simply absent.
                    continue;
                }
                if (!$variants) {
                    continue;
                }
                foreach ($variants as $v) {
                    $v['rule'] = $rule;
                    $list[] = $v;
                }
                $first = $variants[0];
                if ($first['kind'] === 'rewrite') {
                    $hasRewrite = true;
                    if (!$first['terminal']) {
                        $nonL = true;
                    }
                }
                $msg = match ($first['kind']) {
                    'rewrite' => 'Internal rewrite' . ($first['terminal'] ? '' : ' (processing continues with the next rule)') . '.',
                    'redir' => 'Redirect.',
                    'error' => 'Answers ' . $first['action'][1] . '.',
                    default => 'Stops here and serves the request as it is.',
                };
                $caveat = $first['caveat'];
                self::note($rule['file'], $rule['line'], $rule['raw'], 'translated', trim($msg . ' ' . $caveat), str_contains($caveat, 'Review:'));
                foreach ($rule['conds'] as $c) {
                    self::note($rule['file'], $c['line'], $c['raw'], 'translated', 'Condition of the rule on line ' . $rule['line'] . '.');
                }
            }
            self::repassCheck($scope, $dir, $list);
            $compiled[$dir] = $list;
        }

        // Access control on the requested path.
        $denyLines = self::denyLines('d');
        $out = array_merge($out, $denyLines);

        // Rewrite rules, one block per folder that has its own.
        $ruleDirs = array_keys($compiled);
        if ($ruleDirs === [''] ) {
            $out = array_merge($out, self::emitRules($compiled['']));
        } elseif ($ruleDirs) {
            foreach ($ruleDirs as $dir) {
                if (!$compiled[$dir]) {
                    continue;
                }
                $children = array_values(array_filter($ruleDirs, static fn ($d) => $d !== $dir && ($dir === '' || str_starts_with($d, $dir . '/'))));
                $m = 's' . (++self::$st['n']);
                $set = [];
                if ($dir !== '') {
                    $set[] = 'path_regexp ' . self::tok('^/' . self::quoteRe($dir) . '(/|$)');
                }
                if ($children) {
                    $rel = array_map(static fn ($d) => self::quoteRe($dir === '' ? $d : substr($d, strlen($dir) + 1)), $children);
                    $set[] = 'not path_regexp ' . self::tok('^/' . ($dir === '' ? '' : self::quoteRe($dir) . '/') . '(' . implode('|', $rel) . ')(/|$)');
                }
                $out = array_merge($out, self::matcherDef($m, $set));
                $out = array_merge($out, self::block('route @' . $m, self::emitRules($compiled[$dir])));
            }
        }

        $out = array_merge($out, self::tail($hasRewrite));
        return self::render($out);
    }

    private static function buildSite(): string
    {
        $out = [];
        // Unconditional, site-wide headers.
        $hs = array_values(array_filter(self::$st['headers'], static fn ($h) => $h['dir'] === '' && $h['files'] === null));
        if ($hs) {
            $out = array_merge($out, self::headerGroup($hs, '*'));
        }
        // ErrorDocument
        $byCode = [];
        foreach (self::$st['errors'] as $e) {
            $byCode[$e['code']][$e['dir']] = $e;   // the last one wins, like Apache
        }
        ksort($byCode);
        foreach ($byCode as $code => $entries) {
            uksort($entries, static fn ($a, $b) => strlen($b) <=> strlen($a));
            if (count($entries) === 1 && isset($entries[''])) {
                $out = array_merge($out, self::block("handle_errors {$code}", self::errorBody($entries[''], $code)));
                continue;
            }
            $body = [];
            foreach ($entries as $dir => $e) {
                if ($dir === '') {
                    $body = array_merge($body, self::block('handle', self::errorBody($e, $code)));
                    continue;
                }
                $m = 'e' . (++self::$st['n']);
                $body = array_merge($body, self::matcherDef($m, ['path ' . self::tok('/' . $dir) . ' ' . self::tok('/' . $dir . '/*')]));
                $body = array_merge($body, self::block('handle @' . $m, self::errorBody($e, $code)));
            }
            $out = array_merge($out, self::block("handle_errors {$code}", self::block('route', $body)));
        }
        if (!$out) {
            return '';
        }
        array_unshift($out, '# Generated by JinnPanel from .htaccess - see the review notes in cPanel > Domains > Routes.');
        return self::render($out);
    }

    private static function errorBody(array $e, int $code): array
    {
        $a = $e['action'];
        switch ($a['type']) {
            case 'text':
                return ['respond ' . (self::tok($a['value']) ?? '""') . ' ' . $code];
            case 'url':
                return ['redir * ' . self::tok($a['value']) . ' 302'];
            default:
                $path = $a['value'];
                $isPhp = (bool)preg_match('#\.php($|\?)#', $path);
                if ($isPhp) {
                    return ['rewrite * ' . self::tok($path), ...self::block('php_server', ['try_files {path}'])];
                }
                return ['rewrite * ' . self::tok($path), ...self::block('file_server', ["status {$code}"])];
        }
    }

    /** Matcher + error lines for every access-control decision. */
    private static function denyLines(string $prefix): array
    {
        $out = [];
        $ownDirs = [];
        foreach (self::$st['denies'] as $d) {
            if ($d['own'] && $d['files'] === null && $d['methods'] === null) {
                $ownDirs[$d['dir']] = true;
            }
        }
        foreach (self::$st['denies'] as $d) {
            if ($d['kind'] === null) {
                continue;
            }
            $set = [];
            if ($d['dir'] !== '') {
                $set[] = 'path ' . self::tok('/' . $d['dir']) . ' ' . self::tok('/' . $d['dir'] . '/*');
            }
            // A deeper folder with its own access rules overrides this one (folder rules only).
            if ($d['files'] === null && $d['methods'] === null) {
                $deeper = [];
                foreach (array_keys($ownDirs) as $od) {
                    $od = (string)$od;
                    if ($od !== $d['dir'] && ($d['dir'] === '' || str_starts_with($od, $d['dir'] . '/'))) {
                        $deeper[] = self::tok('/' . $od);
                        $deeper[] = self::tok('/' . $od . '/*');
                    }
                }
                if ($deeper) {
                    $set[] = 'not path ' . implode(' ', $deeper);
                }
            }
            if ($d['files'] !== null) {
                $set[] = 'vars_regexp {http.request.uri.path.file} ' . self::tok($d['files']['re']);
            }
            if ($d['methods'] !== null) {
                $set[] = ($d['methods']['in'] ? '' : 'not ') . 'method ' . implode(' ', $d['methods']['list']);
            }
            if ($d['kind'] === 'ipallow') {
                $set[] = 'not remote_ip ' . implode(' ', $d['ips']);
            } elseif ($d['kind'] === 'ipdeny') {
                $set[] = 'remote_ip ' . implode(' ', $d['ips']);
            }
            $m = $prefix . (++self::$st['n']);
            if ($set) {
                $out = array_merge($out, self::matcherDef($m, $set));
                $out[] = "error @{$m} 403";
            } else {
                $out[] = 'error * 403';
            }
        }
        return $out;
    }

    /** What every request that wasn't redirected or denied ends with. */
    private static function tail(bool $hasRewrite): array
    {
        $out = [];
        // mod_alias: original path first, then the rewritten one.
        foreach (self::$st['aliases'] as $a) {
            $variants = $hasRewrite ? ['orig', 'cur'] : ['cur'];
            foreach ($variants as $which) {
                $m = 'a' . (++self::$st['n']);
                $set = [];
                if ($which === 'cur') {
                    $set[] = ['key' => 'path_regexp', 'text' => "path_regexp {$m} " . self::tok($a['re'])];
                    if ($a['dir'] !== '') {
                        $set[] = ['key' => 'path', 'text' => 'path ' . self::tok('/' . $a['dir']) . ' ' . self::tok('/' . $a['dir'] . '/*')];
                    }
                } else {
                    $set[] = ['key' => 'vars_regexp o', 'text' => "vars_regexp {$m} {http.request.orig_uri.path} " . self::tok($a['re'])];
                    if ($a['dir'] !== '') {
                        $set[] = ['key' => 'vars_regexp o', 'text' => 'vars_regexp {http.request.orig_uri.path} ' . self::tok('^/' . self::quoteRe($a['dir']) . '(/|$)')];
                    }
                }
                $out = array_merge($out, self::matcherDef($m, self::assembleSet($set)));
                if ($a['to'] === null) {
                    $out[] = "error @{$m} {$a['status']}";
                    continue;
                }
                $to = preg_replace('/\$(\d)/', '{re.' . $m . '.$1}', $a['to']);
                if (!str_contains($a['to'], '?')) {
                    $to .= '{?query}';
                }
                $out[] = "redir @{$m} " . self::tok($to) . " {$a['status']}";
            }
        }
        // Access control again, on the path a rewrite produced.
        if ($hasRewrite) {
            $out = array_merge($out, self::denyLines('t'));
        }
        // Headers for folders and <Files> sections, on the final path.
        $groups = [];
        foreach (self::$st['headers'] as $h) {
            if ($h['dir'] === '' && $h['files'] === null) {
                continue;
            }
            $groups[$h['dir'] . "\0" . ($h['files']['re'] ?? '')][] = $h;
        }
        foreach ($groups as $hs) {
            $h = $hs[0];
            $m = 'h' . (++self::$st['n']);
            $set = [];
            if ($h['dir'] !== '') {
                $set[] = 'path ' . self::tok('/' . $h['dir']) . ' ' . self::tok('/' . $h['dir'] . '/*');
            }
            if ($h['files'] !== null) {
                $set[] = 'vars_regexp {http.request.uri.path.file} ' . self::tok($h['files']['re']);
            }
            $out = array_merge($out, self::matcherDef($m, $set));
            $out = array_merge($out, self::headerGroup($hs, '@' . $m));
        }
        $out = array_merge($out, self::matcherDef('nophp', ['path *.php', 'not file {path}']));
        $out[] = 'error @nophp 404';
        $out = array_merge($out, self::block('php_server', ['try_files {path} {path}/index.php']));
        return $out;
    }

    /**
     * Header ops -> Caddy header directives. Deferred, so they replace what
     * PHP sends like mod_headers does; `Header always set` is also set right
     * away, because deferred headers don't reach error pages.
     */
    private static function headerGroup(array $hs, string $matcher): array
    {
        $mt = $matcher === '*' ? '' : $matcher . ' ';
        $ops = [];
        $now = [];
        $names = [];
        foreach ($hs as $h) {
            $val = $h['value'] !== null ? ' ' . (self::tok($h['value']) ?? '""') : '';
            $prefix = match ($h['op']) { 'append', 'add' => '+', 'setifempty' => '?', 'unset' => '-', default => '' };
            $ops[] = $prefix . $h['name'] . $val;
            if ($h['op'] === 'set' && $h['always']) {
                $now[] = $h['name'] . $val;
            }
            $names[] = strtolower($h['name']);
        }
        if (count($hs) === 1 || count(array_unique($names)) !== count($names)) {
            // One op, or several ops on the same field: keep them as single lines, in order.
            $out = [];
            foreach ($hs as $i => $h) {
                if ($h['op'] === 'set' && $h['always']) {
                    $out[] = "header {$mt}" . $ops[$i];
                }
                $out[] = "header {$mt}" . ($h['op'] === 'set' ? '>' : '') . $ops[$i];
            }
            return $out;
        }
        $out = [];
        if ($now) {
            // Not a duplicate: deferred headers never reach error pages, and an
            // immediate one alone would be sent twice when PHP sets it too.
            $out[] = '# "Header always set": now (error pages too) and deferred (replaces what PHP sends)';
            $out = array_merge($out, self::block('header' . ($mt === '' ? '' : ' ' . $matcher), $now));
        }
        return array_merge($out, self::block('header' . ($mt === '' ? '' : ' ' . $matcher), array_merge(['defer'], $ops)));
    }

    /**
     * Emits a folder's compiled rules, keeping Apache's order and [L].
     *
     * @return list<string|array>
     */
    private static function emitRules(array $rules): array
    {
        $out = [];
        $n = count($rules);
        for ($i = 0; $i < $n; $i++) {
            $r = $rules[$i];
            $tok = $r['lines'] ? '@' . $r['name'] : '*';
            $restRewrites = true;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($rules[$j]['kind'] !== 'rewrite' || !$rules[$j]['terminal']) {
                    $restRewrites = false;
                    break;
                }
            }
            if ($r['kind'] === 'redir' || $r['kind'] === 'error') {
                $out = array_merge($out, self::matcherDef($r['name'], $r['lines']));
                $out[] = $r['action'][0] . ' ' . $tok . ' ' . $r['action'][1];
                if (!$r['lines']) {
                    return $out;   // everything after is unreachable
                }
                continue;
            }
            if ($r['kind'] === 'rewrite' && !$r['terminal']) {
                $out = array_merge($out, self::matcherDef($r['name'], $r['lines']));
                $out = array_merge($out, self::block('route' . ($r['lines'] ? ' @' . $r['name'] : ''), ['rewrite * ' . $r['action'][1]]));
                continue;
            }
            if ($r['kind'] === 'rewrite' && $restRewrites) {
                $out = array_merge($out, self::matcherDef($r['name'], $r['lines']));
                $out[] = 'rewrite ' . $tok . ' ' . $r['action'][1];
                if (!$r['lines']) {
                    return $out;
                }
                continue;
            }
            if ($r['kind'] === 'pass' && $i === $n - 1) {
                return $out;
            }
            return array_merge($out, self::emitChain(array_slice($rules, $i)));
        }
        return $out;
    }

    /** Mutually exclusive handle blocks: the first rule that matches wins, like [L]. */
    private static function emitChain(array $rules): array
    {
        $out = [];
        $n = count($rules);
        $k = $n;
        while ($k > 1 && $rules[$k - 1]['kind'] === 'rewrite' && $rules[$k - 1]['terminal']) {
            $k--;
        }
        for ($j = 0; $j < $k; $j++) {
            $r = $rules[$j];
            if ($r['kind'] === 'rewrite' && !$r['terminal']) {
                return array_merge($out, self::block('handle', self::block('route', self::emitRules(array_slice($rules, $j)))));
            }
            $body = match ($r['kind']) {
                'pass' => [],
                'rewrite' => ['rewrite * ' . $r['action'][1]],
                default => [$r['action'][0] . ' * ' . $r['action'][1]],
            };
            $out = array_merge($out, self::matcherDef($r['name'], $r['lines']));
            $out = array_merge($out, self::block('handle' . ($r['lines'] ? ' @' . $r['name'] : ''), $body));
            if (!$r['lines']) {
                return $out;
            }
        }
        if ($k < $n) {
            $rest = array_slice($rules, $k);
            if (count($rest) === 1) {
                $r = $rest[0];
                $out = array_merge($out, self::matcherDef($r['name'], $r['lines']));
                $out = array_merge($out, self::block('handle' . ($r['lines'] ? ' @' . $r['name'] : ''), ['rewrite * ' . $r['action'][1]]));
            } else {
                $out = array_merge($out, self::block('handle', self::block('route', self::emitRules($rest))));
            }
        }
        return $out;
    }

    /**
     * Apache runs the rules again after an [L] internal rewrite; Caddy
     * doesn't. Flags rules whose (literal) target would be changed by that
     * second pass.
     */
    private static function repassCheck(array $scope, string $dir, array $list): void
    {
        $prefix = $dir === '' ? '/' : '/' . $dir . '/';
        // Only rules that were emitted: ignored ones (header passing) have no
        // effect, unsupported ones are flagged already.
        $emitted = [];
        foreach ($list as $v) {
            $emitted[$v['rule']['line']] = true;
        }
        $rules = array_values(array_filter($scope['rules'], static fn ($r) => empty($r['bad']) && isset($emitted[$r['line']])));
        $seen = [];
        foreach ($list as $v) {
            $rule = $v['rule'];
            if (isset($seen[$rule['line']]) || $v['kind'] !== 'rewrite' || $v['end'] || !$v['terminal']) {
                continue;
            }
            $seen[$rule['line']] = true;
            $t = $rule['target'];
            if (preg_match('/[$%]/', $t)) {
                continue;
            }
            [$tp, $tq] = array_pad(explode('?', $t, 2), 2, null);
            $tp = str_starts_with($tp, '/') ? $tp : ($scope['base'] ?? $prefix) . $tp;
            if (!str_starts_with($tp, $prefix)) {
                continue;
            }
            $rel = substr($tp, strlen($prefix));
            foreach ($rules as $j => $other) {
                $res = self::evalRule($other, $rel, $tp, $tq, $other['line'] < $rule['line']);
                if ($res === false) {
                    continue;
                }
                $of = $other['flags'];
                $passThrough = $other['target'] === '-' && (isset($of['L']) || isset($of['END'])) && !isset($of['R']) && !isset($of['F']) && !isset($of['G']);
                $sameTarget = !preg_match('/[$%]/', $other['target']) && ltrim($other['target'], '/') === ltrim($t, '/') && !isset($of['R']);
                $stable = $other['line'] === $rule['line'] || $passThrough || $sameTarget;
                if (!$stable) {
                    self::note($rule['file'], $rule['line'], $rule['raw'], 'translated', "Review: Apache runs the rules again on {$tp} after this rewrite, and the rule on line {$other['line']} " . ($res === true ? 'applies' : 'may apply') . ' then; Caddy stops after the first pass.', true);
                }
                break;
            }
        }
    }

    /** @return bool|null true = applies, false = doesn't, null = can't tell */
    private static function evalRule(array $rule, string $rel, string $path, ?string $query, bool $before)
    {
        $pat = $rule['pattern'];
        $neg = str_starts_with($pat, '!');
        $pat = ltrim($pat, '!');
        $m = @preg_match(self::pcreDelim($pat, isset($rule['flags']['NC'])), $rel);
        if ($m === false) {
            return null;
        }
        if (($m === 1) === $neg) {
            return false;
        }
        $result = true;
        $orChain = null;
        foreach ($rule['conds'] as $c) {
            if (!empty($c['bad'])) {
                return null;
            }
            $v = self::evalCond($c, $path, $query);
            if ($orChain !== null) {
                $v = $orChain === true || $v === true ? true : ($orChain === null || $v === null ? null : false);
            }
            if (isset($c['flags']['OR'])) {
                $orChain = $v;
                continue;
            }
            $orChain = null;
            if ($v === false) {
                return false;
            }
            if ($v === null) {
                $result = null;
            }
        }
        return $result;
    }

    private static function evalCond(array $c, string $path, ?string $query): ?bool
    {
        $pat = $c['pattern'];
        $neg = str_starts_with($pat, '!');
        $pat = ltrim($pat, '!');
        $nc = isset($c['flags']['NC']);
        $test = $c['test'];
        if (preg_match('/^-([fd])$/', $pat, $m)) {
            if (preg_match('/^%\{(REQUEST_FILENAME|SCRIPT_FILENAME)\}$/', $test)) {
                $v = $m[1] === 'f';   // the rewrite target is assumed to be an existing file
                return $v xor $neg;
            }
            return null;
        }
        $var = self::condVar($test);
        $subject = match ($var) {
            'REQUEST_URI' => $path,
            'QUERY_STRING' => $query,
            'ENV:REDIRECT_STATUS' => '200',
            default => null,
        };
        if ($var !== null && in_array($var, self::INVARIANT_VARS, true)) {
            return false;   // same value as on the first pass; see repassCheck()
        }
        if ($subject === null) {
            return null;
        }
        if (str_starts_with($pat, '=')) {
            return (substr($pat, 1) === $subject) xor $neg;
        }
        $r = @preg_match(self::pcreDelim($pat, $nc), $subject);
        return $r === false ? null : (($r === 1) xor $neg);
    }

    // =====================================================================
    // Regex helpers
    // =====================================================================

    private static function pcreDelim(string $re, bool $nc): string
    {
        return "\x01" . str_replace("\x01", '', $re) . "\x01" . ($nc ? 'i' : '');
    }

    private static function pcreOk(string $re, bool $nc): bool
    {
        return @preg_match(self::pcreDelim($re, $nc), '') !== false;
    }

    /**
     * Converts an Apache (PCRE) regex to the RE2 syntax Caddy uses, or null
     * (with $err) when it needs a feature RE2 doesn't have.
     */
    private static function re2(string $re, ?string &$err): ?string
    {
        $out = '';
        $len = strlen($re);
        $inClass = false;
        $classStart = 0;
        $lastQuant = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $re[$i];
            $wasQuant = $lastQuant;
            $lastQuant = false;
            if ($c === '\\') {
                if ($i + 1 >= $len) {
                    $err = 'the pattern ends with a backslash';
                    return null;
                }
                $n = $re[++$i];
                if ($n === ' ') {
                    $out .= ' ';
                    continue;
                }
                if (ctype_digit($n)) {
                    $err = 'back-references inside a pattern are not supported';
                    return null;
                }
                if ($n === 'Q') {
                    $end = strpos($re, '\\E', $i + 1);
                    $lit = $end === false ? substr($re, $i + 1) : substr($re, $i + 1, $end - $i - 1);
                    $out .= preg_quote($lit, null);
                    $i = $end === false ? $len : $end + 1;
                    continue;
                }
                if (ctype_alpha($n)) {
                    $ok = $inClass ? 'dDsSwWpPxntrf' : 'dDsSwWbBAzpPxntrf';
                    if (!str_contains($ok, $n)) {
                        $err = "\\{$n} is not supported by Caddy's regex engine";
                        return null;
                    }
                    $out .= '\\' . $n;
                    if (($n === 'p' || $n === 'P' || $n === 'x') && $i + 1 < $len && $re[$i + 1] === '{') {
                        $end = strpos($re, '}', $i);
                        if ($end === false) {
                            $err = 'unterminated \\' . $n . '{';
                            return null;
                        }
                        $out .= substr($re, $i + 1, $end - $i);
                        $i = $end;
                    }
                    continue;
                }
                if (ord($n) > 127) {
                    $err = 'escaped non-ASCII character';
                    return null;
                }
                $out .= '\\' . $n;
                continue;
            }
            if ($inClass) {
                if ($c === '[' && $i + 1 < $len && $re[$i + 1] === ':') {
                    $end = strpos($re, ':]', $i + 2);
                    if ($end === false) {
                        $err = 'unterminated character class';
                        return null;
                    }
                    $out .= substr($re, $i, $end + 2 - $i);
                    $i = $end + 1;
                    continue;
                }
                if ($c === ']') {
                    $inClass = false;
                }
                $out .= $c;
                continue;
            }
            switch ($c) {
                case '[':
                    $inClass = true;
                    $out .= '[';
                    if ($i + 1 < $len && $re[$i + 1] === '^') {
                        $out .= '^';
                        $i++;
                    }
                    if ($i + 1 < $len && $re[$i + 1] === ']') {
                        $out .= '\\]';
                        $i++;
                    }
                    $classStart = $i;
                    continue 2;
                case '(':
                    if ($i + 1 < $len && $re[$i + 1] === '?') {
                        $rest = substr($re, $i + 2);
                        if (str_starts_with($rest, ':')) {
                            $out .= '(?:';
                            $i += 2;
                            continue 2;
                        }
                        if (preg_match('/^P?<([A-Za-z_][A-Za-z0-9_]*)>/', $rest, $m)) {
                            $out .= '(?P<' . $m[1] . '>';
                            $i += 1 + strlen($m[0]);
                            continue 2;
                        }
                        if (preg_match('/^([imsU]*(?:-[imsU]+)?)([:)])/', $rest, $m) && $m[1] !== '' && $m[1] !== '-') {
                            $out .= '(?' . $m[1] . $m[2];
                            $i += 1 + strlen($m[0]);
                            continue 2;
                        }
                        $err = 'lookahead/lookbehind, atomic or conditional groups are not supported';
                        return null;
                    }
                    $out .= '(';
                    continue 2;
                case '*':
                case '+':
                case '?':
                    if ($wasQuant && $c === '+') {
                        $err = 'possessive quantifiers are not supported';
                        return null;
                    }
                    $out .= $c;
                    $lastQuant = $c !== '?' || !$wasQuant;
                    continue 2;
                case '{':
                    if (preg_match('/^\{(\d+)(,(\d*))?\}/', substr($re, $i), $m)) {
                        if ((int)$m[1] > 1000 || (isset($m[3]) && $m[3] !== '' && (int)$m[3] > 1000)) {
                            $err = 'repeat count above 1000';
                            return null;
                        }
                        $out .= $m[0];
                        $i += strlen($m[0]) - 1;
                        $lastQuant = true;
                        continue 2;
                    }
                    $out .= '\\{';
                    continue 2;
                case '}':
                    $out .= '\\}';
                    continue 2;
                case ' ':
                    $out .= ' ';
                    continue 2;
            }
            $out .= $c;
        }
        if ($inClass) {
            $err = 'unterminated character class';
            return null;
        }
        if (str_contains($out, '{$')) {
            $err = 'the pattern contains "{$"';
            return null;
        }
        return $out;
    }

    /**
     * Splits a regex at top-level '|' and reports whether a '^' appears
     * anywhere but at the very start of a top-level branch.
     *
     * @return array{branches: list<string>, innerCaret: bool}
     */
    private static function scanRe(string $re): array
    {
        $branches = [];
        $cur = '';
        $depth = 0;
        $inClass = false;
        $inner = false;
        $len = strlen($re);
        for ($i = 0; $i < $len; $i++) {
            $c = $re[$i];
            if ($c === '\\') {
                $cur .= $c . ($re[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($inClass) {
                if ($c === ']') {
                    $inClass = false;
                }
                $cur .= $c;
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $cur .= $c;
                if (($re[$i + 1] ?? '') === '^') {
                    $cur .= '^';
                    $i++;
                }
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === '|' && $depth === 0) {
                $branches[] = $cur;
                $cur = '';
                continue;
            } elseif ($c === '^' && ($depth > 0 || $cur !== '')) {
                $inner = true;
            }
            $cur .= $c;
        }
        $branches[] = $cur;
        return ['branches' => $branches, 'innerCaret' => $inner];
    }

    /** Replaces every unescaped '^' outside character classes. */
    private static function replaceCarets(string $re, string $with): string
    {
        $out = '';
        $inClass = false;
        $len = strlen($re);
        for ($i = 0; $i < $len; $i++) {
            $c = $re[$i];
            if ($c === '\\') {
                $out .= $c . ($re[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($inClass) {
                $inClass = $c !== ']';
                $out .= $c;
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $out .= $c;
                if (($re[$i + 1] ?? '') === '^') {
                    $out .= '^';
                    $i++;
                }
                continue;
            }
            $out .= $c === '^' ? $with : $c;
        }
        return $out;
    }

    /**
     * A per-directory pattern (matched by Apache against the path below the
     * .htaccess folder, without a leading slash) as a regex for Caddy's
     * full {path}. '' = matches everything below the folder.
     */
    private static function perDir(string $re, string $prefix, bool $refsUsed, ?string &$err): ?string
    {
        $q = self::quoteRe($prefix);
        if (!$refsUsed && in_array($re, ['', '^', '.*', '^.*', '^.*$', '(.*)', '^(.*)', '^(.*)$', '.*$', '(.*)$'], true)) {
            return '';
        }
        if ($re === '.') {
            return '^' . $q . '.';
        }
        $info = self::scanRe($re);
        if (!$info['innerCaret']) {
            $parts = [];
            foreach ($info['branches'] as $b) {
                $parts[] = str_starts_with($b, '^') ? '^' . $q . substr($b, 1) : '^' . $q . '.*?(?:' . $b . ')';
            }
            return count($parts) === 1 ? $parts[0] : '(?:' . implode('|', $parts) . ')';
        }
        if ($refsUsed) {
            $err = 'a "^" inside the pattern combined with $N back-references is not supported';
            return null;
        }
        // '^' inside the pattern means "at the start of the folder-relative
        // path": try the pattern anchored there (^ = empty) or further in
        // (^ = never matches).
        return '^' . $q . '(?:' . self::replaceCarets($re, '') . ')|^' . $q . '.*?(?:' . self::replaceCarets($re, '\b\B') . ')';
    }

    private static function quoteRe(string $s): string
    {
        return preg_quote($s, null);
    }

    /** Number of capturing groups. */
    private static function groupCount(string $re): int
    {
        $n = 0;
        $inClass = false;
        $len = strlen($re);
        for ($i = 0; $i < $len; $i++) {
            $c = $re[$i];
            if ($c === '\\') {
                $i++;
                continue;
            }
            if ($inClass) {
                $inClass = $c !== ']';
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                if (($re[$i + 1] ?? '') === '^') {
                    $i++;
                }
                if (($re[$i + 1] ?? '') === ']') {
                    $i++;
                }
                continue;
            }
            if ($c === '(' && (($re[$i + 1] ?? '') !== '?' || preg_match('/^\(\?P?<[A-Za-z_]/', substr($re, $i)))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Where capturing group $n sits: 'start' (first thing in the pattern,
     * after an optional ^ or flags), 'slash' (its text starts with a literal
     * /), 'afterslash' (right after a literal /, /+ or /*), or null.
     */
    private static function groupContext(string $re, int $n): ?string
    {
        $count = 0;
        $len = strlen($re);
        $inClass = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $re[$i];
            if ($c === '\\') {
                $i++;
                continue;
            }
            if ($inClass) {
                $inClass = $c !== ']';
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                if (($re[$i + 1] ?? '') === '^') {
                    $i++;
                }
                if (($re[$i + 1] ?? '') === ']') {
                    $i++;
                }
                continue;
            }
            if ($c !== '(') {
                continue;
            }
            $named = preg_match('/^\(\?P?<[A-Za-z_][A-Za-z0-9_]*>/', substr($re, $i), $nm);
            if (($re[$i + 1] ?? '') === '?' && !$named) {
                continue;
            }
            if (++$count !== $n) {
                continue;
            }
            $before = substr($re, 0, $i);
            $inner = $named ? substr($re, $i + strlen($nm[0])) : substr($re, $i + 1);
            if (preg_match('/^(\(\?[imsU]+\))?\^?$/', $before)) {
                return 'start';
            }
            if (str_starts_with($inner, '/') || str_starts_with($inner, '\\/')) {
                return 'slash';
            }
            if (preg_match('#(/|\\/)[+*]?$#', $before)) {
                return 'afterslash';
            }
            return null;
        }
        return null;
    }

    /**
     * The literal texts an anchored pattern (^/...) can start with, one per
     * alternative of a group right after the literal part: "^/(a|b)/" ->
     * ["/a", "/b"]. null when the pattern isn't anchored at "/".
     *
     * @return list<string>|null
     */
    private static function literalPrefixes(string $re): ?array
    {
        if (!str_starts_with($re, '^/')) {
            return null;
        }
        $lit = static function (string $s): string {
            $out = '';
            $len = strlen($s);
            for ($i = 0; $i < $len; $i++) {
                $c = $s[$i];
                if ($c === '\\' && $i + 1 < $len && !ctype_alnum($s[$i + 1])) {
                    $out .= $s[++$i];
                    continue;
                }
                if (ctype_alnum($c) || in_array($c, ['/', '-', '_', '~'], true)) {
                    $out .= $c;
                    continue;
                }
                if (in_array($c, ['?', '*', '{'], true)) {
                    $out = substr($out, 0, -1);   // the previous character is optional
                }
                break;
            }
            return $out;
        };
        $head = $lit(substr($re, 1));
        // Find where the literal part ends in the raw pattern.
        $i = 1;
        $len = strlen($re);
        while ($i < $len) {
            $c = $re[$i];
            if ($c === '\\' && $i + 1 < $len && !ctype_alnum($re[$i + 1])) {
                $i += 2;
                continue;
            }
            if (ctype_alnum($c) || in_array($c, ['/', '-', '_', '~'], true)) {
                $i++;
                continue;
            }
            break;
        }
        if (($re[$i] ?? '') !== '(' || $head !== $lit(substr($re, 1, $i - 1))) {
            return [$head];
        }
        // Split the group at depth-1 '|'.
        $depth = 0;
        $alts = [];
        $cur = '';
        for ($j = $i; $j < $len; $j++) {
            $c = $re[$j];
            if ($c === '\\') {
                $cur .= $c . ($re[$j + 1] ?? '');
                $j++;
                continue;
            }
            if ($c === '(') {
                $depth++;
                if ($depth === 1) {
                    if (substr($re, $j, 3) === '(?:') {
                        $j += 2;
                    }
                    continue;
                }
            } elseif ($c === ')') {
                $depth--;
                if ($depth === 0) {
                    $alts[] = $cur;
                    $quant = $re[$j + 1] ?? '';
                    if (in_array($quant, ['?', '*', '{'], true)) {
                        return [$head];
                    }
                    break;
                }
            } elseif ($c === '|' && $depth === 1) {
                $alts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        return array_map(static fn ($a) => $head . $lit($a), $alts ?: ['']);
    }

    /** <Files> wildcard -> anchored regex on the file name. */
    private static function globToRe(string $glob): ?string
    {
        $out = '^';
        $len = strlen($glob);
        for ($i = 0; $i < $len; $i++) {
            $c = $glob[$i];
            if ($c === '*') {
                $out .= '[^/]*';
            } elseif ($c === '?') {
                $out .= '[^/]';
            } elseif ($c === '[') {
                $end = strpos($glob, ']', $i + 1);
                if ($end === false) {
                    return null;
                }
                $cls = substr($glob, $i + 1, $end - $i - 1);
                if (str_starts_with($cls, '!')) {
                    $cls = '^' . substr($cls, 1);
                }
                $out .= '[' . str_replace('\\', '\\\\', $cls) . ']';
                $i = $end;
            } else {
                $out .= preg_quote($c, null);
            }
        }
        return $out . '$';
    }

    /**
     * %{THE_REQUEST} ("GET /path?query HTTP/1.1") pattern -> a regex on
     * Caddy's {http.request.orig_uri} (the raw request URI). $methods gets the
     * method list when the pattern names methods; $offset the number of
     * capturing groups the method part had.
     */
    private static function theRequest(string $pat, ?array &$methods, int &$offset, ?string &$err): ?string
    {
        $methods = null;
        $offset = 0;
        $ws = '(?:\\\\s|\\\\ | |\[\\\\s\]|\[ \])[+*]?';
        if (preg_match('/^\^(?:(\[A-Z\](?:\{\d+,\d*\}|[+*]))|([A-Z]+)|\((\?:)?([A-Z]+(?:\|[A-Z]+)*)\))' . $ws . '/', $pat, $m)) {
            if (($m[2] ?? '') !== '') {
                $methods = [$m[2]];
            } elseif (($m[4] ?? '') !== '') {
                $methods = explode('|', $m[4]);
                $offset = ($m[3] ?? '') === '' ? 1 : 0;
            }
            $rest = substr($pat, strlen($m[0]));
        } elseif (preg_match('/^' . $ws . '/', $pat, $m)) {
            $rest = substr($pat, strlen($m[0]));
        } else {
            $err = 'it must start with the method or with \\s before the path';
            return null;
        }
        if (!str_starts_with($rest, '/')) {
            $err = 'the path part must start with /';
            return null;
        }
        // Walk the path part: whitespace at the top level ends the URI.
        $out = '^';
        $len = strlen($rest);
        $depth = 0;
        for ($i = 0; $i < $len; $i++) {
            $c = $rest[$i];
            $isWs = ($c === '\\' && in_array($rest[$i + 1] ?? '', ['s', ' '], true)) || $c === ' ';
            if ($isWs) {
                $i += $c === '\\' ? 1 : 0;
                if (in_array($rest[$i + 1] ?? '', ['+', '*'], true)) {
                    $i++;
                }
                if ($depth === 0) {
                    $tail = substr($rest, $i + 1);
                    if (!preg_match('#^(HTTP.*|\.\*|\.\+|)$#', $tail)) {
                        $err = 'unexpected text after the URI';
                        return null;
                    }
                    return $out . '$';
                }
                $out .= '$';
                continue;
            }
            if ($c === '\\') {
                $out .= $c . ($rest[$i + 1] ?? '');
                $i++;
                continue;
            }
            if ($c === '[') {
                $end = $i + 1;
                if (($rest[$end] ?? '') === '^') {
                    $end++;
                }
                if (($rest[$end] ?? '') === ']') {
                    $end++;
                }
                while ($end < $len && $rest[$end] !== ']') {
                    $end += $rest[$end] === '\\' ? 2 : 1;
                }
                if ($end >= $len) {
                    $err = 'unterminated character class';
                    return null;
                }
                $cls = substr($rest, $i + 1, $end - $i - 1);
                $negCls = str_starts_with($cls, '^');
                $body = $negCls ? substr($cls, 1) : $cls;
                $hadWs = str_contains($body, '\\s') || str_contains($body, '\\ ') || str_contains($body, ' ');
                $body = str_replace(['\\s', '\\ ', ' '], '', $body);
                if (!$hadWs) {
                    $out .= '[' . $cls . ']';
                } elseif ($negCls) {
                    $out .= $body === '' ? '.' : '[^' . $body . ']';
                } else {
                    $out .= $body === '' ? '$' : '(?:[' . $body . ']|$)';
                }
                $i = $end;
                continue;
            }
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            $out .= $c;
        }
        return $out;
    }

    /** "1.2.3.4 10.0.0.0/8" -> list, or null if anything isn't a full IP/CIDR. */
    private static function ipList(string $s): ?array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) as $ip) {
            $addr = explode('/', $ip, 2);
            if (filter_var($addr[0], FILTER_VALIDATE_IP) === false || (isset($addr[1]) && !ctype_digit($addr[1]))) {
                return null;
            }
            $out[] = $ip;
        }
        return $out ?: null;
    }

    // =====================================================================
    // Small output helpers
    // =====================================================================

    /**
     * Writes a Caddyfile token: as is when safe, else in "quotes" or
     * `backticks`. null when it can't be written safely.
     */
    private static function tok(string $s): ?string
    {
        if (str_contains($s, '{$') || str_contains($s, "\n") || str_contains($s, "\r")) {
            return null;
        }
        if ($s !== '' && !preg_match('/[\s"`]/', $s) && $s[0] !== '#' && !str_starts_with($s, '<<') && $s !== '{' && $s !== '}') {
            return $s;
        }
        if (!str_contains($s, '"') && !str_ends_with($s, '\\')) {
            return '"' . $s . '"';
        }
        if (!str_contains($s, '`')) {
            return '`' . $s . '`';
        }
        return null;
    }

    private static function matcherDef(string $name, array $lines): array
    {
        if (!$lines) {
            return [];
        }
        if (count($lines) === 1) {
            return ["@{$name} {$lines[0]}"];
        }
        return self::block("@{$name}", $lines);
    }

    private static function block(string $head, array $body): array
    {
        $out = [$head . ' {'];
        foreach ($body as $l) {
            $out[] = "\t" . $l;
        }
        $out[] = '}';
        return $out;
    }

    private static function render(array $lines): string
    {
        return implode("\n", $lines) . "\n";
    }

    private static function &scope(string $dir): array
    {
        if (!isset(self::$st['scopes'][$dir])) {
            self::$st['scopes'][$dir] = ['engine' => null, 'base' => null, 'rules' => [], 'hasRewrite' => false];
        }
        return self::$st['scopes'][$dir];
    }

    private static function note(string $file, int $line, string $directive, string $status, string $message, bool $review = false): void
    {
        if (strlen($directive) > 300) {
            $directive = substr($directive, 0, 297) . '...';
        }
        self::$st['notes'][] = ['file' => $file, 'line' => $line, 'directive' => $directive, 'status' => $status, 'message' => $message];
        if ($status === 'unsupported' || $review) {
            self::$st['review'] = true;
        }
    }

    // =====================================================================
    // Caddyfile validation
    // =====================================================================

    /**
     * A tokenizer that follows Caddy's lexer: words separated by whitespace,
     * "double quotes" (\" escapes a quote), `backticks` (raw), heredocs
     * (<<MARKER ... MARKER), # comments at the start of a word.
     *
     * @return list<array{text:string, line:int, quoted:bool}>
     */
    private static function caddyTokens(string $src, array &$errors): array
    {
        $tokens = [];
        $len = strlen($src);
        $line = 1;
        $i = 0;
        while ($i < $len) {
            $c = $src[$i];
            if ($c === "\n") {
                $tokens[] = ['text' => "\n", 'line' => $line, 'quoted' => false, 'nl' => true];
                $line++;
                $i++;
                continue;
            }
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '#') {
                while ($i < $len && $src[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            $start = $line;
            if ($c === '"') {
                $i++;
                $val = '';
                $closed = false;
                while ($i < $len) {
                    $ch = $src[$i];
                    if ($ch === '\\' && $i + 1 < $len && ($src[$i + 1] === '"' || $src[$i + 1] === "\n")) {
                        $val .= $src[$i + 1];
                        if ($src[$i + 1] === "\n") {
                            $line++;
                        }
                        $i += 2;
                        continue;
                    }
                    if ($ch === '"') {
                        $closed = true;
                        $i++;
                        break;
                    }
                    if ($ch === "\n") {
                        $line++;
                    }
                    $val .= $ch;
                    $i++;
                }
                if (!$closed) {
                    $errors[] = "line {$start}: unterminated quoted string";
                    return [];
                }
                $tokens[] = ['text' => $val, 'line' => $start, 'quoted' => true, 'nl' => false];
                continue;
            }
            if ($c === '`') {
                $end = strpos($src, '`', $i + 1);
                if ($end === false) {
                    $errors[] = "line {$start}: unterminated backtick string";
                    return [];
                }
                $val = substr($src, $i + 1, $end - $i - 1);
                $line += substr_count($val, "\n");
                $tokens[] = ['text' => $val, 'line' => $start, 'quoted' => true, 'nl' => false];
                $i = $end + 1;
                continue;
            }
            if ($c === '<' && substr($src, $i, 2) === '<<') {
                if (!preg_match('/\G<<([A-Za-z0-9_-]+)[ \t]*\n/', $src, $m, 0, $i)) {
                    $errors[] = "line {$start}: malformed heredoc";
                    return [];
                }
                $marker = $m[1];
                $i += strlen($m[0]);
                $line++;
                $val = [];
                $closed = false;
                while ($i < $len) {
                    $lineStart = $i;
                    $nl = strpos($src, "\n", $i);
                    $l = $nl === false ? substr($src, $i) : substr($src, $i, $nl - $i);
                    $i = $nl === false ? $len : $nl + 1;
                    // The closing marker starts its line; more tokens may follow it ("EOF 200").
                    if (preg_match('/^([ \t]*)' . preg_quote($marker, '/') . '(?=[ \t]|$)/', $l, $cm)) {
                        $closed = true;
                        $i = $lineStart + strlen($cm[0]);
                        break;
                    }
                    $val[] = $l;
                    $line++;
                }
                if (!$closed) {
                    $errors[] = "line {$start}: heredoc is never closed";
                    return [];
                }
                $tokens[] = ['text' => implode("\n", $val), 'line' => $start, 'quoted' => true, 'nl' => false];
                continue;
            }
            $val = '';
            while ($i < $len && !ctype_space($src[$i])) {
                $val .= $src[$i++];
            }
            if ($val === '\\' || (str_ends_with($val, '\\') && ($src[$i] ?? '') === "\n")) {
                $errors[] = "line {$start}: line continuations are not allowed";
                return [];
            }
            $tokens[] = ['text' => $val, 'line' => $start, 'quoted' => false, 'nl' => false];
        }
        return $tokens;
    }

    /**
     * Groups tokens into directives: ['name', 'args' => tokens, 'block' => ?children, 'line'].
     */
    private static function caddyTree(array $tokens, array &$errors): array
    {
        // Split into lines.
        $lines = [];
        $cur = [];
        foreach ($tokens as $t) {
            if ($t['nl']) {
                if ($cur) {
                    $lines[] = $cur;
                }
                $cur = [];
                continue;
            }
            $cur[] = $t;
        }
        if ($cur) {
            $lines[] = $cur;
        }
        $pos = 0;
        $tree = self::caddyBlock($lines, $pos, $errors, 0);
        if (!$errors && $pos < count($lines)) {
            $errors[] = 'line ' . $lines[$pos][0]['line'] . ': unexpected }';
        }
        return $tree;
    }

    private static function caddyBlock(array $lines, int &$pos, array &$errors, int $depth): array
    {
        $out = [];
        while ($pos < count($lines)) {
            $l = $lines[$pos];
            $first = $l[0];
            if (!$first['quoted'] && $first['text'] === '}') {
                if (count($l) > 1) {
                    $errors[] = "line {$first['line']}: a closing brace must be on its own line";
                }
                if ($depth === 0) {
                    $errors[] = "line {$first['line']}: unexpected }";
                    $pos++;
                    continue;
                }
                $pos++;
                return $out;
            }
            $pos++;
            $last = $l[count($l) - 1];
            $opens = !$last['quoted'] && $last['text'] === '{';
            $args = $opens ? array_slice($l, 0, -1) : $l;
            foreach ($args as $a) {
                if (!$a['quoted'] && ($a['text'] === '{' || $a['text'] === '}')) {
                    $errors[] = "line {$a['line']}: unexpected brace";
                }
            }
            if (!$args) {
                $errors[] = "line {$first['line']}: a block needs a directive or matcher name";
                $node = ['name' => '', 'args' => [], 'block' => null, 'line' => $first['line'], 'quoted' => false];
            } else {
                $node = ['name' => $args[0]['text'], 'quoted' => $args[0]['quoted'], 'args' => array_slice($args, 1), 'block' => null, 'line' => $first['line']];
            }
            if ($opens) {
                $node['block'] = self::caddyBlock($lines, $pos, $errors, $depth + 1);
            }
            $out[] = $node;
        }
        if ($depth > 0 && !in_array('missing } at the end of the file', $errors, true)) {
            $errors[] = 'missing } at the end of the file';
        }
        return $out;
    }

    /** Recursively checks directives against the allow-list for a context. */
    private static function checkBlock(array $nodes, string $ctx, string $docroot, array &$errors): void
    {
        $allowed = match ($ctx) {
            'route' => self::ROUTE_DIRECTIVES,
            'site' => self::SITE_DIRECTIVES,
            'errors' => self::ERROR_DIRECTIVES,
            default => [],
        };
        foreach ($nodes as $n) {
            $line = $n['line'];
            $name = $n['name'];
            if ($n['quoted']) {
                $errors[] = "line {$line}: a directive name can't be quoted";
                continue;
            }
            if (str_starts_with($name, '(')) {
                $errors[] = "line {$line}: snippets are not allowed";
                continue;
            }
            if (str_starts_with($name, '@')) {
                self::checkMatcherDef($n, $docroot, $errors);
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                $errors[] = "line {$line}: directive \"{$name}\" is not allowed here";
                continue;
            }
            // Inline matcher tokens are fine (paths, *, @name); args are checked by checkTokens.
            switch ($name) {
                case 'handle':
                case 'route':
                    if ($n['block'] === null) {
                        $errors[] = "line {$line}: {$name} needs a block";
                        break;
                    }
                    if (count($n['args']) > 1) {
                        $errors[] = "line {$line}: {$name} takes at most one matcher";
                    }
                    self::checkBlock($n['block'], $ctx === 'site' ? 'route' : ($ctx === 'errors' ? 'errors' : 'route'), $docroot, $errors);
                    break;
                case 'handle_errors':
                    foreach ($n['args'] as $a) {
                        if (!preg_match('/^[1-5](\d\d|xx)$/', $a['text'])) {
                            $errors[] = "line {$line}: handle_errors takes status codes";
                        }
                    }
                    if ($n['block'] === null) {
                        $errors[] = "line {$line}: handle_errors needs a block";
                        break;
                    }
                    self::checkBlock($n['block'], 'errors', $docroot, $errors);
                    break;
                case 'php_server':
                    self::checkSubdirectives($n, self::PHP_SERVER_SUBDIRECTIVES, $docroot, $errors);
                    break;
                case 'file_server':
                    foreach ($n['args'] as $a) {
                        if ($a['text'] === 'browse') {
                            $errors[] = "line {$line}: file_server browse is not allowed";
                        }
                    }
                    self::checkSubdirectives($n, self::FILE_SERVER_SUBDIRECTIVES, $docroot, $errors);
                    break;
                case 'try_files':
                    if ($n['block'] !== null) {
                        $errors[] = "line {$line}: try_files takes no block";
                    }
                    foreach ($n['args'] as $a) {
                        self::checkPath($a['text'], $line, $errors);
                    }
                    break;
                case 'vars':
                case 'map':
                    foreach (self::allTokens($n) as $t) {
                        if (preg_match('/^\{?(?:(?:http\.)?vars\.)?root\}?$/i', $t['text'])) {
                            $errors[] = "line {$t['line']}: {$name} must not set the site root";
                        }
                    }
                    break;
                case 'header':
                    if ($n['block'] !== null) {
                        foreach ($n['block'] as $sub) {
                            if ($sub['block'] !== null) {
                                $errors[] = "line {$sub['line']}: unexpected block in header";
                            }
                        }
                    }
                    break;
                case 'encode':
                    break;
                case 'respond':
                    if ($n['block'] !== null) {
                        foreach ($n['block'] as $sub) {
                            if (!in_array($sub['name'], ['body', 'close'], true) || $sub['block'] !== null) {
                                $errors[] = "line {$sub['line']}: unexpected respond option \"{$sub['name']}\"";
                            }
                        }
                    }
                    break;
                default:
                    if ($n['block'] !== null) {
                        $errors[] = "line {$line}: {$name} takes no block here";
                    }
            }
        }
    }

    private static function checkSubdirectives(array $n, array $allowed, string $docroot, array &$errors): void
    {
        if ($n['block'] === null) {
            return;
        }
        foreach ($n['block'] as $sub) {
            if (!in_array($sub['name'], $allowed, true) || $sub['block'] !== null) {
                $errors[] = "line {$sub['line']}: {$n['name']} option \"{$sub['name']}\" is not allowed";
                continue;
            }
            if ($sub['name'] === 'try_files') {
                foreach ($sub['args'] as $a) {
                    self::checkPath($a['text'], $sub['line'], $errors);
                }
            }
        }
    }

    private static function checkMatcherDef(array $n, string $docroot, array &$errors): void
    {
        $line = $n['line'];
        if (!preg_match('/^@[A-Za-z0-9_-]+$/', $n['name'])) {
            $errors[] = "line {$line}: invalid matcher name";
            return;
        }
        if (in_array($n['name'], self::RESERVED_MATCHERS, true)) {
            $errors[] = "line {$line}: matcher name {$n['name']} is reserved";
        }
        if ($n['block'] !== null && !$n['args']) {
            self::checkMatchers($n['block'], $docroot, $errors);
            return;
        }
        if (!$n['args']) {
            $errors[] = "line {$line}: empty matcher";
            return;
        }
        // "@name file {" = one matcher in block form.
        $inline = ['name' => $n['args'][0]['text'], 'quoted' => $n['args'][0]['quoted'], 'args' => array_slice($n['args'], 1), 'block' => $n['block'], 'line' => $line];
        if (!$n['args'][0]['quoted'] && preg_match('#^[/*]#', $n['args'][0]['text'])) {
            return;   // @name /path  (path shorthand)
        }
        self::checkMatchers([$inline], $docroot, $errors);
    }

    private static function checkMatchers(array $nodes, string $docroot, array &$errors): void
    {
        foreach ($nodes as $m) {
            $line = $m['line'];
            if ($m['quoted'] || !in_array($m['name'], self::ALLOWED_MATCHERS, true)) {
                $errors[] = "line {$line}: matcher \"{$m['name']}\" is not allowed";
                continue;
            }
            switch ($m['name']) {
                case 'not':
                    if ($m['block'] !== null) {
                        self::checkMatchers($m['block'], $docroot, $errors);
                    } elseif ($m['args']) {
                        self::checkMatchers([['name' => $m['args'][0]['text'], 'quoted' => $m['args'][0]['quoted'], 'args' => array_slice($m['args'], 1), 'block' => null, 'line' => $line]], $docroot, $errors);
                    }
                    break;
                case 'file':
                    foreach ($m['args'] as $a) {
                        self::checkPath($a['text'], $line, $errors);
                    }
                    foreach ($m['block'] ?? [] as $sub) {
                        if (!in_array($sub['name'], ['root', 'try_files', 'try_policy', 'split_path'], true) || $sub['block'] !== null) {
                            $errors[] = "line {$sub['line']}: file matcher option \"{$sub['name']}\" is not allowed";
                            continue;
                        }
                        if ($sub['name'] === 'root') {
                            $r = $sub['args'][0]['text'] ?? '';
                            $norm = rtrim($r, '/');
                            if ($docroot === '' || ($norm !== $docroot && !str_starts_with($norm, $docroot . '/')) || preg_match('#(^|/)\.\.(/|$)#', $r) || str_contains($r, '{')) {
                                $errors[] = "line {$sub['line']}: file root must be inside the site's document root";
                            }
                        }
                        if ($sub['name'] === 'try_files') {
                            foreach ($sub['args'] as $a) {
                                self::checkPath($a['text'], $sub['line'], $errors);
                            }
                        }
                    }
                    break;
                case 'expression':
                    foreach ($m['args'] as $a) {
                        if (preg_match('/\bfile\s*\(|\broot\b/', $a['text'])) {
                            $errors[] = "line {$line}: file()/root in expressions is not allowed";
                        }
                    }
                    break;
                default:
                    if ($m['block'] !== null) {
                        $errors[] = "line {$line}: matcher {$m['name']} takes no block";
                    }
            }
        }
    }

    private static function checkPath(string $p, int $line, array &$errors): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $p)) {
            $errors[] = "line {$line}: \"..\" is not allowed in paths";
        }
    }

    private static function allTokens(array $n): array
    {
        $out = $n['args'];
        foreach ($n['block'] ?? [] as $child) {
            $out[] = ['text' => $child['name'], 'line' => $child['line'], 'quoted' => $child['quoted']];
            $out = array_merge($out, self::allTokens($child));
        }
        return $out;
    }

    /** True when the last directive is php_server (possibly inside final matcher-less handle/route blocks). */
    private static function endsInPhpServer(array $nodes): bool
    {
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $n = $nodes[$i];
            if (str_starts_with($n['name'], '@')) {
                continue;
            }
            if ($n['name'] === 'php_server') {
                return true;
            }
            if (in_array($n['name'], ['handle', 'route'], true) && !$n['args'] && $n['block'] !== null) {
                return self::endsInPhpServer($n['block']);
            }
            return false;
        }
        return false;
    }
}
