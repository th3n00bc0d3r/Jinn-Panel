-- cPanel > MySQL Databases: users are their own objects (db_user_accounts is
-- the account's list of MySQL users; grants are read live from MariaDB),
-- remote access hosts per account, and phpMyAdmin's temporary logins.
-- Also appended to schema.sql; idempotent.
INSERT IGNORE INTO db_user_accounts (user_id, db_user)
    SELECT user_id, db_user FROM db_instances WHERE db_user IS NOT NULL AND db_user <> '';

CREATE TABLE IF NOT EXISTS mysql_remote_hosts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    host VARCHAR(64) NOT NULL COMMENT 'IP, IPv4 CIDR or %',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_remote_host (user_id, host),
    CONSTRAINT fk_remote_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pma_logins (
    mysql_user VARCHAR(32) PRIMARY KEY,
    user_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_pma_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
