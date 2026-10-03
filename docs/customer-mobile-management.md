# Customer phones, tablets and Macs

`/portal/mobile.php` adds customer-scoped Android and Microsoft Intune Apple
status, ownership-specific setup guides, privacy/offboarding information and
safe support guidance. It is separate from Windows installation and computer
diagnostics. The provider integration contract lives in Milepost's
[customer mobile management documentation](https://github.com/Seckcey/8westit_webapp/blob/main/docs/CUSTOMER_MOBILE_MANAGEMENT.md).

This is an implementation slice, not a claim of deployed or physically accepted
mobile management. Existing customer bindings and current authorization remain
required. The page is default-off through `portal_mobile.enabled`; it also needs
the existing verified `portal_devices` service secret/configuration and the
matching Milepost `customer_mobile` route. No new database migration is needed.
Copy no customer or provider credentials into source or browser code.

The service request uses a separate `safeharbor-customer-mobile-v1` HMAC context,
exact endpoint/path/body, timestamp and one-use nonce. Scope is derived from the
current authenticated customer binding, never request parameters. Safeharbor
rechecks the binding after each provider request and the page rechecks the current
identity before rendering. Milepost independently repeats identity and ownership
checks. Browser device references grant no access without that current session.

Only closed, bounded response objects reach the renderer. There are no provider
tokens, user emails, phone numbers, serials, app inventories, raw logs, command
payloads or arbitrary enrollment redirects. Unexpected fields are rejected.
Device labels are escaped and excluded from Westy's fixed status prompt.

The page offers:

- Reported Android/iPhone/iPad/Mac status and up to 50 devices per page. A provider
  failure remains visible without being translated into zero managed devices.
- Personal and company-owned enrollment guidance. Personal Apple enrollment uses
  Intune's account-driven User Enrollment. Company-device setup warns when it may
  require an erase; this page never erases a device.
- Setup-required or ready-for-device-approval states. The latter requires current
  customer-specific prerequisite evidence and read-only Intune access; it does not
  claim the phone is enrolled or that another customer's licensing covers it.
- Safe troubleshooting and a Westy explanation prompt. Chat is guidance, not
  authorization for a device action. No lock/restart/retire/wipe/script path exists
  in this mobile service.

Mac Intune management and the Milepost Mac agent remain separately reported.
Jamf is not a prerequisite. No Mac runtime, agent installation, policy, identity,
Windows job ledger, add-on order, purchase or provider configuration is changed.

Tests: `app/tests/portal_mobile_test.php` checks the closed projections and
escaped renderer; `app/tests/portal_mobile_mysql_test.php` uses a disposable
real schema to check immutable bindings, exact signed scope and mid-request
revocation. `.github/workflows/mobile.yml` runs both. Browser fixtures must be
identified as synthetic, with desktop and mobile evidence kept outside source.

The coordinator owns release sequencing. The existing Devices navigation link is
a separate additive handoff from its Windows owner; a direct page URL in this
branch does not mean the production portal links to it. Validate both signed-in
customer access and a named physical device after external setup. Roll back this
page by disabling only `portal_mobile.enabled`; keep existing customer support,
Windows controls and provider enrollments intact.
