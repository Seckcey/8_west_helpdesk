<?php
declare(strict_types=1);
require_once __DIR__ . '/suite_workspace_manifest.php';

/** Provision only workspace/owner. Never revive a disabled account or move a subject. */
function suite_workspace_provision(PDO $pdo, array $manifest, bool $adoptHouse = false): int
{
    suite_workspace_manifest_validate($manifest, 'safeharbor');
    if ($adoptHouse && !in_array($manifest['slug'], ['8west', 'internal'], true)) {
        throw new InvalidArgumentException('house adoption refused');
    }
    if ($pdo->inTransaction()) throw new LogicException('transaction ownership required');
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT id FROM tenants WHERE slug = ? FOR UPDATE');
        $query->execute([$manifest['slug']]);
        $tenantId = (int)($query->fetchColumn() ?: 0);
        $owner = $manifest['owner'];
        $query = $pdo->prepare('SELECT * FROM users WHERE suite_subject = ? FOR UPDATE');
        $query->execute([$owner['subject']]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if ($user && ($tenantId < 1 || (int)$user['tenant_id'] !== $tenantId
            || !(int)$user['is_active'] || $user['role'] !== 'owner')) {
            throw new RuntimeException('existing owner is not admitted');
        }
        if ($tenantId > 0 && !$user && !$adoptHouse) {
            throw new RuntimeException('existing workspace needs explicit ownership reconciliation');
        }
        if ($tenantId === 0) {
            $pdo->prepare('INSERT INTO tenants (name, slug) VALUES (?, ?)')
                ->execute([$manifest['name'], $manifest['slug']]);
            $tenantId = (int)$pdo->lastInsertId();
        }
        if (!$user) {
            $query = $pdo->prepare('SELECT id FROM users WHERE email = ? FOR UPDATE');
            $query->execute([$owner['email']]);
            // Adoption is not a global email-based account claim.
            if ($query->fetchColumn() !== false) throw new RuntimeException('email ownership collision');
            $pdo->prepare('INSERT INTO users (tenant_id, email, suite_subject, password_hash, full_name, initials, role)
                VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                    $tenantId, $owner['email'], $owner['subject'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                    mb_substr($owner['name'], 0, 128), mb_strtoupper(mb_substr($owner['name'], 0, 2)), 'owner',
                ]);
        }
        $pdo->commit();
        return $tenantId;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
