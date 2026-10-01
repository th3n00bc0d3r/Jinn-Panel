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

- [x] **Let's Encrypt by default.** New domains and migrations default to
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
- [x] **Restore mail from backup** for an already-migrated account: WHM >
      Migration > "Restore mail" (per account, or "Restore missing
      mailboxes" for all). Re-reads the backup, extracts only the mail,
      creates missing mailboxes; never touches or rolls back the account.
      Also fixed: Dovecot gzip/bzip2/xz/zstd-compressed messages were
      rejected as invalid.
- [x] **Default account inbox** (`~/mail`, the cPanel user's own mailbox:
      system mail and catch-all deliveries) is migrated to
      `<user>@<main domain>` with the cPanel account password, when it holds
      mail. 9 of 13 accounts had some (538 messages).
- [x] **Catch-all addresses.** Most domains had cPanel's default
      `*: <user>` (unknown addresses -> default mailbox); not recreated, so
      that mail is now rejected. Add a per-domain "default address" setting
      (reject / deliver to a mailbox / forward) in cPanel > Email, and
      offer to migrate it.
- [x] **Mail DNS for local mail.** Domains whose mail stays on this server get
      only the panel's MX. The zone template should also publish:
  - `mail.<domain>` when it's missing: A/AAAA to the server, or a CNAME to
    the server hostname (and the MX can then point at it);
  - SPF (`v=spf1 mx a ~all`, plus the server's IPv4/IPv6);
  - DKIM: Stalwart's key for the domain (`<selector>._domainkey`), created
    with the mail domain;
  - DMARC (`_dmarc`, start at `p=none` with a report address);
  - autoconfig/autodiscover so mail clients set themselves up:
    `autoconfig.<domain>` / `autodiscover.<domain>` (CNAME to the server)
    and SRV records `_imaps._tcp`, `_submission._tcp`, `_autodiscover._tcp`.
- [x] **Webmail at `mail.<domain>`.** When `mail.<domain>` points at this
      server, serve a web mail client there so users can read mail in a
      browser: [Cypht](https://github.com/cypht-org/cypht) (PHP, runs on
      FrankenPHP), installed once and shared by every domain, pre-set to
      Stalwart's IMAP/SMTP on localhost. Needs: a Caddy site per
      `mail.<domain>` (with its TLS cert), the login prefilled with the
      domain, and a "Webmail" link in cPanel > Email.
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
- [x] **No www for the server zone.** `www.<server zone>` has no DNS record,
      so its certificate can't be issued.
- [x] **IPv6.** Zones get AAAA records next to every A record pointing here
      (managed='ipv6'). IPv6 rDNS is set at the provider (owner, pending) -
      until then, outbound mail over IPv6 may be rejected by big providers.
- [x] **Custom document root.** Laravel-style apps serve from `public/`
      (handled with a site rule for now); let users set the docroot per
      domain.
- [ ] **cPanel PHP settings.** `php.ini` / `.user.ini` from MultiPHP INI
      Editor aren't applied, and config files still reference
      `/home/<user>/` (listed in the migration report) - rewrite those paths
      or map them.
- [ ] **Not migrated at all:** cron jobs, FTP accounts, parked domains.
      (Email forwarders, autoresponders and default addresses: done.)
- [ ] **Exposed leftovers.** Migrated docroots contain archives and data that
      were public on cPanel too (site `.zip` archives, `orders.json`,
      `_backups/` folders, `error_log`). Flag files like these after a
      migration.
- [ ] **PHP extensions** were missing (only PDO): fixed in `install.sh`, but
      there's no per-site way to enable more - add a WHM page listing
      available `php-zts-*` extensions.

- [x] **Customer panel URL.** `<domain>/jpanel` redirects to
      `https://<domain>:2083`, where every hosted domain serves the panel
      with its own certificate - a separate origin from the site. Site
      blocks strip the panel session cookie (cookies aren't per port).
- [x] **Show it to customers:** "<domain>/jpanel" in cPanel > Domains and
      the domain page; WHM and /setup only on the panel hostname (a customer
      domain's :2083 redirects there). (There is no welcome email yet.)

- [x] **SSL section per domain** (cPanel > Domains > *domain*): certificate
      details and a "Run AutoSSL" button that re-checks DNS and asks Caddy
      to issue/renew now - for an expired certificate, one stuck retrying,
      or right after DNS was fixed.
- [x] **Mail client settings card** in cPanel > Email: IMAP/POP3/SMTP host,
      ports, security and username, for setting up a mail app by hand.
- [ ] **File manager:** select multiple files/folders (bulk delete, move,
      copy, download), extract `.zip` (and `.tar.gz`) archives, compress the
      selection into a `.zip`, and view/change permissions (chmod) of files
      and folders - all confined to the account's own directories.
- [ ] **Zone editor for customers** in the jpanel (cPanel > DNS): add,
      edit and delete records of their own domains' zones, with the same
      validation as WHM > DNS Zones; records the panel manages (mail, IPv6)
      shown read-only.

## 3. Caching

- [ ] **Cache feature.** Nothing is cached today beyond PHP's OPcache
      defaults: no page cache, no object cache, no static-asset
      `Cache-Control` headers. Scope to decide:
  - per-site page cache in Caddy (the Souin/`cache-handler` module - not in
    the stock FrankenPHP binary, needs an xcaddy build), on/off and TTL per domain in cPanel > Domains, with a
    "Purge cache" button and rules that skip logged-in/cart cookies;
  - Redis/Valkey for object caching (WordPress, Laravel) - one instance with
    a per-account ACL user and memory limit, shown in cPanel;
  - OPcache settings per PHP version in WHM (memory, revalidate frequency);
  - long `Cache-Control` headers for static assets by default.
- [ ] **Clear cache per domain:** a "Clear cache" button for each domain in
      cPanel > Domains (page cache for that host, plus the domain's object
      cache keys / OPcache for its docroot).

## 4. Look and feel

- [ ] **Light animations, a more futuristic panel:** subtle transitions
      (page fade-in, card hover lift, progress bars, number counters,
      gradient/glow accents), respecting `prefers-reduced-motion`.
