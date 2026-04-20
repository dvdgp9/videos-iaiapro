<?php
declare(strict_types=1);

namespace App\Render;

use App\Database\Db;
use App\Support\Env;
use RuntimeException;

/**
 * Orchestrates one render request end-to-end:
 *  1. Materialise the project on disk (compile index.html, copy config, etc.).
 *  2. Insert a row in `renders` (status=queued).
 *  3. POST the job to the Node worker.
 *  4. Update `projects.last_render_id`, `projects.status`, progress fields.
 *  5. Return the render row.
 *
 * The corresponding status sync is in `syncFromWorker()` — called by the
 * controller whenever the UI polls.
 */
final class RenderService
{
    public const POLL_STATE_ACTIVE = ['queued', 'rendering'];

    /**
     * @param array<string,mixed> $projectRow
     * @return array<string,mixed>  the newly inserted render row (after update)
     */
    public static function start(array $projectRow): array
    {
        $projectId = (int) $projectRow['id'];
        $userId    = (int) $projectRow['user_id'];

        // 1. Materialise. This can throw RuntimeException (missing template etc.).
        $projectDir = ProjectMaterializer::materialise($projectRow);

        // 2. Generate job id and output path.
        $jobId      = 'r_' . bin2hex(random_bytes(16));
        $rendersDir = rtrim((string) Env::get('RENDERS_DIR', '/home/dvdgp/data/videos/renders'), '/');
        $outputPath = $rendersDir . '/' . $jobId . '.mp4';

        // 3. Insert render row BEFORE calling the worker, so if the worker
        // call fails we still have a DB record to mark as failed.
        $renderId = RenderRepository::create($projectId, $userId, $jobId, $outputPath);

        // 4. Fire the worker.
        try {
            $workerJob = WorkerClient::enqueue([
                'job_id'      => $jobId,
                'project_dir' => $projectDir,
                'output_path' => $outputPath,
                'quality'     => (string) Env::get('RENDER_QUALITY', 'standard'),
                'fps'         => (int)    Env::get('RENDER_FPS', 30),
                'format'      => (string) Env::get('RENDER_FORMAT', 'mp4'),
            ]);
        } catch (\Throwable $e) {
            RenderRepository::markStaleAsFailed($renderId, 'worker rejected: ' . $e->getMessage());
            self::updateProjectFromRender($projectId, 'failed', 0, 'worker rejected', $renderId);
            throw $e;
        }

        // 5. Apply initial worker state & project snapshot.
        RenderRepository::applyWorkerStatus($renderId, $workerJob);
        self::updateProjectFromRender(
            $projectId,
            (string) ($workerJob['status']  ?? 'queued'),
            (int)    ($workerJob['progress'] ?? 0),
            (string) ($workerJob['message']  ?? 'Queued'),
            $renderId,
        );

        return RenderRepository::findById($renderId, $userId) ?? [];
    }

    /**
     * If the render is in an active state, poll the worker and apply the
     * update. Always returns the (possibly refreshed) render row.
     *
     * @param array<string,mixed> $renderRow
     * @return array<string,mixed>
     */
    public static function syncFromWorker(array $renderRow): array
    {
        $status = (string) $renderRow['status'];
        if (!in_array($status, self::POLL_STATE_ACTIVE, true)) {
            return $renderRow;
        }

        $jobId = (string) $renderRow['job_id'];
        try {
            $workerJob = WorkerClient::status($jobId);
        } catch (\Throwable $e) {
            // Transient failure: leave the row as-is and report upstream.
            $renderRow['_worker_error'] = $e->getMessage();
            return $renderRow;
        }

        if ($workerJob === null) {
            // Worker forgot about this job → treat as failed (stale after restart).
            RenderRepository::markStaleAsFailed(
                (int) $renderRow['id'],
                'worker forgot the job (service restarted?)'
            );
            self::updateProjectFromRender(
                (int) $renderRow['project_id'],
                'failed',
                0,
                'worker dropped the job',
                (int) $renderRow['id'],
            );
        } else {
            RenderRepository::applyWorkerStatus((int) $renderRow['id'], $workerJob);
            self::updateProjectFromRender(
                (int) $renderRow['project_id'],
                (string) ($workerJob['status']   ?? 'queued'),
                (int)    ($workerJob['progress'] ?? 0),
                (string) ($workerJob['message']  ?? ''),
                (int) $renderRow['id'],
            );
        }

        return RenderRepository::findById((int) $renderRow['id'], (int) $renderRow['user_id']) ?? $renderRow;
    }

    /**
     * Map worker status → projects.status, and update progress/message.
     */
    private static function updateProjectFromRender(
        int $projectId,
        string $workerStatus,
        int $progress,
        string $message,
        int $renderId,
    ): void {
        $map = [
            'queued'    => 'queued',
            'rendering' => 'rendering',
            'done'      => 'completed',
            'failed'    => 'failed',
        ];
        $projectStatus = $map[$workerStatus] ?? 'queued';

        $stmt = Db::pdo()->prepare(
            'UPDATE projects
                SET status          = :st,
                    render_progress = :pg,
                    render_message  = :msg,
                    last_render_id  = :rid
              WHERE id = :pid'
        );
        $stmt->execute([
            ':st'  => $projectStatus,
            ':pg'  => max(0, min(100, $progress)),
            ':msg' => substr($message, 0, 255),
            ':rid' => $renderId,
            ':pid' => $projectId,
        ]);
    }

    /**
     * Public URL for the rendered MP4, or null if not ready.
     */
    public static function publicUrl(array $renderRow): ?string
    {
        if (($renderRow['status'] ?? '') !== 'done') return null;
        $jobId = (string) ($renderRow['job_id'] ?? '');
        if ($jobId === '') return null;
        return '/storage/renders/' . $jobId . '.mp4';
    }
}
