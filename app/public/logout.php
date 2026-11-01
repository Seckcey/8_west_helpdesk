<?php
/**
 * Staff sign-out. Only a POST with this session's token signs anyone out, so a link, an image or
 * another site that makes the browser load this address does nothing. The account menu and the
 * ⌘K palette post here directly; opening the address shows a button that does the same.
 *
 * A post without the token goes to that page rather than to an error. Someone pressing Sign out in
 * an old tab, or pressing it twice, must be able to finish: "Session expired" reads as signed out,
 * while the 8 West ID cookie would still sign the next person at this machine in.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
enforce_https();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        header('Location: /logout.php', true, 303);
        exit;
    }
    logout();
    header('Location: ' . suite_logout_url());
    exit;
}

// With no session there is nothing to sign out of, and signing in from here goes to the queue.
$user = require_login('/');
page_top($user, 'Sign out', '');
?>
<div class="page page-narrow settings-page">
  <div class="page-head">
    <div>
      <h1 class="page-title">Sign out</h1>
    </div>
  </div>
  <div class="card form-card">
    <p>You are still signed in. Signing out ends your Safeharbor session on this browser and signs you out of 8 West ID.</p>
    <form method="post" action="/logout.php">
      <?= csrf_field() ?>
      <div class="form-actions">
        <button type="submit" class="btn-primary btn-sm">Sign out</button>
        <a class="btn-ghost settings-button" href="/">Back to the queue</a>
      </div>
    </form>
  </div>
</div>
<?php
page_bottom(palette_data());
