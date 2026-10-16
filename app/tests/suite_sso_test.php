<?php
/**
 * Hermetic tests for 8 West ID suite sign-in.
 *
 * CLI only. Runs against a dedicated scratch database — NEVER the live one.
 * The scratch db must exist and be owned by the app MySQL user:
 *   CREATE DATABASE safeharbor_test;
 *   GRANT ALL PRIVILEGES ON safeharbor_test.* TO 'safeharbor'@'localhost';
 *
 * Run only against a disposable MySQL server; this test creates and drops an
 * exact randomly suffixed scratch database.
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

if (getenv('SAFEHARBOR_SSO_TEST_DISPOSABLE_SERVER') !== '1') {
    fwrite(STDERR, "Refusing suite SSO MySQL test without disposable-server acknowledgement.\n");
    exit(2);
}
$databaseBase = getenv('SAFEHARBOR_SSO_TEST_DB');
if (! is_string($databaseBase)
    || preg_match('/\Asafeharbor_sso_test(?:_[a-z0-9_]+)?\z/', $databaseBase) !== 1
    || strlen($databaseBase) > 48) {
    fwrite(STDERR, "Refusing unsafe suite SSO test database name.\n");
    exit(2);
}
$testDatabase = $databaseBase . '_' . bin2hex(random_bytes(6));
$testHost = getenv('SAFEHARBOR_SSO_TEST_HOST') ?: '127.0.0.1';
$testPort = getenv('SAFEHARBOR_SSO_TEST_PORT') ?: '3306';
$testUser = getenv('SAFEHARBOR_SSO_TEST_USER') ?: 'root';
$testPass = getenv('SAFEHARBOR_SSO_TEST_PASS') ?: '';
if (! is_string($testHost) || trim($testHost) === ''
    || ! is_string($testPort) || ! ctype_digit($testPort)
    || (int) $testPort < 1 || (int) $testPort > 65535
    || ! is_string($testUser) || $testUser === '') {
    fwrite(STDERR, "Refusing invalid suite SSO MySQL settings.\n");
    exit(2);
}
$serverDsn = "mysql:host={$testHost};port={$testPort};charset=utf8mb4";
$server = new PDO($serverDsn, $testUser, $testPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$quotedDatabase = '`' . str_replace('`', '``', $testDatabase) . '`';
$server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
register_shutdown_function(static function () use ($server, $quotedDatabase): void {
    try {
        $server->exec("DROP DATABASE IF EXISTS {$quotedDatabase}");
    } catch (Throwable) {
        // The test result remains authoritative; CI teardown removes the host.
    }
});

define('SAFEHARBOR_AUTH_HERMETIC_TEST', true);
$GLOBALS['__SAFEHARBOR_REVOCATION_FETCH'] = static fn (string $url): ?array => null;

require_once __DIR__ . '/../lib/bootstrap.php';

$CONFIG['db'] = [
    'host' => $testHost,
    'port' => (int) $testPort,
    'name' => $testDatabase,
    'user' => $testUser,
    'pass' => $testPass,
    'charset' => 'utf8mb4',
];
$suiteRevocationDir = sys_get_temp_dir() . '/safeharbor-suite-sso-test-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
@mkdir($suiteRevocationDir, 0700, true);
$CONFIG['suite'] = [
    'sso_secret' => 'suite-sso-scratch-secret-0123456789',
    'issuer' => 'https://id.8westit.com',
    'cookie_name' => 'ewid_token',
    'revocation_cache_path' => $suiteRevocationDir . '/snapshot-v3.json',
    'session_version_mode' => 'compat',
];

require_once __DIR__ . '/../lib/auth.php';

/** Test fixture provisioning only; runtime must never repair unsafe files. */
function write_private_suite_cache_fixture(string $path, string $encoded): void
{
    if (file_put_contents($path, $encoded) !== strlen($encoded) || !chmod($path, 0600)) {
        throw new RuntimeException('Private suite cache fixture could not be provisioned.');
    }
}

function write_suite_revocation_fixture(array $payload): void
{
    global $CONFIG;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $body, $CONFIG['suite']['sso_secret']);
    $envelope = suite_revocation_cache_envelope(
        time(),
        suite_revocation_body_mentions_versioned($body),
        $body,
        $signature,
        $CONFIG['suite']['sso_secret'],
    );
    write_private_suite_cache_fixture(
        $CONFIG['suite']['revocation_cache_path'],
        json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

write_suite_revocation_fixture([
    'generated_at' => gmdate('c'),
    'count' => 0,
    'revoked' => [],
]);

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

/** @return list<string> */
function suite_sso_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) {
            continue;
        }
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('DELIMITER changed with pending SQL.');
            }
            $delimiter = $match[1];
            continue;
        }
        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if (! str_ends_with($trimmed, $delimiter)) {
            continue;
        }
        $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Unterminated SQL statement.');
    }

    return $statements;
}

function suite_sso_execute_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if (! is_string($sql)) {
        throw new RuntimeException("Cannot read {$path}");
    }
    foreach (suite_sso_sql_statements($sql) as $statement) {
        $withoutComments = preg_replace('/\A(?:\s*--[^\n]*(?:\n|\z))+/', '', $statement) ?? $statement;
        if (preg_match('/^\s*SELECT\b/i', $withoutComments) === 1) {
            $result = $pdo->query($statement);
            $result->fetchAll();
            $result->closeCursor();
        } else {
            $pdo->exec($statement);
        }
    }
}

/** Mint a token the verifier will accept unless an override is the subject of a deny test. */
function scratch_token(array $overrides = [], array $headerOverrides = []): string
{
    global $CONFIG;

    $claims = array_merge([
        'iss' => $CONFIG['suite']['issuer'],
        'exp' => time() + 900,
        'sub' => 't9u1',
        'email' => 'owner@scratch.test',
        'name' => 'Scratch Owner',
        '8west:role' => 'msp_owner',
        '8west:tenant' => 'scratch-co',
        '8west:products' => ['safeharbor'],
        '8west:session_version' => '1.1',
    ], $overrides);
    if (($claims['__omit_session_version'] ?? false) === true) {
        unset($claims['__omit_session_version'], $claims['8west:session_version']);
    }

    $enc = fn (array $p): string => rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');
    $header = $enc(array_replace(['alg' => 'HS256', 'typ' => 'JWT'], $headerOverrides));
    $body = $enc($claims);
    $sig = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $header . '.' . $body, $CONFIG['suite']['sso_secret'], true)
    ), '+/', '-_'), '=');

    return $header . '.' . $body . '.' . $sig;
}

function arrive(array $overrides = [], array $headerOverrides = []): bool
{
    $_COOKIE['ewid_token'] = scratch_token($overrides, $headerOverrides);
    $_SESSION = [];

    if (! suite_sso_attempt()) {
        return false;
    }

    // A real authenticated request immediately resolves current_user() after
    // suite_sso_attempt(). That second step refreshes the signed preferences
    // and subject-bound avatar into the session. Stopping after the raw login
    // helper made the avatar assertion test a path the application never uses.
    return current_user() !== null;
}

function suite_sso_admission_state_digest(): string
{
    $tenants = db()->query('SELECT * FROM tenants ORDER BY id')->fetchAll();
    $users = db()->query('SELECT * FROM users ORDER BY id')->fetchAll();

    return hash('sha256', json_encode([$tenants, $users], JSON_THROW_ON_ERROR));
}

/** Prove a denied token creates no local user/tenant write and no session. */
function denied_without_admission(array $claims, array $headerOverrides = []): bool
{
    $before = suite_sso_admission_state_digest();
    $_COOKIE['ewid_token'] = scratch_token($claims, $headerOverrides);
    $_SESSION = [];
    $accepted = suite_sso_attempt();

    return ! $accepted
        && $_SESSION === []
        && hash_equals($before, suite_sso_admission_state_digest());
}

// Fresh scratch state.
//
// This used to be two bare DELETEs, which meant the suite only passed when it
// ran FIRST: it assumed the tables already existed AND that nothing else had
// left a row pointing at a tenant. Run it after any other suite and it died on
// a foreign key ("Cannot delete or update a parent row") instead of reporting a
// real result. Build the schema if it is missing, and clear in a way that does
// not depend on what ran before.
// Build only when the scratch database is actually empty: 002 carries an
// ALTER TABLE, which is not safe to replay over an existing schema.
$hasSchema = (int)db()->query(
    "SELECT COUNT(*) FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'tickets'"
)->fetchColumn() > 0;
if (!$hasSchema) {
    foreach (['schema.sql', 'migrations/002_svc_intake.sql', 'migrations/008_westy_reports.sql'] as $f) {
        suite_sso_execute_sql_file(db(), __DIR__ . '/../db/' . $f);
    }
} else {
    $hasGoalTarget = (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE()
            AND table_name = 'tickets'
            AND column_name = 'service_goal_target_id'"
    )->fetchColumn() > 0;
    if (! $hasGoalTarget) {
        suite_sso_execute_sql_file(db(), __DIR__ . '/../db/migrations/010_service_goal_policies.sql');
    }
}
db()->exec('SET FOREIGN_KEY_CHECKS=0');
db()->exec('TRUNCATE TABLE service_goal_policy_targets');
db()->exec('TRUNCATE TABLE service_goal_policy_versions');
foreach ([
    'westy_reports',
    'messages',
    'tickets',
    'contacts',
    'clients',
    'users',
    'tenants',
] as $t) {
    db()->exec('DELETE FROM `' . $t . '`');
}
db()->exec('SET FOREIGN_KEY_CHECKS=1');

// --- The tenant is provisioned on arrival, so granting the tile is the only
//     step needed to give somebody access.
check('first arrival signs in', arrive() === true);
$tenant = db()->query("SELECT COUNT(*) FROM tenants WHERE slug = 'scratch-co'")->fetchColumn();
check('tenant was provisioned on first arrival', (int) $tenant === 1);
$signedInTenant = db()->query("SELECT id FROM tenants WHERE slug = 'scratch-co'")->fetchColumn();
check('the authenticated tenant is stamped into the session',
    (int)($_SESSION['tenant_id'] ?? 0) === (int)$signedInTenant);
check('the exact cookie authorization version is stamped into the session',
    ($_SESSION['suite_session_version'] ?? null) === '1.1');
check('exactly one user exists', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
check('msp owner maps to local owner', db()->query('SELECT role FROM users LIMIT 1')->fetchColumn() === 'owner');

$subject = db()->query('SELECT suite_subject FROM users LIMIT 1')->fetchColumn();
check('the user carries its subject immediately', $subject === 't9u1');
$_COOKIE['ewid_token'] = scratch_token(['8west:session_version' => '1.2']);
check('a same-subject replacement cookie cannot silently upgrade the local authorization version',
    current_user() !== null && ($_SESSION['suite_session_version'] ?? null) === '1.1');
$_COOKIE['ewid_token'] = scratch_token(['sub' => 't9u88', '8west:session_version' => '9.9']);
check('a different subject cookie cannot replace the local authorization version',
    current_user() !== null && ($_SESSION['suite_session_version'] ?? null) === '1.1');
check('legacy cookie signs in before the authorization inventory appears',
    arrive(['__omit_session_version' => true]) === true && ! isset($_SESSION['suite_session_version']));
check('avatar refresh signs in', arrive(['8west:avatar' => 'https://id.8westit.com/uploads/avatar.png']) === true);
check('avatar is bound to the immutable subject',
    ($_SESSION['suite_avatar_subject'] ?? null) === 't9u1'
    && ! isset($_SESSION['suite_avatar_email']));

// --- The whole point: a changed address must not mint a second account.
check('same subject, new email, signs in', arrive(['email' => 'renamed@scratch.test']) === true);
check('still exactly one user', (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
$email = db()->query('SELECT email FROM users LIMIT 1')->fetchColumn();
check('the address was updated in place', $email === 'renamed@scratch.test');
check('msp admin signs in', arrive(['8west:role' => 'msp_admin']) === true);
check('msp admin maps to local admin', db()->query('SELECT role FROM users LIMIT 1')->fetchColumn() === 'admin');
check('msp tech signs in', arrive(['8west:role' => 'msp_tech']) === true);
check('msp tech maps to local tech', db()->query('SELECT role FROM users LIMIT 1')->fetchColumn() === 'tech');
check('msp owner signs in after a role change', arrive(['8west:role' => 'msp_owner']) === true);
check('central role changes reconcile locally', db()->query('SELECT role FROM users LIMIT 1')->fetchColumn() === 'owner');

// Enforce mode accepts a passkey as the complete authentication method. A
// recognized method must never hide an unrecognized AMR value, and that deny
// must happen before tenant or user provisioning.
$CONFIG['suite']['mfa_policy_mode'] = 'enforce';
$passkeyClaims = [
    '8west:auth_policy' => 'suite-mfa-v1',
    '8west:mfa_authenticated' => true,
    '8west:mfa_time' => time() - 60,
    'auth_time' => time() - 30,
    'amr' => ['passkey'],
];
check('passkey-only authentication signs in under enforce mode', arrive($passkeyClaims) === true);
$CONFIG['suite']['mfa_policy_mode'] = 'off';
$deniedClaims = [
    'unknown AMR beside a passkey' => ['amr' => ['passkey', 'future_factor']],
    'duplicate AMR' => ['amr' => ['passkey', 'passkey']],
    'numeric-key AMR object' => ['amr' => (object) ['0' => 'passkey']],
    'empty AMR object' => ['amr' => (object) []],
    'numeric-key product object' => ['8west:products' => (object) ['0' => 'safeharbor']],
    'empty product object' => ['8west:products' => (object) []],
    'duplicate product key' => ['8west:products' => ['safeharbor', 'safeharbor']],
    'noncanonical product key' => ['8west:products' => ['safeharbor', 'Future-Product']],
    'OIDC audience' => ['aud' => 'another-oidc-client'],
    'OIDC nonce' => ['nonce' => 'transaction-specific-nonce'],
    'OIDC authorized party' => ['azp' => 'another-oidc-client'],
];
$deniedIndex = 90;
foreach ($deniedClaims as $label => $claimOverrides) {
    $deniedIndex++;
    check(
        "{$label} is refused without any user, tenant, or session write",
        denied_without_admission(array_merge($passkeyClaims, [
            'sub' => 't90u' . $deniedIndex,
            'email' => "denied-{$deniedIndex}@scratch.test",
            '8west:tenant' => "denied-{$deniedIndex}-must-not-exist",
        ], $claimOverrides)),
    );
}
foreach ([(object) [], ['future_extension']] as $index => $critical) {
    check(
        'unsupported or object-shaped critical header is refused without admission ' . ($index + 1),
        denied_without_admission(array_merge($passkeyClaims, [
            'sub' => 't91u' . ($index + 1),
            'email' => 'critical-' . ($index + 1) . '@scratch.test',
            '8west:tenant' => 'critical-' . ($index + 1) . '-must-not-exist',
        ]), ['crit' => $critical]),
    );
}
$CONFIG['suite']['mfa_policy_mode'] = 'report';

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

// Safeharbor has no viewer role. Refuse rather than silently grant a writer.
check('an msp viewer is refused rather than upgraded to tech', arrive(['8west:role' => 'msp_viewer']) === false);
check('a downstream client contact is refused', arrive(['8west:role' => 'client_owner']) === false);
check('an unknown role string is refused', arrive(['8west:role' => 'wizard']) === false);
$before = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
arrive(['8west:role' => 'msp_viewer', 'sub' => 't9u99', 'email' => 'viewer@scratch.test']);
check(
    'a refused viewer identity is never provisioned',
    (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $before
);
check('a blank tenant slug is refused', arrive(['8west:tenant' => '']) === false);

// 8 West's own staff live in the `8west` tenant. Their legacy staff roles are
// admitted only in that tenant.
check('the 8west staff tenant is admitted', arrive([
    'sub' => 't9u4',
    'email' => 'staff@8westit.com',
    '8west:tenant' => '8west',
    '8west:role' => 'owner',
]) === true);
check('an msp role cannot take the staff tenant', arrive([
    'sub' => 't9u5',
    'email' => 'msp@8westit.com',
    '8west:tenant' => '8west',
    '8west:role' => 'msp_owner',
]) === false);
check('an expired token is refused', arrive(['exp' => time() - 60]) === false);
check('a wrong-issuer token is refused', arrive(['iss' => 'https://evil.test']) === false);
check('a malformed cookie authorization version is refused',
    arrive(['8west:session_version' => '1']) === false);

// A new suite arrival cannot create a tenant or user when the signed feed is
// unavailable. This is the write boundary that existing sessions intentionally
// do not share: they may ride through an issuer outage using prior state.
@unlink($CONFIG['suite']['revocation_cache_path']);
$tenantsBeforeUnavailable = (int) db()->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
$usersBeforeUnavailable = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
check('unavailable signed feed refuses a fresh suite admission', arrive([
    'sub' => 't90u90',
    'email' => 'unavailable@scratch.test',
    '8west:tenant' => 'must-not-exist',
]) === false);
check('unavailable feed cannot provision a tenant or user',
    (int) db()->query('SELECT COUNT(*) FROM tenants')->fetchColumn() === $tenantsBeforeUnavailable
    && (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBeforeUnavailable);

// Runtime cutover invariants, using only locally generated signed fixtures.
$legacyBody = json_encode([
    'generated_at' => gmdate('c'),
    'count' => 0,
    'revoked' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$versionedBody = json_encode([
    'generated_at' => gmdate('c'),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 1,
    'authorizations' => [['sub' => 't9u1', 'session_version' => '1.1']],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$legacySignature = hash_hmac('sha256', $legacyBody, $CONFIG['suite']['sso_secret']);
$versionedSignature = hash_hmac('sha256', $versionedBody, $CONFIG['suite']['sso_secret']);
$raceCache = $suiteRevocationDir . '/race-snapshot-v3.json';
check('v2 writer durably commits the compatibility latch',
    revocation_write_cache(
        $raceCache,
        time(),
        true,
        $versionedBody,
        $versionedSignature,
        $CONFIG['suite']['sso_secret'],
        'versioned',
    ) === 'written');
check('an already-running legacy writer cannot overwrite a concurrent v2 latch',
    revocation_write_cache(
        $raceCache,
        time(),
        false,
        $legacyBody,
        $legacySignature,
        $CONFIG['suite']['sso_secret'],
        'legacy',
    ) === 'downgrade');
$raceEnvelope = revocation_read_cache($raceCache, $CONFIG['suite']['sso_secret'], time());
check('concurrent downgrade refusal preserves the authenticated v2 bytes',
    is_array($raceEnvelope)
    && $raceEnvelope['versioned_observed'] === true
    && hash_equals($raceEnvelope['body'], $versionedBody));

// Exercise the complete two-response runtime path. A correctly signed v2
// payload with an object masquerading as the authorization array must latch v2
// before failing closed. A later correctly signed legacy response may not
// erase that fact or regain compatibility admission.
$malformedV2Payload = (object) [
    'generated_at' => gmdate('c'),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 1,
    'authorizations' => [],
];
$malformedV2Payload->authorizations = (object) [
    '0' => (object) ['sub' => 't9u1', 'session_version' => '1.1'],
];
$malformedV2Body = json_encode(
    $malformedV2Payload,
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);
$malformedV2Signature = hash_hmac('sha256', $malformedV2Body, $CONFIG['suite']['sso_secret']);
$twoStepCache = $suiteRevocationDir . '/malformed-v2-downgrade-v3.json';
$CONFIG['suite']['revocation_cache_path'] = $twoStepCache;
$CONFIG['suite']['session_version_mode'] = 'compat';
$twoStepResponses = [
    [
        'status' => 200,
        'body' => $malformedV2Body,
        'headers' => [
            'HTTP/1.1 200 OK',
            'X-Suite-Signature: ' . $malformedV2Signature,
        ],
    ],
    [
        'status' => 200,
        'body' => $legacyBody,
        'headers' => [
            'HTTP/1.1 200 OK',
            'X-Suite-Signature: ' . $legacySignature,
        ],
    ],
];
$GLOBALS['__SAFEHARBOR_REVOCATION_FETCH'] = static function (string $url) use (&$twoStepResponses): ?array {
    $next = array_shift($twoStepResponses);
    return is_array($next) ? $next : null;
};
$malformedV2Snapshot = revocation_list();
$latchedMalformedV2 = revocation_read_cache(
    $twoStepCache,
    $CONFIG['suite']['sso_secret'],
    time(),
);
check('signed malformed v2 fails closed and durably trips the v2 latch',
    is_array($malformedV2Snapshot)
    && $malformedV2Snapshot['mode'] === 'invalid'
    && is_array($latchedMalformedV2)
    && $latchedMalformedV2['versioned_observed'] === true
    && hash_equals($latchedMalformedV2['body'], $malformedV2Body));
write_private_suite_cache_fixture($twoStepCache, json_encode(suite_revocation_cache_envelope(
    time() - REVOCATION_CACHE_TTL - 1,
    true,
    $malformedV2Body,
    $malformedV2Signature,
    $CONFIG['suite']['sso_secret'],
), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$downgradeSnapshot = revocation_list();
$afterDowngrade = revocation_read_cache($twoStepCache, $CONFIG['suite']['sso_secret'], time());
check('later signed legacy feed cannot downgrade a malformed-v2 latch',
    is_array($downgradeSnapshot)
    && $downgradeSnapshot['mode'] === 'invalid'
    && is_array($afterDowngrade)
    && $afterDowngrade['versioned_observed'] === true
    && hash_equals($afterDowngrade['body'], $malformedV2Body)
    && $twoStepResponses === []);
$GLOBALS['__SAFEHARBOR_REVOCATION_FETCH'] = static fn (string $url): ?array => null;

$strictMalformedCache = $suiteRevocationDir . '/strict-malformed-v3.json';
$CONFIG['suite']['revocation_cache_path'] = $strictMalformedCache;
$CONFIG['suite']['session_version_mode'] = 'strict';
$malformedBody = '[]';
$malformedSignature = hash_hmac('sha256', $malformedBody, $CONFIG['suite']['sso_secret']);
write_private_suite_cache_fixture($strictMalformedCache, json_encode(suite_revocation_cache_envelope(
    time(),
    false,
    $malformedBody,
    $malformedSignature,
    $CONFIG['suite']['sso_secret'],
), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
check('strict mode fails closed on signed malformed JSON even without visible v2 keys',
    revocation_list()['mode'] === 'invalid');

$staleCache = $suiteRevocationDir . '/stale-v3.json';
$CONFIG['suite']['revocation_cache_path'] = $staleCache;
$CONFIG['suite']['session_version_mode'] = 'compat';
$staleVersionedBody = json_encode([
    'generated_at' => gmdate('c', time() - SUITE_REVOCATION_MAX_AGE - 1),
    'count' => 0,
    'revoked' => [],
    'authorization_count' => 0,
    'authorizations' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$staleSignature = hash_hmac('sha256', $staleVersionedBody, $CONFIG['suite']['sso_secret']);
write_private_suite_cache_fixture($staleCache, json_encode(suite_revocation_cache_envelope(
    time(),
    true,
    $staleVersionedBody,
    $staleSignature,
    $CONFIG['suite']['sso_secret'],
), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
check('signed stale v2 preserves established-session availability', revocation_list() === null);

$missingModeCache = $suiteRevocationDir . '/missing-mode-v3.json';
$CONFIG['suite']['revocation_cache_path'] = $missingModeCache;
unset($CONFIG['suite']['session_version_mode']);
write_private_suite_cache_fixture($missingModeCache, json_encode(suite_revocation_cache_envelope(
    time(),
    false,
    $legacyBody,
    $legacySignature,
    $CONFIG['suite']['sso_secret'],
), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
check('missing session-version mode fails closed instead of restarting compat',
    revocation_list()['mode'] === 'invalid');

$_COOKIE['ewid_token'] = 'not-a-token';
check('a malformed token is refused', suite_sso_attempt() === false);

fwrite(STDOUT, "\n{$checks} checks, {$failures} failures\n");
foreach (glob($suiteRevocationDir . '/*') ?: [] as $fixture) {
    @unlink($fixture);
}
@rmdir($suiteRevocationDir);
exit($failures === 0 ? 0 : 1);
