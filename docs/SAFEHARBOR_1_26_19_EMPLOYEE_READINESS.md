# Safeharbor 1.26.19 employee consumer historical source review

**Later deployment:** the compatible consumer was installed from
`1294c84edb7252b8f38cb91f9c843e3bfa55cabd`, with Safeharbor's original
restoration complete October 10 at 5:02:10 PM Pacific. The app tree is unchanged
from this review. See the separate [actual deployment and recovery
record](SAFEHARBOR_1_26_19_EMPLOYEE_DEPLOYMENT_2026_10_10.md); it does not establish
employee/provider/device/customer acceptance or completion of other owners' work.

The October 10 source-review handoff below is historical. Its original
`e6308be` candidate, recorded `4b1ff4fd` baseline, comparisons and synthetic
validation evidence are preserved. That review performed no live account,
invitation, device, provider, schema, configuration or production operation;
the later deployment belongs to its separately recorded executor.

## Exact source and runtime at the original review

The reviewed source candidate is main
`e6308be3cae19a7b3db1e6a85be3aa63772e4a61`, including employee
[PR #193](https://github.com/Seckcey/8_west_helpdesk/pull/193), merged as
`ac3706a13c571e04258860dc41dc82e271b670f5`, and CI cleanup
[PR #208](https://github.com/Seckcey/8_west_helpdesk/pull/208).
The candidate's Git `app/` tree object is
`2125b3df141ec36c169186c12f60f53fed3167ea`. This Git identity includes test
files; it is not a deployment artifact digest. This documentation change does
not change that application tree.

At that original review, the latest recorded runtime was the narrow
`4b1ff4fdf7efc4519f140de78f4d2cde6494c6bc` cut installed October 9 at
11:47 PM Pacific (October 10 UTC). Its recorded source-artifact digest is
`df992c14f516d3edef6731520e314dbb091782dd5c3dd40d6b914588f0072349`,
deployed digest is
`78c320b539002a9deb76e6e09b1bb694440a2c3ac18f4f8610c7a89cae4ad32b`,
and release-record digest is
`05adfab69d261e58ccf905fa869ee7c26a1930a33bae89789948213b3ad3b4ce`.
The original report schedule was recorded restored at 11:48 PM Pacific, with
configuration and original approval/delivery clocks preserved. These are
[historical deployment receipts](WESTY_BROWSER_RECOVERY_2026_10_09.md), not a
fresh production observation or employee activation proof. The retained
`report-rebind-4b1ff4fd` evidence remains under its original protected release
root; this review neither relocated nor invoked those controls.

## Complete content difference

Comparison of those exact Git revisions finds **five runtime paths**, all from
the previously merged employee consumer:

| Runtime path | Difference and dependency |
| --- | --- |
| `app/lib/portal_devices.php` | Adds `portal_devices_can_operate`, four `device_access_*` actions, server-selected redemption label, employee operation role admission, bounded access projections and known access errors. Owner/admin enrollment and purchasing authority remain separate. |
| `app/lib/portal_devices_render.php` | Adds the owner/admin Computer access entry and explains assigned-computer visibility for employees/viewers. |
| `app/lib/portal_device_operations_render.php` | Uses operation authority for employee checks/repair presentation; viewers retain read-only presentation. |
| `app/lib/portal_westy_desktop.php` | One existing `portal_devices_can_operate` role check before offering desktop definitions. It depends on the matching device helper; the desktop file cannot be overlaid alone. |
| `app/public/portal/computer-access.php` | Adds owner-issued one-computer access links, employee/viewer redemption and owner/admin revocation, using the current signed service scope. |

All other runtime files match the recorded cut. In particular, `app/db/` and
`deploy/` have **zero content difference**; canonical `app/db/schema.sql` is
the same Git blob `10d378649e335f5aab72b0fa905d57a126f85fa8`. There is no
Safeharbor migration or new grant prerequisite in this employee delta. This
does not verify the current production schema or upstream assignment schemas.

The full repository comparison has 25 changed paths. Beyond the five runtime
paths, every difference is accounted for here:

| Group | Paths |
| --- | --- |
| Existing test coverage (3) | `app/tests/portal_data_test.php`, `app/tests/portal_device_operations_scenarios.php`, `app/tests/portal_employee_device_access_mysql_test.php` |
| CI cleanup/selection (4) | `.github/scripts/ci_changes.py`, `.github/scripts/test_ci_changes.py`, `.github/workflows/mobile.yml` (removed), `.github/workflows/validate.yml` |
| Repository instructions (1) | `AGENTS.md` |
| Verification tools (2) | `tools/shots/walkthrough.mjs`, `tools/shots/westy-check.mjs` |
| Documentation (10) | `docs/EMPLOYEE_COMPUTER_ACCESS.md`, `docs/WESTY_BROWSER_RECOVERY_2026_10_09.md`, `docs/WESTY_CONNECTION_ASSET_CACHE_2026_10_09.md`, `docs/WESTY_DESKTOP_DIAGNOSTICS_2026_10_09.md`, `docs/WESTY_GENERAL_TOOLS.md`, `docs/ci-validation.md`, `docs/verification-tool-inputs.md`, `docs/westy-1.26.13-release-2026-10-09.md`, `docs/westy-control-repair-release-2026-10-08.md`, `docs/where-things-stand.md` |

This is a content comparison against the recorded release cut, rather than an
assumption that every intervening main-branch merge is absent from that cut.
The application delta needs the complete compatible helper/page/render set;
no employee source rebuild or Companion control repair was justified.

## Identity, computer and result boundaries

`portal_devices_scope` derives provider/customer from the active Safeharbor
customer-sync binding and identity realm, immutable `subject`, session version
and effective role from the authenticated identity. Browser fields cannot
select these values. `portal_devices_request` signs the existing
`safeharbor-customer-devices-v1` contract and rechecks that scope after the
service call before returning a credential or result.

Owners/admins manage computer access and enrollment. Employees can request
operations for their assigned computers; viewers receive status and no desktop
execution definitions. Redemption accepts only employee/viewer identities and
uses the session's display name as a label. A customer-profile overlay does not
promote the underlying ID role or expose its People administration shortcut.

The unchanged device-list result validator accepts only the defined bounded
projection, including nullable validated `computer_time`. Access lists contain
references, states, labels and dates, never tokens or principal records. Create
responses alone may contain the one-use credential; replay may return null.
Operation results use the existing closed projection and match the requested
device for list/start/proposal responses. `device_help.php` first locates the
exact opaque computer reference in the current permitted list, checks CSRF,
and verifies a repair reference against that computer's current operation list
before approval. Pages recheck authenticated identity before displaying results.

Safeharbor forwards the current actor rather than maintaining a separate
assignment roster. Milepost must enforce the same-customer immutable principal
and current assignment on lists, direct requests, operation/result access and
native delivery. Safeharbor's synthetic transport tests do not prove that
upstream enforcement or native behavior; the release lead needs the exact
Milepost candidate and its assignment-readiness evidence.

The ID account setup link and computer access link are **two separate private
one-use links**, each expiring after 48 hours unused. Owners share them manually;
no automatic email is sent. Acceptance creates only that employee/viewer's own
assignment, with no business-wide device grant. Unset personal execution-guard
preferences still mean Allow. Revocation does not claim already delivered work
stopped or completed; Stop, uncertain receipts and recovery history remain.

## Carried feature manifest

These existing source features have no runtime difference from the recorded
cut and must remain in the compatible customer release:

| Feature | Source and acceptance boundary |
| --- | --- |
| Reply Copy and feedback | PR #191 / `c49104b`; private feedback, response identity and existing maintenance/schema files match the cut. Existing recorded schema installation is historical; no migration replay or new customer acceptance was performed here. |
| Tenant AI catalog | PR #190 / `22d72a7`; the vendored catalog/client and tenant-admin settings match. No provider/model change, paid probe or fresh provider acceptance. |
| Computer local time | PR #192 / `0ed99ae`, with prerequisite `aa35b6e7`; nullable clock consumer and timezone assets match. Earlier owner acceptance remains recorded at its original boundary. |
| Companion branding and startup presentation | PR #189 / `1fdd3dc` and subsequent compatible startup source are carried unchanged. Agent/Companion packaging and installation belong to the Milepost/private release owners. |
| Compact footer and existing UI | Portal/public assets match the recorded cut. This comparison is not fresh rendered or physical acceptance. |
| Browser/desktop control and diagnostics | Existing continuation, cache, refusal, cleanup and diagnostic source matches. No repair or diagnostics deletion in this review; browser/native/physical acceptance remains pending as recorded. |

## Existing validation and release sequence

Independently read exact-main
[Validate 38084680465, attempt 1](https://github.com/Seckcey/8_west_helpdesk/actions/runs/38084680465)
completed successfully at October 10, 20:45:21 UTC for
`e6308be3cae19a7b3db1e6a85be3aa63772e4a61`. All four jobs ran and passed:
changes, PHP/MySQL, browser contracts and protected CLI. The actual log records
31 employee binding/transport/presentation assertions and the existing 84-check
portal-device suite. Browser checks included reply Copy/feedback and selected
computer timezone contracts. These are hosted synthetic checks at that exact
source, not live invitations, customer sign-in, provider or laptop acceptance.
No duplicate test run, new test framework or additional provider probe was
needed for this documentation-only handoff.

The release lead must use this dependency order:

1. Milepost's reviewed assignment schema, readiness and enforcement first,
   preserving current customer/employee boundaries and stored history.
2. The complete compatible Safeharbor consumer second, after reviewing the
   exact final candidate/main validation and freshly verifying the installed
   source gap. Use the established [release procedure](../deploy/README.md),
   receiver, technical locks and report restoration contract. Old retained
   controls or earlier 1.26.14 evidence are not current readiness proof.
3. 8 West ID's reviewed invitation schema/source exposure last. No existing
   employee receives automatic computer grants. Manual sharing and genuine
   owner/employee/viewer acceptance remain separately evidenced.

Rollback removes ID invitation exposure first. Retain invitation/assignment
history and compatible Milepost enforcement before reverting either consumer;
an older Milepost source can restore broader historical visibility. The prior
Safeharbor cut is historical recovery material, not an approved employee
rollback by itself. Verify the exact inverse against current schema, assigned
principals, source/artifact/configuration and original reporting clocks.

Milepost's coordinator is the normal/customer release lead; Safeharbor's
coordinator owns normal documentation/PR integration. The suite owner retains
the private signing/controller exception. Held SH #181, MP #610 and ID #152
security/cache/UID/auth/native claims, unknown job 4446, click 638322,
nonreplayable history and the accepted backup remain under their existing
owners. This source handoff releases none of those claims and does not establish
a free production host, a usable old controller or a completed 1.26.19 release.
