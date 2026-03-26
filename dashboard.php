<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
@ini_set('display_errors', '0');

require_once __DIR__ . '/app/config/config.php';

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/app/src/' . str_replace(['PBBG\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

$pageTitle  = 'Hearth';
$activePage = 'hearth';
require_once __DIR__ . '/app/layout/_layout.php';
?>

<div class="page-header">
  <h1 class="page-title">Welcome back, <?= $username ?></h1>
  <p class="page-sub">Your realm awaits.</p>
</div>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-card-icon">&#9996;</div><div class="stat-card-label">Account Level</div><div class="stat-card-value" id="stat-level">—</div></div>
  <div class="stat-card"><div class="stat-card-icon">&#128062;</div><div class="stat-card-label">Companions</div><div class="stat-card-value" id="stat-pets">—</div></div>
  <div class="stat-card"><div class="stat-card-icon">&#10022;</div><div class="stat-card-label">Prestige</div><div class="stat-card-value" id="stat-prestige-card">—</div></div>
  <div class="stat-card"><div class="stat-card-icon">&#128142;</div><div class="stat-card-label">Crystals</div><div class="stat-card-value" id="stat-crystals-card">—</div></div>
</div>

<div class="quick-links">
  <a href="./pets.php"      class="quick-link">&#128062; My Pets</a>
  <a href="./explore.php"   class="quick-link">&#128506; Explore</a>
  <a href="./inventory.php" class="quick-link">&#127874; Inventory</a>
</div>

<?php
$pageScripts = ['./js/dashboard.js'];
require_once __DIR__ . '/app/layout/_layout_end.php';
