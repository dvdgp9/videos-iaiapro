<?php
declare(strict_types=1);

namespace App\Auth;

use App\Database\Db;

final class UserRepository
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $email, string $passwordHash, string $name): int
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO users (email, password_hash, name)
             VALUES (:e, :p, :n)'
        );
        $stmt->execute([':e' => $email, ':p' => $passwordHash, ':n' => $name]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function updatePasswordHash(int $userId, string $newHash): void
    {
        $stmt = Db::pdo()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $stmt->execute([':h' => $newHash, ':id' => $userId]);
    }

    public static function touchLogin(int $userId): void
    {
        $stmt = Db::pdo()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }
}
