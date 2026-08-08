<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();

if (current_user() || suite_sso_attempt()) {
    header('Location: /');
    exit;
}

$error = '';
$email = 'frankie@8westit.com';
$next = $_GET['next'] ?? '/';
if (!str_starts_with((string)$next, '/')) $next = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $next  = (string)($_POST['next'] ?? '/');
    if (!str_starts_with($next, '/')) $next = '/';
    if (attempt_login($email, (string)($_POST['password'] ?? ''))) {
        header('Location: ' . $next);
        exit;
    }
    $error = 'That email and password didn’t match. Try again.';
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<script>document.documentElement.dataset.theme=localStorage.getItem("safeharbor.theme")||"system";</script>
<link rel="stylesheet" href="/assets/css/app.css?v=3">
</head>
<body class="login-body">
<div class="login-wrap">
  <div class="login-head">
    <img src="/assets/brand/favicon.svg" alt="" class="login-mark">
    <h1 class="login-name">Safeharbor</h1>
    <p class="login-tag">Every client issue, safely ashore.</p>
  </div>
  <div class="card login-card">
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="/login.php" class="login-form">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label>Work email
        <input type="email" name="email" value="<?= h($email) ?>" autofocus required>
      </label>
      <label>Password
        <input type="password" name="password" placeholder="••••••••" required>
      </label>
      <button type="submit" class="btn-primary">Sign in</button>
    </form>
    <div class="login-divider"><span></span>or<span></span></div>
    <a class="btn-ghost" href="https://id.8westit.com" style="text-decoration:none; display:flex; align-items:center; justify-content:center; gap:8px;">
      <img src="/assets/brand/safeharbor-mark.svg" alt="" class="sso-mark">
      Continue with 8 West ID
    </a>
  </div>
  <p class="login-foot">
    Demo: any seeded email — password <code>harbor</code><br>
    by 8 West IT, LLC · Part of the 8 West IT Total Business Suite
  </p>
  <div class="theme-switch login-theme" role="group" aria-label="Theme">
    <button type="button" class="theme-btn" data-theme-opt="dark" title="Dark" aria-label="Dark theme"><svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7Z" stroke-linejoin="round"/></svg></button>
    <button type="button" class="theme-btn" data-theme-opt="light" title="Light" aria-label="Light theme"><svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M12.6 3.4l-1 1M4.4 11.6l-1 1" stroke-linecap="round"/></svg></button>
    <button type="button" class="theme-btn" data-theme-opt="system" title="System" aria-label="Match system theme"><svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="3" width="12" height="8" rx="1.5"/><path d="M6 13.5h4" stroke-linecap="round"/></svg></button>
  </div>
<script src="/assets/js/app.js?v=2" defer></script>
</div>
</body>
</html>
