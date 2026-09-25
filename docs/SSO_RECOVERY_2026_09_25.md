# Safeharbor SSO recovery — September 25, 2026

## Confirmed incident

The signed-in 8 West ID launcher was checked across all visible app tiles.
Milepost's recent production audit records show `suite_authorization_feed_invalid`.
Safeharbor displayed its sign-in page and recorded an invalid authorization inventory.
Cloudline displayed its access-confirmation refusal. Coastmark, Logbook, Control Panel,
and Central reached signed-in dashboards in the existing owner browser.

The live ID authorization feed is fresh, HMAC-valid, and includes the owner as
authorized. One unrelated `subscription_lapsed` record has exact date-only `since`
metadata. Each affected consumer rejected the entire feed because it required a
full timestamp for every record. Read-only, in-memory normalization of that one
field made each deployed parser accept the same inventory. No issuer or stored
feed was changed. A pre-existing local Milepost session masked the broken fresh
SSO path in one browser check; its dashboard alone was not counted as SSO proof.

## Scoped repair

The shared authorization-feed policy now accepts an exact calendar date only for `subscription_lapsed`.
Full timestamps remain required for administrative revocations. Invalid calendar
dates, ambiguous formats, trailing bytes, and non-string values still fail closed.
A listed expired subject stays revoked regardless of the date value. Signature,
freshness, exact session generation, tenant, role and product checks are unchanged.
No schema, key, entitlement, identity algorithm, or issuer change is required.

94 authorization-feed checks and the authentication policy test passed locally. Database admission coverage runs in CI against a disposable database.
Regression coverage includes an authorized user alongside an unrelated expired
subscription, the expired subject itself, malformed dates, and administrative
date-only rejection. Existing signature, version, shape and authorization tests
remain in place.

## Release and acceptance

Deployed `fa75a3abbb89752527d7021eca6fbdfbd1a1db7f` from [PR #144](https://github.com/Seckcey/8_west_helpdesk/pull/144) at approximately 03:52 UTC. Exact-main [Validate 36091379003](https://github.com/Seckcey/8_west_helpdesk/actions/runs/36091379003) passed, including disposable-database SSO coverage. The established clean-source deploy script verified the complete artifact under the exclusive report/deploy lock.

- Deployed artifact SHA-256: `b435bb3e4dd70565e72ab1bd5aae51051d36b5e1b9325ac7bbd8ce83bcf127f1`.
- Previous source: `d90a608886deed9f8d3768750cd8029f41427fa5`.
- Fresh root-only rollback backup: `/srv/8west/backups/safeharbor/20260925T035109Z-sso-recovery`, including application, protected config, trigger/routine/event-inclusive database dump, grants, scheduler activation and controls. Archive readability and dump completion were verified; no migration or scratch restore was needed for this PHP-only release.
- Application archive SHA-256: `976d800eb87418b32d8caa9390f48f584f7fe41320fabe94c369edad87f336a6`.
- Database dump SHA-256: `52df8af6ed02824183652b04172f9f3f189bc3987e6183852f9c9a660324f8d3`.
- Protected config remained byte-identical: `12e91f01157f9a18679989eca639dfaa982422dd278f220998f1abd5876f4634`.
- Report cron remained byte-identical: `0ccf6eb37945c35b30cd62fa752ca5ada155a88c12644cf517950c720b4068b6`.

The report scheduler was briefly disabled for deployment, rebound to the new artifact with the existing immutable delivery evidence and same customer/sender/recipient/schedule, then verified active. No report runner or email canary was invoked. The production parser now accepts the unchanged signed authorization feed.

### Suite browser check

All seven visible 8 West ID tiles were clicked again in the owner's signed-in Chrome session on 2026-09-25, approximately 03:52–03:55 UTC.

| App | Observed result |
|---|---|
| Milepost | Home opened; the pre-existing local `seckcey` session remains. This is tile navigation proof, not a clean-session SSO admission test. The deployed parser separately accepted the fresh, HMAC-valid owner authorization feed. |
| Safeharbor | The formerly failing tile admitted Frankie to Queue without a password prompt. |
| Cloudline | The formerly failing `/auth/suite` tile admitted Frankie to Overview; protected Settings and Connections also opened. |
| Coastmark | Home opened as Frankie through the ID tile. |
| Logbook | Dashboard opened as Frankie. |
| Control Panel | Dashboard opened as Frankie. |
| Central | Authenticated dashboard opened with Account and Sign out controls. |

Existing sessions for the unaffected apps were retained; this is not a claim of seven independent clean-browser logins. Cloudline's older Office 365 Email connection displays “Needs attention”; connector repair was outside this SSO change.

### Coordination and boundaries

Frankie explicitly approved the scoped authentication fixes, tests, deployment and repeat tile check at Ultra. Separate task worktrees and `codex/` branches preserved unrelated work. The active Coastmark task explicitly handed over the shared release window. The SSO task held `/run/lock/8west-suite-release.lock` and retained application-specific deployment/report/sync locks while releasing the three apps serially. The lock and Coastline build slot were released after acceptance; the shared record is `D:\projects\SUITE_RELEASE_COORDINATION.md`.

ID, Coastmark, other suite source/runtime, credentials, entitlements and production schema were not changed by this repair. No broad cache clear ran; the versioned-feed latch was retained. A source rollback reintroduces the parser failure while the date-only feed entry remains.
