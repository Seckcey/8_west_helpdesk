<?php
declare(strict_types=1);
require_once __DIR__ . '/portal_render.php';
require_once __DIR__ . '/portal_devices.php';

function portal_render_devices(array $context, ?array $devices, array $enrollments, ?string $error = null, ?array $download = null, ?string $notice = null, ?array $protection = null, ?array $employee = null, ?array $choices = null): void
{
    $manage = portal_devices_can_manage($context);
    // Protection requests are for the customer's own owner/admin only, never delegated access.
    $request = $manage && !array_key_exists('customer_access', $context['identity'] ?? []);
    $requestLabel = $employeeLabel = $choiceLabel = 'the selected computer';
    foreach ($devices['items'] ?? [] as $device) {
        if ($protection !== null && $device['reference'] === $protection['input']['device_reference']) $requestLabel = $device['label'];
        if ($employee !== null && $device['reference'] === $employee['input']['device_reference']) $employeeLabel = $device['label'];
        if ($choices !== null && $device['reference'] === $choices['device_reference']) $choiceLabel = $device['label'];
    }
    // A saved answer is shown as the earlier answer it is: never as a new check, an installation or a bill change.
    $employeeState = $employee === null ? null : ($employee['result']['state'] ?? 'unknown');
    // Adding comes first while there is nothing else to look at, and while a setup file is waiting to be used.
    $addFirst = $download !== null || ($manage && $devices !== null && ($devices['items'] ?? []) === []);
    // The page holds every computer only when its first page loaded and no further page follows. The
    // waiting script starts from this list only then; otherwise it reads the whole list itself first.
    $wholeList = $devices !== null && ($devices['next_after'] ?? null) === null && (int)($_GET['after'] ?? 0) === 0;
    $context['chat_button_placement'] = 'inline';
    portal_page_start('Your devices', 'portal-devices-page', $context);
    ?>
<main class="portal-devices">
 <header class="portal-devices-heading"><div><h1>Your devices</h1><p>Computers your support team can help with.</p></div><div class="portal-devices-actions"><a class="btn-link" href="/portal/devices.php">Refresh status</a><?php if ($manage && !$addFirst): ?><a class="btn-ghost portal-add-jump" href="#add-computer">Add a computer</a><?php endif; ?><?php portal_westy_button(); ?></div></header>
 <?php if ((cfg('portal_mobile', [])['enabled'] ?? false) === true): ?><p><a class="btn-link" href="/portal/mobile.php">Phones, tablets &amp; Macs <?= portal_icon('arrow') ?></a></p><?php endif; ?>
 <?php if ($error !== null): ?><div class="portal-device-notice" role="alert"><?= portal_h($error) ?> <a href="/portal/new.php">Contact support</a></div><?php endif; ?>
 <?php if ($notice !== null): ?><div class="portal-device-notice is-success" role="status"><?= portal_h($notice) ?></div><?php endif; ?>
 <?php if ($addFirst) portal_render_add_computer($context, $manage, $download); ?>
 <div class="portal-devices-layout<?= $addFirst && !($manage && $enrollments !== []) ? ' is-single' : '' ?>"><section aria-labelledby="computers-heading">
  <div class="portal-section-title"><h2 id="computers-heading">Connected computers</h2></div>
  <div id="portal-device-list"<?= $devices !== null ? ' data-loaded' : '' ?><?= $wholeList ? ' data-complete' : '' ?>>
  <?php if ($devices === null): ?><p>We cannot retrieve your computers right now. Their status has not been changed.</p>
  <?php elseif (($devices['items'] ?? []) === []): ?><div class="portal-device-empty"><?= portal_icon('device') ?><h3>No computers on this page yet</h3><p><?= $manage ? 'Add your first Windows computer with the three steps above. It appears here a few minutes after setup.' : 'Ask an owner or administrator to add your computers.' ?></p></div>
  <?php else: ?><div class="portal-device-list"><?php foreach ($devices['items'] as $device): $attention = $device['connection'] !== 'reporting'; ?>
   <article class="portal-device-card" data-device="<?= portal_h($device['reference']) ?>"><div class="portal-device-symbol"><?= portal_icon('device') ?></div><div class="portal-device-details"><h3><?= portal_h($device['label']) ?></h3>
    <p class="portal-device-status"><span class="portal-device-state<?= $device['connection'] === 'reporting' ? ' is-online' : ($device['connection'] === 'stale' ? ' is-attention' : '') ?>"><?= portal_h($device['connection_label']) ?></span><span class="portal-device-platform"><?= portal_h($device['platform']) ?></span></p>
    <?php if ($attention): ?><p class="portal-device-next"><?= portal_h($device['connection_help']) ?></p><?php endif; ?>
    <?php if ($device['last_seen_at'] !== null): ?><p class="portal-hint">Last check-in: <?= portal_h(portal_format_utc($device['last_seen_at'])) ?></p><?php endif; ?>
    <?php if(isset($device['hardware'])): ?><p class="portal-hint">Memory: <?= portal_h((string)$device['hardware']['ram_gb']) ?> GB. Inventory recorded <?= portal_h(portal_format_utc($device['hardware']['observed_at'])) ?>.</p><?php endif; ?>
    <div class="portal-device-links-row">
    <?php if ($manage): ?><a class="btn-link" href="/portal/computer-access.php?device=<?= rawurlencode($device['reference']) ?>">Computer access <?= portal_icon('arrow') ?></a><?php endif; ?>
    <button type="button" class="btn-link" data-portal-chat-prompt="<?= portal_h('Help me describe a problem with my computer ' . $device['label'] . '. It shows ' . $device['connection_label'] . '.') ?>">Ask Westy for help <?= portal_icon('arrow') ?></button>
    <?php if((cfg('portal_devices',[])['diagnostics_enabled']??false)===true):?><a class="btn-link" href="/portal/device_help.php?device=<?= rawurlencode($device['reference']) ?>">Computer checks <?= portal_icon('arrow') ?></a><?php endif;?>
    <?php if((cfg('portal_devices',[])['security_orders_enabled']??false)===true && str_contains(strtolower($device['platform']),'windows')):?><a class="btn-link" href="/portal/security.php?device=<?= rawurlencode($device['reference']) ?>">Secure Plus <?= portal_icon('arrow') ?></a><?php endif;?>
    </div>
   </div></article>
  <?php endforeach; ?></div><?php if (($devices['next_after'] ?? null) !== null): ?><a href="/portal/devices.php?after=<?= (int)$devices['next_after'] ?>">Next computers <?= portal_icon('arrow') ?></a><?php endif; ?><?php endif; ?>
  </div>
  <p class="portal-hint">A connected computer is available for support. Antivirus protection and any paid add-ons have their own status.</p>
 </section><aside>
  <?php if (!$addFirst) portal_render_add_computer($context, $manage, null); ?>
  <?php if ($manage && $enrollments !== []): ?><section class="portal-device-links" aria-labelledby="setup-links-heading"><h2 id="setup-links-heading">Setup links you created</h2><p class="portal-hint">Each link adds one computer. Revoking a link does not remove a computer that is already added.</p>
  <?php foreach ($enrollments as $grant): ?><article><div><strong><?= portal_h(['ready'=>'Ready to use','enrolled'=>'Computer added','revoked'=>'Link revoked','expired'=>'Link expired'][$grant['state']] ?? 'Unavailable') ?></strong><span><?= portal_h(portal_format_utc($grant['created_at'])) ?></span></div>
   <?php if ($grant['state'] === 'ready'): ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="reference" value="<?= portal_h($grant['reference']) ?>"><button name="action" value="enrollment_download" class="btn-link">Get setup link</button><button name="action" value="enrollment_revoke" class="btn-link">Revoke</button></form><?php endif; ?>
  </article><?php endforeach; ?></section><?php endif; ?>
 </aside></div>
 <?php if ($request): ?><div class="portal-device-protection"><h2>Antivirus protection</h2><p class="portal-hint">Ask your support team to protect a computer. A request installs nothing and does not change your bill.</p><div class="portal-device-protection-forms">
  <section class="portal-device-setup" aria-labelledby="protection-heading"><h3 id="protection-heading">Protection request</h3>
  <?php if ($protection !== null && $protection['result'] !== null): ?><div class="portal-device-notice is-success" role="status">Earlier answer, not checked again: <?= $protection['result']['state'] === 'recorded' ? 'the protection request for ' . portal_h($requestLabel) . ' was saved.' : 'a newer request for ' . portal_h($requestLabel) . ' replaced this one.' ?></div>
  <?php elseif ($protection !== null): ?><div class="portal-device-notice" role="status">The protection request for <?= portal_h($requestLabel) ?> has no confirmed result. Retrying sends the same request with its original key, so it cannot create a second one.</div>
   <form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="protection_retry"><button type="submit" class="btn-ghost">Retry the same request</button></form><?php endif; ?>
  <?php if (($devices['items'] ?? []) === []): ?><p>A protection request needs a computer listed on this page.</p>
  <?php else: ?><p>Tell your support team which computer you want protected, and whether it is the same computer as an earlier record.</p>
   <form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="protection_request"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
    <div class="portal-chat-draft-fields"><label>Computer<select name="device_reference" required><option value="">Choose a computer</option><?php foreach ($devices['items'] as $device): ?><option value="<?= portal_h($device['reference']) ?>"><?= portal_h($device['label']) ?></option><?php endforeach; ?></select></label></div>
    <fieldset><legend>Which computer is it?</legend><p class="portal-hint">Choose “the same computer” if this computer was replaced or set up again.</p>
     <label class="portal-device-consent"><input type="radio" name="computer" value="new_computer" required><span>A new computer</span></label>
     <label class="portal-device-consent"><input type="radio" name="computer" value="same_computer"><span>The same computer as an earlier record</span></label></fieldset>
    <div class="portal-chat-draft-fields"><label>Earlier record, only for the same computer<select name="same_as_device_reference"><option value="">None: a new computer</option><?php foreach ($devices['items'] as $device): ?><option value="<?= portal_h($device['reference']) ?>"><?= portal_h($device['label']) ?></option><?php endforeach; ?></select></label></div>
    <button type="submit" class="btn-ghost">Send protection request</button>
   </form><?php endif; ?>
  <p class="portal-hint">Sending this request does not install protection or change your bill.</p></section>
  <section class="portal-device-setup" aria-labelledby="employee-protection-heading"><h3 id="employee-protection-heading">Protection for an employee</h3>
  <?php if ($employeeState === 'recorded' || $employeeState === 'superseded'): ?><div class="portal-device-notice is-success" role="status">Earlier answer, not checked again: <?= $employeeState === 'recorded' ? 'the request for an employee on ' . portal_h($employeeLabel) . ' was saved.' : 'a newer request for ' . portal_h($employeeLabel) . ' replaced this one.' ?></div>
  <?php elseif ($employeeState === 'absent'): ?><div class="portal-device-notice" role="status">Earlier answer, not checked again: no request for an employee on <?= portal_h($employeeLabel) ?> was saved. You can send that same request again. If the employee list has expired, show the employees again and choose again instead; that is a new request.</div>
   <form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="employee_retry"><button type="submit" class="btn-ghost">Send the same request again</button></form>
  <?php elseif ($employeeState === 'unknown'): ?><div class="portal-device-notice" role="status">The request for an employee on <?= portal_h($employeeLabel) ?> has no confirmed result. Checking its status asks about that same request and changes nothing.</div>
   <form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="employee_status"><button type="submit" class="btn-ghost">Check request status</button></form><?php endif; ?>
  <?php if ($choices !== null): ?><p>Your account's current employees for <?= portal_h($choiceLabel) ?>. Choose one before <?= portal_h(portal_format_utc(gmdate('Y-m-d H:i:s', $choices['expires_at']))) ?>.</p>
   <?php if ($choices['employees'] === []): ?><p>No employees are listed here.</p>
   <?php else: ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="employee_request"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>"><input type="hidden" name="device_reference" value="<?= portal_h($choices['device_reference']) ?>">
    <div class="portal-chat-draft-fields"><label>Employee<select name="employee_reference" required><option value="">Choose an employee</option><?php foreach ($choices['employees'] as $member): ?><option value="<?= portal_h($member['employee_reference']) ?>"><?= portal_h($member['display_name']) ?></option><?php endforeach; ?></select></label></div>
    <fieldset><legend>Which computer is it?</legend><p class="portal-hint">Choose “the same computer” if this computer was replaced or set up again.</p>
     <label class="portal-device-consent"><input type="radio" name="computer" value="new_computer" required><span>A new computer</span></label>
     <label class="portal-device-consent"><input type="radio" name="computer" value="same_computer"><span>The same computer as an earlier record</span></label></fieldset>
    <div class="portal-chat-draft-fields"><label>Earlier record, only for the same computer<select name="same_as_device_reference"><option value="">None: a new computer</option><?php foreach ($devices['items'] ?? [] as $device): ?><option value="<?= portal_h($device['reference']) ?>"><?= portal_h($device['label']) ?></option><?php endforeach; ?></select></label></div>
    <button type="submit" class="btn-ghost">Send request for this employee</button>
   </form><?php endif; ?>
   <?php if ($choices['next_cursor'] !== null): ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="employee_choices"><input type="hidden" name="device_reference" value="<?= portal_h($choices['device_reference']) ?>"><input type="hidden" name="cursor" value="<?= portal_h($choices['next_cursor']) ?>"><button type="submit" class="btn-link">More employees</button></form><?php endif; ?>
   <a class="btn-link" href="/portal/devices.php">Choose a different computer</a>
  <?php elseif (($devices['items'] ?? []) === []): ?><p>Choosing an employee needs a computer listed on this page.</p>
  <?php else: ?><p>For a business account, choose the computer, then the employee who uses it from your account's current list.</p>
   <form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="employee_choices"><input type="hidden" name="cursor" value="">
    <div class="portal-chat-draft-fields"><label>Computer<select name="device_reference" required><option value="">Choose a computer</option><?php foreach ($devices['items'] as $device): ?><option value="<?= portal_h($device['reference']) ?>"><?= portal_h($device['label']) ?></option><?php endforeach; ?></select></label></div>
    <button type="submit" class="btn-ghost">Show employees</button>
   </form><?php endif; ?>
  <p class="portal-hint">Choosing an employee gives them no access to this account or computer. Sending this request does not install protection or change your bill.</p></section>
 </div></div><?php endif; ?>
</main>
<?php portal_page_end();
}

/**
 * "Add a computer" as three steps: confirm, install, wait. With a setup file ($download) the first step
 * is done, the second holds the download and the third waits for the computer to appear in the list.
 */
function portal_render_add_computer(array $context, bool $manage, ?array $download): void
{
    $step = static function (int $number, string $state): string {
        return '<li class="setup-step is-' . $state . '"><span class="setup-mark" aria-hidden="true">'
            . ($state === 'done' ? setup_step_check() : (string)$number) . '</span><div class="setup-text">';
    };
    ?>
  <section class="portal-device-setup portal-add-computer" id="add-computer" aria-labelledby="add-device-heading"><h2 id="add-device-heading">Add a computer</h2>
  <?php if (!$manage): ?><p>Owners and admins can add computers. This page lists the computers assigned to your account.</p></section><?php return; endif; ?>
  <p>About five minutes on a Windows computer. You need to be an administrator on that computer.</p>
  <ol class="setup-steps">
   <?= $step(1, $download === null ? 'current' : 'done') ?><strong>Confirm</strong><?php if ($download !== null): ?><span class="sr-only"> (done)</span><p>You confirmed that this computer may be added.</p>
    <?php else: ?><form method="post" action="/portal/devices.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><input type="hidden" name="action" value="enrollment_create"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(16)) ?>">
     <label class="portal-device-consent"><input type="checkbox" name="consent" value="yes" required><span>I am authorized to add this computer to <?= portal_h($context['binding']['client_name']) ?> and allow the support team to manage it.</span></label>
     <button type="submit" class="btn-primary">Create Windows setup link</button>
    </form><?php endif; ?></div></li>
   <?= $step(2, $download === null ? 'later' : 'current') ?><?php if ($download === null): ?><strong>Install</strong><p>You get a setup file to open on the computer you are adding.</p>
    <?php else: ?><h3 id="install-title">Install on your Windows computer</h3><p>On the computer you want to add, download the setup file and open it. Windows asks for an administrator. Say yes.</p>
     <div class="portal-device-download"><a class="btn-primary" href="<?= portal_h($download['download_url']) ?>" rel="noreferrer">Download Milepost setup</a>
      <button type="button" class="btn-ghost" data-copy-text="<?= portal_h($download['download_url']) ?>" data-copy-status="portal-copy-status">Copy link</button></div>
     <p class="portal-hint" id="portal-copy-status" role="status">Adding a different computer? Copy the link and open it there.</p>
     <p class="portal-hint">This private link adds one computer and stops working <?= portal_h(portal_format_utc($download['expires_at'])) ?>. Share it only with someone who may set up that computer.</p>
    <?php endif; ?></div></li>
   <?= $step(3, 'later') ?><strong>Wait for it to appear</strong><?php if ($download === null): ?><p>The computer shows up under Connected computers a few minutes after setup finishes.</p>
    <?php else: ?><p id="portal-device-wait" role="status" data-device-wait data-watching-text="Keep that computer on and online. This page is checking for it and will show it here by itself." data-found-text="is connected. It is under Connected computers." data-stopped-text="Not showing yet? Choose Refresh status, or ask Westy.">Keep that computer on and online. It appears under Connected computers a few minutes after setup finishes. Choose Refresh status to check.</p>
    <?php endif; ?></div></li>
  </ol>
  <p class="portal-hint">Adding a computer does not order antivirus or create a charge. <a href="/portal/new.php">Need help with Mac or Linux?</a></p>
  <?php if ($download !== null): ?><script src="/assets/js/portal-devices.js?v=<?= portal_asset_version(__DIR__ . '/../public/assets/js/portal-devices.js') ?>" defer></script><?php endif; ?>
  </section>
    <?php
}
