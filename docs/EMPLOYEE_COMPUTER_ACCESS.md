# Employee accounts and assigned computers

The compatible Safeharbor consumer is deployed from
`1294c84edb7252b8f38cb91f9c843e3bfa55cabd`; original reporting and writers were
restored October 10, 2026, at 5:02:10 PM Pacific.
Milepost assignment enforcement preceded this release. At Safeharbor's handoff,
8 West ID invitation exposure and customer publication remained pending their
owners. The steps below describe the paired flow, not invitations sent or
employee/device/customer acceptance.

Source-only wording update: the customer portal uses member/People labels for
these existing accounts and assignments. This does not add roles or permissions,
change private-link behavior, or establish deployment/customer acceptance.

## Owner and employee steps

1. A client owner/admin opens People in 8 West ID, chooses Employee or Read only,
   and creates an employee setup link. The employee chooses their own password.
2. In Safeharbor, the owner opens Your devices → Computer access beside the
   appropriate computer and chooses Create member access link.
3. The owner privately shares that separate link with the employee. The first
   signed-in employee in the same verified business to use it receives access.
4. The employee signs into their customer portal and chooses Use this computer.
   If signed out, sign in first and reopen the private link. The token is not
   carried through the OIDC login return URL.
5. The computer appears in that employee's Your devices list. Owners can revoke
   the assignment from Computer access.

Both links expire after 48 hours if unused. Accepted computer assignments remain
active until revoked. No email is sent. An owner with a separate customer-profile
overlay keeps their original account role; the portal does not make that account
a client identity administrator. The ID People shortcut appears only for an
actual client owner/admin without that overlay.

Owners/admins retain access to their own business's computers. Employees operate
only assigned computers; viewers see assigned status without gaining execution
controls. Installation/enrollment and purchasing still need owner/admin access.
Existing personal Allow/Deny settings, Stop and recovery behavior remain intact.
Work-from-home remote access is a separate future feature.

## Current access and uncertain results

The browser never chooses the provider, customer, immutable subject or identity
realm. Safeharbor derives those from its current authenticated binding and
rechecks the binding after the service call. The employee display name also
comes from that session; it is only a label. Milepost enforces current ownership
and assignment again for lists, direct requests and native delivery.

Access-link creation is idempotent for one form request. The credential appears
once; replay reports the existing link without rotating it. If a response is
lost, inspect the current list before revoking/replacing a link. A revoked link
cannot restore access. Revocation prevents further authorization but does not
prove an already delivered command stopped or completed; preserve its recorded
status and use the existing recovery/Stop tools.

The page uses CSRF protection, no-store responses, no-referrer policy and escaped
labels. Lists expose only references, labels, states and dates. Do not place
private link tokens in logs, screenshots, support tickets or release receipts.

## Protection requests are separate

A [protection request](customer-portal-contract.md#protection-request-source-only)
is not a member or computer assignment. Only the account owner or an admin,
signed in with their own account, can send one: employees, viewers and
delegated access cannot. It grants no device access, role, installation or
purchase authority. Assigning a computer to an employee sends no protection
request, and a protection request assigns no computer.

The [employee protection request](customer-portal-contract.md#employee-protection-request-source-only)
lets that owner/admin name the employee who uses a computer.

- The choice comes from the account's current member list, which Milepost
  obtains from 8 West ID.
- The page starts with a blank choice and can page beyond 20 people.
- Choosing an employee is not member setup, an invitation or a computer
  assignment. It gives that person no sign-in, account, device or
  installation access, and changes no role.
- The requesting owner/admin is kept separately from the chosen employee. The
  account owner is not assumed to be the employee.

Both requests are source only: the deployed Safeharbor runtime (2c) does not
include them.

## Integration and release

Release order is Milepost's reviewed assignment schema, readiness and enforcement
first, the complete compatible Safeharbor consumer second, and 8 West ID's
reviewed invitation schema/source exposure last. Confirm Milepost's current
same-customer, immutable-principal enforcement for lists, direct operations,
results and native delivery before exposing employee invitations. Safeharbor
adds no schema, provider credential, roster service or live account migration.
Existing employees/viewers have no automatic computer grants; their owners must
assign access.

Rollback removes ID invitation exposure first, before either compatible consumer
is reverted. Retain assignment and invitation history and compatible Milepost
enforcement: an older Milepost version can restore broader historical visibility.
The release owner must verify that the exact inverse preserves employee/viewer
boundaries; this document does not authorize a migration or live activation.

Clock-first dependency `aa35b6e7b94c60ccf8b8c31aba8cb28834115ef1` supplies the
accepted optional `computer_time` consumer. This work preserves its nullable,
backward-compatible behavior. Later clock presentation changes and reply
Copy/feedback changes retain their separate owners.

`app/tests/portal_employee_device_access_mysql_test.php` uses the actual binding
schema and a captured synthetic service transport. It verifies role gates,
server-selected identity/label, operation forwarding, credential projections,
binding revocation during response handling, and owner/employee presentation.
`portal_devices_mysql_test.php` and the clock consumer tests remain regression
checks. Container validation uses an isolated Coastline project only. No live
invitation, account, device command, migration or deployment is part of these
tests. Frankie owns physical post-release acceptance.
