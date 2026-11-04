# Assignee handback receiver release — November 3, 2026

Migration 036 and application source
`7be64ab4fb69e4692be5df7ed735486079aee312` are live. The unchanged stock publisher
completed at **4:39:45 PM PST on November 3, 2026** (`2026-11-04T00:39:45Z`),
replacing `ab4b07690b3f8e577025289e14623a955fa079f2`.

The current assignee can create or cancel a one-use return intent for an
eligible `needs_human` workflow. Intent leaves ticket ownership and version
unchanged. The signed controller can consume that exact intent once and recover
its original immutable receipt after a lost response. Current human ownership
continues to take precedence. See the [contract](westy-workflow-contract.md).

## Source qualification

[PR #278](https://github.com/Seckcey/8_west_helpdesk/pull/278) was reviewed and
normally squash-merged. Its final reviewed head was
`e5f67b140d232373475c1110ba10f9fbaf0de7c0`; the merged release has the identical
tree `81ddd01f6030319f29d5ceff1dcdbb48b154e72b`.

Both required `php` and `browser` jobs, including every step, passed in the
[PR run](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41690604138) and
[main run](https://github.com/Seckcey/8_west_helpdesk/actions/runs/41692475556).
Focused results were 166 SQLite checks, 242 total MySQL workflow/migration
checks including the shared 166, and 11 browser tests, with no focused failure
or skip. Seven unrelated private-cache ownership/cross-user cases were skipped
by the broader PHP suite; this is not a whole-suite zero-skip claim.

The earlier serialized Coastline qualification covered ordinary runtime DML,
actual session/CSRF/HMAC endpoints, migration replay/partial states and observed
InnoDB concurrency. Its Windows source bundle differed from Git only by CRLF in
491 files; 44 were byte-identical. Final required CI qualified the later review
fixes. A separate final 535-file archive and manifest preserve literal Git blobs.

## Actual installation

The publisher SHA256 was
`f69f265cec00c3ba86c32365e580035e045ecaa86ffc842d8913fbf347afbd08`.
The exact [036 SQL](../app/db/migrations/036_westy_workflow_resume.sql) SHA256 was
`1e7f1116e3531f59c25ecab81d34a2b1b2451c6daad247b5c4c90f29d5384581`.
The schema change appends `resume` to the receipt action enum and creates the
dedicated 22-column grant table, indexes and scoped foreign keys. Historical
migration 025 and existing business histories were not rewritten.

Immediately before application, all 409 owned live files matched the prepared
base. The normal command applied 036 and published exactly 10 changed and 3
added files, with no removals or directory changes. Its native exit was zero.
Afterward, all 412 owned files matched the release's Git bytes plus the normal
eight brand copies and three version-tag transformations.

Under the ordinary runtime identity, the existing workflow health function
returned 1 and the installed `westy_workflow_resume_schema_ready()` validator
passed in a read-only transaction. Actual metadata showed the five-value action
enum and the required grant table/index/foreign-key definitions. Existing
database-wide SELECT/INSERT/UPDATE and narrow health-function EXECUTE were
sufficient; no privilege or configuration change was made.

The Safeharbor runtime was active after publication, and the origin login and
stylesheet returned HTTP 200. The six original cron files retained their exact
bytes, inodes, owners, modes and modification times after resumption. Both
previously active timers were active again. No paused-job, interrupted-release,
cold-window, saved-timer or upload marker remained. The original global mutex
identity was preserved and all owned host operations were released.

## Approved recovery material and limits

The scoped exception allowed the unchanged publisher's mandatory old-source
inverse and private trigger/routine/event-inclusive database dump. The new
root-only directory is:

```text
/srv/8west/apps/safeharbor/deploy-state/backups/20261104T003940Z-ab4b07690b3f-to-7be64ab4fb69
```

`files.tar` contains exactly the ten replaced old files: 643,174 raw bytes,
with original contents, ownership and modes verified against the immediate
preflight. The archive is 665,600 bytes; `database.sql.gz` is 231,212 bytes.
All nine original deployment backup directories were retained, plus this new
tenth directory; no backup was pruned. Database contents were not exported to
the release record, and no database restore or new recovery drill ran.

The existing accepted recovery decision remains in force. This release adds no
new pre-update backup campaign, separate restore-test gate or manual acceptance
suite. A source rollback restores `ab4b0769` while retaining additive 036 schema
and all grants/receipts; it does not undo an accepted handback. Database restore,
enum shrink, grant-table deletion or history reset is not routine rollback.

The detailed operator completion receipt is
`westy-auto-repair-20261103-safeharbor-036-production-complete.json`, SHA256
`2473eab25aed2b1052b76964ef1cfe4a7c5c7ad25b9731605d2e8660d9e58e0f`.
It binds the direct human authority, immediate preflight, publisher log,
runtime-byte/schema checks, exact capture scope and cleanup observations.

## Evidence boundary

This record establishes the installed Safeharbor receiver. It does not attest to
an authenticated producer mutation, a particular ticket handback, successful
device repair, fresh original-alert recovery, or client rollout. Those outcomes
need their own run evidence. The compatible receiver precedes Milepost v2
publication. Frankie retains the agreed post-release acceptance responsibility.
Later documentation-only commits can advance GitHub main without changing this
application release or requiring another runtime publication.
