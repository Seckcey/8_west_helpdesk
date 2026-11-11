<?php
/** Signed-in, tenant-scoped, read-only service-goal policy history. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/service_goal_policy_admin.php';
require_once __DIR__ . '/../lib/service_goal_desk.php';
require_once __DIR__ . '/../lib/ticket_queue.php';
require_once __DIR__ . '/../lib/westy_workflow.php';

enforce_https();
$user = require_login();
if (! service_goal_policy_can_view_history($user)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Active Safeharbor staff role required.';
    exit;
}

$history = null;
$historyError = null;
try {
    $history = service_goal_policy_history(db(), (int) $user['tenant_id']);
} catch (Throwable $error) {
    error_log('service-goal history unavailable: ' . $error::class);
    $historyError = 'Policy history is temporarily unavailable. No policy data was changed.';
    http_response_code(503);
}

$timeZone = (string) ((is_array($_SESSION['suite_preferences'] ?? null)
    ? $_SESSION['suite_preferences'] : suite_preferences_defaults())['time_zone'] ?? 'UTC');
$risk = null;
try {
    $state = queue_request(['view' => 'risk']);
    $risk = [
        'total' => queue_risk_count(db(), (int)$user['tenant_id']),
        'rows' => westy_workflow_ticket_owners(db(), (int)$user['tenant_id'],
            array_slice(queue_page(db(), (int)$user['tenant_id'], (int)$user['id'], $state)['rows'], 0, 10)),
    ];
} catch (Throwable $error) {
    error_log('service-goal at-risk list unavailable: ' . $error::class);
}

page_top($user, 'Service goals', 'service-goals', ['service-goals' => $risk['total'] ?? null]);
echo service_goal_desk_html($history, $historyError, $risk, $timeZone, time());
page_bottom(palette_data());
