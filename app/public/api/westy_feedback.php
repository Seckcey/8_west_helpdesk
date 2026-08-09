<?php
/**
 * POST /api/westy_feedback.php — "this wasn't helpful" on a Westy answer.
 *   question — the question that got the bad answer (<= 2000 chars)
 *   answer   — the answer being flagged (<= 4000 chars)
 *   note     — optional: what went wrong (<= 500 chars)
 *
 * Session-authed and CSRF-checked, exactly like westy_chat.php — deliberately
 * NOT the HMAC door. A signed-in technician uses the front door; api/svc/westy.php
 * exists only for the other suite apps.
 *
 * The server keeps no Westy transcript (westy.js holds it in sessionStorage),
 * so the question and answer arrive from the browser — after the technician has
 * seen the exact text and pressed Send. Nothing is sent unreviewed.
 *
 * The ticket opens in 8 West IT's OWN queue so our staff can fix Westy.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/ai.php';
require_once __DIR__ . '/../../lib/westy_report.php';
enforce_https();

/** Abuse bound. Flagging is deliberate, so this only has to stop a stuck key. */
const WESTY_FEEDBACK_LIMIT      = 20;
const WESTY_FEEDBACK_WINDOW_MIN = 60;

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
csrf_check();

if (!ai_enabled()) json_out(['ok' => false, 'error' => 'Westy is not set up on this Safeharbor installation.'], 503);

$question = trim((string)($_POST['question'] ?? ''));
$answer   = trim((string)($_POST['answer'] ?? ''));
$note     = trim((string)($_POST['note'] ?? ''));
if ($question === '' || $answer === '') {
    json_out(['ok' => false, 'error' => 'Nothing to send — the question and answer are both needed.'], 400);
}

// Per-user sliding window, counted from assistant_log (the westy_chat pattern).
try {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM assistant_log
          WHERE user_id = ? AND action = 'westy_feedback' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $st->execute([(int)$user['id'], WESTY_FEEDBACK_WINDOW_MIN]);
    if ((int)$st->fetchColumn() >= WESTY_FEEDBACK_LIMIT) {
        json_out(['ok' => false, 'error' => 'That is a lot of reports in one hour — take a breath and try again later.'], 429);
    }
} catch (Throwable $e) {
    // Counting must never block a genuine report.
}

try {
    $result = westy_report_record([
        'event'    => 'flagged',
        'app'      => 'safeharbor',
        'surface'  => 'westy_chat',
        'user_ref' => (string)$user['id'],
        'flagged'  => ['question' => $question, 'answer' => $answer, 'note' => $note],
    ]);
} catch (Throwable $e) {
    error_log('westy feedback: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Could not file that just now — try again in a minute.'], 500);
}
if (empty($result['ok'])) {
    json_out(['ok' => false, 'error' => 'Could not file that — ' . ($result['error'] ?? 'bad request') . '.'], 422);
}

// Bounded usage row — lengths and outcome only, never content.
try {
    db()->prepare('INSERT INTO assistant_log (user_id, action, meta) VALUES (?,?,?)')->execute([
        (int)$user['id'],
        'westy_feedback',
        mb_substr('q_len=' . mb_strlen($question) . ' a_len=' . mb_strlen($answer)
            . ' note_len=' . mb_strlen($note) . ' action=' . ($result['action'] ?? '?'), 0, 255),
    ]);
} catch (Throwable $e) {
    // usage logging must never take the report down
}

json_out(['ok' => true]);
