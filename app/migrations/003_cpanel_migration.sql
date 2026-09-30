-- NOTE: not needed for a fresh install via install.sh - schema.sql already
-- includes everything below. This file exists for upgrading an existing
-- JinnPanel database in place (install.sh re-applies schema.sql, which is
-- idempotent, so simply re-running the installer does the same thing):
--
--   mysql -u hostpanel_app -p hostpanel < 003_cpanel_migration.sql
--
-- Adds: the cPanel/WHM migration tables, and lets one MySQL user be shared
-- by several databases of the same account.

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
    transfer_mode ENUM('pull','push') NOT NULL DEFAULT 'pull',
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

-- db_instances.db_user used to be UNIQUE (one user per database). A
-- migrated cPanel user can own several databases, so it becomes a plain
-- index. Conditional-DDL again so re-running stays safe.
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
