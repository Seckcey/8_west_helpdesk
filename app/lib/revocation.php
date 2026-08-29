<?php
/** Signed 8 West ID authorization feed for Safeharbor suite sessions. */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/suite_revocation_policy.php';

const REVOCATION_CACHE_TTL = 60;

function revocation_absolute_path_valid(string $path): bool
{
    return $path !== ''
        && strlen($path) <= 4096
        && ! str_contains($path, "\0")
        && (str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1);
}

function revocation_cache_path(): ?string
{
    $configured = cfg('suite.revocation_cache_path', '');
    if (is_string($configured) && $configured !== '') {
        return revocation_absolute_path_valid($configured) ? $configured : null;
    }

    $attachments = (string) cfg(
        'storage.attachments_dir',
        '/srv/8west/apps/safeharbor/shared/attachments',
    );
    if (! revocation_absolute_path_valid($attachments)) {
        return null;
    }

    return dirname(rtrim($attachments, '/\\'))
        . DIRECTORY_SEPARATOR . 'suite-revocations'
        . DIRECTORY_SEPARATOR . 'snapshot-v3.json';
}

function revocation_feed_url(): ?string
{
    $issuer = trim((string) cfg('suite.issuer', 'https://id.8westit.com'));
    $url = trim((string) cfg(
        'suite.revocation_url',
        rtrim($issuer, '/') . '/suite-revocations.php',
    ));
    $issuerParts = parse_url($issuer);
    $urlParts = parse_url($url);
    if (! is_array($issuerParts)
        || ! is_array($urlParts)
        || strtolower((string) ($issuerParts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($urlParts['scheme'] ?? '')) !== 'https'
        || empty($issuerParts['host'])
        || empty($urlParts['host'])
        || strcasecmp((string) $issuerParts['host'], (string) $urlParts['host']) !== 0
        || (int) ($issuerParts['port'] ?? 443) !== (int) ($urlParts['port'] ?? 443)
        || isset($urlParts['user'])
        || isset($urlParts['pass'])
        || isset($urlParts['query'])
        || isset($urlParts['fragment'])) {
        return null;
    }

    return $url;
}

/** @return array{status:int,body:string,headers:list<string>}|null */
function revocation_http_fetch(string $url): ?array
{
    if (PHP_SAPI === 'cli'
        && defined('SAFEHARBOR_AUTH_HERMETIC_TEST')
        && SAFEHARBOR_AUTH_HERMETIC_TEST === true) {
        $fetcher = $GLOBALS['__SAFEHARBOR_REVOCATION_FETCH'] ?? null;

        return is_callable($fetcher) ? $fetcher($url) : null;
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 3,
        'ignore_errors' => true,
        'follow_location' => 0,
        'header' => "Accept: application/json\r\nConnection: close\r\n",
    ], 'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ]]);
    $body = @file_get_contents(
        $url,
        false,
        $context,
        0,
        SUITE_REVOCATION_MAX_BODY_BYTES + 1,
    );
    $headers = isset($http_response_header) && is_array($http_response_header)
        ? array_values(array_filter($http_response_header, 'is_string'))
        : [];
    if (! is_string($body) || strlen($body) > SUITE_REVOCATION_MAX_BODY_BYTES) {
        return null;
    }

    $status = 0;
    foreach ($headers as $line) {
        if (preg_match('/^HTTP\/\S+\s+([0-9]{3})(?:\s|$)/i', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

/** @param list<string> $headers */
function revocation_signature_from_headers(array $headers): ?string
{
    $signatures = [];
    foreach ($headers as $line) {
        if (stripos($line, 'X-Suite-Signature:') === 0) {
            $signatures[] = trim(substr($line, strlen('X-Suite-Signature:')));
        }
    }

    return count($signatures) === 1 ? $signatures[0] : null;
}

/** @return array|null */
function revocation_read_cache(string $path, string $secret, int $now): ?array
{
    if (! is_file($path) || is_link($path)) {
        return null;
    }
    $encoded = @file_get_contents(
        $path,
        false,
        null,
        0,
        (SUITE_REVOCATION_MAX_BODY_BYTES * 2) + 16384,
    );
    if (! is_string($encoded)) {
        return null;
    }
    try {
        $value = json_decode($encoded, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return suite_revocation_cached_envelope($value, $secret, $now);
}

function revocation_write_cache(
    string $path,
    int $fetchedAt,
    bool $versionedObserved,
    string $body,
    string $signature,
    string $secret,
    string $snapshotMode,
): string {
    if (! in_array($snapshotMode, ['legacy', 'versioned', 'invalid'], true)) {
        return 'failed';
    }
    $directory = dirname($path);
    if (is_link($directory)) {
        return 'failed';
    }
    if (! is_dir($directory)
        && ! @mkdir($directory, 0700, true)
        && ! is_dir($directory)) {
        return 'failed';
    }
    @chmod($directory, 0700);
    if (is_link($path)) {
        return 'failed';
    }

    $lockPath = $path . '.lock';
    if (is_link($lockPath)) {
        return 'failed';
    }
    $lock = @fopen($lockPath, 'c+b');
    if (! is_resource($lock)) {
        return 'failed';
    }
    @chmod($lockPath, 0600);

    try {
        if (! @flock($lock, LOCK_EX)) {
            return 'failed';
        }

        // Re-read only after obtaining the lock. A request that fetched a
        // legacy response before another request committed v2 must observe
        // the newer latch here and may never overwrite it.
        $current = revocation_read_cache($path, $secret, $fetchedAt);
        $versionedObserved = $versionedObserved
            || (bool) ($current['versioned_observed'] ?? false)
            || suite_revocation_body_mentions_versioned($body);
        if ($snapshotMode === 'legacy' && $versionedObserved) {
            return 'downgrade';
        }

        try {
            $encoded = json_encode(
                suite_revocation_cache_envelope(
                    $fetchedAt,
                    $versionedObserved,
                    $body,
                    $signature,
                    $secret,
                ),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return 'failed';
        }

        $temp = $directory . DIRECTORY_SEPARATOR
            . '.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (@file_put_contents($temp, $encoded, LOCK_EX) === false) {
                return 'failed';
            }
            @chmod($temp, 0600);
            if (! @rename($temp, $path)) {
                return 'failed';
            }
            @chmod($path, 0600);

            return 'written';
        } finally {
            @unlink($temp);
        }
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/**
 * Return a current trustworthy snapshot. Established sessions retain the
 * historical availability choice on network/signature failure; new suite
 * admission treats null as a hard refusal before any local write.
 *
 * @return array{generated_at:int,mode:string,revoked:array,authorizations:array}|null
 */
function revocation_list(): ?array
{
    $now = time();
    $mode = suite_revocation_session_version_mode(
        cfg('suite.session_version_mode', null),
    );
    if ($mode === null) {
        error_log('suite revocation: invalid session-version mode; failing closed');

        return suite_revocation_invalid_snapshot($now);
    }

    $secret = (string) cfg('suite.sso_secret', '');
    $path = revocation_cache_path();
    $url = revocation_feed_url();
    if ($secret === '' || $path === null || $url === null) {
        error_log('suite revocation: configuration unavailable');

        return null;
    }

    $cached = revocation_read_cache($path, $secret, $now);
    $versionedObserved = $mode === 'strict'
        || (bool) ($cached['versioned_observed'] ?? false);
    $cachedSnapshot = null;
    if ($cached !== null) {
        $verified = suite_revocation_verify_signed_body(
            $cached['body'],
            $cached['signature'],
            $secret,
            $now,
        );
        if ($verified['status'] === 'ok') {
            $cachedSnapshot = suite_revocation_apply_mode(
                $verified['snapshot'],
                $mode,
                $versionedObserved,
            );
        } elseif ($verified['status'] === 'signed_invalid'
            && $versionedObserved
            && $cached['fetched_at'] >= $now - SUITE_REVOCATION_MAX_AGE) {
            $cachedSnapshot = suite_revocation_invalid_snapshot($now);
        }
        if ($cachedSnapshot !== null
            && $cached['fetched_at'] >= $now - REVOCATION_CACHE_TTL) {
            return $cachedSnapshot;
        }
    }

    $response = revocation_http_fetch($url);
    if (! is_array($response)
        || ($response['status'] ?? null) !== 200
        || ! is_string($response['body'] ?? null)
        || ! is_array($response['headers'] ?? null)) {
        error_log('suite revocation: could not reach the issuer; using only a current signed cache');

        return $cachedSnapshot;
    }

    $signature = revocation_signature_from_headers($response['headers']);
    if ($signature === null) {
        error_log('suite revocation: signature header missing or duplicated');

        return $cachedSnapshot;
    }

    $verified = suite_revocation_verify_signed_body(
        $response['body'],
        $signature,
        $secret,
        $now,
    );
    if ($verified['status'] === 'untrusted') {
        error_log('suite revocation: signature did not verify');

        return $cachedSnapshot;
    }
    if ($verified['status'] === 'stale') {
        error_log('suite revocation: signed body is stale; preserving established-session availability');

        return null;
    }
    if ($verified['status'] !== 'ok') {
        // A correctly signed but malformed response never revives an older
        // compatibility snapshot. If it attempted v2, retain that one-way
        // fact and deny admission/session continuation for this bounded cache.
        if ($versionedObserved || suite_revocation_body_mentions_versioned($response['body'])) {
            revocation_write_cache(
                $path,
                $now,
                true,
                $response['body'],
                $signature,
                $secret,
                'invalid',
            );
            error_log('suite revocation: signed versioned body is malformed; failing closed');

            return suite_revocation_invalid_snapshot($now);
        }
        error_log('suite revocation: signed legacy body is malformed or stale');

        return null;
    }

    $snapshot = $verified['snapshot'];
    $nextVersionedObserved = $versionedObserved
        || in_array($snapshot['mode'], ['versioned', 'invalid'], true);
    $snapshot = suite_revocation_apply_mode(
        $snapshot,
        $mode,
        $versionedObserved,
    );
    if ($snapshot['mode'] === 'invalid'
        && $verified['snapshot']['mode'] === 'legacy') {
        error_log('suite revocation: signed legacy downgrade refused');

        return $snapshot;
    }

    $cacheCommit = revocation_write_cache(
        $path,
        $now,
        $nextVersionedObserved,
        $response['body'],
        $signature,
        $secret,
        $verified['snapshot']['mode'],
    );
    if ($cacheCommit === 'downgrade') {
        error_log('suite revocation: concurrent signed legacy downgrade refused');

        return suite_revocation_invalid_snapshot($snapshot['generated_at']);
    }
    if (in_array($verified['snapshot']['mode'], ['versioned', 'invalid'], true)
        && $cacheCommit !== 'written') {
        // Compatibility must never authorize from v2 unless the monotonic
        // latch was durably committed. Strict already refuses legacy without
        // relying on the cache, but the same fail-closed outcome is clearest.
        error_log('suite revocation: versioned latch could not be committed; failing closed');

        return suite_revocation_invalid_snapshot($snapshot['generated_at']);
    }
    if ($snapshot['mode'] === 'invalid') {
        error_log('suite revocation: signed authorization inventory is invalid; failing closed');
    }

    return $snapshot;
}

function suite_revocation_end_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

/** Enforce revocation/version changes on an established local suite session. */
function enforce_revocation(array $user): void
{
    $subject = (string) ($user['suite_subject'] ?? '');
    if ($subject === '') {
        return;
    }

    $snapshot = revocation_list();
    if ($snapshot === null) {
        // Existing sessions keep working through an issuer outage. New suite
        // admission is separately fail-closed before any local write.
        return;
    }
    $decision = suite_revocation_decision(
        $snapshot,
        $subject,
        $_SESSION['suite_session_version'] ?? null,
    );
    if ($decision['action'] === 'allow') {
        return;
    }

    error_log('suite revocation: signing out ' . $subject . ' (' . $decision['reason'] . ')');
    if ($decision['action'] === 'revoked') {
        db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')
            ->execute([(int) $user['id']]);
    }
    suite_revocation_end_session();
    header('Location: /login.php?' . ($decision['action'] === 'revoked' ? 'revoked=1' : 'reauth=1'));
    exit;
}
