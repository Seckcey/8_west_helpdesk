<?php
/** Presentation only. Preferences never grant workspace or client authority. */
declare(strict_types=1);
require_once __DIR__ . '/suite_preferences.php';

/** Read presentation claims without changing the portal session or its authority. */
function portal_verified_suite_claims(array $identity): ?array
{
    if (!function_exists('cfg') || !is_string($identity['subject'] ?? null)) return null;
    $token = $_COOKIE[cfg('suite.cookie_name', 'ewid_token')] ?? '';
    if (!is_string($token) || $token === '') return null;
    require_once __DIR__ . '/jwt.php';
    $suite = (array)cfg('suite', []);
    $suite['token_algorithms'] = ['RS256'];
    if (function_exists('portal_configured_issuer')
        && ($suite['issuer'] ?? 'https://id.8westit.com') !== portal_configured_issuer()) return null;
    [$claims] = jwt_verify_suite_reason($token, $suite);
    return is_array($claims) && ($claims['sub'] ?? null) === $identity['subject'] ? $claims : null;
}

function portal_personal_preferences(array $identity): array
{
    $claims = ['sub' => $identity['subject'] ?? '',
        '8west:theme' => $identity['theme'] ?? 'system',
        '8west:preferences' => $identity['preferences'] ?? null];
    // Adopt an updated suite cookie only for the SAME verified OIDC person.
    // Portal authentication/revocation and client selection remain independent.
    $fresh = portal_verified_suite_claims($identity);
    if ($fresh !== null) $claims = $fresh;
    return suite_preferences_from_claim($claims);
}

function portal_personal_datetime(string $utc, array $preferences): string
{
    try {
        $date = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        $zone = new DateTimeZone($preferences['time_zone']);
        $date = $date->setTimezone($zone);
        $format = match ($preferences['date_format']) {
            'mdy' => 'm/d/Y', 'dmy' => 'd/m/Y', 'ymd' => 'Y-m-d',
            default => match ($preferences['locale']) {
                'en-GB' => 'd/m/Y', 'en-CA' => 'Y-m-d', 'es-US' => 'd/m/Y', default => 'm/d/Y',
            },
        };
        $time = $preferences['time_format'] === '24h'
            || ($preferences['time_format'] === 'locale' && $preferences['locale'] === 'en-GB')
            ? 'H:i' : 'g:i A';
        return $date->format($format . ' · ' . $time . ' T');
    } catch (Throwable) {
        return 'Not available';
    }
}
