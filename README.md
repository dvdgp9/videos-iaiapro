# videos.iaiapro.com

Frontend web (PHP vanilla + MySQL) para generar y editar vídeos con IA,
apoyándose en [Hyperframes](https://github.com/heygen-com/hyperframes) como
motor de render en un VPS Hetzner y en [OpenRouter](https://openrouter.ai)
para la generación de composiciones a partir de prompts.

## Arquitectura (MVP v1: plantillas, sin LLM)

```
 navegador ──HTTPS──▶ nginx :443 ──▶ Apache :8443 ──▶ PHP-FPM 8.3 (frontend/)
                            │                             │
                            │                             │ MariaDB (dvdgp_videos)
                            │                             │
                            │                             ▼
                            │       HTTP interno ──▶ Node :3001 (backend/)
                            │                             │
                            │                             ▼
                            │                       hyperframes CLI → MP4
                            │
                            └─ /storage/* ──▶ static files desde /home/dvdgp/data/videos/public/
```

- **Frontend PHP**: auth propia, CRUD de proyectos con plantillas, uploads, lanzamiento de renders y descarga.
- **Backend Node**: worker interno escuchando sólo en `127.0.0.1:3001`. Recibe `POST /render` desde PHP, ejecuta `hyperframes`, escribe el MP4. No expuesto públicamente.
- **MariaDB 11**: usuarios, sesiones, proyectos, assets, renders, historial de prompts.
- **Storage MVP**: disco local del VPS bajo `/home/dvdgp/data/videos/`.

**Post-MVP (v2)**: integración con OpenRouter para chat de personalización de plantillas en lenguaje natural.

Ver el plan vivo en [`.cursor/scratchpad.md`](.cursor/scratchpad.md).

## Estructura del repo (monorepo)

```
videos-iaiapro/
├── frontend/          # PHP vanilla 8.3 (Apache + PHP-FPM en producción)
│   ├── public/        # DocumentRoot (symlink desde /home/dvdgp/web/videos.iaiapro.com/public_html)
│   ├── src/           # Clases PHP PSR-4 bajo \App\
│   └── composer.json
├── backend/           # Node 22 worker interno (127.0.0.1:3001)
│   ├── src/server.js  # Render worker (hyperframes CLI wrapper)
│   └── package.json
├── db/migrations/     # .sql versionados, aplicados manualmente
├── deploy/
│   ├── nginx/         # Includes de Hestia (nginx.conf_*) versionados
│   └── systemd/       # videos-backend.service versionado
├── .env.example       # Plantilla; .env real sólo en el VPS
└── .cursor/           # Scratchpad del Planner/Executor
```

## Despliegue (resumen)

Trabajamos directamente contra el VPS (no hay entorno local). Flujo:

```bash
# En local
git add -A && git commit -m "..." && git push

# En el VPS (ssh root@videos.iaiapro.com)
cd /home/dvdgp/apps/videos-iaiapro
git pull
# Si hay cambios en backend/:
systemctl restart videos-backend
# Si hay migración nueva en db/migrations/:
mysql -u dvdgp_vid_usr -p dvdgp_videos < db/migrations/XXXX_*.sql
```

Setup inicial del VPS y configuración detallada de nginx/systemd: ver `deploy/README.md` (pendiente R.12).

## Licencia

Privado. Todos los derechos reservados.
