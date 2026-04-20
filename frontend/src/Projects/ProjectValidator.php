<?php
declare(strict_types=1);

namespace App\Projects;

use App\Templates\Format;
use App\Templates\TemplateRegistry;

/**
 * Validates project content/style against a template's meta.json schema.
 *
 * Rules (v1):
 *  - `content`: only keys declared in `fields[].key` are accepted. Each
 *     value is a string, trimmed, respecting `max_length` and `required`.
 *  - `style`: only keys declared in `style_fields[].key`. Type `color`
 *     must match `^#[0-9a-fA-F]{6}$` (we don't support alpha in v1).
 *  - `format` must be one of `template.formats`.
 *  - `name` is a user-facing project name: 1..160 chars trimmed.
 *
 * Returns [cleanedData, errorsMap]. Errors are per-field human-readable
 * strings. If errorsMap is empty, cleanedData is safe to persist.
 */
final class ProjectValidator
{
    /**
     * @param array<string,mixed> $input   Raw decoded JSON body.
     * @return array{0: array{name:string,template_id:string,format:string,content:array<string,string>,style:array<string,string>}, 1: array<string,string>}
     */
    public static function validateCreate(array $input): array
    {
        $errors = [];

        $name         = is_string($input['name']        ?? null) ? trim($input['name'])        : '';
        $templateId   = is_string($input['template_id'] ?? null) ? trim($input['template_id']) : '';
        $format       = is_string($input['format']      ?? null) ? trim($input['format'])      : '';
        $contentInput = is_array($input['content']      ?? null) ? $input['content']           : [];
        $styleInput   = is_array($input['style']        ?? null) ? $input['style']             : [];

        if ($name === '' || mb_strlen($name) > 160) {
            $errors['name'] = 'El nombre es obligatorio (1–160 caracteres).';
        }

        $tpl = TemplateRegistry::get($templateId);
        if (!$tpl) {
            $errors['template_id'] = 'Plantilla no válida.';
            // Short-circuit: without template we can't validate content/style.
            return [[
                'name' => $name, 'template_id' => $templateId, 'format' => $format,
                'content' => [], 'style' => [],
            ], $errors];
        }

        $tplFormats = is_array($tpl['formats'] ?? null) ? $tpl['formats'] : [];
        if ($format === '') {
            $format = (string) ($tpl['default_format'] ?? ($tplFormats[0] ?? ''));
        }
        if (!Format::isValid($format) || !in_array($format, $tplFormats, true)) {
            $errors['format'] = 'Formato no compatible con la plantilla.';
        }

        [$content, $cErr] = self::validateFields($tpl['fields'] ?? [], $contentInput);
        foreach ($cErr as $k => $v) $errors["content.$k"] = $v;

        [$style, $sErr] = self::validateStyle($tpl['style_fields'] ?? [], $styleInput);
        foreach ($sErr as $k => $v) $errors["style.$k"] = $v;

        return [[
            'name'        => $name,
            'template_id' => $templateId,
            'format'      => $format,
            'content'     => $content,
            'style'       => $style,
        ], $errors];
    }

    /**
     * Validate an update: like create but `template_id` and `format` are
     * taken from the current row and ignored in input. Partial updates for
     * name/content/style are allowed.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current  Existing project row (template_id/format columns).
     * @return array{0: array{name?:string,content?:array<string,string>,style?:array<string,string>}, 1: array<string,string>}
     */
    public static function validateUpdate(array $input, array $current): array
    {
        $errors = [];
        $out = [];

        if (array_key_exists('name', $input)) {
            $name = is_string($input['name']) ? trim($input['name']) : '';
            if ($name === '' || mb_strlen($name) > 160) {
                $errors['name'] = 'El nombre es obligatorio (1–160 caracteres).';
            } else {
                $out['name'] = $name;
            }
        }

        $tpl = TemplateRegistry::get((string) ($current['template_id'] ?? ''));
        if (!$tpl) {
            // The template was removed from disk; we can't validate content/style.
            return [[], ['template_id' => 'La plantilla original ya no existe.']];
        }

        if (array_key_exists('content', $input)) {
            $contentInput = is_array($input['content']) ? $input['content'] : [];
            [$content, $cErr] = self::validateFields($tpl['fields'] ?? [], $contentInput);
            foreach ($cErr as $k => $v) $errors["content.$k"] = $v;
            if (!$cErr) $out['content'] = $content;
        }
        if (array_key_exists('style', $input)) {
            $styleInput = is_array($input['style']) ? $input['style'] : [];
            [$style, $sErr] = self::validateStyle($tpl['style_fields'] ?? [], $styleInput);
            foreach ($sErr as $k => $v) $errors["style.$k"] = $v;
            if (!$sErr) $out['style'] = $style;
        }

        return [$out, $errors];
    }

    /**
     * @param array<int, array<string,mixed>> $fields
     * @param array<string,mixed>             $input
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private static function validateFields(array $fields, array $input): array
    {
        $errors = [];
        $out = [];
        $allowedKeys = [];

        foreach ($fields as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') continue;
            $allowedKeys[$key] = true;
            $required  = (bool) ($f['required']   ?? false);
            $maxLength = (int)  ($f['max_length'] ?? 255);
            $default   = (string) ($f['default']  ?? '');

            $raw = $input[$key] ?? null;
            if (!is_string($raw)) $raw = $default;
            $v = trim($raw);

            if ($v === '' && $required) {
                $errors[$key] = 'Campo obligatorio.';
                continue;
            }
            if ($v !== '' && mb_strlen($v) > $maxLength) {
                $errors[$key] = "Demasiado largo (máximo $maxLength caracteres).";
                continue;
            }
            // Strip control chars (except \n, \t) to avoid sneaky payloads.
            $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? '';
            $out[$key] = $v;
        }

        // Reject unknown keys explicitly.
        foreach ($input as $k => $_) {
            if (!isset($allowedKeys[(string) $k])) {
                $errors[(string) $k] = 'Campo desconocido.';
            }
        }

        return [$out, $errors];
    }

    /**
     * @param array<int, array<string,mixed>> $styleFields
     * @param array<string,mixed>             $input
     * @return array{0: array<string,string>, 1: array<string,string>}
     */
    private static function validateStyle(array $styleFields, array $input): array
    {
        $errors = [];
        $out = [];
        $allowedKeys = [];

        foreach ($styleFields as $f) {
            $key = (string) ($f['key'] ?? '');
            if ($key === '') continue;
            $allowedKeys[$key] = true;
            $type    = (string) ($f['type']    ?? 'text');
            $default = (string) ($f['default'] ?? '');

            $raw = $input[$key] ?? null;
            if (!is_string($raw)) $raw = $default;
            $v = trim($raw);

            if ($v === '') {
                // Empty → use default (which is also possibly empty).
                if ($default !== '') $out[$key] = $default;
                continue;
            }

            if ($type === 'color') {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    $errors[$key] = 'Color no válido (formato esperado: #RRGGBB).';
                    continue;
                }
                $out[$key] = strtolower($v);
            } else {
                // Generic text fallback; cap length.
                if (mb_strlen($v) > 255) {
                    $errors[$key] = 'Valor demasiado largo.';
                    continue;
                }
                $out[$key] = $v;
            }
        }

        foreach ($input as $k => $_) {
            if (!isset($allowedKeys[(string) $k])) {
                $errors[(string) $k] = 'Campo de estilo desconocido.';
            }
        }

        return [$out, $errors];
    }
}
