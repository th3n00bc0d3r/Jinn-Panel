-- "Self-signed only" chosen by the customer (or a migration): the daily
-- SslService::upgradeAll() leaves the domain self-signed even once its DNS
-- points here. Also appended to schema.sql; idempotent.
SET @sp1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'ssl_pinned') = 0,
    'ALTER TABLE domains ADD COLUMN ssl_pinned TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = self-signed on purpose, never switched to Let''''s Encrypt automatically''',
    'SELECT 1'
);
PREPARE sp1_stmt FROM @sp1_sql;
EXECUTE sp1_stmt;
DEALLOCATE PREPARE sp1_stmt;
