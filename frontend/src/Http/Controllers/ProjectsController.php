<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Api;
use App\Projects\ProjectRepository;
use App\Projects\ProjectValidator;

final class ProjectsController
{
    /**
     * GET /api/projects
     * List the current user's projects.
     */
    public static function index(): array
    {
        $user = Api::requireAuth();
        $rows = ProjectRepository::listForUser((int) $user['id']);
        $projects = array_map([ProjectRepository::class, 'toApiSummary'], $rows);
        return ['projects' => $projects];
    }

    /**
     * POST /api/projects
     * Body: { name, template_id, format?, content: {...}, style: {...} }
     */
    public static function store(): array
    {
        $user = Api::requireAuth();
        $body = Api::readJsonBody();
        Api::requireCsrf($body);

        [$data, $errors] = ProjectValidator::validateCreate($body);
        if ($errors) Api::fail(422, 'validation_failed', ['fields' => $errors]);

        $id = ProjectRepository::create((int) $user['id'], $data);
        $row = ProjectRepository::findByIdForUser($id, (int) $user['id']);
        Api::respond(201, ['project' => ProjectRepository::toApi($row ?? [])]);
    }

    /**
     * GET /api/projects/{id}
     */
    public static function show(array $params): array
    {
        $user = Api::requireAuth();
        $id = (int) ($params['id'] ?? 0);
        $row = ProjectRepository::findByIdForUser($id, (int) $user['id']);
        if (!$row) Api::fail(404, 'project_not_found');
        return ['project' => ProjectRepository::toApi($row)];
    }

    /**
     * PUT /api/projects/{id}
     * Body may include: { name?, content?, style? }.
     * template_id and format cannot be changed after creation.
     */
    public static function update(array $params): array
    {
        $user = Api::requireAuth();
        $body = Api::readJsonBody();
        Api::requireCsrf($body);

        $id = (int) ($params['id'] ?? 0);
        $row = ProjectRepository::findByIdForUser($id, (int) $user['id']);
        if (!$row) Api::fail(404, 'project_not_found');

        [$changes, $errors] = ProjectValidator::validateUpdate($body, $row);
        if ($errors) Api::fail(422, 'validation_failed', ['fields' => $errors]);
        if (!$changes) Api::fail(400, 'nothing_to_update');

        ProjectRepository::update($id, (int) $user['id'], $changes);
        $fresh = ProjectRepository::findByIdForUser($id, (int) $user['id']);
        return ['project' => ProjectRepository::toApi($fresh ?? [])];
    }

    /**
     * DELETE /api/projects/{id}
     */
    public static function destroy(array $params): array
    {
        $user = Api::requireAuth();
        $body = Api::readJsonBody();
        Api::requireCsrf($body);

        $id = (int) ($params['id'] ?? 0);
        $ok = ProjectRepository::delete($id, (int) $user['id']);
        if (!$ok) Api::fail(404, 'project_not_found');
        return ['deleted' => true];
    }
}
