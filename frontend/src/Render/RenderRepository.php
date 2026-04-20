<?php
declare(strict_types=1);

namespace App\Render;

use App\Database\Db;

final class RenderRepository
{
    public static function create(int $projectId, int $userId, string $jobId, string $outputPath): int
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO renders (project_id, user_id, job_id, output_path, status, queued_at)
             VALUES (:pid, :uid, :jid, :out, \'queued\', NOW())'
        );
        $stmt->execute([
            ':pid' => $projectId,
            ':uid' => $userId,
            ':jid' => $jobId,
            ':out' => $outputPath,
        ]);
        return (int) Db::pdo()->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public static function findLatestForProject(int $projectId, int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM renders
              WHERE project_id = :pid AND user_id = :uid
           ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([':pid' => $projectId, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public static function findById(int $id, int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT * FROM renders WHERE id = :id AND user_id = :uid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Apply fields from a worker status payload onto the render row.
     *
     * @param array<string,mixed> $workerJob  the decoded job descriptor from the worker
     */
    public static function applyWorkerStatus(int $renderId, array $workerJob): void
    {
        $map = [
            'queued'     => 'queued',
            'rendering'  => 'rendering',
            'done'       => 'done',
            'failed'     => 'failed',
        ];
        $wStatus = (string) ($workerJob['status'] ?? 'queued');
        $status  = $map[$wStatus] ?? 'queued';

        $started  = isset($workerJob['started_at'])  && $workerJob['started_at']  ? (string) $workerJob['started_at']  : null;
        $finished = isset($workerJob['finished_at']) && $workerJob['finished_at'] ? (string) $workerJob['finished_at'] : null;
        $error    = isset($workerJob['error'])       && $workerJob['error']       ? substr((string) $workerJob['error'], 0, 1000) : null;
        $size     = isset($workerJob['output_size_bytes']) && is_numeric($workerJob['output_size_bytes'])
                      ? (int) $workerJob['output_size_bytes']
                      : null;

        $stmt = Db::pdo()->prepare(
            'UPDATE renders
                SET status        = :st,
                    error_message = :err,
                    size_bytes    = COALESCE(:sz, size_bytes),
                    started_at    = COALESCE(started_at, STR_TO_DATE(:sa, \'%Y-%m-%dT%H:%i:%s.%fZ\')),
                    finished_at   = COALESCE(finished_at, STR_TO_DATE(:fa, \'%Y-%m-%dT%H:%i:%s.%fZ\'))
              WHERE id = :id'
        );
        $stmt->execute([
            ':st'  => $status,
            ':err' => $error,
            ':sz'  => $size,
            ':sa'  => $started,
            ':fa'  => $finished,
            ':id'  => $renderId,
        ]);
    }

    /** Mark a render as failed (used when the worker forgot about it). */
    public static function markStaleAsFailed(int $renderId, string $reason): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE renders
                SET status        = \'failed\',
                    error_message = :err,
                    finished_at   = COALESCE(finished_at, NOW())
              WHERE id = :id AND status IN (\'queued\', \'rendering\')'
        );
        $stmt->execute([':err' => substr($reason, 0, 1000), ':id' => $renderId]);
    }
}
