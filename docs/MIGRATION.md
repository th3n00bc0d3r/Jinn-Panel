# cPanel migration

WHM > **cPanel Migration** moves accounts from an existing cPanel & WHM
server to JinnPanel: site files, databases, email accounts and stored mail,
in one click per batch of accounts. The old server is only read from; it
is never changed, apart from the backup file cPanel writes into each
account's home directory in pull mode.

## What you can migrate from

| Source login | Who can use it | What it can migrate |
|---|---|---|
| **Single cPanel account** (port 2083) | admin, reseller | that one account |
| **WHM reseller** (port 2087) | admin, reseller | any or all accounts owned by that reseller |
| **WHM root** (port 2087) | admin only | any accounts on the server, including resellers and their customers |

Everything a JinnPanel reseller migrates is owned by that reseller.

## Getting credentials

An **API token** is recommended: it works even when the login uses
two-factor authentication, and can be revoked the moment the move is done.

- **Single account:** cPanel > Security > Manage API Tokens > Create.
  Connect with the cPanel username + token.
- **Reseller or root:** WHM > Development > Manage API Tokens > Generate
  Token. The token needs access to list the accounts, run functions as
  them (`uapi_cpanel`) and create login sessions for them
  (`create_user_session`) - the simplest is a token with all privileges
  for the duration of the move. Connect with the reseller's username (or
  `root`) + token.

Plain passwords also work (HTTP Basic auth).

The token/password is stored AES-256-GCM encrypted with `Config::APP_KEY`
while the migration needs it, deleted automatically as soon as nothing is
left to retry, and never kept more than 7 days after the migration
finishes. "Forget credentials" on a migration deletes it immediately.

## The flow

1. **Connect** - source type, server, username, token/password. The panel
   logs in and lists the accounts that login can migrate. Nothing is copied
   yet.
2. **Choose** - tick accounts. Accounts whose username or main domain
   already exists on this server are shown but can't be selected. Choose
   what to migrate (files, databases, email accounts, stored mail), which
   package migrated accounts get (by default the package with the same name
   as their cPanel plan), who owns them, and whether email passwords are
   kept or regenerated.
3. **Start** - one click. Accounts are processed one after another by a
   background runner; the page shows live progress and the runner's log,
   and you can leave it and come back. When an account finishes, its
   report appears: every domain, database, MySQL user and mailbox with its
   result, any generated passwords, and things to check.

Failed or cancelled accounts can be retried from the same page; anything a
failed attempt half-created is rolled back first.

## Transfer modes

Each account is moved as a cPanel **full backup** (cPanel's own `pkgacct`
format), created on the source with UAPI `Backup::fullbackup_to_*`.

- **Pull** (default) - the source writes the backup into the account's
  home directory and this server downloads it over an authenticated cPanel
  session (WHM `create_user_session`, or a password login for a single
  account). Only needs this server to reach the source on 2083/2087.
  The backup file stays in the source account's home directory afterwards
  (the report says where) - delete it there once you've checked the
  migration.
- **Push** - the source uploads the backup over SCP straight to this
  server, into a temporary SFTPGo user (random password, single use,
  deleted right after). Needs the **source** to reach this server on port
  2022. This is the only option for a single cPanel account connected with
  an API token, since cPanel tokens can't download files.

"Automatic" picks pull, except in that one token-only case.

### From backup files (no source server)

If the old server is gone but you have its cPanel full backups (cPanel's
scheduled backups, `cpmove-<user>.tar.gz`, or `backup-<date>_<user>.tar.gz`,
for example copied back from offsite storage), restore them directly:

1. Copy the archives into `/var/lib/jinnpanel/migrations/import/`, named
   `<user>.tar.gz` (as cPanel's scheduled backups name them),
   `cpmove-<user>.tar.gz` or `backup-<date>_<user>.tar.gz`, and make them
   readable by frankenphp:
   `chown frankenphp:webusers /var/lib/jinnpanel/migrations/import/*`
2. Check what's there, then queue the accounts (as an admin):

   ```
   cd /var/www/hostpanel
   runuser -u frankenphp -- php worker/migration-import.php --list
   runuser -u frankenphp -- php worker/migration-import.php <admin-username> <user> [<user> ...]
   ```

The restore is the same as for the other modes, and progress, the report and
Retry appear under WHM > cPanel Migration. The archives are left in place;
delete them once you've checked the result.

## What is migrated, and how

- **Account** - same username, same cPanel password (the original SHA-512
  crypt hash is kept and upgraded to bcrypt on first login). Contact email
  from the backup. If the username isn't valid in JinnPanel (3-32
  characters, `a-z 0-9 _`, starting with a letter) the account can't be
  migrated.
- **Resellers** (WHM root, "Keep the cPanel reseller structure") - a cPanel
  reseller is both a WHM login and a hosting account. JinnPanel keeps those
  separate: the hosting account keeps the original username, and a
  JinnPanel reseller login `<name>_whm` (same password) is created that
  owns it and the reseller's migrated customers. Select the reseller
  accounts along with their customers; resellers are processed first.
- **Domains** - main, addon and subdomains each become a JinnPanel domain
  with its own vhost, DNS zone and document root
  (`/var/www/<domain>/public`), with that domain's files copied from its
  cPanel document root. Parked (alias) domains are not migrated yet and are
  listed in the report.
- **Databases** - same database names (they're prefixed with the cPanel
  username, which is kept, so site config files keep working). Dumps are
  imported with the few things a MariaDB server can't run from a MySQL 8
  dump rewritten: `DEFINER` clauses, GTID/binlog statements, and
  `utf8mb4_0900_*` collations. Views, triggers and stored procedures need
  privileges JinnPanel doesn't grant customer databases, so they're
  reported rather than imported.
- **MySQL users** - same names, same `mysql_native_password` hashes, access
  to the same databases. Users whose password uses MySQL 8's
  `caching_sha2_password` can't be recreated with that hash on MariaDB:
  they get a new password, shown in the report - update the site's config
  with it.
- **Email accounts** - same addresses, same password hashes (Stalwart
  verifies crypt-style hashes natively). If the mail server rejects a hash,
  or you chose "Generate new passwords", a new password is set and shown
  in the report.
- **Stored mail** - every folder of the Maildir, with read / answered /
  flagged / draft state and original received dates, imported over JMAP
  while logged in as the mailbox with a temporary credential that is
  removed straight afterwards. Sent / Drafts / Trash / Junk / Archive map
  onto the mailbox's own special folders. Messages marked deleted but not
  yet expunged are skipped.

## Not migrated

Email forwarders and autoresponders, cron jobs, custom DNS records, SSL
certificates (AutoSSL issues new ones once DNS points here), FTP accounts,
and parked domains. The report also flags `.htaccess` files (FrankenPHP
doesn't read them - pretty-URL routing to `index.php` works automatically,
but custom redirects, deny rules and password protection need re-creating)
and config files that still contain the old `/home/<user>/` path.

Nothing is live until DNS for each domain points at this server.

## How it runs

The web request only records what should happen. It queues a
`migration_start` job; `hostpanel-worker.php` (root, every 5 seconds)
launches `app/worker/migration-runner.php` as a transient systemd unit:

```
systemd-run --unit=jinnpanel-migration-<id> --uid=frankenphp --gid=webusers ...
```

- It runs outside FrankenPHP, so hours-long transfers aren't cut off by
  request limits.
- It runs as `frankenphp:webusers` - the same identity the panel already
  uses for vhosts, databases and mailboxes - never as root, because
  everything it unpacks came from another server.
- Scratch space is `/var/lib/jinnpanel/migrations` (`Config::MIGRATION_DIR`);
  each account's work directory is deleted as soon as it's done. It needs
  free space of roughly three times the account's backup size.

Follow a migration from the server with:

```
journalctl -fu jinnpanel-migration-<id>
tail -f /var/www/hostpanel/storage/logs/migration-<id>.log
```

A migration whose runner hasn't reported progress for 10 minutes is shown
as **Stalled**: cancel it (this also stops the unit) and retry.

## Safety

Everything in a backup is treated as untrusted:

- The archive is extracted by an unprivileged user with GNU tar, which
  refuses absolute and `..` member names.
- Every domain, mailbox and document-root path is validated and resolved,
  and must stay inside the backup; files are copied with
  `rsync --safe-links`, so symlinks pointing outside the site are dropped.
- Only databases and MySQL users named `<username>` or `<username>_*` are
  ever touched, so a crafted backup can't reach the panel's own database,
  `mysql`, or another customer's data. Existing databases, MySQL users,
  accounts and domains are never overwritten.
- Admins may migrate from a private-network address; resellers only from
  public ones, and nobody can point the migration at this server itself.

## Requirements

Re-run `install.sh` after updating (it's safe to re-run): it adds the new
database tables, deploys the updated background worker to
`/usr/local/bin`, installs `rsync`, creates the work directory, generates
`APP_KEY`, and checks that the PHP command-line binary has the `curl`,
`openssl`, `pdo_mysql` and `mbstring` extensions the runner needs. On an
existing install without re-running it, apply
`app/migrations/003_cpanel_migration.sql` by hand.
