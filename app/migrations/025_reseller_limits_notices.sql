-- Plan 1 v3. Also appended to schema.sql; idempotent.
--
-- Package limits may be -1 = unlimited (Quota::UNLIMITED); no column change
-- needed. Quota emails to customers remember the last level they were told
-- about (0, 90 or 100 %), so each warning goes out once; bandwidth levels
-- start again every month.
SET @un1_sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_usage' AND COLUMN_NAME = 'disk_notice') = 0,
    'ALTER TABLE account_usage ADD COLUMN disk_notice TINYINT NOT NULL DEFAULT 0 COMMENT ''last disk warning emailed: 0, 90 or 100 (%)'', ADD COLUMN bw_notice TINYINT NOT NULL DEFAULT 0 COMMENT ''last bandwidth warning emailed this month'', ADD COLUMN bw_notice_month CHAR(7) NOT NULL DEFAULT '''' COMMENT ''YYYY-MM bw_notice is for''',
    'SELECT 1'
);
PREPARE un1_stmt FROM @un1_sql;
EXECUTE un1_stmt;
DEALLOCATE PREPARE un1_stmt;
