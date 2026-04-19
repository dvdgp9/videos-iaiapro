// Backend worker configuration — loaded once at startup.
//
// Reads from process.env. When invoked via systemd the EnvironmentFile directive
// already populates the env; when run locally (`npm run dev`) we also load the
// monorepo root `.env` in server.js before importing this module.

import path from 'node:path';
import fs from 'node:fs';

function required(key) {
    const v = process.env[key];
    if (!v || v.trim() === '') {
        console.error(`[config] Missing required env var: ${key}`);
        process.exit(1);
    }
    return v.trim();
}

function optional(key, def) {
    const v = process.env[key];
    return (v === undefined || v === '') ? def : v;
}

function intOpt(key, def) {
    const v = optional(key, String(def));
    const n = Number.parseInt(v, 10);
    return Number.isFinite(n) ? n : def;
}

export const config = {
    host: '127.0.0.1',
    port: intOpt('RENDER_API_PORT', intOpt('PORT', 3001)),
    token: required('RENDER_API_TOKEN'),
    nodeEnv: optional('NODE_ENV', 'production'),

    // Paths on the VPS. Everything is absolute; the worker never resolves
    // arbitrary relative paths sent by the client.
    projectsDir: optional('PROJECTS_DIR', '/home/dvdgp/data/videos/projects'),
    rendersDir:  optional('RENDERS_DIR',  '/home/dvdgp/data/videos/renders'),
    tempDir:     optional('TEMP_DIR',     '/home/dvdgp/data/videos/temp'),

    // Hyperframes CLI binary. Prefer the one in node_modules of this backend.
    // Falls back to `npx hyperframes` if the local one isn't found.
    hyperframesBin: optional(
        'HYPERFRAMES_BIN',
        path.resolve(process.cwd(), 'node_modules/.bin/hyperframes')
    ),

    // Render defaults. Conservative for 2 cores / 3.7 GB RAM.
    defaultWorkers: intOpt('RENDER_WORKERS', 1),
    defaultQuality: optional('RENDER_QUALITY', 'standard'), // draft|standard|high
    defaultFps:     intOpt('RENDER_FPS', 30),
    defaultFormat:  optional('RENDER_FORMAT', 'mp4'),       // mp4|webm|mov

    // Safety: maximum wall-clock time for a single render before killing it.
    renderTimeoutMs: intOpt('RENDER_TIMEOUT_MS', 10 * 60 * 1000), // 10 min

    // How many completed/failed jobs to remember in memory (FIFO).
    jobHistorySize: intOpt('JOB_HISTORY_SIZE', 200),
};

// Sanity checks on paths (warn only — PHP owns the directory lifecycle).
for (const d of [config.projectsDir, config.rendersDir, config.tempDir]) {
    if (!fs.existsSync(d)) {
        console.warn(`[config] WARN: directory does not exist: ${d}`);
    }
}

// Resolve hyperframes binary — if the direct path is not executable, fall back.
if (!fs.existsSync(config.hyperframesBin)) {
    console.warn(`[config] hyperframes binary not found at ${config.hyperframesBin} — will use "npx hyperframes"`);
    config.hyperframesBin = null; // signal to renderer to use npx
}
