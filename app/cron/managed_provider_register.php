<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/suite_workspace_manifest.php';
require_once __DIR__ . '/../lib/suite_managed_provider.php';
try {
    [$manifest, $hash] = suite_workspace_manifest_read('safeharbor', $argv);
    $pdo = db();
    $q = $pdo->prepare('SELECT * FROM tenants WHERE slug = ?');
    $q->execute([$manifest['slug']]);
    $tenant = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tenant)) throw new RuntimeException('workspace missing');
    $q = $pdo->prepare('SELECT * FROM users WHERE tenant_id = ? AND suite_subject = ?');
    $q->execute([(int)$tenant['id'], $manifest['owner']['subject']]);
    $owner = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owner)) throw new RuntimeException('owner missing');
    // Authority is carried only over the coordinator-owned local stdin transport.
    $provider = suite_managed_provider_register($pdo, $manifest, (int)$tenant['id'], (int)$owner['id']);
    suite_managed_provider_workflow_register($pdo, $manifest['slug']);
    require_once __DIR__ . '/../lib/business_reports.php';
    $q = $pdo->prepare('SELECT is_active FROM svc_identities WHERE tenant_id = ? AND service = ?');
    $q->execute([(int)$tenant['id'], 'milepost-customers']);
    $active = $q->fetchColumn();
    if ($active !== false && (int)$active !== 1) throw new RuntimeException('service access was revoked');
    if ($active === false) {
        $q = $pdo->prepare('INSERT INTO svc_identities (tenant_id,service,display_name) VALUES (?,?,?)');
        $q->execute([(int)$tenant['id'], 'milepost-customers', 'Milepost client directory']);
    }
    for ($v=1; $v<=3; $v++) business_report_publish_definition($pdo, $manifest['slug'],
        (int)$owner['id'], 'Automatic managed-client workspace setup', $v);
    echo json_encode(['tenant_id'=>$manifest['tenant_id'], 'manifest_sha256'=>$hash,
        'status'=>'admitted'], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "managed provider admission refused\n"); exit(1);
}
