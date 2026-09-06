<?php
/**
 * Layout + shared UI partials. Pages call page_top() … page_bottom().
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/westy.php';

const STATUS_META = [
    'open'        => ['Open',        'blue'],
    'in_progress' => ['In Progress', 'cyan'],
    'waiting'     => ['Waiting',     'gold'],
    'resolved'    => ['Resolved',    'mint'],
];
const PRIORITY_META = [
    'low'    => ['Low',    1, 'muted'],
    'normal' => ['Normal', 2, 'blue'],
    'high'   => ['High',   3, 'gold'],
    'urgent' => ['Urgent', 4, 'rose'],
];

function status_chip(string $status): string
{
    [$label, $tone] = STATUS_META[$status];
    return '<span class="chip"><span class="chip-dot dot-' . $tone . '"></span>'
         . '<span class="chip-text-' . $tone . '">' . h($label) . '</span></span>';
}

function priority_glyph(string $priority, bool $withLabel = false): string
{
    [$label, $bars, $tone] = PRIORITY_META[$priority];
    $out = '<span class="pri" title="Priority: ' . h($label) . '"><span class="pri-bars">';
    for ($i = 1; $i <= 4; $i++) {
        $cls = $i <= $bars ? 'bar-' . $tone : 'bar-off';
        $out .= '<span class="pri-bar ' . $cls . '" style="height:' . (2 + $i * 3) . 'px"></span>';
    }
    $out .= '</span>';
    if ($withLabel) $out .= '<span class="pri-label">' . h($label) . '</span>';
    return $out . '</span>';
}

function sla_lamp(array $ticket): string
{
    $sla = sla_info($ticket);
    $pulse = in_array($sla['state'], ['at_risk', 'breached'], true) ? ' pulse' : '';
    return '<span class="sla sla-' . $sla['state'] . '">'
         . '<span class="sla-dot' . $pulse . '"></span>' . h($sla['label']) . '</span>';
}

function avatar(?array $user, int $size = 26): string
{
    if (!$user) {
        return '<span class="avatar avatar-none" style="width:' . $size . 'px;height:' . $size . 'px" title="Unassigned">—</span>';
    }
    // 8 West ID avatar: the central profile picture follows the user across suite apps.
    if (!empty($_SESSION['suite_avatar'])
        && (string)($user['suite_subject'] ?? '') !== ''
        && hash_equals((string)($user['suite_subject'] ?? ''), (string)($_SESSION['suite_avatar_subject'] ?? ''))) {
        return '<img class="avatar avatar-img" style="width:' . $size . 'px;height:' . $size . 'px" src="' . h($_SESSION['suite_avatar']) . '" alt="" title="' . h($user['full_name']) . '">';
    }
    return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:' . h($user['color']) . '" title="' . h($user['full_name']) . '">'
         . h($user['initials']) . '</span>';
}

/** One queue row (also used on the client page). */
function ticket_row(array $t, bool $linkWrap = true): string
{
    $url = '/ticket.php?id=' . (int)$t['id'];
    return '<a href="' . $url . '" class="trow" data-ticket-id="' . (int)$t['id'] . '" data-status="' . h($t['status']) . '" data-priority="' . h($t['priority']) . '" role="row">'
        . '<span class="trow-pri">' . priority_glyph($t['priority']) . '</span>'
        . '<span class="trow-num">#' . (int)$t['id'] . '</span>'
        . '<span class="trow-main"><span class="trow-subject">' . h($t['subject']) . '</span>'
        . '<span class="trow-sub">' . h($t['client_name']) . ' · ' . h($t['channel']) . '</span></span>'
        . '<span class="trow-status">' . status_chip($t['status']) . '</span>'
        . '<span class="trow-tech">' . avatar($t['assignee_id'] ? ['full_name' => $t['assignee_name'], 'initials' => $t['assignee_initials'], 'color' => $t['assignee_color']] : null) . '</span>'
        . '<span class="trow-sla">' . sla_lamp($t) . '</span>'
        . '<span class="trow-age" title="Opened">' . rel_time($t['created_at']) . '</span>'
        . '</a>';
}

/** Full chrome: head + sidebar + topbar. */
function page_top(array $user, string $title, string $active): void
{
    $nav = [
        ['/',          'Queue',   'queue',   'G Q', '<path d="M2 4h12M2 8h12M2 12h7" stroke-linecap="round"/>'],
        ['/time.php',  'Time',    'time',    'G T', '<circle cx="8" cy="8" r="6"/><path d="M8 4.5V8l2.5 2" stroke-linecap="round"/>'],
        ['/clients.php', 'Clients', 'clients', 'G C', '<circle cx="5.5" cy="6" r="2.5"/><circle cx="11" cy="7" r="2"/><path d="M1.5 13.5c.6-2.3 2.2-3.5 4-3.5s3.4 1.2 4 3.5M9.5 12.6c.7-1.4 1.9-2.1 3-2.1 1.3 0 2.4.9 2.9 2.6" stroke-linecap="round"/>'],
        ['/service_goals.php', 'Service goals', 'service-goals', 'G S', '<circle cx="8" cy="8" r="5.5"/><circle cx="8" cy="8" r="2"/><path d="M8 1.5V4M8 12v2.5M1.5 8H4M12 8h2.5" stroke-linecap="round"/>'],
        ['/reports.php', 'Reports', 'reports', '', '<path d="M2.5 13.5h11M4 13V8.5M8 13V4.5M12 13V6.5" stroke-linecap="round"/>'],
        ['/users.php', 'Team',    'team',    '', '<circle cx="8" cy="5.5" r="2.5"/><path d="M3 13.5c.8-2.6 2.8-4 5-4s4.2 1.4 5 4M11.5 5.8a2 2 0 1 1 .01 0M11.6 9.6c1.6.4 2.8 1.6 3.3 3.4" stroke-linecap="round"/>'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    ?>
<?php $suitePreferences = is_array($_SESSION['suite_preferences'] ?? null)
    ? $_SESSION['suite_preferences'] : suite_preferences_defaults(); ?>
<!doctype html>
<html lang="<?= h((string)$suitePreferences['locale']) ?>"
      data-preferences-source="8west-id"
      data-density="<?= h((string)$suitePreferences['density']) ?>"
      data-contrast="<?= h((string)$suitePreferences['contrast']) ?>"
      data-motion="<?= h((string)$suitePreferences['motion']) ?>"
      data-time-zone="<?= h((string)$suitePreferences['time_zone']) ?>"
      data-date-format="<?= h((string)$suitePreferences['date_format']) ?>"
      data-time-format="<?= h((string)$suitePreferences['time_format']) ?>"
      data-default-product="<?= h((string)($suitePreferences['default_product'] ?? '')) ?>"
      data-westy-detail="<?= h((string)$suitePreferences['westy_detail']) ?>"
      data-notifications-enabled="<?= $suitePreferences['notifications_enabled'] ? 'true' : 'false' ?>"
      data-quiet-hours-start="<?= h((string)($suitePreferences['quiet_hours_start'] ?? '')) ?>"
      data-quiet-hours-end="<?= h((string)($suitePreferences['quiet_hours_end'] ?? '')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · Safeharbor</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/favicon.svg">
<link rel="icon" type="image/x-icon" href="/assets/brand/favicon.ico" sizes="16x16 32x32 48x48">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<meta name="theme-color" content="#061936">
<script>
document.documentElement.dataset.theme=localStorage.getItem("safeharbor.theme")||"system";
<?php if (!empty($_SESSION['suite_theme'])): ?>
(function(){var t=<?= json_encode($_SESSION['suite_theme']) ?>;localStorage.setItem("safeharbor.theme",t);document.documentElement.dataset.theme=t;})();
<?php endif; ?>
</script>
<link rel="stylesheet" href="/assets/css/app.css?v=5">
</head>
<body data-active="<?= h($active) ?>" data-csrf="<?= csrf_token() ?>"
      data-tenant-id="<?= (int)$user['tenant_id'] ?>" data-user-id="<?= (int)$user['id'] ?>">
<div class="shell">
  <aside class="sidebar" id="suite-sidebar">
    <button type="button" class="mobile-nav-close" aria-label="Close navigation">Close menu ×</button>
    <a class="brand" href="/">
      <img src="/assets/brand/favicon.svg" alt="Safeharbor" class="brand-mark">
      <span class="brand-text">
        <span class="brand-name">Safeharbor</span>
        <span class="brand-sub">by 8 West IT, LLC</span>
      </span>
    </a>
    <nav class="nav">
      <?php foreach ($nav as [$href, $label, $key, $kbd, $icon]): ?>
      <a href="<?= $href ?>" class="nav-item<?= $active === $key ? ' nav-on' : '' ?>">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><?= $icon ?></svg>
        <span class="nav-label"><?= h($label) ?></span>
        <kbd class="kbd nav-kbd"><?= $kbd ?></kbd>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="suite-label">8 West Suite</div>
    <div class="suite">
      <?php foreach (['Milepost' => 'https://support.8westit.com/', 'Coastmark' => 'https://coastmark.8westit.com/auth/suite', 'All apps' => 'https://id.8westit.com/'] as $name => $url): ?>
      <a class="suite-item suite-item-live" href="<?= h($url) ?>" target="_blank" rel="noopener">
        <span class="suite-box"></span><span class="suite-name"><?= h($name) ?></span><span class="suite-tag" aria-hidden="true">↗</span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="usermenu-wrap">
      <div class="usermenu" id="usermenu" role="menu" hidden>
        <div class="um-head">
          <?= avatar($user, 34) ?>
          <span class="um-head-text">
            <span class="um-name"><?= h($user['full_name']) ?></span>
            <span class="um-email"><?= h($user['email']) ?></span>
          </span>
          <span class="role-badge role-<?= h($user['role']) ?>"><?= h($user['role']) ?></span>
        </div>
        <div class="um-label">Suite preferences</div>
        <a class="um-item" href="https://id.8westit.com/settings.php" role="menuitem">Manage global settings</a>
        <div class="um-sep"></div>
        <a class="um-item" href="/profile.php" role="menuitem">My profile</a>
        <a class="um-item" href="/users.php" role="menuitem">Team</a>
        <a class="um-item um-danger" href="/logout.php" role="menuitem">Sign out</a>
      </div>
      <button type="button" class="sidebar-foot usermenu-btn" id="usermenu-btn" aria-haspopup="true" aria-expanded="false">
        <?= avatar($user, 30) ?>
        <span class="foot-text">
          <span class="foot-name"><?= h($user['full_name']) ?></span>
          <span class="foot-tenant">8 West IT, LLC</span>
        </span>
        <svg class="foot-chevron" width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="m4 10 4-4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </button>
    </div>
  </aside>
  <button type="button" class="mobile-nav-backdrop" aria-label="Close navigation" tabindex="-1"></button>
  <div class="main">
    <header class="topbar">
      <button type="button" id="mobile-nav-toggle" class="mobile-nav-toggle" aria-controls="suite-sidebar" aria-expanded="false">☰ Menu</button>
      <button id="palette-trigger" class="search-trigger" type="button">
        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="7" cy="7" r="4.5"/><path d="m10.5 10.5 3 3" stroke-linecap="round"/></svg>
        <span class="search-label">Search or command…</span>
        <kbd class="kbd">⌘K</kbd>
      </button>
      <span class="topbar-flex"></span>
      <a href="/time.php" id="timer-widget" class="timer-widget timer-idle" title="Time tracking">No timer running</a>
    </header>
    <main class="content">
    <?php
}

/** Close chrome + inject palette data + JS. */
function page_bottom(array $paletteData = []): void
{
    ?>
    </main>
  </div>
</div>
<div id="palette-root"></div>
<div id="toasts" class="toasts"></div>
<?php westy_bubble_render(); ?>
<script id="palette-data" type="application/json"><?= json_encode($paletteData, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/js/app.js?v=3" defer></script>
</body>
</html>
    <?php
}

/** Palette data island: all tickets + clients for ⌘K fuzzy search. */
function palette_data(): array
{
    $tickets = db()->prepare(
        'SELECT t.id, t.subject, t.priority, c.name AS client_name
           FROM tickets t JOIN clients c ON c.id = t.client_id
          WHERE t.tenant_id = ? AND t.status != "resolved"
          ORDER BY t.updated_at DESC LIMIT 60'
    );
    $tickets->execute([tenant_id()]);
    $clients = db()->prepare('SELECT id, name, domain FROM clients WHERE tenant_id = ? ORDER BY name');
    $clients->execute([tenant_id()]);
    return [
        'tickets' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'subject' => $r['subject'],
            'priority' => $r['priority'], 'client' => $r['client_name'],
        ], $tickets->fetchAll()),
        'clients' => array_map(static fn($r) => [
            'id' => (int)$r['id'], 'name' => $r['name'], 'domain' => $r['domain'],
        ], $clients->fetchAll()),
    ];
}
