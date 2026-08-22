<?php
/** Fail-soft reader for the signed 8west-user-preferences-v1 claim. */
declare(strict_types=1);

/** @return array<string,mixed> */
function suite_preferences_defaults(array $claims = []): array
{
    $theme = in_array($claims['8west:theme'] ?? '', ['dark', 'light', 'system'], true)
        ? (string) $claims['8west:theme'] : 'system';
    return [
        'version' => 0, 'theme' => $theme, 'density' => 'comfortable',
        'contrast' => 'system', 'motion' => 'system', 'locale' => 'en-US',
        'time_zone' => 'America/Los_Angeles', 'date_format' => 'locale',
        'time_format' => 'locale', 'default_product' => null,
        'westy_detail' => 'balanced', 'notifications_enabled' => true,
        'quiet_hours_start' => null, 'quiet_hours_end' => null,
    ];
}
/** @return array<string,mixed> */
function suite_preferences_from_claim(array $claims): array
{
    $fallback = suite_preferences_defaults($claims);
    $doc = $claims['8west:preferences'] ?? null;
    if (! is_array($doc) || array_is_list($doc)
        || ($doc['schema'] ?? null) !== '8west-user-preferences-v1'
        || ($doc['sub'] ?? null) !== ($claims['sub'] ?? null)
        || ! is_int($doc['version'] ?? null) || $doc['version'] < 0) {
        return $fallback;
    }
    $appearance = $doc['appearance'] ?? null;
    $regional = $doc['regional'] ?? null;
    $experience = $doc['experience'] ?? null;
    $notifications = $doc['notifications'] ?? null;
    if (! is_array($appearance) || ! is_array($regional)
        || ! is_array($experience) || ! is_array($notifications)) {
        return $fallback;
    }
    $allowed = static fn(mixed $value, array $choices): bool =>
        is_string($value) && in_array($value, $choices, true);
    $timeZone = $regional['time_zone'] ?? null;
    $defaultProduct = $experience['default_product'] ?? null;
    if (! $allowed($appearance['theme'] ?? null, ['dark', 'light', 'system'])
        || ! $allowed($appearance['density'] ?? null, ['comfortable', 'compact'])
        || ! $allowed($appearance['contrast'] ?? null, ['system', 'standard', 'high'])
        || ! $allowed($appearance['motion'] ?? null, ['system', 'reduce', 'full'])
        || ! $allowed($regional['locale'] ?? null, ['en-US', 'en-CA', 'en-GB', 'es-US'])
        || ! is_string($timeZone) || $timeZone === '' || strlen($timeZone) > 64
        || preg_match('/^[A-Za-z0-9_+\/-]+$/D', $timeZone) !== 1
        || ! $allowed($regional['date_format'] ?? null, ['locale', 'mdy', 'dmy', 'ymd'])
        || ! $allowed($regional['time_format'] ?? null, ['locale', '12h', '24h'])
        || ($defaultProduct !== null && (! is_string($defaultProduct)
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $defaultProduct) !== 1))
        || ! $allowed($experience['westy_detail'] ?? null, ['concise', 'balanced', 'detailed'])
        || ! is_bool($notifications['enabled'] ?? null)) {
        return $fallback;
    }
    $start = null;
    $end = null;
    $quiet = $notifications['quiet_hours'] ?? null;
    if ($quiet !== null) {
        if (! is_array($quiet)
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) ($quiet['start'] ?? '')) !== 1
            || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', (string) ($quiet['end'] ?? '')) !== 1) {
            return $fallback;
        }
        $start = (string) $quiet['start'];
        $end = (string) $quiet['end'];
    }
    return [
        'version' => $doc['version'], 'theme' => $appearance['theme'],
        'density' => $appearance['density'], 'contrast' => $appearance['contrast'],
        'motion' => $appearance['motion'], 'locale' => $regional['locale'],
        'time_zone' => $timeZone, 'date_format' => $regional['date_format'],
        'time_format' => $regional['time_format'], 'default_product' => $defaultProduct,
        'westy_detail' => $experience['westy_detail'],
        'notifications_enabled' => $notifications['enabled'],
        'quiet_hours_start' => $start, 'quiet_hours_end' => $end,
    ];
}
