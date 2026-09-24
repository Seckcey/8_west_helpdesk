# Suite workspace and support intake

**Production activation, September 24:** eligible future-MSP enrollment and managed-client/integration reconciliation are **on**. See the [stopping point](IT_365_STOPPING_POINT_2026_09_24.md) for effective scope and remaining inputs; conservative installation defaults below are not the live state.

The local-only `cron/suite_workspace_provision.php --apply` accepts the bounded
`8west.workspace.v1` manifest on stdin. It creates/reconciles one workspace and
owner by suite slug and immutable subject. Conflicting identities, inactive
accounts and demoted owners are refused. It creates no ticket, invoice or email.
It reports pending until its support transport is explicitly verified.

`support_addresses` is default off. Configure a separate shared mailbox using
the existing general mail application's Graph authority. The old general mail,
business report and Westy conversation senders remain independently configured.
Each admitted workspace receives `<mailbox-local>+w365-<local-tenant-id>@domain`.
Verify plus-address preservation through the actual provider before setting
`transport_verified=true`. Existing workspaces require exact `tenant_ids`; a
UTC `new_tenants_after` cutoff is a separate new-workspace rollout. During the
internal pilot, `client_ids_by_tenant` restricts both intake and replies to the
selected client. Unknown senders are held; a domain match is insufficient.

Run `cron/support_mail_poll.php` every minute as www-data after configuration.
Its state directory must be outside the deployed app, e.g.
`/srv/8west/apps/safeharbor/shared/support-mail`, www-data:www-data 0700.
The worker takes its own lock, reads at most four Graph pages, and keeps opaque
cursor data in private 0600 files. Continuations must remain on Graph and the
same mailbox Inbox. No email is marked read, moved or deleted; Mail.Read is
sufficient. Message dedupe and ticket intake commit in one database transaction.
Only a completed page advances the cursor, making crash recovery idempotent.

To/Cc/Bcc aliases must identify exactly one admitted workspace. Untagged,
ambiguous or unknown routes are held. Thread replies also require a known
contact on the same ticket's client. Auto-mail is dropped. Held receipts retain
only a hashed message key and bounded reason, while original mail stays in the
mailbox. An operator must review held mail; do not automatically reassign it.
After a provider-expired cursor, stop the worker and review a controlled
rebaseline; existing processed-message keys prevent duplicate ticket creation.
To reconsider held mail after correcting a route, remove only its reviewed
hashed processed-message key and rebaseline. Never clear all dedupe history.

Replies to eligible tickets use the separate support sender and workspace
Reply-To alias. Other mail continues through its existing transport. The
profile page shows the address only after transport verification.

Validation covers recipient ambiguity, Bcc delivery, unknown aliases,
cross-workspace/client/thread denials, injection rejection, actual MySQL
transaction rollback and dedupe, plus the existing business-report suite.
Production acceptance additionally requires provider delivery and a real
round trip before reporting operational mail intake.

Release preserves the report scheduler's protected source/config binding:
disable through the current control bundle, deploy exact green main, then
rebind and restore its unchanged schedule scope. Rollback disables only the
new support worker/config; retain cursor, held and dedupe history.
