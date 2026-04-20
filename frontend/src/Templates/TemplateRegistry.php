<?php
declare(strict_types=1);

namespace App\Templates;

use RuntimeException;

/**
 * Reads template metadata from `backend/templates/<id>/meta.json`.
 * The registry is a stateless façade around the filesystem, with a
 * per-request memoization (templates rarely change during a request).
 */
final class TemplateRegistry
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    public static function templatesDir(): string
    {
        // repo root / backend / templates
        return dirname(__DIR__, 3) . '/backend/templates';
    }

    /**
     * @return array<string, array<string,mixed>>  map: template_id → meta
     */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;

        $dir = self::templatesDir();
        $out = [];
        if (!is_dir($dir)) {
            self::$cache = [];
            return [];
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $metaFile = $dir . '/' . $entry . '/meta.json';
            if (!is_file($metaFile)) continue;

            $meta = self::loadMeta($metaFile);
            if ($meta === null) continue;

            // Sanity: ensure id matches directory name.
            $id = (string) ($meta['id'] ?? '');
            if ($id === '' || $id !== $entry) continue;

            $out[$id] = $meta;
        }

        ksort($out);
        self::$cache = $out;
        return $out;
    }

    public static function get(string $id): ?array
    {
        // Prevent path traversal in the id itself.
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) return null;
        return self::all()[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return self::get($id) !== null;
    }

    /**
     * Publicly listable summary for the catalogue (omits nothing for now;
     * the full meta is small). Kept as a distinct method to make future
     * trimming trivial.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function publicList(): array
    {
        return array_values(self::all());
    }

    /** @return array<string,mixed>|null */
    private static function loadMeta(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) return null;
        return $data;
    }
}
