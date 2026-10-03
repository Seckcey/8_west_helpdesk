# Secure Plus in the customer portal

Status: default-off source capability. Production activation and real customer
acceptance require a separate verified release. Mobile enrollment, computer
checks and support navigation retain their behavior.

When portal_devices.security_orders_enabled is true, Windows computers show
**Secure Plus** in **Your devices**. The page retrieves the selected computer's
customer receipts through Milepost. A business owner or admin can:

1. Review **$15 USD/month for one Windows computer**, the exact versioned terms
   and approval deadline.
2. Tick the commercial consent and accept that exact order.
3. Continue account/package preparation one provider stage per submission.
4. Tick a separate confirmation to install on that computer now.
5. Check installation, device protection and MDR enrollment separately.

The portal does not issue an invoice or collect payment. Review does not place
an order. Competitor security software is not removed automatically. Support
handles missing billing mappings, expired authority and uncertain installations.
Refresh page reads stored status; Check setup status requests fresh provider
evidence without starting another install.

Only owner/admin roles get order/install/provider-refresh controls. Other
authorized customer roles can read status. Forms require CSRF protection;
acceptance and installation additionally require explicit consent and the exact
approval fingerprint. Successful POSTs redirect to GET. Browser inputs cannot
override customer, actor, price, terms, package or command.

The default-off flag is in app/config/portal_devices.sample.php. It depends on
the existing portal/device channel plus the separate Milepost, Gateway and
Coastmark gates. First-release commercial authorization remains explicitly
restricted to verified **8west** customers, independently of generic MSP access.
Missing configuration refuses the request.

The service client uses the established HMAC/nonce channel and rechecks customer
bindings after responses. The page rechecks signed-in identity before rendering
or redirecting. Only the closed receipt/offer/status projection reaches the
renderer; private URLs, native output, provider identifiers and credentials
cannot be rendered.

Installer completion displays Checking provider enrollment. Protection and MDR
remain Not yet verified until independently observed. Stale observations return
to unknown. Ambiguous/timed-out installations show support guidance without an
option to run another installer.

Validation uses synthetic fixtures: contract/consent checks, actual customer
binding MySQL tests and Playwright at desktop/mobile widths. Browser coverage
uses the real PHP renderer for both consent steps, role restrictions,
navigation, responsive layout and separate provider statuses. It does not submit
live orders or install software.

Release through the owner's sequenced four-service migration/release plan.
Keep real customer login acceptance separate from source tests. Do not activate
customer accounts or send an announcement as a smoke test.
