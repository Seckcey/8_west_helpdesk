# Settings UX — Safeharbor candidate

`SETTINGS-UX-CORE-20261021` is presentation work authorized October 21, 2026.
This document describes the source candidate; it does not claim production or
signed-in customer acceptance. The Safeharbor Sergeant owns review, integration
and the normal technical release. Frankie retains genuine customer acceptance.

## Behavior

- Portal Account uses full-width links/buttons with at least 44px targets,
  destination hints, the trusted current identity, effective role and selected
  client name. Switch client retains the existing client chooser and its checks.
  My settings, Client Admin and Plan and billing identify 8 West ID. Computer
  access, guide and support retain their existing destinations. Sign out is still
  the same CSRF-protected POST. The sidebar scrolls when its contents exceed the
  available height, including the Westy workspace on phones.
- Staff Team uses labeled Technician/Administrator/Owner choices and named,
  keyboard-focusable color radios. Native checked state drives the visible
  selection. Access buttons state Enable/Disable; disabled rows remain readable.
  Role values, account handlers and all current access checks are unchanged.
- Profile and Team use clear destination buttons, Cancel navigation and
  accessible success/error feedback. Local recovery forms remain local; linked
  identities still manage profile/security in ID. Password requirements are
  unchanged. No new confirmation, unsaved-change blocker or execution guard.
- The staff menu uses the canonical Personal/Workspaces grouping and destination
  hint classes. Only an exact local destination receives a current-page marker.
  Settings links use the same tab; existing app-drawer behavior is unchanged.

No authentication, requester attribution, tenant/resource privacy, service
contract, schema, provider, billing or device behavior changes. Existing portal
enablement and AI/workflow gates remain unchanged. Client choices retain their
existing POST values and CSRF; the chooser does not invent a current marker from
an identity that lacks a freshly supplied selected-client context.

## Validation and delivery

Use existing `portal_settings_test.php` and `suite_chrome_test.php`, then
`tools/shots/portal-settings.test.mjs` and `tools/shots/suite-settings.test.mjs`.
The latter also invokes CLI-only `staff_settings_fixture.php`: actual PHP
Team/profile handlers and templates run against synthetic identities and an
in-memory adapter. It cannot access a real configuration, database or account.
This checks UI feedback and submitted fields, not database persistence or SSO.

The browser matrix covers 1440px desktop and 390px mobile, light/dark, portal
member/viewer/admin and Westy workspace, staff admin/technician menus, Team and
local/suite-linked profile controls. It checks target sizes, overflow, keyboard
selection/focus, immediate access toggle, name-save feedback, duplicate-account
and password errors, Cancel, and console/page errors. All browser requests are
intercepted fixtures; no real ID navigation or account operation is performed.

Containers run only in this worker's isolated Coastline task directory. Required
GitHub PHP/browser CI remains the integration gate. Package identity, final
validation and cleanup receipts will be recorded before handoff.
