-- `file` transfer mode: restore cPanel backups already on this server
-- (MIGRATION_DIR/import) instead of fetching them from a source server.
-- Also appended to schema.sql; conditional DDL so re-running install.sh stays safe.
SET @tm_sql = IF(
    (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND COLUMN_NAME = 'transfer_mode') NOT LIKE '%''file''%',
    'ALTER TABLE migrations MODIFY transfer_mode ENUM(''pull'',''push'',''file'') NOT NULL DEFAULT ''pull''',
    'SELECT 1'
);
PREPARE tm_stmt FROM @tm_sql;
EXECUTE tm_stmt;
DEALLOCATE PREPARE tm_stmt;
