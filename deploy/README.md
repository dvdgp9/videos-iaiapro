# Despliegue

Configuración versionada que se aplica en el VPS Hestia.

## Disposición en el VPS (tras R.5)

```
/home/dvdgp/
├── apps/videos-iaiapro/              # este repo
│   ├── frontend/public/              # ← target del symlink DocumentRoot
│   ├── backend/
│   └── .env                          # sólo en VPS, NO versionado
└── data/videos/
    ├── projects/<id>/                # composición + assets (PHP escribe)
    ├── uploads/<user>/<project>/     # servidos por PHP con auth
    ├── renders/<id>.mp4              # público vía /storage/renders/
    ├── thumbnails/<id>.jpg           # público vía /storage/thumbnails/
    └── temp/

/home/dvdgp/web/videos.iaiapro.com/
└── public_html -> /home/dvdgp/apps/videos-iaiapro/frontend/public/
```

## `.env` (sólo VPS)

Se mantiene en `/home/dvdgp/apps/videos-iaiapro/.env`. Lo leen PHP (vía
`Env::load()`) y Node (`dotenv` + systemd `EnvironmentFile`). Nunca se
commitea.

Secretos a generar:

```bash
openssl rand -hex 32   # → APP_SECRET
openssl rand -hex 32   # → RENDER_API_TOKEN
```

## Cutover R.5 — pasos

Cada paso es reversible. Parar y revertir al mínimo susto.

### 1. Pull + `.env` + migraciones

```bash
cd /home/dvdgp/apps/videos-iaiapro
sudo -u dvdgp git pull
sudo -u dvdgp cp .env.example .env   # y luego editar con valores reales
mysql -u dvdgp_vid_usr -p dvdgp_videos < db/migrations/0003_auth.sql
```

### 2. Directorios de datos

```bash
sudo -u dvdgp mkdir -p /home/dvdgp/data/videos/{projects,uploads,renders,thumbnails,temp}
```

### 3. systemd unit nuevo

```bash
cp deploy/systemd/videos-backend.service /etc/systemd/system/videos-backend.service
systemctl daemon-reload
systemctl restart videos-backend
curl -s http://127.0.0.1:3001/health
```

Rollback: pegar el viejo contenido al `.service`, `daemon-reload`, `restart`.

### 4. Includes de nginx

```bash
cd /home/dvdgp/conf/web/videos.iaiapro.com/
cp /home/dvdgp/apps/videos-iaiapro/deploy/nginx/videos.iaiapro.com.nginx.conf_api     ./nginx.conf_api
cp /home/dvdgp/apps/videos-iaiapro/deploy/nginx/videos.iaiapro.com.nginx.ssl.conf_api ./nginx.ssl.conf_api
cp /home/dvdgp/apps/videos-iaiapro/deploy/nginx/videos.iaiapro.com.nginx.conf_storage     ./nginx.conf_storage
cp /home/dvdgp/apps/videos-iaiapro/deploy/nginx/videos.iaiapro.com.nginx.ssl.conf_storage ./nginx.ssl.conf_storage
nginx -t && systemctl reload nginx
```

Rollback: volver a poner el contenido anterior del `conf_api` (proxy a
`127.0.0.1:3001`), borrar los `conf_storage`, reload.

### 5. Swap DocumentRoot (crítico)

```bash
cd /home/dvdgp/web/videos.iaiapro.com/
sudo -u dvdgp mv public_html public_html.hestia-default
sudo -u dvdgp ln -s /home/dvdgp/apps/videos-iaiapro/frontend/public public_html
curl -sI https://videos.iaiapro.com/
```

Rollback: `rm public_html && mv public_html.hestia-default public_html`.

### 6. Verificación

```bash
curl -s  https://videos.iaiapro.com/health                # JSON de PHP
curl -sI https://videos.iaiapro.com/ | head -5            # 200 OK
# Prueba auth:
curl -c /tmp/c.jar -sI https://videos.iaiapro.com/register
curl -b /tmp/c.jar -s   https://videos.iaiapro.com/api/me # 401
```

## Despliegue incremental

Para cambios normales (código PHP, worker, migraciones nuevas):

```bash
cd /home/dvdgp/apps/videos-iaiapro
sudo -u dvdgp git pull
# Si cambió backend/:
cd backend && sudo -u dvdgp npm install --omit=dev && cd ..
systemctl restart videos-backend
# Si hay migración nueva:
mysql -u dvdgp_vid_usr -p dvdgp_videos < db/migrations/00XX_*.sql
```

No hace falta reiniciar nada del frontend PHP (FPM recarga opcache
automáticamente cuando cambian los archivos).
