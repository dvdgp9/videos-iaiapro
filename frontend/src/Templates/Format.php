<?php
declare(strict_types=1);

namespace App\Templates;

/**
 * Aspect-ratio id ↔ pixel dimensions. Canonical list for v1.
 * Extend here if new formats are needed; keep in sync with the ENUM in
 * `projects.format` and with each template's `meta.json` formats.
 */
final class Format
{
    /** @var array<string, array{w:int,h:int}> */
    private const MAP = [
        '16:9' => ['w' => 1920, 'h' => 1080],
        '9:16' => ['w' => 1080, 'h' => 1920],
        '1:1'  => ['w' => 1080, 'h' => 1080],
    ];

    public static function isValid(string $id): bool
    {
        return isset(self::MAP[$id]);
    }

    /** @return array{w:int,h:int} */
    public static function dimensions(string $id): array
    {
        if (!isset(self::MAP[$id])) {
            throw new \InvalidArgumentException("Unknown format id: $id");
        }
        return self::MAP[$id];
    }

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::MAP);
    }
}
