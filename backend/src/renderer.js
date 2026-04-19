// Hyperframes CLI wrapper. Spawns one render at a time.
//
// Design:
//  - `startRender(job)` returns a Promise that resolves when hyperframes exits.
//  - While running, it mutates `job.progress` / `job.message` so the HTTP
//    layer can report status via GET /render/:id.
//  - Progress is best-effort: hyperframes prints frame-by-frame logs; we
//    parse "Rendered frame X/Y" style lines when present. If the format
//    changes we still report rendering / done / failed reliably.
//  - A hard timeout (config.renderTimeoutMs) kills the process.

import { spawn } from 'node:child_process';
import path from 'node:path';
import fs from 'node:fs';
import { config } from './config.js';

/**
 * Build argv for the hyperframes render command.
 *
 * @param {object} opts
 * @param {string} opts.projectDir   Absolute path to the hyperframes project dir.
 * @param {string} opts.outputPath   Absolute path for the output MP4.
 * @param {number} [opts.workers]
 * @param {string} [opts.quality]    draft|standard|high
 * @param {number} [opts.fps]
 * @param {string} [opts.format]     mp4|webm|mov
 */
function buildArgs(opts) {
    const args = [
        'render',
        opts.projectDir,
        '--output', opts.outputPath,
        '--workers', String(opts.workers ?? config.defaultWorkers),
        '--quality', opts.quality ?? config.defaultQuality,
        '--fps', String(opts.fps ?? config.defaultFps),
        '--format', opts.format ?? config.defaultFormat,
    ];
    return args;
}

/**
 * Resolve binary+argv for spawning hyperframes.
 * Returns [command, args].
 */
function resolveCommand(args) {
    if (config.hyperframesBin) {
        return [config.hyperframesBin, args];
    }
    return ['npx', ['--yes', 'hyperframes', ...args]];
}

// Matches common progress patterns emitted by hyperframes/puppeteer.
// Examples handled:
//   "Rendered frame 42 / 300"
//   "Capturing frame 42/300"
//   "[1/300] ..."
const PROGRESS_RX = /(?:frame\s+)?(\d+)\s*\/\s*(\d+)/i;

function parseProgress(line) {
    const m = line.match(PROGRESS_RX);
    if (!m) return null;
    const cur = Number.parseInt(m[1], 10);
    const tot = Number.parseInt(m[2], 10);
    if (!Number.isFinite(cur) || !Number.isFinite(tot) || tot <= 0) return null;
    return Math.max(0, Math.min(99, Math.round((cur / tot) * 100)));
}

/**
 * Run a render synchronously (one at a time).
 * @param {object} job  mutable job object managed by queue.js
 */
export function startRender(job) {
    return new Promise((resolve) => {
        const { projectDir, outputPath } = job.input;

        // Defensive checks — reject paths that try to escape the expected roots.
        const absProject = path.resolve(projectDir);
        const absOutput  = path.resolve(outputPath);
        if (!absProject.startsWith(path.resolve(config.projectsDir) + path.sep) &&
            !absProject.startsWith(path.resolve(config.tempDir) + path.sep)) {
            return finish(job, false, `project dir outside allowed roots: ${absProject}`, resolve);
        }
        if (!absOutput.startsWith(path.resolve(config.rendersDir) + path.sep)) {
            return finish(job, false, `output path outside renders dir: ${absOutput}`, resolve);
        }
        if (!fs.existsSync(absProject)) {
            return finish(job, false, `project dir does not exist: ${absProject}`, resolve);
        }

        // Ensure output dir exists
        fs.mkdirSync(path.dirname(absOutput), { recursive: true });

        const args = buildArgs({ ...job.input, projectDir: absProject, outputPath: absOutput });
        const [cmd, argv] = resolveCommand(args);

        job.status     = 'rendering';
        job.startedAt  = new Date().toISOString();
        job.message    = 'Starting hyperframes render…';
        job.progress   = 1;
        job.command    = [cmd, ...argv].join(' ');

        const child = spawn(cmd, argv, {
            cwd: path.dirname(absProject),
            stdio: ['ignore', 'pipe', 'pipe'],
            env: { ...process.env },
        });
        job.pid = child.pid;

        let logBuf = '';
        const appendLog = (chunk) => {
            const s = chunk.toString();
            logBuf += s;
            // Keep log bounded: last 64 KB.
            if (logBuf.length > 65536) logBuf = logBuf.slice(-65536);
            for (const line of s.split(/\r?\n/)) {
                if (!line.trim()) continue;
                const p = parseProgress(line);
                if (p !== null) {
                    job.progress = p;
                }
                // Use last non-empty line as a human-readable message.
                job.message = line.slice(0, 240);
            }
        };
        child.stdout.on('data', appendLog);
        child.stderr.on('data', appendLog);

        const timeout = setTimeout(() => {
            job.message = `timeout after ${config.renderTimeoutMs}ms — killing render`;
            try { child.kill('SIGKILL'); } catch { /* ignore */ }
        }, config.renderTimeoutMs);

        child.on('error', (err) => {
            clearTimeout(timeout);
            finish(job, false, `spawn error: ${err.message}`, resolve, logBuf);
        });

        child.on('exit', (code, signal) => {
            clearTimeout(timeout);
            if (code === 0) {
                // Confirm output file exists and is non-empty.
                let sizeBytes = 0;
                try { sizeBytes = fs.statSync(absOutput).size; } catch { /* ignore */ }
                if (sizeBytes === 0) {
                    return finish(job, false, 'render exited 0 but output is empty', resolve, logBuf);
                }
                job.outputSizeBytes = sizeBytes;
                return finish(job, true, 'Render completed', resolve, logBuf);
            }
            const reason = signal ? `killed by ${signal}` : `exit code ${code}`;
            finish(job, false, `hyperframes failed (${reason})`, resolve, logBuf);
        });
    });
}

function finish(job, ok, message, resolve, log = '') {
    job.status      = ok ? 'done' : 'failed';
    job.progress    = ok ? 100 : job.progress;
    job.message     = message;
    job.finishedAt  = new Date().toISOString();
    job.log         = log;
    if (!ok) job.error = message;
    resolve(job);
}
