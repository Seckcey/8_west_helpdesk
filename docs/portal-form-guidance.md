# Portal request help and client selection

The support-request form keeps its three fields and one **Send request** action.
The summary example and message guidance remain visible while typing, rather than
disappearing as placeholders. Each field has an explicit label and associated
description. The existing priority guide keeps its original meanings. The
introduction points to Support Requests for following replies, without promising
an email or response time.

The client picker says **Choose a client** and **Continue with this client**, because
the selection applies to the portal as a whole. Existing client names, roles and
available choices are retained.

This is presentation only. Request and client-selection controllers are unchanged:
the same POST fields, limits, validation, CSRF and nonce handling, reference and
generation values, selection checks, session behavior and redirects still apply.
Validation errors and the uncertain-save message retain the original wording and
entered values. No uploads, computer associations, email, retries or additional
permissions are introduced.

## Checks and previews

```text
php app/tests/portal_guidance_display_test.php
php app/tests/portal_ticket_workflow_test.php
php app/tests/portal_customer_entry_test.php
php app/tests/portal_auth_test.php
node tools/shots/portal-guidance.test.mjs
node tools/shots/portal-contract.test.mjs
node tools/shots/preview/build-portal-guidance.mjs
```

The render fixture uses only synthetic data. It covers blank, filled, invalid and
uncertain-save request forms, plus zero, one and multiple client choices. Browser
checks verify persistent descriptions while typing, desktop/phone layouts in both
themes, and exact POST payloads with JavaScript disabled and keyboard submission.
Set `PORTAL_GUIDANCE_SHOTS` for 28 captures; CI uploads `portal-guidance-gallery`.

The generated `portal-request-form.html` and `portal-client-picker.html` previews
use the real renderer and stylesheets. Links and submissions are disabled.
