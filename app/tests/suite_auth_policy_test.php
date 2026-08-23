<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/suite_auth_policy.php';

function policy_check(bool $condition, string $message): void
{
    if (! $condition) throw new RuntimeException($message);
}

$now = 2000000000;
$valid = [
    '8west:auth_policy' => 'suite-mfa-v1',
    '8west:mfa_authenticated' => true,
    '8west:mfa_time' => $now - 60,
    'auth_time' => $now - 30,
    'amr' => ['pwd', 'mfa_trusted_device'],
];
policy_check(suite_mfa_policy_evaluate($valid, $now, 2592000)['compliant'], 'trusted browser refused');
policy_check(suite_mfa_policy_evaluate([], $now, 2592000)['reason'] === 'contract_missing', 'legacy claims accepted');
$notAuthenticated = array_replace($valid, ['8west:mfa_authenticated' => false]);
policy_check(suite_mfa_policy_evaluate($notAuthenticated, $now, 2592000)['reason'] === 'mfa_not_authenticated', 'single factor accepted');
$future = array_replace($valid, ['8west:mfa_time' => $now + 121]);
policy_check(suite_mfa_policy_evaluate($future, $now, 2592000)['reason'] === 'mfa_time_invalid', 'future MFA time accepted');
policy_check(suite_mfa_policy_evaluate($valid, $now, 2592001)['reason'] === 'mfa_max_age_invalid', 'overlong policy age accepted');
policy_check(suite_mfa_policy_mode(null) === 'report', 'missing mode did not default to report');
policy_check(suite_local_password_allowed(['suite_subject' => null]), 'unlinked break-glass account was blocked');
policy_check(! suite_local_password_allowed(['suite_subject' => 't9u1']), 'suite-linked account could bypass ID with a local password');

echo "suite_auth_policy_test: ok\n";
