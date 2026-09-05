# CI validation and release speed

Validate runs on pull requests and every main push. New updates cancel older
validation for the same pull request or branch. The changes job tests the path
selector and records why each job runs or skips.

Documentation-only Markdown under docs, root README/AGENTS, and the deployment
README skips application services and browsers. Markdown under app remains
application input. All application, brand, and browser-tool changes keep the
complete PHP/MySQL and browser jobs. Deployment-script changes keep PHP/MySQL
tests; the browser fixtures do not consume deployment scripts.

Browser selection deliberately includes all app files, covering the portal's
transitive business-report, service-goal, and managed-customer dependencies.
Existing authentication, tenant-isolation, database, migration, time-entry,
export, scheduler, and browser tests remain intact.

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
