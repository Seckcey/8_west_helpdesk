<?php
/**
 * Session auth. Real bcrypt passwords + PHP sessions now; the 8 West ID
 * OIDC flow (docs/suite-sso-contract.md) replaces attempt_login() later —
 * the session shape it produces is the same. Local passwords remain available
 * only to accounts that have never been linked to an 8 West ID subject.
 *
 * 8 West ID Phase 1: suite_sso_attempt() trusts the signed suite cookie
 * issued by id.8westit.com and starts the same local session. Bcrypt
 * login stays as the fallback/break-glass path.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/suite_auth_policy.php';
require_once __DIR__ . '/suite_roles.php';
require_once __DIR__ . '/suite_preferences.php';
require_once __DIR__ . '/suite_revocation_policy.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user) {
        // Repair old sessions and keep every tenant-scoped helper bound to
        // the authenticated database row instead of tenant 1 fallback.
        $_SESSION['tenant_id'] = (int)$user['tenant_id'];
        suite_sso_refresh_claims((string)($user['suite_subject'] ?? ''));
        if ((string)($user['suite_subject'] ?? '') !== '') {
            // Centralize the check here so API handlers that resolve their
            // session directly cannot accidentally bypass require_login().
            require_once __DIR__ . '/revocation.php';
            enforce_revocation($user);
        }
    }
    return $user;
}

/**
 * Verify the 8 West ID suite cookie and return its claims (or null).
 * Shared by suite_sso_attempt() and suite_sso_refresh_claims().
 */
function suite_sso_claims(): ?array
{
    $token = (string)($_COOKIE[cfg('suite.cookie_name', 'ewid_token')] ?? '');
    if ($token === '') return null;
    [$claims] = jwt_verify_suite_reason($token, (array) cfg('suite', []));
    return $claims;
}

/**
 * Suite-wide settings sync: when the 8 West ID token changes (theme/avatar
 * updated at id.8westit.com), refresh the session copies. Render uses them
 * to apply the theme and show the central avatar. Cheap: one HMAC per request.
 */
function suite_sso_refresh_claims(?string $expectedSubject = null): void
{
    $claims = suite_sso_claims();
    if ($claims === null) return;
    $claimSubject = (string)($claims['sub'] ?? '');
    if ($expectedSubject !== null
        && $expectedSubject !== ''
        && ! hash_equals($expectedSubject, $claimSubject)) {
        return;
    }
    $preferencesSig = json_encode($claims['8west:preferences'] ?? null);
    $etag = $claimSubject . '|' . ($claims['8west:theme'] ?? '') . '|'
        . ($claims['8west:avatar'] ?? '') . '|' . (string)$preferencesSig . '|' . ($claims['exp'] ?? '');
    if (($_SESSION['suite_claims_etag'] ?? '') === $etag) return;
    $_SESSION['suite_claims_etag'] = $etag;
    $_SESSION['suite_preferences'] = suite_preferences_from_claim($claims);
    $_SESSION['suite_theme'] = $_SESSION['suite_preferences']['theme'];
    $_SESSION['suite_avatar'] = is_string($claims['8west:avatar'] ?? null) ? $claims['8west:avatar'] : null;
    $_SESSION['suite_avatar_subject'] = (string)($claims['sub'] ?? '');
    unset($_SESSION['suite_avatar_email']);
}

/** The signed-in user, or redirect to /login.php. */
function require_login(): array
{
    $user = current_user();
    if (!$user && suite_sso_attempt()) {
        $user = current_user();
    }
    if (!$user) {
        $next = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login.php?next=' . urlencode($next));
        exit;
    }
    return $user;
}

/**
 * 8 West ID SSO (Phase 1, docs/suite-sso-contract.md): trust the signed
 * suite cookie from id.8westit.com, resolve the tenant by its slug claim,
 * and auto-provision a local user on first entry (email match). Starts the
 * same session shape as attempt_login().
 */
/**
 * Why a suite sign-in was refused (CLAIMS_CONTRACT_V1 4.2: fail closed, audit
 * every deny). Every path returns false and drops the visitor on the password
 * form, which is indistinguishable from a mistyped URL unless we record what
 * happened.
 *
 * The visitor is told nothing specific; the log gets the reason code and, when
 * the signature verified, the subject. Never claim payloads - an audit line
 * should not become a place where tenant slugs, roles and addresses
 * accumulate in plaintext.
 */
function suite_sso_refuse(string $reason, ?string $subject = null): bool
{
    error_log('suite sso deny: ' . $reason . ($subject !== null ? ' sub=' . $subject : ''));

    return false;
}

function suite_sso_attempt(): bool
{
    $token = (string)($_COOKIE[cfg('suite.cookie_name', 'ewid_token')] ?? '');
    if ($token === '') return suite_sso_refuse('no_cookie');

    [$claims, $reason] = jwt_verify_suite_reason($token, (array) cfg('suite', []));
    if ($claims === null) return suite_sso_refuse($reason ?? 'token_rejected');

    $subject = trim((string)($claims['sub'] ?? ''));
    $hasSessionVersion = array_key_exists('8west:session_version', $claims);
    $sessionVersion = $claims['8west:session_version'] ?? null;
    if ($hasSessionVersion && ! suite_session_version_valid($sessionVersion)) {
        return suite_sso_refuse('session_version_invalid', $subject);
    }
    if (array_key_exists('amr', $claims) && ! suite_amr_valid($claims['amr'])) {
        return suite_sso_refuse('amr_invalid', $subject);
    }
    if (array_key_exists('8west:products', $claims)
        && ! suite_products_valid($claims['8west:products'])) {
        return suite_sso_refuse('products_invalid', $subject);
    }

    $policyMode = suite_mfa_policy_mode(cfg('suite.mfa_policy_mode', 'report'));
    $policy = $policyMode === 'off'
        ? ['compliant' => true, 'reason' => 'off']
        : suite_mfa_policy_evaluate(
            $claims,
            time(),
            (int)cfg('suite.mfa_max_age', SUITE_MFA_POLICY_MAX_AGE),
        );
    if ($policyMode === 'enforce' && ! $policy['compliant']) {
        return suite_sso_refuse('mfa_policy_' . $policy['reason'], $subject);
    }
    if ($policyMode === 'report' && ! $policy['compliant']) {
        error_log('suite sso mfa report: ' . $policy['reason'] . ' sub=' . $subject);
    }

    if (! in_array('safeharbor', $claims['8west:products'] ?? [], true)) {
        return suite_sso_refuse('product_not_entitled', $subject);
    }

    // Tenant resolution is claim-driven (contract rule 1). The tenant is
    // provisioned on first arrival rather than having to exist here already:
    // granting the tile in 8 West ID should be the only step needed to give
    // somebody access, with no per-app setup.
    //
    // A blank slug is refused: it would otherwise create a tenant with an
    // empty name and quietly collect unrelated people into it.
    //
    // Safeharbor is tenant scoped for everyone, and 8 West's own staff
    // genuinely live in the `8west` tenant. The audience-aware role map keeps
    // customer operators and downstream contacts out of that staff tenant.
    $slug = mb_strtolower(trim((string)($claims['8west:tenant'] ?? '')));
    if ($slug === '') {
        return suite_sso_refuse('tenant_slug_invalid', $subject);
    }

    // The paying MSP uses canonical msp_* roles. The legacy staff vocabulary
    // is admitted only for 8 West's own tenant. Client contacts and viewers
    // are refused: this is the MSP's complete helpdesk and it has no read-only
    // local role, so either admission would grant more than the token says.
    $suiteRole = (string)($claims['8west:role'] ?? '');
    $localRole = safeharbor_suite_local_role($suiteRole, $slug);
    if ($localRole === null) {
        return suite_sso_refuse('role_not_admitted', $subject);
    }

    if (! suite_revocation_subject_valid($subject)) {
        return suite_sso_refuse('subject_invalid', $subject);
    }

    // Admission must be authorized before tenant creation, subject backfill,
    // user provisioning, role sync, session creation, or last-login writes.
    // Existing sessions deliberately ride through an issuer outage; a new
    // local session does not, because it has no previously authorized state.
    require_once __DIR__ . '/revocation.php';
    $authorizationSnapshot = revocation_list();
    if ($authorizationSnapshot === null) {
        return suite_sso_refuse('authorization_feed_unavailable', $subject);
    }
    $authorizationDecision = suite_revocation_decision(
        $authorizationSnapshot,
        $subject,
        $sessionVersion,
    );
    if ($authorizationDecision['action'] !== 'allow') {
        return suite_sso_refuse(
            'authorization_' . $authorizationDecision['reason'],
            $subject,
        );
    }

    $stmt = db()->prepare('SELECT id FROM tenants WHERE slug = ?');
    $stmt->execute([$slug]);
    $tenantId = (int)($stmt->fetchColumn() ?: 0);

    if ($tenantId === 0) {
        $stmt = db()->prepare('INSERT INTO tenants (name, slug) VALUES (?, ?)');
        $stmt->execute([$slug, $slug]);
        $tenantId = (int)db()->lastInsertId();
    }

    $email = mb_strtolower(trim((string)$claims['email']));

    // Map by the immutable subject, never by email (CLAIMS_CONTRACT_V1 rev 2).
    $stmt = db()->prepare('SELECT * FROM users WHERE suite_subject = ?');
    $stmt->execute([$subject]);
    $user = $stmt->fetch();

    if (!$user) {
        // One-time claim for accounts that predate suite entry: matched by
        // email only while no subject is attached, then backfilled so every
        // later sign-in goes through the subject above.
        $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND tenant_id = ? AND suite_subject IS NULL');
        $stmt->execute([$email, $tenantId]);
        $user = $stmt->fetch();

        if ($user) {
            $claim = db()->prepare('UPDATE users SET suite_subject = ? WHERE id = ?');
            $claim->execute([$subject, (int)$user['id']]);
        }
    }

    if ($user) {
        // Existing account: keep identity and authorization in step with 8
        // West ID. This prevents a central demotion leaving a stale elevated
        // Safeharbor role behind.
        if (mb_strtolower((string)$user['email']) !== $email || (string)$user['role'] !== $localRole) {
            $sync = db()->prepare('UPDATE users SET email = ?, role = ? WHERE id = ?');
            $sync->execute([$email, $localRole, (int)$user['id']]);
        }
    } else {
        // Auto-provision from the master user list at id.8westit.com.
        $name = trim((string)($claims['name'] ?? $email));
        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1) . mb_substr(end($parts) ?: '', 0, 1));
        // The subject is written here, not left for the next sign-in to
        // backfill: if the address changed in between, an email-only match
        // would miss and mint a second account - the very split this change
        // exists to prevent.
        $stmt = db()->prepare('INSERT INTO users (tenant_id, email, suite_subject, password_hash, full_name, initials, role)
                               VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$tenantId, $email, $subject, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $name, $initials, $localRole]);
        $user = [
            'id' => (int)db()->lastInsertId(),
            'is_active' => 1,
        ];
    }

    if (!(int)$user['is_active']) return suite_sso_refuse('user_inactive', $subject);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['suite_mfa_policy'] = $policy['compliant'] ? 'compliant' : $policy['reason'];
    if ($hasSessionVersion) {
        $_SESSION['suite_session_version'] = $sessionVersion;
    } else {
        // Consumer-first compatibility: legacy cookies remain usable while
        // the signed feed is still revoked-only. An authoritative new feed
        // will require this value and request a fresh sign-in if it is absent.
        unset($_SESSION['suite_session_version']);
    }
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
    return true;
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND tenant_id = ? AND is_active = 1');
    $stmt->execute([mb_strtolower(trim($email)), tenant_id()]);
    $user = $stmt->fetch();
    if (! $user || ! suite_local_password_allowed($user) || ! password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['tenant_id'] = (int)$user['tenant_id'];
    unset($_SESSION['suite_session_version']);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
    return true;
}

/** Per-session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/** Hidden form input. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

/** Verify a POSTed token (form field or X-CSRF header); 419 + exit on miss. */
function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$sent)) {
        http_response_code(419);
        exit('Session expired — go back and try again.');
    }
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/**
 * Central 8 West ID sign-out endpoint for browser logout handoff.
 *
 * The issuer is server configuration, never request input. Still, refuse
 * malformed or non-HTTPS values so a bad config cannot turn logout into an
 * open redirect. The production default matches the token issuer.
 */
function suite_logout_url(): string
{
    $default = 'https://id.8westit.com';
    $issuer = trim((string)cfg('suite.issuer', $default));
    $parts = parse_url($issuer);

    if (filter_var($issuer, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        $issuer = $default;
    }

    return rtrim($issuer, '/') . '/logout.php';
}
