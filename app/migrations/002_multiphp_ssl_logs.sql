-- NOTE: not needed for a fresh install via install.sh - schema.sql already
-- includes these columns/table directly. This file exists only for
-- upgrading a pre-existing JinnPanel database that predates the multi-PHP/
-- AutoSSL/logs features, from before they were folded into schema.sql.
--
-- Adds: per-domain PHP version + AutoSSL mode, and the server-wide table of
-- installed PHP versions (WHM > PHP Versions).

ALTER TABLE domains
    ADD COLUMN php_version VARCHAR(10) NOT NULL DEFAULT 'default' COMMENT '"default" = the main FrankenPHP instance (8.5); else an installed alt version like "8.2"',
    ADD COLUMN php_port INT NULL COMMENT 'loopback port of the alt-version FrankenPHP instance this domain is reverse-proxied to, NULL when php_version=default',
    ADD COLUMN ssl_mode ENUM('self_signed','letsencrypt') NOT NULL DEFAULT 'self_signed';

CREATE TABLE IF NOT EXISTS php_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(10) NOT NULL UNIQUE COMMENT 'e.g. 8.2, 8.3, 8.4',
    port INT NOT NULL UNIQUE COMMENT 'loopback port its dedicated FrankenPHP instance listens on',
    status ENUM('installing','active','failed','removing') NOT NULL DEFAULT 'installing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
