<?php
/** Local coordinator admission. A receipt never substitutes for current owner authority. */
declare(strict_types=1);

const SUITE_MANAGED_PROVIDER_OWNER_ROLES = ['owner','admin'];

function suite_managed_provider(PDO $pdo, string $slug, bool $lock = false): ?array
{
    if (in_array($slug, ['8west', 'internal'], true)) return null;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        && !(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='suite_managed_providers' AND type='table'")->fetchColumn()) return null;
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR SHARE' : '';
    $q = $pdo->prepare('SELECT * FROM suite_managed_providers WHERE provider_slug = ?' . $suffix);
    $q->execute([$slug]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['lease_until'] <= gmdate('Y-m-d H:i:s')) return null;
    $q = $pdo->prepare('SELECT id, tenant_id, role, is_active, suite_subject FROM users WHERE tenant_id = ? AND id = ?' . $suffix);
    $q->execute([(int)$row['tenant_id'], (int)$row['owner_user_id']]);
    $owner = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($owner) || (int)$owner['is_active'] !== 1
        || !in_array($owner['role'], SUITE_MANAGED_PROVIDER_OWNER_ROLES, true)
        || !suite_managed_provider_owner_matches($row, $owner)) return null;
    $q = $pdo->prepare('SELECT * FROM tenants WHERE id = ? AND slug = ?' . $suffix);
    $q->execute([(int)$row['tenant_id'], $slug]);
    $tenant = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tenant) || (isset($tenant['status']) && $tenant['status'] !== 'active')) return null;
    if (function_exists('suite_managed_provider_entitled')
        && !suite_managed_provider_entitled($pdo, $tenant, $owner)) return null;
    return $row;
}

/** Called only by the local coordinator CLI after the workspace adapter succeeds. */
function suite_managed_provider_register(PDO $pdo, array $manifest, int $tenantId, int $ownerId): array
{
    if (in_array($manifest['slug'], ['8west', 'internal'], true)
        || ($manifest['owner']['role'] ?? '') !== 'msp_owner'
        || !in_array('milepost', $manifest['products'], true)
        || !in_array('safeharbor', $manifest['products'], true)) {
        throw new RuntimeException('managed provider admission refused');
    }
    if ($pdo->inTransaction()) throw new RuntimeException('provider registration owns transaction');
    $pdo->beginTransaction();
    try {
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $q = $pdo->prepare('SELECT id FROM tenants WHERE id = ? AND slug = ?' . $suffix);
        $q->execute([$tenantId, $manifest['slug']]);
        if ((int)$q->fetchColumn() !== $tenantId) throw new RuntimeException('provider tenant mismatch');
        $q = $pdo->prepare('SELECT * FROM suite_managed_providers WHERE tenant_id = ?' . $suffix);
        $q->execute([$tenantId]);
        $old = $q->fetch(PDO::FETCH_ASSOC);
        $identity = [$tenantId, $manifest['tenant_id'], $manifest['slug'], $ownerId, $manifest['owner']['subject']];
        if ($old && [(int)$old['tenant_id'], (int)$old['issuer_tenant_id'], $old['provider_slug'],
            (int)$old['owner_user_id'], $old['owner_subject']] !== $identity) {
            throw new RuntimeException('immutable provider ownership conflict');
        }
        $until = gmdate('Y-m-d H:i:s', time() + 300);
        if ($old) {
            $q = $pdo->prepare('UPDATE suite_managed_providers SET lease_until = ? WHERE tenant_id = ?');
            $q->execute([$until, $tenantId]);
        } else {
            $q = $pdo->prepare('INSERT INTO suite_managed_providers
                (tenant_id, issuer_tenant_id, provider_slug, owner_user_id, owner_subject, lease_until)
                VALUES (?, ?, ?, ?, ?, ?)');
            $q->execute([...$identity, $until]);
        }
        $row = suite_managed_provider($pdo, $manifest['slug']);
        if ($row === null) throw new RuntimeException('provider owner is no longer eligible');
        $pdo->commit();
        return $row;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function suite_managed_provider_owner_matches(array $row, array $owner): bool
{
    return $owner['suite_subject'] === $row['owner_subject'];
}

/** The trusted workspace adapter may add the controller identity, never restore a revoked one. */
function suite_managed_provider_workflow_register(PDO $pdo, string $slug): void
{
    if (cfg('westy_workflow.managed_providers_enabled', false) !== true) return;
    if ($pdo->inTransaction()) throw new RuntimeException('workflow registration owns transaction');
    $pdo->beginTransaction();
    try {
        // Match receive lock order: tenant, current provider/owner, service identity.
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $q = $pdo->prepare('SELECT id FROM tenants WHERE slug=?' . $lock);
        $q->execute([$slug]);
        $tid = (int)$q->fetchColumn();
        $provider = suite_managed_provider($pdo, $slug, true);
        if ($provider === null || (int)$provider['tenant_id'] !== $tid) throw new RuntimeException('provider unavailable');
        $q = $pdo->prepare('SELECT is_active FROM svc_identities WHERE tenant_id=? AND service=?' . $lock);
        $q->execute([$tid, 'milepost-workflow']);
        $active = $q->fetchColumn();
        if ($active !== false && (int)$active !== 1) throw new RuntimeException('workflow service access was revoked');
        if ($active === false) {
            $pdo->prepare('INSERT INTO svc_identities (tenant_id,service,display_name) VALUES (?,?,?)')
                ->execute([$tid, 'milepost-workflow', 'Milepost customer troubleshooting']);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function suite_managed_provider_enrolled(PDO $pdo, int $tenantId): bool
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        && !(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='suite_managed_providers' AND type='table'")->fetchColumn()) return false;
    $q = $pdo->prepare('SELECT COUNT(*) FROM suite_managed_providers WHERE tenant_id = ?');
    $q->execute([$tenantId]);
    return (int)$q->fetchColumn() === 1;
}

/** Automatic report scope is derived from immutable activation and exact current contact evidence. */
function suite_managed_report_schedule_allowed(PDO $pdo, array $schedule): bool
{
    $slug=(string)($schedule['tenant_slug'] ?? '');
    if (in_array($slug,['8west','internal',''],true)) return false;
    $provider=suite_managed_provider($pdo,$slug);
    if ($provider===null || (int)$provider['tenant_id']!==(int)($schedule['tenant_id']??0)) return false;
    $q=$pdo->prepare('SELECT COUNT(*) FROM managed_customer_activation_receipts a
        JOIN business_report_id_client_bindings b ON b.tenant_id=a.tenant_id AND b.client_id=a.client_id
          AND b.id_tenant_key=a.id_tenant_key AND b.id_tenant_slug=a.identity_tenant_slug
        WHERE a.tenant_id=? AND a.client_id=? AND a.schedule_key=?');
    $q->execute([(int)$provider['tenant_id'],(int)($schedule['client_id']??0),(string)($schedule['schedule_key']??'')]);
    if ((int)$q->fetchColumn()!==1) return false;
    $contact=business_report_latest_id_client_contact_for_key($pdo,(int)$provider['tenant_id'],(string)$schedule['schedule_key']);
    return is_array($contact) && hash_equals((string)$contact['recipient_email'],(string)($schedule['recipient_email']??''));
}
