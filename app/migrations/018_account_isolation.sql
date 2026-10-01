-- Per-account PHP (PHP-FPM pools as the account's own Linux user): whether
-- the account's PHP may run programs (exec, proc_open, ...). Off unless an
-- admin turns it on in WHM > Accounts. Also appended to schema.sql; idempotent.
SET @i1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'php_exec') = 0,
    'ALTER TABLE users ADD COLUMN php_exec TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = the account''''s PHP may use exec(), proc_open() etc.''',
    'SELECT 1'
);
PREPARE i1_stmt FROM @i1_sql;
EXECUTE i1_stmt;
DEALLOCATE PREPARE i1_stmt;
