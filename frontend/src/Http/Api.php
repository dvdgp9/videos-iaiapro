<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Auth\SessionStore;

/**
 * Helpers for JSON API endpoints: auth, CSRF, request parsing, responses.
 *
 * All methods that need to terminate the request (e.g. 401, 403) do so via
 * `exit`. Handlers that return normally will have their array result JSON-
 * encoded by the router.
 */
final class Api
{
    /**
     * Ensure the request comes from an authenticated user. On failure,
     * responds with 401 JSON and exits.
     *
     * @return array<string,mixed> the current user row
     */
    public static function requireAuth(): array
    {
        $user = Auth::currentUser();
        if (!$user) {
            self::fail(401, 'unauthenticated');
        }
        return $user;
    }

    /**
     * Ensure the request carries a valid CSRF token for the current session.
     * Reads `X-CSRF-Token` header first, then `_csrf` body field. Safe
     * methods (GET, HEAD, OPTIONS) skip this check.
     *
     * @param array<string,mixed> $body Decoded JSON body (may contain `_csrf`).
     */
    public static function requireCsrf(array $body = []): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;

        $sid = SessionStore::currentId();
        if ($sid === null) {
            self::fail(401, 'unauthenticated');
        }

        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ($body['_csrf'] ?? ($_POST['_csrf'] ?? null));
        if (!is_string($submitted) || !SessionStore::checkCsrf($sid, $submitted)) {
            self::fail(403, 'invalid_csrf');
        }
    }

    /**
     * Parse the request body as JSON and return it as an array, or [] if
     * empty. Invalid JSON → 400 with a helpful error.
     *
     * @return array<string,mixed>
     */
    public static function readJsonBody(): array
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return [];

        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') return [];

        $ct = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_contains($ct, 'application/json')) {
            // Tolerate clients that forget the header but send JSON anyway,
            // as long as the body starts with a sensible character.
            $first = ltrim($raw)[0] ?? '';
            if ($first !== '{' && $first !== '[') return [];
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            self::fail(400, 'invalid_json', ['message' => $e->getMessage()]);
        }
        if (!is_array($data)) {
            self::fail(400, 'invalid_json', ['message' => 'JSON body must be an object']);
        }
        return $data;
    }

    /**
     * Emit a JSON response and exit. Router is bypassed.
     *
     * @param array<string,mixed> $payload
     */
    public static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Error response helper.
     *
     * @param array<string,mixed> $extra
     */
    public static function fail(int $status, string $code, array $extra = []): never
    {
        self::respond($status, array_merge(['error' => $code], $extra));
    }
}
