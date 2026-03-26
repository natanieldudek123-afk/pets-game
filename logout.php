<?php
declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';

spl_autoload_register(function (string $class): void {
    $base = __DIR__ . '/app/src/';
    $file = $base . str_replace(['PBBG\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use PBBG\Middleware\Auth;

Auth::logout();
header('Location: ./index.php');
exit;
