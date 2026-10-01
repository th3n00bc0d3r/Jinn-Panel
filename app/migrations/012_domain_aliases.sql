-- Aliases (cPanel "parked domains"): extra names served by a domain's site.
-- Also appended to schema.sql; idempotent.
CREATE TABLE IF NOT EXISTS domain_aliases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    alias_name VARCHAR(190) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alias_domain FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
