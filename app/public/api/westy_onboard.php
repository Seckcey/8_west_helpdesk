<?php
/**
 * POST /api/westy_onboard.php — mark the signed-in user as onboarded
 * (they finished or dismissed Westy's first-run welcome). Idempotent;
 * the ONLY thing Westy's bubble is allowed to change.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
enforce_https();

$user = current_user();
if (!$user) json_out(['ok' => false, 'error' => 'Unauthorized'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'POST required'], 405);
csrf_check();

if (empty($user['onboarded_at'])) {
    db()->prepare('UPDATE users SET onboarded_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([(int)$user['id']]);
}

json_out(['ok' => true]);
