<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Api;
use App\Templates\TemplateRegistry;

final class TemplatesController
{
    /**
     * GET /api/templates
     * List all templates available on disk. Public: no auth required
     * (they're part of the product catalogue).
     */
    public static function index(): array
    {
        return ['templates' => TemplateRegistry::publicList()];
    }

    /**
     * GET /api/templates/{id}
     */
    public static function show(array $params): array
    {
        $id = (string) ($params['id'] ?? '');
        $tpl = TemplateRegistry::get($id);
        if (!$tpl) {
            Api::fail(404, 'template_not_found');
        }
        return $tpl;
    }
}
