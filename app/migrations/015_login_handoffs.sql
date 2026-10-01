-- One-time tokens that carry an admin/reseller login from a customer
-- domain's :2083 panel to the panel hostname (WHM only runs there; cookies
-- are per host). Single use, 60 s. Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS login_handoffs (
    token_hash CHAR(64) PRIMARY KEY,
    user_id INT NOT NULL,
    next_path VARCHAR(255) NOT NULL DEFAULT '/whm',
    expires_at DATETIME NOT NULL,
    CONSTRAINT fk_handoff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
