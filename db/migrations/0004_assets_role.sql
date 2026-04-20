-- =============================================================================
-- videos.iaiapro.com — Migración 0004
-- Fecha: 2026-04-20
-- Propósito: añadir columna `role` a la tabla `assets` para soportar R.8
-- (uploads por plantilla). Cada proyecto tiene, como mucho, un asset por
-- role (ej. "logo", "main_image"). El fichero en disco se nombra
-- `<role>.<ext>` en `/home/dvdgp/data/videos/projects/<id>/assets/`.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE assets
    ADD COLUMN role VARCHAR(64) NULL AFTER project_id,
    ADD COLUMN sha256 CHAR(64) NULL AFTER size_bytes,
    ADD UNIQUE KEY uk_assets_project_role (project_id, role);

INSERT INTO schema_migrations (version) VALUES ('0004_assets_role');
