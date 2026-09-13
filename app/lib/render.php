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

/** Westy is a service owner, never a fabricated human account. */
function ticket_owner_avatar(array $ticket, int $size = 26): string
{
    if (($ticket['westy_owned'] ?? false) === true && ($ticket['status'] ?? '') === 'in_progress' && empty($ticket['assignee_id'])) {
        $size = max(16, min(64, $size));
        return '<span class="avatar" style="width:' . $size . 'px;height:' . $size . 'px;background:var(--gold);color:var(--bg)" title="Westy" aria-label="Assigned to Westy">W</span>';
    }
    return avatar(!empty($ticket['assignee_id']) ? ['full_name'=>$ticket['assignee_name'], 'initials'=>$ticket['assignee_initials'], 'color'=>$ticket['assignee_color']] : null, $size);
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
        . '<span class="trow-tech">' . ticket_owner_avatar($t) . '</span>'
        . '<span class="trow-sla">' . sla_lamp($t) . '</span>'
        . '<span class="trow-age" title="Opened">' . rel_time($t['created_at']) . '</span>'
        . '</a>';
}

/**
 * The 8 West IT 365 app drawer — the 3x3 squares, in the shared w365 markup that every suite app
 * renders in the same place (the right end of the page header, before the account menu).
 *
 * Always rendered for a signed-in technician, but its CONTENTS are only ever what Safeharbor
 * honestly knows. The three states come from suite_apps_drawer(); there is no fourth state that
 * guesses. It replaced a hard-coded sidebar list of two sister apps plus "All apps", which
 * advertised Milepost and Coastmark to people who may not be licensed for either.
 *
 * Tiles carry the product's SAME-ORIGIN mark from the vendored package, so no page load reaches
 * another host for artwork; the shared launcher swaps in the product's initial if a mark ever
 * fails to load. Presentation only — read-only links, no new permission, nothing executable.
 */
function suite_chrome_drawer(): void
{
    require_once __DIR__ . '/suite_apps.php';

    // The presence of the entitlement list is what makes this a suite session: auth.php writes it
    // from the VERIFIED token on every refresh (including as []) and removes it the moment the
    // token is gone, so a local password login never has one.
    $hasSuite = array_key_exists('suite_products', $_SESSION ?? []);
    $drawer = suite_apps_drawer($hasSuite, $_SESSION['suite_products'] ?? null, suite_app_registry());
    ?>
    <div class="w365-anchor" data-w365-drawer>
      <button type="button" class="w365-waffle" data-w365-drawer-button
              aria-label="Open 8 West IT 365 apps" aria-haspopup="true" aria-expanded="false"
              aria-controls="w365-app-drawer" title="8 West IT 365 apps">
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><g fill="currentColor">
          <rect x="2" y="2" width="5" height="5" rx="1"/><rect x="9.5" y="2" width="5" height="5" rx="1"/><rect x="17" y="2" width="5" height="5" rx="1"/>
          <rect x="2" y="9.5" width="5" height="5" rx="1"/><rect x="9.5" y="9.5" width="5" height="5" rx="1"/><rect x="17" y="9.5" width="5" height="5" rx="1"/>
          <rect x="2" y="17" width="5" height="5" rx="1"/><rect x="9.5" y="17" width="5" height="5" rx="1"/><rect x="17" y="17" width="5" height="5" rx="1"/>
        </g></svg>
      </button>
      <div class="w365-drawer" id="w365-app-drawer" data-w365-drawer-panel hidden>
        <div class="w365-drawer-head"><strong>8 West IT 365</strong><span>Your apps</span></div>
        <?php if ($drawer['state'] === 'apps'): ?>
          <nav class="w365-drawer-grid" aria-label="8 West IT 365 apps">
            <?php foreach ($drawer['apps'] as $app): ?>
              <a class="w365-tile" href="<?= h($app['url']) ?>" target="_blank" rel="noopener noreferrer"
                 title="<?= h($app['desc']) ?>">
                <span class="w365-tile-mark"><?php if (($app['mark'] ?? '') !== ''): ?><img src="/assets/w365/marks/<?= h($app['mark']) ?>?v=20260913a" alt="" width="48" height="48" loading="lazy" data-initial="<?= h(suite_app_initial($app['name'])) ?>"><?php else: ?><?= h(suite_app_initial($app['name'])) ?><?php endif; ?></span>
                <span class="w365-tile-name"><?= h($app['name']) ?></span>
              </a>
            <?php endforeach; ?>
          </nav>
        <?php elseif ($drawer['state'] === 'none'): ?>
          <p class="w365-drawer-note">No other 8 West IT 365 apps are licensed for your account.</p>
        <?php else: ?>
          <p class="w365-drawer-note">Sign in with 8 West ID to see the apps licensed for your account.</p>
          <a class="w365-drawer-link" href="https://id.8westit.com/" target="_blank" rel="noopener noreferrer">Go to 8 West ID</a>
        <?php endif; ?>
        <?php if ($drawer['state'] !== 'no_suite'): ?>
          <div class="w365-drawer-foot"><a href="https://id.8westit.com/?apps=1" target="_blank" rel="noopener noreferrer">8 West IT 365 home</a></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

/**
 * The account menu beside the app drawer (the shared w365 placement contract): who you are, Suite
 * settings when a suite session exists, Safeharbor's own profile page, and Sign out. It replaces
 * the identity block and the Sign out item that used to sit at the bottom of the sidebar, so
 * identity lives in the same place in every 8 West IT 365 app: top right, after the squares.
 *
 * WHO. With a suite session the menu shows the 8 West ID identity — the token's `name`, `email`
 * and the `8west:avatar` picture — so a person sees the same name and picture in every suite app.
 * A local Safeharbor password login shows the Safeharbor account name and a letter mark.
 *
 * THE PICTURE. Only the person's OWN picture, and only from 8 West ID: the avatar is rendered when
 * the session's avatar subject matches this user's `suite_subject` (the same subject binding
 * avatar() uses, so one technician's photo can never appear on another's account) AND the URL is
 * on https://id.8westit.com/ or same-origin. Anything else falls back to the initials circle.
 */
function suite_chrome_account_menu(array $user): void
{
    require_once __DIR__ . '/suite_apps.php';

    $hasSuite = array_key_exists('suite_products', $_SESSION ?? []);
    $suiteName = $hasSuite ? trim((string)($_SESSION['suite_name'] ?? '')) : '';
    $suiteEmail = $hasSuite ? trim((string)($_SESSION['suite_email'] ?? '')) : '';
    $name = $suiteName !== '' ? $suiteName : trim((string)($user['full_name'] ?? ''));
    $secondary = $suiteEmail !== '' ? $suiteEmail : (string)($user['email'] ?? '');
    $role = (string)($user['role'] ?? '');
    $roleLabel = ['owner' => 'Owner', 'admin' => 'Administrator', 'tech' => 'Technician'][$role]
        ?? ($role === '' ? '' : ucfirst($role));
    $initials = suite_name_initials($name);

    $avatar = (string)($_SESSION['suite_avatar'] ?? '');
    $subject = (string)($user['suite_subject'] ?? '');
    $avatarUsable = $avatar !== '' && $subject !== ''
        && hash_equals($subject, (string)($_SESSION['suite_avatar_subject'] ?? ''))
        && (str_starts_with($avatar, 'https://id.8westit.com/')
            || preg_match('~^(?![a-z][a-z0-9+.-]*:)(?!//)~i', $avatar) === 1);
    ?>
    <div class="w365-anchor" data-w365-account>
      <button type="button" class="w365-account-btn" data-w365-account-button aria-haspopup="menu" aria-expanded="false"
              aria-controls="w365-account-menu" aria-label="Account menu for <?= h($name) ?>">
        <?php if ($avatarUsable): ?><img class="w365-avatar" src="<?= h($avatar) ?>" alt="" width="32" height="32" referrerpolicy="no-referrer"><?php else: ?><span class="w365-avatar" aria-hidden="true"><?= h($initials) ?></span><?php endif; ?>
        <span class="w365-account-name"><?= h($name) ?></span>
        <svg viewBox="0 0 10 10" aria-hidden="true" focusable="false"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
      </button>
      <div class="w365-menu" id="w365-account-menu" data-w365-account-menu role="menu" hidden>
        <div class="w365-menu-who"><strong><?= h($name) ?></strong><span><?= h($secondary) ?><?= $roleLabel !== '' ? ' · ' . h($roleLabel) : '' ?></span></div>
        <?php if ($hasSuite): ?>
          <a class="w365-menu-item" role="menuitem" href="https://id.8westit.com/settings.php" target="_blank" rel="noopener noreferrer">Suite settings</a>
        <?php endif; ?>
        <a class="w365-menu-item" role="menuitem" href="/profile.php">Settings</a>
        <hr class="w365-menu-sep">
        <a class="w365-menu-item" role="menuitem" href="/logout.php">Sign out</a>
      </div>
    </div>
    <?php
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
<?php /* The shared 8 West IT 365 suite chrome (w365), vendored under /assets/w365/ and served from
         THIS origin — nothing is hot-loaded from another host, so no Content-Security-Policy
         directive changes. It loads BEFORE app.css so Safeharbor's own stylesheet always wins, and
         the --w365-* hooks it reads are mapped to Safeharbor's semantic tokens there, which is how
         the two panels follow the dark / light / system themes without the package shipping a
         palette of its own. */ ?>
<link rel="stylesheet" href="/assets/w365/w365.css?v=20260913a">
<link rel="stylesheet" href="/assets/css/app.css?v=20260913a">
</head>
<body data-active="<?= h($active) ?>" data-csrf="<?= csrf_token() ?>"
      data-tenant-id="<?= (int)$user['tenant_id'] ?>" data-user-id="<?= (int)$user['id'] ?>">
<div class="shell">
  <aside class="sidebar" id="suite-sidebar">
    <button type="button" class="mobile-nav-close" aria-label="Close navigation">Close menu ×</button>
    <a class="brand" href="/" aria-label="Safeharbor home">
      <img src="/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png" alt="Safeharbor — 8 West IT 365" class="suite-brand-logo" width="1851" height="513">
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
    <?php /* The hard-coded "8 West Suite" list (Milepost, Coastmark, All apps) and the lower-left
             identity menu used to sit here. Both moved to the shared cluster at the right end of the
             topbar below: the drawer now lists the apps this person is actually licensed for, and
             the account menu is the single home for identity, suite settings and Sign out. */ ?>
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
      <?php /* The shared suite cluster (w365): Safeharbor's own controls, then the app drawer, then
               the account menu — the same place, in the same order, in every 8 West IT 365 app. */ ?>
      <div class="w365-cluster">
        <?php suite_chrome_drawer(); ?>
        <?php suite_chrome_account_menu($user); ?>
      </div>
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
<?php /* The shared launcher opens and closes the two panels. Vendored, same-origin, deferred; it
         injects no markup, fetches nothing and reads no storage. */ ?>
<script src="/assets/w365/w365-launcher.js?v=20260913a" defer></script>
<script src="/assets/js/app.js?v=20260913a" defer></script>
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
