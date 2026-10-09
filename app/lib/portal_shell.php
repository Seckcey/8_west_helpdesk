<?php
declare(strict_types=1);
require_once __DIR__.'/portal_access.php';
require_once __DIR__.'/portal_desktop_controls.php';

/** Presentation only: this never creates or validates authentication authority. */
function portal_branding(): array
{
    $pair = $_SESSION['desktop_companion_session'] ?? null;
    $companion = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH) === '/portal/desktop.php'
        || (is_string($pair) && preg_match('/\A[a-f0-9]{32}\z/D', $pair) === 1);
    return $companion
        ? ['name'=>'Westy Companion', 'home'=>'Westy home', 'alt'=>'Westy Companion — 8 West IT',
            'logo'=>'/assets/brand/westy-companion/westy-horizontal-logo-v1.png', 'width'=>2172, 'height'=>724,
            'favicon'=>'/assets/brand/westy-companion/westy-1.png', 'favicon_type'=>'image/png',
            'avatar'=>'/assets/brand/westy-companion/westy-1.png']
        : ['name'=>'Safeharbor', 'home'=>'Safeharbor home', 'alt'=>'Safeharbor — 8 West IT 365',
            'logo'=>'/assets/brand/safeharbor-logo-horizontal-transparent-20260909.png', 'width'=>1851, 'height'=>513,
            'favicon'=>'/assets/brand/favicon.svg', 'favicon_type'=>'image/svg+xml',
            'avatar'=>'/assets/img/westy-avatar.png'];
}

/** File contents, not mtime or a hand-maintained number, invalidate cached assets. */
function portal_asset_version(string $path):string
{
    $hash=hash_file('sha256',$path);
    if($hash===false)throw new RuntimeException('Portal asset unavailable');
    return substr($hash,0,20);
}

function portal_icon(string $name): string
{
    $paths = [
        'home' => '<path d="m3 10 9-7 9 7v10H3z"/><path d="M9 20v-7h6v7"/>',
        'device' => '<rect x="3" y="3" width="18" height="13" rx="2"/><path d="M8 21h8M12 16v5"/>',
        'requests' => '<path d="M5 3h11l3 3v15H5z"/><path d="M9 9h6M9 13h6M9 17h4"/>',
        'guide' => '<path d="M12 5v16M3 3c4 0 6 0 9 2 3-2 5-2 9-2v16c-4 0-6 0-9 2-3-2-5-2-9-2z"/>',
        'chat' => '<path d="M21 11a8 8 0 0 1-8 8H7l-4 3v-7a8 8 0 1 1 18-4Z"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'edit' => '<path d="m15 4 5 5M3 21l5-1L21 7l-5-5L3 15z"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? $paths['requests']) . '</svg>';
}

function portal_shell_start(array $context): void
{
    $identity = $context['identity'];
    $client = (string)($context['binding']['client_name'] ?? 'Your business');
    $workspace=($context['workspace']??false)===true;
    $brand = portal_branding();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    if(in_array($path, ['/portal/device_help.php', '/portal/mobile.php', '/portal/security.php'], true))$path='/portal/devices.php';
    ?>
<a class="portal-skip" href="#portal-content">Skip to content</a>
<header class="portal-top"><a href="/portal/" aria-label="<?= portal_h($brand['home']) ?>"><img src="<?= portal_h($brand['logo']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>" alt="<?= portal_h($brand['alt']) ?>"></a><span>8 West IT</span><button type="button" class="portal-icon-button portal-menu" aria-label="Toggle navigation" aria-expanded="false" aria-controls="portal-nav"><?= portal_icon('menu') ?></button><?php if($workspace): ?><div class="portal-workspace-title"><strong>Westy</strong><span>Private conversation</span></div><button type="button" class="portal-icon-button portal-mobile-new" data-chat-new aria-label="New chat"><?= portal_icon('edit') ?></button><?php endif; ?></header>
<aside class="portal-sidebar" id="portal-nav"><?php if($workspace): ?><a class="portal-workspace-brand" href="/portal/" aria-label="<?= portal_h($brand['home']) ?>"><img src="<?= portal_h($brand['logo']) ?>" width="<?= $brand['width'] ?>" height="<?= $brand['height'] ?>" alt="<?= portal_h($brand['alt']) ?>"></a><button class="portal-new-chat" type="button" data-chat-new><?= portal_icon('edit') ?>New chat</button><?php else: ?><p class="portal-business"><?= portal_h($client) ?></p><?php endif; ?>
<nav aria-label="Customer portal">
<?php foreach ([['/portal/', 'Westy', 'chat'], ['/portal/devices.php', 'Your devices', 'device'], ['/portal/requests.php', 'Support requests', 'requests'], ['/portal/reports.php', 'Service summaries', 'requests']] as [$url, $label, $icon]): ?>
<a href="<?= portal_h($url) ?>"<?= $path === $url ? ' aria-current="page"' : '' ?>><?= portal_icon($icon) ?><span><?= portal_h($label) ?></span></a>
<?php endforeach; ?>
</nav><?php if($workspace): ?><section class="portal-chat-history" aria-label="Recent chats"><h2>Recent chats</h2><div id="portal-chat-history"></div></section><?php endif; ?><div class="portal-account"><strong><?= portal_h($identity['display_name']) ?></strong><span><?= portal_h(portal_role_label(portal_customer_role($identity))) ?></span><?php if(isset($identity['customer_access']) && !isset($_SESSION['desktop_companion_session'])): ?><a href="/portal/clients.php">Use Westy for another client</a><?php endif; ?><a href="/portal/guide.php">Portal guide</a><a href="/portal/guide.php#contact">Contact support</a><form method="post" action="/portal/logout.php"><input type="hidden" name="csrf" value="<?= portal_h(portal_csrf_token()) ?>"><button type="submit" class="btn-link">Sign out</button></form></div></aside>
<div class="portal-content" id="portal-content" tabindex="-1">
<?php
}

/** One chat trigger; device pages place it in their normal header flow. */
function portal_westy_button(): void
{
    ?>
<button id="portal-chat-bubble" type="button" aria-expanded="false" aria-controls="portal-chat-panel"><img src="<?= portal_h(portal_branding()['avatar']) ?>" alt="" width="36" height="36"><strong>Westy</strong><span>Continue chat</span><?= portal_icon('arrow') ?></button>
    <?php
}

function portal_westy_widget(array $context): void
{
    $canWrite = portal_role_can_write_tickets(portal_customer_role($context['identity']));
    $brand = portal_branding();
    ?>
<div id="portal-chat-root" data-csrf="<?= portal_h(portal_csrf_token()) ?>" data-workspace="<?= !empty($context['workspace'])?'1':'0' ?>" data-can-write="<?= $canWrite ? '1' : '0' ?>" data-avatar="<?= portal_h($brand['avatar']) ?>">
<?php if (empty($context['workspace']) && ($context['chat_button_placement'] ?? '') !== 'inline') portal_westy_button(); ?>
<section id="portal-chat-panel" class="portal-westy" aria-label="Private conversation with Westy" hidden>
<div class="portal-chat-head"><div><strong>Westy</strong><span>Private conversation</span></div><button type="button" class="btn-link" id="portal-chat-new">New chat</button><button type="button" class="portal-icon-button" id="portal-chat-close" aria-label="Close Westy"><?= portal_icon('close') ?></button></div>
<div id="portal-chat-messages" role="log" aria-label="Conversation" aria-live="off"><div id="portal-chat-empty"><img src="<?= portal_h($brand['avatar']) ?>" width="64" height="64" alt=""><h1>What can I help you with?</h1><p>Ask about your computer. We’ll work through it together.</p></div></div>
<button type="button" class="portal-jump" id="portal-chat-jump" hidden>Jump to latest ↓</button>
<div id="portal-chat-draft" hidden></div><p id="portal-chat-status" role="status" aria-live="polite"></p>
<form id="portal-chat-form" class="portal-composer" method="post" action="/portal/westy.php"><label class="sr-only" for="portal-chat-input">Ask Westy about your computer</label><textarea id="portal-chat-input" disabled name="message" rows="2" maxlength="2000" placeholder="Ask Westy about your computer…" required></textarea><div class="portal-composer-foot"><label class="portal-device-choice" for="portal-chat-device"><?= portal_icon('device') ?><span class="sr-only">Computer for this chat</span><select id="portal-chat-device"><option value="">Choose a computer</option></select></label><span class="portal-keyboard-hint">Enter to send · Shift+Enter for a new line</span><button type="button" class="portal-icon-button" id="portal-chat-stop" aria-label="Stop reply" hidden>■</button><button type="submit" class="btn-primary" id="portal-chat-send" disabled aria-label="Send message"><?= portal_icon('arrow') ?></button></div></form>
<?php portal_desktop_controls(); ?>
<div class="portal-chat-foot"><a href="/portal/guide.php#privacy">Private to you</a><span>·</span><?php if ($canWrite): ?><a href="/portal/new.php">Contact support</a><?php else: ?><span>Viewer access · requests are read-only</span><?php endif; ?></div>
</section></div>
<script src="/assets/js/portal-computer-time.js?v=<?= portal_asset_version(__DIR__.'/../public/assets/js/portal-computer-time.js') ?>" defer></script>
<script src="/assets/js/portal-westy.js?v=<?= portal_asset_version(__DIR__.'/../public/assets/js/portal-westy.js') ?>" defer></script>
<?php
}
