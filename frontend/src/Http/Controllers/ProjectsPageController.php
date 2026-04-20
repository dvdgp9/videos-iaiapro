<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Http\Middleware;
use App\Http\View;
use App\Projects\ProjectRepository;
use App\Templates\TemplateRegistry;

/**
 * HTML pages for the project workspace (R.10 UI).
 * JSON endpoints live in {@see ProjectsController}/{@see RendersController}/{@see AssetsController}.
 */
final class ProjectsPageController
{
    public static function index(): string
    {
        Middleware::requireAuth();
        $user = Auth::currentUser();
        $rows = ProjectRepository::listForUser((int) $user['id']);
        $projects = array_map([ProjectRepository::class, 'toApiSummary'], $rows);
        return View::render('projects/index', [
            'title'    => 'Proyectos — videos.iaiapro.com',
            'user'     => $user,
            'projects' => $projects,
        ]);
    }

    public static function create(): string
    {
        Middleware::requireAuth();
        $user = Auth::currentUser();
        $templates = TemplateRegistry::publicList();
        return View::render('projects/new', [
            'title'     => 'Nuevo proyecto — videos.iaiapro.com',
            'user'      => $user,
            'templates' => $templates,
        ]);
    }

    public static function show(array $params): string
    {
        Middleware::requireAuth();
        $user = Auth::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $row = ProjectRepository::findByIdForUser($id, (int) $user['id']);
        if (!$row) {
            http_response_code(404);
            return View::render('home', ['title' => '404 — proyecto no encontrado']);
        }
        $project  = ProjectRepository::toApi($row);
        $template = TemplateRegistry::get((string) $project['template_id']) ?? [];
        return View::render('projects/show', [
            'title'    => $project['name'] . ' — videos.iaiapro.com',
            'user'     => $user,
            'project'  => $project,
            'template' => $template,
        ]);
    }
}
