// In-memory single-concurrency render queue.
//
// Limitations:
//  - State is lost on restart. PHP must treat any render in `rendering` for
//    too long as failed (stale). A restart of videos-backend.service implies
//    any in-flight render is aborted.
//  - Only 1 concurrent render (Hyperframes itself parallelises frames with
//    `--workers`; stacking multiple renders on top kills the VPS).
//
// A persistent queue (SQLite-backed or pushed into MariaDB) is a v2
// improvement. For v1 the PHP side owns durability via the `renders` table.

import { config } from './config.js';
import { startRender } from './renderer.js';

/** @typedef {'queued'|'rendering'|'done'|'failed'} JobStatus */

/**
 * @typedef {Object} Job
 * @property {string} id
 * @property {JobStatus} status
 * @property {number} progress          0..100
 * @property {string} message
 * @property {string} queuedAt
 * @property {string} [startedAt]
 * @property {string} [finishedAt]
 * @property {string} [error]
 * @property {string} [log]
 * @property {number} [outputSizeBytes]
 * @property {number} [pid]
 * @property {string} [command]
 * @property {{projectDir:string, outputPath:string, workers?:number, quality?:string, fps?:number, format?:string}} input
 */

/** @type {Map<string, Job>} */
const jobs = new Map();
/** @type {string[]} completed/failed job IDs in FIFO for pruning */
const history = [];
/** @type {string[]} pending job IDs */
const waiting = [];

let activeJobId = null;

export function enqueue(id, input) {
    if (jobs.has(id)) {
        throw new Error(`job_id already exists: ${id}`);
    }
    /** @type {Job} */
    const job = {
        id,
        status: 'queued',
        progress: 0,
        message: 'Queued, waiting for worker…',
        queuedAt: new Date().toISOString(),
        input,
    };
    jobs.set(id, job);
    waiting.push(id);
    process.nextTick(pump);
    return job;
}

export function get(id) {
    return jobs.get(id) || null;
}

export function stats() {
    return {
        active: activeJobId,
        queued: waiting.length,
        total:  jobs.size,
    };
}

async function pump() {
    if (activeJobId) return;
    const next = waiting.shift();
    if (!next) return;
    const job = jobs.get(next);
    if (!job) return;
    activeJobId = next;
    try {
        await startRender(job);
    } catch (err) {
        job.status   = 'failed';
        job.error    = `queue error: ${err?.message || String(err)}`;
        job.message  = job.error;
        job.finishedAt = new Date().toISOString();
    } finally {
        activeJobId = null;
        pushHistory(next);
        // Fire next one, if any.
        process.nextTick(pump);
    }
}

function pushHistory(id) {
    history.push(id);
    while (history.length > config.jobHistorySize) {
        const old = history.shift();
        if (old) jobs.delete(old);
    }
}
