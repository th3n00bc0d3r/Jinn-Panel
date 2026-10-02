-- Routes follow the site's .htaccess: the worker re-translates it when it
-- changes and applies the result (RoutesService::syncPlan). routes_sync NULL
-- = not decided yet: the first check turns it on only when the rules in use
-- are already what the translator makes of the .htaccess, so no live site
-- changes behaviour. Also appended to schema.sql; idempotent.
SET @rs1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'routes_sync') = 0,
    'ALTER TABLE domains ADD COLUMN routes_sync TINYINT(1) NULL DEFAULT NULL COMMENT ''1 = routes follow .htaccess, NULL = decided at the first check'', ADD COLUMN routes_sync_hash CHAR(64) NULL COMMENT ''the .htaccess files the last sync looked at'', ADD COLUMN routes_sync_note VARCHAR(500) NULL, ADD COLUMN routes_synced_at DATETIME NULL',
    'SELECT 1'
);
PREPARE rs1_stmt FROM @rs1_sql;
EXECUTE rs1_stmt;
DEALLOCATE PREPARE rs1_stmt;
