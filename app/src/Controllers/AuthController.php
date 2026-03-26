<?php
// =============================================================================
// src/Controllers/AuthController.php
// Thin HTTP layer — parses input, calls AuthService, sends JSON envelope.
// =============================================================================

declare(strict_types=1);

namespace PBBG\Controllers;

use PBBG\Services\AuthService;
use PBBG\Middleware\Auth;
use PBBG\Utils\Response;
use PBBG\Utils\Validator;

class AuthController
{
    // -------------------------------------------------------------------------
    // POST /api.php?action=auth.register
    // -------------------------------------------------------------------------
    public static function register(array $body): void
    {
        $v = new Validator($body);
        $v->required('username')->minLength('username', 3)->maxLength('username', 32)->alphaNum('username')
          ->required('email')->email('email')
          ->required('password')->minLength('password', 8)->maxLength('password', 128);

        if ($v->fails()) {
            Response::error('Validation failed.', 400, $v->errors());
        }

        try {
            $user = AuthService::register(
                $v->get('username'),
                $v->get('email'),
                $v->get('password')
            );
            Auth::login($user['id'], $user['username']);
            Response::success(
                ['username' => $user['username'], 'email' => $user['email']],
                "Welcome to the Realm, {$user['username']}! Your adventure begins.",
                201
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api.php?action=auth.login
    // -------------------------------------------------------------------------
    public static function login(array $body): void
    {
        $v = new Validator($body);
        $v->required('identifier')->required('password');

        if ($v->fails()) {
            Response::error('Validation failed.', 400, $v->errors());
        }

        try {
            $user = AuthService::login($v->get('identifier'), $v->get('password'));
            Auth::login($user['id'], $user['username']);
            Response::success(
                ['username' => $user['username'], 'email' => $user['email']],
                "Welcome back, {$user['username']}!"
            );
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 401);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api.php?action=auth.logout
    // -------------------------------------------------------------------------
    public static function logout(): void
    {
        Auth::logout();
        Response::success(null, 'You have been safely logged out.');
    }

    // -------------------------------------------------------------------------
    // GET /api.php?action=auth.me   (protected)
    // -------------------------------------------------------------------------
    public static function me(): void
    {
        $sessionUser = Auth::require(); // Terminates with 401 if not logged in

        try {
            $profile = AuthService::getProfile($sessionUser['id']);
            Response::success($profile);
        } catch (\RuntimeException $e) {
            Response::error($e->getMessage(), $e->getCode() ?: 500);
        }
    }
}
