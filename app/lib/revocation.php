<?php
/**
 * Honour 8 West ID's revocation list, so "disable" means now.
 *
 * The problem: this app starts its own PHP session once the suite token has
 * been verified, and `current_user()` reads that session from then on. So
 * disabling somebody in 8 West ID did nothing here until their session ended
 * — the eight-hour token life was not even the binding constraint. Somebody
 * cut off at 9am could still be reading tickets at 6pm.
 *
 * The fix: 8 West ID publishes everybody currently cut off at
 * /suite-revocations.php, signed with the shared suite secret. This checks the
 * signed-in person against that list on every request, using a cached copy so
 * the cost is a file read rather than an HTTP call.
 *
 * Design notes worth keeping:
 *
 * - Fails OPEN, deliberately. If 8 West ID is unreachable or the signature is
 *   wrong, people keep working. The alternative locks every customer out of
 *   the helpdesk whenever the issuer has a bad minute, which is a far worse
 *   failure than a revocation taking a few extra minutes. The staleness is
 *   bounded and logged.
 * - Verifies the HMAC before acting. An unsigned or wrongly signed list is
 *   ignored entirely — a forged empty list would silently switch revocation
 *   off, and a forged full one would sign everybody out.
 * - Refuses a body older than REVOCATION_MAX_AGE, so a captured old snapshot
 *   from before somebody was revoked cannot be replayed.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const REVOCATION_URL = 'https://id.8westit.com/suite-revocations.php';
const REVOCATION_CACHE_TTL = 60;      // seconds between fetches
const REVOCATION_MAX_AGE = 900;       // refuse a signed body older than this

function revocation_cache_path(): string
{
    return sys_get_temp_dir() . '/safeharbor-suite-revocations.json';
}

/**
 * The current revocation list, or null if we could not get a trustworthy one.
 *
 * @return array<string, array{since: string, reason: string}>|null
 */
function revocation_list(): ?array
{
    $path = revocation_cache_path();

    if (is_file($path) && (time() - (int) filemtime($path)) < REVOCATION_CACHE_TTL) {
        $cached = json_decode((string) @file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $secret = (string) cfg('suite.sso_secret', '');
    if ($secret === '') {
        return null;
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 3,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ]]);

    $body = @file_get_contents(REVOCATION_URL, false, $context);
    if ($body === false) {
        error_log('suite revocation: could not reach the issuer; failing open');

        return null;
    }

    // The signature travels in a header; find it among the response headers.
    $signature = '';
    foreach ($http_response_header ?? [] as $line) {
        if (stripos($line, 'X-Suite-Signature:') === 0) {
            $signature = trim(substr($line, strlen('X-Suite-Signature:')));
        }
    }

    if ($signature === '' || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
        error_log('suite revocation: signature did not verify; ignoring the list');

        return null;
    }

    $parsed = json_decode($body, true);
    if (! is_array($parsed) || ! isset($parsed['revoked'], $parsed['generated_at'])) {
        error_log('suite revocation: unexpected body; ignoring');

        return null;
    }

    $age = time() - strtotime((string) $parsed['generated_at']);
    if ($age > REVOCATION_MAX_AGE) {
        error_log('suite revocation: list is ' . $age . 's old; ignoring as stale');

        return null;
    }

    $bySubject = [];
    foreach ($parsed['revoked'] as $entry) {
        if (isset($entry['sub'])) {
            $bySubject[(string) $entry['sub']] = [
                'since' => (string) ($entry['since'] ?? ''),
                'reason' => (string) ($entry['reason'] ?? 'revoked'),
            ];
        }
    }

    @file_put_contents($path, json_encode($bySubject), LOCK_EX);
    @chmod($path, 0600);

    return $bySubject;
}

/**
 * If the signed-in person has been cut off, end their session here and now.
 *
 * Called from require_login() on every authenticated request. Deactivates the
 * local row too, so the effect survives even if the issuer is unreachable on
 * the next request.
 */
function enforce_revocation(array $user): void
{
    $subject = (string) ($user['suite_subject'] ?? '');
    if ($subject === '') {
        // Local-only account, never came through the suite. Nothing to check.
        return;
    }

    $list = revocation_list();
    if ($list === null || ! isset($list[$subject])) {
        return;
    }

    error_log('suite revocation: signing out ' . $subject . ' (' . $list[$subject]['reason'] . ')');

    db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $user['id']]);

    $_SESSION = [];
    session_destroy();

    header('Location: /login.php?revoked=1');
    exit;
}
