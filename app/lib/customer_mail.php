<?php
/**
 * The words of the three emails a customer gets about a request: received, answered, resolved.
 *
 * Words only. Who gets an email, and when, is decided by the callers (intake.php, mail_notify_reply()
 * and csat_send()) and by the mail-loop guard; nothing here sends or decides anything. Every email is
 * plain text, starts with the way that always works (answer this email), and adds the portal link only
 * for a client that can actually sign in to the portal, in a sentence that says who can open it there.
 */
declare(strict_types=1);

const CUSTOMER_MAIL_SITE = 'https://safeharbor.8westit.com';

/** "Dana" from "Dana Whitfield". Empty when the name is really an address or a mailbox word. */
function customer_mail_first_name(string $name): string
{
    $first = (string)(preg_split('/\s+/', trim($name))[0] ?? '');
    $first = trim($first, " \t,.;:\"'");
    if ($first === '' || str_contains($first, '@') || mb_strlen($first) > 40) return '';
    return $first;
}

function customer_mail_greeting(string $name): string
{
    $first = customer_mail_first_name($name);
    return $first === '' ? 'Hello,' : "Hi {$first},";
}

/** The support business's own name (the tenant), or '' when it cannot be read. Never a person's name. */
function customer_mail_business_name(int $tenantId): string
{
    if ($tenantId < 1) return '';
    try {
        $query = db()->prepare('SELECT name FROM tenants WHERE id = ?');
        $query->execute([$tenantId]);
        return trim((string)($query->fetchColumn() ?: ''));
    } catch (Throwable) {
        return '';   // wording must never stop a mail or an intake
    }
}

/** The closing line: "Harbor IT support", or "The support team" when the business name is unknown. */
function customer_mail_signature(string $businessName): string
{
    if ($businessName === '') return 'The support team';
    return preg_match('/\bsupport$/i', $businessName) ? $businessName : $businessName . ' support';
}

/**
 * The request's page in the customer portal, or null when a link would lead nowhere: the portal is
 * switched off, or this client has no active portal access.
 *
 * A request that arrived by email has no portal requester, so in the portal only the client's owners
 * and admins can open it, and nothing here can tell whether the person being emailed is one of them.
 * Every sentence that carries this link therefore names who can use it.
 */
function customer_mail_portal_link(int $tenantId, int $clientId, int $ticketId): ?string
{
    if ($tenantId < 1 || $clientId < 1 || $ticketId < 1) return null;
    if (cfg('portal.enabled', false) !== true) return null;
    try {
        $query = db()->prepare(
            "SELECT 1 FROM customer_portal_bindings
              WHERE tenant_id = ? AND client_id = ? AND status = 'active' LIMIT 1"
        );
        $query->execute([$tenantId, $clientId]);
        if ($query->fetchColumn() === false) return null;
    } catch (Throwable) {
        return null;
    }
    return CUSTOMER_MAIL_SITE . '/portal/ticket.php?id=' . $ticketId;
}

/** One line of a subject, safe to quote inside a sentence. */
function customer_mail_subject_text(string $subject): string
{
    $subject = trim((string)preg_replace('/\s+/', ' ', $subject));
    return $subject === '' ? '(no subject)' : $subject;
}

/**
 * "We have your request." Sent once, when an email opens a request.
 *
 * @param string $replyWindow the first-reply goal in words ("8 hours"), or '' when there is none to promise
 */
function customer_mail_received_body(
    string $contactName,
    int $ticketId,
    string $subject,
    string $replyWindow,
    string $businessName,
    ?string $portalLink,
): string {
    $lines = [
        customer_mail_greeting($contactName),
        '',
        'We have your request: "' . customer_mail_subject_text($subject) . '". It is request #' . $ticketId . '.',
        '',
        $replyWindow !== ''
            ? "We aim to reply within {$replyWindow}. A person on the support team will answer you by email."
            : 'A person on the support team will answer you by email.',
        '',
        'To add more, answer this email.',
    ];
    if ($portalLink !== null) {
        $lines[] = 'Owners and admins of your business can also follow this request in the support portal:';
        $lines[] = $portalLink;
    }
    array_push($lines, '', 'Thank you,', customer_mail_signature($businessName));
    return implode("\n", $lines);
}

/** "Maya from Harbor IT replied." Sent when a technician answers a request that has an email contact. */
function customer_mail_reply_body(
    string $authorName,
    string $subject,
    string $replyBody,
    string $businessName,
    ?string $portalLink,
): string {
    $first = customer_mail_first_name($authorName);
    $who = $first === '' ? 'The support team' : $first . ' from ' . ($businessName === '' ? 'the support team' : $businessName);
    $lines = [
        $who . ' replied to your request "' . customer_mail_subject_text($subject) . '":',
        '',
        wordwrap($replyBody, 78),
        '',
        'To answer, reply to this email.',
    ];
    if ($portalLink !== null) {
        $lines[] = 'Owners and admins of your business can also open this request in the support portal:';
        $lines[] = $portalLink;
    }
    array_push($lines, '', customer_mail_signature($businessName));
    return implode("\n", $lines);
}

/**
 * "We have marked this request as resolved. How did we do?" Sent on the first resolve.
 *
 * @param string $surveyBase the survey address without the score; "&s=3", "&s=2" or "&s=1" is added here
 */
function customer_mail_resolved_body(
    string $contactName,
    string $subject,
    string $surveyBase,
    string $businessName,
    ?string $portalLink,
): string {
    $lines = [
        customer_mail_greeting($contactName),
        '',
        'We have marked your request "' . customer_mail_subject_text($subject) . '" as resolved.',
        '',
        'How did we do? Pick one, then press Send on the page that opens:',
        '',
        "  Great:  {$surveyBase}&s=3",
        "  Okay:   {$surveyBase}&s=2",
        "  Rough:  {$surveyBase}&s=1",
        '',
        'Still not fixed? Answer this email and the request opens again.',
    ];
    if ($portalLink !== null) {
        $lines[] = 'Owners and admins of your business can read the whole conversation in the support portal:';
        $lines[] = $portalLink;
    }
    array_push($lines, '', 'Thank you,', customer_mail_signature($businessName));
    return implode("\n", $lines);
}
