<?php
/**
 * POST /api/westy_thumbs_up.php — "that answer was good" on a Westy answer.
 *   question — the question that got the good answer (<= 2000 chars)
 *   answer   — the answer being praised (<= 4000 chars)
 *
 * The deliberate opposite of westy_feedback.php: this opens NO ticket. Tickets
 * are for problems, and a queue that fills up with good news is a queue nobody
 * reads. The whole effect is one bounded assistant_log row.
 *
 * It stores no chat text either — only lengths and the question fingerprint —
 * because a thumbs-up is one click with no review box, so nobody has looked at
 * those words and agreed to send them. See westy_thumbs_up_record().
 *
 * Session-authed and CSRF-checked, exactly like westy_feedback.php. The browser
 * treats this as fire-and-forget: the button says "Thanks!" the instant it is
 * pressed and never surfaces an error, because a lost compliment must not cost
 * a technician a second of attention.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/ai.php';
require_once __DIR__ . '/../../lib/westy_report.php';
enforce_https();

/** Abuse bound. Only has to stop a stuck key — praise is cheap and welcome. */
const WESTY_THUMBS_UP_LIMIT      = 60;
const WESTY_THUMBS_UP_WINDOW_MIN = 60;

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
csrf_check();

if (!ai_enabled()) json_out(['ok' => false, 'error' => 'Westy is not set up on this Safeharbor installation.'], 503);

$question = trim((string)($_POST['question'] ?? ''));
$answer   = trim((string)($_POST['answer'] ?? ''));
if ($question === '' || $answer === '') {
    json_out(['ok' => false, 'error' => 'Nothing to record.'], 400);
}

// Per-user sliding window, counted from assistant_log (the westy_chat pattern).
try {
    $st = db()->prepare(
        "SELECT COUNT(*) FROM assistant_log
          WHERE user_id = ? AND action = 'westy_thumbs_up' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? MINUTE)"
    );
    $st->execute([(int)$user['id'], WESTY_THUMBS_UP_WINDOW_MIN]);
    if ((int)$st->fetchColumn() >= WESTY_THUMBS_UP_LIMIT) {
        json_out(['ok' => false, 'error' => 'That is a lot of thumbs in one hour.'], 429);
    }
} catch (Throwable $e) {
    // Counting must never block a genuine signal.
}

$ok = westy_thumbs_up_record(
    (int)$user['id'],
    mb_substr($question, 0, 2000),
    mb_substr($answer, 0, 4000)
);

// 200 either way. The browser has already thanked the technician and there is
// nothing useful it could do with a failure, so this only reports the truth for
// anyone reading logs.
json_out(['ok' => $ok]);
