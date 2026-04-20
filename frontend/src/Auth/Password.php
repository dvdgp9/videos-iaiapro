<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Password hashing using Argon2id. Keeps parameters conservative for a 2-core
 * VPS: defaults to ~50ms per verify on target hardware.
 */
final class Password
{
    private const ALGO = PASSWORD_ARGON2ID;

    private const OPTIONS = [
        'memory_cost' => 19 * 1024, // 19 MiB
        'time_cost'   => 2,
        'threads'     => 1,
    ];

    public static function hash(string $plain): string
    {
        return password_hash($plain, self::ALGO, self::OPTIONS);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::ALGO, self::OPTIONS);
    }
}
