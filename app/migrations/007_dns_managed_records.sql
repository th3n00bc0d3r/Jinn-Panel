-- Records the panel keeps in sync by itself (tag = what manages them:
-- 'mail' = Stalwart's DKIM/SPF/DMARC/SRV/autoconfig set, 'ipv6' = AAAA
-- next to A records pointing at this server). NULL = the customer's own.
-- Also appended to schema.sql; conditional DDL so re-running install.sh stays safe.
SET @mg_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dns_records' AND COLUMN_NAME = 'managed') = 0,
    'ALTER TABLE dns_records ADD COLUMN managed VARCHAR(16) NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE mg_stmt FROM @mg_sql;
EXECUTE mg_stmt;
DEALLOCATE PREPARE mg_stmt;
