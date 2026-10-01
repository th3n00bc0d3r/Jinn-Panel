-- Static files are served by each account's own static server (nginx as the
-- account's user) with a response cache in front: per domain, how long a
-- static file stays in that cache (0 = cache off), and whether browsers are
-- told to cache static files. Also appended to schema.sql; idempotent.
SET @sc1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'domains' AND COLUMN_NAME = 'static_cache_ttl') = 0,
    'ALTER TABLE domains ADD COLUMN static_cache_ttl INT NOT NULL DEFAULT 300 COMMENT ''seconds a static file stays in the server cache, 0 = off'', ADD COLUMN browser_cache TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = Cache-Control for static files''',
    'SELECT 1'
);
PREPARE sc1_stmt FROM @sc1_sql;
EXECUTE sc1_stmt;
DEALLOCATE PREPARE sc1_stmt;
