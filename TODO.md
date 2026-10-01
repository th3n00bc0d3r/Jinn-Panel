# JinnPanel - TODO

Work items found while migrating a real cPanel server (13 accounts, 36
domains) onto JinnPanel. CONTEXT.md has the architecture; the security
review there ("Production readiness") still applies and is not repeated here.

## 1. cPanel > Routes (.htaccess) editor

FrankenPHP/Caddy ignores `.htaccess`. Today the rules are translated by hand
into per-site Caddy files that `VhostService` imports:

- `/var/lib/frankenphp/site-rules/<domain>.caddy` - inside the site's
  `route { }` block (rewrites, redirects, denied paths); must end in
  `php_server`.
- `/var/lib/frankenphp/site-rules/<domain>.site.caddy` - site level
  (`header`, `handle_errors` for ErrorDocument).

Build a section in the user panel (cPanel > Domains > *domain* > Routes) that
does this per domain:

- [ ] Read every `.htaccess` under the domain's docroot (root and subfolders)
      and show it next to the generated Caddy rules.
- [ ] Translator for the common subset, flagging anything it can't translate
      instead of guessing:
  - `RewriteRule ^pat$ target [L,QSA]` -> `path_regexp` + `rewrite`
    (`$n` -> `{re.<name>.n}`, QSA -> `&{query}`); `[R=301]` -> `redir`;
    `[F]` -> `respond 403`; `-` with `-f`/`-d` conditions -> `file` matcher
    in a `handle` (serve existing files first, then the rewrite list).
  - `RewriteCond %{REQUEST_FILENAME} !-f/!-d` -> `not file {path} {path}/`;
    `%{REQUEST_FILENAME}.php -f` -> `file {path}.php`;
    `%{HTTP_HOST}` -> `host`; `%{QUERY_STRING}` -> `vars_regexp {query}`;
    `%{REQUEST_METHOD}` -> `method`; `%{THE_REQUEST}` -> evaluate the
    redirect before any rewrite in the same route.
  - `Require all denied` / `Deny from all` in a subfolder, `RedirectMatch 404`,
    `<FilesMatch>` deny -> `respond`/`error` on a path matcher.
  - `ErrorDocument 404 /x` -> `handle_errors 404 { rewrite * /x ... }`.
  - `Header set/always set` (incl. inside `<FilesMatch>`) -> `header`.
  - Ignore with a note: `Options -Indexes` (Caddy never lists directories),
    `mod_deflate`/`mod_expires` (use `encode`/`Cache-Control`), `AddType`,
    LiteSpeed cache, cPanel `php_value` blocks.
- [ ] Let the user edit the generated rules, then validate before saving:
      the root worker writes the files and runs
      `frankenphp validate --config /etc/frankenphp/Caddyfile`; only reload on
      success, otherwise restore the previous files and show the error.
- [ ] The web process must not write the rules directly (same reason as zone
      files and vhosts: a job through `SystemWorkerService`), and rules must be
      restricted so one customer can't add directives affecting another site
      (e.g. no `import`, `root` outside the docroot, `reverse_proxy`).
- [ ] Run the translator automatically during cPanel migration, mark the
      domain "routes need review" when anything was skipped, and drop the
      generic ".htaccess files found" warning.
- [ ] Alt PHP versions: the rules are only imported for `php_version = default`
      sites; alt-version sites need the rules in their instance fragment.

## 2. Manual fixes still needed after adding/migrating an account

Each of these had to be done by hand for the migrated accounts; make the panel
do them.

- [ ] **Let's Encrypt by default.** New domains and migrations default to
      `self_signed` (browser warning). The migrated domains were switched with
      `worker/vhost-rebuild.php letsencrypt`. Default to Let's Encrypt when the
      domain resolves to this server, fall back to self-signed otherwise, and
      show certificate status per domain (ACME failures are only in the
      FrankenPHP journal today: domains pointing elsewhere retry forever).
- [x] **Mailboxes fail to migrate.** Stalwart 0.16 rejects `credentials` in
      an Account create and allows only one password per account, so every
      mailbox create failed (cPanel > Email too). Fixed: create bare, set the
      password with a `credentials/0` patch; migration imports with a temp
      password, then swaps in the cPanel hash.
- [ ] **Restore mail from backup** for an already-migrated account, without
      redoing the whole account - needed to bring back the 26 mailboxes
      (and their stored mail) that failed in the first migration.
- [ ] **Default account inbox** (`~/mail/cur|new`, the cPanel user's own
      mailbox) isn't migrated or reported.
- [ ] **Mail DNS for local mail.** Domains whose mail stays on this server get
      only the panel's MX: publish SPF, Stalwart's DKIM key and a DMARC record.
- [ ] **Imports from offsite backups need a UI.** File-mode migration
      (`worker/migration-import.php`) is CLI-only; the archives were fetched
      from S3 by hand. Add WHM > cPanel Migration > "From backup files", and
      optionally an S3 source (bucket, prefix, credentials stored encrypted
      like `secret_enc`).
- [ ] **DNS records during migration** are now imported
      (`CpanelZoneImporter`), but only proven via
      `worker/dns-import-cpanel.php` on existing zones - verify on the next real
      migration.
- [ ] **Subdomain sites get their own zone** (e.g. `admin.example.com`)
      while the parent zone also holds their records. Create subdomain sites as records in the parent zone instead.
- [ ] **No www for the server zone.** `www.<server zone>` has no DNS record,
      so its certificate can't be issued.
- [ ] **IPv6.** The server has IPv6 but zones get no AAAA records and the
      installer doesn't configure IPv6 rDNS.
- [ ] **Custom document root.** Laravel-style apps serve from `public/`
      (handled with a site rule for now); let users set the docroot per
      domain.
- [ ] **cPanel PHP settings.** `php.ini` / `.user.ini` from MultiPHP INI
      Editor aren't applied, and config files still reference
      `/home/<user>/` (listed in the migration report) - rewrite those paths
      or map them.
- [ ] **Not migrated at all:** cron jobs, email forwarders/autoresponders,
      FTP accounts, parked domains.
- [ ] **Exposed leftovers.** Migrated docroots contain archives and data that
      were public on cPanel too (site `.zip` archives, `orders.json`,
      `_backups/` folders, `error_log`). Flag files like these after a
      migration.
- [ ] **PHP extensions** were missing (only PDO): fixed in `install.sh`, but
      there's no per-site way to enable more - add a WHM page listing
      available `php-zts-*` extensions.
