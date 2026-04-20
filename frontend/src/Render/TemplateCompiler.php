<?php
declare(strict_types=1);

namespace App\Render;

use App\Templates\Format;

/**
 * Compiles a template's `index.html.tmpl` into a concrete HTML file by
 * substituting `{{placeholders}}`. This is the single choke point for any
 * user input that ends up inside the rendered HTML, so every value is
 * categorised and sanitised here:
 *
 *  - numeric (width/height/duration): cast to int/float, stringified
 *  - color   (#RRGGBB): validated upstream; re-checked defensively here
 *  - text    (title/subtitle/cta/...): HTML-escaped
 *  - asset   (asset_<key>): emitted as a local relative path or ""
 *
 * The compiler does NOT read the filesystem — callers give it the raw
 * template string and the compiled data maps.
 */
final class TemplateCompiler
{
    /**
     * @param string                $templateHtml  raw contents of index.html.tmpl
     * @param array<string,mixed>   $templateMeta  decoded meta.json of the template
     * @param array<string,string>  $content       sanitised field values (from validator)
     * @param array<string,string>  $style         sanitised style values (from validator)
     * @param array<string,string>  $assets        map `asset_<key>` → relative path (e.g. "./assets/logo.png")
     * @param array<string,int|float|string> $runtime extra {{vars}} available always: width,height,duration,project_id
     */
    public static function compile(
        string $templateHtml,
        array $templateMeta,
        array $content,
        array $style,
        array $assets = [],
        array $runtime = []
    ): string {
        $subs = [];

        // Runtime vars (numeric or identifiers, already trusted).
        foreach ($runtime as $k => $v) {
            $subs['{{' . $k . '}}'] = (string) $v;
        }

        // Declared fields → HTML-escape.
        $fieldMeta = self::indexByKey($templateMeta['fields'] ?? []);
        foreach ($fieldMeta as $key => $_) {
            $raw = $content[$key] ?? '';
            $subs['{{' . $key . '}}'] = self::escapeHtml($raw);
        }

        // Style fields → keep as-is for colors (validated) or escape for text.
        $styleMeta = self::indexByKey($templateMeta['style_fields'] ?? []);
        foreach ($styleMeta as $key => $def) {
            $raw  = $style[$key] ?? (string) ($def['default'] ?? '');
            $type = (string) ($def['type'] ?? 'text');
            $subs['{{' . $key . '}}'] = $type === 'color'
                ? self::safeColor($raw, (string) ($def['default'] ?? '#000000'))
                : self::escapeHtml($raw);
        }

        // Assets → path string without HTML escaping (already known-safe ASCII paths).
        // Any asset declared in meta that has no file gets an empty string so the
        // template's `[src=""]{display:none}` pattern hides the element.
        $assetMeta = self::indexByKey($templateMeta['assets'] ?? []);
        foreach ($assetMeta as $key => $_) {
            $placeholder = 'asset_' . $key;
            $path = $assets[$placeholder] ?? '';
            $subs['{{' . $placeholder . '}}'] = self::safePath($path);
        }

        return strtr($templateHtml, $subs);
    }

    private static function escapeHtml(string $raw): string
    {
        return htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeColor(string $raw, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? strtolower($raw) : $fallback;
    }

    /** Only allow simple relative paths we create ourselves. */
    private static function safePath(string $raw): string
    {
        if ($raw === '') return '';
        if (!preg_match('#^\./[A-Za-z0-9/_.\-]+$#', $raw)) return '';
        if (str_contains($raw, '..')) return '';
        return $raw;
    }

    /**
     * @param array<int, array<string,mixed>> $list
     * @return array<string, array<string,mixed>>
     */
    private static function indexByKey(array $list): array
    {
        $out = [];
        foreach ($list as $item) {
            $k = (string) ($item['key'] ?? '');
            if ($k !== '') $out[$k] = $item;
        }
        return $out;
    }

    /**
     * Build the runtime map (width/height/duration/project_id) for a given
     * template + format + project id. Centralised here so every caller is
     * consistent.
     *
     * @return array<string,int|float|string>
     */
    public static function runtimeFor(array $templateMeta, string $format, int $projectId): array
    {
        $dims = Format::dimensions($format);
        return [
            'width'      => $dims['w'],
            'height'     => $dims['h'],
            'duration'   => (float) ($templateMeta['duration_seconds'] ?? 10),
            'project_id' => $projectId,
        ];
    }
}
