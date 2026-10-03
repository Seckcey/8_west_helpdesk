# Customer device enrollment

This release adds **Your devices** to the customer portal. It is not a receipt
that production configuration or real customer installation has been accepted.

Customers see their business's computer names, platform and connection status.
An installed agent, a later heartbeat and fresh inventory are separate facts.
Owners and admins can create a Windows setup link after confirming they are
authorized to manage the computer. Each link lasts four hours, enrolls one
computer and can be revoked. Staff and viewers can view device status. Adding a
computer creates no charge and does not order antivirus.

Safeharbor derives provider, customer UUID, identity tenant, immutable subject,
session version and role from the authenticated portal context. Browser fields
cannot supply authority. The device route rechecks its local customer binding
after the service call and its complete authenticated identity before rendering.
Milepost independently validates a dedicated HMAC, replay nonce, signed current
identity authorization and immutable customer mapping. No technician identity is
created or borrowed. Device responses contain no raw inventory, job payloads,
enrollment secrets or remote-access credentials.

The service client accepts only the fixed HTTPS Milepost customer endpoint and
the fixed signed installer origin. Transport is bounded, does not redirect, and
does not log response bodies. Responses must match a closed field projection.
Portal POSTs require CSRF and enforce owner/admin authority for link actions.

## Release setup

Merge `app/config/portal_devices.sample.php` into the protected runtime
configuration. Provision one new random 64-hex-character service secret directly
on the hosts and use it for Milepost's `customer_portal.secret` and Safeharbor's
`portal_devices.secret`; never reuse the staff or Central channel key. No secret
belongs in the repository, command output, PR or customer response.

The Milepost `customer_portal_enrollments` protected migration must be verified
before enabling this channel. Its immutable Windows delivery and Authenticode
requirements must already pass. Set both feature blocks enabled only after
configuration, signed authorization freshness and stable business bindings are
verified. Existing customer role/portal product admission still applies.

Disabling `portal_devices.enabled` makes this service unavailable while existing
support requests continue working. Milepost's channel gate also denies customer
installer tokens. Existing technician links keep their established behavior.

## Verification and limits

`app/tests/portal_devices_mysql_test.php` exercises real customer lifecycle
bindings, scope substitution, roles, HMAC body binding, response projection,
unsafe download origins, revocation during a service request and escaping.
The test requires the same explicitly disposable MySQL environment as the
existing portal Westy tests.

`tools/shots/portal-contract.test.mjs` renders the real PHP UI with synthetic
service results. It checks consent, setup and revoked states, viewer controls,
empty/unavailable views, desktop/mobile overflow, assets and console health.
Those fixtures do not prove a production installation. Live acceptance needs a
real customer sign-in and an authorized Windows computer's later heartbeat and
inventory.

The Westy shortcut opens the existing support conversation. The separate
[Computer checks feature](customer-device-checks.md) adds default-off diagnostic
consent and exact print-service repair review. The enrollment page itself does
not authorize a command. Commercial offers and Bitdefender ordering remain
separate work; adding a computer does not purchase them.
