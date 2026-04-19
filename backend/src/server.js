// videos.iaiapro.com — internal render worker
//
// Binds to 127.0.0.1 only (never expose publicly). The PHP frontend calls this
// service over plain HTTP on localhost. Nginx MUST NOT proxy /api/* to this
// port any more — all /api/* traffic now belongs to PHP.
//
// NOTE: this is a minimal placeholder kept in sync with the previous
// `videos-backend/src/server.js` so that restarting the systemd unit on the
// new monorepo path keeps /health responding. The real render worker
// (POST /render, GET /render/:id, hyperframes integration) is implemented in
// task R.3.

import express from 'express';
import dotenv from 'dotenv';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Load .env from the monorepo root (../.env relative to backend/src/).
const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);
dotenv.config({ path: path.resolve(__dirname, '../../.env') });
// Fallback: also honour EnvironmentFile from systemd (vars already in env).

const app  = express();
const PORT = Number(process.env.RENDER_API_PORT || process.env.PORT || 3001);
const HOST = '127.0.0.1';

app.use(express.json({ limit: '10mb' }));

app.get('/health', (req, res) => {
    res.json({
        ok: true,
        service: 'videos-backend',
        version: '0.1.0',
        port: PORT,
        node: process.version,
    });
});

// Keep legacy /api/health working until nginx stops proxying /api/* here
// and we cut over to PHP. Safe to remove after R.5 is deployed.
app.get('/api/health', (req, res) => {
    res.json({
        ok: true,
        service: 'videos-backend',
        version: '0.1.0',
        port: PORT,
        note: 'legacy path; use /health once nginx switch is done',
    });
});

app.listen(PORT, HOST, () => {
    console.log(`[videos-backend] listening on http://${HOST}:${PORT}`);
});
