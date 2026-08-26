<?php
/**
 * Customer-portal binding lifecycle and read-only ticket-summary queries.
 *
 * The identity tenant slug is only a lookup key into an explicit operator
 * binding. It is never derived from a numeric claim, email, domain, client
 * name, or source key. After that one trust-resolution lookup, every portal
 * read is constrained by the resolved provider tenant and client together.
 */
declare(strict_types=1);

class PortalDataException extends RuntimeException
{
}

final class PortalDataValidationException extends PortalDataException
{
}

final class PortalDataConflictException extends PortalDataException
{
}

final class PortalDataNotFoundException extends PortalDataException
{
}

final class PortalDataForbiddenException extends PortalDataException
{
}

const PORTAL_IDENTITY_TENANT_SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/D';
const PORTAL_FIXED_RESERVED_IDENTITY_TENANT_SLUGS = ['8west', 'internal'];
const PORTAL_CLIENT_ROLES = ['client_owner', 'client_admin', 'client_staff', 'client_viewer'];

/** @return list<string> */
function portal_reserved_identity_tenant_slugs(?array $configured = null): array
{
    if ($configured === null) {
        $configured = function_exists('cfg')
            ? cfg('portal.reserved_identity_tenant_slugs', [])
            : [];
    }
    if (! is_array($configured) || ! array_is_list($configured)) {
        throw new PortalDataValidationException('The configured reserved identity tenant slugs are invalid.');
    }

    $reserved = PORTAL_FIXED_RESERVED_IDENTITY_TENANT_SLUGS;
    foreach ($configured as $slug) {
        if (! is_string($slug)
            || preg_match(PORTAL_IDENTITY_TENANT_SLUG_PATTERN, $slug) !== 1
            || strtolower($slug) !== $slug) {
            throw new PortalDataValidationException('The configured reserved identity tenant slugs are invalid.');
        }
        if (! in_array($slug, $reserved, true)) {
            $reserved[] = $slug;
        }
    }
    return $reserved;
}

function portal_identity_tenant_slug(string $input, ?array $configuredReserved = null): string
{
    $slug = trim($input);
    if ($slug === ''
        || $slug !== $input
        || strtolower($slug) !== $slug
        || preg_match(PORTAL_IDENTITY_TENANT_SLUG_PATTERN, $slug) !== 1) {
        throw new PortalDataValidationException(
            'Identity tenant slug must already be normalized lowercase ASCII (letters, numbers, and hyphens).'
        );
    }
    if (in_array($slug, portal_reserved_identity_tenant_slugs($configuredReserved), true)) {
        throw new PortalDataValidationException('That identity tenant slug is reserved and cannot be mapped.');
    }
    return $slug;
}

function portal_transition_reason(string $input): string
{
    $reason = trim($input);
    if ($reason === '' || mb_strlen($reason) > 500) {
        throw new PortalDataValidationException('A transition reason between 1 and 500 characters is required.');
    }
    return $reason;
}

/** @return array<string,mixed> */
function portal_mapping_target(PDO $pdo, int $tenantId, int $clientId, int $actorUserId): array
{
    if ($tenantId < 1 || $clientId < 1 || $actorUserId < 1) {
        throw new PortalDataValidationException('Provider tenant, client, and actor ids must be positive integers.');
    }
    $stmt = $pdo->prepare(
        "SELECT t.id AS tenant_id, t.slug AS provider_tenant_slug,
                c.id AS client_id, c.name AS client_name,
                u.id AS actor_user_id, u.role AS actor_role
           FROM tenants t
           JOIN clients c ON c.tenant_id = t.id AND c.id = :client_id
           JOIN users u ON u.tenant_id = t.id AND u.id = :actor_user_id
                        AND u.is_active = 1 AND u.role IN ('owner','admin')
          WHERE t.id = :tenant_id"
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'client_id' => $clientId,
        'actor_user_id' => $actorUserId,
    ]);
    $row = $stmt->fetch();
    if (! is_array($row)) {
        throw new PortalDataForbiddenException(
            'The exact provider tenant/client target was not found, or the actor is not an active owner/admin in that tenant.'
        );
    }
    return $row;
}

function portal_binding_select_sql(): string
{
    return "SELECT b.id, b.identity_tenant_slug, b.tenant_id, b.client_id, b.status,
                   b.prepared_by_user_id, b.prepared_at, b.last_changed_by_user_id,
                   b.status_changed_at, b.status_reason,
                   t.slug AS provider_tenant_slug, c.name AS client_name
              FROM customer_portal_bindings b
              JOIN tenants t ON t.id = b.tenant_id
              JOIN clients c ON c.tenant_id = b.tenant_id AND c.id = b.client_id";
}

/**
 * Initial trust-resolution lookup. This is the only portal query that cannot
 * already bind provider tenant/client because discovering those exact ids is
 * the purpose of the explicit mapping. Callers immediately perform the exact
 * id-bound recheck before creating a local session.
 *
 * @return array<string,mixed>|null
 */
function portal_active_binding_by_identity(PDO $pdo, string $identityTenantSlug): ?array
{
    $slug = portal_identity_tenant_slug($identityTenantSlug);
    $stmt = $pdo->prepare(
        portal_binding_select_sql()
        . " WHERE b.identity_tenant_slug = :identity_tenant_slug
              AND b.status = 'active'
            LIMIT 1"
    );
    $stmt->execute(['identity_tenant_slug' => $slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function portal_active_binding_recheck(
    PDO $pdo,
    int $bindingId,
    string $identityTenantSlug,
    int $tenantId,
    int $clientId,
): ?array {
    if ($bindingId < 1 || $tenantId < 1 || $clientId < 1) return null;
    $slug = portal_identity_tenant_slug($identityTenantSlug);
    $stmt = $pdo->prepare(
        portal_binding_select_sql()
        . " WHERE b.id = :binding_id
              AND b.identity_tenant_slug = :identity_tenant_slug
              AND b.tenant_id = :tenant_id
              AND b.client_id = :client_id
              AND b.status = 'active'
            LIMIT 1"
    );
    $stmt->execute([
        'binding_id' => $bindingId,
        'identity_tenant_slug' => $slug,
        'tenant_id' => $tenantId,
        'client_id' => $clientId,
    ]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed> */
function portal_inspect_binding(
    PDO $pdo,
    int $bindingId,
    string $identityTenantSlug,
    int $tenantId,
    int $clientId,
    bool $forUpdate = false,
): array {
    if ($bindingId < 1 || $tenantId < 1 || $clientId < 1) {
        throw new PortalDataValidationException('Binding, provider tenant, and client ids must be positive integers.');
    }
    $slug = portal_identity_tenant_slug($identityTenantSlug);
    $sql = portal_binding_select_sql()
        . " WHERE b.id = :binding_id
              AND b.identity_tenant_slug = :identity_tenant_slug
              AND b.tenant_id = :tenant_id
              AND b.client_id = :client_id
            LIMIT 1"
        . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'binding_id' => $bindingId,
        'identity_tenant_slug' => $slug,
        'tenant_id' => $tenantId,
        'client_id' => $clientId,
    ]);
    $row = $stmt->fetch();
    if (! is_array($row)) {
        throw new PortalDataNotFoundException('No binding matched every expected identity/provider/client fact.');
    }
    return $row;
}

/** @return list<array<string,mixed>> */
function portal_binding_events(PDO $pdo, int $bindingId, int $tenantId, int $clientId): array
{
    $stmt = $pdo->prepare(
        'SELECT e.id, e.event_kind, e.actor_user_id, e.reason, e.snapshot_json, e.created_at
           FROM customer_portal_binding_events e
          WHERE e.binding_id = :binding_id
            AND e.tenant_id = :tenant_id
            AND e.client_id = :client_id
          ORDER BY e.id ASC'
    );
    $stmt->execute([
        'binding_id' => $bindingId,
        'tenant_id' => $tenantId,
        'client_id' => $clientId,
    ]);
    return $stmt->fetchAll();
}

/** @return array<string,mixed> */
function portal_prepare_binding(
    PDO $pdo,
    string $identityTenantSlug,
    int $tenantId,
    int $clientId,
    int $actorUserId,
    string $reason,
): array {
    $slug = portal_identity_tenant_slug($identityTenantSlug);
    $reason = portal_transition_reason($reason);
    portal_mapping_target($pdo, $tenantId, $clientId, $actorUserId);

    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare(
            'SELECT id, identity_tenant_slug, tenant_id, client_id
               FROM customer_portal_bindings
              WHERE identity_tenant_slug = :identity_tenant_slug
                 OR (tenant_id = :tenant_id AND client_id = :client_id)
              FOR UPDATE'
        );
        $existing->execute([
            'identity_tenant_slug' => $slug,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
        ]);
        if ($existing->fetch()) {
            throw new PortalDataConflictException(
                'The identity tenant slug or exact provider tenant/client already has a binding.'
            );
        }

        $insert = $pdo->prepare(
            "INSERT INTO customer_portal_bindings
                (identity_tenant_slug, tenant_id, client_id, status,
                 prepared_by_user_id, last_changed_by_user_id, status_reason)
             VALUES (:identity_tenant_slug, :tenant_id, :client_id, 'disabled',
                     :actor_user_id, :actor_user_id, :status_reason)"
        );
        $insert->execute([
            'identity_tenant_slug' => $slug,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'actor_user_id' => $actorUserId,
            'status_reason' => $reason,
        ]);
        $bindingId = (int) $pdo->lastInsertId();
        $row = portal_inspect_binding($pdo, $bindingId, $slug, $tenantId, $clientId);
        $pdo->commit();
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PortalDataException) throw $error;
        if ($error instanceof PDOException && (string)$error->getCode() === '23000') {
            throw new PortalDataConflictException(
                'The identity tenant slug or exact provider tenant/client already has a binding.',
                0,
                $error,
            );
        }
        throw $error;
    }
}

/** @return array<string,mixed> */
function portal_transition_binding(
    PDO $pdo,
    int $bindingId,
    string $identityTenantSlug,
    int $tenantId,
    int $clientId,
    int $actorUserId,
    string $decision,
    string $reason,
): array {
    if (! in_array($decision, ['active', 'disabled'], true)) {
        throw new PortalDataValidationException('Binding decision must be active or disabled.');
    }
    $slug = portal_identity_tenant_slug($identityTenantSlug);
    $reason = portal_transition_reason($reason);
    portal_mapping_target($pdo, $tenantId, $clientId, $actorUserId);

    $pdo->beginTransaction();
    try {
        $current = portal_inspect_binding($pdo, $bindingId, $slug, $tenantId, $clientId, true);
        if ((string)$current['status'] === $decision) {
            $pdo->commit();
            return $current;
        }
        $update = $pdo->prepare(
            'UPDATE customer_portal_bindings
                SET status = :status,
                    last_changed_by_user_id = :actor_user_id,
                    status_reason = :status_reason
              WHERE id = :binding_id
                AND identity_tenant_slug = :identity_tenant_slug
                AND tenant_id = :tenant_id
                AND client_id = :client_id
                AND status <> :current_status'
        );
        $update->execute([
            'status' => $decision,
            'actor_user_id' => $actorUserId,
            'status_reason' => $reason,
            'binding_id' => $bindingId,
            'identity_tenant_slug' => $slug,
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'current_status' => $decision,
        ]);
        if ($update->rowCount() !== 1) {
            throw new PortalDataConflictException('The binding changed concurrently; inspect it again before retrying.');
        }
        $row = portal_inspect_binding($pdo, $bindingId, $slug, $tenantId, $clientId);
        $pdo->commit();
        return $row;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** @return array{client:array<string,mixed>,counts:array<string,int>,tickets:list<array<string,mixed>>} */
function portal_ticket_summary(PDO $pdo, int $tenantId, int $clientId, int $limit = 50): array
{
    if ($tenantId < 1 || $clientId < 1 || $limit < 1 || $limit > 100) {
        throw new PortalDataValidationException('The ticket-summary boundary is invalid.');
    }

    $client = $pdo->prepare(
        'SELECT id, tenant_id, name
           FROM clients
          WHERE tenant_id = :tenant_id AND id = :client_id
          LIMIT 1'
    );
    $client->execute(['tenant_id' => $tenantId, 'client_id' => $clientId]);
    $clientRow = $client->fetch();
    if (! is_array($clientRow)) {
        throw new PortalDataNotFoundException('The mapped provider tenant/client no longer exists.');
    }

    $counts = ['open' => 0, 'in_progress' => 0, 'waiting' => 0, 'resolved' => 0];
    $countStmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS total
           FROM tickets
          WHERE tenant_id = :tenant_id
            AND client_id = :client_id
            AND merged_into_id IS NULL
          GROUP BY status'
    );
    $countStmt->execute(['tenant_id' => $tenantId, 'client_id' => $clientId]);
    foreach ($countStmt->fetchAll() as $row) {
        if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['total'];
    }

    $tickets = $pdo->prepare(
        "SELECT id, subject, status, priority, created_at, updated_at, resolved_at
           FROM tickets
          WHERE tenant_id = :tenant_id
            AND client_id = :client_id
            AND merged_into_id IS NULL
          ORDER BY updated_at DESC, id DESC
          LIMIT {$limit}"
    );
    $tickets->execute(['tenant_id' => $tenantId, 'client_id' => $clientId]);

    return [
        'client' => $clientRow,
        'counts' => $counts,
        'tickets' => $tickets->fetchAll(),
    ];
}
