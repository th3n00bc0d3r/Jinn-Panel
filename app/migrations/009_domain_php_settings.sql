-- Per-site PHP settings (PhpSettingsService), as JSON. Also appended to
-- schema.sql; conditional DDL so re-running install.sh stays safe.
SET @ps_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'php_settings') = 0,
    'ALTER TABLE domains ADD COLUMN php_settings TEXT NULL DEFAULT NULL',
    'SELECT 1'
);
PREPARE ps_stmt FROM @ps_sql;
EXECUTE ps_stmt;
DEALLOCATE PREPARE ps_stmt;
