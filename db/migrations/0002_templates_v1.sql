-- =============================================================================
-- videos.iaiapro.com — Migración 0002
-- Templates v1: añade plantillas, formato, estado ampliado, assets con rol.
-- Fecha: 2026-04-19
--
-- Compatible con MariaDB 11+ (y MySQL 8+). La columna JSON en MariaDB es un
-- alias de LONGTEXT con CHECK json_valid, así que funciona igual.
--
-- Ejecutar UNA VEZ sobre la BBDD `dvdgp_videos` que ya tiene aplicada 0001_init:
--
--   mysql -u dvdgp_vid_usr -p dvdgp_videos < db/migrations/0002_templates_v1.sql
--
-- Idempotencia: la migración comprueba `schema_migrations` al final. Si ya
-- existe la fila '0002_templates_v1', falla por clave primaria duplicada:
-- eso sirve como guard. Las instrucciones ALTER no son idempotentes por sí
-- mismas; NO relanzar sin antes revertir.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
-- projects: campos para plantillas v1.
-- -----------------------------------------------------------------------------
ALTER TABLE projects
    ADD COLUMN template_id     VARCHAR(64) NULL            AFTER name,
    ADD COLUMN format          ENUM('16:9','9:16','1:1') NOT NULL DEFAULT '16:9' AFTER template_id,
    ADD COLUMN content_json    JSON NULL                   AFTER composition_html,
    ADD COLUMN style_json      JSON NULL                   AFTER content_json,
    ADD COLUMN render_progress TINYINT UNSIGNED NOT NULL DEFAULT 0   AFTER duration_seconds,
    ADD COLUMN render_message  VARCHAR(255) NOT NULL DEFAULT ''      AFTER render_progress;

-- Ampliar el ciclo de vida del proyecto para incluir estados de render.
ALTER TABLE projects
    MODIFY COLUMN status ENUM('draft','queued','rendering','completed','failed','archived')
        NOT NULL DEFAULT 'draft';

-- Índice útil para filtrar proyectos por plantilla dentro de un usuario.
ALTER TABLE projects
    ADD INDEX idx_projects_template (user_id, template_id);

-- -----------------------------------------------------------------------------
-- assets: rol (logo / imagen principal / galería / extra) y posición.
-- -----------------------------------------------------------------------------
ALTER TABLE assets
    ADD COLUMN role     ENUM('logo','main_image','gallery','extra') NOT NULL DEFAULT 'extra' AFTER kind,
    ADD COLUMN position SMALLINT UNSIGNED NOT NULL DEFAULT 0                              AFTER role;

-- Sólo puede haber un logo y una main_image por proyecto. La galería puede
-- tener varios (role='gallery'), así que filtramos por rol con un índice
-- único parcial — MariaDB/MySQL no soportan índices parciales nativos, así
-- que usamos un truco con columna generada si fuera necesario. Para v1 lo
-- dejamos relajado: la app garantiza la unicidad al subir.
ALTER TABLE assets
    ADD INDEX idx_assets_project_role (project_id, role, position);

-- -----------------------------------------------------------------------------
-- registro de la migración
-- -----------------------------------------------------------------------------
INSERT INTO schema_migrations (version) VALUES ('0002_templates_v1');
