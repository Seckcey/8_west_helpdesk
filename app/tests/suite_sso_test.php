<?php
/**
 * Hermetic tests for 8 West ID suite sign-in.
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live one.
 * The scratch db must exist and be owned by the app MySQL user:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
 *
 * Run:  php app/tests/suite_sso_test.php        (from the repo root)
 * Exit: 0 = all green, 1 = failures.
 *
 * What these pin down: a person is identified by their immutable 8 West ID
 * subject, not by their email address. Keying on email splits one human into
 * two accounts the moment they change their address — which is the bug this
 * suite already carries in Milepost and carried here.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';

$CONFIG['db']['name'] = 'safeharbor_test';
$CONFIG['suite'] = [
    'sso_secret' => 'suite-sso-scratch-secret-0123456789',
    'issuer' => 'https://id.8westit.com',
    'cookie_name' => 'ewid_token',
];

require_once __DIR__ . '/../lib/auth.php';

$failures = 0;
$checks = 0;

function check(string $what, bool $ok): void
{
    global $failures, $checks;
    $checks++;
    if (! $ok) {
        $failures++;
        fwrite(STDERR, "FAIL: {$what}\n");

        return;
    }
    fwrite(STDOUT, "ok: {$what}\n");
}

/** Mint a token the verifier will accept. */
function scratch_token(array $overrides = []): string
{
    global $CONFIG;

    $claims = array_merge([
        'iss' => $CONFIG['suite']['issuer'],
        'exp' => time() + 900,
        'sub' => 't9u1',
        'email' => 'owner@scratch.test',
        'name' => 'Scratch Owner',
        '8west:role' => 'owner',
        '8west:tenant' => 'scratch-co',
        '8west:products' => ['safeharbor'],
    ], $overrides);

    $enc = fn (array $p): string => rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');
    $header = $enc(['alg' => 'HS256', 'typ' => 'JWT']);
    $body = $enc($claims);
    $sig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $header . '.' . $body, $CONFIG['suite']['sso_secret'], true)
    ), '+/', '-_'), '=');

    return $header . '.' . $body . '.' . $sig;
}

function arrive(array $overrides = []): bool
{
    $_COOKIE['ewid_token'] = scratch_token($overrides);
    $_SESSION = [];

    return suite_sso_attempt();
}

// Fresh scratch state.
db()->exec('DELETE FROM users');
db()->exec('DELETE FROM tenants');

// --- The tenant is provisioned on arrival, so granting the tile is the only
//     step needed to give somebody access.
check('first arrival signs in', arrive() === true);
$tenant = db()->query("SELECT COUNT(*) FROM tenants WHERE slug = 'scratch-co'")->fetchColumn();
check('tenant was provisioned on first arrival', (int) $tenant === 1);
check('exactly one user exists', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);

$subject = db()->query('SELECT suite_subject FROM users LIMIT 1')->fetchColumn();
check('the user carries its subject immediately', $subject === 't9u1');

// --- The whole point: a changed address must not mint a second account.
check('same subject, new email, signs in', arrive(['email' => 'renamed@scratch.test']) === true);
check('still exactly one user', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
$email = db()->query('SELECT email FROM users LIMIT 1')->fetchColumn();
check('the address was updated in place', $email === 'renamed@scratch.test');

// --- An account created before suite entry is claimed once by email.
db()->exec('DELETE FROM users');
$tenantId = (int) db()->query("SELECT id FROM tenants WHERE slug = 'scratch-co'")->fetchColumn();
$legacy = db()->prepare('INSERT INTO users (tenant_id, email, password_hash, full_name, initials, role)
                         VALUES (?, ?, ?, ?, ?, ?)');
$legacy->execute([$tenantId, 'legacy@scratch.test', password_hash('x', PASSWORD_DEFAULT), 'Legacy User', 'LU', 'tech']);

check('legacy account signs in', arrive(['sub' => 't9u7', 'email' => 'legacy@scratch.test']) === true);
check('legacy account was claimed, not duplicated', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
check(
    'legacy account was backfilled with its subject',
    db()->query('SELECT suite_subject FROM users LIMIT 1')->fetchColumn() === 't9u7'
);

// --- Refusals.
check('a token without the safeharbor entitlement is refused', arrive(['8west:products' => ['milepost']]) === false);

// readonly is a STAFF role, so this is an app-capability refusal rather than
// the 4.3 customer/staff crossing: Safeharbor has no viewer role, and the
// mapping used to fall through to 'tech' - which can write. Founder decision
// 2026-08-02: refuse rather than silently upgrade.
check('a readonly role is refused rather than upgraded to tech', arrive(['8west:role' => 'readonly']) === false);
check('an unknown role string is refused', arrive(['8west:role' => 'wizard']) === false);
$before = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
arrive(['8west:role' => 'readonly', 'sub' => 't9u99', 'email' => 'readonly@scratch.test']);
check(
    'a refused readonly identity is never provisioned',
    (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before
);
check('a reserved tenant slug is refused', arrive(['8west:tenant' => '8west']) === false);
check('an expired token is refused', arrive(['exp' => time() - 60]) === false);
check('a wrong-issuer token is refused', arrive(['iss' => 'https://evil.test']) === false);

$_COOKIE['ewid_token'] = 'not-a-token';
check('a malformed token is refused', suite_sso_attempt() === false);

fwrite(STDOUT, "\n{$checks} checks, {$failures} failures\n");
exit($failures === 0 ? 0 : 1);
