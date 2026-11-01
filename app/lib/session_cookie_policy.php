<?php
/**
 * A POST from another site must not replace anybody's session cookie.
 *
 * A top-level POST from another site arrives without the SameSite=Lax session cookie, so session_start() opens a
 * new session and sends its cookie, and the browser stores that cookie because the response is a top-level
 * navigation. The real one is gone: a password account is signed out, and an 8 West ID person is signed straight
 * back in with every open tab stale. Any website can do that to anybody.
 *
 * Such a request never needs the cookie. Anything a POST does needs a token from a session the browser already
 * holds, or proves itself some other way, and the GET after any redirect does carry the Lax cookie, so the
 * person simply carries on in their own session.
 */
declare(strict_types=1);

/**
 * Sec-Fetch-Site says where a browser's request came from. same-site doesn't count: it carries the Lax cookie,
 * so one that arrives without it has nothing to replace. Where that header is missing (Safari before 16.4, or
 * any browser posting to a plain-http page), every POST still has an Origin, and any that isn't this host counts,
 * "null" included. That can't tell a sibling app from a stranger, which costs nothing: see above. With neither
 * header the caller isn't a browser (api/svc callers, curl), and has no cookie to lose.
 */
function session_request_is_cross_site_post(array $server): bool
{
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (isset($server['HTTP_SEC_FETCH_SITE'])) return $server['HTTP_SEC_FETCH_SITE'] === 'cross-site';
    $origin = (string)($server['HTTP_ORIGIN'] ?? '');
    if ($origin === '') return false;
    return strcasecmp((string)preg_replace('~^https?://~i', '', $origin), (string)($server['HTTP_HOST'] ?? '')) !== 0;
}

/**
 * Call just before session_start(), once the session's name is set. The session still runs, so nothing after it
 * needs to know, but it ends with the request: no cookie goes out, and the code that deletes a session's cookie
 * checks this same setting.
 */
function session_withhold_cookie_from_cross_site_post(): void
{
    if (! isset($_COOKIE[session_name()]) && session_request_is_cross_site_post($_SERVER)) {
        ini_set('session.use_cookies', '0');
    }
}
