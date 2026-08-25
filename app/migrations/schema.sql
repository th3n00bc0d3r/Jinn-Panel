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
    db_user VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

-- Seed data: default packages only. No admin account is seeded here on
-- purpose - the first visit to the panel shows a setup wizard (/setup)
-- that creates the real administrator account interactively. Baking a
-- fixed username/password into a script or repo is exactly the kind of
-- thing this is meant to avoid.
INSERT INTO packages (owner_id, name, disk_quota_mb, bandwidth_mb, max_domains, max_databases, max_email_accounts, max_ftp_accounts)
VALUES
    (NULL, 'Starter', 1024, 10240, 1, 1, 5, 1),
    (NULL, 'Business', 5120, 51200, 5, 5, 25, 3),
    (NULL, 'Reseller', 20480, 204800, 50, 50, 250, 10)
ON DUPLICATE KEY UPDATE name = VALUES(name);
