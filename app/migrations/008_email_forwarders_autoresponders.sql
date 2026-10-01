-- cPanel-style email forwarders and autoresponders, and each domain's
-- "default address" (catch-all). Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS email_forwarders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain_id INT NOT NULL,
    local_part VARCHAR(64) NOT NULL,
    destinations TEXT NOT NULL COMMENT 'comma-separated addresses',
    mail_list_id VARCHAR(64) NULL COMMENT 'Stalwart mailing list when the address is not a mailbox; NULL = Sieve redirect in the mailbox',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_forwarder (domain_id, local_part),
    CONSTRAINT fk_fwd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fwd_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS email_autoresponders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email_account_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    starts_on DATE NULL,
    ends_on DATE NULL,
    interval_days TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'reply to the same sender at most once per N days',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_autoresponder (email_account_id),
    CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_account FOREIGN KEY (email_account_id) REFERENCES email_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @ca_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'catch_all') = 0,
    'ALTER TABLE domains ADD COLUMN catch_all VARCHAR(190) NULL DEFAULT NULL COMMENT ''default address: NULL = reject unknown recipients''',
    'SELECT 1'
);
PREPARE ca_stmt FROM @ca_sql;
EXECUTE ca_stmt;
DEALLOCATE PREPARE ca_stmt;
