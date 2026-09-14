# Automatic completion for covered service

Deployed September 14, with the exact internal pilot activated. Gates still
default off for other scopes. See [live evidence](WESTY_INTERNAL_PILOT_RELEASE_2026_09_14.md).

Frankie wants Westy to finish routine service without a manual billing click.
When a verified, still-resolved workflow has no human time entries, this path
can record the work against an approved customer plan. It does not invent labor
minutes or create an extra invoice. Larger or billable work retains its existing
approved-time contract and requires the separately agreed financial authority.

## Protocol

The existing fixed Coastmark billing-handoff URL, dedicated identity, HMAC over
timestamp plus exact bytes, transport bounds and durable outbox are reused.
Version 1 remains approved-time invoice review. Version 2 is
`safeharbor.ticket.service_completed`. It replaces `time_event_keys` with
`coverage_key` (UUIDv4), `coverage_revision` (positive integer), and
`service_code` (`routine_support`). All tenant/customer/ticket/workflow/closure
and evidence fields remain present. There are no prices, recipients or commands.

Configure `westy_billing_handoff.included_service.enabled` plus an exact
`policies[tenant_slug][customer_uuid]` record containing `enabled`,
`coverage_key`, `coverage_revision`, and `service_code`. This reference does not
grant financial authority. Coastmark independently validates its protected
policy, current owner permission, active customer, and active covering agreement.

Only an exact confirmed receipt is accepted: HTTP 201 created or 200 ignored,
positive completion ID, state `included`, integer `additional_amount_cents=0`,
explicit null invoice ID, and every source field exactly matching the frozen
request with unchanged types. Ambiguous replies stay uncertain and reuse the
same event/body. Changed source facts are held. Accepted receipts are terminal.

The ticket card says the work is covered only after the receipt is accepted.
Recovery and covered-service accounting are separate facts. Human takeover,
reopening, a changed customer or human time prevents a new included-service
claim. A historical receipt does not authorize subsequent work.

## Verification and deployment

Synthetic producer request SHA-256:
`19ad95dfa932c61468692b1ea528caf45481228089f8ead70721fa28be59daaf`.
Actual Coastmark HTTP receiver receipt SHA-256:
`4b4cebeb7f1321f02c11294bb692a8305400f6a8f05508b52db67d51141fb941`.
The consumer test validates that receipt against the actual producer facts.
The fixtures contain synthetic IDs only. SQLite billing/ownership tests,
MySQL migration/runtime guards and desktop/phone browser contracts were checked
on an isolated Coastline test copy. No production message was sent.

This change needs no Safeharbor migration. Deploy the paired Coastmark migration
and receiver before enabling this producer. Keep the existing workflow/billing
gates, exact pilot bindings, dedicated service secrets and bounded worker
schedules. A deployment is not proof of a live incident completing.

Still separate work: customer-facing delivery acknowledgement, durable shared
repair scheduling, database-specific runbooks, richer outcome evaluation and
Logbook lesson publication. Current workflow progress is recorded on the ticket;
this change does not assert a customer received an email or close browser tabs.
