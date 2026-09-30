-- DNS zones and records live in the panel DB; Knot zone files are rendered
-- from these rows by DnsService and written by hostpanel-worker.php.
-- Also appended to schema.sql (CREATE ... IF NOT EXISTS), so install.sh
-- applies it on fresh installs and re-runs alike.

CREATE TABLE IF NOT EXISTS panel_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dns_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_name VARCHAR(190) NOT NULL UNIQUE COMMENT 'matches domains.domain_name for hosted domains',
    is_server_zone TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'the zone holding ns1/ns2, the hostname and the panel',
    serial INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

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
);
