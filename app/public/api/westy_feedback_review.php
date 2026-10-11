<?php
/** Read-only operator metadata bridge. No cookie/session, provisioning or device authority. */
declare(strict_types=1);
require_once __DIR__.'/../../lib/bootstrap.php';
require_once __DIR__.'/../../lib/portal_westy_feedback.php';
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new PortalFeedbackReviewException('method',405);
    $header=$_SERVER['HTTP_AUTHORIZATION']??'';
    if (!is_string($header) || preg_match('/\ABearer ([A-Za-z0-9_.-]{1,16384})\z/D',$header,$match)!==1)
        throw new PortalFeedbackReviewException('sign_in',401);
    $raw=file_get_contents('php://input',false,null,0,4097);
    if (!is_string($raw) || strlen($raw)>4096) throw new PortalFeedbackReviewException('invalid_request',400);
    try { $shape=json_decode($raw); $request=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new PortalFeedbackReviewException('invalid_request',400); }
    if (!$shape instanceof stdClass || !is_array($request)) throw new PortalFeedbackReviewException('invalid_request',400);
    echo json_encode(portal_feedback_review(db(),$match[1],$request),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (PortalFeedbackReviewException $error) {
    http_response_code($error->status);
    echo json_encode(['ok'=>false,'reason'=>$error->reason]);
} catch (Throwable) {
    http_response_code(503);
    echo '{"ok":false,"reason":"unavailable"}';
}
