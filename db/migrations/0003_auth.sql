-- =============================================================================
-- videos.iaiapro.com — Migración 0003
-- Auth: tabla de intentos de login para rate limit por IP y por email.
-- Fecha: 2026-04-20
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip            VARCHAR(45) NOT NULL,
    email         VARCHAR(190) NOT NULL DEFAULT '',
    success       TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_ip    (ip, attempted_at),
    KEY idx_la_email (email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0003_auth');
