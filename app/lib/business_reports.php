<?php
/**
 * Versioned, archived, delivery-tracked Safeharbor business reports.
 *
 * This module is deliberately separate from mail_queue: a Graph 202 means
 * Microsoft accepted a submission, not that a recipient received it. An
 * ambiguous transport outcome becomes terminal `uncertain` and is never
 * retried automatically.
 */
declare(strict_types=1);

const BUSINESS_REPORT_TYPE = 'weekly_client_service_summary';
const BUSINESS_REPORT_DEFINITION_KEY = 'weekly-client-service-summary';
const BUSINESS_REPORT_CONTRACT_VERSION_V1 = 1;
const BUSINESS_REPORT_CONTRACT_VERSION_V2 = 2;
const BUSINESS_REPORT_LATEST_CONTRACT_VERSION = BUSINESS_REPORT_CONTRACT_VERSION_V2;
// Retained for callers that explicitly mean the original immutable contract.
const BUSINESS_REPORT_CONTRACT_VERSION = BUSINESS_REPORT_CONTRACT_VERSION_V1;
const BUSINESS_REPORT_MAX_DUE_SCHEDULES = 100;
const BUSINESS_REPORT_CONTACT_SCOPE_MANUAL = 'MANUAL';
const BUSINESS_REPORT_CONTACT_SCOPE_TENANT = 'TENANT';
const BUSINESS_REPORT_CONTACT_SCOPE_CLIENT = 'CLIENT';
const BUSINESS_REPORT_ARCHIVE_MAX_COUNT = 4294967295;
const BUSINESS_REPORT_ARCHIVE_MAX_MINUTES = 4294967295;
const BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID =
    '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D';
const BUSINESS_REPORT_MASTER_CUSTOMER_ID = '4ebaeefa-b101-47f8-ac76-e49ab309d272';

class BusinessReportException extends RuntimeException {}
final class BusinessReportValidationException extends BusinessReportException {}
final class BusinessReportGateException extends BusinessReportException {}
final class BusinessReportConflictException extends BusinessReportException {}
final class BusinessReportTransportException extends BusinessReportException {}

/** @return 'acquired'|'contended' */
function business_report_advisory_lock_state(mixed $result): string
{
    if ($result === 1 || $result === '1') return 'acquired';
    if ($result === 0 || $result === '0') return 'contended';
    throw new BusinessReportGateException('Business report advisory lock failed.');
}

function business_report_advisory_lock_release(mixed $result): void
{
    if ($result !== 1 && $result !== '1') {
        throw new BusinessReportGateException('Business report advisory lock release failed.');
    }
}

/** @return array<string,mixed> */
function business_report_contract_v1(): array
{
    return [
        'contract_version' => 1,
        'report_type' => BUSINESS_REPORT_TYPE,
        'window' => 'previous_complete_monday_sunday',
        'ticket_opened' => 'created_at_in_window_excluding_merged_sources',
        'ticket_resolved' => 'resolved_at_in_window_excluding_merged_sources',
        'first_response' => 'opened_cohort_first_tech_message_excluding_all_merged_histories',
        'service_goal' => 'versioned_opened_cohort_decided_by_generated_at_excluding_all_merged_histories',
        'service_goal_legacy' => 'unversioned_opened_cohort_reported_as_excluded_not_counted_as_attainment',
        'approved_billable_time' => 'worked_at_in_window_approved_by_generated_at_operational_not_financial_status',
        'csat' => 'survey_created_in_window_answered_by_generated_at',
        'delivery_truth' => 'provider_accepted_is_submitted_not_delivered',
    ];
}

/** @return array<string,mixed> */
function business_report_contract_v2(): array
{
    return [
        'contract_version' => 2,
        'report_type' => BUSINESS_REPORT_TYPE,
        'window' => 'previous_complete_monday_sunday',
        'ticket_opened' => 'created_at_in_window_excluding_merged_sources',
        'ticket_resolved' => 'resolved_at_in_window_excluding_merged_sources',
        'first_response' => 'opened_cohort_first_tech_message_excluding_all_merged_histories',
        'service_goal' => 'versioned_opened_cohort_decided_by_generated_at_excluding_all_merged_histories',
        'service_goal_legacy' => 'unversioned_opened_cohort_reported_as_excluded_not_counted_as_attainment',
        'approved_billable_time' => 'worked_at_in_window_approved_by_generated_at_latest_adjustment_with_id_at_or_below_tenant_lock_cutoff_and_created_by_generated_at_else_original_counted_once',
        'approved_time_adjustments' => 'tenant_lock_captured_adjustment_id_cutoff_original_effective_net_minutes_adjusted_entries_and_applied_slips_without_reasons',
        'csat' => 'survey_created_in_window_answered_by_generated_at',
        'delivery_truth' => 'provider_accepted_is_submitted_not_delivered',
    ];
}

/** @return array<string,mixed> */
function business_report_contract(int $version = BUSINESS_REPORT_CONTRACT_VERSION_V1): array
{
    return match ($version) {
        BUSINESS_REPORT_CONTRACT_VERSION_V1 => business_report_contract_v1(),
        BUSINESS_REPORT_CONTRACT_VERSION_V2 => business_report_contract_v2(),
        default => throw new BusinessReportValidationException(
            'Business report definition version is unsupported.',
        ),
    };
}

function business_report_contract_json(
    int $version = BUSINESS_REPORT_CONTRACT_VERSION_V1,
): string
{
    return json_encode(
        business_report_contract($version),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
}

function business_report_contract_sha256(
    int $version = BUSINESS_REPORT_CONTRACT_VERSION_V1,
): string
{
    return hash('sha256', business_report_contract_json($version));
}

/** @param array<string,mixed> $definition */
function business_report_definition_supported(array $definition): bool
{
    $version = (int) ($definition['definition_version_no'] ?? $definition['version_no'] ?? 0);
    if (!in_array($version, [
        BUSINESS_REPORT_CONTRACT_VERSION_V1,
        BUSINESS_REPORT_CONTRACT_VERSION_V2,
    ], true)) {
        return false;
    }
    $expectedHash = business_report_contract_sha256($version);
    $contractJson = $definition['contract_json'] ?? null;
    $contractHash = $definition['contract_sha256'] ?? null;
    return ($definition['definition_key'] ?? null) === BUSINESS_REPORT_DEFINITION_KEY
        && ($definition['report_type'] ?? null) === BUSINESS_REPORT_TYPE
        && is_string($contractJson)
        && is_string($contractHash)
        && hash_equals($expectedHash, $contractHash)
        && hash_equals($expectedHash, hash('sha256', $contractJson));
}

/** @return array<string,mixed> */
function business_report_config(?array $source = null): array
{
    if ($source === null) {
        $source = function_exists('cfg') ? cfg('business_reports', []) : [];
    }
    if (!is_array($source)) {
        throw new BusinessReportValidationException('Business report configuration is invalid.');
    }

    $leaseSeconds = $source['lease_seconds'] ?? 120;
    if (!is_int($leaseSeconds) || $leaseSeconds < 30 || $leaseSeconds > 300) {
        throw new BusinessReportValidationException('Business report lease must be 30 through 300 seconds.');
    }

    return [
        'generation_enabled' => ($source['generation_enabled'] ?? false) === true,
        'delivery_enabled' => ($source['delivery_enabled'] ?? false) === true,
        'canary_only' => ($source['canary_only'] ?? true) !== false,
        'graph_sender' => business_report_graph_sender($source['graph_sender'] ?? ''),
        'schedule_keys' => business_report_schedule_allowlist($source['schedule_keys'] ?? []),
        'tenant_slugs' => business_report_tenant_allowlist($source['tenant_slugs'] ?? []),
        'client_keys' => business_report_client_allowlist($source['client_keys'] ?? []),
        'recipient_emails' => business_report_email_allowlist($source['recipient_emails'] ?? []),
        'lease_seconds' => $leaseSeconds,
    ];
}

/** @return list<string> */
function business_report_allowlist(mixed $values, int $maximum, string $label): array
{
    if (!is_array($values) || !array_is_list($values)) {
        throw new BusinessReportValidationException("Business report {$label} allowlist is invalid.");
    }
    $result = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            throw new BusinessReportValidationException("Business report {$label} allowlist is invalid.");
        }
        $value = business_report_key($value, $maximum, ucfirst($label));
        if (in_array($value, $result, true)) {
            throw new BusinessReportValidationException("Business report {$label} allowlist contains a duplicate.");
        }
        $result[] = $value;
    }
    return $result;
}

/** @return list<string> */
function business_report_email_allowlist(mixed $values): array
{
    if (!is_array($values) || !array_is_list($values)) {
        throw new BusinessReportValidationException('Business report recipient allowlist is invalid.');
    }
    $result = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            throw new BusinessReportValidationException('Business report recipient allowlist is invalid.');
        }
        $email = business_report_email($value);
        if (in_array($email, $result, true)) {
            throw new BusinessReportValidationException('Business report recipient allowlist contains a duplicate.');
        }
        $result[] = $email;
    }
    return $result;
}

/** @return list<string> */
function business_report_tenant_allowlist(mixed $values): array
{
    $slugs = business_report_allowlist($values, 64, 'tenant slug');
    foreach ($slugs as $slug) business_report_slug($slug);
    return $slugs;
}

/** @return list<string> */
function business_report_client_allowlist(mixed $values): array
{
    $keys = business_report_allowlist($values, 128, 'client key');
    foreach ($keys as $key) {
        if (preg_match('/\Asafeharbor-client:[1-9][0-9]*\z/D', $key) !== 1) {
            throw new BusinessReportValidationException('Business report client key allowlist is invalid.');
        }
    }
    return $keys;
}

/** @return list<string> */
function business_report_schedule_allowlist(mixed $values): array
{
    $keys = business_report_allowlist($values, 64, 'schedule key');
    foreach ($keys as $key) business_report_schedule_key($key);
    return $keys;
}

function business_report_key(string $value, int $maximum, string $label): string
{
    if ($value === ''
        || $value !== trim($value)
        || !mb_check_encoding($value, 'UTF-8')
        || mb_strlen($value, 'UTF-8') > $maximum
        || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
    ) {
        throw new BusinessReportValidationException("{$label} is invalid.");
    }
    return $value;
}

function business_report_slug(string $value): string
{
    if (preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $value) !== 1) {
        throw new BusinessReportValidationException('Tenant slug is invalid.');
    }
    return $value;
}

function business_report_schedule_key(string $value): string
{
    if (preg_match('/\A[a-z0-9][a-z0-9._:-]{0,63}\z/D', $value) !== 1) {
        throw new BusinessReportValidationException('Report schedule key is invalid.');
    }
    return $value;
}

function business_report_email(string $value): string
{
    $email = strtolower(trim($value));
    if ($email !== $value
        || strlen($email) > 190
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new BusinessReportValidationException('Report recipient email must be normalized lowercase email.');
    }
    return $email;
}

function business_report_graph_sender(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) {
        throw new BusinessReportValidationException('Graph report sender configuration is invalid.');
    }
    $sender = strtolower(trim($value));
    if ($sender !== $value
        || strlen($sender) > 190
        || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new BusinessReportValidationException(
            'Graph report sender must be a normalized lowercase email address.',
        );
    }
    return $sender;
}

function business_report_timezone(string $value): string
{
    if ($value === '' || strlen($value) > 64 || !in_array($value, timezone_identifiers_list(), true)) {
        throw new BusinessReportValidationException('Report schedule timezone is invalid.');
    }
    return $value;
}

function business_report_time(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('H:i:s') !== $value
    ) {
        throw new BusinessReportValidationException('Report schedule local time is invalid.');
    }
    return $value;
}

function business_report_utc(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d H:i:s') !== $value
    ) {
        throw new BusinessReportValidationException("{$label} must be an exact UTC database timestamp.");
    }
    return $value;
}

/**
 * Choose the persisted archive cutoff only after the tenant lock is held.
 *
 * Live MySQL generation is anchored to the database UTC clock. An explicit
 * later clock remains available for deterministic future-window integration
 * tests, but an earlier caller value can never backdate the archive. SQLite is
 * the hermetic contract-test driver and retains its explicit deterministic
 * clock because it has no production concurrency role.
 */
function business_report_persisted_generated_at(PDO $pdo, ?int $requestedNow): string
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($driver === 'mysql') {
        $databaseNow = $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
    } elseif ($driver === 'sqlite') {
        if ($requestedNow !== null) {
            return business_report_utc(
                gmdate('Y-m-d H:i:s', $requestedNow),
                'Generated at',
            );
        }
        $databaseNow = $pdo->query("SELECT strftime('%Y-%m-%d %H:%M:%S','now')")
            ->fetchColumn();
    } else {
        throw new BusinessReportGateException('Business report database clock is unsupported.');
    }

    if (!is_string($databaseNow)) {
        throw new BusinessReportGateException('Business report database UTC clock is unavailable.');
    }
    $databaseNow = business_report_utc($databaseNow, 'Database UTC clock');
    if ($requestedNow === null) {
        return $databaseNow;
    }
    $requestedAt = business_report_utc(
        gmdate('Y-m-d H:i:s', $requestedNow),
        'Generated at',
    );
    return $requestedAt > $databaseNow ? $requestedAt : $databaseNow;
}

/**
 * Capture the committed tenant adjustment prefix. Persisted generation requests
 * a current locking read while holding the tenant row lock shared with writers,
 * so a same-second later adjustment cannot move into the archived prefix. A dry
 * run uses the default unlocked read as best-effort preview evidence only.
 */
function business_report_adjustment_id_cutoff(
    PDO $pdo,
    int $tenantId,
    bool $currentLockingRead = false,
): int
{
    if ($tenantId < 1) {
        throw new BusinessReportValidationException('Adjustment cutoff tenant id must be positive.');
    }
    $sql = 'SELECT id FROM time_entry_approval_adjustments
             WHERE tenant_id = ? ORDER BY id DESC LIMIT 1';
    if ($currentLockingRead && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR SHARE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([$tenantId]);
    $value = $query->fetchColumn();
    if ($value === false) return 0;
    if (!is_int($value) && !is_string($value)) {
        throw new BusinessReportGateException('The approved-time adjustment cutoff is unavailable.');
    }
    $text = (string) $value;
    if (preg_match('/\A[0-9]+\z/D', $text) !== 1) {
        throw new BusinessReportGateException('The approved-time adjustment cutoff is invalid.');
    }
    $normalized = ltrim($text, '0');
    if ($normalized === '') $normalized = '0';
    $maximum = (string) PHP_INT_MAX;
    if (strlen($normalized) > strlen($maximum)
        || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
    ) {
        throw new BusinessReportGateException('The approved-time adjustment cutoff exceeds runtime capacity.');
    }
    return (int) $normalized;
}

/** @return array{period_start:string,period_end:string,due_at:string,timezone:string} */
function business_report_previous_week_window(
    int $now,
    string $timezone,
    int $deliveryWeekday = 3,
    string $deliveryLocalTime = '09:00:00',
): array {
    if ($now < 1_000_000_000 || $now > 9_999_999_999) {
        throw new BusinessReportValidationException('Report clock is invalid.');
    }
    $timezone = business_report_timezone($timezone);
    if ($deliveryWeekday < 1 || $deliveryWeekday > 7) {
        throw new BusinessReportValidationException('Report delivery weekday is invalid.');
    }
    $deliveryLocalTime = business_report_time($deliveryLocalTime);
    $zone = new DateTimeZone($timezone);
    $localNow = (new DateTimeImmutable('@' . $now))->setTimezone($zone);
    $currentMonday = $localNow->modify('monday this week')->setTime(0, 0, 0);
    $periodStart = $currentMonday->modify('-7 days');
    [$hour, $minute, $second] = array_map('intval', explode(':', $deliveryLocalTime));
    $dueAt = $currentMonday->modify('+' . ($deliveryWeekday - 1) . ' days')->setTime($hour, $minute, $second);

    return [
        'period_start' => $periodStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'period_end' => $currentMonday->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'due_at' => $dueAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'timezone' => $timezone,
    ];
}

/** @return array{period_start:string,period_end:string,due_at:string,timezone:string} */
function business_report_window_from_start(
    string $periodStartUtc,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
): array {
    $periodStartUtc = business_report_utc($periodStartUtc, 'Report period start');
    $timezone = business_report_timezone($timezone);
    if ($deliveryWeekday < 1 || $deliveryWeekday > 7) {
        throw new BusinessReportValidationException('Report delivery weekday is invalid.');
    }
    $deliveryLocalTime = business_report_time($deliveryLocalTime);
    $zone = new DateTimeZone($timezone);
    $localStart = (new DateTimeImmutable($periodStartUtc, new DateTimeZone('UTC')))->setTimezone($zone);
    if ($localStart->format('N H:i:s') !== '1 00:00:00') {
        throw new BusinessReportConflictException('Archived report periods must begin at local Monday midnight.');
    }
    $localEnd = $localStart->modify('+7 days');
    [$hour, $minute, $second] = array_map('intval', explode(':', $deliveryLocalTime));
    $dueAt = $localEnd->modify('+' . ($deliveryWeekday - 1) . ' days')->setTime($hour, $minute, $second);
    return [
        'period_start' => $localStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'period_end' => $localEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'due_at' => $dueAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        'timezone' => $timezone,
    ];
}

/** @return array{period_start:string,period_end:string,due_at:string,timezone:string} */
function business_report_next_window(PDO $pdo, array $schedule): array
{
    $timezone = (string)$schedule['schedule_timezone'];
    $deliveryWeekday = (int)$schedule['delivery_weekday'];
    $deliveryLocalTime = (string)$schedule['delivery_local_time'];
    $activatedAt = business_report_utc((string)$schedule['created_at'], 'Schedule activation');
    $activatedTimestamp = strtotime($activatedAt . ' UTC');
    if ($activatedTimestamp === false) {
        throw new BusinessReportConflictException('The report schedule activation time is invalid.');
    }
    $first = business_report_previous_week_window(
        $activatedTimestamp,
        $timezone,
        $deliveryWeekday,
        $deliveryLocalTime,
    );
    if ($activatedAt > $first['due_at']) {
        $first = business_report_window_from_start(
            $first['period_end'],
            $timezone,
            $deliveryWeekday,
            $deliveryLocalTime,
        );
    }

    $latest = $pdo->prepare(
        'SELECT period_end FROM business_report_archives
          WHERE tenant_id = ? AND schedule_key = ?
          ORDER BY period_end DESC LIMIT 1'
    );
    $latest->execute([(int)$schedule['tenant_id'], (string)$schedule['schedule_key']]);
    $latestEnd = $latest->fetchColumn();
    if ($latestEnd === false) {
        return $first;
    }
    $afterLatest = business_report_window_from_start(
        (string)$latestEnd,
        $timezone,
        $deliveryWeekday,
        $deliveryLocalTime,
    );
    return $afterLatest['period_start'] >= $first['period_start'] ? $afterLatest : $first;
}

/** @return array<string,mixed> */
function business_report_actor(PDO $pdo, string $tenantSlug, int $actorUserId): array
{
    business_report_slug($tenantSlug);
    if ($actorUserId < 1) {
        throw new BusinessReportValidationException('Actor user id must be positive.');
    }
    $query = $pdo->prepare(
        "SELECT t.id AS tenant_id, t.slug AS tenant_slug, u.id AS actor_user_id, u.role
           FROM tenants t
           JOIN users u ON u.tenant_id = t.id
          WHERE t.slug = ? AND u.id = ? AND u.is_active = 1
            AND u.role IN ('owner','admin')"
    );
    $query->execute([$tenantSlug, $actorUserId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new BusinessReportGateException('An active owner or admin in the exact tenant is required.');
    }
    return $row;
}

/** @return array{action:string,definition:array<string,mixed>} */
function business_report_publish_definition(
    PDO $pdo,
    string $tenantSlug,
    int $actorUserId,
    string $reason,
    int $definitionVersion = BUSINESS_REPORT_CONTRACT_VERSION_V1,
): array {
    $actor = business_report_actor($pdo, $tenantSlug, $actorUserId);
    $reason = business_report_key($reason, 500, 'Definition reason');
    business_report_contract($definitionVersion);
    $tenantId = (int)$actor['tenant_id'];
    $hash = business_report_contract_sha256($definitionVersion);
    $existing = $pdo->prepare(
        'SELECT * FROM business_report_definition_versions
          WHERE tenant_id = ? AND definition_key = ? AND version_no = ?'
    );
    $existing->execute([$tenantId, BUSINESS_REPORT_DEFINITION_KEY, $definitionVersion]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        if (business_report_definition_supported($row)) {
            return ['action' => 'ignored', 'definition' => $row];
        }
        throw new BusinessReportConflictException('A different report definition version already exists.');
    }

    $latest = $pdo->prepare(
        'SELECT version_no FROM business_report_definition_versions
          WHERE tenant_id = ? AND definition_key = ?
          ORDER BY version_no DESC LIMIT 1'
    );
    $latest->execute([$tenantId, BUSINESS_REPORT_DEFINITION_KEY]);
    $latestVersion = $latest->fetchColumn();
    $expectedVersion = $latestVersion === false ? 1 : (int) $latestVersion + 1;
    if ($definitionVersion !== $expectedVersion) {
        throw new BusinessReportConflictException(
            'Business report definitions must be published in exact version order.',
        );
    }

    $insert = $pdo->prepare(
        'INSERT INTO business_report_definition_versions
            (tenant_id, definition_key, version_no, report_type, contract_json,
             contract_sha256, created_by_user_id, reason)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $tenantId,
        BUSINESS_REPORT_DEFINITION_KEY,
        $definitionVersion,
        BUSINESS_REPORT_TYPE,
        business_report_contract_json($definitionVersion),
        $hash,
        $actorUserId,
        $reason,
    ]);
    $id = (int)$pdo->lastInsertId();
    $query = $pdo->prepare('SELECT * FROM business_report_definition_versions WHERE tenant_id = ? AND id = ?');
    $query->execute([$tenantId, $id]);
    return ['action' => 'created', 'definition' => $query->fetch(PDO::FETCH_ASSOC) ?: []];
}

/** @return array<string,mixed> */
function business_report_schedule_target(
    PDO $pdo,
    string $tenantSlug,
    int $clientId,
    int $definitionId,
    int $actorUserId,
): array {
    $actor = business_report_actor($pdo, $tenantSlug, $actorUserId);
    if ($clientId < 1 || $definitionId < 1) {
        throw new BusinessReportValidationException('Client and definition ids must be positive.');
    }
    $query = $pdo->prepare(
        'SELECT t.id AS tenant_id, t.slug AS tenant_slug, c.id AS client_id,
                c.name AS client_name, d.id AS definition_version_id,
                d.definition_key, d.version_no AS definition_version_no,
                d.report_type, d.contract_json, d.contract_sha256
           FROM tenants t
           JOIN clients c ON c.tenant_id = t.id AND c.id = ?
           JOIN business_report_definition_versions d ON d.tenant_id = t.id AND d.id = ?
          WHERE t.id = ?'
    );
    $query->execute([$clientId, $definitionId, (int)$actor['tenant_id']]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !business_report_definition_supported($row)) {
        throw new BusinessReportGateException('The client and supported report definition must belong to the exact tenant.');
    }
    return $row;
}

/** @return array<string,mixed>|null */
function business_report_latest_schedule(PDO $pdo, int $tenantId, string $scheduleKey, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM business_report_schedule_versions
             WHERE tenant_id = ? AND schedule_key = ?
             ORDER BY version_no DESC LIMIT 1';
    if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([$tenantId, $scheduleKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * The wipe-sensitive client-id contact command is retained only to refresh an
 * existing client-scoped schedule. It must never create a new logical schedule
 * or convert tenant/manual history into the legacy client lane.
 *
 * @return array<string,mixed>
 */
function business_report_legacy_client_refresh_target(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    int $actorUserId,
): array {
    $scheduleKey = business_report_schedule_key($scheduleKey);
    $target = business_report_schedule_target(
        $pdo,
        $tenantSlug,
        $clientId,
        $definitionId,
        $actorUserId,
    );
    $latest = business_report_latest_schedule(
        $pdo,
        (int)$target['tenant_id'],
        $scheduleKey,
    );
    if (!is_array($latest)
        || (int)($latest['client_id'] ?? 0) !== $clientId
        || (int)($latest['definition_version_id'] ?? 0) !== $definitionId
    ) {
        throw new BusinessReportGateException(
            'Legacy client-id contact refresh requires exact existing schedule history; '
            . 'use stable customer onboarding for a new managed customer.',
        );
    }
    $contactScope = business_report_contact_scope_for_key(
        $pdo,
        (int)$target['tenant_id'],
        $scheduleKey,
    );
    if (!is_array($contactScope)
        || !hash_equals(BUSINESS_REPORT_CONTACT_SCOPE_CLIENT, (string)$contactScope['scope'])
    ) {
        throw new BusinessReportGateException(
            'Legacy client-id contact refresh requires existing client-scoped ID evidence.',
        );
    }
    return $target;
}

/** @return array<string,mixed>|null */
function business_report_id_contact_for_schedule(
    PDO $pdo,
    int $tenantId,
    int $scheduleVersionId,
    bool $forUpdate = false,
): ?array {
    $sql = 'SELECT * FROM business_report_id_contact_snapshots
             WHERE tenant_id = ? AND schedule_version_id = ? LIMIT 1';
    if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([$tenantId, $scheduleVersionId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function business_report_id_client_contact_for_schedule(
    PDO $pdo,
    int $tenantId,
    int $scheduleVersionId,
    bool $forUpdate = false,
): ?array {
    $sql = 'SELECT * FROM business_report_id_client_contact_snapshots
             WHERE tenant_id = ? AND schedule_version_id = ? LIMIT 1';
    if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $query = $pdo->prepare($sql);
    $query->execute([$tenantId, $scheduleVersionId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function business_report_latest_id_contact_for_key(
    PDO $pdo,
    int $tenantId,
    string $scheduleKey,
): ?array {
    $query = $pdo->prepare(
        'SELECT evidence.*
           FROM business_report_id_contact_snapshots evidence
           JOIN business_report_schedule_versions schedule
             ON schedule.tenant_id = evidence.tenant_id
            AND schedule.id = evidence.schedule_version_id
          WHERE evidence.tenant_id = ? AND schedule.schedule_key = ?
          ORDER BY schedule.version_no DESC LIMIT 1'
    );
    $query->execute([$tenantId, $scheduleKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function business_report_latest_id_client_contact_for_key(
    PDO $pdo,
    int $tenantId,
    string $scheduleKey,
): ?array {
    $query = $pdo->prepare(
        'SELECT evidence.*
           FROM business_report_id_client_contact_snapshots evidence
           JOIN business_report_schedule_versions schedule
             ON schedule.tenant_id = evidence.tenant_id
            AND schedule.id = evidence.schedule_version_id
          WHERE evidence.tenant_id = ? AND schedule.schedule_key = ?
          ORDER BY schedule.version_no DESC LIMIT 1'
    );
    $query->execute([$tenantId, $scheduleKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Return the one contact scope inherited by the complete logical schedule
 * history. Enable/disable versions intentionally carry no duplicate evidence,
 * so the newest evidence-bearing version remains authoritative.
 *
 * @return array{scope:string,evidence:array<string,mixed>|null}|null
 */
function business_report_contact_scope_for_key(
    PDO $pdo,
    int $tenantId,
    string $scheduleKey,
): ?array {
    $scheduleKey = business_report_schedule_key($scheduleKey);
    $history = $pdo->prepare(
        'SELECT COUNT(*) FROM business_report_schedule_versions
          WHERE tenant_id = ? AND schedule_key = ?'
    );
    $history->execute([$tenantId, $scheduleKey]);
    $historyCount = (int)$history->fetchColumn();

    $scopeQuery = $pdo->prepare(
        'SELECT contact_scope FROM business_report_contact_scope_bindings
          WHERE tenant_id = ? AND schedule_key = ?'
    );
    $scopeQuery->execute([$tenantId, $scheduleKey]);
    $scope = $scopeQuery->fetchColumn();
    if ($historyCount === 0 && $scope === false) return null;
    if ($historyCount === 0 || !is_string($scope)) {
        throw new BusinessReportGateException(
            'The report schedule contact-scope registry does not match its history.',
        );
    }

    $tenantEvidence = business_report_latest_id_contact_for_key($pdo, $tenantId, $scheduleKey);
    $clientEvidence = business_report_latest_id_client_contact_for_key($pdo, $tenantId, $scheduleKey);
    if ($scope === BUSINESS_REPORT_CONTACT_SCOPE_MANUAL) {
        if (is_array($tenantEvidence) || is_array($clientEvidence)) {
            throw new BusinessReportGateException(
                'The manual report contact scope has unexpected ID evidence.',
            );
        }
        return ['scope' => $scope, 'evidence' => null];
    }
    if ($scope === BUSINESS_REPORT_CONTACT_SCOPE_TENANT
        && is_array($tenantEvidence)
        && !is_array($clientEvidence)
    ) {
        return ['scope' => $scope, 'evidence' => $tenantEvidence];
    }
    if ($scope === BUSINESS_REPORT_CONTACT_SCOPE_CLIENT
        && is_array($clientEvidence)
        && !is_array($tenantEvidence)
    ) {
        return ['scope' => $scope, 'evidence' => $clientEvidence];
    }
    if (!in_array($scope, [
        BUSINESS_REPORT_CONTACT_SCOPE_MANUAL,
        BUSINESS_REPORT_CONTACT_SCOPE_TENANT,
        BUSINESS_REPORT_CONTACT_SCOPE_CLIENT,
    ], true)) {
        throw new BusinessReportGateException(
            'The report schedule contact scope is invalid.',
        );
    }
    throw new BusinessReportGateException(
        'The report schedule contact scope does not match its immutable evidence.',
    );
}

/** @param array<string,mixed> $schedule */
function business_report_assert_schedule_contact_evidence(PDO $pdo, array $schedule): void
{
    $tenantId = (int)($schedule['tenant_id'] ?? 0);
    $scheduleKey = business_report_schedule_key((string)($schedule['schedule_key'] ?? ''));
    $recipient = (string)($schedule['recipient_email'] ?? '');
    $clientId = (int)($schedule['client_id'] ?? 0);
    if ($tenantId < 1 || $clientId < 1 || $recipient === '') {
        throw new BusinessReportGateException('The report schedule contact evidence target is invalid.');
    }
    $contactScope = business_report_contact_scope_for_key($pdo, $tenantId, $scheduleKey);
    if (!is_array($contactScope)) {
        throw new BusinessReportGateException('The report schedule contact scope was not found.');
    }
    if ($contactScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_MANUAL) return;

    $evidence = $contactScope['evidence'] ?? null;
    if ($contactScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_TENANT
        && is_array($evidence)
        && (string)($evidence['recipient_email'] ?? '') === $recipient
    ) {
        return;
    }
    if ($contactScope['scope'] === BUSINESS_REPORT_CONTACT_SCOPE_CLIENT
        && is_array($evidence)
        && (int)($evidence['client_id'] ?? 0) === $clientId
        && (string)($evidence['recipient_email'] ?? '') === $recipient
    ) {
        return;
    }
    throw new BusinessReportGateException(
        'The report schedule recipient and client do not match the latest immutable ID contact evidence.',
    );
}

/**
 * Validate the authenticated, redaction-safe snapshot returned by the
 * operator-only 8 West ID client before it reaches a transaction.
 *
 * @return array{
 *   tenant_key:string,tenant_slug:string,contact_version:int,
 *   recipient_email:string,generated_at:string,generated_at_db:string,
 *   request_nonce_sha256:string,response_sha256:string
 * }
 */
function business_report_id_contact_validate(array $snapshot, ?string $expectedIdTenantSlug): array
{
    if ($expectedIdTenantSlug !== null) business_report_slug($expectedIdTenantSlug);
    $keys = array_keys($snapshot);
    sort($keys, SORT_STRING);
    $expected = [
        'contact_version', 'generated_at', 'generated_at_db', 'recipient_email',
        'request_nonce_sha256', 'response_sha256', 'tenant_key', 'tenant_slug',
    ];
    if ($keys !== $expected
        || !is_string($snapshot['tenant_key'] ?? null)
        || preg_match('/\Aewid-t[1-9][0-9]{0,9}\z/D', $snapshot['tenant_key']) !== 1
        || filter_var(substr($snapshot['tenant_key'], 6), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4_294_967_295],
        ]) === false
        || !is_string($snapshot['tenant_slug'] ?? null)
        || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $snapshot['tenant_slug']) !== 1
        || ($expectedIdTenantSlug !== null
            && $snapshot['tenant_slug'] !== $expectedIdTenantSlug)
        || !is_int($snapshot['contact_version'] ?? null)
        || $snapshot['contact_version'] < 1
        || !is_string($snapshot['recipient_email'] ?? null)
        || !is_string($snapshot['generated_at'] ?? null)
        || !is_string($snapshot['generated_at_db'] ?? null)
        || !is_string($snapshot['request_nonce_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $snapshot['request_nonce_sha256']) !== 1
        || !is_string($snapshot['response_sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $snapshot['response_sha256']) !== 1
    ) {
        throw new BusinessReportValidationException('8 West ID report-contact evidence is invalid.');
    }
    $recipient = business_report_email($snapshot['recipient_email']);
    if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $snapshot['generated_at']) !== 1) {
        throw new BusinessReportValidationException('8 West ID report-contact evidence time is invalid.');
    }
    $generated = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:s\Z',
        $snapshot['generated_at'],
        new DateTimeZone('UTC'),
    );
    if (!$generated
        || $generated->format('Y-m-d\TH:i:s\Z') !== $snapshot['generated_at']
        || $generated->format('Y-m-d H:i:s') !== $snapshot['generated_at_db']
    ) {
        throw new BusinessReportValidationException('8 West ID report-contact evidence time is invalid.');
    }
    return [
        'tenant_key' => $snapshot['tenant_key'],
        'tenant_slug' => $snapshot['tenant_slug'],
        'contact_version' => $snapshot['contact_version'],
        'recipient_email' => $recipient,
        'generated_at' => $snapshot['generated_at'],
        'generated_at_db' => $snapshot['generated_at_db'],
        'request_nonce_sha256' => $snapshot['request_nonce_sha256'],
        'response_sha256' => $snapshot['response_sha256'],
    ];
}

function business_report_same_prepared_schedule(
    array $schedule,
    int $clientId,
    int $definitionId,
    string $recipientEmail,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
): bool {
    return (string)$schedule['status'] === 'disabled'
        && (int)$schedule['client_id'] === $clientId
        && (int)$schedule['definition_version_id'] === $definitionId
        && (string)$schedule['recipient_email'] === $recipientEmail
        && (string)$schedule['schedule_timezone'] === $timezone
        && (int)$schedule['delivery_weekday'] === $deliveryWeekday
        && (string)$schedule['delivery_local_time'] === $deliveryLocalTime
        && (int)$schedule['canary'] === ($canary ? 1 : 0);
}

/**
 * Prove one managed-customer report preparation without writing report state.
 *
 * The 8 West ID contact is fetched by the operator CLI before this function is
 * called. This read-only plan then rechecks the exact Safeharbor actor, report
 * target, active Milepost customer UUID, existing contact binding/history, and
 * immutable schedule identity. A later prepare still locks and rechecks every
 * fact because this preview deliberately acquires no write lock.
 *
 * @return array{
 *   action:string,
 *   schedule:array<string,mixed>,
 *   id_contact:array<string,mixed>,
 *   customer_id_sha256:string
 * }
 */
function business_report_plan_customer_schedule_from_id(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    string $expectedCustomerId,
    array $idContact,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
    int $actorUserId,
    string $reason,
): array {
    $scheduleKey = business_report_schedule_key($scheduleKey);
    $timezone = business_report_timezone($timezone);
    $deliveryLocalTime = business_report_time($deliveryLocalTime);
    if ($deliveryWeekday < 1 || $deliveryWeekday > 7) {
        throw new BusinessReportValidationException('Report delivery weekday is invalid.');
    }
    business_report_key($reason, 500, 'Schedule reason');
    if (preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $expectedCustomerId) !== 1
        || hash_equals(BUSINESS_REPORT_MASTER_CUSTOMER_ID, $expectedCustomerId)
    ) {
        throw new BusinessReportValidationException(
            'A managed customer report scope requires an exact non-master Milepost customer id.',
        );
    }

    $validated = business_report_id_contact_validate($idContact, null);
    $target = business_report_schedule_target(
        $pdo,
        $tenantSlug,
        $clientId,
        $definitionId,
        $actorUserId,
    );
    $tenantId = (int)$target['tenant_id'];

    $customerBindingQuery = $pdo->prepare(
        'SELECT customer_id, status FROM suite_customer_sync_bindings
          WHERE tenant_id = ? AND client_id = ?'
    );
    $customerBindingQuery->execute([$tenantId, $clientId]);
    $customerBindings = $customerBindingQuery->fetchAll(PDO::FETCH_ASSOC);
    if (count($customerBindings) !== 1
        || !is_string($customerBindings[0]['customer_id'] ?? null)
        || !hash_equals($expectedCustomerId, $customerBindings[0]['customer_id'])
        || !hash_equals('active', (string)($customerBindings[0]['status'] ?? ''))
    ) {
        throw new BusinessReportGateException(
            'The active Milepost customer binding changed before report planning.',
        );
    }

    $contactScope = business_report_contact_scope_for_key($pdo, $tenantId, $scheduleKey);
    if (is_array($contactScope)
        && !hash_equals(BUSINESS_REPORT_CONTACT_SCOPE_CLIENT, (string)$contactScope['scope'])
    ) {
        throw new BusinessReportConflictException(
            'A report schedule key cannot change its contact scope.',
        );
    }

    $bindingQuery = $pdo->prepare(
        'SELECT * FROM business_report_id_client_bindings
          WHERE tenant_id = ? AND client_id = ?'
    );
    $bindingQuery->execute([$tenantId, $clientId]);
    $binding = $bindingQuery->fetch(PDO::FETCH_ASSOC);
    if (!is_array($binding)) {
        $clientKeyQuery = $pdo->prepare(
            'SELECT tenant_id, client_id FROM business_report_id_client_bindings
              WHERE id_tenant_key = ?'
        );
        $clientKeyQuery->execute([$validated['tenant_key']]);
        if ($clientKeyQuery->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new BusinessReportConflictException(
                'The 8 West ID tenant is already bound to a different Safeharbor client.',
            );
        }
        $tenantBindingQuery = $pdo->prepare(
            'SELECT tenant_id FROM business_report_id_tenant_bindings WHERE id_tenant_key = ?'
        );
        $tenantBindingQuery->execute([$validated['tenant_key']]);
        if ($tenantBindingQuery->fetchColumn() !== false) {
            throw new BusinessReportConflictException(
                'The 8 West ID tenant is already bound at Safeharbor tenant scope.',
            );
        }
    } elseif (!hash_equals((string)$binding['id_tenant_key'], $validated['tenant_key'])
        || !hash_equals((string)$binding['id_tenant_slug'], $validated['tenant_slug'])
    ) {
        throw new BusinessReportConflictException(
            'The Safeharbor client is already bound to a different 8 West ID tenant.',
        );
    }

    $contactHistoryQuery = $pdo->prepare(
        'SELECT contact_version, recipient_email
           FROM business_report_id_client_contact_snapshots
          WHERE tenant_id = ? AND client_id = ? AND id_tenant_key = ?
          ORDER BY contact_version DESC, id DESC LIMIT 1'
    );
    $contactHistoryQuery->execute([$tenantId, $clientId, $validated['tenant_key']]);
    $contactHistory = $contactHistoryQuery->fetch(PDO::FETCH_ASSOC);
    if (is_array($contactHistory)
        && (int)$validated['contact_version'] < (int)$contactHistory['contact_version']
    ) {
        throw new BusinessReportConflictException(
            'The 8 West ID report-contact version moved backward.',
        );
    }
    if (is_array($contactHistory)
        && (int)$validated['contact_version'] === (int)$contactHistory['contact_version']
        && !hash_equals((string)$contactHistory['recipient_email'], $validated['recipient_email'])
    ) {
        throw new BusinessReportConflictException(
            'The same 8 West ID report-contact version named a different recipient.',
        );
    }

    $latest = business_report_latest_schedule($pdo, $tenantId, $scheduleKey);
    if (is_array($latest)
        && (!is_array($contactScope) || !is_array($binding) || !is_array($contactHistory))
    ) {
        throw new BusinessReportGateException(
            'The existing managed-customer report schedule has incomplete contact evidence.',
        );
    }
    if (is_array($latest) && (string)$latest['status'] === 'active') {
        throw new BusinessReportConflictException(
            'Disable the active report schedule before reconfiguring it.',
        );
    }
    if (is_array($latest)
        && ((int)$latest['client_id'] !== $clientId
            || (int)$latest['definition_version_id'] !== $definitionId
            || (string)$latest['schedule_timezone'] !== $timezone)
    ) {
        throw new BusinessReportConflictException(
            'A report schedule key cannot change client, definition, or timezone.',
        );
    }

    $action = 'planned';
    $version = is_array($latest) ? (int)$latest['version_no'] + 1 : 1;
    $scheduleId = null;
    if (is_array($latest)
        && business_report_same_prepared_schedule(
            $latest,
            $clientId,
            $definitionId,
            $validated['recipient_email'],
            $timezone,
            $deliveryWeekday,
            $deliveryLocalTime,
            $canary,
        )
    ) {
        $latestEvidence = business_report_id_client_contact_for_schedule(
            $pdo,
            $tenantId,
            (int)$latest['id'],
        );
        if (is_array($latestEvidence)
            && hash_equals((string)$latestEvidence['id_tenant_key'], $validated['tenant_key'])
            && (int)$latestEvidence['contact_version'] === (int)$validated['contact_version']
            && hash_equals((string)$latestEvidence['recipient_email'], $validated['recipient_email'])
        ) {
            $action = 'ignored';
            $version = (int)$latest['version_no'];
            $scheduleId = (int)$latest['id'];
        }
    }

    return [
        'action' => $action,
        'schedule' => [
            'id' => $scheduleId,
            'tenant_id' => $tenantId,
            'schedule_key' => $scheduleKey,
            'version_no' => $version,
            'status' => 'disabled',
            'client_id' => $clientId,
            'definition_version_id' => $definitionId,
            'schedule_timezone' => $timezone,
            'delivery_weekday' => $deliveryWeekday,
            'delivery_local_time' => $deliveryLocalTime,
            'canary' => $canary ? 1 : 0,
            'recipient_sha256' => hash('sha256', $validated['recipient_email']),
        ],
        'id_contact' => [
            'client_id' => $clientId,
            'id_tenant_key' => $validated['tenant_key'],
            'contact_version' => (int)$validated['contact_version'],
            'response_sha256' => $validated['response_sha256'],
        ],
        'customer_id_sha256' => hash('sha256', $expectedCustomerId),
    ];
}

/** @return array{action:string,schedule:array<string,mixed>} */
function business_report_prepare_schedule(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    string $recipientEmail,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
    int $actorUserId,
    string $reason,
    ?array $idContact = null,
    ?int $idContactClientId = null,
    ?string $expectedCustomerId = null,
): array {
    $scheduleKey = business_report_schedule_key($scheduleKey);
    $recipientEmail = business_report_email($recipientEmail);
    $timezone = business_report_timezone($timezone);
    $deliveryLocalTime = business_report_time($deliveryLocalTime);
    if ($deliveryWeekday < 1 || $deliveryWeekday > 7) {
        throw new BusinessReportValidationException('Report delivery weekday is invalid.');
    }
    $reason = business_report_key($reason, 500, 'Schedule reason');
    if ($idContact === null && $idContactClientId !== null) {
        throw new BusinessReportValidationException('A client report-contact scope requires authenticated evidence.');
    }
    if ($expectedCustomerId !== null
        && ($idContact === null
            || $idContactClientId !== $clientId
            || preg_match(BUSINESS_REPORT_MILEPOST_CUSTOMER_UUID, $expectedCustomerId) !== 1
            || hash_equals(BUSINESS_REPORT_MASTER_CUSTOMER_ID, $expectedCustomerId))
    ) {
        throw new BusinessReportValidationException(
            'A managed customer report scope requires an exact non-master Milepost customer id.',
        );
    }
    if ($idContact !== null) {
        if ($idContactClientId !== null && $idContactClientId !== $clientId) {
            throw new BusinessReportValidationException(
                'The client report-contact evidence must target the exact schedule client.',
            );
        }
        $idContact = business_report_id_contact_validate(
            $idContact,
            $idContactClientId === null ? $tenantSlug : null,
        );
        if (!hash_equals($recipientEmail, $idContact['recipient_email'])) {
            throw new BusinessReportValidationException(
                'The report recipient does not match the authenticated 8 West ID snapshot.',
            );
        }
    }
    $target = business_report_schedule_target($pdo, $tenantSlug, $clientId, $definitionId, $actorUserId);
    $tenantId = (int)$target['tenant_id'];
    $id = 0;
    $action = 'prepared';
    $evidence = null;
    $requestedContactScope = $idContact === null
        ? BUSINESS_REPORT_CONTACT_SCOPE_MANUAL
        : ($idContactClientId === null
            ? BUSINESS_REPORT_CONTACT_SCOPE_TENANT
            : BUSINESS_REPORT_CONTACT_SCOPE_CLIENT);
    $pdo->beginTransaction();
    try {
        // Match the production customer-sync lock order: tenant first, then
        // managed binding. This prevents a report FK check and a concurrent
        // tenant→binding source update from waiting on each other in reverse.
        $tenantLockSql = 'SELECT id FROM tenants WHERE id = ? AND slug = ?';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $tenantLockSql .= ' FOR SHARE';
        }
        $tenantLock = $pdo->prepare($tenantLockSql);
        $tenantLock->execute([$tenantId, $tenantSlug]);
        if ((int)$tenantLock->fetchColumn() !== $tenantId) {
            throw new BusinessReportGateException(
                'The exact report tenant changed before schedule preparation.',
            );
        }

        // Serialize with the exact managed-customer source row (or its unique
        // insertion gap). This both closes active→inactive preparation races
        // and prevents a local-id/manual schedule from winning a race against
        // Milepost's first managed binding for the same client.
        $customerBindingSql =
            'SELECT customer_id, status FROM suite_customer_sync_bindings
              WHERE tenant_id = ? AND client_id = ?';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            // We never mutate Milepost-owned context. A shared row/gap lock is
            // enough to serialize source updates/inserts through this commit
            // and needs only read authority on supported MySQL 8 releases.
            $customerBindingSql .= ' FOR SHARE';
        }
        $customerBindingQuery = $pdo->prepare($customerBindingSql);
        $customerBindingQuery->execute([$tenantId, $clientId]);
        $customerBinding = $customerBindingQuery->fetch(PDO::FETCH_ASSOC);
        if ($expectedCustomerId !== null) {
            if (!is_array($customerBinding)
                || !is_string($customerBinding['customer_id'] ?? null)
                || !hash_equals($expectedCustomerId, $customerBinding['customer_id'])
                || !hash_equals('active', (string)($customerBinding['status'] ?? ''))
            ) {
                throw new BusinessReportGateException(
                    'The active Milepost customer binding changed before report preparation.',
                );
            }
        }

        // Lock the logical schedule after any managed-customer source row.
        // Every later enable, disable, or reconfiguration inherits the first
        // committed contact scope.
        $latest = business_report_latest_schedule($pdo, $tenantId, $scheduleKey, true);
        if (!is_array($latest)
            && $expectedCustomerId === null
            && is_array($customerBinding)
            && !(is_string($customerBinding['customer_id'] ?? null)
                && hash_equals(BUSINESS_REPORT_MASTER_CUSTOMER_ID, $customerBinding['customer_id'])
                && hash_equals(BUSINESS_REPORT_CONTACT_SCOPE_TENANT, $requestedContactScope))
        ) {
            throw new BusinessReportGateException(
                'A new managed-customer report schedule requires stable customer onboarding.',
            );
        }
        $contactScope = business_report_contact_scope_for_key($pdo, $tenantId, $scheduleKey);
        if (is_array($contactScope)) {
            if (!hash_equals((string)$contactScope['scope'], $requestedContactScope)) {
                throw new BusinessReportConflictException(
                    'A report schedule key cannot change its contact scope.',
                );
            }
        } else {
            $scopeInsert = $pdo->prepare(
                'INSERT INTO business_report_contact_scope_bindings
                    (tenant_id, schedule_key, contact_scope, created_by_user_id, reason)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $scopeInsert->execute([
                $tenantId,
                $scheduleKey,
                $requestedContactScope,
                $actorUserId,
                $reason,
            ]);
        }

        if ($idContact !== null) {
            // Serialize first binding, version monotonicity, and schedule
            // preparation for this local tenant. Migration 019 also enforces
            // the same logical contact scope below the application boundary.
            $clientScoped = $idContactClientId !== null;
            $bindingSql = $clientScoped
                ? 'SELECT * FROM business_report_id_client_bindings WHERE tenant_id = ? AND client_id = ?'
                : 'SELECT * FROM business_report_id_tenant_bindings WHERE tenant_id = ?';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $bindingSql .= ' FOR UPDATE';
            $bindingQuery = $pdo->prepare($bindingSql);
            $bindingQuery->execute($clientScoped ? [$tenantId, $clientId] : [$tenantId]);
            $binding = $bindingQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($binding)) {
                if ($clientScoped) {
                    $clientKeyQuery = $pdo->prepare(
                        'SELECT tenant_id, client_id FROM business_report_id_client_bindings
                          WHERE id_tenant_key = ?'
                        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE')
                    );
                    $clientKeyQuery->execute([$idContact['tenant_key']]);
                    if ($clientKeyQuery->fetch(PDO::FETCH_ASSOC) !== false) {
                        throw new BusinessReportConflictException(
                            'The 8 West ID tenant is already bound to a different Safeharbor client.',
                        );
                    }
                    $tenantBindingQuery = $pdo->prepare(
                        'SELECT tenant_id FROM business_report_id_tenant_bindings WHERE id_tenant_key = ?'
                        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE')
                    );
                    $tenantBindingQuery->execute([$idContact['tenant_key']]);
                    if ($tenantBindingQuery->fetchColumn() !== false) {
                        throw new BusinessReportConflictException(
                            'The 8 West ID tenant is already bound at Safeharbor tenant scope.',
                        );
                    }
                    $bindingInsert = $pdo->prepare(
                        'INSERT INTO business_report_id_client_bindings
                            (tenant_id, client_id, id_tenant_key, id_tenant_slug,
                             created_by_user_id, reason)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $bindingInsert->execute([
                        $tenantId,
                        $clientId,
                        $idContact['tenant_key'],
                        $idContact['tenant_slug'],
                        $actorUserId,
                        $reason,
                    ]);
                } else {
                    $clientBindingQuery = $pdo->prepare(
                        'SELECT tenant_id FROM business_report_id_client_bindings WHERE id_tenant_key = ?'
                        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE')
                    );
                    $clientBindingQuery->execute([$idContact['tenant_key']]);
                    if ($clientBindingQuery->fetchColumn() !== false) {
                        throw new BusinessReportConflictException(
                            'The 8 West ID tenant is already bound to a Safeharbor client.',
                        );
                    }
                    $bindingInsert = $pdo->prepare(
                        'INSERT INTO business_report_id_tenant_bindings
                            (tenant_id, id_tenant_key, id_tenant_slug,
                             created_by_user_id, reason)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $bindingInsert->execute([
                        $tenantId,
                        $idContact['tenant_key'],
                        $idContact['tenant_slug'],
                        $actorUserId,
                        $reason,
                    ]);
                }
            } elseif (!hash_equals((string)$binding['id_tenant_key'], $idContact['tenant_key'])
                || !hash_equals((string)$binding['id_tenant_slug'], $idContact['tenant_slug'])
            ) {
                throw new BusinessReportConflictException(
                    $clientScoped
                        ? 'The Safeharbor client is already bound to a different 8 West ID tenant.'
                        : 'The Safeharbor tenant is already bound to a different 8 West ID tenant.',
                );
            }

            $contactHistoryQuery = $pdo->prepare(
                'SELECT contact_version, recipient_email FROM '
                . ($clientScoped
                    ? 'business_report_id_client_contact_snapshots'
                    : 'business_report_id_contact_snapshots')
                . ' WHERE tenant_id = ?'
                . ($clientScoped ? ' AND client_id = ?' : '')
                . ' AND id_tenant_key = ?
                  ORDER BY contact_version DESC, id DESC LIMIT 1'
                . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE')
            );
            $contactHistoryQuery->execute(
                $clientScoped
                    ? [$tenantId, $clientId, $idContact['tenant_key']]
                    : [$tenantId, $idContact['tenant_key']],
            );
            $contactHistory = $contactHistoryQuery->fetch(PDO::FETCH_ASSOC);
            if (is_array($contactHistory)
                && (int)$idContact['contact_version'] < (int)$contactHistory['contact_version']
            ) {
                throw new BusinessReportConflictException(
                    'The 8 West ID report-contact version moved backward.',
                );
            }
            if (is_array($contactHistory)
                && (int)$idContact['contact_version'] === (int)$contactHistory['contact_version']
                && !hash_equals((string)$contactHistory['recipient_email'], $idContact['recipient_email'])
            ) {
                throw new BusinessReportConflictException(
                    'The same 8 West ID report-contact version named a different recipient.',
                );
            }
        }

        if (is_array($latest) && (string)$latest['status'] === 'active') {
            throw new BusinessReportConflictException('Disable the active report schedule before reconfiguring it.');
        }
        if (is_array($latest)
            && ((int)$latest['client_id'] !== $clientId
                || (int)$latest['definition_version_id'] !== $definitionId
                || (string)$latest['schedule_timezone'] !== $timezone)
        ) {
            throw new BusinessReportConflictException(
                'A report schedule key cannot change client, definition, or timezone.',
            );
        }
        if ($idContact !== null
            && is_array($latest)
            && business_report_same_prepared_schedule(
                $latest,
                $clientId,
                $definitionId,
                $recipientEmail,
                $timezone,
                $deliveryWeekday,
                $deliveryLocalTime,
                $canary,
            )
        ) {
            $latestEvidence = $idContactClientId === null
                ? business_report_id_contact_for_schedule(
                    $pdo,
                    $tenantId,
                    (int)$latest['id'],
                    true,
                )
                : business_report_id_client_contact_for_schedule(
                    $pdo,
                    $tenantId,
                    (int)$latest['id'],
                    true,
                );
            if (is_array($latestEvidence)
                && hash_equals((string)$latestEvidence['id_tenant_key'], $idContact['tenant_key'])
                && (int)$latestEvidence['contact_version'] === (int)$idContact['contact_version']
                && hash_equals((string)$latestEvidence['recipient_email'], $idContact['recipient_email'])
            ) {
                $id = (int)$latest['id'];
                $action = 'ignored';
                $evidence = $latestEvidence;
                $pdo->commit();
                return ['action' => $action, 'schedule' => $latest, 'id_contact' => $evidence];
            }
        }
        $version = is_array($latest) ? (int)$latest['version_no'] + 1 : 1;
        $insert = $pdo->prepare(
            "INSERT INTO business_report_schedule_versions
                (tenant_id, schedule_key, version_no, definition_version_id,
                 client_id, recipient_email, schedule_timezone, delivery_weekday,
                 delivery_local_time, canary, status, created_by_user_id, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?, 'disabled', ?,?)"
        );
        $insert->execute([
            $tenantId, $scheduleKey, $version, $definitionId, $clientId,
            $recipientEmail, $timezone, $deliveryWeekday, $deliveryLocalTime,
            $canary ? 1 : 0, $actorUserId, $reason,
        ]);
        $id = (int)$pdo->lastInsertId();
        if ($idContact !== null) {
            $clientScoped = $idContactClientId !== null;
            $evidenceInsert = $pdo->prepare(
                'INSERT INTO '
                . ($clientScoped
                    ? 'business_report_id_client_contact_snapshots'
                    : 'business_report_id_contact_snapshots')
                . ' (tenant_id, '
                . ($clientScoped ? 'client_id, ' : '')
                . 'schedule_version_id, id_tenant_key,
                    contact_version, recipient_email, response_generated_at,
                    request_nonce_sha256, response_sha256,
                    created_by_user_id, reason)
                 VALUES (' . ($clientScoped ? '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' : '?, ?, ?, ?, ?, ?, ?, ?, ?, ?') . ')'
            );
            $evidenceValues = [
                $tenantId,
                $id,
                $idContact['tenant_key'],
                $idContact['contact_version'],
                $idContact['recipient_email'],
                $idContact['generated_at_db'],
                $idContact['request_nonce_sha256'],
                $idContact['response_sha256'],
                $actorUserId,
                $reason,
            ];
            if ($clientScoped) array_splice($evidenceValues, 1, 0, [$clientId]);
            $evidenceInsert->execute($evidenceValues);
            $evidenceId = (int)$pdo->lastInsertId();
            $evidenceQuery = $pdo->prepare(
                'SELECT * FROM '
                . ($clientScoped
                    ? 'business_report_id_client_contact_snapshots'
                    : 'business_report_id_contact_snapshots')
                . '
                  WHERE tenant_id = ? AND id = ?'
            );
            $evidenceQuery->execute([$tenantId, $evidenceId]);
            $evidence = $evidenceQuery->fetch(PDO::FETCH_ASSOC);
            if (!is_array($evidence)) {
                throw new BusinessReportGateException(
                    'The immutable 8 West ID report-contact evidence was not created.',
                );
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $query = $pdo->prepare('SELECT * FROM business_report_schedule_versions WHERE tenant_id = ? AND id = ?');
    $query->execute([$tenantId, $id]);
    $result = ['action' => $action, 'schedule' => $query->fetch(PDO::FETCH_ASSOC) ?: []];
    if ($idContact !== null) {
        $result['id_contact'] = $evidence;
    }
    return $result;
}

/** @return array{action:string,schedule:array<string,mixed>,id_contact:array<string,mixed>} */
function business_report_prepare_schedule_from_id(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    array $idContact,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
    int $actorUserId,
    string $reason,
): array {
    $validated = business_report_id_contact_validate($idContact, $tenantSlug);
    $result = business_report_prepare_schedule(
        $pdo,
        $tenantSlug,
        $scheduleKey,
        $clientId,
        $definitionId,
        $validated['recipient_email'],
        $timezone,
        $deliveryWeekday,
        $deliveryLocalTime,
        $canary,
        $actorUserId,
        $reason,
        $validated,
    );
    if (!is_array($result['id_contact'] ?? null)) {
        throw new BusinessReportGateException('8 West ID report-contact evidence is missing.');
    }
    return $result;
}

/** @return array{action:string,schedule:array<string,mixed>,id_contact:array<string,mixed>} */
function business_report_prepare_client_schedule_from_id(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    array $idContact,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
    int $actorUserId,
    string $reason,
): array {
    $validated = business_report_id_contact_validate($idContact, null);
    $result = business_report_prepare_schedule(
        $pdo,
        $tenantSlug,
        $scheduleKey,
        $clientId,
        $definitionId,
        $validated['recipient_email'],
        $timezone,
        $deliveryWeekday,
        $deliveryLocalTime,
        $canary,
        $actorUserId,
        $reason,
        $validated,
        $clientId,
    );
    if (!is_array($result['id_contact'] ?? null)
        || (int)($result['id_contact']['client_id'] ?? 0) !== $clientId
    ) {
        throw new BusinessReportGateException('Client-scoped 8 West ID report-contact evidence is missing.');
    }
    return $result;
}

/** @return array{action:string,schedule:array<string,mixed>,id_contact:array<string,mixed>} */
function business_report_prepare_customer_schedule_from_id(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $clientId,
    int $definitionId,
    string $expectedCustomerId,
    array $idContact,
    string $timezone,
    int $deliveryWeekday,
    string $deliveryLocalTime,
    bool $canary,
    int $actorUserId,
    string $reason,
): array {
    $validated = business_report_id_contact_validate($idContact, null);
    $result = business_report_prepare_schedule(
        $pdo,
        $tenantSlug,
        $scheduleKey,
        $clientId,
        $definitionId,
        $validated['recipient_email'],
        $timezone,
        $deliveryWeekday,
        $deliveryLocalTime,
        $canary,
        $actorUserId,
        $reason,
        $validated,
        $clientId,
        $expectedCustomerId,
    );
    if (!is_array($result['id_contact'] ?? null)
        || (int)($result['id_contact']['client_id'] ?? 0) !== $clientId
    ) {
        throw new BusinessReportGateException('Managed-customer 8 West ID contact evidence is missing.');
    }
    return $result;
}

/** @return array{action:string,schedule:array<string,mixed>} */
function business_report_transition_schedule(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    int $expectedVersion,
    string $toStatus,
    int $actorUserId,
    string $reason,
    ?array $rawConfig = null,
): array {
    business_report_schedule_key($scheduleKey);
    if ($expectedVersion < 1 || !in_array($toStatus, ['active', 'disabled'], true)) {
        throw new BusinessReportValidationException('Report schedule transition is invalid.');
    }
    $reason = business_report_key($reason, 500, 'Schedule reason');
    $actor = business_report_actor($pdo, $tenantSlug, $actorUserId);
    $tenantId = (int)$actor['tenant_id'];
    $pdo->beginTransaction();
    try {
        $latest = business_report_latest_schedule($pdo, $tenantId, $scheduleKey, true);
        if (!is_array($latest) || (int)$latest['version_no'] !== $expectedVersion) {
            throw new BusinessReportConflictException('Report schedule version changed; inspect it again.');
        }
        if (!is_array(business_report_contact_scope_for_key($pdo, $tenantId, $scheduleKey))) {
            throw new BusinessReportGateException(
                'The report schedule contact scope was not found.',
            );
        }
        $fromStatus = (string)$latest['status'];
        if ($fromStatus === $toStatus) {
            throw new BusinessReportConflictException('Report schedule transition must change status explicitly.');
        }
        $target = business_report_schedule_target(
            $pdo,
            $tenantSlug,
            (int)$latest['client_id'],
            (int)$latest['definition_version_id'],
            $actorUserId,
        );
        if ($toStatus === 'active') {
            business_report_assert_schedule_contact_evidence($pdo, $latest);
            $gateSchedule = $latest;
            $gateSchedule['tenant_slug'] = $tenantSlug;
            business_report_assert_schedule_gate(
                $gateSchedule,
                business_report_config($rawConfig),
                'dry_run',
            );
        }
        $insert = $pdo->prepare(
            'INSERT INTO business_report_schedule_versions
                (tenant_id, schedule_key, version_no, definition_version_id,
                 client_id, recipient_email, schedule_timezone, delivery_weekday,
                 delivery_local_time, canary, status, created_by_user_id, reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $tenantId,
            $scheduleKey,
            $expectedVersion + 1,
            (int)$target['definition_version_id'],
            (int)$target['client_id'],
            (string)$latest['recipient_email'],
            (string)$latest['schedule_timezone'],
            (int)$latest['delivery_weekday'],
            (string)$latest['delivery_local_time'],
            (int)$latest['canary'],
            $toStatus,
            $actorUserId,
            $reason,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $query = $pdo->prepare('SELECT * FROM business_report_schedule_versions WHERE tenant_id = ? AND id = ?');
    $query->execute([$tenantId, $id]);
    return ['action' => $toStatus === 'active' ? 'enabled' : 'disabled', 'schedule' => $query->fetch(PDO::FETCH_ASSOC) ?: []];
}

/** @return array<string,mixed> */
function business_report_active_schedule(PDO $pdo, string $tenantSlug, string $scheduleKey): array
{
    business_report_slug($tenantSlug);
    business_report_schedule_key($scheduleKey);
    $query = $pdo->prepare(
        "SELECT s.*, t.slug AS tenant_slug, c.name AS client_name,
                d.definition_key, d.report_type, d.contract_json, d.contract_sha256,
                d.version_no AS definition_version_no
           FROM business_report_schedule_versions s
           JOIN tenants t ON t.id = s.tenant_id
           JOIN clients c ON c.tenant_id = s.tenant_id AND c.id = s.client_id
           JOIN business_report_definition_versions d
             ON d.tenant_id = s.tenant_id AND d.id = s.definition_version_id
          WHERE t.slug = ? AND s.schedule_key = ?
            AND s.version_no = (
                SELECT MAX(newer.version_no)
                  FROM business_report_schedule_versions newer
                 WHERE newer.tenant_id = s.tenant_id
                   AND newer.schedule_key = s.schedule_key
            )"
    );
    $query->execute([$tenantSlug, $scheduleKey]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (string)$row['status'] !== 'active') {
        throw new BusinessReportGateException('No active report schedule matched the exact tenant and key.');
    }
    if (!business_report_definition_supported($row)) {
        throw new BusinessReportGateException('The active report schedule uses an unsupported definition.');
    }
    business_report_assert_schedule_contact_evidence($pdo, $row);
    return $row;
}

/** @param array<string,mixed> $schedule @param array<string,mixed> $config */
function business_report_assert_schedule_gate(array $schedule, array $config, string $gate): void
{
    if (!in_array($gate, ['generation', 'delivery', 'dry_run'], true)) {
        throw new BusinessReportValidationException('Business report gate is invalid.');
    }
    if ($gate === 'generation' && $config['generation_enabled'] !== true) {
        throw new BusinessReportGateException('Business report generation is disabled.');
    }
    if ($gate === 'delivery' && $config['delivery_enabled'] !== true) {
        throw new BusinessReportGateException('Business report delivery is disabled.');
    }
    if ($config['canary_only'] === true && (int)$schedule['canary'] !== 1) {
        throw new BusinessReportGateException('Business reports are restricted to canary schedules.');
    }
    $scheduleKey = business_report_schedule_key((string)($schedule['schedule_key'] ?? ''));
    $tenantSlug = (string)$schedule['tenant_slug'];
    $clientKey = 'safeharbor-client:' . (int)$schedule['client_id'];
    $recipient = (string)$schedule['recipient_email'];
    if (!in_array($scheduleKey, $config['schedule_keys'], true)
        || !in_array($tenantSlug, $config['tenant_slugs'], true)
        || !in_array($clientKey, $config['client_keys'], true)
        || !in_array($recipient, $config['recipient_emails'], true)
    ) {
        throw new BusinessReportGateException(
            'Report schedule, tenant, client, and recipient must be exactly allowlisted.',
        );
    }
}

/**
 * Definition v1 promised raw approved-time facts. It cannot silently reinterpret
 * an entry after an append-only approval adjustment becomes applicable.
 */
function business_report_assert_v1_adjustment_free(
    PDO $pdo,
    array $schedule,
    string $periodStart,
    string $periodEnd,
    string $generatedAt,
): void {
    if ((int) ($schedule['definition_version_no'] ?? 0) !== BUSINESS_REPORT_CONTRACT_VERSION_V1) return;

    $adjusted = $pdo->prepare(
        "SELECT 1
           FROM time_entry_approval_adjustments adjustment
           JOIN time_entries entry
             ON entry.tenant_id = adjustment.tenant_id
            AND entry.id = adjustment.time_entry_id
          WHERE entry.tenant_id = ? AND entry.client_id = ?
            AND entry.approval_status = 'approved'
            AND entry.worked_at >= ? AND entry.worked_at < ?
            AND entry.reviewed_at IS NOT NULL AND entry.reviewed_at <= ?
            AND adjustment.created_at <= ?
          LIMIT 1"
    );
    $adjusted->execute([
        (int) $schedule['tenant_id'],
        (int) $schedule['client_id'],
        $periodStart,
        $periodEnd,
        $generatedAt,
        $generatedAt,
    ]);
    if ($adjusted->fetchColumn() !== false) {
        throw new BusinessReportConflictException(
            'Business report definition v1 cannot archive adjusted approved time.',
        );
    }
}

/** @return array<string,int|string> */
function business_report_approved_billable_time_v2(
    PDO $pdo,
    int $tenantId,
    int $clientId,
    string $periodStart,
    string $periodEnd,
    string $generatedAt,
    int $adjustmentIdCutoff,
): array {
    if ($adjustmentIdCutoff < 0) {
        throw new BusinessReportValidationException('Adjustment id cutoff cannot be negative.');
    }
    $time = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE
                WHEN COALESCE(adjustment.effective_billable, entry.billable) = 1
                THEN COALESCE(adjustment.effective_minutes, entry.minutes)
                ELSE 0
            END), 0) AS effective_billable_minutes,
            COALESCE(SUM(CASE WHEN entry.billable = 1 THEN entry.minutes ELSE 0 END), 0)
                AS original_billable_minutes,
            COALESCE(SUM(CASE WHEN adjustment.id IS NULL THEN 0 ELSE 1 END), 0)
                AS adjusted_entries,
            COALESCE(SUM(COALESCE(adjustment.version_no, 0)), 0)
                AS adjustment_slips
           FROM time_entries entry
           LEFT JOIN time_entry_approval_adjustments adjustment
             ON adjustment.tenant_id = entry.tenant_id
            AND adjustment.time_entry_id = entry.id
            AND adjustment.version_no = (
                SELECT MAX(candidate.version_no)
                  FROM time_entry_approval_adjustments candidate
                 WHERE candidate.tenant_id = entry.tenant_id
                   AND candidate.time_entry_id = entry.id
                   AND candidate.id <= ?
                   AND candidate.created_at <= ?
             )
          WHERE entry.tenant_id = ? AND entry.client_id = ?
            AND entry.approval_status = 'approved'
            AND entry.worked_at >= ? AND entry.worked_at < ?
            AND entry.reviewed_at IS NOT NULL AND entry.reviewed_at <= ?"
    );
    $time->execute([
        $adjustmentIdCutoff,
        $generatedAt,
        $tenantId,
        $clientId,
        $periodStart,
        $periodEnd,
        $generatedAt,
    ]);
    $row = $time->fetch(PDO::FETCH_ASSOC) ?: [];
    $effectiveMinutes = (int) ($row['effective_billable_minutes'] ?? 0);
    $originalMinutes = (int) ($row['original_billable_minutes'] ?? 0);
    return [
        'adjustment_id_cutoff' => $adjustmentIdCutoff,
        'minutes' => $effectiveMinutes,
        'original_approved_billable_minutes' => $originalMinutes,
        'net_adjustment_minutes' => $effectiveMinutes - $originalMinutes,
        'entries_with_adjustments_applied' => (int) ($row['adjusted_entries'] ?? 0),
        'adjustment_slips_applied' => (int) ($row['adjustment_slips'] ?? 0),
        'calculation' => 'each_approved_entry_once_using_latest_adjustment_at_id_cutoff_and_generated_at_else_original',
        'classification' => 'operational_approval_evidence_not_financial_status',
    ];
}

/** @return array<string,mixed> */
function business_report_metrics(
    PDO $pdo,
    array $schedule,
    string $periodStart,
    string $periodEnd,
    string $generatedAt,
    ?int $adjustmentIdCutoff = null,
): array {
    $periodStart = business_report_utc($periodStart, 'Period start');
    $periodEnd = business_report_utc($periodEnd, 'Period end');
    $generatedAt = business_report_utc($generatedAt, 'Generated at');
    if ($periodStart >= $periodEnd || $periodEnd > $generatedAt) {
        throw new BusinessReportValidationException('Report period must be a complete past window.');
    }
    $tenantId = (int)$schedule['tenant_id'];
    $clientId = (int)$schedule['client_id'];
    $definitionVersion = (int) ($schedule['definition_version_no'] ?? 0);
    if (!in_array($definitionVersion, [
        BUSINESS_REPORT_CONTRACT_VERSION_V1,
        BUSINESS_REPORT_CONTRACT_VERSION_V2,
    ], true)) {
        throw new BusinessReportGateException(
            'Business report metrics require an exact supported definition version.',
        );
    }
    if ($definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V2
        && ($adjustmentIdCutoff === null || $adjustmentIdCutoff < 0)
    ) {
        throw new BusinessReportGateException(
            'Business report definition v2 requires an exact adjustment id cutoff.',
        );
    }
    business_report_assert_v1_adjustment_free(
        $pdo,
        $schedule,
        $periodStart,
        $periodEnd,
        $generatedAt,
    );

    $opened = $pdo->prepare(
        'SELECT COUNT(*) FROM tickets
          WHERE tenant_id = ? AND client_id = ?
            AND created_at >= ? AND created_at < ?
            AND merged_into_id IS NULL'
    );
    $opened->execute([$tenantId, $clientId, $periodStart, $periodEnd]);
    $resolved = $pdo->prepare(
        'SELECT COUNT(*) FROM tickets
          WHERE tenant_id = ? AND client_id = ?
            AND resolved_at >= ? AND resolved_at < ?
            AND merged_into_id IS NULL'
    );
    $resolved->execute([$tenantId, $clientId, $periodStart, $periodEnd]);

    $cohort = $pdo->prepare(
        "SELECT t.id, t.created_at, t.resolved_at, t.sla_due_at,
                 t.service_goal_target_id, t.merged_into_id,
                EXISTS (
                    SELECT 1 FROM tickets source
                     WHERE source.tenant_id = t.tenant_id
                       AND source.merged_into_id = t.id
                ) AS has_merged_sources,
                (SELECT MIN(m.created_at) FROM messages m
                  WHERE m.ticket_id = t.id AND m.kind = 'tech'
                    AND m.created_at >= t.created_at
                    AND m.created_at <= ?) AS first_response_at
           FROM tickets t
          WHERE t.tenant_id = ? AND t.client_id = ?
            AND t.created_at >= ? AND t.created_at < ?"
    );
    $cohort->execute([$generatedAt, $tenantId, $clientId, $periodStart, $periodEnd]);
    $responseMinutes = [];
    $mergedExcluded = 0;
    $slaEligible = 0;
    $slaLegacyExcluded = 0;
    $slaDecided = 0;
    $slaMet = 0;
    foreach ($cohort->fetchAll(PDO::FETCH_ASSOC) as $ticket) {
        if ($ticket['merged_into_id'] !== null || (int)$ticket['has_merged_sources'] === 1) {
            $mergedExcluded++;
            continue;
        }
        $firstResponse = $ticket['first_response_at'] !== null ? strtotime((string)$ticket['first_response_at'] . ' UTC') : false;
        $created = strtotime((string)$ticket['created_at'] . ' UTC');
        $validFirstResponse = $firstResponse !== false && $created !== false && $firstResponse >= $created
            ? $firstResponse
            : false;
        if ($validFirstResponse !== false) {
            $responseMinutes[] = (int)floor(($validFirstResponse - $created) / 60);
        }
        if ($ticket['service_goal_target_id'] === null) {
            $slaLegacyExcluded++;
            continue;
        }
        $slaEligible++;
        $due = strtotime((string)$ticket['sla_due_at'] . ' UTC');
        $generated = strtotime($generatedAt . ' UTC');
        $resolvedAtTimestamp = $ticket['resolved_at'] !== null
            ? strtotime((string)$ticket['resolved_at'] . ' UTC')
            : false;
        $decided = $validFirstResponse !== false
            || ($resolvedAtTimestamp !== false && $generated !== false && $resolvedAtTimestamp <= $generated)
            || ($due !== false && $generated !== false && $due <= $generated);
        if (!$decided) continue;
        $slaDecided++;
        if ($validFirstResponse !== false && $due !== false && $validFirstResponse <= $due) {
            $slaMet++;
        }
    }

    if ($definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V1) {
        $time = $pdo->prepare(
            "SELECT COALESCE(SUM(minutes), 0) FROM time_entries
              WHERE tenant_id = ? AND client_id = ?
                AND approval_status = 'approved' AND billable = 1
                AND worked_at >= ? AND worked_at < ?
                AND reviewed_at IS NOT NULL AND reviewed_at <= ?"
        );
        $time->execute([$tenantId, $clientId, $periodStart, $periodEnd, $generatedAt]);
        $approvedTime = [
            'minutes' => (int)$time->fetchColumn(),
            'classification' => 'operational_approval_evidence_not_financial_status',
        ];
    } else {
        $approvedTime = business_report_approved_billable_time_v2(
            $pdo,
            $tenantId,
            $clientId,
            $periodStart,
            $periodEnd,
            $generatedAt,
            $adjustmentIdCutoff,
        );
    }
    $csat = $pdo->prepare(
        'SELECT COUNT(*) AS sent,
                COALESCE(SUM(CASE WHEN s.score IS NOT NULL AND s.responded_at <= ? THEN 1 ELSE 0 END), 0) AS answered,
                AVG(CASE WHEN s.score IS NOT NULL AND s.responded_at <= ? THEN s.score ELSE NULL END) AS avg_score
           FROM csat s
           JOIN tickets t ON t.id = s.ticket_id
          WHERE t.tenant_id = ? AND t.client_id = ?
            AND s.created_at >= ? AND s.created_at < ?'
    );
    $csat->execute([$generatedAt, $generatedAt, $tenantId, $clientId, $periodStart, $periodEnd]);
    $csatRow = $csat->fetch(PDO::FETCH_ASSOC) ?: [];

    $answered = count($responseMinutes);
    return [
        // The archive envelope stays on schema 1 so migration 013's immutable
        // database guard remains valid. Definition version, not envelope
        // version, selects the report semantics and any additive fields.
        'schema_version' => 1,
        'report_type' => BUSINESS_REPORT_TYPE,
        'definition' => [
            'key' => (string)$schedule['definition_key'],
            'version' => (int)$schedule['definition_version_no'],
            'sha256' => (string)$schedule['contract_sha256'],
        ],
        'source' => [
            'tenant_key' => (string)$schedule['tenant_slug'],
            'client_key' => 'safeharbor-client:' . $clientId,
            'client_name' => (string)$schedule['client_name'],
        ],
        'period' => [
            'start_utc' => str_replace(' ', 'T', $periodStart) . 'Z',
            'end_utc_exclusive' => str_replace(' ', 'T', $periodEnd) . 'Z',
            'schedule_timezone' => (string)$schedule['schedule_timezone'],
        ],
        'generated_at' => str_replace(' ', 'T', $generatedAt) . 'Z',
        'tickets' => [
            'opened' => (int)$opened->fetchColumn(),
            'resolved' => (int)$resolved->fetchColumn(),
            'merged_histories_excluded_from_response_metrics' => $mergedExcluded,
        ],
        'first_response' => [
            'answered' => $answered,
            'average_minutes' => $answered > 0 ? (int)round(array_sum($responseMinutes) / $answered) : null,
        ],
        'service_goal' => [
            'eligible_versioned' => $slaEligible,
            'legacy_unversioned_excluded' => $slaLegacyExcluded,
            'decided' => $slaDecided,
            'met' => $slaMet,
            'attainment_percent' => $slaDecided > 0 ? (int)round(100 * $slaMet / $slaDecided) : null,
            'undecided' => $slaEligible - $slaDecided,
        ],
        'approved_billable_time' => $approvedTime,
        'csat' => [
            'surveys_sent' => (int)($csatRow['sent'] ?? 0),
            'responses_received_by_generated_at' => (int)($csatRow['answered'] ?? 0),
            'average_score_out_of_3' => ($csatRow['avg_score'] ?? null) !== null
                ? round((float)$csatRow['avg_score'], 2)
                : null,
        ],
        'delivery_truth' => 'A provider acceptance is submission evidence, not inbox delivery proof.',
    ];
}

/** @param list<string> $expected */
function business_report_archive_has_exact_keys(mixed $value, array $expected): bool
{
    return is_array($value)
        && !array_is_list($value)
        && array_keys($value) === $expected;
}

function business_report_archive_strings_are_safe(mixed $value): bool
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', $value) === 0;
    }
    if (!is_array($value)) return true;
    foreach ($value as $item) {
        if (!business_report_archive_strings_are_safe($item)) return false;
    }
    return true;
}

function business_report_archive_integer_between(mixed $value, int $minimum, int $maximum): bool
{
    return is_int($value) && $value >= $minimum && $value <= $maximum;
}

function business_report_archive_iso_utc(mixed $value): bool
{
    if (!is_string($value)
        || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) !== 1
    ) {
        return false;
    }
    $databaseValue = substr($value, 0, -1);
    $databaseValue[10] = ' ';
    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $databaseValue,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d\TH:i:s\Z') === $value
        && (int) substr($value, 0, 4) >= 1000;
}

/**
 * Refuse any archive metric that is not exactly one of the immutable v1/v2
 * public contracts. This deliberately validates every nested key and scalar
 * before rendering so a self-hashed direct insert cannot turn a metric into
 * report text or use an ignored field as private/financial storage.
 *
 * @param array<string,mixed> $metrics
 */
function business_report_assert_archive_metric_schema(array $metrics): void
{
    $invalid = static function (): never {
        throw new BusinessReportConflictException(
            'The archived report metric schema is invalid.',
        );
    };
    if (!business_report_archive_strings_are_safe($metrics)
        || !business_report_archive_has_exact_keys($metrics, [
            'schema_version',
            'report_type',
            'definition',
            'source',
            'period',
            'generated_at',
            'tickets',
            'first_response',
            'service_goal',
            'approved_billable_time',
            'csat',
            'delivery_truth',
        ])
        || ($metrics['schema_version'] ?? null) !== 1
        || ($metrics['report_type'] ?? null) !== BUSINESS_REPORT_TYPE
        || ($metrics['delivery_truth'] ?? null)
            !== 'A provider acceptance is submission evidence, not inbox delivery proof.'
    ) {
        $invalid();
    }

    $definition = $metrics['definition'] ?? null;
    if (!business_report_archive_has_exact_keys($definition, ['key', 'version', 'sha256'])) {
        $invalid();
    }
    $definitionVersion = $definition['version'] ?? null;
    if (!is_int($definitionVersion)
        || !in_array($definitionVersion, [
            BUSINESS_REPORT_CONTRACT_VERSION_V1,
            BUSINESS_REPORT_CONTRACT_VERSION_V2,
        ], true)
        || ($definition['key'] ?? null) !== BUSINESS_REPORT_DEFINITION_KEY
        || !is_string($definition['sha256'] ?? null)
        || preg_match('/\A[0-9a-f]{64}\z/D', $definition['sha256']) !== 1
        || !hash_equals(business_report_contract_sha256($definitionVersion), $definition['sha256'])
    ) {
        $invalid();
    }

    $source = $metrics['source'] ?? null;
    if (!business_report_archive_has_exact_keys($source, [
        'tenant_key',
        'client_key',
        'client_name',
    ])
        || !is_string($source['tenant_key'] ?? null)
        || preg_match('/\A[a-z0-9][a-z0-9-]{0,63}\z/D', $source['tenant_key']) !== 1
        || !is_string($source['client_key'] ?? null)
        || preg_match('/\Asafeharbor-client:[1-9][0-9]{0,9}\z/D', $source['client_key']) !== 1
        || (int) substr($source['client_key'], strlen('safeharbor-client:'))
            > BUSINESS_REPORT_ARCHIVE_MAX_COUNT
        || !is_string($source['client_name'] ?? null)
        || $source['client_name'] === ''
        || $source['client_name'] !== trim($source['client_name'])
        || mb_strlen($source['client_name'], 'UTF-8') > 128
    ) {
        $invalid();
    }

    $period = $metrics['period'] ?? null;
    if (!business_report_archive_has_exact_keys($period, [
        'start_utc',
        'end_utc_exclusive',
        'schedule_timezone',
    ])
        || !business_report_archive_iso_utc($period['start_utc'] ?? null)
        || !business_report_archive_iso_utc($period['end_utc_exclusive'] ?? null)
        || !business_report_archive_iso_utc($metrics['generated_at'] ?? null)
        || $period['start_utc'] >= $period['end_utc_exclusive']
        || $period['end_utc_exclusive'] > $metrics['generated_at']
        || !is_string($period['schedule_timezone'] ?? null)
        || strlen($period['schedule_timezone']) > 64
        || !in_array($period['schedule_timezone'], timezone_identifiers_list(), true)
    ) {
        $invalid();
    }

    $tickets = $metrics['tickets'] ?? null;
    if (!business_report_archive_has_exact_keys($tickets, [
        'opened',
        'resolved',
        'merged_histories_excluded_from_response_metrics',
    ])) {
        $invalid();
    }
    foreach (['opened', 'resolved', 'merged_histories_excluded_from_response_metrics'] as $key) {
        if (!business_report_archive_integer_between(
            $tickets[$key] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
        )) {
            $invalid();
        }
    }

    $firstResponse = $metrics['first_response'] ?? null;
    if (!business_report_archive_has_exact_keys($firstResponse, ['answered', 'average_minutes'])
        || !business_report_archive_integer_between(
            $firstResponse['answered'] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
        )
        || $firstResponse['answered'] > $tickets['opened']
        || ($firstResponse['answered'] === 0 && $firstResponse['average_minutes'] !== null)
        || ($firstResponse['answered'] > 0
            && !business_report_archive_integer_between(
                $firstResponse['average_minutes'] ?? null,
                0,
                BUSINESS_REPORT_ARCHIVE_MAX_MINUTES,
            ))
    ) {
        $invalid();
    }

    $serviceGoal = $metrics['service_goal'] ?? null;
    if (!business_report_archive_has_exact_keys($serviceGoal, [
        'eligible_versioned',
        'legacy_unversioned_excluded',
        'decided',
        'met',
        'attainment_percent',
        'undecided',
    ])) {
        $invalid();
    }
    foreach ([
        'eligible_versioned',
        'legacy_unversioned_excluded',
        'decided',
        'met',
        'undecided',
    ] as $key) {
        if (!business_report_archive_integer_between(
            $serviceGoal[$key] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
        )) {
            $invalid();
        }
    }
    $expectedAttainment = $serviceGoal['decided'] === 0
        ? null
        : (int) round(100 * $serviceGoal['met'] / $serviceGoal['decided']);
    if ($serviceGoal['eligible_versioned'] + $serviceGoal['legacy_unversioned_excluded']
            > $tickets['opened']
        || $serviceGoal['decided'] > $serviceGoal['eligible_versioned']
        || $serviceGoal['met'] > $serviceGoal['decided']
        || $serviceGoal['undecided']
            !== $serviceGoal['eligible_versioned'] - $serviceGoal['decided']
        || $serviceGoal['attainment_percent'] !== $expectedAttainment
    ) {
        $invalid();
    }

    $approvedTime = $metrics['approved_billable_time'] ?? null;
    $approvedKeys = $definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V1
        ? ['minutes', 'classification']
        : [
            'adjustment_id_cutoff',
            'minutes',
            'original_approved_billable_minutes',
            'net_adjustment_minutes',
            'entries_with_adjustments_applied',
            'adjustment_slips_applied',
            'calculation',
            'classification',
        ];
    if (!business_report_archive_has_exact_keys($approvedTime, $approvedKeys)
        || !business_report_archive_integer_between(
            $approvedTime['minutes'] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_MINUTES,
        )
        || ($approvedTime['classification'] ?? null)
            !== 'operational_approval_evidence_not_financial_status'
    ) {
        $invalid();
    }
    if ($definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V2) {
        if (!business_report_archive_integer_between(
            $approvedTime['original_approved_billable_minutes'] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_MINUTES,
        )) {
            $invalid();
        }
        foreach (['entries_with_adjustments_applied', 'adjustment_slips_applied'] as $key) {
            if (!business_report_archive_integer_between(
                $approvedTime[$key] ?? null,
                0,
                BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
            )) {
                $invalid();
            }
        }
        if (!business_report_archive_integer_between(
                $approvedTime['adjustment_id_cutoff'] ?? null,
                0,
                PHP_INT_MAX,
            )
            || !business_report_archive_integer_between(
                $approvedTime['net_adjustment_minutes'] ?? null,
                -BUSINESS_REPORT_ARCHIVE_MAX_MINUTES,
                0,
            )
            || ($approvedTime['calculation'] ?? null)
                !== 'each_approved_entry_once_using_latest_adjustment_at_id_cutoff_and_generated_at_else_original'
            || $approvedTime['minutes'] > $approvedTime['original_approved_billable_minutes']
            || $approvedTime['net_adjustment_minutes']
                !== $approvedTime['minutes'] - $approvedTime['original_approved_billable_minutes']
            || $approvedTime['adjustment_slips_applied']
                < $approvedTime['entries_with_adjustments_applied']
            || (($approvedTime['entries_with_adjustments_applied'] === 0)
                !== ($approvedTime['adjustment_slips_applied'] === 0))
            || ($approvedTime['entries_with_adjustments_applied'] === 0
                && $approvedTime['minutes'] !== $approvedTime['original_approved_billable_minutes'])
        ) {
            throw new BusinessReportConflictException(
                'The archived report metric schema and v2 adjustment summary are invalid.',
            );
        }
    }

    $csat = $metrics['csat'] ?? null;
    if (!business_report_archive_has_exact_keys($csat, [
        'surveys_sent',
        'responses_received_by_generated_at',
        'average_score_out_of_3',
    ])
        || !business_report_archive_integer_between(
            $csat['surveys_sent'] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
        )
        || !business_report_archive_integer_between(
            $csat['responses_received_by_generated_at'] ?? null,
            0,
            BUSINESS_REPORT_ARCHIVE_MAX_COUNT,
        )
        || $csat['responses_received_by_generated_at'] > $csat['surveys_sent']
    ) {
        $invalid();
    }
    $averageScore = $csat['average_score_out_of_3'];
    if (($csat['responses_received_by_generated_at'] === 0 && $averageScore !== null)
        || ($csat['responses_received_by_generated_at'] > 0
            && (!is_float($averageScore)
                || !is_finite($averageScore)
                || $averageScore < 1.0
                || $averageScore > 3.0))
    ) {
        $invalid();
    }
}

/** @param array<string,mixed> $metrics */
function business_report_text(array $metrics): string
{
    business_report_assert_archive_metric_schema($metrics);
    $value = static fn(mixed $item): string => $item === null ? 'Not enough decided data' : (string)$item;
    $minutes = (int)$metrics['approved_billable_time']['minutes'];
    $hours = number_format($minutes / 60, 2, '.', '');
    $definitionVersion = (int) ($metrics['definition']['version'] ?? 0);
    $approvedTimeLines = $definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V2
        ? [
            'Approved billable operational time after adjustments: ' . $minutes . ' minutes (' . $hours . ' hours)',
            'Original approved billable operational time: '
                . (int) $metrics['approved_billable_time']['original_approved_billable_minutes'] . ' minutes',
            'Billable time net adjustment: '
                . sprintf('%+d', (int) $metrics['approved_billable_time']['net_adjustment_minutes']) . ' minutes',
            'Approved-time entries adjusted: '
                . (int) $metrics['approved_billable_time']['entries_with_adjustments_applied'],
            'Append-only adjustment slips applied: '
                . (int) $metrics['approved_billable_time']['adjustment_slips_applied'],
        ]
        : [
            'Approved billable operational time: ' . $minutes . ' minutes (' . $hours . ' hours)',
        ];
    $lines = [
        'Safeharbor weekly client service summary',
        'Client: ' . $metrics['source']['client_name'],
        'Period: ' . $metrics['period']['start_utc'] . ' through ' . $metrics['period']['end_utc_exclusive'] . ' (end exclusive)',
        'Generated: ' . $metrics['generated_at'],
        '',
        'Tickets opened: ' . $metrics['tickets']['opened'],
        'Tickets resolved: ' . $metrics['tickets']['resolved'],
        'First responses measured: ' . $metrics['first_response']['answered'],
        'Average first response: ' . $value($metrics['first_response']['average_minutes']) . ($metrics['first_response']['average_minutes'] === null ? '' : ' minutes'),
        'Versioned service-goal outcomes decided: ' . $metrics['service_goal']['decided'],
        'Versioned service-goal tickets eligible: ' . $metrics['service_goal']['eligible_versioned'],
        'Versioned service-goal attainment: ' . $value($metrics['service_goal']['attainment_percent']) . ($metrics['service_goal']['attainment_percent'] === null ? '' : '%'),
        'Versioned service-goal outcomes still undecided: ' . $metrics['service_goal']['undecided'],
        'Legacy tickets without a versioned goal excluded: ' . $metrics['service_goal']['legacy_unversioned_excluded'],
        ...$approvedTimeLines,
        'CSAT responses: ' . $metrics['csat']['responses_received_by_generated_at'] . ' of ' . $metrics['csat']['surveys_sent'],
        'CSAT average: ' . $value($metrics['csat']['average_score_out_of_3']) . ($metrics['csat']['average_score_out_of_3'] === null ? '' : ' / 3'),
        '',
        ...($definitionVersion === BUSINESS_REPORT_CONTRACT_VERSION_V2
            ? ['Each approved time entry is counted once using its latest adjustment inside the archived adjustment-id and generation-time cutoffs. Adjustment reasons are not included.']
            : []),
        'Approved billable time above is operational evidence only, not a statement of export or invoice status. This report does not invoice or post anything.',
        'Merged ticket histories are excluded from response metrics because their original response provenance is not retained.',
        'Microsoft Graph acceptance, when recorded, means submitted to the provider; it does not prove inbox delivery.',
    ];
    return implode("\n", $lines) . "\n";
}

/** @param array<string,mixed> $metrics */
function business_report_metrics_json(array $metrics): string
{
    return json_encode(
        $metrics,
        JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR,
    );
}

function business_report_content_sha256_from_json(string $metricsJson, string $text): string
{
    return hash('sha256', $metricsJson . "\n" . $text);
}

/** @param array<string,mixed> $metrics */
function business_report_content_sha256(array $metrics, string $text): string
{
    return business_report_content_sha256_from_json(business_report_metrics_json($metrics), $text);
}

/**
 * Verify only the immutable raw bytes needed to identify an archive before a
 * crossed send boundary is recovered. Current semantic/canonical rules may be
 * newer than an already-sent legacy archive, so they deliberately run later.
 *
 * @return array{metrics:array<string,mixed>,text:string}
 */
function business_report_minimally_verified_archived_content(array $archive): array
{
    $metricsJson = (string)($archive['metrics_json'] ?? '');
    $text = (string)($archive['report_text'] ?? '');
    $storedHash = (string)($archive['content_sha256'] ?? '');
    try {
        $metrics = json_decode($metricsJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new BusinessReportConflictException('The archived report metrics are not valid JSON.', 0, $error);
    }
    if (!is_array($metrics)
        || preg_match('/\A[0-9a-f]{64}\z/D', $storedHash) !== 1
        || !hash_equals($storedHash, business_report_content_sha256_from_json($metricsJson, $text))
    ) {
        throw new BusinessReportConflictException('The archived report content hash does not match.');
    }
    return ['metrics' => $metrics, 'text' => $text];
}

/** @return array<string,mixed> */
function business_report_archived_source_for_scope(array $metrics, array $context): array
{
    $source = $metrics['source'] ?? null;
    if (!is_array($source)
        || !is_string($source['tenant_key'] ?? null)
        || !is_string($source['client_key'] ?? null)
        || !hash_equals((string)$context['tenant_slug'], $source['tenant_key'])
        || !hash_equals('safeharbor-client:' . (int)$context['client_id'], $source['client_key'])
    ) {
        throw new BusinessReportConflictException(
            'The archived report source does not match its database scope.',
        );
    }
    return $source;
}

/** @return array{metrics:array<string,mixed>,text:string} */
function business_report_archived_content(array $archive): array
{
    $content = business_report_minimally_verified_archived_content($archive);
    $metrics = $content['metrics'];
    $metricsJson = (string)($archive['metrics_json'] ?? '');
    $text = $content['text'];
    business_report_assert_archive_metric_schema($metrics);
    try {
        $canonicalMetricsJson = business_report_metrics_json($metrics);
    } catch (JsonException $error) {
        throw new BusinessReportConflictException(
            'The archived report metric schema JSON cannot be encoded canonically.',
            0,
            $error,
        );
    }
    if (!hash_equals($canonicalMetricsJson, $metricsJson)) {
        throw new BusinessReportConflictException(
            'The archived report metric schema JSON is not canonical.',
        );
    }
    try {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): never {
                throw new ErrorException($message, 0, $severity, $file, $line);
            },
        );
        $canonicalText = business_report_text($metrics);
    } catch (Throwable $error) {
        throw new BusinessReportConflictException(
            'The archived report cannot be rendered from its exact metrics.',
            0,
            $error,
        );
    } finally {
        restore_error_handler();
    }
    if (!hash_equals($canonicalText, $text)) {
        throw new BusinessReportConflictException('The archived report text is not canonical.');
    }
    return ['metrics' => $metrics, 'text' => $text];
}

/** @return array{action:string,archive:array<string,mixed>,metrics:array<string,mixed>,text:string} */
function business_report_generate(
    PDO $pdo,
    string $tenantSlug,
    string $scheduleKey,
    ?array $rawConfig = null,
    ?int $now = null,
    bool $dryRun = false,
    bool $requireDue = true,
): array {
    $requestedNow = $now;
    $previewNow = $now ?? time();
    $config = business_report_config($rawConfig);
    $schedule = business_report_active_schedule($pdo, $tenantSlug, $scheduleKey);
    business_report_assert_schedule_gate($schedule, $config, $dryRun ? 'dry_run' : 'generation');
    $window = business_report_next_window($pdo, $schedule);
    if ($dryRun) {
        // A dry run is deliberately read-only and does not take the tenant
        // serialization lock. It is a best-effort preview, never evidence of
        // the exact facts a later persisted archive will capture.
        $generatedAt = business_report_utc(
            gmdate('Y-m-d H:i:s', $previewNow),
            'Generated at',
        );
        if ($requireDue && $generatedAt < $window['due_at']) {
            throw new BusinessReportGateException('The report schedule is not due yet.');
        }
        $adjustmentIdCutoff = (int) ($schedule['definition_version_no'] ?? 0)
            === BUSINESS_REPORT_CONTRACT_VERSION_V2
            ? business_report_adjustment_id_cutoff($pdo, (int) $schedule['tenant_id'])
            : null;
        $metrics = business_report_metrics(
            $pdo,
            $schedule,
            $window['period_start'],
            $window['period_end'],
            $generatedAt,
            $adjustmentIdCutoff,
        );
        $text = business_report_text($metrics);
        $sha256 = business_report_content_sha256($metrics, $text);
        return [
            'action' => 'dry_run',
            'archive' => [
                'id' => null,
                'content_sha256' => $sha256,
                'period_start' => $window['period_start'],
                'period_end' => $window['period_end'],
            ],
            'metrics' => $metrics,
            'text' => $text,
        ];
    }

    $tenantId = (int)$schedule['tenant_id'];
    $pdo->beginTransaction();
    try {
        // Persisted reports and append-only approved-time adjustments share
        // this tenant row as their first lock. Reports need only a shared
        // lock: it still excludes an adjustment's exclusive lock while
        // preserving the report runtime's SELECT-only tenant privilege. That
        // fixed order makes the winner visible before report metrics are read:
        // an adjustment that wins makes v1 fail closed, while a report that
        // wins archives before the later adjustment can be appended. V2 also
        // captures the committed adjustment-id prefix under this lock, making
        // that order durable when both operations share a timestamp second.
        $tenantLockSql = 'SELECT id FROM tenants WHERE id = ? AND slug = ?';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $tenantLockSql .= ' FOR SHARE';
        }
        $tenantLock = $pdo->prepare($tenantLockSql);
        $tenantLock->execute([$tenantId, $tenantSlug]);
        if ((int) $tenantLock->fetchColumn() !== $tenantId) {
            throw new BusinessReportConflictException(
                'The report tenant changed before archive generation completed.',
            );
        }
        $adjustmentIdCutoff = (int) ($schedule['definition_version_no'] ?? 0)
            === BUSINESS_REPORT_CONTRACT_VERSION_V2
            ? business_report_adjustment_id_cutoff($pdo, $tenantId, true)
            : null;
        $generatedAt = business_report_persisted_generated_at($pdo, $requestedNow);
        if ($requireDue && $generatedAt < $window['due_at']) {
            throw new BusinessReportGateException('The report schedule is not due yet.');
        }

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $scheduleLock = $pdo->prepare(
                'SELECT id FROM business_report_schedule_versions
                  WHERE tenant_id = ? AND id = ? FOR UPDATE'
            );
            $scheduleLock->execute([$tenantId, (int)$schedule['id']]);
            if ($scheduleLock->fetchColumn() === false) {
                throw new BusinessReportConflictException('The report schedule disappeared during generation.');
            }
        }
        $latestSchedule = business_report_latest_schedule($pdo, $tenantId, $scheduleKey, true);
        if (!is_array($latestSchedule)
            || (int)$latestSchedule['id'] !== (int)$schedule['id']
            || (string)$latestSchedule['status'] !== 'active'
        ) {
            throw new BusinessReportConflictException(
                'The report schedule changed before archive generation completed.',
            );
        }
        $existing = $pdo->prepare(
            'SELECT * FROM business_report_archives
              WHERE tenant_id = ? AND schedule_key = ? AND period_start = ? AND period_end = ?'
        );
        $existing->execute([$tenantId, $scheduleKey, $window['period_start'], $window['period_end']]);
        $archive = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($archive)) {
            $archivedContent = business_report_archived_content($archive);
            $pdo->commit();
            return [
                'action' => 'ignored',
                'archive' => $archive,
                'metrics' => $archivedContent['metrics'],
                'text' => $archivedContent['text'],
            ];
        }
        $metrics = business_report_metrics(
            $pdo,
            $schedule,
            $window['period_start'],
            $window['period_end'],
            $generatedAt,
            $adjustmentIdCutoff,
        );
        $text = business_report_text($metrics);
        $metricsJson = business_report_metrics_json($metrics);
        $sha256 = business_report_content_sha256_from_json($metricsJson, $text);
        $insert = $pdo->prepare(
            'INSERT INTO business_report_archives
                (tenant_id, client_id, schedule_key, schedule_version_id,
                 definition_version_id, period_start, period_end, generated_at,
                 metrics_json, report_text, content_sha256)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $insert->execute([
            $tenantId,
            (int)$schedule['client_id'],
            $scheduleKey,
            (int)$schedule['id'],
            (int)$schedule['definition_version_id'],
            $window['period_start'],
            $window['period_end'],
            $generatedAt,
            $metricsJson,
            $text,
            $sha256,
        ]);
        $archiveId = (int)$pdo->lastInsertId();
        $delivery = $pdo->prepare(
            "INSERT INTO business_report_deliveries
                (tenant_id, archive_id, schedule_version_id, recipient_email, status)
             VALUES (?,?,?,?, 'pending')"
        );
        $delivery->execute([$tenantId, $archiveId, (int)$schedule['id'], (string)$schedule['recipient_email']]);
        $query = $pdo->prepare('SELECT * FROM business_report_archives WHERE tenant_id = ? AND id = ?');
        $query->execute([$tenantId, $archiveId]);
        $archive = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['action' => 'created', 'archive' => $archive, 'metrics' => $metrics, 'text' => $text];
}

/** @return array<string,mixed> */
function business_report_delivery_context(PDO $pdo, int $archiveId): array
{
    if ($archiveId < 1) throw new BusinessReportValidationException('Archive id must be positive.');
    $query = $pdo->prepare(
        'SELECT d.*, a.schedule_key, a.client_id, a.metrics_json, a.report_text,
                a.content_sha256, a.period_start, a.period_end,
                s.canary, s.status AS schedule_status,
                t.slug AS tenant_slug
           FROM business_report_deliveries d
           JOIN business_report_archives a ON a.tenant_id = d.tenant_id AND a.id = d.archive_id
           JOIN business_report_schedule_versions s
             ON s.tenant_id = d.tenant_id AND s.id = d.schedule_version_id
           JOIN tenants t ON t.id = d.tenant_id
           WHERE a.id = ?'
    );
    $query->execute([$archiveId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new BusinessReportGateException('Report archive delivery was not found.');
    return $row;
}

function business_report_recover_expired_delivery(PDO $pdo, array $delivery, string $nowUtc): bool
{
    if ((string)$delivery['status'] !== 'sending'
        || $delivery['lease_expires_at'] === null
        || (string)$delivery['lease_expires_at'] > $nowUtc
    ) {
        return false;
    }
    $attempt = $pdo->prepare(
        "UPDATE business_report_delivery_attempts
            SET status = 'uncertain', completed_at = ?, outcome_code = 'lease_expired_after_send_boundary'
          WHERE tenant_id = ? AND delivery_id = ? AND status = 'started'"
    );
    $attempt->execute([$nowUtc, (int)$delivery['tenant_id'], (int)$delivery['id']]);
    $update = $pdo->prepare(
        "UPDATE business_report_deliveries
            SET status = 'uncertain', lease_token_hash = NULL, lease_expires_at = NULL,
                updated_at = ?
          WHERE tenant_id = ? AND id = ? AND status = 'sending'"
    );
    $update->execute([$nowUtc, (int)$delivery['tenant_id'], (int)$delivery['id']]);
    return true;
}

/**
 * Accept only the bounded Graph evidence vocabulary. This is the last guard
 * before immutable attempt evidence is written, so malformed or caller-made
 * values lose both their code and HTTP status instead of becoming a covert
 * place to store provider bodies, addresses, credentials, or exception text.
 *
 * @return array{outcome:string,provider_http:int|null,outcome_code:string}
 */
function business_report_transport_outcome(mixed $candidate): array
{
    $invalid = [
        'outcome' => 'uncertain',
        'provider_http' => null,
        'outcome_code' => 'invalid_transport_outcome',
    ];
    if (!is_array($candidate)) return $invalid;

    $http = $candidate['provider_http'] ?? null;
    if ($http !== null && (!is_int($http) || $http < 100 || $http > 599)) return $invalid;
    $code = $candidate['outcome_code'] ?? null;
    if (!is_string($code)) return $invalid;

    if (($candidate['outcome'] ?? null) === 'submitted'
        && $http === 202
        && $code === 'graph_accepted'
    ) {
        return ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted'];
    }
    if ($code === 'graph_send_rejected' && $http !== null && $http !== 202) {
        return ['outcome' => 'uncertain', 'provider_http' => $http, 'outcome_code' => $code];
    }
    if ($code === 'graph_token_rejected' && $http !== null && $http !== 200) {
        return ['outcome' => 'uncertain', 'provider_http' => $http, 'outcome_code' => $code];
    }
    if ($code === 'graph_token_invalid_response' && $http === 200) {
        return ['outcome' => 'uncertain', 'provider_http' => 200, 'outcome_code' => $code];
    }
    if ($http === null && in_array($code, [
        'graph_send_transport_error',
        'graph_send_unknown_response',
        'graph_token_transport_error',
        'graph_token_unknown_response',
        'graph_payload_invalid',
        'transport_exception',
    ], true)) {
        return ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => $code];
    }
    return $invalid;
}

/**
 * Build the in-process Graph configuration for reports only. The ordinary
 * help-desk sender remains in mail.graph.sender for inbound polling and the
 * retrying mail queue; report delivery must name its own dedicated sender and
 * never falls back to the ordinary sender.
 *
 * @return array{tenant_id:string,client_id:string,client_secret:string,sender:string}
 */
function business_report_delivery_graph_config(array $config, ?array $mailGraph): array
{
    $sender = business_report_graph_sender($config['graph_sender'] ?? null);
    if ($sender === null) {
        throw new BusinessReportGateException(
            'A dedicated Microsoft Graph report sender is not configured.',
        );
    }
    if ($mailGraph === null) {
        throw new BusinessReportGateException(
            'Microsoft Graph report delivery credentials are not configured.',
        );
    }
    $reportGraph = [];
    foreach (['tenant_id', 'client_id', 'client_secret'] as $key) {
        $value = trim((string)($mailGraph[$key] ?? ''));
        if ($value === '') {
            throw new BusinessReportGateException(
                'Microsoft Graph report delivery credentials are not configured.',
            );
        }
        $reportGraph[$key] = $value;
    }
    // Deliberately ignore mail.graph.sender. Reports own their sender and must
    // not depend on or mutate the ordinary ticket/inbound mailbox setting.
    $reportGraph['sender'] = $sender;
    return $reportGraph;
}

/**
 * @param null|callable(string,string,string):array{outcome:string,provider_http:int|null,outcome_code:string} $transport
 * @return array{action:string,status:string,archive_id:int,attempt_id:int|null}
 */
function business_report_deliver(
    PDO $pdo,
    int $archiveId,
    ?array $rawConfig = null,
    ?callable $transport = null,
    ?int $now = null,
): array {
    $now ??= time();
    $nowUtc = gmdate('Y-m-d H:i:s', $now);
    $config = business_report_config($rawConfig);
    $context = business_report_delivery_context($pdo, $archiveId);
    $minimalContent = business_report_minimally_verified_archived_content($context);
    business_report_archived_source_for_scope($minimalContent['metrics'], $context);
    $deliveryStatus = (string)$context['status'];

    // A crossed send boundary must always converge to terminal uncertainty when
    // its lease expires, even if the schedule or protected allowlists changed
    // afterward. Recovery verifies only immutable raw hash and decoded source
    // scope, performs no network request, row-locks the delivery, and precedes
    // current semantic/canonical archive rules and send gates.
    if ($deliveryStatus === 'sending') {
        $pdo->beginTransaction();
        try {
            $lockSql = 'SELECT * FROM business_report_deliveries WHERE tenant_id = ? AND id = ?';
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $lockSql .= ' FOR UPDATE';
            $lock = $pdo->prepare($lockSql);
            $lock->execute([(int)$context['tenant_id'], (int)$context['id']]);
            $delivery = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($delivery)) throw new BusinessReportGateException('Report delivery disappeared.');
            if (business_report_recover_expired_delivery($pdo, $delivery, $nowUtc)) {
                $pdo->commit();
                return ['action' => 'recovered_uncertain', 'status' => 'uncertain', 'archive_id' => $archiveId, 'attempt_id' => null];
            }
            $deliveryStatus = (string)$delivery['status'];
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    $archivedContent = business_report_archived_content($context);
    $archivedSource = business_report_archived_source_for_scope(
        $archivedContent['metrics'],
        $context,
    );
    if ($deliveryStatus !== 'pending') {
        return ['action' => 'ignored', 'status' => $deliveryStatus, 'archive_id' => $archiveId, 'attempt_id' => null];
    }

    $archivedClientName = business_report_key(
        (string)$archivedSource['client_name'],
        128,
        'Archived client name',
    );
    business_report_assert_schedule_gate($context, $config, 'delivery');
    if ((string)$context['schedule_status'] !== 'active') {
        throw new BusinessReportGateException('The archived report schedule was not active when delivery was created.');
    }

    $transportWasInjected = $transport !== null;
    if ($transport === null) {
        require_once __DIR__ . '/mailer.php';
        $mailGraph = cfg('mail.graph');
        $graph = business_report_delivery_graph_config(
            $config,
            is_array($mailGraph) ? $mailGraph : null,
        );
        $transport = static function (string $to, string $subject, string $body) use ($graph): array {
            return mailer_send_graph_result($graph, $to, $subject, $body);
        };
    }

    $pdo->beginTransaction();
    try {
        $lockSql = 'SELECT * FROM business_report_deliveries WHERE tenant_id = ? AND id = ?';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') $lockSql .= ' FOR UPDATE';
        $lock = $pdo->prepare($lockSql);
        $lock->execute([(int)$context['tenant_id'], (int)$context['id']]);
        $delivery = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($delivery)) throw new BusinessReportGateException('Report delivery disappeared.');
        if (business_report_recover_expired_delivery($pdo, $delivery, $nowUtc)) {
            $pdo->commit();
            return ['action' => 'recovered_uncertain', 'status' => 'uncertain', 'archive_id' => $archiveId, 'attempt_id' => null];
        }
        $latestSchedule = business_report_latest_schedule(
            $pdo,
            (int)$context['tenant_id'],
            (string)$context['schedule_key'],
            true,
        );
        if (!is_array($latestSchedule)
            || (int)$latestSchedule['id'] !== (int)$context['schedule_version_id']
            || (string)$latestSchedule['status'] !== 'active'
        ) {
            throw new BusinessReportGateException(
                'The archived report schedule is no longer the active version.',
            );
        }
        if ((string)$delivery['status'] !== 'pending') {
            $pdo->commit();
            return ['action' => 'ignored', 'status' => (string)$delivery['status'], 'archive_id' => $archiveId, 'attempt_id' => null];
        }
        $leaseToken = bin2hex(random_bytes(32));
        $leaseHash = hash('sha256', $leaseToken);
        $leaseExpires = gmdate('Y-m-d H:i:s', $now + (int)$config['lease_seconds']);
        $attemptKey = bin2hex(random_bytes(32));
        $claim = $pdo->prepare(
            "UPDATE business_report_deliveries
                SET status = 'sending', lease_token_hash = ?, lease_expires_at = ?,
                    last_attempt_at = ?, updated_at = ?
              WHERE tenant_id = ? AND id = ? AND status = 'pending'"
        );
        $claim->execute([$leaseHash, $leaseExpires, $nowUtc, $nowUtc, (int)$context['tenant_id'], (int)$context['id']]);
        if ($claim->rowCount() !== 1) throw new BusinessReportConflictException('Report delivery could not acquire its lease.');
        $attempt = $pdo->prepare(
            "INSERT INTO business_report_delivery_attempts
                (tenant_id, delivery_id, attempt_key, provider, status, started_at)
             VALUES (?,?,?, 'microsoft_graph', 'started', ?)"
        );
        $attempt->execute([(int)$context['tenant_id'], (int)$context['id'], $attemptKey, $nowUtc]);
        $attemptId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $subject = 'Safeharbor weekly service summary — ' . $archivedClientName;
    try {
        $outcome = $transport(
            (string)$context['recipient_email'],
            $subject,
            $archivedContent['text'],
        );
    } catch (Throwable) {
        $outcome = ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'transport_exception'];
    }
    $outcome = business_report_transport_outcome($outcome);
    $providerHttp = $outcome['provider_http'];
    $outcomeCode = $outcome['outcome_code'];
    $status = $outcome['outcome'];

    $completedAt = gmdate('Y-m-d H:i:s', $transportWasInjected ? $now : max($now, time()));
    $pdo->beginTransaction();
    try {
        $attemptUpdate = $pdo->prepare(
            'UPDATE business_report_delivery_attempts
                SET status = ?, completed_at = ?, provider_http = ?, outcome_code = ?
              WHERE tenant_id = ? AND id = ? AND delivery_id = ? AND status = \'started\''
        );
        $attemptUpdate->execute([
            $status, $completedAt, $providerHttp, $outcomeCode,
            (int)$context['tenant_id'], $attemptId, (int)$context['id'],
        ]);
        if ($attemptUpdate->rowCount() !== 1) throw new BusinessReportConflictException('Report attempt finalization was not exact.');
        $deliveryUpdate = $pdo->prepare(
            'UPDATE business_report_deliveries
                SET status = ?, lease_token_hash = NULL, lease_expires_at = NULL,
                    submitted_at = ?, updated_at = ?
              WHERE tenant_id = ? AND id = ? AND status = \'sending\' AND lease_token_hash = ?'
        );
        $deliveryUpdate->execute([
            $status,
            $status === 'submitted' ? $completedAt : null,
            $completedAt,
            (int)$context['tenant_id'],
            (int)$context['id'],
            $leaseHash,
        ]);
        if ($deliveryUpdate->rowCount() !== 1) throw new BusinessReportConflictException('Report delivery finalization was not exact.');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return ['action' => $status, 'status' => $status, 'archive_id' => $archiveId, 'attempt_id' => $attemptId];
}

/** @return list<array{tenant_slug:string,schedule_key:string}> */
function business_report_due_schedule_keys(
    PDO $pdo,
    int $now,
    int $limit = BUSINESS_REPORT_MAX_DUE_SCHEDULES,
    ?array $rawConfig = null,
): array
{
    $limit = max(1, min(BUSINESS_REPORT_MAX_DUE_SCHEDULES, $limit));
    $config = business_report_config($rawConfig);
    $query = $pdo->query(
        "SELECT t.slug AS tenant_slug, s.schedule_key
           FROM business_report_schedule_versions s
           JOIN tenants t ON t.id = s.tenant_id
          WHERE s.status = 'active'
            AND s.version_no = (
                SELECT MAX(newer.version_no)
                  FROM business_report_schedule_versions newer
                 WHERE newer.tenant_id = s.tenant_id
                   AND newer.schedule_key = s.schedule_key
            )
          ORDER BY s.tenant_id, s.schedule_key"
    );
    $due = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            // The latest schedule may be disabled or superseded between the
            // inventory read and this exact reload. Treat that safe operator
            // race as a skipped key, not a failure for every other schedule.
            $schedule = business_report_active_schedule(
                $pdo,
                (string)$row['tenant_slug'],
                (string)$row['schedule_key'],
            );
            business_report_assert_schedule_gate($schedule, $config, 'generation');
            $window = business_report_next_window($pdo, $schedule);
        } catch (BusinessReportException) {
            continue;
        }
        if (gmdate('Y-m-d H:i:s', $now) >= $window['due_at']) {
            $due[] = ['tenant_slug' => (string)$row['tenant_slug'], 'schedule_key' => (string)$row['schedule_key']];
            if (count($due) >= $limit) break;
        }
    }
    return $due;
}

/** @return list<int> */
function business_report_pending_archive_ids(
    PDO $pdo,
    int $now,
    int $limit = BUSINESS_REPORT_MAX_DUE_SCHEDULES,
    ?array $rawConfig = null,
): array {
    $limit = max(1, min(BUSINESS_REPORT_MAX_DUE_SCHEDULES, $limit));
    $config = business_report_config($rawConfig);
    $nowUtc = gmdate('Y-m-d H:i:s', $now);
    $query = $pdo->prepare(
        "SELECT d.archive_id, d.recipient_email, d.tenant_id, d.status,
                a.client_id, a.schedule_key, s.canary, t.slug AS tenant_slug
           FROM business_report_deliveries d
           JOIN business_report_archives a
             ON a.tenant_id = d.tenant_id AND a.id = d.archive_id
           JOIN business_report_schedule_versions s
             ON s.tenant_id = d.tenant_id AND s.id = d.schedule_version_id
           JOIN tenants t ON t.id = d.tenant_id
          WHERE (
                  d.status = 'pending'
              AND s.status = 'active'
              AND s.version_no = (
                    SELECT MAX(newer.version_no)
                      FROM business_report_schedule_versions newer
                     WHERE newer.tenant_id = s.tenant_id
                       AND newer.schedule_key = s.schedule_key
                  )
                )
             OR (d.status = 'sending' AND d.lease_expires_at <= ?)
          ORDER BY d.created_at, d.id"
    );
    $query->execute([$nowUtc]);
    $archives = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $delivery) {
        if ((string)$delivery['status'] === 'sending') {
            $archives[] = (int)$delivery['archive_id'];
            if (count($archives) >= $limit) break;
            continue;
        }
        try {
            business_report_assert_schedule_gate($delivery, $config, 'delivery');
        } catch (BusinessReportGateException) {
            continue;
        }
        $archives[] = (int)$delivery['archive_id'];
        if (count($archives) >= $limit) break;
    }
    return $archives;
}
