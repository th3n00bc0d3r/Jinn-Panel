-- WHM > cPanel Migration > From backup files > Fetch from S3: downloads of
-- backup archives into the import folder. The secret key is encrypted
-- (Crypto, like migrations.secret_enc) and dropped when the fetch ends.
-- Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS s3_fetches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    region VARCHAR(64) NOT NULL,
    bucket VARCHAR(255) NOT NULL,
    prefix VARCHAR(1024) NOT NULL DEFAULT '',
    access_key VARCHAR(255) NOT NULL,
    secret_enc TEXT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued' COMMENT 'queued|running|done|failed',
    progress VARCHAR(255) NULL,
    log TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    CONSTRAINT fk_s3_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
