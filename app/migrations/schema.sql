-- JinnPanel schema. Run against the `hostpanel` database.

CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NULL COMMENT 'reseller id this package belongs to, NULL = global (admin-created)',
    name VARCHAR(64) NOT NULL,
    disk_quota_mb INT NOT NULL DEFAULT 1024,
    bandwidth_mb INT NOT NULL DEFAULT 10240,
    max_domains INT NOT NULL DEFAULT 1,
    max_databases INT NOT NULL DEFAULT 1,
    max_email_accounts INT NOT NULL DEFAULT 5,
    max_ftp_accounts INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NULL,
    role ENUM('admin','reseller','user') NOT NULL DEFAULT 'user',
    parent_id INT NULL COMMENT 'the reseller who created this user; NULL for admin/reseller',
    package_id INT NULL,
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_parent FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_package FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- packages <-> users is circular (packages.owner_id -> users.id, but
-- users.package_id -> packages.id), so this FK can only be added after both
-- tables exist. MariaDB has no `ADD CONSTRAINT IF NOT EXISTS`, so this is
-- the standard conditional-DDL workaround - needed for install.sh to be
-- safely re-runnable against a database that already has this constraint.
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND CONSTRAINT_NAME = 'fk_packages_owner'
);
SET @add_fk_sql = IF(@fk_exists = 0,
    'ALTER TABLE packages ADD CONSTRAINT fk_packages_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE add_fk_stmt FROM @add_fk_sql;
EXECUTE add_fk_stmt;
DEALLOCATE PREPARE add_fk_stmt;

CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain_name VARCHAR(190) NOT NULL UNIQUE,
    docroot VARCHAR(255) NOT NULL,
    mail_domain_id VARCHAR(64) NULL COMMENT 'Stalwart x:Domain object id, if mail was provisioned',
    dns_provisioned TINYINT(1) NOT NULL DEFAULT 0,
    php_version VARCHAR(10) NOT NULL DEFAULT 'default' COMMENT '"default" = the main FrankenPHP instance; else an installed alt version like "8.2"',
    php_port INT NULL COMMENT 'loopback port of the alt-version FrankenPHP instance, NULL when php_version=default',
    ssl_mode ENUM('self_signed','letsencrypt') NOT NULL DEFAULT 'self_signed',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_domains_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS php_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(10) NOT NULL UNIQUE COMMENT 'e.g. 8.2, 8.3, 8.4',
    port INT NOT NULL UNIQUE COMMENT 'loopback port its dedicated FrankenPHP instance listens on',
    status ENUM('installing','active','failed','removing') NOT NULL DEFAULT 'installing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS db_instances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    db_name VARCHAR(64) NOT NULL UNIQUE,
    db_user VARCHAR(64) NOT NULL COMMENT 'not unique: a migrated cPanel user may own several databases',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_db_user (db_user),
    CONSTRAINT fk_dbinst_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain_id INT NOT NULL,
    local_part VARCHAR(64) NOT NULL,
    mail_account_id VARCHAR(64) NULL COMMENT 'Stalwart x:Account object id',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mailbox (domain_id, local_part),
    CONSTRAINT fk_email_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ftp_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain_id INT NULL,
    username VARCHAR(64) NOT NULL UNIQUE,
    home_dir VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ftp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ftp_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    action VARCHAR(64) NOT NULL,
    detail VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- cPanel/WHM migrations (WHM > cPanel Migration)
-- ---------------------------------------------------------------------------

-- One row per "connect to a source server" run. secret_enc holds the
-- source API token/password, AES-256-GCM encrypted with Config::APP_KEY;
-- it's wiped as soon as nothing is left to retry and never kept longer
-- than 7 days after the migration finishes.
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NOT NULL,
    source_type ENUM('cpanel','reseller','root') NOT NULL,
    source_host VARCHAR(190) NOT NULL,
    source_port INT NOT NULL,
    source_user VARCHAR(64) NOT NULL,
    auth_type ENUM('token','password') NOT NULL DEFAULT 'token',
    secret_enc TEXT NULL,
    verify_tls TINYINT(1) NOT NULL DEFAULT 1,
    transfer_mode ENUM('pull','push','file') NOT NULL DEFAULT 'pull',
    options TEXT NULL COMMENT 'JSON - see MigrationService::defaultOptions()',
    status VARCHAR(32) NOT NULL DEFAULT 'draft' COMMENT 'draft|queued|running|completed|completed_with_errors|failed|cancelled',
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    finished_at DATETIME NULL,
    KEY idx_migrations_creator (created_by),
    CONSTRAINT fk_migrations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per source cPanel account offered by / selected in a migration.
CREATE TABLE IF NOT EXISTS migration_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_id INT NOT NULL,
    source_username VARCHAR(64) NOT NULL,
    source_domain VARCHAR(190) NULL,
    source_owner VARCHAR(64) NULL COMMENT 'owning cPanel reseller (WHM sources)',
    source_plan VARCHAR(128) NULL,
    is_reseller TINYINT(1) NOT NULL DEFAULT 0,
    selected TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|backing_up|transferring|restoring|completed|completed_with_errors|failed|cancelled',
    step VARCHAR(255) NULL,
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    report MEDIUMTEXT NULL COMMENT 'JSON - per-domain/database/mailbox results',
    error TEXT NULL,
    target_user_id INT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    UNIQUE KEY uniq_migration_account (migration_id, source_username),
    CONSTRAINT fk_mitems_migration FOREIGN KEY (migration_id) REFERENCES migrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_mitems_target FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MySQL users an account owns beyond the one-per-database pairs in
-- db_instances. cPanel lets one user access several databases (and one
-- database have several users), so migrated accounts need both: deleting
-- the account drops these too.
CREATE TABLE IF NOT EXISTS db_user_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    db_user VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_db_user_account (db_user),
    CONSTRAINT fk_dbuseracct_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Databases created before this change still have the old UNIQUE index on
-- db_instances.db_user; turn it into the plain idx_db_user index above.
-- Conditional-DDL again so re-running install.sh stays safe.
SET @dbuser_unique = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'db_instances' AND INDEX_NAME = 'db_user' AND NON_UNIQUE = 0
);
SET @dbuser_sql = IF(@dbuser_unique > 0,
    'ALTER TABLE db_instances DROP INDEX db_user, ADD INDEX idx_db_user (db_user)',
    'SELECT 1'
);
PREPARE dbuser_stmt FROM @dbuser_sql;
EXECUTE dbuser_stmt;
DEALLOCATE PREPARE dbuser_stmt;

-- Seed data: default packages only. No admin account is seeded here on
-- purpose - the first visit to the panel shows a setup wizard (/setup)
-- that creates the real administrator account interactively. Baking a
-- fixed username/password into a script or repo is exactly the kind of
-- thing this is meant to avoid.
-- Only into an empty table (a fresh install): this file runs on every
-- install.sh, and packages has no unique key for ON DUPLICATE KEY to hit.
INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts)
SELECT * FROM (
    SELECT NULL AS owner_id, 'Starter' AS name, 1024 AS disk, 10240 AS bw, 1 AS d, 1 AS db, 5 AS em, 1 AS ftp
    UNION ALL SELECT NULL, 'Business', 5120, 51200, 5, 5, 25, 3
    UNION ALL SELECT NULL, 'Reseller', 20480, 204800, 50, 50, 250, 10
) seed
WHERE NOT EXISTS (SELECT 1 FROM packages);

-- DNS zones and records live in the panel DB; Knot zone files are rendered
-- from these rows by DnsService and written by hostpanel-worker.php.
-- Also appended to schema.sql (CREATE ... IF NOT EXISTS), so install.sh
-- applies it on fresh installs and re-runs alike.

CREATE TABLE IF NOT EXISTS panel_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS dns_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_name VARCHAR(190) NOT NULL UNIQUE COMMENT 'matches domains.domain_name for hosted domains',
    is_server_zone TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the zone holding ns1/ns2, the hostname and the panel',
    serial INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS dns_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    name VARCHAR(200) NOT NULL DEFAULT '@' COMMENT 'relative to the zone; @ = apex',
    type VARCHAR(10) NOT NULL,
    ttl INT UNSIGNED NOT NULL DEFAULT 3600,
    priority SMALLINT UNSIGNED NULL COMMENT 'MX / SRV only',
    content VARCHAR(2048) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dns_records_zone_name (zone_id, name),
    CONSTRAINT fk_dns_records_zone FOREIGN KEY (zone_id) REFERENCES dns_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- These tables were first shipped without an explicit charset, so they took
-- the database default (utf8mb4_unicode_ci) while every other panel table is
-- utf8mb4_general_ci; joining dns_zones.zone_name to domains.domain_name then
-- failed with "Illegal mix of collations". Convert them on installs that
-- already created them. Conditional-DDL so re-running install.sh stays safe.
SET @coll_sql = IF(
    (SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'panel_settings') <> 'utf8mb4_general_ci',
    'ALTER TABLE panel_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'SELECT 1'
);
PREPARE coll_stmt FROM @coll_sql;
EXECUTE coll_stmt;
DEALLOCATE PREPARE coll_stmt;
SET @coll_sql = IF(
    (SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dns_zones') <> 'utf8mb4_general_ci',
    'ALTER TABLE dns_zones CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'SELECT 1'
);
PREPARE coll_stmt FROM @coll_sql;
EXECUTE coll_stmt;
DEALLOCATE PREPARE coll_stmt;
SET @coll_sql = IF(
    (SELECT TABLE_COLLATION FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dns_records') <> 'utf8mb4_general_ci',
    'ALTER TABLE dns_records CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'SELECT 1'
);
PREPARE coll_stmt FROM @coll_sql;
EXECUTE coll_stmt;
DEALLOCATE PREPARE coll_stmt;

-- `file` transfer mode: restore cPanel backups already on this server
-- (MIGRATION_DIR/import) instead of fetching them from a source server.
-- Also appended to schema.sql; conditional DDL so re-running install.sh stays safe.
SET @tm_sql = IF(
    (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND COLUMN_NAME = 'transfer_mode') NOT LIKE '%''file''%',
    'ALTER TABLE migrations MODIFY transfer_mode ENUM(''pull'',''push'',''file'') NOT NULL DEFAULT ''pull''',
    'SELECT 1'
);
PREPARE tm_stmt FROM @tm_sql;
EXECUTE tm_stmt;
DEALLOCATE PREPARE tm_stmt;

-- "Restore mail" on a finished migration item: re-reads the account's backup
-- and creates only the mailboxes that are missing. A separate flag, not a
-- status, so the item stays completed and Cancel/Retry never treat the
-- account as a half-done restore (which they would roll back).
-- Also appended to schema.sql; conditional DDL so re-running install.sh stays safe.
SET @mr_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_items' AND COLUMN_NAME = 'mail_restore') = 0,
    'ALTER TABLE migration_items ADD COLUMN mail_restore TINYINT(1) NOT NULL DEFAULT 0 AFTER selected',
    'SELECT 1'
);
PREPARE mr_stmt FROM @mr_sql;
EXECUTE mr_stmt;
DEALLOCATE PREPARE mr_stmt;
