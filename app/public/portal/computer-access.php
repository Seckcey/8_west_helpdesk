<?php
/** Owner-issued access to one computer; the signed service owns all authority. */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_devices.php';
require_once __DIR__ . '/../../lib/portal_render.php';
enforce_https();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
if (!portal_enabled()) { http_response_code(404); exit('Not found.'); }
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST'); portal_render_error(405, 'Method not allowed', 'Open Computer access from Your devices.'); exit;
}
$token = is_string($_POST['token'] ?? $_GET['token'] ?? null) ? (string)($_POST['token'] ?? $_GET['token']) : '';
$device = is_string($_POST['device_reference'] ?? $_GET['device'] ?? null) ? (string)($_POST['device_reference'] ?? $_GET['device']) : '';
$error = null; $notice = null; $link = null; $access = null; $redeemed = false;
try {
    $context = portal_authenticated_context(db());
    if ($context === null) {
        // Keep the one-use credential out of the OIDC return URL. Reopen the private link after sign-in.
        portal_render_login('Sign in with your own account, then open this private computer link again.');
        exit;
    }
    $manage = portal_devices_can_manage($context);
    try {
        if ($method === 'POST') {
            if (!portal_csrf_valid($_POST['csrf'] ?? null)) throw new PortalDevicesException('sign_in', 403);
            $action = $_POST['action'] ?? '';
            if ($action === 'device_access_redeem') {
                $result = portal_devices_request(db(), $context, $action,
                    ['token' => $token, 'display_name' => (string)$context['identity']['display_name']]);
                $redeemed = $result['state'] === 'active';
                $notice = $redeemed ? 'This computer is now available in Your devices.' : null;
            } elseif ($action === 'device_access_create' && $manage) {
                $result = portal_devices_request(db(), $context, $action,
                    ['device_reference' => $device, 'request_key' => $_POST['request_key'] ?? '']);
                if ($result['token'] !== null) {
                    // The link, who to send it to and how long it works are shown once, in step 2 itself.
                    $link = 'https://safeharbor.8westit.com/portal/computer-access.php#token=' . $result['token'];
                } else {
                    $notice = 'This link was already created. Check access below; it was not replaced or sent again.';
                }
            } elseif ($action === 'device_access_revoke' && $manage) {
                portal_devices_request(db(), $context, $action, ['reference' => $_POST['reference'] ?? '']);
                $notice = 'Access revoked. The link cannot be reused. Work already delivered to the computer may still need review.';
            } else throw new PortalDevicesException('role', 403);
        }
        if ($manage && $device !== '') {
            $after = filter_var($_GET['after'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $access = portal_devices_request(db(), $context, 'device_access_list', ['device_reference' => $device, 'after' => $after === false ? 0 : $after]);
        }
    } catch (PortalDevicesException $failure) {
        $error = portal_devices_error($failure->reason);
        if ($method === 'POST') $error .= ' Check current access before creating another link or repeating the action.';
    }
    $fresh = portal_authenticated_context(db());
    if ($fresh === null || $fresh['identity'] !== $context['identity']) { portal_require_sign_in('Please sign in again.'); exit; }
    $fresh['chat_button_placement'] = 'inline';
    portal_page_start('Computer access', 'portal-devices-page', $fresh);
    ?>
<main class="portal-devices portal-computer-access">
  <header class="portal-devices-heading"><div><h1>Computer access</h1><p><?= $manage ? 'Who at ' . portal_h($fresh['binding']['client_name']) . ' can use this computer.' : 'A computer at ' . portal_h($fresh['binding']['client_name']) . ' that was chosen for you.' ?></p></div><div class="portal-devices-actions"><a href="/portal/devices.php">Your devices</a><?php portal_westy_button(); ?></div></header>
  <?php if ($error !== null): ?><p class="portal-device-notice" role="alert"><?= portal_h($error) ?></p><?php endif; ?>
  <?php if ($notice !== null): ?><p class="portal-device-notice is-success" role="status"><?= portal_h($notice) ?></p><?php endif; ?>
  <?php if ($manage && $device !== '' && $access !== null):
      // The 8 West ID member invite: an actual owner or admin, not someone acting through a customer profile.
      $canInvite = in_array($fresh['identity']['role'], ['client_owner', 'client_admin'], true) && !isset($fresh['identity']['customer_access']);
      $mark = static fn(int $number, bool $done): string => '<span class="setup-mark" aria-hidden="true">' . ($done ? setup_step_check() : (string)$number) . '</span>'; ?>
    <section class="portal-device-setup portal-access-steps" aria-labelledby="give-access-heading"><h2 id="give-access-heading">Give a member access</h2>
      <p>Two steps: the person gets their own sign-in, then this computer. Setting up their sign-in and giving them a computer are separate.</p>
      <ol class="setup-steps">
        <li class="setup-step is-later"><?= $mark(1, false) ?><div class="setup-text"><strong>The person needs their own sign-in</strong>
          <p>Skip this if they can already sign in here.</p>
          <?php if ($canInvite): ?><a class="btn-ghost portal-access-invite" href="https://id.8westit.com/users.php#employee-invitations">Invite a member in 8 West ID</a>
          <?php else: ?><p>Your support provider can add people for you.</p><?php endif; ?>
        </div></li>
        <?php // Never marked done here: a link is only an offer. The person has the computer once they use it, which the list below shows. ?>
        <li class="setup-step is-current"><?= $mark(2, false) ?><div class="setup-text"><strong>Give them this computer</strong>
          <?php if ($link !== null): ?>
          <div class="portal-access-link">
            <p class="field"><label for="computer-access-link">Private access link</label><input id="computer-access-link" type="text" readonly autocomplete="off" value="<?= portal_h($link) ?>"></p>
            <button type="button" class="btn-ghost" data-copy-text="<?= portal_h($link) ?>" data-copy-status="computer-access-copy-status" data-copied-text="Link copied. Send it to that person only.">Copy</button>
          </div>
          <p class="portal-hint" id="computer-access-copy-status" role="status">Send this link to that person only. The first member of <?= portal_h($fresh['binding']['client_name']) ?> who uses it while signed in gets this computer. It works once and stops working in 48 hours. No email is sent.</p>
          <script src="/assets/js/portal-devices.js?v=<?= portal_asset_version(__DIR__.'/../assets/js/portal-devices.js') ?>" defer></script>
          <?php else: ?><p>You get a private link to send to that person. It works once and stops working in 48 hours. No email is sent.</p><?php endif; ?>
          <form method="post" action="/portal/computer-access.php">
            <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
            <input type="hidden" name="action" value="device_access_create">
            <input type="hidden" name="device_reference" value="<?= portal_h($device) ?>">
            <input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
            <button class="<?= $link !== null ? 'btn-ghost' : 'btn-primary' ?>" type="submit">Create member access link</button>
          </form>
        </div></li>
      </ol>
    </section>
    <section class="portal-device-links" aria-labelledby="access-list-heading"><h2 id="access-list-heading">People and access links</h2>
      <?php if ($access['items'] === []): ?><p>No member access has been assigned to this computer yet.</p><?php endif; ?>
      <?php foreach ($access['items'] as $grant):
          $state = ['pending' => ['Waiting for a member', ' is-attention'], 'active' => ['Has access', ' is-online'], 'revoked' => ['Revoked', ''], 'expired' => ['Link expired', '']][$grant['state']]; ?><article>
        <div><strong><?= portal_h($grant['employee'] ?? 'Unclaimed access link') ?></strong><span class="portal-device-state<?= $state[1] ?>"><?= portal_h($state[0]) ?></span></div>
        <?php if (in_array($grant['state'], ['pending', 'active'], true)): ?><form method="post" action="/portal/computer-access.php">
          <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="device_access_revoke">
          <input type="hidden" name="device_reference" value="<?= portal_h($device) ?>"><input type="hidden" name="reference" value="<?= portal_h($grant['reference']) ?>">
          <button class="btn-link" type="submit">Revoke access</button>
        </form><?php endif; ?>
      </article><?php endforeach; ?>
      <?php if ($access['next_after'] !== null): ?><a href="/portal/computer-access.php?device=<?= rawurlencode($device) ?>&amp;after=<?= (int)$access['next_after'] ?>">Next access records</a><?php endif; ?>
    </section>
  <?php elseif (!$manage && !$redeemed): ?>
    <section class="portal-device-setup portal-access-steps" aria-labelledby="use-computer-heading"><h2 id="use-computer-heading">Use your assigned computer</h2><p>This private link gives your sign-in one computer at <?= portal_h($fresh['binding']['client_name']) ?>. What your account may do, including read-only access, stays the same.</p>
      <form method="post" action="/portal/computer-access.php">
        <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="device_access_redeem">
        <?php if ($token !== ''): ?>
          <input type="hidden" name="token" value="<?= portal_h($token) ?>">
        <?php else: ?>
          <div class="field" id="computer-access-code-entry">
            <label for="computer-access-code">Private access code</label>
            <input id="computer-access-code" name="token" type="password" autocomplete="off" autocapitalize="none" spellcheck="false"
                   required maxlength="64" pattern="[a-f0-9]{64}" aria-describedby="computer-access-code-help">
            <p id="computer-access-code-help" class="portal-hint">Copy the code after #token= from your original private link and paste it here.</p>
          </div>
          <script defer src="/assets/js/portal-computer-access.js?v=<?= portal_asset_version(__DIR__.'/../assets/js/portal-computer-access.js') ?>"></script>
        <?php endif; ?>
        <button class="btn-primary" type="submit">Use this computer</button>
      </form>
    </section>
  <?php elseif ($redeemed): ?><a class="btn-primary" href="/portal/devices.php">Open Your devices</a>
  <?php else: ?><p>Choose Computer access beside a computer in Your devices. Owners and administrators already have access to the computers in Your devices.</p><?php endif; ?>
</main>
<?php portal_page_end();
} catch (Throwable $failure) {
    error_log('[safeharbor-computer-access] request_failed type=' . $failure::class);
    portal_render_error(503, 'Computer access unavailable', 'We could not confirm current access. Check Your devices before repeating an action.');
}
