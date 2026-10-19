<?php
/**
 * Mail-loop protection: Safeharbor never sends mail to a machine sender.
 *
 * A confirmation sent to mailer-daemon bounces, the bounce opens a ticket, that
 * ticket's confirmation bounces again, forever. In August 2026 that loop wrote
 * 335,000 junk messages before the guard existed.
 *
 * CLI only, against a scratch database (never the live one). Needs
 * app/config/config.php (CI copies config.sample.php) and:
 *   CREATE DATABASE safeharbor_test;
 *
 * Run:  php app/tests/mail_loop_guard_test.php
 * Exit: 0 = all green, 1 = failures.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!is_file(__DIR__ . '/../config/config.php')) {
    fwrite(STDERR, "mail_loop_guard_test needs app/config/config.php (copy config.sample.php).\n");
    exit(1);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/intake.php';
require_once __DIR__ . '/sql_file.php';

// Point the shared connection at the scratch database before the first db() call.
$CONFIG['db']['name'] = 'safeharbor_test';

$checks = 0;
$failures = 0;

function check(string $name, bool $ok): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "ok {$checks} - {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL {$checks} - {$name}\n";
}

function fresh_schema(): void
{
    $pdo = db();
    $tables = $pdo->query(
        'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
    )->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['schema.sql', 'migrations/001_mail_queue.sql'] as $file) {
        test_execute_sql_file($pdo, __DIR__ . '/../db/' . $file);
    }
    $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Test MSP', 'test')");
    $pdo->exec("INSERT INTO clients (id, tenant_id, name, domain) VALUES (1, 1, 'Client', 'client.example')");
    $pdo->exec("INSERT INTO contacts (id, client_id, name, email) VALUES (1, 1, 'Ada', 'ada@client.example')");
}

function mail_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
}

function mail_to(string $address): int
{
    $query = db()->prepare('SELECT COUNT(*) FROM mail_queue WHERE LOWER(to_addr) = LOWER(?)');
    $query->execute([$address]);
    return (int) $query->fetchColumn();
}

function ticket_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
}

/* 1. Machine senders and bounce subjects are recognised. */
foreach ([
    'mailer-daemon@remote.example', 'MAILER-DAEMON@remote.example', 'postmaster@remote.example',
    'noreply@remote.example', 'no-reply@remote.example', 'donotreply@remote.example',
    'bounce@remote.example', 'bounces@remote.example', 'autoreply@remote.example',
] as $sender) {
    check("{$sender} counts as a machine sender", intake_is_auto_mail($sender, 'Hello'));
}
foreach ([
    'Undeliverable: [#12] Printer is jammed', 'Undelivered Mail Returned to Sender',
    'Delivery Status Notification (Failure)', 'Delivery has failed to these recipients',
    'Mail delivery failed: returning message to sender', 'Returned mail: see transcript',
    'failure notice', 'Automatic reply: Printer is jammed', 'Auto-Reply: on leave',
    'Autoreply: on leave', 'Out of Office: back Monday',
] as $subject) {
    check("'{$subject}' counts as automatic mail", intake_is_auto_mail('ada@client.example', $subject));
}
check('a person writing about a printer is not automatic mail',
    !intake_is_auto_mail('ada@client.example', 'Printer is jammed'));

/* 2. A real person gets one confirmation. This proves the checks below can see mail. */
fresh_schema();
$created = intake_message('ada@client.example', 'Ada', 'Printer is jammed', 'Paper everywhere.');
check('a real email opens a ticket', str_starts_with($created, 'created:#'));
$ticketId = (int) substr($created, strlen('created:#'));
check('the real sender gets exactly one confirmation', mail_to('ada@client.example') === 1);

/* 3. Bounces and auto-replies never open a ticket and never get mail back. */
$tickets = ticket_count();
$mail = mail_count();
foreach ([
    ['mailer-daemon@remote.example', 'Mail Delivery System', 'Undeliverable: Printer is jammed'],
    ['postmaster@remote.example', 'Postmaster', 'Delivery Status Notification (Failure)'],
    ['noreply@vendor.example', 'Vendor', 'Your invoice is ready'],
    ['ada@client.example', 'Ada', 'Automatic reply: Printer is jammed'],
] as [$from, $name, $subject]) {
    check("unscoped intake drops mail from {$from}", intake_message($from, $name, $subject, 'Automatic text.') === 'dropped:auto-mail');
    check("tenant intake drops mail from {$from}", intake_message($from, $name, $subject, 'Automatic text.', [], null, 1) === 'dropped:auto-mail');
}
check('no bounce or auto-reply opened a ticket', ticket_count() === $tickets);
check('no bounce or auto-reply queued any mail', mail_count() === $mail);

/* 4. A bounce of a ticket's own confirmation joins that ticket and mails nobody. */
$result = intake_message('mailer-daemon@remote.example', 'Mail Delivery System',
    "Undeliverable: [#{$ticketId}] Printer is jammed", 'The message could not be delivered.');
check('a bounce carrying the ticket number joins that ticket', $result === "appended:#{$ticketId}");
check('the bounce opened no new ticket', ticket_count() === $tickets);
check('the bounce queued no mail', mail_count() === $mail);

/* 5. A technician's reply is never mailed to a machine address. */
$ticket = ['id' => $ticketId, 'subject' => 'Printer is jammed'];
foreach (['mailer-daemon@remote.example', 'POSTMASTER@remote.example', 'no-reply@remote.example', 'bounces@remote.example'] as $address) {
    mail_notify_reply($ticket, $address, 'Tech', 'Try turning it off and on again.');
    check("a reply is not mailed to {$address}", mail_to($address) === 0);
}
mail_notify_reply($ticket, 'ada@client.example', 'Tech', 'Try turning it off and on again.');
check('a reply to the real contact is mailed', mail_to('ada@client.example') === 2);

/* 6. Nothing anywhere in the queue is addressed to a machine sender. */
$addresses = db()->query('SELECT to_addr FROM mail_queue')->fetchAll(PDO::FETCH_COLUMN);
check('the queue holds no mail to a machine sender',
    array_filter($addresses, static fn(string $to): bool => intake_is_auto_mail($to, '')) === []);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
