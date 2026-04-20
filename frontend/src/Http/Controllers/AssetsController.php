<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Assets\AssetRepository;
use App\Assets\AssetService;
use App\Assets\AssetUploadException;
use App\Http\Api;
use App\Projects\ProjectRepository;

final class AssetsController
{
    /** GET /api/projects/{id}/assets */
    public static function index(array $params = []): void
    {
        $user = Api::requireAuth();
        $projectId = (int) ($params['id'] ?? 0);
        if ($projectId <= 0) Api::fail(400, 'invalid_project_id');

        $project = ProjectRepository::findByIdForUser($projectId, (int) $user['id']);
        if (!$project) Api::fail(404, 'project_not_found');

        $rows = AssetRepository::listForProject($projectId, (int) $user['id']);
        $out  = array_map([AssetRepository::class, 'toApi'], $rows);
        Api::respond(200, ['assets' => $out]);
    }

    /**
     * POST /api/projects/{id}/assets
     * multipart/form-data: role=<string>, file=<binary>, _csrf=<token>
     */
    public static function store(array $params = []): void
    {
        $user = Api::requireAuth();
        // CSRF in multipart: read from header OR form field (Api::readJsonBody
        // won't parse multipart, so we feed $_POST).
        Api::requireCsrf($_POST);

        $projectId = (int) ($params['id'] ?? 0);
        if ($projectId <= 0) Api::fail(400, 'invalid_project_id');

        $role = trim((string) ($_POST['role'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role)) {
            Api::fail(422, 'invalid_role');
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) Api::fail(400, 'missing_file');

        try {
            $asset = AssetService::store((int) $user['id'], $projectId, $role, $file);
            Api::respond(201, ['asset' => $asset]);
        } catch (AssetUploadException $e) {
            Api::fail($e->httpStatus, $e->getMessage(), $e->extra);
        }
    }

    /** DELETE /api/projects/{id}/assets/{role} */
    public static function destroy(array $params = []): void
    {
        $user = Api::requireAuth();
        Api::requireCsrf(Api::readJsonBody());

        $projectId = (int) ($params['id'] ?? 0);
        $role      = (string) ($params['role'] ?? '');
        if ($projectId <= 0) Api::fail(400, 'invalid_project_id');
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $role)) {
            Api::fail(422, 'invalid_role');
        }

        try {
            $deleted = AssetService::delete((int) $user['id'], $projectId, $role);
        } catch (AssetUploadException $e) {
            Api::fail($e->httpStatus, $e->getMessage(), $e->extra);
        }

        if (!$deleted) Api::fail(404, 'asset_not_found');
        Api::respond(200, ['ok' => true]);
    }
}
