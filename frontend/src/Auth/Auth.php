<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * High-level auth orchestration used by HTTP controllers and middleware.
 */
final class Auth
{
    /**
     * @var array<string,mixed>|null  Cached current user row for this request.
     */
    private static ?array $current = null;
    private static bool $resolved = false;

    public static function currentUser(): ?array
    {
        if (self::$resolved) return self::$current;
        self::$resolved = true;

        $sid = SessionStore::currentId();
        if ($sid === null) return null;
        $uid = SessionStore::userIdFor($sid);
        if ($uid === null) return null;

        $user = UserRepository::findById($uid);
        if (!$user || (int) $user['is_active'] !== 1) return null;

        self::$current = $user;
        return $user;
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUser() !== null;
    }

    /**
     * Attempt login. Returns [success, errorMessage, userId?].
     *
     * @return array{0:bool,1:?string,2:?int}
     */
    public static function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));

        if (RateLimiter::isBlocked($ip, $email)) {
            RateLimiter::record($ip, $email, false);
            return [false, 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', null];
        }

        $user = UserRepository::findByEmail($email);
        if (!$user || (int) $user['is_active'] !== 1) {
            // Same message for both cases to avoid email enumeration.
            RateLimiter::record($ip, $email, false);
            return [false, 'Credenciales no válidas.', null];
        }

        if (!Password::verify($password, $user['password_hash'])) {
            RateLimiter::record($ip, $email, false);
            return [false, 'Credenciales no válidas.', null];
        }

        if (Password::needsRehash($user['password_hash'])) {
            UserRepository::updatePasswordHash((int) $user['id'], Password::hash($password));
        }

        UserRepository::touchLogin((int) $user['id']);
        RateLimiter::record($ip, $email, true);
        SessionStore::issue((int) $user['id']);

        // Opportunistic GC (runs ~1/100 logins).
        if (random_int(0, 99) === 0) {
            SessionStore::gc();
            RateLimiter::gc();
        }

        return [true, null, (int) $user['id']];
    }

    /**
     * Register a new user. Returns [success, errorMessage, userId?].
     *
     * @return array{0:bool,1:?string,2:?int}
     */
    public static function register(string $email, string $password, string $name): array
    {
        $email = strtolower(trim($email));
        $name  = trim($name);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            return [false, 'Email no válido.', null];
        }
        if (strlen($password) < 8) {
            return [false, 'La contraseña debe tener al menos 8 caracteres.', null];
        }
        if (strlen($password) > 200) {
            return [false, 'La contraseña es demasiado larga.', null];
        }
        if ($name === '' || strlen($name) > 120) {
            return [false, 'Nombre no válido.', null];
        }
        if (UserRepository::findByEmail($email) !== null) {
            return [false, 'Ya existe una cuenta con ese email.', null];
        }

        $uid = UserRepository::create($email, Password::hash($password), $name);
        SessionStore::issue($uid);
        return [true, null, $uid];
    }

    public static function logout(): void
    {
        $sid = SessionStore::currentId();
        if ($sid !== null) {
            SessionStore::destroy($sid);
        }
    }
}
