<?php
declare(strict_types=1);

/** 8 West ID's exact origin, with the same fixed fallback as logout.php if the configured issuer can't be used. */
function portal_issuer_origin(): string
{
    try {
        $issuer = function_exists('portal_configured_issuer') ? parse_url(portal_configured_issuer()) : [];
    } catch (PortalAuthConfigurationException) {
        $issuer = [];
    }
    if (empty($issuer['host'])) return 'https://id.8westit.com';
    return 'https://' . $issuer['host'] . (isset($issuer['port']) ? ':' . $issuer['port'] : '');
}
