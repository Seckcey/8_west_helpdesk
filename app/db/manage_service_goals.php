<?php
/** Review and publish immutable service-goal policy versions. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit('CLI only.');

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/service_goal_policy_admin.php';

function service_goal_cli_usage(): never
{
    $script = 'php db/manage_service_goals.php';
    fwrite(STDERR, <<<TEXT
Usage:
  {$script} inspect --tenant-slug=slug --policy-key=standard|premium
  {$script} plan --tenant-slug=slug --policy-key=standard|premium --expected-current-version=N --effective-from=YYYY-MM-DDTHH:MM:SSZ --low-minutes=N --normal-minutes=N --high-minutes=N --urgent-minutes=N --actor-user-id=N --reason="..."
  {$script} publish --tenant-slug=slug --policy-key=standard|premium --expected-current-version=N --effective-from=YYYY-MM-DDTHH:MM:SSZ --low-minutes=N --normal-minutes=N --high-minutes=N --urgent-minutes=N --actor-user-id=N --reason="..." --plan-sha256=64-lowercase-hex

Plan performs no write. Publish recomputes every fact under tenant/latest-row
locks and requires the exact reviewed digest. Policies are fixed to
Standard/Premium, elapsed time, UTC, no waiting pause, and no resolution goal.
TEXT);
    exit(2);
}

/** @return array<string,string> */
function service_goal_cli_options(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (! is_string($argument)
            || preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match) !== 1
            || array_key_exists($match[1], $options)
        ) {
            service_goal_cli_usage();
        }
        $options[$match[1]] = $match[2];
    }
    return $options;
}

/** @param list<string> $required */
function service_goal_cli_require_exact(array $options, array $required): void
{
    $provided = array_keys($options);
    sort($provided);
    sort($required);
    if ($provided !== $required) service_goal_cli_usage();
}

function service_goal_cli_positive_int(array $options, string $name): int
{
    $value = $options[$name] ?? '';
    if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
        throw new ServiceGoalPolicyValidationException("--{$name} must be a positive integer.");
    }
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (! is_int($number)) {
        throw new ServiceGoalPolicyValidationException("--{$name} is outside the supported integer range.");
    }
    return $number;
}

/** @return array<string,int> */
function service_goal_cli_targets(array $options): array
{
    return [
        'low' => service_goal_cli_positive_int($options, 'low-minutes'),
        'normal' => service_goal_cli_positive_int($options, 'normal-minutes'),
        'high' => service_goal_cli_positive_int($options, 'high-minutes'),
        'urgent' => service_goal_cli_positive_int($options, 'urgent-minutes'),
    ];
}

function service_goal_cli_json(array $value): never
{
    echo json_encode(
        $value,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    exit(0);
}

$command = $argv[1] ?? '';
if (! is_string($command) || ! in_array($command, ['inspect', 'plan', 'publish'], true)) {
    service_goal_cli_usage();
}
$options = service_goal_cli_options(array_slice($argv, 2));

try {
    if ($command === 'inspect') {
        service_goal_cli_require_exact($options, ['tenant-slug', 'policy-key']);
        service_goal_cli_json(service_goal_policy_inspect(
            db(),
            $options['tenant-slug'],
            $options['policy-key'],
        ));
    }

    $required = [
        'tenant-slug', 'policy-key', 'expected-current-version', 'effective-from',
        'low-minutes', 'normal-minutes', 'high-minutes', 'urgent-minutes',
        'actor-user-id', 'reason',
    ];
    if ($command === 'publish') $required[] = 'plan-sha256';
    service_goal_cli_require_exact($options, $required);

    $arguments = [
        db(),
        $options['tenant-slug'],
        $options['policy-key'],
        service_goal_cli_positive_int($options, 'expected-current-version'),
        $options['effective-from'],
        service_goal_cli_targets($options),
        service_goal_cli_positive_int($options, 'actor-user-id'),
        $options['reason'],
    ];
    if ($command === 'plan') {
        $plan = service_goal_policy_plan(...$arguments);
        service_goal_cli_json([
            'phase' => 'planned_no_write',
            'plan_sha256' => $plan['plan_sha256'],
            'canonical_json' => $plan['canonical_json'],
            'plan' => $plan['plan'],
        ]);
    }

    $published = service_goal_policy_publish(...[...$arguments, $options['plan-sha256']]);
    service_goal_cli_json([
        'phase' => 'published',
        'plan_sha256' => $published['plan_sha256'],
        'policy' => $published['policy'],
    ]);
} catch (ServiceGoalPolicyException $error) {
    fwrite(STDERR, 'Service-goal policy command refused: ' . $error->getMessage() . PHP_EOL);
    exit($error instanceof ServiceGoalPolicyValidationException ? 2 : 1);
} catch (Throwable $error) {
    error_log('[safeharbor-service-goal-cli] failed type=' . $error::class);
    fwrite(STDERR, "Service-goal policy command failed. Inspect server logs; no change should be assumed.\n");
    exit(1);
}
