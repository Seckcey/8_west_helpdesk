# Independent MSP customer portal workflow admission

This change connects the existing immutable managed-provider/customer records to
manual Milepost-to-Safeharbor Westy workflows. It adds no schema migration or new
customer identity system. Deployment and signed-in customer/device acceptance are
separate from source and synthetic validation.

With `westy_workflow.managed_providers_enabled = true`, an independent provider
must have current registry/owner/lease authority, its active `milepost-workflow`
service identity and an operational customer binding in the same tenant. These
checks run inside the receive transaction before a receipt replay or ticket action.
The existing customer status/lifecycle policy remains authoritative. Swapped
customer/provider IDs, revoked owners, expired leases and inactive bindings refuse
access. Internal customers retain the existing explicit workflow scope.

The trusted local `cron/managed_provider_register.php` adds the workflow service
identity for an admitted provider when the flag is enabled. Reconciliation is
idempotent but never restores a revoked service identity. Ticket takeover,
technician ownership, independent recovery evidence, billing review and customer
repair approval rules are unchanged. Customer portal mobile/diagnostic flags stay
at their current authorized values. Commercial Secure Plus onboarding retains its
separate scope.

The project coordinator sequences this application with the ID and Milepost
companion changes. Refresh the deployed heads, current jobs, release lock and
verified rollback; enable the flag only when the existing signed workflow service
is healthy. Reconcile provider registration and verify an eligible provider can
claim/replay its own workflow while a different provider cannot. Do not change
the internal exact-customer activation packet or clear human portal/report holds.

Rollback disables the new flag and restores the reviewed application/config
release without removing service identities, bindings, tickets or receipts.
The shared Coastline two-MSP harness tests current-provider admission and revocation
alongside the existing portal/device/workflow regression suites. Real mail delivery,
signed-in customer entry and physical device recovery require their own evidence.
