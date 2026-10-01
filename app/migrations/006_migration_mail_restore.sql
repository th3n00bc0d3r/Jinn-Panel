-- "Restore mail" on a finished migration item: re-reads the account's backup
-- and creates only the mailboxes that are missing. A separate flag, not a
-- status, so the item stays completed and Cancel/Retry never treat the
-- account as a half-done restore (which they would roll back).
-- Also appended to schema.sql; conditional DDL so re-running install.sh stays safe.
SET @mr_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migration_items' AND COLUMN_NAME = 'mail_restore') = 0,
    'ALTER TABLE migration_items ADD COLUMN mail_restore TINYINT(1) NOT NULL DEFAULT 0 AFTER selected',
    'SELECT 1'
);
PREPARE mr_stmt FROM @mr_sql;
EXECUTE mr_stmt;
DEALLOCATE PREPARE mr_stmt;
