<?php
/** Customer-scoped support request creation. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/bootstrap.php';
require_once __DIR__ . '/../../lib/portal_auth.php';
require_once __DIR__ . '/../../lib/portal_data.php';
require_once __DIR__ . '/../../lib/portal_render.php';

enforce_https();
if (! portal_enabled()) {
    http_response_code(404);
    exit('Not found.');
}
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (! in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    portal_render_error(405, 'Method not allowed', 'Use the customer portal form to ask for help.');
    exit;
}

try {
    $context = portal_authenticated_context(db());
} catch (PortalIdentityUnavailableException $error) {
    error_log('[safeharbor-portal] new_ticket_denied reason=revocation_unavailable');
    portal_render_error(503, 'Temporarily unavailable', $error->getMessage());
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] new_ticket_context_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'The customer portal is temporarily unavailable. Try again shortly.');
    exit;
}
if ($context === null) {
    portal_render_login('Sign in before opening a support request.');
    exit;
}
if (! portal_role_can_write_tickets((string)$context['identity']['role'])) {
    portal_render_error(403, 'Read-only access', 'Your customer viewer role cannot open or change tickets.');
    exit;
}

$values = [
    'subject' => is_string($_POST['subject'] ?? null) ? $_POST['subject'] : '',
    'priority' => is_string($_POST['priority'] ?? null) ? $_POST['priority'] : 'normal',
    'body' => is_string($_POST['body'] ?? null) ? $_POST['body'] : '',
];
if ($method === 'POST') {
    if (! portal_csrf_valid($_POST['csrf'] ?? null)) {
        portal_render_error(400, 'Form expired', 'Refresh the page and try again.');
        exit;
    }
    if (! portal_action_nonce_consume('ticket:create', $_POST['action_nonce'] ?? null)) {
        portal_render_error(409, 'Request already handled', 'Check the dashboard first, then refresh the help form if you need another request.');
        exit;
    }
    // Persist the consumed nonce before the database mutation. This closes the
    // session lock and prevents a lost response or worker interruption from
    // letting the same browser form create a duplicate ticket.
    if (! session_write_close()) {
        portal_render_error(503, 'Temporarily unavailable', 'The form could not be safely locked. Refresh and try again.');
        exit;
    }
    try {
        $ticketId = portal_create_ticket(
            db(),
            (int)$context['identity']['tenant_id'],
            (int)$context['identity']['client_id'],
            (string)$context['identity']['role'],
            (string)$context['identity']['display_name'],
            $values['subject'],
            $values['priority'],
            $values['body'],
        );
        header('Location: /portal/ticket.php?id=' . $ticketId . '&created=1', true, 303);
        exit;
    } catch (PortalDataValidationException $error) {
        portal_render_new_ticket($context, $error->getMessage(), $values);
        exit;
    } catch (PortalDataNotFoundException $error) {
        portal_render_error(409, 'Customer mapping changed', $error->getMessage());
        exit;
    } catch (Throwable $error) {
        error_log('[safeharbor-portal] new_ticket_failed type=' . $error::class);
        portal_render_new_ticket(
            $context,
            'We could not confirm whether the request was saved. Check the dashboard before trying again.',
            $values,
        );
        exit;
    }
}

portal_render_new_ticket($context, null, $values);
