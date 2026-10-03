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
- Personal Macs use Company Portal/user-approved enrollment. Company Macs receive
  Automated Device Enrollment guidance, with support choosing the correct
  new/existing-device and authentication steps. A personal-Mac prerequisite
  review cannot mark the corporate method ready; both need their own reviewed
  method and current provider access. The corporate guide discloses possible
  erasure without initiating or universally requiring one.
- Setup-required or ready-for-device-approval states. The latter requires current
  customer-specific prerequisite evidence and read-only Intune access; it does not
  claim the phone is enrolled or that another customer's licensing covers it.
  The backend separately requires current operator evidence of the exact
  dedicated application's permissions. Microsoft access tokens are opaque;
  neither their contents nor a successful Graph read prove the application has
  no other grants. No provider token or permission-review receipt reaches this page.
- Safe troubleshooting and a Westy explanation prompt. Chat is guidance, not
  authorization for a device action. No lock/restart/retire/wipe/script path exists
  in this mobile service.

Mac Intune management and the Milepost Mac agent remain separately reported.
Jamf is not a prerequisite. No Mac runtime, agent installation, policy, identity,
Windows job ledger, add-on order, purchase or provider configuration is changed.

Tests: `app/tests/portal_mobile_test.php` checks the closed projections and
escaped renderer; `app/tests/portal_mobile_mysql_test.php` uses a disposable
real schema to check immutable bindings, exact signed scope and mid-request
revocation. `.github/workflows/mobile.yml` runs both. The shared browser contract
also checks discovery, return navigation, the current Devices item and preserved
computer-check links for admins and viewers at desktop and phone widths, with
mobile enabled and disabled. Browser fixtures are synthetic, with screenshots
and evidence kept outside source.

The coordinator owns release sequencing. When `portal_mobile.enabled` is exactly
`true`, Your devices links to Phones, tablets & Macs. That page keeps Your devices
selected in the sidebar and links back to computer installation and support.
When the gate is disabled, the mobile link is absent and the route remains
unavailable. Existing computer-check links and role permissions remain intact.
These source changes do not activate the production portal. Validate both
signed-in customer access and a named physical device after external setup.
The mobile gate exposes guidance/status broadly to authorized customers, while
the existing signed identities and provider mappings isolate company data.
Broad page availability is distinct from a named customer's enrollment pilot.
Roll back this page by disabling only `portal_mobile.enabled`; keep existing
customer support, Windows controls and provider enrollments intact.
