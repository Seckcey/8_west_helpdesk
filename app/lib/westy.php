<?php
/**
 * Westy — the 8 West IT suite assistant, in his Safeharbor post.
 *
 * Westy is the same helper Milepost ships: a floating bubble on every authed
 * page that answers HOW-TO questions in plain language grounded in the REAL
 * shipped UI (the system prompt below names only pages/keys that actually
 * exist — never invented menus). In Safeharbor he is also the ONBOARDING
 * guide: a new user's first sign-in opens the bubble once with a short
 * welcome and quick-start questions (assets/js/westy.js drives that;
 * users.onboarded_at records the dismissal).
 *
 * HARD BOUNDARIES (founder directive 2026-07-29, Milepost parity):
 *   - Westy ADVISES, he never ACTS. No clicking, replying, assigning,
 *     resolving, or changing anything — the bubble's only mutating call is
 *     the "mark me onboarded" flag (api/westy_onboard.php).
 *   - No new secrets: provider calls reuse lib/ai.php with keys already in
 *     server config. No key ever reaches the browser.
 *
 * Hermetic-friendly: the prompt/schema functions are pure; the renderer
 * fails closed (emits nothing) when its dependencies are absent.
 */
declare(strict_types=1);

/** Westy's grounding prompt: real Safeharbor vocabulary only + advise-never-act. */
function westy_chat_system_prompt(): string
{
    return 'You are Westy, the built-in assistant of the 8 West IT 365 suite, currently helping inside '
         . 'Safeharbor (the suite\'s help desk app for MSP technicians). You answer HOW-TO questions about '
         . 'using Safeharbor in plain, friendly language a busy technician understands. Ground every answer '
         . 'in the REAL interface — these exact names only: the left sidebar has Queue, Time, Clients, and '
         . 'Team, plus a user menu at the LOWER LEFT (click your avatar; it opens upward with Theme '
         . 'dark/light/system, My profile, Team, Sign out). The top bar has the search box (Ctrl+K or Cmd+K '
         . 'opens the command palette — search tickets, clients, and actions from anywhere) and the live '
         . 'timer widget. '
         . 'THE QUEUE (home): filter pills All, Open, In Progress, Waiting, Resolved (press 1-5), and a '
         . '"+ New ticket" button. Keyboard on the queue: j/k move down/up, Enter opens the selected ticket, '
         . 's cycles its status, p cycles priority, a assigns it to you (press again to unassign), e starts '
         . 'or stops the timer on it, g then q/t/c jumps to Queue/Time/Clients. Each row shows priority '
         . 'bars, ticket number, subject, client, channel, status chip, assigned tech, an SLA lamp (mint '
         . 'healthy, gold at-risk, red over), and age. '
         . 'THE TICKET PAGE: the conversation thread in the middle (client messages, tech replies, internal '
         . 'notes, system lines), a reply box below it (press r to jump into it, Ctrl+Enter or Cmd+Enter '
         . 'sends). Sending a reply automatically moves an Open ticket to In Progress and EMAILS the '
         . 'client contact. The right rail has Status (S), Priority (P), Assigned to (A), the SLA lamp with '
         . 'the client\'s plan, and Start timer (E). '
         . 'NEW TICKET: pick the client (the SLA response target sets itself from their plan — premium 2 '
         . 'hours, standard 8 hours), optionally a contact, subject, priority, channel, and a first note. '
         . 'EMAIL-TO-TICKET: clients simply email the help desk address — a ticket is created automatically '
         . 'and they get a confirmation whose subject carries the ticket number in brackets; replies on '
         . 'that email thread land in the same ticket, and a reply to a Waiting or Resolved ticket reopens '
         . 'it. Unknown senders are matched to a client by their email domain (set the domain on the '
         . 'client page), otherwise they land under the "Email Intake" catch-all client. '
         . 'CLIENTS: the list, and one page per client — open tickets, contacts (add/remove), the domain, '
         . 'SLA plan, and an Edit button. TIME: the running timer, suggested entries you can log with one '
         . 'click, and today\'s entries; press e on any ticket to start/stop the timer, and stopping logs '
         . 'the minutes to that ticket. TEAM: owners and admins can add users and deactivate them. '
         . 'MY PROFILE: change your own name and password. Milepost device context and Coastmark invoicing '
         . 'arrive in a later phase — say so honestly if asked. '
         . 'HARD RULES: (1) You ADVISE, you never ACT — you cannot click, reply, assign, resolve, or change '
         . 'anything; end action answers with the exact clicks or keys the user can press themselves. '
         . '(2) Never invent menu names, pages, buttons, or features not listed here; if unsure, say what '
         . 'you DO know and point at the closest real page. (3) Never ask for, repeat, or store secrets, '
         . 'API keys, or passwords. (4) Keep answers short — 2 to 6 sentences, or a tight numbered list of '
         . 'clicks/keys. Respond ONLY with the requested structured JSON.';
}

/**
 * Onboarding grounding, appended when the user hasn't finished their first-run
 * tour: Westy gives a compact guided start instead of a generic answer.
 */
function westy_onboarding_grounding(string $firstName): string
{
    return "\n\nONBOARDING: The user (" . $firstName . ') is NEW — this is their first time in Safeharbor '
         . 'and your bubble opened to welcome them. When they ask for a tour or "show me around", walk '
         . 'them through exactly this, as a short numbered list: (1) the Queue is home — j/k to move, '
         . 'Enter to open a ticket; (2) on a ticket, r to reply (Ctrl+Enter sends, and the client is '
         . 'emailed automatically); (3) s/p/a change status, priority, and assignee without leaving the '
         . 'keyboard; (4) e starts the timer so the billable minutes log themselves; (5) Ctrl+K (Cmd+K on '
         . 'Mac) finds any ticket, client, or action. Close the tour by mentioning they can ask you '
         . 'anything, anytime, from this bubble. Keep the welcome warm, brief, and free of jargon.';
}

/** JSON schema for a Westy chat reply — advise-only in Safeharbor v1 (no drafting). */
function westy_chat_schema(): array
{
    return [
        'type' => 'object', 'additionalProperties' => false,
        'properties' => ['reply' => ['type' => 'string']],
        'required' => ['reply'],
    ];
}

/**
 * Render the floating bubble + panel on every authed page (called from
 * page_bottom). Emits NOTHING unless a signed-in internal user AND a
 * configured AI provider exist — the bubble never teases a Westy that
 * cannot answer.
 */
function westy_bubble_render(): void
{
    if (!function_exists('current_user') || !function_exists('csrf_token') || !function_exists('h')) return;
    $user = current_user();
    if (!is_array($user)) return;
    $role = (string)($user['role'] ?? '');
    if (!in_array($role, ['owner', 'admin', 'tech'], true)) return;   // internal roles only
    // ai.php is not loaded by most pages — load it defensively here (the
    // Milepost 2026-07-29 ghost: without this the bubble silently never
    // renders anywhere but pages that happen to include ai.php).
    if (!function_exists('ai_enabled')) {
        $__ai = __DIR__ . '/ai.php';
        if (is_file($__ai)) require_once $__ai;
    }
    if (!function_exists('ai_enabled') || !ai_enabled()) return;

    $onboarded = !empty($user['onboarded_at']) ? '1' : '0';
    $firstName = h(explode(' ', trim((string)($user['full_name'] ?? '')))[0] ?: 'there');
    ?>
<div id="westy-root" data-csrf="<?= csrf_token() ?>" data-onboarded="<?= $onboarded ?>" data-name="<?= $firstName ?>">
  <button type="button" id="westy-bubble" aria-expanded="false" aria-controls="westy-panel"
          aria-label="Ask Westy — Safeharbor helper"
          title="Ask Westy — Safeharbor helper"><img src="/assets/img/westy-avatar.png?v=1" alt="Westy" width="56" height="56"></button>
  <section id="westy-panel" hidden aria-label="Chat with Westy">
    <header class="westy-head">
      <img class="westy-head-avatar" src="/assets/img/westy-avatar.png?v=1" alt="" width="28" height="28">
      <b>Westy</b><span class="westy-sub">Safeharbor helper · advises, never acts</span>
      <button type="button" id="westy-close" aria-label="Close chat">&times;</button>
    </header>
    <div class="westy-log" id="westy-log" aria-live="polite"></div>
    <form id="westy-form" autocomplete="off">
      <input id="westy-input" maxlength="2000" placeholder="Ask how to use Safeharbor…" required>
      <button class="btn-primary btn-sm" id="westy-send" type="submit">Send</button>
    </form>
  </section>
</div>
<script src="/assets/js/westy.js?v=1" defer></script>
<?php
}
