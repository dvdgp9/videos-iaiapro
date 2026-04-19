<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Minimal HTTP router. Supports static paths and `{param}` placeholders.
 * Handler can be any callable; receives (array $params) and must echo output
 * or return a string/array (array → JSON).
 */
final class Router
{
    /** @var array<string, array<string, callable>> */
    private array $routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []];

    public function get(string $path, callable $handler): void    { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, callable $handler): void   { $this->routes['POST'][$path] = $handler; }
    public function put(string $path, callable $handler): void    { $this->routes['PUT'][$path] = $handler; }
    public function delete(string $path, callable $handler): void { $this->routes['DELETE'][$path] = $handler; }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $method = strtoupper($method);
        $table = $this->routes[$method] ?? [];

        foreach ($table as $pattern => $handler) {
            $params = [];
            if ($this->match($pattern, $path, $params)) {
                $result = $handler($params);
                if (is_array($result)) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (is_string($result)) {
                    echo $result;
                }
                return;
            }
        }

        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>404</title><h1>404 Not Found</h1>';
    }

    /** @param array<string,string> $params */
    private function match(string $pattern, string $path, array &$params): bool
    {
        if ($pattern === $path) {
            return true;
        }
        if (!str_contains($pattern, '{')) {
            return false;
        }
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $path, $m)) {
            foreach ($m as $k => $v) {
                if (!is_int($k)) {
                    $params[$k] = $v;
                }
            }
            return true;
        }
        return false;
    }
}
