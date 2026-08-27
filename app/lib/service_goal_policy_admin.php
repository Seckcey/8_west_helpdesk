<?php
/**
 * Approval-grade publication of immutable service-goal policy versions.
 *
 * This module has no web or session dependency. Operator commands identify an
 * exact tenant slug and database user id; browser pages use only the small
 * client-tier authorization helpers at the bottom of this file.
 */
declare(strict_types=1);

require_once __DIR__ . '/service_goals.php';

const SERVICE_GOAL_POLICY_PLAN_CONTRACT = 'safeharbor.service-goal-policy-plan.v1';
const SERVICE_GOAL_POLICY_KEYS = ['standard', 'premium'];
const SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES = 525600;

class ServiceGoalPolicyException extends RuntimeException {}
class ServiceGoalPolicyValidationException extends ServiceGoalPolicyException {}
class ServiceGoalPolicyGateException extends ServiceGoalPolicyException {}
class ServiceGoalPolicyConflictException extends ServiceGoalPolicyException {}

function service_goal_policy_tenant_slug(string $slug): string
{
    if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $slug) !== 1) {
        throw new ServiceGoalPolicyValidationException('Tenant slug is invalid.');
    }
    return $slug;
}

function service_goal_policy_key(string $policyKey): string
{
    if (! in_array($policyKey, SERVICE_GOAL_POLICY_KEYS, true)) {
        throw new ServiceGoalPolicyValidationException('Policy key must be standard or premium.');
    }
    return $policyKey;
}

function service_goal_policy_display_name(string $policyKey): string
{
    return $policyKey === 'premium' ? 'Premium' : 'Standard';
}

function service_goal_policy_reason(string $reason): string
{
    if ($reason === ''
        || $reason !== trim($reason)
        || ! mb_check_encoding($reason, 'UTF-8')
        || mb_strlen($reason, 'UTF-8') > 500
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1
    ) {
        throw new ServiceGoalPolicyValidationException(
            'Publication reason must be 1-500 trimmed UTF-8 characters without control bytes.',
        );
    }
    return $reason;
}

/** @return array{iso:string,database:string,timestamp:int} */
function service_goal_policy_effective_at(string $value): array
{
    $utc = new DateTimeZone('UTC');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, $utc);
    $errors = DateTimeImmutable::getLastErrors();
    if (! $parsed instanceof DateTimeImmutable
        || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        || $parsed->format('Y-m-d\TH:i:s\Z') !== $value
    ) {
        throw new ServiceGoalPolicyValidationException(
            'Effective time must be exact UTC in YYYY-MM-DDTHH:MM:SSZ form.',
        );
    }
    return [
        'iso' => $value,
        'database' => $parsed->format('Y-m-d H:i:s'),
        'timestamp' => $parsed->getTimestamp(),
    ];
}

/** @param array<string,mixed> $targets
 *  @return array<string,int>
 */
function service_goal_policy_targets(array $targets): array
{
    $provided = array_keys($targets);
    sort($provided);
    $expected = SERVICE_GOAL_PRIORITIES;
    sort($expected);
    if ($provided !== $expected) {
        throw new ServiceGoalPolicyValidationException(
            'Exactly low, normal, high, and urgent response targets are required.',
        );
    }

    $normalized = [];
    foreach (SERVICE_GOAL_PRIORITIES as $priority) {
        $minutes = $targets[$priority];
        if (! is_int($minutes)
            || $minutes < 1
            || $minutes > SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES
        ) {
            throw new ServiceGoalPolicyValidationException(
                "{$priority} response minutes must be between 1 and "
                . SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES . '.',
            );
        }
        $normalized[$priority] = $minutes;
    }
    return $normalized;
}

function service_goal_policy_for_update(PDO $pdo, bool $forUpdate): string
{
    return $forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
        ? ' FOR UPDATE'
        : '';
}

/** @return array{id:int,slug:string,name:string} */
function service_goal_policy_tenant(PDO $pdo, string $tenantSlug, bool $forUpdate = false): array
{
    $tenantSlug = service_goal_policy_tenant_slug($tenantSlug);
    $query = $pdo->prepare(
        'SELECT id, slug, name FROM tenants WHERE slug = ?'
        . service_goal_policy_for_update($pdo, $forUpdate),
    );
    $query->execute([$tenantSlug]);
    $tenant = $query->fetch(PDO::FETCH_ASSOC);
    if (! is_array($tenant) || (string) $tenant['slug'] !== $tenantSlug) {
        throw new ServiceGoalPolicyGateException('The exact tenant was not found.');
    }
    return [
        'id' => (int) $tenant['id'],
        'slug' => (string) $tenant['slug'],
        'name' => (string) $tenant['name'],
    ];
}

/** @return array{id:int,tenant_id:int,role:string,full_name:string} */
function service_goal_policy_actor(
    PDO $pdo,
    int $tenantId,
    int $actorUserId,
    bool $forUpdate = false,
): array
{
    if ($tenantId < 1 || $actorUserId < 1) {
        throw new ServiceGoalPolicyValidationException('Tenant and actor ids must be positive.');
    }
    $query = $pdo->prepare(
        "SELECT id, tenant_id, role, full_name
           FROM users
          WHERE tenant_id = ? AND id = ? AND is_active = 1
            AND role IN ('owner','admin')"
        . service_goal_policy_for_update($pdo, $forUpdate),
    );
    $query->execute([$tenantId, $actorUserId]);
    $actor = $query->fetch(PDO::FETCH_ASSOC);
    if (! is_array($actor)) {
        throw new ServiceGoalPolicyGateException(
            'The publication actor must be an active owner or admin in the exact tenant.',
        );
    }
    return [
        'id' => (int) $actor['id'],
        'tenant_id' => (int) $actor['tenant_id'],
        'role' => (string) $actor['role'],
        'full_name' => (string) $actor['full_name'],
    ];
}

/** @return list<array<string,mixed>> */
function service_goal_policy_version_targets(PDO $pdo, int $tenantId, int $versionId): array
{
    $query = $pdo->prepare(
        "SELECT id, tenant_id, policy_version_id, priority,
                first_response_minutes, resolution_minutes
           FROM service_goal_policy_targets
          WHERE tenant_id = ? AND policy_version_id = ?
          ORDER BY CASE priority
              WHEN 'low' THEN 1 WHEN 'normal' THEN 2
              WHEN 'high' THEN 3 WHEN 'urgent' THEN 4 ELSE 5 END",
    );
    $query->execute([$tenantId, $versionId]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function service_goal_policy_latest(
    PDO $pdo,
    int $tenantId,
    string $policyKey,
    bool $forUpdate = false,
): ?array {
    $query = $pdo->prepare(
        'SELECT * FROM service_goal_policy_versions
          WHERE tenant_id = ? AND policy_key = ?
          ORDER BY version_no DESC LIMIT 1'
        . service_goal_policy_for_update($pdo, $forUpdate),
    );
    $query->execute([$tenantId, service_goal_policy_key($policyKey)]);
    $policy = $query->fetch(PDO::FETCH_ASSOC);
    if (! is_array($policy)) return null;
    $policy['targets'] = service_goal_policy_version_targets($pdo, $tenantId, (int) $policy['id']);
    return $policy;
}

/** Refuse to publish on top of malformed or incomplete immutable history. */
function service_goal_policy_verify_version(array $policy): void
{
    $policyKey = (string) ($policy['policy_key'] ?? '');
    if (! in_array($policyKey, SERVICE_GOAL_POLICY_KEYS, true)
        || (int) ($policy['version_no'] ?? 0) < 1
        || (string) ($policy['display_name'] ?? '') !== service_goal_policy_display_name($policyKey)
        || (string) ($policy['clock_mode'] ?? '') !== 'elapsed'
        || (string) ($policy['time_zone'] ?? '') !== 'UTC'
        || (string) ($policy['pause_mode'] ?? '') !== 'none'
    ) {
        throw new ServiceGoalPolicyGateException(
            'Current policy history is outside the supported elapsed/UTC/no-pause contract.',
        );
    }

    $targets = $policy['targets'] ?? null;
    if (! is_array($targets) || count($targets) !== count(SERVICE_GOAL_PRIORITIES)) {
        throw new ServiceGoalPolicyGateException('Current policy version does not have exactly four targets.');
    }
    foreach (SERVICE_GOAL_PRIORITIES as $index => $priority) {
        $target = $targets[$index] ?? null;
        if (! is_array($target)
            || (string) ($target['priority'] ?? '') !== $priority
            || (int) ($target['tenant_id'] ?? 0) !== (int) $policy['tenant_id']
            || (int) ($target['policy_version_id'] ?? 0) !== (int) $policy['id']
            || (int) ($target['first_response_minutes'] ?? 0) < 1
            || (int) ($target['first_response_minutes'] ?? 0)
                > SERVICE_GOAL_POLICY_MAX_RESPONSE_MINUTES
            || ($target['resolution_minutes'] ?? null) !== null
        ) {
            throw new ServiceGoalPolicyGateException('Current policy targets are incomplete or unsupported.');
        }
    }
}

function service_goal_policy_database_now(PDO $pdo): string
{
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? "SELECT datetime('now')"
        : 'SELECT UTC_TIMESTAMP()';
    $now = $pdo->query($sql)->fetchColumn();
    if (! is_string($now) || service_goal_timestamp($now) === null) {
        throw new ServiceGoalPolicyGateException('Could not read the database UTC clock.');
    }
    return $now;
}

/** @param array<string,mixed> $payload */
function service_goal_policy_canonical_json(array $payload): string
{
    return json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
}

/**
 * Build the exact immutable publication payload without writing anything.
 *
 * @param array<string,mixed> $targets
 * @return array{plan:array<string,mixed>,canonical_json:string,plan_sha256:string,current:array<string,mixed>}
 */
function service_goal_policy_plan(
    PDO $pdo,
    string $tenantSlug,
    string $policyKey,
    int $expectedCurrentVersion,
    string $effectiveAtUtc,
    array $targets,
    int $actorUserId,
    string $reason,
    bool $forUpdate = false,
): array {
    $policyKey = service_goal_policy_key($policyKey);
    if ($expectedCurrentVersion < 1 || $expectedCurrentVersion >= 65535) {
        throw new ServiceGoalPolicyValidationException(
            'Expected current version must leave room for the next SMALLINT version.',
        );
    }
    $effective = service_goal_policy_effective_at($effectiveAtUtc);
    $targets = service_goal_policy_targets($targets);
    $reason = service_goal_policy_reason($reason);
    $tenant = service_goal_policy_tenant($pdo, $tenantSlug, $forUpdate);
    $actor = service_goal_policy_actor($pdo, $tenant['id'], $actorUserId, $forUpdate);
    $current = service_goal_policy_latest($pdo, $tenant['id'], $policyKey, $forUpdate);
    if (! is_array($current)) {
        throw new ServiceGoalPolicyGateException(
            'The exact lazy v1 baseline must exist before a later version can be published.',
        );
    }
    service_goal_policy_verify_version($current);
    if ((int) $current['version_no'] !== $expectedCurrentVersion) {
        throw new ServiceGoalPolicyConflictException(
            'Policy version changed; inspect and plan again.',
        );
    }
    $nowTimestamp = service_goal_timestamp(service_goal_policy_database_now($pdo));
    $latestTimestamp = service_goal_timestamp((string) $current['effective_from']);
    if ($nowTimestamp === null
        || $latestTimestamp === null
        || $effective['timestamp'] <= $nowTimestamp
        || $effective['timestamp'] <= $latestTimestamp
    ) {
        throw new ServiceGoalPolicyValidationException(
            'Effective time must be later than both the database clock and the latest published version.',
        );
    }

    $targetRows = [];
    foreach (SERVICE_GOAL_PRIORITIES as $priority) {
        $targetRows[] = [
            'priority' => $priority,
            'first_response_minutes' => $targets[$priority],
            'resolution_minutes' => null,
        ];
    }
    $payload = [
        'contract' => SERVICE_GOAL_POLICY_PLAN_CONTRACT,
        'tenant' => ['id' => $tenant['id'], 'slug' => $tenant['slug']],
        'policy' => [
            'key' => $policyKey,
            'display_name' => service_goal_policy_display_name($policyKey),
            'expected_current_version' => $expectedCurrentVersion,
            'version_no' => $expectedCurrentVersion + 1,
            'effective_from_utc' => $effective['iso'],
            'clock_mode' => 'elapsed',
            'time_zone' => 'UTC',
            'pause_mode' => 'none',
            'targets' => $targetRows,
            'created_by_user_id' => $actor['id'],
            'reason' => $reason,
        ],
    ];
    $canonical = service_goal_policy_canonical_json($payload);
    return [
        'plan' => $payload,
        'canonical_json' => $canonical,
        'plan_sha256' => hash('sha256', $canonical),
        'current' => $current,
    ];
}

function service_goal_policy_duplicate(PDOException $error): bool
{
    return $error->getCode() === '23000'
        && (int) ($error->errorInfo[1] ?? 0) === 1062;
}

/**
 * Publish one version plus its exact four targets in one transaction.
 *
 * @param array<string,mixed> $targets
 * @return array{action:string,plan_sha256:string,policy:array<string,mixed>}
 */
function service_goal_policy_publish(
    PDO $pdo,
    string $tenantSlug,
    string $policyKey,
    int $expectedCurrentVersion,
    string $effectiveAtUtc,
    array $targets,
    int $actorUserId,
    string $reason,
    string $expectedPlanSha256,
): array {
    if (preg_match('/\A[0-9a-f]{64}\z/D', $expectedPlanSha256) !== 1) {
        throw new ServiceGoalPolicyValidationException('Plan SHA-256 must be 64 lowercase hexadecimal characters.');
    }
    if ($pdo->inTransaction()) {
        throw new ServiceGoalPolicyGateException('Policy publication requires its own database transaction.');
    }

    $pdo->beginTransaction();
    try {
        // Locking the exact tenant serializes Standard and Premium publishers
        // and closes the no-row race before the latest policy row is locked.
        $planned = service_goal_policy_plan(
            $pdo,
            $tenantSlug,
            $policyKey,
            $expectedCurrentVersion,
            $effectiveAtUtc,
            $targets,
            $actorUserId,
            $reason,
            true,
        );
        if (! hash_equals($planned['plan_sha256'], $expectedPlanSha256)) {
            throw new ServiceGoalPolicyConflictException(
                'Publication facts do not match the reviewed plan digest.',
            );
        }

        $policy = $planned['plan']['policy'];
        $effective = service_goal_policy_effective_at((string) $policy['effective_from_utc']);
        $insertPolicy = $pdo->prepare(
            'INSERT INTO service_goal_policy_versions
                (tenant_id, policy_key, version_no, display_name, effective_from,
                 clock_mode, time_zone, pause_mode, created_by_user_id, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $insertPolicy->execute([
            (int) $planned['plan']['tenant']['id'],
            (string) $policy['key'],
            (int) $policy['version_no'],
            (string) $policy['display_name'],
            $effective['database'],
            'elapsed',
            'UTC',
            'none',
            (int) $policy['created_by_user_id'],
            (string) $policy['reason'],
        ]);
        $versionId = (int) $pdo->lastInsertId();
        if ($versionId < 1) {
            throw new RuntimeException('Policy publication did not return a version id.');
        }

        $insertTarget = $pdo->prepare(
            'INSERT INTO service_goal_policy_targets
                (tenant_id, policy_version_id, priority,
                 first_response_minutes, resolution_minutes)
             VALUES (?,?,?,?,NULL)',
        );
        foreach ($policy['targets'] as $target) {
            $insertTarget->execute([
                (int) $planned['plan']['tenant']['id'],
                $versionId,
                (string) $target['priority'],
                (int) $target['first_response_minutes'],
            ]);
        }

        $read = $pdo->prepare(
            'SELECT * FROM service_goal_policy_versions WHERE tenant_id = ? AND id = ?',
        );
        $read->execute([(int) $planned['plan']['tenant']['id'], $versionId]);
        $persisted = $read->fetch(PDO::FETCH_ASSOC);
        if (! is_array($persisted)) {
            throw new RuntimeException('Published policy could not be re-read before commit.');
        }
        $persisted['targets'] = service_goal_policy_version_targets(
            $pdo,
            (int) $planned['plan']['tenant']['id'],
            $versionId,
        );
        service_goal_policy_verify_version($persisted);
        if ((int) $persisted['version_no'] !== (int) $policy['version_no']
            || (string) $persisted['effective_from'] !== $effective['database']
            || (int) ($persisted['created_by_user_id'] ?? 0) !== (int) $policy['created_by_user_id']
            || (string) ($persisted['reason'] ?? '') !== (string) $policy['reason']
        ) {
            throw new RuntimeException('Published policy did not re-read as the reviewed immutable plan.');
        }
        foreach ($policy['targets'] as $index => $target) {
            $stored = $persisted['targets'][$index] ?? [];
            if ((string) ($stored['priority'] ?? '') !== (string) $target['priority']
                || (int) ($stored['first_response_minutes'] ?? 0) !== (int) $target['first_response_minutes']
                || ($stored['resolution_minutes'] ?? null) !== null
            ) {
                throw new RuntimeException('Published target did not re-read as the reviewed immutable plan.');
            }
        }

        $pdo->commit();
        return [
            'action' => 'published',
            'plan_sha256' => $planned['plan_sha256'],
            'policy' => $persisted,
        ];
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (service_goal_policy_duplicate($error)) {
            throw new ServiceGoalPolicyConflictException(
                'Another publisher won; inspect and plan again.',
                0,
                $error,
            );
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** @return array{tenant:array{id:int,slug:string,name:string},policy_key:string,latest_version:int,versions:list<array<string,mixed>>} */
function service_goal_policy_inspect(PDO $pdo, string $tenantSlug, string $policyKey): array
{
    $tenant = service_goal_policy_tenant($pdo, $tenantSlug);
    $policyKey = service_goal_policy_key($policyKey);
    $query = $pdo->prepare(
        'SELECT * FROM service_goal_policy_versions
          WHERE tenant_id = ? AND policy_key = ? ORDER BY version_no',
    );
    $query->execute([$tenant['id'], $policyKey]);
    $versions = $query->fetchAll(PDO::FETCH_ASSOC);
    foreach ($versions as &$version) {
        $version['targets'] = service_goal_policy_version_targets(
            $pdo,
            $tenant['id'],
            (int) $version['id'],
        );
    }
    unset($version);
    return [
        'tenant' => $tenant,
        'policy_key' => $policyKey,
        'latest_version' => $versions === [] ? 0 : (int) end($versions)['version_no'],
        'versions' => $versions,
    ];
}

function service_goal_policy_can_manage_client_tier(array $user): bool
{
    return (int) ($user['is_active'] ?? 0) === 1
        && in_array((string) ($user['role'] ?? ''), ['owner', 'admin'], true);
}

function service_goal_policy_client_tier(
    array $user,
    mixed $requestedTier,
    string $currentTier = 'standard',
): string {
    $currentTier = in_array($currentTier, SERVICE_GOAL_POLICY_KEYS, true)
        ? $currentTier
        : 'standard';
    if (! service_goal_policy_can_manage_client_tier($user)) {
        return $currentTier;
    }
    return is_string($requestedTier) && in_array($requestedTier, SERVICE_GOAL_POLICY_KEYS, true)
        ? $requestedTier
        : $currentTier;
}
