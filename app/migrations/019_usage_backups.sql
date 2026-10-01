-- Quotas that are enforced, not only counted: measured usage per account
-- (disk = site files + databases + mail; bandwidth per month, from the web
-- server's access log), reseller account limits, and backups.
-- Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS account_usage (
    user_id INT NOT NULL PRIMARY KEY,
    files_bytes BIGINT NOT NULL DEFAULT 0,
    db_bytes BIGINT NOT NULL DEFAULT 0,
    mail_bytes BIGINT NOT NULL DEFAULT 0,
    bw_month CHAR(7) NOT NULL DEFAULT '' COMMENT 'YYYY-MM the bandwidth counter is for',
    bw_bytes BIGINT NOT NULL DEFAULT 0,
    over_disk TINYINT(1) NOT NULL DEFAULT 0,
    over_bandwidth TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL,
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @u1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND COLUMN_NAME = 'max_accounts') = 0,
    'ALTER TABLE packages ADD COLUMN max_accounts INT NOT NULL DEFAULT 0 COMMENT ''resellers on this package: how many accounts they may create (0 = none)''',
    'SELECT 1'
);
PREPARE u1_stmt FROM @u1_sql;
EXECUTE u1_stmt;
DEALLOCATE PREPARE u1_stmt;
UPDATE packages SET max_accounts = 50 WHERE name = 'Reseller' AND owner_id IS NULL AND max_accounts = 0;

-- One row per backup: an account's sites, databases and mail, or (user_id
-- NULL, kind 'server') the panel database and server configuration.
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(8) NOT NULL DEFAULT 'account' COMMENT 'account|server',
    user_id INT NULL,
    username VARCHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed|restoring',
    path VARCHAR(255) NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    parts TEXT NULL COMMENT 'JSON: what is in it (files, databases, mailboxes)',
    remote VARCHAR(16) NULL COMMENT 'off-site copy: uploaded|failed|NULL',
    log TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    KEY idx_backup_user (user_id, created_at),
    CONSTRAINT fk_backup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
