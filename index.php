<?php
declare(strict_types=1);
require_once __DIR__ . '/app/config/config.php';
spl_autoload_register(function($c){$f=__DIR__.'/app/src/'.str_replace(['PBBG\\','\\'],['','/'],$c).'.php';if(file_exists($f))require_once $f;});
use PBBG\Middleware\Auth;
Auth::startSession();
if (Auth::check()) { header('Location: ./dashboard.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Realm of Echoes — Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Cinzel+Decorative:wght@400;700&family=Crimson+Text:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="./css/style.css"/>
</head>
<body>
<main class="auth-page">
  <div class="auth-box">
    <div class="auth-logo">Realm of Echoes</div>
    <div class="auth-tag">A Fantasy Pet Chronicle</div>

    <div class="card">
      <div class="tab-row">
        <button class="tab-btn active" data-tab="login">Sign In</button>
        <button class="tab-btn" data-tab="register">Register</button>
      </div>

      <div id="form-msg" class="flash"></div>

      <form id="login-form" class="auth-form active" novalidate>
        <div class="form-group"><label>Username or Email</label><input id="login-id" type="text" autocomplete="username" placeholder="Your name or email…" required/></div>
        <div class="form-group"><label>Password</label><input id="login-pw" type="password" autocomplete="current-password" placeholder="Your password…" required/></div>
        <button type="submit" class="btn btn-primary btn-full">Enter the Realm</button>
      </form>

      <form id="register-form" class="auth-form" novalidate>
        <div class="form-group"><label>Username</label><input id="reg-name" type="text" maxlength="32" placeholder="Letters, numbers, underscores…" required/></div>
        <div class="form-group"><label>Email</label><input id="reg-email" type="email" placeholder="your@email.com" required/></div>
        <div class="form-group"><label>Password</label><input id="reg-pw" type="password" placeholder="At least 8 characters…" required/></div>
        <div class="form-group"><label>Confirm Password</label><input id="reg-pw2" type="password" placeholder="Repeat password…" required/></div>
        <button type="submit" class="btn btn-primary btn-full">Begin Your Legend</button>
      </form>
    </div>
  </div>
</main>
<script type="module" src="./js/auth.js"></script>
</body></html>
