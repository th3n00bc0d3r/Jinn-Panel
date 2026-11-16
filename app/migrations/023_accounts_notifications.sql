-- Plan 1 (account management basics). Also appended to schema.sql; idempotent.
--
-- password_resets: single-use links sent by email - "forgot password" (1 hour)
-- and the welcome email's "set your password" link (3 days). Only a hash of
-- the token is stored.
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    purpose ENUM('reset','welcome') NOT NULL DEFAULT 'reset',
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reset_token (token_hash),
    KEY idx_reset_user (user_id),
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Two-factor recovery codes: ten one-time codes, shown once when two-factor
-- is turned on (or regenerated). Only hashes are stored.
CREATE TABLE IF NOT EXISTS recovery_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash CHAR(64) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_recovery_user (user_id),
    CONSTRAINT fk_recovery_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- SSH public keys of an SFTP login (one per line), mirrored to SFTPGo.
SET @pk1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ftp_accounts' AND COLUMN_NAME = 'public_keys') = 0,
    'ALTER TABLE ftp_accounts ADD COLUMN public_keys TEXT NULL DEFAULT NULL COMMENT ''SSH public keys, one per line (SFTPGo public_keys)''',
    'SELECT 1'
);
PREPARE pk1_stmt FROM @pk1_sql;
EXECUTE pk1_stmt;
DEALLOCATE PREPARE pk1_stmt;
