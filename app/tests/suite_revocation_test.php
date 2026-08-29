<?php
/** DB-free policy and source-contract coverage for suite authorization feeds. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../lib/suite_revocation_policy.php';

$checks = 0;
$failures = 0;
function suite_revocation_check(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;
    if (! $condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$now = 1800000000;
$generatedAt = gmdate('c', $now);
$secret = 'suite-revocation-test-secret-' . bin2hex(random_bytes(16));
$legacyPayload = [
    'generated_at' => $generatedAt,
    'count' => 0,
    'revoked' => [],
];

foreach (['1.1', '9.42', '18446744073709551615.18446744073709551615'] as $valid) {
    suite_revocation_check(suite_session_version_valid($valid), "valid version {$valid} was rejected");
}
foreach (['', '1', '0.1', '1.0', '01.1', '1.01', '1.1.1',
    '18446744073709551616.1', '1.18446744073709551616', 1.1, null] as $invalid) {
    suite_revocation_check(
        ! suite_session_version_valid($invalid),
        'invalid session version was accepted: ' . var_export($invalid, true),
    );
}

$legacy = suite_revocation_snapshot_from_payload($legacyPayload, $now);
suite_revocation_check(is_array($legacy) && $legacy['mode'] === 'legacy', 'legacy feed was rejected');
suite_revocation_check(
    suite_revocation_decision($legacy, 't9u4', null)
        === ['action' => 'allow', 'reason' => 'legacy_feed'],
    'compatibility feed did not admit a pre-v2 session',
);

$revokedPayload = [
    'generated_at' => $generatedAt,
    'count' => 1,
    'revoked' => [[
        'sub' => 't9u4',
        'since' => '2027-01-15 08:00:00',
        'reason' => 'account_disabled',
    ]],
];
$revoked = suite_revocation_snapshot_from_payload($revokedPayload, $now);
suite_revocation_check(
    is_array($revoked)
        && suite_revocation_decision($revoked, 't9u4', null)
            === ['action' => 'revoked', 'reason' => 'subject_revoked'],
    'an exact explicit revocation was not enforced',
);
foreach (['account_disabled', 'business_suspended', 'subscription_lapsed'] as $reason) {
    $payload = $revokedPayload;
    $payload['revoked'][0]['reason'] = $reason;
    suite_revocation_check(
        suite_revocation_snapshot_from_payload($payload, $now) !== null,
        "issuer reason {$reason} was rejected",
    );
}
$badReason = $revokedPayload;
$badReason['revoked'][0]['reason'] = 'tenant_disabled';
suite_revocation_check(
    suite_revocation_snapshot_from_payload($badReason, $now) === null,
    'an unknown revocation reason was accepted',
);
$badSince = $revokedPayload;
$badSince['revoked'][0]['since'] = '2027-01-15T08:00:00+00:00';
suite_revocation_check(
    suite_revocation_snapshot_from_payload($badSince, $now) === null,
    'a noncanonical revoked timestamp was accepted',
);
$extraRevokedField = $revokedPayload;
$extraRevokedField['revoked'][0]['extra'] = true;
suite_revocation_check(
    suite_revocation_snapshot_from_payload($extraRevokedField, $now) === null,
    'an extra revoked-row field was accepted',
);
$duplicateRevoked = $revokedPayload;
$duplicateRevoked['count'] = 2;
$duplicateRevoked['revoked'][] = $duplicateRevoked['revoked'][0];
suite_revocation_check(
    suite_revocation_snapshot_from_payload($duplicateRevoked, $now) === null,
    'duplicate revoked subjects were accepted',
);

$versionedPayload = [
    ...$legacyPayload,
    'authorization_count' => 2,
    'authorizations' => [
        ['sub' => 't9u4', 'session_version' => '2.7'],
        ['sub' => 't9u5', 'session_version' => '4.9'],
    ],
];
$versioned = suite_revocation_snapshot_from_payload($versionedPayload, $now);
suite_revocation_check(
    is_array($versioned) && $versioned['mode'] === 'versioned',
    'exact v2 inventory was rejected',
);
suite_revocation_check(
    suite_revocation_decision($versioned, 't9u4', '2.7')
        === ['action' => 'allow', 'reason' => 'authorized'],
    'matching subject/version was refused',
);
suite_revocation_check(
    suite_revocation_decision($versioned, 't9u4', '2.8')['reason'] === 'session_version_mismatch',
    'changed version did not require fresh sign-in',
);
suite_revocation_check(
    suite_revocation_decision($versioned, 't9u4', null)['reason']
        === 'session_version_missing_or_invalid',
    'missing local version did not require fresh sign-in',
);
suite_revocation_check(
    suite_revocation_decision($versioned, 't9u99', '2.7')['reason'] === 'subject_not_authorized',
    'omitted subject remained authorized',
);

$malformedV2 = [
    'count without list' => [...$legacyPayload, 'authorization_count' => 0],
    'list without count' => [...$legacyPayload, 'authorizations' => []],
    'count mismatch' => [...$legacyPayload, 'authorization_count' => 2, 'authorizations' => [
        ['sub' => 't9u4', 'session_version' => '2.7'],
    ]],
    'duplicate subject' => [...$legacyPayload, 'authorization_count' => 2, 'authorizations' => [
        ['sub' => 't9u4', 'session_version' => '2.7'],
        ['sub' => 't9u4', 'session_version' => '2.8'],
    ]],
    'bad subject' => [...$legacyPayload, 'authorization_count' => 1, 'authorizations' => [
        ['sub' => 'user-4', 'session_version' => '2.7'],
    ]],
    'bad version' => [...$legacyPayload, 'authorization_count' => 1, 'authorizations' => [
        ['sub' => 't9u4', 'session_version' => '2'],
    ]],
    'extra authorization field' => [...$legacyPayload, 'authorization_count' => 1, 'authorizations' => [[
        'sub' => 't9u4', 'session_version' => '2.7', 'extra' => true,
    ]]],
    'extra top-level field' => [...$versionedPayload, 'extra' => true],
];
foreach ($malformedV2 as $label => $payload) {
    $snapshot = suite_revocation_snapshot_from_payload($payload, $now);
    suite_revocation_check(
        is_array($snapshot) && $snapshot['mode'] === 'invalid',
        "{$label} was confused with legacy absence",
    );
    suite_revocation_check(
        suite_revocation_decision($snapshot, 't9u4', '2.7')
            === ['action' => 'reauth', 'reason' => 'authorization_inventory_invalid'],
        "{$label} did not fail closed",
    );
}
$overlap = [
    ...$revokedPayload,
    'authorization_count' => 1,
    'authorizations' => [['sub' => 't9u4', 'session_version' => '2.7']],
];
suite_revocation_check(
    suite_revocation_snapshot_from_payload($overlap, $now)['mode'] === 'invalid',
    'revoked/authorized overlap was accepted',
);
$extraLegacy = [...$legacyPayload, 'unexpected' => true];
suite_revocation_check(
    suite_revocation_snapshot_from_payload($extraLegacy, $now) === null,
    'unknown legacy top-level field was accepted',
);
suite_revocation_check(
    suite_revocation_snapshot_from_payload(
        [...$versionedPayload, 'generated_at' => gmdate('c', $now - SUITE_REVOCATION_MAX_AGE - 1)],
        $now,
    ) === null,
    'stale v2 body was retained as timeless authorization state',
);

suite_revocation_check(
    suite_revocation_session_version_mode('compat') === 'compat'
        && suite_revocation_session_version_mode('strict') === 'strict',
    'supported modes were rejected',
);
foreach (['automatic', 'COMPAT', '', null] as $mode) {
    suite_revocation_check(
        suite_revocation_session_version_mode($mode) === null,
        'unknown mode did not fail closed: ' . var_export($mode, true),
    );
}
suite_revocation_check(
    suite_revocation_apply_mode($legacy, 'compat', false)['mode'] === 'legacy',
    'compat rejected legacy before v2 was observed',
);
suite_revocation_check(
    suite_revocation_apply_mode($legacy, 'compat', true)['mode'] === 'invalid',
    'compat accepted a legacy downgrade after v2 observation',
);
suite_revocation_check(
    suite_revocation_apply_mode($legacy, 'strict', false)['mode'] === 'invalid',
    'strict accepted legacy after cache loss/reboot',
);

$body = json_encode($versionedPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$signature = hash_hmac('sha256', $body, $secret);
$verified = suite_revocation_verify_signed_body($body, $signature, $secret, $now);
suite_revocation_check(
    $verified['status'] === 'ok' && $verified['snapshot']['mode'] === 'versioned',
    'correctly signed v2 bytes did not verify',
);
suite_revocation_check(
    suite_revocation_verify_signed_body($body, str_repeat('0', 64), $secret, $now)['status']
        === 'untrusted',
    'bad issuer signature was trusted',
);
$staleBody = json_encode([
    ...$versionedPayload,
    'generated_at' => gmdate('c', $now - SUITE_REVOCATION_MAX_AGE - 1),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
suite_revocation_check(
    suite_revocation_verify_signed_body(
        $staleBody,
        hash_hmac('sha256', $staleBody, $secret),
        $secret,
        $now,
    )['status'] === 'stale',
    'signed stale v2 was confused with a malformed deny-all inventory',
);
$scalarBody = '[]';
suite_revocation_check(
    suite_revocation_verify_signed_body(
        $scalarBody,
        hash_hmac('sha256', $scalarBody, $secret),
        $secret,
        $now,
    )['status'] === 'signed_invalid',
    'signed malformed JSON was not distinguished from an untrusted response',
);

$cache = suite_revocation_cache_envelope($now, false, $body, $signature, $secret);
suite_revocation_check(
    $cache['versioned_observed'] === true
        && suite_revocation_cached_envelope($cache, $secret, $now) !== null,
    'authenticated cache or v2 latch was rejected',
);
foreach (['fetched_at', 'versioned_observed', 'body', 'signature'] as $field) {
    $tampered = $cache;
    $tampered[$field] = match ($field) {
        'fetched_at' => $now - 1,
        'versioned_observed' => false,
        'body' => str_replace('"2.7"', '"9.9"', $body),
        'signature' => str_repeat('0', 64),
    };
    suite_revocation_check(
        suite_revocation_cached_envelope($tampered, $secret, $now) === null,
        "cache metadata/body tamper was trusted: {$field}",
    );
}
$extraCache = $cache;
$extraCache['unexpected'] = true;
suite_revocation_check(
    suite_revocation_cached_envelope($extraCache, $secret, $now) === null,
    'cache with extra fields was trusted',
);

$authSource = (string) file_get_contents(__DIR__ . '/../lib/auth.php');
$revocationSource = (string) file_get_contents(__DIR__ . '/../lib/revocation.php');
$loginSource = (string) file_get_contents(__DIR__ . '/../public/login.php');
$gate = strpos($authSource, '$authorizationSnapshot = revocation_list();');
$firstTenantWrite = strpos($authSource, 'INSERT INTO tenants');
suite_revocation_check(
    $gate !== false && $firstTenantWrite !== false && $gate < $firstTenantWrite,
    'fresh authorization gate is not before the first admission write',
);
suite_revocation_check(
    str_contains($authSource, "suite_sso_refuse('authorization_feed_unavailable'")
        && str_contains($authSource, "\$_SESSION['suite_session_version'] = \$sessionVersion"),
    'fail-closed admission or immutable local version pin is missing',
);
suite_revocation_check(
    substr_count($authSource, "\$_SESSION['suite_session_version'] =") === 1,
    'local authorization version can change outside fresh suite sign-in',
);
suite_revocation_check(
    str_contains($revocationSource, 'suite_revocation_cached_envelope')
        && str_contains($revocationSource, "'suite-revocations'")
        && str_contains($revocationSource, '@chmod($directory, 0700)')
        && str_contains($revocationSource, '@chmod($path, 0600)')
        && str_contains($revocationSource, '@flock($lock, LOCK_EX)'),
    'private authenticated cache wiring is incomplete',
);
suite_revocation_check(
    str_contains($revocationSource, "cfg('suite.session_version_mode', null)")
        && str_contains($revocationSource, "return 'downgrade';"),
    'explicit mode or monotonic concurrent cutover guard is missing',
);
suite_revocation_check(
    str_contains($loginSource, '$user = current_user();')
        && str_contains($loginSource, 'if ($user && ! $reauthRequired)')
        && str_contains($loginSource, "preg_match('/^\\/(?![\\/\\\\\\\\])/D'"),
    'login redirects without resolving the newly admitted local user',
);

fwrite(STDOUT, "suite revocation: {$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
