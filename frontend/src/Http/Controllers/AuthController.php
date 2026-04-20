<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\Auth;
use App\Auth\SessionStore;
use App\Http\View;
use App\Support\Env;

final class AuthController
{
    public static function showLogin(): string
    {
        self::ensureGuest();
        return View::render('auth/login', [
            'title' => 'Entrar — videos.iaiapro.com',
            'csrf'  => self::csrfForGuest(),
            'email' => '',
            'error' => null,
        ]);
    }

    public static function doLogin(): string
    {
        self::ensureGuest();

        $email    = trim((string) ($_POST['email']    ?? ''));
        $password =          (string) ($_POST['password'] ?? '');
        $csrf     =          (string) ($_POST['_csrf']    ?? '');

        if (!self::validGuestCsrf($csrf)) {
            return self::renderLogin($email, 'Sesión caducada, vuelve a intentarlo.');
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        [$ok, $err] = Auth::attemptLogin($email, $password, $ip);
        if (!$ok) {
            return self::renderLogin($email, $err ?? 'Error al iniciar sesión.');
        }

        header('Location: /dashboard', true, 303);
        exit;
    }

    public static function showRegister(): string
    {
        self::ensureGuest();
        if (!self::registrationEnabled()) {
            http_response_code(403);
            return View::render('auth/register', [
                'title' => 'Registro cerrado',
                'csrf'  => '',
                'email' => '',
                'name'  => '',
                'error' => 'El registro está temporalmente cerrado. Contacta con el administrador.',
            ]);
        }
        return View::render('auth/register', [
            'title' => 'Crear cuenta — videos.iaiapro.com',
            'csrf'  => self::csrfForGuest(),
            'email' => '',
            'name'  => '',
            'error' => null,
        ]);
    }

    public static function doRegister(): string
    {
        self::ensureGuest();
        if (!self::registrationEnabled()) {
            http_response_code(403);
            return 'Registration disabled';
        }

        $email    = trim((string) ($_POST['email']    ?? ''));
        $name     = trim((string) ($_POST['name']     ?? ''));
        $password =          (string) ($_POST['password'] ?? '');
        $csrf     =          (string) ($_POST['_csrf']    ?? '');

        if (!self::validGuestCsrf($csrf)) {
            return self::renderRegister($email, $name, 'Sesión caducada, vuelve a intentarlo.');
        }

        [$ok, $err] = Auth::register($email, $password, $name);
        if (!$ok) {
            return self::renderRegister($email, $name, $err ?? 'No se pudo crear la cuenta.');
        }

        header('Location: /dashboard', true, 303);
        exit;
    }

    public static function doLogout(): string
    {
        $sid = SessionStore::currentId();
        $csrf = (string) ($_POST['_csrf'] ?? '');
        if ($sid !== null && !SessionStore::checkCsrf($sid, $csrf)) {
            http_response_code(400);
            return 'Invalid CSRF token';
        }
        Auth::logout();
        header('Location: /', true, 303);
        exit;
    }

    // --------- helpers ---------

    private static function ensureGuest(): void
    {
        if (Auth::isLoggedIn()) {
            header('Location: /dashboard', true, 303);
            exit;
        }
    }

    private static function registrationEnabled(): bool
    {
        // Defaults to true; set REGISTRATION_ENABLED=false in .env to close it.
        $v = Env::get('REGISTRATION_ENABLED', 'true');
        return !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * CSRF for guest forms (login/register): we don't have a session yet, so
     * we use a token derived from a short-lived cookie.
     */
    private static function csrfForGuest(): string
    {
        $cookieName = SessionStore::cookieName() . '_csrf';
        $nonce = $_COOKIE[$cookieName] ?? null;
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/', $nonce)) {
            $nonce = bin2hex(random_bytes(16));
            $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                || (Env::get('APP_ENV', 'local') !== 'local');
            setcookie($cookieName, $nonce, [
                'expires'  => time() + 1800,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $secret = Env::get('APP_SECRET', 'insecure-default');
        return hash_hmac('sha256', 'guestcsrf:' . $nonce, (string) $secret);
    }

    private static function validGuestCsrf(string $submitted): bool
    {
        if ($submitted === '') return false;
        $expected = self::csrfForGuest();
        return hash_equals($expected, $submitted);
    }

    private static function renderLogin(string $email, string $error): string
    {
        return View::render('auth/login', [
            'title' => 'Entrar — videos.iaiapro.com',
            'csrf'  => self::csrfForGuest(),
            'email' => $email,
            'error' => $error,
        ]);
    }

    private static function renderRegister(string $email, string $name, string $error): string
    {
        return View::render('auth/register', [
            'title' => 'Crear cuenta — videos.iaiapro.com',
            'csrf'  => self::csrfForGuest(),
            'email' => $email,
            'name'  => $name,
            'error' => $error,
        ]);
    }
}
