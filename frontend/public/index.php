<?php
declare(strict_types=1);

// frontend/public/index.php -> frontend/src/bootstrap.php
require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Http\Router;
use App\Support\Env;

// Serve static files when using the PHP built-in server.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && $file !== __FILE__) {
        return false;
    }
}

$router = new Router();

$router->get('/', static function (): string {
    $appUrl = htmlspecialchars(Env::get('APP_URL', ''), ENT_QUOTES);
    return <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>videos.iaiapro.com</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 4rem auto; padding: 0 1rem; color: #222; }
    h1 { margin-bottom: 0.2rem; }
    .muted { color: #666; }
    code { background: #f4f4f4; padding: 0.1rem 0.3rem; border-radius: 3px; }
</style>
<h1>videos.iaiapro.com</h1>
<p class="muted">Bootstrap OK — PHP router funcionando.</p>
<p>APP_URL: <code>$appUrl</code></p>
<p>Siguiente paso del plan: tarea <strong>0.3 Migraciones MySQL iniciales</strong>.</p>
HTML;
});

$router->get('/health', static fn (): array => [
    'status' => 'ok',
    'service' => 'videos.iaiapro.com',
    'time' => date(DATE_ATOM),
]);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
