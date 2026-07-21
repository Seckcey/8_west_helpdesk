<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();

if (current_user()) {
    header('Location: /');
    exit;
}

$error = '';
$email = 'frankie@8westit.com';
$next = $_GET['next'] ?? '/';
if (!str_starts_with((string)$next, '/')) $next = '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
<link rel="stylesheet" href="/assets/css/app.css?v=1">
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
    <button class="btn-ghost" type="button" title="8 West ID SSO arrives with the suite contract" disabled>
      <img src="/assets/brand/safeharbor-mark.svg" alt="" class="sso-mark">
      Continue with 8 West ID
    </button>
  </div>
  <p class="login-foot">
    Demo: any seeded email — password <code>harbor</code><br>
    by 8 West IT, LLC · Part of the 8 West IT Total Business Suite
  </p>
</div>
</body>
</html>
