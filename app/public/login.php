<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/client_operational_context.php';
enforce_https();

$reauthRequired = (string)($_GET['reauth'] ?? '') === '1';
// A48: validate the intended path BEFORE any current-user or 8 West ID branch, so an existing or newly
// admitted STAFF session returns to it. The customer-role portal handoff below never uses it.
$safeNext = static function (mixed $value): string {
    if (! is_string($value)
        || preg_match('/^\/(?![\/\\\\])/D', $value) !== 1
        || preg_match('/[\x00-\x1F\x7F]/D', $value) === 1) {
        return '/';
    }

    return $value;
};
$next = $safeNext($_GET['next'] ?? '/');
$user = current_user();
if (! $user && ! $reauthRequired && suite_sso_attempt()) {
    $user = current_user();
}
// A signed-in customer belongs in the customer portal, not on the staff password form.
// Only a verified client role with Safeharbor, only on GET; the portal's own 8 West ID
// sign-in rechecks product, role, client binding and revocation. Nothing is carried over.
if (! $user && ! $reauthRequired && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $customerClaims = suite_sso_claims();
    if (is_array($customerClaims)
        && in_array($customerClaims['8west:role'] ?? null, ['client_owner', 'client_admin', 'client_staff', 'client_viewer'], true)
        && is_array($customerClaims['8west:products'] ?? null)
        && in_array('safeharbor', $customerClaims['8west:products'], true)) {
        header('Location: /portal/');
        exit;
    }
}
if ($user && ! $reauthRequired) {
    header('Location: ' . $next);
    exit;
}

$error = $reauthRequired
    ? 'Your 8 West ID access changed. Sign in there again to continue.'
    : '';
$email = '';

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

// A51: only a canonical Support target sends "Continue with 8 West ID" through ID's fixed launcher.
$idEntry = client_operational_support_launcher_url($next) ?? 'https://id.8westit.com';

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
<link rel="stylesheet" href="/assets/css/app.css?v=20261113-sh-desk2-floors-v1">
</head>
<body class="login-body login-split-body staff-signin">
<div class="login-split">
<div class="login-brand">
  <div class="login-head">
    <h1 class="login-name"><img src="/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png" alt="Safeharbor — 8 West IT 365" class="suite-login-logo" width="1851" height="513"></h1>
    <p class="login-tag">Every client issue, safely ashore.</p>
  </div>
</div>
<div class="login-wrap">
  <div class="card login-card">
    <?php if ($error): ?><p class="login-error"><?= h($error) ?></p><?php endif; ?>
    <a class="btn-primary login-id" href="<?= h($idEntry) ?>">
      <img src="/assets/brand/safeharbor-mark.svg" alt="" class="sso-mark">
      Continue with 8 West ID
    </a>
    <details class="login-backup"<?= $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' ? ' open' : '' ?>>
    <summary>Use a backup password</summary>
    <form method="post" action="/login.php" class="login-form">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label>Work email
        <input type="email" name="email" value="<?= h($email) ?>" required>
      </label>
      <label>Password
        <input type="password" name="password" required>
      </label>
      <button type="submit" class="btn-ghost">Sign in</button>
    </form>
    </details>
  </div>
  <p class="login-foot">
    <?php if (cfg('demo_mode', false)): ?>
      <strong>Sandbox.</strong> Seeded, fictional data — sign in with any seeded
      email, password <code>harbor</code>.<br>
    <?php endif; ?>
    8 West IT 365 · Your appearance follows your 8 West ID settings.
  </p>
<script src="/assets/js/app.js?v=20261113-sh-desk2-floors-v1" defer></script>
</div>
</div>
</body>
</html>
