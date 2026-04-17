# videos.iaiapro.com

Frontend web (PHP vanilla + MySQL) para generar y editar vídeos con IA,
apoyándose en [Hyperframes](https://github.com/heygen-com/hyperframes) como
motor de render en un VPS Hetzner y en [OpenRouter](https://openrouter.ai)
para la generación de composiciones a partir de prompts.

## Arquitectura (MVP)

```
 navegador ──HTTPS──▶  videos.iaiapro.com (PHP 8.2+ vanilla, MySQL)
                              │
                              ├── OpenRouter  (prompt → HTML de composición)
                              │
                              └── render-api.iaiapro.com (Node + Hyperframes + FFmpeg)
                                    → MP4 en disco del VPS
```

- **Frontend PHP**: auth propia, CRUD de proyectos, chat iterativo con el LLM, preview en iframe, lanzamiento de renders y descarga de MP4.
- **`render-api/`**: microservicio Node.js (Fastify) que envuelve el CLI de Hyperframes. No se expone directamente a internet; sólo lo llama el backend PHP con un token compartido.
- **MySQL**: usuarios, proyectos, assets, renders, historial de prompts.
- **Storage MVP**: disco local del VPS.

Ver el plan vivo en [`.cursor/scratchpad.md`](.cursor/scratchpad.md).

## Estructura del repo

```
videos-iaiapro/
├── public/          # DocumentRoot del vhost PHP (index.php + assets estáticos)
├── src/             # Clases PHP (PSR-4 bajo \App\)
├── db/migrations/   # .sql versionados
├── render-api/      # microservicio Node (se despliega en el VPS)
├── deploy/          # nginx, systemd, notas de despliegue
└── .cursor/         # scratchpad del Planner/Executor
```

## Desarrollo local

Requisitos: PHP 8.2+, MySQL 8+, Composer (opcional), Node 22+ (para render-api).

```bash
cp .env.example .env
# editar credenciales

# servir el frontend con el built-in server de PHP
php -S 127.0.0.1:8000 -t public public/index.php
```

(El resto de pasos se documentará a medida que avancen las tareas 0.3+ del scratchpad.)

## Licencia

Privado. Todos los derechos reservados.
