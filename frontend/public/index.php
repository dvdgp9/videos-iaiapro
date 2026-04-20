<?php
declare(strict_types=1);

// frontend/public/index.php -> frontend/src/bootstrap.php
require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\Auth\Auth;
use App\Auth\SessionStore;
use App\Http\Router;
use App\Http\View;
use App\Http\Middleware;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TemplatesController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\RendersController;

// Built-in PHP server: serve static files directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && $file !== __FILE__) {
        return false;
    }
}

$router = new Router();

// --- Public pages ---
$router->get('/', static function (): string {
    return View::render('home', ['title' => 'videos.iaiapro.com']);
});

// --- Auth ---
$router->get ('/login',    [AuthController::class, 'showLogin']);
$router->post('/login',    [AuthController::class, 'doLogin']);
$router->get ('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'doRegister']);
$router->post('/logout',   [AuthController::class, 'doLogout']);

// --- Authenticated area ---
$router->get('/dashboard', static function (): string {
    Middleware::requireAuth();
    return View::render('dashboard', [
        'title' => 'Panel — videos.iaiapro.com',
        'user'  => Auth::currentUser(),
    ]);
});

// --- JSON: health + current user ---
$router->get('/health', static fn (): array => [
    'status'  => 'ok',
    'service' => 'videos.iaiapro.com',
    'time'    => date(DATE_ATOM),
]);

$router->get('/api/me', static function (): array {
    $u = Auth::currentUser();
    if (!$u) {
        http_response_code(401);
        return ['error' => 'unauthenticated'];
    }
    return [
        'id'    => (int) $u['id'],
        'email' => $u['email'],
        'name'  => $u['name'],
        'quota' => [
            'render_seconds' => (int) $u['quota_render_seconds'],
            'storage_mb'     => (int) $u['quota_storage_mb'],
        ],
        'used'  => [
            'render_seconds' => (int) $u['used_render_seconds'],
            'storage_mb'     => (int) $u['used_storage_mb'],
        ],
    ];
});

// CSRF token for the current session (needed by clients that do JSON fetches).
$router->get('/api/csrf', static function (): array {
    $sid = SessionStore::currentId();
    if ($sid === null) {
        http_response_code(401);
        return ['error' => 'unauthenticated'];
    }
    return ['csrf' => SessionStore::csrfToken($sid)];
});

// --- Templates (catalogue) ---
$router->get('/api/templates',      [TemplatesController::class, 'index']);
$router->get('/api/templates/{id}', [TemplatesController::class, 'show']);

// --- Projects CRUD ---
$router->get   ('/api/projects',      [ProjectsController::class, 'index']);
$router->post  ('/api/projects',      [ProjectsController::class, 'store']);
$router->get   ('/api/projects/{id}', [ProjectsController::class, 'show']);
$router->put   ('/api/projects/{id}', [ProjectsController::class, 'update']);
$router->delete('/api/projects/{id}', [ProjectsController::class, 'destroy']);

// --- Render ---
$router->post('/api/projects/{id}/render', [RendersController::class, 'start']);
$router->get ('/api/projects/{id}/status', [RendersController::class, 'status']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
