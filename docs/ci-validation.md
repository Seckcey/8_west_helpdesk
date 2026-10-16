# CI validation and release speed

Validate runs on pull requests and every main push. New updates cancel older
validation for the same pull request or branch. The changes job tests the path
selector and records why each job runs or skips.

Documentation-only Markdown under docs, root README/AGENTS, and the deployment
README skips application services and browsers. Markdown under app remains
application input. Mobile projection/rendering and real customer-binding/HMAC
checks run in `php-westy-portal`, one of five isolated PHP/MySQL shards
summarized by the public `php` status. Each service carries forward the former
mobile job's exact `mysql:8.0.46` image digest; mobile retains its own
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
The suite-revocation step stays separate and unchanged in `php-core`, preserving the held
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

## October 15 PHP sharding

The public statuses `changes`, `browser-contracts`, `tool-runs-protected-cli`
and `php` remain. Browser and protected CLI checks retain their commands,
selection, setup and parallel execution. PHP now uses five independent jobs:

| Job | Coverage moved from the former serial `php` job |
| --- | --- |
| `php-core` | All-app PHP lint; tenant AI receipts, catalog, desktop continuation, protected release and migration contracts; restricted signup; suite authentication, workspace/support intake, vendored chrome, drawer, revocation, admission, logout, token algorithms, roles and preferences; Logbook export; Westy controller and Graph transport contracts; portal authentication, rendering/branding, cleanup readiness, renewal provenance, ticket workflow and report archive; runtime-config rejection. |
| `php-westy-control` | Desktop cleanup fixture-directory setup; tool continuation; endpoint tool-run v1/v2 migrations; control/terminal ownership and receipt contracts, including their nested fixtures. |
| `php-westy-portal` | Workflow migration/replay and human ownership; approved email and durable mailbox progression; portal Westy and tenant AI delivery; device/employee access, customer bindings, workspace, HTTP streaming and security orders; private feedback ownership/replay/erasure; mobile projection and real binding isolation. |
| `php-time` | Service goals/publication; time provenance, approvals, adjustments and migrations; overlap/version races; Coastmark v3 export, locking and least privilege; desktop renewal handoff/session authority. |
| `php-customers` | Archived-report scheduler and deployment shell checks; report contacts/evidence; managed-customer activation/lifecycle; customer sync; signed alert intake; automatic-closure ownership; portal/service-goal/report migrations; Central private history/isolation/replay/erasure. |

Each shard uses a separate `ubuntu-latest` hosted runner, checkout, ephemeral
filesystem and pinned MySQL service with the original health check, credentials
and initial database. Identical loopback ports and database names belong to
different runners; no database, volume, config file, `/tmp`, `/run` directory or
fixture output is shared between shards. Commands within a shard remain serial.
Test bodies and their nested fixtures are unchanged. The fixture tests create
their own databases and cleanup; they do not consume another shard's database.

Whole test steps retain their environment and default shell. The former long
Westy migration step is divided at complete command boundaries into three steps
with the same environment. The cleanup-directory creation stays before tool-run
and control tests that use it, on their runner. Config-copy steps retain their
same-shell EXIT traps. Every shard ends with an `always()` assertion that
`app/config/config.php` is absent, including when a test fails. This strengthens
the original final assertion on `php-core` and adds four shard-local assertions. The current-main revocation step keeps its exact
`php app/tests/suite_revocation_test.php` command as a reconciliation anchor for
the separately held security-cache work; this CI change adds no held tests.

### The `php` result

`php` is an `always()` job depending explicitly on `changes` and all five PHP
shards. `.github/scripts/ci_php_result.py` requires a successful selector and an
explicit `php` output. When selected, every shard must report `success`.
Failure, cancellation, unexpected skip, missing dependency/result or malformed
selection fails the aggregate. There is no matrix fail-fast or allowed failure.

When the selector explicitly outputs `false`, every PHP shard must be skipped.
The aggregate then succeeds with the summary **“No PHP tests ran.”** This is an
intentional selection result, not runtime proof. A cancelled workflow remains
unqualified; do not use an older green run or a skipped lane as exact-head test
evidence. `changes` runs the existing selector regression tests and the new
aggregation truth cases before publishing its selection.

The release process still requires the exact default-branch candidate and its
successful workflow, as described in [the deployment runbook](../deploy/README.md).
This workflow is a test gate; it does not deploy. GitHub reported no classic
main-branch protection or applicable branch rules during the October 15 inspection;
this change does not alter repository rules or the project's review/release gates.

### Validation and timing evidence

Local focused checks:

```text
python -B .github/scripts/test_ci_changes.py
python -B .github/scripts/test_ci_php_result.py
git diff --check
```

The selector tests cover real Git rename/delete diffs, documentation skips,
unknown inputs, transitive browser/protected CLI dependencies and exact green
predecessor rules. Aggregation tests execute the actual script for every shard's
failure, cancellation, skip and missing-result cases, selector failures and bad
outputs, absent/malformed JSON, and explicit intentional skips. These focused
tests do not replace actual hosted PHP/MySQL/browser/protected CLI execution.

Before/after parsed workflow comparison against base
`6dcf396066a133956d93dc049c344e2fcff70df7` (workflow blob
`a0e837ac8325cd529cce6289a6c0061843bae477`) found identical multisets of all
106 nonempty PHP-job shell lines, including each line's environment, shell,
working directory. Each original occurrence remains once;
intentional repeated commands and nested fixture coverage remain. Additional execution consists of CI aggregation and its tests, four
shard-local runtime-config assertions, and checkout/service setup on extra runners. Browser and protected CLI job definitions compare equal.

Baseline [run 38799134564](https://github.com/Seckcey/8_west_helpdesk/actions/runs/38799134564),
attempt 1, took 493 seconds end to end: serial PHP 480 seconds (29 seconds
container initialization, 182 seconds Westy migration group, 68 seconds tenant
AI group), browser 125 seconds (24 seconds Chromium setup), protected CLI
45 seconds. This run belongs to a different source candidate; it establishes a
bottleneck estimate, not a controlled benchmark. With runners available, the
split targets roughly 150–180 seconds end to end, bounded by the slowest shard
or browser lane plus selection/aggregation and setup. This is an estimate until
the PR's exact-head hosted run finishes.

Record actual run URL, exact source head/base, attempt, creation/start/completion,
per-job queue delay, container/setup time and test-step duration in the PR handoff.
Report retries separately. Runner availability, image pulls and queue/concurrency
limits can reduce or erase wall-clock savings. Five MySQL initializations use
more runner time than one; the goal is shorter feedback latency, not a claim of
lower billed compute. No new caches or reduced security coverage are involved.


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
