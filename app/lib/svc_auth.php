<?php
/**
 * Service-to-service authentication for api/svc/* (Phase 8.1, Path B).
 *
 * Callers sign  "{timestamp}\n{raw_body}"  with HMAC-SHA256 and send
 * X-8W-Service / X-8W-Timestamp / X-8W-Signature. Verification is pinned
 * to SHA-256, compared with hash_equals, and bounded by a ±300 s replay
 * window plus a fixed-window rate limit per service identity.
 *
 * Secrets live ONLY in the server config.php — never the DB, never git:
 *   'svc' => ['enabled' => false, 'secrets' => ['milepost' => '...']]
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const SVC_TIMESTAMP_TOLERANCE = 300;    // seconds
const SVC_RATE_LIMIT_PER_MIN  = 120;
const SVC_MAX_BODY_BYTES      = 16384;

/** Kill switch — endpoints answer 404 while this is false. */
function svc_enabled(): bool
{
    return (bool)cfg('svc.enabled', false);
}

/**
 * Verify one request. Returns ['ok'=>bool, 'code'=>int, 'service'=>?string].
 * Failures are deliberately indistinguishable to the caller: the same
 * generic 401 covers unknown service, inactive identity, stale timestamp,
 * and bad signature — no oracle for which check failed.
 */
function svc_authenticate(string $service, string $timestamp, string $signature, string $rawBody): array
{
    if ($service === '' || $timestamp === '' || $signature === '') {
        return ['ok' => false, 'code' => 401, 'service' => null];
    }
    $secret = (string)cfg('svc.secrets.' . $service, '');
    if ($secret === '') {
        return ['ok' => false, 'code' => 401, 'service' => null];
    }
    if (!svc_identity($service)) {
        return ['ok' => false, 'code' => 401, 'service' => null];
    }
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > SVC_TIMESTAMP_TOLERANCE) {
        return ['ok' => false, 'code' => 401, 'service' => $service];
    }
    $expected = hash_hmac('sha256', $timestamp . "\n" . $rawBody, $secret);
    if (!hash_equals($expected, mb_strtolower($signature))) {
        return ['ok' => false, 'code' => 401, 'service' => $service];
    }
    if (!svc_rate_check($service)) {
        return ['ok' => false, 'code' => 429, 'service' => $service];
    }
    db()->prepare('UPDATE svc_identities SET last_seen_at = UTC_TIMESTAMP() WHERE tenant_id = ? AND service = ?')
        ->execute([tenant_id(), $service]);
    return ['ok' => true, 'code' => 200, 'service' => $service];
}

/** The active registered identity for a service, or null. */
function svc_identity(string $service): ?array
{
    $q = db()->prepare('SELECT * FROM svc_identities WHERE tenant_id = ? AND service = ? AND is_active = 1');
    $q->execute([tenant_id(), $service]);
    return $q->fetch() ?: null;
}

/**
 * Fixed-window rate check: true while the service is within
 * SVC_RATE_LIMIT_PER_MIN for the current minute. Only called after the
 * signature verifies, so unauthenticated traffic never burns the budget.
 */
function svc_rate_check(string $service): bool
{
    $bucket = (int)floor(time() / 60);
    db()->prepare(
        'INSERT INTO svc_rate_buckets (service, bucket_minute, hits) VALUES (?,?,1)
         ON DUPLICATE KEY UPDATE hits = hits + 1'
    )->execute([$service, $bucket]);
    // Opportunistic cleanup of old windows (cheap, keeps the table tiny).
    db()->prepare('DELETE FROM svc_rate_buckets WHERE bucket_minute < ?')->execute([$bucket - 10]);
    $q = db()->prepare('SELECT hits FROM svc_rate_buckets WHERE service = ? AND bucket_minute = ?');
    $q->execute([$service, $bucket]);
    return (int)$q->fetchColumn() <= SVC_RATE_LIMIT_PER_MIN;
}
