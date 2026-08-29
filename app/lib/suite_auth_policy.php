<?php
/** Pure evaluator for the 8 West ID suite-mfa-v1 authentication contract. */
declare(strict_types=1);

const SUITE_MFA_POLICY_VERSION = 'suite-mfa-v1';
const SUITE_MFA_METHODS = ['otp', 'recovery', 'mfa_trusted_device', 'passkey'];
const SUITE_KNOWN_AMR = ['pwd', ...SUITE_MFA_METHODS];
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

function suite_amr_valid(mixed $methods): bool
{
    if (! is_array($methods) || ! array_is_list($methods) || count($methods) > 10) {
        return false;
    }
    $seen = [];
    foreach ($methods as $method) {
        if (! is_string($method)
            || ! in_array($method, SUITE_KNOWN_AMR, true)
            || in_array($method, $seen, true)) {
            return false;
        }
        $seen[] = $method;
    }
    return true;
}

/**
 * The issuer may add a future product without requiring a Safeharbor release,
 * but every entitlement key must retain the suite's bounded canonical form.
 */
function suite_products_valid(mixed $products): bool
{
    if (! is_array($products) || ! array_is_list($products) || count($products) > 64) {
        return false;
    }
    $seen = [];
    foreach ($products as $product) {
        if (! is_string($product)
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $product) !== 1
            || in_array($product, $seen, true)) {
            return false;
        }
        $seen[] = $product;
    }
    return true;
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
    if (! is_array($methods) || ! array_is_list($methods)) {
        return ['compliant' => false, 'reason' => 'mfa_method_missing'];
    }
    if (! suite_amr_valid($methods)) {
        return ['compliant' => false, 'reason' => 'amr_invalid'];
    }
    if (array_intersect($methods, SUITE_MFA_METHODS) === []) {
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
