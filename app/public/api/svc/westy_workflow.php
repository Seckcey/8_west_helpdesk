<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/westy_workflow.php';
header('Cache-Control: no-store');
if (cfg('westy_workflow.enabled', false) !== true) json_out(['ok'=>false,'error'=>'not found'],404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok'=>false,'error'=>'method not allowed'],405);
try { $settings = westy_workflow_settings((array)cfg('westy_workflow',[])); }
catch (RuntimeException) { json_out(['ok'=>false,'error'=>'workflow unavailable'],503); }
$raw = (string)file_get_contents('php://input', false, null, 0, 16385);
if (!westy_workflow_authenticated($settings, ['service'=>$_SERVER['HTTP_X_8W_SERVICE'] ?? '', 'timestamp'=>$_SERVER['HTTP_X_8W_TIMESTAMP'] ?? '', 'signature'=>$_SERVER['HTTP_X_8W_SIGNATURE'] ?? ''], $raw)) json_out(['ok'=>false,'error'=>'unauthorized'],401);
try {
    $payload = westy_workflow_request($raw);
    $result = westy_workflow_receive(db(),$settings,$payload,hash('sha256',$raw));
    json_out($result);
} catch (WestyWorkflowConflict $error) { json_out(['ok'=>false,'error'=>$error->getMessage()],409); }
catch (InvalidArgumentException $error) { json_out(['ok'=>false,'error'=>$error->getMessage()],422); }
catch (Throwable $error) {
    if ($error->getMessage() === 'unauthorized') json_out(['ok'=>false,'error'=>'unauthorized'],401);
    error_log('Westy workflow receiver: ' . $error::class);
    json_out(['ok'=>false,'error'=>'workflow unavailable'],503);
}
