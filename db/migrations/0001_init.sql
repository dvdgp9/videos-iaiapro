-- =============================================================================
-- videos.iaiapro.com — Migración inicial
-- Versión: 0001
-- Fecha: 2026-04-17
--
-- Requisitos: MySQL 8.0+ (usa CHECK constraints, utf8mb4, JSON).
-- Ejecutar una sola vez sobre una BBDD vacía ya creada. Ejemplo:
--
--   CREATE DATABASE videos_iaiapro
--     CHARACTER SET utf8mb4
--     COLLATE utf8mb4_unicode_ci;
--   USE videos_iaiapro;
--   SOURCE db/migrations/0001_init.sql;
--
-- Convenciones:
--   - Todas las tablas InnoDB, utf8mb4_unicode_ci.
--   - Claves primarias BIGINT UNSIGNED AUTO_INCREMENT.
--   - Timestamps en UTC (la app fija date_default_timezone_set('UTC')).
--   - Borrados físicos con ON DELETE CASCADE cuando tiene sentido.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- users
-- -----------------------------------------------------------------------------
CREATE TABLE users (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email                  VARCHAR(190) NOT NULL,
    password_hash          VARCHAR(255) NOT NULL,
    name                   VARCHAR(120) NOT NULL DEFAULT '',
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    quota_render_seconds   INT UNSIGNED NOT NULL DEFAULT 1800,   -- 30 min/mes por defecto
    quota_storage_mb       INT UNSIGNED NOT NULL DEFAULT 1024,   -- 1 GB por defecto
    used_render_seconds    INT UNSIGNED NOT NULL DEFAULT 0,
    used_storage_mb        INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at          DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sessions  (sesiones server-side; la cookie sólo guarda el token opaco)
-- -----------------------------------------------------------------------------
CREATE TABLE sessions (
    id          CHAR(64) NOT NULL,                -- token aleatorio (hex de 32 bytes)
    user_id     BIGINT UNSIGNED NOT NULL,
    ip          VARCHAR(45) NOT NULL DEFAULT '',
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_sessions_user (user_id),
    KEY idx_sessions_expires (expires_at),
    CONSTRAINT fk_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- projects
--   composition_html: HTML Hyperframes (mutado por el LLM en cada iteración).
--   status: ciclo de vida del proyecto a alto nivel.
-- -----------------------------------------------------------------------------
CREATE TABLE projects (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    name               VARCHAR(160) NOT NULL,
    status             ENUM('draft','ready','archived') NOT NULL DEFAULT 'draft',
    composition_html   LONGTEXT NULL,
    width              SMALLINT UNSIGNED NOT NULL DEFAULT 1920,
    height             SMALLINT UNSIGNED NOT NULL DEFAULT 1080,
    duration_seconds   DECIMAL(7,3) NOT NULL DEFAULT 0.000,
    last_render_id     BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_projects_user (user_id, updated_at),
    CONSTRAINT fk_projects_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- assets
--   Archivos subidos por el usuario (vídeo/imagen/audio). Se guardan en disco
--   (storage/users/<user_id>/assets/<uuid>.<ext>) y aquí sólo los metadatos.
--   project_id puede ser NULL si el asset vive en la biblioteca del usuario.
-- -----------------------------------------------------------------------------
CREATE TABLE assets (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            BIGINT UNSIGNED NOT NULL,
    project_id         BIGINT UNSIGNED NULL,
    kind               ENUM('video','image','audio') NOT NULL,
    original_name      VARCHAR(255) NOT NULL,
    storage_path       VARCHAR(500) NOT NULL,   -- ruta relativa dentro de storage/
    mime               VARCHAR(100) NOT NULL,
    size_bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds   DECIMAL(7,3) NULL,
    width              SMALLINT UNSIGNED NULL,
    height             SMALLINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assets_user (user_id, created_at),
    KEY idx_assets_project (project_id),
    CONSTRAINT fk_assets_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_assets_project
        FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- renders
--   Un registro por cada intento de render. El MP4 se guarda en disco del VPS.
--   job_id: identificador devuelto por render-api (útil para debugging).
-- -----------------------------------------------------------------------------
CREATE TABLE renders (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id      BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    status          ENUM('queued','rendering','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    job_id          VARCHAR(64) NULL,
    output_path     VARCHAR(500) NULL,
    duration_seconds DECIMAL(7,3) NULL,
    size_bytes      BIGINT UNSIGNED NULL,
    log             MEDIUMTEXT NULL,
    error_message   VARCHAR(1000) NULL,
    queued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at      DATETIME NULL,
    finished_at     DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_renders_project (project_id, queued_at),
    KEY idx_renders_user (user_id, queued_at),
    KEY idx_renders_status (status),
    CONSTRAINT fk_renders_project
        FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_renders_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ahora que existe 'renders', añadimos la FK suelta desde projects.last_render_id.
ALTER TABLE projects
    ADD CONSTRAINT fk_projects_last_render
        FOREIGN KEY (last_render_id) REFERENCES renders (id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- prompts
--   Historial conversacional con el LLM para cada proyecto.
--   role: user | assistant | system.
--   content: texto del mensaje (para 'assistant' puede contener el HTML devuelto).
--   meta_json: tokens, modelo, coste, etc.
-- -----------------------------------------------------------------------------
CREATE TABLE prompts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id   BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    role         ENUM('user','assistant','system') NOT NULL,
    content      MEDIUMTEXT NOT NULL,
    model        VARCHAR(120) NULL,
    tokens_in    INT UNSIGNED NULL,
    tokens_out   INT UNSIGNED NULL,
    cost_usd     DECIMAL(10,6) NULL,
    meta_json    JSON NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prompts_project (project_id, created_at),
    KEY idx_prompts_user (user_id, created_at),
    CONSTRAINT fk_prompts_project
        FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
    CONSTRAINT fk_prompts_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- schema_migrations  (registro de versiones aplicadas)
-- -----------------------------------------------------------------------------
CREATE TABLE schema_migrations (
    version     VARCHAR(32) NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO schema_migrations (version) VALUES ('0001_init');
