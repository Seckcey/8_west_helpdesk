<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
enforce_https();

$reauthRequired = (string)($_GET['reauth'] ?? '') === '1';
$user = current_user();
if (! $user && ! $reauthRequired && suite_sso_attempt()) {
    $user = current_user();
}
if ($user && ! $reauthRequired) {
    header('Location: /');
    exit;
}

$error = $reauthRequired
    ? 'Your 8 West ID access changed. Sign in there again to continue.'
    : '';
$email = 'frankie@8westit.com';
$safeNext = static function (mixed $value): string {
    if (! is_string($value)
        || preg_match('/^\/(?![\/\\\\])/D', $value) !== 1
        || preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
        return '/';
    }

    return $value;
};
$next = $safeNext($_GET['next'] ?? '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim((string)($_POST['email'] ?? ''));
    $next = $safeNext($_POST['next'] ?? '/');
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
<link rel="stylesheet" href="/assets/css/app.css?v=4">
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
    <?php if (cfg('demo_mode', false)): ?>
      <strong>Sandbox.</strong> Seeded, fictional data — sign in with any seeded
      email, password <code>harbor</code>.<br>
    <?php endif; ?>
    by 8 West IT, LLC · Part of the 8 West IT Total Business Suite
  </p>
  <p class="login-foot">Your appearance follows your global 8 West ID settings after sign-in.</p>
<script src="/assets/js/app.js?v=3" defer></script>
</div>
</body>
</html>
