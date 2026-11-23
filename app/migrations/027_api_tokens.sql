-- Provisioning API: tokens for /json-api/* (WHM API 1 compatible subset).
-- Also appended to schema.sql; idempotent.
--
-- A token belongs to an administrator or reseller and acts with exactly that
-- account's rights (a reseller's token sees and creates only its own
-- accounts). Only the SHA-256 of the token is stored; the token itself is
-- shown once, when it is created.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'the admin/reseller the token acts as',
    name VARCHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token',
    token_hint VARCHAR(8) NOT NULL COMMENT 'its first characters, to tell tokens apart',
    allowed_ips VARCHAR(500) NULL COMMENT 'comma-separated addresses; NULL = any',
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_api_token_hash (token_hash),
    KEY idx_api_tokens_user (user_id),
    CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
