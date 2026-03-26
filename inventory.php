<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
@ini_set('display_errors', '0');

require_once __DIR__ . '/app/config/config.php';

spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/app/src/' . str_replace(['PBBG\\', '\\'], ['', '/'], $class) . '.php';
    if (file_exists($file)) require_once $file;
});

$pageTitle   = 'Inventory';
$activePage  = 'inventory';
require_once __DIR__ . '/app/layout/_layout.php';
?>
<div class="page-header">
  <h1 class="page-title">Inventory</h1>
  <p class="page-sub">Your items and the shop.</p>
</div>
<div id="inventory-root"></div>
<?php
$pageScripts = ['./js/inventory-page.js'];
require_once __DIR__ . '/app/layout/_layout_end.php';
