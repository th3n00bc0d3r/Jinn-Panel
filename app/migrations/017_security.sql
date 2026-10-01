-- Security hardening: login throttling, two-factor (TOTP) logins, the audit
-- trail (activity_log gets who/where), panel-wide settings for the setup
-- token. Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempt_ip (ip, created_at),
    KEY idx_attempt_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @s1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret_enc') = 0,
    'ALTER TABLE users ADD COLUMN totp_secret_enc TEXT NULL DEFAULT NULL COMMENT ''TOTP secret (Crypto), NULL = two-factor off'', ADD COLUMN totp_last_step BIGINT NULL DEFAULT NULL COMMENT ''last accepted TOTP time step (no replays)''',
    'SELECT 1'
);
PREPARE s1_stmt FROM @s1_sql;
EXECUTE s1_stmt;
DEALLOCATE PREPARE s1_stmt;

SET @s2_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log' AND COLUMN_NAME = 'ip') = 0,
    'ALTER TABLE activity_log ADD COLUMN ip VARCHAR(45) NULL DEFAULT NULL, ADD COLUMN target_user_id INT NULL DEFAULT NULL, ADD KEY idx_activity_created (created_at), ADD KEY idx_activity_target (target_user_id), MODIFY detail VARCHAR(1000) NULL, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
    'SELECT 1'
);
PREPARE s2_stmt FROM @s2_sql;
EXECUTE s2_stmt;
DEALLOCATE PREPARE s2_stmt;

-- Role/ownership changes take effect on the next request: the session holds
-- this counter and is dropped when it no longer matches.
SET @s3_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version') = 0,
    'ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1',
    'SELECT 1'
);
PREPARE s3_stmt FROM @s3_sql;
EXECUTE s3_stmt;
DEALLOCATE PREPARE s3_stmt;
