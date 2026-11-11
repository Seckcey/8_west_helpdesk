<?php
/**
 * New ticket, built for logging a call: find the client by typing, say who asked (or add them),
 * write what they said and what you told them, create. The exact effective service-goal target is
 * captured once when the ticket opens.
 *
 * The client check, the new contact, the service-goal snapshot, the ticket and its first messages
 * are one transaction. "Create and resolve" then resolves the new ticket through the same rule as
 * the Resolve button (lib/ticket_changes.php), so a survey is asked for exactly as it would be there.
 *
 * ?client=12 preselects a client (the client page's "New ticket for this client").
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/ticket_clients.php';
require_once __DIR__ . '/../lib/ticket_changes.php';
require_once __DIR__ . '/../lib/contacts.php';
require_once __DIR__ . '/../lib/ticket_new_form.php';
enforce_https();
$user = require_login();

$error = '';
$fromClient = is_string($_GET['client'] ?? null) && ctype_digit($_GET['client']) && strlen($_GET['client']) <= 9 ? (int)$_GET['client'] : 0;
$values = ticket_new_blank();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $values = ticket_new_input($_POST);
    $error = ticket_new_problem($values);

    if ($error === '') {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // First in the transaction: the client must be this tenant's and open to new tickets.
            $client = ticket_new_client_for_create($pdo, tenant_id(), $values['client_id']);
            $clientId = (int)$client['id'];
            $contactId = null;
            $contactName = '';
            if ($values['contact_name'] !== '') {
                // A new contact, added the same way the client page adds one.
                $contactId = contact_add($pdo, $clientId, $values['contact_name'], $values['contact_email']);
                $contactName = $values['contact_name'];
            } elseif ($values['contact_id'] > 0) {
                // A contact only counts when it belongs to this client.
                $kq = $pdo->prepare('SELECT name FROM contacts WHERE id = ? AND client_id = ? FOR SHARE');
                $kq->execute([$values['contact_id'], $clientId]);
                $known = $kq->fetchColumn();
                if (is_string($known)) {
                    $contactId = $values['contact_id'];
                    $contactName = trim($known);
                }
            }
            $goal = service_goal_snapshot_for_new_ticket(
                $pdo,
                tenant_id(),
                $clientId,
                $values['priority'],
            );
            $pdo->prepare(
                'INSERT INTO tickets
                    (tenant_id, client_id, contact_id, subject, priority, assignee_id, channel,
                     sla_due_at, service_goal_target_id, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                tenant_id(),
                $clientId,
                $contactId,
                $values['subject'],
                $values['priority'],
                (int)$user['id'],
                $values['channel'],
                $goal['due_at'],
                $goal['target_id'],
                $goal['opened_at'],
                $goal['opened_at'],
            ]);
            $tid = (int)$pdo->lastInsertId();
            $message = $pdo->prepare('INSERT INTO messages (ticket_id, author_name, kind, body) VALUES (?,?,?,?)');
            if ($values['said'] !== '') {
                // The customer's words open the conversation, in the name of whoever asked; with no
                // contact, in the client's name.
                $message->execute([$tid, $contactName !== '' ? $contactName : (string)$client['name'], 'client', $values['said']]);
            }
            if ($values['told'] !== '') {
                $message->execute([$tid, $user['full_name'], 'tech', $values['told']]);
            }
            $pdo->commit();
        } catch (TicketClientUnavailableException $e) {
            $pdo->rollBack();
            $error = 'This client is unavailable for new tickets. Pick another client.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'The ticket could not be created. Nothing was saved. Try again.';
        }

        if ($error === '') {
            // The ticket exists. What follows is said on its page, once.
            $flash = ['ticket_id' => $tid, 'toast' => '', 'undo_status' => ''];
            if ($values['resolve']) {
                try {
                    $created = db()->prepare('SELECT * FROM tickets WHERE id = ? AND tenant_id = ?');
                    $created->execute([$tid, tenant_id()]);
                    // A person chose Resolve: the same rule as the Resolve button decides about a survey.
                    $change = ticket_change_status($created->fetch(), 'resolved', true);
                    $flash['toast'] = (string)preg_replace('/^Resolved\./', 'Created and resolved.', $change['toast']);
                    $flash['undo_status'] = 'open';
                } catch (Throwable $e) {
                    error_log('Safeharbor create-and-resolve left the ticket open: ' . $e::class);
                    $flash['toast'] = 'The ticket was created, but it could not be resolved. Resolve it here.';
                }
            } elseif ($values['start_timer']) {
                $flash['start_timer'] = true;
            }
            if ($flash['toast'] !== '' || !empty($flash['start_timer'])) $_SESSION['ticket_flash'] = $flash;
            header('Location: /ticket.php?id=' . $tid);
            exit;
        }
    }
}

$clients = ticket_new_clients(db(), tenant_id());
$contacts = ticket_new_contacts(db(), tenant_id(), $clients);
$clientIds = array_map(static fn(array $client): int => (int)$client['id'], $clients);
// A client named in the address is preselected only when it is one this form offers.
$fromClient = in_array($fromClient, $clientIds, true) ? $fromClient : 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $fromClient > 0) $values = ticket_new_blank($fromClient);
$back = $fromClient > 0 ? '/client.php?id=' . $fromClient : '/';

page_top($user, 'New ticket', 'queue');
?>
<div class="page page-narrow page-new-ticket">
  <div class="page-head">
    <div>
      <h1 class="page-title">New ticket</h1>
      <p class="page-sub">Log a call or a request. Start typing the client's name.</p>
    </div>
  </div>
  <?php if ($error): ?><div class="form-error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <?php if (!$clients): ?>
    <div class="card empty"><p>No client is available for new tickets yet.</p><a class="btn-chip" href="/client_new.php">Add a client</a></div>
  <?php else: ?>
  <div class="card form-card">
    <?= ticket_new_form($clients, $contacts, $values, csrf_field(), '/ticket_new.php' . ($fromClient > 0 ? '?client=' . $fromClient : ''), $back) ?>
  </div>
  <?php endif; ?>
</div>
<?php
page_bottom(palette_data());
