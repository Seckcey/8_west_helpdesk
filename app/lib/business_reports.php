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
const BUSINESS_REPORT_CONTRACT_VERSION = 1;
const BUSINESS_REPORT_MAX_DUE_SCHEDULES = 100;
const BUSINESS_REPORT_CONTACT_SCOPE_MANUAL = 'MANUAL';
const BUSINESS_REPORT_CONTACT_SCOPE_TENANT = 'TENANT';
const BUSINESS_REPORT_CONTACT_SCOPE_CLIENT = 'CLIENT';

class BusinessReportException extends RuntimeException {}
final class BusinessReportValidationException extends BusinessReportException {}
final class BusinessReportGateException extends BusinessReportException {}
final class BusinessReportConflictException extends BusinessReportException {}
final class BusinessReportTransportException extends BusinessReportException {}

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

function business_report_contract_json(): string
{
    return json_encode(
        business_report_contract_v1(),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );
}

function business_report_contract_sha256(): string
{
    return hash('sha256', business_report_contract_json());
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
): array {
    $actor = business_report_actor($pdo, $tenantSlug, $actorUserId);
    $reason = business_report_key($reason, 500, 'Definition reason');
    $tenantId = (int)$actor['tenant_id'];
    $hash = business_report_contract_sha256();
    $existing = $pdo->prepare(
        'SELECT * FROM business_report_definition_versions
          WHERE tenant_id = ? AND definition_key = ?
          ORDER BY version_no DESC LIMIT 1'
    );
    $existing->execute([$tenantId, BUSINESS_REPORT_DEFINITION_KEY]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        if ((int)$row['version_no'] === 1
            && (string)$row['contract_sha256'] === $hash
            && (string)$row['report_type'] === BUSINESS_REPORT_TYPE
            && hash_equals($hash, hash('sha256', (string)$row['contract_json']))
        ) {
            return ['action' => 'ignored', 'definition' => $row];
        }
        throw new BusinessReportConflictException('A different report definition version already exists.');
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
        1,
        BUSINESS_REPORT_TYPE,
        business_report_contract_json(),
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
    if (!is_array($row)
        || (string)$row['definition_key'] !== BUSINESS_REPORT_DEFINITION_KEY
        || (string)$row['report_type'] !== BUSINESS_REPORT_TYPE
        || (string)$row['contract_sha256'] !== business_report_contract_sha256()
        || !hash_equals((string)$row['contract_sha256'], hash('sha256', (string)$row['contract_json']))
    ) {
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
        // Lock the logical schedule first. Every later enable, disable, or
        // reconfiguration inherits the first committed contact scope.
        $latest = business_report_latest_schedule($pdo, $tenantId, $scheduleKey, true);
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
                d.definition_key, d.report_type, d.contract_sha256,
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
    if ((string)$row['definition_key'] !== BUSINESS_REPORT_DEFINITION_KEY
        || (string)$row['report_type'] !== BUSINESS_REPORT_TYPE
        || (string)$row['contract_sha256'] !== business_report_contract_sha256()
    ) {
        throw new BusinessReportGateException('The active report schedule uses an unsupported definition.');
    }
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

/** @return array<string,mixed> */
function business_report_metrics(
    PDO $pdo,
    array $schedule,
    string $periodStart,
    string $periodEnd,
    string $generatedAt,
): array {
    $periodStart = business_report_utc($periodStart, 'Period start');
    $periodEnd = business_report_utc($periodEnd, 'Period end');
    $generatedAt = business_report_utc($generatedAt, 'Generated at');
    if ($periodStart >= $periodEnd || $periodEnd > $generatedAt) {
        throw new BusinessReportValidationException('Report period must be a complete past window.');
    }
    $tenantId = (int)$schedule['tenant_id'];
    $clientId = (int)$schedule['client_id'];

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

    $time = $pdo->prepare(
        "SELECT COALESCE(SUM(minutes), 0) FROM time_entries
          WHERE tenant_id = ? AND client_id = ?
            AND approval_status = 'approved' AND billable = 1
            AND worked_at >= ? AND worked_at < ?
            AND reviewed_at IS NOT NULL AND reviewed_at <= ?"
    );
    $time->execute([$tenantId, $clientId, $periodStart, $periodEnd, $generatedAt]);
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
        'approved_billable_time' => [
            'minutes' => (int)$time->fetchColumn(),
            'classification' => 'operational_approval_evidence_not_financial_status',
        ],
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

/** @param array<string,mixed> $metrics */
function business_report_text(array $metrics): string
{
    $value = static fn(mixed $item): string => $item === null ? 'Not enough decided data' : (string)$item;
    $minutes = (int)$metrics['approved_billable_time']['minutes'];
    $hours = number_format($minutes / 60, 2, '.', '');
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
        'Approved billable operational time: ' . $minutes . ' minutes (' . $hours . ' hours)',
        'CSAT responses: ' . $metrics['csat']['responses_received_by_generated_at'] . ' of ' . $metrics['csat']['surveys_sent'],
        'CSAT average: ' . $value($metrics['csat']['average_score_out_of_3']) . ($metrics['csat']['average_score_out_of_3'] === null ? '' : ' / 3'),
        '',
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

/** @return array{metrics:array<string,mixed>,text:string} */
function business_report_archived_content(array $archive): array
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
    if (($metrics['schema_version'] ?? null) !== 1
        || ($metrics['report_type'] ?? null) !== BUSINESS_REPORT_TYPE
        || !is_array($metrics['source'] ?? null)
        || !is_string($metrics['source']['tenant_key'] ?? null)
        || !is_string($metrics['source']['client_key'] ?? null)
        || !is_string($metrics['source']['client_name'] ?? null)
    ) {
        throw new BusinessReportConflictException('The archived report contract is invalid.');
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
    $now ??= time();
    $config = business_report_config($rawConfig);
    $schedule = business_report_active_schedule($pdo, $tenantSlug, $scheduleKey);
    business_report_assert_schedule_gate($schedule, $config, $dryRun ? 'dry_run' : 'generation');
    $window = business_report_next_window($pdo, $schedule);
    $generatedAt = gmdate('Y-m-d H:i:s', $now);
    if ($requireDue && $generatedAt < $window['due_at']) {
        throw new BusinessReportGateException('The report schedule is not due yet.');
    }
    if ($dryRun) {
        $metrics = business_report_metrics(
            $pdo,
            $schedule,
            $window['period_start'],
            $window['period_end'],
            $generatedAt,
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
    $archivedContent = business_report_archived_content($context);
    $archivedSource = $archivedContent['metrics']['source'];
    if ((string)$archivedSource['tenant_key'] !== (string)$context['tenant_slug']
        || (string)$archivedSource['client_key'] !== 'safeharbor-client:' . (int)$context['client_id']
    ) {
        throw new BusinessReportConflictException('The archived report source does not match its database scope.');
    }

    // A crossed send boundary must always converge to terminal uncertainty when
    // its lease expires, even if the schedule or protected allowlists changed
    // afterward. Recovery verifies archive scope/hash, performs no network
    // request, row-locks the delivery, and precedes current send gates.
    if ((string)$context['status'] === 'sending') {
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
            $pdo->commit();
            return ['action' => 'ignored', 'status' => (string)$delivery['status'], 'archive_id' => $archiveId, 'attempt_id' => null];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    if ((string)$context['status'] !== 'pending') {
        return ['action' => 'ignored', 'status' => (string)$context['status'], 'archive_id' => $archiveId, 'attempt_id' => null];
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
        $graph = mailer_graph_config();
        if ($graph === null) {
            throw new BusinessReportGateException('Microsoft Graph report delivery is not configured.');
        }
        $transport = static function (string $to, string $subject, string $body) use ($graph): array {
            $error = null;
            $accepted = mailer_send_graph($graph, $to, $subject, $body, $error);
            return $accepted
                ? ['outcome' => 'submitted', 'provider_http' => 202, 'outcome_code' => 'graph_accepted']
                : ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'graph_not_trustworthy'];
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
    if (!is_array($outcome)) {
        $outcome = ['outcome' => 'uncertain', 'provider_http' => null, 'outcome_code' => 'invalid_transport_outcome'];
    }
    $providerHttp = $outcome['provider_http'] ?? null;
    if ($providerHttp !== null && (!is_int($providerHttp) || $providerHttp < 100 || $providerHttp > 599)) {
        $providerHttp = null;
    }
    $outcomeCode = is_string($outcome['outcome_code'] ?? null)
        && preg_match('/\A[a-z0-9_]{1,64}\z/D', $outcome['outcome_code']) === 1
        ? $outcome['outcome_code']
        : 'invalid_transport_outcome';
    $status = ($outcome['outcome'] ?? null) === 'submitted'
        && $providerHttp === 202
        && $outcomeCode === 'graph_accepted'
        ? 'submitted'
        : 'uncertain';

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
