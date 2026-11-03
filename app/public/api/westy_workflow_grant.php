<?php
declare(strict_types=1);
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/westy_workflow_grants.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok'=>false,'error'=>'method not allowed'],405);
$user = require_login();
csrf_check();
try {
    $p = westy_workflow_grant_request((string)file_get_contents('php://input',false,null,0,2049));
    // Turning the existing controller off must not prevent a creator cancelling intent.
    if ($p['action'] === 'create' && cfg('westy_workflow.enabled',false) !== true) json_out(['ok'=>false,'error'=>'return_not_available'],409);
    json_out(westy_workflow_grant_action(db(),(int)$user['tenant_id'],(int)$user['id'],$p));
} catch (WestyWorkflowConflict $error) { json_out(['ok'=>false,'error'=>$error->getMessage()],409); }
catch (InvalidArgumentException $error) { json_out(['ok'=>false,'error'=>$error->getMessage()],422); }
catch (Throwable $error) {
    error_log('Westy workflow grant: ' . $error::class);
    json_out(['ok'=>false,'error'=>'workflow_v2_unavailable'],503);
}
