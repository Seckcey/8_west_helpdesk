<?php
/** Minimal, dark-by-default presentation for the read-only customer portal. */
declare(strict_types=1);

function portal_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function portal_security_headers(): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
}

function portal_page_start(string $title, string $bodyClass = ''): void
{
    portal_security_headers();
    ?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#061936">
<title><?= portal_h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="stylesheet" href="/assets/css/app.css?v=3">
</head>
<body<?= $bodyClass !== '' ? ' class="' . portal_h($bodyClass) . '"' : '' ?>>
    <?php
}

function portal_page_end(): void
{
    echo "</body>\n</html>\n";
}

function portal_render_login(?string $message = null): void
{
    portal_page_start('Customer portal', 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="login-mark" src="/assets/brand/favicon.svg" alt="">
    <h1 class="login-name">Safeharbor</h1>
    <p class="login-tag">Read-only ticket summaries for your business</p>
  </header>
  <section class="card login-card" aria-labelledby="portal-sign-in-heading">
    <h2 id="portal-sign-in-heading" class="page-title">Customer sign in</h2>
    <p class="page-sub">Continue with your organization’s 8 West ID account.</p>
    <?php if ($message !== null && $message !== ''): ?>
      <p class="login-error" role="alert"><?= portal_h($message) ?></p>
    <?php endif; ?>
    <div class="login-form">
      <a class="btn-primary" href="/portal/login.php">Continue with 8 West ID</a>
    </div>
  </section>
  <p class="login-foot">This portal shows ticket summaries only. It cannot send replies, control devices, view attachments, or access billing.</p>
</main>
    <?php
    portal_page_end();
}

function portal_render_error(int $status, string $title, string $message): void
{
    http_response_code($status);
    portal_page_start($title, 'login-body');
    ?>
<main class="login-wrap">
  <header class="login-head">
    <img class="login-mark" src="/assets/brand/favicon.svg" alt="">
    <h1 class="login-name">Safeharbor</h1>
  </header>
  <section class="card login-card">
    <h2 class="page-title"><?= portal_h($title) ?></h2>
    <p class="page-sub" role="alert"><?= portal_h($message) ?></p>
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
        'waiting' => 'Waiting',
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

/**
 * @param array{identity:array<string,mixed>,binding:array<string,mixed>} $context
 * @param array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} $summary
 */
function portal_render_dashboard(array $context, array $summary): void
{
    $identity = $context['identity'];
    $client = $summary['client'];
    $counts = $summary['counts'];
    portal_page_start('Ticket summaries');
    ?>
<main class="page">
  <header class="page-head">
    <div>
      <p class="page-sub">Safeharbor customer portal</p>
      <h1 class="page-title"><?= portal_h($client['name']) ?></h1>
      <p class="page-sub">Signed in as <?= portal_h($identity['display_name']) ?> via 8 West ID</p>
    </div>
    <form method="post" action="/portal/logout.php">
      <input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>">
      <button type="submit" class="btn-ghost">Sign out</button>
    </form>
  </header>

  <section class="stats" aria-label="Ticket counts">
    <?php foreach ([
        'open' => ['Open', 'stat-warn'],
        'in_progress' => ['In progress', 'stat-good'],
        'waiting' => ['Waiting', 'stat-dim'],
        'resolved' => ['Resolved', 'stat-good'],
    ] as $status => [$label, $class]): ?>
      <article class="card stat">
        <div class="stat-k"><?= portal_h($label) ?></div>
        <div class="stat-v <?= portal_h($class) ?>"><?= (int)($counts[$status] ?? 0) ?></div>
      </article>
    <?php endforeach; ?>
  </section>

  <section aria-labelledby="portal-ticket-heading">
    <div class="page-head">
      <div>
        <h2 id="portal-ticket-heading" class="page-title">Recent tickets</h2>
        <p class="page-sub">Read-only summaries; newest activity first</p>
      </div>
    </div>
    <div class="card rail-list">
      <?php if ($summary['tickets'] === []): ?>
        <div class="empty">No tickets are available for this business.</div>
      <?php else: ?>
        <?php foreach ($summary['tickets'] as $ticket): ?>
          <article class="rail-list-item">
            <h3 class="rail-list-name">#<?= (int)$ticket['id'] ?> · <?= portal_h($ticket['subject']) ?></h3>
            <p class="rail-list-sub">
              <?= portal_h(portal_status_label((string)$ticket['status'])) ?>
              · <?= portal_h(ucfirst((string)$ticket['priority'])) ?> priority
              · Updated <?= portal_h(portal_relative_time((string)$ticket['updated_at'])) ?>
            </p>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <p class="page-note">Up to 50 recent ticket summaries are shown. Messages, internal notes, attachments, technician time, billing, AI, and endpoint controls are not available here.</p>
  </section>
</main>
    <?php
    portal_page_end();
}
