<?php
/** Ticket authority comes only from the freshly authenticated portal identity. */
declare(strict_types=1);

function portal_client_admin(array $identity): bool
{
    return in_array($identity['customer_access']['role'] ?? $identity['role'] ?? '', ['client_owner', 'client_admin'], true);
}

function portal_ticket_actor(?array $identity, int $tenantId, int $clientId): array
{
    $role = $identity['customer_access']['role'] ?? $identity['role'] ?? '';
    if ($identity === null || ($identity['tenant_id'] ?? null) !== $tenantId
        || ($identity['client_id'] ?? null) !== $clientId
        || !in_array($role, PORTAL_CLIENT_ROLES, true)
        || !is_string($identity['subject'] ?? null)
        || strlen($identity['subject']) > 64
        || preg_match('/\At[1-9][0-9]*u[1-9][0-9]*\z/D', $identity['subject']) !== 1
        || !is_string($identity['identity_tenant_slug'] ?? null)
        || preg_match(PORTAL_IDENTITY_TENANT_SLUG_PATTERN, $identity['identity_tenant_slug']) !== 1) {
        throw new PortalDataForbiddenException('The signed-in requester boundary is unavailable.');
    }
    return $identity;
}

/** SQL and parameters are kept together; no caller can omit the requester filter. */
function portal_ticket_visibility(array $identity): array
{
    if (portal_client_admin($identity)) return ['sql' => '', 'params' => []];
    return ['sql' => ' AND EXISTS (SELECT 1 FROM portal_ticket_requesters requester
        WHERE requester.ticket_id = t.id AND requester.tenant_id = t.tenant_id
          AND requester.client_id = t.client_id AND requester.subject = :requester_subject
          AND requester.identity_tenant_slug = :requester_identity_tenant_slug)',
        'params' => ['requester_subject' => $identity['subject'],
            'requester_identity_tenant_slug' => $identity['identity_tenant_slug']]];
}

function portal_require_client_admin(?array $identity, int $tenantId, int $clientId): void
{
    if (!portal_client_admin(portal_ticket_actor($identity, $tenantId, $clientId))) {
        throw new PortalDataForbiddenException('Client Admin access is required for service summaries.');
    }
}
