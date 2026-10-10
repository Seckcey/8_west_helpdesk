# CI validation and release speed

Validate runs on pull requests and every main push. New updates cancel older
validation for the same pull request or branch. The changes job tests the path
selector and records why each job runs or skips.

Documentation-only Markdown under docs, root README/AGENTS, and the deployment
README skips application services and browsers. Markdown under app remains
application input. Mobile projection/rendering and real customer-binding/HMAC
checks now run in the existing PHP/MySQL job. Its service carries forward the
former mobile job's exact `mysql:8.0.46` image digest; mobile retains its own
`safeharbor_westy_test_mobile` database prefix and step-scoped synthetic inputs.
The separate `Customer mobile isolation` workflow is removed.

| Changed inputs | PHP/MySQL, including mobile | Browser contracts | Protected root CLI |
| --- | --- | --- | --- |
| Documentation listed above | Skip | Skip | Skip |
| Browser tooling under `tools/shots/` | Skip | Run | Skip |
| Brand and public assets | Run | Run | Skip |
| Five audited backend helpers below | Run | Skip | Run |
| Other PHP libraries | Run | Run | Run |
| Configuration and database inputs | Run | Skip | Run |
| PHP tests | Run | Only actual browser test inputs | Only actual CLI test inputs |
| Shared application fixtures or unknown/CI inputs | Run | Run | Run |
| Public PHP pages/endpoints | Run | Run | Skip |
| Deployment inputs | Run | Skip | Run, except the four unrelated scripts below |

The five browser exclusions are `portal_westy.php`, `portal_westy_control.php`,
`portal_westy_desktop.php`, `portal_westy_runs.php`, and
`portal_westy_tenant_ai.php` under `app/lib/`. The hosted browser fixtures do not
load these backend helpers: their HTTP/provider responses are synthetic, and
the copied `portal/westy.php` metadata fixture stubs its backend dependencies.
All other PHP libraries remain conservative, including newly added libraries.

Actual browser consumers retain coverage: time page/adjustment source, public
assets and branding, portal rendering/shell/access, mobile/device/order rendering
and projections, business reports, service goals, managed-customer/provider
helpers, queue/workflow rendering, and computer-time policy. Browser fixtures
also execute `portal_computer_time_test.php` and `westy_queue_owner_test.php`.
Their changes still select browser contracts. Other standalone PHP test changes
keep the complete PHP suite without starting Chromium.

The protected CLI tests include `portal_devices_mysql_test.php`,
`portal_westy_fixture.php`, device-operation and security-order scenarios, and
`endpoint_tool_runs_migration_mysql_test.php`. These and the two root CLI tests
keep that job. All library/configuration/database changes retain it because the
real fixture loads authentication, customer bindings and Westy dependencies.
Deployment dependencies remain conservative, including endpoint-tool-run and
managed-provider migration code/catalogs. Only `deploy.sh`,
`hash-safeharbor-app-artifact.sh`, `manage-business-report-scheduler.sh`, and
`remote-install-safeharbor-app.sh` skip the unrelated root CLI runner.

Existing authentication, tenant/employee isolation, feedback, database,
migration, time-entry, export, scheduler and browser test bodies are unchanged.
The suite-revocation step stays separate and unchanged, preserving the held
security PR's private-cache/JWKS/TLS additions for its own integration.

The selector compares the full exact Git diff, including deletions and both
sides of a rename. Workflow, CI helper, and unknown paths run everything.
A main push may skip jobs only if the immediately preceding commit has a
completed, successful push run of this same workflow on main. A missing,
failed, cancelled, pending, or unreadable prior run forces full validation.
Pull requests compare the tested merge commit with their exact base.

The deployment runbook still requires a successful Validate run for the exact
release SHA. The run's selection summary explains any unaffected jobs. Workflow
and documentation changes do not themselves require deploying the application.

Run the selector regression tests locally with:

```sh
python -B .github/scripts/test_ci_changes.py
```

## October 10 cleanup evidence and limits

The audit window was October 9, 20:13 UTC through October 10, 20:13 UTC, covering
six main commits (PRs 202–207) and 28 workflow runs across PR updates and main
pushes. For documentation-only main `0c681e07`,
[Validate 38032569304](https://github.com/Seckcey/8_west_helpdesk/actions/runs/38032569304)
ran its changes job for seven seconds and skipped application jobs, while
[mobile 38032569204](https://github.com/Seckcey/8_west_helpdesk/actions/runs/38032569204)
still used a separate runner/MySQL service for 38 seconds. Consolidation removes
that unconditional runner and workflow start. These are job elapsed times,
not measured billing savings or a claim that one release used an hour of CI.

Browser-tool-only updates also avoid the complete PHP/MySQL and protected CLI
jobs; the audited backend helper updates avoid Chromium installation/contracts.
CI edits themselves deliberately select full validation. Related implementation
and documentation belong in one grouped PR, with one reviewed batched push when
possible. Existing cancellation already avoids superseded runs. No trigger
filter bypasses ordinary PR/main checks.

The six new test/fixture files are retained for distinct regression coverage:

| Recent file | Reason to retain |
| --- | --- |
| `app/tests/portal_westy_desktop_diagnostics_test.php` | Offered-tool diagnostics and separation from private output |
| `app/tests/portal_westy_desktop_recovery_test.php` | Fresh recovery after unavailable observations; uncertain actions are not replayed |
| `app/tests/portal_westy_desktop_continuation_test.php` | Bounded continuation, actual provider-adapter parsing, usage/privacy and Stop boundaries |
| `tools/shots/portal-desktop-cleanup.test.mjs` | Browser cleanup and truthful control state |
| `tools/shots/desktop-renewal-cache.test.mjs` | Controller cache refresh and renewal behavior |
| `tools/shots/fixtures/portal-desktop-pre-renewal.js` | Old-controller input used by the cache regression |

Only the continuation test was newly connected to hosted Validate in this window.
The others are not separate hosted jobs or package scripts; removing them would
not remove paid CI starts. No new tooling/framework or application behavior is
introduced by this cleanup, and no production, provider or laptop acceptance is
claimed.

On October 10, GitHub reported main unprotected and no applicable branch rules,
so no required `mobile` context prevented consolidation. Refresh repository
rules and ordinary checks at the final PR head before integration. A future
required context must be reconciled through the normal repository process.
