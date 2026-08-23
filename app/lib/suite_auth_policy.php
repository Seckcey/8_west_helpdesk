<?php
/** Pure evaluator for the 8 West ID suite-mfa-v1 authentication contract. */
declare(strict_types=1);

const SUITE_MFA_POLICY_VERSION = 'suite-mfa-v1';
const SUITE_MFA_METHODS = ['otp', 'recovery', 'mfa_trusted_device'];
const SUITE_MFA_POLICY_MAX_AGE = 2592000;

function suite_mfa_policy_mode(mixed $value): string
{
    $mode = is_string($value) ? strtolower(trim($value)) : '';
    return in_array($mode, ['off', 'report', 'enforce'], true) ? $mode : 'report';
}

/** Suite-linked identities must return through 8 West ID so MFA cannot be bypassed locally. */
function suite_local_password_allowed(array $user): bool
{
    return trim((string) ($user['suite_subject'] ?? '')) === '';
}

/** @return array{compliant: bool, reason: string} */
function suite_mfa_policy_evaluate(array $claims, int $now, int $maxAge, int $clockSkew = 120): array
{
    if ($maxAge <= 0 || $maxAge > SUITE_MFA_POLICY_MAX_AGE) {
        return ['compliant' => false, 'reason' => 'mfa_max_age_invalid'];
    }
    if (($claims['8west:auth_policy'] ?? null) !== SUITE_MFA_POLICY_VERSION) {
        return ['compliant' => false, 'reason' => 'contract_missing'];
    }
    if (($claims['8west:mfa_authenticated'] ?? null) !== true) {
        return ['compliant' => false, 'reason' => 'mfa_not_authenticated'];
    }
    $authTime = $claims['auth_time'] ?? null;
    if (! is_int($authTime) || $authTime <= 0 || $authTime > $now + $clockSkew) {
        return ['compliant' => false, 'reason' => 'auth_time_invalid'];
    }
    $methods = $claims['amr'] ?? null;
    if (! is_array($methods) || array_intersect($methods, SUITE_MFA_METHODS) === []) {
        return ['compliant' => false, 'reason' => 'mfa_method_missing'];
    }
    $mfaTime = $claims['8west:mfa_time'] ?? null;
    if (! is_int($mfaTime) || $mfaTime <= 0 || $mfaTime > $now + $clockSkew) {
        return ['compliant' => false, 'reason' => 'mfa_time_invalid'];
    }
    if ($now - $mfaTime > $maxAge) {
        return ['compliant' => false, 'reason' => 'mfa_too_old'];
    }
    return ['compliant' => true, 'reason' => 'ok'];
}
