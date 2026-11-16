-- Plan 1 v3: statistics for the dashboards and the per-domain Stats tab.
-- Also appended to schema.sql; idempotent.
--
-- Written by the root worker (StatsCollector): the server every minute,
-- service checks every minute, the web access log every 5 minutes, account
-- disk use with the hourly usage run. Pruned nightly (StatsCollector::prune).
-- No IP addresses are stored: a visitor is a hash of the address and the
-- browser with a salt that changes every day and is then thrown away, so
-- visitors can be counted per day but not followed from one day to the next.

-- The server, one row per minute (kept 32 days).
CREATE TABLE IF NOT EXISTS stat_server (
    at DATETIME NOT NULL PRIMARY KEY COMMENT 'the minute',
    load1 DECIMAL(7,2) NOT NULL DEFAULT 0,
    cpu_pct DECIMAL(5,2) NULL COMMENT 'CPU busy since the previous sample, all cores',
    mem_used BIGINT NOT NULL DEFAULT 0,
    mem_total BIGINT NOT NULL DEFAULT 0,
    disk_used BIGINT NOT NULL DEFAULT 0,
    disk_total BIGINT NOT NULL DEFAULT 0,
    net_rx BIGINT NULL COMMENT 'bytes received since the previous sample',
    net_tx BIGINT NULL COMMENT 'bytes sent since the previous sample'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Whether each service answered, one row per service per minute (kept 32 days).
CREATE TABLE IF NOT EXISTS stat_service_checks (
    service VARCHAR(20) NOT NULL,
    at DATETIME NOT NULL,
    up TINYINT(1) NOT NULL,
    ms SMALLINT UNSIGNED NULL COMMENT 'time to connect',
    PRIMARY KEY (service, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each hosting account per day (kept 400 days): disk at the last hourly
-- measure that day, and what its sites served that day.
CREATE TABLE IF NOT EXISTS stat_account_daily (
    user_id INT NOT NULL,
    day DATE NOT NULL,
    disk_bytes BIGINT NOT NULL DEFAULT 0 COMMENT 'files + databases + mail',
    files_bytes BIGINT NOT NULL DEFAULT 0,
    db_bytes BIGINT NOT NULL DEFAULT 0,
    mail_bytes BIGINT NOT NULL DEFAULT 0,
    bw_bytes BIGINT NOT NULL DEFAULT 0,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_stat_account_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Each domain per hour (kept 95 days). visitors = visitors first seen that
-- day during this hour, so the hours of a day add up to the day's visitors.
CREATE TABLE IF NOT EXISTS stat_domain_hourly (
    domain_id INT NOT NULL,
    hour DATETIME NOT NULL,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    bytes BIGINT NOT NULL DEFAULT 0,
    visitors INT UNSIGNED NOT NULL DEFAULT 0,
    pages INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'requests for pages, not files like images or scripts',
    s2xx INT UNSIGNED NOT NULL DEFAULT 0,
    s3xx INT UNSIGNED NOT NULL DEFAULT 0,
    s4xx INT UNSIGNED NOT NULL DEFAULT 0,
    s5xx INT UNSIGNED NOT NULL DEFAULT 0,
    time_ms BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'summed response time of pages',
    slow INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'pages that took over a second',
    PRIMARY KEY (domain_id, hour),
    KEY idx_stat_domain_hour (hour),
    CONSTRAINT fk_stat_hourly_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Top pages and referring sites per domain per day (kept 95 days; past
-- days keep their 50 biggest, the rest add up under "(other)").
CREATE TABLE IF NOT EXISTS stat_domain_top (
    domain_id INT NOT NULL,
    day DATE NOT NULL,
    kind ENUM('page','referrer') NOT NULL,
    value VARCHAR(190) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (domain_id, day, kind, value),
    CONSTRAINT fk_stat_top_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Today's visitor hashes, only to count each visitor once a day (emptied
-- every night along with the salt that made them).
CREATE TABLE IF NOT EXISTS stat_visitors (
    domain_id INT NOT NULL,
    day DATE NOT NULL,
    hash BINARY(8) NOT NULL,
    PRIMARY KEY (domain_id, day, hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
