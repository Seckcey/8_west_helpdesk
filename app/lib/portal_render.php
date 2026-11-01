<?php
/** Dark-by-default presentation for the customer help portal. */
declare(strict_types=1);
require_once __DIR__ . '/portal_shell.php';
require_once __DIR__ . '/portal_preferences.php';

function portal_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_security_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
}

function portal_page_start(string $title, string $bodyClass = '', ?array $context = null): void
{
    $GLOBALS['portal_view_context'] = $context;
    $brand = portal_branding($context);
    $preferences = portal_personal_preferences($context['identity'] ?? []);
    $GLOBALS['portal_view_preferences'] = $preferences;
    if ($context !== null) $bodyClass = trim('portal-app ' . $bodyClass);
    portal_security_headers();
    ?>
<!doctype html>
<html lang="<?= portal_h($preferences['locale']) ?>" data-theme="<?= portal_h($preferences['theme']) ?>"
      data-preferences-source="8west-id" data-density="<?= portal_h($preferences['density']) ?>"
      data-contrast="<?= portal_h($preferences['contrast']) ?>" data-motion="<?= portal_h($preferences['motion']) ?>"
      data-time-zone="<?= portal_h($preferences['time_zone']) ?>" data-date-format="<?= portal_h($preferences['date_format']) ?>"
      data-time-format="<?= portal_h($preferences['time_format']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#061936">
<title><?= portal_h($title) ?> · <?= portal_h($brand['name']) ?></title>
<link rel="icon" type="<?= portal_h($brand['favicon_type']) ?>" href="<?= portal_h($brand['favicon']) ?>">
<link rel="stylesheet" href="/assets/css/app.css?v=5">
<link rel="stylesheet" href="/assets/css/portal.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal.css') ?>">
<link rel="stylesheet" href="/assets/css/portal-devices.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal-devices.css') ?>">
<link rel="stylesheet" href="/assets/css/portal-workspace.css?v=<?= portal_asset_version(__DIR__.'/../public/assets/css/portal-workspace.css') ?>">
<?php if(str_contains($bodyClass,'portal-security-page')): ?><link rel="stylesheet" href="/assets/css/portal-security.css?v=1"><?php endif; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . portal_h($bodyClass) . '"' : '' ?>>
    <?php
    if ($context !== null) portal_shell_start($context);
}

function portal_page_end(): void
{
    if (is_array($GLOBALS['portal_view_context'] ?? null)) {
        echo '</div>';
        portal_westy_widget($GLOBALS['portal_view_context']);
    }
    echo "</body>\n</html>\n";
}

/** Start customer OIDC on GET; never replay a POST or automatically retry a failure. */
function portal_require_sign_in(?string $message = null): void
{
    if (portal_login_can_start_automatically($_SERVER['REQUEST_METHOD'] ?? '', $_COOKIE)) {
        try {
            portal_redirect_to_identity($_SERVER['REQUEST_URI'] ?? '/portal/');
        } catch (Throwable $error) {
            error_log('[safeharbor-portal] login_failed type=' . $error::class);
            portal_render_error(503, 'Sign-in unavailable', '8 West ID sign-in is temporarily unavailable. Try again shortly.');
            return;
        }
    }
    portal_render_login($message ?? 'Sign-in was not completed. Continue with 8 West ID to try again.');
}

function portal_render_login(?string $message = null): void
{
    $brand = portal_branding();
    $returnPath = portal_safe_return_path($_SERVER['REQUEST_URI'] ?? '/portal/');
    portal_page_start('Customer portal', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
    <p class="login-tag">Ask for help and follow every customer-visible update</p>
  </header>
  <section class="card login-card" aria-labelledby="portal-sign-in-heading">
    <h2 id="portal-sign-in-heading" class="page-title">Customer sign in</h2>
    <p class="page-sub">Continue with your organization’s 8 West ID account.</p>
    <?php if ($message !== null && $message !== ''): ?>
      <p class="login-error" role="alert"><?= portal_h($message) ?></p>
    <?php endif; ?>
    <div class="login-form">
      <a class="btn-primary" href="/portal/login.php?next=<?= portal_h(rawurlencode($returnPath)) ?>">Continue with 8 West ID</a>
    </div>
  </section>
  <p class="login-foot">Use the portal to open, read, and reply to support tickets. Device control, internal notes, technician time, and billing stay private.</p>
</main>
    <?php
    portal_page_end();
}

function portal_render_client_choices(array $principal,array $choices): void
{
    portal_page_start('Choose a client for Westy');
    ?>
<main class="page page-narrow"><header class="page-head"><div>
<h1 class="page-title">Choose a client for Westy</h1>
<p class="page-sub">Continue as <?=portal_h($principal['display_name'])?>. Your 8 West ID account and other apps stay signed in.</p>
</div></header>
<?php if(!$choices): ?><p role="status">No customer access is currently assigned to this account. Ask your workspace administrator to review your access.</p><?php endif; ?>
<div class="portal-report-list"><?php foreach($choices as $choice): ?>
<section class="card login-card" aria-label="<?=portal_h($choice['binding']['client_name'])?>">
<h2 class="portal-client-name"><?=portal_h($choice['binding']['client_name'])?></h2>
<p class="page-sub"><?=portal_h(portal_role_label($choice['access']['role']))?></p>
<form method="post" class="login-form"><input type="hidden" name="csrf" value="<?=portal_h(portal_csrf_token())?>">
<input type="hidden" name="reference" value="<?=portal_h($choice['access']['access']['reference'])?>">
<input type="hidden" name="generation" value="<?=portal_h($choice['access']['access']['generation'])?>">
<button class="btn-primary" type="submit">Use Westy for this client</button></form></section>
<?php endforeach; ?></div></main>
<?php portal_page_end();
}

function portal_render_error(int $status, string $title, string $message): void
{
    $brand = portal_branding();
    http_response_code($status);
    portal_page_start($title, 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
  </header>
  <section class="card login-card">
    <h2 class="page-title"><?= portal_h($title) ?></h2>
    <p class="page-sub" role="alert"><?= portal_h($message) ?></p>
  </section>
</main>
    <?php
    portal_page_end();
}

/** Where a Companion window lands after Sign out: its shell stays on this origin, so this page does too. */
function portal_render_signed_out(bool $connectionOpen): void
{
    $brand = portal_branding();
    portal_page_start('Signed out', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="suite-login-logo" src="<?= portal_h($brand['logo']) ?>" alt="<?= portal_h($brand['alt']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>">
    <h1 class="login-name"><?= portal_h($brand['name']) ?></h1>
  </header>
  <section class="card login-card" aria-labelledby="portal-signed-out-heading">
    <h2 id="portal-signed-out-heading" class="page-title">You’re signed out of Westy</h2>
    <div class="login-form">
    <?php if ($connectionOpen): ?>
      <p class="page-sub" role="status">Your private chat is closed, but Westy could not confirm that this computer disconnected. The connection ends on its own within 30 minutes. To end it now, quit Westy from its icon (the system tray on Windows, the menu bar on a Mac).</p>
    <?php else: ?>
      <p class="page-sub" role="status">Your private chat is closed and this computer is no longer connected to your account. To connect again, open Westy from its icon (the system tray on Windows, the menu bar on a Mac).</p>
    <?php endif; ?>
      <p class="page-sub">Your web browser keeps its own sign-in. On a shared computer, sign out there too.</p>
      <a class="btn-ghost" target="_blank" rel="noopener noreferrer" href="/portal/">Open the customer portal in your browser</a>
    </div>
  </section>
</main>
    <?php
    portal_page_end();
}

function portal_status_label(string $status): string
{
    return match ($status) {
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting' => 'Waiting for your reply',
        'resolved' => 'Resolved',
        default => 'Unknown',
    };
}

function portal_relative_time(string $utc): string
{
    $timestamp = strtotime($utc . ' UTC');
    if ($timestamp === false) return 'Unknown';
    $minutes = max(1, (int)floor((time() - $timestamp) / 60));
    if ($minutes < 60) return $minutes . 'm ago';
    $hours = intdiv($minutes, 60);
    if ($hours < 24) return $hours . 'h ago';
    return intdiv($hours, 24) . 'd ago';
}

function portal_format_utc(?string $utc): string
{
    if ($utc === null || $utc === '') return 'Not available';
    $timestamp = strtotime($utc . ' UTC');
    return $timestamp === false ? 'Not available' : portal_personal_datetime($utc,
        $GLOBALS['portal_view_preferences'] ?? suite_preferences_defaults());
}

function portal_role_label(string $role): string
{
    return match ($role) {
        'client_owner' => 'Client owner',
        'client_admin' => 'Client administrator',
        'client_staff' => 'Member',
        'client_viewer' => 'Viewer',
        default => 'Client account',
    };
}

function portal_priority_label(string $priority): string
{
    return match ($priority) {
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
        default => 'Normal',
    };
}

/** @param list<array<string,mixed>> $tickets @param list<string> $statuses */
function portal_tickets_with_status(array $tickets, array $statuses): array
{
    return array_values(array_filter(
        $tickets,
        static fn(array $ticket): bool => in_array((string)($ticket['status'] ?? ''), $statuses, true),
    ));
}

/** @param list<array<string,mixed>> $tickets */
function portal_render_ticket_list(array $tickets, string $emptyMessage): void
{
    ?>
<div class="card rail-list portal-ticket-list">
  <?php if ($tickets === []): ?>
    <div class="empty"><?= portal_h($emptyMessage) ?></div>
  <?php else: ?>
    <?php foreach ($tickets as $ticket): ?>
      <a class="rail-list-item portal-ticket-link" href="/portal/ticket.php?id=<?= (int)$ticket['id'] ?>">
        <div class="portal-ticket-row">
          <div class="portal-ticket-copy">
            <h3 class="rail-list-name">#<?= (int)$ticket['id'] ?> · <?= portal_h($ticket['subject']) ?></h3>
            <p class="rail-list-sub">
              <?= portal_h(portal_priority_label((string)$ticket['priority'])) ?> priority
              · Updated <?= portal_h(portal_relative_time((string)$ticket['updated_at'])) ?>
            </p>
          </div>
          <span class="portal-status portal-status-<?= portal_h((string)$ticket['status']) ?>"><?= portal_h(portal_status_label((string)$ticket['status'])) ?></span>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
    <?php
}

function portal_report_period_label(
    string $periodStart,
    string $periodEnd,
    string $scheduleTimezone = 'UTC',
): string
{
    try {
        $utc = new DateTimeZone('UTC');
        $timezone = new DateTimeZone($scheduleTimezone);
        $start = (new DateTimeImmutable($periodStart, $utc))->setTimezone($timezone);
        $endExclusive = (new DateTimeImmutable($periodEnd, $utc))->setTimezone($timezone);
        if ($endExclusive <= $start) return 'Archived week';
        return $start->format('M j') . ' – ' . $endExclusive->modify('-1 second')->format('M j, Y');
    } catch (Throwable) {
        return 'Archived week';
    }
}

/** @param array<string,mixed> $archive */
function portal_render_report_preview(array $archive, bool $latest = false): void
{
    $metrics = is_array($archive['metrics'] ?? null) ? $archive['metrics'] : [];
    $tickets = is_array($metrics['tickets'] ?? null) ? $metrics['tickets'] : [];
    $goal = is_array($metrics['service_goal'] ?? null) ? $metrics['service_goal'] : [];
    $period = is_array($metrics['period'] ?? null) ? $metrics['period'] : [];
    $attainment = $goal['attainment_percent'] ?? null;
    ?>
<a class="card portal-report-preview" href="/portal/reports.php?id=<?= (int)$archive['id'] ?>">
  <div class="portal-report-preview-head">
    <div>
      <p class="portal-report-kicker"><?= $latest ? 'Latest archived summary' : 'Archived summary' ?></p>
      <h3><?= portal_h(portal_report_period_label(
          (string)$archive['period_start'],
          (string)$archive['period_end'],
          (string)($period['schedule_timezone'] ?? 'UTC'),
      )) ?></h3>
    </div>
    <span class="portal-report-version">v<?= (int)$archive['definition_version'] ?></span>
  </div>
  <p class="page-sub">
    <?= (int)($tickets['opened'] ?? 0) ?> opened · <?= (int)($tickets['resolved'] ?? 0) ?> resolved
    · Service goal <?= $attainment === null ? 'not yet decided' : (int)$attainment . '%' ?>
  </p>
  <span class="portal-report-open">View summary →</span>
</a>
    <?php
}

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 * @param null|list<array<string,mixed>> $reportArchives
 */
function portal_render_dashboard(array $context, array $summary, ?array $reportArchives = []): void
{
    $identity = $context['identity'];
    $context['binding']['client_name'] = $summary['client']['name'];
    $canWrite = portal_role_can_write_tickets(portal_customer_role($identity));
    $waiting = portal_tickets_with_status($summary['tickets'], ['waiting']);
    portal_page_start('Your support', '', $context);
    ?>
<main class="portal-home">
 <div class="portal-main-column">
  <section class="portal-ask" aria-labelledby="portal-ask-heading">
   <h1 id="portal-ask-heading">Support requests</h1>
   <div class="portal-shortcuts">
    <a href="/portal/?view=westy"><?= portal_icon('chat') ?>Ask Westy</a>
    <a href="/portal/guide.php"><?= portal_icon('guide') ?>How this portal works</a>
    <a href="/portal/devices.php"><?= portal_icon('device') ?>Your devices</a>
    <?php if ($canWrite): ?><a href="/portal/new.php"><?= portal_icon('edit') ?>Write a request</a><?php endif; ?>
   </div>
   <p class="portal-hint">Do not include passwords or verification codes.</p>
  </section>
  <section class="portal-attention" aria-labelledby="attention-heading"><h2 id="attention-heading">Needs your attention</h2>
   <?php portal_render_ticket_list($waiting, 'Nothing needs your reply right now.'); ?>
  </section>
  <section class="portal-requests" id="requests" aria-labelledby="requests-heading">
   <div class="portal-section-title"><h2 id="requests-heading"><?= portal_client_admin($identity) ? 'Client support requests' : 'Your support requests' ?></h2><?php if ($canWrite): ?><a href="/portal/new.php">Open support request <?= portal_icon('arrow') ?></a><?php endif; ?></div>
   <p class="portal-hint"><?= portal_client_admin($identity) ? 'Client-wide view.' : 'Requests you opened.' ?> Showing up to 50 recent requests.</p>
   <div class="portal-counts" aria-label="Ticket counts"><?php foreach (['open'=>'Open','in_progress'=>'In progress','waiting'=>'Waiting on you','resolved'=>'Resolved'] as $status=>$label): ?><span><?= portal_h($label) ?> <strong><?= (int)($summary['counts'][$status] ?? 0) ?></strong></span><?php endforeach; ?></div>
   <?php portal_render_ticket_list($summary['tickets'], 'No support requests yet. When you need a hand, write a request and follow its progress here.'); ?>
  </section>
 </div>
 <aside class="portal-context-rail">
  <section id="contact"><h2>Need a person?</h2><p>Send a request directly to the support team.</p><a href="/portal/guide.php#contact">Contact support <?= portal_icon('arrow') ?></a></section>
  <?php if (portal_client_admin($identity)): ?><section><h2>Service summaries</h2>
   <?php if ($reportArchives === null): ?><p>Service summaries are temporarily unavailable. Your tickets still work normally.</p>
   <?php elseif ($reportArchives === []): ?><p>No weekly service summaries have been archived for this business yet.</p>
   <?php else: portal_render_report_preview($reportArchives[0], true); endif; ?>
   <a href="/portal/reports.php">View all archived summaries <?= portal_icon('arrow') ?></a>
  </section>
  <?php endif; ?><section><h2>Your privacy</h2><p>Your requests are visible to you, client owners/admins and the support team. Your Westy chat is private to you.</p><a href="/portal/guide.php#privacy">About your chat history</a></section>
 </aside>
</main>
    <?php
    portal_page_end();
}

/**
 * Home: one person's overview of their own requests, computers and next steps.
 * Every list comes from the controller's requester-safe reads. Null means the
 * read failed and is said so; it is never shown as zero.
 *
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param null|array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 * @param null|array{items:list<array<string,mixed>>,next_after:?int} $devices
 * @param null|list<array<string,mixed>> $reportArchives
 */
function portal_render_home(array $context, ?array $summary, ?array $devices, ?array $reportArchives = null): void
{
    $identity = $context['identity'];
    if ($summary !== null) $context['binding']['client_name'] = $summary['client']['name'];
    $client = trim((string)($context['binding']['client_name'] ?? '')) ?: 'Your account';
    $name = trim((string)($identity['display_name'] ?? ''));
    $role = portal_customer_role($identity);
    $canWrite = portal_role_can_write_tickets($role);
    // Owners and admins see the whole client; everyone else sees only their own records.
    $clientWide = portal_client_admin($identity);
    $avatar = portal_branding($context)['avatar'];
    $count = static fn(int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    $scope = $clientWide ? 'You see every support request and computer for ' . $client . '.'
        : ($canWrite ? 'You see the requests you opened and the computers assigned to you.'
            : 'You can read the requests opened under your account and see the computers assigned to you. Viewer access cannot write requests or replies.');
    // Westy's trigger closes the rail in normal flow, so it never covers requests or help text.
    $context['chat_button_placement'] = 'inline';
    portal_page_start('Home', '', $context);
    ?>
<main class="portal-home portal-overview">
 <section class="portal-overview-hero" aria-labelledby="portal-home-heading">
  <img class="portal-overview-avatar" src="<?= portal_h($avatar) ?>" alt="" width="64" height="64">
  <div class="portal-overview-intro">
   <p class="portal-overview-client"><?= portal_h($client) ?></p>
   <h1 id="portal-home-heading"><?= $name === '' ? 'Welcome' : 'Welcome, ' . portal_h($name) ?></h1>
   <p class="portal-overview-context"><strong><?= portal_h(portal_role_label($role)) ?></strong> · <?= portal_h($scope) ?></p>
  </div>
  <nav class="portal-overview-actions" aria-label="Start here">
   <a class="portal-overview-action is-primary" href="/portal/?view=westy"><span class="portal-overview-action-icon"><?= portal_icon('chat') ?></span><span><strong>Ask Westy</strong><span>Private help with your computer</span></span></a>
   <?php if ($canWrite): ?><a class="portal-overview-action" href="/portal/new.php"><span class="portal-overview-action-icon"><?= portal_icon('edit') ?></span><span><strong>Write a request</strong><span>Send the details to the support team</span></span></a><?php endif; ?>
   <a class="portal-overview-action" href="/portal/devices.php"><span class="portal-overview-action-icon"><?= portal_icon('device') ?></span><span><strong>Your devices</strong><span><?= $clientWide ? 'Computers for this client' : 'Computers assigned to you' ?></span></span></a>
   <a class="portal-overview-action" href="https://id.8westit.com/settings.php"><span class="portal-overview-action-icon"><?= portal_icon('person') ?></span><span><strong>My settings in 8 West ID</strong><span>Your name, appearance and sign-in</span></span></a>
  </nav>
 </section>
 <div class="portal-main-column">
  <?php if ($summary === null): ?>
  <section class="portal-overview-card" aria-labelledby="home-requests-heading">
   <h2 id="home-requests-heading">Support requests</h2>
   <p class="portal-overview-problem" role="status">We can’t show your support requests right now. Nothing about them has changed. Try again shortly.</p>
   <div class="portal-overview-buttons"><a class="portal-overview-button" href="/portal/">Try again</a><?php if ($canWrite): ?><a class="portal-overview-button" href="/portal/new.php">Write a request</a><?php endif; ?></div>
  </section>
  <?php else:
      $counts = $summary['counts'];
      $waitingTotal = (int)($counts['waiting'] ?? 0);
      $total = 0;
      foreach (['open', 'in_progress', 'waiting', 'resolved'] as $status) $total += (int)($counts[$status] ?? 0);
      $waiting = portal_tickets_with_status($summary['tickets'], ['waiting']);
      $recent = portal_tickets_with_status($summary['tickets'], ['in_progress', 'open', 'resolved']); ?>
  <section class="portal-attention portal-overview-card" aria-labelledby="home-reply-heading">
   <h2 id="home-reply-heading"><?= $canWrite ? 'Needs your reply' : 'Waiting for a reply' ?></h2>
   <?php if ($waitingTotal > 0): ?><p class="portal-hint"><?= portal_h($clientWide ? $count($waitingTotal, 'request', 'requests') . ' across ' . $client . ' waiting for a reply.'
       : ($canWrite ? $count($waitingTotal, 'request is', 'requests are') . ' waiting for your reply.'
           : $count($waitingTotal, 'request', 'requests') . ' waiting. Viewer access can read them but cannot reply.')) ?></p><?php endif; ?>
   <?php portal_render_ticket_list($waiting, $canWrite ? 'Nothing needs your reply right now.' : 'No request is waiting for a reply.'); ?>
   <?php if ($waitingTotal > count($waiting)): ?><p class="portal-hint">Showing <?= count($waiting) ?> of <?= $waitingTotal ?>. <a href="/portal/requests.php">See every request</a></p><?php endif; ?>
  </section>
  <section class="portal-requests portal-overview-card" aria-labelledby="home-recent-heading">
   <div class="portal-section-title"><h2 id="home-recent-heading"><?= $clientWide ? 'Recent client requests' : 'Your recent requests' ?></h2><a href="/portal/requests.php">All support requests <?= portal_icon('arrow') ?></a></div>
   <p class="portal-hint"><?= $clientWide ? 'Client-wide view.' : 'Requests you opened.' ?> Active requests come first, then recently resolved ones.</p>
   <div class="portal-counts" aria-label="Request counts"><?php foreach (['open'=>'Open','in_progress'=>'In progress','waiting'=>'Waiting for a reply','resolved'=>'Resolved'] as $status=>$label): ?><span><?= portal_h($label) ?> <strong><?= (int)($counts[$status] ?? 0) ?></strong></span><?php endforeach; ?></div>
   <?php if ($total === 0): ?>
   <div class="portal-overview-empty"><p><strong>No support requests yet.</strong></p><p><?= $canWrite ? 'When something isn’t working, ask Westy or write a request, then follow its progress here.' : 'Requests opened under your account will appear here.' ?></p></div>
   <?php else: portal_render_ticket_list($recent, 'No other open or recently resolved requests.'); endif; ?>
   <?php if ($total > count($summary['tickets'])): ?><p class="portal-hint">Home shows <?= count($summary['tickets']) ?> of <?= $total ?>. <a href="/portal/requests.php">See every request</a></p><?php endif; ?>
  </section>
  <?php endif; ?>
 </div>
 <aside class="portal-context-rail" aria-label="Computers and help">
  <section class="portal-overview-card" aria-labelledby="home-devices-heading">
   <h2 id="home-devices-heading"><?= $clientWide ? 'Computers' : 'Your computers' ?></h2>
   <?php if ($devices === null): ?>
   <p class="portal-overview-problem" role="status">We can’t show your computers right now. Nothing about them has changed.</p>
   <a class="portal-overview-button" href="/portal/devices.php">Open Your devices</a>
   <?php elseif (($devices['items'] ?? []) === []): ?>
   <p><?= $clientWide ? 'No computers are connected for ' . portal_h($client) . ' yet. Add a Windows computer from Your devices.' : 'No computers are assigned to you yet. Ask your business owner or administrator to add yours.' ?></p>
   <a class="portal-overview-button" href="/portal/devices.php">Open Your devices</a>
   <?php else:
       $items = $devices['items'];
       $more = ($devices['next_after'] ?? null) !== null; ?>
   <p class="portal-hint"><?= $more ? 'More than ' . count($items) . ' computers' : portal_h($count(count($items), 'computer', 'computers')) ?></p>
   <ul class="portal-overview-devices">
    <?php foreach (array_slice($items, 0, 3) as $device): ?>
    <li><strong><?= portal_h($device['label']) ?></strong><span class="portal-device-state<?= $device['connection'] === 'reporting' ? ' is-online' : '' ?>"><?= portal_h($device['connection_label']) ?></span><?php if (($device['last_seen_at'] ?? null) !== null): ?><span class="portal-hint">Last check-in <?= portal_h(portal_format_utc($device['last_seen_at'])) ?></span><?php endif; ?></li>
    <?php endforeach; ?>
   </ul>
   <a class="portal-overview-button" href="/portal/devices.php"><?= count($items) > 3 || $more ? 'See all computers' : 'Open Your devices' ?></a>
   <?php endif; ?>
  </section>
  <?php if ($clientWide): ?>
  <section class="portal-overview-card" aria-labelledby="home-summaries-heading"><h2 id="home-summaries-heading">Service summaries</h2>
   <?php if ($reportArchives === null): ?><p>Service summaries are temporarily unavailable. Your tickets still work normally.</p>
   <?php elseif ($reportArchives === []): ?><p>No weekly service summaries have been archived for this business yet.</p>
   <?php else: portal_render_report_preview($reportArchives[0], true); endif; ?>
   <a href="/portal/reports.php">View all archived summaries <?= portal_icon('arrow') ?></a>
  </section>
  <section class="portal-overview-card" aria-labelledby="home-manage-heading"><h2 id="home-manage-heading">Manage <?= portal_h($client) ?></h2>
   <p>People, plan and computer access. Each destination checks your access again.</p>
   <div class="portal-overview-buttons">
    <a class="portal-overview-button" href="<?= portal_h(safeharbor_settings_panel_url('client_admin')) ?>">Client Admin in 8 West ID</a>
    <a class="portal-overview-button" href="/portal/devices.php">Manage computer access</a>
    <a class="portal-overview-button" href="https://id.8westit.com/customer-billing.php">Plan and billing in 8 West ID</a>
   </div>
  </section>
  <?php endif; ?>
  <section class="portal-overview-card" aria-labelledby="home-help-heading"><h2 id="home-help-heading">Need a person?</h2>
   <p>Reach the support team directly, or read how this portal works.</p>
   <a href="/portal/guide.php#contact">Contact support <?= portal_icon('arrow') ?></a>
   <p class="portal-hint">Your requests are visible to you, client owners/admins and the support team. Your Westy chat is private to you. <a href="/portal/guide.php#privacy">About your privacy</a></p>
  </section>
  <?php portal_westy_button(); ?>
 </aside>
</main>
    <?php
    portal_page_end();
}

function portal_render_workspace(array $context): void
{
    $context['workspace']=true;
    portal_page_start('Westy','portal-workspace',$context);
    echo '<main id="portal-chat-home-slot" aria-label="Westy support workspace"><noscript><p>Enable JavaScript to chat with Westy, or <a href="/portal/new.php">contact support</a>.</p></noscript></main>';
    portal_page_end();
}

/** @param list<array<string,mixed>> $archives */
function portal_render_reports(array $context, array $archives): void
{
    $clientName = (string)($context['binding']['client_name'] ?? 'Your business');
    portal_page_start('Weekly service summaries', '', $context);
    ?>
<main class="page page-narrow">
  <a href="/portal/" class="backlink">← Home</a>
  <header class="page-head portal-ticket-head">
    <div>
      <p class="page-sub"><?= portal_h($clientName) ?></p>
      <h1 class="page-title">Weekly service summaries</h1>
      <p class="page-sub">Each card opens the exact verified archive saved for that week.</p>
    </div>
  </header>
  <div class="portal-report-list">
    <?php if ($archives === []): ?>
      <div class="card empty">No weekly service summaries have been archived for this business yet.</div>
    <?php else: ?>
      <?php foreach ($archives as $index => $archive): ?>
        <?php portal_render_report_preview($archive, $index === 0); ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <p class="page-note">These summaries contain customer-level service totals only. They do not show ticket messages, internal notes, technician entries or rates, recipients, delivery attempts, invoices, AI controls, or endpoint controls.</p>
</main>
    <?php
    portal_page_end();
}

/** @param array<string,mixed> $archive */
function portal_render_report(array $context, array $archive): void
{
    $metrics = $archive['metrics'];
    $tickets = $metrics['tickets'];
    $firstResponse = $metrics['first_response'];
    $goal = $metrics['service_goal'];
    $approvedTime = $metrics['approved_billable_time'];
    $csat = $metrics['csat'];
    $attainment = $goal['attainment_percent'];
    $responseAverage = $firstResponse['average_minutes'];
    $approvedMinutes = (int)$approvedTime['minutes'];
    $csatAverage = $csat['average_score_out_of_3'];
    portal_page_start('Weekly service summary', '', $context);
    ?>
<main class="page page-narrow">
  <a href="/portal/reports.php" class="backlink">← All service summaries</a>
  <header class="page-head portal-ticket-head">
    <div>
      <p class="portal-report-kicker">Verified archive · definition v<?= (int)$archive['definition_version'] ?></p>
      <h1 class="page-title"><?= portal_h(portal_report_period_label(
          (string)$archive['period_start'],
          (string)$archive['period_end'],
          (string)($metrics['period']['schedule_timezone'] ?? 'UTC'),
      )) ?></h1>
      <p class="page-sub"><?= portal_h((string)$metrics['source']['client_name']) ?> · generated <?= portal_h(portal_format_utc((string)$archive['generated_at'])) ?></p>
    </div>
  </header>

  <section class="stats portal-report-stats" aria-label="Weekly service totals">
    <article class="card stat"><div class="stat-k">Tickets opened</div><div class="stat-v"><?= (int)$tickets['opened'] ?></div></article>
    <article class="card stat"><div class="stat-k">Tickets resolved</div><div class="stat-v stat-good"><?= (int)$tickets['resolved'] ?></div></article>
    <article class="card stat"><div class="stat-k">Avg. first response</div><div class="stat-v"><?= $responseAverage === null ? '—' : (int)$responseAverage . ' min' ?></div></article>
    <article class="card stat"><div class="stat-k">Response goal</div><div class="stat-v <?= $attainment === null ? 'stat-dim' : 'stat-good' ?>"><?= $attainment === null ? '—' : (int)$attainment . '%' ?></div></article>
  </section>

  <section class="portal-report-detail-grid">
    <article class="card portal-report-fact">
      <span class="rail-k">Approved operational time</span>
      <strong><?= $approvedMinutes ?> minutes · <?= portal_h(number_format($approvedMinutes / 60, 2, '.', '')) ?> hours</strong>
      <p>Customer-level approved service time only. This is not an invoice or posting status.</p>
    </article>
    <article class="card portal-report-fact">
      <span class="rail-k">Customer satisfaction</span>
      <strong><?= $csatAverage === null ? 'No scored responses' : portal_h((string)$csatAverage) . ' / 3' ?></strong>
      <p><?= (int)$csat['responses_received_by_generated_at'] ?> responses from <?= (int)$csat['surveys_sent'] ?> surveys.</p>
    </article>
  </section>

  <details class="card portal-report-official">
    <summary>Read the exact archived summary</summary>
    <pre><?= portal_h((string)$archive['text']) ?></pre>
  </details>
  <p class="page-note">Archive SHA-256: <code><?= portal_h((string)$archive['content_sha256']) ?></code>. The portal verifies the immutable definition, canonical JSON/text, hash, tenant, customer, period, and generation time before showing this page.</p>
</main>
    <?php
    portal_page_end();
}

/** @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context */
function portal_render_new_ticket(array $context, ?string $error = null, array $values = []): void
{
    $clientName = (string)($context['binding']['client_name'] ?? 'Your business');
    $nonce = portal_action_nonce('ticket:create');
    portal_page_start('Get help', '', $context);
    ?>
<main class="page page-narrow">
  <a href="/portal/" class="backlink">← Home</a>
  <header class="page-head">
    <div>
      <p class="page-sub"><?= portal_h($clientName) ?></p>
      <h1 class="page-title">What can we help with?</h1>
      <p class="page-sub">Tell the support team what is happening. A human remains responsible for resolving the ticket.</p>
    </div>
  </header>
  <?php if ($error !== null && $error !== ''): ?><div class="form-error" role="alert"><?= portal_h($error) ?></div><?php endif; ?>
  <section class="card form-card">
    <form method="post" action="/portal/new.php" class="form-grid">
      <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
      <input type="hidden" name="action_nonce" value="<?= portal_h($nonce) ?>">
      <label class="field span-2">Short summary
        <input type="text" name="subject" maxlength="190" required autofocus autocomplete="off"
               value="<?= portal_h($values['subject'] ?? '') ?>"
               placeholder="The front desk computer cannot print">
      </label>
      <label class="field">How urgent is it?
        <select name="priority">
          <?php foreach (PORTAL_TICKET_PRIORITIES as $priority): ?>
            <option value="<?= portal_h($priority) ?>"<?= (($values['priority'] ?? 'normal') === $priority) ? ' selected' : '' ?>><?= portal_h(portal_priority_label($priority)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="field portal-priority-help">
        <span>Priority guide</span>
        <p>Urgent means the business cannot operate. High means important work is blocked.</p>
      </div>
      <label class="field span-2">What happened?
        <textarea name="body" maxlength="<?= PORTAL_TICKET_BODY_MAX_CHARACTERS ?>" required
                  placeholder="What were you trying to do, what happened instead, and who is affected?"><?= portal_h($values['body'] ?? '') ?></textarea>
      </label>
      <p class="page-note span-2">This request will be visible to the requester, client owners/admins and the support team. Only the text in this form is sent. Do not include passwords, verification codes or secret keys.</p>
      <div class="form-actions span-2">
        <button type="submit" class="btn-primary">Send request</button>
        <a class="btn-link" href="/portal/">Cancel</a>
      </div>
    </form>
  </section>
</main>
    <?php
    portal_page_end();
}

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{ticket:array<string,mixed>,messages:list<array<string,mixed>>} $detail
 */
function portal_render_ticket(
    array $context,
    array $detail,
    ?string $notice = null,
    ?string $error = null,
    string $draft = '',
): void {
    $identity = $context['identity'];
    $ticket = $detail['ticket'];
    $ticketId = (int)$ticket['id'];
    $canWrite = portal_role_can_write_tickets(portal_customer_role($identity));
    $canReply = $canWrite && ($ticket['status'] ?? '') !== 'resolved';
    $nonce = $canReply ? portal_action_nonce('ticket:reply:' . $ticketId) : '';
    portal_page_start('Ticket #' . $ticketId, '', $context);
    ?>
<main class="page">
  <a href="/portal/" class="backlink">← Home</a>
  <header class="page-head portal-ticket-head">
    <div>
      <div class="ticket-head">
        <span class="ticket-num">#<?= $ticketId ?></span>
        <span class="portal-status portal-status-<?= portal_h((string)$ticket['status']) ?>"><?= portal_h(portal_status_label((string)$ticket['status'])) ?></span>
      </div>
      <h1 class="ticket-subject"><?= portal_h($ticket['subject']) ?></h1>
      <p class="ticket-sub"><?= portal_h(portal_priority_label((string)$ticket['priority'])) ?> priority · Opened <?= portal_h(portal_format_utc((string)$ticket['created_at'])) ?></p>
    </div>
    <?php if ($canWrite): ?><a class="btn-primary" href="/portal/new.php">New request</a><?php endif; ?>
  </header>

  <?php if ($notice !== null && $notice !== ''): ?><div class="form-ok" role="status"><?= portal_h($notice) ?></div><?php endif; ?>
  <?php if ($error !== null && $error !== ''): ?><div class="form-error" role="alert"><?= portal_h($error) ?></div><?php endif; ?>
  <?php if (($ticket['status'] ?? '') === 'waiting' && $canReply): ?>
    <div class="card portal-waiting-callout" role="status">
      <strong>The support team is waiting for your reply.</strong>
      <span>Send an update below and this request will return to the open queue for a human technician.</span>
    </div>
  <?php endif; ?>

  <div class="ticket-grid">
    <section class="thread" aria-labelledby="portal-conversation-heading">
      <h2 id="portal-conversation-heading" class="rail-label">Conversation</h2>
      <?php if ($detail['messages'] === []): ?>
        <div class="card empty">No customer-visible messages have been posted yet.</div>
      <?php else: ?>
        <?php foreach ($detail['messages'] as $message):
            $isTech = ($message['kind'] ?? '') === 'tech'; ?>
          <article class="card msg <?= $isTech ? 'msg-tech' : 'msg-client' ?>">
            <div class="msg-meta">
              <span class="<?= $isTech ? 'msg-author-tech' : 'msg-author' ?>"><?= portal_h($message['author_name']) ?></span>
              <span class="msg-when"><?= portal_h(portal_format_utc((string)$message['created_at'])) ?></span>
            </div>
            <div class="msg-body"><?= portal_h($message['body']) ?></div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($canReply): ?>
        <form method="post" action="/portal/ticket.php?id=<?= $ticketId ?>" class="card reply">
          <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
          <input type="hidden" name="action_nonce" value="<?= portal_h($nonce) ?>">
          <label class="field" for="portal-reply"><?= ($ticket['status'] ?? '') === 'waiting' ? 'Send the update the support team needs' : 'Reply to the support team' ?></label>
          <textarea id="portal-reply" name="body" maxlength="<?= PORTAL_TICKET_BODY_MAX_CHARACTERS ?>" required
                    placeholder="Add an update or answer the technician’s question…"><?= portal_h($draft) ?></textarea>
          <div class="reply-foot">
            <span class="reply-hint">Your reply is visible to the requester, client owners/admins and the support team.</span>
            <button type="submit" class="btn-primary btn-sm">Send reply</button>
          </div>
        </form>
      <?php elseif (($ticket['status'] ?? '') === 'resolved'): ?>
        <div class="card portal-closed-note">This ticket is resolved. Open a new request if you still need help.</div>
      <?php else: ?>
        <div class="card portal-closed-note">Your viewer role can read this ticket but cannot send replies.</div>
      <?php endif; ?>
    </section>

    <aside class="rail" aria-label="Ticket details">
      <h2 class="rail-label">Ticket details</h2>
      <div class="card rail-card">
        <span class="rail-k">Status</span>
        <span class="rail-v"><?= portal_h(portal_status_label((string)$ticket['status'])) ?></span>
      </div>
      <div class="card rail-card">
        <span class="rail-k">Response goal</span>
        <?php if (! empty($ticket['first_response_at'])): ?>
          <span class="rail-v">First response sent</span>
          <span class="rail-note"><?= portal_h(portal_format_utc((string)$ticket['first_response_at'])) ?></span>
        <?php else: ?>
          <span class="rail-v">Due <?= portal_h(portal_format_utc((string)$ticket['sla_due_at'])) ?></span>
          <?php if (! empty($ticket['service_goal_policy_name'])): ?>
            <span class="rail-note"><?= portal_h($ticket['service_goal_policy_name']) ?> v<?= (int)$ticket['service_goal_version_no'] ?> · <?= (int)$ticket['service_goal_response_minutes'] ?> elapsed minutes</span>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card rail-card">
        <span class="rail-k">Privacy</span>
        <span class="rail-note">Internal notes, automation evidence, technician time, billing, and device controls are never shown here.</span>
      </div>
    </aside>
  </div>
</main>
    <?php
    portal_page_end();
}
