<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/release.php';

$metadata = safeharbor_release_metadata();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($metadata === null) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable'], JSON_UNESCAPED_SLASHES);
    exit;
}

header('X-Safeharbor-Revision: ' . $metadata['revision']);
echo json_encode([
    'status' => 'ok',
    'revision' => $metadata['revision'],
    'artifact_sha256' => $metadata['artifact_sha256'],
    'release_digest' => $metadata['release_digest'],
], JSON_UNESCAPED_SLASHES);
