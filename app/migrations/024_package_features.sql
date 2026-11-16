-- Feature switches per package, and per account on top of its package
-- (Features.php). NULL = everything on, as before. Also appended to
-- schema.sql; idempotent.
SET @pf1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'packages' AND COLUMN_NAME = 'features') = 0,
    'ALTER TABLE packages ADD COLUMN features TEXT NULL DEFAULT NULL COMMENT ''JSON feature => bool (Features::LIST); NULL = all on''',
    'SELECT 1'
);
PREPARE pf1_stmt FROM @pf1_sql;
EXECUTE pf1_stmt;
DEALLOCATE PREPARE pf1_stmt;

SET @pf2_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'feature_overrides') = 0,
    'ALTER TABLE users ADD COLUMN feature_overrides TEXT NULL DEFAULT NULL COMMENT ''JSON feature => bool, overriding the package for this account''',
    'SELECT 1'
);
PREPARE pf2_stmt FROM @pf2_sql;
EXECUTE pf2_stmt;
DEALLOCATE PREPARE pf2_stmt;
