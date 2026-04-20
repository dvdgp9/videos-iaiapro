# videos.iaiapro.com — Plan inicial

## Background and Motivation

El usuario quiere construir una aplicación web en `videos.iaiapro.com` que permita **generar y editar vídeos** mediante IA, usando como backend de renderizado el proyecto open-source **Hyperframes** (`https://github.com/heygen-com/hyperframes`) desplegado en un VPS Hetzner (Linux).

Requisitos explícitos:

- **Frontend**: PHP + HTML + CSS + JS (lo máximo posible en este stack, sin frameworks JS pesados salvo que sea imprescindible).
- **Backend de render**: Hyperframes en VPS Hetzner.
- **IA**: conexión con **OpenRouter** (el LLM es quien "escribe" la composición HTML que Hyperframes renderiza).
- **BBDD**: MySQL.
- **Multiusuario**: aislamiento por usuario (proyectos, assets, renders).
- **Dominio**: `videos.iaiapro.com`.

## Key Challenges and Analysis

### 1. Hyperframes NO es una API HTTP

Del README se extrae:

- Es un **CLI Node.js** (`hyperframes init`, `hyperframes preview`, `hyperframes render`).
- Requiere **Node.js >= 22** y **FFmpeg**.
- Una composición es un **HTML con atributos `data-*`** (p.ej. `data-composition-id`, `data-start`, `data-duration`, `data-track-index`) que referencia `<video>`, `<img>`, `<audio>`. El render produce un **MP4**.
- Está pensado para ser manejado por agentes IA usando "skills" (Claude Code, Cursor, etc.), no expone HTTP por defecto.

**Implicación**: hay que construir una **capa servidor** encima de Hyperframes que exponga una API HTTP interna para: crear proyecto, guardar/actualizar el HTML de composición, subir assets, lanzar render, consultar estado, servir el MP4 final. Esta capa puede ser:

- **Opción A (recomendada)**: un pequeño **servicio Node.js** (Express/Fastify) en el mismo VPS que invoca el CLI de Hyperframes por `child_process` y gestiona una **cola de trabajos** (BullMQ + Redis, o una cola simple en MySQL). Aisla cada proyecto en su propio directorio.
- **Opción B**: PHP llamando a `shell_exec` al CLI. Más simple, pero peor para jobs largos, concurrencia y logs de render en streaming. Descartada como primaria.

**Decisión propuesta**: **PHP en el frontend (`videos.iaiapro.com`) + microservicio Node.js "render-api" en el VPS** que envuelve Hyperframes. El PHP habla con el microservicio vía HTTPS con token.

### 2. Rol de OpenRouter

El LLM se usa para **traducir prompts del usuario → HTML de composición Hyperframes válido**. Necesita un *system prompt* que incorpore las reglas del "skill" de Hyperframes (estructura del `#stage`, atributos `data-*`, tracks, duraciones, GSAP, etc.).

Flujo:

1. Usuario escribe prompt ("vídeo de 10s con título fade-in, fondo, música").
2. Frontend envía prompt + contexto del proyecto a OpenRouter (modelo a elegir: Claude Sonnet / GPT-4o / etc.).
3. LLM devuelve el HTML de la composición (y opcionalmente una lista de assets que faltan).
4. Se guarda en MySQL y se muestra en el editor.
5. Usuario pulsa "Render" → se encola en el VPS → MP4 resultante.

Iteración: el usuario puede pedir cambios en lenguaje natural ("haz el título 2x más grande") y el LLM devuelve el HTML modificado (diff o reemplazo completo).

### 3. Multiusuario y aislamiento

- Auth propia (email + password con `password_hash`) o SSO si `iaiapro.com` ya tiene uno (preguntar al usuario).
- Cada proyecto vive en un directorio `projects/<user_id>/<project_id>/` en el VPS.
- Assets subidos desde el navegador se almacenan en el VPS (o S3/Hetzner Object Storage si se quiere escalar). Referencias y metadatos en MySQL.
- Cuotas por usuario (minutos de render, GB de assets) desde el inicio para evitar abuso.

### 4. Jobs largos y estado de render

- El render puede tardar de segundos a minutos. Debe ser **asíncrono**.
- Estados: `queued`, `rendering`, `done`, `failed`.
- Frontend hace **polling** (cada 2–3s) o SSE al microservicio para actualizar UI.
- Logs del render se guardan para depuración.

### 5. Seguridad

- El microservicio Node en el VPS **no** se expone directo a internet: sólo a través del backend PHP, o con token compartido + allowlist de IP.
- Los assets subidos por el usuario se sirven desde un subdominio aislado o con `Content-Disposition` correcto; validar MIME y tamaño.
- API key de OpenRouter **sólo en servidor** (PHP), nunca en el navegador.

### 6. Estructura de directorios propuesta en este repo

```
videos-iaiapro/
├── public/                  # DocumentRoot del virtualhost PHP
│   ├── index.php
│   ├── assets/              # css, js, img estáticos del frontend
│   └── api/                 # endpoints PHP (api/projects.php, api/render.php, ...)
├── src/                     # clases PHP (PSR-4)
│   ├── Auth/
│   ├── Db/
│   ├── OpenRouter/
│   ├── Hyperframes/         # cliente HTTP hacia render-api
│   └── Projects/
├── db/
│   └── migrations/          # .sql versionados
├── render-api/              # microservicio Node que envuelve Hyperframes (se despliega en VPS)
│   ├── package.json
│   ├── src/
│   │   ├── server.js
│   │   ├── queue.js
│   │   └── hyperframes.js
│   └── README.md
├── deploy/
│   ├── nginx/               # vhosts
│   ├── systemd/             # units para render-api
│   └── README.md
├── .cursor/
│   └── scratchpad.md
└── README.md
```

### 7. Esquema MySQL inicial (borrador)

- `users` (id, email, password_hash, name, created_at, quota_render_seconds, quota_storage_mb)
- `projects` (id, user_id, name, status, composition_html LONGTEXT, width, height, duration_seconds, created_at, updated_at)
- `assets` (id, user_id, project_id NULL, kind ENUM('video','image','audio'), path, mime, size_bytes, duration_seconds, created_at)
- `renders` (id, project_id, user_id, status ENUM('queued','rendering','done','failed'), job_id, output_path, log TEXT, started_at, finished_at, error_message)
- `prompts` (id, project_id, user_id, role ENUM('user','assistant','system'), content TEXT, model, tokens_in, tokens_out, created_at) — historial conversacional con OpenRouter
- `sessions` (id, user_id, token, expires_at) — o JWT stateless

### 8. Preguntas abiertas (necesito confirmación del usuario)

1. **Auth**: ¿creamos un sistema de login propio o `iaiapro.com` ya tiene usuarios/SSO que reutilizar?
2. **Modelo OpenRouter por defecto**: ¿alguna preferencia (Claude Sonnet 4.5, GPT-4o, Gemini 2.5 Pro)? Afecta coste/calidad.
3. **Assets**: ¿almacenamos en disco del VPS Hetzner o en Hetzner Object Storage / S3 desde el día 1?
4. **Alcance del MVP**: ¿qué es "mínimo viable"? Propongo:
   - Login + listar proyectos.
   - Crear proyecto desde prompt (OpenRouter → HTML).
   - Previsualización del HTML en iframe (sin render aún).
   - Render a MP4 vía render-api y descarga.
   - Editar proyecto con prompts sucesivos (chat).
   Dejar fuera del MVP: editor timeline visual, biblioteca de assets compartida, colaboración multi-user en un mismo proyecto, billing.
5. **VPS Hetzner**: ¿ya está provisionado? ¿SO (Ubuntu 24.04 LTS)? ¿tenemos acceso SSH ahora o lo preparamos nosotros?
6. **Dominio**: ¿`videos.iaiapro.com` ya apunta a algún sitio o lo configuramos ahora? ¿DNS lo gestionas tú?
7. **PHP**: ¿versión disponible (8.2+ recomendado)? ¿Composer permitido? ¿framework (Slim, Laravel) o PHP "a pelo" con router propio?

## High-level Task Breakdown

> Orden propuesto. Cada tarea con criterio de éxito verificable. El Executor sólo ejecuta una tarea a la vez y espera confirmación del Planner/usuario.

### Fase 0 — Setup

1. **Resolver preguntas abiertas** (sección 8). *Éxito*: documento con decisiones firmadas en este scratchpad.
2. **Bootstrap del repo**: crear estructura de carpetas, `composer.json`, `.editorconfig`, `.gitignore`, `README.md`. *Éxito*: `composer install` corre sin errores; repo inicializado en git.
3. **Definir migraciones MySQL iniciales** (`db/migrations/0001_init.sql`) con las tablas de la sección 7. *Éxito*: script idempotente aplicable en local con Docker MySQL; test manual OK.

### Fase 1 — Render API (Node + Hyperframes) en VPS

4. **Probar Hyperframes en local** (`npx hyperframes init demo`, preview, render). *Éxito*: MP4 de demo generado en máquina del usuario.
5. **Construir `render-api` mínima** (Node + Fastify): endpoints `POST /projects`, `PUT /projects/:id/composition`, `POST /projects/:id/assets`, `POST /projects/:id/render`, `GET /renders/:id`, `GET /renders/:id/output.mp4`. Cola in-process al inicio (sin Redis). Auth por `Bearer` token. *Éxito*: desde `curl` se crea un proyecto, se sube un HTML de composición y un asset, se lanza render y se descarga el MP4.
6. **Provisionar VPS Hetzner**: Ubuntu 24.04, Node 22, FFmpeg, Nginx reverse proxy con TLS (Let's Encrypt) en `render-api.iaiapro.com` (o similar), systemd unit, usuario no-root. *Éxito*: `curl https://render-api.iaiapro.com/health` devuelve 200 con token.

### Fase 2 — Frontend PHP

7. **Auth + layout**: login/register, dashboard vacío. *Éxito*: usuario puede registrarse, loguearse y ver dashboard.
8. **Integración OpenRouter**: cliente PHP con `OPENROUTER_API_KEY` en `.env`; system prompt que codifica las reglas de composición Hyperframes; endpoint `POST /api/prompt`. *Éxito*: dado un prompt, devuelve HTML de composición válido parseado desde la respuesta del LLM.
9. **CRUD de proyectos**: listado, crear proyecto desde prompt, ver detalle con HTML de composición y preview en `<iframe srcdoc>`. *Éxito*: usuario crea proyecto con prompt, ve el HTML generado y una preview estática.
10. **Upload de assets**: formulario → PHP → reenvío a `render-api`. *Éxito*: asset aparece en proyecto y el HTML puede referenciarlo.
11. **Lanzar render y polling de estado**: botón "Render" → POST al backend → backend encola en render-api → UI hace polling → descarga MP4. *Éxito*: vídeo MP4 descargado desde el navegador tras render completo.
12. **Chat de edición**: historial en `prompts`, cada mensaje reemplaza/actualiza el HTML del proyecto; mostrar diff opcional. *Éxito*: usuario itera "hazlo más corto" y ve cambio reflejado en preview.

### Fase 3 — Hardening

13. Cuotas por usuario, rate limiting, logs, backups de MySQL, monitorización básica (uptime render-api).
14. Documentación de despliegue en `deploy/README.md`.

## Plan Revisado (2026-04-19)

Tras el avance del usuario en el VPS y una propuesta alternativa recibida (super prompt), se ratifica la arquitectura original con estos ajustes:

### Arquitectura definitiva (Opción A + Monorepo)

```
https://videos.iaiapro.com/
    /                 → PHP (auth, CRUD proyectos, plantillas, uploads, UI)
    /storage/*        → nginx static (MP4s, thumbnails, uploads)
    (interno only)    → Node "render worker" escuchando en 127.0.0.1:3001
```

- **PHP vanilla** concentra toda la lógica de negocio y acceso a MySQL (PDO).
- **Node** se reduce a un **worker de render**: recibe `POST /render` con `{job_id, composition_html, assets[], output_path}`, ejecuta `hyperframes render`, emite progreso y resultado. No toca BBDD. No público.
- **Nginx**: se retira el proxy público `/api/*` → Node (estaba expuesto sin auth). Queda sólo accesible por `127.0.0.1` desde PHP.
- **Estáticos**: `/storage/*` servido por nginx desde `/home/dvdgp/data/videos/public/` (ruta a definir en `deploy/nginx`).

### Monorepo

```
videos-iaiapro/
├── frontend/           # PHP vanilla (DocumentRoot = frontend/public)
│   ├── public/
│   └── src/
├── backend/            # Node worker (systemd WorkingDirectory)
│   ├── src/server.js
│   └── package.json
├── db/migrations/
├── deploy/
│   ├── nginx/          # vhost versionado
│   └── systemd/        # videos-backend.service versionado
└── .cursor/
```

- Despliegue: `git pull` en `/home/dvdgp/apps/videos-iaiapro` → `systemctl restart videos-backend` (+ cualquier composer install futuro).
- El `videos-backend.service` cambia `WorkingDirectory` y `ExecStart` a `backend/src/server.js`.

### Producto: híbrido empezando por plantillas (v1 sin LLM)

**MVP v1** (actual):
- Registro/login.
- Usuario ve catálogo de **plantillas predefinidas** (código Hyperframes en `backend/templates/`).
- Crea proyecto eligiendo plantilla + rellenando campos (title, subtitle, description, cta, brand_name, colores, duración, formato).
- Sube assets (logo, imagen principal, galería).
- Lanza render → worker Node compila la plantilla con los datos del proyecto → MP4.
- Ve estado (queued/rendering/done/failed), preview y descarga.

**MVP v2** (después, cuando v1 esté estable):
- Añadir chat con **OpenRouter** (`google/gemini-3-flash-preview` o el que toque) para personalizar la plantilla elegida en lenguaje natural ("hazlo más oscuro", "cambia la música").
- El LLM devuelve un HTML modificado que reemplaza el compilado desde la plantilla.
- Se activan las tablas `prompts` (ya creadas) y la columna `composition_html` en `projects` (también ya creada).

### Cambios en el esquema MySQL (migración 0002)

Necesarios para soportar plantillas con campos en v1:

- `projects`: añadir columnas `template_id VARCHAR(64)`, `format ENUM('16:9','9:16','1:1')`, `content_json JSON`, `style_json JSON`, `render_progress TINYINT UNSIGNED`, `render_message VARCHAR(255)`.
- `projects.status`: ampliar ENUM a `('draft','queued','rendering','completed','failed','archived')` para cubrir el ciclo completo.
- `assets`: añadir `role ENUM('logo','main_image','gallery','extra') NOT NULL DEFAULT 'extra'` y `position SMALLINT UNSIGNED NOT NULL DEFAULT 0` para ordenar la galería.

La migración `0001` queda tal cual (ya ejecutada). Se añadirá `0002_templates_v1.sql` al avanzar.

### API pública (PHP) v1

Convención: `/api/*` ahora servido por PHP (no por Node). Auth por cookie de sesión.

- `POST /api/auth/register` `{email,password,name}` → 201 + sesión.
- `POST /api/auth/login` `{email,password}` → 200 + sesión.
- `POST /api/auth/logout`.
- `GET  /api/me`.
- `GET  /api/templates` → lista de plantillas (leídas desde `backend/templates/`, cacheadas en PHP).
- `GET  /api/projects`.
- `POST /api/projects` `{name,template_id,format,duration,content,style}`.
- `GET  /api/projects/:id`.
- `PUT  /api/projects/:id`.
- `DELETE /api/projects/:id`.
- `POST /api/projects/:id/assets` (multipart: `logo`, `main_image`, `gallery[]`).
- `POST /api/projects/:id/render` → PHP crea fila en `renders`, llama a Node `http://127.0.0.1:3001/render`.
- `GET  /api/projects/:id/status` → progreso + urls de thumbnail/preview/download.
- `GET  /api/projects/:id/download` → redirect 302 a `/storage/...`.

### API interna (Node worker) v1

- `GET  /health`.
- `POST /render` `{job_id, composition_html, assets_dir, output_path, width, height, duration}` → 202 accepted, encola y responde.
- `GET  /render/:job_id` → `{status, progress, message, error?}`.
- Callback opcional: Node hace `POST http://127.0.0.1/api/internal/render-callback` (token compartido) cuando cambia estado — evita polling del lado PHP. **Para v1 arrancamos con polling** (simpler) y se puede evolucionar a callback.

## Project Status Board

### Hecho

- [x] 0.1 Preguntas abiertas resueltas (auth propia, storage disco VPS, modelo `google/gemini-3-flash-preview`, PHP vanilla, MVP confirmado y re-confirmado como "plantillas-first" v1).
- [x] 0.2 Bootstrap del repo (PHP vanilla + router + .env loader).
- [x] 0.3 Migración 0001 (users/sessions/projects/assets/renders/prompts). **Ejecutada por el usuario en VPS.**
- [x] 1.6 VPS provisionado por el usuario (Ubuntu 22.04, Node 22, FFmpeg, Chrome headless, Hyperframes, systemd `videos-backend`, nginx + SSL Let's Encrypt, `/api/health` OK).

### Replanificación v1 (plantillas, sin LLM)

- [x] **R.1 Reorganizar monorepo** (2026-04-19): `public/` → `frontend/public/`, `src/` → `frontend/src/`, `composer.json` → `frontend/composer.json`. Creado `backend/` con `package.json` alineado al del servidor y `server.js` placeholder (que mantiene `/health` + compat `/api/health` hasta el switch de nginx). Borrado `render-api/`. Creadas `deploy/nginx/` y `deploy/systemd/`. `frontend/src/bootstrap.php` ajustado para leer `.env` del root del monorepo.
- [x] **R.2 Migración 0002** (2026-04-19): `db/migrations/0002_templates_v1.sql` escrita. Cambios: `projects` (+`template_id`, `format`, `content_json`, `style_json`, `render_progress`, `render_message`; `status` ampliado) y `assets` (+`role`, `position`). Pendiente ejecución en el VPS por el usuario.
- [x] **R.3 Backend Node worker** (2026-04-19): escrito. Archivos: `backend/src/server.js` (Express, escucha 127.0.0.1), `config.js`, `auth.js` (Bearer `RENDER_API_TOKEN`), `queue.js` (cola in-memory, 1 render concurrente), `renderer.js` (spawn `hyperframes render`, parse progreso frame X/Y, timeout duro, validación de paths para que no salgan de `projectsDir`/`tempDir`/`rendersDir`). Endpoints: `GET /health` (sin auth), `POST /render`, `GET /render/:id`. **Pendiente smoke test end-to-end en VPS** tras el cutover (R.5).
- [x] **R.4 Primera plantilla Hyperframes** (2026-04-20): `backend/templates/basic-promo/` con `meta.json` (id, name, fields, style_fields, assets, formats), `hyperframes.json` y `index.html.tmpl` (10s con GSAP: top bar con logo+brand, título+subtítulo+CTA animados, fondo con `main_image` opcional dimmed por overlay). Convención documentada en `backend/templates/README.md`. Hyperframes no ofrece plantillas útiles offline (`catalog` vacío; sólo `blank` funciona). **Smoke test verde**: template compilado manualmente con `sed` en VPS → MP4 de 395 KB en ~35s (1080p, quality=draft). MP4 revisado en Mac. Nota: hyperframes quiere un `meta.json` en el directorio del proyecto (además del nuestro `meta.json` de plantilla); PHP en R.7 debe escribirlo al crear el proyecto.
- [x] **R.5 Cutover nginx + systemd + DocumentRoot** (2026-04-20): ejecutado vía SSH. (a) `.env` generado en VPS con secretos `openssl rand -hex 32` para APP_SECRET y RENDER_API_TOKEN, permisos 600 `dvdgp:dvdgp`. (b) migración 0003 aplicada. (c) dirs `/home/dvdgp/data/videos/{projects,uploads,renders,thumbnails,temp}` creados. (d) systemd unit nuevo copiado desde `deploy/systemd/`, `daemon-reload+restart`; worker nuevo versión 0.2.0 activo. (e) nginx includes `conf_api` y `ssl.conf_api` vaciados (ya no proxy a :3001); `conf_storage` y `ssl.conf_storage` añadidos para `/storage/renders/` y `/storage/thumbnails/`. (f) `public_html` movido a `public_html.hestia-default` y reemplazado por symlink a `frontend/public/`. (g) **Incidentes resueltos durante el cutover**: (1) PHP `open_basedir` de Hestia no cubría `/home/dvdgp/apps/` ni `/home/dvdgp/data/` → extendido en `/etc/php/8.3/fpm/pool.d/videos.iaiapro.com.conf` (nota: puede rebuildarse si se usa `v-rebuild-web-domain`); (2) sin `.htaccess` Apache devolvía 404 en todas las rutas salvo `/` → añadido rewrite front-controller; (3) nginx glob `nginx.conf_*` estaba cargando también mis `*.bak-pre-cutover` reactivando el proxy viejo a Node → backups movidos a `/root/backup-pre-cutover-videos/`. **Smoke test auth verde**: register → 303 /dashboard, `/api/me` 200 con usuario, logout → 303 /, `/api/me` 401. DB limpia tras tests.
- [x] **R.6 Auth PHP** (2026-04-20): escrito. `frontend/src/Database/Db.php` (PDO singleton), `Auth/Password.php` (Argon2id), `Auth/SessionStore.php` (DB-backed, sliding expiration, CSRF via HMAC APP_SECRET), `Auth/UserRepository.php`, `Auth/RateLimiter.php` (10 fails/IP/10min, 5 fails/email/10min, con tabla nueva), `Auth/Auth.php` (orquestación). Controllers: `AuthController` (login/register/logout). Vistas PHP: layout, home, login, register, dashboard. Migración `0003_auth.sql` (tabla `login_attempts`). `REGISTRATION_ENABLED` y `APP_SECRET` añadidos al `.env.example`. **Pendiente smoke test en VPS tras cutover (R.5)**.
- [x] **R.7 CRUD plantillas + proyectos (PHP)** (2026-04-20): nuevo namespace `App\Templates` (`TemplateRegistry` lee `backend/templates/*/meta.json` con cache por request + validación de id; `Format` mapea 16:9/9:16/1:1 → 1920x1080/1080x1920/1080x1080), `App\Projects` (`ProjectValidator` valida `name`, `template_id`, `format`, `content` con `required`/`max_length` y sanitiza control chars, `style` con validación de colores hex; rechaza claves no declaradas; `ProjectRepository` con `create`/`findByIdForUser`/`listForUser`/`update`/`delete` + proyecciones `toApi` (full) y `toApiSummary` (list)). Nuevo `App\Http\Api` helper (`requireAuth`→401 JSON, `requireCsrf` por header `X-CSRF-Token` o body `_csrf`, `readJsonBody` con JSON_THROW + 400, `respond`/`fail`). Controllers `TemplatesController` y `ProjectsController`. Rutas: `GET /api/csrf`, `GET /api/templates`, `GET /api/templates/{id}`, `GET /api/projects`, `POST /api/projects`, `GET /api/projects/{id}`, `PUT /api/projects/{id}`, `DELETE /api/projects/{id}`. **Smoke test 11/11 verde en producción**: list templates sin auth, register+login, CSRF flow, POST proyecto válido (201), list, validación de título obligatorio (422), POST sin CSRF (403), PUT rename, DELETE, list vacío.
- [x] **R.8 Uploads de assets (PHP)** (2026-04-20): namespace `App\Assets`. `AssetService::store` valida: (1) tamaño ≤ 5 MB; (2) MIME real vía `finfo_file` (whitelist `image/{png,jpeg,webp,svg+xml}`); (3) rol existe en `meta.json` de la plantilla; (4) si el template tiene `accept`, se respeta por rol (p.ej. `main_image` no acepta SVG). Persiste en `/home/dvdgp/data/videos/projects/<id>/assets/<role>.<ext>` con `move_uploaded_file` + `rename` atómico; borra previos `<role>.*`. Calcula `sha256` y dimensiones (no SVG). `AssetRepository::upsert` usa `INSERT … ON DUPLICATE KEY UPDATE` (unique `project_id,role`). Migración `0004_assets_role.sql` añade columna `role`, `sha256` y unique key. `AssetsController` en `/api/projects/{id}/assets` (GET list, POST upload multipart, DELETE /assets/{role}) con CSRF via `X-CSRF-Token` (o `_csrf` en form). `AssetUploadException` con httpStatus + extras. **Bug nginx arreglado**: servir `/storage/projects/<id>/assets/*` requería `location ^~ /storage/projects/ { alias /home/dvdgp/data/videos/projects/; ... }` (regex puro no matcheaba por el static handler anidado dentro de `location /`). Añadido también denial explícito a paths que no sean `assets/` dentro de cada proyecto. **Smoke test 9/9 verde en producción**: upload PNG válido (201 con URL pública), list assets (200), upload text/plain (415 unsupported_mime), DELETE role (200), HTML pages /projects, /projects/new, /projects/{id} (200), + render end-to-end con logo subido sirviendo MP4 público 200.
- [x] **R.9 Render end-to-end** (2026-04-20): [previo]
- [x] **R.9 Render end-to-end** (2026-04-20): nuevo namespace `App\Render`. `TemplateCompiler` sustituye `{{placeholders}}` con tres categorías de escaping (runtime num, colores validados `#RRGGBB`, texto con `htmlspecialchars`); `ProjectMaterializer` genera `/home/dvdgp/data/videos/projects/<id>/{hyperframes.json,meta.json,index.html,assets/}` con escritura atómica (.tmp+rename); `WorkerClient` hace HTTP a `http://127.0.0.1:3001` con Bearer `RENDER_API_TOKEN` via curl (timeout 10s); `WorkerNotFoundException` para distinguir 404; `RenderRepository` con `create`, `findLatestForProject`, `applyWorkerStatus` (mapea worker status → `renders.status`, rellena size_bytes/started_at/finished_at via `STR_TO_DATE`), `markStaleAsFailed`; `RenderService` orquesta: materialise → insert renders row (status=queued) → POST /render al worker → applyWorkerStatus + updateProjectFromRender (mapea worker→project.status: done→completed, failed→failed, rendering→rendering). `syncFromWorker` poll-en-demanda: sólo contacta al worker si el render está en `queued`/`rendering`; si worker devuelve 404 marca como failed (stale tras restart del servicio). `RendersController` con rutas `POST /api/projects/{id}/render` (202 + render row; 409 si ya hay uno activo) y `GET /api/projects/{id}/status` (devuelve `{project, render}` con `video_url` computado para `/storage/renders/<job_id>.mp4`). **Bug encontrado y arreglado**: `Env::get` exige `?string` como default, pasé `30` (int) para RENDER_FPS → casteado a `'30'`. **Smoke test end-to-end verde**: register → create project id=2 → POST /render (202 HTTP, status=queued, progress=1%) → 45s después → status=done, progress=100%, size_bytes=240435, video_url público. `curl -I` del MP4 vía `/storage/renders/` sirve con `Content-Type: video/mp4` y cache headers. ffprobe: h264 1920x1080 30fps 10s exacto. El `index.html` compilado tiene todos los placeholders sustituidos correctamente. **Worker reiniciado para recoger `RENDER_QUALITY=draft` del .env** (cambiado temporalmente para acelerar tests; volver a `standard` antes de UI pública).
- [x] **R.10 UI mínima PHP** (2026-04-20): vistas server-side + vanilla JS cliente. `ProjectsPageController` sirve `/projects` (grid con badges por status `completed/failed/rendering/queued`), `/projects/new` (wizard de 1 paso: nombre + plantilla + formato, con selector de formato que reacciona a la plantilla elegida), `/projects/{id}` (editor en 2 columnas: preview con `<video>` + status/progreso a la izquierda; formulario con fields de `content`, `style_fields` como `<input type=color>`, y panel de `assets` con upload/reemplazo/quitar a la derecha). Cliente en `/assets/app.js` envuelve `fetch()` con CSRF (header `X-CSRF-Token`, cookie de sesión en `credentials: same-origin`) y helpers `VI.get/post/put/del/upload`. Estilos en `/assets/app.css` (grid responsive, preview con aspect-ratio por formato, estados animados del dot de render). Render flow: "Guardar" hace PUT /api/projects/{id}; "Renderizar" primero guarda, luego POST /render y arranca polling cada 2s a /status hasta `completed|failed`; cuando completa pinta el `<video>` con `s.render.video_url + ?v=timestamp` para bust cache. Dashboard enlazado a `/projects` y `/projects/new`.
- [ ] **R.11 Hardening v1**: cuotas (minutos render/mes y MB storage) aplicadas en endpoints relevantes, CSRF en forms PHP, logs rotados, backup cron de MySQL.
- [ ] **R.12 Docs despliegue** en `deploy/README.md`.

### Fase v2 (post-MVP v1, no priorizado aún)

- [ ] V2.1 Integración OpenRouter (`google/gemini-3-flash-preview`) para chat de personalización sobre plantilla.
- [ ] V2.2 Historial en tabla `prompts`.
- [ ] V2.3 Preview en iframe con el HTML compilado antes de renderizar MP4.

## Decisions Log

- **Auth**: propia (email + password con `password_hash`, sesiones en MySQL). No SSO de iaiapro.com.
- **Storage**: disco del VPS Hetzner (MVP). Posible migración futura a Object Storage.
- **Modelo OpenRouter por defecto**: `google/gemini-3-flash-preview` (configurable por `.env`, fácil de cambiar).
- **VPS**: usuario tiene acceso SSH y panel de control. SO objetivo: Ubuntu 24.04 LTS.
- **DNS `videos.iaiapro.com`**: gestionado por el usuario, sin bloqueo.
- **Stack PHP**: **PHP vanilla 8.2+** (sin Laravel/Slim). Router mínimo propio, PDO para MySQL, sesiones nativas. Composer opcional sólo para dependencias puntuales (p.ej. `guzzlehttp/guzzle` para llamadas a OpenRouter / render-api, o `phpdotenv`). Se decidirá caso a caso.
- **Alcance MVP v1 (19/abr, REVISADO)**: **plantillas-first, sin LLM**. Catálogo de plantillas Hyperframes + wizard de creación (plantilla → campos → assets) + render MP4 + descarga. Multiusuario y cuotas sí. LLM/OpenRouter → v2.
- **Arquitectura (19/abr)**: Opción A — PHP concentra toda la lógica; Node es worker de render escuchando sólo en `127.0.0.1:3001`. Nginx retira el proxy público `/api/*` → Node. Monorepo con `frontend/` (PHP) y `backend/` (Node).
- **Despliegue**: repo clonado en VPS como `/home/dvdgp/apps/videos-iaiapro`. Se actualiza el `systemd` unit para apuntar a `backend/src/server.js`. Trabajo directamente contra servidor (no local dev).

## Current Status / Progress Tracking

- 2026-04-17: Plan inicial redactado y decisiones firmadas.
- 2026-04-17: **0.2 Bootstrap del repo** ejecutado. Estructura creada, router PHP mínimo, `.env` loader, autoloader PSR-4 manual. `/` y `/health` responden.
- 2026-04-17: `git init` + commit inicial realizados. Remote `origin` apuntando a `https://github.com/dvdgp9/videos-iaiapro.git` (push pendiente del usuario con sus credenciales).
- 2026-04-17: **0.3 Migraciones MySQL** escrita en `db/migrations/0001_init.sql`. Tablas: `users`, `sessions`, `projects`, `assets`, `renders`, `prompts`, `schema_migrations`. Pendiente de ejecución manual por el usuario.

## Server Snapshot (2026-04-19, via SSH root@91.98.155.109)

- **OS**: Ubuntu 22.04 (kernel 5.15), hostname `server.example.com`.
- **Panel**: Hestia CP (templates default).
- **Stack web**: `nginx :443` → `apache :8443` (SSL internal) → `php-fpm 8.3`. HTTP `:80` → `apache :8080`.
- **DocumentRoot**: `/home/dvdgp/web/videos.iaiapro.com/public_html/`.
- **SSL**: Let's Encrypt activo, cert en `/home/dvdgp/conf/web/videos.iaiapro.com/ssl/`.
- **BBDD**: MariaDB 11.4.10-ubu2204. Base `dvdgp_videos`, user `dvdgp_vid_usr`, migración 0001 **ejecutada** (7 tablas presentes).
- **PHP**: 8.3.30 CLI + php-fpm 8.3 (running).
- **Node**: 22.x. `videos-backend.service` activo, escucha en `127.0.0.1:3001`, `/api/health` OK.
- **Hyperframes**: 0.4.6 (en `/home/dvdgp/apps/videos-backend/node_modules`).
- **Datos**: `/home/dvdgp/data/videos/{uploads,renders,temp,projects,smoke-test}`. Ownership `dvdgp:dvdgp` excepto `projects/` que es `root:root` (a corregir).
- **Nginx includes relevantes** (todos bajo `/home/dvdgp/conf/web/videos.iaiapro.com/`):
  - `nginx.conf` y `nginx.ssl.conf` (default Hestia, **no modificar**).
  - `nginx.conf_api` y `nginx.ssl.conf_api` (proxy `/api` y `/api/*` → `127.0.0.1:3001`, **modificables**).
  - `nginx.conf_letsencrypt` (ACME challenge).
- **systemd `videos-backend.service`**: usuario `dvdgp`, WorkingDirectory `/home/dvdgp/apps/videos-backend`, EnvironmentFile `/home/dvdgp/apps/videos-backend/.env` (sólo contiene `PORT`).
- **Repo monorepo NO clonado aún** en `/home/dvdgp/apps/videos-iaiapro/`.

### Consecuencias para el plan

- **R.5 simplificada**: aprovechamos el stack Hestia tal cual.
  - `/home/dvdgp/web/videos.iaiapro.com/public_html` → symlink a `/home/dvdgp/apps/videos-iaiapro/frontend/public/`. Apache+PHP-FPM sirve el PHP sin tocar Hestia templates.
  - Los includes `nginx.conf_api` y `nginx.ssl.conf_api` se **vacían** (o sustituyen por la config de `/storage/`). `/api/*` pasa a ir al flujo nginx → apache → PHP.
  - Nuevo include `nginx.conf_storage` + `nginx.ssl.conf_storage` servirá `/storage/` desde `/home/dvdgp/data/videos/public/` (nginx static, sin pasar por Apache).
  - `videos-backend.service`: `WorkingDirectory` y `ExecStart` al nuevo path; `EnvironmentFile` al `.env` común del monorepo.
- **R.3 reescritura del Node**: el `server.js` actual es trivial; se reemplaza entero. Quitamos el prefijo `/api` (PHP se queda con él): endpoints Node serán `/health`, `/render`, `/render/:id` — sin prefijo.

## Executor's Feedback or Assistance Requests

- **0.2 completada (pending)**. Verificación manual sugerida:
  ```bash
  cp .env.example .env
  php -S 127.0.0.1:8000 -t public public/index.php
  # Luego en el navegador: http://127.0.0.1:8000  y  http://127.0.0.1:8000/health
  ```
  Criterios de éxito: la home muestra "Bootstrap OK" y `/health` devuelve JSON `{status: ok, ...}`. Confirma y pasamos a **0.3 Migraciones MySQL iniciales**.
- Nota: no se ha inicializado `git` todavía. ¿Quieres que lo haga ahora (`git init` + commit inicial) o prefieres hacerlo tú?

## Lessons

- Hyperframes es CLI Node.js (no API HTTP): requiere un microservicio wrapper para uso desde web.
- Requisitos de Hyperframes: Node.js >= 22 y FFmpeg en el host de render.
- Composiciones Hyperframes = HTML con atributos `data-*` sobre `<video>/<img>/<audio>` dentro de un `#stage` — encaja bien con generación por LLM.
