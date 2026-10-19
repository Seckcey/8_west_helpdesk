# Where things stand

What is live in Safeharbor, and how to check it on the server yourself. **Check, don't trust:** if you
change what is live, change this page in the same pull request. Older status notes and release records
are in git history.

## The live version

```bash
bash deploy/deploy.sh --check
```

prints the live commit, what a deploy would change, which jobs it would pause, and whether the site
passes its check. Every deploy and rollback adds a line to
`/srv/8west/apps/safeharbor/deploy-state/history.log` on the server.

## What is on

| Thing | State |
|---|---|
| Safeharbor | **Live** at https://safeharbor.8westit.com, on its own PHP runtime (`8west-php-safeharbor`) |
| Alert intake (`api/svc/alerts.php`) | **Live** since 2026-07-29; Milepost emitting |
| Automatic close of telemetry tickets | **Live** (migration 018): only an untouched, exact signed Milepost alert may close on recovery; any human or customer work removes that ability for good |
| Westy defect intake (`api/svc/westy.php`) | Safeharbor's own failure reports and reviewed thumbs-down feedback are **live**. External delivery is **dark**: no Westy service identity is registered |
| Westy thumbs up / down | **Live**: down files a ticket after review, up files nothing |
| Partner support intake (`api/svc/support.php`) | **Live** since 2026-08-09; Coastmark and Waypoint emitting |
| 8 West ID sign-in | **Live**, RS256 only |
| Migrations 001–024 | **Applied**. Later migrations shipped with the features they belong to; check for their tables before assuming (below) |
| Versioned service goals | **Live on v2**: Standard 480/240/120/60 minutes, Premium 240/120/60/30, elapsed UTC time, no waiting pause |
| Approval-grade technician time | **Live**, with append-only correction slips (migration 020); original approvals stay immutable |
| Approved time → Coastmark draft lines | **Live for the existing Lifestyle connection**, including the staff Send to billing / Open in Coastmark buttons |
| Logbook resolved-ticket knowledge | **Live**: service identity 6 reads exports for explicitly bound customers |
| Customer portal | **Live for the approved Lifestyle binding**; intentionally on |
| Scheduled archived business reports | **Live for the Lifestyle schedule** (Wednesday 9:00 AM Pacific); the runner is a cron file run as `eightwest-safeharbor` |
| 8 West ID report contacts | Active for Ventures and eligible provider-owned onboarding |
| New-MSP onboarding, managed-client lifecycle, per-MSP integration registration | **On**. Existing-client activation stays Ventures-only |
| Customer propagation | Milepost → 8 West ID → Safeharbor is deployed for eligible MSPs; broad workers stay off |
| Tenant AI (Westy model choice per customer) | **Live**; see `docs/TENANT_AI.md` |

## Who may call us

| Identity | Endpoint | Product | Active |
|---|---|---|---|
| `milepost` | `alerts.php` | Milepost (RMM) | yes |
| `milepost-customers` | `customers.php` | Milepost customer directory | yes, exact tenant `8west` |
| `coastmark-support` | `support.php` | Coastmark, 365 edition | yes |
| `waypoint-support` | `support.php` | Waypoint (standalone, not a suite app) | yes |

`milepost-westy` and `controlpanel-westy` are **not registered**, so external Westy defect delivery is
off. `last_seen_at` updates on every signed request, so a stale value means that emitter has gone quiet:

```bash
ssh milepost-ec2 'sudo mysql safeharbor -e "SELECT service, is_active, last_seen_at FROM svc_identities ORDER BY service"'
```

Partner support facts worth knowing: routing is by `clients.source_key` (`coastmark:{slug}`,
`waypoint:{slug}`), never by name, so staff may rename client rows; a key longer than 64 characters is
refused rather than cut; client rows read "Acme MSP (Coastmark)" by design. Production has no
`support_intake` block in its config and runs on the code defaults, so the first person to add a third
product creates that block (copy its shape from `config/config.sample.php`).

## Checking the database

```bash
ssh milepost-ec2 'sudo mysql -N safeharbor -e "SHOW TABLES"'
```

Expect `svc_identities`, `svc_rate_buckets`, `svc_support_rate` and `westy_reports` among them.

## Open items

1. **Westy external defect delivery is not activated.** It needs a `milepost-westy` identity, its own
   secret, Milepost's sender and a canary on tenant `8west`.
2. **Technician-time correction acceptance** still needs a fresh signed-in check on the 8 West IT
   tenant (overlap conflict, adjacency, rejection, replay, owner self-approval). Keep it nonbillable.
3. **Portal access for another customer** needs its own 8 West ID evidence, binding, isolation,
   revocation and logout check. Do not reset Lifestyle to rerun setup.
4. **Report delivery:** a changed recipient, sender, schedule or customer needs fresh activation
   evidence and separate authorization to email that customer.
5. **Billing:** approve time in Safeharbor, Send to billing, then Open in Coastmark to review the
   draft. Approving, sending, posting and payment stay deliberate Coastmark steps.
6. **Customer creation is not broadly enabled across the suite.** Coastmark and the Control Panel have
   no automatic customer-provisioning lane yet.

## Traps that have already cost time

- **Docker Compose reads `env_file` only when the container is created.** `docker restart` does not pick
  up a new secret; `docker compose up -d` does. (This bit Waypoint at go-live.)
- **Never hand-edit the server's code.** The next deploy replaces any file that differs from git; a hand
  edit shows up in `--check` as "changed".
- **SSH through the `milepost-ec2` alias.** The hostname is behind Cloudflare and port 22 times out.
- **Run `git fetch` and look at open pull requests before starting.** Two sessions once built the same
  Waypoint feature on the same day.
