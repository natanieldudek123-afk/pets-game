<?php
// _layout.php — only outputs HTML structure
// config.php and autoloader must be loaded by the calling page before this

use PBBG\Middleware\Auth;

Auth::startSession();

if (!Auth::check()) {
    header('Location: ./index.php');
    exit;
}

$username   = htmlspecialchars(Auth::user()['username'] ?? 'Adventurer', ENT_QUOTES, 'UTF-8');
$pageTitle  = isset($pageTitle)  ? $pageTitle  : 'Realm of Echoes';
$activePage = isset($activePage) ? $activePage : 'hearth';

$nav = [
    'hearth'    => ['href' => './dashboard.php', 'icon' => '&#8962;', 'label' => 'Hearth'],
    'pets'      => ['href' => './pets.php',       'icon' => '&#128062;', 'label' => 'My Pets'],
    'explore'   => ['href' => './explore.php',    'icon' => '&#128506;', 'label' => 'Explore'],
    'inventory' => ['href' => './inventory.php',  'icon' => '&#127874;', 'label' => 'Inventory'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle) ?> &mdash; Realm of Echoes</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Cinzel+Decorative:wght@400;700&family=Crimson+Text:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="./css/style.css"/>
</head>
<body>
<div class="layout">
  <header class="topbar">
    <div class="topbar-logo">Realm of Echoes</div>
    <div class="topbar-actions">
      <div class="currency-badge"><span>&#128142;</span><span id="stat-crystals">0</span></div>
      <div class="currency-badge"><span>&#10022;</span><span id="stat-prestige">0</span></div>
      <a href="./logout.php" class="btn btn-ghost btn-sm">Leave Realm</a>
    </div>
  </header>
  <nav class="sidebar">
    <div class="nav-label">Adventure</div>
    <?php foreach ($nav as $key => $item): ?>
    <a href="<?= $item['href'] ?>" class="nav-item <?= ($activePage === $key) ? 'active' : '' ?>">
      <span class="nav-icon"><?= $item['icon'] ?></span> <?= htmlspecialchars($item['label']) ?>
    </a>
    <?php endforeach; ?>
    <div class="nav-label" style="margin-top:1rem;">Soon</div>
    <span class="nav-item disabled"><span class="nav-icon">&#9876;</span> Combat</span>
    <span class="nav-item disabled"><span class="nav-icon">&#127963;</span> Sanctuary</span>
    <div class="sidebar-footer">
      <a href="./logout.php" class="nav-item"><span class="nav-icon">&#8617;</span> Leave Realm</a>
    </div>
  </nav>
  <main class="main">
