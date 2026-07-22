<?php
/**
 * Session auth. Real bcrypt passwords + PHP sessions now; the 8 West ID
 * OIDC flow (docs/suite-sso-contract.md) replaces attempt_login() later —
 * the session shape it produces is the same.
 *
 * 8 West ID Phase 1: suite_sso_attempt() trusts the signed suite cookie
 * issued by id.8westit.com and starts the same local session. Bcrypt
 * login stays as the fallback/break-glass path.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/jwt.php';

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
    return $stmt->fetch() ?: null;
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
function suite_sso_attempt(): bool
{
    $token = (string)($_COOKIE[cfg('suite.cookie_name', 'ewid_token')] ?? '');
    if ($token === '') return false;

    $claims = jwt_verify($token, (string)cfg('suite.sso_secret', ''), (string)cfg('suite.issuer', 'https://id.8westit.com'));
    if ($claims === null) return false;
    if (!in_array('safeharbor', (array)($claims['8west:products'] ?? []), true)) return false;

    // Tenant resolution is claim-driven (contract rule 1).
    $slug = (string)($claims['8west:tenant'] ?? '');
    $stmt = db()->prepare('SELECT id FROM tenants WHERE slug = ?');
    $stmt->execute([$slug]);
    $tenantId = (int)($stmt->fetchColumn() ?: 0);
    if ($tenantId === 0) return false;

    $email = mb_strtolower(trim((string)$claims['email']));
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND tenant_id = ?');
    $stmt->execute([$email, $tenantId]);
    $user = $stmt->fetch();

    if (!$user) {
        // Auto-provision from the master user list at id.8westit.com.
        $name = trim((string)($claims['name'] ?? $email));
        $parts = preg_split('/\s+/', $name) ?: [];
        $initials = mb_strtoupper(mb_substr($parts[0] ?? 'U', 0, 1) . mb_substr(end($parts) ?: '', 0, 1));
        $role = in_array($claims['8west:role'] ?? '', ['owner', 'admin', 'tech'], true) ? $claims['8west:role'] : 'tech';
        $stmt = db()->prepare('INSERT INTO users (tenant_id, email, password_hash, full_name, initials, role)
                               VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$tenantId, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $name, $initials, $role]);
        $user = [
            'id' => (int)db()->lastInsertId(),
            'is_active' => 1,
        ];
    }

    if (!(int)$user['is_active']) return false;

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
    return true;
}

function attempt_login(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND tenant_id = ? AND is_active = 1');
    $stmt->execute([mb_strtolower(trim($email)), tenant_id()]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
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
