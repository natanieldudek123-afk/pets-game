<?php
$env = parse_ini_file(__DIR__ . '/../../.env');
declare(strict_types=1);

// Suppress PHP notices/warnings from leaking into JSON API responses.
// Fatal errors are still logged server-side via error_log().
// Remove E_ALL suppression if you need to debug — add it back for production.
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

// ---------------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------------
define('APP_ENV',  'production');
define('APP_NAME', 'Realm of Echoes');

// ---------------------------------------------------------------------------
// Database 
// ---------------------------------------------------------------------------
define('DB_PORT', $env['DB_PORT']);
define('DB_HOST', $env['DB_HOST']);
define('DB_NAME', $env['DB_NAME']);
define('DB_USER', $env['DB_USER']);
define('DB_PASS', $env['DB_PASS']);
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------
define('SESSION_NAME',     'pbbg_session');
define('SESSION_LIFETIME', 60 * 60 * 24 * 7); // 7 days

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------
define('BCRYPT_COST',    12);
define('CSRF_TOKEN_KEY', '_csrf_token');

// ---------------------------------------------------------------------------
// Game — Tick Engine
// ---------------------------------------------------------------------------
define('TICK_HUNGER_DECAY',    2);    // Per tick
define('TICK_HAPPINESS_DECAY', 1);    // Per tick
define('TICK_INTERVAL_SECS',   300);  // 5 minutes — must match cron schedule

// ---------------------------------------------------------------------------
// Game — Pet
// ---------------------------------------------------------------------------
define('HATCH_DURATION_SECS', 300);  // 5 minutes
define('FEED_AMOUNT',          20);
define('PLAY_AMOUNT',          15);
define('STAT_MIN',              0);
define('STAT_MAX',            100);

// Species base stats [str, agi, int, vit]
define('SPECIES_BASE_STATS', [
    'BEAR' => ['base_str' => 14, 'base_agi' =>  8, 'base_int' =>  6, 'base_vit' => 16],
    'FOX'  => ['base_str' => 10, 'base_agi' => 15, 'base_int' => 10, 'base_vit' => 10],
    'OWL'  => ['base_str' =>  6, 'base_agi' => 10, 'base_int' => 16, 'base_vit' =>  8],
]);

// Tier growth multipliers
define('TIER_MULTIPLIERS', [1 => 1.0, 2 => 1.25, 3 => 1.6, 4 => 2.0, 5 => 2.5]);
