<?php
declare(strict_types=1);

namespace App\Render;

use App\Support\Env;
use App\Templates\TemplateRegistry;
use RuntimeException;

/**
 * Materialises a project on disk so the Node worker can render it:
 *
 *   <projectsDir>/<project_id>/
 *     ├── hyperframes.json   (copied from template)
 *     ├── meta.json          (minimal; hyperframes uses this to identify the project)
 *     ├── index.html         (TemplateCompiler output)
 *     └── assets/            (populated in R.8; empty placeholder for now)
 *
 * Always idempotent: re-running for the same project wipes the old
 * `index.html` and rewrites everything. Assets are merged rather than
 * wiped (so R.8 uploads survive re-compilation).
 */
final class ProjectMaterializer
{
    public static function projectsDir(): string
    {
        return (string) Env::get('PROJECTS_DIR', '/home/dvdgp/data/videos/projects');
    }

    public static function projectDir(int $projectId): string
    {
        return self::projectsDir() . '/' . $projectId;
    }

    /**
     * Compile and persist the project dir. Returns the absolute project
     * directory on success; throws RuntimeException on any IO failure.
     *
     * @param array<string,mixed> $projectRow  full DB row from ProjectRepository
     */
    public static function materialise(array $projectRow): string
    {
        $projectId  = (int)    $projectRow['id'];
        $templateId = (string) $projectRow['template_id'];
        $format     = (string) $projectRow['format'];

        $meta = TemplateRegistry::get($templateId);
        if (!$meta) {
            throw new RuntimeException("Template '$templateId' not found on disk.");
        }

        $tplDir = TemplateRegistry::templatesDir() . '/' . $templateId;
        $tmplFile = $tplDir . '/index.html.tmpl';
        $hfFile   = $tplDir . '/hyperframes.json';
        if (!is_file($tmplFile) || !is_file($hfFile)) {
            throw new RuntimeException("Template '$templateId' is incomplete on disk.");
        }

        $content = self::decodeJson((string) ($projectRow['content_json'] ?? ''));
        $style   = self::decodeJson((string) ($projectRow['style_json']   ?? ''));

        // Ensure project dirs exist.
        $projectDir = self::projectDir($projectId);
        $assetsDir  = $projectDir . '/assets';
        self::mkdirp($projectDir);
        self::mkdirp($assetsDir);

        // Runtime variables + asset placeholders.
        $runtime = TemplateCompiler::runtimeFor($meta, $format, $projectId);
        $assets  = self::scanAssets($meta, $assetsDir);

        // Compile HTML.
        $templateHtml = (string) file_get_contents($tmplFile);
        $html = TemplateCompiler::compile(
            $templateHtml,
            $meta,
            $content,
            $style,
            $assets,
            $runtime,
        );

        // Write index.html atomically (write to .tmp then rename).
        self::writeAtomic($projectDir . '/index.html', $html);

        // Copy hyperframes.json verbatim (may be re-overwritten by template updates).
        copy($hfFile, $projectDir . '/hyperframes.json');

        // meta.json that hyperframes itself expects in the project dir.
        $hfMeta = [
            'id'        => 'project-' . $projectId,
            'name'      => (string) ($projectRow['name'] ?? ('project-' . $projectId)),
            'createdAt' => date(DATE_ATOM),
        ];
        self::writeAtomic(
            $projectDir . '/meta.json',
            json_encode($hfMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
        );

        return $projectDir;
    }

    /**
     * Map `asset_<key>` → `./assets/<filename>` for every asset that exists
     * on disk. Unknown/missing assets are left out (TemplateCompiler emits "").
     *
     * @return array<string,string>
     */
    private static function scanAssets(array $templateMeta, string $assetsDir): array
    {
        $out = [];
        foreach ((array) ($templateMeta['assets'] ?? []) as $def) {
            $key = (string) ($def['key'] ?? '');
            if ($key === '') continue;
            // Accept any file whose basename starts with `<key>.` — R.8 writes
            // uploads as `<key>.<ext>` so we can find the extension.
            $glob = glob($assetsDir . '/' . $key . '.*');
            if ($glob && is_file($glob[0])) {
                $out['asset_' . $key] = './assets/' . basename($glob[0]);
            }
        }
        return $out;
    }

    private static function decodeJson(string $raw): array
    {
        if ($raw === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private static function mkdirp(string $dir): void
    {
        if (is_dir($dir)) return;
        if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: $dir");
        }
    }

    private static function writeAtomic(string $path, string $contents): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Cannot write: $tmp");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot rename to: $path");
        }
    }
}
