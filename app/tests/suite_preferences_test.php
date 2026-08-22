<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/suite_preferences.php';

function preference_check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$claims = [
    'sub' => 't1u2',
    '8west:theme' => 'dark',
    '8west:preferences' => [
        'schema' => '8west-user-preferences-v1', 'sub' => 't1u2', 'version' => 7,
        'appearance' => ['theme' => 'light', 'density' => 'compact', 'contrast' => 'high', 'motion' => 'reduce'],
        'regional' => ['locale' => 'en-GB', 'time_zone' => 'Europe/London', 'date_format' => 'dmy', 'time_format' => '24h'],
        'experience' => ['default_product' => null, 'westy_detail' => 'detailed'],
        'notifications' => ['enabled' => false, 'quiet_hours' => ['start' => '21:30', 'end' => '07:00']],
    ],
];
$preferences = suite_preferences_from_claim($claims);
preference_check($preferences['version'] === 7 && $preferences['theme'] === 'light', 'valid preferences were not applied');
preference_check($preferences['westy_detail'] === 'detailed' && $preferences['quiet_hours_start'] === '21:30', 'experience or notifications were lost');

$claims['8west:preferences']['sub'] = 't9u9';
$fallback = suite_preferences_from_claim($claims);
preference_check($fallback['version'] === 0 && $fallback['theme'] === 'dark', 'malformed preferences did not fail soft');

echo "suite_preferences_test: ok\n";
