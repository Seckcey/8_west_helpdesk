<?php
declare(strict_types=1);
require_once __DIR__.'/../../../lib/bootstrap.php';
require_once __DIR__.'/../../../lib/central_issues.php';
header('Cache-Control: no-store, private, no-transform');
header('X-Content-Type-Options: nosniff');
try {
    $settings=central_issue_config(cfg('central_issues',[]));
    $raw=file_get_contents('php://input',false,null,0,16385);
    if (!is_string($raw)) throw new CentralIssueRefused(400,'invalid_request');
    $actor=central_issue_auth($settings,$raw,$_SERVER,time());
    $request=central_issue_request($raw,$actor);
    $response=central_issue_execute(db(),$settings,$request,$actor);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (CentralIssueRefused $e) {
    json_out(['error'=>$e->reason],$e->status);
} catch (Throwable $e) {
    error_log('central issues: request unavailable');
    json_out(['error'=>'service_unavailable'],503);
}
