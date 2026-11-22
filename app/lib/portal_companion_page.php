<?php
declare(strict_types=1);

/** Presentation only: this never creates or validates authentication authority. */
function portal_companion_page(): bool
{
    $pair = $_SESSION['desktop_companion_session'] ?? null;
    return in_array(parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH), ['/portal/desktop.php', '/portal/signed-out.php'], true)
        || (is_string($pair) && preg_match('/\A[a-f0-9]{32}\z/D', $pair) === 1);
}
