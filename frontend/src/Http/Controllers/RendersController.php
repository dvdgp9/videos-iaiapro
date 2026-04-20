<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Api;
use App\Projects\ProjectRepository;
use App\Render\RenderRepository;
use App\Render\RenderService;

final class RendersController
{
    /**
     * POST /api/projects/{id}/render
     * Starts a new render for the project. Returns 202 with the render row.
     */
    public static function start(array $params): array
    {
        $user = Api::requireAuth();
        Api::requireCsrf(Api::readJsonBody());

        $projectId = (int) ($params['id'] ?? 0);
        $project = ProjectRepository::findByIdForUser($projectId, (int) $user['id']);
        if (!$project) Api::fail(404, 'project_not_found');

        // Reject starting a new render while one is in flight. The user can
        // wait for the previous to finish, or cancel manually (v2).
        $latest = RenderRepository::findLatestForProject($projectId, (int) $user['id']);
        if ($latest && in_array($latest['status'], RenderService::POLL_STATE_ACTIVE, true)) {
            Api::fail(409, 'render_already_in_progress', [
                'render' => self::renderToApi($latest),
            ]);
        }

        try {
            $render = RenderService::start($project);
        } catch (\Throwable $e) {
            Api::fail(502, 'render_worker_error', ['message' => $e->getMessage()]);
        }

        Api::respond(202, ['render' => self::renderToApi($render)]);
    }

    /**
     * GET /api/projects/{id}/status
     * Polls the worker for the latest render (if active), returns a snapshot.
     */
    public static function status(array $params): array
    {
        $user = Api::requireAuth();

        $projectId = (int) ($params['id'] ?? 0);
        $project = ProjectRepository::findByIdForUser($projectId, (int) $user['id']);
        if (!$project) Api::fail(404, 'project_not_found');

        $render = RenderRepository::findLatestForProject($projectId, (int) $user['id']);
        if (!$render) {
            return [
                'project' => ProjectRepository::toApi($project),
                'render'  => null,
            ];
        }

        $render = RenderService::syncFromWorker($render);

        // Refresh project to reflect the sync.
        $project = ProjectRepository::findByIdForUser($projectId, (int) $user['id']) ?? $project;

        return [
            'project' => ProjectRepository::toApi($project),
            'render'  => self::renderToApi($render),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function renderToApi(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'project_id'    => (int) $row['project_id'],
            'status'        => (string) $row['status'],
            'job_id'        => (string) ($row['job_id'] ?? ''),
            'error_message' => $row['error_message'] ?? null,
            'size_bytes'    => isset($row['size_bytes']) && $row['size_bytes'] !== null
                                 ? (int) $row['size_bytes']
                                 : null,
            'queued_at'     => (string) ($row['queued_at']  ?? ''),
            'started_at'    => $row['started_at']  ?? null,
            'finished_at'   => $row['finished_at'] ?? null,
            'video_url'     => RenderService::publicUrl($row),
            '_worker_error' => $row['_worker_error'] ?? null,
        ];
    }
}
