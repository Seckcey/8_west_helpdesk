<?php
/**
 * First steps for a new workspace: the "Get set up" card on an empty staff queue.
 * Presentation only: it reads a client count and the session's 8 West ID panels, and links to pages
 * that already exist. It grants nothing and changes nothing.
 */
declare(strict_types=1);

/** The 8 West ID invite: owner or administrator whose session grants people management in MSP Admin. */
function staff_invite_allowed(array $user, array $session): bool
{
    return in_array($user['role'] ?? '', ['owner', 'admin'], true)
        && ($session['suite_settings_context']['capabilities']['manage_people'] ?? false) === true
        && in_array('msp_admin', $session['suite_settings_context']['panels'] ?? [], true);
}

const STAFF_INVITE_URL = 'https://id.8westit.com/users.php';

/** How many clients this tenant has. One count, never a second full client read. */
function tenant_client_count(PDO $db, int $tenantId): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM clients WHERE tenant_id = ?');
    $stmt->execute([$tenantId]);
    return (int)$stmt->fetchColumn();
}

/** The check drawn in a finished step's marker. */
function setup_step_check(): string
{
    return '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="m3.5 8.5 3 3 6-7" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}

/**
 * One route list. Each step: [title, sentence, done, button label or null, href or null].
 * The first unfinished step is the current one and gets the primary button.
 * $escape is the page's own escaping function (h() for staff, portal_h() for the portal).
 */
function setup_steps_html(string $headingId, array $steps, callable $escape): string
{
    $current = null;
    foreach ($steps as $i => $step) if (!$step[2]) { $current = $i; break; }
    $out = '<section class="card setup-card" aria-labelledby="' . $escape($headingId) . '">'
        . '<h2 class="setup-title" id="' . $escape($headingId) . '">Get set up</h2><ol class="setup-steps">';
    foreach ($steps as $i => [$title, $text, $done, $label, $href]) {
        $state = $done ? 'is-done' : ($i === $current ? 'is-current' : 'is-later');
        $out .= '<li class="setup-step ' . $state . '">'
            . '<span class="setup-mark" aria-hidden="true">' . ($done ? setup_step_check() : (string)($i + 1)) . '</span>'
            . '<div class="setup-text"><strong>' . $escape($title) . '</strong>'
            . ($done ? '<span class="sr-only"> (done)</span>' : '')
            . ($text !== '' ? '<p>' . $escape($text) . '</p>' : '') . '</div>';
        if (!$done && $label !== null && $href !== null) {
            $out .= '<a class="' . ($i === $current ? 'btn-primary' : 'btn-ghost setup-secondary') . '" href="' . $escape($href) . '">' . $escape($label) . '</a>';
        }
        $out .= '</li>';
    }
    return $out . '</ol></section>';
}

/**
 * The staff queue card: only for an owner or administrator in a tenant with no tickets at all.
 * Everyone else, and every tenant that already has tickets, sees exactly what they saw before.
 */
function staff_first_steps_card(array $user, int $ticketCount, int $clientCount, bool $canInvite): string
{
    if ($ticketCount !== 0 || !in_array($user['role'] ?? '', ['owner', 'admin'], true)) return '';
    return setup_steps_html('queue-setup-heading', [
        ['Add your first client', 'Tickets belong to a client. Clients you add in Milepost appear here by themselves.',
            $clientCount > 0, 'Add a client', '/client_new.php'],
        ['Invite your team', 'Each person signs in with their own 8 West ID account.',
            false, $canInvite ? 'Invite a teammate' : 'Open Team', $canInvite ? STAFF_INVITE_URL : '/users.php'],
        ['File a test ticket', 'See how a request moves from Open to Resolved.',
            false, 'New ticket', '/ticket_new.php'],
    ], 'h');
}
