<?php
declare(strict_types=1);

require_once __DIR__.'/../../../lib/bootstrap.php';
require_once __DIR__.'/../../../lib/logbook_export.php';

header('Cache-Control: no-store');
if (cfg('logbook_export.enabled', false) !== true) {
    json_out(['ok' => false, 'error' => 'not found'], 404);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}
try {
    $settings = logbook_export_settings((array)cfg('logbook_export', []));
    $body = (string)file_get_contents('php://input', false, null, 0, 16385);
    if (!logbook_export_authenticated($settings, [
        'secret' => $_SERVER['HTTP_X_SAFEHARBOR_LOGBOOK'] ?? '',
        'timestamp' => $_SERVER['HTTP_X_SAFEHARBOR_TIMESTAMP'] ?? '',
        'nonce' => $_SERVER['HTTP_X_SAFEHARBOR_NONCE'] ?? '',
        'signature' => $_SERVER['HTTP_X_SAFEHARBOR_SIGN'] ?? '',
    ], $body)) {
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    $request = logbook_export_request($body);
    json_out(logbook_export_page(db(), $settings, $request));
} catch (InvalidArgumentException) {
    json_out(['ok' => false, 'error' => 'invalid request'], 400);
} catch (Throwable) {
    json_out(['ok' => false, 'error' => 'export unavailable'], 503);
}
