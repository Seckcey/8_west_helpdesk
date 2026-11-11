<?php
/**
 * Adding a contact to a client: one insert, used by the client page and by "Add a new contact" on
 * the New ticket form, so the two cannot drift apart.
 */
declare(strict_types=1);

/**
 * Add a contact to a client and return its id, or null when there is no name (nothing is added).
 * The email address is stored in lower case; it may be empty.
 *
 * The caller has already established that the client belongs to the signed-in tenant: the client
 * page loads it WHERE id = ? AND tenant_id = ?, and the New ticket form calls
 * ticket_new_client_for_create() in the same transaction.
 */
function contact_add(PDO $pdo, int $clientId, string $name, string $email): ?int
{
    $name = trim($name);
    $email = mb_strtolower(trim($email));
    if ($name === '' || $clientId < 1) return null;
    $pdo->prepare('INSERT INTO contacts (client_id, name, email) VALUES (?,?,?)')->execute([$clientId, $name, $email]);
    return (int)$pdo->lastInsertId();
}
