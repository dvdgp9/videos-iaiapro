<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Very small view helper. Renders a PHP file under frontend/src/views/ with
 * the given variables in scope. Layout is handled via an explicit
 * `$layout`/`$content` pattern.
 */
final class View
{
    /** @param array<string,mixed> $vars */
    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = self::capture($template, $vars);
        if ($layout === null) return $content;
        return self::capture($layout, array_merge($vars, ['content' => $content]));
    }

    /** @param array<string,mixed> $vars */
    private static function capture(string $template, array $vars): string
    {
        $file = dirname(__DIR__) . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
