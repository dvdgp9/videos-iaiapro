<?php
declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;

/**
 * Route guards. Used inside route handlers: `Middleware::requireAuth();`.
 * Throws a Redirect/Response that the router catches (or exits directly).
 */
final class Middleware
{
    public static function requireAuth(string $redirectTo = '/login'): void
    {
        if (Auth::isLoggedIn()) return;
        header('Location: ' . $redirectTo, true, 303);
        exit;
    }

    public static function requireGuest(string $redirectTo = '/dashboard'): void
    {
        if (!Auth::isLoggedIn()) return;
        header('Location: ' . $redirectTo, true, 303);
        exit;
    }
}
