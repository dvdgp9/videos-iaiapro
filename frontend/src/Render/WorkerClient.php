<?php
declare(strict_types=1);

namespace App\Render;

use App\Support\Env;
use RuntimeException;

/**
 * Thin HTTP client for the internal Node render worker (videos-backend).
 *
 * The worker listens only on 127.0.0.1:3001. All mutating endpoints
 * require `Authorization: Bearer <RENDER_API_TOKEN>`.
 */
final class WorkerClient
{
    public static function baseUrl(): string
    {
        return rtrim((string) Env::get('RENDER_API_URL', 'http://127.0.0.1:3001'), '/');
    }

    public static function token(): string
    {
        $tok = (string) Env::get('RENDER_API_TOKEN', '');
        if ($tok === '') {
            throw new RuntimeException('RENDER_API_TOKEN is not set in .env');
        }
        return $tok;
    }

    /**
     * POST /render. Returns the worker's job descriptor.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function enqueue(array $body): array
    {
        return self::request('POST', '/render', $body);
    }

    /** GET /render/:id. Returns null if the worker says 404. */
    public static function status(string $jobId): ?array
    {
        try {
            return self::request('GET', '/render/' . rawurlencode($jobId));
        } catch (WorkerNotFoundException) {
            return null;
        }
    }

    /** GET /health. */
    public static function health(): array
    {
        return self::request('GET', '/health', null, false);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private static function request(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $url = self::baseUrl() . $path;
        $ch  = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }

        $headers = ['Accept: application/json'];
        if ($auth) {
            $headers[] = 'Authorization: Bearer ' . self::token();
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("worker unreachable: $err");
        }
        if ($code === 404) {
            throw new WorkerNotFoundException();
        }
        if ($code >= 400) {
            throw new RuntimeException("worker error HTTP $code: " . (is_string($raw) ? substr($raw, 0, 300) : ''));
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("worker returned non-JSON body (HTTP $code)");
        }
        return $decoded;
    }
}
