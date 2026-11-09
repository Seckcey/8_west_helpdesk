# Employee accounts and assigned computers

The compatible Safeharbor consumer first deployed from
`1294c84edb7252b8f38cb91f9c843e3bfa55cabd`; original reporting and writers were
restored October 10, 2026, at 5:02:10 PM Pacific.
Milepost assignment enforcement preceded this release. At Safeharbor's handoff,
8 West ID invitation exposure and customer publication remained pending their
owners. The steps below describe the paired flow, not invitations sent or
employee/device/customer acceptance.

The current customer portal uses member/People labels for these accounts and
assignments. Employees land in Westy following the
[November 5 portal release](employee-portal-dashboard.md#november-5-release-record).
Account invitations and computer assignment remain separate actions.

## Owner and employee steps

1. A client owner/admin opens People in 8 West ID, chooses Employee or Read only,
   and creates an employee setup link. The employee chooses their own password.
2. In Safeharbor, the owner opens Your devices → Computer access beside the
   appropriate computer and chooses Create member access link.
3. The owner privately shares that separate link with the employee. The first
   signed-in employee in the same verified business to use it receives access.
4. The employee signs into their customer portal and chooses Use this computer.
   If signed out, sign in first and reopen the original private link. The sign-in
   return URL carries no private code. For a link with #token=, the page fills
   the confirmation form and clears the fragment from the address bar. If
   JavaScript is unavailable, copy the code after #token= and paste it into
   Private access code, then choose Use this computer.
5. The computer appears in that employee's Your devices list. Owners can revoke
   the assignment from Computer access.

Both links expire after 48 hours if unused. Accepted computer assignments remain
active until revoked. Safeharbor computer links are shared manually. The ID
invitation has its own delivery settings. An owner with a separate customer-profile
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

Protection-request activation has its own release record. The private computer
link change does not establish that either protection request is enabled.

## November 5 private-link release

Both stages are live. [PR #291](https://github.com/Seckcey/8_west_helpdesk/pull/291)
published reader `16f4eb0763bc33ec4137e85ca72199d32739b43b` over the portal release
`abaf228dd0837327d01e556eae5b3d2666390b5b`. Its full 424-file readback passed at
21:39:30 UTC. [PR #292](https://github.com/Seckcey/8_west_helpdesk/pull/292)
then published issuer **`754c92d06a8fc905a7ed0d11ae9b295ccef90821`**; all 424
managed files and 23 directories matched at **21:40:04 UTC**. New computer links
use fragments; both redemption formats remain available. The first eligible
same-business employee, one-use link and 48-hour lifetime rules are unchanged.

Exact-main [reader Validate 42107558559](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42107558559)
passed on attempt 2. Its first browser job timed out during dependency setup,
before tests ran; one unchanged browser-only retry passed, retaining the PHP
success. Exact-main [issuer Validate 42111878471](https://github.com/Seckcey/8_west_helpdesk/actions/runs/42111878471)
passed on attempt 1. Coastline reader qualification passed 24 portal browser
cases and 67 login checks; issuer coverage also verified the new link format.
Coverage includes legacy links, JavaScript and manual code entry, signed-out
reopening, no automatic submission, and required action/CSRF checks.

Stock deployment replaced five files and added one for the reader, then replaced
four for the issuer, including normal release stamps. Neither stage deleted
application files. Login, stylesheet and served-JavaScript checks passed.
All six paused cron files were restored with their bytes and metadata intact,
both timers were active, and deployment/report locks were probed and released.
No pause markers or active writers remained at handback. No schema, application
configuration, Apache or customer account/device operation was performed.

The retained stock inverses are under
`/srv/8west/apps/safeharbor/deploy-state/backups/`:

| Stage | Inverse directory | `files.tar` bytes / SHA-256 |
| --- | --- | --- |
| Reader | `20261105T213837Z-abaf228dd083-to-16f4eb0763bc` | 81920 / `4b4d683d5a75750aeb929e813590b831882c6353d95face5331c0e6b18781c89` |
| Issuer | `20261105T213955Z-16f4eb0763bc-to-754c92d06a8f` | 71680 / `13ac5ebfb44a4dfbbbc23d02ebf86644219f2a0ffb2bc01b67bc4735d000b703` |

The issuer inverse returns to the dual reader. Do not remove legacy query
redemption until at least 48 hours after the last query issuance, including any
rollback that resumes query issuance. The reader inverse exists for recovery;
using it requires review of outstanding fragment links and issuance state.

Before publication, the historical source/database recovery set was copied to
the [protected recovery location](employee-portal-dashboard.md#november-5-release-record)
and independently accepted by Safeharbor, Milepost and the suite coordinator.
Reader retention removed only its verified original stock duplicate
`20261023T200121Z-a63dc4ee43dc-to-9d1650adc3bb`; issuer retention removed only the
separately reviewed source-only inverse
`20261023T222312Z-9d1650adc3bb-to-d746c193b83d`. The protected copy and both new
inverses remained intact. This was preservation of historical recovery evidence,
not a fresh database backup or restore test.

The sealed worker evidence index is `safeharbor-private-link-live-handoff.json`,
SHA-256 `f885c0cfd5a268b82274a6831c847db82a9838aa3edd05f5656cac9c08c7296c`.
It identifies and hashes the stock checks, publications and full readbacks.
The final issuer proof SHA-256 is
`6c43916bc776b3a924a256dacaedfbf3cdbc437892ab3c6f40a93b836d40c93d`.

**Customer issuance and acceptance remain separate:** the scoped configured
origin and Cloudflare POST-body review completed on November 5, with no configured
capture found on the inspected paths. This is configuration/source evidence;
provider-internal handling is not claimed. Revalidate if ingress or logging
configuration changes. Real signed-in owner/employee link redemption, assignment
and revocation acceptance was still open at that release; the scoped November 9
walkthrough subsequently completed it. Its human-attested Owner/link steps,
directly observed Member visibility and automated-only alternate path are recorded
in the [Guide acceptance record](portal-guide-pilot.md#november-9-scoped-real-account-acceptance).
No real customer link, email or assignment was created for the November 5 probes.
Apache logging PR #290 remains draft/held and was not installed. The ID invitation
flow is owned and released separately; these receipts establish Safeharbor only.

## Integration and release

Install and verify the Safeharbor release that reads both link formats before
changing the issuer. That first release continues to create query links. The
second release switches new links to a fragment, which the browser places in
the existing explicit CSRF-protected redemption POST. Opening a link leaves
the assignment pending until the employee submits the form. Older links still
carry the code in the query on their initial request.

After fragment issuance starts, keep the dual reader in place through any
rollback. Use the reader release as the inverse of the issuer change. Retain
legacy query redemption for at least 48 hours after the last query issuer stops,
including any rollback that issues query links. Verify POST-body handling at
the edge and complete the signed-in owner and employee acceptance before
customer issuance.

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
