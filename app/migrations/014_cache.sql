-- cPanel > Cache: each account's object-cache (Valkey) password, encrypted,
-- and each domain's page cache TTL (NULL = off). Also appended to
-- schema.sql; conditional DDL so re-running install.sh stays safe.
SET @c1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'cache_secret_enc') = 0,
    'ALTER TABLE users ADD COLUMN cache_secret_enc TEXT NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE c1_stmt FROM @c1_sql;
EXECUTE c1_stmt;
DEALLOCATE PREPARE c1_stmt;
SET @c2_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'page_cache_ttl') = 0,
    'ALTER TABLE domains ADD COLUMN page_cache_ttl INT NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE c2_stmt FROM @c2_sql;
EXECUTE c2_stmt;
DEALLOCATE PREPARE c2_stmt;
