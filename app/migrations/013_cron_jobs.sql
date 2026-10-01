-- cPanel > Cron Jobs: scheduled PHP scripts (inside the account's sites) and
-- URL fetches, run by cron-run.php (systemd timer, every minute) as the web
-- user. No free-form shell commands. Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS cron_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain_id INT NULL,
    schedule VARCHAR(100) NOT NULL,
    kind VARCHAR(8) NOT NULL COMMENT 'php|url',
    target VARCHAR(1024) NOT NULL COMMENT 'php: absolute script path inside the site; url: http(s) URL',
    args VARCHAR(255) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    last_status INT NULL COMMENT 'exit code (php) or HTTP status (url); -1 = timed out',
    last_output TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cron_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_cron_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
