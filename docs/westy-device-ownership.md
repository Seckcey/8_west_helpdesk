# Westy device facts and support ownership

The customer device projection accepts dated inventory RAM capacity and both the original and version-two fixed health result. Version two adds total and available RAM capacity. Westy uses the returned observation time when explaining recorded hardware. Fixed diagnostics and recorded facts are distinct from permission to change a computer.

`POST /api/svc/westy_device_ownership.php` is a read-only service endpoint for exact current repair ownership. It uses the existing protected `milepost-workflow` credential and active service identity with a separate HMAC preimage:

```text
safeharbor-westy-device-ownership-v1
POST
/api/svc/westy_device_ownership.php
<timestamp>
<exact request body>
```

The timestamp must be within five minutes. The strict version-one request contains `schema_version`, a UUID `request_key`, `tenant_slug` and one to 50 `workflows`, within 16 KiB. Each item has exactly `workflow_key`, `customer_id`, `alert_key`, positive integer `ticket_id` and positive integer `expected_version`. These identifiers come from Milepost's stored workflow records, never customer or model input. Duplicate fields, workflows, missing records, mismatched scope and a server version older than expected are refused.

Inside one read-only transaction the endpoint locks tenant, active service identity, current customer binding, ticket and workflow. It requires the existing customer lifecycle boundary, including inactive history and an exact current restoration receipt where applicable. House tenants `8west` and `internal` no longer require historical pilot arrays. External providers still require explicitly enabled managed-provider support and current managed-provider/owner admission. The individual workflow receiver uses the same current admission rules and retains its existing signature, action, receipt, optimistic version and mutation protections.

The response echoes the request key and returns ordered exact workflow/customer/alert/ticket identifiers, current version, workflow state and ticket status under `contract: westy-device-ownership-v1`. It creates no receipt, message, billing event or ticket/workflow update. Transport failure or an incomplete response cannot authorize a repair.

Milepost interprets an unresolved `human_owned` or `working` case as an active repair hold. Historical closed-session `needs_human` records alone do not hold repairs. A technician deliberately resolving the ticket after finishing work ends the active case hold without rewriting its workflow history. The customer then reviews the exact proposed action again; an expired preview needs a new preview. Fixed health checks remain available unless actual unresolved execution, identity, maintenance or capability restrictions prevent them.

The desktop UI include and conversation event are integration seams for the separate desktop feature. They carry only the current validated conversation and exact latest completed operation key. A desktop resume reuses the original turn and existing SSE renderer, refuses concurrent streaming or a different/latest pending turn, and never reposts a user message or retries an uncertain resume. Authorization remains server-side and in the companion.

## Candidate validation

The October 4 isolated Coastline run passed 104 workflow/ownership MySQL assertions, 80 device assertions, 108 workspace assertions and the complete 10-test portal browser suite. Browser coverage includes desktop/mobile repair review, recorded RAM capacity, exact desktop continuation, rejection of a different conversation/operation, and no automatic replay after an interrupted continuation. The desktop helper/assets and tenant-AI backend are integration dependencies; these results do not establish a live customer repair or Windows companion acceptance.
