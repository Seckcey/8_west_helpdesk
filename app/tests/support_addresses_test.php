<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/support_addresses.php';
function recipient(string $address): array { return ['emailAddress' => ['address' => $address]]; }
function support_check(bool $ok): void { if (!$ok) throw new RuntimeException('Support routing assertion failed'); }
$mailbox = 'support@example.test';
support_check(support_address(42, $mailbox) === 'support+w365-42@example.test');
support_check(support_address(0, $mailbox) === null);
support_check(support_recipient_tenant([recipient($mailbox)], $mailbox, [42]) === null);
support_check(support_recipient_tenant([recipient('support+w365-42@example.test')], $mailbox, [42]) === 42);
support_check(support_recipient_tenant([recipient('support+w365-43@example.test')], $mailbox, [42]) === null);
support_check(support_recipient_tenant([recipient('support+w365-042@example.test')], $mailbox, [42]) === null);
support_check(support_recipient_tenant([recipient('support+w365-42@example.test'), recipient('support+w365-43@example.test')], $mailbox, [42,43]) === null);
support_check(support_recipient_tenant([recipient('support+w365-42@example.test'), recipient($mailbox)], $mailbox, [42]) === null);
support_check(support_recipient_tenant([recipient('support+w365-42@other.test')], $mailbox, [42]) === null);
echo "support address generation and tenant-routing denial checks passed\n";
