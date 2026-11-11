<?php
/**
 * "Log a call": what the New ticket form sends, what is wrong with it, and the form itself.
 *
 * No database work here. public/ticket_new.php checks the client, adds the contact, captures the
 * service goal and writes the ticket in one transaction; this file only reads the request and
 * draws the page, so both can be checked on their own.
 *
 * Needs render.php (h(), PRIORITY_META) for the form.
 */
declare(strict_types=1);

/** How a ticket a person types in can have come in. An alert is never typed in by hand. */
const TICKET_NEW_CHANNELS = ['phone' => 'Phone', 'email' => 'Email', 'portal' => 'Portal'];

/** Column sizes (tickets.subject, contacts.name, contacts.email). */
const TICKET_NEW_SUBJECT_MAX = 190;
const TICKET_NEW_CONTACT_NAME_MAX = 128;
const TICKET_NEW_CONTACT_EMAIL_MAX = 190;

/**
 * What the form sent, with anything unknown replaced by its default. A new contact's name, when
 * given, wins over a contact picked from the list.
 *
 * @return array{client_id:int,contact_id:int,contact_name:string,contact_email:string,subject:string,
 *               said:string,told:string,channel:string,priority:string,start_timer:bool,resolve:bool}
 */
function ticket_new_input(array $post): array
{
    $text = static fn(string $key): string => is_string($post[$key] ?? null) ? trim($post[$key]) : '';
    $number = static fn(string $key): int => is_string($post[$key] ?? null) && ctype_digit($post[$key]) && strlen($post[$key]) <= 9 ? (int)$post[$key] : 0;
    $contactName = $text('contact_name');
    return [
        'client_id' => $number('client_id'),
        'contact_id' => $contactName === '' ? $number('contact_id') : 0,
        'contact_name' => $contactName,
        'contact_email' => mb_strtolower($text('contact_email')),
        'subject' => $text('subject'),
        'said' => $text('said'),
        'told' => $text('told'),
        'channel' => isset(TICKET_NEW_CHANNELS[$text('channel')]) ? $text('channel') : 'phone',
        'priority' => in_array($text('priority'), ['low', 'normal', 'high', 'urgent'], true) ? $text('priority') : 'normal',
        'start_timer' => $text('start_timer') === '1',
        'resolve' => $text('after') === 'resolved',
    ];
}

/** An empty form: a phone call, normal priority, the timer on. $clientId preselects a client. */
function ticket_new_blank(int $clientId = 0): array
{
    return array_replace(ticket_new_input([]), ['client_id' => $clientId, 'start_timer' => true]);
}

/** The one thing to fix before the ticket can be created, in words; '' when nothing is wrong. */
function ticket_new_problem(array $input): string
{
    if ($input['client_id'] < 1) return 'Choose a client from the list.';
    if ($input['subject'] === '') return 'Add a subject so the ticket can be found later.';
    if (mb_strlen($input['subject']) > TICKET_NEW_SUBJECT_MAX) {
        return 'Shorten the subject to ' . TICKET_NEW_SUBJECT_MAX . ' characters or fewer.';
    }
    if ($input['contact_name'] === '' && $input['contact_email'] !== '') {
        return 'Add the new contact\'s name, or clear their email address.';
    }
    if (mb_strlen($input['contact_name']) > TICKET_NEW_CONTACT_NAME_MAX) {
        return 'Shorten the new contact\'s name to ' . TICKET_NEW_CONTACT_NAME_MAX . ' characters or fewer.';
    }
    if ($input['contact_email'] !== '' && (strlen($input['contact_email']) > TICKET_NEW_CONTACT_EMAIL_MAX
        || filter_var($input['contact_email'], FILTER_VALIDATE_EMAIL) === false)) {
        return 'That email address does not look right. Check it, or leave it empty.';
    }
    return '';
}

/** One row of choices where exactly one is on: radios drawn as a segmented filter. */
function ticket_new_segments(string $name, string $legend, array $choices, string $current): string
{
    $html = '<fieldset class="seg-field"><legend>' . h($legend) . '</legend><div class="seg">';
    foreach ($choices as $value => $label) {
        $html .= '<label class="seg-item"><input type="radio" name="' . h($name) . '" value="' . h($value) . '"'
            . ($value === $current ? ' checked' : '') . '><span>' . h($label) . '</span></label>';
    }
    return $html . '</div></fieldset>';
}

/**
 * The form. It works as plain HTML (a client list, a contact list, a disclosure for a new
 * contact); the script turns the client list into a box you type in and narrows the contacts to
 * the chosen client.
 *
 * @param list<array{id:int|string,name:string,sla_tier:string}> $clients  ticket_new_clients()
 * @param list<array{id:int|string,client_id:int|string,name:string}> $contacts  ticket_new_contacts()
 * @param array<string,mixed> $values  ticket_new_input() or ticket_new_blank()
 * @param string $csrf  csrf_field()
 * @param string $action  where the form posts; $cancel: where Cancel goes
 */
function ticket_new_form(array $clients, array $contacts, array $values, string $csrf, string $action, string $cancel): string
{
    $priorities = [];
    foreach (['low', 'normal', 'high', 'urgent'] as $priority) $priorities[$priority] = PRIORITY_META[$priority][0] ?? ucfirst($priority);

    $html = '<form method="post" action="' . h($action) . '" class="form-grid call-form" id="ticket-new-form">' . $csrf;

    // Client: a list that works by itself; the script puts a type-to-find box in front of it.
    $html .= '<div class="field" id="call-client"><label for="f-client">Client</label>'
        . '<select name="client_id" id="f-client" required><option value="">Choose a client</option>';
    foreach ($clients as $client) {
        $html .= '<option value="' . (int)$client['id'] . '" data-plan="' . h(ucfirst((string)$client['sla_tier'])) . '"'
            . ((int)$client['id'] === (int)$values['client_id'] ? ' selected' : '') . '>' . h($client['name']) . '</option>';
    }
    $html .= '</select></div>';

    // Who asked: one of the client's contacts, or a new one typed in here.
    $newOpen = $values['contact_name'] !== '' || $values['contact_email'] !== '';
    $html .= '<div class="field" id="call-contact"><label for="f-contact">Who asked</label>'
        . '<select name="contact_id" id="f-contact"><option value="">Choose a contact</option>';
    foreach ($contacts as $contact) {
        $html .= '<option value="' . (int)$contact['id'] . '" data-client="' . (int)$contact['client_id'] . '"'
            . ((int)$contact['id'] === (int)$values['contact_id'] ? ' selected' : '') . '>' . h($contact['name']) . '</option>';
    }
    $html .= '</select>'
        . '<details class="call-new" id="contact-new"' . ($newOpen ? ' open' : '') . '><summary>Add a new contact</summary>'
        . '<div class="call-new-fields">'
        . '<label class="call-sub" for="f-contact-name">Name<input type="text" name="contact_name" id="f-contact-name" maxlength="' . TICKET_NEW_CONTACT_NAME_MAX . '"'
        . ' autocomplete="off" value="' . h($values['contact_name']) . '"></label>'
        . '<label class="call-sub" for="f-contact-email">Email (optional)<input type="email" name="contact_email" id="f-contact-email" maxlength="' . TICKET_NEW_CONTACT_EMAIL_MAX . '"'
        . ' autocomplete="off" value="' . h($values['contact_email']) . '"></label>'
        . '</div><p class="field-help">They are saved as a contact of this client and used for this ticket.</p></details></div>';

    $html .= '<div class="field span-2"><label for="f-subject">Subject</label>'
        . '<input type="text" name="subject" id="f-subject" required maxlength="' . TICKET_NEW_SUBJECT_MAX . '" autocomplete="off" value="' . h($values['subject']) . '"></div>';

    $html .= '<div class="field span-2"><label for="f-said">What they said</label>'
        . '<textarea name="said" id="f-said" rows="3" aria-describedby="f-said-help">' . h($values['said']) . '</textarea>'
        . '<p class="field-help" id="f-said-help">The customer\'s words. Saved as their message on the ticket.</p></div>';

    $html .= '<div class="field span-2"><label for="f-told">What you told them (optional)</label>'
        . '<textarea name="told" id="f-told" rows="3" aria-describedby="f-told-help">' . h($values['told']) . '</textarea>'
        . '<p class="field-help" id="f-told-help">Counts as the first reply. It is saved on the ticket and is not emailed.</p></div>';

    $html .= ticket_new_segments('channel', 'Came in by', TICKET_NEW_CHANNELS, (string)$values['channel'])
        . ticket_new_segments('priority', 'Priority', $priorities, (string)$values['priority']);

    $html .= '<label class="call-check span-2"><input type="checkbox" name="start_timer" value="1"' . ($values['start_timer'] ? ' checked' : '') . '>'
        . '<span>Start my timer</span></label>';

    $html .= '<div class="form-actions call-actions span-2">'
        . '<button type="submit" class="btn-primary" id="call-create">Create ticket</button>'
        . '<button type="submit" class="btn-secondary" id="call-resolve" name="after" value="resolved">Create and resolve</button>'
        . '<a class="btn-link" href="' . h($cancel) . '">Cancel</a>'
        . '<span class="call-keys"><kbd class="kbd" data-mod-key>Ctrl</kbd><kbd class="kbd">Enter</kbd> creates</span>'
        . '</div>'
        . '<p class="field-help span-2">The ticket is assigned to you. Create and resolve asks the contact to rate it, if they have an email address.</p>';

    return $html . '</form>';
}
