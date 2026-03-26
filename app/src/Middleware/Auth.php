<?php
// =============================================================================
// src/Middleware/Auth.php
// Session guard — compatible with PHP 7.x and 8.x on Home.pl
//
// Uses the 6-argument session_set_cookie_params() instead of the array syntax
// to avoid any PHP version compatibility issues on shared hosts.
// session_regenerate_id() is only called when a session is confirmed active.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Middleware;

use PBBG\Utils\Response;

class Auth
{
    public static function require(): array
    {
        self::startSession();

        if (empty($_SESSION['user']['id'])) {
            Response::unauthorized('Authentication required. Please log in.');
        }

        return $_SESSION['user'];
    }

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

        session_name(SESSION_NAME);

        // Use 6-arg form — works on PHP 5.2+ unlike the array syntax (PHP 7.3+)
        // samesite is set via ini_set as a fallback for older PHP
        @ini_set('session.cookie_samesite', 'Strict');
        session_set_cookie_params(
            SESSION_LIFETIME,   // lifetime
            '/',                // path
            '',                 // domain
            $isSecure,          // secure
            true                // httponly
        );

        @session_start();
    }

    public static function login(int $id, string $username): void
    {
        self::startSession();

        // Only regenerate if session is confirmed active
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }

        $_SESSION['user'] = [
            'id'       => $id,
            'username' => $username,
        ];
    }

    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        // Expire the cookie
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_destroy();
        }
    }

    public static function check(): bool
    {
        self::startSession();
        return !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        self::startSession();
        return $_SESSION['user'] ?? null;
    }
}
