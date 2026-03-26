<?php
// =============================================================================
// src/Utils/Response.php
// Standardised JSON envelope for all API responses.
// Envelope: { success, message, data? } or { success, message, errors? }
// =============================================================================

declare(strict_types=1);

namespace PBBG\Utils;

class Response
{
    /**
     * Send a success JSON response and terminate.
     */
    public static function success(
        mixed  $data    = null,
        string $message = 'Success',
        int    $status  = 200
    ): never {
        self::send($status, [
            'success' => true,
            'message' => $message,
            ...($data !== null ? ['data' => $data] : []),
        ]);
    }

    /**
     * Send an error JSON response and terminate.
     */
    public static function error(
        string $message = 'An error occurred',
        int    $status  = 500,
        mixed  $errors  = null
    ): never {
        self::send($status, [
            'success' => false,
            'message' => $message,
            ...($errors !== null ? ['errors' => $errors] : []),
        ]);
    }

    /**
     * Send a 401 and terminate.
     */
    public static function unauthorized(string $message = 'Authentication required.'): never
    {
        self::error($message, 401);
    }

    /**
     * Send a 403 and terminate.
     */
    public static function forbidden(string $message = 'Access denied.'): never
    {
        self::error($message, 403);
    }

    // -------------------------------------------------------------------------
    private static function send(int $status, array $payload): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
