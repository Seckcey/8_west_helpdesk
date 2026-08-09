<?php
/**
 * POST /api/westy_chat.php — Westy chat (session-authed, CSRF-checked).
 *   message  — the user's question (<= 2000 chars)
 *   history  — optional JSON array of recent {q,a} pairs (<= 6, each side <= 500 chars)
 *
 * Westy ADVISES, he never ACTS (Milepost parity): this endpoint's only effect
 * is one provider call plus one bounded assistant_log row — it cannot touch a
 * ticket, client, or timer. Keys live only in server config. The audit row
 * records lengths only, never message bodies.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/ai.php';
require_once __DIR__ . '/../../lib/westy.php';
require_once __DIR__ . '/../../lib/westy_report.php';
enforce_https();

/** Shown when a failure has been filed for our own staff to fix. */
const WESTY_LOGGED_SUFFIX = ' I\'ve logged this for the 8 West team.';

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
csrf_check();

if (!ai_enabled()) json_out(['ok' => false, 'error' => 'Westy is not set up on this Safeharbor installation.'], 503);

$msg = trim((string)($_POST['message'] ?? ''));
if ($msg === '') json_out(['ok' => false, 'error' => 'Say something first.'], 400);
if (mb_strlen($msg) > 2000) json_out(['ok' => false, 'error' => 'Keep it under 2000 characters.'], 400);

// Short-term memory: the last few exchanges, sent by the client each time
// (session-only — nothing stored server-side). Hard-bounded.
$hist = [];
$rawH = (string)($_POST['history'] ?? '');
if ($rawH !== '') {
    $dec = json_decode($rawH, true);
    if (is_array($dec)) {
        foreach (array_slice($dec, -6) as $h) {
            if (!is_array($h)) continue;
            $q = mb_substr(trim((string)($h['q'] ?? '')), 0, 500);
            $a = mb_substr(trim((string)($h['a'] ?? '')), 0, 500);
            if ($q !== '' && $a !== '') $hist[] = ['q' => $q, 'a' => $a];
        }
    }
}

// Per-user sliding-window rate limit (abuse/cost protection), counted from
// assistant_log. rate_limit <= 0 disables it.
$c      = ai_config();
$limit  = (int)($c['rate_limit'] ?? 20);
$window = max(1, (int)($c['rate_window_min'] ?? 60));
if ($limit > 0) {
    try {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM assistant_log
              WHERE user_id = ? AND action = 'westy_chat' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
        );
        $st->execute([(int)$user['id'], $window]);
        if ((int)$st->fetchColumn() >= $limit) {
            json_out(['ok' => false, 'error' => 'Westy needs a breather — try again a little later.'], 429);
        }
    } catch (Throwable $e) {
        // migration 002 not applied yet — let the chat work, unlimited until it lands
    }
}

// Fold the bounded history into the single user turn (the provider call is stateless).
$userTurn = $msg;
if ($hist) {
    $lines = [];
    foreach ($hist as $h) { $lines[] = 'User: ' . $h['q'] . "\nWesty: " . $h['a']; }
    $userTurn = "Earlier in this chat:\n" . implode("\n", $lines) . "\n\nCurrent question: " . $msg;
}

$sys = westy_chat_system_prompt();
if (empty($user['onboarded_at'])) {
    $sys .= westy_onboarding_grounding(explode(' ', trim((string)$user['full_name']))[0] ?: 'there');
}

$r = ai_provider_complete($sys, $userTurn, westy_chat_schema());
if (empty($r['ok'])) {
    westy_log((int)$user['id'], 'westy_chat', 'error: ' . mb_substr((string)$r['error'], 0, 200));
    // Until now this is where a Westy failure died: one 255-char log row and an
    // apology, and nobody was ever told. Now it opens a ticket in 8 West IT's
    // own queue. A refusal is skipped inside the helper — that is the safety
    // layer working, not Westy breaking.
    westy_report_failure($r, 'westy_chat', (int)$user['id']);
    $tail = empty($r['refusal']) ? WESTY_LOGGED_SUFFIX : '';
    json_out(['ok' => false, 'error' => 'Westy is unavailable right now — try again in a minute.' . $tail], 502);
}
$reply = trim((string)($r['data']['reply'] ?? ''));
if ($reply === '') {
    westy_log((int)$user['id'], 'westy_chat', 'error: empty reply');
    westy_report_failure(['ok' => false, 'error' => 'empty reply'], 'westy_chat', (int)$user['id'], 'empty reply');
    json_out(['ok' => false, 'error' => 'Westy came back speechless — try rephrasing the question.' . WESTY_LOGGED_SUFFIX], 502);
}

westy_log((int)$user['id'], 'westy_chat', 'msg_len=' . mb_strlen($msg) . ' reply_len=' . mb_strlen($reply));
json_out(['ok' => true, 'reply' => $reply]);

/** Bounded usage row — lengths and outcomes only, never content. */
function westy_log(int $userId, string $action, string $meta): void
{
    try {
        db()->prepare('INSERT INTO assistant_log (user_id, action, meta) VALUES (?,?,?)')
            ->execute([$userId, $action, mb_substr($meta, 0, 255)]);
    } catch (Throwable $e) {
        // usage logging must never take the chat down
    }
}
