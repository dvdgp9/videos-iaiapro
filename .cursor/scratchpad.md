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

## Project Status Board

- [ ] 0.1 Resolver preguntas abiertas (bloqueante)
- [x] 0.2 Bootstrap del repo (pendiente verificación manual del usuario)
- [ ] 0.3 Migraciones MySQL iniciales
- [ ] 1.4 Probar Hyperframes en local
- [ ] 1.5 render-api mínima
- [ ] 1.6 Provisionar VPS Hetzner
- [ ] 2.7 Auth + layout PHP
- [ ] 2.8 Integración OpenRouter
- [ ] 2.9 CRUD proyectos + preview
- [ ] 2.10 Upload assets
- [ ] 2.11 Render + polling
- [ ] 2.12 Chat de edición
- [ ] 3.13 Hardening
- [ ] 3.14 Docs de despliegue

## Decisions Log

- **Auth**: propia (email + password con `password_hash`, sesiones en MySQL). No SSO de iaiapro.com.
- **Storage**: disco del VPS Hetzner (MVP). Posible migración futura a Object Storage.
- **Modelo OpenRouter por defecto**: `google/gemini-3-flash-preview` (configurable por `.env`, fácil de cambiar).
- **VPS**: usuario tiene acceso SSH y panel de control. SO objetivo: Ubuntu 24.04 LTS.
- **DNS `videos.iaiapro.com`**: gestionado por el usuario, sin bloqueo.
- **Stack PHP**: **PHP vanilla 8.2+** (sin Laravel/Slim). Router mínimo propio, PDO para MySQL, sesiones nativas. Composer opcional sólo para dependencias puntuales (p.ej. `guzzlehttp/guzzle` para llamadas a OpenRouter / render-api, o `phpdotenv`). Se decidirá caso a caso.
- **Alcance MVP**: confirmado (login, CRUD proyectos desde prompt, preview en iframe, render MP4 con polling, chat de edición iterativo). Fuera: timeline visual, biblioteca compartida, colaboración, billing.

## Current Status / Progress Tracking

- 2026-04-17: Plan inicial redactado y decisiones firmadas.
- 2026-04-17: **0.2 Bootstrap del repo** ejecutado. Estructura creada (`public/`, `src/`, `db/migrations/`, `render-api/`, `deploy/`), router PHP mínimo, cargador `.env`, autoloader PSR-4 manual (sin dependencia obligatoria de Composer), `composer.json` opcional, `.env.example`, `.editorconfig`, `.gitignore`, `README.md`. Endpoint `/` muestra OK y `/health` devuelve JSON.
- Pendiente de verificación manual del usuario (ver comandos abajo).

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
