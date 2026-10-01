-- Routes translated from .htaccess that need a human look (something
-- couldn't be translated faithfully). Also appended to schema.sql; idempotent.
SET @rr_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'routes_review') = 0,
    'ALTER TABLE domains ADD COLUMN routes_review TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1'
);
PREPARE rr_stmt FROM @rr_sql;
EXECUTE rr_stmt;
DEALLOCATE PREPARE rr_stmt;
