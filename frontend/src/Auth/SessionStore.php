<?php
declare(strict_types=1);

namespace App\Auth;

use App\Database\Db;
use App\Support\Env;

/**
 * Database-backed session store.
 *
 * Session ID is a 64-hex-char random token, stored in the `sessions` table
 * and carried in an HttpOnly cookie. The CSRF token is derived from the
 * session ID via HMAC(APP_SECRET), so we don't need a separate column.
 */
final class SessionStore
{
    private const COOKIE_NAME_DEFAULT = 'viaiapro_sid';

    public static function cookieName(): string
    {
        return Env::get('SESSION_NAME', self::COOKIE_NAME_DEFAULT);
    }

    public static function lifetimeMinutes(): int
    {
        $v = (int) (Env::get('SESSION_LIFETIME_MINUTES', '10080') ?? '10080'); // 7 days
        return $v > 0 ? $v : 10080;
    }

    /**
     * Current session ID from the request cookie, or null.
     */
    public static function currentId(): ?string
    {
        $name = self::cookieName();
        $val  = $_COOKIE[$name] ?? null;
        if (!is_string($val) || !preg_match('/^[a-f0-9]{64}$/', $val)) {
            return null;
        }
        return $val;
    }

    /**
     * Create a new session for a user. Returns the session ID (also sets cookie).
     */
    public static function issue(int $userId): string
    {
        $sid = bin2hex(random_bytes(32));
        $lifetime = self::lifetimeMinutes();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $lifetime * 60);

        $stmt = Db::pdo()->prepare(
            'INSERT INTO sessions (id, user_id, ip, user_agent, expires_at)
             VALUES (:id, :user_id, :ip, :ua, :exp)'
        );
        $stmt->execute([
            ':id'      => $sid,
            ':user_id' => $userId,
            ':ip'      => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ':ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ':exp'     => $expiresAt,
        ]);

        self::setCookie($sid, $lifetime);
        return $sid;
    }

    /**
     * Fetch the valid (non-expired) user_id for a session ID, or null.
     * Also refreshes the cookie lifetime on access (sliding expiration).
     */
    public static function userIdFor(string $sid): ?int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT user_id, expires_at
               FROM sessions
              WHERE id = :id AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([':id' => $sid]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        // Sliding expiration: extend if more than half-life has passed.
        $expiresAt = strtotime($row['expires_at'] . ' UTC');
        $lifetime  = self::lifetimeMinutes() * 60;
        $halfLife  = time() + (int) ($lifetime / 2);
        if ($expiresAt < $halfLife) {
            $newExp = gmdate('Y-m-d H:i:s', time() + $lifetime);
            $upd = Db::pdo()->prepare('UPDATE sessions SET expires_at = :exp WHERE id = :id');
            $upd->execute([':exp' => $newExp, ':id' => $sid]);
            self::setCookie($sid, self::lifetimeMinutes());
        }

        return (int) $row['user_id'];
    }

    public static function destroy(string $sid): void
    {
        $stmt = Db::pdo()->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute([':id' => $sid]);
        self::setCookie('', -1);
    }

    /**
     * Derive a CSRF token from a session ID via HMAC. Same session → same token.
     */
    public static function csrfToken(string $sid): string
    {
        $secret = Env::get('APP_SECRET', '');
        if ($secret === null || $secret === '' || $secret === 'change-me') {
            // Fallback, but warn. In production APP_SECRET must be set.
            $secret = 'insecure-default-app-secret';
        }
        return hash_hmac('sha256', 'csrf:' . $sid, $secret);
    }

    public static function checkCsrf(string $sid, ?string $submitted): bool
    {
        if (!is_string($submitted) || $submitted === '') return false;
        $expected = self::csrfToken($sid);
        return hash_equals($expected, $submitted);
    }

    /**
     * Opportunistic cleanup of expired sessions. Called periodically on login.
     */
    public static function gc(): void
    {
        Db::pdo()->exec('DELETE FROM sessions WHERE expires_at <= UTC_TIMESTAMP()');
    }

    private static function setCookie(string $value, int $lifetimeMinutes): void
    {
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (Env::get('APP_ENV', 'local') !== 'local');

        $expires = $lifetimeMinutes > 0 ? time() + $lifetimeMinutes * 60 : 0;
        setcookie(self::cookieName(), $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
