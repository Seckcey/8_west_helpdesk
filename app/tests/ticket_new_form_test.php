<?php
/**
 * "Log a call": what the New ticket form sends and what is wrong with it, the form's markup, the
 * shared contact insert, and rows that do not name the client as the person who asked
 * (lib/ticket_new_form.php, lib/contacts.php, lib/render.php, lib/ticket_page.php), loaded with
 * isolated dependency edges: no config, no MySQL. Creating the ticket is exercised against MySQL
 * in ticket_action_http_test.php.
 *
 *   php app/tests/ticket_new_form_test.php            runs the checks
 *   php app/tests/ticket_new_form_test.php --render   prints the form built from the same helpers,
 *       for tools/shots/ticket-new.test.mjs
 */
declare(strict_types=1);
$checks = 0;
function form_check(bool $ok, string $name): void
{
    global $checks;
    if (!$ok) throw new RuntimeException($name);
    $checks++;
}

$temp = sys_get_temp_dir() . '/safeharbor-ticket-new-' . bin2hex(random_bytes(8));
mkdir($temp, 0700);
try {
    copy(__DIR__ . '/../lib/render.php', $temp . '/render.php');
    file_put_contents($temp . '/auth.php', '<?php require_once ' . var_export(realpath(__DIR__ . '/../lib/service_goals.php'), true) . ';'
        . ' function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,"UTF-8"); } function rel_time($v): string { return "2h"; }');
    file_put_contents($temp . '/westy.php', '<?php');
    require $temp . '/render.php';
    require __DIR__ . '/../lib/ticket_page.php';
    require __DIR__ . '/../lib/ticket_new_form.php';
    require __DIR__ . '/../lib/contacts.php';

    $dom = static function (string $html): DOMXPath {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($doc);
    };
    $texts = static function (DOMXPath $x, string $query): array {
        $out = [];
        foreach ($x->query($query) as $node) $out[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        return $out;
    };
    $attrs = static function (DOMXPath $x, string $query, string $name): array {
        $out = [];
        foreach ($x->query($query) as $node) $out[] = $node->getAttribute($name);
        return $out;
    };

    // ---- what the form sent ----
    $blank = ticket_new_blank();
    form_check($blank === ['client_id' => 0, 'contact_id' => 0, 'contact_name' => '', 'contact_email' => '', 'subject' => '', 'said' => '', 'told' => '',
        'channel' => 'phone', 'priority' => 'normal', 'start_timer' => true, 'resolve' => false], 'an empty form is a phone call at normal priority with the timer on');
    form_check(ticket_new_blank(12)['client_id'] === 12, 'a client can be preselected');
    $sent = ticket_new_input(['client_id' => '12', 'contact_id' => '31', 'subject' => "  Can't print  ", 'said' => " The printer is offline. \n", 'told' => 'Restart it.',
        'channel' => 'email', 'priority' => 'urgent', 'start_timer' => '1', 'after' => 'resolved']);
    form_check($sent === ['client_id' => 12, 'contact_id' => 31, 'contact_name' => '', 'contact_email' => '', 'subject' => "Can't print", 'said' => 'The printer is offline.',
        'told' => 'Restart it.', 'channel' => 'email', 'priority' => 'urgent', 'start_timer' => true, 'resolve' => true], 'what was typed is read back, trimmed');
    $odd = ticket_new_input(['client_id' => '12 OR 1=1', 'contact_id' => ['31'], 'subject' => ['x'], 'channel' => 'alert', 'priority' => 'critical', 'start_timer' => 'yes', 'after' => 'deleted']);
    form_check($odd['client_id'] === 0 && $odd['contact_id'] === 0 && $odd['subject'] === '' && $odd['channel'] === 'phone' && $odd['priority'] === 'normal'
        && $odd['start_timer'] === false && $odd['resolve'] === false, 'anything that is not a known choice becomes the default; an alert cannot be typed in by hand');
    form_check(array_keys(TICKET_NEW_CHANNELS) === ['phone', 'email', 'portal'], 'a ticket can come in by phone, email or the portal, phone first');
    $fresh = ticket_new_input(['client_id' => '12', 'contact_id' => '31', 'contact_name' => ' Lee Okafor ', 'contact_email' => ' Lee@Example.TEST ', 'subject' => 'x']);
    form_check($fresh['contact_name'] === 'Lee Okafor' && $fresh['contact_email'] === 'lee@example.test' && $fresh['contact_id'] === 0,
        'a new contact\'s name wins over a contact picked from the list, and their address is lower-cased');
    form_check(ticket_new_input(['start_timer' => '1'])['start_timer'] === true && ticket_new_input([])['start_timer'] === false,
        'an unticked timer box sends nothing, so it stays off');

    // ---- the one thing to fix ----
    $good = array_replace($blank, ['client_id' => 12, 'subject' => 'Can\'t print']);
    form_check(ticket_new_problem($good) === '', 'a client and a subject are enough');
    form_check(ticket_new_problem(array_replace($good, ['client_id' => 0])) === 'Choose a client from the list.', 'no client: say so');
    form_check(ticket_new_problem(array_replace($good, ['subject' => ''])) === 'Add a subject so the ticket can be found later.', 'no subject: say why it is needed');
    form_check(ticket_new_problem(array_replace($good, ['subject' => str_repeat('é', 190)])) === ''
        && ticket_new_problem(array_replace($good, ['subject' => str_repeat('é', 191)])) === 'Shorten the subject to 190 characters or fewer.', 'a subject that would not fit says how long it may be');
    form_check(ticket_new_problem(array_replace($good, ['contact_email' => 'lee@example.test'])) === 'Add the new contact\'s name, or clear their email address.',
        'an address with no name is not a contact');
    form_check(ticket_new_problem(array_replace($good, ['contact_name' => 'Lee', 'contact_email' => 'lee@example.test'])) === ''
        && ticket_new_problem(array_replace($good, ['contact_name' => 'Lee'])) === '', 'a new contact needs a name; the address is optional');
    foreach (['lee', 'lee@', 'lee @example.test', 'lee@example.test, dana@example.test', str_repeat('a', 180) . '@example.test'] as $bad) {
        form_check(ticket_new_problem(array_replace($good, ['contact_name' => 'Lee', 'contact_email' => $bad])) === 'That email address does not look right. Check it, or leave it empty.',
            "\"$bad\" is not an address");
    }
    form_check(ticket_new_problem(array_replace($good, ['contact_name' => str_repeat('n', 129)])) === 'Shorten the new contact\'s name to 128 characters or fewer.', 'a name that would not fit says so');

    // ---- the shared contact insert ----
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL DEFAULT "")');
    $id = contact_add($pdo, 12, '  Lee Okafor ', ' Lee@Example.TEST ');
    form_check($id === 1 && $pdo->query('SELECT client_id, name, email FROM contacts')->fetchAll(PDO::FETCH_NUM) === [[12, 'Lee Okafor', 'lee@example.test']],
        'a contact is added to its client, trimmed, with a lower-case address');
    form_check(contact_add($pdo, 12, 'Dana', '') === 2 && contact_add($pdo, 12, '   ', 'x@example.test') === null && contact_add($pdo, 0, 'Dana', '') === null
        && (int)$pdo->query('SELECT COUNT(*) FROM contacts')->fetchColumn() === 2, 'the address is optional; no name, or no client, adds nothing');

    // ---- the form ----
    $clients = [['id' => 11, 'name' => 'Fixture <Dental>', 'sla_tier' => 'premium'], ['id' => 12, 'name' => 'Bayside Realty', 'sla_tier' => 'standard'],
        ['id' => 13, 'name' => 'Bay Street Books', 'sla_tier' => 'standard'], ['id' => 14, 'name' => 'Harbor Bakery', 'sla_tier' => 'standard']];
    $contacts = [['id' => 31, 'client_id' => 12, 'name' => 'Dana <Whitfield>'], ['id' => 32, 'client_id' => 12, 'name' => 'Luis Ortega'], ['id' => 33, 'client_id' => 11, 'name' => 'Priya Anand']];
    $csrf = '<input type="hidden" name="csrf" value="fixture">';
    $html = ticket_new_form($clients, $contacts, $blank, $csrf, '/ticket_new.php', '/');
    $x = $dom($html);
    form_check($x->query('//form[@id="ticket-new-form"][@method="post"][@action="/ticket_new.php"]//input[@name="csrf"][@value="fixture"]')->length === 1, 'the form posts to its own page with the token');
    form_check($texts($x, '//select[@id="f-client"]/option') === ['Choose a client', 'Fixture <Dental>', 'Bayside Realty', 'Bay Street Books', 'Harbor Bakery']
        && $attrs($x, '//select[@id="f-client"]/option', 'data-plan') === ['', 'Premium', 'Standard', 'Standard', 'Standard']
        && $x->query('//select[@id="f-client"]/option[@selected]')->length === 0, 'clients are listed by name only, with the plan as a word beside each, and none is chosen');
    form_check($texts($x, '//select[@id="f-contact"]/option') === ['Choose a contact', 'Dana <Whitfield>', 'Luis Ortega', 'Priya Anand']
        && $attrs($x, '//select[@id="f-contact"]/option[@data-client]', 'data-client') === ['12', '12', '11'], 'contacts say which client they belong to; the first choice is words, not a dash');
    form_check($x->query('//details[@id="contact-new"][not(@open)]')->length === 1 && $texts($x, '//details[@id="contact-new"]/summary') === ['Add a new contact']
        && $x->query('//details//input[@name="contact_name"]')->length === 1 && $x->query('//details//input[@name="contact_email"][@type="email"]')->length === 1,
        'a new contact can be added in place, closed until asked for');
    form_check($texts($x, '//label[@for]') === ['Client', 'Who asked', 'Name', 'Email (optional)', 'Subject', 'What they said', 'What you told them (optional)'],
        'every field has a label in words');
    foreach ($x->query('//label[@for]') as $label) {
        form_check($x->query('//*[@id="' . $label->getAttribute('for') . '"]')->length === 1, 'the label "' . trim($label->textContent) . '" points at its field');
    }
    form_check($texts($x, '//fieldset/legend') === ['Came in by', 'Priority']
        && $texts($x, '//fieldset[1]//label') === ['Phone', 'Email', 'Portal'] && $attrs($x, '//fieldset[1]//input[@checked]', 'value') === ['phone']
        && $texts($x, '//fieldset[2]//label') === ['Low', 'Normal', 'High', 'Urgent'] && $attrs($x, '//fieldset[2]//input[@checked]', 'value') === ['normal'],
        'how it came in and how urgent it is are words to pick from: Phone and Normal to start');
    form_check($x->query('//input[@type="checkbox"][@name="start_timer"][@value="1"][@checked]')->length === 1, 'Start my timer is ticked');
    form_check($texts($x, '//div[contains(@class,"call-actions")]/button') === ['Create ticket', 'Create and resolve']
        && $x->query('//button[@id="call-create"][@type="submit"][not(@name)]')->length === 1
        && $x->query('//button[@id="call-resolve"][@type="submit"][@name="after"][@value="resolved"]')->length === 1
        && $x->query('//div[contains(@class,"call-actions")]/a[@href="/"]')->length === 1 && $texts($x, '//div[contains(@class,"call-actions")]/a') === ['Cancel'],
        'Create ticket, Create and resolve, and Cancel');
    form_check(!str_contains($html, '·') && !str_contains($html, 'File ticket') && !str_contains($html, 'service-goal version'), 'no chain of facts beside the button');
    form_check($x->query('//textarea[@name="said"]')->length === 1 && $x->query('//textarea[@name="told"]')->length === 1
        && $texts($x, '//*[@id="f-told-help"]') === ['Counts as the first reply. It is saved on the ticket and is not emailed.'], 'the two things said each have a box, and the reply says it is not emailed');

    // What was typed comes back after a refusal, as text.
    $typed = array_replace($sent, ['contact_id' => 0, 'contact_name' => 'Lee "Okafor"', 'contact_email' => 'lee@example.test', 'subject' => 'A <b>subject</b>', 'said' => '</textarea><script>x</script>',
        'start_timer' => false]);
    $x = $dom(ticket_new_form($clients, $contacts, $typed, $csrf, '/ticket_new.php?client=12', '/client.php?id=12'));
    form_check($attrs($x, '//select[@id="f-client"]/option[@selected]', 'value') === ['12'] && $attrs($x, '//input[@name="subject"]', 'value') === ['A <b>subject</b>']
        && $texts($x, '//textarea[@name="said"]') === ['</textarea><script>x</script>'] && $texts($x, '//textarea[@name="told"]') === ['Restart it.']
        && $x->query('//script')->length === 0, 'the client, the subject and both messages are kept, and stay text');
    form_check($x->query('//details[@id="contact-new"][@open]')->length === 1 && $attrs($x, '//input[@name="contact_name"]', 'value') === ['Lee "Okafor"']
        && $attrs($x, '//input[@name="contact_email"]', 'value') === ['lee@example.test'], 'a new contact that was typed stays open with its values');
    form_check($attrs($x, '//fieldset[1]//input[@checked]', 'value') === ['email'] && $attrs($x, '//fieldset[2]//input[@checked]', 'value') === ['urgent']
        && $x->query('//input[@name="start_timer"][@checked]')->length === 0, 'so do the choices and the unticked timer');
    form_check($x->query('//form[@action="/ticket_new.php?client=12"]')->length === 1 && $x->query('//div[contains(@class,"call-actions")]/a[@href="/client.php?id=12"]')->length === 1,
        'opened from a client, the form comes back to itself and Cancel goes back to that client');
    $x = $dom(ticket_new_form($clients, $contacts, array_replace($blank, ['client_id' => 12, 'contact_id' => 32]), $csrf, '/ticket_new.php', '/'));
    form_check($attrs($x, '//select[@id="f-contact"]/option[@selected]', 'value') === ['32'], 'a chosen contact stays chosen');

    // ---- a call logged with no contact speaks in the client's name, and is not said to be "from" it ----
    $row = ['id' => 101, 'tenant_id' => 1, 'client_id' => 12, 'status' => 'open', 'priority' => 'normal', 'channel' => 'phone', 'subject' => 'Printer',
        'client_name' => 'Bayside Realty', 'created_at' => gmdate('Y-m-d H:i:s', time() - 1200), 'contact_name' => null];
    form_check(ticket_origin(array_replace($row, ['opener_name' => 'Bayside Realty'])) === 'Bayside Realty, by phone'
        && ticket_origin(array_replace($row, ['opener_name' => 'bayside realty '])) === 'Bayside Realty, by phone', 'a queue row does not say "from" the client itself');
    form_check(ticket_origin(array_replace($row, ['opener_name' => 'Dana Whitfield'])) === 'Bayside Realty, by phone from Dana Whitfield'
        && ticket_origin(array_replace($row, ['contact_name' => 'Dana Whitfield', 'opener_name' => 'Bayside Realty'])) === 'Bayside Realty, by phone from Dana Whitfield',
        'and still names a person');
    $plain = static fn(string $html): string => trim(html_entity_decode(strip_tags($html)));
    form_check($plain(ticket_subtitle($row, 'Bayside Realty')) === '#101 for Bayside Realty, opened 20 minutes ago by phone'
        && $plain(ticket_subtitle($row, 'Dana Whitfield')) === '#101 for Bayside Realty, opened 20 minutes ago by phone from Dana Whitfield', 'nor does the sentence on the ticket');

    if (in_array('--render', $argv, true)) {
        echo json_encode([
            'form' => ticket_new_form($clients, $contacts, $blank, $csrf, '/ticket_new.php', '/'),
            'form_client' => ticket_new_form($clients, $contacts, ticket_new_blank(12), $csrf, '/ticket_new.php?client=12', '/client.php?id=12'),
            'form_typed' => ticket_new_form($clients, $contacts, array_replace($typed, ['subject' => '']), $csrf, '/ticket_new.php', '/'),
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    } else {
        echo "Ticket new form: {$checks} passed.\n";
    }
} finally {
    $actual = realpath($temp);
    $parent = realpath(sys_get_temp_dir());
    if (!$actual || !$parent || !str_starts_with($actual, $parent . DIRECTORY_SEPARATOR) || !str_starts_with(basename($actual), 'safeharbor-ticket-new-')) {
        throw new RuntimeException('Fixture cleanup scope changed');
    }
    foreach (['render.php', 'auth.php', 'westy.php'] as $file) unlink($actual . '/' . $file);
    rmdir($actual);
}
