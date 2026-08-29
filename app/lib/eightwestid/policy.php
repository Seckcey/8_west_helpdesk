<?php
/** Closed identity, role, product, and suite-mfa-v1 policy contract. */
declare(strict_types=1);

namespace EightWest\Id;

const ROLE_CONTRACT = 'suite_roles_v1';
const AUTH_POLICY = 'suite-mfa-v1';
const MAX_MFA_AGE_SECONDS = 2592000;
const KNOWN_ROLES = [
    'owner' => 'owner',
    'admin' => 'administrator',
    'tech' => 'operator',
    'readonly' => 'viewer',
    'custodian' => 'administrator',
    'msp_owner' => 'owner',
    'msp_admin' => 'administrator',
    'msp_tech' => 'operator',
    'msp_viewer' => 'viewer',
    'client_owner' => 'owner',
    'client_admin' => 'administrator',
    'client_staff' => 'operator',
    'client_viewer' => 'viewer',
];
const MFA_METHODS = ['otp', 'recovery', 'mfa_trusted_device', 'passkey'];
const KNOWN_AMR = ['pwd', ...MFA_METHODS];
const SESSION_VERSION_COMPONENT_MAX = '18446744073709551615';
const PREFERENCES_SCHEMA = '8west-user-preferences-v1';

final class MfaPolicyResult
{
    public function __construct(
        public readonly bool $compliant,
        public readonly string $reason,
    ) {
    }
}

final class Identity
{
    /**
     * @param list<string> $products
     * @param list<string> $authenticationMethods
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $email,
        public readonly string $name,
        public readonly string $tenant,
        public readonly string $tenantId,
        public readonly string $sessionVersion,
        public readonly array $products,
        public readonly string $role,
        public readonly string $capabilityRole,
        public readonly string $theme,
        public readonly ?string $avatar,
        public readonly array $preferences,
        public readonly bool $mfaEnrolled,
        public readonly bool $mfaAuthenticated,
        public readonly ?int $mfaTime,
        public readonly int $authenticationTime,
        public readonly array $authenticationMethods,
        public readonly string $authenticationPolicy,
        public readonly MfaPolicyResult $mfaPolicy,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sub' => $this->subject,
            'email' => $this->email,
            'name' => $this->name,
            'tenant' => $this->tenant,
            'tenant_id' => $this->tenantId,
            'session_version' => $this->sessionVersion,
            'products' => $this->products,
            'role' => $this->role,
            'capability_role' => $this->capabilityRole,
            'theme' => $this->theme,
            'avatar' => $this->avatar,
            'preferences' => $this->preferences,
            'mfa' => $this->mfaEnrolled,
            'mfa_authenticated' => $this->mfaAuthenticated,
            'mfa_time' => $this->mfaTime,
            'auth_time' => $this->authenticationTime,
            'amr' => $this->authenticationMethods,
            'auth_policy' => $this->authenticationPolicy,
            'mfa_policy' => [
                'compliant' => $this->mfaPolicy->compliant,
                'reason' => $this->mfaPolicy->reason,
            ],
        ];
    }
}

/** @return array<string,mixed> */
function default_preferences(string $subject, string $name, string $theme, ?string $avatar): array
{
    return [
        'schema' => PREFERENCES_SCHEMA,
        'sub' => $subject,
        'version' => 0,
        'updated_at' => null,
        'profile' => ['name' => $name, 'avatar' => $avatar],
        'appearance' => [
            'theme' => $theme,
            'density' => 'comfortable',
            'contrast' => 'system',
            'motion' => 'system',
        ],
        'regional' => [
            'locale' => 'en-US',
            'time_zone' => 'America/Los_Angeles',
            'date_format' => 'locale',
            'time_format' => 'locale',
        ],
        'experience' => ['default_product' => null, 'westy_detail' => 'balanced'],
        'notifications' => ['enabled' => true, 'quiet_hours' => null],
    ];
}

/**
 * Validate presentation preferences without turning them into an auth gate.
 * A missing or malformed preference claim falls back to documented defaults.
 *
 * @return array<string,mixed>
 */
function validated_preferences_claim(
    mixed $value,
    string $subject,
    string $name,
    string $theme,
    ?string $avatar,
): array {
    $fallback = default_preferences($subject, $name, $theme, $avatar);
    if (! is_array($value) || array_is_list($value)
        || ($value['schema'] ?? null) !== PREFERENCES_SCHEMA
        || ($value['sub'] ?? null) !== $subject
        || ! is_int($value['version'] ?? null) || $value['version'] < 0) {
        return $fallback;
    }

    $profile = $value['profile'] ?? null;
    $appearance = $value['appearance'] ?? null;
    $regional = $value['regional'] ?? null;
    $experience = $value['experience'] ?? null;
    $notifications = $value['notifications'] ?? null;
    if (! is_array($profile) || ! is_array($appearance) || ! is_array($regional)
        || ! is_array($experience) || ! is_array($notifications)) {
        return $fallback;
    }

    $allowed = static fn(mixed $candidate, array $choices): bool =>
        is_string($candidate) && in_array($candidate, $choices, true);
    $profileName = $profile['name'] ?? null;
    $profileAvatar = $profile['avatar'] ?? null;
    $timeZone = $regional['time_zone'] ?? null;
    $defaultProduct = $experience['default_product'] ?? null;
    $quietHours = $notifications['quiet_hours'] ?? null;
    $updatedAt = $value['updated_at'] ?? null;

    $valid = is_string($profileName) && $profileName !== '' && strlen($profileName) <= 190
        && $profileAvatar === $avatar
        && $allowed($appearance['theme'] ?? null, ['dark', 'light', 'system'])
        && $allowed($appearance['density'] ?? null, ['comfortable', 'compact'])
        && $allowed($appearance['contrast'] ?? null, ['system', 'standard', 'high'])
        && $allowed($appearance['motion'] ?? null, ['system', 'reduce', 'full'])
        && $allowed($regional['locale'] ?? null, ['en-US', 'en-CA', 'en-GB', 'es-US'])
        && is_string($timeZone) && $timeZone !== '' && strlen($timeZone) <= 64
        && preg_match('/^[A-Za-z0-9_+\/-]+$/D', $timeZone) === 1
        && $allowed($regional['date_format'] ?? null, ['locale', 'mdy', 'dmy', 'ymd'])
        && $allowed($regional['time_format'] ?? null, ['locale', '12h', '24h'])
        && ($defaultProduct === null || (is_string($defaultProduct)
            && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $defaultProduct) === 1))
        && $allowed($experience['westy_detail'] ?? null, ['concise', 'balanced', 'detailed'])
        && is_bool($notifications['enabled'] ?? null)
        && ($updatedAt === null || (is_string($updatedAt)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $updatedAt) === 1));

    if ($quietHours !== null) {
        $valid = $valid && is_array($quietHours)
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) ($quietHours['start'] ?? '')) === 1
            && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) ($quietHours['end'] ?? '')) === 1;
    }
    if (! $valid) {
        return $fallback;
    }

    return $value;
}

/**
 * The issuer combines positive tenant and user authorization generations as
 * two canonical unsigned-decimal components. Keep the value as a string so it
 * is exact on every PHP build, including values above PHP_INT_MAX.
 */
function valid_session_version(mixed $value): bool
{
    if (! is_string($value)
        || preg_match('/^([1-9][0-9]{0,19})\.([1-9][0-9]{0,19})$/D', $value, $parts) !== 1) {
        return false;
    }

    foreach ([$parts[1], $parts[2]] as $component) {
        if (strlen($component) === strlen(SESSION_VERSION_COMPONENT_MAX)
            && strcmp($component, SESSION_VERSION_COMPONENT_MAX) > 0) {
            return false;
        }
    }
    return true;
}

function evaluate_mfa_policy(array $claims, int $now, int $maxAge, int $clockSkew = 120): MfaPolicyResult
{
    $fail = static fn(string $reason): MfaPolicyResult => new MfaPolicyResult(false, $reason);
    if ($maxAge < 1 || $maxAge > MAX_MFA_AGE_SECONDS) {
        return $fail('mfa_max_age_invalid');
    }
    if (($claims['8west:auth_policy'] ?? null) !== AUTH_POLICY) {
        return $fail('contract_missing');
    }
    if (($claims['8west:mfa_authenticated'] ?? null) !== true) {
        return $fail('mfa_not_authenticated');
    }
    $authTime = $claims['auth_time'] ?? null;
    if (! is_int($authTime) || $authTime <= 0 || $authTime > $now + $clockSkew) {
        return $fail('auth_time_invalid');
    }
    $methods = $claims['amr'] ?? null;
    if (! is_array($methods) || ! array_is_list($methods)
        || array_intersect($methods, MFA_METHODS) === []) {
        return $fail('mfa_method_missing');
    }
    $mfaTime = $claims['8west:mfa_time'] ?? null;
    if (! is_int($mfaTime) || $mfaTime <= 0 || $mfaTime > $now + $clockSkew) {
        return $fail('mfa_time_invalid');
    }
    if ($now - $mfaTime > $maxAge) {
        return $fail('mfa_too_old');
    }
    return new MfaPolicyResult(true, 'ok');
}

/** @return list<string> */
function validated_string_list(mixed $value, string $reason, int $maxItems, ?string $pattern = null): array
{
    if (! is_array($value) || ! array_is_list($value) || count($value) > $maxItems) {
        throw new PolicyException($reason);
    }
    $out = [];
    foreach ($value as $item) {
        if (! is_string($item) || $item === '' || strlen($item) > 128
            || ($pattern !== null && preg_match($pattern, $item) !== 1)
            || in_array($item, $out, true)) {
            throw new PolicyException($reason);
        }
        $out[] = $item;
    }
    return $out;
}

function bounded_claim_string(array $claims, string $name, int $max, string $reason): string
{
    $value = $claims[$name] ?? null;
    if (! is_string($value) || $value === '' || strlen($value) > $max
        || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
        throw new PolicyException($reason);
    }
    return $value;
}
