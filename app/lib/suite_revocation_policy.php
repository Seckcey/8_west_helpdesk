<?php
/** Pure policy for the signed 8 West ID authorization feed and local cache. */
declare(strict_types=1);

const SUITE_REVOCATION_CACHE_VERSION = 3;
const SUITE_REVOCATION_MAX_AGE = 900;
const SUITE_REVOCATION_CLOCK_SKEW = 60;
const SUITE_REVOCATION_MAX_ENTRIES = 100000;
const SUITE_REVOCATION_MAX_BODY_BYTES = 5000000;
const SUITE_SESSION_VERSION_COMPONENT_MAX = '18446744073709551615';

/** The issuer's exact unsigned-BIGINT pair, kept as a string on every PHP build. */
function suite_session_version_valid(mixed $value): bool
{
    if (! is_string($value)
        || preg_match('/^([1-9][0-9]{0,19})\.([1-9][0-9]{0,19})$/D', $value, $parts) !== 1) {
        return false;
    }

    foreach ([$parts[1], $parts[2]] as $component) {
        if (strlen($component) === strlen(SUITE_SESSION_VERSION_COMPONENT_MAX)
            && strcmp($component, SUITE_SESSION_VERSION_COMPONENT_MAX) > 0) {
            return false;
        }
    }

    return true;
}

function suite_revocation_subject_valid(mixed $subject): bool
{
    return is_string($subject)
        && strlen($subject) <= 128
        && preg_match('/^t[1-9][0-9]*u[1-9][0-9]*$/D', $subject) === 1;
}

/** @param list<string> $expected */
function suite_revocation_exact_keys(array $value, array $expected): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);

    return $actual === $expected;
}

function suite_revocation_generated_at(mixed $value): ?int
{
    if (! is_string($value)) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat(
        '!Y-m-d\TH:i:sP',
        $value,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false
        || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
        return null;
    }

    return $parsed->getTimestamp();
}

function suite_revocation_since_valid(mixed $value, mixed $reason = null): bool
{
    if (! is_string($value)) {
        return false;
    }
    // Subscription metadata may be a calendar date; administrative revocations
    // still require timestamps. This never changes whether a subject is revoked.
    $format = $reason === 'subscription_lapsed' && strlen($value) === 10
        ? 'Y-m-d' : 'Y-m-d H:i:s';
    $pattern = $format === 'Y-m-d'
        ? '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D'
        : '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D';
    if (preg_match($pattern, $value) !== 1) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat(
        '!' . $format,
        $value,
        new DateTimeZone('UTC'),
    );
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format($format) === $value;
}

/** @return array{generated_at:int,mode:'invalid',revoked:array,authorizations:array} */
function suite_revocation_invalid_snapshot(int $generatedAt): array
{
    return [
        'generated_at' => $generatedAt,
        'mode' => 'invalid',
        'revoked' => [],
        'authorizations' => [],
    ];
}

/**
 * Parse the exact signed issuer contract.
 *
 * A payload containing either versioned field can never be mistaken for the
 * old revoked-only format. Any defect in that attempted v2 payload becomes a
 * fail-closed invalid snapshot.
 *
 * @return array{generated_at:int,mode:'legacy'|'versioned'|'invalid',
 *   revoked:array<string,array{since:string,reason:string}>,
 *   authorizations:array<string,string>}|null
 */
function suite_revocation_snapshot_from_payload(
    mixed $payload,
    int $now,
    ?stdClass $wireShape = null,
): ?array
{
    $mentionsVersioned = is_array($payload)
        && ! array_is_list($payload)
        && (array_key_exists('authorizations', $payload)
            || array_key_exists('authorization_count', $payload));
    $generatedAt = is_array($payload) && ! array_is_list($payload)
        ? suite_revocation_generated_at($payload['generated_at'] ?? null)
        : null;
    if ($generatedAt === null
        || $generatedAt < $now - SUITE_REVOCATION_MAX_AGE
        || $generatedAt > $now + SUITE_REVOCATION_CLOCK_SKEW) {
        return null;
    }
    $invalid = static fn (): ?array => $mentionsVersioned
        ? suite_revocation_invalid_snapshot($generatedAt)
        : null;

    if (! is_array($payload)
        || array_is_list($payload)
        || ! is_int($payload['count'] ?? null)
        || $payload['count'] < 0
        || $payload['count'] > SUITE_REVOCATION_MAX_ENTRIES
        || ! is_array($payload['revoked'] ?? null)
        || ! array_is_list($payload['revoked'])
        || ($wireShape !== null
            && (! property_exists($wireShape, 'revoked') || ! is_array($wireShape->revoked)))
        || $payload['count'] !== count($payload['revoked'])) {
        return $invalid();
    }

    $revoked = [];
    foreach ($payload['revoked'] as $index => $entry) {
        if (! is_array($entry)
            || array_is_list($entry)
            || ($wireShape !== null
                && ! (($wireShape->revoked[$index] ?? null) instanceof stdClass))
            || ! suite_revocation_exact_keys($entry, ['reason', 'since', 'sub'])
            || ! suite_revocation_subject_valid($entry['sub'] ?? null)
            || ! suite_revocation_since_valid($entry['since'] ?? null, $entry['reason'] ?? null)
            || ! is_string($entry['reason'] ?? null)
            || ! in_array($entry['reason'], [
                'account_disabled',
                'business_suspended',
                'subscription_lapsed',
            ], true)
            || array_key_exists($entry['sub'], $revoked)) {
            return $invalid();
        }
        $revoked[$entry['sub']] = [
            'since' => $entry['since'],
            'reason' => $entry['reason'],
        ];
    }

    if (! $mentionsVersioned) {
        if (! suite_revocation_exact_keys($payload, ['count', 'generated_at', 'revoked'])) {
            return null;
        }

        return [
            'generated_at' => $generatedAt,
            'mode' => 'legacy',
            'revoked' => $revoked,
            'authorizations' => [],
        ];
    }

    if (! suite_revocation_exact_keys(
        $payload,
        ['authorization_count', 'authorizations', 'count', 'generated_at', 'revoked'],
    )
        || ! is_int($payload['authorization_count'] ?? null)
        || $payload['authorization_count'] < 0
        || $payload['authorization_count'] > SUITE_REVOCATION_MAX_ENTRIES
        || ! is_array($payload['authorizations'] ?? null)
        || ! array_is_list($payload['authorizations'])
        || ($wireShape !== null
            && (! property_exists($wireShape, 'authorizations')
                || ! is_array($wireShape->authorizations)))
        || $payload['authorization_count'] !== count($payload['authorizations'])) {
        return suite_revocation_invalid_snapshot($generatedAt);
    }

    $authorizations = [];
    foreach ($payload['authorizations'] as $index => $entry) {
        if (! is_array($entry)
            || array_is_list($entry)
            || ($wireShape !== null
                && ! (($wireShape->authorizations[$index] ?? null) instanceof stdClass))
            || ! suite_revocation_exact_keys($entry, ['session_version', 'sub'])
            || ! suite_revocation_subject_valid($entry['sub'] ?? null)
            || ! suite_session_version_valid($entry['session_version'] ?? null)
            || array_key_exists($entry['sub'], $authorizations)
            || array_key_exists($entry['sub'], $revoked)) {
            return suite_revocation_invalid_snapshot($generatedAt);
        }
        $authorizations[$entry['sub']] = $entry['session_version'];
    }

    return [
        'generated_at' => $generatedAt,
        'mode' => 'versioned',
        'revoked' => $revoked,
        'authorizations' => $authorizations,
    ];
}

/** @return array{status:'ok',snapshot:array}|array{status:'untrusted'|'signed_invalid'|'stale',reason:string} */
function suite_revocation_verify_signed_body(
    string $body,
    string $signature,
    string $secret,
    int $now,
): array {
    if ($secret === ''
        || strlen($body) > SUITE_REVOCATION_MAX_BODY_BYTES
        || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
        || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
        return ['status' => 'untrusted', 'reason' => 'signature_invalid'];
    }

    try {
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $wireShape = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['status' => 'signed_invalid', 'reason' => 'payload_invalid'];
    }
    if (! is_array($payload) || array_is_list($payload) || ! $wireShape instanceof stdClass) {
        return ['status' => 'signed_invalid', 'reason' => 'payload_invalid'];
    }
    $generatedAt = suite_revocation_generated_at($payload['generated_at'] ?? null);
    if ($generatedAt === null) {
        return ['status' => 'signed_invalid', 'reason' => 'generated_at_invalid'];
    }
    if ($generatedAt < $now - SUITE_REVOCATION_MAX_AGE
        || $generatedAt > $now + SUITE_REVOCATION_CLOCK_SKEW) {
        return ['status' => 'stale', 'reason' => 'generated_at_stale'];
    }
    $snapshot = suite_revocation_snapshot_from_payload($payload, $now, $wireShape);
    if ($snapshot === null) {
        return ['status' => 'signed_invalid', 'reason' => 'payload_invalid_or_stale'];
    }

    return ['status' => 'ok', 'snapshot' => $snapshot];
}

function suite_revocation_body_mentions_versioned(string $body): bool
{
    if (strlen($body) > SUITE_REVOCATION_MAX_BODY_BYTES) {
        return false;
    }
    try {
        $payload = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }

    return $payload instanceof stdClass
        && (property_exists($payload, 'authorizations')
            || property_exists($payload, 'authorization_count'));
}

function suite_revocation_session_version_mode(mixed $value): ?string
{
    return is_string($value) && in_array($value, ['compat', 'strict'], true)
        ? $value
        : null;
}

/**
 * The local MAC protects metadata the issuer did not sign (`fetched_at` and
 * the one-way v2 latch). The issuer HMAC still independently authenticates
 * the exact response body.
 */
function suite_revocation_cache_mac(
    int $fetchedAt,
    bool $versionedObserved,
    string $body,
    string $signature,
    string $secret,
): string {
    $input = "safeharbor-suite-revocation-cache-v3\0"
        . $fetchedAt . "\0"
        . ($versionedObserved ? '1' : '0') . "\0"
        . $signature . "\0"
        . $body;

    return hash_hmac('sha256', $input, $secret);
}

/** @return array{cache_version:int,fetched_at:int,versioned_observed:bool,body:string,signature:string,mac:string} */
function suite_revocation_cache_envelope(
    int $fetchedAt,
    bool $versionedObserved,
    string $body,
    string $signature,
    string $secret,
): array {
    $versionedObserved = $versionedObserved || suite_revocation_body_mentions_versioned($body);

    return [
        'cache_version' => SUITE_REVOCATION_CACHE_VERSION,
        'fetched_at' => $fetchedAt,
        'versioned_observed' => $versionedObserved,
        'body' => $body,
        'signature' => $signature,
        'mac' => suite_revocation_cache_mac(
            $fetchedAt,
            $versionedObserved,
            $body,
            $signature,
            $secret,
        ),
    ];
}

/**
 * @return array{cache_version:int,fetched_at:int,versioned_observed:bool,body:string,signature:string,mac:string}|null
 */
function suite_revocation_cached_envelope(mixed $value, string $secret, int $now): ?array
{
    if ($secret === ''
        || ! is_array($value)
        || array_is_list($value)
        || ! suite_revocation_exact_keys($value, [
            'body',
            'cache_version',
            'fetched_at',
            'mac',
            'signature',
            'versioned_observed',
        ])
        || ($value['cache_version'] ?? null) !== SUITE_REVOCATION_CACHE_VERSION
        || ! is_int($value['fetched_at'] ?? null)
        || $value['fetched_at'] < 1
        || $value['fetched_at'] > $now + SUITE_REVOCATION_CLOCK_SKEW
        || ! is_bool($value['versioned_observed'] ?? null)
        || ! is_string($value['body'] ?? null)
        || strlen($value['body']) > SUITE_REVOCATION_MAX_BODY_BYTES
        || ! is_string($value['signature'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $value['signature']) !== 1
        || ! is_string($value['mac'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $value['mac']) !== 1) {
        return null;
    }

    $expectedMac = suite_revocation_cache_mac(
        $value['fetched_at'],
        $value['versioned_observed'],
        $value['body'],
        $value['signature'],
        $secret,
    );
    if (! hash_equals($expectedMac, $value['mac'])
        || ! hash_equals(hash_hmac('sha256', $value['body'], $secret), $value['signature'])) {
        return null;
    }

    return $value;
}

/**
 * @param array{generated_at:int,mode:string,revoked:array,authorizations:array} $snapshot
 * @return array{generated_at:int,mode:string,revoked:array,authorizations:array}
 */
function suite_revocation_apply_mode(array $snapshot, string $mode, bool $versionedObserved): array
{
    if ($snapshot['mode'] === 'legacy'
        && ($mode === 'strict' || $versionedObserved)) {
        return suite_revocation_invalid_snapshot($snapshot['generated_at']);
    }

    return $snapshot;
}

/** @return array{action:'allow'|'revoked'|'reauth',reason:string} */
function suite_revocation_decision(array $snapshot, string $subject, mixed $sessionVersion): array
{
    if (($snapshot['mode'] ?? null) === 'invalid') {
        return ['action' => 'reauth', 'reason' => 'authorization_inventory_invalid'];
    }
    if (array_key_exists($subject, $snapshot['revoked'] ?? [])) {
        return ['action' => 'revoked', 'reason' => 'subject_revoked'];
    }
    if (($snapshot['mode'] ?? null) === 'legacy') {
        return ['action' => 'allow', 'reason' => 'legacy_feed'];
    }
    if (($snapshot['mode'] ?? null) !== 'versioned') {
        return ['action' => 'reauth', 'reason' => 'authorization_inventory_invalid'];
    }
    if (! suite_session_version_valid($sessionVersion)) {
        return ['action' => 'reauth', 'reason' => 'session_version_missing_or_invalid'];
    }
    $currentVersion = $snapshot['authorizations'][$subject] ?? null;
    if (! is_string($currentVersion)) {
        return ['action' => 'reauth', 'reason' => 'subject_not_authorized'];
    }
    if (! hash_equals($currentVersion, $sessionVersion)) {
        return ['action' => 'reauth', 'reason' => 'session_version_mismatch'];
    }

    return ['action' => 'allow', 'reason' => 'authorized'];
}
