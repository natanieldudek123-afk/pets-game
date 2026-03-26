<?php
declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    // If called via web, only allow from localhost
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once dirname(__DIR__) . '/config/config.php';

spl_autoload_register(function (string $class): void {
    $base = dirname(__DIR__) . '/src/';
    $file = $base . str_replace(['PBBG\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

use PBBG\Services\TickEngine;

try {
    $result = TickEngine::runTick();
    echo $result . PHP_EOL;
    // Also write to a log file so we can verify it ran
    $logFile = dirname(__DIR__) . '/logs/tick.log';
    @mkdir(dirname($logFile), 0755, true);
    @file_put_contents($logFile, $result . PHP_EOL, FILE_APPEND);
} catch (\Throwable $e) {
    $msg = '[' . date('Y-m-d H:i:s') . '] Tick FAILED: ' . $e->getMessage()
         . ' in ' . $e->getFile() . ':' . $e->getLine();
    echo $msg . PHP_EOL;
    @file_put_contents(dirname(__DIR__) . '/logs/tick.log', $msg . PHP_EOL, FILE_APPEND);
    exit(1);
}
