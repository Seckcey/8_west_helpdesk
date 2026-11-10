<?php
/**
 * The three emails a customer gets about a request: received, answered, resolved.
 * Their words, their portal link, and that the link appears only where it leads somewhere.
 *
 * Synthetic data only, in an in-memory database. No settings file, no network, nothing is sent.
 * Run:  php app/tests/customer_mail_test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$settings = ['portal.enabled' => true];
function cfg(string $key, mixed $default = null): mixed
{
    global $settings;
    return $settings[$key] ?? $default;
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function db(): PDO
{
    global $pdo;
    return $pdo;
}
$pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE customer_portal_bindings (id INTEGER PRIMARY KEY, tenant_id INTEGER, client_id INTEGER, status TEXT)');
$pdo->exec('CREATE TABLE mail_queue (id INTEGER PRIMARY KEY, to_addr TEXT, subject TEXT, body_text TEXT, ticket_id INTEGER)');
$pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Harbor IT'), (2, 'Northwind Support')");
$pdo->exec("INSERT INTO customer_portal_bindings (tenant_id, client_id, status) VALUES (1, 11, 'active'), (1, 12, 'disabled'), (2, 11, 'active')");
require_once __DIR__ . '/../lib/mailer.php';

$checks = 0;
$failures = 0;
function mail_check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if (! $ok) $failures++;
    echo ($ok ? 'ok ' : 'FAIL ') . $checks . ' - ' . $name . "\n";
}
$link = 'https://safeharbor.8westit.com/portal/ticket.php?id=12';

/* ---- Who signs, and when a portal link is real ------------------------------------------------- */
mail_check('the signature names the support business from its own record, never a person',
    customer_mail_signature(customer_mail_business_name(1)) === 'Harbor IT support'
    && customer_mail_signature(customer_mail_business_name(2)) === 'Northwind Support'
    && customer_mail_signature(customer_mail_business_name(99)) === 'The support team'
    && customer_mail_business_name(0) === '');
mail_check('a client with active portal access gets a link to that exact request',
    customer_mail_portal_link(1, 11, 12) === $link);
mail_check('no link for a client whose portal access is off, another business\'s client, or a missing request',
    customer_mail_portal_link(1, 12, 12) === null && customer_mail_portal_link(1, 13, 12) === null
    && customer_mail_portal_link(2, 12, 12) === null && customer_mail_portal_link(1, 11, 0) === null);
$settings['portal.enabled'] = false;
mail_check('no link at all while the portal is switched off', customer_mail_portal_link(1, 11, 12) === null);
$settings['portal.enabled'] = true;
mail_check('a greeting uses a first name, and falls back when the "name" is an address or missing',
    customer_mail_greeting('Dana Whitfield') === 'Hi Dana,' && customer_mail_greeting('  ') === 'Hello,'
    && customer_mail_greeting('dana@harbordental.example') === 'Hello,');

/* ---- Received ---------------------------------------------------------------------------------- */
$received = customer_mail_received_body('Dana Whitfield', 12, "Can't print to the\r\n front office printer", '8 hours', 'Harbor IT', $link);
mail_check('received: says we have it, quotes the subject on one line and gives the request number',
    str_starts_with($received, "Hi Dana,\n\nWe have your request: \"Can't print to the front office printer\". It is request #12."));
mail_check('received: gives the reply goal in plain words and promises a person',
    str_contains($received, 'We aim to reply within 8 hours. A person on the support team will answer you by email.'));
mail_check('received: answering the email comes first, the portal link second',
    ($answer = strpos($received, 'To add more, answer this email.')) !== false
    && ($portal = strpos($received, $link)) !== false && $answer < $portal
    && str_contains($received, 'If you have a portal sign-in, you can also follow this request here:'));
mail_check('received: signed by the support business',
    str_ends_with($received, "\n\nThank you,\nHarbor IT support"));
$plain = customer_mail_received_body('', 12, 'Printer', '', '', null);
mail_check('received: without a goal, a name, a business name or portal access it still reads as a whole email',
    str_starts_with($plain, "Hello,\n\nWe have your request: \"Printer\". It is request #12.")
    && str_contains($plain, 'A person on the support team will answer you by email.')
    && ! str_contains($plain, 'We aim to reply') && ! str_contains($plain, 'portal')
    && str_ends_with($plain, "Thank you,\nThe support team"));

/* ---- Answered ---------------------------------------------------------------------------------- */
$reply = customer_mail_reply_body('Maya Reyes', 'Front desk printer is offline', 'Is the blue power light on?', 'Harbor IT', $link);
mail_check('reply: says who replied and to which request, then carries the reply itself',
    str_starts_with($reply, "Maya from Harbor IT replied to your request \"Front desk printer is offline\":\n\nIs the blue power light on?\n"));
mail_check('reply: says to answer by email, then links to the request in the portal',
    ($answer = strpos($reply, 'To answer, reply to this email.')) !== false && ($portal = strpos($reply, $link)) !== false && $answer < $portal
    && str_ends_with($reply, "\n\nHarbor IT support"));
mail_check('reply: the portal is a link, not a promise',
    ! str_contains($reply, 'coming soon') && ! str_contains(customer_mail_reply_body('Maya', 'Printer', 'Hello', '', null), 'portal'));
mail_check('reply: a long line is wrapped for plain-text mail',
    max(array_map('strlen', explode("\n", customer_mail_reply_body('Maya', 'Printer', str_repeat('word ', 60), '', null)))) <= 78);

/* ---- Resolved ---------------------------------------------------------------------------------- */
$base = 'https://safeharbor.8westit.com/csat.php?t=' . str_repeat('a', 40);
$resolved = customer_mail_resolved_body('Dana Whitfield', 'Front desk printer is offline', $base, 'Harbor IT', $link);
mail_check('resolved: says the request is marked resolved and asks how we did',
    str_starts_with($resolved, "Hi Dana,\n\nWe have marked your request \"Front desk printer is offline\" as resolved.\n\nHow did we do?"));
mail_check('resolved: the three answers are three labelled links with the scores the survey page expects',
    str_contains($resolved, "  Great:  {$base}&s=3\n  Okay:   {$base}&s=2\n  Rough:  {$base}&s=1\n")
    && str_contains($resolved, 'then press Send on the page that opens'));
mail_check('resolved: says how to reopen, then links to the conversation',
    ($reopen = strpos($resolved, 'Still not fixed? Answer this email and the request opens again.')) !== false
    && ($portal = strpos($resolved, $link)) !== false && $reopen < $portal);

/* ---- Wording that must not come back ----------------------------------------------------------- */
$all = (string)preg_replace('~https://\S+~', '', $received . $plain . $reply . $resolved);   // words only: addresses are checked above
mail_check('no email says ticket, SLA or tech, asks the customer to keep a code in the subject, or names the product vendor',
    ! preg_match('/\bticket|\bSLA\b|\ba tech\b|in the subject|Safeharbor|8 West/i', $all));

/* ---- The reply notice as it is queued: same recipient rule, same subject, new words ------------- */
$ticket = ['id' => 12, 'tenant_id' => 1, 'client_id' => 11, 'subject' => 'Front desk printer is offline'];
mail_notify_reply($ticket, 'dana@harbordental.example', 'Maya Reyes', 'Is the blue power light on?');
$queued = $pdo->query('SELECT * FROM mail_queue')->fetchAll();
mail_check('a reply to a real contact queues one email with the threading subject unchanged',
    count($queued) === 1 && $queued[0]['to_addr'] === 'dana@harbordental.example'
    && $queued[0]['subject'] === '[#12] Front desk printer is offline' && (int)$queued[0]['ticket_id'] === 12);
mail_check('the queued reply is the plain-words email with the portal link for that client',
    $queued[0]['body_text'] === $reply);
mail_notify_reply(['id' => 13, 'tenant_id' => 1, 'client_id' => 12, 'subject' => 'Scanner'], 'sam@tannerengineering.example', 'Maya Reyes', 'Fixed.');
mail_check('a client without portal access gets the same email without a link',
    ! str_contains((string)$pdo->query('SELECT body_text FROM mail_queue WHERE ticket_id = 13')->fetchColumn(), '/portal/'));
foreach (['mailer-daemon@remote.example', 'no-reply@remote.example', 'bounces@remote.example'] as $machine) {
    mail_notify_reply($ticket, $machine, 'Maya Reyes', 'Hello');
}
mail_check('a machine address is still never mailed',
    (int)$pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn() === 2);

echo "Customer mail: {$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
