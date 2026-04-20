<?php
declare(strict_types=1);

namespace App\Auth;

use App\Database\Db;

/**
 * Simple DB-backed rate limit for login attempts.
 *
 *  - Max 10 failed attempts per IP within WINDOW_MIN (default 10 min) → blocked.
 *  - Max  5 failed attempts per email within WINDOW_MIN               → blocked.
 *  - Successful attempts don't count against the limit.
 *
 * Not a defence against distributed credential stuffing, but stops casual
 * brute force. For more we'd need fail2ban + a proper rate limiter at nginx.
 */
final class RateLimiter
{
    private const WINDOW_MIN        = 10;
    private const MAX_FAILED_PER_IP = 10;
    private const MAX_FAILED_PER_EMAIL = 5;

    public static function isBlocked(string $ip, string $email): bool
    {
        $sql = "SELECT
                    SUM(CASE WHEN ip    = :ip    AND success = 0 THEN 1 ELSE 0 END) AS ip_fails,
                    SUM(CASE WHEN email = :email AND success = 0 THEN 1 ELSE 0 END) AS email_fails
                  FROM login_attempts
                 WHERE attempted_at > (UTC_TIMESTAMP() - INTERVAL :win MINUTE)";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':ip' => $ip, ':email' => $email, ':win' => self::WINDOW_MIN]);
        $row = $stmt->fetch();
        $ipFails    = (int) ($row['ip_fails']    ?? 0);
        $emailFails = (int) ($row['email_fails'] ?? 0);
        return $ipFails >= self::MAX_FAILED_PER_IP
            || $emailFails >= self::MAX_FAILED_PER_EMAIL;
    }

    public static function record(string $ip, string $email, bool $success): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO login_attempts (ip, email, success) VALUES (:ip, :email, :s)'
        );
        $stmt->execute([
            ':ip'    => substr($ip, 0, 45),
            ':email' => substr($email, 0, 190),
            ':s'     => $success ? 1 : 0,
        ]);
    }

    /**
     * Delete attempts older than 24h (opportunistic; called on login).
     */
    public static function gc(): void
    {
        Db::pdo()->exec(
            'DELETE FROM login_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)'
        );
    }
}
