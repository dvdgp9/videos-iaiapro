// videos.iaiapro.com — internal render worker
//
// HTTP layer. Listens ONLY on 127.0.0.1 (see config.host). All endpoints
// except GET /health require a Bearer token matching RENDER_API_TOKEN.
//
// Endpoints:
//   GET  /health              — liveness + queue stats (no auth)
//   POST /render              — enqueue a render job          (auth)
//   GET  /render/:id          — job status                    (auth)
//
// Input contract for POST /render:
// {
//   "job_id":       "r_ab12cd34...",            // required, unique
//   "project_dir":  "/home/dvdgp/data/videos/projects/<id>",
//   "output_path":  "/home/dvdgp/data/videos/renders/<job_id>.mp4",
//   "workers":      1,                          // optional
//   "quality":      "standard",                 // optional
//   "fps":          30,                         // optional
//   "format":       "mp4"                       // optional
// }

// Load monorepo root .env BEFORE importing config.js (which reads process.env).
import dotenv from 'dotenv';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
dotenv.config({ path: path.resolve(__dirname, '../../.env') });

import express from 'express';
import { config } from './config.js';
import { requireToken } from './auth.js';
import * as queue from './queue.js';

const app = express();
app.disable('x-powered-by');
app.use(express.json({ limit: '256kb' }));

// Request log — short and bounded.
app.use((req, res, next) => {
    const t0 = Date.now();
    res.on('finish', () => {
        console.log(`[${new Date().toISOString()}] ${req.method} ${req.url} -> ${res.statusCode} ${Date.now() - t0}ms`);
    });
    next();
});

// ---------------------------------------------------------------------------
// Health (no auth)
// ---------------------------------------------------------------------------
app.get('/health', (req, res) => {
    res.json({
        ok:       true,
        service:  'videos-backend',
        version:  '0.2.0',
        node:     process.version,
        queue:    queue.stats(),
    });
});

// ---------------------------------------------------------------------------
// Render (auth)
// ---------------------------------------------------------------------------
app.post('/render', requireToken, (req, res) => {
    const body = req.body || {};
    const errs = [];

    const id = typeof body.job_id === 'string' ? body.job_id.trim() : '';
    if (!/^[A-Za-z0-9_\-]{4,64}$/.test(id)) errs.push('job_id must be 4-64 chars [A-Za-z0-9_-]');
    if (queue.get(id)) errs.push(`job_id already exists: ${id}`);

    const projectDir = typeof body.project_dir === 'string' ? body.project_dir : '';
    const outputPath = typeof body.output_path === 'string' ? body.output_path : '';
    if (!projectDir.startsWith('/')) errs.push('project_dir must be an absolute path');
    if (!outputPath.startsWith('/')) errs.push('output_path must be an absolute path');

    const workers = body.workers !== undefined ? Number(body.workers) : undefined;
    if (workers !== undefined && !(Number.isInteger(workers) && workers >= 1 && workers <= 4)) {
        errs.push('workers must be an integer 1..4');
    }
    const fps = body.fps !== undefined ? Number(body.fps) : undefined;
    if (fps !== undefined && ![24, 30, 60].includes(fps)) {
        errs.push('fps must be 24, 30 or 60');
    }
    const quality = body.quality;
    if (quality !== undefined && !['draft', 'standard', 'high'].includes(quality)) {
        errs.push('quality must be draft|standard|high');
    }
    const format = body.format;
    if (format !== undefined && !['mp4', 'webm', 'mov'].includes(format)) {
        errs.push('format must be mp4|webm|mov');
    }

    if (errs.length) return res.status(400).json({ error: 'validation', details: errs });

    const job = queue.enqueue(id, {
        projectDir,
        outputPath,
        workers,
        quality,
        fps,
        format,
    });

    res.status(202).json(publicJob(job));
});

app.get('/render/:id', requireToken, (req, res) => {
    const job = queue.get(req.params.id);
    if (!job) return res.status(404).json({ error: 'not_found' });
    res.json(publicJob(job));
});

// Remove internal fields (like command/pid) from the public representation by default.
function publicJob(job) {
    return {
        job_id:      job.id,
        status:      job.status,
        progress:    job.progress,
        message:     job.message,
        queued_at:   job.queuedAt,
        started_at:  job.startedAt,
        finished_at: job.finishedAt,
        error:       job.error,
        output_size_bytes: job.outputSizeBytes,
    };
}

// ---------------------------------------------------------------------------
// 404 + error handler
// ---------------------------------------------------------------------------
app.use((req, res) => res.status(404).json({ error: 'not_found' }));
// eslint-disable-next-line no-unused-vars
app.use((err, req, res, _next) => {
    console.error('[server] unhandled error:', err);
    res.status(500).json({ error: 'internal', message: err?.message || 'error' });
});

app.listen(config.port, config.host, () => {
    console.log(`[videos-backend] listening on http://${config.host}:${config.port}`);
    console.log(`[videos-backend] projectsDir=${config.projectsDir}  rendersDir=${config.rendersDir}`);
});

// Graceful shutdown — let systemd restart us cleanly.
for (const sig of ['SIGINT', 'SIGTERM']) {
    process.on(sig, () => {
        console.log(`[videos-backend] received ${sig}, shutting down`);
        process.exit(0);
    });
}
