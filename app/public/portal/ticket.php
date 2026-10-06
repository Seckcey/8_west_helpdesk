<?php
/** Customer-visible ticket conversation and safe customer replies. */
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
    portal_render_error(405, 'Method not allowed', 'Use the ticket page to read or reply.');
    exit;
}
$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId < 1) {
    portal_render_error(404, 'Ticket not found', 'That ticket is not available for this business.');
    exit;
}

try {
    $context = portal_authenticated_context(db());
} catch (PortalIdentityUnavailableException $error) {
    error_log('[safeharbor-portal] ticket_denied reason=revocation_unavailable');
    portal_render_error(503, 'Temporarily unavailable', $error->getMessage());
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] ticket_context_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'The customer portal is temporarily unavailable. Try again shortly.');
    exit;
}
if ($context === null) {
    portal_require_sign_in('Sign in before viewing a support ticket.');
    exit;
}

$draft = is_string($_POST['body'] ?? null) ? $_POST['body'] : '';
$errorMessage = null;
if ($method === 'POST') {
    if (! portal_role_can_write_tickets(portal_customer_role($context['identity']))) {
        portal_render_error(403, 'Read-only access', 'Your customer viewer role cannot reply to tickets.');
        exit;
    }
    if (! portal_csrf_valid($_POST['csrf'] ?? null)) {
        portal_render_error(400, 'Form expired', 'Refresh the page and try again.');
        exit;
    }
    if (! portal_action_nonce_consume('ticket:reply:' . $ticketId, $_POST['action_nonce'] ?? null)) {
        portal_render_error(409, 'Reply already handled', 'Check the conversation first, then refresh before sending another reply.');
        exit;
    }
    // Persist the consumed nonce before the database mutation so a lost
    // response cannot make the same form replayable.
    if (! session_write_close()) {
        portal_render_error(503, 'Temporarily unavailable', 'The reply form could not be safely locked. Refresh and try again.');
        exit;
    }
    try {
        portal_reply_to_ticket(
            db(),
            (int)$context['identity']['tenant_id'],
            (int)$context['identity']['client_id'],
            $ticketId,
            portal_customer_role($context['identity']),
            (string)$context['identity']['display_name'],
            $draft,
        );
        header('Location: /portal/ticket.php?id=' . $ticketId . '&replied=1', true, 303);
        exit;
    } catch (PortalDataValidationException|PortalDataConflictException $error) {
        $errorMessage = $error->getMessage();
    } catch (PortalDataNotFoundException $error) {
        portal_render_error(404, 'Ticket not found', $error->getMessage());
        exit;
    } catch (Throwable $error) {
        error_log('[safeharbor-portal] ticket_reply_failed type=' . $error::class);
        $errorMessage = 'We could not confirm whether the reply was saved. Check the conversation before trying again.';
    }
}

try {
    $detail = portal_ticket_detail(
        db(),
        (int)$context['identity']['tenant_id'],
        (int)$context['identity']['client_id'],
        $ticketId,
    );
} catch (PortalDataNotFoundException|PortalDataValidationException $error) {
    portal_render_error(404, 'Ticket not found', 'That ticket is not available for this business.');
    exit;
} catch (Throwable $error) {
    error_log('[safeharbor-portal] ticket_read_failed type=' . $error::class);
    portal_render_error(503, 'Temporarily unavailable', 'This ticket is temporarily unavailable. Try again shortly.');
    exit;
}

$notice = null;
if (($_GET['created'] ?? '') === '1') $notice = 'Your support request was sent.';
if (($_GET['replied'] ?? '') === '1') $notice = 'Your reply was sent.';
portal_render_ticket($context, $detail, $notice, $errorMessage, $errorMessage === null ? '' : $draft);
